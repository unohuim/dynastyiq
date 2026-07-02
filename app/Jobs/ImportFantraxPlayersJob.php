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
use Illuminate\Support\Str;
use Throwable;

class ImportFantraxPlayersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, Batchable;

    public const TAG_IMPORT = 'import-fantax-players';

    /**
     * Create a new job instance.
     */
    public function __construct(
        private ?int $importRunId = null,
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        ImportStreamEvent::dispatch('fantrax', 'Importing Fantrax players', 'started');

        if ($this->importRunId !== null) {
            $importer = new ImportFantraxPlayers();
            $entries = $importer->fetchEntries();
            $cacheKey = 'fantrax-import:entries:' . Str::uuid()->toString();
            Cache::put($cacheKey, $entries, now()->addHour());

            $this->importRun()?->setProgressTotal(count($entries), 'Fantrax player records');
            ImportFantraxPlayersChunkJob::dispatch($cacheKey, 0, count($entries), $this->importRunId);
            return;
        }

        (new ImportFantraxPlayers())->import();
    }

    public function failed(Throwable $throwable): void
    {
        $this->importRun()?->markFailed($throwable);
    }

    public function tags(): array
    {
        return [self::TAG_IMPORT];
    }

    private function importRun(): ?ImportRun
    {
        if ($this->importRunId === null) {
            return null;
        }

        return ImportRun::query()->find($this->importRunId);
    }
}
