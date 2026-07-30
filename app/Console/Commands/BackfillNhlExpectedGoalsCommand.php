<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\NhlExpectedGoalsBackfiller;
use Illuminate\Console\Command;

/**
 * Backfill versioned NHL expected-goals predictions from shot-attempt facts.
 */
class BackfillNhlExpectedGoalsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'nhl:xg:backfill
                            {--season=20252026 : Season id to train and score}
                            {--version=bucket_smoothed_xg_v1 : Model version to create/update}
                            {--target=goal : Prediction target: goal or shot_on_goal}
                            {--min-attempts=300 : Minimum attempts for a bucket before falling back}
                            {--prior=100 : League-average smoothing prior attempts}
                            {--dry-run : Report training counts without writing rows}';

    /**
     * @var string
     */
    protected $description = 'Train and backfill a bucket-smoothed NHL expected-goals model';

    /**
     * Execute the command.
     */
    public function handle(NhlExpectedGoalsBackfiller $backfiller): int
    {
        $seasonId = trim((string) $this->option('season'));
        $version = trim((string) $this->option('version'));
        $predictionTarget = trim((string) $this->option('target'));
        $minimumBucketAttempts = max(1, (int) $this->option('min-attempts'));
        $smoothingPriorAttempts = max(0, (int) $this->option('prior'));
        $dryRun = (bool) $this->option('dry-run');

        if ($seasonId === '') {
            $this->error('Season id is required.');

            return self::FAILURE;
        }

        $result = $backfiller->backfill(
            seasonId: $seasonId,
            version: $version,
            minimumBucketAttempts: $minimumBucketAttempts,
            smoothingPriorAttempts: $smoothingPriorAttempts,
            dryRun: $dryRun,
            predictionTarget: $predictionTarget
        );

        $this->info($dryRun ? 'Expected-goals backfill dry run.' : 'Expected-goals backfill written.');
        $this->table(
            ['Metric', 'Value'],
            collect($result)
                ->map(fn (int|float|string $value, string $key): array => [$key, (string) $value])
                ->values()
                ->all()
        );

        return self::SUCCESS;
    }
}
