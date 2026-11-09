# Internal confidence restoration

Approved November 9, 2026. Reconnect the previous internal input-confidence
formula unchanged, rather than redesigning it or requiring outcome calibration
before showing a number. No tests, CI, production calculations, imports or sync
commands were run during this implementation. No migration is required.

Canonical calculation authority remains
[NhlPredictionInputServices.yaml](../architecture/stats/NhlPredictionInputServices.yaml)
and [NhlCustomGamePrediction.yaml](../architecture/stats/NhlCustomGamePrediction.yaml).

## Calculation and source wiring

The custom calculator calls the existing `confidenceScore` and
`teamInputConfidence` helpers with its actual players, final player EV+PP goal
contributions and selected-goalie confidence. The helpers themselves are
unchanged: 70% skater / 30% goalie per team, the existing 99% goal-contribution
selection, average both teams, integer rounding and 1–100 limits. Existing null
defaults remain 50%; known zero evidence remains zero. A completely zero internal
estimate is still bounded to one, as before. It is not a calibrated probability
of winning and does not affect qualification.

Skater confidence-only reads use the selected model's stored rate-bucket values
weighted by source SAT with the same usable-TOI/rate readiness rules as the
existing model inputs. Otherwise they use the existing personal training-profile
confidence. Missing personal profiles supply zero rather than borrowing a
league performance estimate's confidence. These reads do not rebuild projected
attempts, conversion rates or annual player projections. The original readiness
gate is shared so confidence cannot choose a different source policy.

Goalie confidence uses the existing exact-bucket profile-confidence ceiling and
100-historical-SAT sample scale. Opposing projected EV attempt volumes weight
the bucket confidences. Missing exact buckets receive zero weight-adjusted
confidence without dropping their exposure. An unidentified starter keeps the
existing missing-goalie default; a known starter with no matching evidence has
zero personal confidence. No goalie GSAx/goal adjustment is recalculated or
applied. Confidence lookup and the existing goalie response builder share the
same sample-confidence helper.

Prepared inputs carry player confidence and goalie bucket confidence/sample
evidence. The input-policy version invalidates older cached inputs missing
those fields; the score/winner algorithm remains unchanged. The calculator
computes confidence only after score/probability calculation. Matchup rosters,
team input scores, goalie diagnostic scores, API markets and saved revision
summaries consume those values. Existing admin UI already reads the numeric
revision field and needs no component change. Earlier revisions are not edited.
There is no return to legacy presentation boosts or discovery-win blending.

## Authored coverage matrix

These assertions are authored, not executed or confirmed passing.

| Category | Coverage |
|---|---|
| A source validity | Same-run TOI publication gate, unavailable personal evidence and missing goalie buckets |
| B exact formula | `reuses the unchanged internal confidence formula with actual skater and goalie inputs` verifies 80% skater / 60% goalie yields 74 internal confidence |
| C source persistence | Stored player/model and exact goalie sample reads; API revision records actual confidence input values |
| D/E API and history | `publishes numeric internal confidence in the API and immutable revision without changing the pick` flows API GET to revision persistence and returned numeric game/market confidence |
| F preparation/cache | Confidence fields attach during the existing input step; configuration fingerprints include the bumped input-policy version |
| G frontend | No component changes; admin already renders numeric confidence from revision summaries |

Additional cases verify the existing missing-field 50% defaults, known zero
evidence and the minimum score of one, missing goalie buckets remaining in the
opposing-attempt denominator, and the existing 99% goal-contribution selection.
Assertions preserve scores, winning probability and recommendation when only
confidence inputs change. Current API/preview expectations now require numeric
confidence; historical/mock unavailable payload cases retain their scope.

## Requirements checklist

- Existing confidence helper formulas and constants remain unchanged.
- Actual selected-model skater/goalie confidence inputs are supplied.
- Successful calculations return numeric internal confidence separately from win probability/completeness.
- Score, winner, qualification and neutral goalie performance treatment remain unchanged.
- API, previews and revision history retain their interfaces and use numeric values.
- Existing historical revisions are preserved.
- No new table, dependency or frontend state is introduced.
- Tests/CI execution remains outstanding for human review.

## Endpoint/auth scope and frontend checklist

| Endpoint | Verb | Scope | Named coverage |
|---|---|---|---|
| `/api/nhl-game-predictions` | GET | No authentication denied | Existing `requires API authentication for new engine predictions` |
| Same | GET | Missing NHL statistics scope denied | Existing `requires the NHL statistics scope for new engine predictions` |
| Same | GET | Authorized client | New numeric-confidence API/revision lifecycle case |

No route, organization scope, editing authority or browser state transition
changes. Existing admin super-admin authorization and Vue confidence rendering
remain unchanged; confidence does not become a new qualification filter.

## Per-file authored test counts

| File | it declarations |
|---|---:|
| `tests/Feature/NhlCustomGamePredictionTest.php` | 101 |
| `tests/Feature/NhlSatPredictionServicesTest.php` | 63 |
| `tests/Feature/NhlGamePredictionsMarketProbabilityApiTest.php` | 57 |

Datasets expand these counts. Full suites remain in the repository. Static
whitespace review uses `git diff --check`; no test command was run. Human-run
verification command:

```sh
php artisan test tests/Feature/NhlCustomGamePredictionTest.php tests/Feature/NhlSatPredictionServicesTest.php tests/Feature/NhlGamePredictionsMarketProbabilityApiTest.php
```
