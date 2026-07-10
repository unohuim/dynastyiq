<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ImportRun;
use App\Services\ImportPlatformCategoryMappings;
use Illuminate\Console\Command;
use Throwable;

class ImportFantraxCategoryDefinitionsCommand extends Command
{
    protected $signature = 'fantrax:import-category-definitions
                            {--path=docs/import-templates/fantrax_category_alignment.csv : CSV import template path}
                            {--import-run-id= : Internal admin import run id}';

    protected $description = 'Import Fantrax scoring category definitions into the platform category mapping dictionary.';

    public function handle(ImportPlatformCategoryMappings $importer): int
    {
        $importRun = $this->importRun();
        $path = (string) $this->option('path');

        try {
            $result = $importer->import('fantrax', $path, $importRun);
            $importRun?->markCompleted();

            $this->info("Imported {$result['imported']} Fantrax category definition mapping(s).");

            foreach ($result['status_counts'] as $status => $count) {
                $this->line("{$status}: {$count}");
            }

            return self::SUCCESS;
        } catch (Throwable $throwable) {
            $importRun?->markFailed($throwable);
            $this->error($throwable->getMessage());

            return self::FAILURE;
        }
    }

    private function importRun(): ?ImportRun
    {
        $importRunId = $this->option('import-run-id');

        if ($importRunId === null || $importRunId === '') {
            return null;
        }

        return ImportRun::query()->find((int) $importRunId);
    }
}
