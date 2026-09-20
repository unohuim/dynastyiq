# OpenAI NHL Lineup Discovery

DynastyIQ uses the OpenAI Responses API as a bounded discovery tool for anticipated NHL lineups. Laravel owns scheduling, validation, persistence, player resolution, consensus, and prediction selection.

## Configuration

Set these values in the deployment environment:

```dotenv
OPENAI_API_KEY=
OPENAI_LINEUP_MODEL=gpt-5.6-terra
OPENAI_LINEUP_MAX_TOOL_CALLS=6
OPENAI_LINEUP_MAX_OUTPUT_TOKENS=6000
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

DynastyIQ first sends an unrestricted web-search request and supplies:

- A configurable model.
- The built-in `web_search` tool.
- A strict JSON schema for observations, sources, engagement, and player slots.
- A maximum tool-call count and output-token count.
- `store: false`.
- Previously successful sources for the team plus instructions to try another source.

If that pass leaves the team without a reportable lineup, DynastyIQ immediately
sends a second request whose web-search tool uses
`filters.allowed_domains: ["x.com"]`. When the general pass produces one source,
the X-only corroboration pass waits until both teams in the game are reported.
A single request may return two or more matching sources, in which case no
follow-up request is needed.

The importer accepts only observations with attributable post text and normalized player rows. Image-only posts are skipped. Returned URLs and post text remain evidence rather than trusted facts until local consensus evaluation.

Team requests are serialized through a shared queue lock. Temporary OpenAI 429
responses release the team job for the provider's `Retry-After` duration, or a
bounded exponential delay when that header is absent.

## Usage tracking

Each response writes token and web-search counts to `integration_api_usage_logs`.
DynastyIQ does not impose its own daily search ceiling; OpenAI project billing
and rate limits remain the external safeguards.

- Responses API: <https://developers.openai.com/api/reference/cli/resources/responses/methods/create>
- API pricing: <https://developers.openai.com/api/docs/pricing>

## Scheduling

When sync is enabled, the main scheduler evaluates eligibility every second.
The Admin Player Imports panel exposes Anticipated Lineups with two independent
base-second intervals that determine when actual searches are due:

- `within_two_hours`: defaults to 900 seconds.
- `outside_two_hours`: defaults to 3600 seconds.

Today's and tomorrow's future games are eligible outside two hours. The
within-two-hours lane applies independently to each same-day game's puck-drop
time. A game that already started today remains eligible when lineup coverage
is missing. Started missing games are handled before later games.

Searches prioritize coverage. A team is reported once twelve forwards and six
defensemen are present; goalies are optional supplemental starting-goalie
evidence. While either team is missing, only missing teams are searched. After
both teams are reported, teams with only one matching source may be searched
again. A team with two matching independent sources is no longer searched.

## Failure behavior

- Missing credentials fail the affected team job without deleting current truth.
- HTTP, schema, parsing, or limit failures are reported and counted on the import run.
- Partial and unresolved observations remain auditable but cannot drive predictions.
- A prediction falls back to the existing projected-roster process unless a corroborated lineup has eighteen uniquely resolved skaters.
