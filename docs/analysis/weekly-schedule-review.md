# Tools Schedule

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
navigation. Local loading/error feedback has a reserved slot. The table scrolls
within its container on mobile with pinned identity and GP columns. Desktop uses
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
| Table structure, scrolling, navigation, retained rows and feedback | COVERED | renders only Team Monday through Sunday and Total GP columns; keeps horizontal scrolling inside the table with team and GP columns pinned; requests the selected week while retaining the current table during loading; updates the displayed days and rows when navigation supplies new props; reports explicit navigation errors in the reserved feedback slot and permits retry |

## Requirements checklist

1. SATISFIED: Tools contains Schedule in desktop and mobile navigation.
2. SATISFIED: public weekly schedule page defaults to the current Eastern week.
3. SATISFIED: teams are rows, Mon–Sun columns, Total GP last.
4. SATISFIED: opponent, venue and start time are shown with week navigation.
5. SATISFIED: stored schedule reads do not run imports, predictions or advanced-stat preparation.
6. SATISFIED: mobile horizontal scrolling and sticky team identity retain the grid.
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
- DOM: column order, row count, venue/time labels, sticky columns and scroll container asserted.
- Payload: navigation uses backend-owned URLs; no browser-derived league membership or dates.
- Access: keyboard dropdown and guest/member mobile Tools links exercised.
- Motion: existing dropdown/drawer patterns and reduced-motion classes retained.
- No automatic requests or background status messages added.
- No external requests, uncontrolled clocks, background jobs or global state in the new tests.

## Per-file test count

| File | `it(...)` declarations |
| --- | ---: |
| tests/Feature/NhlScheduleTest.php | 37 |
| resources/js/pages/games.test.js | 146 |

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
