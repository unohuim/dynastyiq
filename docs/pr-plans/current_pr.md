---
pr_id: 12
pr_name: pr12
status: Active
created: 2026-07-09
last_updated: 2026-07-09
---

# Stats Payload, Filter Schema, And JS Stats Abstractions

## Source

League Players tab performance and architecture review on 2026-07-09.

During the league Players tab work, timing instrumentation showed that the original Fantrax league skater payload took roughly 1.6 seconds locally, with most of the cost inside row assembly. A contract-loading N+1 inside `assembleRowsFromCollection()` was removed, reducing the same payload to roughly 475ms locally. After that fix, the largest remaining costs were distributed across schema/filter preparation, stats query execution, row assembly, and league ownership hydration.

The follow-up review identified that the current stats payload path works, but responsibilities are concentrated inside `StatsController` and `StatsPageShell`.

## Objective

Introduce clear, reusable boundaries for stats payload construction, filter parsing, filter schema generation, derived filtering, row assembly, and `js-stats` request handling while preserving the existing stats payload contract.

The goal is to make the stats system faster to optimize, easier to test, and safer to reuse across:

- Main stats pages.
- League Players tabs.
- Fantrax league scoring views.
- Goalie column-group views.
- Draft player pools.
- Prospects views.
- Future team or unit stats views.

## Current DynastyIQ Context

The current stats endpoint and league stats payload logic live primarily in:

- `app/Http/Controllers/StatsController.php`
- `resources/js/pages/stats-page.js`
- `resources/js/components/StatsPage/*`

Current server payload shape includes:

- `headings`
- `data`
- `settings`
- `meta.filterSchema`
- `meta.appliedFilters`
- `meta.positionButtons`
- `meta.availableSeasons`
- `meta.availableGameTypes`
- `connectedLeagues`
- `perspectives`
- `selectedPerspective`

This contract should remain stable during the migration unless a later PR explicitly approves a breaking change.

## Findings

The current `buildSchemaAndApplyFilters()` method does several jobs at once:

- Joins the stats query to players as `pf`.
- Applies availability and locked perspective filters.
- Builds UI schema for age, team, position, league, stat columns, and GP.
- Calculates bounds/options by cloning the query.
- Applies request filters back onto the same base query.

The current `applyPostFilters()` method correctly handles derived filters after row assembly, such as:

- `gp`
- `contract_value_num`
- `contract_last_year_num`

The current `StatsPageShell` does several jobs at once:

- UI state.
- Rendering coordination.
- Request parameter construction.
- Payload fetching.
- Payload caching.
- Response normalization.
- Filter state.
- Position and goalie-mode behavior.

## Design Direction

Do not create a broad stats framework. Create small, named abstractions around existing domain responsibilities.

The server remains the source of truth for hockey, fantasy, scoring, and stats semantics. The frontend should render the server contract and manage UI state, not own domain rules.

## Proposed PHP Abstractions

### StatsQueryContext

Value object describing the stats request context.

Candidate fields:

- User.
- Platform league, when present.
- Perspective slug/name/id.
- Season.
- Game type.
- Period.
- Slice.
- Column group.
- Platform.
- Draft context.
- Resource type.

This replaces loose request arrays and repeated scalar passing through the payload pipeline.

### StatsFilterSet

Parsed representation of requested filters.

Candidate responsibilities:

- Parse HTTP query parameters once.
- Normalize position and position-type selections.
- Hold team, league, numeric, and derived filter ranges.
- Distinguish DB-backed filters from post-assembly filters.

### StatsQueryFilterApplier

Applies DB-backed filters to the base query.

Candidate responsibilities:

- Availability.
- Locked perspective filters.
- Position and position type.
- Team.
- League.
- Age via DOB.
- Physical numeric min/max filters.

It should not build UI schema.

### StatsFilterSchemaProvider

Builds UI filter schema.

Candidate responsibilities:

- Return available filter definitions.
- Return enum options.
- Return numeric bounds.
- Make bounds/options cacheable by context.

Candidate cache key:

`platform:league_id:perspective_slug:season_id:game_type:column_group:period:slice`

This should support league stats and prospects without forcing a second implementation path.

### StatsDerivedFilterApplier

Applies filters that cannot be pushed into the base SQL query before row assembly.

Initial derived filters:

- Contract AAV.
- Contract end year.
- Other fantasy/provider values that only exist after row formatting.

### StatsPayloadAssembler

Turns loaded stats records into payload rows.

Candidate responsibilities:

- Player identity fields.
- Contract fields.
- TOI normalization.
- Native fantasy aliases.
- Goalie stats aliases.
- On-ice aliases.
- Prospects row grouping.

This should own behavior currently centered around `assembleRowsFromCollection()`, `withNativeFantasyAliases()`, official boxscore TOI helpers, and on-ice append helpers.

### StatsPayloadBuilder

Coordinates context, filters, schema, query execution, row assembly, post filters, sorting, metadata, and final payload shape.

The controller should validate and authorize, then delegate to this builder.

## Proposed JS Abstractions

### StatsPayloadClient

Owns network and cache behavior.

Candidate responsibilities:

- Request param building from a stable state object.
- Cache key normalization.
- Fetch.
- Stale response protection.
- Response normalization.
- Resolved-season cache aliases.
- Optional debug/timing header capture.

### StatsFilterState

Owns client-side filter UI state.

Candidate responsibilities:

- Selected positions.
- Selected position types.
- Selected leagues.
- Numeric filter values.
- Dirty numeric filter tracking.
- Reset/apply behavior.

### StatsSchemaAdapter

Owns interpretation of `meta.filterSchema`.

Candidate responsibilities:

- Numeric filter specs.
- Default bounds.
- Control visibility.
- Schema-to-UI mapping.

### StatsPageShell

Keep or rename as a coordinator that wires the payload client, filter state, schema adapter, desktop renderer, and mobile renderer.

It should not own hockey-specific request semantics directly.

## JS Stats Boundary Direction

The `js-stats` component should maintain:

- Table and card rendering.
- Sort state and local sort interactions.
- Responsive desktop/mobile rendering.
- Drawer/open/close UI state.
- Loading/error/empty states.
- Consumption of the existing payload contract.

The `js-stats` component should shed:

- Direct business assumptions about what request params mean.
- Fetch/cache internals inside the rendering class.
- URL/query parameter construction inside rendering logic.
- Response normalization rules that are better owned by a client/adaptor layer.
- Domain-specific goalie/skater behavior where the server schema can express the correct behavior.

## Migration Plan

1. Add `StatsQueryContext` and `StatsFilterSet`.
   - No behavior change.
   - Keep `StatsController` as the caller.

2. Extract DB-backed query filtering.
   - Move query mutation from `buildSchemaAndApplyFilters()` into `StatsQueryFilterApplier`.
   - Preserve existing request and payload behavior.

3. Extract schema generation.
   - Move filter schema, bounds, and enum option generation into `StatsFilterSchemaProvider`.
   - Keep payload shape unchanged.

4. Extract derived filtering.
   - Move `applyPostFilters()` behavior into `StatsDerivedFilterApplier`.
   - Keep derived filters post-assembly until there is a better persisted/queryable representation.

5. Extract row assembly.
   - Move row assembly, TOI helpers, aliases, and on-ice append behavior into `StatsPayloadAssembler`.
   - Preserve current player identity, contract, goalie, skater, and prospects behavior.

6. Add `StatsPayloadBuilder`.
   - Coordinate the extracted services.
   - Slim `StatsController::leaguePayload()` and related payload methods.

7. Add schema caching.
   - Cache stable schema/bounds/options by context.
   - Invalidate or bypass when context changes.

8. Extract `StatsPayloadClient` in JS.
   - Move fetch, cache, stale response protection, and request key normalization out of `StatsPageShell`.

9. Extract `StatsFilterState` and `StatsSchemaAdapter`.
   - Keep existing desktop/mobile renderers.
   - Avoid UI regressions.

## Performance Direction

Do not optimize blindly. Keep lightweight timing hooks while migrating, then remove temporary logs once the path is stable.

Expected wins:

- Schema metadata can be cached instead of recalculated on every tab action.
- Bounds/options can be calculated in fewer queries.
- Request/cache behavior becomes easier to reason about.
- Row assembly remains isolated for further optimization.

## Implementation Progress

Implemented in this PR slice:

- Added `StatsQueryContext` and `StatsFilterSet`.
- Extracted DB-backed query filtering to `StatsQueryFilterApplier`.
- Extracted schema generation and physical numeric key detection to `StatsFilterSchemaProvider`.
- Added short-lived schema caching keyed by the prepared base query and requested columns.
- Extracted derived filtering to `StatsDerivedFilterApplier`.
- Extracted row assembly, native aliases, TOI helpers, and on-ice aliasing to `StatsPayloadAssembler`.
- Added `StatsPayloadBuilder` as the controller-facing coordinator for the extracted PHP services.
- Moved season stats payload construction into `StatsPayloadBuilder` behind `SeasonStatsPayloadRequest`.
- Moved date-range stats payload construction into `StatsPayloadBuilder` behind `RangeStatsPayloadRequest`.
- Replaced temporary league stats payload timing logs with `LOG_STATS_TIMING`, default off.
- Added explicit schema cache context from builder paths while keeping SQL/bindings in the cache key.
- Extracted league ownership decoration and roster-only row hydration into `LeagueStatsOwnershipHydrator`.
- Added five-minute league ownership map caching scoped by league, user, and platform.
- Added optional ownership sub-timings under `ownership_timings` when `LOG_STATS_TIMING` is enabled.
- Extracted synthetic Yahoo/Fantrax league perspective construction and Fantrax goalie column-group settings into `LeagueStatsPerspectiveFactory`.
- Extracted platform player-universe filtering into `LeagueStatsPlayerUniverseFilter` before league ownership hydration.
- Extracted JS fetch/cache/stale-response handling to `StatsPayloadClient`.
- Extracted JS client filter state to `StatsFilterState`.
- Extracted JS payload schema interpretation to `StatsSchemaAdapter`.
- Extracted JS column-group and active-heading behavior to `StatsColumnGroupAdapter`.
- Documented that `StatsPayloadClient` owns goalie `column_group=goalie` request translation until the backend exposes a separate active column-group request contract.

Human verification still needed:

- Run the PHP and JavaScript test suites after review, per repository execution policy.

## Testing Expectations

The implementation PR should be test-driven and follow `docs/testing/testing-standards.yaml`.

Expected PHP coverage:

- Context parsing preserves current request behavior.
- Position and position-type filters match current results.
- Goalie column-group requests preserve goalie rows and headings.
- League scoring perspectives preserve skater and goalie column groups.
- Prospects and prospects-goalie perspectives preserve current row sets.
- Derived filters apply after row assembly and return expected `appliedFilters`.
- Contract fields are assembled without N+1 relation queries where testable.
- Schema provider returns stable bounds/options for a fixed context.
- Cached schema does not change response semantics.

Expected JS coverage:

- Payload client caches resolved-season aliases.
- Stale fetch responses do not replace newer state.
- Filter state sends only dirty numeric filters.
- Goalie toggle behavior preserves existing visible results.
- StatsPageShell still renders existing payloads.

## Out Of Scope

- Changing the public stats payload contract.
- Rewriting the visual design of stats tables.
- Replacing the desktop or mobile renderer.
- Adding new stat categories.
- Changing Fantrax scoring mappings.
- Changing player identity resolution.
- Adding external advanced stats providers.
- Running CI, tests, imports, migrations, seeders, queues, schedulers, bots, or operational commands.
