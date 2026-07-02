<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Player;
use App\Models\PlayerExternalIdentity;
use App\Services\NhlPlayerIdentityLookup;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Attempts to attach an NHL API identity to a provisional canonical player.
 */
class ResolveCanonicalPlayerNhlIdentityJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Keep one NHL lookup queued or running for a canonical player.
     */
    public int $uniqueFor = 900;

    public function __construct(
        public readonly int $playerId,
        public readonly ?int $sourceIdentityId = null,
    ) {
        $this->onConnection('database');
    }

    public function handle(NhlPlayerIdentityLookup $lookup): void
    {
        $player = Player::query()->find($this->playerId);
        $identity = $this->sourceIdentityId !== null
            ? PlayerExternalIdentity::query()->find($this->sourceIdentityId)
            : null;

        if (! $player || $player->nhl_id !== null) {
            return;
        }

        if ($this->sourceIdentityId !== null && ! $identity) {
            return;
        }

        $lookup->enrich($player, $identity);
    }

    /**
     * Unique key for queued NHL identity lookup jobs.
     */
    public function uniqueId(): string
    {
        return (string) $this->playerId;
    }

    /**
     * @return array<int,string>
     */
    public function tags(): array
    {
        return ['resolve-canonical-player-nhl-identity', "player:{$this->playerId}"];
    }
}
