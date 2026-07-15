# Fantrax getPlayerIds Response

## Endpoint

```text
https://www.fantrax.com/fxea/general/getPlayerIds?sport=NHL
```

Config key:

```text
fantrax.players
```

Current DynastyIQ consumers:

- `App\Services\ImportFantraxPlayer`
- `App\Classes\ImportFantraxPlayers`
- `App\Jobs\ImportFantraxPlayersChunkJob`
- `App\Services\SyncFantraxLeague`

## Purpose

This response is the broad Fantrax NHL player identity pool. It returns Fantrax player ids and basic player metadata that DynastyIQ uses to populate `fantrax_players` and `player_external_identities`.

It is not roster membership truth, league-specific eligibility truth, or player-pool availability truth.

## Observations For DynastyIQ

`getPlayerIds` is the identity spine for Fantrax. It gives DynastyIQ enough provider metadata to resolve roster and draft payload ids into local player records, but it deliberately lacks league context. The endpoint also includes inactive or unaffiliated players with `(N/A)` teams, which is useful for identity coverage but risky for user-facing league availability unless paired with league-specific endpoints.

## Sample Sources

- `docs/api_responses/samples/getPlayerIds.txt`: NHL player id pool.

Observed sample shape:

- Top-level object with 8,909 player entries.
- Top-level keys are Fantrax player ids.
- Each row repeats `fantraxId`.
- `name` uses `"Last, First"` format.
- `team` can be an NHL abbreviation or `(N/A)`.
- External provider ids are optional per row.

## Sample Response Excerpt

```json
{
  "04qyh": {
    "statsIncId": 7966,
    "rotowireId": 5798,
    "sportRadarId": "ab3a4a15-eaa8-4025-9cbd-a144d1487fd5",
    "name": "Madden, Tyler",
    "fantraxId": "04qyh",
    "team": "MIN",
    "position": "RW"
  },
  "04qyf": {
    "statsIncId": 7964,
    "rotowireId": 5725,
    "sportRadarId": "08d8e479-92ab-4e39-be71-b85f8627171e",
    "name": "Hillis, Cameron",
    "fantraxId": "04qyf",
    "team": "(N/A)",
    "position": "C"
  }
}
```

## Field Map

| Path | Type / Shape | Observed Values | Meaning | DynastyIQ Usage | Must Not Drive |
| --- | --- | --- | --- | --- | --- |
| top-level key | string | Fantrax player id, such as `04qyh`. | Provider player id key. | Primary Fantrax player identity. | Canonical NHL id by itself. |
| `{fantraxId}.fantraxId` | string | Usually matches top-level key. | Provider player id repeated in row. | Persist as `fantrax_players.fantrax_id` and external identity provider id. | League roster membership. |
| `{fantraxId}.name` | string | `"Last, First"` format. | Provider display name. | Name parsing and identity matching. | Stable canonical name without normalization. |
| `{fantraxId}.team` | string | NHL abbreviation or `(N/A)`. | Provider current team hint. | Identity matching and broad display fallback. | Roster ownership or active NHL status by itself. |
| `{fantraxId}.position` | string | `C`, `LW`, `RW`, `D`, `G`, etc. | Provider primary/base position. | Identity matching and fallback display. | League-specific eligibility or roster slot. |
| `{fantraxId}.statsIncId` | integer/absent | Optional. | Stats Inc external id. | Additional identity evidence. | Primary identity without reconciliation. |
| `{fantraxId}.rotowireId` | integer/absent | Optional. | Rotowire external id. | Additional identity evidence. | Primary identity without reconciliation. |
| `{fantraxId}.sportRadarId` | string/absent | UUID string. | Sportradar external id. | Additional identity evidence. | Primary identity without reconciliation. |

## Parser Contract

Consumers should normalize the top-level object values into player identity rows.

Read:

- top-level key
- `fantraxId`
- `name`
- `team`
- `position`
- `statsIncId`
- `rotowireId`
- `sportRadarId`

Normalized row:

```json
{
  "provider": "fantrax",
  "provider_player_id": "04qyh",
  "name": "Madden, Tyler",
  "team": "MIN",
  "position": "RW",
  "statsinc_id": 7966,
  "rotowire_id": 5798,
  "sport_radar_id": "ab3a4a15-eaa8-4025-9cbd-a144d1487fd5"
}
```

Rules:

- Prefer `fantraxId` when present; otherwise use the top-level key.
- Treat a mismatch between top-level key and `fantraxId` as a data-quality issue requiring review.
- Parse `name` as provider display text; do not assume it is already canonical.
- Preserve `(N/A)` team values as provider data rather than converting to null unless a consuming service explicitly needs null.
- Store optional external ids when present, but do not require them.
- Skip team aggregate rows if the provider ever returns them.

## Expected Normalized Output

```json
{
  "players": [
    {
      "fantrax_id": "04qyh",
      "name": "Madden, Tyler",
      "team": "MIN",
      "position": "RW",
      "statsinc_id": 7966,
      "rotowire_id": 5798,
      "sport_radar_id": "ab3a4a15-eaa8-4025-9cbd-a144d1487fd5"
    }
  ]
}
```

## Product Semantics

This endpoint can power:

- Fantrax player import.
- Provider identity matching.
- Roster and draft player-id resolution.
- Admin triage for unmatched Fantrax players.
- Fallback player display when roster/draft payloads only contain Fantrax ids.

It should be paired with league-specific endpoints before displaying availability, ownership, or eligibility.

## Import Behavior

Current behavior:

- Fantrax player import reads `fantrax.players`.
- Entries are chunked for queue processing.
- `ImportFantraxPlayer` upserts Fantrax external identities and `fantrax_players`.
- `PlayerIdentityResolver` resolves the provider identity to a canonical player when possible.
- Existing roster sync can use `fantrax_players` as the fast path for resolving roster player ids.
- Rows without canonical matches can remain staged as Fantrax player rows and external identities for triage.

## Does Not Tell Us

This endpoint does not provide authoritative:

- Roster ownership.
- Free-agent availability.
- League-specific eligibility.
- Current roster slot.
- Roster status.
- Salary or contract metadata.
- Draft status or draft ownership.
- League-specific player pool scope.

Use `getTeamRosters` for roster membership, `getLeagueInfo.playerInfo` for league-specific eligibility and availability, and draft endpoints for draft state.

## Schema And UI Implications

`fantrax_players` and `player_external_identities` should preserve the raw provider row because optional external ids are useful for future reconciliation.

User-facing league pages should not treat every `getPlayerIds` row as available in a league. The endpoint includes broad player identity coverage, including `(N/A)` team values, prospects, inactive players, and players outside a specific league's current roster/player-pool state.

## Open Verification Questions

- Can the top-level key ever differ from `fantraxId`?
- Does the endpoint include retired players or only active/provider-selectable players?
- Are `position` values always single-position base values?
- Can `team` values include non-NHL abbreviations besides `(N/A)`?
- Does `sport=NHL` include players from all league types and all Fantrax hockey games, or only NHL fantasy pools?
- Are `statsIncId`, `rotowireId`, and `sportRadarId` stable over time?
