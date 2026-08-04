<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\NhlSkaterDefensiveChanceProfileBuilder;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Builds one skater's historical on-ice defensive chance-profile buckets.
 */
class BuildNhlSkaterDefensiveChanceProfileForPlayerJob implements ShouldQueue
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
        public int $playerId
    ) {
        $this->afterCommit = true;
    }

    /**
     * Prevent duplicate builds for the same skater defensive profile.
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
            'nhl-skater-defensive-profile-player',
            'source-season:' . $this->sourceSeasonId,
            'game-type:' . $this->gameType,
            'player:' . $this->playerId,
        ];
    }

    public function handle(NhlSkaterDefensiveChanceProfileBuilder $builder): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $builder->buildPlayer(
            sourceSeasonId: $this->sourceSeasonId,
            gameType: $this->gameType,
            goalModelId: $this->goalModelId,
            sogModelId: $this->sogModelId,
            playerId: $this->playerId
        );
    }

    public function failed(Throwable $exception): void
    {
        Log::error('NHL skater defensive chance profile player job failed.', [
            'source_season_id' => $this->sourceSeasonId,
            'game_type' => $this->gameType,
            'player_id' => $this->playerId,
            'error' => $exception->getMessage(),
        ]);
    }

    private function uniqueKey(): string
    {
        return 'nhl-skater-defensive-profile-player:'
            . $this->sourceSeasonId . ':'
            . $this->gameType . ':'
            . $this->goalModelId . ':'
            . $this->sogModelId . ':'
            . $this->playerId;
    }
}
