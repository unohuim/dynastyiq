<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Player;
use App\Services\ImportNHLPlayer;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Refreshes canonical NHL player metadata and landing season totals.
 */
class RefreshNhlPlayerLandingJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Keep one landing refresh queued or running for an NHL player id.
     */
    public int $uniqueFor = 900;

    public function __construct(public readonly int $nhlPlayerId)
    {
        $this->onConnection('database');
    }

    public function handle(ImportNHLPlayer $importer): void
    {
        $isProspect = (bool) Player::query()
            ->where('nhl_id', $this->nhlPlayerId)
            ->value('is_prospect');

        $importer->import((string) $this->nhlPlayerId, $isProspect);
    }

    /**
     * Unique key for queued NHL landing refresh jobs.
     */
    public function uniqueId(): string
    {
        return (string) $this->nhlPlayerId;
    }

    /**
     * @return array<int,string>
     */
    public function tags(): array
    {
        return ['refresh-nhl-player-landing', "nhl_player_id:{$this->nhlPlayerId}"];
    }
}
