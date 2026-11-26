# Profile timeout recovery review

## Files changed

- `app/Services/NhlSatModelEntityProfileBuilder.php`
- `app/Jobs/LoadNhlSatModelProfileBatchJob.php`
- `app/Jobs/BuildNhlSatModelEntityProfileForEntityJob.php`
- `app/Models/NhlModelRun.php`
- `app/Http/Controllers/Admin/NhlModelRunController.php`
- `tests/Feature/NhlBuildPredictionsTest.php`
- `tests/Feature/NhlStrengthProbabilityTrainingTest.php`
- `docs/architecture/stats/NhlModelRuns.yaml`
- `docs/ARCHITECTURE_INVENTORY.md`
- `docs/ENUMS.md`
- `docs/testing/profile-timeout-recovery-review.md`

## Minimum coverage matrix

COVERED means authored regression assertions, not passing test execution.

| Category | Status | Evidence |
|---|---|---|
| A validation | COVERED | Malformed, mismatched, zero, negative and overflowing numeric IDs; reject jobs over 15; existing permissions |
| B defaults | COVERED | Whole-profile unrestricted lookup, separate goalie/skater ID columns, 100-entity loader pages split into seven jobs |
| C persistence | COVERED | Real training and snapshot builder SQL uses numeric bindings; original keys/counts remain intact; preserve snapshot rows when clearing Train rows |
| D HTTP contract | COVERED | Existing JSON actions retained; failed-build retry evidence persisted through the admin action |
| E HTTP read | COVERED | Admin profile POST → stored retry metadata → model index GET; existing evaluation POST → bucket GET regression |
| F queue/event | COVERED | 15-entity delivery, reject 16, duplicate generation/receipt tests retained, loader skips reusable snapshots and preserves completed counts |
| G frontend | COVERED (not applicable) | No frontend or UI behavior change |

## Requirements checklist

1. SATISFIED: bind numeric player IDs in both construction and strength verification; retain entity-key identities and source eligibility.
2. SATISFIED: loader maximum is 15 players per child job, enforced before writes; retain 100-identifier discovery pages and bounded queue inserts.
3. SATISFIED: keep successful season snapshots only for eligible unchanged failed-build retries; changed evaluation/scope/source or incomplete evidence falls back to rebuilding.
4. SATISFIED: carry forward snapshot completion counts while rebuilding Train in a fresh generation; completion rechecks retained snapshot sources before marking profiles current.
5. SATISFIED: preserve existing cancellation, authorization, build ownership and duplicate receipt protection.
6. SATISFIED: no migration, timeout increase, prediction math change, deployment command or production mutation.

## Endpoint × verb × auth/scope matrix

| Endpoint / verb | Guest | Ordinary user | Super admin |
|---|---|---|---|
| `admin.nhl-sat-models.profiles.build` POST | blocks guests from standalone profile builds | blocks ordinary users from standalone profile builds | carries reusable snapshot decisions through the admin action and HTTP progress read |
| `admin.nhl-sat-models.predictions.build` POST | blocks guests from starting prediction builds | blocks ordinary users from starting prediction builds | starts profiles on the same model and exposes the running stage through HTTP |
| `admin.nhl-sat-models.index` GET | blocks guests from viewing prediction build progress | blocks ordinary users from viewing prediction build progress | carries reusable snapshot decisions through the admin action and HTTP progress read |

These remain existing global super-admin routes; organization/owner scoping is not applicable. No partner endpoint changes.

## Frontend checklist

Not applicable: no Vue, Blade or JavaScript changes.

## Per-file test declarations

| File | `it(...)` declarations |
|---|---:|
| `tests/Feature/NhlBuildPredictionsTest.php` | 100 |
| `tests/Feature/NhlStrengthProbabilityTrainingTest.php` | 52 |

Both exceed the minimum 20. PHP tests remain strict-types Pest with controlled clocks, fake queues/broadcasts and blocked external requests.

## Checks actually run

- Source and scoped diff review.
- `git diff --check`: passed.
- Declaration counts inspected using `rg`.
- No tests, lint, CI, migration, imports, queues or production writes run for implementation.

Human-run regression command (not executed by Codex):

```sh
php artisan test tests/Feature/NhlBuildPredictionsTest.php tests/Feature/NhlStrengthProbabilityTrainingTest.php
```

PostgreSQL is required for the real profile SQL assertions. No measured speedup is claimed before the next operator-run build.

## Operator follow-up

No migration is required; existing indexes and metadata fields are used. After deployment, run the normal **Build Offensive Skaters** action again. It admits a fresh generation of 15-player jobs. Do not replay old 25-player job payloads through Horizon. Fully completed season snapshots are reused only if all source/scope/evaluation checks still pass. Reevaluating SAT/SOG appropriately invalidates snapshot reuse. Existing timeout remains five minutes; this change reduces query scans and per-job work rather than extending it.


## Progress broadcast follow-up

Changed files in this follow-up: `app/Jobs/BuildNhlSatModelEntityProfileForEntityJob.php`,
`tests/Feature/NhlBuildPredictionsTest.php`,
`docs/architecture/stats/NhlModelRuns.yaml`, and this review document.

The modulo-based broadcast gate was incorrect for carried-forward counts such as
3,722. Every committed child job now registers the existing private admin progress
notification, including a partial final job. No polling, public API, frontend,
queue routing or profile calculation changes.

Coverage matrix: A/B unchanged existing guards; C committed counters asserted;
D/E existing action/read contract and authenticated model-row listener unchanged;
F COVERED by `broadcasts profile progress with a reused snapshot count not divisible
by the job size`, `defers short profile job progress notification to its after commit
callback`, and `does not rebroadcast profile progress when a committed job is
redelivered`; G not applicable, no JavaScript changes. The existing rollback test
also asserts no notification.

Requirements: SATISFIED — notify each completed job; SATISFIED — defer until commit;
SATISFIED — keep duplicate/rollback suppression; SATISFIED — preserve existing
private authorization, queue and calculation. Endpoint/auth matrix above remains
applicable without route changes. Frontend checklist remains not applicable.
The touched Pest suite now has 100 declarations; no new test file is introduced.

Checks actually run: diff inspection, `rg` declaration count and
`git diff --check`. Tests, lint, CI, deployment/worker commands and production
writes were not executed. No migration required.
