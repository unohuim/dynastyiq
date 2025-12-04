<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use App\Http\Middleware\GlobalFreshInstallGuard;
use App\Models\Organization;
use App\Http\Controllers\PlayerStatsController;
use App\Http\Controllers\PlayByPlayController;
use App\Http\Controllers\PlayerImportController;
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

// Public pages: Prospects and Players
Route::get('/players', [PlayerStatsController::class, 'index'])
    ->name('players.index');


Route::get('/stats', [StatsController::class, 'index'])->name('stats.index');

Route::get('/api/stats', [StatsController::class, 'payload'])
    ->middleware('web')
    ->name('stats.payload'); // public but session-aware



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



//socialite auth routes
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







Route::get('/discord/join', function () {
    // optional: you could log the click or ensure user is authenticated here
    return Redirect::away(config('services.discord.invite'));
})->name('discord.join');


// Login route handled by Fortify; see FortifyServiceProvider for Discord redirect.






// Authenticated dashboard/admin routes
Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
    Route::get('/dashboard', fn () => view('dashboard'))->name('dashboard');

    Route::get('/admin', [\App\Http\Controllers\Admin\DashboardController::class, 'index'])
        ->name('admin.dashboard');


    //Communities
    Route::get('/communities', [CommunitiesController::class, 'index'])
        ->middleware(['auth', 'can:view-nav-communities'])
        ->name('communities.index');


    //Leagues
    Route::post('/organizations/{organization}/leagues/{league?}', [LeaguesController::class, 'store'])
        ->middleware('auth')
        ->name('organizations.leagues.store');


    //Community Leagues
    Route::get('/communities/{c_id}/leagues/{l_id}', [CommunityLeagues::class, 'show'])
        ->middleware('auth')
        ->name('community.leagues.show');


    //User preferences
    Route::put('/me/preferences',
        [\App\Http\Controllers\UserPreferencesController::class, 'upsert']
    )->name('user.preferences.update');

    // Organization settings
    Route::put('/organizations/{organization?}/settings',
        [\App\Http\Controllers\OrganizationsController::class, 'updateSettings']
    )->name('organizations.settings.update');

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


    Route::controller(PlayerRankingController::class)->group(function () {
        Route::get('/players/rankings',         'index')->name('player.rankings.index');
        Route::post('/players/rankings/upload', 'upload')->name('player.rankings.upload');
        Route::post('/players/rankings/manual', 'manual')->name('player.rankings.manual');
    });

    Route::prefix('admin')
        ->middleware(['admin.super', 'admin.lifecycle'])
        ->group(function () {
            Route::get('/initialize', [\App\Http\Controllers\Admin\InitializationController::class, 'index'])
                ->name('admin.initialize.index');
            Route::post('/initialize', [\App\Http\Controllers\Admin\InitializationController::class, 'run'])
                ->name('admin.initialize.run');

            Route::get('/imports', [\App\Http\Controllers\Admin\ImportsController::class, 'index'])
                ->name('admin.imports');
            Route::post('/imports/{key}/run', [\App\Http\Controllers\Admin\ImportsController::class, 'run'])
                ->name('admin.imports.run');
            Route::post('/imports/{key}/retry', [\App\Http\Controllers\Admin\ImportsController::class, 'retry'])
                ->name('admin.imports.retry');

            Route::get('/player-triage', [\App\Http\Controllers\Admin\PlayerTriageController::class, 'index'])
                ->name('admin.player-triage');
            Route::post('/player-triage/{platform}/{id}/link', [\App\Http\Controllers\Admin\PlayerTriageController::class, 'link'])
                ->name('admin.player-triage.link');
            Route::post('/player-triage/{platform}/{id}/variant', [\App\Http\Controllers\Admin\PlayerTriageController::class, 'addVariant'])
                ->name('admin.player-triage.variant');
            Route::post('/player-triage/{platform}/{id}/defer', [\App\Http\Controllers\Admin\PlayerTriageController::class, 'defer'])
                ->name('admin.player-triage.defer');

            Route::get('/scheduler', [\App\Http\Controllers\Admin\SchedulerController::class, 'index'])
                ->name('admin.scheduler');
        });

    // Patreon Memberships
    Route::get('/organizations/{organization}/patreon/redirect', [PatreonConnectController::class, 'redirect'])
        ->name('patreon.redirect');
    Route::get('/organizations/patreon/callback', [PatreonConnectController::class, 'callback'])
        ->name('patreon.callback');
    Route::delete('/organizations/{organization}/patreon', [PatreonConnectController::class, 'disconnect'])
        ->name('patreon.disconnect');
    Route::post('/organizations/{organization}/patreon/sync', [PatreonSyncController::class, 'sync'])
        ->name('patreon.sync');

    Route::controller(PlayByPlayController::class)->group(function () {
        Route::get('/admin/import-playbyplays', 'ImportPlayByPlays');
    });

    Route::controller(SeasonStatController::class)->group(function () {
        Route::get('/sumseason/{season_id}', 'Sum');
    });


    //STATS
    Route::get('/stats/units', [StatsUnitsController::class, 'index'])->name('stats.units.index');




    //LEAGUES
    Route::get('/leagues', [LeagueController::class, 'index'])
        ->name('leagues.index');

    Route::get('/leagues/{league_id}', [LeagueController::class, 'show'])
        ->name('leagues.show');

    Route::get('/leagues/{league_id}/panel', [LeagueController::class, 'panel'])
        ->name('leagues.panel');



    //fantrax testing
    Route::get('/admin/fantrax', function () {
        app(ImportUserFantraxLeagues::class)->import(Auth::user());
    })->name('admin.fantrax.import');



    //fantrax leagues
    // Route::get('/fantrax/leagues', [FantraxController::class, 'index'])
    //     ->name('fantrax.leagues.index');

    // Route::get('/fantrax/leagues/create', [FantraxController::class, 'create'])
    //     ->name('fantrax.leagues.create');

    // Route::put('/fantrax/leagues/{league}', [FantraxController::class, 'update'])
    //     ->name('fantrax.leagues.update');

    // Route::get('/fantrax/leagues/{league}/edit', [FantraxController::class, 'edit'])
    //     ->name('fantrax.leagues.edit');

    // Route::get('/fantrax/leagues/{league}', [FantraxController::class, 'show'])
    //     ->name('fantrax.leagues.show');

    // Route::delete('/fantrax/leagues/{league}', [FantraxController::class, 'destroy'])
    //     ->name('fantrax.leagues.destroy');

    // Route::post('/fantrax/leagues/{league}/sync', [FantraxController::class, 'sync'])
    //     ->name('fantrax.leagues.sync');



    // Fantrax integration (temp routes)
    Route::prefix('integrations/fantrax')
    ->name('integrations.fantrax.')
    ->controller(FantraxUserController::class)
    ->group(function () {
        Route::post('save', 'save')->name('save');
        Route::post('disconnect', 'disconnect')->name('disconnect');
    });
});
});

