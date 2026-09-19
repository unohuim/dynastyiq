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
    protected $signature = 'nhl:import-starting-goalies
        {--date=* : Dates in YYYY-MM-DD format}
        {--future-window : Import consecutive future dates through seven days, stopping at the first blank date}
        {--import-run-id= : Internal admin import run id}';
    protected $description = 'Import RotoWire NHL starting-goalie observations';

    public function handle(NhlStartingGoalieImporter $importer): int
    {
        $run = $this->importRun();
        try {
            $dates = $this->dates();
            $total = 0;
            foreach ($dates as $date) {
                $observed = $importer->import(Carbon::createFromFormat('Y-m-d', (string) $date)->startOfDay())['observed'];
                $total += $observed;
                if ($this->option('future-window') && $observed === 0) {
                    break;
                }
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

    /** @return array<int,string> */
    private function dates(): array
    {
        if ($this->option('future-window')) {
            return collect(range(1, 7))->map(fn (int $day): string => today()->addDays($day)->toDateString())->all();
        }

        return $this->option('date') ?: [today()->toDateString(), today()->addDay()->toDateString()];
    }

    private function importRun(): ?ImportRun
    {
        return $this->option('import-run-id') ? ImportRun::query()->find((int) $this->option('import-run-id')) : null;
    }
}
