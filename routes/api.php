<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PlayerStatsController;
use App\Http\Controllers\Api\DiscordWebhookController;
use App\Http\Controllers\StatsController;

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



// Discord
Route::post('/discord/member-joined', [DiscordWebhookController::class, 'memberJoined'])
    ->name('discord.webhooks.memberJoined');

Route::get('/discord/users/{discord_id}', [DiscordWebhookController::class, 'getUserTeams']);

Route::post('/diq/is-fantrax', [DiscordWebhookController::class, 'isFantrax']);





// Stats
Route::get('/stats', [StatsController::class, 'payload'])->name('api.stats');
