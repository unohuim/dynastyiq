<?php

use Illuminate\Support\Facades\Schedule;


Schedule::command('nhl:discover --days=2')->dailyAt('07:50');
Schedule::command('nhl:process')->everyMinute();
Schedule::command('patreon:sync-nightly')->dailyAt('02:00');
Schedule::command('fantrax:drafts:poll')->everyMinute();
Schedule::command('leagues:refresh-connected')->everyFourHours();
