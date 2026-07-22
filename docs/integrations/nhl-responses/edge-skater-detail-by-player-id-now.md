# NHL Edge Skater Detail by Player Id Now Response

## Endpoint

| Field | Value |
| --- | --- |
| Method | `GET` |
| Path | `/edge/skater-detail/{player-id}/now` |
| Source Description | See the NHL API reference for provider-authored description. This local row maps the endpoint to DynastyIQ generated files. |
| Example cURL | `curl -X GET "https://api-web.nhle.com/v1/edge/skater-detail/8482116/now"` |

Current DynastyIQ consumers:

- Possible overlap with player identity, NHL roster status, or player-page workflows; exact consumers were not verified by this generated document.

## Sample Source

- `docs/api_responses/samples/nhlApi-edge-skater-detail-by-player-id-now.txt`

The sample was generated from the runnable cURL example recorded in `docs/integrations/nhl-responses/nhl_api_index.md`.

## Purpose

Document the observed NHL API payload shape and identify DynastyIQ opportunities before implementation treats any field as authoritative.

## Observations For DynastyIQ

- Top-level observed shape: player, seasonsWithEdgeStats, topShotSpeed, skatingSpeed, totalDistanceSkated, distanceMaxGame, sogSummary, sogDetails, zoneTimeDetails
- This document is generated from a real raw payload and still needs human interpretation before fields become product or import authority.
- Preserve provider field names in raw-response docs; normalize only inside implementation contracts.

## Top-Level Observed Shape

| Section | Observed Shape | Observed Values / Notes |
| --- | --- | --- |
| `player` | object{14} | Keys: id, firstName, lastName, birthDate, shootsCatches, sweaterNumber, position, slug, headshot, goals |
| `seasonsWithEdgeStats` | array[5] | 5 row(s). |
| `topShotSpeed` | object{5} | Keys: imperial, metric, percentile, leagueAvg, overlay |
| `skatingSpeed` | object{2} | Keys: speedMax, burstsOver20 |
| `totalDistanceSkated` | object{4} | Keys: imperial, metric, percentile, leagueAvg |
| `distanceMaxGame` | object{5} | Keys: imperial, metric, percentile, leagueAvg, overlay |
| `sogSummary` | array[4] | 4 row(s). |
| `sogDetails` | array[17] | 17 row(s). |
| `zoneTimeDetails` | object{12} | Keys: offensiveZonePctg, offensiveZonePercentile, offensiveZoneLeagueAvg, offensiveZoneEvPctg, offensiveZoneEvPercentile, offensiveZoneEvLeagueAvg, neutralZonePctg, neutralZonePercentile, neutralZoneLeagueAvg, defensiveZonePctg |

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
| `$.seasonsWithEdgeStats[].id` | integer | 20212022 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.seasonsWithEdgeStats[].gameTypes[]` | array[1] | 1 row(s). | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.topShotSpeed.imperial` | decimal | 90.1 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.topShotSpeed.metric` | decimal | 145.0019 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.topShotSpeed.percentile` | decimal | 0.8377 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.topShotSpeed.leagueAvg.imperial` | decimal | 83.6208 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.topShotSpeed.leagueAvg.metric` | decimal | 134.5747 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.topShotSpeed.overlay.player.firstName.default` | string | Tim | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.topShotSpeed.overlay.player.lastName.default` | string | Stützle | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.topShotSpeed.overlay.gameDate` | string | 2025-12-02 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.topShotSpeed.overlay.awayTeam.abbrev` | string | OTT | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.topShotSpeed.overlay.awayTeam.score` | integer | 5 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.topShotSpeed.overlay.homeTeam.abbrev` | string | MTL | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.topShotSpeed.overlay.homeTeam.score` | integer | 2 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.topShotSpeed.overlay.gameOutcome.lastPeriodType` | string | REG | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.topShotSpeed.overlay.periodDescriptor.maxRegulationPeriods` | integer | 3 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.topShotSpeed.overlay.periodDescriptor.number` | integer | 1 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.topShotSpeed.overlay.periodDescriptor.periodType` | string | REG | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.topShotSpeed.overlay.timeInPeriod` | string | 11:50 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.topShotSpeed.overlay.gameType` | integer | 2 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingSpeed.speedMax.imperial` | decimal | 23.5373 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingSpeed.speedMax.metric` | decimal | 37.8795 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingSpeed.speedMax.percentile` | decimal | 0.956 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingSpeed.speedMax.leagueAvg.imperial` | decimal | 22.1684 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingSpeed.speedMax.leagueAvg.metric` | decimal | 35.6765 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingSpeed.speedMax.overlay.player.firstName.default` | string | Tim | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingSpeed.speedMax.overlay.player.lastName.default` | string | Stützle | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingSpeed.speedMax.overlay.gameDate` | string | 2026-01-28 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingSpeed.speedMax.overlay.awayTeam.abbrev` | string | COL | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingSpeed.speedMax.overlay.awayTeam.score` | integer | 2 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingSpeed.speedMax.overlay.homeTeam.abbrev` | string | OTT | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingSpeed.speedMax.overlay.homeTeam.score` | integer | 5 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingSpeed.speedMax.overlay.gameOutcome.lastPeriodType` | string | REG | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingSpeed.speedMax.overlay.periodDescriptor.maxRegulationPeriods` | integer | 3 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingSpeed.speedMax.overlay.periodDescriptor.number` | integer | 2 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingSpeed.speedMax.overlay.periodDescriptor.periodType` | string | REG | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingSpeed.speedMax.overlay.timeInPeriod` | string | 02:13 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingSpeed.speedMax.overlay.gameType` | integer | 2 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingSpeed.burstsOver20.value` | integer | 364 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingSpeed.burstsOver20.percentile` | decimal | 0.9935 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingSpeed.burstsOver20.leagueAvg.value` | decimal | 75.2 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.totalDistanceSkated.imperial` | decimal | 261.3643 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.totalDistanceSkated.metric` | decimal | 420.6046 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.totalDistanceSkated.percentile` | decimal | 0.9723 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.totalDistanceSkated.leagueAvg.imperial` | decimal | 123.5454 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.totalDistanceSkated.leagueAvg.metric` | decimal | 198.8173 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.distanceMaxGame.imperial` | decimal | 4.2947 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.distanceMaxGame.metric` | decimal | 6.9112 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.distanceMaxGame.percentile` | decimal | 0.9756 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.distanceMaxGame.leagueAvg.imperial` | decimal | 2.9231 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.distanceMaxGame.leagueAvg.metric` | decimal | 4.7041 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.distanceMaxGame.overlay.player.firstName.default` | string | Tim | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.distanceMaxGame.overlay.player.lastName.default` | string | Stützle | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.distanceMaxGame.overlay.gameDate` | string | 2025-10-16 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.distanceMaxGame.overlay.awayTeam.abbrev` | string | SEA | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.distanceMaxGame.overlay.awayTeam.score` | integer | 3 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.distanceMaxGame.overlay.homeTeam.abbrev` | string | OTT | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.distanceMaxGame.overlay.homeTeam.score` | integer | 4 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.distanceMaxGame.overlay.gameOutcome.lastPeriodType` | string | SO | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.distanceMaxGame.overlay.periodDescriptor.maxRegulationPeriods` | integer | 3 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.distanceMaxGame.overlay.periodDescriptor.number` | integer | 3 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.distanceMaxGame.overlay.periodDescriptor.periodType` | string | REG | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.distanceMaxGame.overlay.gameType` | integer | 2 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.sogSummary[].locationCode` | string | all | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.sogSummary[].shots` | integer | 194 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.sogSummary[].shotsPercentile` | decimal | 0.9121 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.sogSummary[].shotsLeagueAvg` | decimal | 85.9138 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.sogSummary[].goals` | integer | 34 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.sogSummary[].goalsPercentile` | decimal | 0.9577 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.sogSummary[].goalsLeagueAvg` | decimal | 11.1545 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.sogSummary[].shootingPctg` | decimal | 0.1753 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.sogSummary[].shootingPctgPercentile` | decimal | 0.8853 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.sogSummary[].shootingPctgLeagueAvg` | decimal | 0.1298 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.sogDetails[].area` | string | Behind the Net | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.sogDetails[].shots` | integer | 0 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.sogDetails[].shotsPercentile` | integer | 0 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.zoneTimeDetails.offensiveZonePctg` | decimal | 0.46505416 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.zoneTimeDetails.offensiveZonePercentile` | decimal | 0.9505882352941 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.zoneTimeDetails.offensiveZoneLeagueAvg` | decimal | 0.43087924 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.zoneTimeDetails.offensiveZoneEvPctg` | decimal | 0.45043614 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.zoneTimeDetails.offensiveZoneEvPercentile` | decimal | 0.9623529411765 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.zoneTimeDetails.offensiveZoneEvLeagueAvg` | decimal | 0.42022407 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.zoneTimeDetails.neutralZonePctg` | decimal | 0.17121101 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.zoneTimeDetails.neutralZonePercentile` | decimal | 0.1035294117647 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.zoneTimeDetails.neutralZoneLeagueAvg` | decimal | 0.16794965 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.zoneTimeDetails.defensiveZonePctg` | decimal | 0.36373482 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.zoneTimeDetails.defensiveZonePercentile` | decimal | 0.9247058823529 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.zoneTimeDetails.defensiveZoneLeagueAvg` | decimal | 0.4011711 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |

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
