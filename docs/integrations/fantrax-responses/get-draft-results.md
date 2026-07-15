# Fantrax getDraftResults Response

## Endpoint

```text
https://www.fantrax.com/fxea/general/getDraftResults?leagueId=[LEAGUE_ID]
```

Config key:

```text
fantrax.draft_results
```

Current DynastyIQ consumers:

- `App\Services\FantraxDraftingWindow`
- `App\Services\SyncFantraxDraftState`
- `App\Http\Controllers\CommunityLeagues`
- `App\Jobs\SyncFantraxDraftStateJob`

## Purpose

This response describes draft results and draft lifecycle state for a Fantrax league. It is the observed authority for made-player selections.

DynastyIQ uses this response to mirror draft rows into canonical draft state. `getDraftPicks` can describe future draft assets and current draft order, but player selections come from `getDraftResults.draftPicks` rows once `playerId` is present.

## Observations For DynastyIQ

CLH and SDL show that completed `getDraftResults` payloads include draft lifecycle state, draft timing, draft order, and completed pick rows. FHL shows the same endpoint can expose pending draft slots before players are selected. That gives DynastyIQ the pieces needed for commissioner-facing draft audit, Discord draft announcements, Draft Central history, and manager-facing recap surfaces, but the app must distinguish a pending pick slot from a made pick by the presence of `playerId`.

## Sample Sources

- `docs/api_responses/samples/getDraftResults_clh.txt`: Champions League of Hockey.
- `docs/api_responses/samples/getDraftResults_sdl.txt`: Super Duper League.
- `docs/api_responses/samples/getDraftResults_fhl.txt`: FHL Tiered Dynasty.

## Major Observed League Differences

| Area | SDL Sample | CLH Sample | FHL Sample | Meaning For DynastyIQ |
| --- | --- | --- | --- | --- |
| Draft state | `completed`. | `completed`. | Pending/not yet completed in observed shape. | Draft rows must be interpreted with lifecycle state and `playerId` presence. |
| `draftPicks` rows | 96 rows. | 126 rows. | 632 rows. | Row count is league/draft-size dependent. |
| `playerId` | Present on observed rows. | Present on observed rows. | Not present on observed rows. | A row without `playerId` is a pending draft slot, not a completed player selection. |
| Division scope | Not present on pick rows. | Not present on pick rows. | `division` present on pick rows. | `ACROSS_DIVISIONS` drafts need division-scoped pick identity. |
| `draftOrder` shape | Flat array of 24 team ids. | Flat array of 18 team ids. | Object keyed by division, each value an array of team ids. | Draft order can be flat or division-grouped. |
| Round depth | Rounds 1-4. | Rounds 1-7. | Rounds observed in pending rows; division-scoped. | Round depth and uniqueness are league-specific. |
| Pick uniqueness | `pick` is overall pick. | `pick` is overall pick. | `pick` repeats by division. | Overall pick assumptions are invalid for division-scoped pending drafts. |

## Sample Response Excerpt

```json
{
  "draftDate": "2026-06-28T12:00:00.0-0400",
  "draftPicks": [
    {
      "round": 1,
      "pick": 1,
      "teamId": "7dik07lsmo2xp2y0",
      "time": 1782662499000,
      "pickInRound": 1,
      "playerId": "062h5"
    }
  ],
  "draftState": "completed",
  "endDate": "2026-07-08T02:18:45.0-0400",
  "draftOrder": [
    "rayxnvt5mo2xp2y0",
    "v27c3lm3mo2xp2y0"
  ],
  "draftType": "snake",
  "startDate": "2026-06-28T12:00:01.0-0400"
}
```

Observed CLH sample shape:

- `draftPicks`: 126 rows.
- `draftOrder`: 18 team ids.
- `round`: rounds 1 through 7 observed.
- `pick`: overall pick numbers 1 through 126 observed.
- `pickInRound`: pick number within the round; 1 through 18 observed.
- `draftState`: `completed`.
- `draftType`: `snake`.

Observed FHL pending-slot excerpt:

```json
{
  "draftPicks": [
    {
      "division": "Gretzky",
      "round": 1,
      "pick": 1,
      "teamId": "ya41vv3bmohhymts",
      "time": 1780344058000,
      "pickInRound": 1
    }
  ],
  "draftOrder": {
    "Gretzky": [
      "ya41vv3bmohhymts",
      "u1xpiksemohhymtn"
    ]
  },
  "draftType": "snake"
}
```

## Field Map

| Path | Type / Shape | Observed Values | Meaning | DynastyIQ Usage | Must Not Drive |
| --- | --- | --- | --- | --- | --- |
| `draftDate` | datetime string | `2026-06-28T12:00:00.0-0400` | Scheduled draft date/time. | Draft window and display context. | Pick timestamp for individual selections. |
| `draftPicks` | array | SDL `[96]`, CLH `[126]`, FHL `[632]` | Draft pick rows; may be completed selections or pending slots. | Authoritative draft-result/slot rows. | Future draft asset ownership. |
| `draftPicks[].division` | string | FHL division names. | Division/pool key for division-scoped drafts. | Part of pick identity when present. | League-wide availability by itself. |
| `draftPicks[].round` | integer | SDL `1-4`, CLH `1-7`. | Draft round. | Canonical draft pick round. | Future asset round count by itself. |
| `draftPicks[].pick` | integer | SDL/CLH overall pick numbers; FHL repeats by division. | Pick number; overall only in flat draft shapes. | Overall pick when flat; division-scoped pick number when `division` exists. | Global uniqueness by itself. |
| `draftPicks[].pickInRound` | integer | SDL `1-24`, CLH `1-18`, FHL division-scoped values. | Pick number within the round/scope. | Canonical pick-in-round with division scope when present. | Overall pick. |
| `draftPicks[].teamId` | string | Fantrax team id. | Team that made or owns the pick row. | Resolve to `platform_teams` and draft owner display. | User ownership by itself. |
| `draftPicks[].playerId` | string/absent | Present in SDL/CLH completed rows; absent in FHL pending rows. | Drafted Fantrax player when present. | Resolve to Fantrax player/canonical player for completed selections. | Required field for pending slots. |
| `draftPicks[].time` | integer | Epoch milliseconds. | Pick timestamp or scheduled/pending slot time depending on lifecycle. | `picked_at` only when `playerId` is present; otherwise pending slot timing context. | Draft start/end time. |
| `draftState` | string | `completed` observed. | Provider draft lifecycle state. | Draft status display and sync logic. | Pick-level status by itself. |
| `endDate` | datetime string | `2026-07-08T02:18:45.0-0400` | Provider draft end timestamp. | Draft lifecycle display. | Last pick timestamp if pick rows are present. |
| `draftOrder` | array of strings or object of arrays | SDL `[24]`, CLH `[18]`, FHL division-keyed object. | Provider draft team order. | Draft order display and fallback team sequence context. | Made-pick rows by itself. |
| `draftType` | string | `snake` observed. | Provider draft format. | Draft setup display. | Snake reversal logic without verifying order rows. |
| `startDate` | datetime string | `2026-06-28T12:00:01.0-0400` | Provider draft start timestamp. | Draft lifecycle display. | Scheduled draft date by itself. |

## Parser Contract

Consumers should normalize the response into draft lifecycle metadata and made-pick rows.

### Draft Metadata

Read:

- `draftDate`
- `draftState`
- `startDate`
- `endDate`
- `draftType`
- `draftOrder`

Normalized metadata:

```json
{
  "draft_at": "2026-06-28T12:00:00.0-0400",
  "status": "completed",
  "started_at": "2026-06-28T12:00:01.0-0400",
  "ended_at": "2026-07-08T02:18:45.0-0400",
  "draft_type": "snake",
  "provider_draft_order": [
    "rayxnvt5mo2xp2y0",
    "v27c3lm3mo2xp2y0"
  ]
}
```

Rules:

- Treat `draftDate` as scheduled draft time.
- Treat `startDate` and `endDate` as lifecycle timestamps.
- Preserve `draftState` as provider state, then map it into DynastyIQ draft status deliberately.
- Preserve `draftOrder` exactly as provided; it can be a flat team-id array or a division-keyed object of team-id arrays.

### Made Draft Picks

Read:

- `draftPicks[].round`
- `draftPicks[].pick`
- `draftPicks[].pickInRound`
- `draftPicks[].teamId`
- `draftPicks[].playerId`
- `draftPicks[].time`
- `draftPicks[].division`

Normalized row:

```json
{
  "round": 1,
  "overall_pick": 1,
  "pick_in_round": 1,
  "division": null,
  "provider_team_id": "7dik07lsmo2xp2y0",
  "provider_player_id": "062h5",
  "picked_at_epoch_ms": 1782662499000
}
```

Rules:

- Use `draftPicks[].pick` as overall pick number only when the draft shape is flat.
- When `division` is present, include it in pick identity and do not treat `pick` as globally unique.
- Use `draftPicks[].pickInRound` as pick-in-round within the relevant flat or division scope.
- Convert `time` from epoch milliseconds before storing as a datetime.
- Resolve `teamId` through platform teams in the same Fantrax league.
- Resolve `playerId` through Fantrax player identities when present; do not assume it is a canonical NHL id.
- Treat rows without `playerId` as pending draft slots, not made selections.
- Do not use this endpoint for future draft asset ownership; use `getDraftPicks.futureDraftPicks`.

## Expected Normalized Output

```json
{
  "draft": {
    "provider": "fantrax",
    "provider_league_id": "2k8tsy4imo2wkl7j",
    "draft_at": "2026-06-28T12:00:00.0-0400",
    "status": "completed",
    "draft_type": "snake"
  },
  "picks": [
    {
      "round": 1,
      "overall_pick": 1,
      "pick_in_round": 1,
      "division": null,
      "provider_team_id": "7dik07lsmo2xp2y0",
      "provider_player_id": "062h5",
      "picked_at_epoch_ms": 1782662499000
    }
  ]
}
```

## Product Semantics

This endpoint is the draft-result and draft-slot source. Rows with `playerId` are made picks; rows without `playerId` are pending slots. It can power:

- Draft Central historical pick lists.
- Live or completed draft state sync.
- Discord draft pick announcements for rows that gain `playerId`.
- Commissioner draft audit.
- Manager draft recap views.

It should be paired with `getDraftPicks` when DynastyIQ needs both completed selections and future draft asset inventory.

## Import Behavior

Current behavior:

- `SyncFantraxDraftState` reads draft result rows from:
  - `draftPicks`
  - `draft_picks`
  - `draft.picks`
- The first observed draft payload establishes baseline canonical draft picks.
- Later transitions from unpicked to picked can emit `DraftPickMade`.
- `FantraxDraftingWindow` reads draft result row counts and timestamps for draft status and display.
- Division-scoped rows must preserve `division` so FHL-style drafts do not collapse several parallel pick `1` rows into one league-wide pick.

## Does Not Tell Us

This endpoint does not provide authoritative:

- Future draft asset current owner/original owner.
- Player display name.
- Player NHL/canonical id.
- Team display avatar/logo.
- User ownership.
- Division/player-pool scope.
- Roster membership.
- Pick trade conditions.

Use `getDraftPicks.futureDraftPicks` for future asset inventory, `getLeagueInfo` for team/division context, Fantrax player identity data for player display, and platform team mappings for team display.

## Schema And UI Implications

Draft result rows should mirror into canonical draft and draft pick models, preserving provider ids while resolving platform teams and canonical players where possible.

Because the observed samples include `draftOrder` separately from draft rows, Draft Central can display provider order even when not every pick row has a selected player yet. For FHL-style division drafts, the schema or normalized payload must retain division scope so repeated pick numbers across divisions do not collide. Made-pick truth still requires a `draftPicks[]` row with `playerId`.

## Open Verification Questions

- What `draftState` values exist besides `completed`?
- During a live draft, are unmade picks included in `draftPicks`, or only completed picks?
- Does `draftOrder` represent first-round order only for snake drafts?
- In slow drafts, can `time` be absent or null for pending picks?
- Do auction drafts add price or winning bid fields?
- Do keeper drafts add keeper-specific flags?
- In `ACROSS_DIVISIONS` leagues, does this endpoint include division context anywhere, or must team id be joined to `getLeagueInfo`?
