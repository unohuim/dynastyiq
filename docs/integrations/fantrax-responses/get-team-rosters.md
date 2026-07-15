# Fantrax getTeamRosters Response

## Endpoint

```text
https://www.fantrax.com/fxea/general/getTeamRosters?leagueId=[LEAGUE_ID]&period=[PERIOD]
```

Config key:

```text
fantrax.team_rosters
```

Current DynastyIQ consumers:

- `App\Services\SyncFantraxLeague`
- `App\Services\PlatformLeaguePlayerStatService`

## Purpose

This response is the observed authority for current Fantrax roster membership. It returns rosters by Fantrax team id, with rostered player ids, current roster slot assignment, roster status, and optional salary/contract metadata.

DynastyIQ should use this endpoint for current roster ownership. It should not use `getLeagueInfo.playerInfo.status` or `getLeagues` as roster membership truth.

## Observations For DynastyIQ

The roster endpoint is where league configuration becomes operational. SDL, CLH, and FHL all expose the same core roster membership shape, but salary and contract fields are league-dependent. That means DynastyIQ can build one roster sync contract for ownership/status/slot, while treating cap and contract metadata as optional enhancements. CLH is especially useful for custom-cap leagues because it exposes both salary and Fantrax contract code labels on roster items.

## Sample Sources

- `docs/api_responses/samples/getTeamRosters_sdl.txt`: Super Duper League.
- `docs/api_responses/samples/getTeamRosters_clh.txt`: Champions League of Hockey.
- `docs/api_responses/samples/getTeamRosters_fhl.txt`: FHL Tiered Dynasty.

## Major Observed League Differences

| Area | SDL Sample | CLH Sample | FHL Sample | Meaning For DynastyIQ |
| --- | --- | --- | --- | --- |
| Team count | 24 teams. | 18 teams. | 90 teams. | Roster sync must not assume one standard league size. |
| Team shape | `teamName`, `rosterItems`. | `teamName`, `rosterItems`. | `teamName`, `rosterItems`. | Core team roster shape is stable across samples. |
| Roster item core fields | `id`, `position`, `salary`, `status`. | `contract`, `id`, `position`, `salary`, `status`. | `id`, `position`, `status`. | Player id, slot, and status are core; salary/contract are optional. |
| Salary | Present, NHL-style dollar values. | Present, custom-cap values such as `850` and `12000`. | Not observed. | Salary scale is league-dependent and must not be interpreted without league context. |
| Contract object | Not observed. | Present with `smallId` and `name`. | Not observed. | Fantrax contract codes are optional custom-league metadata. |
| Status values | `ACTIVE`, `RESERVE`, `MINORS`, `INJURED_RESERVE` observed. | `ACTIVE`, `RESERVE`, `MINORS` observed. | `ACTIVE`, `RESERVE`, `MINORS`, `INJURED_RESERVE` observed. | Status normalization must support active, bench, minors, and IR. |
| Position values | Includes `C`, `LW`, `RW`, `D`, `G`, `Skt`. | Includes `C`, `LW`, `RW`, `D`, `G`, `F`, `Skt`. | Includes `C`, `LW`, `RW`, `D`, `G`. | Slot codes are league-specific and may include synthetic slots. |
| Top-level cap | `salaryCap` observed. | Not observed in inspected slice. | Not observed. | Top-level cap is optional and not enough by itself for cap modeling. |

## Sample Response Excerpts

SDL roster item:

```json
{
  "period": 1,
  "rosters": {
    "92es2zwnmo6nzpre": {
      "teamName": "Mr. Titanium Cranium",
      "rosterItems": [
        {
          "id": "03bxl",
          "position": "LW",
          "salary": 4200000,
          "status": "ACTIVE"
        }
      ]
    }
  },
  "salaryCap": 135000000
}
```

CLH custom salary and contract item:

```json
{
  "period": 1,
  "rosters": {
    "rayxnvt5mo2xp2y0": {
      "teamName": "Twyford Redcoats",
      "rosterItems": [
        {
          "contract": {
            "smallId": "k",
            "name": "P4"
          },
          "id": "075zc",
          "position": "C",
          "salary": 850,
          "status": "MINORS"
        }
      ]
    }
  }
}
```

FHL roster item without salary/contract:

```json
{
  "period": 1,
  "rosters": {
    "wgo35koomohhymtn": {
      "teamName": "JAG- Scottditor",
      "rosterItems": [
        {
          "id": "042xa",
          "position": "LW",
          "status": "INJURED_RESERVE"
        }
      ]
    }
  }
}
```

## Field Map

| Path | Type / Shape | Observed Values | Meaning | DynastyIQ Usage | Must Not Drive |
| --- | --- | --- | --- | --- | --- |
| `period` | integer | `1` observed. | Fantrax roster period. | Roster sync period context. | Scoring period by itself. |
| `rosters` | object keyed by Fantrax team id | SDL `[24]`, CLH `[18]`, FHL `[90]`. | Team roster map. | Current roster membership authority. | User-owned team discovery by itself. |
| `rosters.{teamId}.teamName` | string | Provider team name. | Team display name in roster payload. | Secondary team display/update source. | Stable team identity. |
| `rosters.{teamId}.rosterItems` | array | One row per rostered player. | Current rostered players for that team. | Membership sync. | Player-pool availability by itself. |
| `rosterItems[].id` | string | Fantrax player id. | Provider player identity. | Resolve to Fantrax player/canonical player. | Canonical NHL id without resolver. |
| `rosterItems[].position` | string | `C`, `LW`, `RW`, `D`, `G`, `F`, `Skt`. | Current roster slot assignment. | Roster slot and display grouping. | Full eligibility by itself. |
| `rosterItems[].status` | string | `ACTIVE`, `RESERVE`, `MINORS`, `INJURED_RESERVE`. | Current roster grouping/status. | Normalize to active/bench/minors/IR. | Player-pool availability status. |
| `rosterItems[].salary` | integer/absent | SDL NHL-style dollars; CLH custom values; absent in FHL sample. | Provider salary/cap value when league exposes it. | Optional custom salary/cap metadata. | Universal NHL cap hit without league context. |
| `rosterItems[].contract` | object/absent | CLH object with `smallId`, `name`. | Provider contract-code metadata. | Optional custom contract display/planning metadata. | Canonical CapWages contract. |
| `rosterItems[].contract.smallId` | string | CLH examples include `k`, `7`, `b`. | Fantrax small contract id/code. | Persist as provider metadata. | Contract meaning without league code definitions. |
| `rosterItems[].contract.name` | string | CLH examples include `P4`, `A1`, `E2`. | Fantrax contract label/code. | Custom cap/contract display and planning. | Contract term/type without commissioner/user definitions. |
| `salaryCap` | integer/absent | SDL `135000000` observed. | Provider top-level salary cap when present. | Optional league cap display/context. | Multi-season cap rules or commissioner authority by itself. |

## Parser Contract

Consumers should normalize team rosters into team rows and roster item rows.

### Team Roster Rows

Read:

- `rosters` object key as provider team id.
- `rosters.{teamId}.teamName`
- `rosters.{teamId}.rosterItems`

Normalized team row:

```json
{
  "provider_team_id": "92es2zwnmo6nzpre",
  "team_name": "Mr. Titanium Cranium",
  "period": 1
}
```

Rules:

- Use the object key as the provider team id.
- Treat `teamName` as display text.
- Do not infer DynastyIQ user ownership from this response.
- In large community leagues, expect the roster map to include many teams.

### Roster Item Rows

Read:

- `rosterItems[].id`
- `rosterItems[].position`
- `rosterItems[].status`
- `rosterItems[].salary`
- `rosterItems[].contract.smallId`
- `rosterItems[].contract.name`

Normalized roster item:

```json
{
  "provider_player_id": "03bxl",
  "slot": "LW",
  "status": "ACTIVE",
  "salary": 4200000,
  "contract": null
}
```

Rules:

- Treat each roster item as current membership for the containing team.
- Resolve `id` through Fantrax player identities.
- Treat `position` as current slot assignment, not complete eligibility.
- Prefer `getLeagueInfo.playerInfo.*.eligiblePos` for full league-specific eligibility when available.
- Preserve raw status before normalizing to DynastyIQ roster group/status.
- Preserve salary only when present; do not invent zero salary for leagues that omit it.
- Preserve contract metadata only when present.

## Expected Normalized Output

```json
{
  "period": 1,
  "teams": [
    {
      "provider_team_id": "92es2zwnmo6nzpre",
      "team_name": "Mr. Titanium Cranium"
    }
  ],
  "memberships": [
    {
      "provider_team_id": "92es2zwnmo6nzpre",
      "provider_player_id": "03bxl",
      "slot": "LW",
      "status": "ACTIVE",
      "salary": 4200000,
      "contract": null
    }
  ]
}
```

## Product Semantics

This endpoint is the roster membership source. It can power:

- League roster sync.
- Team roster pages.
- Player ownership and free-agent derivation when combined with league player pool data.
- Custom salary/cap displays when salary is present.
- Custom contract code displays when contract metadata is present.

Salary and contract data should be presented as provider league data, not universal NHL contract truth.

## Import Behavior

Current behavior:

- `SyncFantraxLeague` reads `fantrax.team_rosters`.
- Roster item salary is persisted in metadata as `fantrax_salary` when numeric.
- Roster item contract metadata is persisted as `fantrax_contract` when present.
- Status values are normalized:
  - `ACTIVE` to active.
  - `RESERVE` to bench/reserve.
  - `INJURED_RESERVE` to IR.
  - `MINORS` to minors/NA.
- Roster slot assignment is derived from `position`.

## Does Not Tell Us

This endpoint does not provide authoritative:

- Full player eligibility.
- Player display name.
- Canonical NHL player id.
- User ownership of teams.
- League scoring settings.
- Player-pool availability by itself.
- Division/player-pool behavior.
- Multi-season cap ceilings/floors.
- Canonical CapWages contract details.

Use `getLeagueInfo` for league setup, eligibility, division/player-pool behavior, roster constraints, and scoring settings. Use `getLeagues` for connected-user team ownership.

## Schema And UI Implications

Roster membership schema should preserve provider player id, provider team id, raw slot, raw status, normalized status, and optional metadata.

Custom-cap UI should treat Fantrax salary and contract codes as league-scoped inputs. CLH shows that `salary` can be custom scale values and `contract.name` can contain league-specific codes, so commissioner/user-defined meanings are required before turning those codes into term/type semantics.

FHL shows that salary and contract metadata can be absent while roster membership remains authoritative. UI should not show blank salary as zero cap hit unless a league rule or fallback source supplies that value.

## Open Verification Questions

- Does `salaryCap` appear consistently for salary-cap leagues, or only some configurations?
- Can `contract` include more fields than `smallId` and `name`?
- Are there roster item statuses besides `ACTIVE`, `RESERVE`, `MINORS`, and `INJURED_RESERVE`?
- Does requesting a future/past `period` change `rosterItems` to historical roster state?
- Can roster item `position` contain multiple values, or always one current slot?
- For `ACROSS_DIVISIONS` leagues, is team division only available from `getLeagueInfo`, or can it appear in roster payloads?
