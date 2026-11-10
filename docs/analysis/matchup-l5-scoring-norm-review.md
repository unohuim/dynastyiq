# L5 scoring rates versus season norm

Approved scope: show L5 GF/GP, GA/GP, xGF/GP and xGA/GP, with relative percentage
changes against the current season at ten completed games or last season otherwise.
The implementation uses available scored xG rather than blanking partial totals.

## Files changed

- `app/Services/NhlPublicGamePage.php`
- `app/Services/NhlStatsDashboardPayload.php`
- `resources/js/pages/Games/Show.vue`
- `tests/Feature/NhlPublicGamePageTest.php`
- `tests/Feature/NhlStatsDashboardTest.php`
- `resources/js/pages/games.test.js`
- `docs/architecture/ui/NhlPublicGamePage.yaml`
- `docs/architecture/stats/NhlStatsDashboard.yaml`
- `docs/UI_DESIGN.md`
- `docs/ENUMS.md`
- `docs/integrations/DIQ-API-Usage-Doc.md`
- `docs/ARCHITECTURE_INVENTORY.md` (public game entry only)
- This review document.

## Behavior

Each team independently selects its norm from completed results strictly before
the chosen game's date and of the same game type. At ten current-season games,
the norm is the full current-season average; below ten it is the previous-season
average. L5 continues into the previous season when fewer than five current-season
games exist. Window GP is explicit, never silently replaced by rated-game count.

GF/GA use the matching model-owned, committed team context when its samples match
the selected result windows. The reader checks game/team/opponent/version/cutoff
and completed comparisons. Older/unavailable context has a lightweight stored-score
compatibility path; no pregame rebuild or request-time shot scan is required.

The existing background public-game preparation gathers required game identities
and calls the existing expected-statistics reader once per selected season in its
new optional per-game mode. Its statistics model selection, target semantics,
eligibility and current-over-legacy score precedence remain unchanged. The reader
returns game/team-keyed for/against totals; exact L5, norm and compatibility season
memberships are then summed without rescanning shots for each window.

Known xG totals are divided by full window GP. Individual missing ratings are
ignored, so partial averages can understate xG; an entirely unrated window remains
unknown. Existing statistics dashboard completeness policies remain intact. No
shot ratings, imported facts or prediction data are modified. No scoring jobs or
historical backfill are launched by this change.

The public expected-stat cache namespace changes. Metadata records L5 GP,
current-season GP, norm GP and norm period. Changed window metadata rejects cached
comparison values and coalesces the existing background preparation job for eligible
upcoming games. Visible content remains in place. The API's legacy form rates keep
their season meaning; the additive `form.scoring_trend` contains all four L5 values,
norms and signed relative changes. The page labels each team's norm and GP above
its column and shows changes in parentheses with direction arrows and accessible
direction text. Missing or zero norms have no percentage; observed zero values
remain zero. No engine formula, prediction, schedule effect or driver changes.

## Authored minimum coverage matrix

Written coverage below has not been executed.

| Contract | Status | Named coverage |
|---|---|---|
| Exactly-ten season norm and nine-game previous-season norm | COVERED | uses season norm at ten completed games and last season below ten (dataset) |
| Previous-season continuation and zero norm | COVERED | uses prior-season games in early L5 while retaining a zero norm without dividing by zero |
| Committed matching context rate read | COVERED | reads matching committed context for L5 goals and its previous-season norm |
| Partial xG preserved with full-GP denominator | COVERED | uses available prepared xG without blanking partially rated samples; aggregates per-game xG over exact L5 and season memberships using full game denominators |
| Exact game-keyed expected-stat populations | COVERED | returns per-game xG totals for cached exact-window Matchup comparisons |
| No public expected-stat aggregation | COVERED | does not call the expected goal reader during a public Matchup request |
| Changed cached windows become pending without serving mismatched comparison | COVERED | aggregates per-game xG over exact L5 and season memberships using full game denominators |
| Visible L5 values, direction, team-specific norms and zeros | COVERED | shows L5 scoring values and changes against each teams own norm period |
| Cutoff and empty/unrated populations | COVERED | existing excludes games beyond the preview cutoff even if their identities are supplied; returns unavailable team expectations for an empty prior game selection |

## Requirements checklist

1. SATISFIED in code: four scoring rows use L5 averages and relative changes.
2. SATISFIED in code: ten-game season threshold is per team, otherwise last season.
3. SATISFIED in code: saved context reused for GF/GA; expected calculations remain background/cached.
4. SATISFIED in code: missing xG ratings do not blank a partially rated window.
5. SATISFIED in code: compatibility API fields, source cutoffs and zero handling retained.
6. SATISFIED in code/docs: prediction math unchanged; no migration or pregame rebuild.

## Endpoint × verb authorization/scope

No routes or permission rules change. Public `/games/{id}/details?section=matchup`
GET remains available to guests and ordinary authenticated users (existing
`exposes saved Matchup drivers without raw algorithm definitions or bucket evidence`
dataset); new public trend HTTP coverage appears in the norm threshold and xG
membership tests above. Same-game revision checks remain covered by
`pins Matchup drivers to the displayed revision and rejects another games revision`.
API authentication/scope denial remains covered by the existing prediction API
tests; its shared Matchup reader adds the new trend object without changing scope.
Organization and ownership restrictions are not applicable to these public stats.

## Frontend component checklist

- Written checks assert L5 values replace compatibility season values in the UI.
- Each team's baseline label/GP, signed direction and observed zero remain visible.
- Missing comparisons do not fabricate an infinite percentage.
- Existing silent-feed, pinned driver, tab, section-loading and cleanup tests remain.
- Fixed dates and fake timers, mocked requests and no external network calls.

## Per-file test declaration counts

- `tests/Feature/NhlPublicGamePageTest.php`: 101.
- `tests/Feature/NhlStatsDashboardTest.php`: 139.
- `resources/js/pages/games.test.js`: 128.

No tests, CI, browser scripts, migrations, imports, syncs, queues or rebuilds were
run. `git diff --check` passed. Automated execution and browser verification remain
for human review. No migration required.
