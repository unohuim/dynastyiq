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

1. Audit the existing admin import and player triage UI against `docs/UI_DESIGN.md` and `docs/ui_backlog.md`.
2. Identify the current routes, controllers, middleware, and JavaScript entrypoints used by admin imports and player triage.
3. Define the target admin control panel information architecture for imports, import feedback, and player triage queues.
4. Refactor admin import UI to a flatter operational layout with explicit actions, loading states, empty states, and restrained status feedback.
5. Refactor player triage UI to prioritize queue scanning, candidate comparison, explicit actions, and clear match-state feedback.
6. Move new or materially changed page behavior into page modules and avoid executable Blade scripts.
7. Preserve backend-owned authorization and existing route names.
8. Add or update focused tests for changed behavior, including permission coverage where routes or actions are touched.
9. Update `docs/ui_backlog.md` if this PR removes known UI deviations.

## Test Plan

- Admin import index remains protected from unauthorized access.
- Authorized admins can see supported import sources.
- Authorized admins can dispatch supported import workflows through existing routes.
- Import retry behavior remains available where currently supported.
- Import status/output rendering preserves the existing server contract.
- Player triage page remains protected from unauthorized access.
- Player triage actions preserve existing link, variant, and defer behavior where those flows remain in scope.
- Changed UI payloads or page-module contracts are covered by deterministic frontend or feature tests as applicable.

## Decisions

- The admin control panel should remain a Blade-first Laravel surface with progressive enhancement.
- The server remains the source of truth for import status, player identity state, and authorization.
- The UI should be operational and dense enough for repeated admin work, not marketing-style or card-heavy.
- New interactive behavior should use page modules rather than executable Blade scripts.
- This PR should reduce existing UI debt where it touches known deviations, but should not widen scope into unrelated admin pages.

## Open Questions

- Should the player triage UI focus first on `player_external_identities`, legacy `PlatformPlayerId`, or a combined transition view?
- Should bulk approve, bulk ignore, or bulk defer actions be included in this PR or deferred to a later triage workflow PR?
- Which import run history fields are most useful for day-to-day admin review?

## Deferred Work

- Full bulk triage operations.
- Provider-specific triage review pages.
- Import scheduling UI.
- Deep operational analytics for import reliability.
