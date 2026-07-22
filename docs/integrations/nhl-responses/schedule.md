# NHL Schedule Response

## Endpoint

| Field | Value |
| --- | --- |
| Method | `GET` |
| Path | `/score/{date}` |
| Source Description | See the NHL API reference for provider-authored description. This local row maps the endpoint to DynastyIQ generated files. |
| Example cURL | `curl -X GET "https://api-web.nhle.com/v1/score/2025-02-01"` |

Current DynastyIQ consumers:

- Possible overlap with NHL game import/discovery workflows; exact consumers were not verified by this generated document.

## Sample Source

- `docs/api_responses/samples/nhlApi-schedule.txt`

The sample was generated from the runnable cURL example recorded in `docs/integrations/nhl-responses/nhl_api_index.md`.

## Purpose

Document the observed NHL API payload shape and identify DynastyIQ opportunities before implementation treats any field as authoritative.

## Observations For DynastyIQ

- Top-level observed shape: prevDate, currentDate, nextDate, gameWeek, oddsPartners, games
- This document is generated from a real raw payload and still needs human interpretation before fields become product or import authority.
- Preserve provider field names in raw-response docs; normalize only inside implementation contracts.

## Top-Level Observed Shape

| Section | Observed Shape | Observed Values / Notes |
| --- | --- | --- |
| `prevDate` | string | 2025-01-31 |
| `currentDate` | string | 2025-02-01 |
| `nextDate` | string | 2025-02-02 |
| `gameWeek` | array[7] | 7 row(s). |
| `oddsPartners` | array[7] | 7 row(s). |
| `games` | array[9] | 9 row(s). |

## Observed Field Inventory

| Path | Type / Shape | Observed Values | Meaning | DynastyIQ Usage | Must Not Drive |
| --- | --- | --- | --- | --- | --- |
| `$.prevDate` | string | 2025-01-31 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.currentDate` | string | 2025-02-01 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.nextDate` | string | 2025-02-02 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.gameWeek[].date` | string | 2025-01-29 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.gameWeek[].dayAbbrev` | string | WED | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.gameWeek[].numberOfGames` | integer | 5 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.oddsPartners[].partnerId` | integer | 2 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.oddsPartners[].country` | string | SE | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.oddsPartners[].name` | string | Unibet | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.oddsPartners[].imageUrl` | string | https://assets.nhle.com/betting_partner/unibet.svg | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.oddsPartners[].siteUrl` | string | https://www.unibet.se/betting/sports/filter/ice_hockey/nhl/all/matches | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.oddsPartners[].bgColor` | string | #14805E | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.oddsPartners[].textColor` | string | #FFFFFF | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.oddsPartners[].accentColor` | string | #3AAA35 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.games[].id` | integer | 2024020826 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.games[].season` | integer | 20242025 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.games[].gameType` | integer | 2 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.games[].gameDate` | string | 2025-02-01 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.games[].venue.default` | string | Amerant Bank Arena | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.games[].startTimeUTC` | string | 2025-02-01T18:00:00Z | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.games[].easternUTCOffset` | string | -05:00 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.games[].venueUTCOffset` | string | -05:00 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.games[].tvBroadcasts[].id` | integer | 384 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.games[].tvBroadcasts[].market` | string | N | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.games[].tvBroadcasts[].countryCode` | string | US | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.games[].tvBroadcasts[].network` | string | ABC | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.games[].tvBroadcasts[].sequenceNumber` | integer | 1 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.games[].gameState` | string | OFF | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.games[].gameScheduleState` | string | OK | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.games[].awayTeam.id` | integer | 16 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.games[].awayTeam.name.default` | string | Blackhawks | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.games[].awayTeam.abbrev` | string | CHI | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.games[].awayTeam.score` | integer | 1 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.games[].awayTeam.sog` | integer | 25 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.games[].awayTeam.logo` | string | https://assets.nhle.com/logos/nhl/svg/CHI_light.svg | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.games[].homeTeam.id` | integer | 13 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.games[].homeTeam.name.default` | string | Panthers | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.games[].homeTeam.abbrev` | string | FLA | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.games[].homeTeam.score` | integer | 5 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.games[].homeTeam.sog` | integer | 44 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.games[].homeTeam.logo` | string | https://assets.nhle.com/logos/nhl/svg/FLA_light.svg | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.games[].gameCenterLink` | string | /gamecenter/chi-vs-fla/2025/02/01/2024020826 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.games[].threeMinRecap` | string | /video/chi-at-fla-recap-6368128667112 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.games[].threeMinRecapFr` | string | /fr/video/chi-vs-fla-01-02-2025-resume-6368130326112 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.games[].condensedGame` | string | /video/chi-at-fla-condensed-game-6368129736112 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.games[].condensedGameFr` | string | /fr/video/match-condense-blackhawks-panthers01-02-2025-6368130514112 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.games[].clock.timeRemaining` | string | 00:00 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.games[].clock.secondsRemaining` | integer | 0 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.games[].clock.running` | boolean | false | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.games[].clock.inIntermission` | boolean | false | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.games[].neutralSite` | boolean | false | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.games[].venueTimezone` | string | US/Eastern | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.games[].period` | integer | 3 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.games[].periodDescriptor.number` | integer | 3 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.games[].periodDescriptor.periodType` | string | REG | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.games[].periodDescriptor.maxRegulationPeriods` | integer | 3 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.games[].gameOutcome.lastPeriodType` | string | REG | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.games[].goals[].period` | integer | 1 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.games[].goals[].periodDescriptor.number` | integer | 1 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.games[].goals[].periodDescriptor.periodType` | string | REG | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.games[].goals[].periodDescriptor.maxRegulationPeriods` | integer | 3 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.games[].goals[].timeInPeriod` | string | 00:07 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.games[].goals[].playerId` | integer | 8482172 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.games[].goals[].name.default` | string | L. Slaggert | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.games[].goals[].firstName.default` | string | Landon | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.games[].goals[].lastName.default` | string | Slaggert | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.games[].goals[].goalModifier` | string | none | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.games[].goals[].assists[].playerId` | integer | 8477987 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.games[].goals[].assists[].name.default` | string | R. Donato | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.games[].goals[].assists[].assistsToDate` | integer | 15 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.games[].goals[].mugshot` | string | https://assets.nhle.com/mugs/nhl/20242025/CHI/8482172.png | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.games[].goals[].teamAbbrev` | string | CHI | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.games[].goals[].goalsToDate` | integer | 2 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.games[].goals[].awayScore` | integer | 1 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.games[].goals[].homeScore` | integer | 0 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.games[].goals[].strength` | string | ev | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.games[].goals[].highlightClipSharingUrl` | string | https://nhl.com/video/chi-fla-slaggert-scores-goal-against-sergei-bobrovsky-6368122979112 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.games[].goals[].highlightClipSharingUrlFr` | string | https://nhl.com/fr/video/chi-fla-slaggert-marque-un-but-contre-sergei-bobrovsky-6368123358112 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.games[].goals[].highlightClip` | integer | 6368122979112 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.games[].goals[].highlightClipFr` | integer | 6368123358112 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.games[].goals[].discreteClip` | integer | 6368123538112 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |
| `$.games[].goals[].discreteClipFr` | integer | 6368123441112 | Observed provider field. | Candidate input only after source precedence is reviewed. | Do not treat as canonical without implementation-specific validation. |

## Opportunity

- Review for source-specific metadata that can reduce manual imports, improve admin troubleshooting, or enrich player/team pages.
- Use only after source precedence, identity matching, and update cadence are documented.
- The payload shape suggests possible utility, but no product use should be assumed until reviewed against DynastyIQ workflows.

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
