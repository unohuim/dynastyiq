# NHL Player Landing Edge Response

## Endpoint

The sample corresponds to the NHL Edge data associated with player landing pages. The exact route is not currently configured in DynastyIQ.

Current DynastyIQ consumers:

- None.

## Sample Source

- `docs/api_responses/samples/nhlPlayerLandingEdge.txt`

The sample is a browser/object-inspector dump. It is not literal JSON text, but it preserves field names, nested shapes, arrays, and observed values.

## Purpose

This response exposes NHL Edge availability by season/game type plus league-leader style Edge metrics. It is not currently part of DynastyIQ imports.

## Observations For DynastyIQ

The sample is not player-specific in the same way `/v1/player/{playerId}/landing` is. The `leaders` object contains league leaders across multiple Edge categories, each with a player/team snippet and category-specific metric payload. This belongs to future presentation, comparison, or advanced-stats planning, not canonical player identity or game import validation.

## Top-Level Observed Shape

| Section | Observed Shape | Observed Values / Notes |
| --- | --- | --- |
| `seasonsWithEdgeStats` | array | 5 seasons, `20212022` through `20252026` |
| `seasonsWithEdgeStats[].gameTypes` | array | `[2, 3]` for each observed season |
| `leaders` | object | 7 observed leader categories |

## Leader Categories Observed

| Path | Metric Fields | Observed Leader | Meaning | DynastyIQ Usage | Must Not Drive |
| --- | --- | --- | --- | --- | --- |
| `leaders.hardestShot` | `shotSpeed.imperial`, `shotSpeed.metric`, overlay context | Tage Thompson | Hardest shot leader. | Future NHL Edge UI. | Canonical shot stats or game validation. |
| `leaders.maxSkatingSpeed` | `skatingSpeed.imperial`, `skatingSpeed.metric`, overlay context | Miles Wood | Max skating speed leader. | Future NHL Edge UI. | Player ranking without season/game-type context. |
| `leaders.totalDistanceSkated` | `distanceSkated.imperial`, `distanceSkated.metric` | Zach Werenski | Total distance leader. | Future NHL Edge UI. | TOI or shift summary. |
| `leaders.distanceMaxGame` | `distanceSkated.imperial`, `distanceSkated.metric`, overlay context | Zach Werenski | Single-game distance leader. | Future NHL Edge UI. | Shiftchart replacement. |
| `leaders.highDangerSOG` | `sog`, `shotLocationDetails[]` | Anders Lee | High-danger shots-on-goal leader. | Future shot-location UI. | PBP shot location truth without endpoint-specific import design. |
| `leaders.offensiveZoneTime` | `zoneTime` | Shayne Gostisbehere | Offensive-zone time leader. | Future NHL Edge UI. | Unit or possession model by itself. |
| `leaders.defensiveZoneTime` | `zoneTime` | Shayne Gostisbehere | Defensive-zone time leader. | Future NHL Edge UI. | Defensive player valuation by itself. |

## Repeated Player Snippet Shape

| Path | Type / Shape | Observed Values | Meaning | DynastyIQ Usage | Must Not Drive |
| --- | --- | --- | --- | --- | --- |
| `leaders.*.player.id` | integer | NHL player ids. | Leader player identity. | Future link to canonical player. | Canonical player creation without landing verification. |
| `leaders.*.player.firstName.default`, `lastName.default` | localized object | Player names. | Display name. | Future UI. | Unique identity. |
| `leaders.*.player.sweaterNumber` | integer | Jersey number. | Display context. | Future UI. | Identity. |
| `leaders.*.player.position` | string | `C`, `L`, `D`. | NHL position code. | Future UI/filter. | Fantasy eligibility. |
| `leaders.*.player.slug` | string | NHL slug. | URL/display context. | Future UI. | Provider id replacement. |
| `leaders.*.player.headshot` | URL string | NHL mugshot URL. | Player image. | Future UI. | Identity. |
| `leaders.*.player.team` | object | Common name, place name, abbrev, light/dark logos. | Current team display context for leader. | Future UI. | Durable current-team authority without landing/team endpoint. |

## Overlay Shape

Some categories include an `overlay` object with game context.

| Path | Type / Shape | Observed Values | Meaning | DynastyIQ Usage | Must Not Drive |
| --- | --- | --- | --- | --- | --- |
| `overlay.player.firstName.default`, `lastName.default` | localized object | Repeated player display name. | Overlay display. | Future UI. | Identity by itself. |
| `overlay.gameDate` | date string | `2024-12-31`, `2025-04-10`, `2025-03-24`. | Game date for the leader event. | Future UI/context. | Game import scheduling. |
| `overlay.awayTeam`, `overlay.homeTeam` | object | Abbrev and score. | Game matchup context. | Future UI. | Official boxscore. |
| `overlay.gameOutcome.lastPeriodType` | string | `REG`, `SO`. | Game-ending period type. | Future UI/context. | Validation status. |
| `overlay.periodDescriptor` | object | `maxRegulationPeriods`, `number`, `periodType`. | Period context. | Future UI/context. | Shift/game-end boundary without PBP. |
| `overlay.timeInPeriod` | string, optional | `07:40`, `07:45`. | Time of event in period. | Future UI/context. | PBP event identity. |
| `overlay.gameType` | integer | `2`. | Game type. | Future filters. | Import eligibility without canonical enum handling. |

## Shot Location Details

`leaders.highDangerSOG.shotLocationDetails` is an array of 17 location buckets in the sample.

Observed fields:

- `area`: display bucket, such as `Low Slot`, `Crease`, `L Circle`, `R Point`.
- `sog`: shots on goal count for that area.
- `sogPercentile`: percentile-like decimal from `0` to `1`.

This is a derived NHL Edge payload and should not be merged with DynastyIQ PBP shot-location logic unless a specific NHL Edge import contract is created.

## Opportunity

- The `leaders` object can power league-wide NHL Edge leaderboard cards without calculating tracking stats locally.
- Each leader category can become a player badge concept: hardest shot, fastest skater, distance workhorse, high-danger shooter, offensive-zone driver, defensive-zone deployment.
- `seasonsWithEdgeStats` can gate UI controls so DynastyIQ only offers NHL Edge views for seasons/game types where the provider says Edge data exists.
- The high-danger SOG leader includes location-bucket detail, which could seed a shot-profile visual vocabulary before deeper per-player Edge imports exist.
- Overlay game context gives story hooks for leader cards: when the max event happened, opponent, score, period, and time.
- Team logo and player headshot snippets make this endpoint useful for polished standalone widgets, even before persistent Edge tables are designed.

## Parser Contract

- Do not add this endpoint to production import code until the exact URL, parameters, and response stability are verified.
- Treat `seasonsWithEdgeStats` as availability metadata, not as proof that DynastyIQ has imported those stats.
- Treat `leaders` as presentation/leaderboard data, not player identity authority.
- If linked to canonical players, validate `leaders.*.player.id` through player landing or an existing NHL identity.
- Preserve imperial and metric values separately; do not infer unit conversions from one sample.
- Do not mix NHL Edge shot-location details with PBP shot geometry without a dedicated mapping.

## Expected Normalized Output

No current normalized output. Future work could define:

- NHL Edge leader cards.
- Player profile Edge badges.
- Season/game-type Edge availability metadata.
- Shot-location leader visualizations.

## Open Verification Questions

- What is the exact endpoint path and required parameters for this response?
- Is the response global, player-specific, or landing-page contextual?
- Are all leader categories always present?
- Are `zoneTime` values percentages, shares, or normalized rates?
- Do playoff (`gameType = 3`) leader categories appear when requested?
