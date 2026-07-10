# Architecture Inventory

This document tracks **reusable abstractions, components, and architectural patterns**
used throughout the project.

Its purpose is to:

- Prevent duplicate abstractions
- Make intent explicit for future contributors, human or AI
- Serve as the architectural source of truth

This is an **index**, not a tutorial.

---

## Authority & References

- **Database schema** is inventoried in `docs/DB_SCHEMA.md`.
- **Enum-like values** are defined canonically in `docs/ENUMS.md`.
- **Development conventions** are defined in `docs/CONVENTIONS.md`.
- **New and materially touched UI** must follow `docs/UI_DESIGN.md`.
- This document must not duplicate enum values or table definitions except where needed to identify an interface.

---

## Entry Requirements

Each entry includes:

- **Name**
- **Type**
- **Location**
- **Purpose**
- **When to Use**
- **When Not to Use**
- **Public Interface**
- **Example Usage**

---

## Application Structure

### Laravel Application Shell

**Name:** Laravel Application Shell
**Type:** Framework Pattern
**Location:**
- `routes/web.php`
- `routes/api.php`
- `app/Http/Controllers/`
- `resources/views/`
- `resources/js/app.js`

**Purpose:**
Provide the primary authenticated and public web application using Laravel, Jetstream/Fortify, Blade, Alpine, Tailwind, Livewire, queues, and Vite.

**When to Use:**
Adding first-party HTTP routes, controllers, views, request validation, policies, jobs, and application JavaScript.

**When Not to Use:**
Discord bot runtime code, which lives under `diq-bot/`, or external scripts that should remain outside Laravel.

**Public Interface:**
- Laravel route definitions
- Blade views and components
- Vite-managed JavaScript entrypoints
- Laravel queues and jobs

**Example Usage:**
```php
Route::get('/stats', [StatsController::class, 'index'])->name('stats.index');
```

---

### Yahoo Fantasy Player Import

**Name:** Yahoo Fantasy Player Import
**Type:** External Platform Import Pattern
**Location:**
- `app/Models/YahooFantasyConnection.php`
- `app/Models/YahooPlayer.php`
- `app/Services/YahooFantasyClient.php`
- `app/Services/YahooFantasyPlayerImporter.php`
- `app/Jobs/ImportYahooPlayersPageJob.php`
- `app/Http/Controllers/Admin/YahooOAuthProbeController.php`
- `app/Http/Controllers/Admin/YahooPlayerImportController.php`
- `database/migrations/*_create_yahoo_fantasy_connections_table.php`
- `database/migrations/*_create_yahoo_players_table.php`

**Purpose:**
Persist user-owned Yahoo Fantasy OAuth access and stage Yahoo Fantasy hockey player records before matching them to canonical DynastyIQ players.

**When to Use:**
Connecting a user's Yahoo Fantasy Sports OAuth grant, importing Yahoo Fantasy hockey player collection pages, and preserving Yahoo player resource keys and raw payloads for later identity matching.

**When Not to Use:**
Yahoo league, team, roster, standings, scoring settings, transaction sync, or canonical player creation.

**Public Interface:**
- `integrations.yahoo.redirect`
- `integrations.yahoo.callback`
- `admin.yahoo.oauth.redirect`
- `admin.yahoo.oauth.callback`
- `admin.yahoo.players.import`
- `ImportYahooPlayersPageJob`
- `YahooFantasyConnection`
- `YahooFantasyClient::fantasyXmlForConnection()`
- `YahooPlayer`
- `YahooFantasyPlayerImporter::importPage()`
- `YahooFantasyPlayerImporter::import()`

**Example Usage:**
```php
ImportYahooPlayersPageJob::dispatch($connection->id, $importRun->id, 0, 25);
```

---

### Discord Bot Runtime

**Name:** Discord Bot Runtime
**Type:** External Node Service
**Location:**
- `diq-bot/bot.js`
- `diq-bot/features/connect.js`
- `diq-bot/features/user-teams.js`
- `diq-bot/features/assign-fantrax-roles.js`

**Purpose:**
Run Discord-specific behavior outside Laravel while consuming Laravel HTTP APIs and broadcast events.

**When to Use:**
Discord slash/context commands, guild-member event handling, Discord role assignment, and bot-side realtime reactions.

**When Not to Use:**
Laravel web routes, domain persistence, or app authorization rules.

**Public Interface:**
- Discord bot process
- Laravel API endpoints under `/api/discord/*`
- `/diq connect`
- Reverb/Pusher channel events emitted by Laravel

**Example Usage:**
```js
// Bot feature code calls Laravel API endpoints to resolve Discord user team data.
```

### Sortable List

**Name:** Sortable List
**Type:** Frontend Component
**Location:**
- `resources/js/components/SortableList/sortable-list.js`

**Purpose:**
Provide reusable native drag-and-drop row reordering for page-local lists that persist ordered item identifiers through page-owned endpoints.

**When to Use:**
Settings, navigation, or option lists where the current user can manually reorder rows and the page owns the persistence contract.

**When Not to Use:**
Cross-list dragging, rich board interactions, or server-ranked lists where manual row order is not a user preference.

**Public Interface:**
- `mountSortableList()`
- `mountSortableLists()`
- `data-sortable-list`
- `data-sortable-row`
- `data-sortable-id`
- `data-sortable-handle`
- `sortable-list:changed`
- `sortable-list:saved`
- `sortable-list:failed`

**Example Usage:**
```html
<div data-sortable-list data-sortable-url="/leagues/order" data-sortable-payload-key="league_ids">
    <div data-sortable-row data-sortable-id="1">
        <button type="button" draggable="true" data-sortable-handle>Move</button>
    </div>
</div>
```

---

### Fantrax Draft State Sync

**Name:** Fantrax Draft State Sync
**Type:** External Platform Sync Pattern
**Location:**
- `app/Services/SyncFantraxDraftState.php`
- `app/Jobs/SyncFantraxDraftStateJob.php`
- `app/Console/Commands/FantraxDraftsPollCommand.php`
- `app/Events/DraftPickMade.php`

**Purpose:**
Fetch Fantrax draft payloads and mirror them into canonical Draft Central tables.

**When to Use:**
Polling Fantrax draft payloads, comparing latest provider draft rows against canonical draft picks, and emitting events when a previously unmade canonical pick receives a Fantrax player id.
Discord draft announcement cards use skater columns GP/G/A/PTS and goalie columns GP/W/SV/SV% based on canonical or provider position.

**When Not to Use:**
Rendering the draft window directly from Fantrax cache tables, storing every raw polling snapshot, or syncing Fantrax rosters.

**Public Interface:**
- `SyncFantraxDraftState::sync`
- `SyncFantraxDraftState::syncPayloads`
- `SyncFantraxDraftStateJob`
- `fantrax:drafts:poll`
- `DraftPickMade`

**Example Usage:**
```php
SyncFantraxDraftStateJob::dispatch($platformLeague->id);
```

---

### Stats Payload Pipeline

**Name:** Stats Payload Pipeline
**Type:** Backend Read Model Pattern
**Location:**
- `app/Support/Stats/StatsQueryContext.php`
- `app/Support/Stats/StatsFilterSet.php`
- `app/Support/Stats/SeasonStatsPayloadRequest.php`
- `app/Support/Stats/RangeStatsPayloadRequest.php`
- `app/Support/Stats/StatsQueryFilterApplier.php`
- `app/Support/Stats/StatsFilterSchemaProvider.php`
- `app/Support/Stats/StatsDerivedFilterApplier.php`
- `app/Support/Stats/StatsPayloadAssembler.php`
- `app/Support/Stats/StatsPayloadBuilder.php`
- `app/Support/Stats/LeagueStatsOwnershipHydrator.php`
- `app/Support/Stats/LeagueStatsPerspectiveFactory.php`
- `app/Support/Stats/LeagueStatsPlayerUniverseFilter.php`
- `app/Http/Controllers/StatsController.php`
- `resources/js/pages/stats-payload-client.js`
- `resources/js/pages/stats-filter-state.js`
- `resources/js/pages/stats-schema-adapter.js`
- `resources/js/pages/stats-column-group-adapter.js`
- `resources/js/pages/stats-page.js`

**Purpose:**
Build stats payloads through explicit request context, parsed filters, schema metadata, row assembly, and frontend payload consumption boundaries.
Stats report UIs should consume server JSON payloads and apply sort, display-mode, and filter interactions without full page refreshes.

**When to Use:**
League stats payloads, perspective-driven player stats payloads, JSON-fed stats report views including stats unit reports, prospects and draft-player stats payloads, and stats query/schema/row assembly refactors.

**When Not to Use:**
NHL import aggregation pipelines, Discord bot runtime, or one-off admin reports that do not expose the stats payload contract.

**Public Interface:**
- `StatsQueryContext`
- `StatsFilterSet`
- `SeasonStatsPayloadRequest`
- `RangeStatsPayloadRequest`
- `StatsQueryFilterApplier`
- `StatsFilterSchemaProvider`
- `StatsDerivedFilterApplier`
- `StatsPayloadAssembler`
- `StatsPayloadBuilder`
- `LeagueStatsOwnershipHydrator`
- `LeagueStatsPerspectiveFactory`
- `LeagueStatsPlayerUniverseFilter`
- `StatsController::leaguePayload()`
- `StatsPayloadClient`
- `StatsFilterState`
- `StatsSchemaAdapter`
- `StatsColumnGroupAdapter`
- Stats payload JSON contract
- `window.DIQ.mountStatsPage`

**Example Usage:**
```php
$context = StatsQueryContext::fromRequest($request, $league, null, $defaultPerspectiveSlug);
$filterSet = StatsFilterSet::fromRequest($request);
$payload = app(LeagueStatsPlayerUniverseFilter::class)->filter($payload, $league);
```

---

## Authorization & Identity

### Role and Organization Authorization

**Name:** Role and Organization Authorization
**Type:** Authorization Pattern
**Location:**
- `app/Models/User.php`
- `app/Models/Role.php`
- `app/Models/RoleUser.php`
- `app/Http/Middleware/SuperAdminMiddleware.php`
- `app/Http/Middleware/AdminLifecycleMiddleware.php`
- `database/seeders/RoleSeeder.php`

**Purpose:**
Authorize users through global roles and organization-scoped role assignments.

**When to Use:**
Admin route protection, community administration, organization settings, and role-aware UI visibility.

**When Not to Use:**
Replacing backend policies, bypassing route middleware, or encoding one-off UI-only access rules.

**Public Interface:**
- `User::roles()`
- `User::organizations()`
- `admin.super` middleware
- `admin.lifecycle` middleware

**Example Usage:**
```php
Route::middleware(['admin.super', 'admin.lifecycle'])->group(function () {
    Route::get('/admin/imports', [ImportsController::class, 'index']);
});
```

---

### Perspective Policy

**Name:** Perspective Policy
**Type:** Authorization Policy
**Location:**
- `app/Policies/PerspectivePolicy.php`
- `app/Models/Perspective.php`
- `app/Http/Controllers/PerspectiveController.php`

**Purpose:**
Control who can create, update, delete, and view user or organization-owned stats perspectives.

**When to Use:**
Any mutation or non-public access check for saved perspective records.

**When Not to Use:**
General route authorization outside the perspective domain.

**Public Interface:**
- `PerspectivePolicy`
- `Perspective::forUser()`

**Example Usage:**
```php
$this->authorize('update', $perspective);
```

---

### Social Account Identity

**Name:** Social Account Identity
**Type:** OAuth Identity Pattern
**Location:**
- `app/Models/SocialAccount.php`
- `app/Http/Controllers/Auth/SocialiteCallbackController.php`
- `routes/web.php`

**Purpose:**
Link external OAuth identities, currently Discord, to application users.

**When to Use:**
Discord login, external identity lookup, and future first-party OAuth provider account linkage that authenticates users.

**When Not to Use:**
Provider integrations that are organization-owned service connections, such as Patreon provider accounts.

**Public Interface:**
- `/auth/discord/redirect`
- `/auth/discord/callback`
- `SocialAccount`

**Example Usage:**
```php
return Socialite::driver('discord')
    ->scopes(['identify', 'email'])
    ->redirect();
```

---

## Community & Membership

### Organization Community Model

**Name:** Organization Community Model
**Type:** Domain Pattern
**Location:**
- `app/Models/Organization.php`
- `app/Models/OrganizationLeague.php`
- `app/Models/Membership.php`
- `app/Models/MembershipTier.php`
- `app/Models/MemberProfile.php`
- `app/Http/Controllers/CommunitiesController.php`
- `app/Http/Controllers/CommunityMemberController.php`
- `app/Http/Controllers/CommunityTierController.php`

**Purpose:**
Represent communities as organizations with leagues, members, member profiles, membership tiers, and provider-backed memberships.

**When to Use:**
Community hub pages, organization-level league linkage, member management, and tier management.

**When Not to Use:**
User authentication identity, raw Discord guild state, or provider-specific sync internals.

**Public Interface:**
- `communities.index`
- `communities.members.*`
- `communities.tiers.*`
- Organization and membership Eloquent relationships

**Example Usage:**
```php
$memberships = $organization->memberships()->with(['user', 'tier'])->get();
```

---

### Community Hub Layout

**Name:** Community Hub Layout
**Type:** UI Layout Pattern
**Location:**
- `app/View/Components/CommunityHubLayout.php`
- `resources/views/components/community-hub-layout.blade.php`
- `resources/views/layouts/community-hub.blade.php`
- `resources/js/components/CommunityHubLayout.js`
- `resources/js/community-hub.js`

**Purpose:**
Provide the shared community hub shell, layout, and progressive enhancement behavior.

**When to Use:**
Community pages that share hub navigation, organization context, and common page chrome.

**When Not to Use:**
Global app navigation, standalone admin pages, or non-community public pages.

**Public Interface:**
- `<x-community-hub-layout>`
- `resources/js/community-hub.js`

**Example Usage:**
```blade
<x-community-hub-layout :organization="$organization">
    ...
</x-community-hub-layout>
```

---

### Community Members Store

**Name:** Community Members Store
**Type:** Frontend State Module
**Location:**
- `resources/js/components/community-members-store.js`
- `resources/js/components/__tests__/community-members-store.test.js`
- `resources/views/communities/_desktop-memberships.blade.php`

**Purpose:**
Manage page-local member and tier UI state for community membership screens.

**When to Use:**
Interactive community membership/tier CRUD behavior on the community hub.

**When Not to Use:**
Global app state, backend authorization, or unrelated page modules.

**Public Interface:**
- Community member store factory exported from `community-members-store.js`

**Example Usage:**
```js
// Mounted from community hub JavaScript for membership UI behavior.
```

---

### League Show View Model

**Name:** League Show View Model
**Type:** View Model / DTO Pattern
**Location:**
- `app/ViewModels/LeagueShowViewModel.php`
- `app/DTO/LeagueShowDto.php`
- `app/Http/Controllers/LeagueController.php`
- `app/Http/Controllers/CommunityLeagues.php`

**Purpose:**
Prepare league detail data for Blade without spreading query and transformation logic through views.

**When to Use:**
League detail pages that need organization, platform league, teams, roster, drafting, and availability context.

**When Not to Use:**
Raw API endpoints, import services, or low-level model relationships.

**Public Interface:**
- `LeagueShowViewModel`
- `LeagueShowDto`

**Example Usage:**
```php
$dto = $leagueShowViewModel->toDto();
```

---

## Stats & Player Data

### Perspective-Driven Stats Payload

**Name:** Perspective-Driven Stats Payload
**Type:** Read Model / UI Contract
**Location:**
- `app/Models/Perspective.php`
- `app/Http/Controllers/StatsController.php`
- `app/Http/Controllers/PlayerStatsController.php`
- `app/Http/Controllers/PerspectiveController.php`
- `resources/views/stats-view.blade.php`
- `resources/views/player-stats-view.blade.php`
- `resources/js/components/StatsPage/`
- `resources/js/components/PlayerStatsPage/`

**Purpose:**
Drive stats tables and cards from saved perspective settings, including visible columns, filters, sort state, and display mode.

**When to Use:**
Stats pages, player stats pages, saved custom views, and API payloads that represent user-selected stat views.

**When Not to Use:**
One-off admin reports, import pipelines, or persistence that should be normalized into first-class tables.

**Public Interface:**
- `Perspective::forUser()`
- `stats.payload`
- `api.stats`
- `api.player-stats`
- Perspective CRUD endpoints

**Example Usage:**
```php
$perspectives = Perspective::query()
    ->forUser($request->user())
    ->orderBy('name')
    ->get();
```

---

### Stats Page Renderer

**Name:** Stats Page Renderer
**Type:** Frontend UI Pattern
**Location:**
- `resources/js/components/StatsPage/stats-page.js`
- `resources/js/components/StatsPage/stats-desktop.js`
- `resources/js/components/StatsPage/stats-mobile.js`
- `resources/js/components/StatsPage/stats-utils.js`
- `resources/js/components/StatsPage/ui/`

**Purpose:**
Render desktop and mobile stats experiences from the stats payload while sharing utilities and UI primitives.

**When to Use:**
Stats table/card rendering on `/stats`.

**When Not to Use:**
Player-only stats pages, which have a parallel `PlayerStatsPage` module, or admin import UI.

**Public Interface:**
- `StatsPage` JavaScript module
- Stats payload JSON from `StatsController::payload()`

**Example Usage:**
```js
// Initialized by the stats page entrypoint with server-provided payload data.
```

---

### Player Stats Page Renderer

**Name:** Player Stats Page Renderer
**Type:** Frontend UI Pattern
**Location:**
- `resources/js/components/PlayerStatsPage/player-stats-page.js`
- `resources/js/components/PlayerStatsPage/player-stats-desktop.js`
- `resources/js/components/PlayerStatsPage/player-stats-mobile.js`
- `resources/js/components/PlayerStatsPage/player-stats-utils.js`
- `resources/js/components/PlayerStatsPage/ui/`

**Purpose:**
Render player-centered stats views with the same desktop/mobile split used by the main stats page.

**When to Use:**
Player stats and public player table surfaces.

**When Not to Use:**
General stats pages that should use the `StatsPage` module.

**Public Interface:**
- `PlayerStatsPage` JavaScript module
- `api.player-stats`

**Example Usage:**
```js
// Initialized by the player stats page entrypoint with API-backed rows.
```

---

### Player Ranking Profiles

**Name:** Player Ranking Profiles
**Type:** Domain Pattern
**Location:**
- `app/Models/RankingProfile.php`
- `app/Models/PlayerRanking.php`
- `app/Http/Controllers/PlayerRankingController.php`
- `app/Http/Controllers/PlayerRankingSetController.php`
- `app/Livewire/PlayerRankingsTable.php`
- `resources/views/player/rankings.blade.php`

**Purpose:**
Store and render player ranking sets independent from raw NHL stats imports.

**When to Use:**
Manual ranking creation, ranking upload, and user-facing ranking views.

**When Not to Use:**
Replacing source player identity, NHL stats, or Fantrax roster data.

**Public Interface:**
- `player.rankings.index`
- `player.rankings.upload`
- `player.rankings.manual`
- `RankingProfile`
- `PlayerRanking`

**Example Usage:**
```php
$profile->rankings()->create([
    'player_id' => $player->id,
    'rank' => 1,
]);
```

---

### Shot Geometry Service

**Name:** Shot Geometry Service
**Type:** Domain Service
**Location:**
- `app/Services/ShotGeometryService.php`
- `app/Console/Commands/BackfillShotGeometryCommand.php`
- `docs/shot_geometry_options.md`

**Purpose:**
Centralize shot-location and geometry calculations for imported NHL play-by-play data.

**When to Use:**
Calculating or backfilling shot geometry fields derived from NHL event data.

**When Not to Use:**
Presentation-only stat formatting or unrelated player metadata imports.

**Public Interface:**
- `ShotGeometryService`
- `BackfillShotGeometryCommand`

**Example Usage:**
```php
app(ShotGeometryService::class)->computeFromPlay($playByPlay, $game);
```

---

## NHL Imports

### NHL Import Orchestrator

**Name:** NHL Import Orchestrator
**Type:** Queue Orchestration Pattern
**Location:**
- `app/Services/NhlImportOrchestrator.php`
- `app/Repositories/NhlImportProgressRepo.php`
- `app/Jobs/NhlOrchestratorJob.php`
- `app/Jobs/ImportPbpNhlJob.php`
- `app/Jobs/SummarizePbpNhlJob.php`
- `app/Jobs/ImportShiftsNhlJob.php`
- `app/Jobs/ImportBoxscoreNhlJob.php`
- `app/Jobs/MakeShiftUnitsNhlJob.php`
- `app/Jobs/ConnectEventsShiftUnitsNhlJob.php`
- `app/Jobs/SumNhlGameUnitsJob.php`

**Purpose:**
Move NHL game imports through explicit progress stages while tracking status, dependencies, failures, and stale running jobs.

**When to Use:**
Scheduling or executing game-level NHL imports that must run in dependency order.

**When Not to Use:**
One-off admin imports that do not use `nhl_import_progress`, or synchronous request-time imports.

**Public Interface:**
- `NhlImportOrchestrator::processScheduled()`
- `NhlImportOrchestrator::claim()`
- `NhlImportOrchestrator::onSuccess()`
- `NhlImportOrchestrator::onFailure()`
- `NhlImportOrchestrator::sweepStale()`

**Example Usage:**
```php
app(NhlImportOrchestrator::class)->processScheduled('2026-01-15');
```

---

### NHL Discovery Pipeline

**Name:** NHL Discovery Pipeline
**Type:** Import Discovery Pattern
**Location:**
- `app/Services/NhlDiscoverGames.php`
- `app/Services/NhlDiscovery.php`
- `app/Console/Commands/NhlDiscoverCommand.php`
- `app/Jobs/NhlDiscoveryJob.php`
- `app/Jobs/NhlDiscoverDayJob.php`

**Purpose:**
Discover NHL games for a date or season and seed downstream import progress rows.

**When to Use:**
Finding NHL games before running the staged import pipeline.

**When Not to Use:**
Importing play-by-play, shifts, boxscores, or summarizing already discovered games.

**Public Interface:**
- `NhlDiscoverCommand`
- `NhlDiscoveryJob`
- `NhlDiscoverGames`

**Example Usage:**
```bash
php artisan nhl:discover --date=2026-01-15
```

---

### NHL Game Data Import Services

**Name:** NHL Game Data Import Services
**Type:** Domain Import Services
**Location:**
- `app/Console/Commands/EmptyNhlCommand.php`
- `app/Services/ImportNHLPlayByPlay.php`
- `app/Services/NhlPbpEventNormalizer.php`
- `app/Services/SumNHLPlayByPlay.php`
- `app/Services/ImportNhlShifts.php`
- `app/Services/ImportNhlBoxscore.php`
- `app/Services/MakeNhlGameShiftUnits.php`
- `app/Services/ConnectEventsToUnitShifts.php`
- `app/Services/SumNhlGameUnits.php`
- `app/Services/SumNhlSeasonStats.php`

**Purpose:**
Own the actual NHL data transformations used by queued import jobs and admin commands, including boxscore-guided reconciliation of provider shiftchart artifacts when official shift and TOI targets are already available. Goalie decisions preserve regulation, overtime, and shootout splits before season aggregation.

**When to Use:**
Implementing or changing a single NHL import stage.

**When Not to Use:**
Coordinating stage order; use `NhlImportOrchestrator` for orchestration.

**Public Interface:**
- `EmptyNhlCommand`
- Stage-specific service classes
- Stage-specific queued jobs

**Example Usage:**
```php
app(ImportNHLPlayByPlay::class)->import($gameId);
```

```bash
php artisan nhl:empty --games
php artisan nhl:empty --players
```

---

### NHL PBP Event Normalizer

**Name:** NHL PBP Event Normalizer
**Type:** Domain Rule Service
**Location:**
- `app/Services/NhlPbpEventNormalizer.php`
- `docs/architecture/imports/NhlPbpEventNormalizer.yaml`

**Purpose:**
Centralize NHL play-by-play event classification used by player summaries, unit summaries, and validation triage.

**When to Use:**
Classifying PBP events as boxscore-comparable shots, attempts, shootout-only events, or normalized penalty-minute contributors.

**When Not to Use:**
Fetching provider payloads, replacing official boxscore validation, or coordinating import stages.

**Public Interface:**
- `NhlPbpEventNormalizer`

**Example Usage:**
```php
$normalizer->isShotOnGoal($play);
```

---

### NHL Game Import Eligibility

**Name:** NHL Game Import Eligibility
**Type:** Domain Rule Service
**Location:**
- `app/Services/NhlGameImportEligibility.php`
- `docs/architecture/imports/NhlGameImportEligibility.yaml`

**Purpose:**
Centralize which NHL provider game types can be discovered, imported, and advanced through the game pipeline.

**When to Use:**
Filtering discovered games, validating PBP provider payloads, or guarding later import stages.

**When Not to Use:**
Defining game type meanings or deciding stage dependency order.

**Public Interface:**
- `NhlGameImportEligibility`

**Example Usage:**
```php
$eligibility->allowsStoredGame($gameId);
```

---

### NHL Game Import Rebuilder

**Name:** NHL Game Import Rebuilder
**Type:** Operational Import Repair Service
**Location:**
- `app/Services/NhlGameImportRebuilder.php`
- `docs/architecture/imports/NhlGameImportRebuilder.yaml`

**Purpose:**
Clear game-scoped NHL raw and derived import artifacts and requeue the canonical pipeline from PBP when parser changes make normal upserts insufficient.

**When to Use:**
Repairing validation failures caused by stale game-scoped records or rerunning a game after parser semantics change.

**When Not to Use:**
Routine provider refreshes where natural-key upserts are sufficient.

**Public Interface:**
- `NhlGameImportRebuilder::rebuild()`
- `admin.nhl-validations.rebuild-game`

**Example Usage:**
```php
app(NhlGameImportRebuilder::class)->rebuild($gameId);
```

---

### NHL Plus Minus Calculator

**Name:** NHL Plus Minus Calculator
**Type:** Domain Rule Service
**Location:**
- `app/Services/NhlPlusMinusCalculator.php`
- `docs/architecture/imports/NhlPlusMinusCalculator.yaml`

**Purpose:**
Calculate skater plus/minus from eligible linked goal events and reconcile persisted player-game totals to official boxscore values when available.

**When to Use:**
After shift units and event-unit links exist for a game.

**When Not to Use:**
Before event links exist, or for individual scoring and goalie stats.

**Public Interface:**
- `NhlPlusMinusCalculator::calculate()`

**Example Usage:**
```php
app(NhlPlusMinusCalculator::class)->calculate($gameId);
```

---

### NHL Import Stages

**Name:** NHL Import Stages
**Type:** Domain Metadata Contract
**Location:**
- `app/Support/NhlImportStages.php`

**Purpose:**
Define the canonical NHL import stage order, dependencies, queue job mappings, and stale-timeout config keys, including boxscore before shifts so official targets are available for shift reconciliation.

**When to Use:**
Seeding import progress rows, checking stage dependencies, dispatching stage jobs, or sweeping stale running rows.

**When Not to Use:**
Performing stage transformations or persisting progress state.

**Public Interface:**
- `NhlImportStages::ordered()`
- `NhlImportStages::dependenciesFor()`
- `NhlImportStages::nextAfter()`
- `NhlImportStages::jobClassFor()`
- `NhlImportStages::timeoutConfigKeyFor()`

**Example Usage:**
```php
foreach (NhlImportStages::ordered() as $stage) {
    // process stage metadata
}
```

---

### NHL Game Source Preflight

**Name:** NHL Game Source Preflight
**Type:** Import Availability Gate
**Location:**
- `app/Services/NhlGameSourcePreflight.php`
- `app/Models/NhlGameSourceStatus.php`
- `app/Http/Controllers/Admin/NhlGameImportController.php`
- `database/migrations/2026_06_30_000003_create_nhl_game_source_statuses_table.php`

**Purpose:**
Verify PBP, boxscore, and shiftcharts source feeds before a game enters source-dependent NHL import stages, and persist blocked-source context for admin review.

**When to Use:**
Before claiming the PBP stage, when explaining why a discovered game is blocked from processing, or when an admin retries missing provider sources.

**When Not to Use:**
For boxscore stat validation after imports complete.

**Public Interface:**
- `NhlGameSourcePreflight::check()`
- `NhlGameSourcePreflight::storedOrCheck()`
- `NhlGameSourcePreflight::storedShiftsAvailable()`
- `NhlGameSourcePreflight::shiftsUrl()`
- `admin.nhl-game-imports.source-gaps`
- `admin.nhl-game-imports.source-gaps.rerun`

**Example Usage:**
```php
$result = app(NhlGameSourcePreflight::class)->check($gameId);
```

---

### NHL Game Summary Validation

**Name:** NHL Game Summary Validation
**Type:** Domain Validation Gate
**Location:**
- `app/Services/CompareNhlPbPBoxscore.php`
- `app/Services/ValidateNhlGameSummary.php`
- `app/Jobs/ValidateNhlGameSummaryJob.php`
- `app/Models/NhlGameValidation.php`
- `app/Models/NhlGameValidationDelta.php`
- `database/migrations/2026_06_29_010000_create_nhl_game_validations_table.php`

**Purpose:**
Persist an auditable validation result comparing computed NHL player game summaries against official NHL boxscore totals.

**When to Use:**
Validating completed NHL game summaries, persisting field-level deltas, or accepting reviewed exceptions.

**When Not to Use:**
Comparing unsupported provider fields, replacing import services, or presenting trusted public stats without approved validation state.

**Public Interface:**
- `CompareNhlPbPBoxscore::compare()`
- `ValidateNhlGameSummary::validate()`
- `ValidateNhlGameSummaryJob`
- `NhlGameValidation`
- `NhlGameValidationDelta`

**Example Usage:**
```php
$validation = app(ValidateNhlGameSummary::class)->validate($gameId);
```

---

### NHL Validation Troubleshooting Exporter

**Name:** NHL Validation Troubleshooting Exporter
**Type:** Import Debugging Artifact
**Location:**
- `app/Services/NhlValidationTroubleshootingExporter.php`
- `docs/architecture/imports/NhlValidationTroubleshootingExporter.yaml`

**Purpose:**
Write compact per-game markdown snapshots and raw provider payload text artifacts for failed NHL summary validations so standalone delta, boxscore, play-by-play, and shift context can be reviewed together.

**When to Use:**
Failed summary-boxscore validations that need durable local troubleshooting evidence.

**When Not to Use:**
Replacing persisted validation deltas or exporting public user-facing stats.

**Public Interface:**
- `NhlValidationTroubleshootingExporter::export()`

**Example Usage:**
```php
app(NhlValidationTroubleshootingExporter::class)->export($validation);
```

---

### NHL Strength On-Ice Stats

**Name:** NHL Strength On-Ice Stats
**Type:** Stats Aggregation Pattern
**Location:**
- `app/Services/SumNhlGameStrengthUnits.php`
- `app/Services/NhlStrengthStatsQuery.php`
- `app/Models/NhlUnitGameStrengthSummary.php`
- `app/Models/NhlPlayerGameStrengthSummary.php`
- `database/migrations/2026_06_29_020001_create_nhl_strength_summaries_table.php`

**Purpose:**
Persist strength-aware on-ice totals by game so stats requests can aggregate summaries instead of scanning raw play-by-play events.

**When to Use:**
Building unit or player on-ice stats by season, range, game type, or strength.

**When Not to Use:**
Importing raw play-by-play events, replacing validation, or storing cheap rate variants.

**Public Interface:**
- `SumNhlGameStrengthUnits::sum()`
- `NhlStrengthStatsQuery::players()`
- `NhlStrengthStatsQuery::units()`
- `NhlUnitGameStrengthSummary`
- `NhlPlayerGameStrengthSummary`

**Example Usage:**
```php
app()->make(SumNhlGameStrengthUnits::class, ['gameId' => $gameId])->sum();
```

---

### Import Progress Repository

**Name:** Import Progress Repository
**Type:** Persistence Abstraction
**Location:**
- `app/Repositories/NhlImportProgressRepo.php`
- `database/migrations/2025_08_13_155830_nhl_import_progress.php`

**Purpose:**
Centralize reads and writes to `nhl_import_progress` for import claim, status, dependency, and stale-job behavior.

**When to Use:**
Any code that mutates or checks NHL import progress state.

**When Not to Use:**
Generic import runs, admin batch history, or non-NHL queue status.

**Public Interface:**
- `claim()`
- `isRunning()`
- `markCompleted()`
- `reschedule()`
- `markError()`
- `scheduledExists()`
- `completedDepsCount()`

**Example Usage:**
```php
if ($repo->claim($gameId, 'pbp')) {
    dispatch(new ImportPbpNhlJob($gameId));
}
```

---

### Player Identity Resolution

**Name:** Player Identity Resolution
**Type:** Import Identity Pattern
**Location:**
- `app/Models/PlayerExternalIdentity.php`
- `app/Events/PlayerExternalIdentityLinked.php`
- `app/Console/Commands/ResolveNhlCommand.php`
- `app/Jobs/ImportPlayersJob.php`
- `app/Jobs/ImportNhlDraftPicksJob.php`
- `app/Jobs/ResolveCanonicalPlayerNhlIdentityJob.php`
- `app/Jobs/RefreshNhlPlayerLandingJob.php`
- `app/Observers/PlayerNhlIdentityObserver.php`
- `app/Listeners/QueueNhlIdentityResolution.php`
- `app/Jobs/RefreshCapWagesContractsForIdentityJob.php`
- `app/Listeners/QueueCapWagesContractRefresh.php`
- `app/Services/NhlPlayerIdentityLookup.php`
- `app/Services/PlayerIdentityNormalizer.php`
- `app/Services/PlayerIdentityResolver.php`
- `database/migrations/*_create_player_external_identities_table.php`

**Purpose:**
Preserve provider-sourced hockey player identities separately from canonical players so imports can be idempotent, auditable, and expandable across NHL, Fantrax, CapWages, and future providers.

**When to Use:**
Importing provider player records or resolving provider IDs to canonical DynastyIQ players.

**When Not to Use:**
Storing canonical player attributes, fantasy roster membership, or provider-only import payload semantics owned by another table.

**Rules:**
NHL draft identities check existing canonical players by normalized name and compatible position type before creating minimal prospect rows with `nhl_id = NULL`.
NHL draft imports resolve or create the canonical prospect before landing refresh, then use a draft payload NHL player id, an existing canonical `nhl_id`, or the NHL Stats cayenne lookup to refresh NHL landing metadata and upsert stats when an NHL id can be found.
NHL roster and prospect landing imports retry transient provider failures for the individual NHL player id and record persistent transient failures without aborting the whole team import.
NHL roster, prospect, and draft per-player landing failures are recorded and skipped without aborting the broader import run.
Non-NHL provider identity links may queue asynchronous NHL identity enrichment for canonical players without `nhl_id`; enrichment only updates the canonical player after exactly one NHL Stats player candidate is validated through the NHL player landing endpoint.
Canonical player creates and identity-evidence updates may queue the same asynchronous NHL identity enrichment when `nhl_id` is null and first-name, last-name, and position-type evidence is usable.
`NhlPlayerIdentityLookup` is the shared NHL Stats name-search and landing-validation abstraction for resolving an NHL player id from either a canonical player or first-name, last-name, and position-type evidence.
When an NHL id is newly assigned to a canonical player, the assignment must queue `RefreshNhlPlayerLandingJob` so the canonical NHL landing import refreshes player metadata and upserts legacy `stats` rows from `seasonTotals`.
NHL identity enrichment links the validated NHL identity to the existing canonical player before running the canonical NHL landing import so the refresh updates that player instead of creating a duplicate NHL row.
NHL identity enrichment rejects NHL Stats last-name spillover by exact normalized name and may reduce same-name ambiguity to a single current-team candidate.
NHL identity enrichment must merge a null-`nhl_id` duplicate into an existing NHL-owned player when validated NHL lookup resolves to an already-owned NHL id, then refresh NHL landing stats for the retained player.
NHL player resolution can queue `nhl:resolve --players` for background reconciliation, while the admin import registry uses `nhl:resolve --players --inline` so progress reflects actual resolver outcomes instead of unique job dispatch attempts.

**Public Interface:**
- `PlayerExternalIdentity`
- `Player::externalIdentities()`
- `PlayerIdentityNormalizer::normalizeName()`
- `PlayerIdentityResolver::upsertNhlIdentity()`
- `PlayerIdentityResolver::upsertNhlDraftIdentity()`
- `ResolveNhlCommand`
- `ImportPlayersJob`
- `ImportNhlDraftPicksJob`
- `ResolveCanonicalPlayerNhlIdentityJob`
- `RefreshNhlPlayerLandingJob`
- `PlayerNhlIdentityObserver`
- `QueueNhlIdentityResolution`
- `NhlPlayerIdentityLookup::resolveForPlayer()`
- `NhlPlayerIdentityLookup::resolveForName()`
- `NhlPlayerIdentityLookup::hasLookupEvidence()`
- `PlayerIdentityResolver::upsertFantraxIdentity()`
- `PlayerIdentityResolver::upsertYahooIdentity()`
- `PlayerIdentityResolver::upsertCapWagesIdentity()`
- `PlayerIdentityResolver::resolveNonAuthorityIdentity()`
- `PlayerIdentityResolver::linkIdentityToPlayer()`
- `PlayerExternalIdentityLinked`
- `RefreshCapWagesContractsForIdentityJob`
- `PlayerIdentityResolver::statusCountsByProvider()`

**Example Usage:**
```php
$identity = app(PlayerIdentityResolver::class)->upsertNhlIdentity($payload);
$player = Player::firstOrNew(['nhl_id' => $payload['playerId']]);
app(PlayerIdentityResolver::class)->linkIdentityToPlayer($identity, $player);
```

---

### CapWages Player Profiles

**Name:** CapWages Player Profiles
**Type:** Provider Detail Import Pattern
**Location:**
- `app/Models/CapWagesPlayer.php`
- `app/Console/Commands/EmptyCapWagesCommand.php`
- `app/Services/ImportCapWagesPlayer.php`
- `database/migrations/*_create_capwages_players_table.php`

**Purpose:**
Store CapWages-owned player profile details separately from provider identity matching and canonical player contracts.

**When to Use:**
Persisting or refreshing CapWages player detail payload fields before or after canonical player linkage.

**When Not to Use:**
Storing canonical player attributes, identity match state, or materialized contract rows before a player link exists.

**Rules:**
CapWages identity/profile import eligibility requires a payable non-buyout contract season at least as recent as the current calendar-year season key; buyout or dead-cap-only rows do not qualify retired/inactive players.
CapWages payloads with durable `nhlId` must link by `players.nhl_id` or remain unresolved; they must not create provisional canonical players with `nhl_id = NULL`.
CapWages list imports crawl pages sequentially with no fixed delay after successful pages; 403 responses trigger backoff.
CapWages player detail imports reuse cached raw payload only when `capwages_players.api_last_updated` is from the current day; older or missing provider freshness metadata requires a live detail request.
CapWages contract entries with signing dates are materialized into signed NHL player transactions, and CapWages import completion reconciles missing contract-signing transactions.
CapWages player detail 5xx responses and connection failures are recorded and skipped so one provider-side player failure does not fail the whole page import.
Admin import progress bars read processed/total counters from `import_runs` instead of parsing terminal output.

**Public Interface:**
- `CapWagesPlayer`
- `cap:empty`
- `ImportCapWagesPlayer::syncBySlug()`
- `ImportCapWagesPlayer::refreshContractsForLinkedIdentity()`

**Example Usage:**
```php
app(ImportCapWagesPlayer::class)->syncBySlug($slug, false);
```

---

### NHL Team Reference Data

**Name:** NHL Team Reference Data
**Type:** Import Reference Data Pattern
**Location:**
- `app/Models/NhlTeam.php`
- `app/Services/ImportNhlTeams.php`
- `app/Services/NhlTeamReference.php`
- `database/migrations/*_create_nhl_teams_table.php`

**Purpose:**
Store NHL-owned team identifiers and names so provider imports can normalize team evidence to NHL abbreviations.

**When to Use:**
Importing NHL team reference data or comparing provider team strings to canonical player team abbreviations.

**When Not to Use:**
Roster membership, standings, franchise history, or season-specific team state.

**Public Interface:**
- `NhlTeam`
- `ImportNhlTeams::sync()`
- `NhlTeamReference::normalizeToAbbrev()`
- `NhlTeamReference::idForAbbrev()`

**Example Usage:**
```php
$abbrev = app(NhlTeamReference::class)->normalizeToAbbrev('Toronto Maple Leafs');
```

---

### NHL Player Transactions

**Name:** NHL Player Transactions
**Type:** Import History Pattern
**Location:**
- `app/Models/NhlPlayerTransaction.php`
- `app/Services/ImportCapWagesPlayer.php`
- `database/migrations/*_create_nhl_player_transactions_table.php`

**Purpose:**
Store real hockey player movement history separately from fantasy roster transactions.

**When to Use:**
Persisting provider-sourced NHL-domain player acquisition, contract-signing, or movement events.

**When Not to Use:**
Fantasy roster adds, drops, trades, waivers, or league-specific transactions.

**Public Interface:**
- `NhlPlayerTransaction`
- `ImportCapWagesPlayer::syncBySlug()`

**Example Usage:**
```php
NhlPlayerTransaction::query()->where('player_id', $player->id)->get();
```

---

## Platform Integrations

### Fantasy Provider League Access

**Name:** Fantasy Provider League Access
**Type:** External Platform Integration Pattern
**Location:**
- `app/Support/FantasyProvider.php`
- `app/Services/FantasyIntegrationState.php`
- `app/Services/FantasyLeagueAccess.php`
- `app/Http/Controllers/LeagueController.php`

**Purpose:**
Expose provider-neutral fantasy readiness and active league access for navigation and direct Leagues route access.

**When to Use:**
Determining whether a user can access the Leagues experience, returning a fantasy provider state payload, or querying active leagues across ready providers.

**When Not to Use:**
Storing provider credentials, importing provider-specific payloads, or replacing Fantrax/Yahoo sync jobs.

**Public Interface:**
- `FantasyProvider::leagueProviders()`
- `FantasyIntegrationState::forProvider()`
- `FantasyIntegrationState::forUser()`
- `FantasyLeagueAccess::canViewLeagues()`
- `FantasyLeagueAccess::activeLeaguesForUser()`

**Example Usage:**
```php
$leagues = app(FantasyLeagueAccess::class)->activeLeaguesForUser($user)->get();
```

---

### Fantrax User Connection

**Name:** Fantrax User Connection
**Type:** External Platform Integration Pattern
**Location:**
- `app/Http/Controllers/FantraxUserController.php`
- `app/Models/IntegrationSecret.php`
- `app/Services/ConnectFantraxUser.php`
- `app/Services/FantasyIntegrationState.php`
- `app/Services/ImportUserFantraxLeagues.php`
- `app/Events/FantraxUserConnected.php`
- `app/Listeners/HandleFantraxUserConnected.php`

**Purpose:**
Store a user's Fantrax integration secret, trigger Fantrax league/team imports, deactivate user league assignments on disconnect, and derive user-facing Fantrax readiness state for navigation and route access.

**When to Use:**
Connecting, disconnecting, or refreshing a user's Fantrax account.

**When Not to Use:**
Provider accounts owned by an organization, Patreon sync, or Discord OAuth login.

**Public Interface:**
- `integrations.fantrax.save`
- `integrations.fantrax.disconnect`
- `ConnectFantraxUser`
- `IntegrationSecret`
- `FantasyIntegrationState`
- `FantraxUserConnected`

**Example Usage:**
```php
$state = app(FantasyIntegrationState::class)->forProvider($user, FantasyProvider::FANTRAX);
```

---

### Fantrax League Sync

**Name:** Fantrax League Sync
**Type:** External Platform Sync Pattern
**Location:**
- `app/Services/FantraxLeagueService.php`
- `app/Console/Commands/FantraxInspectLogosCommand.php`
- `app/Services/SyncFantraxLeague.php`
- `app/Services/FantraxScoringCategoryMapper.php`
- `app/Services/PlatformLeagueScoringCategoryService.php`
- `app/Services/FantraxLogoSyncService.php`
- `app/Support/FantraxLogoBrowserProfile.php`
- `app/Services/ImportFantraxLeagues.php`
- `app/Services/ImportFantraxPlayers.php`
- `app/Jobs/SyncFantraxLeagueJob.php`
- `app/Jobs/SyncFantraxTeamJob.php`
- `app/Events/TeamLogosSynced.php`
- `app/Listeners/SyncFantraxRosterMembershipsForLinkedIdentity.php`
- `app/Models/PlatformLeague.php`
- `app/Models/PlatformLeagueScoringCategory.php`
- `app/Models/PlatformTeam.php`
- `app/Models/PlatformRosterMembership.php`
- `app/Models/PlatformPlayerId.php`

**Purpose:**
Map Fantrax leagues, teams, rosters, and player identities into platform-neutral tables.
Provider league and team logo URLs may be stored on the platform-neutral league and team rows when Fantrax exposes them. Fantrax team logos must come from explicit provider payload fields, not derived team-id paths.
Authenticated browser logo extraction backend code is league-scoped and persists only explicit provider logo URLs when the browser profile is ready, but the commissioner-facing UI entry point is hibernated until a per-commissioner collection approach replaces the shared server browser profile model.
Completed browser logo extraction may broadcast a user-scoped logo update event so the league list can update without a page refresh.
Fantrax league scoring categories are enriched from the platform category mapping dictionary during league sync, with manual mappings overriding dictionary auto mappings while preserving support metadata.
Provider scoring categories that power league UI persist to platform_league_scoring_categories; platform_leagues.scoring_settings may retain raw provider scoring payload for fallback and audit context.
Provider scoring category sync normalizes shorthand group names, upserts current rows, and deletes stale rows no longer present in the provider payload.
Scheduled league refresh uses the same league sync path as the user-triggered top-level Leagues refresh action and runs on a four-hour cadence.

**When to Use:**
Syncing Fantrax leagues, updating rosters, resolving Fantrax player identity, or rendering league availability.

**When Not to Use:**
NHL source-of-truth stats imports or Patreon membership syncing.

**Public Interface:**
- `SyncFantraxLeagueJob`
- `SyncFantraxTeamJob`
- `SyncFantraxRosterMembershipsForLinkedIdentity`
- `FantraxLeagueService`
- `FantraxScoringCategoryMapper`
- `PlatformLeagueScoringCategoryService`
- `FantraxLogoSyncService`
- `leagues.team-logos.sync`
- `community.leagues.team-logos.sync`
- `TeamLogosSynced`
- `fantrax:inspect-logos`
- `FantraxLogoBrowserProfile`
- Platform league/team/roster models
- Platform league scoring category rows

**Example Usage:**
```php
dispatch(new SyncFantraxLeagueJob($platformLeague->id));
```

---

### Platform Category Mapping Import

**Name:** Platform Category Mapping Import
**Type:** Admin Import Dictionary
**Location:**
- `app/Services/ImportPlatformCategoryMappings.php`
- `app/Console/Commands/ImportFantraxCategoryDefinitionsCommand.php`
- `app/Console/Commands/BackfillPlatformLeagueScoringCategoriesCommand.php`
- `app/Models/FantasyScoringCategoryMapping.php`
- `app/Models/PlatformLeagueScoringCategory.php`
- `database/migrations/2026_07_09_000000_create_fantasy_scoring_category_mappings_table.php`
- `database/migrations/2026_07_10_000000_create_platform_league_scoring_categories_table.php`

**Purpose:**
Import provider scoring category definitions and DynastyIQ stat alignment metadata into a platform-neutral dictionary table.
Existing platform_leagues.scoring_settings category JSON may be backfilled into platform_league_scoring_categories before JSON fallback is retired.

**When to Use:**
Importing Fantrax category definition templates, preparing provider scoring settings for supportability checks, or adding category dictionaries for future fantasy platforms.

**When Not to Use:**
Syncing league rosters, importing player stats, or running NHL game and season stat pipelines.

**Public Interface:**
- `AdminImports::sources()`
- `fantrax:import-category-definitions`
- `platform-leagues:backfill-scoring-categories`
- `ImportPlatformCategoryMappings::import()`

**Example Usage:**
```bash
php artisan fantrax:import-category-definitions --path=docs/import-templates/fantrax_category_alignment.csv
```

---

### Fantrax Drafting Window

**Name:** Fantrax Drafting Window
**Type:** External Platform View Payload
**Location:**
- `app/Services/FantraxDraftingWindow.php`
- `app/Http/Controllers/CommunityLeagues.php`
- `app/ViewModels/LeagueShowViewModel.php`
- `app/DTO/LeagueShowDto.php`
- `resources/views/communities/leagues/show.blade.php`

**Purpose:**
Normalize Fantrax draft payloads into a stable community league show drafting view contract.

**When to Use:**
Rendering Fantrax draft results on community league detail pages or enriching drafted Fantrax player ids for display.

**When Not to Use:**
Persisting Fantrax draft history, syncing Fantrax rosters, or importing canonical NHL source data.

**Public Interface:**
- `FantraxDraftingWindow`
- `CommunityLeagues::show`
- `LeagueShowViewModel`
- `LeagueShowDto`

**Example Usage:**
```php
$drafting = app(FantraxDraftingWindow::class)->normalize($leagueInfo, $draftResults);
```

---

### Draft Central Draft Model

**Name:** Draft Central Draft Model
**Type:** First-Party Domain Model
**Location:**
- `app/Models/Draft.php`
- `app/Models/DraftPick.php`
- `app/Models/DraftNotificationSetting.php`
- `app/Events/DraftPickMade.php`
- `database/migrations/*_create_drafts_tables.php`

**Purpose:**
Store platform-neutral draft configuration, pick order, pick selections, and notification settings for Draft Central.

**When to Use:**
Building Draft Central UI/API payloads, mirroring provider draft data, or running DynastyIQ-managed drafts.

**When Not to Use:**
Raw provider polling snapshots or current platform roster sync.

**Public Interface:**
- `Draft`
- `DraftPick`
- `DraftNotificationSetting`
- `DraftPickMade`
- `drafts`
- `draft_picks`
- `draft_notification_settings`

**Example Usage:**
```php
$draft = Draft::query()->with(['picks', 'currentPick'])->findOrFail($draftId);
```

---

### League Commissioner Role

**Name:** League Commissioner Role
**Type:** Authorization Pattern
**Location:**
- `app/Models/LeagueUserRole.php`
- `database/migrations/*_create_league_user_roles_table.php`
- `app/Console/Commands/BackfillLeagueCommissionersCommand.php`
- `app/Http/Controllers/LeaguesController.php`
- `app/Http/Controllers/LeagueController.php`

**Purpose:**
Assign users commissioner authority for one specific internal league.

**When to Use:**
League-level management controls, default commissioner assignment during community league creation, and backfilling existing community leagues from organization ownership.

**When Not to Use:**
Organization-wide roles, provider roster ownership, or team membership.

**Public Interface:**
- `LeagueUserRole`
- `league_user_roles`
- `User::isCommissionerForLeague`
- `leagues:backfill-commissioners`

**Example Usage:**
```php
$canManage = $user->isCommissionerForLeague($league->id);
```

---

### Platform State Service

**Name:** Platform State Service
**Type:** Support Service
**Location:**
- `app/Services/PlatformState.php`
- `app/Models/PlatformLeague.php`
- `app/Models/PlatformTeam.php`

**Purpose:**
Provide reusable platform integration state checks and display helpers.

**When to Use:**
Rendering connection state or choosing behavior based on an external platform link.

**When Not to Use:**
Executing sync jobs or storing provider credentials.

**Public Interface:**
- `PlatformState`

**Example Usage:**
```php
$state = app(PlatformState::class);
```

---

### Platform League Roster Slots

**Name:** Platform League Roster Slots
**Type:** External Platform Integration Pattern
**Location:**
- `app/Models/PlatformLeagueRosterSlot.php`
- `app/Models/PlatformLeague.php`
- `app/Http/Controllers/LeagueController.php`
- `app/Services/YahooFantasyLeagueService.php`
- `database/migrations/*_create_platform_league_roster_slots_table.php`

**Purpose:**
Store provider-neutral roster slot order and counts for platform leagues so roster displays can follow league settings.

**When to Use:**
Importing league roster position settings from external fantasy providers or sorting platform roster memberships for display.

**When Not to Use:**
Current roster membership storage, scoring categories, standings, or provider setting guesses without source payloads.

**Public Interface:**
- `PlatformLeagueRosterSlot`
- `PlatformLeague::rosterSlots()`
- `platform_league_roster_slots`

**Example Usage:**
```php
$slotOrder = $league->rosterSlots()->pluck('sort_order', 'slot');
```

---

### Yahoo Fantasy League Sync

**Name:** Yahoo Fantasy League Sync
**Type:** External Platform Sync Pattern
**Location:**
- `app/Jobs/SyncYahooTeamRosterJob.php`
- `app/Services/YahooFantasyLeagueService.php`
- `app/Services/YahooFantasyClient.php`
- `app/Models/YahooFantasyConnection.php`
- `app/Models/PlatformLeague.php`
- `app/Models/PlatformLeagueRosterSlot.php`
- `app/Models/PlatformTeam.php`
- `app/Http/Controllers/LeagueController.php`
- `app/Http/Controllers/Admin/YahooOAuthProbeController.php`

**Purpose:**
Map a connected Yahoo Fantasy user's hockey leagues, league settings, scoring categories, roster settings, and teams into platform-neutral league tables.

**When to Use:**
Syncing Yahoo leagues after OAuth connection, refreshing Yahoo league assignments, importing Yahoo league settings, scoring categories, roster position settings, or making Yahoo leagues available through the shared Leagues experience.

**When Not to Use:**
Yahoo player imports, standings, scoreboard data, or transactions.

**Public Interface:**
- `SyncYahooTeamRosterJob`
- `PlatformLeague::rosterSlots()`
- `YahooFantasyLeagueService::syncForConnection()`
- `YahooFantasyClient::fantasyXmlForConnection()`
- `integrations.yahoo.callback`
- `admin.yahoo.oauth.callback`
- `leagues.yahoo.resync`

**Example Usage:**
```php
$summary = app(YahooFantasyLeagueService::class)->syncForConnection($connection);
```

---

### Yahoo Fantasy Roster Sync

**Name:** Yahoo Fantasy Roster Sync
**Type:** External Platform Sync Pattern
**Location:**
- `app/Jobs/SyncYahooTeamRosterJob.php`
- `app/Services/YahooFantasyRosterService.php`
- `app/Services/YahooFantasyClient.php`
- `app/Services/PlayerIdentityResolver.php`
- `app/Models/YahooPlayer.php`
- `app/Models/PlatformTeam.php`

**Purpose:**
Map Yahoo Fantasy team rosters through Yahoo player staging and canonical identities into platform roster memberships.

**When to Use:**
Syncing current Yahoo rosters for owned Yahoo teams or updating Yahoo platform roster memberships.

**When Not to Use:**
Yahoo league discovery, all-player collection imports, or rendering league rosters directly from provider XML.

**Notes:**
Roster sync reports payload, identity resolution, membership insert/update, and stale-close counts, and may persist the latest summary on `platform_teams.extras` for diagnostics.

**Public Interface:**
- `SyncYahooTeamRosterJob`
- `YahooFantasyRosterService::syncTeam()`

**Example Usage:**
```php
SyncYahooTeamRosterJob::dispatch($platformTeam->id);
```

---

### Patreon Provider Sync

**Name:** Patreon Provider Sync
**Type:** External Provider Sync Pattern
**Location:**
- `app/Http/Controllers/PatreonConnectController.php`
- `app/Http/Controllers/PatreonSyncController.php`
- `app/Http/Controllers/PatreonWebhookController.php`
- `app/Services/Patreon/PatreonClient.php`
- `app/Services/Patreon/PatreonSyncService.php`
- `app/Services/Patreon/MembershipSyncService.php`
- `app/Services/Patreon/TierMapper.php`
- `app/Console/Commands/PatreonNightlySync.php`
- `app/Models/ProviderAccount.php`
- `app/Models/MembershipEvent.php`

**Purpose:**
Connect organization-owned Patreon accounts, sync campaign tiers and memberships, and process Patreon webhooks.

**When to Use:**
Patreon OAuth, manual sync, nightly sync, webhook handling, tier mapping, and provider membership updates.

**When Not to Use:**
User login OAuth, Fantrax imports, or manual community membership changes unrelated to provider sync.

**Public Interface:**
- `patreon.redirect`
- `patreon.callback`
- `patreon.sync`
- `patreon.webhook`
- `PatreonSyncService`
- `MembershipSyncService`

**Example Usage:**
```php
app(PatreonSyncService::class)->syncProviderAccount($providerAccount);
```

---

### Discord Server Connection

**Name:** Discord Server Connection
**Type:** External Platform Connection Pattern
**Location:**
- `app/Models/DiscordServer.php`
- `app/Http/Controllers/Auth/DiscordServerCallbackController.php`
- `app/Http/Controllers/Api/DiscordWebhookController.php`
- `app/Events/DiscordMemberConnected.php`
- `app/Listeners/MarkDiscordConnected.php`

**Purpose:**
Attach Discord guilds to organizations and connect Discord member events to users, memberships, and bot behavior.

**When to Use:**
Guild connection OAuth, Discord member join webhooks, and server-aware community features.

**When Not to Use:**
General OAuth login, Fantrax role sync internals, or non-Discord provider data.

**Public Interface:**
- `discord-server.redirect`
- `discord-server.callback`
- `discord.webhooks.memberJoined`
- `DiscordServer`

**Example Usage:**
```php
event(new DiscordMemberConnected($user, $discordServer));
```

---

### Discord Bot Bridge

**Name:** Discord Bot Bridge
**Type:** Cross-Runtime Integration Pattern
**Location:**
- `app/Events/BotFantraxLinked.php`
- `app/Http/Controllers/Api/DiscordWebhookController.php`
- `resources/js/echo.js`
- `diq-bot/bot.js`
- `diq-bot/features/connect.js`
- `diq-bot/features/user-teams.js`
- `diq-bot/features/assign-fantrax-roles.js`

**Purpose:**
Bridge Laravel domain events and HTTP APIs to the Discord bot process.

**When to Use:**
Bot-facing user team lookup, Fantrax linked status checks, bot-originated Fantrax connection, and bot role assignment events.

**When Not to Use:**
Browser-only realtime UI or backend-only sync logic.

**Public Interface:**
- `/api/discord/users/{discord_id}`
- `/api/discord/fantrax/connect`
- `/api/diq/is-fantrax`
- `BotFantraxLinked`
- `/diq connect`

**Example Usage:**
```php
event(new BotFantraxLinked($user));
```

---

## Admin & Imports

### Admin Import Registry

**Name:** Admin Import Registry
**Type:** Admin Service Pattern
**Location:**
- `app/Services/AdminImports.php`
- `app/Http/Controllers/Admin/ImportsController.php`
- `app/Jobs/RunImportCommandJob.php`
- `resources/views/admin/imports.blade.php`
- `resources/js/admin/admin-hub.js`

**Purpose:**
Define manually runnable import sources and dispatch them as queue batches from the admin UI.

**When to Use:**
Adding or running an admin-triggered import command.

**When Not to Use:**
NHL stage orchestration, which is handled by the NHL import pipeline.

**Public Interface:**
- `AdminImports::sources()`
- `AdminImports::dispatch()`
- `admin.imports`
- `admin.imports.run`
- `admin.imports.retry`

**Example Usage:**
```php
$batch = app(AdminImports::class)->dispatch('fantrax');
```

---

### Import Broadcast Stream

**Name:** Import Broadcast Stream
**Type:** Realtime Feedback Pattern
**Location:**
- `app/Support/ImportBroadcast.php`
- `app/Events/ImportStreamEvent.php`
- `app/Models/ImportRun.php`
- `resources/js/admin/admin-hub.js`

**Purpose:**
Publish import progress and operational messages to admin UI consumers, and persist admin import lifecycle timing through ImportRun.

**When to Use:**
Long-running import workflows that should surface progress without requiring a manual refresh.

**When Not to Use:**
Domain events that should not be displayed as import-stream UI messages.

**Public Interface:**
- `ImportBroadcast`
- `ImportStreamEvent`
- `ImportRun`

**Example Usage:**
```php
$broadcast = new ImportBroadcast('fantrax', $batchId);
$broadcast->started();
```

---

### Admin Player Triage

**Name:** Admin Player Triage
**Type:** Admin Workflow Pattern
**Location:**
- `app/Http/Controllers/Admin/PlayerTriageController.php`
- `app/Models/Player.php`
- `app/Models/PlayerExternalIdentity.php`
- `resources/views/admin/player-triage.blade.php`
- `resources/js/admin/player-triage.js`
- `resources/js/admin/player-triage-inbox.js`
- `resources/js/admin/player-triage-detail.js`

**Purpose:**
Resolve imported provider player identities against canonical application players through a manual admin inbox.

**When to Use:**
Reviewing low-confidence unmatched, candidate, or conflicting provider identities by default; filtering external identities by source provider for missing canonical links or source-to-source coverage through canonical player links; switching between unmatched, matched, and all triage states; displaying or applying current resolver recommendations; linking matching-source identities to covered canonical players; manually linking an identity to a canonical player; or ignoring/deferring an identity that should not be linked yet.

The browser-side inbox owns the currently loaded identity JSON and filters that loaded payload locally for player search input while preserving SearchField focus. Its count display distinguishes total matching identities from the browser-loaded slice when server payloads are capped. It also owns inbox loading, loaded, empty, and error rendering from page-level events. The browser-side detail panel owns selected identity detail JSON and renders loading, loaded, empty, and error states from page-level events; it may show an immediate selected-identity preview header before full detail JSON resolves and receives full detail data from a dedicated web-authenticated admin JSON route.
Embedded player triage JSON requests should return inbox/detail payloads without re-rendering the full Blade fragment after the initial fragment load, and resolver recommendation previews must bound canonical player candidates by identity name evidence before applying PHP scoring.

**When Not to Use:**
Normal player display, legacy platform identity workflows, bulk triage, or automated NHL stat imports that do not require manual identity triage.

**Public Interface:**
- `admin.player-triage`
- `admin.player-triage.detail`
- `admin.player-triage.link`
- `admin.player-triage.link-matching-source`
- `admin.player-triage.link-external-source`
- `admin.player-triage.create-canonical`
- `admin.player-triage.resolve`
- `admin.player-triage.ignore`
- `admin.player-triage.defer`

**Example Usage:**
```php
Route::post('/player-triage/identities/{identity}/link', [PlayerTriageController::class, 'link']);
```

---

### Admin NHL Game Imports

**Name:** Admin NHL Game Imports
**Type:** Admin Queue Orchestration UI
**Location:**
- `app/Http/Controllers/Admin/NhlGameImportController.php`
- `app/Jobs/SeasonSumJob.php`
- `app/Models/NhlGameImportRun.php`
- `app/Events/NhlGameImportStatusUpdated.php`
- `resources/views/admin/operational.blade.php`
- `resources/js/admin/admin-hub.js`
- `docs/architecture/admin/AdminNhlGameImports.yaml`

**Purpose:**
Dispatch and monitor NHL game discovery, processing, and season stat rollup jobs from the admin control panel.

**When to Use:**
Admin-triggered NHL game discovery, discovery-row processing actions, season stat rollups, and recent orchestration progress display.

**When Not to Use:**
Synchronous web-request imports, NHL stage transformation ownership, or replacing `nhl_import_progress`.

**Public Interface:**
- `admin.nhl-game-imports.status`
- `admin.nhl-game-imports.discover`
- `admin.nhl-game-imports.process`
- `admin.nhl-game-imports.season-sync`
- `NhlGameImportRun`
- `NhlGameImportStatusUpdated`

**Example Usage:**
```php
NhlOrchestratorJob::dispatch($gameDate);
SeasonSumJob::dispatch($seasonId, $runId);
```

---

## UI Architecture

### UI Design Authority

**Name:** UI Design Authority
**Type:** UI Governance Rule
**Location:**
- `docs/UI_DESIGN.md`
- `docs/ui_backlog.md`

**Purpose:**
Define the required UI direction for new and materially touched UI while tracking existing deviations separately.

**When to Use:**
Any UI change, page migration, Blade component update, or frontend JavaScript work.

**When Not to Use:**
Backend-only changes with no user-facing UI impact.

**Public Interface:**
- `docs/UI_DESIGN.md`
- `docs/ui_backlog.md`

**Example Usage:**
```text
New interactive pages must use the page module contract unless an approved exception is documented.
```

---

### Slide-Over Drawer

**Name:** Slide-Over Drawer
**Type:** Reusable Blade UI Component
**Location:**
- `resources/views/components/ui/slide-over.blade.php`
- `docs/architecture/ui/SlideOverDrawer.yaml`

**Purpose:**
Provide the canonical right-side slide-over shell for Blade and Alpine workflows.

**When to Use:**
Long create or configuration forms that should open in a right-side panel while preserving page context.

**When Not to Use:**
Short confirmation prompts, dropdown menus, small popovers, or global application state.

**Public Interface:**
- `x-ui.slide-over`
- `show`
- `close-action`
- `title-id`
- `max-width`

**Example Usage:**
```blade
<x-ui.slide-over show="drawerOpen" close-action="drawerOpen = false" title-id="example-drawer-title">
    <h3 id="example-drawer-title">Configure</h3>
</x-ui.slide-over>
```

---

### Date Field

**Name:** Date Field
**Type:** Reusable Blade UI Component
**Location:**
- `resources/views/components/ui/date-field.blade.php`
- `docs/architecture/ui/DateField.yaml`

**Purpose:**
Provide a native browser date input with a full-field click target that opens the calendar picker where supported.

**When to Use:**
Blade and Alpine forms that need a single native date input with normal form styling.

**When Not to Use:**
Custom range selectors, date-time inputs, or custom calendar widgets.

**Public Interface:**
- `x-ui.date-field`
- `id`
- `label`
- `model`
- standard Blade attribute passthrough

**Example Usage:**
```blade
<x-ui.date-field id="starts-at" label="Start date" model="form.start" />
```

---

### Search Field

**Name:** Search Field
**Type:** Frontend Component
**Location:**
- `resources/js/components/SearchField/search-field.js`

**Purpose:**
Provide a reusable Tailwind-styled search input enhancement for page-local modules.

**When to Use:**
Debounced search inputs that should emit page-local JavaScript events with clear, loading, disabled, and error states.

**When Not to Use:**
Domain-specific API calls, URL state, rendering, or app-wide frontend state.

**Public Interface:**
- `SearchField`
- `mountSearchFields()`
- `search-field:change`

**Example Usage:**
```blade
<div data-search-field data-search-field-name="search">
    <input name="search" />
</div>
```

---

### Select Field

**Name:** Select Field
**Type:** Frontend Component
**Location:**
- `resources/js/components/SelectField/select-field.js`

**Purpose:**
Provide a reusable Tailwind-styled native select enhancement for page-local modules.

**When to Use:**
Native select controls that should emit page-local JavaScript events with `name`, `value`, `label`, `scope`, and `id` metadata.

**When Not to Use:**
Searchable option pickers, custom menu behavior, domain-specific filtering, API calls, URL state, or app-wide frontend state.

**Public Interface:**
- `SelectField`
- `mountSelectFields()`
- `select-field:change`

**Example Usage:**
```blade
<div data-select-field data-select-field-name="source">
    <select name="source"></select>
</div>
```

---

### Toast Stack

**Name:** Toast Stack
**Type:** UI Feedback Component
**Location:**
- `resources/js/components/toast-stack.js`
- `resources/js/components/toast-stack.test.js`
- `resources/views/partials/toast-container.blade.php`

**Purpose:**
Provide non-blocking page feedback for success, warning, and error messages.

**When to Use:**
AJAX actions, community mutations, admin actions, and other UI flows that need transient feedback.

**When Not to Use:**
Blocking confirmations, form validation summaries, or persistent status panels.

**Public Interface:**
- `toast-stack.js`
- `toast-container.blade.php`

**Example Usage:**
```js
window.dispatchEvent(new CustomEvent('toast', {
    detail: { type: 'success', message: 'Saved.' },
}));
```

---

### Card Section Component

**Name:** Card Section Component
**Type:** Blade Component
**Location:**
- `app/View/Components/CardSection.php`
- `resources/views/components/card-section.blade.php`

**Purpose:**
Render reusable collapsible or grouped card-like page sections.

**When to Use:**
Existing views that need consistent section framing while they await alignment with the new UI standards.

**When Not to Use:**
New card-heavy dashboard layouts that conflict with `docs/UI_DESIGN.md`.

**Public Interface:**
- `<x-card-section>`

**Example Usage:**
```blade
<x-card-section title="Members">
    ...
</x-card-section>
```

---

### New League Modal

**Name:** New League Modal
**Type:** Blade / Alpine Component
**Location:**
- `resources/views/components/new-league-modal.blade.php`
- `resources/views/leagues.blade.php`
- `resources/views/communities/leagues/show.blade.php`

**Purpose:**
Provide a reusable modal for creating league records from league-oriented pages.

**When to Use:**
League creation flows that reuse the same form and server route.

**When Not to Use:**
Non-league creation flows or new multi-field create flows that should use a page-module pattern.

**Public Interface:**
- `<x-new-league-modal>`
- `organizations.leagues.store`

**Example Usage:**
```blade
<x-new-league-modal :organization="$organization" />
```

---

### Leagues Hub Layout

**Name:** Leagues Hub Layout
**Type:** UI Layout Pattern
**Location:**
- `app/View/Components/LeaguesHubLayout.php`
- `resources/views/components/leagues-hub-layout.blade.php`
- `resources/js/components/LeaguesHubLayout.js`
- `resources/js/leagues-hub.js`

**Purpose:**
Provide a reusable shell for league hub pages and progressive enhancement behavior, including viewport-constrained Draft Central tab panels.

**When to Use:**
League hub views that need shared page chrome, JavaScript behavior, or Draft Central tab content that must scroll internally without making the whole page scroll.

**When Not to Use:**
Community-only pages or generic stats pages.

**Public Interface:**
- `<x-leagues-hub-layout>`
- `resources/js/components/LeaguesHubLayout.js`

**Example Usage:**
```blade
<x-leagues-hub-layout>
    ...
</x-leagues-hub-layout>
```

---

### Range Slider

**Name:** Range Slider
**Type:** Frontend Component
**Location:**
- `resources/js/components/RangeSlider/range-slider.js`
- `resources/js/components/RangeSlider/range-slider.css`

**Purpose:**
Provide a reusable range slider interaction for filter-style UI.

**When to Use:**
Range-based inputs where the existing component behavior and visual treatment are appropriate.

**When Not to Use:**
Simple numeric inputs, hidden filters, or new UI that can use native controls under `docs/UI_DESIGN.md`.

**Public Interface:**
- Range slider JavaScript module
- Range slider CSS module

**Example Usage:**
```js
// Imported by page code that mounts range-filter controls.
```

---

### Livewire Stats Tables

**Name:** Livewire Stats Tables
**Type:** Legacy / Active UI Pattern
**Location:**
- `app/Livewire/PlayerStatsPage.php`
- `app/Livewire/PlayerStatsTable.php`
- `app/Livewire/PlayerRankingsTable.php`
- `resources/views/livewire/`

**Purpose:**
Render selected stats and ranking UI through Livewire components.

**When to Use:**
Maintaining existing Livewire surfaces.

**When Not to Use:**
New interactive pages unless a deliberate exception to `docs/UI_DESIGN.md` is approved.

**Public Interface:**
- Livewire components under `app/Livewire`
- Blade partials under `resources/views/livewire`

**Example Usage:**
```blade
<livewire:player-stats-table />
```

---

## Preferences & Personalization

### User Preferences

**Name:** User Preferences
**Type:** User Settings Pattern
**Location:**
- `app/Http/Controllers/UserPreferencesController.php`
- `database/migrations/2025_09_07_222339_create_user_preferences.php`
- `resources/views/nav/partials/_right-account-drawer-notifications.blade.php`

**Purpose:**
Persist user-level UI and notification preferences separately from core identity fields.

**When to Use:**
User-specific settings that should survive sessions and do not belong on `users`.

**When Not to Use:**
Organization settings, provider connection state, or authorization data.

**Public Interface:**
- `user.preferences.update`
- `user_preferences`

**Example Usage:**
```php
return redirect()->route('user.preferences.update');
```

---

## Public Content

### Static Markdown Content

**Name:** Static Markdown Content
**Type:** Public Content Pattern
**Location:**
- `resources/markdown/policy.md`
- `resources/markdown/terms.md`
- `resources/markdown/discord-join-welcome.md`
- `resources/markdown/discord-user-teams.md`
- `resources/views/policy.blade.php`
- `resources/views/terms.blade.php`

**Purpose:**
Keep static public or Discord-facing copy in repository-managed markdown files.

**When to Use:**
Terms, policy, Discord help, and similar repo-owned static content.

**When Not to Use:**
Authenticated app pages, provider-synced content, or future `/learn/{slug}` marketing pages until that route/content system exists.

**Public Interface:**
- Markdown files in `resources/markdown/`
- Blade views that render or include their content

**Example Usage:**
```text
resources/markdown/terms.md
```

---

### Future Marketing Pages

**Name:** Future Marketing Pages
**Type:** Reserved Public Page Pattern
**Location:**
- `docs/UI_DESIGN.md`
- Future `resources/content/marketing/`

**Purpose:**
Reserve a repo-managed markdown content model for public marketing pages under `/learn/{slug}`.

**When to Use:**
Future SEO or education pages that should be versioned with the app and not database-managed.

**When Not to Use:**
Current static policy/terms markdown, authenticated app content, or a CMS.

**Public Interface:**
- Future `GET /learn/{slug}`
- Future `resources/content/marketing/{slug}.md`

**Example Usage:**
```markdown
---
title: "Fantasy Hockey Draft Strategy"
slug: "fantasy-hockey-draft-strategy"
---
```

---

## Testing

### Frontend Component Tests

**Name:** Frontend Component Tests
**Type:** JavaScript Test Pattern
**Location:**
- `resources/js/components/toast-stack.test.js`
- `resources/js/components/__tests__/community-members-store.test.js`
- `resources/js/admin/admin-hub.test.js`

**Purpose:**
Test reusable frontend modules independently from Blade rendering.

**When to Use:**
New reusable JavaScript modules or changes to existing page-state helpers.

**When Not to Use:**
Backend domain behavior or browser-level end-to-end assertions.

**Public Interface:**
- Existing JavaScript test files
- Project npm test script, when configured

**Example Usage:**
```js
expect(store.members.length).toBe(1);
```

---

### Laravel Feature and Unit Tests

**Name:** Laravel Feature and Unit Tests
**Type:** Backend Test Pattern
**Location:**
- `tests/`
- `tests/Pest.php`
- `docs/CONVENTIONS.md`

**Purpose:**
Validate backend behavior, routes, models, services, policies, and domain workflows.

**When to Use:**
All new backend behavior and bug fixes.

**When Not to Use:**
New PHPUnit-style test classes; new tests must use Pest per `docs/CONVENTIONS.md`.

**Public Interface:**
- Pest `it()`
- Pest `expect()`
- Laravel test helpers

**Example Usage:**
```php
it('returns stats payload rows', function () {
    $this->getJson(route('api.stats'))->assertOk();
});
```

---

**End of ARCHITECTURE_INVENTORY**
