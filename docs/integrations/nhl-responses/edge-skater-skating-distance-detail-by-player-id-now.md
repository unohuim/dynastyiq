# NHL Edge Skater Skating Distance Detail by Player Id Now Response

## Endpoint

| Field | Value |
| --- | --- |
| Method | `GET` |
| Path | `/edge/skater-skating-distance-detail/{player-id}/now` |
| Source Description | See the NHL API reference for provider-authored description. This local row maps the endpoint to DynastyIQ generated files. |
| Example cURL | `curl -X GET "https://api-web.nhle.com/v1/edge/skater-skating-distance-detail/8482116/now"` |

Current DynastyIQ consumers:

- Possible overlap with player identity, NHL roster status, or player-page workflows; exact consumers were not verified by this generated document.

## Sample Source

- `docs/api_responses/samples/nhlApi-edge-skater-skating-distance-detail-by-player-id-now.txt`

The sample was generated from the runnable cURL example recorded in `docs/integrations/nhl-responses/nhl_api_index.md`.

## Purpose

Document the observed NHL API payload shape and identify DynastyIQ opportunities before implementation treats any field as authoritative.

## Observations For DynastyIQ

- Top-level observed shape: skatingDistanceLast10, skatingDistanceDetails
- This document is generated from a real raw payload and still needs human interpretation before fields become product or import authority.
- Preserve provider field names in raw-response docs; normalize only inside implementation contracts.

## Top-Level Observed Shape

| Section | Observed Shape | Observed Values / Notes |
| --- | --- | --- |
| `skatingDistanceLast10` | array[10] | 10 row(s). |
| `skatingDistanceDetails` | array[4] | 4 row(s). |

## Observed Field Inventory

| Path | Type / Shape | Observed Values | Meaning | DynastyIQ Usage | Must Not Drive |
| --- | --- | --- | --- | --- | --- |
| `$.skatingDistanceLast10[].gameCenterLink` | string | /gamecenter/ott-vs-nyi/2026/04/11/2025021262 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceLast10[].gameDate` | string | 2026-04-11 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceLast10[].playerOnHomeTeam` | boolean | false | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceLast10[].distanceSkatedAll.imperial` | decimal | 3.2175 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceLast10[].distanceSkatedAll.metric` | decimal | 5.1781 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceLast10[].toiAll` | integer | 1260 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceLast10[].distanceSkatedEven.imperial` | decimal | 1.6765 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceLast10[].distanceSkatedEven.metric` | decimal | 2.698 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceLast10[].toiEven` | integer | 631 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceLast10[].distanceSkatedPP.imperial` | decimal | 0.9879 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceLast10[].distanceSkatedPP.metric` | decimal | 1.5899 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceLast10[].toiPP` | integer | 381 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceLast10[].distanceSkatedPK.imperial` | decimal | 0.5532 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceLast10[].distanceSkatedPK.metric` | decimal | 0.8903 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceLast10[].toiPK` | integer | 248 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
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
| `$.skatingDistanceDetails[].strengthCode` | string | all | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails[].distanceTotal.imperial` | decimal | 261.3643 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails[].distanceTotal.metric` | decimal | 420.6046 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails[].distanceTotal.percentile` | decimal | 0.9723 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails[].distanceTotal.leagueAvg.imperial` | decimal | 123.5454 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails[].distanceTotal.leagueAvg.metric` | decimal | 198.8173 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails[].distancePer60.imperial` | decimal | 9.6718 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails[].distancePer60.metric` | decimal | 15.5645 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails[].distancePer60.percentile` | decimal | 0.5459 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails[].distancePer60.leagueAvg.imperial` | decimal | 9.6011 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails[].distancePer60.leagueAvg.metric` | decimal | 15.4507 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails[].distanceMaxGame.imperial` | decimal | 4.2947 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails[].distanceMaxGame.metric` | decimal | 6.9112 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails[].distanceMaxGame.percentile` | decimal | 0.9756 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails[].distanceMaxGame.leagueAvg.imperial` | decimal | 2.9231 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails[].distanceMaxGame.leagueAvg.metric` | decimal | 4.7041 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails[].distanceMaxGame.overlay.player.firstName.default` | string | Tim | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails[].distanceMaxGame.overlay.player.lastName.default` | string | Stützle | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails[].distanceMaxGame.overlay.gameDate` | string | 2025-10-16 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails[].distanceMaxGame.overlay.awayTeam.abbrev` | string | SEA | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails[].distanceMaxGame.overlay.awayTeam.score` | integer | 3 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails[].distanceMaxGame.overlay.homeTeam.abbrev` | string | OTT | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails[].distanceMaxGame.overlay.homeTeam.score` | integer | 4 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails[].distanceMaxGame.overlay.gameOutcome.lastPeriodType` | string | SO | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails[].distanceMaxGame.overlay.periodDescriptor.maxRegulationPeriods` | integer | 3 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails[].distanceMaxGame.overlay.periodDescriptor.number` | integer | 3 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails[].distanceMaxGame.overlay.periodDescriptor.periodType` | string | REG | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails[].distanceMaxGame.overlay.gameType` | integer | 2 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails[].distanceMaxPeriod.imperial` | decimal | 1.833 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails[].distanceMaxPeriod.metric` | decimal | 2.9498 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails[].distanceMaxPeriod.percentile` | decimal | 0.9951 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails[].distanceMaxPeriod.leagueAvg.imperial` | decimal | 1.1579 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails[].distanceMaxPeriod.leagueAvg.metric` | decimal | 1.8633 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails[].distanceMaxPeriod.overlay.player.firstName.default` | string | Tim | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails[].distanceMaxPeriod.overlay.player.lastName.default` | string | Stützle | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails[].distanceMaxPeriod.overlay.gameDate` | string | 2026-01-05 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails[].distanceMaxPeriod.overlay.awayTeam.abbrev` | string | DET | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails[].distanceMaxPeriod.overlay.awayTeam.score` | integer | 5 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails[].distanceMaxPeriod.overlay.homeTeam.abbrev` | string | OTT | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails[].distanceMaxPeriod.overlay.homeTeam.score` | integer | 3 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails[].distanceMaxPeriod.overlay.gameOutcome.lastPeriodType` | string | REG | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails[].distanceMaxPeriod.overlay.periodDescriptor.maxRegulationPeriods` | integer | 3 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails[].distanceMaxPeriod.overlay.periodDescriptor.number` | integer | 3 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails[].distanceMaxPeriod.overlay.periodDescriptor.periodType` | string | REG | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingDistanceDetails[].distanceMaxPeriod.overlay.gameType` | integer | 2 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |

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
