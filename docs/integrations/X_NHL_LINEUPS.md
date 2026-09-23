# X NHL Lineup Discovery

DynastyIQ searches X directly for recent text-based anticipated NHL lineups only after the NHL gamecenter boxscore does not contain a complete official roster for the game/team. Laravel owns scheduling, text parsing, persistence, player resolution, consensus, and prediction selection. OpenAI is not used by this workflow.

## Configuration

OCR and English language data are production dependencies in `package.json` and
`package-lock.json`; see [Lineup OCR](LINEUP_OCR.md). Date-eligible photo attachments
are read when caption text does not contain a verified full lineup. The normal
npm installation supplies the runtime; no separate model setup is required.

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
3. If both a complete forward group and complete defense group have not been accepted, read posts 6–10 from source one using its `next_token`.
4. Continue the same cycle until both groups are available or every timeline is exhausted. Each account is limited to six pages of five posts (at most 30 posts) per discovery attempt, even if X returns another pagination token. Reaching one account's cap does not stop the remaining accounts.

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

Every returned timeline post is read in full. Before resolving names, the parser requires a single roster-shaped block containing twelve forward names followed by six defense names in that same post. Forward/defense headings are allowed; ordered individual names and delimited lines/pairs map to F1–F4 and D1–D3. Sentences are not mined for player mentions to fill missing slots. Multiple complete roster blocks are ambiguous and declined. Canonical identity and slot verification follows through `NhlLineupPlayerResolver`, preserving apostrophe normalization and the F4/D3 peer exceptions. Goalies and scratches remain supplemental and cannot fill missing skater slots.

When multiple players on the team share a surname, a reported first initial selects the matching player. For example, `B. Tkachuk` and `M. Tkachuk` resolve independently; a conflicting initial is not permitted to fall back to the wrong same-surname player.

Explicit player-specific spelling aliases are maintained in `config/name_variants.php`
under `player_name_aliases` and exposed through `PlayerIdentityNormalizer`.
For example, `Igor Chernyshev`, `I. Chernyshev`, and `Chernyshev` can resolve to
canonical `Igor Chernyshov` through the shared lineup resolver. Team preference
and ambiguity checks still apply; no blanket Russian surname substitutions or
unrestricted fuzzy matching are used. Add reviewed spellings to this dictionary,
not to individual importers or the text parser.

Parsing is intentionally conservative:

- A post becomes a lineup candidate only after the complete 12F-then-6D block is found and its players pass shared verification.
- During preseason, automated X candidates must also explicitly contain the word “tonight” (case-insensitive), anywhere before or after the roster in the complete caption or extracted lineup text. Practice-only posts and other posts without that word are declined with an audit reason. Manual submissions and official NHL rosters are exempt; regular-season and playoff discovery are unchanged. This filter applies to newly evaluated candidates and does not rewrite previously stored lineups.
- Forward-only and defense-only posts remain discovery-audit-only. Photo text passes the same parser and verification as captions; original captions, OCR text, confidence, and image URLs remain separate evidence.
- Current truth selects one complete observation. Corroboration requires matching the whole roster; separate posts never contribute different halves. Historical combined-post records cannot qualify as reported or stop discovery. Existing date eligibility checks remain in force.
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
- A team with a verified, date-eligible current lineup is no longer searched, even if it has only one source. Unresolved core players, duplicate identities, and invalid positions prevent this skip. Unknown F4/D3 slots remain explicitly unresolved and are allowed only with a verified same-group peer for prediction fallback.

The scheduler still controls when each configured lane becomes due. The parent import command dispatches one `lineups` queue job per game/team for today and tomorrow without checking current lineups or contacting providers. Each job rechecks its game date and requested timing window, skips existing complete lineup coverage, checks NHL, and only then scans X. Ineligible jobs count as skipped in import progress. Manual runs use the `all` window; the within-two-hours window applies only to today's games, including games already started.

Jobs share an X-search overlap lock. HTTP 429 responses release the job according to X's `Retry-After` header or the existing bounded backoff. Pagination remains attempt-local: a retried job starts its scan again, so the 30-post cap is not a persistent cross-retry usage budget.

Live terminal and Admin output is intentionally concise and emits one line per timeline page, for example `CAR | @Canes | posts 1-5`. Detailed post decisions and reasons remain in local troubleshooting Markdown.

## Usage and failures

Every X timeline request writes an `integration_api_usage_logs` row containing its source context and number of posts returned. No application-owned daily request ceiling is imposed.

Admin-triggered lineup runs accumulate returned Post Reads and User Reads in `import_runs.meta`, while their running and final estimated spend is persisted in `import_runs.estimated_cost_usd`. The progress UI includes `(posts, estimated spend)` directly in the records progress text while polling. Estimated spend uses X's published standard Post Read rate of `$0.005` per returned post and User Read rate of `$0.01` when an uncached source handle must be resolved. `X_POST_READ_COST_USD` and `X_USER_READ_COST_USD` may override those rates when X changes its pricing. The `$0.001` Owned Read price does not apply to reporter and team accounts that DynastyIQ does not own.

- Missing credentials fail the affected team job without deleting current truth.
- Authentication, credit, HTTP, and parsing failures are reported through the existing import-run workflow.
- Empty, unparseable, and duplicate-only searches are skipped rather than counted as successful imports.
- Actual billed usage remains authoritative in the X Developer Console; DynastyIQ displays an estimate from returned resources.
