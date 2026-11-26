# Independent operational game predictions — review

DIQ-only implementation of the approved plan. No Gner8 files or production records were changed.
Scoring equations and engine version remain unchanged; the input preparation policy is versioned separately.

## Files changed/created

- `app/Exceptions/NhlGamePredictionPreparing.php`
- `app/Http/Controllers/Admin/NhlGamePredictionController.php`
- `app/Jobs/PrepareNhlGamePredictionJob.php`
- `app/Jobs/RefreshNhlGamePredictionJob.php`
- `app/Jobs/RunNhlGamePredictionJob.php`
- `app/Services/NhlCustomGameForecast.php`
- `app/Services/NhlCustomGamePredictionInputs.php`
- `app/Services/NhlGamePredictionHistory.php`
- `app/Services/NhlGamePredictionResponseCache.php`
- `config/nhl-custom-prediction.php`
- `docs/ARCHITECTURE_INVENTORY.md`
- `docs/DB_SCHEMA.md`
- `docs/ENUMS.md`
- `docs/architecture/application/LaravelApplicationShell.yaml`
- `docs/architecture/imports/NhlAnticipatedLineups.yaml`
- `docs/architecture/stats/NhlCustomGamePrediction.yaml`
- `docs/architecture/stats/NhlGamePredictionHistory.yaml`
- `docs/architecture/stats/NhlGamePredictionResponseCache.yaml`
- `docs/integrations/DIQ-API-Usage-Doc.md`
- `resources/js/pages/Admin/GamePredictions.vue`
- `resources/js/pages/Admin/game-predictions.test.js`
- `routes/channels.php`
- `routes/console.php`
- `tests/Feature/NhlCustomGamePredictionTest.php`
- `tests/Feature/NhlGamePredictionsMarketProbabilityApiTest.php`
- `tests/Feature/NhlManualLineupPredictionRefreshTest.php`
- `tests/Feature/NhlOperationalGamePredictionsTest.php`
- `app/Events/NhlGamePredictionStatusUpdated.php`
- `database/migrations/2026_11_25_000003_add_activity_tracking_to_nhl_game_prediction_runs.php`
- `tests/Feature/NhlIndependentGamePredictionActivityTest.php`
- `docs/analysis/independent-game-predictions-review.md`

## Finalized minimum coverage matrix

COVERED below means regression code is authored, not executed or certified passing.

| Area | Status | Coverage |
|---|---|---|
| A — validation/admission | COVERED (authored) | Existing inclusive-date validation and auth tests; independent claims, token/generation rejection, terminal callbacks, per-request variants. |
| B — normalization/defaults | COVERED (authored) | Default admin/API trigger, generation one, source identity retained, request variants isolated, queue routing. |
| C — persistence | COVERED (authored) | Admission, cursor/total, heartbeat, first start, immutable revision, recovered generation, post-publication completion. |
| D — API response | COVERED (authored) | Existing saved-success path, pending deduplication, queued continuation, publication and terminal cleanup in NhlGamePredictionsMarketProbabilityApiTest. |
| E — HTTP read | COVERED (authored) | Admission → DB → authorized admin GET with trigger/progress/timing; latest background work independent of historical run selection; private inputs/claim tokens omitted. |
| F — queue/event | COVERED (authored) | Independent games, projections routing, bounded yield, cold source-evidence paging, stale delivery fencing, abandoned recovery, private Reverb identifiers. |
| G — frontend | COVERED (authored) | Separate saved/attempt timestamps, stage/cursor/lock reason, quiet polling, Reverb coalescing, mounted rows, listener/timer cleanup, preserved scores. |

## Numbered requirements checklist

1. SATISFIED in code — each operational game owns its preparation key, cache lock and checkpoint. The normal worker passes an explicit game ID, resolves only its roster/context and never waits for another game's preparation. League history still supplies the unchanged calibration equations; this does not invent a shortcut around required source data.
2. SATISFIED in code — admin, scheduled, API, injury and lineup-triggered predictions use projections; lineup acquisition remains on lineups. Healthy saved API reads remain immediate.
3. SATISFIED in code — first checkpoint precedes source evidence, roster resolution and context hydration. Cold source hashing yields after one configured page; historical collection/fitting already use bounded pages. No timeout increase masks initialization.
4. SATISFIED in code — durable dispatch deduplication prevents repeated API polls from admitting duplicate deliveries. Claims reject competing workers; generation/token checks guard progress, publication and finish. Expired tokens require fenced recovery before replacement.
5. SATISFIED in code — a minute watchdog revisits five-minute abandoned activity, preserves checkpoints and first-start time, and schedules a new generation. Three abandoned recoveries produce a visible terminal failure. A committed revision is reconciled before enforcing this limit. New timeout failures record terminal status immediately; a hard-killed worker without a failure callback is recovered by the watchdog. The existing one-day calculation deadline and shared Redis retry_after remain unchanged.
6. SATISFIED in code — all calculation triggers have durable activity rows. Queued and running rows protect lineup writes. New tracked calculations do not add a second long-lived cache input lease, so terminal cleanup releases their protection. Legacy refresh-only leases are retired when their tracked work is admitted/claimed.
7. SATISFIED in code — admin activity shows the latest attempt across all triggers, original saved timestamp, current phase/progress/heartbeat, delivery/exception/recovery counters, elapsed time and lineup-lock reason. Reverb invalidates a quiet read; polling and last saved results remain available.
8. SATISFIED in code — scoring formulas, model eligibility, actual-versus-anticipated lineup authority, data-first context fallback, source-age days_away and post-publication context repair remain intact. Source-only checks used by pregame builders preserve their previous contract; bounded/scoped evidence applies specifically to operational prediction preparation.
9. SATISFIED in authored coverage/docs — regression suites and canonical architecture/schema/API documentation updated. Runtime validation remains pending human execution.

## Endpoint × verb authorization/scope matrix

| Endpoint | Guest | Authenticated without super-admin role | Super admin | Organization/owner scope |
|---|---|---|---|---|
| GET /admin/game-predictions | `blocks guest reads of prediction activity` | `blocks non-admin reads of prediction activity` | `admits admin work and reads its trigger and lineup protection via HTTP`; `persists background API admission before any forecast input is read` | N/A: existing global super-admin endpoint |
| POST /admin/game-predictions/runs | `blocks guest prediction admission` | `blocks non-admin prediction admission` | `admits admin work and reads its trigger and lineup protection via HTTP` | N/A: existing global super-admin endpoint |
| GET /admin/game-predictions/games/{game}/revisions and /revisions/{revision} | `blocks guests on every operational endpoint` dataset | `blocks ordinary users on every operational endpoint` dataset | Existing revision pagination and full-evidence read tests in NhlOperationalGamePredictionsTest | N/A: existing global super-admin endpoints |
| POST /broadcasting/auth, private-admin.predictions | `authorizes the private prediction channel only for super admins` guest dataset | Same test, member dataset | Same test, admin dataset | N/A: global super-admin channel; no prediction inputs in the event |
| Existing partner prediction and lineup routes | Existing MarketProbabilityApi and ManualLineupPredictionRefresh auth/scope suites retained | Existing wrong-scope/unauthorized cases retained | Existing authorized API fixtures and saved/pending response tests retained | Existing partner token scope and lineup authority unchanged |

## Frontend component contract checklist

- Authored: saved forecast and new attempt render separately; older successful scores remain while replacements run or fail.
- Authored: cursor/denominator, counters and lineup-lock explanation come from the compact admin payload.
- Authored: checkpoint broadcasts coalesce into a single read; no new page-global state.
- Authored: polling failure retains rows and inserts no background error banner.
- Authored: polling works without Echo and after a missed invalidation.
- Authored: component teardown aborts requests, cancels timers and removes its own listener.
- Authored: fake timers, fixed dates and stubbed fetch/Echo; no external network.

## Per-file test count report

Counts are test declarations, with parameterized datasets expanding at execution.

| File | Declarations | Minimum 20 |
|---|---:|---|
| `tests/Feature/NhlIndependentGamePredictionActivityTest.php` | 44 | Met |
| `tests/Feature/NhlCustomGamePredictionTest.php` | 144 | Met |
| `tests/Feature/NhlGamePredictionsMarketProbabilityApiTest.php` | 108 | Met |
| `tests/Feature/NhlManualLineupPredictionRefreshTest.php` | 32 | Met |
| `tests/Feature/NhlOperationalGamePredictionsTest.php` | 48 | Met |
| `resources/js/pages/Admin/game-predictions.test.js` | 73 | Met |

## Checks actually performed

- Read source and reviewed the targeted diff, queue admission, checkpoint boundaries, source fingerprints, lock order, publication fencing and UI lifecycle.
- `git diff --check` performed; final staged check is recorded in the handoff.
- Test declaration counts inspected from the source files.
- No Pest, Vitest, PHP lint, CI, build, import, queue, scheduler, migration or production operational commands were run. No runtime speed or production success claim is made.

## Migration and rollout

**Migration required:** `database/migrations/2026_11_25_000003_add_activity_tracking_to_nhl_game_prediction_runs.php`.
The human must run **`php artisan migrate --force` before using the changed tracker or starting the new workers**.
This approval covers code and push, not a manual production deployment, worker operation or recovery command.

The existing scheduler invokes recovery every minute after deployment. Serialized older refresh jobs are admitted into the new tracker only if no newer tracked attempt supersedes them. Old date-wide preparation caches are intentionally isolated by the new game-v6 namespace; existing stored forecast revisions remain available. An incomplete date-wide cache checkpoint is not imported into the new game-owned state. The new game starts its own bounded checkpoint, while already committed statistics and contexts are reused.

Suggested human validation (not executed):

```sh
php artisan test tests/Feature/NhlIndependentGamePredictionActivityTest.php tests/Feature/NhlCustomGamePredictionTest.php tests/Feature/NhlOperationalGamePredictionsTest.php tests/Feature/NhlManualLineupPredictionRefreshTest.php tests/Feature/NhlGamePredictionsMarketProbabilityApiTest.php tests/Feature/NhlDataFirstPredictionContextTest.php tests/Feature/NhlPredictionLineupReadTest.php tests/Feature/NhlLineupInjurySafetyTest.php
npx vitest run resources/js/pages/Admin/game-predictions.test.js
```

Runtime checks after human-authorized deployment: run two games concurrently, confirm separate cursors, confirm a waiting/failed game does not prevent the other publishing, verify live admin activity without losing saved scores, and verify queued/running lineup writes return 409 while terminal work releases the lock. Exercise interrupted-delivery recovery in a controlled environment before any production failure simulation.

Awaiting human review

## Approved status wording follow-up

Files: `resources/js/pages/Admin/GamePredictions.vue`, its existing
`game-predictions.test.js` suite, and the canonical history architecture rule.

COVERED (authored): an existing game without an attempt displays Queued; a queued
attempt displays Queued without duplicate phase wording; a running first
calculation displays Running with its stage. Saved renders only with an actual
saved timestamp. Database status, queue admission and prediction calculations are
unchanged. Existing endpoint authorization/scope coverage above remains applicable;
no endpoint changed. Requirements for both Queued cases, running-stage display and
omitting Saved — are SATISFIED in the authored UI and tests. Frontend transitions
use the existing polling data and fixed-time, stubbed-network tests. The modified
frontend file has 73 test declarations, exceeding the 20-test minimum. Tests were
not executed. No migration is required for this wording change.

Awaiting human review
