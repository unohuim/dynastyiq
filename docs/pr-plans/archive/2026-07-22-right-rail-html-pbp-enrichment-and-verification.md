---
pr_id: 16
pr_name: pr16
status: Archived
created: 2026-07-21
last_updated: 2026-07-22
---

# Right-Rail HTML PBP Enrichment And Verification

## Source

Discussion on 2026-07-21 about NHL gamecenter `right-rail` payloads exposing official HTML report URLs, especially the `PL` play-by-play report and `TV`/`TH` time-on-ice reports.

The agreed direction is to keep all current NHL sources, add right-rail HTML reports as an additional source layer, and use the HTML play-by-play report after the normal API PBP import to enrich on-ice player positions and verify that events match.

## Objective

Add a separate right-rail HTML PBP enrichment and verification stage that runs after the existing API PBP import and event-unit linking. The stage should parse the NHL HTML play-by-play report, attach contextual on-ice player positions to unit shifts, and record whether the HTML report agrees with the imported API PBP events.

## Current DynastyIQ Context

DynastyIQ already imports and derives game data from:

- API play-by-play rows in `play_by_plays`.
- Boxscore rows used as validation/reconciliation targets.
- Stats REST shiftchart rows.
- `nhl_units`, `nhl_unit_shifts`, and `event_unit_shifts` for on-ice unit windows and event links.

Recent schema work added the planned storage shape for contextual player positions:

- `nhl_unit_shift_players`
- `unit_shift_id`
- `player_id`
- `position_code`

This table keeps player position scoped to a specific unit shift, so the same unit composition can have different assignments on different shifts.

## Scope

- Add a service that reads the gamecenter right-rail payload and discovers the official HTML play-by-play report URL.
- Add an HTML PBP parser for the `PL` report that extracts normalized event rows.
- Extract event-level on-ice players and contextual positions when the report provides them.
- Match parsed HTML PBP events to existing `play_by_plays` records.
- Upsert `nhl_unit_shift_players` rows by `(unit_shift_id, player_id)`.
- Compare HTML PBP events against imported API PBP events for event count, period, time, event type, scoring events, penalty events, team, and player involvement where available.
- Persist source/enrichment/verification status without making missing HTML reports fatal to the core import.
- Use official TV/TH time-on-ice reports as fallback shift windows when stats REST shiftcharts are missing or empty.
- Add focused Pest tests for parser behavior, event matching, position upserts, idempotency, and mismatch status behavior.

## Out Of Scope

- Replacing API play-by-play as the primary PBP import source.
- Replacing stats REST shiftcharts as the primary shift source when shiftcharts are available.
- Normalizing every right-rail HTML report type.
- Building user-facing UI for right-rail reports.
- Changing fantasy stat calculations to depend on HTML PBP position enrichment.

## Data Model

Use the existing/imported schema direction:

- `nhl_unit_shift_players.unit_shift_id`
- `nhl_unit_shift_players.player_id`
- `nhl_unit_shift_players.position_code`

Allowed `position_code` values remain canonical in `docs/ENUMS.md`:

- `LW`
- `C`
- `RW`
- `LD`
- `RD`
- `G`

If implementation needs source-level audit rows for HTML report availability or mismatch details, prefer extending existing NHL source-status or validation patterns instead of introducing broad new tables.

## Service Shape

Expected service boundaries:

- Right-rail report discovery service.
- HTML PBP fetcher.
- HTML PBP parser returning normalized DTOs.
- Enrichment writer for `nhl_unit_shift_players`.
- Verification service comparing HTML PBP DTOs against API PBP rows.

The parser should not write database rows directly.

## Processing Rules

- This stage runs after API PBP import and after events are linked to unit shifts.
- Missing right-rail or missing `PL` report should mark enrichment unavailable, not fail the game.
- HTML fetch or parse failure should mark enrichment failed, not fail the core game import.
- Event mismatch should create a separate verification warning/status.
- Position writes must be idempotent and upsert by `(unit_shift_id, player_id)`.
- Existing unit identity must not change when player positions change.
- HTML PBP must not overwrite canonical API PBP fields.
- Raw source URLs and failure reasons should be preserved for audit.

## Admin Review Workflow

HTML PBP disagreement should mean that DynastyIQ found a difference between two NHL-published sources, not that the game import automatically failed.

Keep all review inside the admin control panel. HTML/API disagreements should be surfaced through the embedded game validation and shift mismatch panels, with expandable detail rows, not through standalone game verification routes or a separate non-admin experience.

Rows should show:

- Game, teams, date, and import run context when available.
- Mismatch severity.
- Mismatch type.
- Affected event count.
- Last checked timestamp.

Mismatch types should distinguish:

- Event count mismatch.
- Event time, period, type, or team mismatch.
- Scoring event player mismatch.
- Penalty event player mismatch.
- On-ice player mismatch.
- Position-only mismatch.
- Parser or source availability failure.

Severity should reflect downstream risk:

- High: scoring, penalty, player attribution, or broad event alignment differences that may affect fantasy stats.
- Medium: on-ice player differences that may affect unit attribution or plus/minus confidence.
- Low: position-only differences where core stats and event-unit links remain usable.
- Info: unavailable HTML report, missing right-rail URL, or parser coverage gap.

The detail view should show the API PBP row and parsed HTML PBP row side by side for each mismatch, including the raw source URL.

Expected admin actions:

- Re-run HTML verification.
- Re-run event-shift linking.
- Run source-only/full-PBP troubleshooting when event-level source comparison is needed.
- Run TV/TH TOI reconciliation when remaining mismatches need shift-window evidence.
- Accept API as canonical and leave the game otherwise approved.
- Accept position enrichment only when core event data agrees.
- Ignore or acknowledge a known source mismatch with a note.

The default response should be to keep the game imported and preserve existing stats. Manual review is only required when the mismatch could affect player stats, unit attribution, or event trust.

## Acceptance Criteria

- Running the service for a game with an available `PL` report discovers and parses the HTML PBP report.
- Matching HTML events enrich linked unit shifts with per-player `position_code` rows.
- Re-running the service updates existing position rows without duplicates.
- Event verification records success when HTML PBP and API PBP agree on comparable fields.
- Event verification records a non-fatal mismatch status when comparable fields disagree.
- Missing or unavailable HTML reports are recorded as unavailable and do not fail the game import.
- Admin users can review HTML/API disagreements inside the admin control panel validation surfaces without leaving the admin shell.
- Admin users can see mismatch severity, mismatch type, affected events, source URLs, and side-by-side API vs HTML event data.
- Admin users can re-run verification, accept API as canonical, accept position enrichment only, or acknowledge a known source mismatch.
- Tests cover a successful parse/enrich path, missing report path, parser failure path, mismatch path, and idempotent re-run path.

## Implementation Notes

- Use `right-rail` as the report-discovery source when available.
- Prefer stable game/report URL derivation only as a fallback after confirming source behavior.
- Use the `PL` report as the event-level HTML source.
- Use `TV`/`TH` as TOI and shift-count troubleshooting sources when HTML/API PBP enrichment finds source mismatches, and as fallback shift-window sources when shiftcharts are missing or empty.
- Regulation and overtime penalty-shot attempts count toward normal game shot totals when the event type qualifies, while shootout attempts remain excluded from normal game totals.
- Penalty-shot attempts must be skipped for normal event-unit links and on-ice mismatch reporting because the HTML event presents shooter-goalie participants while shiftcharts present normal shift windows.

## Documentation Updates

- Promote any durable source-order or status rules into `docs/architecture/imports/NhlGameDataImportServices.yaml`.
- Keep position-code enum authority in `docs/ENUMS.md`.
- Update `docs/DB_SCHEMA.md` if implementation changes schema beyond the current planned table.
- Add or update NHL response usage docs only when new right-rail HTML parsing behavior changes how DynastyIQ uses those payloads.
