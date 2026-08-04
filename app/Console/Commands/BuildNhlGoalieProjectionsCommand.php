<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\BuildNhlGoalieProjectionsJob;
use App\Services\NhlGoalieProjectionBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Queue first-pass goalie performance projections.
 */
class BuildNhlGoalieProjectionsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'nhl:goalie-projections
                            {--source= : Source season id, e.g. 20252026}
                            {--target= : Target season id, e.g. 20262027}
                            {--goalie-workload-version= : Goalie workload projection version}
                            {--toi-version= : Skater TOI projection version}
                            {--version= : Goalie projection version}';

    /**
     * @var string
     */
    protected $description = 'Queue first-pass NHL goalie projections with one worker job per goalie.';

    /**
     * Execute the command.
     */
    public function handle(NhlGoalieProjectionBuilder $builder): int
    {
        $sourceSeasonId = (string) $this->option('source');
        $targetSeasonId = (string) $this->option('target');
        $goalieWorkloadProjectionVersion = (string) $this->option('goalie-workload-version');
        $toiProjectionVersion = (string) $this->option('toi-version');

        if (! preg_match('/^\d{8}$/', $sourceSeasonId) || ! preg_match('/^\d{8}$/', $targetSeasonId)) {
            $this->error('Provide --source and --target as 8-digit NHL season ids.');

            return self::INVALID;
        }

        if ($goalieWorkloadProjectionVersion === '' || $toiProjectionVersion === '') {
            $this->error('Provide --goalie-workload-version and --toi-version.');

            return self::INVALID;
        }

        if (! $this->projectionTablesExist()) {
            $this->error('Run migrations before building goalie projections.');

            return self::FAILURE;
        }

        $version = (string) ($this->option('version') ?: $builder->defaultVersion($targetSeasonId));

        try {
            $builder->prepareBuild(
                $sourceSeasonId,
                $targetSeasonId,
                $goalieWorkloadProjectionVersion,
                $toiProjectionVersion,
                $version
            );
        } catch (\RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        BuildNhlGoalieProjectionsJob::dispatch(
            $sourceSeasonId,
            $targetSeasonId,
            $goalieWorkloadProjectionVersion,
            $toiProjectionVersion,
            $version
        );

        $this->info(sprintf(
            'Queued goalie projections %s from %s to %s using workload %s and TOI %s.',
            $version,
            $sourceSeasonId,
            $targetSeasonId,
            $goalieWorkloadProjectionVersion,
            $toiProjectionVersion
        ));

        return self::SUCCESS;
    }

    /**
     * Determine whether goalie projection storage and prerequisites are available.
     */
    private function projectionTablesExist(): bool
    {
        return Schema::hasTable('nhl_goalie_season_projections')
            && Schema::hasTable('nhl_goalie_projection_chance_buckets')
            && Schema::hasTable('nhl_goalie_workload_projections')
            && Schema::hasTable('nhl_goalie_chance_profile_buckets')
            && Schema::hasTable('nhl_player_toi_projections')
            && Schema::hasTable('nhl_shot_attempts_facts')
            && Schema::hasTable('nhl_shot_attempt_predictions')
            && Schema::hasColumn('nhl_goalie_season_projections', 'goalie_workload_projection_version')
            && Schema::hasColumn('nhl_goalie_season_projections', 'projected_starts')
            && Schema::hasColumn('nhl_goalie_season_projections', 'projected_ev_xga')
            && Schema::hasColumn('nhl_goalie_projection_chance_buckets', 'projection_strength');
    }
}
