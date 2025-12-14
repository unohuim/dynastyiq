<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Jobs\ImportPlayersJob;

class ImportNhlPlayersCommand extends Command
{
    /**
     * Usage: php artisan nhl:import --players
     */
    protected $signature = 'nhl:import {--players : Import NHL players for all teams}';

    protected $description = 'Import NHL data';

    public function handle(): int
    {
        if (! $this->option('players')) {
            $this->error('Nothing to do. Try: nhl:import --players');
            return Command::FAILURE;
        }

        foreach ($this->teams() as $abbrev) {
            ImportPlayersJob::dispatch($abbrev, (string)\Illuminate\Support\Str::uuid());
        }

        $this->info('Dispatched ImportPlayersJob for all teams.');
        return Command::SUCCESS;
    }

    /**
     * @return array<int,string>
     */
    private function teams(): array
    {
        return [
            'ANA','ARI','BOS','BUF','CGY','CAR','CHI','COL','CBJ','DAL','DET','EDM',
            'FLA','LAK','MIN','MTL','NSH','NJD','NYI','NYR','OTT','PHI','PIT','SJS',
            'SEA','STL','TBL', 'UTA','TOR','VAN','VGK','WSH','WPG',
        ];
    }
}
