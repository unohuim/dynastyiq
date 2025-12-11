<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;

use App\Http\Middleware\GlobalFreshInstallGuard;

use App\Models\Organization;
use App\Services\ImportUserFantraxLeagues;

// Public controllers
use App\Http\Controllers\PlayerStatsController;
use App\Http\Controllers\StatsController;

// Admin controllers
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\InitializationController;
use App\Http\Controllers\Admin\ImportsController;
use App\Http\Controllers\Admin\PlayerTriageController;
use App\Http\Controllers\Admin\SchedulerController;
use App\Http\Controllers\Admin\AdminPlayerController;

// Domain controllers
use App\Http\Controllers\PlayByPlayController;
use App\Http\Controllers\PlayerImportController;
use App\Http\Controllers\PlayerRankingController;
use App\Http\Controllers\SeasonStatController;
use App\Http\Controllers\LeagueController;
use App\Http\Controllers\FantraxUserController;
use App\Http\Controllers\CommunitiesController;
use App\Http\Controllers\LeaguesController;
use App\Http\Controllers\CommunityLeagues;
use App\Http\Controllers\CommunityMemberController;
use App\Http\Controllers\CommunityTierController;
use App\Http\Controllers\PatreonConnectController;
use App\Http\Controllers\PatreonSyncController;
use App\Http\Controllers\StatsUnitsController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

Route::middleware(GlobalFreshInstallGuard::class)->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Public Routes
    |--------------------------------------------------------------------------
    */

    Route::get('/', fn () => view('welcome'))->name('welcome');

    Route::get('/players', [PlayerStatsController::class, 'index'])
        ->name('players.index');

    Route::get('/stats', [StatsController::class, 'index'])
        ->name('stats.index');

    // Public JSON stats for front-end
    Route::get('/api/stats', [StatsController::class, 'payload'])
        ->middleware('web')
        ->name('stats.payload');

    /*
    |--------------------------------------------------------------------------
    | Discord OAuth + Server Attach
    |--------------------------------------------------------------------------
    */

    Route::middleware('auth')->group(function () {

        Route::get('/auth/discord-server/redirect/{organization}', function (Organization $organization) {
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

        Route::post('/auth/discord-server/attach',
            [\App\Http\Controllers\Auth\DiscordServerCallbackController::class, 'attach']
        )->name('discord-server.attach');
    });

    /*
    |--------------------------------------------------------------------------
    | Discord OAuth Login
    |--------------------------------------------------------------------------
    */

    Route::get('/auth/discord/redirect', function () {
        $redirectUri = config('services.discord.redirect') ?: route('discord.callback');

        return Socialite::driver('discord')
            ->scopes(['identify', 'email'])
            ->redirectUrl($redirectUri)
            ->redirect();
    })->name('discord.redirect');

    Route::get(
        '/auth/discord/callback',
        \App\Http\Controllers\Auth\SocialiteCallbackController::class
    )->name('discord.callback');

    Route::get(
        '/auth/discord-server/callback',
        \App\Http\Controllers\Auth\DiscordServerCallbackController::class
    )->name('discord-server.callback');

    /*
    |--------------------------------------------------------------------------
    | Discord Invite Link
    |--------------------------------------------------------------------------
    */

    Route::get('/discord/join', function () {
        return Redirect::away(config('services.discord.invite'));
    })->name('discord.join');

    /*
    |--------------------------------------------------------------------------
    | Authenticated Routes
    |--------------------------------------------------------------------------
    */

    Route::middleware([
        'auth:sanctum',
        config('jetstream.auth_session'),
        'verified',
    ])->group(function () {

        Route::get('/dashboard', fn () => view('dashboard'))
            ->name('dashboard');

        /*
        |--------------------------------------------------------------------------
        | Admin Dashboard
        |--------------------------------------------------------------------------
        */

        Route::get('/admin', [DashboardController::class, 'index'])
            ->name('admin.dashboard');

        /*
        |--------------------------------------------------------------------------
        | Admin API ( SPA data for admin panel )
        |--------------------------------------------------------------------------
        */

        Route::prefix('admin/api')
            ->middleware(['admin.super', 'admin.lifecycle'])
            ->group(function () {

                // NEW JSON ENDPOINT REQUIRED BY ALPINE
                Route::get('/players', [AdminPlayerController::class, 'index'])
                    ->name('admin.api.players');
            });

        /*
        |--------------------------------------------------------------------------
        | Admin Tools (Initialization, Imports, Triage, Scheduler)
        |--------------------------------------------------------------------------
        */

        Route::prefix('admin')
            ->middleware(['admin.super', 'admin.lifecycle'])
            ->group(function () {

                // Initialization
                Route::get('/initialize', [InitializationController::class, 'index'])
                    ->name('admin.initialize.index');

                Route::post('/initialize', [InitializationController::class, 'run'])
                    ->name('admin.initialize.run');

                // Real-time Import Tools
                Route::get('/imports', [ImportsController::class, 'index'])
                    ->name('admin.imports');

                Route::post('/imports/{key}/run', [ImportsController::class, 'run'])
                    ->name('admin.imports.run');

                Route::post('/imports/{key}/retry', [ImportsController::class, 'retry'])
                    ->name('admin.imports.retry');

                // Player Triage
                Route::get('/player-triage', [PlayerTriageController::class, 'index'])
                    ->name('admin.player-triage');

                Route::post('/player-triage/{platform}/{id}/link', [PlayerTriageController::class, 'link'])
                    ->name('admin.player-triage.link');

                Route::post('/player-triage/{platform}/{id}/variant', [PlayerTriageController::class, 'addVariant'])
                    ->name('admin.player-triage.variant');

                Route::post('/player-triage/{platform}/{id}/defer', [PlayerTriageController::class, 'defer'])
                    ->name('admin.player-triage.defer');

                // Scheduler UI
                Route::get('/scheduler', [SchedulerController::class, 'index'])
                    ->name('admin.scheduler');
            });

        /*
        |--------------------------------------------------------------------------
        | Community Panels
        |--------------------------------------------------------------------------
        */

        Route::get('/communities', [CommunitiesController::class, 'index'])
            ->middleware('can:view-nav-communities')
            ->name('communities.index');

        Route::prefix('/communities/{organization}')
            ->middleware('auth')
            ->group(function () {
                Route::resource('/members', CommunityMemberController::class)->except(['create', 'edit', 'show']);
                Route::resource('/tiers', CommunityTierController::class)->except(['create', 'edit', 'show']);
            });

        /*
        |--------------------------------------------------------------------------
        | Players Import + PBP Import
        |--------------------------------------------------------------------------
        */

        Route::controller(PlayerImportController::class)->group(function () {
            Route::get('/admin/players-import', 'importNHL');
            Route::get('/admin/fantrax-import', 'importFantrax');
            Route::get('/admin/capwages-import', 'importCapWages');
            Route::get('/admin/daily-import', 'importDaily');
        });

        Route::controller(PlayByPlayController::class)->group(function () {
            Route::get('/admin/pbp-import', 'ImportNHLPlayByPlay');
            Route::get('/admin/sum/{season_id}', 'sum')
                ->where('season_id', '^\d{8}$');
        });

        /*
        |--------------------------------------------------------------------------
        | Rankings + Preferences
        |--------------------------------------------------------------------------
        */

        Route::controller(PlayerRankingController::class)->group(function () {
            Route::get('/players/rankings', 'index')->name('player.rankings.index');
            Route::post('/players/rankings/upload', 'upload')->name('player.rankings.upload');
            Route::post('/players/rankings/manual', 'manual')->name('player.rankings.manual');
        });

        Route::put('/me/preferences', [\App\Http\Controllers\UserPreferencesController::class, 'upsert'])
            ->name('user.preferences.update');

        /*
        |--------------------------------------------------------------------------
        | Patreon
        |--------------------------------------------------------------------------
        */

        Route::controller(PatreonConnectController::class)->group(function () {
            Route::get('/organizations/{organization}/patreon/redirect', 'redirect')->name('patreon.redirect');
            Route::get('/organizations/patreon/callback', 'callback')->name('patreon.callback');
            Route::delete('/organizations/{organization}/patreon', 'disconnect')->name('patreon.disconnect');
        });

        Route::post('/organizations/{organization}/patreon/sync', [PatreonSyncController::class, 'sync'])
            ->name('patreon.sync');

        /*
        |--------------------------------------------------------------------------
        | Stats + Leagues
        |--------------------------------------------------------------------------
        */

        Route::get('/stats/units', [StatsUnitsController::class, 'index'])
            ->name('stats.units.index');

        Route::get('/leagues', [LeagueController::class, 'index'])->name('leagues.index');
        Route::get('/leagues/{league_id}', [LeagueController::class, 'show'])->name('leagues.show');
        Route::get('/leagues/{league_id}/panel', [LeagueController::class, 'panel'])->name('leagues.panel');

        /*
        |--------------------------------------------------------------------------
        | Fantrax Integration
        |--------------------------------------------------------------------------
        */

        Route::get('/admin/fantrax', function () {
            app(ImportUserFantraxLeagues::class)->import(Auth::user());
        })->name('admin.fantrax.import');

        Route::prefix('integrations/fantrax')
            ->name('integrations.fantrax.')
            ->controller(FantraxUserController::class)
            ->group(function () {
                Route::post('/save', 'save')->name('save');
                Route::post('/disconnect', 'disconnect')->name('disconnect');
            });
    });
});
