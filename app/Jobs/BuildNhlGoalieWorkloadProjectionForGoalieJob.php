<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\NhlGoalieWorkloadProjectionBuilder;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Builds one goalie's first-pass workload projection row.
 */
class BuildNhlGoalieWorkloadProjectionForGoalieJob implements ShouldQueue
{
    use Batchable;
    use Queueable;
    use InteractsWithQueue;
    use SerializesModels;

    /**
     * @var int
     */
    public int $tries = 2;

    /**
     * @var array<int, int>
     */
    public array $backoff = [30, 120];

    /**
     * @var int
     */
    public int $timeout = 120;

    public function __construct(
        public string $sourceSeasonId,
        public string $targetSeasonId,
        public string $version,
        public int $goaliePlayerId
    ) {
        $this->afterCommit = true;
    }

    /**
     * Prevent duplicate builds for the same goalie workload projection.
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->uniqueKey()))
                ->expireAfter($this->timeout + 120),
        ];
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [
            'nhl-goalie-workload-projection-goalie',
            'source-season:' . $this->sourceSeasonId,
            'target-season:' . $this->targetSeasonId,
            'version:' . $this->version,
            'goalie:' . $this->goaliePlayerId,
        ];
    }

    public function handle(NhlGoalieWorkloadProjectionBuilder $builder): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $builder->buildGoalie(
            sourceSeasonId: $this->sourceSeasonId,
            targetSeasonId: $this->targetSeasonId,
            version: $this->version,
            goaliePlayerId: $this->goaliePlayerId
        );
    }

    public function failed(Throwable $exception): void
    {
        Log::error('NHL goalie workload projection goalie job failed.', [
            'source_season_id' => $this->sourceSeasonId,
            'target_season_id' => $this->targetSeasonId,
            'version' => $this->version,
            'goalie_player_id' => $this->goaliePlayerId,
            'error' => $exception->getMessage(),
        ]);
    }

    private function uniqueKey(): string
    {
        return 'nhl-goalie-workload-projection-goalie:'
            . $this->sourceSeasonId . ':'
            . $this->targetSeasonId . ':'
            . $this->version . ':'
            . $this->goaliePlayerId;
    }
}
