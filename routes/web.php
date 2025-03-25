<?php

use App\Http\Controllers\PlayByPlayController;
use App\Http\Controllers\PlayerImportController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\StatController;
use App\Http\Controllers\SeasonStatController;


Route::get('/', function () {
    return view('welcome');
});


Route::get('/prospects', [StatController::class, 'prospects']);


Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
    Route::get('/dashboard', function () {
        return view('dashboard');
    })->name('dashboard');


    Route::controller(PlayerImportController::class)->group(function() {
        Route::get('/players-import', 'import');
        Route:: get('/players-import-stats', 'importStats');
    });


    Route::controller(PlayByPlayController::class)->group(function() {
        Route::get('/import-playbyplay/{game_id}', 'ImportPlayByPlay');
        Route::get('/import-games-by-date/{date}', 'ImportPlayByPlaysByDate');
        Route::get('/import-playbyplays', 'ImportPlayByPlays');
    });

    Route::controller(SeasonStatController::class)->group(function() {
        Route::get('/sumseason/{season_id}', 'Sum');
    });
});
