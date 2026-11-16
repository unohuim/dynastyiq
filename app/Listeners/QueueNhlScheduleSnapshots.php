<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\NhlLiveGameUpdated;
use App\Events\NhlSeasonStatsSynced;
use App\Services\NhlScheduleSnapshots;

/** Refresh prepared schedule views after committed source results change. */
class QueueNhlScheduleSnapshots
{
    /** Ignore ordinary live clock changes; final corrections update remaining totals. */
    public function completed(NhlLiveGameUpdated $event): void
    {
        $game = $event->snapshot['game'] ?? [];
        if (($event->snapshot['changed'] ?? false) && in_array($game['game_state'] ?? '', ['FINAL', 'OFF'], true) && ! empty($game['game_date'])) {
            app(NhlScheduleSnapshots::class)->changedDate($game['game_date']);
        }
    }

    /** Completed historical imports also refresh their season's published weeks. */
    public function synced(NhlSeasonStatsSynced $event): void
    {
        app(NhlScheduleSnapshots::class)->queueSeason($event->seasonId);
    }
}
