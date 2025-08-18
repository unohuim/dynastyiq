<?php

declare(strict_types=1);

namespace App\Services;

use App\Traits\HasAPITrait;
use Illuminate\Support\Facades\Log;
use App\Jobs\ImportFantraxPlayerJob;

/**
 * Fetches Fantrax player metadata and dispatches a per-player job.
 */
class ImportFantraxPlayers
{
    use HasAPITrait;

    /**
     * Fetch all Fantrax player entries and queue per-player imports.
     */
    public function import(): void
    {
        $entries = $this->getAPIData('fantrax', 'players');

        if (!is_array($entries)) {
            Log::error('[Fantrax] Unexpected response format', ['response' => $entries]);
            return;
        }

        foreach ($entries as $entry) {
            try {
                ImportFantraxPlayerJob::dispatch($entry);
            } catch (\Throwable $e) {
                Log::error('[Fantrax] Failed to dispatch ImportFantraxPlayerJob', [
                    'entry_preview' => is_array($entry) ? array_intersect_key($entry, array_flip(['fantraxId', 'name', 'team', 'position'])) : $entry,
                    'error'         => $e->getMessage(),
                ]);
            }
        }
    }
}
