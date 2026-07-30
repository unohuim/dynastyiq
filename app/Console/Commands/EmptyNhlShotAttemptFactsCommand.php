<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Remove NHL shot-attempt facts and dependent prediction rows.
 */
class EmptyNhlShotAttemptFactsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'nhl:shots:empty-facts
                            {--force : Run without an interactive confirmation prompt.}';

    /**
     * @var string
     */
    protected $description = 'Truncate NHL shot-attempt facts and dependent shot prediction rows.';

    public function handle(): int
    {
        if (! Schema::hasTable('nhl_shot_attempts_facts')) {
            $this->error('Table [nhl_shot_attempts_facts] does not exist.');

            return self::FAILURE;
        }

        $factCount = DB::table('nhl_shot_attempts_facts')->count();
        $predictionCount = Schema::hasTable('nhl_shot_attempt_predictions')
            ? DB::table('nhl_shot_attempt_predictions')->count()
            : 0;

        if (! (bool) $this->option('force')) {
            $confirmed = $this->confirm(sprintf(
                'Delete %d shot-attempt fact rows and %d dependent prediction rows?',
                $factCount,
                $predictionCount
            ));

            if (! $confirmed) {
                $this->warn('Cancelled. No shot-attempt facts were removed.');

                return self::SUCCESS;
            }
        }

        $this->emptyTables();

        $this->info('Removed NHL shot-attempt facts.');
        $this->line("nhl_shot_attempts_facts: {$factCount}");
        $this->line("nhl_shot_attempt_predictions: {$predictionCount}");

        return self::SUCCESS;
    }

    /**
     * Empty the shot-attempt fact table and dependent predictions.
     */
    private function emptyTables(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('TRUNCATE TABLE nhl_shot_attempts_facts RESTART IDENTITY CASCADE');

            return;
        }

        if (Schema::hasTable('nhl_shot_attempt_predictions')) {
            DB::table('nhl_shot_attempt_predictions')->delete();
        }

        DB::table('nhl_shot_attempts_facts')->delete();
    }
}
