# Pregame live workstreams and focused repair

## Exact changed files

- `app/Services/NhlPregameWorkerActivity.php`
- `resources/js/pages/Admin/SatModels/Index.vue`
- `resources/js/pages/Admin/SatModels/Index.test.js`
- `tests/Feature/NhlPregameWorkerActivityTest.php`
- `docs/architecture/admin/NhlSatWorkspace.yaml`
- `docs/architecture/admin/NhlPregameWorkerActivity.yaml`
- `docs/ENUMS.md`
- `docs/UI_DESIGN.md`
- `docs/ARCHITECTURE_INVENTORY.md`
- `docs/testing/pregame-live-workstreams-review.md`

## Minimum coverage matrix

Authored assertions only; no passing test results are claimed.

| Category | Status | Evidence |
| --- | --- | --- |
| A — validation | COVERED | Existing pilot/job identity allowlist and malformed/unrelated execution tests retained. No new endpoint or request input. Existing workspace guest/member matrix remains applicable. |
| B — normalization/defaulting | COVERED | Job-to-pool classification, optional missing progress, opaque league cursor/finalization handling, measured context progress and host/PID deduplication. |
| C — persistence | COVERED | Existing telemetry lifecycle cleanup tests; checkpoint inspection asserts durable counters remain unchanged. No database, queue reservation or processing writes added. |
| D — response | COVERED | Additive pool/work_progress metadata, null percentage for league phases, null progress without valid checkpoint and execution retention on optional read failure. |
| E — read after action | COVERED | `serves committed date progress for an observed execution through authorized HTTP`: checkpoint DB assertions + real registered JobProcessing event → shared fake telemetry assertion → authorized partial GET pool/progress assertions. Existing provider registration test retained. |
| F — events/queues | COVERED | Existing start/finish/error/expiry/attempt isolation tests; no dispatch or worker configuration changes. UI single repair chooses existing repair or resume endpoint, while confirmed rebuild stays in maintenance options. |
| G — frontend | COVERED | Completed maintenance becomes idle without active bars; eight distinct workers appear once in correct pools; measured per-worker progress; queued/paused/blocked/unknown states; single issue action; maintenance confirmation; quiet list reconciliation. |

## Numbered requirements

1. SATISFIED — Current activity is explicit rather than inferred from completed-run green bars. Historical run status/counts remain compactly available.
2. SATISFIED — Real league/context pools contain workers; shared player/team/goalie/rankings/travel categories remain inspectable without duplicating workers. Coordination appears separately while observed.
3. SATISFIED — Actual observed unique host/PID count remains separate from configured claim capacity. No claim or configured slot is promoted to an executing process.
4. SATISFIED — Worker rows show task, date/game where present, queue, owning run, host/PID, elapsed time and optional measured checkpoints. Unknown denominators produce no invented percentage.
5. SATISFIED — View issues contains causes/source blockers and a single repair action. Retryable or paused work uses existing resume semantics. Test-only updates and confirmed full rebuild/stop options are separate.
6. SATISFIED — Quiet refresh retains the fixed activity viewport and prior observations as Unknown on telemetry failure. Finished rows disappear. Missing checkpoint data does not hide execution identity.
7. SATISFIED — Source schemas, prediction math, processing admission, worker allocations, authoritative context ownership and production operations remain unchanged. No migration required.

## Endpoint × verb authorization/scope

| Endpoint | Guest | Ordinary member | Authorized super admin | Scope |
| --- | --- | --- | --- | --- |
| GET /admin/nhl-sat-models | Existing `blocks guests from every workspace endpoint` | Existing `blocks ordinary members from every workspace endpoint` | `serves committed date progress for an observed execution through authorized HTTP` | Existing `ignores production model executions` and `does not guess a pilot when its selection is ambiguous`; checkpoint reads match exact observed run/date tuples. |
| POST /admin/nhl-sat-models/pregame/repair | Existing guest endpoint dataset | Existing member endpoint dataset | Existing `admits missing Train and Test repair through HTTP and reads its scoped run` | Existing `repairs the historical pilot even when a caller supplies a production model identity`. No endpoint changes. |

Existing resume/build/update/stop routes retain their protected middleware and
admission checks. Organization/member ownership is not applicable to this global
super-admin workspace. No partner API or Horizon authorization changes.

## Frontend checklist

- COVERED — Pool activity states are independent of completed historical counters.
- COVERED — Eight workers, distinct pools, one row per observed host/PID.
- COVERED — Coordination visibility and measured worker progress.
- COVERED — Queued claims versus executing processes and configured capacity.
- COVERED — Retained Unknown observations, fixed list viewport and finished row removal.
- COVERED — Exactly one repair action in View issues; resume mapping for retryable work.
- COVERED — Full rebuild still requires confirmation in Maintenance options.
- COVERED — Existing animated category disclosure, timers, visibility guard and unmount cleanup retained.
- Fixed clocks/timers, mock HTTP/router and in-memory Redis; no external network.

## Per-file counts

| File | it(...) declarations | Executed |
| --- | ---: | --- |
| tests/Feature/NhlPregameWorkerActivityTest.php | 39 | No |
| resources/js/pages/Admin/SatModels/Index.test.js | 74 | No |

Checks performed: source and migration review, declaration counts and
`git diff --check`. Tests, lint, CI, builds, browser/Playwright checks, queue
commands, worker operations and production writes were not run.

Human verification commands:

```bash
php artisan test tests/Feature/NhlPregameWorkerActivityTest.php tests/Feature/NhlSatWorkspaceTest.php
npm run test -- --run resources/js/pages/Admin/SatModels/Index.test.js
npm run build
```

No migration required. Optional progress uses one bounded exact-run/date query
against existing day counters. League entity cursors are IDs, not sample counts;
finalization is shown as phase text. Activity still observes queue lifecycle
events rather than independently probing OS liveness; existing expiry bounds
abandoned observations. This change does not provision eight workers or alter
processing concurrency. Missing source imports continue to require the existing
import pathway before a context repair can succeed.

Awaiting human review
