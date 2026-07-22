# NHL Edge Skater Detail Response

## Endpoint

The exact endpoint route is not currently configured in DynastyIQ.

Current DynastyIQ consumers:

- None.

## Sample Source

- `docs/api_responses/samples/nhlEdgeSkaterDetail.txt`

The sample is a browser/object-inspector dump for Tim Stützle (`player.id = 8482116`). It is not literal JSON text, but it preserves field names, nested shapes, arrays, and observed values.

## Purpose

This response exposes player-specific NHL Edge detail metrics for a skater. It combines a compact player profile, Edge availability by season/game type, maximum shot speed, maximum skating speed, burst count over 20 mph, total distance skated, max-game distance, shot-on-goal location summaries, shot-area percentiles, and zone-time percentages.

## Observations For DynastyIQ

This is a player-specific Edge detail payload. Compared with `edge-skater-comparison.md`, it is more compact and more percentile/league-average oriented.

The sample does not include recent-game distance rows, distance per 60, max-period distance, shot-speed bucket counts, multi-bucket skating bursts, zone starts, or per-area goals/shooting percentage. It is useful for profile cards and percentile comparisons, but it is not a replacement for NHL player landing identity, PBP shot events, boxscore totals, or shiftchart TOI.

The sample contains regular-season stats for a skater and does not prove goalie shape, playoff-only shape, or historical availability behavior.

## Top-Level Observed Shape

| Section | Observed Shape | Observed Values / Notes |
| --- | --- | --- |
| `player` | object | Tim Stützle identity/profile/stat snippet |
| `seasonsWithEdgeStats` | array | Five season rows from `20212022` through `20252026` |
| `topShotSpeed` | object | Maximum shot speed with percentile, league average, and overlay context |
| `skatingSpeed` | object | Maximum skating speed under `speedMax` |
| `burstsOver20` | object | Count, percentile, and league average for bursts over 20 mph |
| `totalDistanceSkated` | object | Total distance with percentile and league average |
| `distanceMaxGame` | object | Max single-game distance with percentile, league average, and overlay context |
| `sogSummary` | array | Four grouped shot-location summary rows |
| `sogDetails` | array | 17 shot-area rows with shots and percentile |
| `zoneTimeDetails` | object | Offensive, even-strength offensive, neutral, and defensive zone percentages |

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

## Edge Availability

| Path | Type / Shape | Observed Values | Meaning | DynastyIQ Usage | Must Not Drive |
| --- | --- | --- | --- | --- | --- |
| `seasonsWithEdgeStats` | array | Five rows. | Seasons where Edge stats exist. | Future UI gating. | Proof that DynastyIQ imported Edge stats. |
| `seasonsWithEdgeStats[].id` | integer | `20212022` through `20252026` | NHL season id. | Future season selector. | Player season stat identity by itself. |
| `seasonsWithEdgeStats[].gameTypes` | array of integers | `[2]` for older rows, `[2, 3]` for 2024-25 and 2025-26. | Available game types for Edge stats. | Future season/game-type filter. | Import eligibility without enum handling. |

## Shot Speed

| Path | Type / Shape | Observed Values | Meaning | DynastyIQ Usage | Must Not Drive |
| --- | --- | --- | --- | --- | --- |
| `topShotSpeed.imperial` | decimal | `90.51` | Top shot speed in mph. | Future Edge UI. | PBP shot event speed without event id. |
| `topShotSpeed.metric` | decimal | `145.6617` | Top shot speed in km/h. | Future Edge UI. | Unit conversion source by itself. |
| `topShotSpeed.percentile` | decimal | `0.8165` | Player percentile for top shot speed. | Future rank/context UI. | Global ranking without request-scope verification. |
| `topShotSpeed.leagueAvg.imperial` | decimal | `83.9219` | League-average top shot speed in mph. | Future Edge comparison UI. | Global baseline without request-scope verification. |
| `topShotSpeed.leagueAvg.metric` | decimal | `135.0592` | League-average top shot speed in km/h. | Future Edge comparison UI. | Unit conversion source by itself. |
| `topShotSpeed.overlay` | object | `2025-02-01`, MIN `0` at OTT `6`, period 2, `03:54`. | Context for the top shot-speed value. | Future UI. | Game import or PBP event identity. |

## Skating Speed And Bursts

| Path | Type / Shape | Observed Values | Meaning | DynastyIQ Usage | Must Not Drive |
| --- | --- | --- | --- | --- | --- |
| `skatingSpeed.speedMax.imperial` | decimal | `23.663` | Max skating speed in mph. | Future Edge UI. | Player speed ranking without context. |
| `skatingSpeed.speedMax.metric` | decimal | `38.082` | Max skating speed in km/h. | Future Edge UI. | Unit conversion source by itself. |
| `skatingSpeed.speedMax.percentile` | decimal | `0.9733` | Player percentile for max skating speed. | Future rank/context UI. | Global ranking without request-scope verification. |
| `skatingSpeed.speedMax.leagueAvg.imperial` | decimal | `22.1829` | League-average max speed in mph. | Future Edge comparison UI. | Global baseline without request-scope verification. |
| `skatingSpeed.speedMax.leagueAvg.metric` | decimal | `35.6998` | League-average max speed in km/h. | Future Edge comparison UI. | Unit conversion source by itself. |
| `skatingSpeed.speedMax.overlay` | object | `2024-12-19`, OTT `3` at CGY `2`, OT period 4, `00:15`. | Context for the max-speed value. | Future UI. | PBP event identity. |
| `burstsOver20.value` | integer | `380` | Count of bursts over 20 mph. | Future Edge UI. | Shift count. |
| `burstsOver20.percentile` | decimal | `0.995` | Player percentile for bursts over 20 mph. | Future rank/context UI. | Global ranking without request-scope verification. |
| `burstsOver20.leagueAvg.value` | decimal | `76.4559` | League-average bursts over 20 mph. | Future Edge comparison UI. | Global baseline without request-scope verification. |

## Skating Distance

| Path | Type / Shape | Observed Values | Meaning | DynastyIQ Usage | Must Not Drive |
| --- | --- | --- | --- | --- | --- |
| `totalDistanceSkated.imperial` | decimal | `273.1901` | Total distance in miles. | Future Edge UI. | TOI calculation. |
| `totalDistanceSkated.metric` | decimal | `439.6354` | Total distance in kilometers. | Future Edge UI. | Unit conversion source by itself. |
| `totalDistanceSkated.percentile` | decimal | `0.99` | Player percentile for total distance. | Future workload comparison UI. | Global ranking without request-scope verification. |
| `totalDistanceSkated.leagueAvg.imperial` | decimal | `127.079` | League-average total distance in miles. | Future Edge comparison UI. | Global baseline without request-scope verification. |
| `totalDistanceSkated.leagueAvg.metric` | decimal | `204.5038` | League-average total distance in kilometers. | Future Edge comparison UI. | Unit conversion source by itself. |
| `distanceMaxGame.imperial` | decimal | `4.2688` | Max single-game distance in miles. | Future Edge UI. | Shiftchart replacement. |
| `distanceMaxGame.metric` | decimal | `6.8696` | Max single-game distance in kilometers. | Future Edge UI. | Unit conversion source by itself. |
| `distanceMaxGame.percentile` | decimal | `0.985` | Player percentile for max-game distance. | Future workload comparison UI. | Global ranking without request-scope verification. |
| `distanceMaxGame.leagueAvg.imperial` | decimal | `2.9007` | League-average max-game distance in miles. | Future Edge comparison UI. | Global baseline without request-scope verification. |
| `distanceMaxGame.leagueAvg.metric` | decimal | `4.668` | League-average max-game distance in kilometers. | Future Edge comparison UI. | Unit conversion source by itself. |
| `distanceMaxGame.overlay` | object | `2024-11-14`, PHI `5` at OTT `4`, period 3. | Context for the max-game distance value. | Future UI. | Game validation. |

## Shot-On-Goal Summary

`sogSummary` contains four grouped location rows in the sample.

| Path | Type / Shape | Observed Values | Meaning | DynastyIQ Usage | Must Not Drive |
| --- | --- | --- | --- | --- | --- |
| `sogSummary[].locationCode` | string | `all`, `high`, `long`, `mid` | Provider location grouping. | Future Edge UI. | Shot geometry taxonomy without mapping. |
| `sogSummary[].shots` | integer | `11` to `162`. | Grouped shot total. | Future Edge UI. | Boxscore/PBP SOG validation. |
| `sogSummary[].shotsPercentile` | decimal | `0.665` to `0.8917`. | Player percentile for grouped shot total. | Future rank/context UI. | Global ranking without request-scope verification. |
| `sogSummary[].shotsLeagueAvg` | decimal | `8.7471` to `88.7072`. | League-average grouped shot total. | Future Edge comparison UI. | Global baseline without request-scope verification. |
| `sogSummary[].goals` | integer | `0` to `24`. | Grouped goal total. | Future Edge UI. | PBP goal import. |
| `sogSummary[].goalsPercentile` | decimal | `0` to `0.8983`. | Player percentile for grouped goal total. | Future rank/context UI. | Global ranking without request-scope verification. |
| `sogSummary[].goalsLeagueAvg` | decimal | `0.3328` to `11.2479`. | League-average grouped goal total. | Future Edge comparison UI. | Global baseline without request-scope verification. |
| `sogSummary[].shootingPctg` | decimal | `0` to `0.2344`. | Grouped shooting percentage. | Future Edge UI. | Scoring projection by itself. |
| `sogSummary[].shootingPctgPercentile` | decimal | `0` to `0.7048`. | Player percentile for grouped shooting percentage. | Future rank/context UI. | Global ranking without request-scope verification. |
| `sogSummary[].shootingPctgLeagueAvg` | decimal | `0.038` to `0.2015`. | League-average grouped shooting percentage. | Future Edge comparison UI. | Global baseline without request-scope verification. |

Observed notable rows:

- `all`: `162` shots, `24` goals, `0.1481` shooting percentage.
- `high`: `64` shots, `15` goals, `0.2344` shooting percentage.
- `long`: `11` shots, `0` goals, `0` shooting percentage.
- `mid`: `46` shots, `4` goals, `0.087` shooting percentage.

## Shot-On-Goal Details

`sogDetails` contains 17 area buckets in the sample.

| Path | Type / Shape | Observed Values | Meaning | DynastyIQ Usage | Must Not Drive |
| --- | --- | --- | --- | --- | --- |
| `sogDetails[].area` | string | `Low Slot`, `Crease`, `L Circle`, `R Point`, `Offensive Neutral Zone`, etc. | Provider shot-area bucket. | Future Edge shot map UI. | Shot geometry taxonomy without mapping. |
| `sogDetails[].shots` | integer | `0` to `59`. | Shot count for the area. | Future Edge UI. | Boxscore/PBP SOG validation. |
| `sogDetails[].shotsPercentile` | decimal | `0` to `0.99`. | Player percentile for shots from the area. | Future rank/context UI. | Global ranking without request-scope verification. |

Observed notable values:

- `Low Slot`: `59` shots, `0.9083` percentile.
- `L Net Side`: `15` shots, `0.99` percentile.
- `High Slot`: `15` shots, `0.78` percentile.
- `Center Point`: `6` shots, `0.8117` percentile.

These are derived Edge shot-location summaries. They should not be merged into PBP shot geometry without a dedicated mapping and source authority decision.

## Zone Time

| Path | Type / Shape | Observed Values | Meaning | DynastyIQ Usage | Must Not Drive |
| --- | --- | --- | --- | --- | --- |
| `zoneTimeDetails.offensiveZonePctg` | decimal | `0.47613027` | Player offensive-zone time percentage/share. | Future Edge UI. | Unit possession model by itself. |
| `zoneTimeDetails.offensiveZonePercentile` | decimal | `0.9703703703704` | Player percentile for offensive-zone time. | Future rank/context UI. | Global ranking without request-scope verification. |
| `zoneTimeDetails.offensiveZoneLeagueAvg` | decimal | `0.4234038` | League-average offensive-zone percentage/share. | Future Edge comparison UI. | League factor without sample-set verification. |
| `zoneTimeDetails.offensiveZoneEvPctg` | decimal | `0.44667263` | Player even-strength offensive-zone time percentage/share. | Future Edge UI. | Unit possession model by itself. |
| `zoneTimeDetails.offensiveZoneEvPercentile` | decimal | `0.9382716049383` | Player percentile for even-strength offensive-zone time. | Future rank/context UI. | Global ranking without request-scope verification. |
| `zoneTimeDetails.offensiveZoneEvLeagueAvg` | decimal | `0.41293798` | League-average even-strength offensive-zone percentage/share. | Future Edge comparison UI. | League factor without sample-set verification. |
| `zoneTimeDetails.neutralZonePctg` | decimal | `0.17063416` | Player neutral-zone time percentage/share. | Future Edge UI. | Unit possession model by itself. |
| `zoneTimeDetails.neutralZonePercentile` | decimal | `0.0814814814815` | Player percentile for neutral-zone time. | Future rank/context UI. | Global ranking without request-scope verification. |
| `zoneTimeDetails.neutralZoneLeagueAvg` | decimal | `0.17830407` | League-average neutral-zone percentage/share. | Future Edge comparison UI. | League factor without sample-set verification. |
| `zoneTimeDetails.defensiveZonePctg` | decimal | `0.35323556` | Player defensive-zone time percentage/share. | Future Edge UI. | Defensive valuation by itself. |
| `zoneTimeDetails.defensiveZonePercentile` | decimal | `0.9555555555556` | Player percentile for defensive-zone time. | Future rank/context UI. | Global ranking without request-scope verification. |
| `zoneTimeDetails.defensiveZoneLeagueAvg` | decimal | `0.39829213` | League-average defensive-zone percentage/share. | Future Edge comparison UI. | Defensive valuation by itself. |

## Overlay Shape

Several max values include an `overlay` object.

| Path | Type / Shape | Observed Values | Meaning | DynastyIQ Usage | Must Not Drive |
| --- | --- | --- | --- | --- | --- |
| `overlay.player.firstName.default`, `lastName.default` | localized object | `Tim`, `Stützle`. | Display name. | Future UI. | Identity. |
| `overlay.gameDate` | date string | `2025-02-01`, `2024-12-19`, `2024-11-14`. | Game date for max event/value. | Future UI. | Import scheduling. |
| `overlay.awayTeam`, `overlay.homeTeam` | object | Abbrev and score. | Game matchup context. | Future UI. | Official boxscore. |
| `overlay.gameOutcome.lastPeriodType` | string | `REG`, `OT`. | Game outcome period type. | Future UI. | Validation status. |
| `overlay.gameOutcome.otPeriods` | integer, optional | `1`. | Overtime count. | Future UI. | Shiftchart OT trimming by itself. |
| `overlay.periodDescriptor` | object | `maxRegulationPeriods`, `number`, `periodType`. | Period context. | Future UI. | PBP event identity. |
| `overlay.timeInPeriod` | string, optional | `03:54`, `00:15`. | Time in period. | Future UI. | PBP event identity. |
| `overlay.gameType` | integer | `2`. | Game type. | Future filters. | Import eligibility without enum handling. |

## Difference From Edge Skater Comparison

- `edge-skater-detail` uses direct top-level sections like `topShotSpeed`, `burstsOver20`, `totalDistanceSkated`, and `sogSummary`.
- `edge-skater-comparison` uses richer grouped sections like `shotSpeedDetails`, `skatingSpeedDetails`, `skatingDistanceDetails`, `shotLocationTotals`, and `zoneStarts`.
- Detail includes percentiles and league averages across most sections.
- Detail does not include last-10 distance, distance per 60, max-period distance, shot-speed attempt buckets, burst buckets above 18/20/22 mph, zone starts, or per-area goals/shooting percentage in the observed sample.
- Detail `sogDetails` exposes `shots` and `shotsPercentile`; comparison `shotLocationDetails` exposes `sog`, `goals`, and `shootingPctg`.

## Opportunity

- This endpoint is well suited for compact player profile cards because it supplies player value, percentile, and league average in one payload.
- Percentiles can power quick "what stands out" badges for speed, shot power, workload, dangerous shot volume, and zone usage.
- `burstsOver20` and `skatingSpeed.speedMax` can identify players whose skating profile is materially different from their fantasy production.
- `sogSummary` can support role classification, such as high-danger shooter, perimeter shooter, or low-volume finisher.
- `sogDetails` can drive a simple shot-location tendency map without needing full PBP coordinates.
- `zoneTimeDetails.offensiveZoneEvPctg` can separate all-situation usage from even-strength territorial profile.
- League-average values make this endpoint useful for inline context even before DynastyIQ has its own Edge league baseline tables.

## Parser Contract

- Do not add this endpoint to production import code until the exact route, required parameters, and response stability are verified.
- Treat this response as a player-specific Edge detail payload, not as canonical player identity authority.
- Validate `player.id` through player landing or an existing NHL identity before linking to canonical players.
- Keep imperial and metric fields as separate provider values; do not regenerate one from the other unless a later contract explicitly chooses one canonical unit.
- Do not use Edge distance, zone time, or shot-location fields as official boxscore/PBP/shiftchart validation values.
- Treat percentile and league-average values as request-scoped provider context until endpoint parameters and denominator rules are verified.
- Do not assume every player has every Edge detail section; missing or null sections should be treated as unavailable provider data.

## Expected Normalized Output

No current normalized output. Future work could define:

- Compact player Edge profile cards.
- Percentile-based scouting badges.
- League-average comparison chips.
- Shot-location tendency summaries.
- Offensive-zone and even-strength offensive-zone usage panels.

## Open Verification Questions

- What is the exact endpoint path and parameter set for this response?
- Are all sections present for defensemen, wingers, centers, rookies, and players with partial seasons?
- Does the endpoint support playoff-only requests, and does the shape change for `gameType = 3`?
- Are percentile denominators based on all skaters, position groups, qualifiers, season/game type, or another provider filter?
- Are league-average values calculated from the same population as percentiles?
- Are `sogSummary.locationCode` values stable across seasons?
