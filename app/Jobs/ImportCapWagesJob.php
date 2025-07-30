<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Classes\ImportCapWages;
use App\Traits\HasAPITrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Bus\Batchable;

/**
 * Class ImportCapWagesJob
 *
 * Fetches the paginated list of players from the CapWages “players” endpoint,
 * then for each player slug invokes ImportCapWages::importBySlug().
 *
 * @package App\Jobs
 */
class ImportCapWagesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use HasAPITrait;
    use Batchable;

    /**
     * Number of players to fetch per page.
     *
     * @var int
     */
    private int $perPage;

    /**
     * Create a new job instance.
     *
     * @param int $perPage Optional page size for the players endpoint; defaults to 100.
     */
    public function __construct(int $perPage = 100)
    {
        $this->perPage = $perPage;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(): void
    {
        $importer = new ImportCapWages();
        $page = 1;
        $totalPages = 1;

        do {
            try {
                $response = $this->getAPIData(
                    'capwages',
                    'players',
                    [],
                    ['page' => $page, 'limit' => $this->perPage]
                );
            } catch (\Exception $e) {
                Log::error("Failed to fetch CapWages players page {$page}", [
                    'error' => $e->getMessage(),
                ]);
                break;
            }

            foreach ($response['data'] ?? [] as $playerInfo) {
                $slug = $playerInfo['slug'] ?? null;
                if ($slug) {
                    $importer->importBySlug($slug);
                }
            }

            $pagination = $response['meta']['pagination'] ?? [];
            $totalPages = (int) ($pagination['totalPages'] ?? $page);
            $page++;
        } while ($page <= $totalPages);
    }
}
