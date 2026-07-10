<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ImportRun;
use App\Models\PlatformLeague;
use App\Services\PlatformLeagueScoringCategoryService;
use Illuminate\Console\Command;
use Throwable;

class BackfillPlatformLeagueScoringCategoriesCommand extends Command
{
    protected $signature = 'platform-leagues:backfill-scoring-categories
                            {--platform=}
                            {--import-run-id= : Internal admin import run id}';

    protected $description = 'Backfill platform league scoring category rows from legacy scoring_settings JSON.';

    public function handle(PlatformLeagueScoringCategoryService $scoringCategoryService): int
    {
        $importRun = $this->importRun();

        try {
            $query = PlatformLeague::query()
                ->whereNotNull('scoring_settings')
                ->orderBy('id');

            $platform = trim((string) $this->option('platform'));

            if ($platform !== '') {
                $query->where('platform', $platform);
            }

            $processed = 0;
            $backfilled = 0;

            $query->chunkById(100, function ($leagues) use ($scoringCategoryService, &$processed, &$backfilled): void {
                foreach ($leagues as $league) {
                    if (! $league instanceof PlatformLeague) {
                        continue;
                    }

                    $processed++;
                    $categories = data_get($league, 'scoring_settings.categories', []);

                    if (! is_array($categories) || $categories === []) {
                        continue;
                    }

                    $manualMappings = data_get($league, 'scoring_settings.manual_mappings', []);
                    $scoringCategoryService->sync(
                        $league,
                        array_is_list($categories) ? $categories : array_values($categories),
                        is_array($manualMappings) ? $manualMappings : [],
                    );
                    $backfilled++;
                }
            });

            $importRun?->markCompleted();
            $this->components->info("Processed {$processed} platform leagues; backfilled {$backfilled} scoring category sets.");

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
