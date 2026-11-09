# Pregame tabs and historical value comparisons

Approved scope: default Matchup with season comparison statistics and head-to-head
record; separate Lineups, Head-to-head and Factors tabs; actual historical values
instead of blend formulas or internal source labels.

## Implementation

- `Show.vue` uses accessible local preview tabs with keyboard navigation and
  reduced-motion-aware opacity transitions. Only the selected preview tab loads.
  The shared overview remains visible. Obsolete requests are cancelled; live and
  recap tabs retain their existing pathway. Game navigation resets to Matchup.
- Matchup shows season record, last-ten record, same-season head-to-head record,
  GF/GP, GA/GP, xGF/GP, xGA/GP, PP%, PK% and PP opportunities/GP. Existing goalie
  presentation is retained. Head-to-head keeps previous meeting detail separately.
- Basic statistics use completed prior games in the same season/game type.
  Special-teams rates divide summed goals by summed opportunities. Missing goals
  leave PP% unknown without hiding a known opportunity average; zero opportunities
  have unknown success percentage and a valid zero average.
- Expected-goal rates use the existing statistics scorer through the added
  `NhlStatsDashboardPayload::teamExpectationsForGames` read method. Both its model
  selection and attempt query honor the prior-date cutoff and explicit game IDs.
  Target semantics and score precedence remain unchanged. Empty/unscored inputs
  stay unavailable, and partial appearance/scoring coverage is withheld.
- The existing default-queue public-game preparation job publishes normal game
  detail first, then prepares expected rates. Public Matchup reads return basic
  stats immediately, queue/coalesce this existing preparation on a cold expected
  cache, and poll boundedly. No public request performs shot aggregation.
- Factors retains the saved revision and selected forecast roster. Contributor
  comparisons use the archived personal EV/PP history totals, not current settings
  or pooled fallback estimates. Rates include EV SAT/60, EV SOG/SAT%, EV SH% and
  PP G/GP, with each metric's relevant recent comparison alongside its historical
  baseline. No blend weights, equations or fallback-source names are displayed.
- Historical labels use the frozen revision's actual training-season count:
  Last 3 Seasons for the normal three-season model. Other counts remain accurate;
  absent provenance says Historical seasons. Recent labels are Last 10 Games,
  Last 20 Games and Last Season. Insights also use plain period labels.
- The Factors cache namespace changes. No engine algorithm, goalie selection,
  prediction probability, database schema, dependency or migration changes.

## Changed files

- `app/Services/NhlPublicGamePage.php`
- `app/Services/NhlStatsDashboardPayload.php`
- `app/Http/Controllers/NhlGamesController.php`
- `resources/js/pages/Games/Show.vue`
- `resources/js/pages/Games/game-panels.js`
- `tests/Feature/NhlPublicGamePageTest.php`
- `tests/Feature/NhlStatsDashboardTest.php`
- `resources/js/pages/games.test.js`
- `docs/architecture/ui/NhlPublicGamePage.yaml`
- `docs/architecture/stats/NhlStatsDashboard.yaml`
- `docs/UI_DESIGN.md`, `docs/ENUMS.md`
- `docs/ARCHITECTURE_INVENTORY.md` (public game entry only)
- `docs/analysis/pregame-preview-insights-review.md` (supersession note)
- This review document.

## Authored coverage matrix

Written coverage is distinguished from executed validation; no suites were run.

| Contract | Status | Named coverage |
|---|---|---|
| Default and isolated preview tabs | COVERED | opens Matchup by default and keeps Factors behind its own tab |
| Lazy requests/revision matching | COVERED | requests each deferred section independently; pins deferred Factors to the displayed prediction revision |
| Keyboard controls | COVERED | supports keyboard navigation between preview tabs without page navigation |
| Matchup statistics | COVERED | includes PP PK opportunities and head to head records without exposing Factors; includes expected goals special teams and the head to head record in Matchup |
| Denominators and missing values | COVERED | calculates special teams percentages from summed goals and opportunities; keeps known opportunities available when goal totals are missing; keeps zero opportunity success rates unknown and the opportunity average zero |
| Prior-game boundaries | COVERED | does not count the requested game or future games in its season baseline; excludes games beyond the preview cutoff even if their identities are supplied |
| Background expected rates | COVERED | does not call the expected goal reader during a public Matchup request; uses prepared expected goal rates and withholds incomplete coverage |
| Existing xG rules | COVERED | reuses team expectation scoring rules for a bounded prior game preview; returns unavailable team expectations for an empty prior game selection |
| Actual historical values | COVERED | shows saved historical rates without formula or fallback descriptions; uses plain Last 3 Seasons labels from the displayed revisions frozen training horizon |
| No formula/fallback display | COVERED | shows saved contributions and historical comparisons only in Factors; shows honest missing historical comparison states |
| Privacy/model/roster/revision boundaries | COVERED | Existing curated-contributor, same-model, roster, cutoff, canonical and revision tests now use Factors |
| Mode changes/allowlist | COVERED | keeps preview explanations hidden after the game becomes live; merges curated analysis only through Factors without changing public odds |

## Requirements checklist

1. Matchup is the default preview: SATISFIED in implementation.
2. Lineups, Head-to-head and Factors have separate content: SATISFIED.
3. Matchup includes scoring, expected scoring, special teams, opportunities and
   head-to-head record: SATISFIED; unavailable expected scoring stays unknown.
4. Historical values replace formulas and internal shorthand: SATISFIED.
5. Accurate plain period labels and saved revision matching: SATISFIED.
6. Keep first paint lightweight and advanced work off public reads: SATISFIED.
7. Relevant docs and regression files updated: SATISFIED as authored.
8. No migration or change to prediction algorithms: SATISFIED.

## Endpoint/auth scope

| Endpoint | Verb | Scope and named coverage |
|---|---|---|
| `/games/{game}` | GET | Existing public guest/ordinary-user and first-paint coverage |
| `/games/{game}/details?section=matchup` | GET | Public basic statistics; new Matchup statistics cases plus existing guest and ordinary-viewer cases |
| `/games/{game}/details?section=factors` | GET | Existing guest/authenticated curated-contributor dataset; same-game canonical revision and invalid/missing revision cases |
| `/games/{game}/details?section=lineups` or `meetings` | GET | Existing public deferred-section and invalid/unknown game cases |

No new write authority or organization/tenant scope is introduced. Existing
super-admin lineup controls and API scopes are unchanged. Public Facts never
return the full private prediction envelope.

## Frontend checklist and full-file test counts

- Tab selection, lazy request behavior, keyboard focus and mode changes covered.
- Loading/error/retry and overview protection retain existing coverage.
- Numeric comparison formatting and absence of formula text covered.
- Existing full suites retained; no new global test helpers or state introduced.
- Tests use fixed clocks, fake queues/providers and existing local fixtures.

| Test file | it declarations |
|---|---:|
| `tests/Feature/NhlPublicGamePageTest.php` | 79 |
| `tests/Feature/NhlStatsDashboardTest.php` | 138 |
| `resources/js/pages/games.test.js` | 122 |

`git diff --check` was run. Tests, CI, migrations, imports, predictions and queue
workers were not run. PostgreSQL execution, browser layout and suite results
remain for human verification.
