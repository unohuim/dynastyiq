<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\NhlImportOrchestrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class NhlOrchestratorJob implements ShouldQueue
{
    use \App\Jobs\Concerns\ControlsNhlGameImportRun;
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const TAG_IMPORT = 'nhl-import-date';

    public string $gameDate;
    public ?int $runId;

    /**
     * Create a new job instance.
     */
    public function __construct(string $gameDate, ?int $runId = null)
    {
        $this->gameDate = $gameDate;
        $this->runId = $runId;
        $this->captureRunGeneration();
    }

    /** Fence run-owned coordinators while preserving unscoped legacy invocations. */
    public function middleware(): array
    {
        return [new \App\Jobs\Middleware\ControlNhlGameImportRun()];
    }

    /**
     * Execute the job.
     */
    public function handle(NhlImportOrchestrator $orchestrator): void
    {
        $orchestrator->processScheduled($this->gameDate, $this->runId);
    }

    /**
     * Get the tags for the job.
     *
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [
            self::TAG_IMPORT,
            $this->gameDate,
            'run-id:' . ($this->runId ?? 'none'),
        ];
    }
}
