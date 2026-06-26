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

### Discord Bot Runtime

**Name:** Discord Bot Runtime  
**Type:** External Node Service  
**Location:**
- `diq-bot/bot.js`
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
- Reverb/Pusher channel events emitted by Laravel

**Example Usage:**
```js
// Bot feature code calls Laravel API endpoints to resolve Discord user team data.
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
League detail pages that need organization, platform league, teams, roster, and availability context.

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
- `app/Services/ImportNHLPlayByPlay.php`
- `app/Services/SumNHLPlayByPlay.php`
- `app/Services/ImportNhlShifts.php`
- `app/Services/ImportNhlBoxscore.php`
- `app/Services/MakeNhlGameShiftUnits.php`
- `app/Services/ConnectEventsToUnitShifts.php`
- `app/Services/SumNhlGameUnits.php`
- `app/Services/SumNhlSeasonStats.php`

**Purpose:**  
Own the actual NHL data transformations used by queued import jobs and admin commands.

**When to Use:**  
Implementing or changing a single NHL import stage.

**When Not to Use:**  
Coordinating stage order; use `NhlImportOrchestrator` for orchestration.

**Public Interface:**
- Stage-specific service classes
- Stage-specific queued jobs

**Example Usage:**
```php
app(ImportNHLPlayByPlay::class)->import($gameId);
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
- `app/Services/PlayerIdentityNormalizer.php`
- `app/Services/PlayerIdentityResolver.php`
- `database/migrations/*_create_player_external_identities_table.php`

**Purpose:**  
Preserve provider-sourced hockey player identities separately from canonical players so imports can be idempotent, auditable, and expandable across NHL, Fantrax, CapWages, and future providers.

**When to Use:**  
Importing provider player records or resolving provider IDs to canonical DynastyIQ players.

**When Not to Use:**  
Storing canonical player attributes, fantasy roster membership, or provider-only import payload semantics owned by another table.

**Public Interface:**
- `PlayerExternalIdentity`
- `Player::externalIdentities()`
- `PlayerIdentityNormalizer::normalizeName()`
- `PlayerIdentityResolver::upsertNhlIdentity()`
- `PlayerIdentityResolver::linkIdentityToPlayer()`
- `PlayerIdentityResolver::statusCountsByProvider()`

**Example Usage:**
```php
$identity = app(PlayerIdentityResolver::class)->upsertNhlIdentity($payload);
$player = Player::firstOrNew(['nhl_id' => $payload['playerId']]);
app(PlayerIdentityResolver::class)->linkIdentityToPlayer($identity, $player);
```

---

## Platform Integrations

### Fantrax User Connection

**Name:** Fantrax User Connection  
**Type:** External Platform Integration Pattern  
**Location:**
- `app/Http/Controllers/FantraxUserController.php`
- `app/Models/IntegrationSecret.php`
- `app/Services/ImportUserFantraxLeagues.php`
- `app/Events/FantraxUserConnected.php`
- `app/Listeners/HandleFantraxUserConnected.php`

**Purpose:**  
Store a user's Fantrax integration secret and trigger Fantrax league/team imports for that user.

**When to Use:**  
Connecting, disconnecting, or refreshing a user's Fantrax account.

**When Not to Use:**  
Provider accounts owned by an organization, Patreon sync, or Discord OAuth login.

**Public Interface:**
- `integrations.fantrax.save`
- `integrations.fantrax.disconnect`
- `IntegrationSecret`
- `FantraxUserConnected`

**Example Usage:**
```php
event(new FantraxUserConnected($user));
```

---

### Fantrax League Sync

**Name:** Fantrax League Sync  
**Type:** External Platform Sync Pattern  
**Location:**
- `app/Services/FantraxLeagueService.php`
- `app/Services/SyncFantraxLeague.php`
- `app/Services/ImportFantraxLeagues.php`
- `app/Services/ImportFantraxPlayers.php`
- `app/Jobs/SyncFantraxLeagueJob.php`
- `app/Jobs/SyncFantraxTeamJob.php`
- `app/Models/PlatformLeague.php`
- `app/Models/PlatformTeam.php`
- `app/Models/PlatformRosterMembership.php`
- `app/Models/PlatformPlayerId.php`

**Purpose:**  
Map Fantrax leagues, teams, rosters, and player identities into platform-neutral tables.

**When to Use:**  
Syncing Fantrax leagues, updating rosters, resolving Fantrax player identity, or rendering league availability.

**When Not to Use:**  
NHL source-of-truth stats imports or Patreon membership syncing.

**Public Interface:**
- `SyncFantraxLeagueJob`
- `SyncFantraxTeamJob`
- `FantraxLeagueService`
- Platform league/team/roster models

**Example Usage:**
```php
dispatch(new SyncFantraxLeagueJob($platformLeague->id));
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
- `diq-bot/features/user-teams.js`
- `diq-bot/features/assign-fantrax-roles.js`

**Purpose:**  
Bridge Laravel domain events and HTTP APIs to the Discord bot process.

**When to Use:**  
Bot-facing user team lookup, Fantrax linked status checks, and bot role assignment events.

**When Not to Use:**  
Browser-only realtime UI or backend-only sync logic.

**Public Interface:**
- `/api/discord/users/{discord_id}`
- `/api/diq/is-fantrax`
- `BotFantraxLinked`

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
Publish import progress and operational messages to admin UI consumers.

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
- `app/Http/Controllers/Admin/AdminPlayersController.php`
- `app/Models/Player.php`
- `app/Models/PlatformPlayerId.php`
- `resources/views/admin/player-triage.blade.php`

**Purpose:**  
Resolve imported platform player identities against canonical application players.

**When to Use:**  
Linking, variant creation, or deferring uncertain player identity matches.

**When Not to Use:**  
Normal player display or automated NHL stat imports that do not require manual identity triage.

**Public Interface:**
- `admin.player-triage`
- `admin.player-triage.link`
- `admin.player-triage.variant`
- `admin.player-triage.defer`

**Example Usage:**
```php
Route::post('/player-triage/{platform}/{id}/link', [PlayerTriageController::class, 'link']);
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
Provide a reusable shell for league hub pages and progressive enhancement behavior.

**When to Use:**  
League hub views that need shared page chrome and JavaScript behavior.

**When Not to Use:**  
Community-only pages or generic stats pages.

**Public Interface:**
- `<x-leagues-hub-layout>`

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
