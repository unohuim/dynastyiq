<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\NhlPregameContextOrchestrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Claims one bounded page of durable pregame-context game work. */
class LoadNhlPregameContextRunJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;

    public function __construct(public int $runId)
    {
        $this->afterCommit = true;
    }

    public function handle(NhlPregameContextOrchestrator $orchestrator): void
    {
        $orchestrator->load($this->runId);
    }
}
