# NHL Edge Skater Landing Now Response

## Endpoint

| Field | Value |
| --- | --- |
| Method | `GET` |
| Path | `/edge/skater-landing/now` |
| Source Description | See the NHL API reference for provider-authored description. This local row maps the endpoint to DynastyIQ generated files. |
| Example cURL | `curl -X GET "https://api-web.nhle.com/v1/edge/skater-landing/now"` |

Current DynastyIQ consumers:

- None verified by this generated document.

## Sample Source

- `docs/api_responses/samples/nhlApi-edge-skater-landing-now.txt`

The sample was generated from the runnable cURL example recorded in `docs/integrations/nhl-responses/nhl_api_index.md`.

## Purpose

Document the observed NHL API payload shape and identify DynastyIQ opportunities before implementation treats any field as authoritative.

## Observations For DynastyIQ

- Top-level observed shape: seasonsWithEdgeStats, leaders
- This document is generated from a real raw payload and still needs human interpretation before fields become product or import authority.
- Preserve provider field names in raw-response docs; normalize only inside implementation contracts.

## Top-Level Observed Shape

| Section | Observed Shape | Observed Values / Notes |
| --- | --- | --- |
| `seasonsWithEdgeStats` | array[5] | 5 row(s). |
| `leaders` | object{7} | Keys: hardestShot, maxSkatingSpeed, totalDistanceSkated, distanceMaxGame, highDangerSOG, offensiveZoneTime, defensiveZoneTime |

## Observed Field Inventory

| Path | Type / Shape | Observed Values | Meaning | DynastyIQ Usage | Must Not Drive |
| --- | --- | --- | --- | --- | --- |
| `$.seasonsWithEdgeStats[].id` | integer | 20212022 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.seasonsWithEdgeStats[].gameTypes[]` | array[2] | 2 row(s). | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.hardestShot.player.id` | integer | 8482095 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.hardestShot.player.firstName.default` | string | Tyler | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.hardestShot.player.lastName.default` | string | Kleven | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.hardestShot.player.sweaterNumber` | integer | 43 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.hardestShot.player.position` | string | D | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.hardestShot.player.slug` | string | tyler-kleven-8482095 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.hardestShot.player.headshot` | string | https://assets.nhle.com/mugs/nhl/20252026/OTT/8482095.png | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.hardestShot.player.team.commonName.default` | string | Senators | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.hardestShot.player.team.commonName.fr` | string | Sénateurs | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.hardestShot.player.team.placeNameWithPreposition.default` | string | Ottawa | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.hardestShot.player.team.placeNameWithPreposition.fr` | string | d'Ottawa | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.hardestShot.player.team.abbrev` | string | OTT | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.hardestShot.player.team.teamLogo.light` | string | https://assets.nhle.com/logos/nhl/svg/OTT_light.svg | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.hardestShot.player.team.teamLogo.dark` | string | https://assets.nhle.com/logos/nhl/svg/OTT_dark.svg | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.hardestShot.overlay.player.firstName.default` | string | Tyler | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.hardestShot.overlay.player.lastName.default` | string | Kleven | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.hardestShot.overlay.gameDate` | string | 2026-01-31 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.hardestShot.overlay.awayTeam.abbrev` | string | NJD | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.hardestShot.overlay.awayTeam.score` | integer | 1 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.hardestShot.overlay.homeTeam.abbrev` | string | OTT | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.hardestShot.overlay.homeTeam.score` | integer | 4 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.hardestShot.overlay.gameOutcome.lastPeriodType` | string | REG | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.hardestShot.overlay.periodDescriptor.maxRegulationPeriods` | integer | 3 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.hardestShot.overlay.periodDescriptor.number` | integer | 3 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.hardestShot.overlay.periodDescriptor.periodType` | string | REG | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.hardestShot.overlay.timeInPeriod` | string | 12:37 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.hardestShot.overlay.gameType` | integer | 2 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.hardestShot.shotSpeed.imperial` | decimal | 103.51 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.hardestShot.shotSpeed.metric` | decimal | 166.5832 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.maxSkatingSpeed.player.id` | integer | 8479359 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.maxSkatingSpeed.player.firstName.default` | string | Beck | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.maxSkatingSpeed.player.lastName.default` | string | Malenstyn | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.maxSkatingSpeed.player.sweaterNumber` | integer | 29 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.maxSkatingSpeed.player.position` | string | L | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.maxSkatingSpeed.player.slug` | string | beck-malenstyn-8479359 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.maxSkatingSpeed.player.headshot` | string | https://assets.nhle.com/mugs/nhl/20252026/BUF/8479359.png | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.maxSkatingSpeed.player.team.commonName.default` | string | Sabres | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.maxSkatingSpeed.player.team.placeNameWithPreposition.default` | string | Buffalo | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.maxSkatingSpeed.player.team.placeNameWithPreposition.fr` | string | de Buffalo | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.maxSkatingSpeed.player.team.abbrev` | string | BUF | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.maxSkatingSpeed.player.team.teamLogo.light` | string | https://assets.nhle.com/logos/nhl/svg/BUF_light.svg | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.maxSkatingSpeed.player.team.teamLogo.dark` | string | https://assets.nhle.com/logos/nhl/svg/BUF_dark.svg | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.maxSkatingSpeed.overlay.player.firstName.default` | string | Beck | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.maxSkatingSpeed.overlay.player.lastName.default` | string | Malenstyn | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.maxSkatingSpeed.overlay.gameDate` | string | 2026-03-12 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.maxSkatingSpeed.overlay.awayTeam.abbrev` | string | WSH | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.maxSkatingSpeed.overlay.awayTeam.score` | integer | 2 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.maxSkatingSpeed.overlay.homeTeam.abbrev` | string | BUF | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.maxSkatingSpeed.overlay.homeTeam.score` | integer | 1 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.maxSkatingSpeed.overlay.gameOutcome.lastPeriodType` | string | REG | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.maxSkatingSpeed.overlay.periodDescriptor.maxRegulationPeriods` | integer | 3 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.maxSkatingSpeed.overlay.periodDescriptor.number` | integer | 3 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.maxSkatingSpeed.overlay.periodDescriptor.periodType` | string | REG | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.maxSkatingSpeed.overlay.timeInPeriod` | string | 12:05 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.maxSkatingSpeed.overlay.gameType` | integer | 2 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.maxSkatingSpeed.skatingSpeed.imperial` | decimal | 24.9389 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.maxSkatingSpeed.skatingSpeed.metric` | decimal | 40.1352 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.totalDistanceSkated.player.id` | integer | 8478402 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.totalDistanceSkated.player.firstName.default` | string | Connor | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.totalDistanceSkated.player.lastName.default` | string | McDavid | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.totalDistanceSkated.player.sweaterNumber` | integer | 97 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.totalDistanceSkated.player.position` | string | C | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.totalDistanceSkated.player.slug` | string | connor-mcdavid-8478402 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.totalDistanceSkated.player.headshot` | string | https://assets.nhle.com/mugs/nhl/20252026/EDM/8478402.png | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.totalDistanceSkated.player.team.commonName.default` | string | Oilers | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.totalDistanceSkated.player.team.placeNameWithPreposition.default` | string | Edmonton | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.totalDistanceSkated.player.team.placeNameWithPreposition.fr` | string | d'Edmonton | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.totalDistanceSkated.player.team.abbrev` | string | EDM | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.totalDistanceSkated.player.team.teamLogo.light` | string | https://assets.nhle.com/logos/nhl/svg/EDM_light.svg | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.totalDistanceSkated.player.team.teamLogo.dark` | string | https://assets.nhle.com/logos/nhl/svg/EDM_dark.svg | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.totalDistanceSkated.distanceSkated.imperial` | decimal | 330.2671 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.totalDistanceSkated.distanceSkated.metric` | decimal | 531.4875 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.distanceMaxGame.player.id` | integer | 8480039 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.distanceMaxGame.player.firstName.default` | string | Martin | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.distanceMaxGame.player.lastName.default` | string | Necas | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.distanceMaxGame.player.lastName.cs` | string | Nečas | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.distanceMaxGame.player.lastName.sk` | string | Nečas | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.distanceMaxGame.player.sweaterNumber` | integer | 88 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.distanceMaxGame.player.position` | string | C | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.distanceMaxGame.player.slug` | string | martin-necas-8480039 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.distanceMaxGame.player.headshot` | string | https://assets.nhle.com/mugs/nhl/20252026/COL/8480039.png | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.distanceMaxGame.player.team.commonName.default` | string | Avalanche | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.distanceMaxGame.player.team.placeNameWithPreposition.default` | string | Colorado | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.distanceMaxGame.player.team.placeNameWithPreposition.fr` | string | du Colorado | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.distanceMaxGame.player.team.abbrev` | string | COL | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.distanceMaxGame.player.team.teamLogo.light` | string | https://assets.nhle.com/logos/nhl/svg/COL_light.svg | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.distanceMaxGame.player.team.teamLogo.dark` | string | https://assets.nhle.com/logos/nhl/svg/COL_dark.svg | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.distanceMaxGame.distanceSkated.imperial` | decimal | 5.0908 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.distanceMaxGame.distanceSkated.metric` | decimal | 8.1924 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.distanceMaxGame.overlay.player.firstName.default` | string | Martin | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.distanceMaxGame.overlay.player.lastName.default` | string | Necas | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.distanceMaxGame.overlay.player.lastName.cs` | string | Nečas | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.distanceMaxGame.overlay.player.lastName.sk` | string | Nečas | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.distanceMaxGame.overlay.gameDate` | string | 2026-04-13 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.distanceMaxGame.overlay.awayTeam.abbrev` | string | COL | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.distanceMaxGame.overlay.awayTeam.score` | integer | 2 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.distanceMaxGame.overlay.homeTeam.abbrev` | string | EDM | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.distanceMaxGame.overlay.homeTeam.score` | integer | 1 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.distanceMaxGame.overlay.gameOutcome.lastPeriodType` | string | SO | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.distanceMaxGame.overlay.periodDescriptor.maxRegulationPeriods` | integer | 3 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.distanceMaxGame.overlay.periodDescriptor.number` | integer | 3 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.distanceMaxGame.overlay.periodDescriptor.periodType` | string | REG | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.distanceMaxGame.overlay.gameType` | integer | 2 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.highDangerSOG.player.id` | integer | 8478498 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.highDangerSOG.player.firstName.default` | string | Jake | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.highDangerSOG.player.lastName.default` | string | DeBrusk | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.highDangerSOG.player.sweaterNumber` | integer | 74 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.highDangerSOG.player.position` | string | L | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.highDangerSOG.player.slug` | string | jake-debrusk-8478498 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.highDangerSOG.player.headshot` | string | https://assets.nhle.com/mugs/nhl/20252026/VAN/8478498.png | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.highDangerSOG.player.team.commonName.default` | string | Canucks | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.highDangerSOG.player.team.placeNameWithPreposition.default` | string | Vancouver | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.highDangerSOG.player.team.placeNameWithPreposition.fr` | string | de Vancouver | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.highDangerSOG.player.team.abbrev` | string | VAN | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.highDangerSOG.player.team.teamLogo.light` | string | https://assets.nhle.com/logos/nhl/svg/VAN_light.svg | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.highDangerSOG.player.team.teamLogo.dark` | string | https://assets.nhle.com/logos/nhl/svg/VAN_dark.svg | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.highDangerSOG.sog` | integer | 121 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.highDangerSOG.shotLocationDetails[].area` | string | Behind the Net | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.highDangerSOG.shotLocationDetails[].sog` | integer | 3 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.highDangerSOG.shotLocationDetails[].sogPercentile` | decimal | 0.935 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.offensiveZoneTime.player.id` | integer | 8476906 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.offensiveZoneTime.player.firstName.default` | string | Shayne | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.offensiveZoneTime.player.lastName.default` | string | Gostisbehere | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.offensiveZoneTime.player.sweaterNumber` | integer | 4 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.offensiveZoneTime.player.position` | string | D | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.offensiveZoneTime.player.slug` | string | shayne-gostisbehere-8476906 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.offensiveZoneTime.player.headshot` | string | https://assets.nhle.com/mugs/nhl/20252026/CAR/8476906.png | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.offensiveZoneTime.player.team.commonName.default` | string | Hurricanes | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.offensiveZoneTime.player.team.placeNameWithPreposition.default` | string | Carolina | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.offensiveZoneTime.player.team.placeNameWithPreposition.fr` | string | de la Caroline | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.offensiveZoneTime.player.team.abbrev` | string | CAR | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.offensiveZoneTime.player.team.teamLogo.light` | string | https://assets.nhle.com/logos/nhl/svg/CAR_light.svg | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.offensiveZoneTime.player.team.teamLogo.dark` | string | https://assets.nhle.com/logos/nhl/svg/CAR_dark.svg | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.offensiveZoneTime.zoneTime` | decimal | 0.49576938 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.defensiveZoneTime.player.id` | integer | 8476906 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.defensiveZoneTime.player.firstName.default` | string | Shayne | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.defensiveZoneTime.player.lastName.default` | string | Gostisbehere | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.defensiveZoneTime.player.sweaterNumber` | integer | 4 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.defensiveZoneTime.player.position` | string | D | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.defensiveZoneTime.player.slug` | string | shayne-gostisbehere-8476906 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.defensiveZoneTime.player.headshot` | string | https://assets.nhle.com/mugs/nhl/20252026/CAR/8476906.png | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.defensiveZoneTime.player.team.commonName.default` | string | Hurricanes | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.defensiveZoneTime.player.team.placeNameWithPreposition.default` | string | Carolina | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.defensiveZoneTime.player.team.placeNameWithPreposition.fr` | string | de la Caroline | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.defensiveZoneTime.player.team.abbrev` | string | CAR | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.defensiveZoneTime.player.team.teamLogo.light` | string | https://assets.nhle.com/logos/nhl/svg/CAR_light.svg | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.defensiveZoneTime.player.team.teamLogo.dark` | string | https://assets.nhle.com/logos/nhl/svg/CAR_dark.svg | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.defensiveZoneTime.zoneTime` | decimal | 0.32600889 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |

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
