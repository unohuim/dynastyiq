# NHL Edge Skater Shot Location Detail by Player Id Now Response

## Endpoint

| Field | Value |
| --- | --- |
| Method | `GET` |
| Path | `/edge/skater-shot-location-detail/{player-id}/now` |
| Source Description | See the NHL API reference for provider-authored description. This local row maps the endpoint to DynastyIQ generated files. |
| Example cURL | `curl -X GET "https://api-web.nhle.com/v1/edge/skater-shot-location-detail/8482116/now"` |

Current DynastyIQ consumers:

- Possible overlap with player identity, NHL roster status, or player-page workflows; exact consumers were not verified by this generated document.

## Sample Source

- `docs/api_responses/samples/nhlApi-edge-skater-shot-location-detail-by-player-id-now.txt`

The sample was generated from the runnable cURL example recorded in `docs/integrations/nhl-responses/nhl_api_index.md`.

## Purpose

Document the observed NHL API payload shape and identify DynastyIQ opportunities before implementation treats any field as authoritative.

## Observations For DynastyIQ

- Top-level observed shape: shotLocationDetails, shotLocationTotals
- This document is generated from a real raw payload and still needs human interpretation before fields become product or import authority.
- Preserve provider field names in raw-response docs; normalize only inside implementation contracts.

## Top-Level Observed Shape

| Section | Observed Shape | Observed Values / Notes |
| --- | --- | --- |
| `shotLocationDetails` | array[17] | 17 row(s). |
| `shotLocationTotals` | array[4] | 4 row(s). |

## Observed Field Inventory

| Path | Type / Shape | Observed Values | Meaning | DynastyIQ Usage | Must Not Drive |
| --- | --- | --- | --- | --- | --- |
| `$.shotLocationDetails[].area` | string | Behind the Net | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.shotLocationDetails[].sog` | integer | 0 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.shotLocationDetails[].goals` | integer | 0 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.shotLocationDetails[].shootingPctg` | integer | 0 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.shotLocationDetails[].sogPercentile` | integer | 0 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.shotLocationDetails[].goalsPercentile` | integer | 0 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.shotLocationDetails[].shootingPctgPercentile` | integer | 0 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.shotLocationTotals[].locationCode` | string | all | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.shotLocationTotals[].sog` | integer | 194 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.shotLocationTotals[].goals` | integer | 34 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.shotLocationTotals[].shootingPctg` | decimal | 0.1753 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.shotLocationTotals[].sogPercentile` | decimal | 0.9121 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.shotLocationTotals[].goalsPercentile` | decimal | 0.9577 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.shotLocationTotals[].shootingPctgPercentile` | decimal | 0.8853 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.shotLocationTotals[].sogLeagueAvg` | decimal | 85.9138 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.shotLocationTotals[].goalsLeagueAvg` | decimal | 11.1545 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.shotLocationTotals[].shootingPctgLeagueAvg` | decimal | 0.1298 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |

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
