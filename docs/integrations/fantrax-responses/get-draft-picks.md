# Fantrax getDraftPicks Response

## Endpoint

```text
https://www.fantrax.com/fxea/general/getDraftPicks?leagueId=[LEAGUE_ID]
```

Config key:

```text
fantrax.draft_picks
```

Current DynastyIQ consumers:

- `App\Services\FantraxDraftingWindow`
- `App\Services\SyncFantraxDraftState`
- `App\Http\Controllers\CommunityLeagues`
- `App\Jobs\SyncFantraxDraftStateJob`

## Purpose

This response describes Fantrax draft pick inventory for a league. Observed payloads expose two different concepts:

- `futureDraftPicks`: future-year dynasty draft assets, including current owner and original owner.
- `currentDraftPicks`: current draft-order rows, including round, pick number, and owning team.

DynastyIQ currently uses `currentDraftPicks` as a draft status signal. Actual made-player selections come from `getDraftResults`, not this endpoint.

## Observations For DynastyIQ

This endpoint gives DynastyIQ enough structure to separate "draft capital" from "draft event." That matters for commissioner and community leagues because future pick ownership is a tradeable asset that should be visible before the draft starts, while current draft picks are operational state during the draft itself. In large Fantrax communities, especially leagues with division-scoped player pools, future pick boards should be team/pool aware and should show original-owner context so managers can understand traded picks without relying on Fantrax's raw UI.

## Sample Sources

- `docs/api_responses/samples/getDraftPicks_sdl.txt`: Super Duper League.
- `docs/api_responses/samples/getDraftPicks_fhl.txt`: FHL Tiered Dynasty.
- `docs/api_responses/samples/getDraftPicks_clh.txt`: Champions League of Hockey.

## Major Observed League Differences

| Area | SDL Sample | FHL Sample | CLH Sample | Meaning For DynastyIQ |
| --- | --- | --- | --- | --- |
| `futureDraftPicks` | 192 rows. | Empty array. | 252 rows. | Future pick assets are league-dependent and may be absent even when current draft picks exist. |
| `currentDraftPicks` | Empty array. | 632 rows. | Empty array. | Empty current picks can mean no active/current draft order remains; non-empty current picks indicate a draft order/pick queue exists. |
| Future pick ownership | Includes `currentOwnerTeamId` and `originalOwnerTeamId`. | Not observed because array is empty. | Includes `currentOwnerTeamId` and `originalOwnerTeamId`. | Traded future picks can be represented without a current draft pick number. |
| Current pick ownership | Not observed because array is empty. | Includes `teamId`. | Not observed because array is empty. | Current draft rows identify which team owns each current draft pick slot. |
| Future pick round depth | Rounds 1-4 observed. | Not observed because array is empty. | Rounds 1-7 observed. | Future pick round depth is league-specific and must not be hard-coded. |
| Pick numbering | No `pick` field on future rows. | `round` and `pick` on current rows. | No `pick` field on future rows. | Future assets and current draft order require separate normalized contracts. |

## Sample Response Excerpts

SDL future-pick shape:

```json
{
  "futureDraftPicks": [
    {
      "currentOwnerTeamId": "92es2zwnmo6nzpre",
      "round": 1,
      "year": 2027,
      "originalOwnerTeamId": "m43pz68wmo6nzpre"
    }
  ],
  "currentDraftPicks": []
}
```

FHL current-pick shape:

```json
{
  "futureDraftPicks": [],
  "currentDraftPicks": [
    {
      "round": 1,
      "pick": 15,
      "teamId": "vseo54q8mq0tpkta"
    }
  ]
}
```

## Field Map

| Path | Type / Shape | Observed Values | Meaning | DynastyIQ Usage | Must Not Drive |
| --- | --- | --- | --- | --- | --- |
| `futureDraftPicks` | array | SDL `[192]`, FHL `[]` | Future dynasty draft asset rows. | Candidate source for future pick ownership and pick-trade display. | Current draft status by itself. |
| `futureDraftPicks[].currentOwnerTeamId` | string | Fantrax team id | Team currently owning the future pick. | Future asset ownership and trade history display. | Original franchise identity. |
| `futureDraftPicks[].originalOwnerTeamId` | string | Fantrax team id | Team that originally owned the future pick. | Display traded-origin labels, such as "Team A's 2027 1st". | Current ownership. |
| `futureDraftPicks[].round` | integer | `1` through `7` observed across SDL/CLH samples. | Future pick round. | Future asset identity. | Current draft pick number or fixed league round depth. |
| `futureDraftPicks[].year` | integer | `2027`, `2028` observed in SDL sample. | Future draft year. | Future asset season/year identity. | Fantrax league season year by itself. |
| `currentDraftPicks` | array | SDL `[]`, FHL `[632]` | Current draft-order rows. | Draft live/complete status signal and current draft order context. | Made drafted-player results. |
| `currentDraftPicks[].round` | integer | `1`, `2`, `3` observed in FHL sample. | Current draft round. | Current draft order display. | Future pick asset year. |
| `currentDraftPicks[].pick` | integer | `1` through `16` observed in FHL sample. | Pick number within the current draft order row. | Draft order context and possible pick-in-round/overall source after validation. | Player selection identity. |
| `currentDraftPicks[].teamId` | string | Fantrax team id | Team currently assigned to the current draft pick row. | Current pick owner display and draft status context. | User ownership by itself. |

## Parser Contract

Consumers should normalize future assets separately from current draft-order rows.

### Future Draft Assets

Read:

- `futureDraftPicks[].currentOwnerTeamId`
- `futureDraftPicks[].originalOwnerTeamId`
- `futureDraftPicks[].round`
- `futureDraftPicks[].year`

Normalized row:

```json
{
  "provider_current_owner_team_id": "92es2zwnmo6nzpre",
  "provider_original_owner_team_id": "m43pz68wmo6nzpre",
  "round": 1,
  "year": 2027
}
```

Rules:

- Treat the tuple of league, year, round, original owner, and current owner as a future asset observation unless Fantrax exposes a stronger pick id.
- Preserve original owner separately from current owner.
- Do not infer a current draft `pick` number from future asset rows.
- Do not hard-code future pick round depth; CLH shows rounds beyond SDL's observed four rounds.
- Do not write future asset rows into roster membership tables.

### Current Draft Picks

Read:

- `currentDraftPicks[].round`
- `currentDraftPicks[].pick`
- `currentDraftPicks[].teamId`

Normalized row:

```json
{
  "round": 1,
  "pick": 15,
  "provider_team_id": "vseo54q8mq0tpkta"
}
```

Rules:

- Use `currentDraftPicks` as current draft-order context, not as made-pick history.
- Continue using `getDraftResults.draftPicks` as the authority for drafted-player rows.
- Treat a non-empty `currentDraftPicks` array as evidence that a current draft pick queue/order exists.
- Treat an empty `currentDraftPicks` array as no current draft-order rows available; combine with draft date/results before declaring a draft complete.

## Expected Normalized Output

If persisted in first-party models, future assets should have a different model/table contract from live or historical drafted-player picks:

```json
{
  "future_assets": [
    {
      "provider": "fantrax",
      "provider_league_id": "uf1sdl47mo6nzpr6",
      "year": 2027,
      "round": 1,
      "provider_original_owner_team_id": "m43pz68wmo6nzpre",
      "provider_current_owner_team_id": "92es2zwnmo6nzpre"
    }
  ],
  "current_order": [
    {
      "provider": "fantrax",
      "provider_league_id": "49wwcp3imohhymt9",
      "round": 1,
      "pick": 15,
      "provider_team_id": "vseo54q8mq0tpkta"
    }
  ]
}
```

## Product Semantics

`futureDraftPicks` describes asset ownership for dynasty planning. It can support future-pick boards, trade audits, "original team" labels, and commissioner review of traded pick inventory.

`currentDraftPicks` describes a current draft order or queue. It can support on-the-clock context, pick-order display, and draft-progress status when combined with `getDraftResults`.

The endpoint does not say which player was selected. Player selections must come from `getDraftResults`.

## Import Behavior

Current behavior:

- `FantraxDraftingWindow` and `SyncFantraxDraftState` count `currentDraftPicks` to help derive draft status.
- The current draft status fallback reads:
  - `currentDraftPicks`
  - `current_draft_picks`
  - `draft.currentDraftPicks`
- `SyncFantraxDraftState` mirrors made-player rows from `getDraftResults`, not from this endpoint.

Potential future behavior:

- Persist `futureDraftPicks` into a dedicated future draft asset model/table.
- Link `currentOwnerTeamId`, `originalOwnerTeamId`, and `teamId` to `platform_teams` by Fantrax league/team id.
- Keep future asset import independent from roster and current draft-result import.

## Does Not Tell Us

This endpoint does not provide authoritative:

- Drafted player id.
- Player name.
- Pick timestamp.
- Auction value.
- Keeper flag.
- Draft date.
- Pick clock.
- Draft room state.
- League division/player-pool scope.
- Team names.
- User ownership.

Use `getDraftResults` for made picks, `getLeagueInfo` for draft setup and team/division context, and `getLeagues` only for connected-user team ownership bootstrap.

## Schema And UI Implications

Future pick assets should not be stored as roster memberships or completed `draft_picks` rows. They are draft capital.

If DynastyIQ persists future assets, it needs a provider-aware asset identity that preserves year, round, current owner, and original owner. A future UI should make original-owner labels visible because the sample shows traded picks as first-class data.

Current draft-order rows can enrich Draft Central, but they should not replace canonical `draft_picks` rows built from `getDraftResults` because this endpoint has no player-selection fields.

## Open Verification Questions

- Does `currentDraftPicks[].pick` mean pick-in-round or overall pick in every Fantrax league shape?
- Does Fantrax expose a stable id for future picks in any league configuration?
- Can future pick rows include conditions or partial protections?
- Are future pick years always calendar-style draft years, or can they represent Fantrax season years?
- In `ACROSS_DIVISIONS` leagues, are future/current draft pick rows scoped by division elsewhere, or only inferable from team id and `getLeagueInfo` team division?
- Does `currentDraftPicks` shrink as picks are made, or can it remain as the full current draft order?
