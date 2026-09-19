# OpenAI NHL Lineup Discovery

DynastyIQ uses the OpenAI Responses API as a bounded discovery tool for anticipated NHL lineups. Laravel owns scheduling, validation, persistence, player resolution, consensus, and prediction selection.

## Configuration

Set these values in the deployment environment:

```dotenv
OPENAI_API_KEY=
OPENAI_LINEUP_MODEL=gpt-5.6-terra
OPENAI_LINEUP_MAX_TOOL_CALLS=6
OPENAI_LINEUP_MAX_OUTPUT_TOKENS=6000
OPENAI_LINEUP_DAILY_SEARCH_LIMIT=200
OPENAI_TIMEOUT_SECONDS=120
```

The API key must be stored as a deployment secret and must never be committed. API billing is separate from ChatGPT billing. Configure an OpenAI project spending limit before enabling automatic sync.

## Request

`POST https://api.openai.com/v1/responses`

Headers:

```http
Authorization: Bearer OPENAI_API_KEY
Content-Type: application/json
```

DynastyIQ supplies:

- A configurable model.
- The built-in `web_search` tool.
- A strict JSON schema for observations, sources, engagement, and player slots.
- A maximum tool-call count and output-token count.
- `store: false`.
- Previously successful sources for the team plus instructions to try another source.

The importer accepts only observations with attributable post text and normalized player rows. Image-only posts are skipped. Returned URLs and post text remain evidence rather than trusted facts until local consensus evaluation.

## Cost controls

Each response writes token and web-search counts to `integration_api_usage_logs`. Before a request, DynastyIQ sums the current day's lineup search calls and refuses work that would exceed `OPENAI_LINEUP_DAILY_SEARCH_LIMIT`.

The default maximum of 200 searches per day corresponds to at most $2.00 per day in web-search tool charges at a price of $10 per 1,000 calls, excluding model tokens. Confirm current pricing in the official OpenAI documentation before changing limits.

- Responses API: <https://developers.openai.com/api/reference/cli/resources/responses/methods/create>
- API pricing: <https://developers.openai.com/api/docs/pricing>

## Scheduling

The Admin Player Imports panel exposes Anticipated Lineups with two independent base-second intervals:

- `within_two_hours`: defaults to 900 seconds.
- `outside_two_hours`: defaults to 3600 seconds.

Only today's future games are eligible. Processing stops for a game at puck drop. Manual Run Now uses all remaining games today.

## Failure behavior

- Missing credentials fail the affected team job without deleting current truth.
- HTTP, schema, parsing, or limit failures are reported and counted on the import run.
- Partial and unresolved observations remain auditable but cannot drive predictions.
- A prediction falls back to the existing projected-roster process unless a corroborated lineup has eighteen uniquely resolved skaters.
