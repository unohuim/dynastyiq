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
use Illuminate\Support\Facades\DB;
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
        $this->onQueue('fantrax');
    }

    public function handle(ImportFantraxPlayers $importer): void
    {
        if ($this->cacheKey === 'persisted') {
            $this->handlePersisted($importer);

            return;
        }

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
        if ($this->cacheKey === 'persisted') {
            DB::transaction(function () use ($throwable): void {
                $run = ImportRun::query()->lockForUpdate()->find($this->importRunId);
                if ($run?->status === 'working'
                    && (int) ($run->meta['fantrax_players']['offset'] ?? 0) <= $this->offset) {
                    $run->markFailed($throwable);
                }
            });

            return;
        }

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

    /** Process and checkpoint together so duplicate deliveries cannot double-count. */
    private function handlePersisted(ImportFantraxPlayers $importer): void
    {
        DB::transaction(function () use ($importer): void {
            $run = ImportRun::query()->lockForUpdate()->find($this->importRunId);
            if ($run === null || $run->source !== 'fantrax' || $run->status !== 'working') {
                return;
            }

            $meta = $run->meta ?? [];
            $snapshot = $meta['fantrax_players'] ?? null;
            if (! is_array($snapshot) || ! is_array($snapshot['entries'] ?? null)) {
                throw new \RuntimeException('Fantrax player import snapshot is missing.');
            }

            $offset = (int) $snapshot['offset'];
            $total = count($snapshot['entries']);
            if ($this->offset > $offset) {
                throw new \RuntimeException('Fantrax player chunk is ahead of its saved checkpoint.');
            }
            if ($this->offset === $offset) {
                $chunk = array_slice($snapshot['entries'], $offset, self::CHUNK_SIZE);
                $importer->importChunk($chunk, $run->id);
                $offset += count($chunk);
                $meta['fantrax_players']['offset'] = $offset;
                if ($offset >= $total) {
                    unset($meta['fantrax_players']);
                    $run->update(['meta' => $meta]);
                    $run->markCompleted();
                    $this->notifyAfterCommit('Fantrax player import completed', 'finished');

                    return;
                }
                $run->update(['meta' => $meta]);
                $this->notifyAfterCommit("Processed Fantrax players {$offset} / {$total}", 'progress');
            }

            // Re-delivery after a committed checkpoint can repair a failed enqueue.
            self::dispatch('persisted', $offset, $total, $run->id)
                ->onConnection($this->connection)
                ->afterCommit();
        });
    }

    /** UI delivery must not fail already committed import work. */
    private function notifyAfterCommit(string $message, string $status): void
    {
        DB::afterCommit(static function () use ($message, $status): void {
            try {
                ImportStreamEvent::dispatch('fantrax', $message, $status);
            } catch (Throwable $throwable) {
                report($throwable);
            }
        });
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
