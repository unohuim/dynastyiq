<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\ImportStreamEvent;
use App\Events\PlayersAvailable;
use App\Jobs\ImportFantraxPlayersChunkJob;
use App\Models\FantraxPlayer;
use App\Models\ImportRun;
use App\Traits\HasAPITrait;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Fetches Fantrax player metadata for one durable, bounded import.
 */
class ImportFantraxPlayers
{
    use HasAPITrait;

    /**
     * Route legacy callers through the same bounded import path.
     */
    public function import(?int $importRunId = null): void
    {
        if ($importRunId !== null) {
            $this->prepare($importRunId);
            return;
        }

        app(AdminImports::class)->dispatch('fantrax');
    }

    /** Persist input once; retries reuse the same snapshot and cursor. */
    public function prepare(int $importRunId): void
    {
        $run = ImportRun::query()->findOrFail($importRunId);
        if ($run->source !== 'fantrax' || $run->status !== 'working') {
            return;
        }

        $entries = isset($run->meta['fantrax_players']) ? null : $this->fetchEntries();

        DB::transaction(function () use ($importRunId, $entries): void {
            $run = ImportRun::query()->lockForUpdate()->findOrFail($importRunId);
            if ($run->status !== 'working') {
                return;
            }
            $meta = $run->meta ?? [];
            if (! isset($meta['fantrax_players'])) {
                $meta['fantrax_players'] = ['entries' => $entries ?? [], 'offset' => 0];
                $run->update([
                    'meta' => $meta,
                    'total_records' => count($entries ?? []),
                    'progress_label' => 'Fantrax player records',
                ]);
            }
            ImportFantraxPlayersChunkJob::dispatch(
                'persisted',
                $meta['fantrax_players']['offset'],
                count($meta['fantrax_players']['entries']),
                $run->id,
            )->afterCommit();
        });
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
