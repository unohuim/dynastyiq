# Prediction API Matchup and player PP points

## Home/away scenario records — November 11, 2026

Approved scope: add current-season home records for the scheduled host and away
records for the visitor, for the season, last ten and last five matching-role
games. Keep existing overall records. The venue filter precedes each window
limit. Recorded OT/SO endings determine OTL. Target-day, future, incomplete,
unfinished, other-season and other-game-type games are excluded by the existing
form reader. No new tables, queries, providers or scoring adjustments are added.

Changed files for this addition:

- `app/Services/NhlPublicGamePage.php`
- `app/Services/NhlGamePredictionResponseCache.php`
- `resources/js/pages/Games/Show.vue`
- `tests/Feature/NhlPublicGamePageTest.php`
- `tests/Feature/NhlGamePredictionsMarketProbabilityApiTest.php`
- `tests/Feature/NhlSatPredictionServicesTest.php` (prepared-cache fixture namespace)
- `resources/js/pages/games.test.js`
- `docs/architecture/ui/NhlPublicGamePage.yaml`
- `docs/architecture/stats/NhlGamePredictionResponseCache.yaml`
- `docs/ENUMS.md`
- `docs/integrations/DIQ-API-Usage-Doc.md`
- This review document.

The shared form adds `venue_records` under the canonical ENUMS contract.
The approved compact follow-up displays overall Season, Last 10 and Last 5
records as primary values, with a smaller `Home {record}` or `Away {record}`
line beneath each. Dedicated venue rows are removed; head-to-head has no
venue sublabel. Matchup up-arrow percentages are green, down-arrow percentages
red, and zero changes muted; numeric values and accessible descriptions are
unchanged. Empty or missing samples display an em dash. These layout/color
changes do not change the backend windows, API shape or prediction calculations.
Prepared public payloads use
`nhl:public-game:v4`, source-bound prediction Matchup uses
`nhl:prediction-matchup:v3`, and API response identity uses
`pregame-dependencies-v18`. Prior cached shapes cannot satisfy the new preparation;
earlier saved revisions are not rewritten. A new prediction may need the
existing one-game Matchup preparation after deployment. No migration or import
is required, and Build Pregame is unchanged.

| Minimum coverage | Status | Test |
| --- | --- | --- |
| Host home / visitor away, overall preserved | AUTHORED | `shows the hosts home record and the visitors away record alongside overall records` |
| Venue filter before L10/L5 limits; recorded OT/SO | AUTHORED | `selects the last ten and five matching venue games before limiting the windows` |
| Temporal, season, game-type and source exclusions | AUTHORED | `excludes other seasons same day future unfinished and mismatched game types from venue records` |
| Short/empty samples | AUTHORED | `keeps short and empty venue histories explicit without borrowing other seasons` |
| API request → saved revision → cached GET | AUTHORED | `freezes scenario records in new prediction Matchup payloads and retains them on cached reads` |
| Inline venue labels, separate windows, missing display | AUTHORED | `nests home and away scenario records beneath corresponding overall records`; `shows unavailable venue records without inventing zero win loss records` |
| Direction colors and accessible percentages | AUTHORED | `colors percentage increases green decreases red and zero changes neutral` |

Requirements: 1. Correct scheduled role; 2. Current-season matching-role windows;
3. Primary overall records with inline venue sublabels; 4. Recorded W–L–OTL;
5. Additive shared payload and cache-shape versioning; 6. Unavailable rendering
for empty/missing history; 7. Green up/red down and neutral zero percentages.
All have implementation and regression assertions; execution is outstanding.

| Endpoint / verb | Existing auth/scope test |
| --- | --- |
| GET `/games/{id}/details?section=matchup`, guest allowed | `shows the hosts home record and the visitors away record alongside overall records` |
| GET `/api/nhl-game-predictions`, guest denied | `requires API authentication for new engine predictions` |
| Same API, missing scope denied | `requires the NHL statistics scope for new engine predictions` |
| Same API, scoped token allowed | `freezes scenario records in new prediction Matchup payloads and retains them on cached reads` |
| Organization/owner scope | N/A; unchanged global NHL reads |

Frontend contract: deterministic existing Vue mount tests assert both ordered
team columns, all three primary/secondary record pairs, smaller block sublabels,
absence of dedicated venue rows and head-to-head sublabels, missing placeholders,
and directional colors with preserved accessible percentage descriptions. No
interaction, timer, global-state or animation behavior changes.

Pest/Vitest declaration counts (excluding dataset expansion):
`NhlPublicGamePageTest.php` **113**, `NhlGamePredictionsMarketProbabilityApiTest.php`
**76**, `NhlSatPredictionServicesTest.php` **63**, and `games.test.js` **131**.
All exceed twenty. Tests and browser checks have not been run under repository
execution policy; human execution and review are outstanding.

Suggested human checks:

```sh
php artisan test tests/Feature/NhlPublicGamePageTest.php
php artisan test tests/Feature/NhlGamePredictionsMarketProbabilityApiTest.php
php artisan test tests/Feature/NhlSatPredictionServicesTest.php
npx vitest run resources/js/pages/games.test.js
```

## Earlier PP-points review (historical)

Subsequent approved rollback restores PP G/GP and removes the added builder points
field. The API Matchup addition remains. The implementation and counts below
describe the earlier PP-points change, not the current Factors metric.

Approved scope: include the existing Matchup statistics in the prediction API and
replace player PP G/GP with PP PTS/GP in Factors, retaining L10-versus-Train changes.

## Files changed

- `app/Http/Controllers/Api/NhlGamePredictionsController.php`
- `app/Services/NhlPublicGamePage.php`
- `app/Services/NhlPregameContextBuilder.php`
- `tests/Feature/NhlGamePredictionsMarketProbabilityApiTest.php`
- `tests/Feature/NhlPregameStrengthMetricsTest.php`
- `tests/Feature/NhlPublicGamePageTest.php`
- `resources/js/pages/games.test.js`
- `docs/integrations/DIQ-API-Usage-Doc.md`
- `docs/architecture/ui/NhlPublicGamePage.yaml`
- `docs/architecture/stats/NhlGamePredictionResponseCache.yaml`
- `docs/architecture/stats/NhlPregameContextEvidence.yaml`
- `docs/UI_DESIGN.md`
- `docs/ENUMS.md`
- `docs/ARCHITECTURE_INVENTORY.md` (public game entry only)
- This review document.

## Resulting behavior and limits

The controller appends `matchup` after the existing response cache resolves, so
new, fresh and stale HTTP 200 responses expose the statistics without recalculating
a forecast or rewriting its archived revision. The HTTP 202 preparation contract,
authentication, scores, markets and confidence calculations are unchanged.

The shared `NhlPublicGamePage::matchup` reader returns both team identities and
`form`, a prior-day `through_date`, the ten-meeting limit and expected-rate pending
state. Form includes season and L10 W-L-OTL, cross-season last-ten H2H W-L-OTL,
GF/GP, GA/GP, prepared xGF/GP and xGA/GP, PP%, PK%, and opportunities for/allowed
per GP. Both public preview and partner API use the same reader. API Matchup also
works for explicitly requested live/final forecasts without reading target results
or depending on a live-feed snapshot. All form results precede the selected game
date; H2H also matches game type. Missing values remain null.

The reader does not call providers or aggregate shot facts. Upcoming-game expected
cache misses coalesce the existing background preparation job. Live/final cache
misses leave expected rates unknown without scheduling live/recap work. Matchup is
descriptive at response time within its prior-date boundary, not a frozen forecast
input; the API documentation distinguishes these timestamps.

Factors now uses personal power-play points (goals plus assists) divided by GP for
both L10 and Train. Its delta retains the existing relative-change formula. The
builder already reads imported strength `individual_pts` for IPP but did not save
that total. It now saves `points` in strength windows and normalized metric rows,
using the same appearances/cutoff and missing-evidence rules. Known no-PP exposure
counts as zero points for that appearance. Invalid points exceeding on-ice goals,
missing evidence and unsupported older snapshots are unavailable, never replaced
with PP goals. The Factors cache namespace is versioned for this display change.

No schema or migration change is required. Existing contexts need to be refreshed
to gain stored strength points. This change does not automatically enqueue that
refresh, rebuild a season, or start any operational process. Existing prediction
history may lack points and remains immutable. Forecast PP-goal calculations are
unchanged.

## Authored minimum coverage matrix

These statuses describe written assertions; suites have not been executed.

| Contract | Status | Named coverage |
|---|---|---|
| Shared prior-date API form, H2H, opportunities and rates | COVERED | includes prior season form and the last ten cross-season meetings in API Matchup |
| Cached response enrichment and revision preservation | COVERED | includes prior season form and the last ten cross-season meetings in API Matchup (second HTTP read and stored payload assertions) |
| No request-time xG aggregation/provider fetch; coalesced preparation | COVERED | includes prior season form and the last ten cross-season meetings in API Matchup; does not call the expected goal reader during a public Matchup request |
| Live/final state independence and target/future exclusion | COVERED | includes pregame Matchup for live and finished prediction requests without target results (dataset) |
| PP points storage including assists and normalized values | COVERED | stores personal strength points including assists for compact PP comparisons |
| Zero-exposure appearance and invalid/missing evidence | COVERED | stores zero PP points for an appearance without PP exposure rather than excluding the game; keeps invalid or unavailable strength points unknown |
| PP points display and Train change | COVERED | shows saved historical rates without formula or fallback descriptions; uses committed personal L10 strength context even when engine sources differ |
| Old context/history never substitutes PP goals | COVERED | does not substitute PP goals when saved PP points are unavailable; does not guess source periods when a historical revision lacks provenance |
| Frontend metric label, unavailable delta and direction | COVERED | shows red decreases and unavailable changes without inventing Train percentages |

## Requirements checklist

1. SATISFIED in code: API has the same Matchup statistics for both teams.
2. SATISFIED in code: cutoff/window definitions and unavailable values are preserved.
3. SATISFIED in code: expected calculations remain outside the API request.
4. SATISFIED in code: PP PTS/GP replaces player PP G/GP with L10/Train comparison.
5. SATISFIED in code: source points are retained by the builder without a schema change.
6. SATISFIED in code: old context limitations are explicit; forecast calculations unchanged.
7. SATISFIED in code: partner documentation, canonical architecture and authored coverage updated.

## Endpoint × verb authorization/scope matrix

| Endpoint / verb | Case | Written coverage |
|---|---|---|
| `/api/nhl-game-predictions` GET | Guest denied 401 | requires API authentication for new engine predictions |
| Same | Wrong scope denied 403 | requires the NHL statistics scope for new engine predictions |
| Same | Correct read scope allowed | includes prior season form and the last ten cross-season meetings in API Matchup |
| Same | Invalid input | requires a game identifier; rejects nonnumeric game identifiers |
| Same | Per-game date/team isolation | includes pregame Matchup for live and finished prediction requests without target results |
| `/games/{id}/details?section=factors` GET | Guest and ordinary authenticated viewer allowed | allows guests and authenticated viewers to read curated saved contributors (dataset) |
| Factors GET | Revision/game isolation | does not expose another games revision through a pinned explanation request |
| Organization/owner permissions | Not applicable | Public game statistics; existing partner token scope retained |

## Frontend contract checklist

- Existing compact player comparison component renders `PP PTS/GP` from its metric label.
- The updated deterministic DOM test asserts the new label and absence of PP G/GP.
- Zero values, unavailable changes, green/red direction and hidden baseline behavior remain covered.
- No frontend runtime behavior or new request state was added; existing tab/refresh tests remain.

## Per-file test declaration counts

- `tests/Feature/NhlGamePredictionsMarketProbabilityApiTest.php`: 59.
- `tests/Feature/NhlPregameStrengthMetricsTest.php`: 49.
- `tests/Feature/NhlPublicGamePageTest.php`: 93.
- `resources/js/pages/games.test.js`: 124.

No tests, CI, migrations, imports, syncs or queue operations were run. Static diff
review only; automated execution and production verification await human action.
