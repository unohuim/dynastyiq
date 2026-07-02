<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\PlayerExternalIdentity;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a provider identity is linked to a player record.
 */
class PlayerExternalIdentityLinked
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly PlayerExternalIdentity $identity,
        public readonly ?int $previousPlayerId,
        public readonly int $playerId,
    ) {
    }
}
