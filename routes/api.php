<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PlayerStatsController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
| These are automatically prefixed with /api by Laravel.
|
*/



// Protected user route
Route::middleware('auth:sanctum')
    ->get('/user', fn(Request $r) => $r->user())
    ->name('api.user');

// Public Player‑Stats JSON endpoint
Route::get('/player-stats', [PlayerStatsController::class, 'payload'])
    ->name('api.player-stats');
