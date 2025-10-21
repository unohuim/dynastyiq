<?php

use Illuminate\Foundation\Console\ClosureCommand;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;


Schedule::command('nhl:discover --days=2')->dailyAt('07:50');
Schedule::command('nhl:process')->everyTenMinutes();
