<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\SumNHLPlayByPlay;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

/**
 * Rebuilds one game's PBP-derived summary splits from already imported play-by-play rows.
 */
class RefreshNhlSpecialTeamsSplitsJob implements ShouldQueue
{
    use Queueable;
    use InteractsWithQueue;
    use SerializesModels;

    /**
     * @var int
     */
    public int $tries = 3;

    /**
     * @var array<int,int>
     */
    public array $backoff = [30, 120, 300];

    /**
     * @var int
     */
    public int $timeout = 180;

    public function __construct(public int $nhlGameId)
    {
        $this->afterCommit = true;
    }

    /**
     * Prevent duplicate summary refreshes for the same NHL game.
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('nhl-special-teams-splits:' . $this->nhlGameId))
                ->expireAfter(300),
        ];
    }

    /**
     * @return array<int,string>
     */
    public function tags(): array
    {
        return [
            'nhl-special-teams-splits',
            'game:' . $this->nhlGameId,
        ];
    }

    public function handle(SumNHLPlayByPlay $summary): void
    {
        $summary->summarize($this->nhlGameId, reconcileGoalies: false);
    }
}
