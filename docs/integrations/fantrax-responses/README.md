# Fantrax Response Semantics

This directory records observed Fantrax response fields and response shapes that affect DynastyIQ behavior.

When DynastyIQ consumes a Fantrax endpoint and a new meaningful response data point is discovered, update the matching endpoint file with:

- Sample response, sanitized when copied from a real account.
- Field path and observed type or shape.
- Observed values.
- Semantic meaning.
- Parser contract and normalization rules sufficient to implement the endpoint without re-reading raw samples.
- Expected normalized output when the endpoint feeds first-party services.
- DynastyIQ usage.
- Observations for DynastyIQ product, commissioner, community, or user-planning opportunities.
- What the field must not be used for.
- Schema, import, roster, draft, community, or UI implications.
- Open verification questions.

Raw response samples may live in `docs/api_responses/`, but these files explain what the responses mean for product behavior.

## Endpoint Files

- `source-map.md`: Fantrax-specific data needs mapped to authoritative endpoint sources.
- `get-leagues.md`: Fantrax user secret ID league discovery.
- `get-league-info.md`: League setup, roster constraints, player pools, periods, draft settings, and scoring configuration.
- `get-team-rosters.md`: Current roster membership, slot/status, and optional salary/contract metadata.
- `get-draft-picks.md`: Future draft assets and current draft-order pick rows.
- `get-draft-results.md`: Draft lifecycle metadata and made-player pick rows.
- `get-player-ids.md`: Broad Fantrax NHL player identity pool and optional external ids.

## Planned Endpoint Files

- `get-adp.md`
- `get-standings.md`
- `get-player-profile.md`
