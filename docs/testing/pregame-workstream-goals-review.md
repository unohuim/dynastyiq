# Pregame area goals: review and verification

Status: authored for human review. Tests, builds, migrations, queues and production
operations have **not** been run by Codex. Source review is not runtime validation.

## Files changed or created

- `app/Services/NhlPregameWorkstreams.php`
- `app/Jobs/CoordinateNhlPregameWorkstreamsJob.php`
- `app/Jobs/WorkNhlPregameWorkstreamTaskJob.php`
- `app/Services/NhlPregameContextBuilder.php`
- `app/Services/NhlPregameContextOrchestrator.php`
- `app/Services/NhlPregameLeagueResults.php`
- `app/Services/NhlSatPregameWorkspace.php`
- `app/Http/Controllers/Admin/NhlSatWorkspaceController.php`
- `app/Providers/AppServiceProvider.php`
- `routes/web.php`
- `routes/console.php`
- `database/migrations/2026_11_26_000001_create_nhl_pregame_workstream_goals.php`
- `database/migrations/2026_11_26_000002_create_nhl_pregame_workstream_tasks.php`
- `resources/js/pages/Admin/SatModels/Index.vue`
- `resources/js/pages/Admin/SatModels/Index.test.js`
- `tests/Feature/NhlPregameWorkstreamGoalsTest.php`
- `tests/Feature/NhlSatWorkspaceTest.php`
- `docs/architecture/stats/NhlPregameWorkstreamGoals.yaml`
- `docs/architecture/stats/NhlPregameContextEvidence.yaml`
- `docs/architecture/admin/NhlSatWorkspace.yaml`
- `docs/ARCHITECTURE_INVENTORY.md`
- `docs/DB_SCHEMA.md`
- `docs/ENUMS.md`
- `docs/UI_DESIGN.md`
- `docs/testing/pregame-workstream-goals-review.md`

## Minimum coverage matrix

COVERED means an assertion was authored; it does not mean a test was executed.

| Category | Authored coverage | Status |
| --- | --- | --- |
| A: request validation | Guest/member denial, missing/fractional/negative/excess limits, unknown repair area, unavailable pilot | COVERED |
| B: normalization/defaults | Six default goals, integer limits including zero, exact historical pilot selection | COVERED |
| C: persistence | HTTP allocations stored and reread; unique tasks; actual owned-field writes; source generations; retained checkpoints | COVERED |
| D: response contract | Six areas, assigned counts, worker identities, missing-source errors, terminal status | COVERED |
| E: HTTP read | Allocation action → DB assertion → authorized partial Inertia GET; repair action → canonical writes → GET area status | COVERED |
| F: async work | Fake dispatch assertions, independent admission, dependency gates, expired leases, bounded retries, Pause/Stop and legacy isolation | COVERED |
| G: frontend | Six row controls, allocation payload, several workers per area, idle/waiting/blocked states, investigation repair, migration/legacy messaging | COVERED |

The small action-to-completion test uses real builder writes and coordinator task
transitions but mocks the league/comparison page service. It tests orchestration,
not league arithmetic. A separate split-versus-legacy fixture uses the real
builder, prior shot facts and strength summaries to compare JSON metrics,
opponent history and schedule values. Existing league/comparison tests continue
to own their arithmetic. PostgreSQL lock concurrency, multi-server delivery and
visual browser rendering remain unexecuted runtime checks.

## Requirements checklist

1. SATISFIED in authored code: six independently allocated areas, stated goals,
   remaining work and current executing workers.
2. SATISFIED in authored code: live limits affect subsequent claims; zero pauses
   new claims; already-claimed bounded pages may finish.
3. SATISFIED in authored code: dependencies keep player/goalie/travel linked to
   team identity and rankings linked to completed metrics and league references.
4. SATISFIED in authored code: actual prior facts, strength/window samples and
   existing statistical formulas are retained; missing prior evidence blocks
   completion rather than silently reporting healthy context.
5. SATISFIED in authored code: explicit per-area investigation and repair preserve
   completed pages. Source-preflight exclusions require a fresh assessment.
6. SATISFIED in authored code: fenced commits, bounded lease recovery, global
   Pause/Stop, inventory errors and failed-cleanup retries remain visible.
7. SATISFIED in authored code: enrolled pilot source changes invalidate dependent
   historical cutoffs; model scope/version changes are reassessed. Season-level
   source events conservatively mark later season cutoffs for rebuilding.
8. SATISFIED in authored code: unique Sep2026 pilot only; unchanged production
   model paths; active legacy builds and their committed checkpoints are retained.
9. SATISFIED in authored code: two empty operational migrations, canonical YAML,
   descriptive schema, enum and UI documentation. No statistical rows are copied
   by the migrations.
10. SATISFIED: no tests/CI/builds/migrations/imports/queues/production operations
    executed. Human verification remains required.

## Endpoint × verb authorization and scope

These routes are super-admin operations, not organization- or owner-scoped data.
The uniquely configured historical pilot is the applicable scope boundary.

| Endpoint and verb | Guest / member blocked test | Authorized success test | Model scope test |
| --- | --- | --- | --- |
| GET `/admin/nhl-sat-models` | `blocks guests from every workspace endpoint`; `blocks ordinary members from every workspace endpoint` | `persists six area allocations and exposes them through authorized HTTP`; `can finish a small goal run and expose the resulting area completion through HTTP` | `keeps the selected pilot independent of the newest production model`; `does not enrol or dispatch workers when merely reading the workspace` |
| PUT `/admin/nhl-sat-models/pregame/goals` | `blocks unauthenticated area allocations`; `blocks ordinary members from area allocation` | `persists six area allocations and exposes them through authorized HTTP` | `never substitutes a production model for an absent historical pilot` |
| POST `/admin/nhl-sat-models/pregame/repair` | Existing two `every workspace endpoint` tests above | `admits missing Train and Test repair through HTTP and reads its scoped run`; new task seed/completion tests | `repairs the historical pilot even when a caller supplies a production model identity`; `refuses repair when the historical pilot is ambiguous` |
| PUT `/admin/nhl-sat-models/pregame/workers` (legacy retained) | Existing two `every workspace endpoint` tests above | `persists pilot limits through HTTP and exposes them through workspace reads` | `preserves production model state when pilot allocation is changed` |
| GET `/admin/nhl-sat-models/pregame/inspect` (unchanged) | Existing two `every workspace endpoint` tests above | `returns only bounded current-version pilot metric rows` | `returns empty inspector evidence instead of borrowing production snapshots` |

Organization membership and row-owner authorization are not applicable to these
global super-admin pilot routes. No public or partner API interface changes.

## Frontend component checklist

- COVERED: six concern rows and explicit worker-limit fields.
- COVERED: inline allocation and six-field drawer send the complete limit map.
- COVERED: multiple workers appear inside their own area; queued claims do not
  become executing-worker evidence.
- COVERED: completed goals show Idle and no active bar; waiting explains prerequisites.
- COVERED: blocker investigation shows specific cause and sends the selected area.
- COVERED: unavailable migrations disable new repair and show a clear explanation.
- COVERED: active legacy maintenance remains visible separately.
- COVERED: existing polling tests retain content/scroll and clean up timers;
  fake clocks, mocked network, scoped component state and teardown are retained.

## Per-file test counts

Source declarations counted; dataset-expanded cases are not added to these totals.

| File | `it(...)` declarations | Execution |
| --- | ---: | --- |
| `tests/Feature/NhlPregameWorkstreamGoalsTest.php` | 41 | Not run |
| `tests/Feature/NhlSatWorkspaceTest.php` | 59 | Not run |
| `resources/js/pages/Admin/SatModels/Index.test.js` | 83 | Not run |

## Human verification commands

Run the focused automated checks in the project's configured testing environment:

```sh
php artisan test tests/Feature/NhlPregameWorkstreamGoalsTest.php tests/Feature/NhlSatWorkspaceTest.php tests/Feature/NhlPregameComparisonPagesTest.php tests/Feature/NhlPregameStrengthMetricsTest.php
npm run test -- --run resources/js/pages/Admin/SatModels/Index.test.js
npm run build
```

**Migration required** before using area controls:

- `2026_11_26_000001_create_nhl_pregame_workstream_goals.php`
- `2026_11_26_000002_create_nhl_pregame_workstream_tasks.php`

```sh
php artisan migrate
```

The migrations create empty tables; first explicit Repair or allocation enrols
only the historical pilot. New task jobs use `projections`; allocation is a task
capacity limit, not OS-worker provisioning. No server jobs were moved or started
as part of this code change. Existing active legacy maintenance is not converted
mid-run. Stopped maintenance stays stopped until explicitly restarted.
