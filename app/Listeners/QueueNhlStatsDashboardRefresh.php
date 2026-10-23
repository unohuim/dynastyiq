<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\NhlSeasonStatsSynced;
use App\Jobs\RefreshNhlStatsDashboardJob;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\DB;

/** Prepare shared public statistics after committed season summaries change. */
class QueueNhlStatsDashboardRefresh implements ShouldQueueAfterCommit
{
    public string $queue = 'projections';
    public int $tries = 3;
    public int $timeout = 30;
    public int $backoff = 30;

    /** Queue one bounded refresh per view, including comparisons affected in the following season. */
    public function handle(NhlSeasonStatsSynced $event): void
    {
        $seasons = [$event->seasonId];
        $following = (string) ((int) $event->seasonId + 10001);
        if (DB::table('nhl_season_stats')->where('season_id', $following)->where('game_type', 2)->where('gp', '>', 0)->exists()) {
            $seasons[] = $following;
        }
        foreach ($seasons as $season) {
            foreach (['leaders', 'skaters', 'goalies', 'teams'] as $view) {
                RefreshNhlStatsDashboardJob::dispatch($season, $view);
            }
        }
    }
}
