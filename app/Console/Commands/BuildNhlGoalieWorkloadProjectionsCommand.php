<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\BuildNhlGoalieWorkloadProjectionsJob;
use App\Services\NhlGoalieWorkloadProjectionBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Queue first-pass goalie workload projections.
 */
class BuildNhlGoalieWorkloadProjectionsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'nhl:goalie-workload-projections
                            {--source= : Source season id, e.g. 20252026}
                            {--target= : Target season id, e.g. 20262027}
                            {--version= : Projection version}';

    /**
     * @var string
     */
    protected $description = 'Queue first-pass NHL goalie workload projections with one worker job per goalie.';

    /**
     * Execute the command.
     */
    public function handle(NhlGoalieWorkloadProjectionBuilder $builder): int
    {
        $sourceSeasonId = (string) $this->option('source');
        $targetSeasonId = (string) $this->option('target');

        if (! preg_match('/^\d{8}$/', $sourceSeasonId) || ! preg_match('/^\d{8}$/', $targetSeasonId)) {
            $this->error('Provide --source and --target as 8-digit NHL season ids.');

            return self::INVALID;
        }

        if (! $this->workloadProjectionTableExists()) {
            $this->error('Run migrations before building goalie workload projections.');

            return self::FAILURE;
        }

        $version = (string) ($this->option('version') ?: $builder->defaultVersion($targetSeasonId));

        BuildNhlGoalieWorkloadProjectionsJob::dispatch($sourceSeasonId, $targetSeasonId, $version);

        $this->info(sprintf(
            'Queued goalie workload projections %s from %s to %s.',
            $version,
            $sourceSeasonId,
            $targetSeasonId
        ));

        return self::SUCCESS;
    }

    /**
     * Determine whether the goalie workload projection table is available.
     */
    private function workloadProjectionTableExists(): bool
    {
        return Schema::hasTable('nhl_goalie_workload_projections')
            && Schema::hasColumn('nhl_goalie_workload_projections', 'projection_version')
            && Schema::hasColumn('nhl_goalie_workload_projections', 'target_season_id')
            && Schema::hasColumn('nhl_goalie_workload_projections', 'goalie_player_id')
            && Schema::hasColumn('nhl_goalie_workload_projections', 'projected_starts');
    }
}
