# NHL Player Landing Response

## Endpoint

```text
https://api-web.nhle.com/v1/player/{playerId}/landing
```

Config key:

```text
nhl.player_landing
```

Current DynastyIQ consumers:

- `App\Services\ImportNHLPlayer`
- `App\Jobs\ImportPlayersJob`
- `App\Jobs\RefreshNhlPlayerLandingJob`
- `App\Services\NhlPlayerIdentityLookup`
- `App\Services\PlayerIdentityResolver`
- `App\Services\NhlTeamReference`
- `App\Services\SumNHLPlayByPlay`

## Sample Source

- `docs/api_responses/samples/nhlPlayerLanding.txt`

The sample is a browser/object-inspector dump of the response for Connor McDavid (`playerId = 8478402`). It is not literal JSON text, but it preserves field names, nested shapes, arrays, and observed values.

## Purpose

This endpoint is the primary NHL player identity/profile response. DynastyIQ uses it to validate a known NHL player id, create or update canonical player data, attach NHL external identities, import player season totals, and enrich team reference data.

## Observations For DynastyIQ

The observed response combines durable identity fields, current-team profile fields, presentation assets, high-level featured/career totals, game-log snippets, full season totals, awards, and a current-team roster list.

Only the identity/profile and `seasonTotals` portions are currently part of DynastyIQ's import contract. `featuredStats`, `careerTotals`, `last5Games`, `awards`, and `currentTeamRoster` are observed but should remain presentation or future-planning inputs until a specific consumer is designed.

## Top-Level Observed Shape

| Section | Observed Shape | Observed Values / Notes |
| --- | --- | --- |
| Identity | scalar fields plus localized name objects | `playerId`, `firstName`, `lastName`, `playerSlug` |
| Current team | scalar fields plus localized team objects | `currentTeamId = 22`, `currentTeamAbbrev = EDM`, team names/logos |
| Biographical profile | scalar fields | height, weight, birth date/place/country, shoots/catches |
| Draft profile | `draftDetails` object | 2015, EDM, round 1, pick 1, overall 1 |
| Presentation flags/assets | arrays/scalars/URLs | `badges`, `teamLogo`, `headshot`, `heroImage`, `inTop100AllTime`, `inHHOF` |
| Featured stats | `featuredStats` object | Current/recent regular-season and playoff stat summaries |
| Career totals | `careerTotals` object | Regular season and playoff career totals |
| Recent games | `last5Games` array | Five game-log rows |
| Season totals | `seasonTotals` array | 36 historical rows across NHL, junior, international, and playoffs |
| Awards | `awards` array | Trophy objects with season stat rows |
| Current team roster | `currentTeamRoster` array | 24 teammate identity snippets |

## Field Map

| Path | Type / Shape | Observed Values | Meaning | DynastyIQ Usage | Must Not Drive |
| --- | --- | --- | --- | --- | --- |
| `playerId` | integer | `8478402` | Stable NHL player id. | `players.nhl_id`; NHL external identity provider id. | Fantrax/Yahoo identity replacement without resolver evidence. |
| `isActive` | boolean | `true` | NHL active flag. | Profile context. | Fantasy roster availability by itself. |
| `currentTeamId` | integer | `22` | Current NHL team id. | `players.nhl_team_id`; team reference enrichment. | Historical game team assignment. |
| `currentTeamAbbrev` | string | `EDM` | Current NHL team abbreviation. | `players.team_abbrev`; identity payload team. | Game-specific team assignment. |
| `fullTeamName.default`, `fullTeamName.fr` | localized object | `Edmonton Oilers`; French value. | Current team full display name. | Team reference/profile display. | Stable franchise identity by itself. |
| `teamCommonName.default` | localized object | `Oilers` | Current team common display name. | Team reference/profile display. | Roster membership. |
| `teamPlaceNameWithPreposition.*` | localized object | `Edmonton`; French value. | Team place display text. | Team reference/profile display. | Team identity by itself. |
| `teamLogo` | URL string | NHL assets SVG URL. | Current team logo asset. | Future profile/team UI after verification. | Team identity. |
| `firstName.default` | localized object | `Connor` | First name. | Canonical player name; identity matching. | Unique identity alone. |
| `lastName.default` | localized object | `McDavid` | Last name. | Canonical player name; identity matching. | Unique identity alone. |
| `badges` | array | Empty array in sample. | Presentation badges. | Future UI only. | Import/identity decisions. |
| `sweaterNumber` | integer | `97` | Current sweater number. | Profile display. | Stable identity. |
| `position` | string | `C` | NHL position code. | Canonical position and skater/goalie matching. | League-specific fantasy eligibility. |
| `headshot` | URL string | NHL mugshot URL with season/team/player path. | Player headshot asset. | `players.head_shot_url`. | Player identity without `playerId`. |
| `heroImage` | URL string | NHL action-shot URL. | Player hero/profile media. | `players.hero_image_url`. | Import identity or stats. |
| `heightInInches`, `heightInCentimeters` | integer | `73`, `185` | Height. | Future profile display. | Stat calculations. |
| `weightInPounds`, `weightInKilograms` | integer | `194`, `88` | Weight. | Future profile display. | Stat calculations. |
| `birthDate` | date string | `1997-01-13` | Birth date. | `players.dob`; identity matching/profile. | Prospect eligibility by itself. |
| `birthCity.default` | localized object | `Richmond Hill` | Birth city. | Future profile display. | Identity by itself. |
| `birthStateProvince.default` | localized object | `Ontario` | Birth region. | Future profile display. | Identity by itself. |
| `birthCountry` | string | `CAN` | Birth country code. | `players.country_code`. | League/team assignment. |
| `shootsCatches` | string | `L` | Handedness. | Future profile display. | Position or eligibility. |
| `draftDetails.year` | integer | `2015` | Draft year. | `players.draft_year`; prospect/profile context. | Fantasy draft assets. |
| `draftDetails.teamAbbrev` | string | `EDM` | Drafting NHL team abbreviation. | Profile context. | Current team. |
| `draftDetails.round` | integer | `1` | Draft round. | `players.draft_round`. | Fantasy draft round. |
| `draftDetails.pickInRound` | integer | `1` | Pick within round. | `players.draft_round_pick`. | Fantasy draft pick. |
| `draftDetails.overallPick` | integer | `1` | Overall NHL draft pick. | `players.draft_oa`. | Fantasy draft pick. |
| `playerSlug` | string | `connor-mcdavid-8478402` | NHL slug. | Future URL/display context. | Provider id replacement. |
| `inTop100AllTime`, `inHHOF` | integer flags | `0`, `0` | NHL presentation/accolade flags. | Future profile UI. | Stat ranking logic. |
| `shopLink`, `twitterLink`, `watchLink` | string | `#TODO` in sample. | Placeholder external links. | Do not use until real values observed. | Any user-facing link. |

## Featured And Career Stats

| Path | Type / Shape | Observed Values | Meaning | DynastyIQ Usage | Must Not Drive |
| --- | --- | --- | --- | --- | --- |
| `featuredStats.season` | integer | `20252026` | Featured stat season. | Future profile display. | Full season import identity. |
| `featuredStats.regularSeason.subSeason` | object | 14 skater stat fields. | Featured current/recent regular-season totals. | Future profile summary. | Authoritative stat import while `seasonTotals` exists. |
| `featuredStats.regularSeason.career` | object | 14 skater stat fields. | Regular-season career totals. | Future profile summary. | Game validation. |
| `featuredStats.playoffs.subSeason` | object | 14 skater stat fields. | Featured playoff totals. | Future profile summary. | Full playoff history. |
| `featuredStats.playoffs.career` | object | 14 skater stat fields. | Playoff career totals. | Future profile summary. | Game validation. |
| `careerTotals.regularSeason` | object | 16 skater stat fields including `avgToi` and `faceoffWinningPctg`. | Career regular-season totals. | Future profile summary. | Row-level season stat import. |
| `careerTotals.playoffs` | object | 16 skater stat fields. | Career playoff totals. | Future profile summary. | Row-level season stat import. |

Observed skater stat fields include `assists`, `gameWinningGoals`, `gamesPlayed`, `goals`, `otGoals`, `pim`, `plusMinus`, `points`, `powerPlayGoals`, `powerPlayPoints`, `shootingPctg`, `shorthandedGoals`, `shorthandedPoints`, `shots`, plus `avgToi` and `faceoffWinningPctg` in career totals.

## Last Five Games

| Path | Type / Shape | Observed Values | Meaning | DynastyIQ Usage | Must Not Drive |
| --- | --- | --- | --- | --- | --- |
| `last5Games` | array of 5 objects | Recent playoff games in sample. | Recent game-log snippet. | Future player profile UI. | Canonical game import or validation. |
| `last5Games[].gameId` | integer | `2025030186`, etc. | NHL game id. | Future link/display. | Scheduling imports by itself. |
| `last5Games[].gameDate` | date string | `2026-04-30` | Game date. | Future display. | Game state. |
| `last5Games[].gameTypeId` | integer | `3` | Game type. | Future display/filter. | Validation status. |
| `last5Games[].homeRoadFlag` | string | `H`, `R` | Home/road marker. | Future display. | Team identity. |
| `last5Games[].opponentAbbrev`, `teamAbbrev` | string | `ANA`, `EDM` | Team/opponent context. | Future display. | Current team. |
| `last5Games[].toi` | string | `24:49`, etc. | Game TOI display. | Future display. | Official game summary over boxscore/PBP. |
| `last5Games[].shifts` | integer | `22` to `27` in sample. | Game shift count. | Future display. | Shift import validation. |

## Season Totals

`seasonTotals` is the current DynastyIQ season-stat import source from this response.

Observed shape:

- Array of 36 rows.
- Rows cover multiple leagues: `QC Int PW`, `GTHL`, `Other`, `OHL`, `WJ18-A`, `WJC-A`, `WC-A`, `WCup`, `WC`, `NHL`, `4 Nations`, `OG`.
- Rows cover regular season and playoffs through `gameTypeId`.
- NHL rows expose richer fields than some junior/international rows.

| Path | Type / Shape | Observed Values | Meaning | DynastyIQ Usage | Must Not Drive |
| --- | --- | --- | --- | --- | --- |
| `seasonTotals[].season` | integer | `20082009` through `20252026` | Season id. | `stats.season_id`. | Game date. |
| `seasonTotals[].leagueAbbrev` | string | `NHL`, `OHL`, `WJC-A`, etc. | League/source competition. | `stats.league_abbrev`; prospect evaluation context. | Current NHL team by itself. |
| `seasonTotals[].teamName.default` | localized object | `Edmonton Oilers`, `Canada`, junior teams. | Team display for stat row. | `stats.team_name`; stat identity. | NHL team id. |
| `seasonTotals[].teamCommonName.default` | localized object, optional | `Oilers`, `Canada`. | Team display. | Future display/team reference. | Stat identity without `teamName`. |
| `seasonTotals[].teamPlaceNameWithPreposition.*` | localized object, optional | Team place text. | Display context. | Future display/team reference. | Current team. |
| `seasonTotals[].gameTypeId` | integer | `2`, `3` | Regular season/playoff marker. | `stats.game_type_id`; stat identity. | NHL import eligibility by itself. |
| `seasonTotals[].sequence` | integer | `1`, `2`, `3`, `11`, `44` | Provider row disambiguator. | Included in stat identity to avoid collisions. | Sort order semantics without verification. |
| `seasonTotals[].gamesPlayed` | integer | Varies. | Games played. | `stats.gp`. | Game validation. |
| `seasonTotals[].goals`, `assists`, `points` | integer | Varies. | Scoring totals. | `stats.g`, `stats.a`, `stats.pts`. | Game-level event truth. |
| `seasonTotals[].pim`, `plusMinus`, `shots` | integer, optional | Varies by league row. | Skater totals. | Stored when present. | Missing fields as zero unless parser intentionally defaults. |
| `seasonTotals[].avgToi` | `MM:SS`, optional | NHL rows. | Average time on ice. | `stats.avg_toi`, derived TOI minutes. | Game-level TOI. |
| `seasonTotals[].faceoffWinningPctg` | decimal, optional | NHL rows. | Faceoff win percentage. | Future stat display. | Faceoff wins/losses without source values. |
| `seasonTotals[].shootingPctg` | decimal, optional | NHL rows. | Shooting percentage. | `stats.shooting_percentage`. | Shot total derivation when `shots` absent. |
| `seasonTotals[].powerPlayGoals`, `powerPlayPoints` | integer, optional | NHL rows. | Power-play totals. | `stats.ppg`, `stats.ppp`. | Full strength split by itself. |
| `seasonTotals[].shorthandedGoals`, `shorthandedPoints` | integer, optional | NHL rows. | Shorthanded totals. | `stats.shg`; future SH points. | Full strength split by itself. |
| `seasonTotals[].otGoals`, `gameWinningGoals` | integer, optional | NHL rows. | Game context totals. | `stats.ot_goals`, `stats.gwg`. | Game event identity. |

## Awards And Current Team Roster

| Path | Type / Shape | Observed Values | Meaning | DynastyIQ Usage | Must Not Drive |
| --- | --- | --- | --- | --- | --- |
| `awards` | array | 5 trophy objects in sample. | Award history snippets. | Future profile UI. | Player ranking or stat import. |
| `awards[].trophy.default`, `.fr` | localized object | Art Ross, Conn Smythe, Hart, Rocket Richard, Ted Lindsay. | Award display name. | Future profile UI. | Stat identity. |
| `awards[].seasons` | array | Season stat summary rows. | Award-winning season context. | Future profile UI. | `stats` import while `seasonTotals` exists. |
| `currentTeamRoster` | array | 24 player snippets. | Current team roster context. | Future team/profile navigation. | Roster sync or current team authority for other players. |
| `currentTeamRoster[].playerId` | integer | NHL player ids. | Teammate identity snippet. | Future navigation only. | Canonical player creation without landing. |
| `currentTeamRoster[].firstName.default`, `lastName.default` | localized object | Teammate names. | Teammate display. | Future navigation only. | Unique identity. |
| `currentTeamRoster[].playerSlug` | string | NHL slug. | Teammate URL/display context. | Future navigation only. | Provider id replacement. |

## Opportunity

- Player profile pages could use observed assets and presentation fields immediately: `headshot`, `heroImage`, current team logo/name, sweater number, height, weight, birthplace, draft details, and handedness.
- `awards` is a strong profile storytelling feature. DynastyIQ could surface trophies and award-season stat lines without building a separate awards import.
- `last5Games` can power a compact recent-form card on player pages, especially when paired with existing DynastyIQ game summaries for richer context.
- `currentTeamRoster` enables teammate navigation and "team context" modules from a single player profile, but teammate player ids should be verified through player landing before creating canonical rows.
- `careerTotals` and `featuredStats` can provide fast profile header summaries without aggregating local stat rows, but should remain presentation-only until multi-sample consistency is verified.
- `seasonTotals` includes non-NHL leagues and international tournaments. That can improve prospect context, current non-NHL league labeling, and historical player-path displays beyond NHL-only stats.

## Parser Contract

- Require `playerId` before mutating canonical player or NHL identity data.
- Prefer localized `default` strings for canonical display names.
- Upsert NHL external identity from the full landing payload and preserve raw payload for audit.
- Position-type matching must distinguish goalie from skater before mutating a canonical player.
- Landing identity enrichment must not create a duplicate when the resolved NHL id already belongs to another canonical player; use the resolver/merge workflow.
- Treat 404 as provider-unavailable for that player id where the calling importer has documented skip behavior. Treat non-404 failures as retryable or fatal per importer rules.
- Use `seasonTotals` for persisted player season stats; do not import stats from `featuredStats`, `careerTotals`, `last5Games`, or `awards` unless a new consumer is explicitly designed.
- Upsert season-total rows using `player_id`, `season`, `leagueAbbrev`, `teamName.default`, `gameTypeId`, and `sequence`.
- Optional stat fields must remain nullable or intentionally defaulted; one observed sample shows junior/international rows with fewer fields than NHL rows.

## Expected Normalized Output

- Canonical `players` row with NHL id, name, position, DOB, country, current NHL team context, draft details, and media URLs currently consumed by code.
- `player_external_identities` NHL row linked to the canonical player.
- NHL team reference row when current-team fields are present.
- `stats` rows from `seasonTotals`.

## Open Verification Questions

- Do goalie landing responses expose goalie-specific `seasonTotals` fields such as wins, losses, saves, save percentage, GAA, and shots against in the same array?
- Do inactive, historical, or non-NHL-contracted players consistently return `currentTeamRoster` or current-team fields?
- Are `shopLink`, `twitterLink`, and `watchLink` always placeholders or only placeholders in this sample?
- Is `sequence` stable enough across refreshes to remain part of stat identity for every player type?
