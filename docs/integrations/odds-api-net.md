# odds-api.net Usage Guide

This document describes how DynastyIQ should use odds-api.net for NHL game
odds, player props, line movement, and realtime odds monitoring.

Source documentation:

- `https://odds-api.net/docs`
- `https://odds-api.net/coverage`
- `https://odds-api.net/pricing`

## Role In DynastyIQ

odds-api.net should be treated as an external betting-market source. NHL API
game, team, player, play-by-play, boxscore, shift, and schedule data remain the
hockey identity and statistics authority.

Use odds-api.net for:

- Current game markets.
- Player prop markets.
- Bookmaker comparisons.
- Odds snapshots and line movement.
- Realtime odds changes through SSE or WebSocket feeds.
- No-vig or fair-price market analysis when available.

Do not use odds-api.net for:

- NHL game identity by itself.
- NHL player identity by itself.
- Final stats, scores, play-by-play, or validation.
- Replacing DynastyIQ shot, expected-goal, faceoff, or on-ice models.

## Host And Auth

Base host:

```text
https://api.odds-api.net/v1
```

Requests use an API key header:

```http
X-API-Key: <ODDS_API_NET_KEY>
```

DynastyIQ should store the API key only in environment/configuration, never in
code, docs examples, logs, or persisted raw payloads.

## Discovery Endpoints

Use discovery endpoints to confirm provider coverage before requesting event
odds.

| Endpoint | Purpose |
| --- | --- |
| `GET /sports` | List supported sports. |
| `GET /leagues` | List supported leagues. |
| `GET /bookmakers` | List supported bookmakers. |
| `GET /coverage` | Inspect available coverage by sport, league, bookmaker, or market. |

DynastyIQ should use discovery for provider availability checks only. Internal
league identity should still come from DynastyIQ/NHL data.

## Event Flow

### Discover NHL Events

```http
GET /events?sport=<sport>&league=<league>
X-API-Key: <ODDS_API_NET_KEY>
```

DynastyIQ matching strategy:

- Match provider events to `nhl_games` by game date, home team, away team, and
  start time.
- Persist the provider `event_id` after a confident match.
- Keep a review path for unmatched events, postponed games, neutral-site games,
  and provider naming differences.

## Odds Snapshots

Pull the current market state for one event:

```http
GET /events/{event_id}/odds/snapshot
X-API-Key: <ODDS_API_NET_KEY>
```

Expected usage:

- Pull a snapshot before opening a realtime stream.
- Store every normalized outcome as an immutable snapshot row.
- Preserve enough raw provider data to debug parser and identity matching.
- Use provider timestamps when available and DynastyIQ `captured_at` as the
  local request timestamp.

Relevant response concepts from the docs:

| Field / Concept | Meaning |
| --- | --- |
| `selection_key` | Stable provider key for a specific outcome/selection. Useful for history and movement tracking. |
| `as_of_ts_ms` | Provider timestamp used to identify the snapshot point in time. |
| `ttl_seconds` | Suggested freshness lifetime for the snapshot. |
| `fair_odds` | Provider fair/no-vig style odds field when requested or available. |

## Realtime Odds

odds-api.net documents realtime event odds using SSE and WebSocket feeds.

SSE endpoint:

```http
GET /events/{event_id}/odds/stream
X-API-Key: <ODDS_API_NET_KEY>
```

WebSocket endpoint:

```text
/events/{event_id}/odds/ws
```

Recommended DynastyIQ usage:

- Use snapshots for baseline state.
- Use SSE first for realtime changes because DynastyIQ mainly needs one-way
  provider-to-app updates.
- Use WebSockets only if we need dynamic subscriptions or higher-volume realtime
  behavior.
- Store streamed changes as immutable odds snapshots or deltas with enough data
  to reconstruct the line at any timestamp.
- Use resume/since-style parameters when supported to continue after disconnects.

Do not make realtime feeds part of normal game import validation. Betting odds
are a separate market-data pipeline.

## Line Movement

Historical line movement is available through event history endpoints.

```http
GET /events/{event_id}/odds/history
X-API-Key: <ODDS_API_NET_KEY>
```

Expected filters include event, bookmaker, market, selection, and time range.

Use line history for:

- Open-to-current movement.
- Current-to-close movement.
- Steam move detection.
- Comparing DynastyIQ projections to market movement.
- Backtesting model edges against historical market prices.

Use `selection_key` when available rather than reconstructing a line identity
from player name, market, side, and point value.

## Results

Event results can be requested with:

```http
GET /events/{event_id}/results
X-API-Key: <ODDS_API_NET_KEY>
```

DynastyIQ should not use provider results as NHL game/stat authority. Results
may be useful for settling provider-market records or validating odds-specific
workflows, but final hockey data should continue to come from NHL imports.

## Usage And Status

Operational endpoints advertised by odds-api.net include usage and status
surfaces.

Recommended DynastyIQ behavior:

- Persist API usage counts with import runs when available.
- Alert or throttle before hitting rate limits.
- Treat 429 responses as retryable with spacing.
- Treat empty odds responses as provider availability unless the HTTP response
  indicates an error.

## Market Coverage

Public coverage indicates support for NHL games and props. Exact market keys
should be verified against live provider responses before implementation treats
them as canonical.

Expected DynastyIQ phase-one groups:

| Group | Examples |
| --- | --- |
| Game lines | Moneyline, puck line/spread, game total. |
| Period lines | First-period moneyline, spread, and total when available. |
| Skater props | Shots on goal, goals, assists, points, blocked shots. |
| Goalie props | Saves. |
| Scorer props | Anytime, first, and last goal scorer. |

## No-Vig And Fair Odds

No-vig odds remove sportsbook margin from a complete market. Fair odds represent
the provider's fair-price view when available.

Use raw sportsbook odds for:

- Displaying the actual market.
- Comparing bookmaker prices.
- Tracking line movement.

Use no-vig or fair odds for:

- Comparing DynastyIQ model probability to market-implied probability.
- Identifying potential edges.
- Backtesting model calibration.

Do not mix raw and no-vig/fair odds in the same metric without clearly labeling
the source probability.

## Normalized Storage Recommendation

DynastyIQ should store odds as immutable snapshots. Do not overwrite old prices.

Recommended concepts:

| Concept | Purpose |
| --- | --- |
| Provider event map | Links odds-api.net event ids to DynastyIQ `nhl_game_id`. |
| Bookmaker | Stores provider bookmaker key and display name. |
| Market | Stores canonical market key and provider market key. |
| Selection | Stores provider `selection_key`, side, team, player, and line identity. |
| Snapshot | Stores odds observed at a provider timestamp or DynastyIQ capture timestamp. |
| Stream cursor | Stores resume/since state for SSE or WebSocket reconnects. |
| Raw payload | Preserves provider response evidence for parser review. |

Minimum fields for a normalized outcome snapshot:

| Field | Meaning |
| --- | --- |
| `provider` | `odds_api_net`. |
| `provider_event_id` | odds-api.net event id. |
| `nhl_game_id` | Matched DynastyIQ NHL game id when known. |
| `selection_key` | Provider selection key when available. |
| `bookmaker_key` | Provider bookmaker key. |
| `market_key` | Provider market key. |
| `selection_name` | Team, `Over`, `Under`, `Yes`, `No`, or other outcome name. |
| `participant_name` | Player name for props when provider exposes it. |
| `line` | Spread, total, or prop threshold when present. |
| `price_american` | American odds when available or derived. |
| `fair_price_american` | Fair/no-vig price when available. |
| `implied_probability` | Derived from raw sportsbook price before vig removal. |
| `fair_implied_probability` | Derived from fair/no-vig price when available. |
| `provider_timestamp` | Provider event/update timestamp. |
| `captured_at` | DynastyIQ request capture timestamp. |
| `raw_payload` | Original provider fragment for audit. |

## Derived Values

American odds to implied probability:

| Odds | Formula |
| --- | --- |
| Positive | `100 / (odds + 100)` |
| Negative | `abs(odds) / (abs(odds) + 100)` |

For two-sided over/under markets, a no-vig probability can be derived by
normalizing both implied probabilities so they sum to `1.0`.

## Import Cadence

Suggested initial cadence:

| Window | Cadence |
| --- | --- |
| Future games more than 24 hours away | 2 to 4 times per day. |
| Game day before final 2 hours | Every 30 minutes. |
| Final 2 hours before puck drop | Snapshot every 5 to 10 minutes or open SSE for watched games. |
| Live games | Use SSE only after DynastyIQ intentionally supports live odds. |

Props should be requested closer to game time than game lines because player
availability and sportsbook prop coverage change frequently.

## Cost Controls

- Pull events first, then only request odds for events DynastyIQ can map.
- Pull one event snapshot before opening a stream.
- Prefer bookmaker filters when the product needs a known book set.
- Store stream cursors so reconnects do not replay unnecessary data.
- Skip duplicate snapshot writes when no market changed.
- Keep props scoped to markets DynastyIQ can model or display.

## MCP Usage

If odds-api.net exposes an MCP server, DynastyIQ should use it first for
exploration rather than production ingestion.

Good MCP use cases:

- Ask ad hoc questions about current NHL markets.
- Inspect player prop availability.
- Compare market movement across books.
- Validate provider event ids before building import code.
- Let AI-assisted workflows query odds data through typed tools.

Production ingestion should still live in DynastyIQ Laravel services/jobs so
auth, persistence, retries, rate limits, and audits are under application
control.

## Provider Caveats

- Market availability varies by sport, league, bookmaker, event, and time.
- Player prop names must be matched to DynastyIQ players with a reviewable
  resolver.
- Realtime feeds can disconnect and must support resume/replay behavior.
- Empty responses should be treated as provider availability unless the HTTP
  response indicates an error.
- Odds data must not affect NHL import validation or game-stat truth.
