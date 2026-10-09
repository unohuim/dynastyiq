<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** All discovery dates have committed their import progress for one run. */
class GamesDiscovered implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /** Carry the run identity, never game payloads. */
    public function __construct(public int $runId)
    {
    }
}
