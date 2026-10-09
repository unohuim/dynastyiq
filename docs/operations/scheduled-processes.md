# Scheduled discovery and parallel Engine discovery

## Deploy

**Migration required before using the settings drawer or enabling the new scheduler:**

```sh
php artisan migrate
```

Migration: `2026_10_10_000001_create_scheduled_processes_table.php`.
Deploy through the normal process, refreshing event caches and restarting existing
queue workers so the new listener and job code load. No Horizon worker configuration
is changed. The regular Laravel scheduler must still run; the manager is called
once per minute. The old direct discovery and processing schedules are removed.

## Game Import Pipeline

The active page is `/admin?tab=game-imports`, rendered by
`resources/js/pages/Admin/Dashboard.vue`. Its gear and recycle controls use the
shared page-local `adminHub` settings methods. The unused Blade dashboard and
operational partial were removed; Git history retains them for recovery.

The recycling toggle controls automatic discovery only (green on, grey off). The
gear opens persisted settings: start time in Toronto, frequency in hours (24), and
days back (3). Three means yesterday and the two days before; today is excluded.
Toggle changes save immediately. Timing/scope edits use Save settings and do not
start work immediately. An enabled due schedule is picked up by the next tick.
Disabling discovery does not cancel active imports or suppress processing of
already discovered games.

The scheduler creates the normal discovery run and queues discovery; it does not
perform imports. When all dates finish, GamesDiscovered is emitted after commit.
A queued listener starts that run's existing bounded processing service. Completed
stages and terminal failures continue filling slots without a minute-by-minute
processing schedule. Existing provider-import schedules are unchanged.

Already completed discoveries from before deployment will not retroactively emit
the new event. Use the existing manual Process action or `php artisan nhl:process`
for pending historical work. Failed queue deliveries remain visible in the failed
job tooling for operator recovery; no watchdog or automatic replay is introduced.

## SAT Engine discovery concurrency

New discovery queues all game jobs in the current stage immediately after commit.
For one pair and 25 games, all 25 jobs are available without predecessor dependencies;
Horizon workers limit simultaneous execution. Later stages are not queued early.
Large stages therefore create larger queue backlogs, not more workers.
The last committed game opens ranking. Ranking uses up to sixteen independent
split lanes and opens the next search stage only after all candidates finish.
A stage with only one ranking split still has one ranking job.

To upgrade an already-running discovery, **Pause, then Resume** in the existing UI.
The paused work generation invalidates old deliveries, preserves results, and
resumes only missing coordinates with the new scheduler. Do not edit running-run
JSON directly. Actual execution still depends on available projections workers.

## Verification (not run automatically)

```sh
php artisan test tests/Feature/ScheduledProcessesTest.php
php artisan test tests/Feature/NhlSatEnginesTest.php
npx vitest run resources/js/admin/admin-hub.test.js
```

Coverage includes scheduler settings auth/validation/readback, timing and DST,
duplicate tick prevention, past-date scope, discovery readiness and processing
guards, game-level lane filling/refill, duplicate receipts, pause/resume upgrade,
ranking barriers, and frontend save/error/toggle behavior. Existing import slots
and per-game locks remain unchanged.

### Review checklist and coverage map

| Requirement | Coverage |
| --- | --- |
| Settings GET/PUT: guests denied, non-admins denied, super-admin allowed | `ScheduledProcessesTest`: denies guest/non-admin access; provides defaults; persists toggle/timing |
| Global operational scope, no arbitrary process execution | Unknown process route rejected; fixed allowlisted key, no organization-owned records |
| Prior-date scope, enable state, timing, DST, active-run and dispatch locks | `ScheduledProcessesTest`: scheduler and timing cases |
| Event only after final distinct date; queued processing with existing guards | Readiness, listener registration, incomplete/empty/failed/terminal processing cases |
| Sixteen bounded game lanes, preserved results, duplicate receipts, ranking barrier | `NhlSatEnginesTest`: game-lane and ranking-lane cases |
| Drawer load, independent toggle, validation/network errors, save notification, duplicate-save guard | Six added `admin-hub.test.js` cases |

Frontend checklist: labeled buttons and inputs, persisted toggle state, loading and
disabled states, inline errors, success notification, existing slide-over motion,
overlay/Escape close, and no new global state. Browser interaction remains unverified.

Test declaration counts (not execution results): `ScheduledProcessesTest.php` 23,
`NhlSatEnginesTest.php` 115, `admin-hub.test.js` 85. Dataset-expanded cases are not
included in those counts. PHP and JavaScript syntax checks and `git diff --check`
passed. Automated suites and migrations were not run; execution remains with the
administrator under repository policy.

The Vue-page correction adds template-compilation/control-binding and drawer
accessibility/feedback checks to the existing frontend suite. Settings state and
save/error behavior retain their existing six checks; endpoint authorization and
database behavior are unchanged. No additional migration is required for this
UI correction. The original scheduled-process migration is still required if it
has not already been applied.
