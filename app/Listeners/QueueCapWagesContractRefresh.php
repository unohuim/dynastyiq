<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\PlayerExternalIdentityLinked;
use App\Jobs\RefreshCapWagesContractsForIdentityJob;
use App\Models\CapWagesPlayer;
use App\Models\PlayerExternalIdentity;
use App\Services\ImportCapWagesPlayer;

/**
 * Queues CapWages contract refresh work after a CapWages identity is linked.
 */
class QueueCapWagesContractRefresh
{
    public function handle(PlayerExternalIdentityLinked $event): void
    {
        if ($event->identity->provider !== PlayerExternalIdentity::PROVIDER_CAPWAGES) {
            return;
        }

        $capWagesPlayer = $this->capWagesPlayer($event->identity);

        if ($capWagesPlayer?->raw_payload !== null) {
            app(ImportCapWagesPlayer::class)->materializeCachedContractsForLinkedIdentity($event->identity);
            return;
        }

        if ($capWagesPlayer !== null) {
            return;
        }

        RefreshCapWagesContractsForIdentityJob::dispatch($event->identity->id);
    }

    /**
     * Find any cached CapWages profile row for this identity.
     */
    private function capWagesPlayer(PlayerExternalIdentity $identity): ?CapWagesPlayer
    {
        return CapWagesPlayer::query()
            ->where('player_external_identity_id', $identity->id)
            ->first();
    }
}
