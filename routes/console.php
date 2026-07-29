<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('nhl:import --players')
    ->dailyAt('03:30')
    ->timezone('America/Toronto')
    ->unlessBetween('08:00', '12:00');
Schedule::command('fx:import --players')
    ->dailyAt('03:30')
    ->timezone('America/Toronto')
    ->unlessBetween('08:00', '12:00');
Schedule::command('nhl:resolve --players')
    ->dailyAt('03:30')
    ->timezone('America/Toronto')
    ->unlessBetween('08:00', '12:00');
Schedule::command('cap:import --per-page=100 --all=true')
    ->dailyAt('03:30')
    ->timezone('America/Toronto')
    ->unlessBetween('08:00', '12:00');

Schedule::command('nhl:discover --days=2')
    ->dailyAt('03:50')
    ->timezone('America/Toronto')
    ->unlessBetween('08:00', '12:00');
Schedule::command('nhl:process')
    ->everyMinute()
    ->timezone('America/Toronto')
    ->unlessBetween('08:00', '12:00');
Schedule::command('patreon:sync-nightly')
    ->dailyAt('03:15')
    ->timezone('America/Toronto')
    ->unlessBetween('08:00', '12:00');
Schedule::command('leagues:refresh-connected')
    ->everyThreeHours()
    ->timezone('America/Toronto')
    ->unlessBetween('08:00', '12:00');
Schedule::command('fantrax:drafts:poll')
    ->everyTwoMinutes()
    ->timezone('America/Toronto');
