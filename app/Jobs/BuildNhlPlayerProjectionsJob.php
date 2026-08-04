<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\NhlPlayerProjectionBuilder;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Coordinates first-pass player season projection jobs from shot profile buckets.
 */
class BuildNhlPlayerProjectionsJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;
    use InteractsWithQueue;
    use SerializesModels;

    /**
     * @var int
     */
    public int $tries = 1;

    /**
     * @var int
     */
    public int $timeout = 1800;

    /**
     * @var int
     */
    public int $uniqueFor = 21600;

    public function __construct(
        public string $sourceSeasonId,
        public string $targetSeasonId,
        public string $version
    ) {
        $this->afterCommit = true;
    }

    /**
     * Prevent duplicate projection builds for the same source, target, and version.
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->uniqueId()))
                ->expireAfter($this->timeout + 300),
        ];
    }

    public function uniqueId(): string
    {
        return 'nhl-player-projections:' . $this->sourceSeasonId . ':' . $this->targetSeasonId . ':' . $this->version;
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [
            'nhl-player-projections',
            'source-season:' . $this->sourceSeasonId,
            'target-season:' . $this->targetSeasonId,
            'version:' . $this->version,
        ];
    }

    public function handle(NhlPlayerProjectionBuilder $builder): void
    {
        $setup = $builder->prepareBuild($this->sourceSeasonId, $this->targetSeasonId, $this->version);
        $jobs = array_map(
            fn (int $playerId): BuildNhlPlayerProjectionForPlayerJob => new BuildNhlPlayerProjectionForPlayerJob(
                sourceSeasonId: $this->sourceSeasonId,
                targetSeasonId: $this->targetSeasonId,
                version: $this->version,
                goalModelId: $setup['goal_model_id'],
                sogModelId: $setup['sog_model_id'],
                playerId: $playerId
            ),
            $setup['player_ids']
        );

        if ($jobs === []) {
            Log::warning('No NHL player projection jobs were queued.', [
                'projection_version' => $setup['projection_version'],
                'source_season_id' => $setup['source_season_id'],
                'target_season_id' => $setup['target_season_id'],
                'goal_model_id' => $setup['goal_model_id'],
                'sog_model_id' => $setup['sog_model_id'],
            ]);

            return;
        }

        Bus::batch($jobs)
            ->name('NHL player projections ' . $this->version)
            ->allowFailures()
            ->dispatch();

        Log::info('NHL player projection jobs queued.', [
            'projection_version' => $setup['projection_version'],
            'source_season_id' => $setup['source_season_id'],
            'target_season_id' => $setup['target_season_id'],
            'goal_model_id' => $setup['goal_model_id'],
            'sog_model_id' => $setup['sog_model_id'],
            'player_job_count' => count($jobs),
        ]);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('NHL player projections job failed.', [
            'source_season_id' => $this->sourceSeasonId,
            'target_season_id' => $this->targetSeasonId,
            'version' => $this->version,
            'error' => $exception->getMessage(),
        ]);
    }
}
