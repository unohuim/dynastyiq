<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\NhlGoalieProjectionBuilder;
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
 * Coordinates first-pass goalie projection jobs.
 */
class BuildNhlGoalieProjectionsJob implements ShouldQueue, ShouldBeUnique
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
        public string $goalieWorkloadProjectionVersion,
        public string $toiProjectionVersion,
        public string $version
    ) {
        $this->afterCommit = true;
    }

    /**
     * Prevent duplicate goalie projection builds for the same source, target, and version.
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
        return 'nhl-goalie-projections:'
            . $this->sourceSeasonId . ':'
            . $this->targetSeasonId . ':'
            . $this->goalieWorkloadProjectionVersion . ':'
            . $this->toiProjectionVersion . ':'
            . $this->version;
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [
            'nhl-goalie-projections',
            'source-season:' . $this->sourceSeasonId,
            'target-season:' . $this->targetSeasonId,
            'goalie-workload-version:' . $this->goalieWorkloadProjectionVersion,
            'toi-version:' . $this->toiProjectionVersion,
            'version:' . $this->version,
        ];
    }

    public function handle(NhlGoalieProjectionBuilder $builder): void
    {
        $setup = $builder->prepareBuild(
            $this->sourceSeasonId,
            $this->targetSeasonId,
            $this->goalieWorkloadProjectionVersion,
            $this->toiProjectionVersion,
            $this->version
        );
        $jobs = array_map(
            fn (int $goaliePlayerId): BuildNhlGoalieProjectionForGoalieJob => new BuildNhlGoalieProjectionForGoalieJob(
                sourceSeasonId: $this->sourceSeasonId,
                targetSeasonId: $this->targetSeasonId,
                goalieWorkloadProjectionVersion: $this->goalieWorkloadProjectionVersion,
                toiProjectionVersion: $this->toiProjectionVersion,
                version: $this->version,
                goaliePlayerId: $goaliePlayerId
            ),
            $setup['goalie_player_ids']
        );

        if ($jobs === []) {
            Log::warning('No NHL goalie projection jobs were queued.', [
                'projection_version' => $setup['projection_version'],
                'source_season_id' => $setup['source_season_id'],
                'target_season_id' => $setup['target_season_id'],
                'goalie_workload_projection_version' => $setup['goalie_workload_projection_version'],
                'toi_projection_version' => $setup['toi_projection_version'],
            ]);

            return;
        }

        Bus::batch($jobs)
            ->name('NHL goalie projections ' . $this->version)
            ->dispatch();

        Log::info('NHL goalie projection jobs queued.', [
            'projection_version' => $setup['projection_version'],
            'source_season_id' => $setup['source_season_id'],
            'target_season_id' => $setup['target_season_id'],
            'goalie_workload_projection_version' => $setup['goalie_workload_projection_version'],
            'toi_projection_version' => $setup['toi_projection_version'],
            'goalie_job_count' => count($jobs),
        ]);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('NHL goalie projections job failed.', [
            'source_season_id' => $this->sourceSeasonId,
            'target_season_id' => $this->targetSeasonId,
            'goalie_workload_projection_version' => $this->goalieWorkloadProjectionVersion,
            'toi_projection_version' => $this->toiProjectionVersion,
            'version' => $this->version,
            'error' => $exception->getMessage(),
        ]);
    }
}
