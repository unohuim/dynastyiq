<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\NhlPregameContextRun;
use App\Services\NhlPregameContextOrchestrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/** Seeds durable game work for one pregame-context run. */
class PrepareNhlPregameContextRunJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;
    public int $timeout = 120;

    public function __construct(public int $runId)
    {
        $this->afterCommit = true;
    }

    public function handle(NhlPregameContextOrchestrator $orchestrator): void
    {
        $run = NhlPregameContextRun::query()->find($this->runId);
        if ($run !== null) {
            $orchestrator->prepare($run);
        }
    }

    public function failed(Throwable $exception): void
    {
        NhlPregameContextRun::query()->whereKey($this->runId)->update([
            'status' => NhlPregameContextRun::STATUS_FAILED,
            'last_error' => mb_strimwidth($exception->getMessage(), 0, 2000),
            'updated_at' => now(),
        ]);
    }
}
