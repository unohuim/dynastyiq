---
pr_id: 1
pr_name: pr1
status: Archived
---

# Player Identity Resolution Foundation PR Plan

Status: Archived
Source: Multi-provider player import review
Target branch: staging
Created: 2026-06-26
Last updated: 2026-06-26

## Goal

Introduce the shared player identity foundation required for reliable NHL, Fantrax, CapWages, and future EliteProspects imports without changing every importer at once.

## Scope

- Add an external player identity schema for provider-sourced player records.
- Add model and service scaffolding for player identity normalization and resolution.
- Keep NHL as an authority provider that may create canonical `players` records.
- Refactor NHL player import to upsert an external identity before creating or updating canonical players.
- Preserve existing public interfaces and existing import commands unless explicitly changed in implementation.
- Add focused tests for NHL identity upsert, canonical player creation, canonical player update, idempotency, and raw payload preservation.

## Out of Scope

- Fantrax importer adoption of the identity workflow.
- CapWages importer adoption of the identity workflow.
- EliteProspects integration.
- Full admin triage redesign.
- Removing legacy columns such as `players.nhl_id`.
- Reworking unrelated NHL game/stats import stages.

## Architecture Impact

This PR introduces a new reusable abstraction for player identity resolution. Before implementation, add:

- `docs/architecture/imports/PlayerIdentityResolution.yaml`

Update derived or canonical docs as applicable:

- `docs/ARCHITECTURE_INVENTORY.md`
- `docs/ENUMS.md`
- `docs/DB_SCHEMA.md`

## Schema Changes

Proposed minimum table:

```text
player_external_identities
- id
- player_id nullable
- provider
- provider_player_id
- provider_slug nullable
- display_name
- normalized_name
- first_name nullable
- last_name nullable
- birthdate nullable
- position nullable
- team nullable
- raw_payload json nullable
- match_status
- match_confidence nullable
- unmatched_reason nullable
- first_seen_at
- last_seen_at
- timestamps
```

Expected initial `match_status` values:

- `matched`
- `candidate`
- `unmatched`
- `ignored`
- `conflict`

These values must be documented in `docs/ENUMS.md` before use.

## Implementation Plan

1. Implemented for review: Document the `PlayerIdentityResolution` architecture.
2. Implemented for review: Add the additive migration for external player identities.
3. Implemented for review: Add the `PlayerExternalIdentity` model.
4. Implemented for review: Add `PlayerIdentityNormalizer` for name and provider-field normalization.
5. Implemented for review: Add `PlayerIdentityResolver` with conservative result objects.
6. Implemented for review: Refactor NHL player import so every NHL player payload upserts an external identity.
7. Implemented for review: Preserve NHL authority behavior: linked NHL identities update canonical players; new NHL identities may create canonical players.
8. Implemented for review: Add an audit/query method for provider identity counts by status.

## Test Plan

- NHL import creates a `player_external_identities` row.
- Re-running the same NHL import updates the identity instead of duplicating it.
- A new NHL identity can create a canonical player.
- A linked NHL identity updates the existing canonical player.
- Raw provider payload is preserved.
- Normalized names are stable for casing, punctuation, spacing, and accents.
- Match status and confidence are persisted for resolver outcomes.

## Decisions

- NHL remains an authority source for active NHL player identity.
- Non-authority providers should not freely create canonical players in this first PR.
- Legacy player fields stay in place during the transition.
- `provider`, `match_status`, and `unmatched_reason` are string-backed documented values instead of database enums.
- `provider_player_id` uniqueness is scoped to provider.
- Raw provider payloads are preserved for audit and future rematching.

## Open Questions

- Should raw payload retention later get a pruning policy after the rematching workflow stabilizes?

## Deferred Work

- Fantrax identity workflow adoption.
- CapWages identity workflow adoption.
- Candidate and conflict triage UI.
- EliteProspects provider integration.

## Archive Notes

- Archive this file to `docs/pr-plans/archive/YYYY-MM-DD-player-identity-resolution-foundation.md` when the PR is merged, closed, or abandoned.
- After archiving, promote the next selected backlog plan into `docs/pr-plans/current_pr.md`.
