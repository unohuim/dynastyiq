---
pr_id: 5
pr_name: pr5
status: Active
---

# Yahoo Fantasy Hockey Player Import PR Plan

Status: Active
Source: Future fantasy provider expansion
Target branch: staging
Created: 2026-06-27
Last updated: 2026-06-28

## Goal

Add a Yahoo fantasy hockey player import so DynastyIQ can ingest Yahoo player records, preserve Yahoo provider identities, and use the existing player identity resolver and admin import progress patterns to match Yahoo players to canonical DynastyIQ players.

## Prerequisites

- PR3 admin imports and player triage work has been reviewed by the human.
- The Yahoo Fantasy Sports API access model, OAuth requirements, rate limits, and player endpoint contracts have been verified before implementation.
- Yahoo provider credentials and tokens have an approved storage model before any live API calls are implemented.
- Player identity resolution continues to use `player_external_identities`.
- Import progress for admin-triggered imports continues to use `import_runs`.

## Scope

- Add Yahoo as a fantasy hockey provider for player identity import.
- Fetch Yahoo fantasy hockey player records from an approved Yahoo API path.
- Normalize Yahoo player names, provider player ids, positions, teams, and raw payloads.
- Upsert Yahoo player records into `player_external_identities` as a non-authority provider.
- Resolve Yahoo identities through `PlayerIdentityResolver` without creating canonical players by default.
- Add Yahoo player import progress tracking through `import_runs`.
- Add Yahoo to the admin import registry only after the backend import contract exists.
- Surface Yahoo unmatched/candidate/conflict identities through the existing triage provider filters.
- Add a provider empty command only if implementation creates Yahoo-owned imported records outside `player_external_identities`.

## Out of Scope

- Yahoo league sync, team sync, roster sync, standings, scoring settings, transactions, or user league authorization flows.
- Replacing Fantrax fantasy integration behavior.
- Creating canonical players from Yahoo-only identities.
- Yahoo league/team/roster connection UI beyond the account drawer OAuth entry point.
- Adding app-wide provider abstraction beyond the minimum Yahoo import surface.
- Changing existing Fantrax, NHL, or CapWages matching thresholds.
- Bulk triage operations.

## Architecture Impact

This PR is expected to require a new Yahoo integration architecture entry if implementation introduces durable Yahoo-specific services or jobs:

- Candidate canonical doc: `docs/architecture/integrations/YahooFantasyPlayerImport.yaml`
- Existing docs that must remain respected:
  - `docs/architecture/admin/AdminImportRegistry.yaml`
  - `docs/architecture/admin/ImportBroadcastStream.yaml`
  - `docs/architecture/imports/PlayerIdentityResolution.yaml`
  - `docs/architecture/integrations/PlatformStateService.yaml`

If Yahoo adds new provider keys, identity statuses, or import keys, `docs/ENUMS.md` must be updated before those values are used.

## Implementation Plan

1. Verify Yahoo Fantasy Sports API authentication, player listing endpoints, pagination, and fantasy hockey player payload fields.
2. Decide whether Yahoo player import is app-owned or user-owned based on the verified API authentication model.
3. Define the minimal Yahoo provider player payload fields needed for identity matching:
   - provider player id.
   - display name.
   - first and last name when available.
   - eligible positions.
   - NHL team abbreviation when available.
   - player status or injury metadata when available.
   - raw payload.
4. Add a Yahoo player import service that fetches player records and maps them into normalized identity payloads.
5. Add `PlayerIdentityResolver::upsertYahooIdentity()` or an approved provider-neutral upsert path if one already exists by implementation time.
6. Treat Yahoo as a non-authority provider: auto-link only to exactly one canonical player at or above the approved provider threshold.
7. Add a queued Yahoo player import job or bounded chunk job if the provider payload can exceed worker timeout limits.
8. Track total, processed, imported, failed, and skipped records through `import_runs`.
9. Register Yahoo in `AdminImports` after the import command/job can report progress safely.
10. Ensure triage provider filters include Yahoo identities without special-case UI behavior.
11. Add focused Pest and JavaScript coverage for the new import command/job, resolver behavior, admin import registration, and progress contract.
12. Update canonical architecture docs and the derived architecture inventory after the implementation surface is approved.

## Test Plan

- Yahoo player payloads are normalized into stable provider identity fields.
- Yahoo aggregate or non-player rows are skipped before identity upsert.
- Yahoo identity upsert is idempotent by provider and provider player id.
- Yahoo identity raw payloads are preserved for audit and rematching.
- Yahoo identities do not create canonical players by default.
- Yahoo identities auto-link only when resolver evidence identifies exactly one canonical player at the approved threshold.
- Multiple viable Yahoo candidates become conflicts rather than auto-links.
- Position type matching uses the canonical position type rule: goalies `G`, defense `D`, and `L/C/R/LW/RW/F` as `F`.
- Team abbreviations are normalized through NHL team reference data when available.
- Admin-triggered Yahoo imports create and update `import_runs` progress fields.
- Yahoo import failures record failed/skipped counts without failing the whole import when individual player rows are bad.
- The admin import registry exposes Yahoo only to authorized admin users.
- Triage provider filters can show Yahoo unmatched, candidate, conflict, ignored, and matched identities.

## Decisions

- Yahoo is a non-authority fantasy provider for player identity matching.
- Yahoo Fantasy Sports API requires OAuth 2.0 and Fantasy Sports Read access.
- Yahoo Fantasy API responses should be treated as XML first.
- Yahoo `nhl` game code should be used for current-season proof calls before resolving the season-specific game id from returned metadata.
- Yahoo player keys follow `{game_key}.p.{player_id}` and pagination uses semicolon parameters such as `;start=0;count=5`.
- Yahoo player import should reuse the existing player identity resolver rather than creating a parallel matching system.
- Yahoo import progress should use `import_runs`; Reverb events may nudge the UI, but the database remains authoritative.
- Yahoo raw payloads should remain provider-owned evidence and must not overwrite canonical player attributes without an explicit resolver rule.
- Yahoo league and roster sync are separate future work from importing the Yahoo player universe.
- Yahoo OAuth credentials should live in `config/services.php`; Yahoo API URLs and import defaults should live in `config/yahoo.php`.
- The Yahoo OAuth flow exchanges a Yahoo code, calls `game/nhl`, fetches `game/nhl/players;start=0;count=5`, and persists the current user's Yahoo OAuth grant in `yahoo_fantasy_connections`.
- Yahoo player imports should stage Yahoo player collection pages into `yahoo_players` using the current super-admin user's persisted Yahoo connection, without creating canonical players.
- Yahoo player collection pagination should use the resolved season game key and Yahoo semicolon parameters, such as `game/{game_key}/players;start=0;count=25`.
- Yahoo admin-triggered all-player imports should queue page-level jobs and continue until Yahoo returns a short or empty player collection page.
- The Yahoo Players admin import card should use the direct endpoint backed by a persisted Yahoo connection to create an `import_runs` row and queue the first page job.
- Staged `yahoo_players` rows should immediately upsert `player_external_identities` provider rows with provider `yahoo`, then resolve them as non-authority identities at the same auto-link threshold as Fantrax.
- `yahoo:empty` removes Yahoo staged player rows and Yahoo provider identities while preserving canonical players and Yahoo OAuth connections.

## Resolved Questions

- The first Yahoo PR should import player identities only.
- Yahoo should be visible in triage as another external identity source once imported.
- Canonical player creation from Yahoo-only identities is out of scope.

## Deferred Work

- Yahoo fantasy league sync.
- Yahoo fantasy team and roster sync.
- Yahoo fantasy transactions.
- Yahoo availability filters for connected leagues.
- Provider-neutral fantasy platform import abstraction, if Yahoo and Fantrax duplication later justifies one.
