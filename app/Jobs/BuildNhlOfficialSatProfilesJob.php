<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\NhlGameContextSatProfileBuilder;
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
 * Coordinates historical official SAT profile jobs.
 */
class BuildNhlOfficialSatProfilesJob implements ShouldQueue, ShouldBeUnique
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
        return 'nhl-official-sat-profiles:' . $this->sourceSeasonId . ':' . $this->gameType;
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [
            'nhl-official-sat-profiles',
            'source-season:' . $this->sourceSeasonId,
            'game-type:' . $this->gameType,
        ];
    }

    public function handle(NhlGameContextSatProfileBuilder $builder): void
    {
        $setup = $builder->prepareOfficialBuild($this->sourceSeasonId, $this->gameType);
        $jobs = array_map(
            fn (array $assignment): BuildNhlOfficialSatProfileForOfficialJob => new BuildNhlOfficialSatProfileForOfficialJob(
                sourceSeasonId: $this->sourceSeasonId,
                gameType: $this->gameType,
                goalModelId: $setup['goal_model_id'],
                sogModelId: $setup['sog_model_id'],
                officialId: $assignment['official_id'],
                role: $assignment['role']
            ),
            $setup['assignments']
        );

        if ($jobs === []) {
            Log::warning('No NHL official SAT profile jobs were queued.', [
                'source_season_id' => $setup['source_season_id'],
                'game_type' => $setup['game_type'],
                'goal_model_id' => $setup['goal_model_id'],
                'sog_model_id' => $setup['sog_model_id'],
            ]);

            return;
        }

        Bus::batch($jobs)
            ->name('NHL official SAT profiles ' . $this->sourceSeasonId)
            ->allowFailures()
            ->dispatch();

        Log::info('NHL official SAT profile jobs queued.', [
            'source_season_id' => $setup['source_season_id'],
            'game_type' => $setup['game_type'],
            'goal_model_id' => $setup['goal_model_id'],
            'sog_model_id' => $setup['sog_model_id'],
            'official_job_count' => count($jobs),
        ]);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('NHL official SAT profiles job failed.', [
            'source_season_id' => $this->sourceSeasonId,
            'game_type' => $this->gameType,
            'error' => $exception->getMessage(),
        ]);
    }
}
