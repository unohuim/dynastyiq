<?php

use Illuminate\Foundation\Console\ClosureCommand;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;


Schedule::command('nhl:discover --days=2')->dailyAt('03:50');
Schedule::command('nhl:process --limit=500')->everyTenMinutes();
