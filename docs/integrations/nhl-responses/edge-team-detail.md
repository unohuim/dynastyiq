# NHL Edge Team Detail Response

## Endpoint

| Field | Value |
| --- | --- |
| Method | `GET` |
| Path | `/v1/edge/team-detail/{team-id}/{season}/{game-type}` |
| Source Description | Retrieve team-based ranking for NHL Edge data. |
| Example cURL | `curl -X GET "https://api-web.nhle.com/v1/edge/team-detail/9/20242025/2"` |

Current DynastyIQ consumers:

- None.

## Sample Source

- `docs/api_responses/samples/nhlApi-edge-team-detail.txt`

The sample is a browser/object-inspector dump for the Ottawa Senators (`team.id = 9`) in the `20242025` regular season. It is not literal JSON text, but it preserves field names, nested shapes, arrays, and observed values.

## Purpose

This response exposes team-specific NHL Edge detail metrics. It combines team identity, Edge availability by season/game type, shot-speed leaders, skating-speed leaders, total distance skated, shot-on-goal location summaries, shot-area ranks, and zone-time percentages.

## Observations For DynastyIQ

This is a team-level Edge detail payload. It is useful for presentation and team-context analysis, but it is not a replacement for official standings, boxscore totals, PBP shot events, or shiftchart TOI.

The sample is regular-season data for one NHL team. It does not prove playoff shape, historical team shape, expansion/relocated team behavior, or whether every team has every section populated.

## Top-Level Observed Shape

| Section | Observed Shape | Observed Values / Notes |
| --- | --- | --- |
| `team` | object | Ottawa Senators identity and season summary snippet |
| `seasonsWithEdgeStats` | array | Five season rows from `20212022` through `20252026` |
| `shotSpeed` | object | Attempts over 90 mph and top shot speed |
| `skatingSpeed` | object | Bursts over 22 mph, bursts over 20 mph, and max skating speed |
| `distanceSkated` | object | Total team distance skated |
| `sogSummary` | array | Four grouped shot-location summary rows |
| `sogDetails` | array | 17 shot-area rows with shots and rank |
| `zoneTimeDetails` | object | Offensive, even-strength offensive, neutral, and defensive zone percentages |

## Team Snippet

| Path | Type / Shape | Observed Values | Meaning | DynastyIQ Usage | Must Not Drive |
| --- | --- | --- | --- | --- | --- |
| `team.id` | integer | `9` | NHL team id. | Future link to canonical NHL team. | Team identity mutation without team endpoint verification. |
| `team.commonName.default`, `team.commonName.fr` | localized object | `Senators`, `Sénateurs` | Team common name. | Future UI. | Team identity by itself. |
| `team.placeNameWithPreposition.default`, `.fr` | localized object | `Ottawa`, `d'Ottawa` | Team place display. | Future UI. | Team identity by itself. |
| `team.abbrev` | string | `OTT` | Team abbreviation. | Future UI. | Franchise history or relocation handling. |
| `team.teamLogo.light`, `.dark` | URL strings | NHL logo SVG URLs. | Team logo assets. | Future UI. | Team identity. |
| `team.slug` | string | `ottawa-senators-9` | NHL team slug. | Future URL/display context. | Team id replacement. |
| `team.conference` | string | `Eastern` | Conference display value. | Future UI. | Standings authority. |
| `team.division` | string | `Atlantic` | Division display value. | Future UI. | Standings authority. |
| `team.wins`, `losses`, `otLosses`, `gamesPlayed`, `points` | integers | `45`, `30`, `7`, `82`, `97` | Season summary stat snippet. | Future profile context. | Official standings import while standings endpoints exist. |

## Edge Availability

| Path | Type / Shape | Observed Values | Meaning | DynastyIQ Usage | Must Not Drive |
| --- | --- | --- | --- | --- | --- |
| `seasonsWithEdgeStats` | array | Five rows. | Seasons where Edge stats exist. | Future season/game-type selector. | Proof that DynastyIQ imported Edge stats. |
| `seasonsWithEdgeStats[].id` | integer | `20212022` through `20252026` | NHL season id. | Future filters. | Season authority by itself. |
| `seasonsWithEdgeStats[].gameTypes` | array of integers | `[2]` for older rows, `[2, 3]` for 2024-25 and 2025-26. | Available game types for Edge stats. | Future filters. | Import eligibility without enum handling. |

## Shot Speed

| Path | Type / Shape | Observed Values | Meaning | DynastyIQ Usage | Must Not Drive |
| --- | --- | --- | --- | --- | --- |
| `shotSpeed.shotAttemptsOver90.value` | integer | `58` | Team shot attempts over 90 mph. | Future Edge UI. | PBP shot totals. |
| `shotSpeed.shotAttemptsOver90.rank` | integer | `9` | League rank for attempts over 90 mph. | Future rank/context UI. | Global ranking without request-scope verification. |
| `shotSpeed.topShotSpeed.imperial` | decimal | `96.7` | Team top shot speed in mph. | Future Edge UI. | Event-level shot speed without event id. |
| `shotSpeed.topShotSpeed.metric` | decimal | `155.6236` | Team top shot speed in km/h. | Future Edge UI. | Unit conversion source by itself. |
| `shotSpeed.topShotSpeed.rank` | integer | `31` | League rank for top shot speed. | Future rank/context UI. | Global ranking without request-scope verification. |
| `shotSpeed.topShotSpeed.leagueAvg.*` | object | `99.8084` mph, `160.6261` km/h. | League average top shot speed. | Future comparison UI. | Global baseline without request-scope verification. |
| `shotSpeed.topShotSpeed.overlay` | object | Travis Hamonic, 2024-11-01, OTT at NYR, period 3, `13:25`. | Context for the top shot. | Future UI. | PBP event identity. |

## Skating Speed

| Path | Type / Shape | Observed Values | Meaning | DynastyIQ Usage | Must Not Drive |
| --- | --- | --- | --- | --- | --- |
| `skatingSpeed.burstsOver22.value` | integer | `107` | Team bursts over 22 mph. | Future Edge UI. | Shift count. |
| `skatingSpeed.burstsOver22.rank` | integer | `5` | League rank for bursts over 22 mph. | Future rank/context UI. | Global ranking without request-scope verification. |
| `skatingSpeed.burstsOver20.value` | integer | `1928` | Team bursts over 20 mph. | Future Edge UI. | Shift count. |
| `skatingSpeed.burstsOver20.rank` | integer | `6` | League rank for bursts over 20 mph. | Future rank/context UI. | Global ranking without request-scope verification. |
| `skatingSpeed.burstsOver20.leagueAvg.value` | integer | `1728` | League-average bursts over 20 mph. | Future comparison UI. | Global baseline without request-scope verification. |
| `skatingSpeed.speedMax.imperial` | decimal | `23.76` | Team max skating speed in mph. | Future Edge UI. | Player speed event without event id. |
| `skatingSpeed.speedMax.metric` | decimal | `38.238` | Team max skating speed in km/h. | Future Edge UI. | Unit conversion source by itself. |
| `skatingSpeed.speedMax.rank` | integer | `11` | League rank for max skating speed. | Future rank/context UI. | Global ranking without request-scope verification. |
| `skatingSpeed.speedMax.leagueAvg.*` | object | `23.7119` mph, `38.1607` km/h. | League average max skating speed. | Future comparison UI. | Global baseline without request-scope verification. |
| `skatingSpeed.speedMax.overlay` | object | Josh Norris, 2024-11-09, OTT at BOS, OT outcome, period 1, `04:01`. | Context for the max speed. | Future UI. | PBP event identity. |

## Distance Skated

| Path | Type / Shape | Observed Values | Meaning | DynastyIQ Usage | Must Not Drive |
| --- | --- | --- | --- | --- | --- |
| `distanceSkated.total.imperial` | decimal | `3735.0204` | Total team distance in miles. | Future Edge UI. | TOI calculation. |
| `distanceSkated.total.metric` | decimal | `6010.6394` | Total team distance in kilometers. | Future Edge UI. | Unit conversion source by itself. |
| `distanceSkated.total.rank` | integer | `21` | League rank for total distance. | Future rank/context UI. | Global ranking without request-scope verification. |
| `distanceSkated.total.leagueAvg.*` | object | `3741.4702` miles, `6021.0187` kilometers. | League-average team distance. | Future comparison UI. | Global baseline without request-scope verification. |

## Shot-On-Goal Summary

`sogSummary` contains four grouped location rows in the sample: `all`, `high`, `long`, and `mid`.

| Path | Type / Shape | Observed Values | Meaning | DynastyIQ Usage | Must Not Drive |
| --- | --- | --- | --- | --- | --- |
| `sogSummary[].locationCode` | string | `all`, `high`, `long`, `mid` | Provider location grouping. | Future Edge UI. | Shot geometry taxonomy without mapping. |
| `sogSummary[].shots` | integer | `548` to `2403` | Grouped shot total. | Future Edge UI. | Boxscore/PBP SOG validation. |
| `sogSummary[].shotsRank` | integer | `8` to `14` | League rank for grouped shots. | Future rank/context UI. | Global ranking without request-scope verification. |
| `sogSummary[].shotsLeagueAvg` | decimal | `506.8125` to `2318.5` | League average grouped shots. | Future comparison UI. | Global baseline without request-scope verification. |
| `sogSummary[].shootingPctg` | decimal | `0.0255` to `0.1216` | Grouped shooting percentage. | Future Edge UI. | Scoring projection by itself. |
| `sogSummary[].shootingPctgRank` | integer | `15` to `29` | League rank for grouped shooting percentage. | Future rank/context UI. | Global ranking without request-scope verification. |
| `sogSummary[].shootingPctgLeagueAvg` | decimal | `0.0351` to `0.1997` | League average grouped shooting percentage. | Future comparison UI. | Global baseline without request-scope verification. |
| `sogSummary[].goals` | integer | `14` to `242` | Grouped goal total. | Future Edge UI. | PBP goal import. |
| `sogSummary[].goalsRank` | integer | `15` to `26` | League rank for grouped goals. | Future rank/context UI. | Global ranking without request-scope verification. |
| `sogSummary[].goalsLeagueAvg` | decimal | `17.7813` to `246.9063` | League average grouped goals. | Future comparison UI. | Global baseline without request-scope verification. |

Observed notable rows:

- `all`: `2403` shots, `242` goals, `0.1007` shooting percentage.
- `high`: `629` shots, `120` goals, `0.1908` shooting percentage.
- `long`: `548` shots, `14` goals, `0.0255` shooting percentage.
- `mid`: `666` shots, `81` goals, `0.1216` shooting percentage.

## Shot-On-Goal Details

`sogDetails` contains 17 area buckets in the sample.

| Path | Type / Shape | Observed Values | Meaning | DynastyIQ Usage | Must Not Drive |
| --- | --- | --- | --- | --- | --- |
| `sogDetails[].area` | string | `Low Slot`, `Crease`, `L Circle`, `R Point`, `Offensive Neutral Zone`, etc. | Provider shot-area bucket. | Future Edge shot map UI. | Shot geometry taxonomy without mapping. |
| `sogDetails[].shots` | integer | `3` to `547` | Team shot count for the area. | Future Edge UI. | Boxscore/PBP SOG validation. |
| `sogDetails[].shotsRank` | integer | `1` to `27` | League rank for shots from the area. | Future rank/context UI. | Global ranking without request-scope verification. |

Observed notable values:

- `Low Slot`: `547` shots, rank `15`.
- `L Circle`: `242` shots, rank `8`.
- `R Circle`: `231` shots, rank `8`.
- `R Point`: `190` shots, rank `6`.
- `R Corner`: `7` shots, rank `1`.

These are derived Edge shot-location summaries. They should not be merged into PBP shot geometry without a dedicated mapping and source authority decision.

## Zone Time

| Path | Type / Shape | Observed Values | Meaning | DynastyIQ Usage | Must Not Drive |
| --- | --- | --- | --- | --- | --- |
| `zoneTimeDetails.offensiveZonePctg` | decimal | `0.4176958` | Team offensive-zone time percentage/share. | Future Edge UI. | Unit possession model by itself. |
| `zoneTimeDetails.offensiveZoneRank` | integer | `7` | League rank for offensive-zone time. | Future rank/context UI. | Global ranking without request-scope verification. |
| `zoneTimeDetails.offensiveZoneLeagueAvg` | decimal | `0.410504` | League-average offensive-zone percentage/share. | Future comparison UI. | Global baseline without request-scope verification. |
| `zoneTimeDetails.offensiveZoneEvPctg` | decimal | `0.4112718` | Team even-strength offensive-zone time percentage/share. | Future Edge UI. | Unit possession model by itself. |
| `zoneTimeDetails.offensiveZoneEvRank` | integer | `10` | League rank for even-strength offensive-zone time. | Future rank/context UI. | Global ranking without request-scope verification. |
| `zoneTimeDetails.offensiveZoneEvLeagueAvg` | decimal | `0.4072952` | League-average even-strength offensive-zone percentage/share. | Future comparison UI. | Global baseline without request-scope verification. |
| `zoneTimeDetails.neutralZonePctg` | decimal | `0.175396` | Team neutral-zone time percentage/share. | Future Edge UI. | Unit possession model by itself. |
| `zoneTimeDetails.neutralZoneRank` | integer | `25` | League rank for neutral-zone time. | Future rank/context UI. | Global ranking without request-scope verification. |
| `zoneTimeDetails.neutralZoneLeagueAvg` | decimal | `0.1789921` | League-average neutral-zone percentage/share. | Future comparison UI. | Global baseline without request-scope verification. |
| `zoneTimeDetails.defensiveZonePctg` | decimal | `0.4069082` | Team defensive-zone time percentage/share. | Future Edge UI. | Defensive valuation by itself. |
| `zoneTimeDetails.defensiveZoneRank` | integer | `14` | League rank for defensive-zone time. | Future rank/context UI. | Global ranking without request-scope verification. |
| `zoneTimeDetails.defensiveZoneLeagueAvg` | decimal | `0.410504` | League-average defensive-zone percentage/share. | Future comparison UI. | Global baseline without request-scope verification. |

## Overlay Shape

`shotSpeed.topShotSpeed` and `skatingSpeed.speedMax` include an `overlay` object.

| Path | Type / Shape | Observed Values | Meaning | DynastyIQ Usage | Must Not Drive |
| --- | --- | --- | --- | --- | --- |
| `overlay.player.firstName.default`, `lastName.default` | localized object | Travis Hamonic, Josh Norris. | Player display name for the max value. | Future UI. | Identity. |
| `overlay.gameDate` | date string | `2024-11-01`, `2024-11-09`. | Game date for max event/value. | Future UI. | Import scheduling. |
| `overlay.awayTeam`, `overlay.homeTeam` | object | Abbrev and score. | Game matchup context. | Future UI. | Official boxscore. |
| `overlay.gameOutcome.lastPeriodType` | string | `REG`, `OT`. | Game outcome period type. | Future UI. | Validation status. |
| `overlay.gameOutcome.otPeriods` | integer, optional | `1`. | Overtime count. | Future UI. | Shiftchart OT trimming by itself. |
| `overlay.periodDescriptor` | object | `maxRegulationPeriods`, `number`, `periodType`. | Period context. | Future UI. | PBP event identity. |
| `overlay.timeInPeriod` | string | `13:25`, `04:01`. | Time in period. | Future UI. | PBP event identity. |
| `overlay.gameType` | integer | `2`. | Game type. | Future filters. | Import eligibility without enum handling. |

## Opportunity

- This endpoint can power team Edge profile cards that explain how a team plays: pace, speed, shot power, shot-location tendencies, and territorial profile.
- `shotSpeed.shotAttemptsOver90` and `shotSpeed.topShotSpeed` can identify teams with unusually heavy shot profiles.
- `skatingSpeed.burstsOver22`, `burstsOver20`, and `distanceSkated.total` can support team pace and skating-style comparisons.
- `sogSummary` can show whether a team generates offense from high-danger, mid-range, or long-range areas.
- `sogDetails` can be used as a team shot-location tendency map, especially if later mapped against DynastyIQ's PBP coordinate-derived zones.
- `zoneTimeDetails` gives immediate team territorial context with league averages and ranks, making it useful for opponent-strength and matchup views.
- Overlay context can make top shot and top speed values inspectable instead of anonymous leaderboard numbers.

## Parser Contract

- Treat this response as a team-specific Edge detail payload, not as canonical team, standings, boxscore, PBP, or shiftchart authority.
- Validate `team.id` and `team.abbrev` through team endpoints or existing canonical team mappings before linking to DynastyIQ teams.
- Keep imperial and metric fields as separate provider values; do not regenerate one from the other unless a later contract explicitly chooses one canonical unit.
- Do not use Edge distance, zone time, or shot-location fields as official game-summary, PBP, boxscore, or shiftchart validation values.
- Do not use overlay data as a reliable PBP event link unless a later contract verifies how NHL Edge chooses and identifies the source event.
- Treat rank and league-average values as request-scoped provider context until endpoint parameters and denominator rules are verified.
- Do not assume every team has every Edge detail section; missing or null sections should be treated as unavailable provider data.

## Expected Normalized Output

No current normalized output. Future work could define:

- Team Edge profile cards.
- Pace and skating-style indicators.
- Team shot-power and shot-location tendency panels.
- Offensive/neutral/defensive zone-time comparison widgets.
- Opponent matchup context for fantasy player analysis.

## Open Verification Questions

- Does the response shape change for playoff requests with `gameType = 3`?
- Are rank and league-average values calculated across all NHL teams, playoff teams only, or another request-scoped population?
- Are shot-area bucket names stable across seasons?
- Are `sogSummary.locationCode` values stable and directly comparable to skater Edge payloads?
- Can overlay values be mapped to official PBP events, or are they presentation-only moments?
