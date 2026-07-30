<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Remove NHL import progress rows and admin-visible import orchestration runs.
 */
class EmptyNhlImportProgressCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'nhl:imports:empty-progress
                            {--force : Run without an interactive confirmation prompt.}';

    /**
     * @var string
     */
    protected $description = 'Clear NHL import progress and game import run records.';

    public function handle(): int
    {
        if (! Schema::hasTable('nhl_import_progress') || ! Schema::hasTable('nhl_game_import_runs')) {
            $this->error('Required NHL import progress tables do not exist.');

            return self::FAILURE;
        }

        $progressCount = DB::table('nhl_import_progress')->count();
        $runCount = DB::table('nhl_game_import_runs')->count();

        if (! (bool) $this->option('force')) {
            $confirmed = $this->confirm(sprintf(
                'Delete %d import progress rows and %d game import run rows?',
                $progressCount,
                $runCount
            ));

            if (! $confirmed) {
                $this->warn('Cancelled. No NHL import progress rows were removed.');

                return self::SUCCESS;
            }
        }

        $this->emptyTables();

        $this->info('Removed NHL import progress and game import runs.');
        $this->line("nhl_import_progress: {$progressCount}");
        $this->line("nhl_game_import_runs: {$runCount}");

        return self::SUCCESS;
    }

    /**
     * Empty import progress tables in dependency-safe order.
     */
    private function emptyTables(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('TRUNCATE TABLE nhl_import_progress, nhl_game_import_runs RESTART IDENTITY CASCADE');

            return;
        }

        DB::table('nhl_import_progress')->delete();
        DB::table('nhl_game_import_runs')->delete();
    }
}
