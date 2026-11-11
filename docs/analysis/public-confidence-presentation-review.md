# Public confidence in admin and partner predictions

## Files changed

- `app/Http/Controllers/Api/NhlGamePredictionsController.php`
- `resources/js/pages/Admin/GamePredictions.vue`
- `resources/js/pages/Admin/game-predictions.test.js`
- `tests/Feature/NhlGamePredictionsMarketProbabilityApiTest.php`
- `tests/Feature/NhlOperationalGamePredictionsTest.php`
- `docs/architecture/stats/NhlGamePredictionHistory.yaml`
- `docs/architecture/stats/NhlGamePredictionResponseCache.yaml`
- `docs/architecture/stats/NhlCustomGamePrediction.yaml`
- `docs/integrations/DIQ-API-Usage-Doc.md`
- `docs/UI_DESIGN.md`
- `docs/ARCHITECTURE_INVENTORY.md` (public-confidence presentation entry only)
- This review document.

## Minimum coverage matrix

COVERED denotes written deterministic assertions, not an executed test result.

| Behavior | Status | Named coverage |
| --- | --- | --- |
| Replace Qualified, label internal confidence, add stored public score/horizon | COVERED | `replaces qualification with separate public confidence and forecast horizon columns` |
| Admin persisted fields available in rows/history | COVERED | `keeps internal and public confidence plus days away available to super admin game rows and history` |
| Legacy missing fields and explicit zeros | COVERED | `shows missing historical public confidence and horizon without inventing values`; `preserves zero public confidence and zero days away as explicit values` |
| Expanded history and table alignment | COVERED | `shows future forecast horizons and both confidences in expanded history`; `keeps revision disclosure rows aligned under the timing column` |
| API aliases public rating and removes game/team internals | COVERED | `returns public confidence in game and market fields separately from winning probability` |
| Request → persisted internal evidence → API public read | COVERED | `publishes public confidence while retaining numeric internal confidence in the immutable revision` |
| All markets and stale cached responses preserve internal revision bytes | COVERED | `returns public confidence for every market on fresh and stale API reads without rewriting internal evidence` |
| Missing public rating has no internal fallback | COVERED | `does not substitute internal confidence when an older cached public score is missing` |
| Internal input coverage remains privately available | COVERED | `completes missing observed player evidence instead of rejecting the game` now inspects stored diagnostics, not partner output |

## Numbered requirements checklist

1. SATISFIED: Admin main table shows Internal Conf, Public Conf and Days Away; Qualified is removed from the display.
2. SATISFIED: Both confidences use percentage-rating units; days away uses the revision's integer value. Missing fields show an unavailable marker, while zero remains visible.
3. SATISFIED: Expanded revision history labels both confidences and shows the stored horizon; disclosure/empty rows span all twelve columns.
4. SATISFIED: API prediction.confidence_score and all market confidence_score fields return the stored public score, retaining prediction.public_confidence_score explicitly.
5. SATISFIED: API omits prediction.confidence_inputs, teams.*.input_confidence_score and private game-confidence envelopes. Separate player/goalie evidence-quality fields retain their existing meanings.
6. SATISFIED: API transformation is response-only, including fresh/stale cache hits; cached payloads and immutable revisions preserve internal values for engine/admin use.
7. SATISFIED: A missing public score returns null without exposing an internal substitute. Formula, probabilities, goals, qualification and lineup behavior are unchanged.
8. SATISFIED: No schema migration, route, authorization, queue, motion or dependency changes.
9. SATISFIED: Partner API usage and canonical architecture/UI documentation describe the outward confidence semantics.

## Endpoint × verb authorization/scope checklist

| Endpoint / verb | Caller | Expected | Named test |
| --- | --- | --- | --- |
| `/api/nhl-game-predictions` GET | Guest | 401 | `requires API authentication for new engine predictions` |
| Same | Token without nhl-stats:read | 403 | `requires the NHL statistics scope for new engine predictions` |
| Same | Scoped token | Public score, no internal game/team breakdown | `returns public confidence in game and market fields separately from winning probability` |
| `/admin/game-predictions` GET and `/admin/game-predictions/games/{game}/revisions` GET | Guest | Rejected | `blocks guests on every operational endpoint` dataset |
| Same admin reads | Ordinary user | Rejected | `blocks ordinary users on every operational endpoint` dataset |
| Same admin reads | Super admin | Internal and public scores, stored horizon | `keeps internal and public confidence plus days away available to super admin game rows and history` |
| All | Organization/ownership | Not applicable: global NHL data, existing super-admin/API scope boundaries unchanged | Existing endpoint contracts |

## Frontend component checklist

- COVERED: Headers, correct units, zero/missing values and stored horizons asserted in rendered DOM.
- COVERED: Existing revision disclosure interaction verifies expanded state and both confidence labels.
- COVERED: Disclosure spans all twelve columns; existing date navigation, polling, requests, focus/disposal tests retained.
- COVERED: Fixed system clock, mocked fetch, no provider/network access; no global application state introduced.
- No additional animation or loading state introduced.

## Per-file test counts

- `tests/Feature/NhlGamePredictionsMarketProbabilityApiTest.php`: 73 `it(...)` declarations.
- `tests/Feature/NhlOperationalGamePredictionsTest.php`: 48 `it(...)` declarations.
- `resources/js/pages/Admin/game-predictions.test.js`: 47 direct `it(...)` declarations plus existing parameterized cases.

All modified test files exceed the minimum twenty declarations. Whole test files
are written on disk. Test, build and operational commands were not run.

## Human verification

No migration required. Only source review and `git diff --check` performed.
Suggested human-run commands:

```sh
php artisan test tests/Feature/NhlGamePredictionsMarketProbabilityApiTest.php tests/Feature/NhlOperationalGamePredictionsTest.php
npm test -- --run resources/js/pages/Admin/game-predictions.test.js
```

Unrelated import-index migration, tests, documentation and metadata remain untouched.
