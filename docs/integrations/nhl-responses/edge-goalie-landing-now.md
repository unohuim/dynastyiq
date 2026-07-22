# NHL Edge Goalie Landing Now Response

## Endpoint

| Field | Value |
| --- | --- |
| Method | `GET` |
| Path | `/edge/goalie-landing/now` |
| Source Description | See the NHL API reference for provider-authored description. This local row maps the endpoint to DynastyIQ generated files. |
| Example cURL | `curl -X GET "https://api-web.nhle.com/v1/edge/goalie-landing/now"` |

Current DynastyIQ consumers:

- None verified by this generated document.

## Sample Source

- `docs/api_responses/samples/nhlApi-edge-goalie-landing-now.txt`

The sample was generated from the runnable cURL example recorded in `docs/integrations/nhl-responses/nhl_api_index.md`.

## Purpose

Document the observed NHL API payload shape and identify DynastyIQ opportunities before implementation treats any field as authoritative.

## Observations For DynastyIQ

- Top-level observed shape: seasonsWithEdgeStats, minimumGamesPlayed, leaders
- This document is generated from a real raw payload and still needs human interpretation before fields become product or import authority.
- Preserve provider field names in raw-response docs; normalize only inside implementation contracts.

## Top-Level Observed Shape

| Section | Observed Shape | Observed Values / Notes |
| --- | --- | --- |
| `seasonsWithEdgeStats` | array[5] | 5 row(s). |
| `minimumGamesPlayed` | integer | 25 |
| `leaders` | object{5} | Keys: highDangerSavePctg, highDangerSaves, highDangerGoalsAgainst, savePctg5v5, gamesAbove900 |

## Observed Field Inventory

| Path | Type / Shape | Observed Values | Meaning | DynastyIQ Usage | Must Not Drive |
| --- | --- | --- | --- | --- | --- |
| `$.seasonsWithEdgeStats[].id` | integer | 20212022 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.seasonsWithEdgeStats[].gameTypes[]` | array[2] | 2 row(s). | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.minimumGamesPlayed` | integer | 25 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.highDangerSavePctg.player.id` | integer | 8478009 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.highDangerSavePctg.player.firstName.default` | string | Ilya | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.highDangerSavePctg.player.firstName.cs` | string | Ilja | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.highDangerSavePctg.player.firstName.fi` | string | Ilja | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.highDangerSavePctg.player.firstName.sk` | string | Ilja | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.highDangerSavePctg.player.lastName.default` | string | Sorokin | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.highDangerSavePctg.player.sweaterNumber` | integer | 30 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.highDangerSavePctg.player.position` | string | G | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.highDangerSavePctg.player.slug` | string | ilya-sorokin-8478009 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.highDangerSavePctg.player.headshot` | string | https://assets.nhle.com/mugs/nhl/20252026/NYI/8478009.png | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.highDangerSavePctg.player.team.commonName.default` | string | Islanders | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.highDangerSavePctg.player.team.placeNameWithPreposition.default` | string | New York | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.highDangerSavePctg.player.team.placeNameWithPreposition.fr` | string | de New York | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.highDangerSavePctg.player.team.abbrev` | string | NYI | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.highDangerSavePctg.player.team.teamLogo.light` | string | https://assets.nhle.com/logos/nhl/svg/NYI_light.svg | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.highDangerSavePctg.player.team.teamLogo.dark` | string | https://assets.nhle.com/logos/nhl/svg/NYI_dark.svg | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.highDangerSavePctg.savePctg` | decimal | 0.864245 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.highDangerSavePctg.shotLocationDetails[].area` | string | Behind the Net | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.highDangerSavePctg.shotLocationDetails[].savePctg` | decimal | 0.818182 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.highDangerSavePctg.shotLocationDetails[].savePctgPercentile` | decimal | 0.1186 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.highDangerSaves.player.id` | integer | 8478009 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.highDangerSaves.player.firstName.default` | string | Ilya | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.highDangerSaves.player.firstName.cs` | string | Ilja | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.highDangerSaves.player.firstName.fi` | string | Ilja | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.highDangerSaves.player.firstName.sk` | string | Ilja | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.highDangerSaves.player.lastName.default` | string | Sorokin | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.highDangerSaves.player.sweaterNumber` | integer | 30 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.highDangerSaves.player.position` | string | G | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.highDangerSaves.player.slug` | string | ilya-sorokin-8478009 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.highDangerSaves.player.headshot` | string | https://assets.nhle.com/mugs/nhl/20252026/NYI/8478009.png | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.highDangerSaves.player.team.commonName.default` | string | Islanders | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.highDangerSaves.player.team.placeNameWithPreposition.default` | string | New York | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.highDangerSaves.player.team.placeNameWithPreposition.fr` | string | de New York | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.highDangerSaves.player.team.abbrev` | string | NYI | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.highDangerSaves.player.team.teamLogo.light` | string | https://assets.nhle.com/logos/nhl/svg/NYI_light.svg | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.highDangerSaves.player.team.teamLogo.dark` | string | https://assets.nhle.com/logos/nhl/svg/NYI_dark.svg | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.highDangerSaves.saves` | integer | 452 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.highDangerSaves.shotLocationDetails[].area` | string | Behind the Net | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.highDangerSaves.shotLocationDetails[].saves` | integer | 9 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.highDangerSaves.shotLocationDetails[].savesPercentile` | decimal | 0.8367 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.highDangerGoalsAgainst.player.id` | integer | 8481020 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.highDangerGoalsAgainst.player.firstName.default` | string | Justus | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.highDangerGoalsAgainst.player.lastName.default` | string | Annunen | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.highDangerGoalsAgainst.player.sweaterNumber` | integer | 29 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.highDangerGoalsAgainst.player.position` | string | G | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.highDangerGoalsAgainst.player.slug` | string | justus-annunen-8481020 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.highDangerGoalsAgainst.player.headshot` | string | https://assets.nhle.com/mugs/nhl/20252026/NSH/8481020.png | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.highDangerGoalsAgainst.player.team.commonName.default` | string | Predators | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.highDangerGoalsAgainst.player.team.placeNameWithPreposition.default` | string | Nashville | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.highDangerGoalsAgainst.player.team.placeNameWithPreposition.fr` | string | de Nashville | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.highDangerGoalsAgainst.player.team.abbrev` | string | NSH | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.highDangerGoalsAgainst.player.team.teamLogo.light` | string | https://assets.nhle.com/logos/nhl/svg/NSH_light.svg | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.highDangerGoalsAgainst.player.team.teamLogo.dark` | string | https://assets.nhle.com/logos/nhl/svg/NSH_dark.svg | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.highDangerGoalsAgainst.goalsAgainst` | integer | 32 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.savePctg5v5.player.id` | integer | 8475809 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.savePctg5v5.player.firstName.default` | string | Scott | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.savePctg5v5.player.lastName.default` | string | Wedgewood | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.savePctg5v5.player.sweaterNumber` | integer | 41 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.savePctg5v5.player.position` | string | G | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.savePctg5v5.player.slug` | string | scott-wedgewood-8475809 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.savePctg5v5.player.headshot` | string | https://assets.nhle.com/mugs/nhl/20252026/COL/8475809.png | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.savePctg5v5.player.team.commonName.default` | string | Avalanche | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.savePctg5v5.player.team.placeNameWithPreposition.default` | string | Colorado | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.savePctg5v5.player.team.placeNameWithPreposition.fr` | string | du Colorado | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.savePctg5v5.player.team.abbrev` | string | COL | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.savePctg5v5.player.team.teamLogo.light` | string | https://assets.nhle.com/logos/nhl/svg/COL_light.svg | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.savePctg5v5.player.team.teamLogo.dark` | string | https://assets.nhle.com/logos/nhl/svg/COL_dark.svg | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.savePctg5v5.savePctg` | decimal | 0.9274 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.gamesAbove900.player.id` | integer | 8480280 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.gamesAbove900.player.firstName.default` | string | Jeremy | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.gamesAbove900.player.lastName.default` | string | Swayman | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.gamesAbove900.player.sweaterNumber` | integer | 1 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.gamesAbove900.player.position` | string | G | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.gamesAbove900.player.slug` | string | jeremy-swayman-8480280 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.gamesAbove900.player.headshot` | string | https://assets.nhle.com/mugs/nhl/20252026/BOS/8480280.png | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.gamesAbove900.player.team.commonName.default` | string | Bruins | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.gamesAbove900.player.team.placeNameWithPreposition.default` | string | Boston | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.gamesAbove900.player.team.placeNameWithPreposition.fr` | string | de Boston | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.gamesAbove900.player.team.abbrev` | string | BOS | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.gamesAbove900.player.team.teamLogo.light` | string | https://assets.nhle.com/logos/nhl/svg/BOS_light.svg?season=20252026 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.gamesAbove900.player.team.teamLogo.dark` | string | https://assets.nhle.com/logos/nhl/svg/BOS_dark.svg?season=20252026 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.leaders.gamesAbove900.games` | integer | 38 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |

## Opportunity

- Add goalie evaluation context beyond wins and save percentage, especially edge save percentage, shot location, and workload indicators.
- Support goalie risk/opportunity summaries for leagues where starter status and quality of chances matter.
- Use carefully as a scouting signal only; do not let NHL Edge shape replace boxscore goalie summaries without validation.

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
