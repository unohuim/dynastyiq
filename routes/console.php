<?php

use App\Console\Commands\PatreonNightlySync;
use Illuminate\Foundation\Console\ClosureCommand;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;


Schedule::command('nhl:discover --days=2')->dailyAt('07:50');
Schedule::command('nhl:process')->everyTenMinutes();
Schedule::command('patreon:sync-nightly')->dailyAt('02:00');

Artisan::starting(function ($artisan) {
    $artisan->resolveCommands([
        PatreonNightlySync::class,
    ]);
});
