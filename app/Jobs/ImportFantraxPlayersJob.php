<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\ImportFantraxPlayers;
use App\Events\ImportStreamEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Bus\Batchable;

class ImportFantraxPlayersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, Batchable;

    public const TAG_IMPORT = 'import-fantax-players';

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        // No constructor parameters—this job will import the full list
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        ImportStreamEvent::dispatch('fantrax', 'Starting Fantrax players import', 'started');

        // Delegate to the ImportFantraxPlayers class
        (new ImportFantraxPlayers())->import();

        ImportStreamEvent::dispatch('fantrax', 'Finished Fantrax players import', 'finished');
    }


    public function tags(): array
    {
        return [self::TAG_IMPORT];
    }
}
