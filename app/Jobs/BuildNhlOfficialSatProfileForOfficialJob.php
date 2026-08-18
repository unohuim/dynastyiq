<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\NhlGameContextSatProfileBuilder;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Builds one official's historical SAT profile buckets.
 */
class BuildNhlOfficialSatProfileForOfficialJob implements ShouldQueue
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
        public int $officialId,
        public string $role
    ) {
        $this->afterCommit = true;
    }

    /**
     * Prevent duplicate builds for the same official SAT profile.
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
            'nhl-official-sat-profile',
            'source-season:' . $this->sourceSeasonId,
            'game-type:' . $this->gameType,
            'official:' . $this->officialId,
            'role:' . $this->role,
        ];
    }

    public function handle(NhlGameContextSatProfileBuilder $builder): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $builder->buildOfficial(
            sourceSeasonId: $this->sourceSeasonId,
            gameType: $this->gameType,
            goalModelId: $this->goalModelId,
            sogModelId: $this->sogModelId,
            officialId: $this->officialId,
            role: $this->role
        );
    }

    public function failed(Throwable $exception): void
    {
        Log::error('NHL official SAT profile job failed.', [
            'source_season_id' => $this->sourceSeasonId,
            'game_type' => $this->gameType,
            'official_id' => $this->officialId,
            'role' => $this->role,
            'error' => $exception->getMessage(),
        ]);
    }

    private function uniqueKey(): string
    {
        return 'nhl-official-sat-profile:'
            . $this->sourceSeasonId . ':'
            . $this->gameType . ':'
            . $this->goalModelId . ':'
            . $this->sogModelId . ':'
            . $this->officialId . ':'
            . $this->role;
    }
}
