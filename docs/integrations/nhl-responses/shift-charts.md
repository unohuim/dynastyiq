# NHL Stats Shift Charts Response

## Endpoint

```text
https://api.nhle.com/stats/rest/en/shiftcharts?cayenneExp=gameId={gameId}
```

Current DynastyIQ consumers:

- `App\Services\ImportNhlShifts`
- `App\Services\NhlGameSourcePreflight`
- `App\Services\NhlValidationTroubleshootingExporter`
- Shift-derived unit services through stored `nhl_shifts`

## Purpose

This is the raw shift interval response. DynastyIQ uses it to store player shift rows, derive TOI and shift counts, build unit shifts, and link PBP events to on-ice units.

## Observations For DynastyIQ

Shiftcharts are necessary for on-ice analysis, but they are not perfectly aligned with official boxscore TOI and shift counts. Observed provider issues include duplicate intervals, contained rows, rows for players absent from PBP and boxscore, short impossible artifacts, goalie empty-net artifacts, and overtime rows that extend beyond the PBP-proven game end.

When every documented repair has run and the only remaining validation deltas are TOI or shifts, DynastyIQ marks the game `shiftchart-mismatch`, preserves the deltas, and uses official boxscore TOI/shifts in summaries.

## Field Map

| Path | Type / Shape | Observed Values | Meaning | DynastyIQ Usage | Must Not Drive |
| --- | --- | --- | --- | --- | --- |
| `data` | array | Shift rows. | Raw provider shiftchart rows. | Source for `nhl_shifts`. | Boxscore approval by itself. |
| `data[].gameId` | integer/string | NHL game id. | Game identity. | Scope validation. | Cross-game identity. |
| `data[].playerId` | integer | NHL player id. | Shift player identity. | Summary TOI/shifts and unit composition. | Player creation when absent from PBP and boxscore. |
| `data[].teamAbbrev` | string | Team abbreviation. | Shift team. | Filter to game home/away teams. | Current player team. |
| `data[].period` | integer | `1`, `2`, `3`, `4+`. | Period number. | Shift timing. | Game type. |
| `data[].startTime` | string | `MM:SS`. | Shift start in period. | `shift_start_seconds`. | Event time without period. |
| `data[].endTime` | string | `MM:SS`. | Shift end in period. | `shift_end_seconds`. | Final game boundary by itself. |
| `data[].duration` | string | `MM:SS`. | Provider duration. | Duration cross-check and stored duration. | Replacement for start/end ordering. |
| `data[].shiftNumber` | integer | Shift sequence for player. | Provider shift count context. | Duplicate/contained row decisions. | Official shift count by itself. |
| `data[].eventNumber` / event fields | integer/null | Provider event anchor. | Optional context. | Troubleshooting. | PBP event identity without PBP link. |
| `data[].typeCode` | integer | `517` for shift rows. | Row type. | Filter shift rows. | Other event imports. |

## Parser Contract

- Only import rows recognized as shift rows.
- Only import rows whose team abbreviation matches the stored game home or away team.
- Convert period-local times to game seconds.
- Drop rows with missing or invalid start/end times.
- Preserve raw shift payload fields for audit.
- Use boxscore targets only for documented reconciliation decisions.
- Drop shiftchart-only players only when both boxscore and PBP exist and neither source references the player.
- Trim or drop overtime shift time after a PBP-proven early OT game end.
- For goalies, ignore tiny zero-appearance artifacts only when boxscore and PBP both prove zero appearance and delta is under 30 seconds.
- Greater unresolved TOI/shift disagreement must remain visible as `shiftchart-mismatch` rather than being silently approved.

## Expected Normalized Output

- `nhl_shifts` rows with game, player, team, period, start/end seconds, and duration.
- Updated `nhl_game_summaries.toi` and `nhl_game_summaries.shifts`.
- Inputs for unit-shift creation and event-unit linking.
- Validation context for shiftchart mismatch troubleshooting.

## Open Verification Questions

- Which provider fields reliably identify duplicate rows versus legitimate repeated intervals?
- Are shiftchart rows stable after a game has been final for several days?
- Do historical seasons use the same `typeCode` and timing shapes?
