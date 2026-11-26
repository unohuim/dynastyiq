# Pregame update progress and worker activity

## Files changed or created

- `app/Providers/AppServiceProvider.php`
- `app/Services/NhlPregameWorkerActivity.php`
- `app/Services/NhlSatPregameWorkspace.php`
- `resources/js/pages/Admin/SatModels/Index.vue`
- `resources/js/pages/Admin/SatModels/Index.test.js`
- `tests/Feature/NhlPregameWorkerActivityTest.php`
- `tests/Feature/NhlSatWorkspaceTest.php`
- `docs/architecture/admin/NhlPregameWorkerActivity.yaml`
- `docs/architecture/admin/NhlSatWorkspace.yaml`
- `docs/ARCHITECTURE_INVENTORY.md`
- `docs/ENUMS.md`
- `docs/UI_DESIGN.md`
- `docs/testing/pregame-progress-worker-activity-review.md`

## Minimum coverage matrix

Coverage means authored assertions, not executed passing tests.

| Category | Status | Evidence |
| --- | --- | --- |
| A — request validation | COVERED | Existing workspace authorization and pilot-selection tests; new observer rejects unrelated, missing, ambiguous, malformed, oversized and mismatched job identities. No new request input. |
| B — normalization/defaults | COVERED | Unknown denominator, completed empty selection, mutually exclusive processed counts, default missing telemetry, expired observations and fixed-clock elapsed formatting. |
| C — persistence | COVERED | Maintenance action creates scoped run; real count aggregation commits run counters; deterministic Redis fake verifies exact execution cleanup, attempt isolation and expiry. No new business storage. |
| D — response contract | COVERED | Processed/total/percentage, independent day counts, timestamps, worker identity/task/date, unavailable and bounded activity payloads. |
| E — HTTP read after action | COVERED | `reads committed progress through HTTP after admitting a maintenance action`: POST admission → DB assertion → real committed counter aggregation → partial Inertia GET. `observes real provider lifecycle registration and serves the same execution through admin HTTP`: lifecycle event → fake Redis state assertion → authorized GET → completion cleanup. |
| F — event/queue contract | COVERED | Actual provider event registration, successful/exception/failed cleanup, duplicate notifications, retries, distinct worker identities, serialization canary and write/read/delete failure isolation. No queue dispatch introduced by telemetry. |
| G — frontend | COVERED | Prominent progress, independent phases, actual execution versus date claim distinction, fixed list height, finished row removal, unavailable-read retention, source switching and quiet polling. |

## Numbered requirements

1. SATISFIED — Current Update shows selected-game processing percentage and denominator; successful updates, excluded and failed games remain separate.
2. SATISFIED — League and context day progress remain independent. Unknown denominators do not manufacture percentages.
3. SATISFIED — Observed queue executions show task, owning run, game/date where present, queue, server, PID and elapsed time.
4. SATISFIED — Quiet refresh preserves the page and activity region. Unavailable telemetry does not become a false zero; retained rows become Unknown.
5. SATISFIED — Monitoring remains Sep2026-only. No processing admission, prediction equations, context records or production model configuration changes.
6. SATISFIED — Finish events remove exact execution observations; expiry bounds records left after worker termination. Telemetry failures cannot fail or retry business jobs.
7. SATISFIED — No migration, dependency change or new HTTP endpoint. Existing workers must load the deployed code before executions can be observed.

## Endpoint × verb authorization and scope

Only the existing GET payload gains fields. No authentication or middleware change.

| Endpoint | Guest | Ordinary member | Super admin | Model scope |
| --- | --- | --- | --- | --- |
| GET /admin/nhl-sat-models | `blocks guests from every workspace endpoint` | `blocks ordinary members from every workspace endpoint` | `reads committed progress through HTTP after admitting a maintenance action`; `observes real provider lifecycle registration and serves the same execution through admin HTTP` | `keeps the selected pilot independent of the newest production model`; `ignores production model executions`; `does not guess a pilot when its selection is ambiguous` |

This is a global super-admin tool; organization and member ownership dimensions
are not applicable. Existing PUT allocation and GET inspector authorization
datasets are retained unchanged. No partner API or Horizon HTTP access is added.

## Frontend component checklist

- COVERED — Percentage/count DOM and initial unknown selection state.
- COVERED — League and context progress bars use their own counters.
- COVERED — Executing worker identity, task, queue, date and elapsed time.
- COVERED — Claims remain distinct from observed executions.
- COVERED — Successful refresh removes completed activity without replacing the fixed list region.
- COVERED — Unavailable refresh retains prior evidence as Unknown; source change clears it.
- COVERED — Unconfirmed observations are excluded from confirmed-running count.
- COVERED — Active executions retain quiet timed updates with scroll/state preserved.
- Deterministic component fixtures, fake clock/timers and mocked router; no network.
- Responsive/browser rendering and visual comparison were not executed.

## Per-file test count

| File | it(...) declarations | Executed |
| --- | ---: | --- |
| tests/Feature/NhlPregameWorkerActivityTest.php | 30 | No |
| tests/Feature/NhlSatWorkspaceTest.php | 38 | No |
| resources/js/pages/Admin/SatModels/Index.test.js | 50 | No |

Checks actually performed: source inspection, declaration counts and
`git diff --check`. No tests, lint, builds, browser checks, migrations, queue
commands or production operations were run.

Suggested human checks:

```bash
php artisan test tests/Feature/NhlPregameWorkerActivityTest.php tests/Feature/NhlSatWorkspaceTest.php
npm run test -- --run resources/js/pages/Admin/SatModels/Index.test.js
npm run build
```

No migration is required. Telemetry uses bounded, expiring shared queue Redis
records, not the database or queue reservations. Per-execution deadlines include
30 seconds of grace; unconfirmed records are pruned 120 seconds later, with a
two-hour key expiry as a final backstop. At most 200 observations are returned.
This observes queue lifecycle events, not OS health or a retroactive process
inventory. A killed worker can remain observed until its deadline. Older jobs
already running when this code is deployed cannot be reconstructed. Date claims
remain available separately. No worker was restarted as part of this change.

Awaiting human review
