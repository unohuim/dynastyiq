# MLB Draft Response

## Endpoint

| Field | Value |
| --- | --- |
| Method | `GET` |
| Base URL | `https://statsapi.mlb.com/api` |
| Path Template | `/draft{prospects}{year}{latest}` |
| Registry Key | `draft` |
| Category | `Awards And Draft` |
| Example URL | `https://statsapi.mlb.com/api/v1/draft/2024` |

## Parameters

Required parameter sets: None documented by the registry.

| Parameter | Notes |
| --- | --- |
| `limit` | Provider-supported query parameter; verify accepted values before implementation. |
| `fields` | Provider-supported query parameter; verify accepted values before implementation. |
| `round` | Provider-supported query parameter; verify accepted values before implementation. |
| `name` | Provider-supported query parameter; verify accepted values before implementation. |
| `school` | Provider-supported query parameter; verify accepted values before implementation. |
| `state` | Provider-supported query parameter; verify accepted values before implementation. |
| `country` | Provider-supported query parameter; verify accepted values before implementation. |
| `position` | Provider-supported query parameter; verify accepted values before implementation. |
| `teamId` | Provider-supported query parameter; verify accepted values before implementation. |
| `playerId` | Provider-supported query parameter; verify accepted values before implementation. |
| `bisPlayerId` | Provider-supported query parameter; verify accepted values before implementation. |

## Registry Notes

- No query parameters are honored when "latest" endpoint is queried (year is still required). Prospects and Latest cannot be used together.

## Purpose

Document candidate MLB awards and draft endpoints for historical and prospect context.

## Observations For DynastyIQ

- This document is generated from public endpoint registries and has not been validated against a captured provider response sample.
- The MLB Stats API root `https://statsapi.mlb.com/api/v1` is not a usable endpoint by itself; call a concrete path.
- Preserve provider field names in raw-response docs. Normalize only inside implementation contracts after samples are reviewed.
- Treat `sportId=1` as the normal MLB filter where the endpoint supports `sportId` or `sportIds`.

## Candidate Source Authority

| Need | Candidate Use | Must Not Drive |
| --- | --- | --- |
| Discovery | Candidate endpoint for MLB data discovery or lookup after sample validation. | Production imports without retry/backoff and response-shape tests. |
| Identity | Candidate source for provider ids and display context when the response includes people/team objects. | Canonical DynastyIQ identity without cross-source matching rules. |
| Stats | Candidate source for aggregate or game-scoped stats when the response exposes stat fields. | Fantasy scoring, projections, or validation rules before field semantics are documented. |

## Parser Contract

- Do not treat this generated document as final endpoint authority until a real sample is captured and reviewed.
- Capture representative responses before adding a first-party importer or UI consumer.
- Add field maps for any persisted, displayed, or derived values.
- Keep request parameters explicit and bounded.
- Add tests around normalized DynastyIQ output rather than provider payload shape wholesale.

## Expected Normalized Output

No current normalized output.

## Open Verification Questions

- Which top-level response keys are present for successful requests?
- Which parameters are required in practice for MLB-only data?
- Does the response shape vary by season, game type, league, team, player, or postseason context?
- Which fields should DynastyIQ persist, display only, derive from, or ignore?
