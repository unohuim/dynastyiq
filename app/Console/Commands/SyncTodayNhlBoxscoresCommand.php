<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ImportRun;
use App\Services\ImportNhlBoxscore;
use App\Services\NhlGameLiveContext;
use App\Services\AdminImportSchedules;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/** Refreshes today's non-final game states and available official boxscores. */
class SyncTodayNhlBoxscoresCommand extends Command
{
    protected $signature = 'nhl:sync-today-boxscores {--import-run-id= : Internal admin import run id}';

    protected $description = 'Refresh today NHL game states and available boxscores';

    public function handle(NhlGameLiveContext $liveContext, ImportNhlBoxscore $boxscores, AdminImportSchedules $schedules): int
    {
        $run = $this->option('import-run-id')
            ? ImportRun::query()->find((int) $this->option('import-run-id'))
            : null;

        try {
            $schedule = $schedules->forSource(AdminImportSchedules::GAME_BOXSCORES)->first();
            $games = $schedules->dueBoxscoreGames($schedule, CarbonImmutable::now('UTC'));
            $run?->setProgressTotal($games->count(), 'Today NHL games');

            foreach ($games as $game) {
                $context = $liveContext->forGame($game);
                if ($context === null || empty($context['state'])) {
                    throw new \RuntimeException("No game state returned for NHL game {$game->nhl_game_id}.");
                }
                $state = mb_strtoupper((string) ($context['state'] ?? $game->game_state ?? ''));
                if (! in_array($state, ['', 'FUT', 'PRE'], true)) {
                    $boxscores->import((int) $game->nhl_game_id);
                }
                $game->forceFill(['boxscore_synced_at' => now('UTC')])->save();
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
