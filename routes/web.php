<?php

use Illuminate\Support\Facades\Route;
use App\Http\Middleware\GlobalFreshInstallGuard;
use App\Http\Controllers\PlayerStatsController;
use App\Http\Controllers\PlayByPlayController;
use App\Http\Controllers\PlayerImportController;
use App\Http\Controllers\NhlPlayerTransactionController;
use App\Http\Controllers\PlayerRankingController;
use App\Http\Controllers\SeasonStatController;
use App\Http\Controllers\LeagueController;
use App\Http\Controllers\FantraxUserController;
use App\Http\Controllers\FantraxController;
use App\Http\Controllers\StatsUnitsController;
use App\Http\Controllers\StatsController;
use App\Http\Controllers\CommunitiesController;
use App\Http\Controllers\CommunityMemberProviderRefreshController;
use App\Http\Controllers\DiscordBotInstallController;
use App\Http\Controllers\DiscordCommunityMemberRefreshController;
use App\Http\Controllers\DiscordServerController;
use App\Http\Controllers\LeaguesController;
use App\Http\Controllers\CommunityLeagues;
use App\Http\Controllers\CommunityMemberController;
use App\Http\Controllers\CommunityTierController;
use App\Http\Controllers\PlatformTeamRosterShareLinkController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\PatreonConnectController;
use App\Http\Controllers\PatreonSyncController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

Route::middleware(GlobalFreshInstallGuard::class)->group(function () {
    Route::view('/', 'welcome')->name('welcome');

    Route::post('/analytics/events', [AnalyticsController::class, 'store'])
        ->name('analytics.events.store');

    // Public pages
    Route::get('/players', [PlayerStatsController::class, 'index'])
        ->name('players.index');

    Route::get('/stats', [StatsController::class, 'index'])->name('stats.index');

    Route::get('/api/stats', [StatsController::class, 'payload'])
        ->middleware('web')
        ->name('stats.payload');

    Route::get('/shared/rosters/{token}', [LeagueController::class, 'sharedRoster'])
        ->name('shared.rosters.show');
    Route::get('/shared/rosters/{token}/players-payload', [LeagueController::class, 'sharedRosterPlayersPayload'])
        ->name('shared.rosters.players-payload');

    Route::get('/transactions', [NhlPlayerTransactionController::class, 'index'])
        ->name('transactions.index');

    Route::get('/transactions/payload', [NhlPlayerTransactionController::class, 'payload'])
        ->name('transactions.payload');

    // Discord Server joins
    Route::middleware('auth')
        ->get('/auth/discord-server/redirect/{organization}', [\App\Http\Controllers\Auth\DiscordServerCallbackController::class, 'redirect'])
        ->name('discord-server.redirect');

    Route::middleware('auth')->post('/auth/discord-server/attach', [\App\Http\Controllers\Auth\DiscordServerCallbackController::class, 'attach'])
        ->name('discord-server.attach');

    // Discord OAuth login
    Route::get('/auth/discord/redirect', [\App\Http\Controllers\Auth\SocialiteCallbackController::class, 'redirect'])
        ->name('discord.redirect');

    Route::get('/auth/discord/callback', \App\Http\Controllers\Auth\SocialiteCallbackController::class)
        ->name('discord.callback');

    Route::get('/auth/discord-server/callback', \App\Http\Controllers\Auth\DiscordServerCallbackController::class)
        ->name('discord-server.callback');

    Route::get('/auth/discord-bot-installed', [DiscordBotInstallController::class, 'callback'])
        ->name('discord-server.bot-installed.callback');

    Route::get('/discord/join', [DiscordBotInstallController::class, 'join'])
        ->name('discord.join');

    // Authenticated dashboard/admin routes
    Route::middleware([
        'auth:sanctum',
        config('jetstream.auth_session'),
        'verified',
    ])->group(function () {

        Route::view('/dashboard', 'dashboard')->name('dashboard');

        Route::get('/admin', [\App\Http\Controllers\Admin\DashboardController::class, 'index'])
            ->middleware(['admin.super', 'admin.lifecycle'])
            ->name('admin.dashboard');

        // Yahoo user integration
        Route::get('/integrations/yahoo/redirect', [\App\Http\Controllers\Admin\YahooOAuthProbeController::class, 'redirect'])
            ->name('integrations.yahoo.redirect');
        Route::get('/integrations/yahoo/callback', [\App\Http\Controllers\Admin\YahooOAuthProbeController::class, 'callback'])
            ->name('integrations.yahoo.callback');

        // Communities
        Route::get('/communities', [CommunitiesController::class, 'index'])
            ->middleware(['auth', 'can:view-nav-communities'])
            ->name('communities.index');

        // Leagues
        Route::post('/organizations/{organization}/leagues/{league?}', [LeaguesController::class, 'store'])
            ->middleware('auth')
            ->name('organizations.leagues.store');
        Route::delete('/organizations/{organization}/leagues/{league}', [LeaguesController::class, 'destroy'])
            ->middleware('auth')
            ->name('organizations.leagues.destroy');
        Route::put(
            '/organizations/{organization}/leagues/{league}/provider-binding',
            [LeaguesController::class, 'migrateProviderBinding']
        )
            ->middleware('auth')
            ->name('organizations.leagues.provider-binding.update');
        Route::post(
            '/organizations/{organization}/discord-servers/{discordServer}/members/refresh',
            [DiscordCommunityMemberRefreshController::class, 'store']
        )
            ->middleware('auth')
            ->name('organizations.discord-servers.members.refresh');
        Route::post(
            '/organizations/{organization}/discord-servers/members/refresh',
            [DiscordCommunityMemberRefreshController::class, 'storeAll']
        )
            ->middleware('auth')
            ->name('organizations.discord-servers.members.refresh-all');
        Route::post(
            '/organizations/{organization}/members/refresh',
            [CommunityMemberProviderRefreshController::class, 'store']
        )
            ->middleware('auth')
            ->name('organizations.members.refresh');
        Route::delete(
            '/organizations/{organization}/discord-servers/{discordServer}',
            [DiscordServerController::class, 'destroy']
        )
            ->middleware('auth')
            ->name('organizations.discord-servers.destroy');
        Route::get(
            '/organizations/{organization}/discord-servers/{discordServer}/bot/install',
            [DiscordBotInstallController::class, 'redirect']
        )
            ->middleware('auth')
            ->name('organizations.discord-servers.bot.install');
        Route::get(
            '/organizations/{organization}/discord-servers/{discordServer}/bot/status',
            [DiscordBotInstallController::class, 'status']
        )
            ->middleware('auth')
            ->name('organizations.discord-servers.bot.status');

        // Community Leagues
        Route::get('/communities/{c_id}/leagues/{l_id}', [CommunityLeagues::class, 'show'])
            ->middleware('auth')
            ->name('community.leagues.show');
        Route::get('/communities/{c_id}/leagues/{l_id}/teams', [CommunityLeagues::class, 'teams'])
            ->middleware('auth')
            ->name('community.leagues.teams');
        Route::get('/communities/{c_id}/leagues/{l_id}/teams/{team}/roster-share-links', [PlatformTeamRosterShareLinkController::class, 'index'])
            ->middleware('auth')
            ->name('community.leagues.teams.roster-share-links.index');
        Route::post('/communities/{c_id}/leagues/{l_id}/teams/{team}/roster-share-links', [PlatformTeamRosterShareLinkController::class, 'store'])
            ->middleware('auth')
            ->name('community.leagues.teams.roster-share-links.store');
        Route::put('/communities/{c_id}/leagues/{l_id}/teams/{team}/roster-share-links/{shareLink}', [PlatformTeamRosterShareLinkController::class, 'update'])
            ->middleware('auth')
            ->name('community.leagues.teams.roster-share-links.update');
        Route::delete('/communities/{c_id}/leagues/{l_id}/teams/{team}/roster-share-links/{shareLink}', [PlatformTeamRosterShareLinkController::class, 'destroy'])
            ->middleware('auth')
            ->name('community.leagues.teams.roster-share-links.destroy');
        Route::get('/communities/{c_id}/leagues/{l_id}/draft-summary', [CommunityLeagues::class, 'draftSummary'])
            ->middleware('auth')
            ->name('community.leagues.draft-summary');
        Route::get('/communities/{c_id}/leagues/{l_id}/draft-settings', [CommunityLeagues::class, 'draftSettings'])
            ->middleware('auth')
            ->name('community.leagues.draft-settings');
        Route::get('/communities/{c_id}/leagues/{l_id}/options', [CommunityLeagues::class, 'leagueOptions'])
            ->middleware('auth')
            ->name('community.leagues.options');
        Route::get('/communities/{c_id}/leagues/{l_id}/players-payload', [CommunityLeagues::class, 'playersPayload'])
            ->middleware('auth')
            ->name('community.leagues.players-payload');
        Route::get('/communities/{c_id}/leagues/{l_id}/transactions', [CommunityLeagues::class, 'transactions'])
            ->middleware('auth')
            ->name('community.leagues.transactions.index');
        Route::post('/communities/{c_id}/leagues/{l_id}/transactions/browser-rpc', [CommunityLeagues::class, 'transactionsBrowserRpc'])
            ->middleware('auth')
            ->name('community.leagues.transactions.browser-rpc');
        Route::get('/communities/{c_id}/leagues/{l_id}/draft-testing', [CommunityLeagues::class, 'draftTesting'])
            ->middleware('auth')
            ->name('community.leagues.draft-testing');
        Route::post('/communities/{c_id}/leagues/{l_id}/draft-testing/simulate', [CommunityLeagues::class, 'simulateDraftTestingPick'])
            ->middleware('auth')
            ->name('community.leagues.draft-testing.simulate');
        Route::get('/communities/{c_id}/leagues/{l_id}/stats-payload', [StatsController::class, 'communityLeaguePayload'])
            ->middleware('auth')
            ->name('community.leagues.stats-payload');
        Route::get('/communities/{c_id}/leagues/{l_id}/fantrax-aav-export', [CommunityLeagues::class, 'exportFantraxAav'])
            ->middleware('auth')
            ->name('community.leagues.fantrax-aav-export');
        Route::put('/communities/{c_id}/leagues/{l_id}/draft-settings', [CommunityLeagues::class, 'updateDraftSettings'])
            ->middleware('auth')
            ->name('community.leagues.draft-settings.update');
        Route::put('/communities/{c_id}/leagues/{l_id}/options', [CommunityLeagues::class, 'updateLeagueOptions'])
            ->middleware('auth')
            ->name('community.leagues.options.update');
        Route::post('/communities/{c_id}/leagues/{l_id}/team-logos/sync', [CommunityLeagues::class, 'syncTeamLogos'])
            ->middleware('auth')
            ->name('community.leagues.team-logos.sync');

        // User preferences
        Route::put('/me/preferences', [\App\Http\Controllers\UserPreferencesController::class, 'upsert'])
            ->name('user.preferences.update');

        // Organization settings
        Route::put('/organizations/{organization?}/settings', [\App\Http\Controllers\OrganizationsController::class, 'updateSettings'])
            ->name('organizations.settings.update');

        // Community Members / Tiers
        Route::prefix('/communities/{organization}')
            ->middleware('auth')
            ->group(function () {

                Route::get('/members', [CommunityMemberController::class, 'index'])
                    ->name('communities.members.index');
                Route::post('/members', [CommunityMemberController::class, 'store'])
                    ->name('communities.members.store');
                Route::put('/members/{membership}', [CommunityMemberController::class, 'update'])
                    ->name('communities.members.update');
                Route::delete('/members/{membership}', [CommunityMemberController::class, 'destroy'])
                    ->name('communities.members.destroy');

                Route::get('/tiers', [CommunityTierController::class, 'index'])
                    ->name('communities.tiers.index');
                Route::post('/tiers', [CommunityTierController::class, 'store'])
                    ->name('communities.tiers.store');
                Route::put('/tiers/{membershipTier}', [CommunityTierController::class, 'update'])
                    ->name('communities.tiers.update');
                Route::delete('/tiers/{membershipTier}', [CommunityTierController::class, 'destroy'])
                    ->name('communities.tiers.destroy');
            });

        // Player + import tools
        Route::get('/admin/players-import', [PlayerImportController::class, 'importNHL']);
        Route::get('/admin/fantrax-import', [PlayerImportController::class, 'importFantrax']);
        Route::get('/admin/capwages-import', [PlayerImportController::class, 'importCapWages']);
        Route::get('/admin/daily-import', [PlayerImportController::class, 'importDaily']);

        Route::get('/admin/pbp-import', [PlayByPlayController::class, 'ImportNHLPlayByPlay']);
        Route::get('/admin/sum/{season_id}', [PlayByPlayController::class, 'sum'])
            ->where('season_id', '^\d{8}$');

        Route::any('/user/setup/fantrax', [LeagueController::class, 'import']);

        // Player Rankings
        Route::get('/players/rankings', [PlayerRankingController::class, 'index'])
            ->name('player.rankings.index');
        Route::post('/players/rankings/upload', [PlayerRankingController::class, 'upload'])
            ->name('player.rankings.upload');
        Route::post('/players/rankings/manual', [PlayerRankingController::class, 'manual'])
            ->name('player.rankings.manual');

        /*
        |--------------------------------------------------------------------------
        | ADMIN API (SPA JSON for Alpine)
        |--------------------------------------------------------------------------
        |
        | JSON endpoints used by the admin control panel.
        |
        */

        Route::prefix('admin')
            ->middleware(['admin.super', 'admin.lifecycle'])
            ->group(function () {

                Route::get('/api/players', [\App\Http\Controllers\Admin\AdminPlayersController::class, 'index'])
                    ->name('admin.api.players');

                Route::get('/api-keys', [\App\Http\Controllers\Admin\ApiKeyController::class, 'index'])
                    ->name('admin.api-keys.index');
                Route::post('/api-keys', [\App\Http\Controllers\Admin\ApiKeyController::class, 'store'])
                    ->name('admin.api-keys.store');

                // Imports
                Route::get('/imports', [\App\Http\Controllers\Admin\ImportsController::class, 'index'])
                    ->name('admin.imports');
                Route::post('/imports/{key}/run', [\App\Http\Controllers\Admin\ImportsController::class, 'run'])
                    ->name('admin.imports.run');
                Route::get('/imports/{key}/status', [\App\Http\Controllers\Admin\ImportsController::class, 'status'])
                    ->name('admin.imports.status');
                Route::post('/imports/{key}/retry', [\App\Http\Controllers\Admin\ImportsController::class, 'retry'])
                    ->name('admin.imports.retry');

                // Yahoo OAuth proof
                Route::get('/yahoo/oauth/redirect', [\App\Http\Controllers\Admin\YahooOAuthProbeController::class, 'redirect'])
                    ->name('admin.yahoo.oauth.redirect');
                Route::get('/yahoo/oauth/callback', [\App\Http\Controllers\Admin\YahooOAuthProbeController::class, 'callback'])
                    ->name('admin.yahoo.oauth.callback');
                Route::post('/yahoo/players/import', \App\Http\Controllers\Admin\YahooPlayerImportController::class)
                    ->name('admin.yahoo.players.import');

                // NHL game import orchestration
                Route::get('/nhl-game-imports/status', [\App\Http\Controllers\Admin\NhlGameImportController::class, 'status'])
                    ->name('admin.nhl-game-imports.status');
                Route::get('/nhl-game-imports/source-gaps', [\App\Http\Controllers\Admin\NhlGameImportController::class, 'sourceGaps'])
                    ->name('admin.nhl-game-imports.source-gaps');
                Route::post('/nhl-game-imports/source-gaps/{gameId}/rerun', [\App\Http\Controllers\Admin\NhlGameImportController::class, 'rerunSourceGap'])
                    ->whereNumber('gameId')
                    ->name('admin.nhl-game-imports.source-gaps.rerun');
                Route::post('/nhl-game-imports/games/{gameId}/rerun', [\App\Http\Controllers\Admin\NhlGameImportController::class, 'rerunStoppedGame'])
                    ->whereNumber('gameId')
                    ->name('admin.nhl-game-imports.games.rerun');
                Route::delete('/nhl-game-imports/{run}', [\App\Http\Controllers\Admin\NhlGameImportController::class, 'destroy'])
                    ->whereNumber('run')
                    ->name('admin.nhl-game-imports.destroy');
                Route::post('/nhl-game-imports/rerun-failed', [\App\Http\Controllers\Admin\NhlGameImportController::class, 'rerunFailedOnly'])
                    ->name('admin.nhl-game-imports.rerun-failed');
                Route::post('/nhl-game-imports/duplicate-pbp/scan', [\App\Http\Controllers\Admin\NhlGameImportController::class, 'scanDuplicatePlayByPlay'])
                    ->name('admin.nhl-game-imports.duplicate-pbp.scan');
                Route::post('/nhl-game-imports/duplicate-pbp/{run}/dedupe', [\App\Http\Controllers\Admin\NhlGameImportController::class, 'dedupeDuplicatePlayByPlay'])
                    ->whereNumber('run')
                    ->name('admin.nhl-game-imports.duplicate-pbp.dedupe');
                Route::post('/nhl-game-imports/duplicate-pbp/{run}/rebuild', [\App\Http\Controllers\Admin\NhlGameImportController::class, 'rebuildDuplicatePlayByPlay'])
                    ->whereNumber('run')
                    ->name('admin.nhl-game-imports.duplicate-pbp.rebuild');
                Route::post('/nhl-game-imports/discover', [\App\Http\Controllers\Admin\NhlGameImportController::class, 'discover'])
                    ->name('admin.nhl-game-imports.discover');
                Route::post('/nhl-game-imports/schedule-refresh', [\App\Http\Controllers\Admin\NhlGameImportController::class, 'refreshSchedule'])
                    ->name('admin.nhl-game-imports.schedule-refresh');
                Route::post('/nhl-game-imports/process-shots', [\App\Http\Controllers\Admin\NhlGameImportController::class, 'processShots'])
                    ->name('admin.nhl-game-imports.process-shots');
                Route::post('/nhl-game-imports/process-faceoffs', [\App\Http\Controllers\Admin\NhlGameImportController::class, 'processFaceoffs'])
                    ->name('admin.nhl-game-imports.process-faceoffs');
                Route::post('/nhl-game-imports/process-refs-staff', [\App\Http\Controllers\Admin\NhlGameImportController::class, 'processRefsStaff'])
                    ->name('admin.nhl-game-imports.process-refs-staff');
                Route::post('/nhl-game-imports/process', [\App\Http\Controllers\Admin\NhlGameImportController::class, 'process'])
                    ->name('admin.nhl-game-imports.process');
                Route::post('/nhl-game-imports/season-sync', [\App\Http\Controllers\Admin\NhlGameImportController::class, 'seasonSync'])
                    ->name('admin.nhl-game-imports.season-sync');
                Route::post('/nhl-game-imports/empty-games', [\App\Http\Controllers\Admin\NhlGameImportController::class, 'emptyGames'])
                    ->name('admin.nhl-game-imports.empty-games');

                // NHL shot attempt analysis
                Route::get('/nhl-shot-attempts', [\App\Http\Controllers\Admin\NhlShotAttemptController::class, 'index'])
                    ->name('admin.nhl-shot-attempts.index');
                Route::get('/nhl-shot-attempts/factor-values', [\App\Http\Controllers\Admin\NhlShotAttemptController::class, 'factorValues'])
                    ->name('admin.nhl-shot-attempts.factor-values');
                Route::redirect('/nhl-model-runs', '/admin/nhl-sat-models');
                Route::get('/nhl-sat-models', [\App\Http\Controllers\Admin\NhlModelRunController::class, 'index'])
                    ->name('admin.nhl-sat-models.index');
                Route::post('/nhl-sat-models', [\App\Http\Controllers\Admin\NhlModelRunController::class, 'store'])
                    ->name('admin.nhl-sat-models.store');
                Route::get('/nhl-sat-models/{run}/buckets', [\App\Http\Controllers\Admin\NhlModelRunController::class, 'buckets'])
                    ->whereNumber('run')
                    ->name('admin.nhl-sat-models.buckets');
                Route::post('/nhl-sat-models/{run}/train', [\App\Http\Controllers\Admin\NhlModelRunController::class, 'train'])
                    ->whereNumber('run')
                    ->name('admin.nhl-sat-models.train');
                Route::post('/nhl-sat-models/{run}/profiles/build', [\App\Http\Controllers\Admin\NhlModelRunController::class, 'buildProfiles'])
                    ->whereNumber('run')
                    ->name('admin.nhl-sat-models.profiles.build');
                Route::get('/nhl-sat-models/{run}/profiles', [\App\Http\Controllers\Admin\NhlModelRunController::class, 'profiles'])
                    ->whereNumber('run')
                    ->name('admin.nhl-sat-models.profiles');
                Route::get('/nhl-sat-models/{run}/profiles/training-drift', [\App\Http\Controllers\Admin\NhlModelRunController::class, 'trainingDrift'])
                    ->whereNumber('run')
                    ->name('admin.nhl-sat-models.profiles.training-drift');
                Route::get('/nhl-sat-models/{run}/profiles/bucket-stability', [\App\Http\Controllers\Admin\NhlModelRunController::class, 'genericBucketStability'])
                    ->whereNumber('run')
                    ->name('admin.nhl-sat-models.profiles.bucket-stability');
                Route::get('/nhl-sat-models/{run}/profiles/bucket-stability/export', [\App\Http\Controllers\Admin\NhlModelRunController::class, 'exportGenericBucketStability'])
                    ->whereNumber('run')
                    ->name('admin.nhl-sat-models.profiles.bucket-stability.export');
                Route::post('/nhl-sat-models/{run}/rate-projections/build', [\App\Http\Controllers\Admin\NhlModelRunController::class, 'buildRateProjections'])
                    ->whereNumber('run')
                    ->name('admin.nhl-sat-models.rate-projections.build');
                Route::post('/nhl-sat-models/{run}/toi-projections/build', [\App\Http\Controllers\Admin\NhlModelRunController::class, 'buildToiProjections'])
                    ->whereNumber('run')
                    ->name('admin.nhl-sat-models.toi-projections.build');
                Route::get('/nhl-sat-models/{run}/toi-projections', [\App\Http\Controllers\Admin\NhlModelRunController::class, 'toiProjections'])
                    ->whereNumber('run')
                    ->name('admin.nhl-sat-models.toi-projections');
                Route::post('/nhl-sat-models/{run}/rate-projections/compare/build', [\App\Http\Controllers\Admin\NhlModelRunController::class, 'buildRateComparisons'])
                    ->whereNumber('run')
                    ->name('admin.nhl-sat-models.rate-projections.compare.build');
                Route::get('/nhl-sat-models/{run}/rate-projections/compare/raw', [\App\Http\Controllers\Admin\NhlModelRunController::class, 'compareRateProjectionsRaw'])
                    ->whereNumber('run')
                    ->name('admin.nhl-sat-models.rate-projections.compare.raw');
                Route::get('/nhl-sat-models/{run}/rate-projections/compare/aggregates', [\App\Http\Controllers\Admin\NhlModelRunController::class, 'compareRateProjectionsAggregate'])
                    ->whereNumber('run')
                    ->name('admin.nhl-sat-models.rate-projections.compare.aggregates');
                Route::get('/nhl-sat-models/{run}/rate-projections/compare/aggregates/export', [\App\Http\Controllers\Admin\NhlModelRunController::class, 'exportRateProjectionsAggregate'])
                    ->whereNumber('run')
                    ->name('admin.nhl-sat-models.rate-projections.compare.aggregates.export');
                Route::get('/nhl-sat-models/{run}/rate-projections', [\App\Http\Controllers\Admin\NhlModelRunController::class, 'rateProjections'])
                    ->whereNumber('run')
                    ->name('admin.nhl-sat-models.rate-projections');
                Route::get('/nhl-shot-attempts/context-sat-profiles/aggregate', [\App\Http\Controllers\Admin\NhlShotAttemptController::class, 'contextSatAggregateProfiles'])
                    ->name('admin.nhl-shot-attempts.context-sat-profiles.aggregate');
                Route::get('/nhl-shot-attempts/context-sat-profiles/exact', [\App\Http\Controllers\Admin\NhlShotAttemptController::class, 'contextSatExactProfiles'])
                    ->name('admin.nhl-shot-attempts.context-sat-profiles.exact');
                Route::get('/nhl-shot-attempts/context-sat-profiles/bucket-comparisons', [\App\Http\Controllers\Admin\NhlShotAttemptController::class, 'contextSatBucketComparisons'])
                    ->name('admin.nhl-shot-attempts.context-sat-profiles.bucket-comparisons');
                Route::get('/nhl-shot-attempts/context-sat-profiles/bucket-comparison-rows', [\App\Http\Controllers\Admin\NhlShotAttemptController::class, 'contextSatBucketComparisonRows'])
                    ->name('admin.nhl-shot-attempts.context-sat-profiles.bucket-comparison-rows');
                Route::post('/nhl-shot-attempts/xg', [\App\Http\Controllers\Admin\NhlShotAttemptController::class, 'buildXg'])
                    ->name('admin.nhl-shot-attempts.xg.build');
                Route::post('/nhl-shot-attempts/projections', [\App\Http\Controllers\Admin\NhlShotAttemptController::class, 'buildProjections'])
                    ->name('admin.nhl-shot-attempts.projections.build');
                Route::post('/nhl-shot-attempts/toi-projections', [\App\Http\Controllers\Admin\NhlShotAttemptController::class, 'buildToiProjections'])
                    ->name('admin.nhl-shot-attempts.toi-projections.build');
                Route::post('/nhl-shot-attempts/goalie-workload-projections', [\App\Http\Controllers\Admin\NhlShotAttemptController::class, 'buildGoalieWorkloadProjections'])
                    ->name('admin.nhl-shot-attempts.goalie-workload-projections.build');
                Route::post('/nhl-shot-attempts/goalie-projections', [\App\Http\Controllers\Admin\NhlShotAttemptController::class, 'buildGoalieProjections'])
                    ->name('admin.nhl-shot-attempts.goalie-projections.build');
                Route::post('/nhl-shot-attempts/goalie-chance-profiles', [\App\Http\Controllers\Admin\NhlShotAttemptController::class, 'buildGoalieChanceProfiles'])
                    ->name('admin.nhl-shot-attempts.goalie-chance-profiles.build');
                Route::post('/nhl-shot-attempts/skater-offensive-chance-profiles', [\App\Http\Controllers\Admin\NhlShotAttemptController::class, 'buildSkaterOProfiles'])
                    ->name('admin.nhl-shot-attempts.skater-offensive-chance-profiles.build');
                Route::post('/nhl-shot-attempts/skater-defensive-chance-profiles', [\App\Http\Controllers\Admin\NhlShotAttemptController::class, 'buildSkaterDProfiles'])
                    ->name('admin.nhl-shot-attempts.skater-defensive-chance-profiles.build');
                Route::post('/nhl-shot-attempts/game-context-sat-profiles', [\App\Http\Controllers\Admin\NhlShotAttemptController::class, 'buildGameContextSatProfiles'])
                    ->name('admin.nhl-shot-attempts.game-context-sat-profiles.build');

                // NHL faceoff analysis
                Route::get('/nhl-faceoffs', [\App\Http\Controllers\Admin\NhlFaceoffController::class, 'index'])
                    ->name('admin.nhl-faceoffs.index');

                // Player Triage
                Route::get('/player-triage', [\App\Http\Controllers\Admin\PlayerTriageController::class, 'index'])
                    ->name('admin.player-triage');
                Route::get('/player-triage/identities/{identity}/detail', [\App\Http\Controllers\Admin\PlayerTriageController::class, 'detail'])
                    ->name('admin.player-triage.detail');

                Route::post('/player-triage/identities/{identity}/link', [\App\Http\Controllers\Admin\PlayerTriageController::class, 'link'])
                    ->name('admin.player-triage.link');
                Route::post('/player-triage/identities/{identity}/link-matching-source', [\App\Http\Controllers\Admin\PlayerTriageController::class, 'linkMatchingSource'])
                    ->name('admin.player-triage.link-matching-source');
                Route::post('/player-triage/identities/{identity}/link-external-source', [\App\Http\Controllers\Admin\PlayerTriageController::class, 'linkExternalSource'])
                    ->name('admin.player-triage.link-external-source');
                Route::post('/player-triage/identities/{identity}/create-canonical', [\App\Http\Controllers\Admin\PlayerTriageController::class, 'createCanonical'])
                    ->name('admin.player-triage.create-canonical');
                Route::post('/player-triage/identities/{identity}/resolve', [\App\Http\Controllers\Admin\PlayerTriageController::class, 'resolve'])
                    ->name('admin.player-triage.resolve');
                Route::post('/player-triage/identities/{identity}/ignore', [\App\Http\Controllers\Admin\PlayerTriageController::class, 'ignore'])
                    ->name('admin.player-triage.ignore');
                Route::post('/player-triage/identities/{identity}/defer', [\App\Http\Controllers\Admin\PlayerTriageController::class, 'defer'])
                    ->name('admin.player-triage.defer');

                // NHL game validation triage
                Route::get('/nhl-validations', [\App\Http\Controllers\Admin\NhlGameValidationController::class, 'index'])
                    ->name('admin.nhl-validations.index');
                Route::get('/nhl-pbp', [\App\Http\Controllers\Admin\NhlGameValidationController::class, 'pbp'])
                    ->name('admin.nhl-pbp.index');
                Route::post('/nhl-pbp/{gameId}/enrich', [\App\Http\Controllers\Admin\NhlGameValidationController::class, 'enrichPbp'])
                    ->name('admin.nhl-pbp.enrich');
                Route::post('/nhl-pbp/{gameId}/event-shifts', [\App\Http\Controllers\Admin\NhlGameValidationController::class, 'rebuildEventShifts'])
                    ->name('admin.nhl-pbp.event-shifts');
                Route::get('/nhl-pbp/{gameId}/full', [\App\Http\Controllers\Admin\NhlGameValidationController::class, 'fullPbp'])
                    ->name('admin.nhl-pbp.full');
                Route::get('/nhl-validations/{validation}', [\App\Http\Controllers\Admin\NhlGameValidationController::class, 'show'])
                    ->name('admin.nhl-validations.show');
                Route::post('/nhl-validations/{validation}/accept-exception', [\App\Http\Controllers\Admin\NhlGameValidationController::class, 'acceptException'])
                    ->name('admin.nhl-validations.accept-exception');
                Route::post('/nhl-validations/{validation}/rerun', [\App\Http\Controllers\Admin\NhlGameValidationController::class, 'rerun'])
                    ->name('admin.nhl-validations.rerun');
                Route::post('/nhl-validations/{validation}/rerun-summary', [\App\Http\Controllers\Admin\NhlGameValidationController::class, 'rerunSummary'])
                    ->name('admin.nhl-validations.rerun-summary');
                Route::post('/nhl-validations/{validation}/rerun-boxscore', [\App\Http\Controllers\Admin\NhlGameValidationController::class, 'rerunBoxscore'])
                    ->name('admin.nhl-validations.rerun-boxscore');
                Route::post('/nhl-validations/{validation}/rebuild-game', [\App\Http\Controllers\Admin\NhlGameValidationController::class, 'rebuildGame'])
                    ->name('admin.nhl-validations.rebuild-game');
                Route::post('/nhl-validations/{validation}/rerun-html-pbp', [\App\Http\Controllers\Admin\NhlGameValidationController::class, 'rerunHtmlPbp'])
                    ->name('admin.nhl-validations.rerun-html-pbp');
                Route::post('/nhl-validations/{validation}/accept-api-pbp', [\App\Http\Controllers\Admin\NhlGameValidationController::class, 'acceptApiPbp'])
                    ->name('admin.nhl-validations.accept-api-pbp');
                Route::post('/nhl-validations/{validation}/accept-html-pbp-positions', [\App\Http\Controllers\Admin\NhlGameValidationController::class, 'acceptHtmlPbpPositions'])
                    ->name('admin.nhl-validations.accept-html-pbp-positions');
                Route::post('/nhl-validations/{validation}/acknowledge-pbp-mismatch', [\App\Http\Controllers\Admin\NhlGameValidationController::class, 'acknowledgePbpMismatch'])
                    ->name('admin.nhl-validations.acknowledge-pbp-mismatch');

                // Scheduler
                Route::get('/scheduler', [\App\Http\Controllers\Admin\SchedulerController::class, 'index'])
                    ->name('admin.scheduler');
            });

        // Patreon
        Route::get('/organizations/{organization}/patreon/redirect', [PatreonConnectController::class, 'redirect'])
            ->name('patreon.redirect');
        Route::get('/organizations/patreon/callback', [PatreonConnectController::class, 'callback'])
            ->name('patreon.callback');
        Route::delete('/organizations/{organization}/patreon', [PatreonConnectController::class, 'disconnect'])
            ->name('patreon.disconnect');

        Route::post('/organizations/{organization}/patreon/sync', [PatreonSyncController::class, 'sync'])
            ->name('patreon.sync');

        // Play-by-play import all
        Route::get('/admin/import-playbyplays', [PlayByPlayController::class, 'ImportPlayByPlays']);

        // Season Stats
        Route::get('/sumseason/{season_id}', [SeasonStatController::class, 'Sum']);

        // Stats Units
        Route::get('/stats/units', [StatsUnitsController::class, 'index'])
            ->name('stats.units.index');

        // Leagues
        Route::get('/leagues', [LeagueController::class, 'index'])
            ->name('leagues.index');
        Route::post('/leagues/resync', [LeagueController::class, 'resync'])
            ->name('leagues.resync');
        Route::post('/leagues/yahoo/resync', [LeagueController::class, 'resyncYahoo'])
            ->name('leagues.yahoo.resync');
        Route::put('/leagues/order', [LeagueController::class, 'updateOrder'])
            ->name('leagues.order.update');
        Route::put('/leagues/{league_id}/visibility', [LeagueController::class, 'updateVisibility'])
            ->name('leagues.visibility.update');
        Route::put('/leagues/{league_id}/scoring-settings', [LeagueController::class, 'updateScoringSettings'])
            ->name('leagues.scoring-settings.update');
        Route::put('/leagues/{league_id}/cap-settings', [LeagueController::class, 'updateCapSettings'])
            ->name('leagues.cap-settings.update');
        Route::put('/leagues/{league_id}/cap-projections', [LeagueController::class, 'updateCapProjections'])
            ->name('leagues.cap-projections.update');
        Route::post('/leagues/{league_id}/team-logos/sync', [LeagueController::class, 'syncTeamLogos'])
            ->name('leagues.team-logos.sync');
        Route::post('/integrations/fantrax/logos/connect', [FantraxController::class, 'connectLogoBrowser'])
            ->name('integrations.fantrax.logos.connect');
        Route::get('/leagues/{league_id}/stats-payload', [StatsController::class, 'leaguePayload'])
            ->name('leagues.stats.payload');
        Route::get('/leagues/{league_id}/players-payload', [LeagueController::class, 'playersPayload'])
            ->name('leagues.players.payload');
        Route::get('/leagues/{league_id}/players-free-agents-payload', [LeagueController::class, 'playersFreeAgentsPayload'])
            ->name('leagues.players.free-agents.payload');
        Route::post('/leagues/{league_id}/drafts', [LeagueController::class, 'storeDraft'])
            ->name('leagues.drafts.store');
        Route::put('/leagues/{league_id}/drafts/{draft}/settings', [LeagueController::class, 'updateDraftSettings'])
            ->name('leagues.drafts.settings.update');
        Route::post('/leagues/{league_id}/drafts/{draft}/queue', [LeagueController::class, 'storeDraftQueueItem'])
            ->name('leagues.drafts.queue.store');
        Route::get('/leagues/{league_id}/drafts/{draft}/queue-payload', [LeagueController::class, 'draftQueuePayload'])
            ->name('leagues.drafts.queue.payload');
        Route::delete('/leagues/{league_id}/drafts/{draft}/queue/{queueItem}', [LeagueController::class, 'destroyDraftQueueItem'])
            ->name('leagues.drafts.queue.destroy');
        Route::get('/leagues/{league_id}', [LeagueController::class, 'show'])
            ->name('leagues.show');
        Route::get('/leagues/{league_id}/panel', [LeagueController::class, 'panel'])
            ->name('leagues.panel');

        // Fantrax burst import
        Route::get('/admin/fantrax', [FantraxController::class, 'importUserLeagues'])
            ->name('admin.fantrax.import');

        // Fantrax integration
        Route::prefix('integrations/fantrax')
            ->name('integrations.fantrax.')
            ->controller(FantraxUserController::class)
            ->group(function () {
                Route::post('save', 'save')->name('save');
                Route::post('disconnect', 'disconnect')->name('disconnect');
            });
    });
});
