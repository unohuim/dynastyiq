# Highlightly NHL & NCAAH API Usage Guide

## Scope and sources

This is an external provider reference for Highlightly's NHL & NCAAH API, covering all 18 documented endpoints, with setup instructions for DynastyIQ's outbound client. The client does not import data or establish identity mappings or a partner-facing API. DynastyIQ's partner contract remains [DIQ-API-Usage-Doc.md](DIQ-API-Usage-Doc.md).

Reviewed September 28, 2026 against the provider's documentation and OpenAPI version **3.1.4**. The API covers NHL and NCAA Division I hockey; availability depends on the subscription and resource. Examples below illustrate requests, not captured live responses. No authenticated API requests were used to prepare this guide.

- [Documentation home](https://highlightly.net/nhl-api/documentation/)
- [OpenAPI specification](https://highlightly.net/nhl-api/documentation/docs.json): exact parameter and response schemas; importable into API clients.
- [Getting started](https://highlightly.net/nhl-api/documentation/getting-started/)
- [Authentication](https://highlightly.net/nhl-api/documentation/getting-started/authentication/)
- [Pagination, quotas, and plan restrictions](https://highlightly.net/nhl-api/documentation/getting-started/pagination/)
- [Errors](https://highlightly.net/nhl-api/documentation/getting-started/errors/)
- [NHL subscription plans](https://highlightly.net/nhl-api/) and [All Sports subscription plans](https://highlightly.net/sport-api/)
- [RapidAPI NHL & NCAAH product](https://rapidapi.com/highlightly-api-highlightly-api-default/api/nhl-ncaah-api)

## Access requirements

### DynastyIQ client setup

The server-side client is `App\Services\HighlightlyNhlClient`. Laravel can resolve it directly with `app(HighlightlyNhlClient::class)` or inject it into another service. No service-provider registration is needed.

| Environment setting | Laravel configuration | Default / requirement |
| --- | --- | --- |
| `HIGHLIGHTLY_API_KEY` | `services.highlightly.key` | Required before making requests; use the selected product's key. |
| `HIGHLIGHTLY_BASE_URL` | `apiurls.highlightly.base` | `https://nhl.highlightly.net`; accepts the four documented base URLs below. A trailing slash is allowed. |
| `HIGHLIGHTLY_TIMEOUT_SECONDS` | `services.highlightly.timeout_seconds` | 30; must be positive. |
| `HIGHLIGHTLY_CONNECT_TIMEOUT_SECONDS` | `services.highlightly.connect_timeout_seconds` | 10; must be positive. |

Endpoint paths live in `apiurls.highlightly.endpoints`. Use `HighlightlyNhlClient`, rather than the generic `HasAPITrait` request methods, for this provider: the client owns product-specific headers and retains the full HTTP response. No separate host-header setting is needed; the client derives it from a RapidAPI base URL and omits it on Highlightly hosts. Credentials must remain in server configuration. Repository environment files are not modified by this implementation.

All methods return `Illuminate\Http\Client\Response`. Use `json()` for the unchanged decoded body, `json('data')` for a paginated result array, and `header()` for quota information. By-ID arrays remain arrays. Restricted successful results keep their original `plan` metadata.

```php
use App\Services\HighlightlyNhlClient;

$client = app(HighlightlyNhlClient::class);
$response = $client->matches([
    'league' => 'NHL',
    'date' => '2026-01-15',
    'timezone' => 'America/Toronto',
    'limit' => 100,
    'offset' => 0,
]);

$matches = $response->json('data');
$pagination = $response->json('pagination');
$remaining = $response->header('x-ratelimit-requests-remaining');
```

| Client method | GET endpoint |
| --- | --- |
| `bookmakers(array $query = [])` | `/bookmakers` |
| `bookmaker(int $id)` | `/bookmakers/{id}` |
| `headToHead(int $teamIdOne, int $teamIdTwo)` | `/head-2-head` |
| `highlights(array $query)` | `/highlights` |
| `highlightGeoRestrictions(int $id)` | `/highlights/geo-restrictions/{id}` |
| `highlight(int $id)` | `/highlights/{id}` |
| `lastFiveGames(int $teamId)` | `/last-five-games` |
| `matches(array $query)` | `/matches` |
| `matchDetails(int $id)` | `/matches/{id}` |
| `odds(array $query)` | `/odds` |
| `standings(array $query = [])` | `/standings` |
| `teams(array $query = [])` | `/teams` |
| `teamStatistics(int $id, string $fromDate, ?string $timezone = null)` | `/teams/statistics/{id}` |
| `team(int $id)` | `/teams/{id}` |
| `lineups(int $matchId)` | `/lineups/{matchId}` |
| `players(array $query = [])` | `/players` |
| `player(int $id)` | `/players/{id}` |
| `playerStatistics(int $id)` | `/players/{id}/statistics` |

Query arrays use the exact provider parameter names documented below and pass them through without renaming, implicit filters, or altered pagination. Required ID arguments are positive integers in this client, a deliberately narrower local interface than OpenAPI's `number`. It validates the required aggregate date and primary-filter presence locally; the provider validates remaining filter values and optional parameters.

Each method makes exactly one request, with no automatic retries, redirects, pagination, caching, or database writes. Callers control any subsequent requests and should inspect remaining quota first. Missing credentials or invalid configuration throw `LogicException`; invalid required inputs throw `InvalidArgumentException`. Provider HTTP failures throw Laravel `RequestException`, retaining the response; connection failures propagate as `ConnectionException`. Unexpected redirects or success responses without an object/array JSON body throw `UnexpectedValueException`.

The client intentionally leaves plan enforcement and optional-filter validation to Highlightly. Its canonical integration boundary is documented in [HighlightlyNhlClient.yaml](../architecture/integrations/HighlightlyNhlClient.yaml).

The accompanying `tests/Unit/HighlightlyNhlClientTest.php` uses fake HTTP responses and blocks stray requests. To run that suite manually:

```bash
php artisan test tests/Unit/HighlightlyNhlClientTest.php
```

### Provider account setup

#### Browser test page

Visit `/lineups-test` to browse NHL games using the configured Highlightly account. The page uses the regular application layout and defaults to **September 29, 2026**. `/lineups-test?date=2026-09-30` overrides the initial date. Dates and displayed game times use **America/Toronto**.

Click the date field to open the browser calendar, or use the arrows on either side to move one day. Date changes fetch the game's list asynchronously through `/lineups-test/games?date=YYYY-MM-DD`. Selecting a game fetches `/lineups-test/{matchId}/lineups` and displays both teams' provider player lists, including jersey, position, and scratch status. The page offers refresh/retry actions and identifies unavailable lineups separately from failed requests.

These are public read-only page routes, consistent with the existing games page. Credentials remain server-side. Selecting dates or games consumes provider requests; there is no background polling or persistence. Daily game pagination is bounded to 1,000 results and returns an error instead of silently truncating unexpected larger responses. The page does not infer forward lines, defensive pairings, or starting goalies from list order.

Accompanying tests (not executed as part of authoring):

```bash
php artisan test tests/Feature/HighlightlyLineupsTestPageTest.php
npm test -- --run resources/js/pages/lineups-test.test.js
```

### Obtaining credentials

Create an account and obtain a key from the platform supplying the subscription. Highlightly and RapidAPI accounts are separate and are not synchronized. Requests must use the base URL and credentials for the subscribed product.

| Platform and subscription | Base URL | Required headers |
| --- | --- | --- |
| Highlightly NHL & NCAAH | `https://nhl.highlightly.net` | `x-rapidapi-key: YOUR_HIGHLIGHTLY_KEY` |
| Highlightly All Sports | `https://sports.highlightly.net/nhl` | `x-rapidapi-key: YOUR_HIGHLIGHTLY_KEY` |
| RapidAPI NHL & NCAAH | `https://nhl-ncaah-api.p.rapidapi.com` | `x-rapidapi-key: YOUR_NHL_PRODUCT_KEY` and `x-rapidapi-host: nhl-ncaah-api.p.rapidapi.com` |
| RapidAPI All Sports | `https://sport-highlights-api.p.rapidapi.com/nhl` | `x-rapidapi-key: YOUR_ALL_SPORTS_PRODUCT_KEY` and `x-rapidapi-host: sport-highlights-api.p.rapidapi.com` |

Append the endpoint paths in this guide to the selected base URL. For example, All Sports uses `/nhl/matches`; the dedicated NHL host uses `/matches`.

The direct Highlightly service still calls its key header `x-rapidapi-key`. Direct requests need only that header; `x-rapidapi-host` applies only to RapidAPI. Highlightly uses one account key across its products, subject to the subscriptions held. RapidAPI's setup instructions require the key associated with the selected product.

Keep credentials on the server. Do not put a key in browser JavaScript, public URLs, or committed examples. Public logo and preview-image URLs do not require these authentication headers.

All documented operations use **GET**, with path/query parameters and no request body. Responses use `application/json`; XML is unsupported.

### Subscription and quota requirements

| Resource | Documented restriction |
| --- | --- |
| Highlights | Basic excludes NHL highlights. The highlights page specifies Pro or higher for highlights across all covered leagues. Basic/Free responses may contain restricted results. |
| Odds | Unavailable on Basic/Free. |
| Highlight geo restrictions | Unavailable on Basic/Free. |
| All Sports | The same resource restrictions apply. Requests across all sports share the subscription's quota. |

Inspect both the HTTP response and the `plan` object. Restricted result sets can arrive with **200**, so success does not prove full coverage. Consult the linked subscription pages for current prices, quotas, and entitlements; this guide does not fix commercial limits to a particular plan.

| Response header | Meaning |
| --- | --- |
| `x-ratelimit-requests-limit` | Request allowance associated with the plan. |
| `x-ratelimit-requests-remaining` | Requests remaining before exhaustion. At zero, requests fail until the daily quota resets. |
| `content-type` | `application/json`. |

The setup documentation does not specify an exact daily reset time, a quota-exhaustion status code, or a universal requests-per-second limit. A data refresh interval is not a request-rate allowance. Each pagination request spends quota.

## Common request rules

### Identifiers, dates, and league filters

- Use Highlightly IDs from its own team, player, match, highlight, and bookmaker responses. No mapping to NHL official IDs or DynastyIQ IDs is documented.
- Path IDs and numeric filters are declared as `number` in OpenAPI. Substitute actual returned IDs for `{id}` and `{matchId}` placeholders.
- Query `date` and `fromDate` use `YYYY-MM-DD`. Where supported, `timezone` accepts a valid timezone identifier, such as `America/Toronto`, and defaults to `Etc/UTC`.
- `season` and standings `year` are numeric provider values, with four-digit examples. The documentation does not define a universal start-year/end-year conversion to DynastyIQ season identifiers.
- League filter names differ: `league` on teams/matches, `leagueName` on highlights/odds, and `leagueType` on standings. `NHL` and `NCAA` are documented league examples, not a declared closed enum.
- Team `name`, `displayName`, and `abbreviation` are separate fields. Name-matching case sensitivity, substring behavior, and sort order are not specified.

### Primary filters

`/matches`, `/highlights`, and `/odds` each require **at least one primary query parameter**, even though individual filters are marked optional in OpenAPI.

`timezone`, `limit`, and `offset` are secondary. `/odds` also treats `oddsType` as secondary. All other query parameters listed for these three endpoints are primary. For example, `/matches?league=NHL` satisfies the rule, while `/matches?limit=100` does not. The documentation says secondary-only requests do not return data; it does not promise a particular missing-filter status code.

### Pagination

Only these six endpoints support `limit` and `offset`:

| Endpoint | Default limit | Maximum limit |
| --- | ---: | ---: |
| `/bookmakers` | 20 | 100 |
| `/highlights` | 40 | 40 |
| `/matches` | 100 | 100 |
| `/odds` | 5 | 5 |
| `/standings` | 10 | 10 |
| `/players` | 1000 | 1000 |

All six declare `limit >= 0`, `offset >= 0`, and default `offset = 0`. Although zero is schema-valid for `limit`, use a positive page size when iterating so the offset can advance.

Each paginated response requires these fields:

```json
{
  "data": [],
  "pagination": { "totalCount": 0, "offset": 0, "limit": 100 },
  "plan": { "tier": "BASIC", "message": "Provider explanation of plan coverage" }
}
```

This is an illustrative envelope. `data` holds endpoint-specific objects. `totalCount` counts matching results, not the length of the current page. Hold the filters and positive limit steady, start at offset zero, and advance offset by the page size until the matching range has been covered. Plan restrictions can make returned pages shorter than the matching count; pagination does not bypass those restrictions. Inspect coverage before claiming a complete collection.

Other endpoints return arrays or objects directly, with no pagination envelope. In particular, most by-ID endpoints return an **array**, even when requesting one entity.

## Endpoint reference

Every parameter below is optional unless explicitly marked **required**. Types are `string` or `number` as declared by the provider. Common headers, errors, and pagination rules apply to all relevant entries.

### 1. GET `/bookmakers`

[Provider reference](https://highlightly.net/nhl-api/documentation/bookmakers/)

Lists bookmakers. Refreshed daily. Returns a paginated envelope of bookmaker objects (`id`, `name`). Use these IDs or names in odds filters.

| Query parameter | Type | Requirement/default |
| --- | --- | --- |
| `name` | string | Bookmaker name. |
| `limit` | number | 0–100; default 20. |
| `offset` | number | Minimum 0; default 0. |

### 2. GET `/bookmakers/{id}`

[Provider reference](https://highlightly.net/nhl-api/documentation/bookmakers/by-id/)

**Required path:** `id` (number), a bookmaker ID. No query parameters documented. Returns an array of bookmaker objects. Useful for checking an existing bookmaker record; no separate refresh cadence is specified.

### 3. GET `/head-2-head`

[Provider reference](https://highlightly.net/nhl-api/documentation/head-2-head/)

Returns an array containing the last ten head-to-head games between two teams. Team order does not matter. No pagination or separate refresh cadence is documented.

| Query parameter | Type | Requirement |
| --- | --- | --- |
| `teamIdOne` | number | **Required** Highlightly team ID. |
| `teamIdTwo` | number | **Required** Highlightly team ID. |

### 4. GET `/highlights`

[Provider reference](https://highlightly.net/nhl-api/documentation/highlights/)

Returns a paginated envelope of highlights. Refreshed every minute. Requires at least one primary filter. NHL highlights require Pro or higher according to this endpoint's coverage notes.

| Query parameter | Type | Requirement/default |
| --- | --- | --- |
| `leagueName` | string | League, for example `NHL`. |
| `date` | string | `YYYY-MM-DD`. |
| `timezone` | string | Secondary; default `Etc/UTC`. |
| `season` | number | Provider season value. |
| `matchId` | number | Highlightly match ID. |
| `homeTeamId`, `awayTeamId` | number | Filter the corresponding side. |
| `homeTeamName`, `awayTeamName` | string | Filter the corresponding side's name. |
| `homeTeamAbbreviation`, `awayTeamAbbreviation` | string | Filter the corresponding side's abbreviation. |
| `homeTeamDisplayName`, `awayTeamDisplayName` | string | Filter the corresponding side's display name. |
| `limit` | number | Secondary; 0–40; default 40. |
| `offset` | number | Secondary; minimum 0; default 0. |

`VERIFIED` identifies inspected sources; the provider says these clips generally arrive 1–48 hours after a game finishes. `UNVERIFIED` includes user-uploaded material that can arrive during the game and may disappear from the host. Verification does not guarantee geographic availability or embedding permission.

Highlights have a title, URL, associated match, and optional description, preview image, embed URL, source, channel, and category. Sources include platforms such as YouTube, Twitter, Reddit, and ESPN; these examples are not an exhaustive enum.

Documented category values are:

```text
match-highlights       goal                  power-play-goal
shorthanded-goal       overtime-shootout-goal hat-trick
save                  fight                 hit-check
assist-play           injury                viral-moment
pre-match-content     post-match-content    press-conference
other
```

Categorization is automatic. `other` covers content without a confident category. There is no documented `category`, `source`, `channel`, or `type` query filter; apply any such selection to returned data locally.

### 5. GET `/highlights/geo-restrictions/{id}`

[Provider reference](https://highlightly.net/nhl-api/documentation/highlights/geo-restrictions/)

**Required path:** `id` (number), a highlight ID. No query parameters. Returns a restriction **object**. Refreshed hourly. Unavailable on Basic/Free.

All response fields are required: `state` (string), `allowedCountries` (string array), `blockedCountries` (string array), and `embeddable` (boolean). Country lists use ISO 3166 two-letter codes.

| Exact `state` value | Interpretation |
| --- | --- |
| `No restricitons applied` | No geographic restriction; ignore both country lists. The misspelling is part of the documented wire value. |
| `Allowed countries restriction` | Only countries in `allowedCountries` are allowed. |
| `Blocked countries restriction` | Countries in `blockedCountries` are blocked. |
| `Unknown restrictions` | Availability cannot be determined reliably. |

`embeddable` independently indicates whether the host permits embedding. Video URLs come from the highlight response, not this endpoint. Check the country rule and embedding flag before rendering an embed; unknown restrictions do not establish availability.

### 6. GET `/highlights/{id}`

[Provider reference](https://highlightly.net/nhl-api/documentation/highlights/by-id/)

**Required path:** `id` (number), a highlight ID. No query parameters. Returns an array of highlight objects. Recheck cached clips because providers can edit or remove them; no separate refresh cadence is specified.

### 7. GET `/last-five-games`

[Provider reference](https://highlightly.net/nhl-api/documentation/last-five-games/)

**Required query:** `teamId` (number). Returns an array of the team's last five **finished** games. Cancelled and postponed games are excluded. Updated when a game is considered finished. No pagination.

### 8. GET `/matches`

[Provider reference](https://highlightly.net/nhl-api/documentation/matches/)

Returns a paginated envelope of general match records. Refreshed every minute. Requires at least one primary filter. Fetch match details separately for events and team statistics.

| Query parameter | Type | Requirement/default |
| --- | --- | --- |
| `league` | string | League, for example `NHL`; not `leagueName`. |
| `date` | string | `YYYY-MM-DD`. |
| `timezone` | string | Secondary; default `Etc/UTC`. |
| `season` | number | Provider season value. |
| `homeTeamId`, `awayTeamId` | number | Filter the corresponding side. |
| `homeTeamName`, `awayTeamName` | string | Filter the corresponding side's name. |
| `homeTeamAbbreviation`, `awayTeamAbbreviation` | string | Filter the corresponding side's abbreviation. |
| `homeTeamDisplayName`, `awayTeamDisplayName` | string | Filter the corresponding side's display name. |
| `limit` | number | Secondary; 0–100; default 100. |
| `offset` | number | Secondary; minimum 0; default 0. |

No generic `teamId` or `matchId` query parameter is documented here. Use the side-specific filters or the by-ID endpoint as appropriate. For games involving a team on either side, a consumer can make separate home/away queries and deduplicate by match ID; that is consumer logic, not a documented OR filter.

### 9. GET `/matches/{id}`

[Provider reference](https://highlightly.net/nhl-api/documentation/matches/by-id/)

**Required path:** `id` (number), a match ID. No query parameters. Returns an array of detailed match records. General match fields are supplemented by optional `venue`, `referees`, `forecast`, `overallStatistics`, `events`, and `predictions`. No separate detail refresh cadence is stated.

`overallStatistics` contains team statistics. The player statistics endpoint supplies season aggregates; neither route documents per-player game statistics or shift records. Predictions appear as an optional nested field; no standalone predictions endpoint is documented.

### 10. GET `/odds`

[Provider reference](https://highlightly.net/nhl-api/documentation/odds/)

Returns a paginated envelope grouped by match. Unavailable on Basic/Free. Requires at least one primary filter. Prematch odds refresh several times daily; live odds refresh every ten minutes. Coverage runs from seven days before game start until 28 days after game completion.

| Query parameter | Type | Requirement/default |
| --- | --- | --- |
| `oddsType` | string | Secondary; `prematch` (default) or `live`. |
| `leagueName` | string | League, for example `NHL`. |
| `timezone` | string | Secondary; default `Etc/UTC`. |
| `bookmakerId` | number | Bookmaker ID. |
| `bookmakerName` | string | Bookmaker name. |
| `matchId` | number | Match ID. |
| `date` | string | `YYYY-MM-DD`. |
| `limit` | number | Secondary; 0–5; default 5. |
| `offset` | number | Secondary; minimum 0; default 0. |

| Market | Documented selections |
| --- | --- |
| `3-Way Moneyline` | `Home`, `Draw`, `Away`. |
| `Moneyline` | `Home`, `Away`; two-way alternative where no three-way moneyline is offered. |
| `Total Goals` | Line-specific variants, such as `Total Goals 8.5`, with `Over` / `Under`. |
| `Both Teams to Score` | `Yes`, `No`. |
| `Correct Score` | Score-specific variants, each with one outcome. |
| `Odd or Even` | `Odd`, `Even`. |

Each result has `matchId` and an `odds` array. A market entry contains `bookmakerId`, optional `bookmakerName`, `type`, `market`, and `values`. Each selection has numeric `odd` and string `value`. The same market can occur for multiple bookmakers or lines. The schema does not declare a price-format selector, an odds timestamp, or explicit overtime/settlement rules; do not infer these from market names alone.

### 11. GET `/standings`

[Provider reference](https://highlightly.net/nhl-api/documentation/standings/)

Returns a paginated envelope of league/conference/division standings. Updates can take up to one hour after a game in the relevant league and season finishes. No mandatory primary-filter rule is documented for this endpoint.

| Query parameter | Type | Requirement/default |
| --- | --- | --- |
| `leagueType` | string | League type, for example `NHL`. |
| `leagueName` | string | League/conference name, for example `Eastern Conference`. |
| `abbreviation` | string | League/conference abbreviation, for example `EAST`. |
| `year` | number | Provider season year. |
| `limit` | number | 0–10; default 10. |
| `offset` | number | Minimum 0; default 0. |

The provider lists these name/abbreviation pairs; they are reference examples, not a guarantee of current coverage in every season:

| Name | Abbreviation |
| --- | --- |
| Eastern Conference | `EAST` |
| Western Conference | `WEST` |
| Hockey East | `HE` |
| East Coast Athletic Conference | `ECAC` |
| Central Collegiate Hockey Association | `CCHA` |
| Western Collegiate Hockey Association | `WCHA` |
| College Hockey America | `CHA` |
| Metro Atlantic Athletic Conference | `MAAC` |
| Northeast 10 Conference | `NE10` |
| Independent | `IND` |

### 12. GET `/teams`

[Provider reference](https://highlightly.net/nhl-api/documentation/teams/)

Returns an unpaginated array of teams. Optional string query parameters: `name`, `displayName`, `abbreviation`, and `league`. No `limit` or `offset`. No refresh cadence specified.

Use the returned IDs for head-to-head, recent games, and team statistics. The endpoint documents NHL and NCAA Division I coverage.

### 13. GET `/teams/statistics/{id}`

[Provider reference](https://highlightly.net/nhl-api/documentation/teams/statistics/)

Returns an array of team aggregates grouped by the provider's `leagueName` and `round` fields. Updated when a game finishes. These are aggregate team results, not one object per game.

| Parameter | Location | Type | Requirement/default |
| --- | --- | --- | --- |
| `id` | path | number | **Required** team ID. |
| `fromDate` | query | string | **Required**, `YYYY-MM-DD`. |
| `timezone` | query | string | Default `Etc/UTC`. |

Each aggregate requires `total`, `home`, `away`, `leagueName`, and `round`. Each split contains `games` (`played`, `wins`, `loses`) and `goals` (`scored`, `received`), all numeric. Preserve the provider's spelling `loses` when parsing. There is no documented `toDate` parameter, explicit end boundary, or inclusion rule for `fromDate`.

### 14. GET `/teams/{id}`

[Provider reference](https://highlightly.net/nhl-api/documentation/teams/by-id/)

**Required path:** `id` (number), a team ID. No query parameters. Returns an array of team objects. No separate refresh cadence specified.

### 15. GET `/lineups/{matchId}`

[Provider reference](https://highlightly.net/nhl-api/documentation/lineups/)

**Required path:** `matchId` (number). No query parameters. Returns an **object** containing required `home` and `away` entries. Each contains a `team` object and a `lineup` array.

Each player requires `id` (number), `player` (string), `position` (string), and `positionAbbreviation` (string). Optional fields are `jersey` (number) and `isScratched` (boolean).

The provider recommends querying within a few hours before the game starts; it does not specify a fixed refresh cadence or guarantee an availability time. This is a list of players for each side, without documented forward-line combinations, defensive pairings, shift timing, or on-ice performance.

### 16. GET `/players`

[Provider reference](https://highlightly.net/nhl-api/documentation/players/)

Returns a paginated envelope of basic player records. Refreshed every 15 minutes. Each record requires `id`; `fullName` and `logo` are optional.

| Query parameter | Type | Requirement/default |
| --- | --- | --- |
| `name` | string | Player name. |
| `limit` | number | 0–1000; default 1000. |
| `offset` | number | Minimum 0; default 0. |

No team, league, date, or season query filters are documented for this endpoint.

### 17. GET `/players/{id}`

[Provider reference](https://highlightly.net/nhl-api/documentation/players/by-id/)

**Required path:** `id` (number), a player ID. No query parameters. Returns an array of detailed player summaries. Refreshed daily.

The player requires `id`; `fullName`, `logo`, and `profile` are optional. If present, `profile` requires its `position`, `draft`, and `team` objects. Profile details include optional `fullName`, `birthPlace`, `birthDate`, `height`, `weight`, `jersey`, and `isActive`.

- `birthDate` uses **`DD.MM.YYYY`**, unlike request dates.
- `height`, `weight`, and profile `jersey` are strings; lineup `jersey` is numeric.
- `position` has optional `main` and `abbreviation` strings.
- `draft` has optional numeric `round`, `year`, and `pick`.
- `team` requires `id`, `name`, `league`, `displayName`, and `abbreviation`; `logo` is optional.

### 18. GET `/players/{id}/statistics`

[Provider reference](https://highlightly.net/nhl-api/documentation/players/statistics/)

**Required path:** `id` (number), a player ID. No query parameters. Returns an array of player statistics records. Refreshed daily. Each record requires `id` and `perSeason`; `fullName` and `logo` are optional.

Each `perSeason` entry requires `stats`, `teams`, `league`, `season`, and `seasonBreakdown`. `teams` lists the player's teams for that season. Each statistic requires `name` (string), `value` (number), and `category` (one of `General`, `Defense`, `Offense`, `Penalties`). The statistic set varies by player position; no exhaustive stat-name list is declared.

| `seasonBreakdown` | Included games |
| --- | --- |
| `Entire` | Regular season plus postseason; excludes preseason. |
| `Season` | Regular season only. |

These are overlapping aggregates: do not add `Entire` and `Season` together. No game, date, season, or breakdown query filter is documented; choose the relevant returned entry locally.

## Shared response structures

The following describes the payload fields needed to consume the endpoints above. Required means OpenAPI lists the field as required. Optional fields may be absent; the specification does not generally declare them nullable. The linked OpenAPI document remains the source for exact schema definitions.

### Teams and matches

| Object | Required fields | Optional fields |
| --- | --- | --- |
| Bookmaker | `id` number; `name` string | None. |
| Team | `id` number; `displayName`, `abbreviation`, `league` strings | `name`, `logo` strings. |
| Team embedded in a match | `id` number; `displayName`, `abbreviation` strings | `name`, `logo` strings; no `league` field declared. |
| Match | `id`, `season` numbers; `round`, `date`, `league` strings; `homeTeam`, `awayTeam`, `state` objects | None on the general match schema. |
| Match state | `description` string; `score` object | `period`, `clock` numbers; `report` string. |
| Score | None | `current`, `firstPeriod`, `secondPeriod`, `thirdPeriod`, `overtimePeriod` strings. |
| Highlight | `id` number; `type`, `title`, `url` strings; `match` object | `imgUrl`, `description`, `embedUrl`, `channel`, `source`, `category` strings. |

Match timestamps are shown as ISO-style UTC strings, such as `2024-02-10T19:00:00.000Z`. Score strings represent **home – away**. `state.clock` is documented as the current minute, not elapsed seconds. Event clocks are a separate string field.

Exact match `state.description` values are `Suspended`, `Postponed`, `Cancelled`, `Abandoned`, `Finished`, `In progress`, `End period`, `Half time`, `Unknown`, and `Scheduled`. Preserve these provider values, including `Half time`; do not substitute a different hockey status without an explicit mapping.

`round` is the provider's field name for its competition-stage label, for example `Regular Season - 14`. It is not documented as a period, shift, or line combination.

### Additional detailed-match fields

All six top-level additions are optional:

| Field | Structure |
| --- | --- |
| `venue` | Object requiring string `city`, `name`, `state`. |
| `referees` | Array of objects requiring string `name`, `position`. |
| `forecast` | Object with optional string `status`, `temperature`. |
| `overallStatistics` | Array of objects requiring `team` and `data`; each data item requires string `value` and `displayName`. |
| `events` | Array of objects requiring string `type`, string `clock`, numeric `period`, boolean `isScoringPlay`; `team` is optional. |
| `predictions` | Object requiring `prematch` and `live` arrays. Each prediction requires string `type`, `modelType`, `generatedAt`, `description`, and a `probabilities` object. Probabilities require string `home` and `away`; string `draw` is optional. Examples use percentage strings. |

No closed event-type enum, prediction model enum, event player field, or numeric score breakdown is declared. Availability of optional detail fields should be checked before rendering or processing them.

### Standings

Each standings group requires `leagueName`, `abbreviation`, `leagueType`, `seasonType`, `startDate`, `endDate` (strings), `year` (number), and `data` (array).

Each `data` item requires `team` and `statistics`. The standings team requires numeric `id` and string `logo`, `name`, `displayName`, `abbreviation`. Each statistic requires string `value` and `displayName`. Numeric-looking values are still strings in this schema; no fixed statistic-name enum is supplied.

## Errors and handling

[Provider error reference](https://highlightly.net/nhl-api/documentation/getting-started/errors/)

| Status | Documented meaning | Response handling |
| --- | --- | --- |
| 200 | Successful response; coverage may still be restricted. | Parse the endpoint's actual array/object/envelope shape and inspect `plan` when supplied. |
| 400 | Malformed request. | JSON requires `message` string and `statusCode` number; inspect the explanation and correct the request. |
| 401 | API key rejected. | Documented `message` is `Invalid request token.`; verify the key and product. A complete schema is not declared. |
| 403 | Missing `x-rapidapi-key`. | Uses `status` and `error`, rather than the usual `statusCode` and `message`. Exact field types are not specified by the setup page. |
| 500 | Provider server error. | JSON requires `message` string and `statusCode` number. Persistent failures can be reported to `support@highlightly.net` with the URL and occurrence time, excluding credentials. |

Every OpenAPI operation declares 200, 400, and 500. The setup pages additionally document authentication failures. They do not define the response for every missing ID, empty lookup, quota exhaustion, or gateway failure; avoid assuming a guaranteed 404, 429, or common error envelope.

Consumer recommendations: set connection/request timeouts, use bounded retries with backoff for transient failures, avoid retrying unchanged invalid/authentication requests, and pause polling before quota exhaustion. These are integration recommendations, not additional provider requirements.

## Request examples

Examples use placeholders and historical dates for illustration. Replace the key, date, and IDs with values appropriate to the account and request. These examples have not been executed against the authenticated API.

### Find NHL teams

```bash
curl --get 'https://nhl.highlightly.net/teams' \
  --header 'x-rapidapi-key: YOUR_HIGHLIGHTLY_KEY' \
  --data-urlencode 'league=NHL'
```

### Find games on a Toronto calendar date

```bash
curl --get 'https://nhl.highlightly.net/matches' \
  --header 'x-rapidapi-key: YOUR_HIGHLIGHTLY_KEY' \
  --data-urlencode 'league=NHL' \
  --data-urlencode 'date=2026-01-15' \
  --data-urlencode 'timezone=America/Toronto' \
  --data-urlencode 'limit=100' \
  --data-urlencode 'offset=0'
```

### Retrieve one game's highlights through RapidAPI

```bash
curl --get 'https://nhl-ncaah-api.p.rapidapi.com/highlights' \
  --header 'x-rapidapi-key: YOUR_NHL_PRODUCT_KEY' \
  --header 'x-rapidapi-host: nhl-ncaah-api.p.rapidapi.com' \
  --data-urlencode 'matchId=REPLACE_WITH_MATCH_ID' \
  --data-urlencode 'limit=40'
```

`REPLACE_WITH_MATCH_ID` must be replaced with a numeric ID. For each returned clip, use its ID with `/highlights/geo-restrictions/{id}` to inspect playback and embedding restrictions, subject to plan access.

### Retrieve team aggregates through Highlightly All Sports

```bash
curl --get 'https://sports.highlightly.net/nhl/teams/statistics/REPLACE_WITH_TEAM_ID' \
  --header 'x-rapidapi-key: YOUR_HIGHLIGHTLY_KEY' \
  --data-urlencode 'fromDate=2025-10-01' \
  --data-urlencode 'timezone=America/Toronto'
```

## Documentation limitations and integration boundaries

- The OpenAPI file lists two dedicated NHL servers and marks both authentication headers required in operation parameters. The current authentication guide explicitly supplies four product/platform base URLs and limits the host header requirement to RapidAPI. This guide follows that platform-specific setup guidance.
- Examples in the specification include generic or cross-sport content and occasionally inconsistent example values. Treat schema examples as illustrations, not observed NHL data or coverage guarantees.
- Refresh intervals describe upstream update frequency. They do not guarantee that every optional field is available, that a clip remains playable, or that a requested historical season is covered.
- No documented endpoints expose shift data, line-combination performance, transactions, injuries as a dedicated feed, webhooks, or a full league catalogue. Highlight categories can include injury clips, but that does not establish a structured injury feed.
- No explicit historical-retention guarantee is supplied for most resources. Odds have the specific time window documented above. Exact subscription limits, licensing/redistribution permissions, and service-level guarantees must come from the applicable provider agreement; API access and the `VERIFIED` label do not establish those permissions.
- Provider enum strings in this guide describe the external payload only. Any future adoption into DynastyIQ requires explicit mappings and the applicable canonical architecture and enum documentation.
