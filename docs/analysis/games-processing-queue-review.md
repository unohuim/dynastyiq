# Dedicated NHL game processing queue

## Files changed

- `config/horizon.php`
- `app/Jobs/BaseNhlJob.php`
- `app/Jobs/NhlDiscoveryJob.php`
- `app/Jobs/NhlDiscoverDayJob.php`
- `app/Jobs/NhlOrchestratorJob.php`
- `app/Jobs/BuildNhlShotAttemptFactsJob.php`
- `app/Jobs/BuildNhlFaceoffFactsJob.php`
- `app/Jobs/RefreshNhlGameContextJob.php`
- `app/Jobs/RebuildNhlOnIceStatsJob.php`
- `app/Jobs/RebuildNhlGameImportJob.php`
- `app/Jobs/ClearNhlGameImportJob.php`
- `app/Jobs/PreflightNhlGameImportRebuildJob.php`
- `app/Jobs/SeedNhlGameImportProgressJob.php`
- `app/Jobs/DispatchNhlGameImportPipelineJob.php`
- `app/Jobs/RefreshNhlGoalieDecisionJob.php`
- `app/Jobs/RefreshNhlSpecialTeamsSplitsJob.php`
- `app/Jobs/ScanDuplicateNhlPlayByPlayRepairJob.php`
- `app/Jobs/DedupeNhlPlayByPlayRepairJob.php`
- `app/Jobs/QueueDuplicatePbpAffectedRebuildsJob.php`
- `app/Jobs/RefreshNhlScheduleDateJob.php`
- `app/Jobs/RefreshNhlPublicGamePageJob.php`
- `app/Listeners/StartDiscoveredGameProcessing.php`
- `app/Http/Controllers/Admin/NhlGameImportController.php`
- `app/Services/NhlImportOrchestrator.php`
- `tests/Unit/NhlImportPipelineFoundationTest.php`
- `tests/Feature/NhlGameImportRunControlTest.php`
- `tests/Feature/NhlPublicGamePageTest.php`
- `tests/Feature/AdminControlPanelTriageTest.php`
- `docs/ENUMS.md`
- `docs/architecture/application/LaravelApplicationShell.yaml`
- `docs/architecture/imports/NhlDiscoveryPipeline.yaml`
- `docs/architecture/imports/NhlImportOrchestrator.yaml`
- This review document.

New game-processing work uses the dedicated queue and ten Horizon workers per
instance. Explicit new batch options retain that routing rather than overriding
constructor choices. Prediction-required Matchup preparation uses projections;
ordinary public-page refreshes remain on default. Existing live queues,
prediction calculations, season rollups and import admission/pause/cancel rules
are unchanged. No migration is required.

## Minimum coverage matrix

COVERED means authored assertions, not executed tests.

| Contract | Status | Test |
| --- | --- | --- |
| All canonical import stages and serialized identities | COVERED | routes every canonical game stage to games-processing and retains routing when serialized |
| Discovery, facts, repairs and backfills | COVERED | routes new discovery repair and fact jobs to games-processing (17 dataset cases) |
| Schedule refresh and readiness listener | COVERED | isolates scheduled game refresh and discovery readiness from the default queue |
| Worker allocation, environment registration and timeout envelope | COVERED | allocates ten dedicated game-processing workers in every Horizon environment |
| Live/season routing boundaries | COVERED | preserves live queues and the projections queue for season rollups |
| Legacy serialized queue preserved | COVERED | retains the original queue of already serialized game rebuild work |
| Actual admin dispatch → tracked run → status read | COVERED | queues only derived on-ice jobs and preserves the original discovery; allows super admins to queue shot fact processing for a game import run range |
| Scoped shots/faceoffs and Full batch routing | COVERED | allows super admins to queue shot fact processing for a game import run range; queues faceoff fact jobs for games in a discovered run without starting full imports; excludes overlap-owned games from full post-processing without dropping their completed stage history |
| Prediction dependency dispatch and serialization | COVERED | coalesces source-bound expected-stat work separately from public refreshes |
| Ordinary public refresh remains default | COVERED | queues public page preparation after a canonical prediction |

## Requirements checklist

1. SATISFIED: ten dedicated workers consume games-processing in every environment.
2. SATISFIED: new game imports, PBP, scoped rebuilds and repair jobs select it.
3. SATISFIED: explicit new batches preserve the queue choice.
4. SATISFIED: prediction-required Matchup preparation uses projections.
5. SATISFIED: live processing and season sync retain their existing queues.
6. SATISFIED: existing serialized jobs/batches are not relocated or cancelled.
7. SATISFIED: no schema, dependencies, prediction math or authorization changes.

## Endpoint × verb authorization/scope checklist

Routes and permissions are unchanged. Existing tests retain these boundaries:

| Surface | Guest | Non-admin | Authorized / scope |
| --- | --- | --- | --- |
| POST discovery | blocks guests from queuing NHL game discovery | blocks authenticated non-admin users from queuing NHL game discovery | allows super admins to queue NHL game discovery for a single date |
| POST Shots | blocks guests from queuing NHL shot fact processing | blocks authenticated non-admin users from queuing NHL shot fact processing | allows super admins to queue shot fact processing for a game import run range (request, tracked DB run, queued jobs and subsequent status GET) |
| POST On-Ice | denies guests the on-ice processing endpoint | denies non-admins the on-ice processing endpoint | queues only derived on-ice jobs and preserves the original discovery; limits queued games to the selected discovery range |
| POST Pause/Resume/Cancel | denies guests access to all run controls | denies non-admin users access to all run controls | allows an admin to pause and reads the paused status through the existing status endpoint; allows an admin to resume unfinished discovery and dispatches a new coordinator; allows an admin to cancel pending work and reads the durable status |

No new organization-scoped endpoint or stored public field is introduced.

## Frontend component contract checklist

Not applicable: no frontend files or browser behavior changed.

## Per-file test count

| File | `it(...)` declarations |
| --- | ---: |
| tests/Unit/NhlImportPipelineFoundationTest.php | 70 |
| tests/Feature/NhlGameImportRunControlTest.php | 60 |
| tests/Feature/NhlPublicGamePageTest.php | 113 |
| tests/Feature/AdminControlPanelTriageTest.php | 235 |

Tests use existing fake dispatch/source boundaries and deterministic dates.
No live worker, provider request or import is run by the routing assertions.

## Verification and rollout

The diff was inspected and `git diff --check` passed. Tests and CI were not run.
Suggested human command:

`php artisan test tests/Unit/NhlImportPipelineFoundationTest.php tests/Feature/NhlGameImportRunControlTest.php tests/Feature/NhlPublicGamePageTest.php tests/Feature/AdminControlPanelTriageTest.php`

Deployment must refresh cached configuration and reload Horizon through the
existing deployment process for the new supervisor and routing to take effect.
Already queued default jobs, including today's prediction dependencies, retain
their serialized queue. They continue draining on default. Recovery or movement
of that existing production work requires its own approved operational plan.
No production writes, queue moves, worker restarts or deployments were performed.
