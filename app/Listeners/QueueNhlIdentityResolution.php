<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\PlayerExternalIdentityLinked;
use App\Jobs\ResolveCanonicalPlayerNhlIdentityJob;
use App\Models\Player;
use App\Models\PlayerExternalIdentity;
use App\Services\NhlPlayerIdentityLookup;

/**
 * Queues NHL identity enrichment for provisional canonical players.
 */
class QueueNhlIdentityResolution
{
    private NhlPlayerIdentityLookup $lookup;

    public function __construct(?NhlPlayerIdentityLookup $lookup = null)
    {
        $this->lookup = $lookup ?? app(NhlPlayerIdentityLookup::class);
    }

    public function handle(PlayerExternalIdentityLinked $event): void
    {
        if (in_array($event->identity->provider, [
            PlayerExternalIdentity::PROVIDER_NHL,
            PlayerExternalIdentity::PROVIDER_NHL_DRAFT,
        ], true)) {
            return;
        }

        $player = Player::query()->find($event->playerId);

        if (! $player || $player->nhl_id !== null || ! $this->lookup->hasLookupEvidence($player, $event->identity)) {
            return;
        }

        ResolveCanonicalPlayerNhlIdentityJob::dispatch($player->id, $event->identity->id);
    }
}
