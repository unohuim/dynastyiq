# gner8 NHL Season Stats API

This document describes how gner8 pulls NHL season stats from DynastyIQ.

## How To Pull All Stats

Call the season stats endpoint once per stat group and window. A full regular-season import is 12 requests:

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

If gner8 also pulls `/api/nhl-teams` or `/api/nhl-players`, the same token also needs:

```text
nhl-reference:read
```

## Query Parameters

| Parameter | Required | Example | Meaning |
| --- | --- | --- | --- |
| `season` | No | `20252026` | NHL season key. Defaults to DynastyIQ's current NHL season. |
| `game_type` | No | `2` | NHL game type. Use `2` for regular season. |
| `stat_group` | No | `basic` | Limits response to `basic`, `on_ice`, or `expected`. Required for full imports. |
| `window_key` | No | `season` | Limits response to `season`, `last_5`, `last_10`, or `last_20`. Required for full imports. |

The unfiltered endpoint remains available for diagnostics, but gner8 should use chunked pulls for season imports so DynastyIQ does not build one large JSON response.

## Payload Fields

Top-level payload:

| Field | Type | Meaning |
| --- | --- | --- |
| `league_abbrev` | string | Always `NHL`. gner8 maps this to its local `league_id`. |
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
| `supports_per_game` | boolean | Whether gner8 may derive per-game values. |
| `supports_per_60` | boolean | Whether gner8 may derive per-60 values. |
| `higher_is_better` | boolean|null | Sort/evaluation hint. |
| `active` | boolean | Whether the stat type is active. |
| `metadata` | object | Reserved extension field. |

`player_stats[]` object:

| Field | Type | Meaning |
| --- | --- | --- |
| `league_abbrev` | string | Always `NHL`. |
| `season_key` | string | Season key for this stat row. |
| `nhl_player_id` | integer | NHL player id. gner8 maps this to its local `players.id`. |
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
| `goalie_xga` | `expected` | `goals` | Expected goals against assigned to the goalie in net. |
| `goalie_xsoga` | `expected` | `shots` | Expected shots on goal against assigned to the goalie in net. |
| `goalie_xsaves` | `expected` | `saves` | Expected saves: `goalie_xsoga - goalie_xga`. |
| `goalie_gsax` | `expected` | `goals` | Goals saved above expected: `goalie_xga - actual goals against`. |
| `goalie_xsave_percentage` | `expected` | `percent` | Expected save percentage: `goalie_xsaves / goalie_xsoga`. |

## Ingestion Order

1. Resolve `league_abbrev` to gner8's local `leagues.id`.
2. Upsert `season` by `league_id + season_key`.
3. Upsert `stat_types` by `league_id + slug`.
4. Resolve each `player_stats[].nhl_player_id` to gner8's local `players.id`.
5. Resolve each `player_stats[].stat_slug` to gner8's local `nhl_stat_types.id`.
6. Upsert `player_stats`.
7. Resolve and upsert `player_stat_features`.

Rows with unknown `nhl_player_id` should be skipped or quarantined until gner8 imports the missing player reference row.

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

- DynastyIQ intentionally sends `league_abbrev`, not `league_id`. gner8 owns local `league_id` values.
- Expected stats require DynastyIQ xG and xSOG predictions for the requested season.
- On-ice expected stats require DynastyIQ event-to-shift links.
- A row is emitted only when the player has qualifying data for that stat/window.
- Goalie expected stats require shot-attempt facts with `goalie_player_id` and scored xG/xSOG predictions.
- Feature rows currently compare recent `last_10` expected-rate values against the season baseline.
