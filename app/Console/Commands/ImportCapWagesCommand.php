<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ImportCapWagesJob;
use App\Models\ImportRun;
use App\Services\ImportCapWages;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Artisan command to kick off CapWages imports.
 *
 * Usage: php artisan cap:import [--per-page=100] [--all=true]
 */
class ImportCapWagesCommand extends Command
{
    protected $signature = 'cap:import 
                            {--per-page=100 : Players per page to fetch} 
                            {--all=true : When true, import missing players by dispatching NHL jobs; when false, skip missing players}
                            {--import-run-id= : Internal admin import run id}';

    protected $description = 'Dispatch per-page jobs to import players/contracts from CapWages';

    public function handle(): int
    {
        $perPage = max(1, (int) $this->option('per-page'));
        $allFlag = filter_var($this->option('all'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $importRunId = $this->option('import-run-id')
            ? (int) $this->option('import-run-id')
            : null;

        // default to true if option not parseable
        $all = $allFlag ?? true;

        try {
            // Initial call only to read pagination meta
            $service    = new ImportCapWages();
            $response   = $service->fetchPlayersPage(1, $perPage);
            $totalPages = (int) ($response['meta']['pagination']['totalPages'] ?? 1);
            $totalRecords = isset($response['meta']['pagination']['total'])
                ? (int) $response['meta']['pagination']['total']
                : null;

            if ($importRunId !== null) {
                ImportRun::query()
                    ->find($importRunId)
                    ?->setProgressTotal($totalRecords, 'CapWages player records');
            }

            ImportCapWagesJob::dispatch(1, $perPage, $all, $importRunId, $totalPages);

            $this->info("Queued sequential ImportCapWagesJob crawl for {$totalPages} page(s) at {$perPage} per page (all=" . ($all ? 'true' : 'false') . ").");
            return self::SUCCESS;
        } catch (\Throwable $e) {
            if ($importRunId !== null) {
                ImportRun::query()->find($importRunId)?->markFailed($e);
            }

            Log::error('cap:import failed to initialize pagination', [
                'perPage' => $perPage,
                'all'     => $all,
                'error'   => $e->getMessage(),
            ]);
            $this->error('Failed to initialize CapWages pagination. See logs.');
            return self::FAILURE;
        }
    }
}
