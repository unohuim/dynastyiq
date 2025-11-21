<?php

use App\Console\Commands\FantraxImportPlayersCommand;
use App\Console\Commands\FantraxSyncCommand;
use App\Console\Commands\ImportCapWagesCommand;
use App\Console\Commands\ImportNhlPlayersCommand;
use App\Console\Commands\NhlDiscoverCommand;
use App\Console\Commands\NhlProcessCommand;
use App\Console\Commands\PatreonNightlySync;
use App\Console\Commands\SumNhlSeasonCommand;
use App\Http\Middleware\HydrateDiscordSession;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Sentry\Laravel\Integration;



return Application::configure(basePath: dirname(__DIR__))
    ->withCommands([
        PatreonNightlySync::class,
    ])
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        //$middleware->web(HydrateDiscordSession::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        Integration::handles($exceptions);
    })->create();
