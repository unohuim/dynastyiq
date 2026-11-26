# SAT HQ and Sep2026 Pregame workspace review

Implementation awaits human review. Source inspection and `git diff --check` were
performed. Tests, CI, lint, asset builds, browser/Playwright verification, imports,
queue/scheduler commands and production operations were not run. Coverage below
means authored assertions, not observed passing results.

## Exact files changed or created

- `app/Http/Controllers/Admin/NhlModelRunController.php`
- `app/Http/Controllers/Admin/NhlPregameContextController.php`
- `app/Http/Controllers/Admin/NhlSatWorkspaceController.php`
- `app/Services/NhlPregameContextOrchestrator.php`
- `app/Services/NhlSatModelPresentation.php`
- `app/Services/NhlSatPregameWorkspace.php`
- `config/nhl-pregame.php`
- `docs/ARCHITECTURE_INVENTORY.md`
- `docs/ENUMS.md`
- `docs/UI_DESIGN.md`
- `docs/architecture/admin/NhlSatWorkspace.yaml`
- `docs/architecture/application/ScheduledProcessManager.yaml`
- `docs/architecture/stats/NhlModelRuns.yaml`
- `docs/architecture/stats/NhlPregameContextEvidence.yaml`
- `docs/architecture/stats/NhlSatModelEntityToiProjection.yaml`
- `docs/testing/sat-hq-pregame-workspace-review.md`
- `resources/js/pages/Admin/SatModels/Index.test.js`
- `public/images/sat-models-rink.png` — reference artwork extracted from the supplied PDF
- `resources/js/pages/Admin/SatModels/Index.vue`
- `resources/views/admin/nhl-sat-models/index.blade.php` — deleted obsolete landing template
- `routes/web.php`
- `tests/Feature/AdminControlPanelTriageTest.php`
- `tests/Feature/NhlBuildPredictionsTest.php`
- `tests/Feature/NhlModelPregameBuildTest.php`
- `tests/Feature/NhlSatWorkspaceTest.php`
- `tests/Feature/NhlStrengthProbabilityTrainingTest.php`

## Minimum coverage matrix

| Category | Authored coverage | Evidence |
| --- | --- | --- |
| A — validation | COVERED | Pilot identity ambiguity/missing source; allocation integer/range/sum validation; inspector game/entity validation |
| B — normalization/defaults | COVERED | Pilot name normalization, nonpilot default limits, structured Vue acknowledgement, missing evidence markers |
| C — persistence | COVERED | HTTP allocation saves model configuration and active run opt-in; production raw state unchanged |
| D — response contract | COVERED | Explicit Inertia model/action props; pilot-only progress/coverage; inspector shape; no claim-token exposure |
| E — HTTP read after action | COVERED | Allocation PUT → model/run assertions → partial Inertia GET; model POST → DB assertion → registry GET |
| F — jobs | COVERED | Future pilot builds retain limits and projections routing; production builds do not opt in; reads never queue work |
| G — component | COVERED | Peer tabs, local search, progress polling/unmount, disclosures, drawers, confirmations, toasts, missing source, safe errors and form preservation |

## Requirements checklist

1. SATISFIED in source/coverage: `/admin/nhl-sat-models` is Vue/Inertia-owned using the existing AppLayout, headers and navbars.
2. SATISFIED in source/coverage: obsolete Blade landing template removed; Vue action requests avoid HTML fragments. Existing unmigrated report pages and their fragment contracts remain compatible.
3. SATISFIED in source/coverage: compact D04-inspired workstreams and mobile arrangement, D02 allocation clarity, M01 drill-down interactions.
4. SATISFIED in source/coverage: Models retains model creation, filtering, profiles, evaluations, projections, comparisons, season games and context controls with existing admission authority.
5. SATISFIED in source/coverage: Pregame is a peer health view using exactly one Sep2026 Test-20252026 pilot. Missing/ambiguous source cannot fall back to production.
6. SATISFIED in source/coverage: health, statistical cutoff, historical team coverage and processing activity retain separate meanings; shared claims are labelled honestly.
7. SATISFIED in source/coverage: allocation changes affect only pilot claim concurrency; production context ownership, defaults, predictions and equations are unchanged.
8. SATISFIED in source/coverage: existing update/rebuild/retry/pause/resume/stop/automation pathways remain authoritative; no work starts merely by visiting the page.
9. SATISFIED in source/coverage: quiet updates preserve content, scroll and local forms; no background notification banners or overlays.
10. SATISFIED in source/coverage: bounded saved-metric inspection and recent maintenance history; unknown values remain unavailable.
11. SATISFIED in documentation: canonical workspace rules, enums, UI and inventory updated. No migration or dependency change.

## Endpoint × verb authorization/scope matrix

This workspace remains global super-admin administration; organization/member
ownership is not applicable. Existing authentication, verified-user and super-admin
middleware are unchanged. New routes are under the same protected route group.

| Endpoint / verb | Guest | Ordinary member | Authorized super admin | Model scope |
| --- | --- | --- | --- | --- |
| GET /admin/nhl-sat-models | blocks guests from every workspace endpoint | blocks ordinary members from every workspace endpoint | serves the shared Vue shell and structured model actions to super admins | keeps the selected pilot independent of the newest production model |
| PUT /admin/nhl-sat-models/pregame/workers | blocks guests from every workspace endpoint | blocks ordinary members from every workspace endpoint | persists pilot limits through HTTP and exposes them through workspace reads | preserves production model state when pilot allocation is changed; ignores a supplied production identity on pilot allocation requests |
| GET /admin/nhl-sat-models/pregame/inspect | blocks guests from every workspace endpoint | blocks ordinary members from every workspace endpoint | returns only bounded current-version pilot metric rows | returns empty inspector evidence instead of borrowing production snapshots |

The guest/member tests use datasets containing each endpoint/verb above. No
partner API or authorization policy changes. Existing model action routes retain
their existing feature coverage in AdminControlPanelTriageTest,
NhlBuildPredictionsTest, NhlModelPregameBuildTest and
NhlStrengthProbabilityTrainingTest. The new tests `returns structured model creation
acknowledgement for Vue` and `returns structured pregame action progress for Vue
without rendering fragments` cover the opt-in presentation contract while legacy
row/progress assertions remain in the existing action suites.

## Frontend component checklist

- COVERED: compact workspace and peer-tab URL state without duplicating shared navigation.
- COVERED: model search/status/actions, prerequisites and broad-action confirmation.
- COVERED: semantic missing-source/loading/idle states and explicit historical scope.
- COVERED: accessible caret/disclosure state with retained detail DOM.
- COVERED: automation form/start time, switch toast and two actual allocation pools.
- COVERED: silent timed reload, visibility guard, unmount cleanup and local form preservation.
- COVERED: literal error rendering rather than executable markup.
- COVERED: scoped inspector entry without starting jobs.
- Tests use fake clocks/timers, mocked HTTP/router and local fixtures. No network or workers.
- Actual responsive rendering and visual comparison have not been executed.

## Per-file test count

| File | it(...) declarations | Execution |
| --- | ---: | --- |
| tests/Feature/NhlSatWorkspaceTest.php | 31 | Not run |
| resources/js/pages/Admin/SatModels/Index.test.js | 40 | Not run |
| tests/Feature/NhlModelPregameBuildTest.php | 107 | Not run |
| tests/Feature/NhlBuildPredictionsTest.php | 111 | Not run |
| tests/Feature/NhlStrengthProbabilityTrainingTest.php | 52 | Not run |
| tests/Feature/AdminControlPanelTriageTest.php | 235 | Not run |

Suggested human verification:

```bash
php artisan test tests/Feature/NhlSatWorkspaceTest.php tests/Feature/NhlModelPregameBuildTest.php tests/Feature/NhlBuildPredictionsTest.php tests/Feature/NhlStrengthProbabilityTrainingTest.php tests/Feature/AdminControlPanelTriageTest.php
npm run test -- --run resources/js/pages/Admin/SatModels/Index.test.js
npm run build
```

## Operational boundaries and limitations

No migration is required. This first shared workspace reads Sep2026's existing
model-owned storage without copying records or switching production readers.
Its explicit source match requires the configured normalized model name and Test
20252026; absent or ambiguous matches disable pilot controls.

Worker allocation means simultaneous date claims, not provisioning actual Horizon
processes. Existing workers and queues remain unchanged. Separate player, goalie,
ranking and travel pools are not invented; these domain views share context work.
Lowering limits lets live claims finish and affects the next admissions.

Historical coverage verifies both team snapshots for completed regular-season
games. It does not certify every player or normalized metric, and does not claim
that today's production games are ready. Historical counts cache for 60 seconds
with an explicit checked timestamp; run/claim activity is read separately.

Targeted repair initially exposes the existing missing-Test update and unfinished
retry controls, plus confirmed full Train+Test rebuild. Arbitrary per-metric repair
and a model-independent storage migration require separate work. The inspector
returns at most 50 contexts and 200 metric rows and labels truncation. No existing
context is rebuilt by this code change or by visiting the page.

The new landing page never loads Alpine/Livewire. Legacy detail reports and old
response/broadcast fragments remain for their existing consumers; those pages were
not part of this landing-page migration. Prediction formulas, configuration
identity, cached forecasts and partner API contracts are unchanged.


## Reference fidelity correction

The later instruction to copy PG-D04 explicitly replaces the simplified visual
interpretation. The correction changes the existing Index.vue and its component
suite, adds `public/images/sat-models-rink.png` extracted from the supplied PDF,
and adds real registry summary/date-strip presentation to NhlModelRunController
and NhlSatPregameWorkspace. Canonical UI/workspace documentation is updated.

The heading now follows SAT Models Mission Control in white/gold italic type,
with the exact reference rink illustration, five summary cards and eight icon tabs.
Models remains first/default; Pregame remains one tab. Research has no existing
workspace and is disabled rather than implying a working research system.
Existing routes supply the other linked tools. A compact health/table/control
arrangement and three-panel footer replace the earlier simplified layout.

New assertions cover title treatment, five cards without manufactured sample
metrics, reference tab order, actual artwork URL, registry counts and actual
completed Test dates. Header research/production counts are explicitly with/without
Test season; unavailable correlation/lift remain null. No model algorithm,
production context, worker behavior or prediction pathway is changed by this
visual correction. No database migration is needed.

Checks actually performed: source/reference inspection, artwork inspection and
`git diff --check`. Tests, build, browser rendering and Playwright verification
were not run. No pixel-perfect runtime claim is made without browser verification.


## Health issue navigation correction

Files changed for this correction:

- `resources/js/pages/Admin/SatModels/Index.vue`
- `resources/js/pages/Admin/SatModels/Index.test.js`
- `docs/UI_DESIGN.md`
- `docs/testing/sat-hq-pregame-workspace-review.md`

The Overall Health badge, Outstanding count and visible View issues button open
the existing full issues/repair panel. Native buttons expose dialog ownership,
expanded state and visible keyboard focus. The overview remains mounted. Viewing
issues remains available without a pilot, while actual maintenance buttons retain
their eligibility checks. No endpoint, queue operation, database or calculation
behaviour changes. No migration is required.

Coverage matrix for this correction: G COVERED by six additional deterministic
component cases; A–F are unchanged, with no new endpoint or persistence contract.
The source uses the existing accessible ReKa dialog for focus/escape handling.

Requirements: (1) SATISFIED — badge opens all issues, (2) SATISFIED — count opens
same panel, (3) SATISFIED — explicit View issues affordance, (4) SATISFIED — no
processing or HTTP request on open, (5) SATISFIED — unavailable source inspection
with disabled repairs, (6) SATISFIED — empty state and close-state reconciliation.

New assertions:

- opens all recorded issues from the Overall Health badge
- opens the same issue panel from the Outstanding count without processing work
- provides a visible native-button View issues affordance with dialog state
- allows issue inspection when the pilot is unavailable while repair remains disabled
- shows an explicit empty issue state when current health has no tracked problems
- restores closed issue-trigger state without removing the health summary

The preexisting pilot-caption assertion is aligned with the visible reference
header introduced by the preceding design correction. The component suite now
contains 40 it(...) declarations. Backend authorization/scope is unchanged; the
endpoint matrix above still applies. Source review and `git diff --check` were
performed; tests, CI, builds, browser checks and production operations were not run.
Human command: `npm run test -- --run resources/js/pages/Admin/SatModels/Index.test.js`.
