---
pr_id: 8
pr_name: pr8
status: Archived
created: 2026-06-29
last_updated: 2026-07-01
---

# On-Ice Strength Stats Refactor

## Source

Play-by-play import review and follow-up discussion about using shifts, units, and event links to derive plus/minus, IPP, per-game, per-60, and strength-aware stats.

## Objective

Refactor the on-ice stat layer toward a best-practice strength-aware aggregation model while leveraging existing shift, unit, unit-shift, event-link, and summary abstractions.

## Ideal Target

If implemented from scratch, raw shifts and PBP events would produce deterministic on-ice intervals, event links, and pre-aggregated game-level totals by player or unit and strength. Season and range views would sum these persisted game totals and calculate rates from totals at the presentation/API layer.

The system should never inspect raw PBP events during ordinary user stats requests.

## Existing Abstractions To Preserve

- `nhl_shifts` as raw player shift intervals.
- `nhl_units` as stable player combinations.
- `nhl_unit_shifts` as game-level time windows for units.
- `event_unit_shifts` as persisted links between PBP events and on-ice intervals.
- `ConnectEventsToUnitShifts` as the current event-linking concept.
- `SumNhlGameUnits` and `nhl_unit_game_summaries` as the current game-level unit aggregation concept.
- Stats page slice concepts: `total`, `pgp`, and `p60`.

## Existing Abstractions To Improve

- Add a stable unit composition identity, such as a composition hash, instead of scanning units by player list in PHP.
- Treat strength as a first-class aggregation dimension rather than only a wide-column suffix pattern.
- Move range aggregation to SQL `SUM(...) GROUP BY ...` over pre-aggregated game rows.
- Calculate per-game, per-60, percentages, and IPP from totals consistently in one layer.
- Clarify whether player on-ice summaries should be materialized separately from unit summaries.

## Scope

1. Audit current on-ice stat requirements for players and units.
2. Decide the approved target grain for strength-aware totals:
   - player-game-strength summaries,
   - unit-game-strength summaries,
   - or both.
3. Define allowed strength values and whether empty-net contexts remain EV or receive a separate context flag.
4. Add or evolve database structures for strength-aware totals without losing existing unit-game summary behavior.
5. Add stable unit composition identity and uniqueness rules.
6. Refactor event linking and game aggregation to produce deterministic, rerunnable totals.
7. Add SQL aggregation paths for season and arbitrary date ranges.
8. Derive `per_gp`, `per_60`, percentages, and IPP from grouped totals instead of storing every rate variant.
9. Update stats payload generation so strength filters and slices use the new aggregation layer.
10. Add fixture-driven tests for EV, PP, PK, empty-net, boundary events, and IPP calculations.
11. Update canonical architecture docs and derived inventory.

## Non-Goals

- Replacing the base PBP importer.
- Adding boxscore validation and triage.
- Removing existing stat pages before the new aggregation path is approved.
- Running imports, migrations, queues, schedulers, or CI.

## Stat Model Guidance

Persist expensive totals:

- Time on ice.
- Shifts.
- Goals for and against.
- Shots for and against.
- Shot attempts for and against.
- Fenwick for and against.
- Blocks and hits for and against.
- Zone starts.
- Faceoffs.
- Penalties and PIM.
- Individual goals, assists, and points needed for IPP.

Derive cheap rates:

- Per game.
- Per 60.
- Percentages.
- IPP.
- On-ice shooting percentage.
- On-ice save percentage.

## Acceptance Criteria

- On-ice stats can be queried by total, range, season, and strength without scanning raw PBP rows in request time.
- EV/PP/PK splits are consistent for players and units.
- Per-game and per-60 slices are derived from the same total model.
- Unit identity is stable and enforced.
- Tests cover event-to-interval boundary behavior and strength-specific aggregation.

## Review Notes

This PR is the stats-depth layer. It should build on the reliability and validation PRs rather than trying to solve all import concerns at once.

## Implementation Notes

- Added canonical strength values `EV`, `PP`, and `PK`; empty-net contexts remain `EV`.
- Added deterministic NHL unit composition identity via `composition_hash`.
- Added normalized unit-game-strength and player-game-strength summary tables.
- Added `ResolveNhlUnit` to replace player-list scanning during unit resolution.
- Added `SumNhlGameStrengthUnits` alongside the legacy `SumNhlGameUnits` output.
- Added `NhlStrengthStatsQuery` for season/range/grouped totals with derived `total`, `pgp`, and `p60` slices.
- Embedded NHL validation triage into the Admin Control Panel as an approved operational tab while preserving standalone routes.
- Renamed the admin import tab to Player Imports and added a separate Game Imports tab for the staged NHL game pipeline.
- Added an admin Game Imports workflow that queues NHL discovery and processing jobs from date selections, tracks admin run requests, and displays pipeline progress from `nhl_import_progress`.
- Moved Game Imports processing to discovery-row actions and made discovery rows show known discovered work facts instead of a processing progress bar.
- Reused the clicked discovery orchestration row for processing progress instead of creating a second visible processing row.
- Moved NHL summary validation to the terminal game pipeline stage after shift units, event connections, and game-unit aggregation.
- Raw shift imports now write both time on ice and shift counts into `nhl_game_summaries`.
- Split `nhl:empty` into explicit `--players` and `--games` modes so NHL player identities and NHL game-derived import data can be cleared independently.
- Play-by-play goal events now count as shots on goal only when NHL provides shot metadata; no-shot goals still count as goals and goalie goals against.
- Failed NHL summary validations now export per-game boxscore, play-by-play, and shift troubleshooting markdown snapshots.
- Failed validation troubleshooting now also exports standalone per-game delta markdown snapshots, and one failed section no longer blocks the remaining files.
- NHL game imports now accept only provider game types 1, 2, and 3; PBP establishes the stored game type before later stages can advance.
- Failed validation triage now exposes an explicit full-game rebuild path that clears game-scoped raw and derived import artifacts and requeues the pipeline from PBP.
- Skater plus/minus is now derived from eligible linked goal events after event-unit links exist and is compared against official boxscore plus/minus during validation.
- Persisted skater plus/minus now reconciles to official boxscore values when available to absorb provider ambiguity at exact goal-time shift boundaries.
- NHL game processing now runs source preflight before dispatch, blocks core imports missing PBP or boxscore, skips only shift-derived on-ice stages when shiftcharts are missing, and exposes the source reason plus exact provider URL in the Game Imports accordion.
- Summary validation now records `incomplete` when comparable core totals pass but source coverage prevents shift-dependent validation.
- Game Imports now includes a Source Gaps queue for missing provider feeds, with per-game reruns that refresh source preflight before queueing either a full core rebuild or only shift-derived stages.
