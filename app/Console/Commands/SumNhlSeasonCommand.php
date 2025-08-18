<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Jobs\SeasonSumJob;

class SumNhlSeasonCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * Usage: php artisan nhl:sum --season=20222023
     *
     * @var string
     */
    protected $signature = 'nhl:sum {--season= : The NHL season ID (e.g. 20222023)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dispatches a job to sum NHL season stats for the given season.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $seasonId = $this->option('season');

        if (!$seasonId) {
            $this->error('You must provide a --season option, e.g. --season=20222023');
            return Command::FAILURE;
        }

        SeasonSumJob::dispatch($seasonId);

        $this->info("Dispatched SeasonSumJob for season: {$seasonId}");

        return Command::SUCCESS;
    }
}
