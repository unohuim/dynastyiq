# Compact pregame factors and matchup comparison

Approved scope: cross-season last-ten head-to-head record, allowed PP opportunities,
adjacent team comparison values, no recurring preview reload, compact L10 player
rates with Train-relative changes, and points leaders alongside goal contributors.

## Files changed

- `app/Services/NhlPublicGamePage.php`
- `resources/js/pages/Games/Show.vue`
- `tests/Feature/NhlPublicGamePageTest.php`
- `resources/js/pages/games.test.js`
- `docs/architecture/ui/NhlPublicGamePage.yaml`
- `docs/UI_DESIGN.md`
- `docs/ENUMS.md`
- `docs/ARCHITECTURE_INVENTORY.md` (public game entry only)
- This review document.

The earlier approved actual-ending record correction remains in the same working
files and is described in `game-record-ending-display-review.md`. Unrelated import
index/schema changes are outside this scope.

## Behavior

Matchup keeps season statistics within the selected season and game type, while
its head-to-head record reads at most ten completed earlier meetings of the same
game type across seasons. Allowed opportunities divide summed opponent PP
opportunities by team GP; missing opponent goals do not hide a known opportunity
average. Values use one centered table, adjacent away/home columns and a shared
metric label. The separate meeting-detail tab retains its existing five games.

Factors places both team scoring summaries before compact player rows. Select the
union of three positive projected-goal leaders and three known L10 PTS/GP leaders
per forecast roster, without duplicates, listing points leaders first. This removes
the goal-only selection condition that could omit a player such as McDavid; his
specific production roster/context has not been queried in this change.

Every player comparison uses L10: EV SAT/60, EV SAT/GP, EV SH% and PP G/GP. Read
committed personal strength context matching the forecast's model, team, game,
parent, opponent and cutoff, with archived personal input history retained for
older revisions lacking strength context. L10 points ranking requires matching
committed context. Missing L10 data stays unknown; another period is never used.
Show the recent value and signed relative change versus personal Train, with
green/up and red/down plus accessible directional labels. Train values and blend
formulas are absent from these player rows. Zero baselines have no relative
percentage; genuine recent zeros remain visible. Existing descriptive insight
cards retain their distinct historical comparison semantics.

The once-per-minute Inertia preview reload is removed. Explicit lineup actions
still refresh, bounded expected-rate preparation retains the rendered Matchup,
and shared live-feed state transitions remain active. Prediction calculations,
probabilities, lineups and schema are unchanged. No migration required.

## Authored minimum coverage matrix

Coverage below refers to written assertions, not executed test results.

| Contract | Status | Named coverage |
|---|---|---|
| Cross-season H2H, ten-game limit, cutoff/type/season isolation | COVERED | limits head to head to ten prior meetings across seasons without expanding season form |
| Allowed opportunities independent of goal availability | COVERED | counts allowed PP opportunities independently of missing opponent goals |
| Leading points player outside projected top three | COVERED | includes the leading L10 points player even outside the top three goal projections |
| Matching own-team context for ranking | COVERED | does not rank an opponent or mismatched parent as an own points leader |
| Consistent L10 rates and context source | COVERED | uses committed personal L10 strength context even when engine sources differ; shows saved historical rates without formula or fallback descriptions |
| Missing recent period, zeros and zero baseline | COVERED | leaves missing L10 rates unknown instead of using another recent period; uses committed personal L10 strength context even when engine sources differ |
| Frozen training caption and settings independence | COVERED | uses plain Last 3 Seasons labels from the displayed revisions frozen training horizon; shows saved historical rates without formula or fallback descriptions |
| Adjacent values and allowed opportunities in DOM | COVERED | includes expected goals special teams and the head to head record in Matchup |
| No recurring reload or content removal | COVERED | keeps preview content visible without recurring page reloads; retains visible basic Matchup statistics while expected rates finish preparing |
| Compact player values, hidden baseline, direction/color | COVERED | shows saved contributions and historical comparisons only in Factors; shows red decreases and unavailable changes without inventing Train percentages |
| Lazy/revision-pinned tabs and live transition | COVERED | pins deferred Factors to the displayed prediction revision; keeps preview explanations hidden after the game becomes live |

## Requirements checklist

1. SATISFIED in code: last-ten cross-season H2H record; actual-ending classification retained.
2. SATISFIED in code: allowed PP opportunities under own opportunities, adjacent values.
3. SATISFIED in code: periodic preview reload removed; visible expected-rate preparation retained.
4. SATISFIED in code: separate team summaries followed by compact player rows.
5. SATISFIED in code: all requested player metrics use L10, with personal Train changes only.
6. SATISFIED in code: points leaders enter the list independently of projected goals.
7. SATISFIED in code: missing evidence stays unknown; no engine or schema changes.

## Endpoint × verb authorization/scope matrix

| Endpoint / verb | Audience/scope | Written coverage |
|---|---|---|
| `/games/{id}` GET | Guest | opens the public recap for guests at the existing numeric URL |
| `/games/{id}` GET | Ordinary authenticated viewer | allows ordinary authenticated viewers without granting lineup management |
| `/games/{id}/details?section=factors` GET | Guest and ordinary authenticated viewer | allows guests and authenticated viewers to read curated saved contributors (dataset) |
| Factors GET | Same-game revision scope | does not expose another games revision through a pinned explanation request; does not substitute another revision when a requested saved revision is absent |
| Matchup GET | Public read and date/type scope | limits head to head to ten prior meetings across seasons without expanding season form; counts allowed PP opportunities independently of missing opponent goals |
| Organization/owner scope | Not applicable | Public game reads; existing lineup mutation authorization unchanged |

## Frontend component checklist

- Written: default tab, keyboard navigation and selected-tab-only loading remain covered.
- Written: centered comparison DOM has two adjacent value cells per metric row.
- Written: player deltas expose direction beyond color; baseline values stay hidden.
- Written: virtual timers prove preview content remains mounted and no Inertia reload fires.
- Written: expected-rate background completion updates the same mounted panel.
- Written: missing data, live-mode transition and obsolete request handling retain coverage.
- Deterministic fixtures, mocked fetch/router and fake timers; no provider calls.

## Per-file test declaration counts

- `tests/Feature/NhlPublicGamePageTest.php`: 92 `it(...)` declarations.
- `resources/js/pages/games.test.js`: 124 `it(...)` declarations.

No test/CI command, migration, import, sync, queue or operational command was run.
`git diff --check` passed. Browser rendering and automated suites await human
verification; no claim of executed success is made.
