---
pr_id: 10
pr_name: pr10
status: Active
created: 2026-07-01
last_updated: 2026-07-01
---

# Stats Page JS Overhaul

## Source

Follow-up from PR 9 mobile/non-desktop stats rendering failures and architectural review of the `/stats` page perspective-to-render flow.

## Objective

Rebuild the `/stats` page as an isolated JSON-driven stats experience with separate mobile and non-mobile JavaScript renderers, a dedicated stats page layout that avoids Livewire and Jetstream page shell dependencies, and a polished loading experience for perspective changes.

## Product Direction

The stats page should keep separate mobile and non-mobile renderers.

The goal is not to force one fully responsive table/card component. Mobile and desktop stats views have different interaction models and should be implemented as distinct JavaScript components that consume the same normalized JSON payload.

Preserve the current visual direction:

- Keep the existing mobile card visual language where practical.
- Keep the existing desktop stats table visual language where practical.
- Keep mobile bottom navigation.
- Keep the profile/account drawer.
- Replace or isolate the desktop top bar if it depends on Jetstream, Livewire, or the current app layout shell.

## Current Problems

The current `/stats` page is fragile because state and rendering are split across too many owners:

- `StatsController@index` builds initial payload server-side.
- Blade embeds `window.__stats` and an inline Alpine `statsPage()` controller.
- Alpine owns perspective controls, filter state, URL updates, API fetches, and `statsUpdated` dispatches.
- Separate vanilla JavaScript owns desktop/mobile rendering and sort state.
- Rendering depends on an empty `#stats-page` mount point and the global app bundle loading successfully.
- The current page uses `<x-app-layout>`, which brings Jetstream and Livewire concerns into a data-heavy stats page.

This makes perspective changes, mobile rendering, asset failures, and first-paint timing difficult to reason about.

## Target Architecture

Create a stats-only page architecture:

- A new stats-specific layout, such as `resources/views/layouts/stats.blade.php`.
- A new stats page view, such as `resources/views/stats/index.blade.php`.
- A dedicated stats JavaScript entry or boot module, such as `resources/js/pages/stats-page.js`.
- A single JavaScript shell/controller that owns all `/stats` state.
- Separate renderer components for mobile and non-mobile views.

The new stats layout must not consume:

- `<x-app-layout>`
- Livewire styles/scripts
- Jetstream banner
- Jetstream modal stacks
- Any page-level Livewire dependency

The new stats layout should keep:

- Basic HTML/head metadata.
- CSRF meta.
- Required Vite assets.
- Mobile bottom navigation.
- Profile/account drawer.
- A lightweight desktop navigation/header if the existing desktop top bar is Jetstream/Livewire-coupled.

Do not delete old layouts, views, or components during this PR. Route `/stats` to the new isolated view and leave old files in place unless a scoped cleanup is explicitly approved.

## JavaScript State Ownership

The dedicated stats shell should own:

- `perspective`
- `period`
- `season_id`
- `game_type`
- `filters`
- `sortKey`
- `sortDirection`
- `viewportMode`
- `payload`
- `loading`
- `error`

The shell should:

- Read the initial JSON payload from the page.
- Normalize the payload once.
- Render controls and the correct renderer.
- Fetch `/api/stats` on perspective or server-backed filter changes.
- Update the URL.
- Show loading states.
- Delegate display to the mobile or non-mobile renderer.

Avoid the current global `statsUpdated` event bridge for the rebuilt page.

## Renderer Requirements

Create or adapt two dedicated renderers:

- `StatsDesktopRenderer`
- `StatsMobileRenderer`

Both renderers should receive plain JSON and callbacks. They should not fetch data or own page-level state.

Expected renderer inputs:

- `rows`
- `columns`
- `settings`
- `state`
- `controls`
- callbacks such as `onSortChange`, `onFilterChange`, and `onPerspectiveChange`

Local filtering and sorting should be fast and should operate against the already-loaded JSON rows whenever possible. Perspective changes should fetch fresh JSON.

## Loading Experience

Perspective changes must have a deliberate styled loading experience.

Requirements:

- Never show a blank stats area.
- Keep perspective/navigation controls usable or visibly disabled with clear loading affordance.
- Use desktop row skeletons in non-mobile mode.
- Use mobile card skeletons in mobile mode.
- Preserve layout dimensions enough to avoid large visual jumps.
- Show a clear error state with retry behavior when fetch fails.

Sort/search/filter operations that can be performed locally should update immediately and should not show the heavier perspective-change loading state.

## JSON Contract Direction

Keep the current API shape initially if that reduces risk, but normalize it in the JS shell.

Target client-side shape:

```js
{
  columns: [
    { key: "name", label: "Player", type: "identity" },
    { key: "pts", label: "PTS", type: "number", sortable: true }
  ],
  rows: [],
  controls: {
    perspectives: [],
    seasons: [],
    gameTypes: [],
    positionButtons: [],
    filterSchema: []
  },
  state: {
    perspective: "skaters",
    season_id: "20252026",
    game_type: 2,
    sortKey: "pts",
    sortDirection: "desc",
    canSlice: false
  },
  meta: {}
}
```

The server API can be cleaned up in a later PR if needed. This PR should focus on page isolation, state ownership, and reliable rendering.

## Implementation Outline

1. Audit `nav.main` and profile drawer dependencies.
2. Create a stats-specific layout without Livewire or Jetstream page shell dependencies.
3. Create a new stats page view using that layout.
4. Point `StatsController@index` at the new stats view.
5. Add a dedicated stats page JS shell.
6. Adapt or replace current desktop and mobile renderers so they consume normalized JSON and callbacks.
7. Move perspective, filter, fetch, sort, URL, and viewport logic into the JS shell.
8. Add styled mobile-card and desktop-row loading skeletons.
9. Keep current API response shape initially and normalize client-side.
10. Remove reliance on the `statsUpdated` global event flow for the new page.

## Out Of Scope

- Deleting old stats views or old renderer files.
- Removing global app layout dependencies outside `/stats`.
- Rebuilding the stats API contract server-side unless required for the page shell.
- Changing NHL import logic.
- External provider stats integrations.
- Running CI, tests, imports, migrations, seeders, queues, schedulers, or operational commands.

## Verification Direction

The implementation PR should include focused verification for:

- Initial `/stats` render.
- Perspective change fetch and render.
- Mobile/non-mobile renderer selection at the desktop cutoff.
- JSON-driven local sort behavior.
- Styled loading state during perspective changes.
- Fetch failure error state.

Automated tests should be scoped and should follow repository testing standards. Manual viewport review should verify both mobile and non-mobile visual continuity.
