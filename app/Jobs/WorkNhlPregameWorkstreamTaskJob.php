<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\NhlPregameWorkstreams;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/** Execute one bounded owned page, then release capacity for the next allocation. */
class WorkNhlPregameWorkstreamTaskJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 300;
    public int $tries = 1;
    public bool $failOnTimeout = true;

    /** Carry only durable task identity and its fenced execution token. */
    public function __construct(public int $taskId, public string $claimToken)
    {
        $this->onQueue('projections');
        $this->afterCommit = true;
    }

    /** Execute one owned page with canonical calculations. */
    public function handle(NhlPregameWorkstreams $workstreams): void
    {
        $workstreams->execute($this->taskId, $this->claimToken);
    }

    /** Preserve a visible failure or bounded timeout recovery in the task ledger. */
    public function failed(Throwable $error): void
    {
        app(NhlPregameWorkstreams::class)->failed($this->taskId, $this->claimToken, $error);
    }
}
