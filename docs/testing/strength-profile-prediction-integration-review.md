# Strength-profile prediction integration review

## Files changed or created

- `app/Services/NhlCustomGamePredictionInputs.php`
- `app/Services/NhlCustomGamePredictionEngine.php`
- `app/Services/NhlCustomGameForecast.php`
- `app/Services/NhlSeasonGameEvaluation.php`
- `config/nhl-custom-prediction.php`
- `tests/Feature/NhlPredictionStrengthProfileReferencesTest.php`
- `tests/Feature/NhlGamePredictionsMarketProbabilityApiTest.php`
- `docs/architecture/stats/NhlCustomGamePrediction.yaml`
- `docs/ENUMS.md`
- `docs/ARCHITECTURE_INVENTORY.md`
- `docs/integrations/DIQ-API-Usage-Doc.md`
- `docs/testing/strength-profile-prediction-integration-review.md`

## Minimum coverage matrix

COVERED means assertions authored or retained, not executed or passed.

| Dimension | Status | Evidence |
|---|---|---|
| A validation | COVERED | Stale certificates, changed evaluation descriptors, mismatched Train seasons, unsupported denominators and partially rebuilt profiles retain safe fallback or the existing explicit missing-SAT error. Existing route auth tests retained. |
| B normalization | COVERED | Pool expected SOG/source SAT and expected goals/source SOG; EV/PP/PK remain separate; observed personal zero stays zero; twelve-SAT threshold and 47/11/42 weights unchanged. |
| C persistence | COVERED | Frozen reference checkpoint round trip; calculated reference → publication → exact private revision evidence assertions. No schema change. |
| D API | COVERED | New saved-revision test checks returned version and score while private source counts stay private; existing response/version assertions updated. |
| E HTTP read | COVERED | New action → revision DB evidence → authenticated saved GET test retains the published score after live inputs change. |
| F asynchronous boundary | COVERED | Checkpoint preservation, source identity invalidation and no dispatch assertion; existing per-game preparation and revision tests retained. No new job/event. |
| G frontend | COVERED (not applicable) | No frontend files or UI behavior changed. |

## Requirements checklist

1. SATISFIED: Only selected-model, regular-season offensive Train rows at EV/PP/PK and retained keys supply references. Combined-strength rows and other profile types are excluded; the Test snapshot table is never read.
2. SATISFIED: Rates use pooled expected/count sums, not averages of percentages; conditional goals/SOG does not use the profile's all-attempt mean goal probability.
3. SATISFIED: Only existing league components change. Supported personal/team values, measured zeros, periods, twelve-SAT EV threshold and blend weights remain intact.
4. SATISFIED: Missing or stale evidence preserves raw fallback. Validate per-type completion, actual evaluation revisions, row provenance and Train seasons; recheck source identity after bounded loading. No build is dispatched.
5. SATISFIED: Freeze references per input preparation and checkpoint; archive reference counts, actual descriptors and selected fallback sources privately. Prepared-input and season-run identities include profile source changes.
6. SATISFIED: Algorithm and input-pipeline versions advance; old saved revisions remain immutable and immediately readable.
7. SATISFIED: TOI, SAT rates, defense factors, schedule rules, final team PP allocation and neutral goalie scoring are preserved. No migration required.
8. SATISFIED: Canonical architecture, enums, API documentation and inventory describe the new integration without claiming a measured accuracy improvement.

## Endpoint × verb × authorization/scope

No endpoint, verb, authorization or ownership rule is changed. Retained declarations in `NhlGamePredictionsMarketProbabilityApiTest.php` cover:

| Endpoint / verb | Authorization or scope | Test declaration |
|---|---|---|
| `/api/nhl-game-predictions` GET | Guest rejected | `requires API authentication for new engine predictions` |
| Same GET | Missing statistics scope rejected | `requires the NHL statistics scope for new engine predictions` |
| Same GET | Authorized calculation/publication and saved read | `archives smoothed strength references while serving an unchanged saved revision through the API` |
| Same GET | Private input evidence excluded | `archives exact private inputs without exposing them in the API response` |
| Same GET | Prior revision immutable after recalculation | `invalidates earlier algorithm cache entries while retaining the previous immutable revision` |

Existing season evaluation GET/POST authorization and model ownership tests are unchanged. No new organization or tenant scope is introduced.

## Frontend component checklist

Not applicable: no component, DOM, loading, motion, global JavaScript state or route navigation changes.

## Per-file test declaration counts

| Modified/created test file | `it(...)` declarations |
|---|---:|
| `tests/Feature/NhlPredictionStrengthProfileReferencesTest.php` | 28 |
| `tests/Feature/NhlGamePredictionsMarketProbabilityApiTest.php` | 135 |

Both exceed the repository minimum. The first file adds 28 cases; the API file adds one case and updates current-version assertions. Fixtures freeze time, fake dispatches and prohibit stray HTTP.

## Checks actually run

- Scoped source, test and documentation diff reviewed.
- `git diff --check`: passed.
- Test declaration counts inspected using `rg`.
- No tests, lint, CI, imports, migrations, builds, workers or production operations executed.

Human-run regression command (not executed by Codex):

```sh
php artisan test tests/Feature/NhlPredictionStrengthProfileReferencesTest.php tests/Feature/NhlCustomGamePredictionTest.php tests/Feature/NhlGamePredictionsMarketProbabilityApiTest.php tests/Feature/NhlSeasonGameEvaluationTest.php
```

No migration is required. Existing valid profile certificates can be consumed directly; stale/missing sources retain raw references. This code integration does not establish a new win percentage. Any season accuracy comparison requires separate explicit execution authorization.
