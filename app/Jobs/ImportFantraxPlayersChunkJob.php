<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Events\ImportStreamEvent;
use App\Models\ImportRun;
use App\Services\ImportFantraxPlayers;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Imports one bounded chunk of Fantrax player records and dispatches the next chunk.
 */
class ImportFantraxPlayersChunkJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const CHUNK_SIZE = 100;

    public function __construct(
        private string $cacheKey,
        private int $offset,
        private int $totalRecords,
        private ?int $importRunId = null,
    ) {
    }

    public function handle(ImportFantraxPlayers $importer): void
    {
        $entries = Cache::get($this->cacheKey, []);

        if ((! is_array($entries) || $entries === []) && $this->totalRecords > 0) {
            $this->importRun()?->markFailed('Fantrax import entries cache expired before all chunks completed.');
            return;
        }

        $chunk = is_array($entries)
            ? array_slice($entries, $this->offset, self::CHUNK_SIZE)
            : [];

        if ($chunk === []) {
            $this->markCompleted();
            return;
        }

        $importer->importChunk($chunk, $this->importRunId);
        $this->broadcastProgress();

        $nextOffset = $this->offset + self::CHUNK_SIZE;

        if ($nextOffset >= $this->totalRecords) {
            $this->markCompleted();
            return;
        }

        self::dispatch($this->cacheKey, $nextOffset, $this->totalRecords, $this->importRunId);
    }

    public function failed(Throwable $throwable): void
    {
        Cache::forget($this->cacheKey);
        $this->importRun()?->markFailed($throwable);
    }

    /**
     * @return array<int,string>
     */
    public function tags(): array
    {
        return ['import-fantrax-players-chunk', "offset:{$this->offset}"];
    }

    private function markCompleted(): void
    {
        Cache::forget($this->cacheKey);
        $this->importRun()?->markCompleted();
        ImportStreamEvent::dispatch('fantrax', 'Fantrax player import completed', 'finished');
    }

    private function importRun(): ?ImportRun
    {
        if ($this->importRunId === null) {
            return null;
        }

        return ImportRun::query()->find($this->importRunId);
    }

    private function broadcastProgress(): void
    {
        $processed = min($this->offset + self::CHUNK_SIZE, $this->totalRecords);

        ImportStreamEvent::dispatch(
            'fantrax',
            "Processed Fantrax players {$processed} / {$this->totalRecords}",
            'progress',
        );
    }
}
