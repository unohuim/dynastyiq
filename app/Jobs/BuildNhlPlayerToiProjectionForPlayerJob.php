<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\NhlPlayerToiProjectionBuilder;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Builds one player's first-pass TOI projection row.
 */
class BuildNhlPlayerToiProjectionForPlayerJob implements ShouldQueue
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
        public int $playerId
    ) {
        $this->afterCommit = true;
    }

    /**
     * Prevent duplicate builds for the same player TOI projection.
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
            'nhl-player-toi-projection-player',
            'source-season:' . $this->sourceSeasonId,
            'target-season:' . $this->targetSeasonId,
            'version:' . $this->version,
            'player:' . $this->playerId,
        ];
    }

    public function handle(NhlPlayerToiProjectionBuilder $builder): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $builder->buildPlayer(
            sourceSeasonId: $this->sourceSeasonId,
            targetSeasonId: $this->targetSeasonId,
            version: $this->version,
            playerId: $this->playerId
        );
    }

    public function failed(Throwable $exception): void
    {
        Log::error('NHL player TOI projection player job failed.', [
            'source_season_id' => $this->sourceSeasonId,
            'target_season_id' => $this->targetSeasonId,
            'version' => $this->version,
            'player_id' => $this->playerId,
            'error' => $exception->getMessage(),
        ]);
    }

    private function uniqueKey(): string
    {
        return 'nhl-player-toi-projection-player:'
            . $this->sourceSeasonId . ':'
            . $this->targetSeasonId . ':'
            . $this->version . ':'
            . $this->playerId;
    }
}
