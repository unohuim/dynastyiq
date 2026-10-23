<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** A game's imported shot-attempt facts have committed and can be rated. */
class SatImported implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /** Carry source identities rather than serializing shot facts. */
    public function __construct(
        public int $nhlGameId,
        public string $seasonId,
        public int $gameType,
    ) {
    }
}
