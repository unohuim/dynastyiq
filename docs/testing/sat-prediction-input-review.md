# SAT prediction input review

## Current API addition: pick_qualified

Files changed for this addition:

- `app/Services/NhlGamePredictionPayload.php`
- `tests/Feature/NhlGamePredictionsMarketProbabilityApiTest.php`
- `docs/integrations/DIQ-API-Usage-Doc.md`
- `docs/architecture/stats/NhlProjectedTeamMatchups.yaml`
- `docs/ARCHITECTURE_INVENTORY.md`
- `docs/testing/sat-prediction-input-review.md`

### Coverage matrix

COVERED means assertions authored, not executed.

| Requirement | Status | Test |
|---|---|---|
| Top-level boolean; confidence 72/73/74 included, 71/75 excluded | COVERED | `qualifies picks by inclusive confidence and score gap before display rounding` |
| Home/away lead, exact tie, both directions of sub-display-precision lead | COVERED | Same dataset test: five confidence values by five score pairs, 25 cases |
| Nonqualifying predictions still expose scores and market probabilities | COVERED | Same dataset test |
| One or both preseason lineups unresolved yields false | COVERED | `returns evidence without a prediction when one preseason lineup is unresolved`; `returns both missing teams when neither preseason lineup is resolved` |

### Requirements checklist

1. SATISFIED in source: exactly one added top-level boolean, no recommendation object or reason enum.
2. SATISFIED in source: qualification uses returned integer confidence and goals before display rounding.
3. SATISFIED in source: no score, confidence, winner, market or availability filtering changes.
4. SATISFIED in authored tests: fixed clock, fake/blocked provider HTTP, no global helper functions; fixtures flow through the real API payload assembly with controlled simulator results.
5. NOT VERIFIED at runtime: tests were not run, as instructed.

### Endpoint authorization/scope matrix

| Endpoint | Verb | Case | Test |
|---|---|---|---|
| `/api/nhl-game-predictions` | GET | Missing token blocked | `requires a scoped API client for game prediction market probabilities` |
| `/api/nhl-game-predictions` | GET | Wrong scope blocked | `rejects API clients without the NHL stats scope` |
| `/api/nhl-game-predictions` | GET | Authorized reader gets computed boolean | `qualifies picks by inclusive confidence and score gap before display rounding` |
| `/api/nhl-game-predictions` | GET | Organization/owner scope | Not applicable: global NHL data |

Frontend checklist: not applicable; no frontend changes.
Per-file count: `tests/Feature/NhlGamePredictionsMarketProbabilityApiTest.php`
contains **46** `it(...)` declarations. The new declaration expands to 25 cases;
two existing unavailable-response tests have additional assertions.

Human-run command (not executed by Codex):

```sh
php artisan test tests/Feature/NhlGamePredictionsMarketProbabilityApiTest.php
```

Awaiting human review.

---

## Current revision: 88O/2D production environment

The approved change uses 88% of every offensive bucket's attempts plus 2% of
defensive attempts with the exact same key, without normalizing weights or totals.
Missing matching defense adds zero; offense still receives its 88% weight.
Defense-only buckets are excluded. Historical conversion, goalie response,
confidence calculation and model selection remain unchanged. Confidence-range
and pick-gap filters and head-to-head overrides are not introduced. The legacy
historical-only simulator retains its existing formula.

Files changed in this revision:

- `app/Services/NhlSatModelPredictionService.php`
- `app/Services/NhlProjectedTeamMatchupSimulator.php`
- `tests/Feature/NhlSatPredictionServicesTest.php`
- `docs/architecture/stats/NhlPredictionInputServices.yaml`
- `docs/architecture/stats/NhlProjectedTeamMatchups.yaml`
- `docs/ARCHITECTURE_INVENTORY.md`
- `docs/integrations/DIQ-API-Usage-Doc.md`
- `docs/testing/sat-prediction-input-review.md`

### Coverage matrix

COVERED means assertions authored, not executed or passing.

| Requirement | Status | Test |
|---|---|---|
| Independent 88/2 weights and attacking conversion | COVERED | `changes attempt volume before applying offensive conversion` |
| No renormalization when matching defense is absent | COVERED | `keeps the offensive weight when no defensive evidence exists` |
| Exact keys only; preserve offensive Other/unknown; exclude defense-only keys | COVERED | `adds only exact defensive matches while retaining every offensive bucket` |
| Empty offensive input cannot generate defensive-only volume | COVERED | `does not produce attempts from defense when the offensive bucket set is empty` |
| Optional offensive weight does not imply complementary defense | COVERED | `keeps defensive weight independent of an explicit offensive weight` |
| Valid zero offensive conversion remains zero | COVERED | `does not turn a zero offensive bucket into average conversion when defense adds attempts` |
| Simulator uses new weights before unchanged goalie response | COVERED | `feeds model buckets into matchup goals and counts full-game volume once` |
| Existing historical-only path | COVERED | `retains the legacy historical 85 to 15 shape blend while preserving totals` |
| API exposes calculated values without changing stored forecasts | COVERED | `exposes paired model buckets through the real prediction endpoint` |

### Requirements checklist

1. SATISFIED in source: additive 88% offense and 2% exact-matching defense.
2. SATISFIED in source: no weight or volume normalization; offensive keys retained.
3. SATISFIED in source: existing conversion, goalie skill, confidence and model selection preserved.
4. SATISFIED in source: no confidence-range or pick-gap filtering and no history override.
5. SATISFIED in authored assertions: deterministic Pest coverage with fixed clock and blocked HTTP.
6. NOT VERIFIED at runtime: tests and prediction runs were not executed after these edits.

Endpoint authorization/scope matrix: the existing GET `/api/nhl-game-predictions`
matrix below remains applicable; no route, authentication, scope or tenant behavior
changes. The existing API integration test checks computed output from stored
forecast fixtures and verifies that the stored forecasts remain unchanged.
Frontend component checklist: not applicable; no frontend changes.

Per-file test count: `tests/Feature/NhlSatPredictionServicesTest.php` contains
**47** `it(...)` declarations, including three new cases in this revision.

Human-run command (not executed by Codex):

```sh
php artisan test tests/Feature/NhlSatPredictionServicesTest.php
```

Awaiting human review.

---

Status: implementation and test authoring only. Human review and test execution
remain outstanding. No tests or prediction reports were run for this revision.

## Historical-conversion revision (weight behavior superseded below)

- Keep predicted SAT/60 and same-run predicted TOI unchanged.
- Calculate xSOG using personal historical training-bucket SOG/SAT.
- Calculate xGF using personal historical training-bucket goals/SOG.
- Fall back separately to bucket averages when the relevant personal denominator
  is unavailable, then trained bucket probabilities if empirical averages are absent.
- Preserve valid observed zeros. Do not consume stored predicted SOG/60 or xG/60.
- Apply the same rule to Other; unavailable exact personal history uses the
  existing bucket fallback, not history from unrelated buckets.
- This earlier revision retained 85/15 production weights and a separate
  100% offense + 20% defense report experiment. The current production weights
  are documented in the 88O/2D revision below.

The reports currently on disk still describe the preceding prediction-first
experiment. They have not been regenerated for this historical-conversion revision.

## Files changed

- `app/Services/NhlSatModelPredictionService.php`
- `tests/Feature/NhlSatPredictionServicesTest.php`
- `docs/architecture/stats/NhlPredictionInputServices.yaml`
- `docs/architecture/stats/NhlProjectedTeamMatchups.yaml`
- `docs/architecture/stats/NhlSatEntityRateProjection.yaml`
- `docs/integrations/DIQ-API-Usage-Doc.md`
- `docs/ENUMS.md`
- `docs/ARCHITECTURE_INVENTORY.md`
- `docs/testing/sat-prediction-input-review.md`

## Coverage matrix

COVERED means assertions authored, not executed or passing.

| Requirement | Status | Test |
|---|---|---|
| Unchanged model SAT and TOI; historical outcomes despite available predictions | COVERED | `uses personal historical conversion instead of predicted outcome rates` |
| Missing model pair and invalid SAT | COVERED | `falls back as a pair when model TOI is absent`; `rejects an entire player when one projected bucket rate is missing`; `rejects a negative attempt projection instead of silently converting it to zero` |
| Personal conversion before pooled averages | COVERED | `uses personal bucket accuracy and finishing without replacing model attempts` |
| Missing personal history uses averages even if outcome predictions exist | COVERED | `uses bucket averages ahead of predicted outcomes when personal history is absent` |
| Preserve observed zero SOG/SAT and goals/SOG | COVERED | `preserves observed zero accuracy despite positive predicted outcomes and pooled history`; `preserves observed zero finishing instead of substituting an average` |
| Other conversion and unchanged opportunity | COVERED | `uses the matching personal Other bucket without changing its projected attempts` |
| Zero output retained through defense | COVERED | `does not turn a zero offensive bucket into average conversion when defense adds attempts` |
| Held-out/preseason exclusion | COVERED | `does not read held-out snapshots as historical projection input`; `excludes preseason profiles from static bucket conversion averages` |
| Shared simulator, goalie response, no duplicate PK volume | COVERED | `feeds model buckets into matchup goals and counts full-game volume once` |
| Stored forecasts, provenance and real HTTP payload | COVERED | `exposes paired model buckets through the real prediction endpoint` |
| Event/queue behavior | NOT APPLICABLE | No changes |
| Frontend behavior | NOT APPLICABLE | No changes |

## Requirements checklist

1. Preserve predicted xSAT and TOI: SATISFIED in implementation and authored assertions.
2. Use personal historical bucket conversion, then averages: SATISFIED.
3. Preserve historical zero outcomes: SATISFIED.
4. Keep defense and goalie calculations: SATISFIED; no new weight or skill changes.
5. Deterministic Pest suite, frozen clock, no global helpers or external HTTP: SATISFIED in authored tests.
6. Runtime verification: UNSATISFIED; tests have not been run.
7. Human completion approval: outstanding.

## Endpoint × verb authorization/scope matrix

| Endpoint | Verb | Case | Test |
|---|---|---|---|
| `/api/nhl-game-predictions` | GET | Guest blocked | `requires a scoped API client for game prediction market probabilities` |
| `/api/nhl-game-predictions` | GET | Wrong scope blocked | `rejects API clients without the NHL stats scope` |
| `/api/nhl-game-predictions` | GET | Authorized reader allowed | `exposes paired model buckets through the real prediction endpoint` |
| `/api/nhl-game-predictions` | GET | Organization/owner scope | Not applicable; global NHL data |

The changed service introduces no write HTTP endpoint. Its read integration uses
stored forecast fixtures, checks the calculated HTTP values and provenance, and
checks unchanged stored rate fields and absence of import/network side effects.

## Frontend checklist and test counts

Frontend component contract: not applicable; no frontend files changed.

| Modified test file | `it(...)` declarations |
|---|---:|
| `tests/Feature/NhlSatPredictionServicesTest.php` | 44 |

Datasets are not additional declarations. The file exceeds the 20-test minimum.

Earlier outstanding coverage remains separate: dedicated cohort-selection and
missing-TOI defensive replacement assertions; explicit pinned-run/default-run
selection and training-horizon rejection; stored-boxscore identity-only reads
and ambiguous starter refusal; historical-report confidence reuse. This revision
does not claim these earlier gaps were resolved.

## Human-run command

```sh
php artisan test tests/Feature/NhlSatPredictionServicesTest.php tests/Feature/NhlAvailabilityApiTest.php tests/Feature/NhlGamePredictionsMarketProbabilityApiTest.php
```

Awaiting human review.


## All historical bucket identities — approved grouping change

Files changed for this revision:

- `app/Services/NhlSatModelEntityRateProjectionBuilder.php`
- `docs/architecture/stats/NhlSatEntityRateProjection.yaml`
- `docs/architecture/stats/NhlModelRuns.yaml`
- `docs/ARCHITECTURE_INVENTORY.md`
- `tests/Feature/NhlSatPredictionServicesTest.php` (full edited test source)
- `docs/testing/sat-prediction-input-review.md`

Coverage below describes authored assertions, not executed results.

| Requirement | Coverage | Test |
| --- | --- | --- |
| Preserve sparse and beyond-95% buckets, dimensions and source counts; consume persisted projections with same-run TOI | COVERED | `builds separate projections for sparse buckets and buckets beyond the old share cutoff` |
| Match prior/latest training snapshots by exact key; exclude held-out values | COVERED | `matches sparse training snapshots by exact key without consuming held-out buckets` |
| Remove obsolete Other, preserve another player and historical rows; repeat rebuild without duplicates | COVERED | `removes obsolete Other on entity rebuild without touching another player` |
| Read existing legacy Other projections until rebuilt | COVERED | `preserves legacy model bucket definitions and the Other tail` |

1. SATISFIED in source: skater-offense selection has no minimum-attempt or cumulative-share gate.
2. SATISFIED in source: original keys/dimensions remain separate; prior/latest snapshots match those keys.
3. SATISFIED in source: obsolete synthetic Other is removed from the rebuilt entity; historical records remain untouched.
4. SATISFIED in documentation: active-bucket-count changes can change projected totals; existing data needs an explicit rebuild.
5. UNSATISFIED at runtime: automated tests and model rebuild were not run, per repository execution policy.

Endpoint x verb authorization/scope: no endpoint, permission or organization-scope behavior changes. Existing API matrix above remains applicable. The new builder tests cover persisted outputs through the prediction input service; build-to-HTTP coverage is not newly added in this revision.

Frontend component checklist: not applicable; no frontend changes.

Per-file count: `tests/Feature/NhlSatPredictionServicesTest.php` has **44** `it(...)` declarations (three added). No new test file or shared test state was introduced. Fixtures use the suite's frozen clock and blocked HTTP transport.

Human-run command:

```sh
php artisan test tests/Feature/NhlSatPredictionServicesTest.php
```

Stored Sep2026 projections and prediction reports have not been rebuilt. Existing event eligibility and conversion/goalie formulas remain unchanged. The legacy generic non-skater builder is outside this approved skater-offense change; current build entry points admit only skater-offense entities.

Awaiting human review.
