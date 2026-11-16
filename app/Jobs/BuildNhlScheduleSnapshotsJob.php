<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\NhlScheduleSnapshots;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/** Coalesce pending refreshes while allowing writes during a build to queue a follow-up. */
class BuildNhlScheduleSnapshotsJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;
    public int $tries = 20;
    public int $maxExceptions = 3;
    public int $uniqueFor = 300;

    /** Carry season scope only; all display calculations occur on the worker. */
    public function __construct(public string $seasonId)
    {
        $this->onQueue('games-processing');
    }

    /** Keep pending unique admission held while another worker is building. */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('nhl-schedule-snapshots:worker'))->shared()->releaseAfter(10)->expireAfter(190)];
    }

    /** Share unique admission between web and worker hosts. */
    public function uniqueVia(): Repository
    {
        return Cache::store('database');
    }

    /** Deduplicate only identical season refreshes. */
    public function uniqueId(): string
    {
        return $this->seasonId;
    }

    /** Publish a complete replacement while retaining the last successful data on failure. */
    public function handle(NhlScheduleSnapshots $snapshots): void
    {
        $snapshots->build($this->seasonId);
    }
}
