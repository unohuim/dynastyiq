# Tools Lines review — complete special-teams units and display positions

Files changed or created:

- `app/Services/NhlTeamLines.php`
- `resources/js/pages/Tools/Lines.vue`
- `resources/js/pages/Tools/lines.test.js`
- `tests/Feature/NhlLinesTest.php`
- `docs/architecture/stats/NhlStrengthOnIceStats.yaml`
- `docs/architecture/ui/InertiaAppShell.yaml`
- `docs/ARCHITECTURE_INVENTORY.md`
- `docs/ENUMS.md`
- `docs/analysis/team-lines-review.md`

## Minimum coverage matrix

Coverage below is authored and manually reviewed, not executed.

| Contract | Status | Test names |
| --- | --- | --- |
| Public route, shell link and active navigation | COVERED | serves guests and exposes the active Lines tool beside Schedule; allows ordinary authenticated members to read the same public lines |
| Current participants and honest latest-game selection | COVERED | lists current-season teams alphabetically including teams without a completed game; omits defunct directory teams and teams found only in a previous season; uses each teams own latest game rather than one league-wide game date; does not substitute older combinations when the latest game has missing summaries |
| Completed status, ordering and deterministic ties | COVERED | ignores live and upcoming games when selecting the latest completed game; accepts FINAL games and ignores cancelled schedule states; selects the later start time when completed games share a date; uses a stable game ID tie break when date and start time match |
| Greedy overlap exclusion and four/three EV limits | COVERED | takes the highest TOI forward trio and skips its overlapping alternate line; shows at most four non-overlapping EV forward trios; shows at most three non-overlapping EV defensive pairs |
| Complete special-teams units and two-unit caps | COVERED | shows at most two complete non-overlapping PP units ranked by TOI; shows two complete PK units with four or three skaters; retains a five-forward PP unit and fills its defensive display spots; keeps a three-forward one-defenseman PK unit together |
| Native stored PP/PK units, TOI and overlap | COVERED | reads EV PP and PK lines after the existing aggregation action persists shift summaries; ranks whole PP units without combining TOI across different defensemen; does not reuse defensemen across different selected PK units |
| Canonical membership, team/strength separation and malformed data | COVERED | permits the same player in EV PP and PK without changing unit membership; does not reuse a player across forward and defensive groups at the same strength; excludes zero TOI and goalie units; rejects malformed EV group sizes and goalies inside skater groups; keeps opposing team summaries separate even when the game is shared; uses position fallback for display without discarding an unknown-position unit member; rejects partial PP groups and oversized PK units without synthesizing a roster |
| Composition totals, privacy and factual units | COVERED | combines identical stored memberships before ranking without repeating a composition; formats shared TOI without multiplying it by the number of players; serves current names and game links without leaking private player attributes |
| No request-time source processing or operational work | COVERED | reads only prepared summaries without raw event scans or queued work; does not fetch or mutate supplied lines when teams are expanded |
| Accordion controls, independent state and motion | COVERED | starts every team collapsed; opens the chosen team with an accessible disclosure relationship; closes the same team on a second press; allows independent teams to remain expanded together; keeps another team expanded when closing a team; animates the caret and panel with reduced-motion support; uses native non-submit buttons and exposes keyboard focus styling |
| Rendered group labels, rank, TOI, game metadata and safety | COVERED | renders EV PP and PK as separately labelled sections; separates EV positions but keeps PP and PK as complete units; preserves the supplied TOI order and sequential line ranks; shows shared TOI with a descriptive accessible label; links to the correct completed game from each team; shows honest empty group messages without filling from another strength; escapes player and team names rather than interpreting markup |
| Faceoff-based C and listed wings | COVERED | selects the center by faceoffs taken rather than wins alone; ignores other games faceoff counts when placing the center; uses listed wings and center as stable fallbacks when faceoffs are missing |
| Inferred defensive spots without changing composition | COVERED | places both defensemen behind the three PP forwards; places one defenseman and one extra forward behind a four-forward PP unit; positions a three-skater PK unit with one center and two back spots; positions a four-skater PK unit using its listed wing and busiest center |
| Initial/surname formatting and full-name hover | COVERED | formats first initial and surname while retaining the full name; preserves accented initials and compound surnames; uses full-name parts when separate first and last names are missing; shows abbreviated player names with the full name on hover |
| Front/back grid and complete-unit rendering | COVERED | renders supplied center and wing spots in distinct grid columns; uses the second row for special-team back spots and the first for EV defensive pairs; keeps a five-forward power-play unit intact in all five display spots; shows four-player PK units without creating a fifth player; shows three-player PK units with a center and two back spots |

## Requirements checklist

1. SATISFIED: public `/tools/lines`, linked beside Schedule in all existing Tools menus.
2. SATISFIED: current-season teams as independent accordions with each team's own
   latest completed game, using Eastern dates and deterministic ordering.
3. SATISFIED: EV retains four forward lines/three pairs. PP selects up to two
   complete five-player units; PK selects up to two complete three/four-player units.
4. SATISFIED: combine identical full memberships, sort descending, then exclude every
   previously selected player within the same team and strength. Reset for each
   strength; never replace actual combinations with invented lines.
5. SATISFIED: display first initial and surname with full-name hover, shared TOI
   and game date/opponent/link. Inferred front/back spots prioritize the game's
   busiest faceoff taker at C, actual defensemen at the back, then listed wings.
   Extra forwards fill back spots. Keep honest empty states and shared shell.
6. SATISFIED: read existing stored game-strength summaries and memberships in
   batch queries plus prepared boxscore faceoffs. Preserve full PP/PK membership. No raw PBP or
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
- Three responsive strength sections; EV F/D groups and complete PP/PK units.
- Front/back positional grid, abbreviated names, full-name hover and shared TOI.
- Server-provided game date/opponent, local game links and honest empty states.
- Escaped text, decorative logos and no remote calls, timers or global state.
- Fixed input fixtures; no real clock, randomness or external dependencies.

## Test count and checks

- `tests/Feature/NhlLinesTest.php`: 41 `it(...)` declarations.
- `resources/js/pages/Tools/lines.test.js`: 30 `it(...)` declarations.

Only manual source/diff review and `git diff --check` were performed. Tests,
builds, CI, browser checks, imports and production operations were not run.
No migration required. Existing homepage Top Lines behavior is unchanged.
