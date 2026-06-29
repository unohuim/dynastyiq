<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\PlayerExternalIdentityLinked;
use App\Jobs\ResolveCanonicalPlayerNhlIdentityJob;
use App\Models\Player;
use App\Models\PlayerExternalIdentity;

/**
 * Queues NHL identity enrichment for provisional canonical players.
 */
class QueueNhlIdentityResolution
{
    public function handle(PlayerExternalIdentityLinked $event): void
    {
        if (in_array($event->identity->provider, [
            PlayerExternalIdentity::PROVIDER_NHL,
            PlayerExternalIdentity::PROVIDER_NHL_DRAFT,
        ], true)) {
            return;
        }

        $player = Player::query()->find($event->playerId);

        if (! $player || $player->nhl_id !== null || ! $this->hasLookupEvidence($player, $event->identity)) {
            return;
        }

        ResolveCanonicalPlayerNhlIdentityJob::dispatch($player->id, $event->identity->id);
    }

    private function hasLookupEvidence(Player $player, PlayerExternalIdentity $identity): bool
    {
        $displayName = trim((string) ($identity->display_name ?: $player->full_name));
        $position = trim((string) ($identity->position ?: $player->position));

        return $displayName !== ''
            && str_contains($displayName, ' ')
            && $this->positionType($position) !== null;
    }

    private function positionType(?string $position): ?string
    {
        $position = mb_strtoupper(trim((string) $position));

        return match ($position) {
            'G' => 'G',
            'D', 'LD', 'RD' => 'D',
            'F', 'C', 'L', 'R', 'LW', 'RW' => 'F',
            default => null,
        };
    }
}
