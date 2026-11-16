# Tools Lines review

Files changed or created:

- `app/Http/Controllers/NhlLinesController.php`
- `app/Services/NhlTeamLines.php`
- `app/Http/Middleware/HandleInertiaRequests.php`
- `routes/web.php`
- `resources/js/pages/Tools/Lines.vue`
- `resources/js/pages/Tools/lines.test.js`
- `tests/Feature/NhlLinesTest.php`
- `docs/architecture/stats/NhlStrengthOnIceStats.yaml`
- `docs/architecture/ui/InertiaAppShell.yaml`
- `docs/ARCHITECTURE_INVENTORY.md`
- `docs/analysis/team-lines-review.md`

## Minimum coverage matrix

Coverage below is authored and manually reviewed, not executed.

| Contract | Status | Test names |
| --- | --- | --- |
| Public route, shell link and active navigation | COVERED | serves guests and exposes the active Lines tool beside Schedule; allows ordinary authenticated members to read the same public lines |
| Current participants and honest latest-game selection | COVERED | lists current-season teams alphabetically including teams without a completed game; omits defunct directory teams and teams found only in a previous season; uses each teams own latest game rather than one league-wide game date; does not substitute older combinations when the latest game has missing summaries |
| Completed status, ordering and deterministic ties | COVERED | ignores live and upcoming games when selecting the latest completed game; accepts FINAL games and ignores cancelled schedule states; selects the later start time when completed games share a date; uses a stable game ID tie break when date and start time match |
| Greedy overlap exclusion and four/three EV limits | COVERED | takes the highest TOI forward trio and skips its overlapping alternate line; shows at most four non-overlapping EV forward trios; shows at most three non-overlapping EV defensive pairs |
| Two-group special-teams caps and variable membership sizes | COVERED | shows two PP forward groups including four-forward units; shows two PK forward groups without assuming trios; shows two PP defensive groups including a single defenseman; shows at most two PK defensive pairs |
| Native stored PP/PK units and subgroup TOI | COVERED | reads EV PP and PK lines after the existing aggregation action persists shift summaries; sums a repeated PP forward subgroup across changes to its defenseman; sums a repeated PK defensive subgroup across changes to its forwards |
| Canonical membership, team/strength separation and malformed data | COVERED | permits the same player in different strengths; does not reuse a player across forward and defensive groups at the same strength; excludes zero TOI and goalie units; rejects malformed EV group sizes and goalies inside skater groups; keeps opposing team summaries separate even when the game is shared; uses position as a fallback and omits unclassifiable special-teams combinations |
| Composition totals, privacy and factual units | COVERED | combines identical stored memberships before ranking without repeating a composition; formats shared TOI without multiplying it by the number of players; serves current names and game links without leaking private player attributes |
| No request-time source processing or operational work | COVERED | reads only prepared summaries without raw event scans or queued work; does not fetch or mutate supplied lines when teams are expanded |
| Accordion controls, independent state and motion | COVERED | starts every team collapsed; opens the chosen team with an accessible disclosure relationship; closes the same team on a second press; allows independent teams to remain expanded together; keeps another team expanded when closing a team; animates the caret and panel with reduced-motion support; uses native non-submit buttons and exposes keyboard focus styling |
| Rendered group labels, rank, TOI, game metadata and safety | COVERED | renders EV PP and PK as separately labelled sections; separates forward and defensive groups within each strength; preserves the supplied TOI order and sequential line ranks; shows shared TOI with a descriptive accessible label; links to the correct completed game from each team; shows honest empty group messages without filling from another strength; escapes player and team names rather than interpreting markup |

## Requirements checklist

1. SATISFIED: public `/tools/lines`, linked beside Schedule in all existing Tools menus.
2. SATISFIED: current-season teams as independent accordions with each team's own
   latest completed game, using Eastern dates and deterministic ordering.
3. SATISFIED: EV four forward lines/three pairs; PP and PK two forward/two
   defensive groups each, subject to available non-overlapping combinations.
4. SATISFIED: combine subgroup TOI, sort descending, then greedily exclude every
   previously selected player within the same team and strength. Reset for each
   strength; never replace actual combinations with invented lines.
5. SATISFIED: display names, shared TOI and game date/opponent/link, with honest
   empty states and compact responsive sections under the existing shared shell.
6. SATISFIED: read existing stored game-strength summaries and memberships in
   batch queries. Full PP/PK units are split using stored position. No raw PBP or
   shift calculation, import trigger, schema change or prediction changes.
7. SATISFIED: scoped automated coverage and canonical/derived documentation added.

## Endpoint × verb authorization/scope matrix

| Endpoint / verb | Scope | Test names |
| --- | --- | --- |
| GET /tools/lines | Guest allowed | serves guests and exposes the active Lines tool beside Schedule |
| GET /tools/lines | Ordinary authenticated member allowed | allows ordinary authenticated members to read the same public lines |
| GET /tools/lines | Global public NHL facts; no tenant, ownership or admin mutation | serves current names and game links without leaking private player attributes; reads only prepared summaries without raw event scans or queued work |

The action → persistence → HTTP case uses the existing
`SumNhlGameStrengthUnits::sum()` with stored F/D/PP/PK shifts, asserts persisted
strength summaries, and reads all three strengths through `/tools/lines`. The
page itself is read-only and creates no write endpoint or operational action.

## Frontend component checklist

- Local independent disclosure state; native button semantics and focus styling.
- Explicit aria-expanded, aria-controls, labelled regions and inert closed panels.
- Grid/opacity reveal and caret rotation respect reduced motion.
- Three responsive strength sections; separate ranked F/D groups and shared TOI.
- Server-provided game date/opponent, local game links and honest empty states.
- Escaped text, decorative logos and no remote calls, timers or global state.
- Fixed input fixtures; no real clock, randomness or external dependencies.

## Test count and checks

- `tests/Feature/NhlLinesTest.php`: 30 `it(...)` declarations.
- `resources/js/pages/Tools/lines.test.js`: 24 `it(...)` declarations.

Only manual source/diff review and `git diff --check` were performed. Tests,
builds, CI, browser checks, imports and production operations were not run.
No migration required. Existing homepage Top Lines behavior is unchanged.
