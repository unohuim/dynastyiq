<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PlayerStatsController;
use App\Http\Controllers\Api\DiscordWebhookController;
use App\Http\Controllers\Api\NhlGamePredictionsController;
use App\Http\Controllers\Api\NhlReferenceController;
use App\Http\Controllers\Api\NhlSeasonStatsController;
use App\Http\Controllers\StatsController;
use App\Http\Controllers\PatreonWebhookController;

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

Route::post('/discord/fantrax/connect', [DiscordWebhookController::class, 'connectFantrax'])
    ->name('discord.fantrax.connect');

// Patreon Webhooks
Route::post('/patreon/webhook', [PatreonWebhookController::class, 'handle'])
    ->name('patreon.webhook');

Route::middleware('api.client:nhl-reference:read')->group(function (): void {
    Route::get('/nhl-teams', [NhlReferenceController::class, 'teams'])
        ->name('api.nhl-reference.teams');
    Route::get('/nhl-players', [NhlReferenceController::class, 'players'])
        ->name('api.nhl-reference.players');
});

Route::middleware('api.client:nhl-stats:read')->group(function (): void {
    Route::get('/nhl-season-stats', NhlSeasonStatsController::class)
        ->name('api.nhl-season-stats.index');
    Route::get('/nhl-game-predictions', NhlGamePredictionsController::class)
        ->name('api.nhl-game-predictions.show');
});





// Stats
Route::get('/stats', [StatsController::class, 'payload'])->name('api.stats');
