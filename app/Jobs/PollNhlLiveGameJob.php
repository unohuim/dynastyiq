<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\NhlLiveGameFeed;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** One bounded provider poll per game, independent of viewer count. */
final class PollNhlLiveGameJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 20;
    public int $timeout = 10;
    public int $tries = 1;

    /** Drop obsolete queued ticks rather than replaying a backlog of live polls. */
    public function __construct(public int $gameId, public int $queuedAt)
    {
        $this->onQueue('nhl-live');
    }

    /** Shared queue uniqueness covers the pending and running lifecycle. */
    public function uniqueId(): string
    {
        return (string) $this->gameId;
    }

    /** Make per-game ingestion and failures identifiable in Horizon. */
    public function tags(): array
    {
        return ['nhl-live', 'game:'.$this->gameId];
    }

    /** The scheduler will admit a new tick after failure or a skipped stale tick. */
    public function handle(NhlLiveGameFeed $feed): void
    {
        if (now()->timestamp - $this->queuedAt <= 10) {
            $feed->poll($this->gameId);
        }
    }
}
