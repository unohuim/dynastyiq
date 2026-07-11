<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\ImportNhlBoxscore;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

/**
 * Refreshes one game's official goalie decisions from the NHL boxscore feed.
 */
class RefreshNhlGoalieDecisionJob implements ShouldQueue
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
    public int $timeout = 120;

    public function __construct(public int $nhlGameId)
    {
        $this->afterCommit = true;
    }

    /**
     * Prevent duplicate decision refreshes for the same NHL game.
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('nhl-goalie-decisions:' . $this->nhlGameId))
                ->expireAfter(300),
        ];
    }

    /**
     * @return array<int,string>
     */
    public function tags(): array
    {
        return [
            'nhl-goalie-decisions',
            'game:' . $this->nhlGameId,
        ];
    }

    public function handle(ImportNhlBoxscore $boxscore): void
    {
        $boxscore->refreshGoalieDecisions($this->nhlGameId);
    }
}
