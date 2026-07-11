<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use App\Http\Middleware\GlobalFreshInstallGuard;
use App\Models\Organization;
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
use App\Http\Controllers\LeaguesController;
use App\Http\Controllers\CommunityLeagues;
use App\Http\Controllers\CommunityMemberController;
use App\Http\Controllers\CommunityTierController;
use App\Http\Controllers\PatreonConnectController;
use App\Http\Controllers\PatreonSyncController;
use App\Services\ImportUserFantraxLeagues;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

Route::middleware(GlobalFreshInstallGuard::class)->group(function () {
    Route::get('/', fn () => view('welcome'))->name('welcome');

    // Public pages
    Route::get('/players', [PlayerStatsController::class, 'index'])
        ->name('players.index');

    Route::get('/stats', [StatsController::class, 'index'])->name('stats.index');

    Route::get('/api/stats', [StatsController::class, 'payload'])
        ->middleware('web')
        ->name('stats.payload');

    Route::get('/transactions', [NhlPlayerTransactionController::class, 'index'])
        ->name('transactions.index');

    Route::get('/transactions/payload', [NhlPlayerTransactionController::class, 'payload'])
        ->name('transactions.payload');

    // Discord Server joins
    Route::middleware('auth')->get('/auth/discord-server/redirect/{organization}', function (Organization $organization) {
        $user = Auth::user();

        $isAdmin = $user?->roles()
            ->wherePivot('organization_id', $organization->id)
            ->max('level') >= 10;
        abort_unless($isAdmin, 403);

        $state = encrypt(['org_id' => $organization->id]);

        return Socialite::driver('discord')
            ->scopes(['identify', 'guilds'])
            ->with(['state' => $state, 'prompt' => 'consent'])
            ->redirectUrl(route('discord-server.callback'))
            ->redirect();
    })->name('discord-server.redirect');

    Route::middleware('auth')->post('/auth/discord-server/attach', [\App\Http\Controllers\Auth\DiscordServerCallbackController::class, 'attach'])
        ->name('discord-server.attach');

    // Discord OAuth login
    Route::get('/auth/discord/redirect', function () {
        $redirectUri = config('services.discord.redirect') ?: route('discord.callback');

        return Socialite::driver('discord')
            ->scopes(['identify','email'])
            ->redirectUrl($redirectUri)
            ->redirect();
    })->name('discord.redirect');

    Route::get('/auth/discord/callback', \App\Http\Controllers\Auth\SocialiteCallbackController::class)
        ->name('discord.callback');

    Route::get('/auth/discord-server/callback', \App\Http\Controllers\Auth\DiscordServerCallbackController::class)
        ->name('discord-server.callback');

    Route::get('/discord/join', fn () => Redirect::away(config('services.discord.invite')))
        ->name('discord.join');

    // Authenticated dashboard/admin routes
    Route::middleware([
        'auth:sanctum',
        config('jetstream.auth_session'),
        'verified',
    ])->group(function () {

        Route::get('/dashboard', fn () => view('dashboard'))->name('dashboard');

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

        // Community Leagues
        Route::get('/communities/{c_id}/leagues/{l_id}', [CommunityLeagues::class, 'show'])
            ->middleware('auth')
            ->name('community.leagues.show');
        Route::get('/communities/{c_id}/leagues/{l_id}/fantrax-aav-export', [CommunityLeagues::class, 'exportFantraxAav'])
            ->middleware('auth')
            ->name('community.leagues.fantrax-aav-export');
        Route::put('/communities/{c_id}/leagues/{l_id}/draft-settings', [CommunityLeagues::class, 'updateDraftSettings'])
            ->middleware('auth')
            ->name('community.leagues.draft-settings.update');
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
        Route::controller(PlayerImportController::class)->group(function () {
            Route::get('/admin/players-import',  'importNHL');
            Route::get('/admin/fantrax-import',  'importFantrax');
            Route::get('/admin/capwages-import', 'importCapWages');
            Route::get('/admin/daily-import',    'importDaily');
        });

        Route::controller(PlayByPlayController::class)->group(function () {
            Route::get('/admin/pbp-import', 'ImportNHLPlayByPlay');
            Route::get('/admin/sum/{season_id}', 'sum')
                ->where('season_id', '^\d{8}$');
        });

        Route::any('/user/setup/fantrax', [LeagueController::class, 'import']);

        // Player Rankings
        Route::controller(PlayerRankingController::class)->group(function () {
            Route::get('/players/rankings',         'index')->name('player.rankings.index');
            Route::post('/players/rankings/upload', 'upload')->name('player.rankings.upload');
            Route::post('/players/rankings/manual', 'manual')->name('player.rankings.manual');
        });

        /*
        |--------------------------------------------------------------------------
        | ADMIN API (SPA JSON for Alpine)
        |--------------------------------------------------------------------------
        |
        | ⭐ This is the ONLY NEW ROUTE ADDED.
        |
        */

        Route::prefix('admin')
            ->middleware(['admin.super', 'admin.lifecycle'])
            ->group(function () {

                // 🔥 Added JSON endpoint for admin player list
                Route::get('/api/players', [\App\Http\Controllers\Admin\AdminPlayersController::class, 'index'])
                    ->name('admin.api.players');

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
                Route::post('/nhl-game-imports/discover', [\App\Http\Controllers\Admin\NhlGameImportController::class, 'discover'])
                    ->name('admin.nhl-game-imports.discover');
                Route::post('/nhl-game-imports/process', [\App\Http\Controllers\Admin\NhlGameImportController::class, 'process'])
                    ->name('admin.nhl-game-imports.process');
                Route::post('/nhl-game-imports/season-sync', [\App\Http\Controllers\Admin\NhlGameImportController::class, 'seasonSync'])
                    ->name('admin.nhl-game-imports.season-sync');

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

                // Scheduler
                Route::get('/scheduler', [\App\Http\Controllers\Admin\SchedulerController::class, 'index'])
                    ->name('admin.scheduler');
            });

        // Patreon
        Route::controller(PatreonConnectController::class)->group(function () {
            Route::get('/organizations/{organization}/patreon/redirect', 'redirect')->name('patreon.redirect');
            Route::get('/organizations/patreon/callback', 'callback')->name('patreon.callback');
            Route::delete('/organizations/{organization}/patreon', 'disconnect')->name('patreon.disconnect');
        });

        Route::post('/organizations/{organization}/patreon/sync', [PatreonSyncController::class, 'sync'])
            ->name('patreon.sync');

        // Play-by-play import all
        Route::controller(PlayByPlayController::class)->group(function () {
            Route::get('/admin/import-playbyplays', 'ImportPlayByPlays');
        });

        // Season Stats
        Route::controller(SeasonStatController::class)->group(function () {
            Route::get('/sumseason/{season_id}', 'Sum');
        });

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
        Route::post('/leagues/{league_id}/team-logos/sync', [LeagueController::class, 'syncTeamLogos'])
            ->name('leagues.team-logos.sync');
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
        Route::get('/admin/fantrax', function () {
            app(ImportUserFantraxLeagues::class)->import(Auth::user());
        })->name('admin.fantrax.import');

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
