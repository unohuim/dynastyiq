# Season Sync uses projections

## Files changed

- `app/Jobs/SeasonSumJob.php`
- `tests/Feature/AdminControlPanelTriageTest.php`
- `tests/Feature/NhlStatsDashboardTest.php`
- `tests/Unit/NhlImportPipelineFoundationTest.php`
- `docs/architecture/application/LaravelApplicationShell.yaml`
- `docs/architecture/stats/NhlStatsCacheRefresh.yaml`
- `docs/ENUMS.md`
- `AGENTS.md`
- This review document.

The shared SeasonSumJob constructor now selects the existing `projections` queue.
This covers admin, command-line, automatic Full-import and on-ice-rebuild
dispatches without duplicating queue choices at each call site. Existing cache
and SAT goalie-rating preparation already uses projections. No new queue or
Horizon worker allocation is introduced. Calculation, retries, timeout, run identity,
connection selection and completion gates are preserved.

Existing serialized pending/reserved jobs are not relocated by this code change.
No production jobs were inspected, moved, cancelled or restarted during this
implementation. The user's requirement for a production-specific plan followed by
explicit approval before any production write is now also recorded in AGENTS.md.

## Minimum coverage matrix

COVERED means authored assertions, not executed tests.

| Contract | Status | Test |
| --- | --- | --- |
| Admin HTTP request → tracked DB run → projections dispatch with correct run/season | COVERED | allows super admins to queue NHL season stat syncs |
| Command-line entrypoint uses projections without an admin run | COVERED | routes command line season sync dispatches to projections |
| Queue and identity survive serialization, with and without admin run | COVERED | preserves projections routing and season identity when rollup jobs are serialized |
| Automatic Full-import completion queues projections with tracked run identity | COVERED | queues a tracked season sync despite unfinished validations in other runs |
| Request authorization and validation retained | COVERED | blocks guests from queuing NHL season stat syncs; blocks authenticated non-admin users from queuing NHL season stat syncs; rejects invalid NHL season stat sync selections |
| Calculation/lifecycle behavior retained | COVERED | Existing season-sync completion/failure assertions in NhlStatsDashboardTest remain unchanged |

## Requirements checklist

1. SATISFIED: newly dispatched season syncs use the existing projections queue.
2. SATISFIED: constructor-level routing covers every existing dispatch entrypoint.
3. SATISFIED: existing follow-up queue choices and worker configuration remain unchanged.
4. SATISFIED: regression coverage includes real admin, automatic and command-line dispatch paths.
5. SATISFIED: canonical queue documentation and production-write approval rule updated.
6. SATISFIED: no migration, production changes, job moves, worker operations, test execution or push.

## Endpoint × verb authorization/scope checklist

| Endpoint / verb | Case | Test |
| --- | --- | --- |
| POST `admin.nhl-game-imports.season-sync` | Guest 401 | blocks guests from queuing NHL season stat syncs |
| Same | Ordinary authenticated user 403 | blocks authenticated non-admin users from queuing NHL season stat syncs |
| Same | Super admin 202; tracked run and projections dispatch | allows super admins to queue NHL season stat syncs |
| Same | Invalid season 422; no rollup queued | rejects invalid NHL season stat sync selections |
| Organization/owner scope | N/A | Existing global NHL import administration remains super-admin-only |
| New HTTP read fields | N/A | Queue routing is an internal dispatch contract; no public data fields or response shapes changed |

## Frontend component checklist

Not applicable: no frontend files changed.

## Per-file test counts and checks

- `tests/Feature/AdminControlPanelTriageTest.php`: **235** `it(...)` declarations;
  existing admin dispatch assertion strengthened.
- `tests/Feature/NhlStatsDashboardTest.php`: **141** declarations; two new
  declarations, three cases including nullable-run serialization.
- `tests/Unit/NhlImportPipelineFoundationTest.php`: **64** declarations;
  existing automatic dispatch assertion strengthened across its three cases.

Checks performed: read-only dispatch-path inspection, test declaration counting,
static diff review and `git diff --check`. No tests or CI were run under repository
policy. Suggested human command:

```sh
php artisan test tests/Feature/NhlStatsDashboardTest.php tests/Feature/AdminControlPanelTriageTest.php tests/Unit/NhlImportPipelineFoundationTest.php
```

No migration is required. Deployment through the normal release/worker-reload
process makes subsequent Season Sync dispatches use projections. This task does
not execute deployment or change jobs already in production queues.
