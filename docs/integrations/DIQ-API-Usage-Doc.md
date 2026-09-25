# DynastyIQ API Usage Guide

## Selected starters without goalie history

Game predictions retain the selected starter even without a personal goalie
projection. `goalies.away` / `goalies.home` expose `projection_source: league_average`
and `projection_fallback_reason: missing_goalie_projection`. His name and NHL ID
remain unchanged (ID may be null for a name-only manual starter). The estimate
uses league expected rates from the selected season/version weighted by projected
games, neutral GA/xGA of 1 and GSAx of 0, including penalty-kill contribution.
Confidence is low; season games, starts and workload remain null. Nothing is
persisted. Modelled starters return `projection_source: goalie_model` and a null
fallback reason. Other model and lineup prerequisites remain unchanged.

This is the official and canonical API usage guide for every DynastyIQ partner
and API consumer. It covers authentication, reference dependencies, NHL season
stats, availability data, anticipated lineups, and game predictions. Consumer-
specific implementation notes are subordinate to this contract.

## Authentication And Scopes

Every request must include the configured DynastyIQ API token.

```http
Accept: application/json
Authorization: Bearer <DYNASTYIQ_API_TOKEN>
```

Endpoint scopes:

| Endpoint | Scope |
| --- | --- |
| `GET /api/nhl-teams` | `nhl-reference:read` |
| `GET /api/nhl-players` | `nhl-reference:read` |
| `GET /api/nhl-season-stats` | `nhl-stats:read` |
| `GET /api/nhl-starting-goalies` | `nhl-stats:read` |
| `GET /api/nhl-anticipated-lineups` | `nhl-stats:read` |
| `GET /api/nhl/lineups` | `nhl-stats:read` |
| `GET /api/nhl-game-predictions` | `nhl-stats:read` |
| `POST /api/nhl-lineups` | `nhl-lineups:write` |

Most partner applications need a token with both `nhl-reference:read` and
`nhl-stats:read` so they can resolve teams, players, stats, and predictions
with one configured client.

Writes require a separate, explicitly issued key. Existing read tokens are not
upgraded and the write scope does not grant read access.

## Read Game Lineups By Date

`GET /api/nhl/lineups?date=2026-09-24`

Use the existing **read key** (`nhl-stats:read`), not the separate write-only key.
`date` is required in `YYYY-MM-DD` format and selects the stored NHL schedule
calendar date, without conversion to the caller's timezone. Missing or invalid
dates return `422`. A date with no games returns `games: []` and `meta.count: 0`.
Missing credentials return `401`; invalid, revoked, or wrong-scope keys return `403`.

Every scheduled game is returned, ordered by start time and then NHL game ID.
Both teams are present even if neither lineup has been reported. Split-squad
games retain separate `nhl_game_id` values; do not key records by team/date alone.
`team_id` is the NHL team identifier, not the consumer's local team ID.

```json
{
  "games": [
    {
      "nhl_game_id": 2026010088,
      "game_date": "2026-09-24",
      "start_time_utc": "2026-09-24T23:00:00+00:00",
      "game_state": "FUT",
      "teams": {
        "away": {
          "team_id": 8,
          "team_abbrev": "MTL",
          "lineup_status": "not_reported",
          "evidence_status": null,
          "updated_at": null,
          "players": [],
          "sources": [],
          "starting_goalie": null
        },
        "home": {
          "team_id": 10,
          "team_abbrev": "TOR",
          "lineup_status": "not_reported",
          "evidence_status": null,
          "updated_at": null,
          "players": [],
          "sources": [],
          "starting_goalie": null
        }
      }
    }
  ],
  "meta": {
    "date": "2026-09-24",
    "count": 1,
    "generated_at": "2026-09-24T12:00:00+00:00"
  }
}
```

`lineup_status` distinguishes unreported, reported, manual, and official lineups
(canonical values are listed in `docs/ENUMS.md`). Corroborated evidence displays
as reported; `evidence_status` retains the original confidence distinction.
Manual means the currently selected eligible manual override, including partner
submissions. Ineligible or incomplete stored observations remain not reported.

For accepted lineups, `players` uses the existing anticipated-lineup player schema:
`player_id`, `nhl_player_id`, `player_name`, `lineup_role`, `line_key`, `slot_index`,
`power_play_unit`, `penalty_kill_unit`, and `resolution_status`. It includes listed
goalies/scratches when present; permitted unresolved peers retain null IDs. Sources
use the existing lineup source schema. `updated_at` is the accepted lineup's
`last_observed_at`, not the request time. Unreported teams always return empty
`players` and `sources`, never a guessed skater lineup.

`starting_goalie` is selected independently through the same selector used by
predictions, even for unreported teams. It is `null` when no selection is available;
otherwise it includes `nhl_player_id`, `name`, `avatar_url`, `status`, and
`selection_source`, plus `provider`/`observed_at` for observed evidence when available.
Display the supplied status (expected, confirmed, or projected); a projected goalie
does not make the team's lineup reported. Listed G2 remains in `players` as backup
context and is not a second starter. Game-specific manual starter decisions apply.

This is a persisted-data read: it does not import boxscores, contact X, or calculate
predictions. Scores/live play are outside this endpoint. Data freshness depends
on the existing import/sync processes. Existing read and write routes are unchanged.

## Submit A Lineup (Authoritative Manual Override)

`POST https://dynastyiq.com/api/nhl-lineups` accepts a full team lineup through
the same text parser, player resolver, OCR, and validation used by the DynastyIQ
manual lineup modal. This is currently an authoritative submission, not a proposal
awaiting moderation. The latest **valid saved** manual submission wins for that
game/team, whether submitted through DynastyIQ or any authorized partner key.
Save order is server-controlled (observation id breaks timestamp ties); supplied
publication timestamps or claimed submitter identities cannot change it.

### Separate Write Key And Environment

In DynastyIQ **Admin → API Keys**, create a key named `gner8 Lineups Write` with
**only NHL Lineups Write** (`nhl-lineups:write`) selected. Leave the existing read
key untouched. Copy the new token when shown; only its hash is stored afterward.
Issue a different key for each additional submitting integration/person so
attribution and revocation remain independent.

Recommended entry on the **submitting server (gner8)**:

```dotenv
DYNASTYIQ_LINEUPS_WRITE_TOKEN=<new-write-key-from-DynastyIQ>
```

The submitting application must read this variable and use its value as the
Bearer token for lineup POSTs. This is the integration configuration contract,
not an environment variable read by DynastyIQ. DynastyIQ needs **no new `.env`
entry**: it authenticates `api_clients` token hashes. Keep the token server-side,
out of browser bundles, logs, and source control. Do not use a `VITE_` variable.

### Input

| Field | Required | Meaning |
| --- | --- | --- |
| `nhl_game_id` | Yes | Exact scheduled NHL game ID; required to distinguish split squads. |
| `team_abbrev` | Yes | Participating team, such as `TOR`; normalized to uppercase. |
| `text` | Unless providing an image or post URL | Full lineup text, maximum 20,000 characters. |
| `image` | Unless providing text or a post URL | Multipart JPEG/PNG upload, maximum 10 MiB. Image URLs, base64 strings, PDFs and arbitrary files are not accepted. |
| `post_url` | Unless providing text or an image | HTTPS X/Twitter status URL. Fetches that exact post through DynastyIQ's configured X API token. |
| `image_reviewed` | No | Send `true`/`1` with reviewed text to validate that text without substituting OCR output. Text is then required. |

Send JSON for text only, or `multipart/form-data` for uploads (let the HTTP client
generate the multipart boundary). Include `Accept: application/json` and
`Authorization: Bearer <write-key>`. No browser session or CSRF token is needed.

```bash
curl -X POST 'https://dynastyiq.com/api/nhl-lineups' \
  -H "Authorization: Bearer $DYNASTYIQ_LINEUPS_WRITE_TOKEN" \
  -H 'Accept: application/json' \
  -F 'nhl_game_id=2026010036' \
  -F 'team_abbrev=TOR' \
  -F 'text=<lineup.txt'

curl -X POST 'https://dynastyiq.com/api/nhl-lineups' \
  -H "Authorization: Bearer $DYNASTYIQ_LINEUPS_WRITE_TOKEN" \
  -H 'Accept: application/json' \
  -F 'nhl_game_id=2026010036' \
  -F 'team_abbrev=TOR' \
  -F 'image=@lineup.png'
```

The roster still needs twelve forward slots and six defense slots under the
existing identity/peer-fallback rules. A skater's usual database position does not
restrict their reported F/D slot, but goalies cannot occupy skater slots. Resolved
players with a nonempty canonical team assignment must match the submitted team
for regular-season and playoff games, including listed goalies; blank/null assignments are allowed.
For preseason games (`game_type = 1`), recognized players may belong to another
canonical team, without rewriting their team assignment. NHL/AHL/prospect league assignment is not a restriction.
Outside preseason, a known wrong-team player is rejected, not treated as an unresolved peer fallback.
The `422 errors.text` response names offending players and their recorded teams.
This check applies only to new submissions. Previously accepted lineups remain
eligible without retroactive team-membership checks, including prediction reads.
An explicit sectioned roster table is the exception for mixed-team content:
Goaltenders/Goalies, Defensemen/Defencemen, and Forwards headings with stat-column
labels (such as GP/PTS/GAA/SV) may appear in any order. Known opponent players are ignored,
while recognized players with blank team assignments are allowed,
and the selected team must resolve to exactly 12 forwards and 6 defensemen, with
up to two goalies and no duplicates. Jersey numbers and stats are not player slots.
Selected forwards form F1-F4 in listed groups of three; defensemen form D1-D3 in
pairs. First goalie is G1, second G2. These groups are inferred from roster order,
not proof of actual line combinations. Unrecognized names cannot fill missing
slots in this table format. Mixed-team table filtering remains active in preseason
so opponent columns are not combined; ordinary preseason line submissions permit cross-team players.
This endpoint does not accept a
goalie-only submission. Goalies are optional additions: G1 becomes the expected
manual starter, G2 remains the dressed backup. The latest manual goalie decision
(UI, API, or explicit goalie picker) takes precedence over provider expectations
and confirmations for prediction selection. Explicit prediction request goalie
overrides remain first; live NHL boxscore presentation stays unchanged.

### Submit An X Post URL

```json
{
  "nhl_game_id": 2026010036,
  "team_abbrev": "TOR",
  "post_url": "https://x.com/reporter/status/1234567890123456789"
}
```

`post_url` is optional; at least one of text, image, or post URL is required.
Only HTTPS X/Twitter status links are accepted (including `i/status` and
`i/web/status` forms). Profile/search links and shortened URLs are not followed.
The service extracts the numeric post ID and calls the fixed X API host once,
without timeline searches, redirects, or automatic retries. This can incur an X
Post Read charge using the existing DynastyIQ `X_BEARER_TOKEN`; the partner still
sends its DynastyIQ write key, never the X token.

When a URL is supplied it is always fetched. Explicit nonempty `text` remains
the preferred lineup input; otherwise the full fetched post text is used.
An uploaded image takes priority over post attachments. If no upload is supplied
and the text lacks a valid full roster, attached photos use the existing bounded
OCR path (up to four photos). `image_reviewed=1` with explicit text suppresses
automatic OCR substitution. Low-confidence OCR is returned for correction but
cannot bypass player/roster validation. Videos and linked articles are not read.

Accepted URL submissions remain **manual overrides attributed to the API client**,
not automatically trusted X-source discoveries. The explicit game/team is the
manual target; automated discovery's date/context restrictions are not applied.
Server save time determines manual ordering, not the X publication timestamp.
The original post, author ID, publication time, metrics, media, and URL remain in
raw evidence. Source links point to the fetched post with a unique submission
fragment, so submitting the same URL again creates a fresh manual observation.

Invalid URLs, missing X configuration, unavailable/private/deleted posts, provider
access/credit errors, rate limits, and network failures return `422` with
`errors.post_url`; the existing lineup is unchanged. OCR/roster validation still
returns `errors.image` or `errors.text`. Usage is recorded separately as
`nhl_lineup_post_lookup`, not attributed to an admin import run.

### Responses

`201 Created` means validation and persistence succeeded:

```json
{
  "success": true,
  "message": "Lineup accepted as the current manual override.",
  "submission_id": 1234,
  "nhl_game_id": 2026010036,
  "team_abbrev": "TOR",
  "lineup": { "manual_override": true, "team_abbrev": "TOR", "players": [] },
  "starting_goalie": null
}
```

The example abbreviates `lineup`; the actual response includes the accepted
players, slots, source attribution, and evidence status using the existing lineup
read schema. `starting_goalie` contains the shared selection object when one is
available. Every accepted resubmission creates a new immutable observation;
retries are new manual submissions, not idempotent replays.

`422 Unprocessable Entity` leaves the previous lineup and goalies unchanged:

```json
{
  "success": false,
  "message": "Select a known NHL team participating in this game.",
  "errors": { "team_abbrev": ["Select a known NHL team participating in this game."] },
  "interpreted_text": ""
}
```

For parsing/OCR failures, `errors.text` or `errors.image` explains the rejection;
`interpreted_text` provides submitted/extracted text where available so the caller
can offer correction and resubmission. Low-confidence images do not bypass roster
validation. Other errors use standard JSON `message` responses: `401` missing
Bearer token, `403` invalid/revoked/wrong-scope token, `404` unknown game. Uploads
rejected by the web server before Laravel may return `413`. Text/upload-only
submissions make no X requests; `post_url` submissions fetch that specific post.
No OpenAI requests are made.

## NHL Season Stats Endpoint

Call the season stats endpoint once per stat group and window.

A full regular-season import is 12 requests:

```text
basic   + season
basic   + last_5
basic   + last_10
basic   + last_20
on_ice  + season
on_ice  + last_5
on_ice  + last_10
on_ice  + last_20
expected + season
expected + last_5
expected + last_10
expected + last_20
```

```http
GET /api/nhl-season-stats?season=20252026&game_type=2&stat_group=basic&window_key=season
Accept: application/json
Authorization: Bearer <DYNASTYIQ_API_TOKEN>
```

Production URL:

```http
https://dynastyiq.com/api/nhl-season-stats?season=20252026&game_type=2&stat_group=basic&window_key=season
```

Local URL:

```http
http://dynastyiq.test/api/nhl-season-stats?season=20252026&game_type=2&stat_group=basic&window_key=season
```

Required scope:

```text
nhl-stats:read
```

### Query Parameters

| Parameter | Required | Example | Meaning |
| --- | --- | --- | --- |
| `season` | No | `20252026` | NHL season key. Defaults to DynastyIQ's current NHL season. |
| `game_type` | No | `2` | NHL game type. Use `2` for regular season. |
| `stat_group` | No | `basic` | Limits response to `basic`, `on_ice`, or `expected`. Required for full imports. |
| `window_key` | No | `season` | Limits response to `season`, `last_5`, `last_10`, or `last_20`. Required for full imports. |

The unfiltered endpoint remains available for diagnostics, but consumers should use chunked pulls for season imports so DynastyIQ does not build one large JSON response.

### Payload Fields

Top-level payload:

| Field | Type | Meaning |
| --- | --- | --- |
| `league_abbrev` | string | Always `NHL`. The consumer maps this to its local league identity. |
| `season` | object | Requested season metadata. |
| `stat_types` | array | All stat definitions currently emitted by DynastyIQ. |
| `player_stats` | array | Skater and goalie stat values by stat type and window. |
| `player_stat_features` | array | Derived comparison features, currently recent expected-rate vs season baseline. |
| `meta` | object | Source and request metadata. |

`season` object:

| Field | Type | Meaning |
| --- | --- | --- |
| `league_abbrev` | string | Always `NHL`. |
| `season_key` | string | Season key, for example `20252026`. |
| `label` | string | Human label, for example `2025-26`. |
| `starts_on` | string|null | First game date found for the requested season/game type. |
| `ends_on` | string|null | Last game date found for the requested season/game type. |
| `current` | boolean | Whether DynastyIQ considers this the current NHL season. |

`stat_types[]` object:

| Field | Type | Meaning |
| --- | --- | --- |
| `league_abbrev` | string | Always `NHL`. |
| `slug` | string | Stable stat identifier used by `player_stats[].stat_slug`. |
| `name` | string | Display name. |
| `stat_group` | string | `basic`, `on_ice`, or `expected`. |
| `value_type` | string | `integer`, `decimal`, or `percentage`. |
| `unit` | string|null | Value unit, such as `goals`, `shots`, `attempts`, `seconds`, or `percent`. |
| `supports_per_game` | boolean | Whether the consumer may derive per-game values. |
| `supports_per_60` | boolean | Whether the consumer may derive per-60 values. |
| `higher_is_better` | boolean|null | Sort/evaluation hint. |
| `active` | boolean | Whether the stat type is active. |
| `metadata` | object | Reserved extension field. |

`player_stats[]` object:

| Field | Type | Meaning |
| --- | --- | --- |
| `league_abbrev` | string | Always `NHL`. |
| `season_key` | string | Season key for this stat row. |
| `nhl_player_id` | integer | NHL player id. Consumers map this to their local player identity. |
| `stat_slug` | string | Joins to `stat_types[].slug`. |
| `stat_group` | string | `basic`, `on_ice`, or `expected`. |
| `window_key` | string | `season`, `last_5`, `last_10`, or `last_20`. |
| `window_games` | integer|null | Actual qualifying games represented by the row. |
| `start_date` | string|null | First game date in the row window when available. |
| `end_date` | string|null | Last game date in the row window when available. |
| `value` | number|null | Stat value. |
| `source_system` | string | Always `dynastyiq`. |
| `source_fetched_at` | string | Timestamp when DynastyIQ built the payload. |
| `metadata` | object | Extra row metadata, such as `nhl_team_id` where available. |

`player_stats[].value` is JSON numeric. Consumers must use the matching
`stat_types[].value_type` row for semantic typing. Whole-number decimal or
percentage values may decode as integers in PHP JSON clients, so `2` and `2.0`
must be treated as the same numeric value when the stat type is `decimal` or
`percentage`.

`player_stat_features[]` object:

| Field | Type | Meaning |
| --- | --- | --- |
| `league_abbrev` | string | Always `NHL`. |
| `season_key` | string | Season key for this feature row. |
| `nhl_player_id` | integer | NHL player id. |
| `stat_slug` | string | Stat being compared. |
| `stat_group` | string | Currently `expected`. |
| `window_key` | string | Current comparison window, currently `last_10`. |
| `baseline_window_key` | string | Baseline window, currently `season`. |
| `raw_value` | number|null | Recent-window value. |
| `per_game` | number|null | Reserved; currently null. |
| `per_60` | number|null | Reserved; currently null. |
| `baseline_value` | number|null | Season baseline value. |
| `baseline_per_game` | number|null | Reserved; currently null. |
| `baseline_per_60` | number|null | Reserved; currently null. |
| `deviation_absolute` | number|null | `raw_value - baseline_value`. |
| `deviation_percent` | number|null | Relative deviation from baseline. |
| `sample_games` | integer | Games in the recent sample. |
| `reliable_games_required` | integer | Games required for high confidence. |
| `coverage_ratio` | number | `sample_games / reliable_games_required`, capped at `1`. |
| `confidence_label` | string | `low`, `medium`, or `high`. |
| `confidence_score` | number | Confidence score from `0` to `1`. |
| `generated_at` | string | Feature generation timestamp. |
| `metadata` | object | Reserved extension field. |

`meta` object:

| Field | Type | Meaning |
| --- | --- | --- |
| `source_system` | string | Always `dynastyiq`. |
| `source_fetched_at` | string | Payload generation timestamp. |
| `season_key` | string | Requested season key. |
| `game_type` | integer | Requested NHL game type. |
| `stat_group` | string|null | Requested stat-group filter, if provided. |
| `window_key` | string|null | Requested window filter, if provided. |

## Stat Groups

| Group | Meaning |
| --- | --- |
| `basic` | Player boxscore and season-summary totals. |
| `on_ice` | Player on-ice counts while the player was on the ice. |
| `expected` | Shot-attempt probability outputs and derived xG/xSOG metrics. |

## Windows

DynastyIQ emits the same supported windows for `basic`, `on_ice`, and `expected` player stat rows when the player has qualifying data for that window. Goalie rows use the same endpoint, same `player_stats[]` shape, and distinct goalie stat slugs.

Consumers should ingest goalie rows exactly like skater rows. The row identity is
still `nhl_player_id + stat_slug + window_key` after resolving
`league_abbrev` and `season_key`; goalie rows are distinguished only by
`goalie_`-prefixed stat slugs and the referenced player.

| Window | Meaning |
| --- | --- |
| `season` | Full requested season and game type. |
| `last_5` | Most recent 5 qualifying player games in the requested season and game type. |
| `last_10` | Most recent 10 qualifying player games in the requested season and game type. |
| `last_20` | Most recent 20 qualifying player games in the requested season and game type. |

## Stat Slugs

| Slug | Group | Unit | Meaning |
| --- | --- | --- | --- |
| `games_played` | `basic` | `games` | Games played. |
| `goals` | `basic` | `goals` | Goals. |
| `assists` | `basic` | `assists` | Assists. |
| `points` | `basic` | `points` | Points. |
| `shots_on_goal` | `basic` | `shots` | Shots on goal. |
| `sat` | `basic` | `attempts` | Shot attempts. |
| `toi_seconds` | `basic` | `seconds` | Total time on ice in seconds. |
| `goalie_starts` | `basic` | `games` | Goalie starts. |
| `goalie_relief_appearances` | `basic` | `games` | Goalie relief appearances. |
| `goalie_wins` | `basic` | `wins` | Goalie wins, including overtime and shootout wins. |
| `goalie_losses` | `basic` | `losses` | Regulation goalie losses. |
| `goalie_ot_losses` | `basic` | `losses` | Overtime goalie losses. |
| `goalie_overtime_wins` | `basic` | `wins` | Overtime goalie wins. |
| `goalie_shootout_wins` | `basic` | `wins` | Shootout goalie wins. |
| `goalie_shootout_losses` | `basic` | `losses` | Shootout goalie losses. |
| `goalie_shots_against` | `basic` | `shots` | Goalie shots against. |
| `goalie_saves` | `basic` | `saves` | Goalie saves. |
| `goalie_goals_against` | `basic` | `goals` | Goalie goals against. |
| `goalie_save_percentage` | `basic` | `percent` | Saves divided by shots against. |
| `goalie_goals_against_average` | `basic` | `goals` | Goals against per 60 goalie TOI minutes. |
| `goalie_shutouts` | `basic` | `shutouts` | Goalie shutouts. |
| `goalie_quality_starts` | `basic` | `starts` | Goalie quality starts. |
| `goalie_really_bad_starts` | `basic` | `starts` | Goalie really bad starts. |
| `goalie_quality_start_percentage` | `basic` | `percent` | Quality starts divided by starts. |
| `on_ice_toi_seconds` | `on_ice` | `seconds` | On-ice time in seconds. |
| `on_ice_gf` | `on_ice` | `goals` | Goals for while on ice. |
| `on_ice_ga` | `on_ice` | `goals` | Goals against while on ice. |
| `on_ice_sf` | `on_ice` | `shots` | Shots for while on ice. |
| `on_ice_sa` | `on_ice` | `shots` | Shots against while on ice. |
| `on_ice_satf` | `on_ice` | `attempts` | Shot attempts for while on ice. |
| `on_ice_sata` | `on_ice` | `attempts` | Shot attempts against while on ice. |
| `ixg` | `expected` | `goals` | Individual expected goals. |
| `xsog` | `expected` | `shots` | Expected shots on goal. |
| `xg_per_sat` | `expected` | `percent` | Expected goals per shot attempt. |
| `xsog_per_sat` | `expected` | `percent` | Expected shots on goal per shot attempt. |
| `sog_minus_xsog` | `expected` | `shots` | Actual shots on goal above expected shots on goal. |
| `goals_minus_ixg` | `expected` | `goals` | Actual goals above individual expected goals. |
| `ixg_share` | `expected` | `percent` | Player share of team individual xG. |
| `on_ice_xgf` | `expected` | `goals` | Expected goals for while on ice. |
| `on_ice_xga` | `expected` | `goals` | Expected goals against while on ice. |
| `on_ice_xg_pct` | `expected` | `percent` | On-ice xG share: xGF / (xGF + xGA). |
| `on_ice_xg_diff` | `expected` | `goals` | On-ice xGF minus xGA. |
| `goalie_sata` | `expected` | `attempts` | Shot attempts against assigned to the goalie in net. |
| `goalie_xga` | `expected` | `goals` | Expected goals against assigned to the goalie in net. |
| `goalie_xsoga` | `expected` | `shots` | Expected shots on goal against assigned to the goalie in net. |
| `goalie_xsaves` | `expected` | `saves` | Expected saves: `goalie_xsoga - goalie_xga`. |
| `goalie_gsax` | `expected` | `goals` | Goals saved above expected: `goalie_xga - actual goals against`. |
| `goalie_xsave_percentage` | `expected` | `percent` | Expected save percentage: `goalie_xsaves / goalie_xsoga`. |

## Ingestion Order

1. Resolve `league_abbrev` to the consumer's local league identity.
2. Upsert `season` by `league_id + season_key`.
3. Upsert `stat_types` by `league_id + slug`.
4. Resolve each `player_stats[].nhl_player_id` to the consumer's local player identity.
5. Resolve each `player_stats[].stat_slug` to the consumer's local stat-type identity.
6. Upsert `player_stats`; goalie rows follow the same path as skater rows and should not use a separate goalie table.
7. Resolve and upsert `player_stat_features`.

Rows with unknown `nhl_player_id` should be skipped or quarantined until the consumer imports the missing player reference row.

## Upsert Keys

| Target table | Upsert identity |
| --- | --- |
| `seasons` | `league_id + season_key` |
| `nhl_stat_types` | `league_id + slug` |
| `nhl_player_stats` | `league_id + season_id + player_id + nhl_stat_type_id + window_key` |
| `nhl_player_stat_features` | `league_id + season_id + player_id + nhl_stat_type_id + window_key + baseline_window_key` |

## Consumption Example

Pseudo-code:

```php
$statGroups = ['basic', 'on_ice', 'expected'];
$windowKeys = ['season', 'last_5', 'last_10', 'last_20'];

foreach ($statGroups as $statGroup) {
    foreach ($windowKeys as $windowKey) {
        $payload = $client->get('/api/nhl-season-stats', [
            'season' => '20252026',
            'game_type' => 2,
            'stat_group' => $statGroup,
            'window_key' => $windowKey,
        ]);

        ingestDynastyIqSeasonStatsPayload($payload);
    }
}
```

## Notes

- DynastyIQ intentionally sends `league_abbrev`, not `league_id`. Each consumer owns its local league identifiers.
- Expected stats require DynastyIQ xG and xSOG predictions for the requested season.
- On-ice expected stats require DynastyIQ event-to-shift links.
- A row is emitted only when the player has qualifying data for that stat/window.
- Goalie expected stats require shot-attempt facts with `goalie_player_id` and scored xG/xSOG predictions.
- Feature rows currently compare recent `last_10` expected-rate values against the season baseline.
- Consumers should normalize received numeric values according to `stat_types[].value_type`; JSON decoding may not preserve a float type for whole-number decimal values.

## NHL Starting Goalies Endpoint

This endpoint returns the latest projected or confirmed starting-goalie
observation for each team and game on the requested date. It exposes both flat
goalie rows and matchup-grouped game rows from the same underlying observations.

Selection preserves confirmed-over-expected priority. Official confirmed starters
rank first; within expected reports, game-specific lineup G1 evidence takes
precedence over RotoWire expectations, even if RotoWire was fetched more recently.
Within the same priority, the most recently fetched observation wins, with its
record id breaking timestamp ties.

For split-squad dates, RotoWire observations assigning the same goalie to more
than one game for that team are withheld as ambiguous. This check also applies
when requesting a single `nhl_game_id`. Raw observations remain stored, but an
ambiguous row is not returned as an expected or confirmed starter. A missing row
means no eligible observation, not that the team has no goalie. Predictions and
game cards use the same evidence rules and do not use team-level workload guesses
to fill missing starters when the team plays multiple games on the date.

```http
GET /api/nhl-starting-goalies?date=2026-09-19
Accept: application/json
Authorization: Bearer <DYNASTYIQ_API_TOKEN>
```

Production URL:

```http
https://dynastyiq.com/api/nhl-starting-goalies?date=2026-09-19
```

Local URL:

```http
http://dynastyiq.test/api/nhl-starting-goalies?date=2026-09-19
```

Required scope:

```text
nhl-stats:read
```

### Query Parameters

| Parameter | Required | Example | Meaning |
| --- | --- | --- | --- |
| `date` | No | `2026-09-19` | NHL game date in `YYYY-MM-DD` format. Defaults to today when neither filter is supplied. |
| `nhl_game_id` | No | `2026020001` | Return observations for one DynastyIQ NHL game and derive the date from that game. |

`date` and `nhl_game_id` are mutually exclusive. Supplying both returns a
validation error.

### Response Shape

```json
{
  "starting_goalies": [
    {
      "nhl_game_id": 2026020001,
      "game_date": "2026-09-19",
      "start_time_utc": "2026-09-19T23:00:00+00:00",
      "team_abbrev": "MTL",
      "opponent_abbrev": "TOR",
      "is_home": false,
      "nhl_player_id": 8484170,
      "player_name": "Jacob Fowler",
      "status": "expected",
      "provider": "rotowire",
      "observed_at": "2026-09-18T20:29:00+00:00",
      "season_stats": {
        "season_key": "20252026",
        "games_played": 30,
        "goals_against_average": 3.01,
        "save_percentage": 0.901
      }
    },
    {
      "nhl_game_id": 2026020001,
      "game_date": "2026-09-19",
      "start_time_utc": "2026-09-19T23:00:00+00:00",
      "team_abbrev": "TOR",
      "opponent_abbrev": "MTL",
      "is_home": true,
      "nhl_player_id": 8475683,
      "player_name": "Sergei Bobrovsky",
      "status": "confirmed",
      "provider": "rotowire",
      "observed_at": "2026-09-18T20:29:00+00:00",
      "season_stats": {
        "season_key": "20252026",
        "games_played": 54,
        "goals_against_average": 2.77,
        "save_percentage": 0.912
      }
    }
  ],
  "games": [
    {
      "nhl_game_id": 2026020001,
      "game_date": "2026-09-19",
      "start_time_utc": "2026-09-19T23:00:00+00:00",
      "away_team_abbrev": "MTL",
      "home_team_abbrev": "TOR",
      "away_team_logo": "https://assets.nhle.com/logos/nhl/svg/MTL_light.svg",
      "home_team_logo": "https://assets.nhle.com/logos/nhl/svg/TOR_light.svg",
      "away_goalie": {
        "nhl_game_id": 2026020001,
        "game_date": "2026-09-19",
        "start_time_utc": "2026-09-19T23:00:00+00:00",
        "team_abbrev": "MTL",
        "opponent_abbrev": "TOR",
        "is_home": false,
        "nhl_player_id": 8484170,
        "player_name": "Jacob Fowler",
        "status": "expected",
        "provider": "rotowire",
        "observed_at": "2026-09-18T20:29:00+00:00",
        "season_stats": {
          "season_key": "20252026",
          "games_played": 30,
          "goals_against_average": 3.01,
          "save_percentage": 0.901
        }
      },
      "home_goalie": {
        "nhl_game_id": 2026020001,
        "game_date": "2026-09-19",
        "start_time_utc": "2026-09-19T23:00:00+00:00",
        "team_abbrev": "TOR",
        "opponent_abbrev": "MTL",
        "is_home": true,
        "nhl_player_id": 8475683,
        "player_name": "Sergei Bobrovsky",
        "status": "confirmed",
        "provider": "rotowire",
        "observed_at": "2026-09-18T20:29:00+00:00",
        "season_stats": {
          "season_key": "20252026",
          "games_played": 54,
          "goals_against_average": 2.77,
          "save_percentage": 0.912
        }
      }
    }
  ],
  "meta": {
    "date": "2026-09-19",
    "count": 2,
    "generated_at": "2026-09-18T20:30:00+00:00"
  }
}
```

`games[].away_goalie` and `games[].home_goalie` contain the complete
corresponding object from `starting_goalies[]`. Either goalie may be `null`
when no observation is available for that side.

### Goalie Fields

| Field | Type | Meaning |
| --- | --- | --- |
| `nhl_game_id` | integer|null | Canonical NHL game identifier when the observation resolved to a scheduled game. |
| `game_date` | string | NHL schedule date in `YYYY-MM-DD` format. |
| `start_time_utc` | string|null | Canonical game start as an ISO 8601 UTC timestamp. |
| `team_abbrev` | string | NHL team abbreviation represented by this goalie row. |
| `opponent_abbrev` | string|null | Opposing NHL team abbreviation. |
| `is_home` | boolean|null | Whether this goalie belongs to the scheduled home team. |
| `nhl_player_id` | integer|null | Canonical NHL player identifier; null when player identity is unresolved. |
| `player_name` | string | Provider-reported goalie name retained even when identity resolution fails. |
| `status` | string | Current observation status: `confirmed`, `expected`, or `unknown`. |
| `provider` | string | Source provider for the observation. |
| `observed_at` | string|null | ISO 8601 timestamp when DynastyIQ fetched the observation. |
| `season_stats` | object|null | Regular-season goalie baseline selected for the game context; null when qualifying summaries are unavailable. |

`season_stats` contains `season_key`, `games_played`,
`goals_against_average`, and `save_percentage`. Preseason games use the prior
season's regular-season results. Regular-season and playoff games use the
game's current season regular-season results.

### Consumption Guidance

- Use `games[]` when processing a scheduled matchup or preparing a prediction.
- Use `starting_goalies[]` when performing team-oriented or row-oriented upserts.
- Resolve goalies by `nhl_player_id`; do not use `player_name` as a durable identity key.
- A resolved goalie may currently be assigned outside the NHL, particularly during preseason; the canonical `nhl_player_id` remains authoritative.
- Treat `expected` as projected evidence and `confirmed` as stronger third-party evidence.
- Treat a null goalie or null `nhl_player_id` as unresolved rather than guessing an identity.
- Store and compare `observed_at` so older observations do not overwrite newer state.
- All timestamps are ISO 8601. `start_time_utc` is canonical UTC; timezone localization is presentation-only.
- An empty response for a date means DynastyIQ has no imported goalie observations for that date. It does not prove that no NHL games are scheduled.

Example game-specific request before generating a prediction:

```http
GET /api/nhl-starting-goalies?nhl_game_id=2026020001
Accept: application/json
Authorization: Bearer <DYNASTYIQ_API_TOKEN>
```

## NHL Anticipated Lineups Endpoint

Returns the latest source-consensus lineup for each team. With no parameters it defaults to today. Supply either `date=YYYY-MM-DD` or `nhl_game_id`, never both.

```http
GET /api/nhl-anticipated-lineups?nhl_game_id=2026020001
Accept: application/json
Authorization: Bearer <DYNASTYIQ_API_TOKEN>
```

```json
{
  "anticipated_lineups": [
    {
      "nhl_game_id": 2026020001,
      "team_id": 10,
      "team_abbrev": "TOR",
      "evidence_status": "corroborated",
      "source_count": 2,
      "first_observed_at": "2026-09-19T13:10:00+00:00",
      "last_observed_at": "2026-09-19T13:22:00+00:00",
      "players": [
        {
          "player_id": 123,
          "nhl_player_id": 8470001,
          "player_name": "Example Player",
          "lineup_role": "forward",
          "line_key": "F1",
          "slot_index": 1,
          "power_play_unit": 1,
          "penalty_kill_unit": null,
          "resolution_status": "resolved"
        }
      ],
      "sources": [
        {
          "source_id": 4,
          "name": "Example Reporter",
          "handle": "example",
          "platform": "x",
          "post_url": "https://x.com/example/status/1",
          "published_at": "2026-09-19T13:05:00+00:00",
          "observed_at": "2026-09-19T13:10:00+00:00",
          "engagement": { "likes": 25, "replies": 2, "reposts": 4, "views": 3200 }
        }
      ]
    }
  ],
  "meta": {
    "date": "2026-09-19",
    "nhl_game_id": 2026020001,
    "count": 2,
    "generated_at": "2026-09-19T13:25:00+00:00"
  }
}
```

`team_id` is the canonical `nhl_teams.nhl_id`. Use `nhl_game_id + team_id` as the current-lineup upsert key and `nhl_player_id` as the durable player identity. `official` means the NHL gamecenter boxscore supplied a complete game roster, `reported` means one public source, `corroborated` means two distinct public sources, and `strongly_corroborated` means at least three. Unresolved names remain in the payload and must not be silently substituted.

Before searching X, DynastyIQ checks `GET https://api-web.nhle.com/v1/gamecenter/{game-id}/boxscore` for each queued team. A complete official roster of twelve forwards and six defensemen is persisted as `official` and suppresses X discovery for that game/team. A goalie carrying the NHL `starter: true` flag is recorded as confirmed starting-goalie evidence. The X fallback runs only when that official roster is absent or incomplete.

For preseason games, newly evaluated X lineup posts must identify game context:
“tonight” or a matching explicit game date and opponent somewhere in the full
caption or extracted text, before or after the roster. Game-specific morning-skate
reports qualify; generic practice groups do not. Manual submissions and official
NHL rosters are exempt; regular-season and playoff discovery are unchanged.
This discovery filter does not retroactively rewrite existing observations.

The prediction endpoint accepts an `official`, `reported`, `corroborated`, or `strongly_corroborated` lineup when all eighteen skaters resolve uniquely. Otherwise DynastyIQ uses its existing projected-roster fallback. Explicitly reported PP/PK units are returned as `power_play_unit` and `penalty_kill_unit`; null means the source did not report the unit.

## NHL Game Predictions Endpoint

This endpoint returns a compact projected matchup prediction for one scheduled
NHL game.

```http
GET /api/nhl-game-predictions?nhl_game_id=2026020001
Accept: application/json
Authorization: Bearer <DYNASTYIQ_API_TOKEN>
```

Required scope:

```text
nhl-stats:read
```

Every successful prediction payload includes `prediction_available`. Consumers
must check this field before reading prediction or market output.

During preseason (`game_type: 1`), DynastyIQ publishes a prediction only when
both teams have a complete official NHL roster or a complete reported lineup
whose 18 skaters resolve canonically. A missing lineup does not turn the request
into an API error. Before withholding the prediction, DynastyIQ checks the live
NHL gamecenter boxscore for every missing team, persists any complete official
roster, and reevaluates both sides. If either side remains unresolved, the
endpoint returns HTTP `200` with
`prediction_available: false`, `reason: preseason_lineup_unresolved`, and the
affected abbreviations in `missing_lineups`.

That evidence-only response preserves any available reported lineup and goalie
evidence. For each missing side, `teams.*.roster` contains the ordinary fallback
roster only as a preview and `teams.*.lineup_source` is `projected_roster`.
`prediction` is null and `market_probabilities` and `reasons` are empty, so a
consumer must not publish a predicted score, probability, bet, or value index.
Regular-season roster fallback behavior is unchanged.

```json
{
  "prediction_available": false,
  "reason": "preseason_lineup_unresolved",
  "missing_lineups": ["NYI"],
  "game": {
    "nhl_game_id": 2026010001,
    "game_type": 1,
    "away_team_abbrev": "NYI",
    "home_team_abbrev": "NJD"
  },
  "inputs": {
    "away_lineup_source": "projected_roster",
    "home_lineup_source": "anticipated_lineup"
  },
  "prediction": null,
  "market_probabilities": [],
  "goalies": {
    "away": null,
    "home": { "nhl_player_id": 8476433 }
  },
  "anticipated_lineups": {
    "away": null,
    "home": { "players": [] }
  },
  "teams": {
    "away": {
      "team_abbrev": "NYI",
      "lineup_source": "projected_roster",
      "roster": []
    },
    "home": {
      "team_abbrev": "NJD",
      "lineup_source": "anticipated_lineup",
      "roster": []
    }
  },
  "reasons": []
}
```

### Query Parameters

| Parameter | Required | Meaning |
| --- | --- | --- |
| `nhl_game_id` | Yes | NHL game id from DynastyIQ `nhl_games`. |
| `source_season_id` | No | Override source season for projections. |
| `target_season_id` | No | Override target projection season. |
| `projection_version` | No | Override skater projection version. |
| `toi_projection_version` | No | Override skater TOI projection version. |
| `goalie_projection_version` | No | Override goalie performance projection version. |
| `away_goalie_id` | No | Override away starting goalie NHL player id. |
| `home_goalie_id` | No | Override home starting goalie NHL player id. |
| `markets[]` | No | Optional market filter. Supported values are `moneyline`, `puckline`, and `total`. |
| `puckline` | No | Single puckline spread to price. Must be nonzero. DynastyIQ normalizes sign from the projected favorite. |
| `puckline_spreads[]` | No | Multiple puckline spreads to price. Each value must be nonzero. |
| `total` | No | Single full-game total line to price. Must be greater than `0`. |
| `total_lines[]` | No | Multiple full-game total lines to price. Each value must be greater than `0`. |

If goalie ids are omitted, DynastyIQ derives starters from projected goalie
starts. If either starter cannot be resolved to a usable goalie performance
projection, the endpoint returns a validation error and does not emit a
prediction.

If no market parameters are provided, DynastyIQ emits full-game moneyline rows
only. If `puckline`, `puckline_spreads[]`, `total`, or `total_lines[]` are
provided without `markets[]`, moneyline remains included and the requested
line-based markets are added. If `markets[]` is provided, only those requested
markets are emitted.

Default line values are used only when the market is requested without an
explicit line:

| Market | Default |
| --- | --- |
| `puckline` | `1.5` goals |
| `total` | `6.0` goals |

### Headline Payload

The `prediction` block contains the display headline:

| Field | Meaning |
| --- | --- |
| `predicted_score.away` | Away projected goals per game. |
| `predicted_score.home` | Home projected goals per game. |
| `winner` | Higher projected-goal side. |
| `goal_differential` | Home projected goals minus away projected goals. |
| `confidence_score` | `1` to `100`, combining goal margin and projection confidence. |
| `goalie_edge` | Selected-starter goalie edge in goals saved per game. |

`goalie_edge.score` scale:

| Range | Meaning |
| --- | --- |
| `-0.05` to `+0.05` | Essentially even. |
| `-0.40` to `+0.40` | Normal practical range. |
| Positive | Home goalie edge. |
| Negative | Away goalie edge. |

The response also includes `goalies.away` and `goalies.home` with the selected
starters and their projected stats, including projected xGA/G, GA/G, and GSAx/G.

`teams.away.dressed_roster[]` and `teams.home.dressed_roster[]` add the listed
goalies to each team's skater roster. `teams.*.roster[]` remains skater-only for
backward compatibility. Listed goalies have `lineup_role: goalie`, `line_key: G`,
`slot_index: 1` (G1) or `2` (G2), `player_name`, `nhl_player_id`, `position: G`,
and `is_starter`. Both listed goalies are included even if G2 has no performance
projection. Only the selected starter affects goalie simulation; G2 is not a
second starter. Unknown identities remain null. Without listed goalies, the
selected starter is included when available, but no backup is invented.
Evidence-only responses also return this field; projected roster previews remain
previews, not confirmed dressed participants.

For started-game **prediction requests**, the designated NHL boxscore starter takes
precedence over all pregame choices, including manual and request overrides.
The read uses the shared 60-second gamecenter cache, so provider changes may take
up to that cache interval to appear. A matching game/team, nonempty state other
than FUT/PRE, and a unique goalie with `starter: true` are required. Relief-goalie
ice time and array order do not establish who started. The response identifies
`goalies.*.selection_source: nhl_boxscore`, updates `inputs.*_goalie_id`, and aligns
the dressed roster's G1 with the simulation goalie. No stored manual evidence is
rewritten. If a provider read fails or identifies no starter, stored official
starter data and then existing fallbacks apply. An official starter missing a
usable goalie projection still produces a prerequisite error, not a substitute.

Otherwise, starter selection precedence is request override, latest authoritative manual starter selection, official NHL starter, current
starting-goalie observation, goalie season projection, then workload projection.
Within observations, confirmed evidence outranks expected evidence, with newest
evidence breaking ties.

An explicit super-admin goalie-picker selection takes precedence immediately
after a request override, ahead of provider starter evidence. It is returned with
`selection_source: manual_starter_override` (and `provider: manual` in availability
responses). It applies only to the selected game/team, persists until replaced by
another accepted manual goalie decision, and does not change live NHL-owned presentation.
Validated UI and partner API lineup G1 submissions have the same manual authority;
the latest accepted decision wins, with record ID breaking timestamp ties.
If the chosen player lacks an NHL ID or usable goalie model, predictions report
the missing prerequisite rather than silently selecting another goalie.
The prediction dressed roster uses this starter as G1; selecting the listed G2
swaps the two goalie slots. Other changes preserve the listed backup where possible.

The picker uses session-authenticated, super-admin-only browser endpoints (not
partner API endpoints): `GET /games/{nhlGameId}/goalies?team_abbrev=TOR` lists
`player_id`, nullable `nhl_player_id`, name, avatar, and league;
`POST /games/{nhlGameId}/starting-goalie` accepts `team_abbrev` and the canonical
local `player_id`, requires CSRF protection, and returns the updated
`starting_goalie` plus game/team identity. It includes prospects regardless of
league assignment and preserves an attributed, append-only selection history.

A validated manual lineup's G1 is authoritative for that specific game/team,
including split-squad games, even when its evidence status is `expected`.
It outranks provider evidence; explicit prediction-request goalie overrides retain precedence.
Superseded or wrong-date lineup-derived observations cannot select a starter.
Live game presentation remains sourced from the NHL boxscore.

When a complete official or reported lineup is used, `teams.away.roster[]` and
`teams.home.roster[]` contain all 18 skaters actually used. Each row includes
the reported line and PP/PK units, `game_projected_toi_seconds`, a readable
`game_projected_toi`, `projection_source`, `confidence`, `nhl_games_played`,
and nullable `nhle_factor`. Players below 25 career NHL regular-season games
use their previous-season non-NHL production when available. G, A, and SOG are
translated with the versioned league factor, SOG is marked low confidence, and
all rates are scaled to that game's lineup-aware TOI. These values are
read-time inputs and do not overwrite raw statistics or season projections.
If neither a projection nor translatable history exists, the player remains in
the roster with `projection_source: replacement_level` and low confidence.

### Roster and Goalie Resolution Order

DynastyIQ resolves each team independently. Consumers must inspect both
`inputs.away_lineup_source` and `inputs.home_lineup_source`; the two teams in
one game may use different source levels.

Skater roster precedence:

0. A validated super-admin manual override, when present (`anticipated_lineups.*.manual_override = true`). This is authoritative for prediction roster selection, retains manual source attribution, and is not overwritten by automated imports.
1. A complete official NHL boxscore roster.
2. A complete reported anticipated lineup whose 18 skaters resolve to 18
   unique NHL player ids.
3. The existing projected-roster process after excluding current injuries.

A complete official or reported game roster is newer, game-specific evidence
than the injury table. A player explicitly present in that roster is retained
even if the current injury table still lists the player as unavailable. An
accepted reported roster must come from one post containing twelve forwards
followed by six defensemen. Separate forward/defense posts are not combined.
Headings may separate the groups; goalies and scratches are supplemental.
Grouped posts use the first four forward lines and first three defense pairs;
extra lines, extra pairs, and standalone extras are ignored rather than used to
fill missing required slots. The selected eighteen still require validation.
Player identities and slots are verified after the complete roster block is
identified or covered by the documented game-type-specific peer fallback. Historical combined-post
lineups no longer qualify as reported or suppress further discovery.

| `inputs.*_lineup_source` | Meaning |
| --- | --- |
| `nhl_boxscore` | A complete official NHL game roster supplied the skaters. |
| `anticipated_lineup` | A complete, canonically resolved reported lineup supplied the skaters. |
| `projected_roster` | No usable game-specific lineup existed, so DynastyIQ used its roster projection and injury exclusions. |

Goalie precedence:

1. `away_goalie_id` or `home_goalie_id` request override.
2. Explicit super-admin goalie-picker selection for this game/team.
3. Official NHL starter evidence.
4. Imported starting-goalie observation, preferring `confirmed` over
   `expected`, then preferring the newest observation within that status.
5. Highest-start goalie from the selected goalie season projection.
6. Highest-start goalie from the workload projection.

Read `goalies.away.selection_source` and `goalies.home.selection_source` rather
than inferring how the goalie was selected. Current values are `provided`,
`nhl_boxscore`, `starting_goalie_observation`, `goalie_projection`, and
`workload_projection`.

### Game-Specific Lineup TOI and Production Inputs

The public `/games/{nhlGameId}` detail page also offers per-team Lineup and
Prediction tabs. An unreported team receives a clearly labeled, injury-filtered
projected-roster preview. Its player projections can be viewed even when a full
game prediction is withheld. These browser previews are not reported evidence
and do not change this API's `prediction_available` rules. SAT, SOG and G are
shown per 60 and per game; unavailable inputs render as dashes.

For complete official or reported rosters, DynastyIQ selects the newest created
completed SAT model with successfully completed Build /60 outputs, a run-scoped
goal model, and usable skater rate rows. Model TOI is optional; missing TOI does
not discard usable SAT rates. A newer unfinished model does not displace a usable one. Selection is
automatic; no additional API parameter is required. The SAT model's stored
`target_season_id` is its evaluation/test season and does not have to match the
game's season. Request-season filtering still applies to legacy season projections.

`inputs.sat_model_run_id` identifies the selected model, or is null when none
qualifies. Selection does not mean every skater used that model: inspect each
roster row's `projection_source` and `model_run_id` for actual application.
Players without usable rate outputs keep their production fallback. Projected
rosters without an official or reported lineup retain the existing simulator
path. Legacy simulator and goalie projection prerequisites still apply.

For a model-backed skater, each bucket contributes:

- Attempts: `SAT/60 × game TOI seconds / 3600`.
- SOG: attempts multiplied by the bucket's trained attempt-to-SOG probability.
- Goals: expected SOG multiplied by its trained SOG-to-goal probability.

The contributions are summed across buckets. Model-supplied rates and TOI come
from the same model and player; unavailable model TOI uses the ladder below.
Diagnostic `projected_xsog_per_60` and
`projected_xg_per_60` storage columns are not used as prediction inputs.
For all game types, TOI uses the selected model's projected TOI/GP first, then
the player's previous NHL regular-season average (total TOI divided by GP), then
the average of available same-line teammates (the other two forwards or defensive
partner). Only model or historical teammate TOI can supply that average, avoiding
circular estimates. If no anchor exists, estimates are F1 20, F2 16.5, F3 13.5,
F4 8.5, D1 24, D2 20, D3 16 minutes. Positive TOI is required at each step.
This also applies without a usable SAT model and replaces the preseason override.
Fallback production is rescaled to the chosen TOI; rookie NHLe and depth-peer
production rules remain intact. Goalie adjustments are unchanged.

When `inputs.*_lineup_source` is `nhl_boxscore` or `anticipated_lineup`,
DynastyIQ builds a game-specific input for every resolved skater. For players
using the existing fallback rather than the SAT model:

- The reported forward or defense pair determines the player's base game role.
- Explicit PP1, PP2, PK1, and PK2 evidence adjusts that role. Null special-team
  values mean the source did not report the unit and are not inferred.
- Final game TOI follows the model, previous-season, linemate, then line-estimate
  ladder above, rather than blending line estimates into an available model value.
- G, A, and SOG rates are scaled to the resulting game TOI.
- A player with fewer than 25 career NHL regular-season games uses their prior
  season non-NHL production and the versioned NHLe league factor when those
  records are available.
- The NHLe points factor is applied to G and A. It is provisionally applied to
  SOG as well, with low confidence because the source model does not publish a
  separate SOG translation factor.
- If neither a usable NHL projection nor translatable non-NHL history exists,
  DynastyIQ retains the player with an explicit low-confidence
  `replacement_level` rate. Resolved lineup players are never silently dropped.
- In preseason, an unresolved player is permitted on any forward line or defense
  pair when at least one teammate in that exact group resolves canonically.
  Regular-season and playoff exceptions remain limited to `F4`/`D3`. The unresolved row
  retains its reported name and null NHL id, uses `line_peer_average`, and
  receives the average projected TOI, goals, assists, SOG, and available SAT and
  per-sixty rates of its resolved peers. A line or pairing with no resolved peer
  remains prediction-ineligible. Unknown players never serve as peers for each other.

Official NHL authority changes the roster evidence source, not the player-rate
fallback ladder. Official-boxscore rookies therefore receive the same NHLe or
replacement-level handling as rookies from reported anticipated lineups. When
only official boxscore roster IDs are available and no ordered official lineup
observation exists, DynastyIQ assigns provisional line keys by position and
projected TOI solely to calculate game-specific usage.

These calculations are request-time prediction inputs. They do not replace raw
statistics, historical records, or stored season projections.

### Expanded Team Roster Fields

`teams.away.roster[]` and `teams.home.roster[]` describe the skaters actually
used by the prediction. When the lineup source is `nhl_boxscore` or
`anticipated_lineup`, each row has the expanded shape below.

| Field | Type | Meaning |
| --- | --- | --- |
| `player_id` | integer/null | Internal DynastyIQ player id. |
| `nhl_player_id` | integer/null | Canonical NHL player id; null for an eligible peer-average row (any preseason line/pair, otherwise F4/D3 only). |
| `player_name` | string | Display name captured with the reported lineup. |
| `position` | string | Normalized lineup group: `forward` or `defense`. |
| `line_key` | string | Reported line or pair: `F1`-`F4` or `D1`-`D3`. |
| `slot_index` | integer | Position within the reported line or pair. |
| `power_play_unit` | integer/null | Explicitly reported PP unit, `1` or `2`; null means unreported. |
| `penalty_kill_unit` | integer/null | Explicitly reported PK unit, `1` or `2`; null means unreported. |
| `nhl_games_played` | integer | Career NHL regular-season GP used for the 25-game experience threshold. |
| `projection_source` | string | Player-rate source: `sat_model`, `nhl_projection`, `nhle_non_nhl_history`, `replacement_level`, or `line_peer_average`. |
| `model_run_id` | integer | Applied SAT model id; present on model-backed rows. |
| `model_projected_toi_per_game_seconds` | number/null | Selected model's TOI/GP; null when unavailable. |
| `toi_source` | string | Chosen TOI source: `sat_model`, `previous_season`, `linemate_average`, or `line_estimate`. |
| `projected_sat_per_60` | number | Sum of model bucket SAT/60; model-backed rows only. |
| `projected_sog_per_60` | number | Bucket SAT/60 weighted by attempt-to-SOG probability; model-backed rows only. |
| `projected_goals_per_60` | number | Bucket SAT/60 weighted by attempt-to-SOG and SOG-to-goal probabilities; model-backed rows only. |
| `projected_sat` | number | Expected game shot attempts at the applied game TOI; model-backed rows only. |
| `nhle_factor` | number/null | Applied versioned league factor; null when NHLe was not used. |
| `confidence` | string/null | Human-readable projection confidence bucket. NHLe and replacement rows are `low`. |
| `confidence_score` | number | Numeric `0`-`1` confidence input used by prediction confidence weighting. |
| `baseline_toi_seconds` | number | Existing season TOI projection before applying tonight's role. |
| `game_projected_toi_seconds` | integer | Game-specific projected TOI used to scale the player's rates. |
| `game_projected_toi` | string | Display form of game TOI in `M:SS` format. |
| `projected_goals` | number | Game-specific projected goals after role and TOI scaling. |
| `adjusted_xgf_per_game` | number | Goal input consumed by the matchup confidence calculation; currently matches `projected_goals` for expanded lineup rows. |
| `projected_assists` | number | Game-specific projected assists after role and TOI scaling. |
| `projected_sog` | number | Game-specific projected shots on goal after role and TOI scaling. |

Projected-roster rows continue to use the established simulator roster shape
and may not contain the lineup-specific, NHLe, or game-TOI fields above.
Consumers must branch on `inputs.*_lineup_source` and treat absent optional
fields as unavailable, not as zero.

### Expanded Prediction Example

The following excerpt focuses on the new lineup-aware fields. Other response
blocks, including `market_probabilities` and `reasons`, remain unchanged.

```json
{
  "inputs": {
    "source_season_id": "20252026",
    "target_season_id": "20262027",
    "projection_version": "skater-2026-09-20",
    "toi_projection_version": "toi-2026-09-20",
    "goalie_projection_version": "goalie-2026-09-20",
    "away_goalie_id": 8478406,
    "home_goalie_id": 8478492,
    "away_lineup_source": "anticipated_lineup",
    "home_lineup_source": "projected_roster"
  },
  "goalies": {
    "away": {
      "nhl_player_id": 8478406,
      "name": "Example Away Goalie",
      "team_abbrev": "AWY",
      "selection_source": "starting_goalie_observation"
    },
    "home": {
      "nhl_player_id": 8478492,
      "name": "Example Home Goalie",
      "team_abbrev": "HOM",
      "selection_source": "goalie_projection"
    }
  },
  "anticipated_lineups": {
    "away": {
      "nhl_game_id": 2026020001,
      "team_abbrev": "AWY",
      "evidence_status": "reported",
      "source_count": 1,
      "players": [
        {
          "nhl_player_id": 8480001,
          "player_name": "Example Rookie",
          "lineup_role": "forward",
          "line_key": "F3",
          "slot_index": 2,
          "power_play_unit": null,
          "penalty_kill_unit": 2,
          "resolution_status": "resolved"
        }
      ]
    },
    "home": null
  },
  "teams": {
    "away": {
      "team_abbrev": "AWY",
      "opponent_team_abbrev": "HOM",
      "summary": {
        "adjusted_xsog_per_game": 27.84,
        "total_goalie_adjusted_xgf_per_game": 2.7134
      },
      "roster": [
        {
          "player_id": 1234,
          "nhl_player_id": 8480001,
          "player_name": "Example Rookie",
          "position": "forward",
          "line_key": "F3",
          "slot_index": 2,
          "power_play_unit": null,
          "penalty_kill_unit": 2,
          "nhl_games_played": 8,
          "projection_source": "nhle_non_nhl_history",
          "nhle_factor": 0.45,
          "confidence": "low",
          "confidence_score": 0.25,
          "baseline_toi_seconds": 1020,
          "game_projected_toi_seconds": 884,
          "game_projected_toi": "14:44",
          "projected_goals": 0.1172,
          "adjusted_xgf_per_game": 0.1172,
          "projected_assists": 0.1847,
          "projected_sog": 1.843
        }
      ]
    },
    "home": {
      "team_abbrev": "HOM",
      "opponent_team_abbrev": "AWY",
      "summary": {
        "adjusted_xsog_per_game": 31.18,
        "total_goalie_adjusted_xgf_per_game": 3.0261
      },
      "roster": [
        {
          "player_id": 8479001,
          "player_name": "Projected Veteran",
          "position": "C",
          "adjusted_xgf_per_game": 0.2141,
          "confidence_score": 0.82
        }
      ]
    }
  }
}
```

`anticipated_lineups.*.players` is the source-evidence view: it explains what
was reported, by whom, and with which lineup slots. `teams.*.roster` is the
prediction-input view: it identifies the skaters and rates actually used by the
model. Consumers should use the latter for prediction audit and player-level event
inputs, while retaining the former for evidence provenance.

### Lineup-Aware Consumption Guidance

- Lineup evidence may have `sources[].platform: manual`: a super admin submitted it in DynastyIQ or an explicitly authorized partner submitted it through `POST /api/nhl-lineups`. Both use the same validator, retain server-owned user/API-client attribution, and are temporarily equally authoritative manual overrides—not X posts or official NHL evidence.
- X posts saying “tonight” or “today” apply to their publication date; “tomorrow” applies to the next day, using America/Toronto calendar dates. They must match the target game date, including when reading older stored observations. Yesterday's explicitly dated report for today's opponent remains eligible. Relative wording without a publication timestamp is rejected; manual and official evidence are exempt.
- A `reported` lineup has valid slots and verified identities or eligible peer fallbacks, not merely eighteen parsed names. Duplicate identities and goalies in skater slots remain invalid. Unknown F1–F3/D1–D2 players are eligible only in preseason with a verified same-group peer. Invalid legacy lineups are omitted too.
- Eligible unidentified players remain `resolution_status: unresolved` with null identity fields. They do not block reporting or predictions when a verified player in that same line/pair supplies the peer-average fallback; they are never presented as identified players.
- A shared slot such as `Lemire/Kumpulainen` retains both names in `player_name` with null identity and `resolution_status: unresolved`, even when both alternatives can be matched individually. It represents one slot, not two selected players, and follows the same season-specific peer-average eligibility. Consumers must not select an alternative by name.
- Persist `nhl_player_id`; do not join prediction players by name.
- Record `inputs.*_lineup_source` with every imported prediction snapshot.
- Use `teams.*.roster` to audit which players contributed to that prediction.
- Treat `anticipated_lineups` as evidence and provenance, not as a second set
  of calculated player projections.
- Do not reject a lineup solely because `evidence_status` is `reported`; one
  complete source is accepted by DynastyIQ.
- Treat null PP/PK units as unknown, not as evidence that the player is off the
  unit.
- Treat `nhle_non_nhl_history` and `replacement_level` as lower-confidence
  inputs, but retain those players in event construction.
- Do not convert missing optional fields to zero when the lineup source is
  `nhl_boxscore` or `projected_roster`.
- Use the prediction and market rows returned in the same response. Do not
  combine a newer lineup payload with an older prediction snapshot.

Projected goalie xGA/G is the selected goalie's projected season xGA divided by
projected games. It is not current-season GAA, historical on-ice xGA, or a
single-game observed value.

### Market Probabilities

The `market_probabilities[]` block contains model-owned betting market
probabilities. Each row is one market selection for one full-game market.
Supported market keys are `moneyline`, `puckline`, and `total`.

Consumers should read market probabilities from:

```text
market_probabilities[]
  and period_key = full_game
```

Selection keys:

| Market | Selection keys |
| --- | --- |
| `moneyline` | `away`, `home` |
| `puckline` | `away`, `home` |
| `total` | `over`, `under` |

Common row shape:

| Field | Meaning |
| --- | --- |
| `market_key` | `moneyline`, `puckline`, or `total`. |
| `period_key` | `full_game`. |
| `selection_key` | Market selection: `away`, `home`, `over`, or `under`. |
| `team_abbrev` | Team represented by the selection. Present for `moneyline` and `puckline`; omitted for `total`. |
| `line` | Priced line. Present for `puckline` and `total`; omitted for `moneyline`. |
| `line_unit` | `goals`. Present for `puckline` and `total`; omitted for `moneyline`. |
| `probability` | DynastyIQ fair win probability from `0` to `1`. Same as `win_probability`. |
| `win_probability` | Probability the selection wins. |
| `push_probability` | Probability the selection pushes. Always `0` for moneyline. Usually `0` for half-goal lines. |
| `loss_probability` | Probability the selection loses. |
| `fair_odds_american` | American fair odds derived from probability. |
| `fair_odds_decimal` | Decimal fair odds derived from probability. |
| `confidence_score` | Prediction confidence from `1` to `100`. |
| `model.method` | Probability method. See method values below. |
| `model.source` | Source input path, currently `prediction.predicted_score`. |
| `model.includes_overtime` | Whether the probability includes overtime resolution in the score distribution. |
| `model.max_score` | Highest score included in the finite Poisson score grid. |

Method values:

| Market | `model.method` |
| --- | --- |
| `moneyline` | `poisson_projected_score_moneyline` |
| `puckline` | `projected_margin_distribution` |
| `total` | `projected_total_distribution` |

Moneyline rows resolve tied score states into away/home winners according to
projected goal share. Line markets evaluate the projected score distribution
against the requested spread or total; whole-number lines can produce nonzero
`push_probability`.

### Market Request Examples

Default moneyline request:

```http
GET /api/nhl-game-predictions?nhl_game_id=2026020001
```

Moneyline plus one puckline:

```http
GET /api/nhl-game-predictions?nhl_game_id=2026020001&puckline=1.5
```

Only puckline and total rows, using default lines:

```http
GET /api/nhl-game-predictions?nhl_game_id=2026020001&markets[]=puckline&markets[]=total
```

Multiple pucklines and totals:

```text
GET /api/nhl-game-predictions
  ?nhl_game_id=2026020001
  &markets[]=moneyline
  &markets[]=puckline
  &markets[]=total
  &puckline_spreads[]=1.5
  &puckline_spreads[]=2.5
  &total_lines[]=5.5
  &total_lines[]=6.0
```

Puckline signs are assigned from the projected favorite. The projected favorite
receives the negative spread and the projected underdog receives the positive
spread, regardless of whether the request used a positive or negative input
value.

Example:

```json
{
  "market_probabilities": [
    {
      "market_key": "moneyline",
      "period_key": "full_game",
      "selection_key": "away",
      "team_abbrev": "FLA",
      "probability": 0.4218,
      "win_probability": 0.4218,
      "push_probability": 0.0,
      "loss_probability": 0.5782,
      "fair_odds_american": 137,
      "fair_odds_decimal": 2.371,
      "confidence_score": 62,
      "model": {
        "method": "poisson_projected_score_moneyline",
        "source": "prediction.predicted_score",
        "includes_overtime": true,
        "tie_resolution": "projected_goal_share",
        "max_score": 15
      }
    },
    {
      "market_key": "puckline",
      "period_key": "full_game",
      "selection_key": "away",
      "line": 1.5,
      "line_unit": "goals",
      "probability": 0.6421,
      "win_probability": 0.6421,
      "push_probability": 0.0,
      "loss_probability": 0.3579,
      "fair_odds_american": -179,
      "fair_odds_decimal": 1.557,
      "confidence_score": 62,
      "model": {
        "method": "projected_margin_distribution",
        "source": "prediction.predicted_score",
        "includes_overtime": true,
        "max_score": 15
      },
      "team_abbrev": "FLA"
    },
    {
      "market_key": "total",
      "period_key": "full_game",
      "selection_key": "over",
      "line": 6.0,
      "line_unit": "goals",
      "probability": 0.3854,
      "win_probability": 0.3854,
      "push_probability": 0.1602,
      "loss_probability": 0.4544,
      "fair_odds_american": 159,
      "fair_odds_decimal": 2.595,
      "confidence_score": 62,
      "model": {
        "method": "projected_total_distribution",
        "source": "prediction.predicted_score",
        "includes_overtime": true,
        "max_score": 15
      }
    }
  ]
}
```
