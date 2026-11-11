# Last 5 and Last 10 Matchup PP/PK percentages

## Files changed

- `app/Services/NhlPublicGamePage.php`
- `app/Services/NhlGamePredictionResponseCache.php`
- `tests/Feature/NhlPublicGamePageTest.php`
- `tests/Feature/NhlGamePredictionsMarketProbabilityApiTest.php`
- `tests/Feature/NhlSatPredictionServicesTest.php` (prepared-cache fixture namespace only)
- `docs/architecture/ui/NhlPublicGamePage.yaml`
- `docs/architecture/stats/NhlGamePredictionResponseCache.yaml`
- `docs/integrations/DIQ-API-Usage-Doc.md`
- `docs/ENUMS.md`
- `docs/ARCHITECTURE_INVENTORY.md` (Matchup field summary only)
- This review document.

Earlier approved public-confidence/admin-display changes remain in the working
tree; unrelated import-index files and documentation are not part of this change.

## Minimum coverage matrix

COVERED means deterministic assertions are authored; tests have not been executed.

| Behavior | Status | Named coverage |
| --- | --- | --- |
| Last 5/Last 10 windows and pooled ratios | COVERED | `pools goals and opportunities within distinct last five and last ten windows` |
| Target/future/unfinished/wrong-type exclusion | COVERED | Same pooled-window test includes each excluded result type |
| Previous-season continuation and independent opponent-side totals | COVERED | `continues recent special teams into last season while keeping season rates and records separate` |
| Missing source game retained; independent window/strength validity | COVERED | `keeps a missing last ten source game inside the window without damaging an independently complete last five` |
| Empty/zero-denominator/observed-zero behavior | COVERED | `keeps empty histories zero opportunities and observed zero conversions distinct` |
| Request → saved revision → cached API read | COVERED | `stores last five and last ten special teams percentages in the completed API Matchup snapshot` |
| Existing shared-engine integration | COVERED | Existing NhlSatPredictionServicesTest prepared Matchup fixture updated to the current namespace |

## Numbered requirements checklist

1. SATISFIED: Each team form exposes l5_pp_pct, l5_pk_pct, l10_pp_pct and l10_pk_pct.
2. SATISFIED: Rates use pooled goals and opportunities, not means of game percentages; values remain in percentage units.
3. SATISFIED: Exact prior completed games of the same game type are selected. Short current-season samples continue into the immediately previous season, using at most ten games and the available sample when shorter.
4. SATISFIED: Missing required numerator/denominator or zero opportunities makes only that window/strength unavailable. Observed zero conversions remain valid; missing games are not replaced by older ones.
5. SATISFIED: Existing season rates, opportunity averages, record-window definitions and prediction equations remain unchanged.
6. SATISFIED: New saved prediction Matchup payloads include all four fields; past revisions are not rewritten.
7. SATISFIED: Versioned preparation/response keys reject older cached payload shapes without starting imports or rewriting historical predictions.
8. SATISFIED: Documentation defines the four fields and cross-season window distinction. No new schema, dependency, route or visible UI rows.

## Endpoint × verb authorization/scope checklist

| Endpoint / verb | Caller | Expected | Named test |
| --- | --- | --- | --- |
| `/api/nhl-game-predictions` GET | Guest | 401 | `requires API authentication for new engine predictions` |
| Same | Token lacking nhl-stats:read | 403 | `requires the NHL statistics scope for new engine predictions` |
| Same | Scoped token | Stored L5/L10 PP/PK rates for both teams | `stores last five and last ten special teams percentages in the completed API Matchup snapshot` |
| `/games/{game}` GET | Guest | Public read remains available | `opens the public recap for guests at the existing numeric URL` |
| Same public surface | Ordinary authenticated viewer | Public read without lineup privileges | `allows ordinary authenticated viewers without granting lineup management` |
| All | Organization/owner scope | Not applicable: existing global NHL read contract, no mutation or auth changes | Existing endpoint contracts |

## Frontend component checklist

Not applicable: no frontend changes for these additional payload fields.

## Per-file test counts

- `tests/Feature/NhlPublicGamePageTest.php`: 109 it declarations.
- `tests/Feature/NhlGamePredictionsMarketProbabilityApiTest.php`: 74 it declarations.
- `tests/Feature/NhlSatPredictionServicesTest.php`: 63 it declarations.

All exceed twenty declarations. Tests retain fixed clocks, fake queues and
blocked HTTP. Whole test files are written on disk. No test execution occurred.

## Verification

Source review and `git diff --check` only. No migration required. No imports,
season syncs, scoring jobs, prediction runs, tests or builds were executed.

Suggested human-run check:

```sh
php artisan test tests/Feature/NhlPublicGamePageTest.php tests/Feature/NhlGamePredictionsMarketProbabilityApiTest.php tests/Feature/NhlSatPredictionServicesTest.php
```
