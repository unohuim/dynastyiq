<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\NhlGoalieChanceProfileBuilder;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Builds one goalie's historical chance-profile buckets.
 */
class BuildNhlGoalieChanceProfileForGoalieJob implements ShouldQueue
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
    public int $timeout = 180;

    public function __construct(
        public string $sourceSeasonId,
        public int $gameType,
        public int $goalModelId,
        public int $sogModelId,
        public int $goaliePlayerId
    ) {
        $this->afterCommit = true;
    }

    /**
     * Prevent duplicate builds for the same goalie profile.
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
            'nhl-goalie-chance-profile-goalie',
            'source-season:' . $this->sourceSeasonId,
            'game-type:' . $this->gameType,
            'goalie:' . $this->goaliePlayerId,
        ];
    }

    public function handle(NhlGoalieChanceProfileBuilder $builder): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $builder->buildGoalie(
            sourceSeasonId: $this->sourceSeasonId,
            gameType: $this->gameType,
            goalModelId: $this->goalModelId,
            sogModelId: $this->sogModelId,
            goaliePlayerId: $this->goaliePlayerId
        );
    }

    public function failed(Throwable $exception): void
    {
        Log::error('NHL goalie chance profile goalie job failed.', [
            'source_season_id' => $this->sourceSeasonId,
            'game_type' => $this->gameType,
            'goalie_player_id' => $this->goaliePlayerId,
            'error' => $exception->getMessage(),
        ]);
    }

    private function uniqueKey(): string
    {
        return 'nhl-goalie-chance-profile-goalie:'
            . $this->sourceSeasonId . ':'
            . $this->gameType . ':'
            . $this->goalModelId . ':'
            . $this->sogModelId . ':'
            . $this->goaliePlayerId;
    }
}
