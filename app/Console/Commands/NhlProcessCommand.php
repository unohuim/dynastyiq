<?php
// app/Console/Commands/NhlProcessCommand.php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\NhlImportOrchestrator;

class NhlProcessCommand extends Command
{
    protected $signature = 'nhl:process {--limit=500} {--season=} {--gameType=}';
    protected $description = 'Scan tracker and dispatch eligible NHL import jobs.';

    public function handle(NhlImportOrchestrator $orchestrator): int
    {
        $limit    = (int) $this->option('limit');
        $seasonId = $this->option('season') ?: null;
        $gameType = $this->option('gameType') !== null ? (int) $this->option('gameType') : null;

        $orchestrator->processScheduled($limit, $seasonId, $gameType);
        $this->info('Processed scheduled imports.');
        return self::SUCCESS;
    }
}
