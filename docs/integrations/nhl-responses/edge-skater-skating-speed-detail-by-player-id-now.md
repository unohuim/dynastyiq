# NHL Edge Skater Skating Speed Detail by Player Id Now Response

## Endpoint

| Field | Value |
| --- | --- |
| Method | `GET` |
| Path | `/edge/skater-skating-speed-detail/{player-id}/now` |
| Source Description | See the NHL API reference for provider-authored description. This local row maps the endpoint to DynastyIQ generated files. |
| Example cURL | `curl -X GET "https://api-web.nhle.com/v1/edge/skater-skating-speed-detail/8482116/now"` |

Current DynastyIQ consumers:

- Possible overlap with player identity, NHL roster status, or player-page workflows; exact consumers were not verified by this generated document.

## Sample Source

- `docs/api_responses/samples/nhlApi-edge-skater-skating-speed-detail-by-player-id-now.txt`

The sample was generated from the runnable cURL example recorded in `docs/integrations/nhl-responses/nhl_api_index.md`.

## Purpose

Document the observed NHL API payload shape and identify DynastyIQ opportunities before implementation treats any field as authoritative.

## Observations For DynastyIQ

- Top-level observed shape: topSkatingSpeeds, skatingSpeedDetails
- This document is generated from a real raw payload and still needs human interpretation before fields become product or import authority.
- Preserve provider field names in raw-response docs; normalize only inside implementation contracts.

## Top-Level Observed Shape

| Section | Observed Shape | Observed Values / Notes |
| --- | --- | --- |
| `topSkatingSpeeds` | array[10] | 10 row(s). |
| `skatingSpeedDetails` | object{4} | Keys: maxSkatingSpeed, burstsOver22, bursts20To22, bursts18To20 |

## Observed Field Inventory

| Path | Type / Shape | Observed Values | Meaning | DynastyIQ Usage | Must Not Drive |
| --- | --- | --- | --- | --- | --- |
| `$.topSkatingSpeeds[].gameCenterLink` | string | /gamecenter/col-vs-ott/2026/01/28/2025020841 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.topSkatingSpeeds[].gameDate` | string | 2026-01-28 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.topSkatingSpeeds[].gameType` | integer | 2 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.topSkatingSpeeds[].playerOnHomeTeam` | boolean | true | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.topSkatingSpeeds[].skatingSpeed.imperial` | decimal | 23.5373 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.topSkatingSpeeds[].skatingSpeed.metric` | decimal | 37.8796 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.topSkatingSpeeds[].timeInPeriod` | string | 02:13 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.topSkatingSpeeds[].periodDescriptor.number` | integer | 2 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.topSkatingSpeeds[].periodDescriptor.periodType` | string | REG | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.topSkatingSpeeds[].periodDescriptor.maxRegulationPeriods` | integer | 3 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.topSkatingSpeeds[].homeTeam.commonName.default` | string | Senators | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.topSkatingSpeeds[].homeTeam.commonName.fr` | string | Sénateurs | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.topSkatingSpeeds[].homeTeam.placeNameWithPreposition.default` | string | Ottawa | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.topSkatingSpeeds[].homeTeam.placeNameWithPreposition.fr` | string | d'Ottawa | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.topSkatingSpeeds[].homeTeam.abbrev` | string | OTT | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.topSkatingSpeeds[].homeTeam.teamLogo.light` | string | https://assets.nhle.com/logos/nhl/svg/OTT_light.svg | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.topSkatingSpeeds[].homeTeam.teamLogo.dark` | string | https://assets.nhle.com/logos/nhl/svg/OTT_dark.svg | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.topSkatingSpeeds[].homeTeam.slug` | string | ottawa-senators-9 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.topSkatingSpeeds[].awayTeam.commonName.default` | string | Avalanche | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.topSkatingSpeeds[].awayTeam.placeNameWithPreposition.default` | string | Colorado | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.topSkatingSpeeds[].awayTeam.placeNameWithPreposition.fr` | string | du Colorado | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.topSkatingSpeeds[].awayTeam.abbrev` | string | COL | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.topSkatingSpeeds[].awayTeam.teamLogo.light` | string | https://assets.nhle.com/logos/nhl/svg/COL_light.svg | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.topSkatingSpeeds[].awayTeam.teamLogo.dark` | string | https://assets.nhle.com/logos/nhl/svg/COL_dark.svg | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.topSkatingSpeeds[].awayTeam.slug` | string | colorado-avalanche-21 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingSpeedDetails.maxSkatingSpeed.imperial` | decimal | 23.5373 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingSpeedDetails.maxSkatingSpeed.metric` | decimal | 37.8795 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingSpeedDetails.maxSkatingSpeed.percentile` | decimal | 0.956 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingSpeedDetails.maxSkatingSpeed.leagueAvg.imperial` | decimal | 22.1684 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingSpeedDetails.maxSkatingSpeed.leagueAvg.metric` | decimal | 35.6765 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
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
| `$.skatingSpeedDetails.burstsOver22.value` | integer | 46 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingSpeedDetails.burstsOver22.percentile` | decimal | 0.9967 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingSpeedDetails.burstsOver22.leagueAvg` | decimal | 3.8472 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingSpeedDetails.bursts20To22.value` | integer | 318 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingSpeedDetails.bursts20To22.percentile` | decimal | 0.9935 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingSpeedDetails.bursts20To22.leagueAvg` | decimal | 71.3528 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingSpeedDetails.bursts18To20.value` | integer | 756 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingSpeedDetails.bursts18To20.percentile` | decimal | 0.9805 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.skatingSpeedDetails.bursts18To20.leagueAvg` | decimal | 313.7463 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |

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
