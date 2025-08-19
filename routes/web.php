<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PlayerStatsController;
use App\Http\Controllers\PlayByPlayController;
use App\Http\Controllers\PlayerImportController;
use App\Http\Controllers\PlayerRankingController;
use App\Http\Controllers\SeasonStatController;
use App\Http\Controllers\LeagueController;
use Laravel\Socialite\Facades\Socialite;
use App\Http\Controllers\FantraxUserController;
use App\Http\Controllers\FantraxController;
use App\Services\ImportUserFantraxLeagues;
use App\Http\Controllers\StatsUnitsController;


/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

Route::get('/', fn () => view('welcome'))->name('welcome');

// Public pages: Prospects and Players
Route::get('/players', [PlayerStatsController::class, 'index'])
    ->name('players.index');



//socialite auth routes
Route::get('/auth/discord/redirect', function () {
    return Socialite::driver('discord')
        ->scopes(['identify','email'])
        ->redirect();
})->name('discord.redirect');

Route::get('/auth/discord/callback', \App\Http\Controllers\Auth\SocialiteCallbackController::class)
    ->name('discord.callback');



// Authenticated dashboard/admin routes
Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
    Route::get('/dashboard', fn () => view('dashboard'))->name('dashboard');

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



    //fantrax testing
    Route::get('/admin/fantrax', function () {
        app(ImportUserFantraxLeagues::class)->import(Auth::user());
    })->name('admin.fantrax.import');



    //fantrax leagues
    Route::get('/fantrax/leagues', [FantraxController::class, 'index'])
        ->name('fantrax.leagues.index');

    Route::get('/fantrax/leagues/create', [FantraxController::class, 'create'])
        ->name('fantrax.leagues.create');

    Route::put('/fantrax/leagues/{league}', [FantraxController::class, 'update'])
        ->name('fantrax.leagues.update');

    Route::get('/fantrax/leagues/{league}/edit', [FantraxController::class, 'edit'])
        ->name('fantrax.leagues.edit');

    Route::get('/fantrax/leagues/{league}', [FantraxController::class, 'show'])
        ->name('fantrax.leagues.show');

    Route::delete('/fantrax/leagues/{league}', [FantraxController::class, 'destroy'])
        ->name('fantrax.leagues.destroy');

    Route::post('/fantrax/leagues/{league}/sync', [FantraxController::class, 'sync'])
        ->name('fantrax.leagues.sync');






    // Fantrax integration (temp routes)
    Route::prefix('integrations/fantrax')
    ->name('integrations.fantrax.')
    ->controller(FantraxUserController::class)
    ->group(function () {
        Route::post('save', 'save')->name('save');
        Route::post('disconnect', 'disconnect')->name('disconnect');
    });
});

