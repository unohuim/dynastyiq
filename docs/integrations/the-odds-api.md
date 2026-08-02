# The Odds API Usage Guide

This document describes how DynastyIQ should use The Odds API v4 for NHL game
odds and player props.

Source documentation:

- `https://the-odds-api.com/liveapi/guides/v4/#overview`
- `https://the-odds-api.com/sports-odds-data/betting-markets.html`

## Role In DynastyIQ

The Odds API should be treated as an external betting-market source. NHL API
game, team, player, play-by-play, boxscore, and schedule data remain the hockey
identity and statistics authority.

Use The Odds API for:

- Current game markets.
- Player prop markets.
- Bookmaker comparisons.
- Odds snapshots and line movement.
- Historical odds research when the plan supports historical access.

Do not use The Odds API for:

- NHL game identity by itself.
- NHL player identity by itself.
- Final stats, scores, play-by-play, or validation.
- Replacing DynastyIQ shot, expected-goal, faceoff, or on-ice models.

## Host And Auth

Base host:

```text
https://api.the-odds-api.com
```

Every request requires an API key as the `apiKey` query parameter:

```http
GET /v4/sports?apiKey=<THE_ODDS_API_KEY>
```

DynastyIQ should store the API key only in environment/configuration, never in
code, docs examples, logs, or persisted raw payloads.

## Quota Headers

Responses include quota headers:

| Header | Meaning |
| --- | --- |
| `x-requests-remaining` | Usage credits remaining until quota reset. |
| `x-requests-used` | Usage credits used since the last quota reset. |
| `x-requests-last` | Usage cost of the most recent request. |

Persisting these values with an import run or request log is useful for
operational monitoring and cost tuning.

## NHL Sport Key

The NHL sport key is:

```text
icehockey_nhl
```

Discover available sports with:

```http
GET /v4/sports?apiKey=<THE_ODDS_API_KEY>
```

Use this only to verify provider availability. DynastyIQ should not depend on
provider sport labels for internal league identity.

## Request Parameters

Common odds parameters:

| Parameter | Example | Meaning |
| --- | --- | --- |
| `regions` | `us` | Bookmaker region. Multiple regions increase request cost. |
| `bookmakers` | `draftkings,fanduel` | Optional direct bookmaker filter. Usually better than broad regions when we only need selected books. |
| `markets` | `h2h,spreads,totals` | Comma-separated market keys. More returned markets increase request cost. |
| `oddsFormat` | `american` | Use `american` for DynastyIQ betting UI. |
| `dateFormat` | `iso` | Use ISO timestamps for storage. |

Use `bookmakers` when DynastyIQ needs specific books. Use `regions` when
DynastyIQ needs broad market discovery.

## Game Odds Flow

### Discover Upcoming NHL Events

```http
GET /v4/sports/icehockey_nhl/events?apiKey=<THE_ODDS_API_KEY>&dateFormat=iso
```

Expected event fields include provider event id, commence time, home team, and
away team.

DynastyIQ matching strategy:

- Match to `nhl_games` by game date, home team, away team, and start time.
- Store the provider event id once matched.
- Keep a manual review path for unmatched events, neutral-site games, postponed
games, and provider naming differences.

### Pull Featured Game Markets

```http
GET /v4/sports/icehockey_nhl/odds?apiKey=<THE_ODDS_API_KEY>&regions=us&markets=h2h,spreads,totals&oddsFormat=american&dateFormat=iso
```

Core game markets:

| Market | Meaning |
| --- | --- |
| `h2h` | Moneyline. |
| `spreads` | Puck line. |
| `totals` | Game total over/under. |
| `h2h_3_way` | 3-way moneyline including draw where available. |
| `team_totals` | Team total over/under where available. |

Ice-hockey period markets documented by The Odds API include:

| Market | Meaning |
| --- | --- |
| `h2h_p1` | First-period moneyline. |
| `h2h_p2` | Second-period moneyline. |
| `h2h_p3` | Third-period moneyline. |
| `h2h_3_way_p1` | First-period 3-way result. |
| `h2h_3_way_p2` | Second-period 3-way result. |
| `h2h_3_way_p3` | Third-period 3-way result. |

## Player Props Flow

Additional markets and props are pulled one event at a time.

### Discover Event Markets

```http
GET /v4/sports/icehockey_nhl/events/{eventId}/markets?apiKey=<THE_ODDS_API_KEY>
```

Use this before requesting props so DynastyIQ only asks for available markets and
does not waste quota.

### Pull Event Props

```http
GET /v4/sports/icehockey_nhl/events/{eventId}/odds?apiKey=<THE_ODDS_API_KEY>&regions=us&markets=player_shots_on_goal,player_goals,player_assists,player_points,player_blocked_shots,player_total_saves&oddsFormat=american&dateFormat=iso
```

Core NHL player props documented by The Odds API:

| Market | Meaning |
| --- | --- |
| `player_points` | Player points over/under. |
| `player_power_play_points` | Player power-play points over/under. |
| `player_assists` | Player assists over/under. |
| `player_blocked_shots` | Player blocked shots over/under. |
| `player_shots_on_goal` | Player shots on goal over/under. |
| `player_goals` | Player goals over/under. |
| `player_total_saves` | Goalie saves over/under. |
| `player_goal_scorer_first` | First goal scorer yes/no. |
| `player_goal_scorer_last` | Last goal scorer yes/no. |
| `player_goal_scorer_anytime` | Anytime goal scorer yes/no. |

Alternate NHL props documented by The Odds API:

| Market | Meaning |
| --- | --- |
| `player_points_alternate` | Alternate player points. |
| `player_assists_alternate` | Alternate player assists. |
| `player_power_play_points_alternate` | Alternate power-play points. |
| `player_goals_alternate` | Alternate player goals. |
| `player_shots_on_goal_alternate` | Alternate shots on goal. |
| `player_blocked_shots_alternate` | Alternate blocked shots. |
| `player_total_saves_alternate` | Alternate goalie saves. |

## Historical Odds

The v4 docs include historical odds endpoints for snapshots. Historical event
odds responses are wrapped with snapshot metadata including:

| Field | Meaning |
| --- | --- |
| `timestamp` | Snapshot timestamp returned by the provider. |
| `previous_timestamp` | Previous available snapshot timestamp. |
| `next_timestamp` | Next available snapshot timestamp. |
| `data` | Event odds payload for that snapshot. |

Use historical endpoints for backtests and model research. Do not mix historical
backfill writes into live current-odds tables without preserving the source
snapshot timestamp.

## Normalized Storage Recommendation

DynastyIQ should store odds as immutable snapshots. Do not overwrite old prices.

Recommended concepts:

| Concept | Purpose |
| --- | --- |
| Provider event map | Links The Odds API event ids to DynastyIQ `nhl_game_id`. |
| Bookmaker | Stores provider bookmaker key and display name. |
| Market | Stores canonical market key such as `h2h` or `player_shots_on_goal`. |
| Outcome | Stores side/selection, player name when applicable, line, and price. |
| Snapshot | Stores all odds observed at a provider timestamp or request timestamp. |
| Raw payload | Preserves provider response evidence for parser review. |

Minimum fields for a normalized outcome snapshot:

| Field | Meaning |
| --- | --- |
| `provider` | `the_odds_api`. |
| `provider_event_id` | The Odds API event id. |
| `nhl_game_id` | Matched DynastyIQ NHL game id when known. |
| `bookmaker_key` | Provider bookmaker key. |
| `market_key` | Provider market key. |
| `selection_name` | Team, `Over`, `Under`, `Yes`, `No`, or other outcome name. |
| `participant_name` | Player name for props when provider exposes it separately. |
| `line` | Spread, total, or prop threshold when present. |
| `price_american` | American odds when requested with `oddsFormat=american`. |
| `price_decimal` | Optional derived decimal odds. |
| `implied_probability` | Derived from price before vig removal. |
| `bookmaker_last_update` | Provider bookmaker or market update timestamp. |
| `captured_at` | DynastyIQ request capture timestamp. |
| `raw_payload` | Original provider fragment for audit. |

## Derived Values

American odds to implied probability:

| Odds | Formula |
| --- | --- |
| Positive | `100 / (odds + 100)` |
| Negative | `abs(odds) / (abs(odds) + 100)` |

Store raw implied probability first. Vig-free probability should be derived at
the market/bookmaker grouping layer after all related outcomes are available.

## Import Cadence

Suggested initial cadence:

| Window | Cadence |
| --- | --- |
| Future games more than 24 hours away | 2 to 4 times per day. |
| Game day before final 2 hours | Every 30 minutes. |
| Final 2 hours before puck drop | Every 5 to 10 minutes. |
| Live games | Defer until DynastyIQ intentionally supports live odds. |

Props should be requested closer to game time than game lines because player
availability and sportsbook prop coverage change frequently.

## Cost Controls

- Pull `/events` first, then only request odds for events DynastyIQ can use.
- Use specific `bookmakers` when the product needs a known book set.
- Use `regions=us` only when broad discovery is useful.
- Request props one event at a time.
- Call `/events/{eventId}/markets` before requesting many props.
- Store quota headers on each run.
- Skip duplicate snapshot writes when no market changed.

## DynastyIQ First Markets

Recommended phase-one markets:

| Group | Markets |
| --- | --- |
| Game lines | `h2h`, `spreads`, `totals` |
| Skater props | `player_shots_on_goal`, `player_points`, `player_goals`, `player_assists`, `player_blocked_shots` |
| Goalie props | `player_total_saves` |
| Scorer props | `player_goal_scorer_anytime` |

Add alternate props only after the base markets are matched reliably to
DynastyIQ players and games.

## Provider Caveats

- Market availability varies by sport, bookmaker, region, event, and time.
- Player prop names must be matched to DynastyIQ players with a reviewable
  resolver; never assume names alone are durable identity.
- Bookmakers can change lines without changing every market in a payload.
- Empty responses should be treated as provider availability, not necessarily an
  application error.
- Rate limiting returns HTTP 429; retry with spacing rather than immediate
  tight loops.
