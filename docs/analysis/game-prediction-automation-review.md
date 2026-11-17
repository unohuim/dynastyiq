# Game Predictions automation review

## Files changed or created

- `app/Events/NhlPregameContextDayCompleted.php`
- `app/Listeners/QueueNhlGamePredictions.php`
- `app/Http/Controllers/Admin/NhlGamePredictionController.php`
- `app/Providers/AppServiceProvider.php`
- `app/Services/NhlGamePredictionHistory.php`
- `app/Services/NhlPregameContextOrchestrator.php`
- `app/Services/ScheduledProcessManager.php`
- `routes/web.php`
- `resources/js/pages/Admin/GamePredictions.vue`
- `resources/js/pages/Admin/game-predictions.test.js`
- `tests/Feature/NhlGamePredictionAutomationTest.php`
- `docs/architecture/application/ScheduledProcessManager.yaml`
- `docs/architecture/stats/NhlGamePredictionHistory.yaml`
- `docs/ENUMS.md`
- `docs/UI_DESIGN.md`
- `docs/ARCHITECTURE_INVENTORY.md`
- `docs/analysis/game-prediction-automation-review.md`

## Minimum coverage matrix

Coverage refers to authored tests and source review, not passing execution results.
Backend tests below live in `NhlGamePredictionAutomationTest.php`; frontend tests
live in `game-predictions.test.js`. Existing manual prediction regression coverage
in `NhlOperationalGamePredictionsTest.php` remains unchanged.

| Contract | Authored coverage | Test names |
| --- | --- | --- |
| Settings defaults and no work on read/save | COVERED | reads disabled six AM daily defaults without creating a schedule; saves timing through HTTP and reads the same persisted settings without starting work; saves event mode without requiring a time or interval |
| Request validation and one selected mode | COVERED | rejects invalid or combined trigger settings; does not permit client timezone or command overrides |
| Persisted settings and read-after-write | COVERED | saves timing through HTTP and reads the same persisted settings without starting work; keeps one settings row when changing modes and preserves event deduplication evidence |
| Auth for both endpoints | COVERED | denies guests each automation endpoint; denies ordinary members each automation endpoint; saves timing through HTTP and reads the same persisted settings without starting work |
| Timer admission, due dates, frequency and DST | COVERED | waits until six AM Eastern on the initial daily schedule; does not duplicate repeated timer ticks or burst missed intervals; keeps daily wall time across daylight-saving changes; uses the configured subdaily interval after actual dispatch; resets due timing when saving a different mode or start time |
| Mode/enable exclusion | COVERED | does not dispatch disabled prediction automation; does not start time-based runs while event mode is selected; ignores completion notifications in time mode or when automation is disabled; does not enqueue a completion listener when automation is disabled or time-based |
| Today/upcoming scope and empty slate | COVERED | skips past future live and final games during automatic admission; skips nights with no upcoming games without creating an empty prediction run |
| Default engine, queue and saved run read | COVERED | queues the current engine on projections and exposes its automatic run through HTTP; retains explicit manual ownership and the configured algorithm definition |
| Current-model completion and duplicates | COVERED | admits one event run for complete current-model context and deduplicates repeated notifications; ignores context notifications for another model or another game date |
| Partial slate, both teams, players and comparisons | COVERED | does not predict before every current slate game has context; requires both teams and player snapshots in the current context version; rejects incomplete blocked or failed context day states; requires separate comparisons for the older three-stage pipeline |
| Builder notification and blocked dates | COVERED | publishes a current-day completion event from the ordinary loader after readiness; does not publish context readiness while selected games remain excluded |
| Existing manual/API work and deferred event retry | COVERED | suppresses automatic overlap with a manual run without consuming the due time; suppresses automatic overlap with an active API prediction lease; retries busy event work without marking its context event consumed; releases a busy event listener and stops retrying when its date expires |
| Queued listener rechecks settings | COVERED | keeps a queued listener harmless after the setting changes |
| Publication errors and manual scope preservation | COVERED | records queue publication failures as failed run items instead of leaving queued work stuck; preserves explicit manual prediction admission for historical and final games |
| Gear, exclusive controls and always-visible time | COVERED | opens automation from the top right gear without starting a prediction; shows disabled daily six AM defaults and an always visible start time in time mode; switches to event mode instead of combining triggers and preserves timing edits |
| Frontend payload, CSRF, errors and retries | COVERED | saves the master toggle trigger and timing with CSRF through the settings endpoint; retains user settings and shows inline validation after a failed save; disables saving until settings load and permits a scoped retry |
| Modal focus, dismissal, busy state and cleanup | COVERED | closes with Escape and restores focus and the previous body scrolling; traps forward and backward keyboard navigation inside the drawer; blocks duplicate saves and does not dismiss while a save is pending; aborts drawer reads and restores scrolling when the tab unmounts |
| Stable content and local UI state | COVERED | keeps the prediction table mounted and stable during automation edits and polling; uses independent motion transitions and honors reduced motion |

## Requirements checklist

1. SATISFIED: top-right gear opens a styled Vue-owned slide-over with saved local
   form state, independent motion, reduced-motion classes, focus trapping and restoration.
2. SATISFIED: the master switch and exactly one trigger mode are persisted in the
   existing scheduling table; defaults are disabled and daily at 06:00 Eastern.
3. SATISFIED: time-based automatic runs are admitted through the existing minute
   scheduler. Event mode uses committed complete context for the current day and
   selected model, including comparisons, both teams and player snapshots.
4. SATISFIED: automatic runs use the same default engine, projections queue,
   durable work items and revision boundary as manual requests. Calculations and
   existing manual date/range scope are preserved.
5. SATISFIED: row locks, active-run/API-lease guards and per-date/context-run
   evidence prevent overlapping automatic runs and duplicate event admission.
6. SATISFIED: no migration, worker/configuration operations, production edits,
   enabled production automation or prediction execution performed by Codex.

## Endpoint × verb authorization/scope matrix

| Endpoint / verb | Guest | Ordinary member | Super-admin |
| --- | --- | --- | --- |
| GET /admin/game-predictions/automation | 401: denies guests each automation endpoint | 403: denies ordinary members each automation endpoint | 200: reads disabled six AM daily defaults without creating a schedule |
| PUT /admin/game-predictions/automation | 401: denies guests each automation endpoint | 403: denies ordinary members each automation endpoint | 200 + DB + GET: saves timing through HTTP and reads the same persisted settings without starting work |

These settings are global operational configuration. There is no tenant or
owner-specific settings scope. Existing super-admin and lifecycle middleware
remain authoritative. Existing manual/read prediction endpoints retain their
unchanged authorization and regression suite.

## Frontend checklist

- Gear opens an accessible teleported dialog without replacing the table.
- Read/save requests use the existing component's cancellation and CSRF patterns.
- One selector chooses a single mode; event mode removes timing controls.
- Start time remains present for every time-based interval, including three hours.
- Focus, Escape, overlay dismissal, scrolling and unmount cleanup are handled locally.
- Saving prevents duplicate requests; server errors preserve form values.
- No new global state, background notification banners or page reloads.
- Tests use mocked fetch, a fixed clock and controlled timers; no provider calls.

## Test counts and checks

- `tests/Feature/NhlGamePredictionAutomationTest.php`: 36 `it(...)` declarations,
  with additional parameterized authorization/validation/state cases.
- `resources/js/pages/Admin/game-predictions.test.js`: 62 test declarations,
  including three `it.each(...)` declarations and retained existing tests.

Performed: source/diff review and `git diff --check`.
Not performed: tests, CI, builds, lint, browser checks, migrations, queue commands,
scheduler commands or production operations, per repository execution rules.
No migration required. After deployment, configure and enable the desired mode
using the gear in Admin → Game Predictions. Saving does not run predictions.
