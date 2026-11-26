# PK goal pathway review

## Files changed

- `app/Services/NhlCustomGamePredictionEngine.php`
- `app/Services/NhlCustomGamePredictionInputs.php`
- `app/Services/NhlCustomGameDefense.php`
- `app/Services/NhlCustomGameForecast.php`
- `app/Services/NhlCustomGamePredictionReplay.php`
- `app/Services/NhlGamePredictionPayload.php`
- `app/Services/NhlSeasonGameEvaluation.php`
- `config/nhl-custom-prediction.php`
- `resources/js/pages/Admin/Evaluations/SeasonGames.vue`
- `resources/js/pages/Admin/Evaluations/SeasonGames.test.js`
- `tests/Feature/NhlCustomGamePredictionTest.php`
- `tests/Feature/NhlGamePredictionsMarketProbabilityApiTest.php`
- `tests/Feature/NhlSeasonGameEvaluationTest.php`
- `docs/architecture/stats/NhlCustomGamePrediction.yaml`
- `docs/architecture/stats/NhlNextGameEvaluation.yaml`
- `docs/architecture/stats/NhlSeasonGameEvaluations.yaml`
- `docs/ARCHITECTURE_INVENTORY.md`
- `docs/ENUMS.md`
- `docs/integrations/DIQ-API-Usage-Doc.md`
- `docs/testing/pk-goal-pathway-review.md`

## Minimum coverage matrix

COVERED means authored assertions, not executed or passing tests.

| Category | Coverage | Evidence |
|---|---|---|
| A validation | COVERED | Existing API authentication, scopes, market validation and season-run guards; new negative-rate, impossible-probability and non-retained-PK bucket tests |
| B defaults/normalization | COVERED | Missing personal PK usage uses frozen league PK usage; all-zero lineup fallback; zero-opportunity handling; measured individual zero preserved |
| C persistence | COVERED | PK prediction/actual fields, player JSON, existing immutable revision evidence and season-run totals |
| D API | COVERED | publish PK components once and serve them with the saved prediction; existing moneyline/market and version assertions |
| E HTTP read | COVERED | Calculated PK revision → DB evidence assertion → authenticated saved API GET; existing season POST → persisted run → Inertia GET |
| F queues/events | COVERED | Existing per-game preparation, resume and source-version tests retained; algorithm/input versions advance so prior preparation cannot satisfy the new definition |
| G frontend | COVERED | Existing deterministic component test asserts strength-neutral opposition forecast wording; UI change is copy only |

## Requirements checklist

1. SATISFIED: PK time comes from opposing PP; regulation EV time remains unchanged.
2. SATISFIED: normalize L10 PK usage over expected skaters, allocating four skater-minutes per PK minute. Personal missing usage and entirely zero-use lineups use the documented PK league fallback, with explicit whole-lineup imputation evidence.
3. SATISFIED: retain PK facts, appearance exposure, raw personal Train/recent buckets and frozen same-strength league history in synchronous and checkpointed readers.
4. SATISFIED: use the existing Train/L10 attempt-rate blend and documented matching PK defense coefficient. Prior-season opponent PP exposure and PK attempts allowed are pooled by opponent, not stored as duplicated per-game histories.
5. SATISFIED: PK SOG/SAT and goals/SOG use personal Train PK bucket ratios; missing denominators use matching PK Train league evidence, not EV/PP conversion. This is the engine's chronological count-based conversion pathway; it does not introduce a new fitted goalie layer.
6. SATISFIED: goalie multiplier remains 1.0; schedule/form SAT multipliers are not extrapolated to PK.
7. SATISFIED: add PK production to player/team/game outputs and returned score probabilities; preserve the separately calculated PP allocation and its own PP history windows.
8. SATISFIED: persist predicted/actual PK goals in existing columns and include them in new evaluation error comparisons. Earlier rows without a PK prediction retain their original EV+PP basis; missing PK actuals remain unknown.
9. SATISFIED: version new scoring and prepared-input definitions without rewriting old revisions or performing production operations.
10. SATISFIED: canonical engine rules, API contract, enums and inventory updated. No migration required.

## Endpoint × verb × authorization/scope

| Endpoint / verb | Unauthenticated | Missing permission/scope | Allowed |
|---|---|---|---|
| `GET /api/nhl-game-predictions` | requires API authentication for new engine predictions | requires the NHL statistics scope for new engine predictions | publishes PK components once and serves them with the saved prediction; uses the new shared engine and SAT_V2 for an ordinary API request |
| Season evaluations `GET` index/details and `POST` runs/resume | denies guests all season evaluation endpoints (verb/suffix dataset) | denies non-admins all season evaluation endpoints (verb/suffix dataset) | admits a run freezes its definition and reads it through the new page; existing completed-game detail HTTP assertions |

No authorization, tenant/organization ownership, route name or lineup validation changes. Model selection and cutoff checks retain their existing tests and boundaries.

## Frontend component checklist

- COVERED: neutral goalie label no longer falsely limits an opposition total to EV+PP.
- COVERED: existing component test checks the new copy with fixed fixture values.
- No new controls, state, transitions, external calls or global JavaScript state.

## Per-file declaration counts

| Test file | `it(...)` declarations |
|---|---:|
| `tests/Feature/NhlCustomGamePredictionTest.php` | 165 |
| `tests/Feature/NhlGamePredictionsMarketProbabilityApiTest.php` | 134 |
| `tests/Feature/NhlSeasonGameEvaluationTest.php` | 42 |
| `resources/js/pages/Admin/Evaluations/SeasonGames.test.js` | 29 |

All modified test files exceed the 20-test minimum. PHP remains strict-types Pest. Fixtures use fixed clocks, fake dispatch, isolated cache and blocked stray HTTP.

## Checks actually performed

- Scoped source, migration-schema and test diff inspection.
- Test declaration counts read using `rg`.
- `git diff --check`: passed.
- No tests, lint, builds, imports, queue operations, migrations or production writes executed.

Suggested human-run PHP check, not executed by Codex:

```sh
php artisan test tests/Feature/NhlCustomGamePredictionTest.php tests/Feature/NhlGamePredictionsMarketProbabilityApiTest.php tests/Feature/NhlSeasonGameEvaluationTest.php
```

Run the existing JavaScript test runner for `resources/js/pages/Admin/Evaluations/SeasonGames.test.js` as part of human verification.

## Operational scope and limits

No migration is required: the existing custom team table already contains predicted and actual PK goals. Recompute through the normal prediction/run controls after deployment to publish the new algorithm; existing revisions remain intact. No fresh winner-accuracy measurement is claimed. The four-skater PK budget is an aggregate baseline and does not model the duration of future three-versus-five situations. Empty-net and shootout goals remain excluded from these strength production estimates.
