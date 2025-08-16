<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PlayerStatsController;
use App\Http\Controllers\PlayByPlayController;
use App\Http\Controllers\PlayerImportController;
use App\Http\Controllers\PlayerRankingController;
use App\Http\Controllers\SeasonStatController;
use App\Http\Controllers\LeagueController;
use Laravel\Socialite\Facades\Socialite;



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
});

