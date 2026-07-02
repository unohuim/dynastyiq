<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\ImportStreamEvent;
use App\Events\PlayersAvailable;
use App\Jobs\ImportFantraxPlayerJob;
use App\Models\FantraxPlayer;
use App\Traits\HasAPITrait;
use Illuminate\Support\Facades\Log;

/**
 * Fetches Fantrax player metadata and dispatches a per-player job.
 */
class ImportFantraxPlayers
{
    use HasAPITrait;

    /**
     * Fetch all Fantrax player entries and queue per-player imports.
     */
    public function import(?int $importRunId = null): void
    {
        $entries = $this->fetchEntries();

        if ($importRunId !== null) {
            $this->importChunk($entries, $importRunId);
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

    /**
     * @return array<int,array<string,mixed>>
     */
    public function fetchEntries(): array
    {
        $entries = $this->getAPIData('fantrax', 'players');

        if (! is_array($entries)) {
            Log::error('[Fantrax] Unexpected response format', ['response' => $entries]);
            return [];
        }

        return array_values($entries);
    }

    /**
     * @param array<int,array<string,mixed>> $entries
     */
    public function importChunk(array $entries, ?int $importRunId = null): void
    {
        $fantraxPlayersExistedBefore = FantraxPlayer::query()->exists();
        $playerImport = new ImportFantraxPlayer();

        foreach ($entries as $entry) {
            $fantraxId = $entry['fantraxId'] ?? 'unknown';
            $fantraxName = $entry['name'] ?? 'no-name';

            ImportStreamEvent::dispatch('fantrax', "Importing Fantrax player {$fantraxId} - {$fantraxName}", 'started');

            try {
                $result = $playerImport->syncOne($entry);
                $this->recordProcessedRecord($importRunId, $result);

                if (! $fantraxPlayersExistedBefore && FantraxPlayer::query()->exists()) {
                    broadcast(new PlayersAvailable('fantrax', FantraxPlayer::query()->count()));
                    $fantraxPlayersExistedBefore = true;
                }
            } catch (\Throwable $e) {
                $this->recordProcessedRecord($importRunId, 'failed');

                Log::error('[Fantrax] Inline player import failed', [
                    'fantraxId' => $entry['fantraxId'] ?? null,
                    'name' => $entry['name'] ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function recordProcessedRecord(?int $importRunId, string $result): void
    {
        if ($importRunId === null) {
            return;
        }

        \App\Models\ImportRun::query()->find($importRunId)?->recordProcessed($result);
    }
}
