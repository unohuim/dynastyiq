# Tools Schedule

Current scope: Weekly uses the selected week. Off Nights independently covers the
remaining current season from today in America/Toronto, including uncompleted
live games. Off Nights displays only Team and Total, with no logos or game dates. Earlier
weekly/date-list descriptions below are superseded by the Remaining-season
correction and Compact rows sections.

## Files changed

- `app/Http/Controllers/NhlScheduleController.php`
- `app/Http/Middleware/HandleInertiaRequests.php`
- `routes/web.php`
- `resources/js/layouts/AppLayout.vue`
- `resources/js/pages/Tools/Schedule.vue`
- `resources/views/nav/main.blade.php`
- `tests/Feature/NhlScheduleTest.php`
- `resources/js/pages/games.test.js`
- `docs/architecture/ui/InertiaAppShell.yaml`
- `docs/ARCHITECTURE_INVENTORY.md`
- This review document.

Tools → Schedule is public for guests and signed-in users. The page defaults to
the current Eastern Monday–Sunday week. Teams are rows, the seven days are
columns, and Total GP is last. Each game shows home/away, opponent and Eastern
start time and links to the existing preview/recap. Missing times remain TBD.
Postponed/cancelled games remain visible without contributing to GP.

Season schedule participants define the team population, including teams without
games in the selected week. The full team directory includes defunct franchises
and is not an active-team list. September–August season boundaries are retained;
a week crossing that boundary includes the relevant season populations. Missing
stored schedule coverage is not replaced with fabricated games or remote calls.
Reference names/logos supplement stored identities. All game types supported by
the import pipeline are included; exhibition/event types outside that set are not.

Previous/next/This Week controls preserve the mounted table during explicit
navigation. Local loading/error feedback has a reserved slot. The mobile table fits all seven days in the viewport; desktop retains pinned
identity and GP columns. Desktop uses
the existing dropdown pattern; mobile Tools uses an accessible drawer available
to guests, with the same links also in member navigation. Legacy navigation keeps
its existing runtime and shared slide-over component. No new global state or
executable Blade scripts are introduced.

## Minimum coverage matrix

COVERED means authored assertions, not executed tests.

| Contract | Status | Tests |
| --- | --- | --- |
| Public and member access; backend-owned Tools route | COVERED | allows guests to view the schedule in the shared shell; allows authenticated members without an admin role to view the same schedule |
| Eastern Monday default, midnight/UTC and year boundaries | COVERED | defaults to the current Monday through Sunday; uses Eastern calendar time before the UTC Monday rollover reaches Toronto; starts the new week at Eastern Monday midnight without the scoreboard eleven am rule; preserves the correct week across a year boundary |
| Week normalization, URLs and current selection | COVERED | normalizes a selected midweek date to Monday; provides previous next and current week navigation URLs; identifies a selected historical week independently of the current week |
| All season teams, including zero games; defunct references excluded | COVERED | keeps all season teams visible even when they have no games in the week; shows all thirty-two season participants without requiring a game in the selected week; does not add defunct teams merely because they remain in the team directory |
| Reciprocal venue, game-date columns, Eastern offsets and TBD | COVERED | places reciprocal home and away entries in the same day; uses scheduled game date rather than the UTC timestamp date for the column; formats summer start times using the daylight saving offset; keeps unknown start times as TBD |
| Totals, terminal/live/upcoming states, postponed/cancelled and multiple games/day | COVERED | counts completed live and upcoming games once per participating team; keeps postponed and cancelled games visible without counting them toward GP; includes Sunday and excludes both adjacent weeks from totals; retains multiple games in one day in chronological order |
| Game links, missing data, input validation and no heavy work | COVERED | links each scheduled game to its existing preview or recap route; does not require imported boxscores or completed pregame context; never imports source data or dispatches predictions while reading a schedule; returns an honest empty schedule when the selected season has no stored games; rejects malformed impossible and non-scalar week dates |
| Desktop and guest/member mobile Tools navigation | COVERED | opens the desktop Tools menu with its backend-owned Schedule link; lets guests open Tools from the mobile bar without member navigation; includes Tools in the member navigation drawer without removing existing entries; keeps Schedule reachable from legacy desktop and mobile Tools navigation |
| Table structure, scrolling, navigation, retained rows and feedback | COVERED | renders only Team Monday through Sunday and Total GP columns; fits all seven days with fixed mobile columns and retains desktop sizing; requests the selected week while retaining the current table during loading; updates the displayed days and rows when navigation supplies new props; reports explicit navigation errors beside the tabs without reserving table space and permits retry |

## Requirements checklist

1. SATISFIED: Tools contains Schedule in desktop and mobile navigation.
2. SATISFIED: public weekly schedule page defaults to the current Eastern week.
3. SATISFIED: teams are rows, Mon–Sun columns, Total GP last.
4. SATISFIED: opponent, venue and start time are shown with week navigation.
5. SATISFIED: stored schedule reads do not run imports, predictions or advanced-stat preparation.
6. SATISFIED: mobile fixed columns retain all seven days without horizontal scrolling.
7. SATISFIED: regression coverage and canonical/derived documentation added.
8. SATISFIED: no migration, dependency, production data or worker changes.

## Endpoint × verb authorization/scope checklist

| Endpoint / verb | Scope | Test |
| --- | --- | --- |
| GET /tools/schedule | Guest allowed | allows guests to view the schedule in the shared shell |
| Same | Ordinary member allowed; admin not required | allows authenticated members without an admin role to view the same schedule |
| Same | Invalid date rejected | rejects malformed impossible and non-scalar week dates |
| Same | Week/date/season selection | normalizes a selected midweek date to Monday; includes Sunday and excludes both adjacent weeks from totals; does not add defunct teams merely because they remain in the team directory |

This public read has no organization, owner or member-only population. There are
no mutation endpoints or new stored fields requiring a store → DB → HTTP test;
the feature tests create stored game facts and exercise the actual HTTP read.
The existing fresh-install guard is retained.

## Frontend component contract checklist

- State: week visits disable controls, preserve the grid, handle failures and accept new props.
- DOM: column order, row count, venue/time labels, responsive column widths and desktop sizing asserted.
- Payload: navigation uses backend-owned URLs; no browser-derived league membership or dates.
- Access: keyboard dropdown and guest/member mobile Tools links exercised.
- Motion: existing dropdown/drawer patterns and reduced-motion classes retained.
- No automatic requests or background status messages added.
- No external requests, uncontrolled clocks, background jobs or global state in the new tests.

## Per-file test count

| File | `it(...)` declarations |
| --- | ---: |
| tests/Feature/NhlScheduleTest.php | 43 |
| resources/js/pages/games.test.js | 153 |

## Verification

The scoped diff was reviewed and `git diff --check` passed. Tests, builds, CI and
browser validation were not run under the repository execution policy.
Suggested human commands:

- `php artisan test tests/Feature/NhlScheduleTest.php`
- `npm test -- --run resources/js/pages/games.test.js`

No migration is required. No production commands were run. An empty or incomplete
stored season still requires its ordinary schedule import; this page does not
initiate one during a visit.


## Off Nights extension

The approved extension touches the controller, Schedule Vue page, the two existing
test files, InertiaAppShell architecture, inventory and this review. No routes,
authorization, migrations, dependencies, global state or production records change.
Weekly remains the initial tab. Off Nights uses the selected week and preserves
its tab during week navigation. It shows every team, qualifying game dates and
opponents, and a count. Zero-count teams remain visible.

At most 50% of that date's stored season participants may be playing. The boundary
is inclusive (16 of 32 teams, normally eight games). Count distinct active team
IDs; exclude postponed and cancelled games from both slate activity and team
counts. A quiet Tuesday qualifies and a busy Monday does not. Season membership
is calculated separately for each date's season, even across September first.
Zero-game dates contribute no team games; missing season coverage does not
classify a date as an off night. Partial stored schedules remain a limitation:
classification reflects the imported schedule, so a complete season schedule is
needed for dependable league percentages.

### Extension coverage matrix

COVERED means authored, not executed.

| Requirement | Status | Test names |
| --- | --- | --- |
| Inclusive threshold and weekday independence | COVERED | includes exactly half the league as an off night even on Tuesday; excludes a busy Monday when more than half the league plays |
| League denominator, distinct IDs and season boundary | COVERED | uses the season population rather than assuming thirty-two teams; counts only unique playing teams when a team has multiple games in a day; uses each dates own season population in a week crossing September first |
| Every team, zero counts, active dates/states, missing coverage | COVERED | retains every season team with zero off-night games where appropriate; excludes postponed or cancelled games from off-night activity and counts; does not invent team off-night games on dates with an empty slate; counts completed live and future off-night games within the selected week only; does not classify dates without stored season coverage as off nights |
| Default tab, request-free switching, rendered dates/counts | COVERED | defaults to Weekly and switches to Off Nights without a request; keeps zero-count teams and omits postponed and busy-day games from off-night links |
| Keyboard selection and week changes | COVERED | supports arrow Home and End keyboard navigation between schedule tabs; preserves Off Nights across week navigation and renders updated counts |

### Extension requirements checklist

1. SATISFIED: Weekly remains default; Off Nights is second.
2. SATISFIED: all teams, qualifying dates and counts use the selected week.
3. SATISFIED: inclusive league-activity cutoff replaces weekday assumptions.
4. SATISFIED: postponed/cancelled and empty dates do not add team games.
5. SATISFIED: browser-owned tab state, keyboard controls and week preservation.
6. SATISFIED: coverage authored in existing files (37 Pest declarations; 146 JS declarations).

The GET /tools/schedule auth/scope matrix above remains applicable and unchanged:
guest access maps to `allows guests to view the schedule in the shared shell`;
member access maps to `allows authenticated members without an admin role to view
the same schedule`; validation maps to `rejects malformed impossible and
non-scalar week dates`. Off-night HTTP assertions exercise derived values from
stored games; no mutation endpoint or stored derived field was introduced.

Frontend checklist: selected-state transitions, ARIA tab/panel linkage, roving
keyboard focus, dates/counts, zero-game rows, unchanged Weekly columns and updated
props are asserted. No external requests, uncontrolled clocks or global state.
Existing tab color transitions respect reduced motion. No tests, CI, build,
browser checks, migrations or operational commands were run. Only static diff
review and `git diff --check` are performed before the requested push.


## Mobile width adjustment

Files changed: `resources/js/pages/Tools/Schedule.vue`,
`resources/js/pages/games.test.js`, `docs/architecture/ui/InertiaAppShell.yaml`,
and this document. This is a layout-only change; no backend fields, authorization,
requests, calculations, tabs, or navigation behavior change. No migration required.

1. SATISFIED: Team, all seven weekdays and Total GP use a fixed-width mobile table,
   with 10% reserved for Team and 7% for GP. The days share the remaining 83%.
2. SATISFIED: remove the mobile 920px minimum and horizontal-scroll requirement.
3. SATISFIED: compact mobile fonts, cell padding and stacked team logo/abbreviation;
   increase text and logo sizing at 420px, with desktop sizing restored at lg.
4. SATISFIED: remove Off Nights' 400px minimum so both tabs fit narrow containers.

Coverage: `fits all seven days with fixed mobile columns and retains desktop
sizing` replaces the superseded mobile-scroll expectation. Existing tests retain
nine-column structure, game links/content, default tab, keyboard navigation,
week-change state and feedback coverage. This is authored class/DOM-contract
coverage, not a browser measurement of widths; jsdom does not perform layout.
The same GET guest/member/validation matrix remains unchanged and mapped above.
The frontend file retains 146 test declarations. No new test files, global state,
external requests or uncontrolled timing. Static diff review and whitespace checks
only; tests, builds and browser checks were not run. The human should visually
check 350px, 420px and desktop widths, including postponed/cancelled games.


## Remaining-season correction

### Files changed

- `app/Http/Controllers/NhlScheduleController.php`
- `resources/js/pages/Tools/Schedule.vue`
- `tests/Feature/NhlScheduleTest.php`
- `resources/js/pages/games.test.js`
- `docs/architecture/ui/InertiaAppShell.yaml`
- `docs/ARCHITECTURE_INVENTORY.md`
- This review document.

### Coverage matrix

COVERED means authored assertions, not executed tests.

| Contract | Status | Test names |
| --- | --- | --- |
| Today through current season, all teams and zero counts | COVERED | counts off nights across the remaining season rather than the selected week; retains zero-count teams when no uncompleted games remain this season |
| Independent of selected week or selected season | COVERED | keeps remaining off-night data identical when navigating historical or future weeks |
| Past/final/cancelled/postponed/next-season excluded | COVERED | excludes past completed cancelled postponed and other-season games from remaining off nights |
| Full-date inclusive league cutoff and completed-today classification | COVERED | applies the inclusive off-night cutoff to each future full league slate; retains completed games in todays slate classification without counting them as remaining |
| Separate season heading, no week controls, dates and counts | COVERED | defaults to Weekly and switches to Off Nights without a request; shows server-provided remaining off-night counts independently of weekly games; keeps remaining-season off nights unchanged after browsing a different week |
| Season empty state and retained zero rows | COVERED | shows remaining-season empty state instead of the selected weeks empty state |

### Requirements checklist

1. SATISFIED: all current-season teams receive a remaining-season count.
2. SATISFIED: begin today Eastern, exclude past and completed games, include live.
3. SATISFIED: use full daily slate, not only still-upcoming games, for the 50% cutoff.
4. SATISFIED: selected Weekly dates cannot change the Off Nights period/population.
5. SATISFIED: heading, dates, totals and empty state match the season period.
6. SATISFIED: existing mobile sizing, routes, authorization and game links retained.
7. SATISFIED: no migration, new abstraction, dependency or operational changes.

### Endpoint × verb auth/scope

| Endpoint / verb | Scope | Tests |
| --- | --- | --- |
| GET /tools/schedule | Guests | allows guests to view the schedule in the shared shell |
| GET /tools/schedule | Members without admin role | allows authenticated members without an admin role to view the same schedule |
| GET /tools/schedule | Validated weekly input | rejects malformed impossible and non-scalar week dates |
| GET /tools/schedule | Off Nights bound to today's season, independent of week input | keeps remaining off-night data identical when navigating historical or future weeks; excludes past completed cancelled postponed and other-season games from remaining off nights |

No new mutation endpoint or stored derived fields. Derived read tests create stored
schedule facts, then exercise the actual HTTP response. Organization/owner scope
is not applicable to this public NHL schedule.

### Frontend checklist and counts

- Authored: local tab switching, keyboard focus, dates/counts from independent
  payload, season heading, absent week controls, season empty state, unchanged
  off-night data after a weekly visit, return to the weekly nine-column grid.
- Deterministic fixtures, mocked router, no external calls, randomness or timers.
- Backend file: 43 `it(...)` declarations. Frontend file: 147.
- `git diff --check` and manual scoped-diff review only. Tests, builds, CI,
  browser checks, migrations and production commands were not run.

The imported schedule still defines available coverage. No external schedule
fetch, generated future games, prediction runs or league-stat preparation occurs
when reading either tab. No migration required.


## Compact rows

Files changed: `resources/js/pages/Tools/Schedule.vue`,
`resources/js/pages/games.test.js`, `docs/architecture/ui/InertiaAppShell.yaml`,
`docs/ARCHITECTURE_INVENTORY.md`, and this document.

1. SATISFIED: remove team logos in Weekly and Off Nights.
2. SATISFIED: reduce weekly body padding from 8px to 4px vertically, lower game
   link minimum height from 40px to 24px, and remove the identity height minimum.
3. SATISFIED: Off Nights has exactly Team (abbreviation) and Total columns;
   remove the game-date links and use compact 6px vertical cell padding.
4. SATISFIED: preserve remaining-season counts, zero-count teams, headings,
   week navigation, tabs, game links in Weekly and mobile width behavior.

Coverage matrix (authored, not executed):

| Contract | Status | Existing updated test |
| --- | --- | --- |
| Weekly logos removed and rows compacted | COVERED | fits all seven days with fixed mobile columns and retains desktop sizing |
| Two columns, no dates/links/logos, correct count | COVERED | defaults to Weekly and switches to Off Nights without a request |
| Remaining-season counts independent of browsed week | COVERED | keeps remaining-season off nights unchanged after browsing a different week |
| Zero-count teams | COVERED | shows server-provided remaining off-night counts independently of weekly games |

Frontend checklist: nine weekly columns retained; two Off Nights columns, absent
logos/links, compact row classes and counts asserted; existing local tab,
keyboard and week navigation assertions retained. No new requests, timers or
global state. The existing endpoint/verb auth-scope matrix remains applicable;
no backend or permission changes. Frontend file retains 147 test declarations.
No migration required. Static diff/whitespace review only: tests, builds, browser
checks and production commands were not run.


## Compact date controls and sortable Off Nights totals

Files changed: `resources/js/pages/Tools/Schedule.vue`,
`resources/js/pages/games.test.js`, `docs/architecture/ui/InertiaAppShell.yaml`,
and this review document.

1. SATISFIED: week buttons reduce from 44px to 28px, with 12px icons, 11px
   This Week text, smaller padding and gaps. Backend URLs and disabled states stay.
2. SATISFIED: default numeric descending Total; click toggles ascending/descending.
3. SATISFIED: small down/up arrow, aria-sort and action label reflect the direction;
   keyboard activation uses a native button and rotation respects reduced motion.
4. SATISFIED: alphabetical ties, unchanged incoming rows, retained direction across
   tabs, reactive ordering after count changes, and no request on sorting.

Coverage matrix (authored, not executed):

| Contract | Status | Test name |
| --- | --- | --- |
| Numeric descending, ties, immutable input, no request | COVERED | defaults to numeric descending off-night totals and breaks ties alphabetically |
| Toggle, arrow/ARIA, retained direction, updated counts, date sizes | COVERED | toggles off-night sort direction retains it between tabs and reorders changed counts |
| Week navigation and existing controls | COVERED | requests the selected week while retaining the current table during loading; uses the previous-week URL and enables This Week for a historical selection |

Frontend checklist: computed sorting copies supplied rows; mounted-only state;
DOM order, indicators, action, transitions, count updates and navigation classes
asserted. Controlled fixtures, no timers, randomness or external requests.
The endpoint/verb authorization and scope matrix above remains unchanged: this
change introduces no endpoints, writes, payload fields or permission changes.
Frontend file: 149 test declarations. No migration required.
Static diff review and whitespace checks only; tests, CI, builds and browser checks
were not run. No production operations performed.


## Fixed headers and non-off-night column backgrounds

Files changed: `resources/js/pages/Tools/Schedule.vue`,
`resources/js/pages/games.test.js`, `docs/architecture/ui/InertiaAppShell.yaml`,
and this document.

1. SATISFIED: both tables scroll rows vertically in a maximum 60dvh region.
2. SATISFIED: column headers stick at top zero with opaque backgrounds; weekly
   Team/GP header intersections use higher stacking than pinned body columns.
3. SATISFIED: non-off-night columns use gray-200 headers and gray-100 body cells;
   off-night columns use gray-50 headers and white body cells.
4. SATISFIED: remove all today-specific column backgrounds and text color.
5. SATISFIED: retain narrow mobile columns without horizontal scrolling, compact
   rows, keyboard-focusable regions, native table semantics and Total sorting.

Coverage matrix (authored, not executed):

| Contract | Status | Test name |
| --- | --- | --- |
| Both bounded vertical scroll regions and pinned headers | COVERED | keeps both table headers above vertically scrolling rows in bounded regions |
| Non-off-night shading, no today highlight and changed props | COVERED | shades non-off-night columns regardless of today and reacts to new day classifications |
| Mobile width, sorting and tabs | COVERED | fits all seven days with fixed mobile columns and retains desktop sizing; toggles off-night sort direction retains it between tabs and reorders changed counts |

Frontend checklist: scrolling/sticky/stacking class contracts asserted for both
tabs; header/body shading and reactive classification updates asserted; existing
local navigation and sorting state retained. No timers, randomness, global state
or external requests. The earlier endpoint × verb authorization/scope matrix is
unchanged; no endpoints, writes, or backend calculation changes.
Frontend file: 151 test declarations. No migration required.
Static diff review and `git diff --check` only. Tests, builds, CI and browser
checks were not run; actual sticky positioning should be visually checked in a
browser at 350px, 420px and desktop widths. No production operations performed.


## Remove space below tabs

Files changed: `resources/js/pages/Tools/Schedule.vue`,
`resources/js/pages/games.test.js`, `docs/architecture/ui/InertiaAppShell.yaml`,
and this document.

1. SATISFIED: remove the empty 20px status row, its 12px margin and the Weekly
   table's 8px top margin so column headers immediately follow the tabs.
2. SATISFIED: position explicit navigation feedback beside the tabs without layout
   space; truncate long visible errors with the full text accessible and titled.
3. SATISFIED: move the Off Nights definition after the table so it does not
   separate the tab headers from the column headers.

Coverage matrix (authored, not executed):

| Contract | Status | Existing updated test |
| --- | --- | --- |
| No Weekly table top margin | COVERED | fits all seven days with fixed mobile columns and retains desktop sizing |
| Feedback out of flow, same node retained on error and retry | COVERED | reports explicit navigation errors beside the tabs without reserving table space and permits retry |
| Off Nights table is first in panel, definition retained | COVERED | defaults to Weekly and switches to Off Nights without a request |

Frontend checklist: spacing classes/DOM order and retained navigation/error state
asserted; no new requests, timers, randomness or global state. Existing endpoint ×
verb auth/scope matrix remains unchanged; no backend or permission changes.
Frontend file retains 151 test declarations. No migration required.
Static diff review and whitespace checks only; tests, builds, browser checks and
production commands were not run.


## Schedule scroll ownership correction

Files changed: `resources/js/layouts/AppLayout.vue`,
`resources/js/pages/Tools/Schedule.vue`, `resources/js/pages/games.test.js`,
`docs/architecture/ui/InertiaAppShell.yaml`, and this document.

The earlier 60dvh table cap is superseded. It did not reserve the actual shared
header/scoreboard and mobile navigation space, and scrolling could pass to the
outer document at the table boundary.

1. SATISFIED: only Tools/Schedule uses an all-breakpoint viewport-height flex
   shell with hidden outer overflow; shared header and scoreboard do not shrink.
2. SATISFIED: reserve 5rem plus the bottom safe-area inset for fixed mobile nav;
   remove that reservation when the mobile bar is hidden at md.
3. SATISFIED: page-content, main and panels fill/shrink within the remaining
   height; tables use flex remaining space rather than 60dvh.
4. SATISFIED: table regions alone scroll vertically, with boundary overscroll and
   scroll anchoring disabled. Headers, footer and legend retain their space.
5. SATISFIED: other routes retain their prior root padding and scrolling rules;
   mobile width, compact rows, sticky headers, shading and sorting preserved.

Coverage matrix (authored, not executed):

| Contract | Status | Test name |
| --- | --- | --- |
| Schedule-only viewport, nav inset and non-scrolling content | COVERED | reserves the Schedule viewport beneath the scoreboard and above mobile navigation |
| Existing game-page root and scrolling retained | COVERED | preserves ordinary page scrolling and the existing game-page layout outside Schedule |
| Both tab regions fill available space, suppress overscroll/anchoring, keep headers | COVERED | keeps both table headers above vertically scrolling rows in bounded regions |

Frontend checklist: shell selection, route-scoped class behavior, reserved inset,
flex minimums/height, overflow ownership, table overscroll/anchoring and sticky
header contracts asserted. No wheel/touch interception, resize observers, global
state, timers or extra requests introduced. Existing navigation/sort assertions
and endpoint × verb authorization/scope matrix remain applicable; no endpoints,
backend payloads, calculations, or permissions change.
Frontend file: 153 test declarations. No migration required.
Static scoped-diff review and whitespace checks only. Tests, builds and browser
checks were not run; native scrolling, final-row reachability and safe-area
behavior need browser review at 350px, 420px and desktop, including touch input.
No production operations were performed.
