<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Ratings or prospect imports changed facts used by prepared public season reports. */
class NhlStatsSourceUpdated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /** Carry season scope without serializing source rows or importing statistics again. */
    public function __construct(public string $seasonId, public ?int $runId = null)
    {
    }
}
