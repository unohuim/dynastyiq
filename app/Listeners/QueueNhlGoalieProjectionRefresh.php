<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\NhlSeasonStatsSynced;
use App\Services\NhlGoalieProjectionRefresh;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/** Prepare production SAT goalie ratings after committed season syncs, regardless of entrypoint. */
class QueueNhlGoalieProjectionRefresh implements ShouldHandleEventsAfterCommit
{
    /** Record pending preparation before the independently queued cache publishers can finish. */
    public function handle(NhlSeasonStatsSynced $event): void
    {
        app(NhlGoalieProjectionRefresh::class)->queueSeason($event->seasonId, $event->runId);
    }
}
