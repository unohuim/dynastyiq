# Prediction-triggered pregame readiness review

Approved scope: include today's unfinished games in incremental updates and
prepare missing model-owned game context before operational prediction. Reuse
existing persistence and queue pathways. No migration is required. No production
jobs, imports, workers, migrations, prediction runs or test commands were run.

## Behavior

- Update Pregame and auto-sync select missing completed games plus today's
  unfinished regular-season games in Toronto, within the selected model's Test
  season or its prediction season when Test is absent. Train is only an input.
- Requested predictions may prepare explicit scheduled games on another date or
  in any state. Freeze anticipated skaters, selected starters, prior-result
  cutoff, model and source identities before queuing context.
- Readiness requires both teams, the selected players/starters with same-team
  links, matching fingerprints and completed comparisons. Missing target
  boxscores do not prevent building future context; missing identities remain
  explicit errors.
- One shared date/roster preparation checkpoints Train collection, independent
  defensive bucket fitting, prior-result pages and one target at a time. It
  retains personal histories only for selected skaters while preserving the full
  league Train fallback. Existing scoring equations are unchanged.
- API requests return HTTP 202 preparing with Retry-After 15 while dependencies
  are pending. Refresh/manual jobs wait with a fixed one-day deadline, then
  automatically archive and publish successful predictions. Preparing is not a
  prediction revision. Source changes invalidate pending inputs before publish.
- No new table, route, UI component, queue allocation or engine formula is added.
  New preparation jobs use projections; API continuation stays on lineups.

## Changed surfaces

Production code: `NhlCustomGameForecast`, `NhlCustomGamePredictionInputs`,
`NhlCustomGameDefense`, `NhlPregameContextOrchestrator`,
`NhlPregameContextBuilder`, `NhlPregameLeagueResults`,
`NhlGamePredictionResponseCache`, `PrepareNhlGamePredictionJob`,
`NhlGamePredictionPreparing`, `RefreshNhlGamePredictionJob`,
`RunNhlGamePredictionJob`, `ReplayNhlCustomGamePredictionsCommand` and
`config/nhl-custom-prediction.php`.

The API usage guide, canonical prediction/context/cache/history YAML, engine
walkthrough, enums and derived inventory describe the revised behavior. Existing
local import-index migration/schema work and Finder files are unrelated.

## Coverage matrix

COVERED means authored assertions only, not executed or passing tests.

| Area | Status | Named assertions |
|---|---|---|
| Validation and scope | COVERED | `rejects requested-date prediction combined with replay-only options`; `requires a complete selected SAT model for requested-date predictions`; retained API scope and admin authorization cases |
| Model/date normalization | COVERED | `includes todays unfinished games in Update Pregame using anticipated evidence`; `skips fresh unfinished context instead of rebuilding every automatic update`; `queues explicit prediction dependencies for scheduled games outside todays date` |
| Persistence | COVERED | `saves upcoming player and starter context without target boxscores or target outcomes`; `requires committed comparisons and the same lineup before accepting saved prediction context`; `rejects a selected starter without the correct same-game team context link` |
| Response and HTTP reads | COVERED | `returns an explicit preparing response and queues automatic prediction continuation`; `automatically saves and publishes the prediction when queued dependencies become ready` covers pending GET, worker continuation, revision persistence, successful GET and cache-hit idempotency |
| Queue/recovery | COVERED | `coalesces repeated pending API requests without saving placeholder predictions`; `releases pending refresh dependencies without exhausting delivery attempts`; `admits a new refresh after a terminal queued refresh failure`; `keeps manual game work queued while pregame context is preparing` |
| Temporal correctness | COVERED | `freezes forecast context before scheduled start and excludes the targets own result updates`; `builds queued inputs from saved contexts in bounded pages without reading target outcomes` |
| Calculation continuity | COVERED | `preserves the defensive equation across serialized collection and fit checkpoints`; `keeps the full Train league fallback while checkpointing only selected player histories` |
| Shared entry points | COVERED | `queues a cold date once and shares completed prepared inputs across its game requests`; `prints requested-date predictions through the shared operational command pathway` |
| Frontend | NOT APPLICABLE | No frontend edits; existing admin run polling reads queued/running/completed status. Consumers must honor documented API 202 responses. |

## Requirements checklist

1. Authored: today selection uses America/Toronto and excludes tomorrow from
   ordinary updates.
2. Authored: explicit prediction requests queue their own missing dependencies.
3. Authored: own-model snapshots, same-game player/team links and prior-result
   cutoffs are preserved.
4. Authored: future/live context can be built without completed target summaries.
5. Authored: Train snapshots are outside incremental/request-triggered work.
6. Authored: repeated requests share work and automatically continue predictions.
7. Authored: bounded checkpoints prevent a whole season replay inside HTTP or a
   single preparation delivery.
8. Authored: no preparing placeholder is saved as a prediction; completed
   revisions retain existing history/publication rules.
9. Authored: API documentation describes 202, Retry-After, polling and refresh.
10. Unverified: runtime tests, queue throughput and production deployment.

## Authorization and scope matrix

| Endpoint | Verb | Scope | Named coverage |
|---|---|---|---|
| `/api/nhl-game-predictions` | GET | Existing bearer authentication and nhl-stats:read | `requires the NHL statistics scope for new engine predictions`; pending/ready API assertions above |
| `/admin/game-predictions/runs` | POST | Existing super-admin group | `blocks guests on every operational endpoint`; `admits one date and persists the requesting admin and stack`; pending manual case above |
| Model context update | POST | Guest denied | `denies dashboard actions to guests` update-route dataset |
| Model context update | POST | Non-super-admin denied | `denies dashboard actions to non super admins` update-route dataset |
| Model context update | POST | Super-admin, SAT training model | `includes todays unfinished games in Update Pregame using anticipated evidence`; `rejects non SAT models`; `rejects updates for a model outside the training registry` |
| Existing progress/history reads | GET | Retained route protection | `allows super admins to read stored schedule rows without calculating`; `lets super admins inspect immutable revision history and full evidence`; update POST-to-progress assertion |

No organization scope is added to these global super-admin workflows. Explicit
prediction variants and model ownership retain existing boundaries.

## Frontend checklist

- No new markup, interaction, animation or global JavaScript state.
- Existing manual run progress remains queued while dependencies are pending.
- Partner integrations receive the documented pending JSON instead of an old pick.

## Test file counts and operator verification

| File | Authored it declarations |
|---|---:|
| `tests/Feature/NhlCustomGamePredictionTest.php` | 85 |
| `tests/Feature/NhlGamePredictionsMarketProbabilityApiTest.php` | 52 |
| `tests/Feature/NhlModelPregameBuildTest.php` | 95 |
| `tests/Feature/NhlOperationalGamePredictionsTest.php` | 33 |

Dataset cases expand these counts. All four files exceed the twenty-test minimum;
full authored suites are in the linked repository files. Static whitespace review
uses `git diff --check`; no tests or CI were executed. After review, the human can
run the repository's approved Pest command scoped to these four files. Runtime
results and production performance remain unverified; no new win-rate claim is
made by this readiness change.
