# SAT engine management review

## Default engine

`is_default` is a single boolean on `nhl_sat_engines`, backed by a partial unique
database index. The first engine becomes default automatically. The list marks it
and offers Make default on every other engine. Switching starts no work. Deleting
the default promotes the newest remaining engine. Ordinary prediction calls use
the default engine's model, offense/defense settings, confidence bounds and gap;
historical evaluations stay explicitly pinned.

Auth coverage includes the default-selection route. Tests were authored for first
creation, list output, switching, deletion promotion, and the direct list action.
No migration or test was run.

## Test terminology

Engine buttons, process selector, explanatory copy, evaluation heading and history
now display Test instead of Build. The persisted/request kind remains `build`.
This is a presentation change; existing runs and calculation behavior are preserved.
Changed files: `Workspace.vue`, `Run.vue`, `engine-ui.test.js`, `docs/ENUMS.md`
and this review file. Existing component assertions cover the renamed selector,
button, history and evaluation heading. Test counts remain 66 PHP / 42 JavaScript;
tests were not executed. Existing authorization/scope matrices remain applicable.

## Direct engine creation from results

Both result tables expose Create engine on evaluated rows. A native modal asks
for a name, previews the clicked candidate's exact settings, focuses the name
field and restores focus on cancellation. Polling cannot replace the candidate
being saved. Pending candidates have no creation action. Loading disables repeat
submission and errors remain in the prompt.

The existing scoped adoption endpoint accepts evaluated candidates from active
or complete runs. It rejects pending candidates, failed/cancelled runs, invalid
names and cross-run IDs. The server copies persisted model/settings and ignores
client settings. Saving redirects to the new engine without starting a build or
changing discovery state. Existing-engine adoption is still available in details.

Changed files for this revision: `Run.vue`, `NhlSatEngineController.php`, both
test suites listed below, `NhlSatEngines.yaml`, architecture inventory and this
review file. No migration or prediction formula changes.

## Automatic settings discovery

Discovery now takes only model, game scope, desired win% and minimum coverage.
Manual settings remain in Build; switching an existing engine to Discovery hides
its settings editor without discarding edits. Neither UI submissions nor new
backend runs use client-provided search ranges.

The backend searches independent 0–200% weights every 25 points (81 pairs), then
refines up to three distinct promising pairs at five-point and one-point steps.
Centers first meet both targets, then minimize summed target shortfalls, then
rank by win%, coverage and stable ID. Previously evaluated pairs are reused.
At most 807 pairs can be scheduled before deduplication. This staged search is
not exhaustive over every possible pair and does not guarantee a global optimum.
Four sequential lanes bound queue fan-out. Refinement appends work atomically;
the displayed stage explains why the progress denominator can increase.

For each pair, qualification search reads only scalar confidence/gap/correctness
for at most 3000 games. It evaluates every distinct gap selection within 0–10 goals
at the stored six-decimal precision and every inclusive confidence interval.
It retains the win/coverage frontier across both dimensions without pruning
below-target alternatives. All-game totals remain independent of qualification.
No prediction is rerun merely to test another confidence interval or gap.

Files changed for this revision:

- `app/Http/Controllers/Admin/NhlSatEngineController.php`
- `app/Services/NhlSatEngineSettings.php`
- `app/Services/NhlSatEngineEvaluator.php`
- `app/Jobs/EvaluateNhlSatEngineGameJob.php`
- `app/Jobs/RankNhlSatEngineCandidatesJob.php`
- `resources/js/pages/Admin/SatEngines/Workspace.vue`
- `resources/js/pages/Admin/SatEngines/Run.vue`
- `resources/js/pages/Admin/SatEngines/SettingsFields.vue`
- `resources/js/pages/Admin/SatEngines/engine-ui.js`
- `resources/js/pages/Admin/SatEngines/engine-ui.test.js`
- `tests/Feature/NhlSatEnginesTest.php`
- `docs/architecture/stats/NhlSatEngines.yaml`
- `docs/ARCHITECTURE_INVENTORY.md`
- `docs/ENUMS.md`
- `docs/DB_SCHEMA.md`
- `docs/testing/nhl-sat-engines-review.md`

No schema changes or existing result rewrites. Legacy runs retain their original
weight/gap behavior. Runtime and production-sized timing remain unverified.

## Counted game selection and date layout

Starting and ending dates share a dedicated two-column row. Counted game and
game-day scopes show First / Last / Random immediately after Count, defaulting
to First. Date/season/team filters precede selection. Last uses chronological
date and game-ID ordering. Random samples without replacement using a
server-generated seed; the seed and selected IDs are frozen before jobs start.
Workers never resample. Date samples include every matching game on each chosen
date. Entire-season and explicit-ID modes ignore counted-selection state.

No migration is needed; selection lives in the existing run definition JSON.

## Automatic confidence discovery and coverage alternatives

Earlier discovery runs searched explicit offense, matching-defense and gap axes.
Confidence bounds are derived automatically across 0–100. SQL supplies at most
101 confidence counts for each weight/gap combination; the interval comparison
then uses those counts without additional prediction calls or per-interval SQL.
Equivalent selections are collapsed and only non-dominated win-rate/coverage
alternatives are retained. This preserves the optimal win rate for every possible
minimum pick count within each weight/gap combination. Targets do not prune this
search, so useful below-target alternatives survive.

The result page separately highlights retained candidates meeting desired win%
but below minimum coverage. It orders these by closest coverage, then win%, and
labels shortfall in percentage points. It has independent pagination and does
not mark these candidates as satisfying both targets. They can still be adopted.

No schema change is required for this revision. Start a new discovery to use
automatic confidence search. Existing completed results are not rewritten;
legacy queued explicit-range runs and configured engine builds keep their bounds.
Legacy progress counts weight/gap searches; new automatic search counts weight
pairs, not the number of qualification alternatives created from those pairs.

## Scope and files

New migration `database/migrations/2026_10_01_000001_create_nhl_sat_engines.php`.
New backend files:

- `app/Models/NhlSatEngine.php`
- `app/Models/NhlSatEngineRun.php`
- `app/Services/NhlSatEngineSettings.php`
- `app/Services/NhlSatEngineEvaluator.php`
- `app/Http/Controllers/Admin/NhlSatEngineController.php`
- `app/Jobs/EvaluateNhlSatEngineGameJob.php`
- `app/Jobs/RankNhlSatEngineCandidatesJob.php`

Existing prediction integration: `NhlGamePredictionPayload.php`,
`NhlProjectedTeamMatchupSimulator.php`, `NhlSatModelPredictionService.php`.
Routes: `routes/web.php`. Existing shared navigation gains a link in
`resources/views/nav/partials/_right-account-drawer.blade.php`; no new Blade page
or Alpine/Livewire behavior is added.

Vue files under `resources/js/pages/Admin/SatEngines/`: `Index.vue`,
`Workspace.vue`, `Run.vue`, `SettingsFields.vue`, `Pagination.vue` and `engine-ui.js`.

Documentation: `docs/architecture/stats/NhlSatEngines.yaml`, related prediction
architecture YAML, inventory, enums, schema inventory and partner API guide.

## Coverage matrix

COVERED means assertions authored, not executed. Runtime behavior and production
performance remain unverified. Tests use fixed clocks, blocked provider HTTP,
fake queue dispatch and isolated fixtures. No real jobs or imports are started.

| Requirement | Authored status | Evidence |
| --- | --- | --- |
| Vue/Inertia CRUD and request → database → HTTP read | COVERED | `creates an engine without dispatching work and reads it through Inertia` |
| Read-only discovery page | COVERED | `renders discovery without creating an engine` |
| Validated settings and immutable old runs | COVERED | `updates settings while preserving prior run definitions`, boundary validation tests |
| Explicit build request → persisted snapshot → HTTP progress | COVERED | `starts a build through HTTP and persists its snapshot before dispatch` |
| Model-owned test horizon and leakage rejection | COVERED | test-season, missing-season and training-leakage tests |
| Game/day/team/ID scope and stable ordering | COVERED | scope-specific tests in `NhlSatEnginesTest.php` |
| Independent weight math and preserved default | COVERED | `weights offense and only matching defense without normalization` |
| Finite grid and decimal steps | COVERED | search-bound and decimal-step tests |
| Qualification, all-game actuals, picks and coverage denominators | COVERED | gap-boundary, aggregate and zero-pick tests |
| Cancellation, duplicate delivery, model mutation, terminal protection | COVERED | worker and lifecycle tests |
| Candidate adoption and run scope | COVERED | completed-candidate and cross-run-adoption tests |
| Frontend requests, controls, polling cleanup and actual totals | COVERED | `engine-ui.test.js` |
| Automatic confidence range completeness | COVERED | `matches an exhaustive confidence search for every minimum pick count`; 0/100 boundary and outside-old-band tests |
| No repeated SQL per confidence interval | COVERED | `aggregates confidence discovery with bounded queries and all-game totals` |
| Multiple retained intervals, one progress receipt, duplicate delivery | COVERED | `persists automatic ranges once while counting the search once` |
| 38% coverage remains visible alongside 40% matches and is adoptable | COVERED | `surfaces a 38 percent candidate separately without marking it as meeting 40 percent` |
| Confidence controls removed only from discovery; shortfall UI | COVERED | added `engine-ui.test.js` discovery, saved-engine, near-miss and polling cases |
| First/last/random selection after filters; all games on selected dates | COVERED | last-game, same-date tie-break, last-day and fixed-seed random game/day tests |
| Frozen random sample and validated selection | COVERED | invalid HTTP selection and fixed server-seed run snapshot tests |
| Date row, selector placement/default and outgoing selection | COVERED | five additional `engine-ui.test.js` scope/layout cases |
| No manual discovery fields or server dependency on client ranges | COVERED | `asks only for model scope and targets during discovery`; `starts automatic discovery from model scope and targets with four bounded lanes`; stale-range tests |
| Independent broad weights and bounded duplicate-free refinement | COVERED | `searches independent full-domain weights and refines without repeating evaluated splits`; `bounds refinements to three centers and legal unique percentages` |
| Gap/confidence frontier matches exhaustive qualification outcomes | COVERED | `matches exhaustive gap and confidence selections for every minimum pick count`; exact-gap, tied/excluded and no-evidence tests |
| Four lanes, stage receipts and stable selected sample | COVERED | `advances a bounded lane to its next split only after the last game`; `appends refinement work once and preserves the original game sample`; final-stage test |
| Discovery/build switch, exact-gap editor and refinement progress | COVERED | new `engine-ui.test.js` mode-switch, precision and progress tests |
| Direct creation while discovery continues, exact persisted settings and request → DB → read | COVERED | `creates an engine from an evaluated candidate while discovery continues` across four allowed states |
| Pending, failed/cancelled, invalid name and cross-run rejection | COVERED | existing pending/cross-run tests plus `rejects candidate adoption from failed or cancelled runs` and `requires an engine name without changing the evaluated candidate` |
| Both row actions, clicked-row identity, focus/cancel and polling stability | COVERED | four new direct-creation component tests |
| Real historical prediction end-to-end execution and runtime | NOT EXECUTED | Human validation required with a prepared model and stored boxscores |

## Requirements checklist

1. SATISFIED in source: saved engine settings separate from model training.
2. SATISFIED in source: explicit build/discovery, bounded jobs, finite search, persisted results.
3. SATISFIED in source: model test season, user game/day/team scope, unique game counts.
4. SATISFIED in source: shared prediction calculation with isolated per-call weights.
5. SATISFIED in source: targets, candidate records, all-game predicted/actual totals and adoption.
6. SATISFIED in source: current partner API parameters, defaults and qualification remain unchanged.
7. SATISFIED in source: new Vue/Inertia pages, regular shared application shell.
8. NOT VERIFIED at runtime: migration, PHP tests, JavaScript tests, queue execution and production-sized evaluation.
9. SATISFIED in source and authored assertions: automatic confidence discovery, bounded aggregate inputs, non-dominated alternatives, independent below-coverage highlights and exact target flags.
10. SATISFIED in source and authored assertions: First / Last / Random counted selection, frozen samples, and paired date layout.
11. SATISFIED in source and authored assertions: automatic backend-owned O/D discovery, complete qualification search per tested pair, bounded refinement, optional candidate adoption and preserved manual Build settings.
12. SATISFIED in source and authored assertions: direct creation from evaluated rows while discovery continues, preserving exact settings, run scope and super-admin authorization.

## Endpoint × verb authorization/scope

All routes use existing authenticated super-admin and lifecycle middleware. NHL
data is global; tenant, owner and organization scope do not apply. Candidate IDs
must belong to the route's run.

| Route suffix | Verb | Guest / ordinary user | Authorized coverage |
| --- | --- | --- | --- |
| `/` | GET | Both endpoint-matrix tests | `renders the engine index as an Inertia page` |
| `/` | POST | Both endpoint-matrix tests | `creates an engine without dispatching work and reads it through Inertia` |
| `/discover` | GET | Both endpoint-matrix tests | `renders discovery without creating an engine` |
| `/{engine}` | GET | Both endpoint-matrix tests | create/read workflow test |
| `/{engine}` | PUT | Both endpoint-matrix tests | `updates settings while preserving prior run definitions` |
| `/{engine}` | DELETE | Both endpoint-matrix tests | deletion lifecycle tests |
| `/runs` | POST | Both endpoint-matrix tests | build start workflow test; `starts automatic discovery from model scope and targets with four bounded lanes` |
| `/runs/{run}` | GET | Both endpoint-matrix tests | build start/read workflow test; automatic discovery request → DB → Inertia read test |
| `/runs/{run}/cancel` | POST | Both endpoint-matrix tests | cancellation workflow test |
| `/runs/{run}/candidates/{candidate}/apply` | POST | Both endpoint-matrix tests | adoption and cross-run rejection tests; `creates an engine from an evaluated candidate while discovery continues`; failed/cancelled and name validation tests |

Matrix tests: `blocks guests on every engine endpoint` and
`blocks ordinary users on every engine endpoint`, each expanded across ten routes.

## Frontend checklist

- Index creates definitions and links to discovery without starting a build.
- Settings emit local updates without mutating incoming props.
- Scope controls alter the outgoing request; hidden game IDs are cleared.
- Models without test seasons cannot start evaluation in the page.
- Deletion requires an explicit confirmation action.
- Active-run polling prevents overlapping refreshes and cleans up on unmount.
- Terminal runs stop refreshes; failed runs show diagnostics and cannot be adopted.
- Candidate detail shows predicted and actual totals and supports explicit adoption.
- Discovery contains no manual search axes; saved engine settings remain accessible in Build.
- Stale search state cannot constrain submitted discovery; switching modes preserves saved settings.
- Automatic progress shows refinement stage and explains increasing work totals and search limits.
- Discovered score gaps retain persisted precision when editing an adopted engine.
- Both tables offer a direct name prompt; it uses the clicked row even if a different candidate is selected below.
- Modal focuses the name field, cancellation restores focus and sends no request, polling preserves the chosen candidate.
- Pending candidates and failed/cancelled runs cannot be adopted; active evaluated candidates can.
- A 38% result against a 40% target shows a two-percentage-point shortfall and remains selectable.
- Below-target alternatives refresh with candidate results and have independent pagination.
- Starting/ending dates share a row; counted scopes show selection immediately after Count.
- Switching to season scope normalizes irrelevant selection state before submission.

## Test counts and human checks

- `tests/Feature/NhlSatEnginesTest.php`: 69 `it(...)` declarations.
- `resources/js/pages/Admin/SatEngines/engine-ui.test.js`: 43 `it(...)` declarations.
- Existing prediction API tests are unchanged; run them to guard API compatibility.

Suggested human-run commands (not run by Codex):

```sh
php artisan migrate
php artisan test tests/Feature/NhlSatEnginesTest.php tests/Feature/NhlGamePredictionsMarketProbabilityApiTest.php tests/Feature/NhlSatPredictionServicesTest.php
npm test -- resources/js/pages/Admin/SatEngines/engine-ui.test.js
```

Use the normal deployment asset build and queue-worker lifecycle. Start with a
single known historical game using Build and one saved weight pair, compare against
the existing prediction payload, then try automatic Discovery. Inspect excluded-game reasons and
totals before interpreting candidate rankings. A completed discovery is an
in-sample comparison, not independent validation of future accuracy. No historical
evaluation, migration, test, queue restart or model rebuild has been executed here.

Awaiting human review.
