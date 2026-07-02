---
pr_id: 6
pr_name: pr6
status: Archived
created: 2026-06-29
last_updated: 2026-06-29
---

# PBP Pipeline Foundation

## Source

Play-by-play import architecture review and follow-up planning.

## Objective

Refactor the NHL play-by-play import foundation toward a best-practice staged Laravel pipeline while preserving existing abstractions that already match the desired architecture.

## Ideal Target

If implemented from scratch, the NHL game import pipeline would have one canonical stage graph, one atomic progress repository, queue jobs that only execute claimed work, stage services that own transformations only, and database constraints that enforce the natural identity of imported records.

The pipeline should be predictable under retries, backfills, concurrent workers, and partial failures.

## Existing Abstractions To Preserve

- `NhlImportOrchestrator` as the owner of stage order and advancement.
- `NhlImportProgressRepo` as the only persistence boundary for `nhl_import_progress`.
- `BaseNhlJob` and stage-specific jobs as queue wrappers.
- Existing stage service boundaries for PBP, summaries, shifts, boxscores, shift units, event connections, and unit game summaries.
- `nhl_import_progress` as the per-game stage state table.

## Existing Abstractions To Improve

- Replace duplicated stage arrays with one canonical stage definition used by discovery, orchestration, jobs, docs, and tests.
- Make `NhlImportProgressRepo::claim()` atomically transition `scheduled` to `running`.
- Remove or quarantine legacy/manual controller routes that bypass the orchestrator.
- Align stale-stage configuration with the actual config file.
- Document and enforce natural database identities used by import upserts.

## Scope

1. Audit current stage definitions across discovery, orchestrator, jobs, docs, and enums.
2. Introduce a single canonical stage definition without changing public route names unless explicitly approved.
3. Update import claiming semantics so dispatch and execution cannot race through the `scheduled` state.
4. Align stale sweep config lookups with `config/apiImportNhl.php`.
5. Identify required uniqueness guarantees for PBP events, shifts, unit shifts, event links, and summaries.
6. Remove, disable, or move debug-only PBP controller behavior behind an approved admin-safe path.
7. Add focused tests for claim behavior, stage readiness, stale sweeping, and duplicate-dispatch prevention.
8. Update canonical architecture docs affected by the durable pipeline rules.
9. Update `docs/ARCHITECTURE_INVENTORY.md` only as a derived summary after canonical docs are updated.

## Non-Goals

- Rewriting stat calculations.
- Adding boxscore validation or approval workflow.
- Redesigning unit/on-ice stat aggregation.
- Running imports, migrations, queues, schedulers, or CI.

## Acceptance Criteria

- Stage order and dependencies are defined in one authoritative code path.
- Discovery and orchestration use the same stage definition.
- Claiming a scheduled stage changes its persisted state atomically.
- Jobs cannot run unclaimed scheduled work.
- Stale job thresholds read the correct config keys.
- Legacy/manual PBP paths no longer bypass the canonical import contract.
- Tests cover the progress and orchestration behavior changed in this PR.

## Review Notes

This PR should prioritize reliability and architecture normalization before changing stat semantics. It is the foundation for later validation and on-ice stat work.

## Implementation Notes

- Added `NhlImportStages` as the canonical stage metadata contract for order, dependencies, job mappings, and stale timeout config keys.
- Refactored discovery and orchestration to read stage metadata from `NhlImportStages`.
- Updated progress claiming so scheduled rows are atomically transitioned to running before dispatch.
- Updated NHL jobs to verify already-running work instead of trying to claim inside the job.
- Replaced legacy/debug PBP controller behavior with safe responses that point operators to `nhl:discover` and `nhl:process`.
- Added focused Pest coverage for stage metadata, claim behavior, orchestration dispatch, stale sweeping, and discovery seeding.
- Updated canonical architecture docs, enum notes, and derived architecture inventory.
