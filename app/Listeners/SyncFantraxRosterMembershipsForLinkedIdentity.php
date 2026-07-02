<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\PlayerExternalIdentityLinked;
use App\Jobs\SyncFantraxLeagueJob;
use App\Models\FantraxPlayer;
use App\Models\PlatformLeague;
use App\Models\PlayerExternalIdentity;
use Illuminate\Support\Facades\Log;

/**
 * Refresh Fantrax roster memberships after a Fantrax identity gains a canonical player.
 */
class SyncFantraxRosterMembershipsForLinkedIdentity
{
    /**
     * Handle the linked identity event.
     */
    public function handle(PlayerExternalIdentityLinked $event): void
    {
        if ($event->identity->provider !== PlayerExternalIdentity::PROVIDER_FANTRAX) {
            return;
        }

        $fantraxId = (string) $event->identity->provider_player_id;

        if ($fantraxId === '') {
            return;
        }

        $claimed = FantraxPlayer::query()
            ->where('player_id', $event->playerId)
            ->where('fantrax_id', '!=', $fantraxId)
            ->exists();

        if ($claimed) {
            Log::warning('[Fantrax] Linked identity could not update Fantrax player; canonical player is already claimed.', [
                'fantrax_id' => $fantraxId,
                'player_id' => $event->playerId,
            ]);

            return;
        }

        FantraxPlayer::query()->updateOrCreate(
            ['fantrax_id' => $fantraxId],
            ['player_id' => $event->playerId],
        );

        PlatformLeague::query()
            ->fantrax()
            ->pluck('id')
            ->each(static fn (int $platformLeagueId) => SyncFantraxLeagueJob::dispatch($platformLeagueId));
    }
}
