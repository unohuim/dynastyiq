<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\ImportCapWagesPlayerJob;
use App\Models\Player;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Bus;
use App\Traits\HasAPITrait;

/**
 * Class ImportCapWages
 *
 * Iterates all players and dispatches a job per player
 * to import contract data asynchronously.
 */
class ImportCapWages
{
    use HasAPITrait;

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
     * Dispatch import jobs for all players in the database.
     */
    public function import(): void
    {
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
                    try {                        
                        ImportCapWagesPlayerJob::dispatch($slug);
                    } catch (\Exception $e) {
                        Log::error("Failed to import CapWages data for slug {$slug}", [
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }

            $pagination = $response['meta']['pagination'] ?? [];
            $totalPages = (int) ($pagination['totalPages'] ?? $page);
            $page++;
        } while ($page <= $totalPages);
    }

















    // public function import(): void
    // {
    //     $players = Player::all();

    //     foreach ($players as $player) {
    //         try {
    //             //ImportCapWagesPlayerJob::dispatch($player->nhl_id);
    //             $i = new ImportCapWagesPlayer();
    //             $i->sync($player->nhl_id);
    //         } catch (\Exception $e) {
    //             Log::error("Failed to dispatch CapWages import job for NHL player ID {$player->nhl_id}", [
    //                 'error' => $e->getMessage(),
    //             ]);
    //         }
    //     }
    // }
}
