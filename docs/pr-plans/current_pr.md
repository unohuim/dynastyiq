---
pr_id: 3
pr_name: pr3
status: Active
---

# Admin Imports and Player Triage UI Refactor PR Plan

Status: Active
Source: Admin control panel workflow review
Target branch: staging
Created: 2026-06-26
Last updated: 2026-06-26

## Goal

Refactor the admin control panel experience for running imports and triaging imported players so administrators can inspect import state, start supported import workflows, and resolve player identity issues from a calm, task-focused UI that follows the repository UI direction.

## Prerequisites

- Current Fantrax and CapWages identity adoption work has been reviewed by the human.
- Admin import sources remain registered through `AdminImports`.
- Admin import feedback remains exposed through the import broadcast stream.
- Player triage behavior remains protected by admin middleware.
- Player identity review should use the current identity architecture where applicable.

## Scope

- Refactor `resources/views/admin/imports.blade.php` toward the canonical operational UI direction.
- Refactor `resources/views/admin/player-triage.blade.php` toward the canonical operational UI direction.
- Organize admin import controls around supported workflows rather than raw implementation details.
- Surface import status, recent output, and retry affordances without adding full-page reload dependencies.
- Surface player triage queues for unmatched, candidate, conflicting, ignored, and matched provider identities where supported by the current backend.
- Preserve existing admin route names, middleware, gates, and command dispatch behavior.
- Move new or materially changed interactive behavior into page modules instead of executable Blade scripts.
- Add focused coverage for any changed admin import or player triage behavior.

## Out of Scope

- Rewriting import services or import command behavior.
- Adding new import providers.
- Changing queue, scheduler, Reverb, or broadcast infrastructure.
- Changing authorization semantics for admin access.
- Removing legacy Fantrax platform identity tables.
- Building a full bulk triage approval system unless explicitly approved during implementation.
- Introducing a SPA framework, client-side routing, or global JavaScript state.

## Architecture Impact

This PR should use existing documented architecture rather than introducing a new abstraction by default:

- `docs/architecture/admin/AdminImportRegistry.yaml`
- `docs/architecture/admin/AdminPlayerTriage.yaml`
- `docs/architecture/admin/ImportBroadcastStream.yaml`
- `docs/architecture/imports/PlayerIdentityResolution.yaml`
- `docs/architecture/ui/UIDesignAuthority.yaml`

If implementation introduces a reusable UI component or page-module pattern, document the durable invariant in the correct `docs/architecture/ui/` YAML file and update `docs/ARCHITECTURE_INVENTORY.md` as a derived summary.

## Implementation Plan

1. Implemented for review: Audit the existing admin import and player triage UI against `docs/UI_DESIGN.md` and `docs/ui_backlog.md`.
2. Implemented for review: Identify the current routes, controllers, middleware, and JavaScript entrypoints used by admin imports and player triage.
3. Implemented for review: Define the target admin control panel information architecture for imports, import feedback, and player triage queues.
4. Implemented for review: Refactor admin import UI to a flatter operational layout with explicit actions, loading states, empty states, and restrained status feedback.
5. Implemented for review: Refactor player triage UI to prioritize queue scanning, candidate comparison, explicit actions, and clear match-state feedback.
6. Implemented for review: Move new or materially changed page behavior into page modules and avoid executable Blade scripts.
7. Implemented for review: Preserve backend-owned authorization and existing route names.
8. Implemented for review: Add or update focused tests for changed behavior, including permission coverage where routes or actions are touched.
9. Implemented for review: Update `docs/ui_backlog.md` if this PR removes known UI deviations.

## Test Plan

- Admin import index remains protected from unauthorized access.
- Authorized admins can see supported import sources.
- Authorized admins can dispatch supported import workflows through existing routes.
- Import retry behavior remains available where currently supported.
- Import status/output rendering preserves the existing server contract.
- Player triage page remains protected from unauthorized access.
- Player triage actions preserve manual link, ignore, and defer behavior where those flows remain in scope.
- Changed UI payloads or page-module contracts are covered by deterministic frontend or feature tests as applicable.

## Decisions

- The admin control panel should remain a Blade-first Laravel surface with progressive enhancement.
- The server remains the source of truth for import status, player identity state, and authorization.
- The UI should be operational and dense enough for repeated admin work, not marketing-style or card-heavy.
- New interactive behavior should use page modules rather than executable Blade scripts.
- This PR should reduce existing UI debt where it touches known deviations, but should not widen scope into unrelated admin pages.
- Player triage is based on `player_external_identities` only; legacy `PlatformPlayerId` workflows are not used in this domain.
- Bulk triage is out of scope; triage actions are manual and identity-specific.
- The import page keeps current backend workflows while aligning action labels and layout with the admin control panel direction.
- Player identity auto-linking uses deterministic evidence tiers: exact normalized name is `75`, exact normalized name plus one strong context match is `85`, and exact normalized name plus birthdate or two strong context matches is `95`.
- Strong context matches currently include provider position type and team abbreviation against canonical player position type and team abbreviation.
- Position type matching compares goalies as `G`, defense as `D`, and `L/C/R/LW/RW/F` as `F`.
- Provider auto-link thresholds are NHL `85+`, Fantrax `85+`, and CapWages `75+`; multiple threshold-passing candidates become conflicts.
- CapWages identities are only upserted when the player detail payload has a payable non-buyout contract season key at least as recent as the current calendar-year season key.
- Eligible CapWages identities with current-or-future contract seasons and no canonical match create non-prospect canonical players with `nhl_id` null, link immediately, and materialize contracts.
- CapWages player detail profile data is stored in `capwages_players`, linked one-way to `player_external_identities`, and may mirror `player_id` after linkage.
- CapWages contracts and contract seasons are only written after a CapWages identity has a `player_id`.
- CapWages imports use sequential page crawling with inline player detail processing, no fixed delay on successful pages, and 403 backoff only when the provider blocks a request, while player detail imports prefer cached `capwages_players.raw_payload` before live provider detail requests.
- CapWages player detail 5xx responses and connection failures are recorded on the import run and skipped so one provider-side player detail failure does not fail the whole page import.
- Admin import cards read progress from `import_runs` progress fields and display processed/total records separately from raw terminal output for NHL, Fantrax, and CapWages imports.
- NHL import progress totals are dynamic because roster, prospect, and draft discovery determine unique player records during the run.
- Fantrax admin imports fetch the provider player list once, store it temporarily, and process records through bounded sequential chunk jobs so progress updates continue without a single long-running queue job.
- CapWages acquisition details are materialized into `nhl_player_transactions` as real hockey transaction history, separate from future fantasy transaction history.
- `cap:empty` removes CapWages imported profile rows, CapWages provider identities, and CapWages-sourced NHL player transactions while preserving canonical players and contracts.
- `fx:empty` removes Fantrax imported player rows and Fantrax provider identities while preserving canonical players and Fantrax league connection data.
- CapWages link handling materializes contracts from cached `capwages_players.raw_payload` without scheduling live refresh when cached payload exists; refresh jobs remain a fallback for linked identities without cached payload or blocked provider refreshes.
- When any identity link changes through `PlayerIdentityResolver::linkIdentityToPlayer()`, the resolver emits `PlayerExternalIdentityLinked`; CapWages listens to that event and queues a contract refresh job that re-fetches CapWages detail before materializing contracts.
- NHL team reference data is stored in `nhl_teams` from the NHL stats API and used to normalize provider team strings to NHL abbreviations for identity resolver evidence.
- NHL team reference import upserts by `abbrev` because the NHL stats team payload may include multiple source rows for the same current abbreviation.
- NHL player imports sync `nhl_teams` first and dispatch per-team roster/prospect discovery from `nhl_teams.abbrev` instead of a hardcoded team list or standings payload.
- NHL player discovery keeps the existing current-roster, previous-season roster, and prospects endpoints, then imports canonical player details through `player/{playerId}/landing`.
- NHL player discovery uses one shared import run id across all team jobs so duplicate roster/prospect player ids only enqueue one player detail job per run.
- NHL roster and prospect discovery also cache run-level normalized-name plus position-type fingerprints for duplicate protection against draft pick records that do not expose NHL player ids.
- NHL draft discovery runs after the roster/prospect discovery batch completes so it can rely on the completed run-level fingerprint cache.
- NHL draft discovery fetches configured recent draft years once per NHL import run, treats draft picks as name/position/team records, and creates minimal canonical prospect players with `nhl_id` null only when the name/position fingerprint was not already seen in the run.
- CapWages identity upsert reads birthdate from `personalInfo.birthDate` when present and normalizes full team names such as `Pittsburgh Penguins` through NHL team reference data before scoring.
- The triage UI displays current resolver recommendations separately from persisted match state so stale candidate rows can show updated confidence without mutating on page load.
- The default triage inbox focuses on current resolver recommendations below `75%` or identities with no confidence; explicit filters such as All may include higher-confidence identities.
- The triage detail pane does not expose generic manual action buttons; linking is done through explicit player or source-record link actions.
- Fantrax team aggregate rows, such as entries with `name` of `Team` or position markers of `Tm`, are skipped before identity upsert.
- The triage inbox includes a source provider filter that shows external identities from that provider with no canonical player link when used alone.
- Canonical player suggestions are only shown for identities that are not already linked to a canonical player.
- Linked identity detail renders from the player record perspective, not the selected provider identity perspective.
- Linked identity detail shows a user-facing player record label, DOB, team, position, NHL ID, status, and prospect state when available.
- Linked identity detail can show a compact current contract summary near the player name when the canonical player has CapWages contract data.
- The triage inbox can compare a source provider to a matching source provider through canonical `player_id`, showing either identities missing any matching-source identity or identities that already have one.
- In source-to-source comparison mode, triage list rows display coverage state rather than resolver recommendation state.
- In source-to-source comparison mode, linked selected identities still render the player record view and show linked external sources for that player.
- In source-to-source comparison mode, unlinked selected identities show matching-source identity suggestions and link actions instead of canonical player suggestions.
- In source-to-source comparison mode, unlinked selected identities show the matched matching-source identity instead of search or suggestions when coverage already exists.
- Source-to-source link actions support AJAX updates that preserve the selected source perspective without a full page refresh.
- The triage detail panel shows linked external identities that share the selected identity's `player_id`, regardless of source-filter perspective.
- The triage detail panel can show suggested unlinked external matches as supporting evidence even when canonical candidates exist.
- Suggested external matches are not directly actionable until the selected identity has a canonical `player_id`; after that, admins may link the suggested external identity to the same canonical player.
- When an unlinked external identity has no canonical candidate, admins can manually create a minimal canonical prospect player and optionally attach suggested unlinked external matches.
- Canonical players created from external identities keep `nhl_id` null and use `player_external_identities.player_id` as the durable linkage.
- Manual canonical player search excludes players already linked to the selected identity's provider and filters results by compatible position type when available.
- Matching-source suggestions only include unlinked identities from the matching source with the same normalized name and compatible position type as the selected source identity.
- Matching-source search results query `player_external_identities` for unlinked identities from the matching source and allow normalized-name variants while preserving compatible position type constraints.
- Admin control panel top-level navigation now exposes only Triage and Data Imports, with the full triage inbox embedded in the admin panel while preserving the standalone protected triage route.

## Resolved Questions

- Player triage focuses on `player_external_identities`; legacy identity workflows are out of scope for this domain.
- Bulk approve, bulk ignore, and bulk defer actions are deferred to later work.
- Import UI keeps the current backend workflow model in this PR and shares the admin panel with embedded triage.

## Deferred Work

- Full bulk triage operations.
- Provider-specific triage review pages.
- Import scheduling UI.
- Deep operational analytics for import reliability.
