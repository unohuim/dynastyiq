# Partner prediction status — review

Approved DIQ-only additive status payload for Gner8's prediction progress bar.

## Files changed/created

- `app/Http/Controllers/Api/NhlGamePredictionsController.php`
- `app/Services/NhlGamePredictionHistory.php`
- `docs/integrations/DIQ-API-Usage-Doc.md`
- `docs/architecture/stats/NhlGamePredictionHistory.yaml`
- `docs/architecture/stats/NhlGamePredictionResponseCache.yaml`
- `docs/ENUMS.md`
- `tests/Feature/NhlGamePredictionsMarketProbabilityApiTest.php`
- `docs/analysis/prediction-api-status-review.md`

## Finalized minimum coverage matrix

COVERED means authored coverage; tests have not been executed.

| Area | Status | Test evidence |
|---|---|---|
| A — validation/auth | COVERED (authored) | Existing `requires API authentication for new engine predictions` and `requires the NHL statistics scope for new engine predictions` now assert no status disclosure; existing malformed/missing game/market validation retained. |
| B — normalization | COVERED (authored) | `uses indeterminate progress when the current stage has no known denominator`, `does not divide by a zero stage denominator`, `preserves a measured zero percent progress value`, `bounds the visual stage percentage while preserving the recorded counts`. |
| C — persistence | COVERED (authored) | `returns completed activity and the saved revision identity after an actual API calculation` flows API calculation → stored completed work → subsequent HTTP read; `keeps live status out of immutable prediction payloads and response caches`. |
| D — public response | COVERED (authored) | Saved/queued/running/failed/unavailable responses, legacy completion, publication binding, independent timestamps, retries/recoveries and private-field exclusion. |
| E — read/poll | COVERED (authored) | `reads completed status repeatedly without preparing or dispatching more predictions`, `does not report another games attempt as the requested games activity`, and original saved-revision/market/lineup contracts. |
| F — queue behavior | COVERED (authored) | Existing continuation/dedup/retry tests retained; `keeps historical requests without a saved prediction unavailable without admitting work`. Status reads admit no new work. |
| G — frontend | N/A | No frontend or Gner8 code changed. |

## Numbered requirements checklist

1. SATISFIED in code/authored coverage: read the latest tracked attempt for the requested game using compact indexed activity data, independent from the saved forecast.
2. SATISFIED in code/authored coverage: HTTP 200 retains the full existing prediction, markets, lineup, revision and publication timestamp. Add completion timestamps without relabeling the saved forecast as newly calculated.
3. SATISFIED in code/authored coverage: HTTP 202 keeps its envelope and Retry-After 15 and adds the same status block for queued/running work.
4. SATISFIED in code/authored coverage: expose current stage, counts/denominator, stage-only percentage/indeterminate flag, elapsed seconds, ISO UTC timing, deliveries, retries and recoveries. Do not manufacture overall progress.
5. SATISFIED in code/authored coverage: retain an older successful forecast alongside a queued/running/failed/unavailable replacement; no raw SQL/errors, request inputs, claims or internal confidence are exposed.
6. SATISFIED in code/authored coverage: preserve legacy and historical behavior. Saved publications without tracked work derive completion from their publication; unknown timing stays unavailable. Historical missing forecasts enqueue nothing.
7. SATISFIED in code/authored coverage: bind publication fields to the forecast actually returned even if a newer publication appears during the request. Activity remains live response metadata and is not persisted/cached into a revision.
8. SATISFIED in docs: official usage guide includes field types/units, completed/preparing examples, stage-reset behavior and polling instructions. No new migration, scoring change, worker operation or Gner8 modification.

## Endpoint × verb authorization/scope matrix

| Endpoint | Guest | Wrong API scope | Authorized NHL stats scope | Organization/owner |
|---|---|---|---|---|
| GET /api/nhl-game-predictions | `requires API authentication for new engine predictions` → 401, no prediction_status | `requires the NHL statistics scope for new engine predictions` → 403, no prediction_status | `returns completed activity and the saved revision identity after an actual API calculation`; `adds queued activity to the existing 202 preparing response without a saved forecast` → 200/202 | N/A: existing partner token scope; no organization/owner boundary added |

## Frontend component checklist

Not applicable. The API supplies the data for Gner8 to build its own status bar;
this change does not modify Gner8 or any DIQ component.

## Per-file test count report

`tests/Feature/NhlGamePredictionsMarketProbabilityApiTest.php`: **133 `it(...)`
declarations**, including 25 new status cases; minimum 20 met. Parameterized
existing tests expand further at execution. Fixed test clock, fake queue/events,
prevented external HTTP and local fixtures remain in place.

## Checks actually performed

- Read and reviewed both saved/preparing controller paths, durable tracker fields, public serialization, immutable revision/cache boundaries and official API contracts.
- Inspected the diff and test declaration count.
- `git diff --check` passed before staging; staged whitespace review is part of the handoff.
- No tests, CI, build, PHP lint, migration, queue, sync or production operational command executed. Runtime validation remains for the human.

Suggested human command (not executed):

```sh
php artisan test tests/Feature/NhlGamePredictionsMarketProbabilityApiTest.php tests/Feature/NhlIndependentGamePredictionActivityTest.php tests/Feature/NhlPredictionLineupReadTest.php
```

No migration required. Existing admission/retry behavior is preserved; the new
block is descriptive. Gner8 should continue checking prediction_available to
know whether a saved forecast is included, separately from the latest attempt's
status. A terminal block with a saved forecast does not invalidate that forecast.

Awaiting human review
