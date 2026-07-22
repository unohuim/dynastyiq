# NHL Edge Skater Comparison Response

## Endpoint

The exact endpoint route is not currently configured in DynastyIQ.

Current DynastyIQ consumers:

- None.

## Sample Source

- `docs/api_responses/samples/nhlEdgeSkaterComparison.txt`

The sample is a browser/object-inspector dump for Tim Stützle (`player.id = 8482116`). It is not literal JSON text, but it preserves field names, nested shapes, arrays, and observed values.

## Purpose

This response exposes player-specific NHL Edge comparison metrics for a skater. It combines player profile context, Edge availability by season/game type, shot-speed buckets, skating-speed burst buckets, recent-game skating distance, aggregate skating distance, shot-location summaries, zone-time percentages, and zone-start percentages.

## Observations For DynastyIQ

This is a player-specific Edge payload, unlike `player-landing-edge.md`, which is a leader-style payload. It is useful for future player profile comparisons and advanced visualizations, but it is not a replacement for NHL player landing identity, PBP shot events, boxscore totals, or shiftchart TOI.

The sample contains regular-season stats for a skater and does not prove goalie shape, playoff-only shape, or historical availability behavior.

## Top-Level Observed Shape

| Section | Observed Shape | Observed Values / Notes |
| --- | --- | --- |
| `player` | object | Tim Stützle identity/profile/stat snippet |
| `seasonsWithEdgeStats` | array | Five season rows from `20212022` through `20252026` |
| `shotSpeedDetails` | object | Top shot speed, average shot speed, shot-attempt speed buckets |
| `skatingSpeedDetails` | object | Max skating speed and burst speed buckets |
| `skatingDistanceLast10` | array | Ten recent game distance rows |
| `skatingDistanceDetails` | object | Total distance, per-60 distance, max-game/max-period distance |
| `shotLocationDetails` | array | 17 shot-area buckets |
| `shotLocationTotals` | array | 7 grouped shot-location totals |
| `zoneTimeDetails` | object | Offensive/neutral/defensive zone percentages and league averages |
| `zoneStarts` | object | Offensive/neutral/defensive zone start percentages |

## Player And Team Snippets

| Path | Type / Shape | Observed Values | Meaning | DynastyIQ Usage | Must Not Drive |
| --- | --- | --- | --- | --- | --- |
| `player.id` | integer | `8482116` | NHL player id. | Future link to canonical player. | Canonical player creation without landing verification. |
| `player.firstName.default`, `player.lastName.default` | localized object | `Tim`, `Stützle` | Player display name. | Future UI. | Unique identity by itself. |
| `player.birthDate` | date string | `2002-01-15` | Birth date. | Future display or identity cross-check. | Identity mutation without landing. |
| `player.shootsCatches` | string | `L` | Handedness. | Future UI. | Position/eligibility. |
| `player.sweaterNumber` | integer | `18` | Jersey number. | Future UI. | Identity. |
| `player.position` | string | `C` | NHL position code. | Future filtering/display. | Fantasy eligibility. |
| `player.slug` | string | `tim-stützle-8482116` | NHL slug. | Future URL/display context. | Provider id replacement. |
| `player.headshot` | URL string | NHL mugshot URL. | Player image. | Future UI. | Identity. |
| `player.goals`, `assists`, `points`, `gamesPlayed` | integers | `24`, `55`, `79`, `82` | Summary player stat context. | Future profile header. | Persisted season stats while landing/stat endpoints exist. |
| `player.team.commonName.*` | localized object | `Senators`, French value. | Team common name. | Future UI. | Team authority. |
| `player.team.placeNameWithPreposition.*` | localized object | `Ottawa`, French value. | Team place display. | Future UI. | Team identity by itself. |
| `player.team.abbrev` | string | `OTT` | Team abbreviation. | Future UI. | Current team mutation without landing/team endpoint. |
| `player.team.teamLogo.light`, `.dark` | URL strings | NHL logo SVGs. | Team logo assets. | Future UI. | Team identity. |
| `player.team.slug` | string | `ottawa-senators-9` | NHL team slug. | Future URL/display context. | Team id replacement. |

## Edge Availability

| Path | Type / Shape | Observed Values | Meaning | DynastyIQ Usage | Must Not Drive |
| --- | --- | --- | --- | --- | --- |
| `seasonsWithEdgeStats` | array | Five rows. | Seasons where Edge stats exist. | Future UI gating. | Proof that DynastyIQ imported Edge stats. |
| `seasonsWithEdgeStats[].id` | integer | `20212022` through `20252026` | NHL season id. | Future season selector. | Player season stat identity by itself. |
| `seasonsWithEdgeStats[].gameTypes` | array of integers | `[2]` for older rows, `[2, 3]` for 2024-25 and 2025-26. | Available game types for Edge stats. | Future season/game-type filter. | Import eligibility without enum handling. |

## Shot Speed Details

| Path | Type / Shape | Observed Values | Meaning | DynastyIQ Usage | Must Not Drive |
| --- | --- | --- | --- | --- | --- |
| `shotSpeedDetails.topShotSpeed.imperial` | decimal | `90.51` | Top shot speed in mph. | Future Edge UI. | PBP shot event speed without event id. |
| `shotSpeedDetails.topShotSpeed.metric` | decimal | `145.6617` | Top shot speed in km/h. | Future Edge UI. | Unit conversion source by itself. |
| `shotSpeedDetails.topShotSpeed.overlay` | object | Game/date/teams/period/time context. | Context for top shot speed. | Future UI. | Game import or PBP event identity. |
| `shotSpeedDetails.avgShotSpeed.imperial` | decimal | `60.6955` | Average shot speed in mph. | Future Edge UI. | Shot quality model by itself. |
| `shotSpeedDetails.avgShotSpeed.metric` | decimal | `97.6799` | Average shot speed in km/h. | Future Edge UI. | Unit conversion source by itself. |
| `shotSpeedDetails.shotAttemptsOver100` | integer | `0` | Attempts over 100 mph. | Future Edge UI. | Shot-on-goal total. |
| `shotSpeedDetails.shotAttempts90To100` | integer | `2` | Attempts from 90 to 100 mph. | Future Edge UI. | Shot-on-goal total. |
| `shotSpeedDetails.shotAttempts80To90` | integer | `22` | Attempts from 80 to 90 mph. | Future Edge UI. | Shot-on-goal total. |
| `shotSpeedDetails.shotAttempts70To80` | integer | `72` | Attempts from 70 to 80 mph. | Future Edge UI. | Shot-on-goal total. |

## Skating Speed Details

| Path | Type / Shape | Observed Values | Meaning | DynastyIQ Usage | Must Not Drive |
| --- | --- | --- | --- | --- | --- |
| `skatingSpeedDetails.maxSkatingSpeed.imperial` | decimal | `23.663` | Max skating speed in mph. | Future Edge UI. | Player speed ranking without context. |
| `skatingSpeedDetails.maxSkatingSpeed.metric` | decimal | `38.082` | Max skating speed in km/h. | Future Edge UI. | Unit conversion source by itself. |
| `skatingSpeedDetails.maxSkatingSpeed.overlay` | object | Game/date/teams/period/time context. | Context for max speed. | Future UI. | PBP event identity. |
| `skatingSpeedDetails.burstsOver22` | integer | `33` | Bursts over 22 mph. | Future Edge UI. | Shift count. |
| `skatingSpeedDetails.bursts20To22` | integer | `347` | Bursts from 20 to 22 mph. | Future Edge UI. | Shift count. |
| `skatingSpeedDetails.bursts18To20` | integer | `811` | Bursts from 18 to 20 mph. | Future Edge UI. | Shift count. |

## Skating Distance Last 10

`skatingDistanceLast10` contains ten recent game rows in the sample.

| Path | Type / Shape | Observed Values | Meaning | DynastyIQ Usage | Must Not Drive |
| --- | --- | --- | --- | --- | --- |
| `skatingDistanceLast10[].gameCenterLink` | string | `/gamecenter/ott-vs-car/2025/04/17/2024021309` | NHL gamecenter path. | Future UI link. | Game id parsing without verification. |
| `skatingDistanceLast10[].gameDate` | date string | `2025-04-17` through `2025-03-30`. | Game date. | Future display. | Import scheduling. |
| `skatingDistanceLast10[].playerOnHomeTeam` | boolean | `true`, `false`. | Whether player team was home. | Future display. | Team assignment by itself. |
| `skatingDistanceLast10[].distanceSkated.imperial` | decimal | `2.6269` to `3.9464`. | Distance skated in miles. | Future Edge UI. | TOI or shiftchart distance. |
| `skatingDistanceLast10[].distanceSkated.metric` | decimal | `4.2275` to `6.3511`. | Distance skated in kilometers. | Future Edge UI. | Unit conversion source by itself. |
| `skatingDistanceLast10[].toi` | integer seconds | `1000` to `1422`. | Time on ice for the game. | Future Edge UI context. | Official summary TOI while boxscore/shift validation exists. |
| `skatingDistanceLast10[].homeTeam`, `.awayTeam` | team object | Team display names, abbrev, logos, slug. | Game matchup context. | Future UI. | Team identity mutation. |

## Skating Distance Details

| Path | Type / Shape | Observed Values | Meaning | DynastyIQ Usage | Must Not Drive |
| --- | --- | --- | --- | --- | --- |
| `skatingDistanceDetails.distanceTotal.imperial` | decimal | `273.1901` | Total distance in miles. | Future Edge UI. | TOI calculation. |
| `skatingDistanceDetails.distanceTotal.metric` | decimal | `439.6354` | Total distance in kilometers. | Future Edge UI. | Unit conversion source by itself. |
| `skatingDistanceDetails.distancePer60.imperial` | decimal | `10.0891` | Distance per 60 minutes in miles. | Future Edge UI. | Fantasy points by itself. |
| `skatingDistanceDetails.distancePer60.metric` | decimal | `16.236` | Distance per 60 minutes in kilometers. | Future Edge UI. | Unit conversion source by itself. |
| `skatingDistanceDetails.distanceMaxGame.imperial` | decimal | `4.2688` | Max single-game distance in miles. | Future Edge UI. | Shiftchart replacement. |
| `skatingDistanceDetails.distanceMaxGame.metric` | decimal | `6.8696` | Max single-game distance in kilometers. | Future Edge UI. | Shiftchart replacement. |
| `skatingDistanceDetails.distanceMaxGame.overlay` | object | Game context. | Context for max-game distance. | Future UI. | Game validation. |
| `skatingDistanceDetails.distanceMaxPeriod.imperial` | decimal | `1.7259` | Max single-period distance in miles. | Future Edge UI. | Shiftchart replacement. |
| `skatingDistanceDetails.distanceMaxPeriod.metric` | decimal | `2.7775` | Max single-period distance in kilometers. | Future Edge UI. | Shiftchart replacement. |
| `skatingDistanceDetails.distanceMaxPeriod.overlay` | object | Game context. | Context for max-period distance. | Future UI. | Game validation. |

## Shot Location Details

`shotLocationDetails` contains 17 area buckets in the sample.

Observed fields:

- `area`: display bucket such as `Low Slot`, `Crease`, `L Circle`, `R Point`, `Offensive Neutral Zone`.
- `sog`: shots on goal count for that area.
- `goals`: goal count for that area.
- `shootingPctg`: shooting percentage for that area.

Observed notable values:

- `Low Slot`: `59` SOG, `11` goals, `0.1864`.
- `Crease`: `5` SOG, `4` goals, `0.8`.
- `L Circle`: `18` SOG, `1` goal, `0.0556`.

These are derived Edge shot-location summaries. They should not be merged into PBP shot geometry without a dedicated mapping and source authority decision.

## Shot Location Totals

`shotLocationTotals` contains 7 grouped location rows.

| Path | Type / Shape | Observed Values | Meaning | DynastyIQ Usage | Must Not Drive |
| --- | --- | --- | --- | --- | --- |
| `shotLocationTotals[].locationCode` | string | `all`, `high`, `high+long`, `high+mid`, `high+mid+long`, `long`, `mid` | Provider location grouping. | Future Edge UI. | Shot geometry taxonomy without mapping. |
| `shotLocationTotals[].sog` | integer | `11` to `162`. | Grouped SOG total. | Future Edge UI. | Boxscore/PBP SOG validation. |
| `shotLocationTotals[].goals` | integer | `0` to `24`. | Grouped goal total. | Future Edge UI. | PBP goal import. |
| `shotLocationTotals[].shootingPctg` | decimal | `0` to `0.2344`. | Grouped shooting percentage. | Future Edge UI. | Scoring projection by itself. |

## Zone Time And Zone Starts

| Path | Type / Shape | Observed Values | Meaning | DynastyIQ Usage | Must Not Drive |
| --- | --- | --- | --- | --- | --- |
| `zoneTimeDetails.offensiveZonePctg` | decimal | `0.47613027` | Player offensive-zone time percentage/share. | Future Edge UI. | Unit possession model by itself. |
| `zoneTimeDetails.offensiveZoneLeagueAvg` | decimal | `0.4234038` | League average offensive-zone percentage/share. | Future Edge UI comparison. | League factor without sample-set verification. |
| `zoneTimeDetails.neutralZonePctg` | decimal | `0.17063416` | Player neutral-zone time percentage/share. | Future Edge UI. | Unit possession model by itself. |
| `zoneTimeDetails.neutralZoneLeagueAvg` | decimal | `0.17830407` | League average neutral-zone percentage/share. | Future Edge UI comparison. | League factor without sample-set verification. |
| `zoneTimeDetails.defensiveZonePctg` | decimal | `0.35323556` | Player defensive-zone time percentage/share. | Future Edge UI. | Defensive valuation by itself. |
| `zoneTimeDetails.defensiveZoneLeagueAvg` | decimal | `0.39829213` | League average defensive-zone percentage/share. | Future Edge UI comparison. | League factor without sample-set verification. |
| `zoneStarts.offensiveZoneStarts` | decimal | `0.4549` | Offensive-zone start percentage/share. | Future Edge UI. | Faceoff deployment model without endpoint contract. |
| `zoneStarts.neutralZoneStarts` | decimal | `0.3072` | Neutral-zone start percentage/share. | Future Edge UI. | Faceoff deployment model without endpoint contract. |
| `zoneStarts.defensiveZoneStarts` | decimal | `0.2379` | Defensive-zone start percentage/share. | Future Edge UI. | Faceoff deployment model without endpoint contract. |

## Overlay Shape

Several max values include an `overlay` object.

| Path | Type / Shape | Observed Values | Meaning | DynastyIQ Usage | Must Not Drive |
| --- | --- | --- | --- | --- | --- |
| `overlay.player.firstName.default`, `lastName.default` | localized object | `Tim`, `Stützle`. | Display name. | Future UI. | Identity. |
| `overlay.gameDate` | date string | `2025-02-01`, `2024-12-19`, etc. | Game date for max event/value. | Future UI. | Import scheduling. |
| `overlay.awayTeam`, `overlay.homeTeam` | object | Abbrev and score. | Game matchup context. | Future UI. | Official boxscore. |
| `overlay.gameOutcome.lastPeriodType` | string | `REG`, `OT`. | Game outcome period type. | Future UI. | Validation status. |
| `overlay.gameOutcome.otPeriods` | integer, optional | `1`. | Overtime count. | Future UI. | Shiftchart OT trimming by itself. |
| `overlay.periodDescriptor` | object | `maxRegulationPeriods`, `number`, `periodType`. | Period context. | Future UI. | PBP event identity. |
| `overlay.timeInPeriod` | string, optional | `03:54`, `00:15`. | Time in period. | Future UI. | PBP event identity. |
| `overlay.gameType` | integer | `2`. | Game type. | Future filters. | Import eligibility without enum handling. |

## Opportunity

- This endpoint can become a player comparison surface that DynastyIQ cannot currently build from boxscore/PBP alone: speed profile, shot-speed profile, distance workload, zone usage, and shot-location tendency.
- `shotSpeedDetails` and `skatingSpeedDetails` can produce scouting-style trait cards, such as "shot power", "top-end speed", and "burst frequency".
- `skatingDistanceLast10` is a strong workload trend input. Paired with TOI, it can show whether a player is covering more ice per minute or simply playing more.
- `skatingDistanceDetails.distancePer60` is already normalized for usage, making it useful for comparing players with different TOI loads.
- `shotLocationDetails` and `shotLocationTotals` can reveal role and shot-quality patterns: low-slot finisher, point shooter, perimeter shooter, net-front scorer.
- `zoneTimeDetails` includes player values and league averages in the same payload, which enables immediate above/below-average context without a separate league baseline import.
- `zoneStarts` can support deployment context for player cards and fantasy analysis, especially when explaining why production is sustainable or usage-driven.
- Overlay context can make max-speed, max-shot, and max-distance moments inspectable rather than anonymous numbers.

## Parser Contract

- Do not add this endpoint to production import code until the exact route, required parameters, and response stability are verified.
- Treat this response as a player-specific Edge comparison payload, not as canonical player identity authority.
- Validate `player.id` through player landing or an existing NHL identity before linking to canonical players.
- Keep imperial and metric fields as separate provider values; do not regenerate one from the other unless a later contract explicitly chooses one canonical unit.
- Do not use Edge `toi` as official game-summary TOI while boxscore/PBP/shift validation contracts exist.
- Do not use Edge shot-location fields as PBP shot geometry without a dedicated mapping.
- Treat league-average zone-time values as context scoped to the endpoint request; do not persist as global league factors without verifying request parameters.

## Expected Normalized Output

No current normalized output. Future work could define:

- Player profile Edge comparison panels.
- Season/game-type Edge availability selectors.
- Shot-speed and skating-speed distributions.
- Recent-game skating-distance charts.
- Shot-location heat tables.
- Zone-time and zone-start comparison widgets.

## Open Verification Questions

- What is the exact endpoint path and parameter set for this response?
- Are all sections present for defensemen, wingers, centers, rookies, and players with partial seasons?
- Does the endpoint support playoff-only requests, and does the shape change for `gameType = 3`?
- Are zone-time and zone-start values percentages of total tracked time, shares of events, or another NHL Edge-specific denominator?
- Are shot-location bucket names stable across seasons?
