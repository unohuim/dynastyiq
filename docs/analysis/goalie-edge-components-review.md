# Individual goalie edge contributions

Approved scope: expose the two selected goalies' contributions to the existing
edge. Prediction math, edge math, weights, null matchup values, availability and
saved-revision read behavior remain unchanged. No migration is required.

## Files changed

- `app/Services/NhlGamePredictionPayload.php`
- `tests/Feature/NhlGamePredictionsMarketProbabilityApiTest.php`
- `docs/integrations/DIQ-API-Usage-Doc.md`
- `docs/architecture/stats/NhlCustomGamePrediction.yaml`
- This review document.

## Minimum coverage matrix

COVERED means authored assertions, not executed tests.

| Contract | Status | Test |
| --- | --- | --- |
| Each contribution uses the existing weights; signed values, ties, missing/null/zero terms and rounding | COVERED | exposes both weighted goalie contributions without changing the existing edge calculation (seven dataset cases) |
| API creation → immutable DB payload → subsequent HTTP read | COVERED | publishes individual goalie edge values through revision storage and saved API reads |
| Selected goalie identities and existing edge availability | COVERED | publishes individual goalie edge values through revision storage and saved API reads |
| Predicted team/total goals, winner, probabilities and internal/public confidence retained | COVERED | publishes individual goalie edge values through revision storage and saved API reads |
| Unavailable evidence remains unavailable; existing missing-term zeros retained | COVERED | preserves unavailable goalie evidence while exposing the unchanged zero contributions |
| Saved reads do not calculate, dispatch, fetch or rewrite | COVERED | publishes individual goalie edge values through revision storage and saved API reads |
| Request validation and unknown game | COVERED | requires a game identifier; rejects nonnumeric game identifiers; returns not found for an unknown game without prediction work |

## Requirements checklist

1. SATISFIED: expose `prediction.goalie_edge.components.away_value_per_game`.
2. SATISFIED: expose `prediction.goalie_edge.components.home_value_per_game`.
3. SATISFIED: preserve the original score calculation and edge interpretation.
4. SATISFIED: preserve forecasts, probabilities, confidence, weights and availability.
5. SATISFIED: retain null matchup fit rather than substituting a different metric.
6. SATISFIED: new fields persist in new revisions; old revisions remain immutable.
7. SATISFIED: official partner documentation identifies fields, units and example.

## Endpoint × verb authorization/scope checklist

| Endpoint / verb | Case | Test |
| --- | --- | --- |
| GET `/api/nhl-game-predictions` | Guest 401 | requires API authentication for new engine predictions |
| Same | Wrong scope 403 | requires the NHL statistics scope for new engine predictions |
| Same | `nhl-stats:read` 200, initial and saved response | publishes individual goalie edge values through revision storage and saved API reads |
| Same | Saved historical auth and scope remain enforced | retains authentication and scope restrictions for saved past predictions |
| Tenant/organization scope | N/A | Existing global NHL statistics endpoint; no new permission or tenant data |

## Frontend component checklist

Not applicable: no frontend file changes. Partners must read the new component
fields for individual ratings; this repository does not alter their dashboards.

## Per-file test count and execution

`tests/Feature/NhlGamePredictionsMarketProbabilityApiTest.php`: **86** `it(...)`
declarations, excluding dataset expansion (three added declarations).

Tests were not run under repository policy. Suggested human command:

```sh
php artisan test tests/Feature/NhlGamePredictionsMarketProbabilityApiTest.php
```

Static review uses `git diff --check`. No imports, queues, syncs, migrations,
production operations or forecast reruns were performed for this change.
