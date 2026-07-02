---
pr_id: 4
pr_name: pr4
status: Archived
---

# Player Triage AJAX and SearchField Refactor PR Plan

Status: Archived
Source: Admin player triage workflow review
Target branch: staging
Created: 2026-06-26
Last updated: 2026-06-28

## Goal

Refactor the player triage page so once the page has loaded, triage work happens through AJAX/JSON interactions with in-place UI updates, while introducing a reusable Tailwind-styled JavaScript SearchField component that can be adopted by other pages later.

## Prerequisites

- PR3 admin player triage UI refactor has been reviewed by the human.
- Player triage continues to use `player_external_identities`.
- The triage page remains protected by existing admin middleware.
- Existing route names and authorization behavior remain stable unless explicitly approved.

## Scope

- Convert triage-page interactions after initial page load to AJAX/JSON.
- Add a reusable JavaScript SearchField component styled with Tailwind utilities.
- Use the SearchField component for triage search inputs.
- Trigger triage filter/search changes automatically without an Apply button dependency.
- Debounce search input changes, initially `300ms`.
- Update source, matching source, triage-state segment, search, inbox selection, detail loading, and triage actions in place.
- Preserve useful URL state for refresh, back, and bookmark behavior.
- Add focused feature and JavaScript coverage for the new JSON/page-module contracts.

## Out of Scope

- Removing Jetstream, Livewire, or Alpine globally.
- Replacing existing app-wide navigation, layout, toast, modal, or form systems.
- Creating a global frontend framework, router, registry, centralized store, or generic list/detail renderer.
- Changing import services, identity resolution rules, or provider matching thresholds.
- Adding bulk triage.
- Refactoring unrelated admin pages.

## Architecture Impact

This PR is expected to introduce or formalize a reusable frontend component pattern:

- `resources/js/components/SearchField/search-field.js`
- `resources/js/components/SelectField/select-field.js`
- `resources/js/admin/player-triage.js`
- `docs/architecture/ui/` entry for the durable SearchField/page-module contract, if approved during implementation
- `docs/ARCHITECTURE_INVENTORY.md` derived summary update after the canonical architecture file is added

Canonical architecture docs should be updated when this backlog PR becomes active and the abstraction is approved for implementation.

## Implementation Plan

1. Implemented for review: Audit current triage form, filter, search, row-selection, and action interactions for refresh paths.
2. Implemented for review: Define the SearchField component contract:
   - Tailwind-only styling.
   - `300ms` debounce by default.
   - clear/loading/disabled/error states.
   - DOM event payload with `name`, `value`, optional `scope`, and optional `id`.
3. Implemented for review: Define the triage page JSON contract for inbox, detail, matching-source search, canonical search, and actions.
4. Implemented for review: Add JSON responses/endpoints while preserving safe fallback behavior where practical.
5. Implemented for review: Refactor `player-triage.js` to own page state, request cancellation, URL updates, and DOM rendering.
6. Implemented for review: Replace Apply-dependent filter/search behavior with debounced or immediate AJAX changes.
7. Implemented for review: Ensure row selection and detail pane loading do not refresh the page.
8. Implemented for review: Ensure link, resolve, ignore, defer, and manual search actions update UI in place.
9. Implemented for review: Add focused tests for JSON contracts, request behavior, URL state, and SearchField events.
10. Implemented for review: Update canonical architecture docs and derived inventory once implementation details are approved.

## Test Plan

- Admin-only triage JSON endpoints reject unauthorized users.
- Triage filters return deterministic inbox payloads.
- Source/matching-source/triage-state changes update inbox payloads correctly.
- SearchField emits debounced named events with stable payload shape.
- Multiple SearchField instances on one page remain distinguishable by `name`, `scope`, and `id`.
- Stale requests are cancelled or ignored so late responses do not overwrite newer UI state.
- Matching-source link actions return JSON and update linked identity state.
- Canonical link, resolve, ignore, and defer actions return JSON and preserve expected identity state.
- URL query state is updated for filters, searches, and selected identity.
- Browser back/forward restores triage state.
- Empty, loading, and error states are visible and non-blocking.

## Decisions

- The reusable SearchField should be a straight JavaScript component, not Alpine-in-Blade.
- The SearchField should use Tailwind utility classes only.
- The SearchField must be generic and must not know about player triage, routes, players, or providers.
- The reusable SelectField should enhance native selects, emit only generic select-field events, and leave domain refresh events to page modules.
- Page modules own page-specific state, API calls, and rendering decisions.
- The triage inbox owns the currently loaded identity JSON and filters player search locally; broader server refreshes remain coordinated by the page module.
- The triage inbox count display distinguishes total matching identities from the browser-loaded slice when the server caps payloads.
- The triage inbox owns its loading and error rendering; the page module emits inbox loading, loaded, and error events around server refreshes.
- The triage detail panel owns the currently selected identity detail JSON and renders loading, loaded, empty, and error states from page-level events.
- The triage detail panel can render a selected identity preview header immediately from the inbox selection event before full detail JSON resolves.
- Row selection should fetch selected identity detail through a dedicated web-authenticated admin JSON route, while the full triage route remains available for Blade page loads and compatibility refreshes.
- Triage AJAX should use request cancellation or stale-response guards.
- The old apply/reset and checkbox filters should be replaced by immediate controls, including an unmatched/matched/all triage-state segment that defaults to unmatched.
- URL state should be preserved for refresh, back, and bookmark behavior.
- This PR should chip away from Jetstream/Livewire coupling without attempting a global removal.

## Resolved Questions

- The first reusable component is SearchField.
- One responsive SearchField component should serve mobile and desktop.
- Multiple SearchField instances should be distinguished by event payload fields, not by component-specific global state.
- Triage page only is in scope; other pages should be able to adopt the SearchField later.

## Deferred Work

- Full Jetstream removal.
- Full Livewire removal.
- App-wide component registry or frontend platform decisions.
- Replacing existing app-wide toast, modal, or layout systems.
