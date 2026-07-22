# NHL Edge Skater Shot Speed Detail by Player Id Now Response

## Endpoint

| Field | Value |
| --- | --- |
| Method | `GET` |
| Path | `/edge/skater-shot-speed-detail/{player-id}/now` |
| Source Description | See the NHL API reference for provider-authored description. This local row maps the endpoint to DynastyIQ generated files. |
| Example cURL | `curl -X GET "https://api-web.nhle.com/v1/edge/skater-shot-speed-detail/8482116/now"` |

Current DynastyIQ consumers:

- Possible overlap with player identity, NHL roster status, or player-page workflows; exact consumers were not verified by this generated document.

## Sample Source

- `docs/api_responses/samples/nhlApi-edge-skater-shot-speed-detail-by-player-id-now.txt`

The sample was generated from the runnable cURL example recorded in `docs/integrations/nhl-responses/nhl_api_index.md`.

## Purpose

Document the observed NHL API payload shape and identify DynastyIQ opportunities before implementation treats any field as authoritative.

## Observations For DynastyIQ

- Top-level observed shape: hardestShots, shotSpeedDetails
- This document is generated from a real raw payload and still needs human interpretation before fields become product or import authority.
- Preserve provider field names in raw-response docs; normalize only inside implementation contracts.

## Top-Level Observed Shape

| Section | Observed Shape | Observed Values / Notes |
| --- | --- | --- |
| `hardestShots` | array[10] | 10 row(s). |
| `shotSpeedDetails` | object{6} | Keys: topShotSpeed, avgShotSpeed, shotAttemptsOver100, shotAttempts90To100, shotAttempts80To90, shotAttempts70To80 |

## Observed Field Inventory

| Path | Type / Shape | Observed Values | Meaning | DynastyIQ Usage | Must Not Drive |
| --- | --- | --- | --- | --- | --- |
| `$.hardestShots[].gameCenterLink` | string | /gamecenter/ott-vs-mtl/2025/12/02/2025020411 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.hardestShots[].gameDate` | string | 2025-12-02 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.hardestShots[].gameType` | integer | 2 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.hardestShots[].playerOnHomeTeam` | boolean | false | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.hardestShots[].shotSpeed.imperial` | decimal | 90.1 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.hardestShots[].shotSpeed.metric` | integer | 145 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.hardestShots[].timeInPeriod` | string | 11:50 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.hardestShots[].periodDescriptor.number` | integer | 1 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.hardestShots[].periodDescriptor.periodType` | string | REG | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.hardestShots[].periodDescriptor.maxRegulationPeriods` | integer | 3 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.hardestShots[].homeTeam.commonName.default` | string | Canadiens | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.hardestShots[].homeTeam.placeNameWithPreposition.default` | string | Montréal | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.hardestShots[].homeTeam.placeNameWithPreposition.fr` | string | de Montréal | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.hardestShots[].homeTeam.abbrev` | string | MTL | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.hardestShots[].homeTeam.teamLogo.light` | string | https://assets.nhle.com/logos/nhl/svg/MTL_light.svg | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.hardestShots[].homeTeam.teamLogo.dark` | string | https://assets.nhle.com/logos/nhl/svg/MTL_dark.svg | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.hardestShots[].homeTeam.slug` | string | montreal-canadiens-8 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.hardestShots[].awayTeam.commonName.default` | string | Senators | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.hardestShots[].awayTeam.commonName.fr` | string | Sénateurs | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.hardestShots[].awayTeam.placeNameWithPreposition.default` | string | Ottawa | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.hardestShots[].awayTeam.placeNameWithPreposition.fr` | string | d'Ottawa | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.hardestShots[].awayTeam.abbrev` | string | OTT | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.hardestShots[].awayTeam.teamLogo.light` | string | https://assets.nhle.com/logos/nhl/svg/OTT_light.svg | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.hardestShots[].awayTeam.teamLogo.dark` | string | https://assets.nhle.com/logos/nhl/svg/OTT_dark.svg | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.hardestShots[].awayTeam.slug` | string | ottawa-senators-9 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.shotSpeedDetails.topShotSpeed.imperial` | decimal | 90.1 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.shotSpeedDetails.topShotSpeed.metric` | decimal | 145.0019 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.shotSpeedDetails.topShotSpeed.percentile` | decimal | 0.8377 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.shotSpeedDetails.topShotSpeed.leagueAvg.imperial` | decimal | 83.6208 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.shotSpeedDetails.topShotSpeed.leagueAvg.metric` | decimal | 134.5747 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
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
| `$.shotSpeedDetails.avgShotSpeed.percentile` | decimal | 0.7393 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.shotSpeedDetails.avgShotSpeed.leagueAvg.imperial` | decimal | 53.1912 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.shotSpeedDetails.avgShotSpeed.leagueAvg.metric` | decimal | 85.603 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.shotSpeedDetails.shotAttemptsOver100.value` | integer | 0 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.shotSpeedDetails.shotAttemptsOver100.percentile` | integer | 0 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.shotSpeedDetails.shotAttemptsOver100.leagueAvg` | decimal | 0.0049 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.shotSpeedDetails.shotAttempts90To100.value` | integer | 1 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.shotSpeedDetails.shotAttempts90To100.percentile` | decimal | 0.8361 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.shotSpeedDetails.shotAttempts90To100.leagueAvg` | decimal | 0.6399 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.shotSpeedDetails.shotAttempts80To90.value` | integer | 24 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.shotSpeedDetails.shotAttempts80To90.percentile` | decimal | 0.8705 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.shotSpeedDetails.shotAttempts80To90.leagueAvg` | decimal | 10.0082 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.shotSpeedDetails.shotAttempts70To80.value` | integer | 100 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.shotSpeedDetails.shotAttempts70To80.percentile` | decimal | 0.9623 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.shotSpeedDetails.shotAttempts70To80.leagueAvg` | decimal | 28.3699 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |

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
