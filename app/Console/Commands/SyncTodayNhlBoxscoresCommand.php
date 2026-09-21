<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ImportRun;
use App\Models\NhlGame;
use App\Services\ImportNhlBoxscore;
use App\Services\NhlGameLiveContext;
use Illuminate\Console\Command;
use Throwable;

/** Refreshes today's non-final game states and available official boxscores. */
class SyncTodayNhlBoxscoresCommand extends Command
{
    protected $signature = 'nhl:sync-today-boxscores {--import-run-id= : Internal admin import run id}';

    protected $description = 'Refresh today NHL game states and available boxscores';

    public function handle(NhlGameLiveContext $liveContext, ImportNhlBoxscore $boxscores): int
    {
        $run = $this->option('import-run-id')
            ? ImportRun::query()->find((int) $this->option('import-run-id'))
            : null;

        try {
            $games = NhlGame::query()
                ->whereDate('game_date', now('UTC')->toDateString())
                ->where(fn ($query) => $query->whereNull('game_state')->orWhere('game_state', '<>', 'FINAL'))
                ->orderBy('start_time_utc')->get();
            $run?->setProgressTotal($games->count(), 'Today NHL games');

            foreach ($games as $game) {
                $context = $liveContext->forGame($game);
                $state = mb_strtoupper((string) ($context['state'] ?? $game->game_state ?? ''));
                if (! in_array($state, ['', 'FUT', 'PRE'], true)) {
                    $boxscores->import((int) $game->nhl_game_id);
                }
                $run?->recordProcessed();
            }

            $run?->markCompleted();
            $this->info("Refreshed {$games->count()} NHL games.");

            return self::SUCCESS;
        } catch (Throwable $throwable) {
            $run?->markFailed($throwable);
            throw $throwable;
        }
    }
}
