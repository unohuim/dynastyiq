<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Classes\ImportFantraxPlayers;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Bus\Batchable;

class ImportFantraxPlayersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, Batchable;

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
        // Delegate to the ImportFantraxPlayers class
        (new ImportFantraxPlayers())->import();
    }
}
