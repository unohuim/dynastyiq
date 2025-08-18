<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ImportCapWagesJob;
use App\Services\ImportCapWages;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Artisan command to kick off CapWages imports.
 *
 * Usage: php artisan cap:import [--per-page=100]
 */
class ImportCapWagesCommand extends Command
{
    protected $signature = 'cap:import {--per-page=100 : Players per page to fetch}';
    protected $description = 'Dispatch per-page jobs to import players/contracts from CapWages';

    public function handle(): int
    {
        $perPage = max(1, (int) $this->option('per-page'));

        try {
            // Initial call only to read pagination meta
            $service   = new ImportCapWages();
            $response  = $service->fetchPlayersPage(1, $perPage);
            $totalPages = (int) ($response['meta']['pagination']['totalPages'] ?? 1);

            // Dispatch a page job for each page
            for ($page = 1; $page <= $totalPages; $page++) {
                ImportCapWagesJob::dispatch($page, $perPage);
            }

            $this->info("Queued ImportCapWagesJob for {$totalPages} page(s) at {$perPage} per page.");
            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('cap:import failed to initialize pagination', [
                'perPage' => $perPage,
                'error'   => $e->getMessage(),
            ]);
            $this->error('Failed to initialize CapWages pagination. See logs.');
            return self::FAILURE;
        }
    }
}
