<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Events\NhlGameImportStatusUpdated;
use App\Models\NhlGameImportRun;
use App\Services\NhlGameImportRebuilder;
use App\Services\NhlGameImportRunControl;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Rebuild one game's derived on-ice graph under the existing run cancellation fence. */
class RebuildNhlOnIceStatsJob implements ShouldQueue
{
    use Batchable, Queueable, InteractsWithQueue, Concerns\ControlsNhlGameImportRun;

    public int $tries = 0;
    public int $maxExceptions = 3;
    public int $timeout = 600;
    public array $backoff = [30, 120, 300];

    /** Capture the scoped run generation and retain ordinary import queue routing. */
    public function __construct(public int $nhlGameId, public ?int $runId = null)
    {
        $this->onQueue('default');
        $this->afterCommit = true;
        $this->captureRunGeneration();
    }

    /** Share the canonical pipeline's game lock so derived rebuilds cannot overlap game imports. */
    public function middleware(): array
    {
        return [new Middleware\ControlNhlGameImportRun(),
            (new WithoutOverlapping('nhl-game-import:'.$this->nhlGameId))->shared()->releaseAfter(30)->expireAfter(720)];
    }

    /** Reuse the canonical transformations without rerunning raw imports or SAT facts. */
    public function handle(NhlGameImportRebuilder $rebuilder): void
    {
        if (! $this->batch()?->cancelled()) {
            $rebuilder->rebuildOnIce($this->nhlGameId);
        }
    }

    /** Preserve failures in the scoped run without reviving a cancelled generation. */
    public function failed(Throwable $exception): void
    {
        if ($this->runId === null || ! $this->canReportRunFailure()) {
            return;
        }
        DB::transaction(function () use ($exception): void {
            $run = NhlGameImportRun::query()->lock('for no key update')->find($this->runId);
            if ($run === null || ! $this->canReportRunFailure()) {
                return;
            }
            $payload = $run->payload ?? [];
            $payload['on_ice_failed_game_ids'] = array_values(array_unique([
                ...($payload['on_ice_failed_game_ids'] ?? []), $this->nhlGameId,
            ]));
            app(NhlGameImportRunControl::class)->saveWorkerRun($run, [
                'status' => 'failed', 'last_error' => mb_substr($exception->getMessage(), 0, 1000), 'payload' => $payload,
            ]);
        }, 3);
        broadcast(new NhlGameImportStatusUpdated('on-ice-rebuild-failed', $this->runId, $this->nhlGameId));
    }

    /** Identify the selected game and scoped parent in Horizon. */
    public function tags(): array
    {
        return ['nhl-on-ice-rebuild', 'game:'.$this->nhlGameId, 'run:'.$this->runId];
    }
}
