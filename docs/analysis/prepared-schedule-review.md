# Prepared NHL Schedule

## Files changed

- `database/migrations/2026_11_16_210000_create_nhl_schedule_snapshots_table.php`
- `app/Services/NhlScheduleCalculator.php`
- `app/Services/NhlScheduleSnapshots.php`
- `app/Jobs/BuildNhlScheduleSnapshotsJob.php`
- `app/Console/Commands/PrepareNhlScheduleCommand.php`
- `app/Console/Commands/DispatchScheduledAdminImportsCommand.php`
- `app/Listeners/QueueNhlScheduleSnapshots.php`
- `app/Http/Controllers/NhlScheduleController.php`
- `app/Providers/AppServiceProvider.php`
- `app/Services/AdminImports.php`
- `app/Services/AdminImportSchedules.php`
- `app/Services/NhlScheduleRefresh.php`
- `resources/js/pages/Tools/Schedule.vue`
- `resources/js/pages/Admin/Dashboard.vue`
- `resources/js/admin/admin-hub.js`
- `tests/Feature/NhlScheduleTest.php`
- `resources/js/pages/games.test.js`
- `resources/js/admin/admin-hub.test.js`
- `docs/architecture/ui/NhlScheduleSnapshots.yaml`
- `docs/architecture/ui/InertiaAppShell.yaml`
- `docs/architecture/admin/AdminImportRegistry.yaml`
- `docs/architecture/imports/NhlScheduleRefresh.yaml`
- `docs/DB_SCHEMA.md`
- `docs/ENUMS.md`
- `docs/ARCHITECTURE_INVENTORY.md`
- `docs/analysis/weekly-schedule-review.md`
- This document.

## Storage and behavior

`nhl_games` remains authoritative source data. The new table stores durable,
replaceable JSON display payloads: one indexed row per Monday-based week and one
row for the current season's remaining off-night counts as of an Eastern date.
Build today and tomorrow within the same season so midnight normally stays warm.
The payload includes calculated team totals; these values do not depend on Redis
retention or cache eviction. A successful build atomically upserts replacements.
No source results are modified, copied into another fact model, or reimported.

The former HTTP calculation has been moved to a worker-only calculator. Admin
Game Imports gains Prepare NHL Schedule, Build Schedule, normal ImportRun progress,
Auto refresh and the existing schedule settings drawer. The default interval is
24 hours for new settings, opt-in; existing saved intervals are preserved. Manual preparation processes all stored seasons plus current;
the command can also target one season. Scheduled refreshes target only the
current season; historical seasons are not rebuilt every hour. Progress advances after each season has
published. Both admin batches and background builds run on games-processing.

A worker prepares all calendar weeks, including empty weeks and cross-season
boundary weeks. Current-season builds also publish remaining totals. Off-night
date lists and unused logos are removed from the display payload. Game hrefs are
relative because the worker host can differ from the public web host.

Source schedule refresh writes queue affected season builds. Live FINAL/OFF
changes and completed season-stat syncs also queue builds. Clock-only live changes
do not. Queued season builds deduplicate until processing begins, retain their
pending unique admission while waiting behind overlap middleware, and share a
publication lock with manual builds. New source writes during a build can queue a
follow-up. Admission failures are reported without failing source imports.

Warm reads query only prepared rows, decode payloads, and update inexpensive
calendar/navigation metadata. Missing known source seasons queue preparation
without calculating on the request. Unknown empty seasons cannot admit arbitrary
historical builds. If preparation falls behind the prebuilt next date, retain the latest non-future
as-of-labelled totals while preparing the requested date. An initial cold view shows preparation
feedback, polls only the prepared read, and stops after 30 attempts with an
explicit retry message. Old rows remain visible during any rebuild.

Week navigation requests only schedule props, avoiding repeated shared shell and
fantasy-navigation assembly. A mounted-only 16-entry cache reuses loaded weeks
for five minutes using Inertia client-side visits. It preserves browser history,
retains other props, and refuses reuse when the off-night build timestamp changes.
Timers and cache state are disposed on unmount. No global browser state is added.
The router interface was checked against the official
[Inertia v3 manual visits documentation](https://inertiajs.com/docs/v3/the-basics/manual-visits).

## Minimum coverage matrix

COVERED means authored assertions, not executed tests.

| Contract | Status | Test names |
| --- | --- | --- |
| Prepare → database → actual HTTP display | COVERED | prepares source data through the command persists it and serves it through HTTP |
| Upsert and retain old data on failure | COVERED | upserts published snapshots without duplicating weeks; keeps the last published snapshot unchanged when replacement calculation fails |
| Warm read has no source queries | COVERED | warm reads do not query source games or team identities |
| Cold read queues, never calculates inline | COVERED | cold reads queue preparation without calculating or publishing source data inline |
| Rollover and old-data retention | COVERED | prepares the next Eastern day so ordinary midnight rollover remains a warm read; retains last off-night totals while queuing the new Eastern dates preparation |
| Counts-only payload and timestamps | COVERED | stores off-night counts and build timestamps without the unused game-date list |
| Queue, uniqueness and overlap wiring | COVERED | uses the game-processing queue and shared database uniqueness for background builds |
| Season validation and cross-season refresh | COVERED | rejects invalid seasons before calculating or writing snapshots; queues both seasons affected by schedule edits across September first |
| Prevent arbitrary empty-season builds | COVERED | does not queue builds for an arbitrary season with no stored source schedule |
| Final facts and rollups queue affected builds | COVERED | refreshes snapshots for final changes while ignoring ordinary live clock updates; queues the affected season after season-stat source rollup commits |
| Admin build and scheduling access | COVERED | blocks non-admin schedule preparation and scheduling mutations; lets super-admins queue schedule preparation with a persistent progress run; lets super-admins save the existing automatic preparation schedule without running it |
| Scheduled scope | COVERED | limits scheduled refreshes to the current season while manual builds can prepare history |
| Existing weekly/off-night correctness | COVERED | existing NhlScheduleTest tests now prepare snapshots before HTTP reads, preserving venue, dates, counts, cutoff, empty rows and remaining-season assertions |
| Partial visits and loaded-week reuse | COVERED | requests only prepared schedule props when changing to an uncached week; reuses a loaded week immediately through client-side navigation without another request |
| Cold polling and lifecycle cleanup | COVERED | polls snapshot preparation without calculating in the browser and stops when prepared |
| Admin markup, shared actions and progress | COVERED | exposes queued build progress and existing scheduling controls inside Game Imports |

## Requirements checklist

1. SATISFIED: durable table holds prepared weeks and off-night totals.
2. SATISFIED: use existing admin run, progress and scheduling pathways.
3. SATISFIED: source changes/finals/rollups and missing rollover snapshots queue refreshes.
4. SATISFIED: public warm navigation reads prepared rows without source aggregation.
5. SATISFIED: old data survives failed builds and remains readable during replacement.
6. SATISFIED: browser reuses loaded weeks briefly without another server request.
7. SATISFIED: existing compact/sticky/mobile schedule UI and sorting retained.
8. SATISFIED: documentation, enums, migration handoff and regression coverage authored.
9. SATISFIED: no dependencies, engine changes, source-result changes or production operations.

## Endpoint × verb authorization/scope

| Endpoint / verb | Scope | Test names |
| --- | --- | --- |
| GET /tools/schedule | Guest | allows guests to view the schedule in the shared shell |
| GET /tools/schedule | Ordinary member | allows authenticated members without an admin role to view the same schedule |
| GET /tools/schedule | Invalid week rejected | rejects malformed impossible and non-scalar week dates |
| GET /tools/schedule | Off Nights bound to current season; unknown seasons cannot enqueue arbitrary builds | keeps remaining off-night data identical when navigating historical or future weeks; does not queue builds for an arbitrary season with no stored source schedule |
| POST /admin/imports/nhl-schedule-snapshots/run | Guest/member denied | blocks non-admin schedule preparation and scheduling mutations (both auth variants) |
| Same | Super-admin allowed and batch queued | lets super-admins queue schedule preparation with a persistent progress run |
| PUT /admin/imports/nhl-schedule-snapshots/schedule | Guest/member denied | blocks non-admin schedule preparation and scheduling mutations (both auth variants) |
| Same | Super-admin allowed; save only, no dispatch | lets super-admins save the existing automatic preparation schedule without running it |

These are global public NHL views and existing super-admin operational settings;
organization/owner scoping is not applicable. The new command round-trip test
executes preparation in the test, asserts stored rows, then reads actual HTTP.
It does not rely solely on a GET echo assertion.

## Frontend component checklist

- Existing state transitions, sorting, column structure and scroll contracts retained.
- Partial visits, client-side cache/history props, five-minute expiry and unchanged
  shared props asserted with router mocks.
- Initial cold feedback suppresses false empty-state messaging; prepared reads stop
  polling and unmount disposes timers.
- Admin control wiring and full Vue template compilation are asserted by an authored
  test; existing admin-hub tests cover the shared start/schedule/progress behavior.
- Fixed time/fake timers, no external services, randomness or background execution.
- No new global state, dependencies or executable Blade scripts.

## Per-file test counts

| File | it declarations |
| --- | ---: |
| tests/Feature/NhlScheduleTest.php | 75 |
| resources/js/pages/games.test.js | 156 |
| resources/js/admin/admin-hub.test.js | 133 |

## Verification and required setup

Only manual scoped-diff review and `git diff --check` were performed. Tests,
builds, browser checks, migrations, imports, jobs and production commands were
not run. Actual runtime speed and browser behavior have not been measured.

**Migration required:**
`2026_11_16_210000_create_nhl_schedule_snapshots_table.php`.
The migration creates an empty table; no historical copy or calculation occurs.
Before using the feature, the human must run:

```sh
php artisan migrate --force
```

Then use **Admin → Game Imports → Prepare NHL Schedule → Build Schedule** and
enable **Auto refresh** for its daily-default schedule. Existing saved intervals
can be adjusted through the gear. Cold public visits can also queue
missing known snapshots, but preparing before visitors arrive avoids the cold wait.
The existing games-processing workers and persisted admin-import dispatcher must
be running. No new worker pool or server operation was performed here.

Suggested human checks (not executed):

```sh
php artisan test tests/Feature/NhlScheduleTest.php
npm test -- --run resources/js/pages/games.test.js resources/js/admin/admin-hub.test.js
```


## Daily default and settings gear

Files changed: `app/Services/AdminImportSchedules.php`,
`resources/js/pages/Admin/Dashboard.vue`, `tests/Feature/NhlScheduleTest.php`,
`resources/js/admin/admin-hub.test.js`, `docs/architecture/ui/NhlScheduleSnapshots.yaml`,
`docs/ARCHITECTURE_INVENTORY.md`, and this document.

1. SATISFIED: new preparation schedules default to 86400 seconds, disabled until enabled.
2. SATISFIED: existing saved frequencies remain unchanged and editable.
3. SATISFIED: a labelled gear button opens the existing frequency settings;
   Auto refresh and Build Schedule retain their existing actions.

Authored coverage matrix:

| Contract | Status | Test |
| --- | --- | --- |
| Daily initialization, saved interval preservation, no dispatch on read | COVERED | defaults new automatic schedule preparation to daily without replacing saved intervals |
| Gear icon, accessible name, dialog action and retained controls | COVERED | exposes queued build progress and existing scheduling controls inside Game Imports |

The endpoint × verb authorization/scope matrix above is unchanged and remains
covered by its named guest/member/super-admin tests. Frontend checklist: native
keyboard button, accessible action label and dialog semantics, existing open
handler, disabled-saving state, reduced-motion color feedback and retained toggle
wiring. Controlled fixtures, no new global state, requests or timers.
Test counts: PHP 62, pages JS 156 (unchanged), admin-hub JS 127.
Only static diff and whitespace checks were performed. Tests, builds, browser
checks, migrations and production operations were not run. No new migration is
required for this default/icon adjustment; the original snapshot-table migration
remains required if it has not yet been applied.


## Daily clock time, next run and recycle control

Files changed:

- `app/Services/AdminImportSchedules.php`
- `app/Http/Controllers/Admin/ImportsController.php`
- `resources/js/admin/admin-hub.js`
- `resources/js/pages/Admin/Dashboard.vue`
- `tests/Feature/NhlScheduleTest.php`
- `resources/js/admin/admin-hub.test.js`
- `docs/architecture/ui/NhlScheduleSnapshots.yaml`
- `docs/ARCHITECTURE_INVENTORY.md`
- This document.

1. SATISFIED: use the existing circle/recycle SVG control, green when enabled,
   immediately saving its boolean and restoring state on failure.
2. SATISFIED: adjacent gear retains the existing settings dialog. When frequency
   equals 24 hours, expose an optional native daily-time input fixed to Eastern.
3. SATISFIED: reuse existing daily_start_time/timezone fields and daily scheduling
   logic for this source. A selected time follows America/Toronto across DST;
   a missed un-dispatched daily time remains due. Without time, use dispatch-based
   24-hour intervals. Other saved intervals remain supported.
4. SATISFIED: show configured daily time or interval and next_due_at on the card,
   formatting the due timestamp in Eastern with EST/EDT. Disabled means no next run.
5. SATISFIED: import-status reads refresh the saved timing after dispatch, while
   pending settings writes retain their local state and the failure remains visible.
6. SATISFIED: preserve source permissions, queued execution, default-disabled
   auto refresh and separation between saving settings and executing work.

Coverage matrix (authored, not executed):

| Contract | Status | Test names |
| --- | --- | --- |
| Admin timing save → DB → status read | COVERED | saves daily Eastern run time through admin HTTP and returns it with the next run |
| Preserve configured time through enabled toggle | COVERED | retains the chosen daily time when the recycle toggle changes enabled state |
| Spring/fall wall-clock cadence | COVERED | keeps the same Eastern daily time through daylight-saving transitions (two DST cases) |
| Blank-time/subdaily behavior | COVERED | uses dispatch-based interval cadence when no start time is selected |
| Reject invalid time | COVERED | rejects invalid daily clock input on the admin schedule endpoint |
| Settings hydrate/save and toggle request | COVERED | opens the existing gear settings with the Eastern daily clock and preserved frequency; saves the chosen daily time and reuses it when enabling the recycle toggle |
| Card formatting and disabled state | COVERED | shows the configured daily time and the next run in Eastern standard or daylight time |
| Poll timing without clobbering a save | COVERED | refreshes the displayed next run from status without overwriting settings while saving |
| Failed toggle rollback | COVERED | restores the toggle and shows the save failure when scheduling is rejected |
| Icon/time-input markup and retained handlers | COVERED | exposes queued build progress and existing scheduling controls inside Game Imports |

Additional endpoint × verb authorization/scope:

| Endpoint / verb | Scope | Test names |
| --- | --- | --- |
| GET /admin/imports/nhl-schedule-snapshots/status | Guest/member denied | restricts prepared schedule timing status to super-admins (two auth cases) |
| Same | Super-admin sees saved clock and next run | saves daily Eastern run time through admin HTTP and returns it with the next run |
| PUT /admin/imports/nhl-schedule-snapshots/schedule | Existing guest/member denial retained | blocks non-admin schedule preparation and scheduling mutations |
| Same | Super-admin save and time validation | saves daily Eastern run time through admin HTTP and returns it with the next run; rejects invalid daily clock input on the admin schedule endpoint |

Frontend checklist: accessible icon labels and pressed state, native time input,
reactive configured/next-run text, request payload, failed-save rollback and status
refresh guards asserted. Fixed clocks, mocked fetch, controlled fixture dates and
explicit timezone formatting; no external calls, global state or new timers.
Counts: PHP 68; admin-hub JS 132; pages JS 156 unchanged.
Only manual scoped-diff review and whitespace checks were performed. Tests, builds,
CI, browser checks and production operations were not run. No new migration is
required; the original snapshot-table migration still applies if not installed.


## Eastern start clock at every interval

Files changed: `app/Services/AdminImportSchedules.php`,
`resources/js/admin/admin-hub.js`, `resources/js/pages/Admin/Dashboard.vue`,
`tests/Feature/NhlScheduleTest.php`, `resources/js/admin/admin-hub.test.js`,
`docs/architecture/ui/NhlScheduleSnapshots.yaml`, `docs/ARCHITECTURE_INVENTORY.md`,
and this document.

1. SATISFIED: always expose Start time (Eastern) for prepared schedules, including
   three-hour or shorter intervals; retain existing persisted clock/timezone fields.
2. SATISFIED: activation chooses the next aligned clock slot at or after now.
   For example, three hours from 06:00 yields 06:00, 09:00, 12:00 and 15:00.
3. SATISFIED: later dispatches advance from the saved scheduled instant, not actual
   dispatch time. Skip expired slots with one next due time; no missed-run burst.
4. SATISFIED: retain a running interval across midnight and unchanged saves;
   changing frequency/start clock reanchors future slots. Avoid repeating a slot
   already dispatched when settings change at that instant.
5. SATISFIED: daily schedules keep their existing Eastern wall-clock/DST behavior.
   Other intervals use elapsed seconds after their initial Eastern anchor, including
   through DST and midnight, rather than resetting into a daily operating window.
   A blank start time retains the existing dispatch-based cadence.
6. SATISFIED: card describes the interval and start clock, with its next run;
   source permissions, recycle/gear actions and settings storage are retained.

Coverage matrix (authored, not executed):

| Contract | Status | Test names |
| --- | --- | --- |
| First aligned slot before/at/after start | COVERED | aligns the first interval to its Eastern start time before or after that clock time (three cases) |
| Late dispatch and missed slots | COVERED | keeps later intervals aligned when a scheduled run is dispatched late; skips missed interval slots without shifting the next slot or queuing catch-up work |
| Continuous interval across midnight; unchanged save | COVERED | preserves a running interval across midnight and unchanged settings saves |
| Changed clock/frequency and same-slot safety | COVERED | reanchors future intervals when the chosen start time or frequency changes; does not repeat an anchored slot when the frequency changes just after dispatch |
| Three-hour HTTP save → stored settings → status read | COVERED | round trips a three-hour frequency and Eastern start time through admin HTTP |
| Blank-time compatibility | COVERED | uses dispatch-based interval cadence when no start time is selected |
| Visible field, card description and save payload | COVERED | shows and saves a start time for a three-hour frequency; exposes queued build progress and existing scheduling controls inside Game Imports |

The endpoint × verb authorization/scope matrix above remains applicable, including
member/guest rejection and super-admin mutation/status reads. Frontend checklist:
source-specific field always shown, interval/start summary, settings hydration and
request payload asserted; no new timers, global state or external requests. Test
fixtures retain fixed clocks and mocked fetch. Counts: PHP 75, admin-hub JS 133,
pages JS 156 unchanged. Only manual diff and whitespace checks performed; tests,
builds, browser checks and production operations were not run. No migration
required.
