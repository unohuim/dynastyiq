<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Services\NhlImportOrchestrator;

class NhlOrchestratorJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const TAG_IMPORT = 'nhl-import-date';

    public string $gameDate;

    /**
     * Create a new job instance.
     */
    public function __construct(string $gameDate)
    {
        $this->gameDate = $gameDate;
    }

    /**
     * Execute the job.
     */
    public function handle(NhlImportOrchestrator $orchestrator): void
    {
        $orchestrator->processScheduled($this->gameDate);
    }

    /**
     * Get the tags for the job.
     *
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [self::TAG_IMPORT, $this->gameDate];
    }
}
