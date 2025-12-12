<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\ImportFantraxPlayer;
use App\Events\ImportStreamEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Imports/links a single Fantrax player entry.
 */
class ImportFantraxPlayerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, Batchable;

    public const TAG_IMPORT = 'import-fantrax-player';


    /**
     * @var array<string,mixed>
     */
    private array $entry;

    public function __construct(array $entry)
    {
        $this->entry = $entry;
    }

    public function handle(): void
    {
        $fantraxId = $this->entry['fantraxId'] ?? 'unknown';
        $fantraxName = $this->entry['name'] ?? 'no-name';

        ImportStreamEvent::dispatch('fantrax', "Importing Fantrax player {$fantraxId} -  {$fantraxName}", 'started');
        

        try {
            (new ImportFantraxPlayer())->syncOne($this->entry);
        } catch (\Throwable $e) {
            Log::error('[Fantrax] ImportFantraxPlayerJob failed', [
                'fantraxId' => $this->entry['fantraxId'] ?? null,
                'name'      => $this->entry['name'] ?? null,
                'error'     => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function tags(): array
    {
        $fid = $this->entry['fantraxId'] ?? 'unknown';
        return ['import-fantrax-player', "fantrax-id:{$fid}"];
    }
}
