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
one hour, opt-in. Manual preparation processes all stored seasons plus current;
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
| tests/Feature/NhlScheduleTest.php | 61 |
| resources/js/pages/games.test.js | 156 |
| resources/js/admin/admin-hub.test.js | 127 |

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
enable **Auto refresh** for its hourly schedule. Cold public visits can also queue
missing known snapshots, but preparing before visitors arrive avoids the cold wait.
The existing games-processing workers and persisted admin-import dispatcher must
be running. No new worker pool or server operation was performed here.

Suggested human checks (not executed):

```sh
php artisan test tests/Feature/NhlScheduleTest.php
npm test -- --run resources/js/pages/games.test.js resources/js/admin/admin-hub.test.js
```
