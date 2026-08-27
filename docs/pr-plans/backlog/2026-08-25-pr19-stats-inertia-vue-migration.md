---
pr_id: 19
pr_name: pr19
status: Backlog
created: 2026-08-25
last_updated: 2026-08-25
---

# Stats Inertia Vue Migration

## Objective

Move the public `/stats` page from a Blade-delivered JavaScript mount to an
Inertia/Vue page while preserving the existing stats payload contract, visual
output, filtering behavior, sorting behavior, and JSON refresh flow.

## Scope

- Add the first app-level Inertia/Vue wiring needed for staged page migration.
- Convert `StatsController::index()` to render an Inertia `Stats/Index` page.
- Keep the `/stats` route name as `stats.index`.
- Keep the existing stats JSON payload endpoint for filter and display events.
- Reuse the current stats renderer as a Vue compatibility mount for the first
  migration pass.
- Migrate mobile player-card list rendering to scoped Vue components while the
  existing stats shell continues to own controls, filtering, sorting, and data
  refresh.
- Migrate desktop player-row rendering to scoped Vue components while
  the existing stats shell continues to own desktop controls, headers,
  filtering, sorting, and data refresh.
- Update architecture and convention documentation to authorize staged
  Inertia/Vue adoption.
- Add focused route/payload contract coverage without running tests or CI.

## Out Of Scope

- Rebuilding the full stats UI as native Vue components.
- Moving desktop controls, sticky headers, filters, or split-pane scroll hints
  into native Vue components in this slice.
- Changing stats row semantics, perspective semantics, filter schemas, sorting,
  or fantasy ownership decoration.
- Migrating other application routes to Inertia/Vue.
- Removing Blade, Alpine, Livewire, or the existing stats JavaScript modules.
- Consolidating duplicate `/api/stats` route definitions.

## Acceptance Criteria

- `/stats` returns an Inertia `Stats/Index` page.
- The Inertia page receives the same effective page configuration previously
  written into `window.__statsPageConfig`.
- The first render uses the existing backend-built stats payload.
- Filter, sort, display, and date-range interactions continue to request JSON
  payloads from the stats endpoint.
- Mobile player cards render through Vue components using the existing stats
  payload and formatting utilities.
- Desktop player rows render through Vue components using the existing
  stats payload and formatting utilities.
- Existing Blade/Alpine pages continue using the app JavaScript entrypoint.
- Documentation describes the staged Inertia/Vue boundary and preserves backend
  ownership of stats semantics.
