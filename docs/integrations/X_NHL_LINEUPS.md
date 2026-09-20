# X NHL Lineup Discovery

DynastyIQ searches X directly for recent text-based anticipated NHL lineups only after the NHL gamecenter boxscore does not contain a complete official roster for the game/team. Laravel owns scheduling, text parsing, persistence, player resolution, consensus, and prediction selection. OpenAI is not used by this workflow.

## Configuration

Store the X app bearer token as a deployment secret:

```dotenv
X_BEARER_TOKEN=
X_TIMEOUT_SECONDS=30
```

The bearer token must never be committed. The X developer project must have prepaid credits and should have an operator-defined spending limit.

## Request

Before spending X credits, each queued team job requests:

```http
GET https://api-web.nhle.com/v1/gamecenter/{game-id}/boxscore
```

When `playerByGameStats` contains at least twelve forwards and six defensemen for the team, DynastyIQ stores that roster as `official`, records a goalie marked `starter: true` as confirmed starting-goalie evidence, and does not search X. Incomplete or absent boxscore rosters fall through to X.

The X fallback request is:

```http
GET https://api.x.com/2/tweets/search/recent
Authorization: Bearer X_BEARER_TOKEN
```

Each team search uses this query:

```text
{team_abbrev} {team_nickname} starting lineup
```

Example:

```text
ANA Ducks starting lineup
```

The request uses `max_results=10`, the endpoint's minimum page size, and asks for:

- Post text and publication time.
- Author name, username, URL, and public account metrics.
- Like, reply, repost, and impression counts when X returns them.
- Media metadata for audit purposes.

X charges for every post resource returned and separately charges for expanded author resources. Repeated resources are generally deduplicated by X within one UTC day, while DynastyIQ separately prevents the same post URL from creating duplicate observations.

## Local parsing

The parser uses canonical DynastyIQ player names, team context, and explicitly delimited lineup sections to recognize ordered text groups. It maps four groups of three forwards to `F1` through `F4`, three defense pairs to `D1` through `D3`, ordered goalies to `G1` and `G2`, and explicitly headed scratches to `SCR`. Names that cannot be resolved remain visible as unresolved observation players.

Parsing is intentionally conservative:

- Returned text posts are retained as partial raw-evidence observations even when no lineup can be parsed; image-only content is not interpreted.
- Posts without enough recognizable ordered player text are skipped.
- Missing players are never invented from roster history or hockey knowledge.
- Special-teams assignments remain empty unless a future deterministic parser explicitly supports them.
- On split-squad dates, a post must name the targeted opponent before DynastyIQ attaches it to a specific game.

## Scheduling

The existing Anticipated Lineups admin schedule remains authoritative:

- `within_two_hours` defaults to 900 seconds and applies to each same-day game relative to puck drop.
- `outside_two_hours` defaults to 3600 seconds and considers games today and tomorrow.
- Same-day games with missing lineup coverage remain eligible after puck drop.
- A team with two current matching independent sources is no longer searched.

The scheduler queues one bounded job per eligible game/team. Jobs share an X-search overlap lock. HTTP 429 responses release the job according to X's `Retry-After` header or the existing bounded backoff.

## Usage and failures

Every X request writes an `integration_api_usage_logs` row containing the search query and number of posts returned. No application-owned daily search ceiling is imposed.

- Missing credentials fail the affected team job without deleting current truth.
- Authentication, credit, HTTP, and parsing failures are reported through the existing import-run workflow.
- Empty, unparseable, and duplicate-only searches are skipped rather than counted as successful imports.
- Current X pricing and usage are available in the X Developer Console.
