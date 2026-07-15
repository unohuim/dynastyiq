# Fantrax getLeagueInfo Response

## Endpoint

```text
https://www.fantrax.com/fxea/general/getLeagueInfo?leagueId=[LEAGUE_ID]
```

Config key:

```text
fantrax.league_info
```

Current DynastyIQ consumers:

- `App\Services\SyncFantraxLeague`
- `App\Services\FantraxScoringCategoryMapper`
- `App\Services\PlatformLeagueScoringCategoryService`
- `App\Services\FantraxDraftingWindow`

## Purpose

This is the primary league setup response. It describes league-level configuration, teams, matchups, roster constraints, player-pool metadata, scoring settings, schedule periods, draft settings, and league identity fields.

DynastyIQ uses this response to understand how a Fantrax league should behave before or alongside roster, scoring, and draft sync.

## Observations For DynastyIQ

`getLeagueInfo` is where Fantrax exposes the difference between an ordinary league and a larger community structure. `duplicatePlayerType = ACROSS_DIVISIONS` is especially important because it suggests one Fantrax league can contain multiple parallel player universes, which affects draft availability, roster views, trade context, and commissioner management. CLH shows that divisions can also exist with `duplicatePlayerType = NONE`, so DynastyIQ must not treat division labels alone as player-pool boundaries.

## Sample Sources

- `docs/api_responses/leagueinfo_sdl.txt`: Super Duper League.
- `docs/api_responses/leagueinfo_fhl.txt`: FHL Tiered Dynasty.
- `docs/api_responses/samples/getLeagueInfo_clh.txt`: Champions League of Hockey.

## Major Observed League Differences

| Area | SDL Sample | CLH Sample | FHL Sample | Meaning For DynastyIQ |
| --- | --- | --- | --- | --- |
| `leagueName` | `Super Duper League` | `Champions League of Hockey` | `FHL Tiered Dynasty` | Display identity only; stable identity remains provider league id. |
| `poolSettings.duplicatePlayerType` | `NONE` | `NONE` | `ACROSS_DIVISIONS` | SDL/CLH have one league-wide player universe. FHL has division-scoped player pools where the same player can be owned in multiple divisions. |
| Division/team shape | Top-level team map entries. | `teamInfo` entries with two divisions. | Division labels with duplicate-player pools. | Consumers must read both top-level team maps and `teamInfo`; division labels are structure, not player-pool scope by themselves. |
| `playerInfo` shape | Flat map keyed by Fantrax player id. | Flat map keyed by Fantrax player id. | Grouped map keyed by division name, then Fantrax player id. | Importers and availability logic must handle both shapes. |
| `rosterInfo.positionConstraints` | C 4, LW 4, RW 4, D 5, G 2, Skt 1. | C 4, D 6, RW 3, F 1, G 2, LW 3, Skt 1. | C 3, LW 3, RW 3, D 6, G 2. | Roster UI and validation must be league-specific. |
| `rosterInfo.maxTotalPlayers` | 51 | 80 | 29 | Roster capacity differs materially. |
| `rosterInfo.maxTotalActivePlayers` | 20 | 20 | 17 | Active roster count differs materially. |
| `rosterInfo.maxTotalReservePlayers` | 4 | 6 | 29 | Reserve capacity differs materially. |
| `scoringPeriods` | 27 | Observed in response. | 25 | League matchup/scoring cadence differs. |
| `scoringSystem.type` | `points` | `rotisserie` | `points` | Points and rotisserie leagues require different stat presentation. |
| `scoringSystem.scoringCategories.SKATING` | 8 categories. | Rotisserie category setup. | 12 categories. | Scoring sync must preserve provider setup instead of assuming one league scoring model. |
| `scoringSystem.scoringCategories.GOALIE` | 7 categories. | Rotisserie category setup. | 5 categories. | SDL includes goalie offensive scoring; FHL does not in the sample. |
| Position-specific scoring | Not observed. | Not central to observed difference. | Defense has different G/A values. | Scoring sync must preserve position-specific weights. |

## Sample Response Excerpts

SDL roster and pool shape:

```json
{
  "rosterInfo": {
    "positionConstraints": {
      "C": { "maxActive": 4 },
      "D": { "maxActive": 5 },
      "RW": { "maxActive": 4 },
      "G": { "maxActive": 2 },
      "LW": { "maxActive": 4 },
      "Skt": { "maxActive": 1 }
    },
    "maxTotalPlayers": 51,
    "maxTotalActivePlayers": 20,
    "maxTotalReservePlayers": 4,
    "endDate": "2027-04-11"
  },
  "playerInfo": {
    "04qyi": {
      "eligiblePos": "D,Skt",
      "status": "FA"
    }
  },
  "poolSettings": {
    "duplicatePlayerType": "NONE",
    "playerSourceType": "ALL_TEAMS"
  }
}
```

FHL roster and pool shape:

```json
{
  "rosterInfo": {
    "positionConstraints": {
      "C": { "maxActive": 3 },
      "D": { "maxActive": 6 },
      "RW": { "maxActive": 3 },
      "G": { "maxActive": 2 },
      "LW": { "maxActive": 3 }
    },
    "maxTotalPlayers": 29,
    "maxTotalActivePlayers": 17,
    "maxTotalReservePlayers": 29,
    "endDate": "2027-04-11"
  },
  "playerInfo": {
    "Jagr": {
      "04qyi": {
        "eligiblePos": "D",
        "status": "FA"
      }
    }
  },
  "poolSettings": {
    "duplicatePlayerType": "ACROSS_DIVISIONS",
    "playerSourceType": "ALL_TEAMS"
  }
}
```

FHL position-specific scoring excerpt:

```json
{
  "scoringSystem": {
    "scoringCategories": {
      "SKATING": {
        "A": {
          "D": "points4",
          "Default": "points3"
        },
        "G": {
          "D": "points6",
          "Default": "points4.5"
        }
      }
    }
  }
}
```

## Field Map

| Path | Type / Shape | Observed Values | Meaning | DynastyIQ Usage | Must Not Drive |
| --- | --- | --- | --- | --- | --- |
| `matchups` | array | SDL `[27]`, FHL `[25]` | League matchup schedule by scoring period. | Schedule context and display. | Roster membership or scoring category identity. |
| `matchups[].period` | integer | `1`, `2`, etc. | Matchup period number. | Align display with scoring periods. | Calendar dates by itself. |
| `matchups[].matchupList` | array | SDL first period `[24]`, FHL first period `[45]` | Matchups in a period. | Matchup display and league structure clues. | Team ownership. |
| `matchups[].matchupList[].away.id` | string | Fantrax team id or omitted when TBD. | Away team provider id. | Matchup display. | User ownership or roster membership. |
| `matchups[].matchupList[].home.id` | string | Fantrax team id or omitted when TBD. | Home team provider id. | Matchup display. | User ownership or roster membership. |
| Team map entries | object keyed by Fantrax team id | `division`, `name`, `id` | Provider team metadata and division label. | Team display, division/pool modeling candidate. | User ownership by itself. |
| `rosterInfo.positionConstraints` | object | Position codes mapped to `maxActive`. | Active lineup slot limits by position. | Roster display, slot ordering/constraints, validation hints. | Current roster membership. |
| `rosterInfo.maxTotalPlayers` | integer | SDL `51`, FHL `29` | Total roster size limit. | League settings display and validation hints. | Current roster count by itself. |
| `rosterInfo.maxTotalActivePlayers` | integer | SDL `20`, FHL `17` | Active roster size limit. | League settings display and validation hints. | Player status. |
| `rosterInfo.maxTotalReservePlayers` | integer | SDL `4`, FHL `29` | Reserve roster size limit. | League settings display and validation hints. | Reserve membership by itself. |
| `rosterInfo.endDate` | date string | `2027-04-11` | League season end date. | League setup display and schedule context. | Roster period boundaries by itself. |
| `playerInfo` | object | Flat player map or division-grouped player map. | League player-pool metadata. | Eligibility and availability enrichment. | Canonical player identity by itself. |
| `playerInfo.*.eligiblePos` | string | SDL examples include `D,Skt`; FHL examples include `D`. | League-specific Fantrax eligibility string. | Preferred eligibility source when syncing rostered players. | Current roster slot. |
| `playerInfo.*.status` | string | `FA`, `T` | Player-pool availability status. | Availability signal, scoped by duplicate-player rules. | Active/bench/minors roster status. |
| `poolSettings.duplicatePlayerType` | string | `NONE`, `ACROSS_DIVISIONS` | Player duplication boundary. | Determines whether availability is league-wide or division-scoped. | Roster membership without team/division context. |
| `poolSettings.playerSourceType` | string | `ALL_TEAMS` | Real-life player source pool. | League setup context. | Fantasy availability by itself. |
| `rosterPeriods` | array | `[195]` in both samples. | Daily roster periods with start/end timestamps. | Calendar and lineup-period context. | Scoring periods by itself. |
| `scoringPeriods` | array | SDL `[27]`, FHL `[25]` | Scoring matchup periods with start/end timestamps. | Matchup/draft/league display context. | Roster periods by itself. |
| `draftType` | string | `snake` | Provider draft type summary. | Draft display/bootstrap context. | Complete draft state. |
| `draftSettings.draftType` | string | `snake` | Draft type inside settings object. | Draft display/bootstrap context. | Complete draft state. |
| `leagueName` | string | SDL/FHL names. | Provider league display name. | Stored/displayed as platform league name. | Stable identity. |
| `leagueHistoryId` | string | Provider history id. | Cross-season Fantrax history identifier. | Candidate future league continuity key. | Current provider league id replacement without proof. |
| `scoringSystem.scoringCategories` | object | Shorthand scoring category weights by group. | Provider scoring summary. | Fallback/reconciliation for scoring sync. | Sole scoring source when rich settings exist. |
| `scoringCategorySettings[].configs` | array | Rich scoring configs. | Detailed category rows with category id/name/shortName/points and position. | Preferred scoring category source. | Roster stats by itself. |
| `scoringSystem.type` | string | `points` | Scoring system type. | Determines points vs category behavior. | Category support by itself. |
| `seasonYear` | integer | `2026` | Fantrax season year. | League setup and scoring metadata. | NHL stats season mapping by itself. |
| `startDate` | date string | `2026-09-29` | League season start date. | League setup and schedule context. | Roster membership by itself. |

## Top-Level Shape

The response is a single object. Consumers should tolerate missing optional sections but should not silently invent league behavior when setup-critical sections are absent.

Known top-level sections from the SDL/FHL samples:

| Section | Required For | Shape |
| --- | --- | --- |
| `matchups` | Matchup/schedule display. | Array of period objects. |
| `rosterInfo` | Roster constraints and roster-setting display. | Object. |
| `playerInfo` | League-specific player eligibility and availability. | Flat player map or division-grouped player map. |
| `poolSettings` | Player-pool semantics. | Object. |
| `rosterPeriods` | Daily lineup period calendar. | Array of period date objects. |
| `scoringPeriods` | Matchup/scoring period calendar. | Array of period date objects. |
| Team map entries | Team display and division/pool context. | Top-level objects keyed by Fantrax team id. |
| `draftType` | Draft context. | String. |
| `draftSettings` | Draft context. | Object. |
| `leagueName` | League display. | String. |
| `leagueHistoryId` | Cross-season continuity candidate. | String. |
| `scoringSystem` | Scoring setup. | Object. |
| `scoringCategorySettings` | Rich scoring category setup. | Array of grouped config objects. |
| `seasonYear` | Scoring/season metadata. | Integer. |
| `startDate` | League season metadata. | Date string. |

Top-level team map entries are not under a dedicated `teams` key in the observed samples. A team entry is an object with `id`, `name`, and usually `division`, keyed by the provider team id.

## Parser Contract

An implementation should normalize `getLeagueInfo` into separate contracts instead of letting raw Fantrax paths leak through the app.

### League Identity

Read:

- `leagueName`
- `leagueHistoryId`
- `seasonYear`
- `startDate`
- `draftType`
- `draftSettings.draftType`

Rules:

- Use the platform league id from the request/configured league context as stable identity.
- Treat `leagueName` as display text.
- Treat `leagueHistoryId` as a future continuity key candidate, not as a replacement for the current provider league id.

### Teams And Divisions

Read all top-level object values that look like team entries:

- key is the Fantrax team id.
- value has `id` matching the key.
- value has `name`.
- value may have `division`.

Normalized row:

```json
{
  "provider_team_id": "p6m5ywf3mohhymtr",
  "name": "JAG- EL-DUBS",
  "division": "Jagr"
}
```

Rules:

- Preserve `division` exactly as provided.
- Do not infer user ownership from team map entries.
- When `poolSettings.duplicatePlayerType` is `ACROSS_DIVISIONS`, use the team `division` value as the best observed player-pool key until Fantrax exposes a stronger id.

### Matchups

Read:

- `matchups[].period`
- `matchups[].matchupList[].away`
- `matchups[].matchupList[].home`

Normalized row:

```json
{
  "period": 1,
  "away_team_id": "9mai84wmmohhymtn",
  "home_team_id": "38xcda97mohhymtr",
  "away_tbd": false,
  "home_tbd": false
}
```

Rules:

- If a side has `TBD: true`, preserve it as TBD and leave team id/name null.
- Do not create teams from matchup-only data.

### Roster Settings

Read:

- `rosterInfo.positionConstraints`
- `rosterInfo.maxTotalPlayers`
- `rosterInfo.maxTotalActivePlayers`
- `rosterInfo.maxTotalReservePlayers`
- `rosterInfo.endDate`

Normalized position constraint row:

```json
{
  "slot": "D",
  "max_active": 6
}
```

Rules:

- Preserve provider slot codes exactly, including `Skt`.
- Do not assume all leagues include the same slot codes.
- Use this for settings/display/validation hints, not current roster membership.

### Player Info

`playerInfo` has two observed shapes.

Flat shape:

```json
{
  "playerInfo": {
    "04qyi": {
      "eligiblePos": "D,Skt",
      "status": "FA"
    }
  }
}
```

Division-grouped shape:

```json
{
  "playerInfo": {
    "Jagr": {
      "04qyi": {
        "eligiblePos": "D",
        "status": "FA"
      }
    }
  }
}
```

Detection rules:

- If a direct child of `playerInfo` has `eligiblePos` or `status`, treat `playerInfo` as a flat player map.
- If a direct child of `playerInfo` is an object whose children have `eligiblePos` or `status`, treat the direct child key as `pool_key` and the grandchildren as players.
- Unknown nested objects should be preserved in raw diagnostics and skipped by strict normalizers until documented.

Normalized player-pool row:

```json
{
  "provider_player_id": "04qyi",
  "pool_key": "Jagr",
  "eligible_positions": ["D"],
  "provider_status": "FA"
}
```

Rules:

- For flat player maps, set `pool_key` to null.
- Split `eligiblePos` on commas, trim tokens, and preserve provider casing.
- Treat `status` as player-pool availability, not roster status.
- With `duplicatePlayerType: NONE`, null `pool_key` represents the whole league pool.
- With `duplicatePlayerType: ACROSS_DIVISIONS`, non-null `pool_key` must be carried into availability and draft logic.

### Pool Settings

Read:

- `poolSettings.duplicatePlayerType`
- `poolSettings.playerSourceType`

Rules:

- `NONE`: a player can be owned only once in the league player universe.
- `ACROSS_DIVISIONS`: a player can be owned once per division/player pool.
- Unknown `duplicatePlayerType` values must be surfaced for review before availability or draft logic trusts them.

### Periods

Read:

- `rosterPeriods[].number`
- `rosterPeriods[].startDate`
- `rosterPeriods[].endDate`
- `scoringPeriods[].number`
- `scoringPeriods[].startDate`
- `scoringPeriods[].endDate`

Normalized period row:

```json
{
  "number": 1,
  "start_at": "2026-09-29T12:00:00.0-0400",
  "end_at": "2026-10-05T11:59:59.0-0400"
}
```

Rules:

- Keep provider timestamps with offsets when preserving raw values.
- Roster periods and scoring periods are different concepts and must not be merged.

### Scoring

Preferred source:

- `scoringCategorySettings[].configs[]`

Fallback/reconciliation source:

- `scoringSystem.scoringCategories`

Rich config normalized row:

```json
{
  "provider_group": "HOCKEY_SKATING",
  "provider_category_id": "2130",
  "provider_code": "INDIVIDUAL_GOALS",
  "provider_short_label": "G",
  "provider_label": "Goals",
  "position_code": "DEFAULT",
  "position_short_label": "Default",
  "points": 4.5,
  "cumulative": true
}
```

Shorthand category normalized row:

```json
{
  "provider_group": "SKATING",
  "provider_short_label": "G",
  "position_values": {
    "Default": "points4.5",
    "D": "points6"
  }
}
```

Rules:

- Prefer rich configs because they include provider category ids and names.
- Preserve position-specific rows such as FHL defense G/A values.
- Normalize shorthand groups for comparison: `SKATING` maps to skating, `GOALIE` maps to goalie.
- Do not collapse position-specific weights into one default value.
- Do not infer provider-earned player stat totals from scoring settings.

## Field Priority Rules

- Stable league identity: request/platform context first, not `leagueName`.
- Team identity: team map `id` or external team id from other endpoint context, not team name.
- Roster membership: `getTeamRosters`, not `playerInfo.status`.
- Eligibility: prefer `getLeagueInfo.playerInfo.*.eligiblePos` when available, then roster/player fallback.
- Roster constraints: `rosterInfo.positionConstraints`.
- Scoring category identity: `scoringCategorySettings` first, `scoringSystem.scoringCategories` second.
- Draft current/results: draft endpoints first; `draftType` and `draftSettings` are setup context only.
- Player-pool scope: `poolSettings.duplicatePlayerType` plus team/playerInfo division keys.

## Expected Normalized Output

A complete parser should be able to produce a structure shaped like:

```json
{
  "league": {
    "name": "FHL Tiered Dynasty",
    "league_history_id": "td26cwr5lvfecemz",
    "season_year": 2026,
    "start_date": "2026-09-29",
    "draft_type": "snake"
  },
  "pool_settings": {
    "duplicate_player_type": "ACROSS_DIVISIONS",
    "player_source_type": "ALL_TEAMS"
  },
  "teams": [
    {
      "provider_team_id": "p6m5ywf3mohhymtr",
      "name": "JAG- EL-DUBS",
      "division": "Jagr"
    }
  ],
  "roster_constraints": [
    {
      "slot": "D",
      "max_active": 6
    }
  ],
  "player_pool": [
    {
      "provider_player_id": "04qyi",
      "pool_key": "Jagr",
      "eligible_positions": ["D"],
      "provider_status": "FA"
    }
  ],
  "roster_periods": [
    {
      "number": 1,
      "start_at": "2026-09-29T12:00:00.0-0400",
      "end_at": "2026-09-30T11:59:59.0-0400"
    }
  ],
  "scoring_periods": [
    {
      "number": 1,
      "start_at": "2026-09-29T12:00:00.0-0400",
      "end_at": "2026-10-05T11:59:59.0-0400"
    }
  ],
  "scoring_categories": [
    {
      "provider_group": "HOCKEY_SKATING",
      "provider_category_id": "2130",
      "provider_short_label": "G",
      "position_short_label": "D",
      "points": 6
    }
  ]
}
```

This shape is documentation guidance, not an existing serialized API contract unless a service explicitly adopts it.

## Known Variants

- `playerInfo` can be flat or division-grouped.
- `duplicatePlayerType` can be `NONE` or `ACROSS_DIVISIONS` in observed samples.
- Roster slot codes vary by league; `Skt` exists in SDL but not FHL.
- Scoring categories vary by league.
- Scoring weights can be default-only or position-specific.
- Goalies may or may not include offensive categories.
- `matchups` can include TBD sides instead of team ids.

## Product Semantics

`getLeagueInfo` is a league setup contract, not just metadata. The response determines how DynastyIQ should interpret later roster, draft, scoring, and availability payloads.

For normal leagues like SDL:

- `duplicatePlayerType: NONE` means one player universe across the whole league.
- A player marked `T` should generally be unavailable to other teams in that league.
- `playerInfo` can be read as a flat map keyed by Fantrax player id.

For tiered/division leagues like FHL:

- `duplicatePlayerType: ACROSS_DIVISIONS` means each division can behave like its own player universe.
- A player can be taken in one division and still valid in another.
- Availability and draft logic must include the division/player-pool boundary.
- `playerInfo` is grouped by division, so a flat lookup by Fantrax player id is insufficient.
- Community league management should preserve division context because the top-level Fantrax league is operating more like a league system.

## Import Behavior

Current intended behavior:

- League identity and scoring setup are hydrated from `getLeagueInfo`.
- Scoring category sync should prefer `scoringCategorySettings` rich configs when available.
- Shorthand `scoringSystem.scoringCategories` should be treated as fallback or reconciliation data.
- Roster eligibility should prefer `playerInfo` eligibility when available.
- Roster membership still comes from team roster endpoints, not `playerInfo.status`.

FHL-specific import requirement:

- Sync code must preserve the division or player-pool boundary when `duplicatePlayerType` is `ACROSS_DIVISIONS`.
- Any availability, draft, and free-agent logic that currently assumes one league-wide player universe must become pool-aware for these leagues.

## Does Not Tell Us

This endpoint does not provide authoritative:

- Current team roster memberships.
- Current roster item salary or contract labels.
- User-owned team assignments.
- Draft pick results.
- Live draft current pick state by itself.
- Discord/community authority.
- Team logo URLs in the observed SDL/FHL API samples.

Those must come from team roster, draft, user league, or DynastyIQ-owned community configuration.

## Schema And UI Implications

`duplicatePlayerType: ACROSS_DIVISIONS` exposes a current modeling gap: platform leagues and teams exist, but the player-pool/division boundary is not first-class in the current platform roster membership schema.

Before FHL draft or availability logic is trusted, DynastyIQ should persist or derive:

- League duplicate player type.
- Team division/pool label.
- Player availability scoped to the division/pool when applicable.
- Draft board or pick context scoped to the same division/pool when Fantrax behaves that way.

League management in Communities remains important for these leagues because the community operator may need to manage divisions, managers, draft visibility, Discord channels, and public league presentation above a single manager-facing league page.

## Open Verification Questions

- Is `ACROSS_DIVISIONS` always paired with division-grouped `playerInfo`?
- Is the team `division` field the stable pool key for draft and availability, or only display text?
- Can division names change during a season?
- Does Fantrax expose a division id separate from division name in any endpoint?
- Does `getDraftResults` include enough division context for FHL drafts?
- Does `getTeamRosters` return team division metadata or only team ids?
- Are `FA` and `T` the only `playerInfo.status` values?
- Are goalie offensive scoring categories missing from FHL by rule, or only absent in this sample?
