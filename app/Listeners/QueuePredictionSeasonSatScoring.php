<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\SatImported;
use App\Jobs\ScoreNhlPredictionGameSatJob;
use App\Models\NhlModelRun;
use App\Services\NhlExpectedGoalsBackfiller;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;

/** Fan out committed SAT imports to models forecasting the imported season. */
class QueuePredictionSeasonSatScoring implements ShouldQueueAfterCommit
{
    public string $queue = 'projections';
    public int $tries = 3;
    public int $timeout = 120;
    public int $backoff = 30;

    /** Match prediction seasons independently of Train and Test membership. */
    public function handle(SatImported $event): void
    {
        NhlModelRun::query()
            ->where('model_family', NhlModelRun::FAMILY_SAT)
            ->whereNotIn('status', [NhlModelRun::STATUS_ARCHIVED, NhlModelRun::STATUS_FAILED])
            ->chunkById(100, function ($runs) use ($event): void {
                foreach ($runs as $run) {
                    if ($run->projectionSeasonId() !== $event->seasonId || (int) ($run->game_type ?? 2) !== $event->gameType) {
                        continue;
                    }

                    $models = ScoreNhlPredictionGameSatJob::trainedModels($run);
                    if (! $models->has(NhlExpectedGoalsBackfiller::TARGET_SHOT_ON_GOAL)
                        || ! $models->has(NhlExpectedGoalsBackfiller::TARGET_GOAL)) {
                        continue;
                    }

                    ScoreNhlPredictionGameSatJob::dispatch($run->id, $event->nhlGameId, $event->seasonId);
                }
            });
    }
}
