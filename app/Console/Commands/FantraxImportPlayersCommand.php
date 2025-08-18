<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Jobs\ImportFantraxPlayersJob;

class FantraxImportPlayersCommand extends Command
{
    /**
     * Usage: php artisan fx:import --players
     *
     * @var string
     */
    protected $signature = 'fx:import {--players : Import Fantrax players}';

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
            ImportFantraxPlayersJob::dispatch();
            $this->info('Dispatched ImportFantraxPlayersJob.');
            return self::SUCCESS;
        }

        $this->error('Nothing to do. Try: fx:import --players');
        return self::FAILURE;
    }
}
