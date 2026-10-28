<?php

use Illuminate\Support\Facades\Schedule;

Schedule::call(fn () => app(\App\Services\NhlPregameContextOrchestrator::class)->recoverActiveRuns())
    ->name('nhl-pregame-recover-active-runs')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping(2);

Schedule::command('nhl:import --players')
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

Schedule::command('processes:dispatch-due')
    ->everyMinute()
    ->withoutOverlapping(1)
    ->timezone('America/Toronto');
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
Schedule::command('admin:dispatch-scheduled-imports')
    ->everySecond()
    ->withoutOverlapping(1)
    ->timezone('America/Toronto');


Schedule::call(fn () => app(\App\Services\NhlLiveGameFeed::class)->dispatchDue())
    ->name('nhl-live-dispatch')
    ->everyFiveSeconds()
    ->onOneServer()
    ->withoutOverlapping(1);

// Revisit tracked recent finals for provider corrections after the live window.
Schedule::call(function (): void {
    \App\Models\NhlGame::query()->whereIn('game_state', ['OFF', 'FINAL'])
        ->whereBetween('game_date', [now('America/Toronto')->subDays(3)->toDateString(), now('America/Toronto')->toDateString()])
        ->whereIn('nhl_game_id', \Illuminate\Support\Facades\DB::table('nhl_pbp_processing_states')->select('nhl_game_id'))
        ->pluck('nhl_game_id')->each(function ($id): void {
            \App\Jobs\PollNhlLiveGameJob::dispatch((int) $id, now()->timestamp, true);
        });
})->name('nhl-live-final-reconciliation')->dailyAt('04:00')->timezone('America/Toronto')->onOneServer();
