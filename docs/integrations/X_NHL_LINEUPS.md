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

Each team search uses one broad OR query containing the available team identifiers:

```text
({team_abbrev} OR {team_nickname} OR "{team_full_name}")
```

Example:

```text
(ANA OR Ducks OR "Anaheim Ducks")
```

The request uses `max_results=10`, the endpoint's minimum page size, and asks for:

- Post text and publication time.
- Author name, username, URL, and public account metrics.
- Like, reply, repost, and impression counts when X returns them.
- Media metadata for audit purposes.

X charges for every post resource returned and separately charges for expanded author resources. Repeated resources are generally deduplicated by X within one UTC day, while DynastyIQ separately prevents the same post URL from creating duplicate observations.

## Local parsing

All ten returned posts are read in full. Search words do not qualify or reject a post after retrieval. The parser scans each post line by line, resolves apparent names against canonical players belonging to the target team, and then looks for lineup-shaped groups. Three resolved forwards form a forward line and two resolved defensemen form a defense pair whether the names are separated by spaces, hyphens, slashes, or surrounding prose. It maps four forward groups to `F1` through `F4`, three defense pairs to `D1` through `D3`, and ordered goalies to `G1` and `G2` when present.

Parsing is intentionally conservative:

- A post becomes a lineup candidate only after at least one target-team player group is resolved; unrelated and single-name posts are skipped after their complete text has been evaluated.
- Partial lineup candidates remain partial observations, but only twelve resolved forwards and six resolved defensemen constitute a full reported lineup; image-only content is not interpreted.
- Missing players are never invented from roster history or hockey knowledge.
- Special-teams assignments remain empty unless a future deterministic parser explicitly supports them.
- On split-squad dates, extracted players and opponent context must identify the targeted game; ambiguous evidence is not attached to either game.

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
