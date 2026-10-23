<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** A season's summary writes have committed, independently of the sync entrypoint. */
class NhlSeasonStatsSynced implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /** Carry the affected season and result count without serializing player rows. */
    public function __construct(public string $seasonId, public int $rowsUpserted)
    {
    }
}
