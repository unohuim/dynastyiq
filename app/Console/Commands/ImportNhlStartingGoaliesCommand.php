<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ImportRun;
use App\Services\NhlStartingGoalieImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

class ImportNhlStartingGoaliesCommand extends Command
{
    protected $signature = 'nhl:import-starting-goalies {--date=* : Dates in YYYY-MM-DD format} {--import-run-id= : Internal admin import run id}';
    protected $description = 'Import RotoWire NHL starting-goalie observations';

    public function handle(NhlStartingGoalieImporter $importer): int
    {
        $run = $this->importRun();
        try {
            $dates = $this->option('date') ?: [today()->toDateString(), today()->addDay()->toDateString()];
            $total = 0;
            foreach ($dates as $date) {
                $total += $importer->import(Carbon::createFromFormat('Y-m-d', (string) $date)->startOfDay())['observed'];
            }
            $run?->setProgressTotal($total, 'Starting goalie observations');
            for ($index = 0; $index < $total; $index++) {
                $run?->recordProcessed();
            }
            $run?->markCompleted();
            $this->info("Imported {$total} starting goalie observations.");
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
