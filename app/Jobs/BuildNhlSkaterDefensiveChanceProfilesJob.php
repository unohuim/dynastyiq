<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\NhlSkaterDefensiveChanceProfileBuilder;
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
 * Coordinates historical skater on-ice defensive chance-profile jobs.
 */
class BuildNhlSkaterDefensiveChanceProfilesJob implements ShouldQueue, ShouldBeUnique
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
        public int $gameType
    ) {
        $this->afterCommit = true;
    }

    /**
     * Prevent duplicate profile builds for the same source season and game type.
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
        return 'nhl-skater-defensive-profiles:' . $this->sourceSeasonId . ':' . $this->gameType;
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [
            'nhl-skater-defensive-profiles',
            'source-season:' . $this->sourceSeasonId,
            'game-type:' . $this->gameType,
        ];
    }

    public function handle(NhlSkaterDefensiveChanceProfileBuilder $builder): void
    {
        $setup = $builder->prepareBuild($this->sourceSeasonId, $this->gameType);
        $jobs = array_map(
            fn (int $playerId): BuildNhlSkaterDefensiveChanceProfileForPlayerJob => new BuildNhlSkaterDefensiveChanceProfileForPlayerJob(
                sourceSeasonId: $this->sourceSeasonId,
                gameType: $this->gameType,
                goalModelId: $setup['goal_model_id'],
                sogModelId: $setup['sog_model_id'],
                playerId: $playerId
            ),
            $setup['player_ids']
        );

        if ($jobs === []) {
            Log::warning('No NHL skater defensive chance profile jobs were queued.', [
                'source_season_id' => $setup['source_season_id'],
                'game_type' => $setup['game_type'],
                'goal_model_id' => $setup['goal_model_id'],
                'sog_model_id' => $setup['sog_model_id'],
            ]);

            return;
        }

        Bus::batch($jobs)
            ->name('NHL skater defensive chance profiles ' . $this->sourceSeasonId)
            ->allowFailures()
            ->dispatch();

        Log::info('NHL skater defensive chance profile jobs queued.', [
            'source_season_id' => $setup['source_season_id'],
            'game_type' => $setup['game_type'],
            'goal_model_id' => $setup['goal_model_id'],
            'sog_model_id' => $setup['sog_model_id'],
            'player_job_count' => count($jobs),
        ]);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('NHL skater defensive chance profiles job failed.', [
            'source_season_id' => $this->sourceSeasonId,
            'game_type' => $this->gameType,
            'error' => $exception->getMessage(),
        ]);
    }
}
