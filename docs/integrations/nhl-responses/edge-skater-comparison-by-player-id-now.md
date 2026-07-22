# NHL Edge Skater Comparison by Player Id Now Response

## Endpoint

| Field | Value |
| --- | --- |
| Method | `GET` |
| Path | `/edge/skater-comparison/{player-id}/now` |
| Source Description | See the NHL API reference for provider-authored description. This local row maps the endpoint to DynastyIQ generated files. |
| Example cURL | `curl -X GET "https://api-web.nhle.com/v1/edge/skater-comparison/8482116/now"` |

Current DynastyIQ consumers:

- Possible overlap with player identity, NHL roster status, or player-page workflows; exact consumers were not verified by this generated document.

## Sample Source

- `docs/api_responses/samples/nhlApi-edge-skater-comparison-by-player-id-now.txt`

The sample was generated from the runnable cURL example recorded in `docs/integrations/nhl-responses/nhl_api_index.md`.

## Purpose

Document the observed NHL API payload shape and identify DynastyIQ opportunities before implementation treats any field as authoritative.

## Observations For DynastyIQ

- Top-level observed shape: player, seasonsWithEdgeStats, shotSpeedDetails, skatingSpeedDetails, skatingDistanceLast10, skatingDistanceDetails, shotLocationDetails, shotLocationTotals, zoneTimeDetails, zoneStarts
- This document is generated from a real raw payload and still needs human interpretation before fields become product or import authority.
- Preserve provider field names in raw-response docs; normalize only inside implementation contracts.

## Top-Level Observed Shape

| Section | Observed Shape | Observed Values / Notes |
| --- | --- | --- |
| `player` | object{14} | Keys: id, firstName, lastName, birthDate, shootsCatches, sweaterNumber, position, slug, headshot, goals |
| `seasonsWithEdgeStats` | array[5] | 5 row(s). |
| `shotSpeedDetails` | object{6} | Keys: topShotSpeed, avgShotSpeed, shotAttemptsOver100, shotAttempts90To100, shotAttempts80To90, shotAttempts70To80 |
| `skatingSpeedDetails` | object{4} | Keys: maxSkatingSpeed, burstsOver22, bursts20To22, bursts18To20 |
| `skatingDistanceLast10` | array[10] | 10 row(s). |
| `skatingDistanceDetails` | object{4} | Keys: distanceTotal, distancePer60, distanceMaxGame, distanceMaxPeriod |
| `shotLocationDetails` | array[17] | 17 row(s). |
| `shotLocationTotals` | array[7] | 7 row(s). |
| `zoneTimeDetails` | object{6} | Keys: offensiveZonePctg, offensiveZoneLeagueAvg, neutralZonePctg, neutralZoneLeagueAvg, defensiveZonePctg, defensiveZoneLeagueAvg |
| `zoneStarts` | object{3} | Keys: offensiveZoneStarts, neutralZoneStarts, defensiveZoneStarts |

## Observed Field Inventory

| Path | Type / Shape | Observed Values | Meaning | DynastyIQ Usage | Must Not Drive |
| --- | --- | --- | --- | --- | --- |
| `$.player.id` | integer | 8482116 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.player.firstName.default` | string | Tim | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.player.lastName.default` | string | Stützle | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.player.birthDate` | string | 2002-01-15 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.player.shootsCatches` | string | L | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.player.sweaterNumber` | integer | 18 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.player.position` | string | C | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.player.slug` | string | tim-stützle-8482116 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.player.headshot` | string | https://assets.nhle.com/mugs/nhl/20252026/OTT/8482116.png | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.player.goals` | integer | 34 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.player.assists` | integer | 49 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.player.points` | integer | 83 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.player.gamesPlayed` | integer | 80 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.player.team.commonName.default` | string | Senators | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.player.team.commonName.fr` | string | Sénateurs | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.player.team.placeNameWithPreposition.default` | string | Ottawa | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.player.team.placeNameWithPreposition.fr` | string | d'Ottawa | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.player.team.abbrev` | string | OTT | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.player.team.teamLogo.light` | string | https://assets.nhle.com/logos/nhl/svg/OTT_light.svg | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.player.team.teamLogo.dark` | string | https://assets.nhle.com/logos/nhl/svg/OTT_dark.svg | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.player.team.slug` | string | ottawa-senators-9 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.seasonsWithEdgeStats[].id` | integer | 20212022 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.seasonsWithEdgeStats[].gameTypes[]` | array[1] | 1 row(s). | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.shotSpeedDetails.topShotSpeed.imperial` | decimal | 90.1 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.shotSpeedDetails.topShotSpeed.metric` | decimal | 145.0019 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.shotSpeedDetails.topShotSpeed.overlay.player.firstName.default` | string | Tim | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.shotSpeedDetails.topShotSpeed.overlay.player.lastName.default` | string | Stützle | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.shotSpeedDetails.topShotSpeed.overlay.gameDate` | string | 2025-12-02 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.shotSpeedDetails.topShotSpeed.overlay.awayTeam.abbrev` | string | OTT | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.shotSpeedDetails.topShotSpeed.overlay.awayTeam.score` | integer | 5 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.shotSpeedDetails.topShotSpeed.overlay.homeTeam.abbrev` | string | MTL | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.shotSpeedDetails.topShotSpeed.overlay.homeTeam.score` | integer | 2 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.shotSpeedDetails.topShotSpeed.overlay.gameOutcome.lastPeriodType` | string | REG | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.shotSpeedDetails.topShotSpeed.overlay.periodDescriptor.maxRegulationPeriods` | integer | 3 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.shotSpeedDetails.topShotSpeed.overlay.periodDescriptor.number` | integer | 1 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.shotSpeedDetails.topShotSpeed.overlay.periodDescriptor.periodType` | string | REG | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.shotSpeedDetails.topShotSpeed.overlay.timeInPeriod` | string | 11:50 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.shotSpeedDetails.topShotSpeed.overlay.gameType` | integer | 2 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.shotSpeedDetails.avgShotSpeed.imperial` | decimal | 55.5203 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.shotSpeedDetails.avgShotSpeed.metric` | decimal | 89.3513 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.shotSpeedDetails.shotAttemptsOver100` | integer | 0 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.shotSpeedDetails.shotAttempts90To100` | integer | 1 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.shotSpeedDetails.shotAttempts80To90` | integer | 24 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.shotSpeedDetails.shotAttempts70To80` | integer | 100 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingSpeedDetails.maxSkatingSpeed.imperial` | decimal | 23.5373 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingSpeedDetails.maxSkatingSpeed.metric` | decimal | 37.8795 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingSpeedDetails.maxSkatingSpeed.overlay.player.firstName.default` | string | Tim | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingSpeedDetails.maxSkatingSpeed.overlay.player.lastName.default` | string | Stützle | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingSpeedDetails.maxSkatingSpeed.overlay.gameDate` | string | 2026-01-28 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingSpeedDetails.maxSkatingSpeed.overlay.awayTeam.abbrev` | string | COL | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingSpeedDetails.maxSkatingSpeed.overlay.awayTeam.score` | integer | 2 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingSpeedDetails.maxSkatingSpeed.overlay.homeTeam.abbrev` | string | OTT | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingSpeedDetails.maxSkatingSpeed.overlay.homeTeam.score` | integer | 5 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingSpeedDetails.maxSkatingSpeed.overlay.gameOutcome.lastPeriodType` | string | REG | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingSpeedDetails.maxSkatingSpeed.overlay.periodDescriptor.maxRegulationPeriods` | integer | 3 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingSpeedDetails.maxSkatingSpeed.overlay.periodDescriptor.number` | integer | 2 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingSpeedDetails.maxSkatingSpeed.overlay.periodDescriptor.periodType` | string | REG | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingSpeedDetails.maxSkatingSpeed.overlay.timeInPeriod` | string | 02:13 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingSpeedDetails.maxSkatingSpeed.overlay.gameType` | integer | 2 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingSpeedDetails.burstsOver22` | integer | 46 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingSpeedDetails.bursts20To22` | integer | 318 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingSpeedDetails.bursts18To20` | integer | 756 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceLast10[].gameCenterLink` | string | /gamecenter/nyi-vs-ott/2026/04/11/2025021262 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceLast10[].gameDate` | string | 2026-04-11 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceLast10[].playerOnHomeTeam` | boolean | false | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceLast10[].distanceSkated.imperial` | decimal | 3.2175 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceLast10[].distanceSkated.metric` | decimal | 5.1781 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceLast10[].toi` | integer | 1260 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceLast10[].homeTeam.commonName.default` | string | Islanders | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceLast10[].homeTeam.placeNameWithPreposition.default` | string | New York | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceLast10[].homeTeam.placeNameWithPreposition.fr` | string | de New York | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceLast10[].homeTeam.abbrev` | string | NYI | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceLast10[].homeTeam.teamLogo.light` | string | https://assets.nhle.com/logos/nhl/svg/NYI_light.svg | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceLast10[].homeTeam.teamLogo.dark` | string | https://assets.nhle.com/logos/nhl/svg/NYI_dark.svg | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceLast10[].homeTeam.slug` | string | new-york-islanders-2 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceLast10[].awayTeam.commonName.default` | string | Senators | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceLast10[].awayTeam.commonName.fr` | string | Sénateurs | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceLast10[].awayTeam.placeNameWithPreposition.default` | string | Ottawa | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceLast10[].awayTeam.placeNameWithPreposition.fr` | string | d'Ottawa | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceLast10[].awayTeam.abbrev` | string | OTT | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceLast10[].awayTeam.teamLogo.light` | string | https://assets.nhle.com/logos/nhl/svg/OTT_light.svg | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceLast10[].awayTeam.teamLogo.dark` | string | https://assets.nhle.com/logos/nhl/svg/OTT_dark.svg | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceLast10[].awayTeam.slug` | string | ottawa-senators-9 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails.distanceTotal.imperial` | decimal | 261.3643 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails.distanceTotal.metric` | decimal | 420.6046 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails.distancePer60.imperial` | decimal | 9.6718 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails.distancePer60.metric` | decimal | 15.5645 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails.distanceMaxGame.imperial` | decimal | 4.2947 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails.distanceMaxGame.metric` | decimal | 6.9112 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails.distanceMaxGame.overlay.player.firstName.default` | string | Tim | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails.distanceMaxGame.overlay.player.lastName.default` | string | Stützle | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails.distanceMaxGame.overlay.gameDate` | string | 2025-10-16 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails.distanceMaxGame.overlay.awayTeam.abbrev` | string | SEA | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails.distanceMaxGame.overlay.awayTeam.score` | integer | 3 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails.distanceMaxGame.overlay.homeTeam.abbrev` | string | OTT | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails.distanceMaxGame.overlay.homeTeam.score` | integer | 4 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails.distanceMaxGame.overlay.gameOutcome.lastPeriodType` | string | SO | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails.distanceMaxGame.overlay.periodDescriptor.maxRegulationPeriods` | integer | 3 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails.distanceMaxGame.overlay.periodDescriptor.number` | integer | 3 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails.distanceMaxGame.overlay.periodDescriptor.periodType` | string | REG | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails.distanceMaxGame.overlay.gameType` | integer | 2 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails.distanceMaxPeriod.imperial` | decimal | 1.833 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails.distanceMaxPeriod.metric` | decimal | 2.9498 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails.distanceMaxPeriod.overlay.player.firstName.default` | string | Tim | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails.distanceMaxPeriod.overlay.player.lastName.default` | string | Stützle | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails.distanceMaxPeriod.overlay.gameDate` | string | 2026-01-05 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails.distanceMaxPeriod.overlay.awayTeam.abbrev` | string | DET | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails.distanceMaxPeriod.overlay.awayTeam.score` | integer | 5 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails.distanceMaxPeriod.overlay.homeTeam.abbrev` | string | OTT | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails.distanceMaxPeriod.overlay.homeTeam.score` | integer | 3 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails.distanceMaxPeriod.overlay.gameOutcome.lastPeriodType` | string | REG | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails.distanceMaxPeriod.overlay.periodDescriptor.maxRegulationPeriods` | integer | 3 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails.distanceMaxPeriod.overlay.periodDescriptor.number` | integer | 3 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails.distanceMaxPeriod.overlay.periodDescriptor.periodType` | string | REG | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails.distanceMaxPeriod.overlay.gameType` | integer | 2 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.shotLocationDetails[].area` | string | Behind the Net | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.shotLocationDetails[].sog` | integer | 0 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.shotLocationDetails[].goals` | integer | 0 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.shotLocationDetails[].shootingPctg` | integer | 0 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.shotLocationTotals[].locationCode` | string | all | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.shotLocationTotals[].sog` | integer | 194 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.shotLocationTotals[].goals` | integer | 34 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.shotLocationTotals[].shootingPctg` | decimal | 0.1753 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.zoneTimeDetails.offensiveZonePctg` | decimal | 0.46505416 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.zoneTimeDetails.offensiveZoneLeagueAvg` | decimal | 0.43087924 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.zoneTimeDetails.neutralZonePctg` | decimal | 0.17121101 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.zoneTimeDetails.neutralZoneLeagueAvg` | decimal | 0.16794965 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.zoneTimeDetails.defensiveZonePctg` | decimal | 0.36373482 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.zoneTimeDetails.defensiveZoneLeagueAvg` | decimal | 0.4011711 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.zoneStarts.offensiveZoneStarts` | decimal | 0.4821 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.zoneStarts.neutralZoneStarts` | decimal | 0.3086 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.zoneStarts.defensiveZoneStarts` | decimal | 0.2093 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |

## Opportunity

- Create player profile signals beyond fantasy box stats, such as speed, distance, shot location, shot speed, and zone-time context.
- Flag waiver or queue candidates whose underlying NHL Edge traits are strong before fantasy production catches up.
- Add percentile-style comparison views once source stability is verified across seasons and current endpoints.

## Parser Contract

- Do not treat this generated document as final endpoint authority until a human reviews the raw sample.
- Do not use this endpoint as canonical identity, stats, standings, roster, or validation authority until source precedence is documented.
- Treat missing, null, or empty sections as provider data availability unless verified otherwise.
- If this endpoint becomes part of an import path, add focused tests around the normalized contract rather than the provider payload wholesale.

## Expected Normalized Output

No current normalized output.

## Open Verification Questions

- What request parameters are safe for production usage?
- Does the response shape change across seasons, game types, teams, players, languages, or playoffs?
- Which fields should DynastyIQ persist, display only, derive from, or ignore?
