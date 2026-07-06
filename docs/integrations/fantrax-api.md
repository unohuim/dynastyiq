# Fantrax API Reference

Version: v1.2 (Beta)

## Introduction

The Fantrax REST API provides access to data for fantasy leagues on Fantrax.

The API is draft/beta documentation. Fantrax notes that the API has been built, and will continue to be built, based on user needs. A more complete document is expected in the future.

## API Usage

### General

The Fantrax API is a REST API. Requests are made with HTTP requests, and responses are returned in JSON format.

Extra request data can be sent either as query string parameters or as JSON in the body of a POST request.

Equivalent request examples:

```text
https://www.fantrax.com/fxea/general/getAdp?sport=MLB
```

```http
POST https://www.fantrax.com/fxea/general/getAdp
Content-Type: application/json

{"sport":"NFL"}
```

## Endpoints

### Retrieve Player IDs

Retrieves the Fantrax IDs that identify every player. Other Fantrax API calls use these IDs to refer to players.

URL:

```text
https://www.fantrax.com/fxea/general/getPlayerIds?sport=NFL
```

Request parameters:

None documented.

DynastyIQ usage:

- Config key: `fantrax.players`.
- Current role: source list for the Fantrax player import.
- Evaluation focus: verify whether IDs include inactive/minor/free-agent pool players and whether IDs are stable across leagues.
- Not enough by itself for roster display because it returns identifiers, not league-specific eligibility or ownership context.

### Retrieve Player Info / ADP

Retrieves ADP (Average Draft Pick) information for all players in the specified sport, with optional filters.

URL:

```text
https://www.fantrax.com/fxea/general/getAdp
```

Request parameters:

| Parameter | Required | Description |
| --- | --- | --- |
| `sport` | Yes | One of `NFL`, `MLB`, `NHL`, `NBA`, `NCAAF`, `NCAAB`, `PGA`, `NASCAR`, `EPL`. |
| `position` | No | Standard position abbreviations, such as `QB` or `WR` for football. |
| `showAllPositions` | No | Whether to show the default position or all Fantrax positions for the player. Can be `true` or `false`. |
| `start` | No | Start offset. |
| `limit` | No | Result limit. |
| `order` | No | Player order. Can be `Name` or `ADP`; defaults to `ADP`. |

Request body examples:

```json
{"sport":"NFL"}
```

```json
{"sport":"NFL","position":"QB","start":1,"limit":5,"order":"NAME"}
```

Query string example:

```text
https://www.fantrax.com/fxea/general/getAdp?sport=NFL
```

DynastyIQ usage:

- Config key: `fantrax.adp`.
- Candidate role: supplement Fantrax player import with broad player-pool information and optional all-position data.
- Evaluation focus: call with `sport=NHL&showAllPositions=true&limit=10` and inspect whether returned player IDs match `getPlayerIds` IDs, whether position eligibility is league-neutral or league-specific, and whether inactive/minor players appear.
- Do not use ADP as roster membership truth; it is player-pool/draft-market context.

### Retrieve League List

Retrieves the list of leagues, including the name and ID of each league, and the name(s) and ID(s) that the user owns in each league.

URL:

```text
https://www.fantrax.com/fxea/general/getLeagues
```

Request parameters:

| Parameter | Required | Description |
| --- | --- | --- |
| `userSecretId` | Yes | Secret ID shown on the Fantrax user profile screen. |

Request body example:

```json
{"userSecretId":"24pscnquxwekzngy"}
```

Query string example:

```text
https://www.fantrax.com/fxea/general/getLeagues?userSecretId=24pscnquxwekzngy
```

DynastyIQ usage:

- Config key: `fantrax.user_leagues`.
- Current role: user Fantrax connection bootstrap.
- Evaluation focus: confirm league IDs, owned team IDs, team names, and whether leagues that are inactive, archived, or non-hockey appear.
- This endpoint should remain the source for discovering a user's Fantrax leagues from their secret ID.

### Retrieve League Info

Retrieves information about a specific league. This includes team names and IDs, matchups, players in the pool with info, and many league configuration settings.

URL:

```text
https://www.fantrax.com/fxea/general/getLeagueInfo?leagueId=[League ID]
```

Request parameters:

None documented.

DynastyIQ usage:

- Config key: `fantrax.league_info`.
- Current role: league-specific enrichment for Fantrax sync.
- Useful payload areas observed so far:
  - `playerInfo`: keyed by Fantrax player ID, includes `eligiblePos` and ownership-style `status`.
  - `rosterInfo`: candidate source for roster slot settings, display order, and league-specific roster constraints.
- Evaluation focus:
  - Confirm whether every rostered player ID from `getTeamRosters.rosters.*.rosterItems.*.id` exists in `playerInfo`.
  - Confirm whether `playerInfo.*.eligiblePos` is league-specific and more complete than roster item `position`.
  - Confirm whether `playerInfo.*.status` uses `T` for taken and `FA` for free agent; do not treat it as active/bench/minors roster status.
  - Inspect `rosterInfo` for slot names, counts, minimums/maximums, position limits, and ordering.
- Current app behavior should prefer `playerInfo.*.eligiblePos` for `platform_roster_memberships.eligibility` when syncing rostered players.
- Free-agent storage is intentionally deferred until the app has a dedicated model for league-specific player pool state.

### Retrieve Draft Pick Info

Retrieves future and current draft picks in a specific league.

URL:

```text
https://www.fantrax.com/fxea/general/getDraftPicks?leagueId=[League ID]
```

Request parameters:

None documented.

DynastyIQ usage:

- Config key: `fantrax.draft_picks`.
- Candidate role: future draft asset tracking for dynasty leagues.
- Evaluation focus: inspect owner team IDs, original team IDs, season/year fields, round fields, pick numbers, conditions, and whether traded future picks are represented.
- Do not map this into roster tables; it likely needs a dedicated draft asset model if adopted.

### Retrieve Draft Results

Retrieves draft results for a specific league. Results can be retrieved live during a draft.

URL:

```text
https://www.fantrax.com/fxea/general/getDraftResults?leagueId=[League ID]
```

Request parameters:

None documented.

DynastyIQ usage:

- Config key: `fantrax.draft_results`.
- Candidate role: historical draft context and player acquisition source.
- Evaluation focus: inspect whether the payload includes Fantrax player IDs, pick number, round, team ID, timestamp, auction value, keeper flags, and live draft status.
- If adopted, store as league draft history rather than mutating current roster membership directly.

### Retrieve Team Rosters

Retrieves roster data for all teams, including salary and contract data if the league uses it, plus rostered players, statuses, and positions.

By default, rosters are retrieved for the upcoming/current period. The current period changes once the last game of the period starts. A specific period can also be requested.

URL:

```text
https://www.fantrax.com/fxea/general/getTeamRosters?leagueId=[League ID]&period=6
```

Request parameters:

| Parameter | Required | Description |
| --- | --- | --- |
| `period` | No | Lineup period for which rosters are returned. |

DynastyIQ usage:

- Config key: `fantrax.team_rosters`.
- Current role: authoritative source for current team roster membership.
- Useful payload areas observed so far:
  - `rosters`: keyed by Fantrax team ID.
  - `rosterItems`: includes player `id`, lineup `position`, salary, and roster `status`.
  - `rosterItems.*.status`: observed values include `ACTIVE`, `RESERVE`, and `MINORS`.
- Evaluation focus:
  - Confirm that all league teams are returned, not only the authenticated user's teams.
  - Confirm `status` semantics across leagues with minors, reserve, injured reserve, and custom bench slots.
  - Confirm whether `position` is assigned lineup slot or base position, and prefer `getLeagueInfo.playerInfo.*.eligiblePos` for eligibility when available.
- This endpoint should remain the source of truth for who is rostered on each team and whether they are active, reserve/bench, or minors.

### Retrieve League Standings

Retrieves the current league standings. This includes basic standings data such as rank, points, W-L-T, games back, and win percentage.

Individual stats are not yet included, but Fantrax expects to include them in a future release.

URL:

```text
https://www.fantrax.com/fxea/general/getStandings?leagueId=[League ID]
```

Request parameters:

None documented.

DynastyIQ usage:

- Config key: `fantrax.standings`.
- Candidate role: league context for team ordering, playoff race context, and commissioner/team overview surfaces.
- Evaluation focus: inspect team IDs, rank, points, record fields, win percentage, games back, division support, playoff seed, and whether standings match Fantrax UI ordering.
- Do not use standings to infer roster membership or user ownership.

## Local Payload Evaluation

Codex sessions must not read `.env*` files or commit real Fantrax secrets. Use these commands locally with your own values, then paste sanitized payload excerpts back into the chat when deeper evaluation is needed.

Set shell variables:

```bash
export FANTRAX_SECRET_ID='your-secret-id'
export FANTRAX_LEAGUE_ID='your-league-id'
export FANTRAX_PLAYER_ID='player-id-from-roster-or-player-list'
```

Fetch payloads:

```bash
curl -s "https://www.fantrax.com/fxea/general/getLeagues?userSecretId=${FANTRAX_SECRET_ID}" | jq .
curl -s "https://www.fantrax.com/fxea/general/getLeagueInfo?leagueId=${FANTRAX_LEAGUE_ID}" | jq .
curl -s "https://www.fantrax.com/fxea/general/getTeamRosters?leagueId=${FANTRAX_LEAGUE_ID}" | jq .
curl -s "https://www.fantrax.com/fxea/general/getPlayerIds?sport=NHL" | jq .
curl -s "https://www.fantrax.com/fxea/general/getAdp?sport=NHL&showAllPositions=true&limit=10" | jq .
curl -s "https://www.fantrax.com/fxea/general/getDraftPicks?leagueId=${FANTRAX_LEAGUE_ID}" | jq .
curl -s "https://www.fantrax.com/fxea/general/getDraftResults?leagueId=${FANTRAX_LEAGUE_ID}" | jq .
curl -s "https://www.fantrax.com/fxea/general/getStandings?leagueId=${FANTRAX_LEAGUE_ID}" | jq .
curl -s "https://www.fantrax.com/fxea/general/getPlayerProfile?leagueId=${FANTRAX_LEAGUE_ID}&playerId=${FANTRAX_PLAYER_ID}" | jq .
```

Inspect logo-like fields for a synced Fantrax league:

```bash
php artisan fantrax:inspect-logos "${FANTRAX_LEAGUE_ID}" --platform-id --json
```

Use this before wiring logo persistence to a new Fantrax payload shape. The command checks
`getLeagueInfo`, `getTeamRosters`, and `getStandings`, then prints only logo-like keys
or image URLs with their JSON paths.

Inspect browser network responses for the authenticated Fantrax web app:

```bash
node scripts/inspect-fantrax-network.mjs
```

The script uses `FANTRAX_BROWSER_PROFILE_PATH` for its persistent Chromium profile,
falling back to `/tmp/dynastyiq-fantrax-profile` when the variable is not set. If the
Fantrax page requires login, use the opened browser window to log in, then reload
the page. It prints matching XHR/fetch/document excerpts and `fantraximg.com` image
requests. For matched POST/PUT/PATCH browser requests, it
also writes the request body so authenticated `fxpa/req` payload names can be
identified without logging cookies or authorization headers. Inspection dumps are
written under `docs/inspection` as you browse, using the current league and page route, such as
`league_uf1sdl47mo6nzpr6_home_reload_1.txt` or
`league_uf1sdl47mo6nzpr6_standings.txt`.

The community league options drawer and the `/leagues/{id}` options drawer can
trigger a league-scoped Fantrax team logo sync for commissioner-managed leagues.
The sync uses the configured profile directory only; it does not store Fantrax
credentials or prove that the profile is currently authenticated.
When visible Chromium is used and Fantrax requires login, the logo sync waits for
the commissioner to authenticate, captures the logo payload after the Fantrax
league page loads, and then closes Chromium automatically. The login wait is
bounded so the browser does not stay open indefinitely.

Pass a URL only when a specific starting page is needed:

```bash
node scripts/inspect-fantrax-network.mjs "https://www.fantrax.com/fantasy/league/${FANTRAX_LEAGUE_ID}/standings"
```

Observed Fantrax web team logo format:

```text
https://fantraximg.com/logos/{bucket}/tmLogo_{logoId}_128.webp
```

Example:

```text
https://fantraximg.com/logos/yyu/tmLogo_yyu4dyusmora3ff8_128.webp
```

The `logoId` is not the Fantrax team ID. Authenticated web payloads observed via
`fxpa/req` can include direct mappings such as `fantasyTeams.*.id` to
`fantasyTeams.*.logoUrl128` and user league-list `teamId` to `teamLogo`.

Minimum inspection checklist:

- `getLeagueInfo.playerInfo`: count records, sample rostered IDs, compare `eligiblePos` against Fantrax UI.
- `getLeagueInfo.rosterInfo`: identify slot names, counts, ordering, reserve/minor/injured settings.
- `getTeamRosters.rosters.*.rosterItems`: sample `id`, `position`, `status`, salary fields, and whether every team appears.
- `getAdp`: compare player IDs and all-position data against league-specific `playerInfo`.
- `getDraftPicks`: confirm future pick shape and whether original/current owner team IDs are present.
- `getDraftResults`: confirm player IDs, team IDs, pick order, and whether auction/keeper metadata exists.
- `getStandings`: confirm team IDs and ordering fields match Fantrax UI.
