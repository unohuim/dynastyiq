<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AdminImports;
use App\Services\ImportFantraxPlayers;
use Illuminate\Console\Command;

class FantraxImportPlayersCommand extends Command
{
    /**
     * Usage: php artisan fx:import --players
     *
     * @var string
     */
    protected $signature = 'fx:import
                            {--players : Import Fantrax players}
                            {--import-run-id= : Internal admin import run id}';

    /**
     * @var string
     */
    protected $description = 'Run Fantrax import tasks';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if ($this->option('players')) {
            $importRunId = $this->option('import-run-id')
                ? (int) $this->option('import-run-id')
                : null;

            if ($importRunId !== null) {
                app(ImportFantraxPlayers::class)->prepare($importRunId);

                $this->info('Queued Fantrax player chunks.');
                return self::SUCCESS;
            }

            app(AdminImports::class)->dispatch('fantrax');
            $this->info('Dispatched ImportFantraxPlayersJob.');
            return self::SUCCESS;
        }

        $this->error('Nothing to do. Try: fx:import --players');
        return self::FAILURE;
    }
}
