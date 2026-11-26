# Profile reservation recovery review

## Files changed

- `app/Services/NhlSatModelEntityProfileBuilder.php`
- `app/Jobs/BuildNhlSatModelEntityProfileForEntityJob.php`
- `routes/console.php`
- `tests/Feature/NhlBuildPredictionsTest.php`
- `docs/architecture/stats/NhlModelRuns.yaml`
- `docs/ARCHITECTURE_INVENTORY.md`
- `docs/ENUMS.md`
- `docs/testing/profile-reservation-recovery-review.md`

## Minimum coverage matrix

COVERED below means authored assertions, not executed tests.

| Category | Status | Evidence |
|---|---|---|
| A validation | COVERED | Exact child class, five-minute timeout, bounded payload/entity count, valid receipt/generation and active-build checks; existing ID/authorization regressions |
| B defaults | COVERED | Legacy generation defaults to one; shared Redis retry_after remains 3900; healthy reservations and non-Redis drivers are ignored |
| C persistence | COVERED | Replacement fence commits before publication; late superseded work rolls back; counts and snapshots remain unchanged by recovery |
| D API | COVERED (unchanged) | No public/partner payload or route change; existing admin action JSON coverage retained |
| E read contract | COVERED (unchanged) | Existing admin POST → DB → index and prediction-build progress HTTP tests retained |
| F queue/event | COVERED | Stale profile reservation CAS recovery, preserved attempts/UUID, failed and older builds ignored, uncertain Redis publish retried at the same prepared generation, replacement idempotence |
| G frontend | COVERED (not applicable) | No frontend changes |

## Requirements checklist

1. SATISFIED: scan a rotating bounded Redis page, profile child jobs only.
2. SATISFIED: wait job timeout plus lock grace and safety margin; keep shared long-job queue settings intact.
3. SATISFIED: preserve attempts, UUID and existing queue/batch ownership instead of starting a new profile build.
4. SATISFIED: fence unfinished stale deliveries in model metadata before replacement publication; late old workers cannot commit rows or counts.
5. SATISFIED: recover uncertain DB-fence/Redis-publish gaps without repeatedly advancing generation.
6. SATISFIED: preserve completed counts/snapshots and respect existing retry limits, source checks and terminal model states.
7. SATISFIED: reuse the existing scheduler, profile builder, jobs and model metrics; no new table or migration.

## Endpoint × verb × authorization/scope

No endpoints or authorization changes. Existing coverage remains:

| Endpoint / verb | Guest | Ordinary user | Super admin |
|---|---|---|---|
| profiles build POST | blocks guests from standalone profile builds | blocks ordinary users from standalone profile builds | carries reusable snapshot decisions through the admin action and HTTP progress read |
| predictions build POST | blocks guests from starting prediction builds | blocks ordinary users from starting prediction builds | starts profiles on the same model and exposes the running stage through HTTP |
| model index GET | blocks guests from viewing prediction build progress | blocks ordinary users from viewing prediction build progress | starts profiles on the same model and exposes the running stage through HTTP |

Organization/owner scoping is not applicable to existing global super-admin model actions. Background recovery requires the active model's exact build identity and retains batch cancellation/completion checks.

## Frontend checklist

Not applicable. Existing post-commit private progress events remain unchanged.

## Per-file test count

`tests/Feature/NhlBuildPredictionsTest.php`: **111** `it(...)` declarations.
PHP remains strict-types Pest; recovery tests use fixed clocks and mocked Redis,
queues, events and exception reporting. No real Redis/network/background work is
executed by the authored tests.

## Checks actually performed

- Source/diff inspection and declaration count using `rg`.
- `git diff --check`: passed.
- Tests, lint and CI were not executed.
- Separately approved production recovery requeued exactly 12 stale profile
  reservations after checking that the build was active and no profile SQL was
  running. Existing completed counts were preserved; production subsequently reported 4,928/4,928 with zero failed jobs. This was an operational
  recovery, not automated test execution.

Suggested human-run check (not run by Codex):

```sh
php artisan test tests/Feature/NhlBuildPredictionsTest.php
```

## Deployment and limits

No migration required. The existing Laravel scheduler must be running to execute
recovery once per minute; existing Forge worker reloads must load the generation
checks. Recovery preserves the exception-attempt limit and does not promise that
an expensive query will finish within its existing timeout. Terminally failed
builds are not automatically resumed. No profile math, prediction engine,
15-player cap, worker count, long-job reservation setting or completed source
snapshot is changed by this feature.
