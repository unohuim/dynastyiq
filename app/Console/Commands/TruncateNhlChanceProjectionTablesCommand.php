<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fast destructive reset for NHL chance profile and projection output tables.
 */
class TruncateNhlChanceProjectionTablesCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'nhl:truncate-chance-projection-tables
                            {--confirm : Required confirmation for destructive truncate}';

    /**
     * @var string
     */
    protected $description = 'Truncate NHL chance profile and projection output tables with RESTART IDENTITY CASCADE';

    /**
     * @var array<int, string>
     */
    private const TABLES = [
        'nhl_player_projection_profile_buckets',
        'nhl_player_season_projections',
        'nhl_player_toi_projections',
        'nhl_skater_offensive_chance_profile_buckets',
        'nhl_official_sat_aggregate_profile_buckets',
        'nhl_staff_sat_aggregate_profile_buckets',
        'nhl_official_sat_profile_buckets',
        'nhl_staff_sat_profile_buckets',
        'nhl_goalie_chance_profile_buckets',
        'nhl_skater_defensive_chance_profile_buckets',
        'nhl_skater_defensive_chance_projection_buckets',
        'nhl_skater_defensive_chance_projections',
        'nhl_goalie_projection_chance_buckets',
        'nhl_goalie_season_projections',
        'nhl_goalie_workload_projections',
    ];

    /**
     * Execute the command.
     */
    public function handle(): int
    {
        $existingTables = collect(self::TABLES)
            ->filter(fn (string $table): bool => Schema::hasTable($table))
            ->values();

        if ($existingTables->isEmpty()) {
            $this->warn('No NHL chance profile or projection tables exist in this database.');

            return self::SUCCESS;
        }

        if (! (bool) $this->option('confirm')) {
            $this->warn('This command is destructive. Re-run with --confirm to truncate these tables:');

            foreach ($existingTables as $table) {
                $this->line('- ' . $table);
            }

            return self::FAILURE;
        }

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->error('This command requires PostgreSQL TRUNCATE ... RESTART IDENTITY CASCADE support.');

            return self::FAILURE;
        }

        $tableSql = $existingTables
            ->map(fn (string $table): string => '"' . str_replace('"', '""', $table) . '"')
            ->implode(', ');

        DB::statement('TRUNCATE TABLE ' . $tableSql . ' RESTART IDENTITY CASCADE');

        $this->info(sprintf(
            'Truncated %d NHL chance profile/projection tables.',
            $existingTables->count()
        ));

        return self::SUCCESS;
    }
}
