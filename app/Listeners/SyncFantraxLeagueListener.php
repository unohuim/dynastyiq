<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\FantraxLeagueCreated;
use App\Jobs\SyncFantraxLeagueJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

final class SyncFantraxLeagueListener implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Ensure this listener dispatches only after surrounding transactions commit.
     */
    public bool $afterCommit = true;

    public function handle(FantraxLeagueCreated $event): void
    {
        SyncFantraxLeagueJob::dispatch($event->platformLeagueId);
    }
}
