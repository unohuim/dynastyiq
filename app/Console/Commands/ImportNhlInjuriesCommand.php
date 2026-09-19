<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ImportRun;
use App\Services\NhlInjuryImporter;
use Illuminate\Console\Command;
use Throwable;

class ImportNhlInjuriesCommand extends Command
{
    protected $signature = 'nhl:import-injuries {--import-run-id= : Internal admin import run id}';
    protected $description = 'Import current NHL injuries from CBS Sports and RotoWire';

    public function handle(NhlInjuryImporter $importer): int
    {
        $run = $this->importRun();
        try {
            $summary = $importer->import();
            $run?->setProgressTotal($summary['observed'], 'Changed injury observations');
            for ($index = 0; $index < $summary['observed']; $index++) {
                $run?->recordProcessed();
            }
            $run?->markCompleted();
            $this->info(json_encode($summary, JSON_THROW_ON_ERROR));
            return self::SUCCESS;
        } catch (Throwable $throwable) {
            $run?->markFailed($throwable);
            throw $throwable;
        }
    }

    private function importRun(): ?ImportRun
    {
        return $this->option('import-run-id') ? ImportRun::query()->find((int) $this->option('import-run-id')) : null;
    }
}
