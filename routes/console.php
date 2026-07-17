<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('nhl:import --players')->dailyAt('03:30')->timezone('America/Toronto');
Schedule::command('fx:import --players')->dailyAt('03:30')->timezone('America/Toronto');
Schedule::command('nhl:resolve --players')->dailyAt('03:30')->timezone('America/Toronto');
Schedule::command('cap:import --per-page=100 --all=true')->dailyAt('03:30')->timezone('America/Toronto');

Schedule::command('nhl:discover --days=2')->dailyAt('07:50');
Schedule::command('nhl:process')->everyMinute();
Schedule::command('patreon:sync-nightly')->dailyAt('02:00');
Schedule::command('leagues:refresh-connected')->everyThreeHours()->timezone('America/Toronto');
