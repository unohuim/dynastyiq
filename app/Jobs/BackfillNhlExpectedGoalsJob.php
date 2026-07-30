<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\NhlExpectedGoalsBackfiller;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Builds and stores expected-goals predictions for a season from shot-attempt facts.
 */
class BackfillNhlExpectedGoalsJob implements ShouldQueue, ShouldBeUnique
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
    public int $timeout = 3600;

    /**
     * @var int
     */
    public int $uniqueFor = 21600;

    public function __construct(
        public string $seasonId,
        public string $version,
        public int $minimumBucketAttempts,
        public int $smoothingPriorAttempts,
        public string $predictionTarget = NhlExpectedGoalsBackfiller::TARGET_GOAL
    ) {
        $this->afterCommit = true;
    }

    /**
     * Prevent duplicate xG builds for the same season and version.
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
        return 'nhl-shot-outcome-backfill:' . $this->seasonId . ':' . $this->version . ':' . $this->predictionTarget;
    }

    /**
     * @return array<int,string>
     */
    public function tags(): array
    {
        return [
            'nhl-xg-backfill',
            'season:' . $this->seasonId,
            'version:' . $this->version,
            'target:' . $this->predictionTarget,
        ];
    }

    public function handle(NhlExpectedGoalsBackfiller $backfiller): void
    {
        $backfiller->backfill(
            seasonId: $this->seasonId,
            version: $this->version,
            minimumBucketAttempts: $this->minimumBucketAttempts,
            smoothingPriorAttempts: $this->smoothingPriorAttempts,
            dryRun: false,
            predictionTarget: $this->predictionTarget
        );
    }

    public function failed(Throwable $exception): void
    {
        app(NhlExpectedGoalsBackfiller::class)->markFailed($this->version, $exception->getMessage(), $this->predictionTarget);

        Log::error('NHL expected-goals backfill job failed.', [
            'season_id' => $this->seasonId,
            'version' => $this->version,
            'prediction_target' => $this->predictionTarget,
            'error' => $exception->getMessage(),
        ]);
    }
}
