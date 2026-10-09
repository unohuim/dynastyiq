<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\GamesDiscovered;
use App\Services\DiscoveredGameProcessing;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;

/** Start bounded processing outside the discovery transaction and worker. */
class StartDiscoveredGameProcessing implements ShouldQueueAfterCommit
{
    public string $queue = 'default';
    public int $tries = 3;
    public int $timeout = 300;
    public int $backoff = 30;

    /** Delegate to the existing game-slot orchestrator through an idempotent service. */
    public function handle(GamesDiscovered $event): void
    {
        app(DiscoveredGameProcessing::class)->start($event->runId);
    }
}
