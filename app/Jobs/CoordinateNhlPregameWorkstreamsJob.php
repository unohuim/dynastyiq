<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\NhlPregameWorkstreams;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Admit a bounded set of pilot workstream tasks; coalesce repeated wakeups. */
class CoordinateNhlPregameWorkstreamsJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 120;
    public int $tries = 2;
    public int $uniqueFor = 120;

    /** Keep operational prediction and historical maintenance work on the existing projection pool. */
    public function __construct(public int $modelId)
    {
        $this->onQueue('projections');
        $this->afterCommit = true;
    }

    /** Coalesce model-scoped wakeups without serializing unrelated models. */
    public function uniqueId(): string
    {
        return 'pregame-workstreams:'.$this->modelId;
    }

    /** Reconcile leases and admit bounded dependency-ready work. */
    public function handle(NhlPregameWorkstreams $workstreams): void
    {
        $workstreams->coordinate($this->modelId);
    }
}
