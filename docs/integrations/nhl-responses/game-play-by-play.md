# NHL Game Play-By-Play Response

## Endpoint

```text
https://api-web.nhle.com/v1/gamecenter/{gameId}/play-by-play
```

Config key:

```text
nhl.pbp
```

Current DynastyIQ consumers:

- `App\Services\ImportNHLPlayByPlay`
- `App\Services\NhlPbpEventNormalizer`
- `App\Services\SumNHLPlayByPlay`
- `App\Services\MakeNhlGameShiftUnits`
- `App\Services\ConnectEventsToUnitShifts`
- `App\Services\NhlValidationTroubleshootingExporter`

## Sample Source

- `docs/api_responses/samples/nhlPlayByPlay.txt`

Observed sample:

- `source = pbp`
- `url = https://api-web.nhle.com/v1/gamecenter/2025021200/play-by-play`
- `payload.id = 2025021200`
- `payload.gameType = 2`
- `payload.gameState = OFF`
- `payload.gameScheduleState = OK`
- `payload.limitedScoring = false`
- `payload.plays` contains 318 rows.
- `payload.rosterSpots` contains 40 rows.
- `payload.summary` is an empty array in this sample.

## Purpose

This endpoint is the primary NHL game event response. DynastyIQ uses it to create or update `nhl_games`, store raw PBP events, derive player game summaries, link events to unit shifts, and prove timing context for validation repairs.

## Observations For DynastyIQ

PBP is event truth, not official player total truth. It includes the ordered event stream, event timing, participant ids, location coordinates, situation codes, score state, and selected media links. Official validation totals still belong to the boxscore endpoint.

The sample shows event details vary heavily by `typeDescKey`. A parser must route by event type and tolerate missing detail keys. Empty-net goals may omit `goalieInNetId`, and stoppage rows may carry only reason text.

## Top-Level Observed Shape

| Path | Type / Shape | Observed Values | Meaning | DynastyIQ Usage | Must Not Drive |
| --- | --- | --- | --- | --- | --- |
| `source` | string | `pbp` | Sample wrapper label. | Troubleshooting sample context. | Provider parser logic. |
| `url` | string | Full PBP URL. | Source URL. | Troubleshooting provenance. | Game identity without payload. |
| `payload` | object | Game PBP response. | Provider response body. | Import source. | None by itself. |
| `payload.id` | integer | `2025021200` | NHL game id. | `nhl_games.nhl_game_id`; game-scoped natural key. | Player identity. |
| `payload.season` | integer | `20252026` | NHL season id. | `nhl_games.season_id`; progress context. | Fantasy season mapping by itself. |
| `payload.gameType` | integer | `2` | NHL game type. | Import eligibility and validation behavior. | UI label without enum mapping. |
| `payload.limitedScoring` | boolean | `false` | Provider scoring-detail flag. | Stored game metadata and validation context. | Automatic approval/failure by itself. |
| `payload.gameDate` | date string | `2026-04-02` | Game date. | Game/progress display. | Exact start time. |
| `payload.venue.default` | localized object | `Rogers Place` | Venue display name. | Game display. | Import eligibility. |
| `payload.venueLocation.default` | localized object | `Edmonton` | Venue location display. | Game display. | Team identity. |
| `payload.startTimeUTC` | datetime string | `2026-04-03T01:00:00Z` | Scheduled start time. | Game display/scheduling context. | Completion. |
| `payload.easternUTCOffset`, `venueUTCOffset` | string | `-04:00`, `-06:00` | Time zone offsets. | Display/context. | Date conversion without `startTimeUTC`. |
| `payload.tvBroadcasts` | array | 3 rows: SNW, TVAS, CHSN. | Broadcast metadata. | Future display. | Game authority. |
| `payload.gameState` | string | `OFF` | Provider game lifecycle. | Import state context. | Validation success by itself. |
| `payload.gameScheduleState` | string | `OK` | Schedule lifecycle. | Stored game metadata. | Source availability. |
| `payload.periodDescriptor` | object | Final period 3, `REG`, max regulation 3. | Current/final period context. | Stored game metadata and timing. | Shift timing without event/shift evidence. |
| `payload.clock` | object | `00:00`, 0 seconds, not running, not intermission. | Clock state. | Stored game metadata. | Historical game-end boundary without final events. |
| `payload.displayPeriod` | integer | `1` | Display period value. | Future display after verification. | Actual current period over `periodDescriptor`. |
| `payload.maxPeriods` | integer | `5` | Max periods display/rules context. | Stored metadata. | Game type by itself. |
| `payload.gameOutcome.lastPeriodType` | string | `REG` | Final period type. | Game outcome context. | Validation status. |
| `payload.summary` | array | Empty array in sample. | Unused provider section in this sample. | None currently. | Scoring/penalty summary assumptions. |
| `payload.regPeriods` | integer | Present in payload keys. | Regulation periods metadata. | Future display/rules context. | `periodDescriptor` replacement without verification. |
| `payload.rosterSpots` | array | 40 player rows. | Game participant roster snippets. | Future game participant bootstrap/display. | Canonical player creation without landing. |
| `payload.plays` | array | 318 event rows. | Ordered event stream. | `play_by_plays`, summaries, event-unit links. | Official boxscore totals. |

## Team And Broadcast Shape

| Path | Type / Shape | Observed Values | Meaning | DynastyIQ Usage | Must Not Drive |
| --- | --- | --- | --- | --- | --- |
| `payload.awayTeam.id`, `homeTeam.id` | integer | CHI `16`, EDM `22`. | NHL team ids. | Stored game team ids. | Current team roster. |
| `payload.awayTeam.commonName.default`, `homeTeam.commonName.default` | localized object | `Blackhawks`, `Oilers`. | Team common names. | Game display. | Team identity alone. |
| `payload.awayTeam.abbrev`, `homeTeam.abbrev` | string | `CHI`, `EDM`. | Team abbreviations. | Stored game team abbrevs. | Current player team. |
| `payload.awayTeam.score`, `homeTeam.score` | integer | `1`, `3`. | Final/current team score. | Stored game score. | Player scoring totals. |
| `payload.awayTeam.sog`, `homeTeam.sog` | integer | `18`, `39`. | Team shots on goal. | Stored game team SOG. | Player SOG validation. |
| `payload.*Team.logo`, `darkLogo` | URL string | NHL SVG asset URLs. | Team logo assets. | Game display. | Team identity. |
| `payload.*Team.placeName.default` | localized object | `Chicago`, `Edmonton`. | Team place display. | Game display. | Team identity alone. |
| `payload.*Team.placeNameWithPreposition.*` | localized object | English/French values. | Localized place text. | Future display. | Team identity. |
| `payload.tvBroadcasts[].id` | integer | `289`, `281`, `551`. | Broadcast provider id. | Future display. | Game identity. |
| `payload.tvBroadcasts[].market` | string | `H`, `N`, `A`. | Home/national/away market marker. | Future display. | Team ownership. |
| `payload.tvBroadcasts[].countryCode` | string | `CA`, `US`. | Broadcast country. | Future display. | User locale. |
| `payload.tvBroadcasts[].network` | string | `SNW`, `TVAS`, `CHSN`. | Network name. | Future display. | Game import decisions. |
| `payload.tvBroadcasts[].sequenceNumber` | integer | Varies. | Provider ordering. | Display ordering candidate. | Stable identity without verification. |

## Roster Spots

Observed `payload.rosterSpots[0]`:

```json
{
  "teamId": 22,
  "playerId": 8474641,
  "firstName": {"default": "Adam"},
  "lastName": {"default": "Henrique"},
  "sweaterNumber": 19,
  "positionCode": "C",
  "headshot": "https://assets.nhle.com/mugs/nhl/20252026/EDM/8474641.png"
}
```

| Path | Type / Shape | Observed Values | Meaning | DynastyIQ Usage | Must Not Drive |
| --- | --- | --- | --- | --- | --- |
| `payload.rosterSpots[].teamId` | integer | `22`, `16`. | Game team id for participant. | Future display/bootstrap. | Current team beyond this game. |
| `payload.rosterSpots[].playerId` | integer | NHL player ids. | Participant player id. | Future participant display and landing refresh candidates. | Canonical player creation without landing. |
| `payload.rosterSpots[].firstName.default`, `lastName.default` | localized object | Player names. | Participant display. | Future UI. | Unique identity. |
| `payload.rosterSpots[].sweaterNumber` | integer | Jersey number. | Game display. | Future UI. | Stable identity. |
| `payload.rosterSpots[].positionCode` | string | `C`, etc. | Game participant position. | Future UI/filter. | Fantasy eligibility. |
| `payload.rosterSpots[].headshot` | URL string | NHL mugshot URL. | Player image. | Future UI. | Identity. |

## Play Row Base Shape

Every observed play has a base event envelope.

| Path | Type / Shape | Observed Values | Meaning | DynastyIQ Usage | Must Not Drive |
| --- | --- | --- | --- | --- | --- |
| `payload.plays[].eventId` | integer | `52`, `541`, etc. | Event id within game. | Natural key with game id. | Cross-game identity. |
| `payload.plays[].periodDescriptor.number` | integer | `1`, `2`, `3`. | Event period number. | Timing and period context. | Scheduled period length by itself. |
| `payload.plays[].periodDescriptor.periodType` | string | `REG`. | Period type. | Timing and OT/shootout handling. | Game type. |
| `payload.plays[].periodDescriptor.maxRegulationPeriods` | integer | `3`. | Regulation length context. | Timing/rules context. | Period count by itself. |
| `payload.plays[].timeInPeriod` | `MM:SS` string | `00:47`, `18:59`. | Elapsed time in period. | `seconds_in_period`, `seconds_in_game`. | Final game length by itself. |
| `payload.plays[].timeRemaining` | `MM:SS` string | `19:13`, `01:01`. | Remaining period time. | Troubleshooting/context. | Shift duration. |
| `payload.plays[].situationCode` | string | `1551`, `1451`, `0651`. | Manpower/situation code. | Strength normalization and empty-net context. | Strength without normalizer. |
| `payload.plays[].homeTeamDefendingSide` | string | `left`, `right`. | Rink side context. | Shot map orientation. | Team identity. |
| `payload.plays[].typeCode` | integer | `502`, `505`, `506`, etc. | Numeric event type. | Stored raw metadata/context. | Event semantics over `typeDescKey` without mapping. |
| `payload.plays[].typeDescKey` | string | See event type list. | Event type key. | Event routing and normalizer. | Boxscore counting without normalizer. |
| `payload.plays[].sortOrder` | integer | Varies. | Provider event ordering. | Ordering events at same clock. | Event identity. |
| `payload.plays[].details` | object, optional | Event-specific keys. | Participant/location/stat context. | Summary derivation and triage. | Raw display without event-specific parsing. |
| `payload.plays[].pptReplayUrl` | URL string, optional | Present on observed goals. | Replay sprite JSON URL. | Future media/debug display. | Stat import. |

## Observed Event Types

| `typeDescKey` | Count In Sample | Notes |
| --- | ---: | --- |
| `blocked-shot` | 37 | Details include shooter, blocker, coordinates, zone, reason. |
| `delayed-penalty` | 3 | Details only include `eventOwnerTeamId` in sample. |
| `faceoff` | 60 | Details include winner, loser, coordinates, zone. |
| `game-end` | 1 | Terminal event. |
| `giveaway` | 25 | Details include player, team, coordinates, zone. |
| `goal` | 4 | Details include scorer, assists, score, goalie when applicable, media clip ids/URLs. |
| `hit` | 27 | Details include hitter and hittee. |
| `missed-shot` | 34 | Details include shooter, goalie, shot type, miss reason. |
| `penalty` | 4 | Details include committed/drawn players, duration, desc/type. |
| `period-end` | 3 | Period terminal event. |
| `period-start` | 3 | Period opening event. |
| `shot-on-goal` | 53 | Details include shooter, goalie, shot type, SOG counters. |
| `stoppage` | 54 | Details include reason and sometimes secondary reason. |
| `takeaway` | 10 | Details include player, team, coordinates, zone. |

## Event Detail Variants

| Event Type | Observed Detail Keys | DynastyIQ Usage | Must Not Drive |
| --- | --- | --- | --- |
| `faceoff` | `eventOwnerTeamId`, `losingPlayerId`, `winningPlayerId`, `xCoord`, `yCoord`, `zoneCode` | Faceoff summaries and event context. | Player identity without landing. |
| `shot-on-goal` | `xCoord`, `yCoord`, `zoneCode`, `shotType`, `shootingPlayerId`, `goalieInNetId`, `eventOwnerTeamId`, `awaySOG`, `homeSOG` | Shot summaries, goalie-facing totals, shot maps. | Official boxscore SOG without normalizer. |
| `missed-shot` | `xCoord`, `yCoord`, `zoneCode`, `reason`, `shotType`, `shootingPlayerId`, `goalieInNetId`, `eventOwnerTeamId` | Attempts and shot context. | SOG totals. |
| `blocked-shot` | `xCoord`, `yCoord`, `zoneCode`, `blockingPlayerId`, `shootingPlayerId`, `eventOwnerTeamId`, `reason` | Blocks and shot-attempt context. | SOG totals. |
| `goal` | `xCoord`, `yCoord`, `zoneCode`, `shotType`, `scoringPlayerId`, `scoringPlayerTotal`, `assist1PlayerId`, `assist1PlayerTotal`, `assist2PlayerId`, `assist2PlayerTotal`, `eventOwnerTeamId`, `goalieInNetId`, `awayScore`, `homeScore`, media clip keys | Goals, assists, score state, goalie-facing context, highlights. | Official season totals or boxscore validation without normalizer. |
| `hit` | `xCoord`, `yCoord`, `zoneCode`, `eventOwnerTeamId`, `hittingPlayerId`, `hitteePlayerId` | Hit summaries. | Penalty/contact interpretation. |
| `giveaway` / `takeaway` | `xCoord`, `yCoord`, `zoneCode`, `eventOwnerTeamId`, `playerId` | Turnover summaries. | Fantasy scoring category support without league mapping. |
| `penalty` | `xCoord`, `yCoord`, `zoneCode`, `eventOwnerTeamId`, `committedByPlayerId`, `drawnByPlayerId`, `duration`, `typeCode`, `descKey` | PIM summaries and penalty semantics. | Boxscore PIM without match-penalty tolerance rules. |
| `stoppage` | `reason`, optional `secondaryReason` | Stoppage analysis and troubleshooting. | Event-unit state changes by itself. |
| `delayed-penalty` | `eventOwnerTeamId` | Future game-state context. | PIM or power-play state by itself. |

## Goal Media Fields

Observed goal rows include media fields inside `details` and `pptReplayUrl` on the play row.

| Path | Type / Shape | Observed Values | Meaning | DynastyIQ Usage | Must Not Drive |
| --- | --- | --- | --- | --- | --- |
| `details.highlightClipSharingUrl`, `highlightClipSharingUrlFr` | URL string | NHL video URLs. | Shareable highlight URLs. | Future game/player UI. | Stat validation. |
| `details.highlightClip`, `highlightClipFr` | integer | Clip ids. | Provider highlight ids. | Future media embed lookup. | Event identity. |
| `details.discreteClip`, `discreteClipFr` | integer | Clip ids. | Provider discrete clip ids. | Future media/debug display. | Event identity. |
| `pptReplayUrl` | URL string | `https://wsr.nhle.com/sprites/.../ev541.json` | Replay sprite JSON URL. | Future shot/replay visualization. | Stat import. |

## Opportunity

- The ordered event stream can power a rich game timeline with goals, penalties, shots, turnovers, hits, faceoffs, and stoppages.
- Goal rows already expose shareable highlight URLs and replay sprite URLs, making goal clips a near-term player/game profile feature.
- Coordinates plus `homeTeamDefendingSide` can support shot maps, rink-zone visuals, faceoff maps, hit maps, and turnover location views.
- Stoppage reasons and secondary reasons can support stoppage analytics, review workflows, or troubleshooting when games behave strangely.
- `rosterSpots` can bootstrap a game participant list with headshots and sweater numbers before deeper player profile loading.
- `situationCode` plus score/goalie fields can support empty-net, manpower, and strength-context explanations.
- `awaySOG` and `homeSOG` counters on shot rows can help detect PBP feed inconsistencies or missing shot events during validation diagnostics.
- Media fields on goals can make validation/debug views more human-readable by linking directly to the relevant highlight.

## Parser Contract

- Do not import unsupported `gameType` values.
- Store raw provider event/details payload in `play_by_plays.metadata` for audit.
- Use `NhlPbpEventNormalizer` for shots, goals, shootout, empty-net, penalty-shot, and penalty-minute semantics before deriving summaries.
- Natural event identity is game id plus event id.
- Use `sortOrder` with event time to preserve provider ordering.
- Compute elapsed seconds from `timeInPeriod` and period number with regulation period length of 20 minutes, while using `periodDescriptor.periodType` for overtime/shootout handling.
- PBP player ids may require landing import before summaries can reference canonical player rows.
- A player missing from both PBP and boxscore may be ignored when only shiftcharts mention that player.
- Treat missing `goalieInNetId` as meaningful absence, not as zero. Empty-net and goalie-facing summaries need normalizer/reconciliation logic.
- Treat `summary` as unused until a sample with populated structure is documented.

## Expected Normalized Output

- `nhl_games` row with game metadata, teams, clock, and state.
- `play_by_plays` rows keyed by game and event id.
- PBP-derived player game summaries from supported event types.
- Event timing suitable for unit-shift links and validation troubleshooting.
- Raw metadata sufficient to re-audit event-specific parsing decisions.

## Open Verification Questions

- Which live-state values should be treated as safe for import versus wait/retry?
- Are all event ids and sort orders stable after a game moves to `OFF`?
- Which event fields differ between regular season, playoffs, preseason, and exhibition games?
- What shape does `summary` take when it is populated?
- Are media clip fields present for all goals, only final games, or only selected games?
