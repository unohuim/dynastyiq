# Complete predictions include ready expected-goal Matchup statistics

## Files changed

- `app/Http/Controllers/Api/NhlGamePredictionsController.php`
- `app/Jobs/RefreshNhlPublicGamePageJob.php`
- `app/Services/NhlCustomGameForecast.php`
- `app/Services/NhlGamePredictionPayload.php`
- `app/Services/NhlGamePredictionResponseCache.php`
- `app/Services/NhlPublicGamePage.php`
- `tests/Feature/NhlGamePredictionsMarketProbabilityApiTest.php`
- `tests/Feature/NhlPublicGamePageTest.php`
- `tests/Feature/NhlSatPredictionServicesTest.php`
- `docs/architecture/stats/NhlCustomGamePrediction.yaml`
- `docs/architecture/stats/NhlGamePredictionHistory.yaml`
- `docs/architecture/stats/NhlGamePredictionResponseCache.yaml`
- `docs/architecture/ui/NhlPublicGamePage.yaml`
- `docs/integrations/DIQ-API-Usage-Doc.md`
- `docs/ARCHITECTURE_INVENTORY.md` (prediction-readiness entry only)
- This review document.

## Minimum coverage matrix

COVERED means assertions are authored; no runtime pass is claimed.

| Behavior | Status | Named coverage |
| --- | --- | --- |
| API waits and does not publish an incomplete revision | COVERED | `waits for expected Matchup statistics before publishing a completed prediction` |
| Worker → revision → API data flow | COVERED | `publishes the worker-prepared expected values through revision storage and subsequent API reads` |
| Stored Matchup survives public invalidation and preparatory expiry | COVERED | `returns the frozen Matchup after preparation expiry and public page invalidation` |
| Explicit missing-rating error | COVERED | `reports missing expected goal history rather than publishing empty completed predictions`; `reports entirely unrated windows without manufacturing expected goals` |
| State-independent historical preparation and measured zero | COVERED | `prepares complete expected statistics independently of game state and public cache version` (FUT/LIVE/FINAL and scored zero datasets) |
| Deduplication and source isolation | COVERED | `coalesces source-bound expected-stat work separately from public refreshes`; ready-preparation test changes source identity and expects preparing |
| Exhausted worker failure | COVERED | `exposes exhausted Matchup preparation errors and leaves ordinary public refreshes unchanged` |
| Existing public optional behavior and no request-time shot scan | COVERED | Existing `does not call the expected goal reader during a public Matchup request`; ordinary public preparation tests retained |
| Existing shared-engine API integration | COVERED | Existing single-default API integration in NhlSatPredictionServicesTest now supplies its ready historical Matchup fixture |

## Numbered requirements checklist

1. SATISFIED: Successful new predictions require numeric season and L5 xGF/xGA for both teams before publication.
2. SATISFIED: Existing HTTP 202 dependency flow waits for the historical Matchup worker and archives no placeholder revision.
3. SATISFIED: Source-bound preparation runs on the existing default queue via the existing public-page job's optional prediction fingerprint. Ordinary public refresh constructors, queue and identity remain compatible.
4. SATISFIED: Separate expiring keys, an atomic lock, source identities and deduplicated admission isolate prediction readiness from public-page invalidation.
5. SATISFIED: Completed Matchup is frozen in existing revision payload JSON. API no longer replaces a successful prediction's Matchup after cache resolution. Older revisions are not rewritten.
6. SATISFIED: Missing rated histories expose errors naming affected team/window/metric. Exhausted worker failures expose the underlying preparation error. No fabricated zero is substituted.
7. SATISFIED: Existing partial-rated aggregation remains unchanged; measured zero is valid, and unavailable norm comparisons remain unknown.
8. SATISFIED: The expectation worker reads strictly prior-date historical results in every target game state, using the existing statistics reader without provider/live-target reads.
9. SATISFIED: Engine equations, goals, probability, internal/public confidence, authorization and qualification are unchanged.
10. SATISFIED: No new table, migration, dependency or frontend change. A new response-cache namespace prevents earlier incomplete payloads from satisfying new requests; new calculations produce new revisions.

## Endpoint × verb authorization/scope checklist

| Endpoint and verb | Caller | Expected | Test |
| --- | --- | --- | --- |
| `/api/nhl-game-predictions` GET | Guest | 401 | `requires API authentication for new engine predictions` |
| Same | Token without `nhl-stats:read` | 403 | `requires the NHL statistics scope for new engine predictions` |
| Same | Scoped token, pending expected values | 202 | `waits for expected Matchup statistics before publishing a completed prediction` |
| Same | Scoped token, ready expected values | 200 with stored Matchup | `publishes the worker-prepared expected values through revision storage and subsequent API reads` |
| Same | Scoped token, missing required historical ratings | 422, `errors.matchup` | `reports missing expected goal history rather than publishing empty completed predictions` |
| Same | Organization/owner checks | Not applicable to global NHL read; no tenant mutation | Existing contract unchanged |

## Frontend component checklist

Not applicable: no frontend files or interactive behavior changed.

## Per-file test counts

- `tests/Feature/NhlGamePredictionsMarketProbabilityApiTest.php`: 71 `it(...)` declarations.
- `tests/Feature/NhlPublicGamePageTest.php`: 105 `it(...)` declarations.
- `tests/Feature/NhlSatPredictionServicesTest.php`: 63 `it(...)` declarations.

All exceed the twenty-declaration minimum. Tests retain fixed clocks, faked
queues and blocked HTTP; queue execution is explicitly driven only by test code.
Whole test files are updated on disk. No automated tests were executed.

## Verification and deployment

Only source review and `git diff --check` were performed. No worker, import,
scoring, prediction, migration or test command was run. No migration is required
for this change. Existing historical predictions remain immutable; the next
calculation publishes the ready Matchup in a new revision. Entirely unrated
required windows must be imported/scored before a new prediction can complete.

Human-run checks:

```sh
php artisan test tests/Feature/NhlGamePredictionsMarketProbabilityApiTest.php tests/Feature/NhlPublicGamePageTest.php tests/Feature/NhlSatPredictionServicesTest.php
```

Unrelated import-index files, tests and documentation hunks remain outside this scope.
