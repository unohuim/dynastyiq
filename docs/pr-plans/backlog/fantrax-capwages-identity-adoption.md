# Fantrax and CapWages Identity Adoption PR Plan

Status: Backlog
Source: Multi-provider player import review
Target branch: staging
Created: 2026-06-26
Last updated: 2026-06-26

## Goal

Adopt the player identity workflow for Fantrax and CapWages imports so imported player-like records become matched, candidate, or unmatched provider identities instead of being silently skipped, loosely linked, or over-applied to canonical players.

## Prerequisites

- `player_external_identities` exists.
- `PlayerIdentityNormalizer` exists.
- `PlayerIdentityResolver` exists.
- NHL imports upsert external identities.
- Player identity statuses are documented in `docs/ENUMS.md`.

## Scope

- Refactor Fantrax player imports to upsert external identities.
- Refactor CapWages player imports to upsert external identities.
- Auto-link only high-confidence Fantrax and CapWages matches.
- Leave ambiguous matches as candidates.
- Leave insufficient matches as unmatched with reason codes.
- Ensure CapWages contract updates only apply after a resolved canonical player exists.
- Add reporting for matched, candidate, unmatched, ignored, and conflict counts by provider.

## Out of Scope

- EliteProspects implementation.
- Full manual triage UI rebuild.
- Removing legacy Fantrax player tables or platform identity tables.
- Changing user-facing league roster behavior unless required by identity adoption.
- Rewriting unrelated Fantrax league/team sync behavior.

## Architecture Impact

This PR should use the `PlayerIdentityResolution` abstraction created by the foundation PR. If new durable rules are introduced, update:

- `docs/architecture/imports/PlayerIdentityResolution.yaml`
- `docs/ARCHITECTURE_INVENTORY.md`
- `docs/ENUMS.md`
- `docs/DB_SCHEMA.md`

## Implementation Plan

1. Remove or quarantine debug-stop behavior from Fantrax import paths.
2. Map Fantrax player payloads into external identity records.
3. Run resolver for each Fantrax identity.
4. Auto-link Fantrax identities only when confidence is high.
5. Convert ambiguous Fantrax matches into candidate records or candidate status.
6. Map CapWages player payloads into external identity records.
7. Run resolver before contract writes.
8. Apply contract data only to resolved canonical players.
9. Add provider-level identity audit reporting.

## Test Plan

- Fantrax import upserts external identities.
- Fantrax known provider ID links to an existing canonical player.
- Fantrax exact normalized name plus birthdate auto-links.
- Fantrax ambiguous names become candidates.
- Fantrax insufficient data remains unmatched with a reason.
- CapWages import upserts external identities.
- CapWages contract data is not applied without a resolved player.
- CapWages matched identity applies contract data to the linked canonical player.
- Re-running Fantrax and CapWages imports is idempotent.
- Provider audit counts include matched, candidate, unmatched, ignored, and conflict statuses.

## Decisions

- Fantrax is a fantasy-platform authority, not a canonical hockey identity authority.
- CapWages is a contract authority, not a canonical hockey identity authority.
- Fantrax and CapWages should enrich canonical players only after identity resolution.

## Open Questions

- Should low-confidence candidates be stored in a separate candidates table or represented only through identity status and metadata?
- What confidence threshold should Fantrax and CapWages use for automatic linking?
- Which unmatched reason codes are required for useful review of the current 7300 unmatched players?

## Deferred Work

- Manual triage UI improvements.
- Bulk approve/reject workflows for candidate matches.
- EliteProspects enrichment for the long-tail unmatched pool.

