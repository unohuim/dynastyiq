<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Committed snapshots, league comparisons and ranks are complete for one model's game day. */
final class NhlPregameContextDayCompleted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /** Carry identifiers only; consumers recheck readiness before admitting work. */
    public function __construct(public int $runId, public int $modelRunId, public string $gameDate)
    {
    }
}
