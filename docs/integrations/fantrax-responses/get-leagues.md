# Fantrax getLeagues Response

## Endpoint

```text
https://www.fantrax.com/fxea/general/getLeagues?userSecretId=[USER_SECRET_ID]
```

Config key:

```text
fantrax.user_leagues
```

Current DynastyIQ consumer:

- `App\Services\ConnectFantraxUser`
- `App\Services\FantraxLeagueService`

## Purpose

This is the Fantrax connection entry point. It uses a Fantrax user secret ID to discover leagues and teams owned by the connected Fantrax user.

DynastyIQ uses this response to bootstrap:

- `platform_leagues`
- `platform_teams`
- `league_user_teams`

It also queues league sync after league/team/user ownership rows are persisted.

## Observations For DynastyIQ

`getLeagues` is a connection and ownership handshake, not a league modeling endpoint. It is useful for quickly attaching a user to their Fantrax teams, but DynastyIQ should immediately follow it with league-scoped sync because the response lacks the setup details that make a league behave differently. Connection screens can stay simple, while league pages, community pages, and commissioner tooling should not rely on this endpoint beyond bootstrap.

## Sample Response

Sample source: `docs/api_responses/fx_getleagues.txt`.

```json
{
  "leagues": [
    {
      "leagueName": "Super Duper League",
      "teamName": "Tokyo Killer Tardigrades",
      "leagueId": "uf1sdl47mo6nzpr6",
      "teamId": "3p7cwizlmo6nzpre",
      "sport": "NHL"
    }
  ]
}
```

Observed shape: one flat row per discovered league/team assignment. The observed response does not include league logo URLs, team logo URLs, or team short names.

## Field Map

| Path | Type / Shape | Observed Values | Meaning | DynastyIQ Usage | Must Not Drive |
| --- | --- | --- | --- | --- | --- |
| `leagues` | array | Empty or one item per discovered owned team/league row | Top-level discovery list for the user secret ID | Required connection payload; empty list is treated as invalid secret ID | Roster, scoring, draft, or standings truth |
| `leagues[].leagueId` | string | Fantrax league id | Provider league identifier | Stored as `platform_leagues.platform_league_id` with `platform = fantrax` | Display name, sport inference, division logic |
| `leagues[].leagueName` | string/null | League display name | Provider league name | Stored as `platform_leagues.name`, fallback `Unnamed League` | Stable identity |
| `leagues[].teamId` | string | Fantrax team id | Provider fantasy team identifier within the league | Stored as `platform_teams.platform_team_id` | User identity by itself |
| `leagues[].teamName` | string/null | Team display name | Provider fantasy team name | Stored as `platform_teams.name`, fallback `Unnamed Team` | Stable identity |
| `leagues[].sport` | string/null | Usually sport label/id | Provider sport hint | Currently ignored; DynastyIQ canonicalizes synced Fantrax leagues to `hockey` | Eligibility, league validation, roster assumptions |

## Fields Not Present

The observed `getLeagues` response does not provide:

- League logo URL.
- Team logo URL.
- Team short name.
- Commissioner/admin flag.
- League history id.
- Division or player-pool metadata.

## Product Semantics

This response says which Fantrax leagues and teams the connected Fantrax user owns.

For DynastyIQ, that means:

- The user has a Fantrax connection if this endpoint returns at least one league row.
- Each returned team row can attach the DynastyIQ user to a platform league/team.
- The endpoint can show multiple teams for one user across different leagues.
- The endpoint may show multiple teams in the same league if Fantrax allows that account to manage more than one team.

## Import Behavior

Current behavior:

- `ConnectFantraxUser` stores the user secret ID as an integration secret.
- It calls `fantrax.user_leagues`.
- It reads `response.leagues`.
- Empty `response.leagues` is treated as invalid Fantrax Secret ID.
- `FantraxLeagueService` normalizes the flat observed response shape.
- `platform_leagues` are upserted by `platform + platform_league_id`.
- `platform_teams` are upserted by `platform_league_id + platform_team_id`.
- `league_user_teams` links the DynastyIQ user to the discovered team.
- A Fantrax league sync job is dispatched per discovered league/team row.

## Does Not Tell Us

This endpoint does not provide authoritative:

- Current roster membership.
- Free-agent availability.
- League scoring categories.
- Roster slot rules.
- Salary or contract values.
- League or team logos.
- Division/player-pool behavior.
- Draft picks or draft results.
- Commissioner permissions.
- Discord/community membership.

Those must come from later league-scoped endpoints or DynastyIQ-owned community configuration.

## Schema And UI Implications

The endpoint is appropriate for identity and ownership bootstrap only.

It should not be used to render detailed league pages without follow-up league sync because it lacks the league setup details required for roster, scoring, cap, and draft experiences.

Community league management can use the discovered platform league/team rows as attachment points, but community-level authority, visibility, Discord connections, and draft notification settings remain DynastyIQ-owned state.

## Open Verification Questions

- Can one Fantrax user secret return multiple teams in the same league?
- Does Fantrax return commissioner/admin flags from this endpoint, or only from browser/web payloads?
- Are inactive, archived, or prior-season leagues included?
- Does `sport` always identify NHL reliably enough to reject non-hockey leagues?
- Does Fantrax ever add short names or logos to this endpoint, or are logos only available through other API/browser payloads?
