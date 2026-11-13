# On-ice rebuild lock pressure

Approved scope: remove the rebuild's avoidable per-shift transaction bookkeeping,
preserve whole-game atomicity and cancellation, and increase PostgreSQL lock
headroom. No migration, queue allocation or prediction change is required.

## Production evidence — November 13, 2026

- Run 219, 2022–23 On-Ice Stats, failed on game 2022020058.
- PostgreSQL recorded 323 shared-lock-memory errors between 00:02:39 and 06:04:46
  UTC (November 12 19:02 through November 13 01:04 Toronto time).
- 169 logged errors involved inserting `nhl_unit_shifts`; website connections,
  live polling, SAT scoring and other transactions also encountered exhaustion.
- At inspection, the incident had subsided and only dozens of locks were held.
  The log cannot reconstruct the exact peak lock holders or their lock counts.
- `max_locks_per_transaction` was the default 64, `max_connections` was 100 and
  `max_prepared_transactions` was zero. Available system RAM was about 13 GiB.
- The database resolves to `/mnt/diq-400gb/postgresql/17/main` on the 400 GB
  volume (52% used). The old 200 GB volume was full; nothing was removed.

The rebuild transaction and run-control transaction correctly fence the derived
graph. The shift loop previously used Eloquent `updateOrCreate`, whose creation
path introduces a nested transaction/savepoint inside an active transaction.
Thousands of those inserts across concurrent games can accumulate transaction
lock bookkeeping. This is the identified avoidable hot path, rather than proof
that every affected transaction was an original source of exhaustion.

## Files changed

- `app/Models/NhlUnitShift.php`
- `app/Services/MakeNhlGameShiftUnits.php`
- `app/Services/ConnectEventsToUnitShifts.php`
- `tests/Feature/NhlStrengthOnIceStatsTest.php`
- `docs/architecture/imports/NhlGameImportRebuilder.yaml`
- `docs/architecture/imports/NhlGameDataImportServices.yaml`
- `docs/ARCHITECTURE_INVENTORY.md`
- This review document.

## Behavior

Both ordinary unit construction and HTML-supported corrections use native
upserts through the existing `(unit_id, nhl_game_id, start_game_seconds)` unique
constraint and read the persisted identity. Updates retain IDs, creation times
and fields not owned by the supplied transformation. No model observers are
registered for these writes. The transformation order, raw sources, per-game
lock, whole-game rollback, final cancellation check and checkpoints are retained.
Global unit/player resolution and its existing concurrency behavior are unchanged.

## Minimum coverage matrix

COVERED means authored assertions or named existing regression coverage;
execution remains outstanding.

| Contract | Status | Test |
| --- | --- | --- |
| Persist natural identity, timings and timestamps | COVERED | creates an import shift and returns its persisted natural identity and timing |
| Repeat writes retain row ID, creation time and unowned fields | COVERED | updates a repeated import without replacing its ID creation time or unowned context |
| Game/unit/start isolation | COVERED | keeps import shifts separate by game unit and start time (three cases) |
| Insert and update rollback within outer transaction | COVERED | rolls back native shift inserts and corrections with the surrounding game transaction |
| Per-shift nested transactions do not grow with volume | COVERED | does not create nested transaction savepoints as imported shift volume grows |
| Real shift construction and repeat timing | COVERED | builds and repeats real shift windows without per-shift transaction growth |
| HTML-supported correction path | COVERED | rebuilds event links with HTML PBP and TV TOI when shiftcharts disagree (NhlGameValidationTest) |
| Raw-source retention, stage order, failed-validation rollback | COVERED | runs the five canonical derived transformations in order while preserving source rows; rolls back derived cleanup and replacements if validation fails |
| Cancellation rollback and checkpoint reuse | COVERED | rolls back active database writes when the cancellation signal arrives; checkpoints successful on-ice games through the existing commit fence; resumes only unfinished on-ice games in the original batch (NhlGameImportRunControlTest) |

## Requirements checklist

1. SATISFIED: avoid nested savepoints for individual unit-shift insertions.
2. SATISFIED: use existing database-enforced natural identity; no migration.
3. SATISFIED: preserve timing, stable IDs, unowned columns and timestamps.
4. SATISFIED: preserve atomic graph replacement, validation and run controls.
5. SATISFIED: document regression coverage and human verification commands.
6. SATISFIED: production lock capacity is active at 256 after the approved
   PostgreSQL restart, with `pending_restart = false`.

## Endpoint × verb authorization/scope checklist

No route, permission or request contract changes. Existing POST
`/admin/nhl-game-imports/process-on-ice` coverage in NhlGameImportRunControlTest:

| Case | Test |
| --- | --- |
| Guest denied | denies guests the on-ice processing endpoint |
| Ordinary user denied | denies non-admins the on-ice processing endpoint |
| Super-admin accepted; selected run scoped | queues only derived on-ice jobs and preserves the original discovery |
| Duplicate active selection denied | does not admit a duplicate active on-ice selection |
| Organization/owner scope | N/A; existing super-admin operations |

Frontend component checklist: N/A; no UI changes.

## Test counts and human verification

Modified `tests/Feature/NhlStrengthOnIceStatsTest.php`: **75** `it(...)`
declarations (six added), excluding datasets. Existing run-control and HTML
correction suites are unchanged. No tests or CI were run.

```sh
php artisan test tests/Feature/NhlStrengthOnIceStatsTest.php
php artisan test tests/Feature/NhlGameImportRunControlTest.php
php artisan test tests/Feature/NhlGameValidationTest.php --filter="rebuilds event links with HTML PBP and TV TOI when shiftcharts disagree"
```

## Production activation

The approved database change was applied using `ALTER SYSTEM SET
max_locks_per_transaction = '256'`, followed by `pg_reload_conf()`. It initially
remained pending because this is a startup-only setting. The human subsequently
authorized using the existing local sudo credential without displaying it and
explicitly approved pausing new job admission, draining claimed work, restarting
PostgreSQL and resuming admission. The credential file was not modified.

On November 13, all six Horizon supervisors were paused. Prediction reservations
drained; remaining reservations were checked against worker activity rather than
treated as proof of a running job. Existing Fantrax work was allowed to exit
under its normal lifecycle. Once workers were idle, PostgreSQL 17/main restarted
at **13:40:55 UTC**. A fresh connection to `dynastyiq` confirmed
`max_locks_per_transaction = 256` and `pending_restart = false`. Horizon was
continued and all six supervisors reported running. No queue messages were
deleted, workers manually terminated or failed runs manually retried.

The operational commands for a future planned activation/check are:

```sh
sudo systemctl restart postgresql@17-main
sudo -u postgres psql -d dynastyiq -c "SHOW max_locks_per_transaction;"
```

The verified active setting is 256. Deploy the code change through the usual process
before retrying affected rebuilding work. The setting alone does not deploy code
or resume the failed run. To revert only this operational setting, use `ALTER
SYSTEM RESET max_locks_per_transaction` and a planned cluster restart; its prior
value was the default 64. No automatic incident replay is part of this change.
