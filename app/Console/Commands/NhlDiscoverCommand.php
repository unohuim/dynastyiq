<?php
// app/Console/Commands/NhlDiscoverCommand.php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Jobs\NhlDiscoveryJob;

class NhlDiscoverCommand extends Command
{
    protected $signature = 'nhl:discover {--days=}';
    protected $description = 'Queue NHL discovery for the past N days.';

    public function handle(): int
    {
        $raw  = $this->option('days');
        $days = ($raw === null || $raw === '') ? null : (int) $raw;
        
        NhlDiscoveryJob::dispatch($days);
        $this->info("Queued discovery for {$days} days.");
        return self::SUCCESS;


    }
}
