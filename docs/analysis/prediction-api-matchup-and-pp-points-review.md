# Prediction API Matchup and player PP points

## Saved-first API responses for all dates — November 12, 2026

This approved follow-up supersedes the date-limited read behavior below. All
partner prediction requests return the latest successful saved game revision
immediately, including today and future dates. The stored `meta.predicted_at`
and revision ID identify when that forecast was calculated. Changed inputs,
cache age, requested variants and force_refresh cannot hold up the saved response.
The read starts no refresh work; independent manual/worker recalculations remain
available and subsequent reads pick up their published revisions. With no saved
success, today/future requests retain preparation; past requests remain unavailable.
No migration, queue routing change or scoring change is introduced.

Files changed: `app/Http/Controllers/Api/NhlGamePredictionsController.php`,
`tests/Feature/NhlGamePredictionsMarketProbabilityApiTest.php`,
`docs/integrations/DIQ-API-Usage-Doc.md`,
`docs/architecture/stats/NhlGamePredictionResponseCache.yaml`,
`docs/architecture/stats/NhlGamePredictionHistory.yaml`,
`docs/ARCHITECTURE_INVENTORY.md`, and this review document.

| Minimum coverage | Status | Named test |
| --- | --- | --- |
| Initial request → stored revision → saved HTTP read with frozen timestamp, scores and public confidence, despite changed algorithm/force/variant/cache flush | COVERED (authored) | immediately returns saved predictions despite new inputs or forced refresh for any game date (today/tomorrow/next-week dataset) |
| Expired cache cannot cause waiting, revision creation or refresh jobs | COVERED (authored) | returns saved predictions beyond cache expiry without starting refresh work |
| Independent explicit publication remains possible and API returns new revision | COVERED (authored) | appends a fresh immutable revision through a separate explicit calculation; invalidates earlier algorithm cache entries while retaining the previous immutable revision |
| Missing current/future prediction retains preparation/continuation | COVERED (authored) | returns an explicit preparing response and queues automatic prediction continuation; automatically saves and publishes the prediction when queued dependencies become ready |
| Past-date read and absence retain resource guard | COVERED (authored) | returns the latest saved past prediction without preparing or refreshing anything; returns unavailable for an unsaved past game without creating dependencies |
| Public confidence mapping and missing-score behavior | COVERED (authored) | returns public confidence for every market on initial and saved API reads without rewriting internal evidence; does not substitute internal confidence when a saved public score is missing |

Requirements checklist:
1. SATISFIED in code/assertions: saved predictions return first for every date.
2. SATISFIED in code/assertions: original timestamp and revision ID returned unchanged.
3. SATISFIED in code/assertions: API saved reads are independent of refresh work.
4. SATISFIED in code/assertions: absence retains existing date-appropriate behavior.
5. SATISFIED in docs: API usage and canonical architecture describe precedence.

| Endpoint × verb | Auth/scope case | Named test |
| --- | --- | --- |
| GET `/api/nhl-game-predictions` | Guest 401 | requires API authentication for new engine predictions |
| Same | Wrong scope 403 | requires the NHL statistics scope for new engine predictions |
| Same | Scoped read 200, initial and saved | immediately returns saved predictions despite new inputs or forced refresh for any game date |
| Same | Past-date guest and wrong scope denied | retains authentication and scope restrictions for saved past predictions |
| Organization/ownership | N/A | Existing global NHL read scope; no scope changes |

Frontend checklist: N/A; no frontend files changed.
Per-file count: `tests/Feature/NhlGamePredictionsMarketProbabilityApiTest.php`
contains **83** test declarations, above the twenty-test minimum. Existing tests
that exercise algorithm/configuration invalidation now explicitly invoke the shared
calculation boundary before reading the newly published revision over HTTP; they
no longer rely on GET to force recalculation. Test execution is outstanding under
repository policy. `git diff --check` passes. Human verification command remains:

```sh
php artisan test tests/Feature/NhlGamePredictionsMarketProbabilityApiTest.php
```

## Read-only past-date API requests — November 12, 2026

Approved change: past-date partner requests return the latest successful stored
prediction for the game without refreshing anything. No override bypass is added.
Date means the stored game date compared with today in America/Toronto, independent
of game state. Today/future requests and explicit manual admin runs retain their
existing behavior. Historical request variants return the latest published output,
not newly calculated goalie, model or market variants. Failed/unavailable later
attempts do not obscure a successful prediction. No success returns HTTP 200
unavailable with the reason documented in ENUMS. No migration is required.

Files changed for this addition:

- `app/Http/Controllers/Api/NhlGamePredictionsController.php`
- `app/Services/NhlGamePredictionHistory.php`
- `tests/Feature/NhlGamePredictionsMarketProbabilityApiTest.php`
- `docs/integrations/DIQ-API-Usage-Doc.md`
- `docs/architecture/stats/NhlGamePredictionResponseCache.yaml`
- `docs/architecture/stats/NhlGamePredictionHistory.yaml`
- `docs/ENUMS.md`
- `docs/ARCHITECTURE_INVENTORY.md`
- This review document.

Coverage matrix (COVERED means authored assertions, not executed tests):

| Contract | Status | Named test |
| --- | --- | --- |
| Request → published revisions → later historical HTTP read, latest identity, unchanged rows and frozen values | COVERED | returns the latest saved past prediction without preparing or refreshing anything |
| Force-refresh/variant inputs cannot rebuild; public confidence mapping retained; no jobs | COVERED | returns the latest saved past prediction without preparing or refreshing anything |
| No stored success, all game states, ignored is_override, no Matchup or preparing response | COVERED | returns unavailable for an unsaved past game without creating dependencies |
| Per-game isolation and unsuccessful later attempts | COVERED | keeps historical selection within the requested game and skips later unavailable attempts |
| Toronto date boundary, same-day before midnight and historical after midnight | COVERED | switches to saved history at Toronto midnight rather than UTC midnight |
| Today/future engine and horizon behavior | COVERED | uses the new shared engine and SAT_V2 for an ordinary API request; stores calendar days away and calculated public confidence while retaining internal confidence |
| Unknown game and unchanged validation | COVERED | returns not found for an unknown game without prediction work; rejects nonnumeric game identifiers; rejects unsupported market keys |

Requirements checklist:

1. SATISFIED in implementation/assertions: historical API requests only read saved success.
2. SATISFIED in implementation/assertions: no success returns unavailable without work.
3. SATISFIED in implementation/assertions: force refresh and request variants cannot bypass.
4. SATISFIED in implementation/assertions: saved confidence/horizon/scores/Matchup remain frozen.
5. SATISFIED in implementation/assertions: today/future behavior uses the existing pipeline.
6. SATISFIED in implementation/assertions: authentication, validation and game isolation retained.
7. SATISFIED in documentation: partner contract, availability reason and canonical rules updated.

Endpoint × verb authorization/scope matrix:

| Endpoint / verb | Case | Named test |
| --- | --- | --- |
| GET `/api/nhl-game-predictions` | Guest denied 401; wrong scope denied 403 for past dates | retains authentication and scope restrictions for saved past predictions |
| Same | Scoped token allowed 200 for a saved past date | returns the latest saved past prediction without preparing or refreshing anything |
| Same | Scoped token allowed 200 unavailable without stored success | returns unavailable for an unsaved past game without creating dependencies |
| Same | Today/future guest and wrong scope denied | requires API authentication for new engine predictions; requires the NHL statistics scope for new engine predictions |
| Organization/owner scope | Not applicable | Existing global NHL read scope; no tenant data or new permissions |

Frontend component checklist: not applicable; no UI files changed.
Per-file test count: `tests/Feature/NhlGamePredictionsMarketProbabilityApiTest.php`
has **82** `it(...)` declarations, excluding dataset expansion. The previous past-date
API creation case is removed from the horizon dataset because API creation is now
prohibited for past dates; the public-score formula's negative-horizon unit case
remains. No tests were run under the repository execution policy. Human command:

```sh
php artisan test tests/Feature/NhlGamePredictionsMarketProbabilityApiTest.php
```

Static review: `git diff --check` only. No production or queue operations performed.


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
