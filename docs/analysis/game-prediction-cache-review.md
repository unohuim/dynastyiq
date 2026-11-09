# Prediction response-cache age update

Approved November 9, 2026: extend matching stale-response retention from five
minutes to three hours. Fresh age remains sixty seconds. The existing atomic
lock, deduplicated background refresh, evidence invalidation and explicit force
refresh behavior are unchanged. The canonical contract is
[NhlGamePredictionResponseCache.yaml](../architecture/stats/NhlGamePredictionResponseCache.yaml).

The same retention constant governs both response age acceptance and cache TTL.
Retention starts when the prediction is published; stale reads do not extend
it. Matching older responses are served only while a refresh is admitted.
Changed model/configuration, lineup/starter or statistical evidence still
invalidates a response immediately. No scoring or pregame algorithm changes.

New cache writes receive the extended TTL. Existing entries keep their original
expiry until refreshed; no cache flush or operational command is required.
No migration is required. Tests/CI were not run; static whitespace review uses
`git diff --check` only.

## Authored coverage matrix

These assertions are authored, not executed or confirmed passing.

| Category | Coverage |
|---|---|
| A validation | Existing request/auth/scope cases remain; no request fields changed |
| B age boundaries | Fresh at 59 seconds; stale at 61, 301, 3,600 and 10,799 seconds; recalculation at 10,800 and 10,801 seconds |
| C revision persistence | Stale reads keep one saved revision; expiration and forced/changed-evidence builds append a new revision |
| D/E API lifecycle | `reuses a two hour old API revision with a queued refresh and replaces it after three hours` exercises authenticated GET, DB revision persistence, stale GET reuse and expired GET recalculation |
| F background refresh | `serves matching predictions beyond five minutes until the three hour limit with one queued refresh` asserts repeated stale requests deduplicate the refresh |
| G frontend | Not applicable: no frontend changes |

Additional focused cases are `honors force refresh inside the extended stale
window` and `invalidates changed engine evidence inside the extended stale
window`. Existing source/lineup correction cases remain applicable. Timing
tests that use 300 seconds for engine elapsed time are unrelated and unchanged.

## Requirements checklist

- Stale acceptance and TTL extend to three hours.
- The sixty-second fresh window remains.
- Stale reads queue refresh without appending history themselves.
- Expiration, explicit force and changed evidence still calculate anew.
- API and canonical architecture documentation match the implementation.
- No migration, schema, scoring, pregame or queue assignment changes.
- Runtime tests remain for the human to run after review.

## Endpoint/auth scope matrix

| Endpoint | Verb | Scope | Named coverage |
|---|---|---|---|
| `/api/nhl-game-predictions` | GET | No API authentication, denied | `requires API authentication for new engine predictions` |
| Same | GET | Missing NHL scope, denied | `requires the NHL statistics scope for new engine predictions` |
| Same | GET | Authorized client | New two-hour/three-hour API lifecycle test |

Admin route authorization, organization scope and frontend behavior are
unchanged. Existing super-admin operational endpoint matrix remains in place.

## Per-file test counts

| File | Authored it declarations |
|---|---:|
| `tests/Feature/NhlOperationalGamePredictionsTest.php` | 43 |
| `tests/Feature/NhlGamePredictionsMarketProbabilityApiTest.php` | 56 |

Datasets expand those counts. The full suites remain at the listed paths.
Human-run verification command:

```sh
php artisan test tests/Feature/NhlOperationalGamePredictionsTest.php tests/Feature/NhlGamePredictionsMarketProbabilityApiTest.php
```
