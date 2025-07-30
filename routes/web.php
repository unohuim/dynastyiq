<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PlayerStatsController;
use App\Http\Controllers\PlayByPlayController;
use App\Http\Controllers\PlayerImportController;
use App\Http\Controllers\PlayerRankingController;
use App\Http\Controllers\SeasonStatController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

Route::get('/', fn () => view('welcome'))->name('welcome');

// Public pages: Prospects and Players
Route::get('/players', [PlayerStatsController::class, 'index'])
    ->name('players.index');

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

    Route::controller(PlayerRankingController::class)->group(function () {
        Route::get('/players/rankings',         'index')->name('player.rankings.index');
        Route::post('/players/rankings/upload', 'upload')->name('player.rankings.upload');
        Route::post('/players/rankings/manual', 'manual')->name('player.rankings.manual');
    });

    Route::controller(PlayByPlayController::class)->group(function () {
        Route::get('/import-playbyplays', 'ImportPlayByPlays');
    });

    Route::controller(SeasonStatController::class)->group(function () {
        Route::get('/sumseason/{season_id}', 'Sum');
    });
});

