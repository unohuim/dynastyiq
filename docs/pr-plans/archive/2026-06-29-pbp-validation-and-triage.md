---
pr_id: 7
pr_name: pr7
status: Archived
created: 2026-06-29
last_updated: 2026-06-29
---

# PBP Validation And Triage

## Source

Play-by-play import review and discussion about comparing PBP-derived summaries against NHL boxscores.

## Objective

Add a best-practice validation gate that compares computed player game summaries to official boxscore totals, auto-approves exact comparable matches, and persists triage data for mismatches.

## Ideal Target

If implemented from scratch, PBP-derived stats would not become trusted merely because an import job succeeded. A validation stage would compare computed totals against official provider totals, persist the validation result, and expose actionable deltas for admin review.

The validation layer should be auditable, rerunnable, and separate from both import and presentation.

## Existing Abstractions To Preserve

- `CompareNhlPbPBoxscore` as an initial comparison concept, upgraded into a formal validation service.
- `nhl_boxscores` as the official per-game provider total table.
- `nhl_game_summaries` as the computed per-player per-game summary table.
- `NhlImportOrchestrator` and stage jobs as the pipeline execution path.
- Existing admin UI patterns for triage-style JSON and Tailwind-driven pages where applicable.

## Existing Abstractions To Improve

- Convert comparison from an ephemeral array result into persisted validation state.
- Separate comparable exact fields from tolerated, derived, unsupported, or intentionally ignored fields.
- Make validation an explicit stage or post-stage gate instead of a controller/debug helper.
- Store per-player, per-field deltas for triage.
- Add approval state that downstream stats can trust.

## Scope

1. Define the summary-vs-boxscore validation contract and comparable field map.
2. Add persisted validation state for each game validation run.
3. Add persisted validation deltas by player and field.
4. Add an explicit pipeline stage or approved post-stage hook after both `summary` and `boxscore` are available.
5. Auto-approve validations when all exact comparable fields match.
6. Mark validations failed when deltas exist.
7. Add admin triage JSON and UI surfaces for failed games, player deltas, and field-level detail.
8. Provide actions for rerun summary, rerun boxscore, accept exception, or leave unresolved.
9. Add tests for exact approval, mismatch persistence, ignored/tolerated fields, and authorization.
10. Update canonical architecture docs and enum documentation for new validation statuses and stage names.

## Non-Goals

- Rebuilding the entire PBP importer.
- Changing unit/on-ice aggregation.
- Making every NHL API field comparable if the source semantics differ.
- Bulk triage unless explicitly approved.
- Running imports, migrations, queues, schedulers, or CI.

## Comparable Field Guidance

Exact comparison candidates:

- Goals.
- Assists.
- Points.
- Penalty minutes.
- Shots on goal.
- Hits.
- Blocks.
- Giveaways.
- Takeaways.
- Faceoffs won and lost.
- Power-play goals.
- Goalie saves, goals against, and shots against.
- Goalie EV/PP/PK save and shot-against splits where provider semantics are confirmed.

Derived or tolerance-based candidates:

- Faceoff percentage.
- Time on ice.
- Percentages derived from totals.

Fields requiring explicit rules before comparison:

- Plus/minus.
- Short-handed assist mappings.
- Shootout and penalty-shot stats.
- Split shutouts and goalie edge cases.

## Acceptance Criteria

- Validation results are persisted and rerunnable.
- Exact comparable matches auto-approve the game validation.
- Mismatches produce durable player and field deltas.
- Admin triage can show failed games and mismatch details without recalculating the entire import.
- Downstream code can distinguish approved, failed, accepted-exception, and unresolved validations.

## Review Notes

This PR should make trust explicit. A successful import is not the same thing as a validated stat line.

## Implementation Notes

- Added `validate-summary` as the canonical stage after `boxscore` and before `shift-units`.
- Added persisted `nhl_game_validations` and `nhl_game_validation_deltas` state for summary-boxscore validation.
- Converted `CompareNhlPbPBoxscore` into a normalized delta producer and added `ValidateNhlGameSummary`.
- Added `ValidateNhlGameSummaryJob` so failed deltas persist before the pipeline stage is marked failed.
- Moved season rollup dispatch to wait for completed validation stages instead of completed boxscores.
- Added super-admin triage routes and Tailwind Blade views for listing, inspecting, rerunning, and accepting validations.
- Added Pest coverage for exact approval, mismatch persistence, tolerated fields, stage gating, and admin authorization/actions.
