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

The X timeline request is:

```http
GET https://api.x.com/2/users/{id}/tweets
Authorization: Bearer X_BEARER_TOKEN
```

Discovery uses only X accounts linked to the team through `sources` and `source_scopes`. Generic X keyword search is not used. Handles are resolved once to `sources.platform_user_id`; subsequent imports use `GET /2/users/{id}/tweets` directly.

The importer reads timelines in round-robin order:

1. Read the newest five posts from source one.
2. Read the newest five posts from source two, then every remaining source.
3. If no complete lineup was accepted, read posts 6–10 from source one using its `next_token`.
4. Continue the same cycle until a complete lineup is accepted or every timeline is exhausted.

Every request includes a `start_time` at the beginning of the calendar day immediately preceding the target game, interpreted in America/Toronto and sent to X in UTC. This permits yesterday's lineup report for today's game without scanning older history.

Returned evidence is checked against the same boundary before acceptance. Older observations cannot remain current, suppress another scheduled lookup, appear in public or partner payloads, or feed a prediction.

Example source-first request:

```text
GET /2/users/{Canes-user-id}/tweets?max_results=5&start_time=...
```

The request uses `max_results=5` and asks for:

- Post text and publication time.
- Author name, username, URL, and public account metrics.
- Like, reply, repost, and impression counts when X returns them.
- Media metadata for audit purposes.

X charges for every post resource returned. Repeated resources are generally deduplicated by X within one UTC day, while DynastyIQ separately prevents the same post URL from creating duplicate observations.

An account supplying accepted lineup evidence is stored in `sources` and linked to the NHL team through `source_scopes`. Official team accounts and beat writers use the same source representation for now; persistence does not imply trust.

## Local parsing

Every returned timeline post is read in full. The parser scans each post line by line, resolves apparent names against canonical players belonging to the target team through `PlayerIdentityNormalizer`, and then looks for lineup-shaped groups. Straight apostrophes, curly apostrophes, backticks, and omitted apostrophes are equivalent for matching while stored display names remain unchanged. A hyphen-delimited group of three names forms a forward line and a group of two names forms a defense pair when at least one name establishes that the group belongs to the target team. Reported prospect names that do not yet resolve are retained as unresolved evidence rather than discarded. It maps four forward groups to `F1` through `F4`, three defense pairs to `D1` through `D3`, and ordered goalies to `G1` and `G2` when present.

When multiple players on the team share a surname, a reported first initial selects the matching player. For example, `B. Tkachuk` and `M. Tkachuk` resolve independently; a conflicting initial is not permitted to fall back to the wrong same-surname player.

Parsing is intentionally conservative:

- A post becomes a lineup candidate only after at least one target-team player group is resolved; unrelated and single-name posts are skipped after their complete text has been evaluated.
- Partial player groups are declined and retained only in discovery audits. They do not stop discovery, create lineup observations, or count as lineups found. Twelve forwards and six defensemen are required; image-only content is not interpreted.
- Missing players are never invented from roster history or hockey knowledge.
- Special-teams assignments remain empty unless a future deterministic parser explicitly supports them.
- On split-squad dates, extracted players and opponent context must identify the targeted game; ambiguous evidence is not attached to either game.

## Local troubleshooting audits

When Laravel's environment is `local`, every returned X post is written before filtering to:

```text
docs/troubleshooting/lineups/{TEAM_ABBREV}/x_post_{X_POST_ID}.md
```

The Markdown file starts with the approval or decline reason and includes the target game, timeline page context, author, timestamp, complete post text, every canonical target-team player match, parsed lineup slots, and raw X post JSON. Repeated timeline reads overwrite the same post-ID file. Testing, staging, and production do not write these files.

Before each local import writes new audits, it recursively deletes every generated `.md` file beneath `docs/troubleshooting/lineups/` while preserving `README.md` and all directories. The import then writes `import_YYYYMMDD_HHMMSS_UUUUUU.md`, listing every eligible team/game job and whether it was dispatched or skipped, and creates `docs/troubleshooting/lineups/{TEAM_ABBREV}/search.md` for every eligible team. Each subsequent X request appends the exact query and all returned results to that file; an empty X response is written as zero results rather than leaving the search invisible.

## Scheduling

The existing Anticipated Lineups admin schedule remains authoritative:

- `within_two_hours` defaults to 900 seconds and applies to each same-day game relative to puck drop.
- `outside_two_hours` defaults to 3600 seconds and considers games today and tomorrow.
- Same-day games with missing lineup coverage remain eligible after puck drop.
- A team with two current matching independent sources is no longer searched.

The scheduler queues one bounded job per eligible game/team. Jobs share an X-search overlap lock. HTTP 429 responses release the job according to X's `Retry-After` header or the existing bounded backoff.

Live terminal and Admin output is intentionally concise and emits one line per timeline page, for example `CAR | @Canes | posts 1-5`. Detailed post decisions and reasons remain in local troubleshooting Markdown.

## Usage and failures

Every X timeline request writes an `integration_api_usage_logs` row containing its source context and number of posts returned. No application-owned daily request ceiling is imposed.

- Missing credentials fail the affected team job without deleting current truth.
- Authentication, credit, HTTP, and parsing failures are reported through the existing import-run workflow.
- Empty, unparseable, and duplicate-only searches are skipped rather than counted as successful imports.
- Current X pricing and usage are available in the X Developer Console.
