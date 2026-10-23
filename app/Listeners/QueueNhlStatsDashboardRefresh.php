<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\NhlGameImportStatusUpdated;
use App\Events\NhlSeasonStatsSynced;
use App\Events\NhlStatsSourceUpdated;
use App\Jobs\RefreshNhlStatsDashboardJob;
use App\Models\NhlGameImportRun;
use App\Models\Perspective;
use App\Services\NhlStatsDashboardPayload;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Prepare complete public season snapshots after committed source changes. */
class QueueNhlStatsDashboardRefresh implements ShouldQueueAfterCommit
{
    public string $queue = 'projections';
    public int $tries = 3;
    public int $timeout = 30;
    public int $backoff = 30;

    /** Queue source-season snapshots plus affected following-season comparisons. */
    public function handle(NhlSeasonStatsSynced|NhlStatsSourceUpdated $event): void
    {
        $this->queueSeason($event->seasonId, $event->runId);
    }

    /** Dispatch bounded publishers; admin syncs track the complete preparation batch. */
    public function queueSeason(string $season, ?int $runId = null): void
    {
        if (! preg_match('/^\d{8}$/', $season)) {
            throw new \InvalidArgumentException('Public stats preparation requires an eight-digit season.');
        }
        app(NhlStatsDashboardPayload::class)->refreshSeasonCatalogue();
        $seasons = [$season];
        $following = (string) ((int) $season + 10001);
        if (DB::table('nhl_season_stats')->where('season_id', $following)->where('game_type', 2)->where('gp', '>', 0)->exists()) {
            $seasons[] = $following;
        }
        $jobs = [new RefreshNhlStatsDashboardJob($season, 'highlights')];
        $perspectives = Perspective::query()->where('visibility', 'public_guest')->get();
        foreach ($seasons as $seasonId) {
            foreach (['leaders', 'skaters', 'goalies', 'teams'] as $view) {
                $jobs[] = new RefreshNhlStatsDashboardJob($seasonId, $view);
            }
            foreach ($perspectives as $perspective) {
                $gameTypes = str_starts_with((string) $perspective->slug, 'prospects') ? [2] : [1, 2, 3];
                foreach ($gameTypes as $gameType) {
                    $jobs[] = new RefreshNhlStatsDashboardJob($seasonId, 'table', (int) $perspective->id, $gameType);
                }
            }
        }
        if ($runId === null) {
            foreach ($jobs as $job) {
                dispatch($job);
            }
            return;
        }
        $existing = NhlGameImportRun::query()->find($runId);
        $batchId = data_get($existing?->payload, 'stats_cache.batch_id');
        $existingBatch = $batchId ? Bus::findBatch($batchId) : null;
        if ($existingBatch !== null && $existingBatch->totalJobs > 0) {
            return;
        }
        Bus::batch($jobs)->name('Public stats · '.$season)->onQueue('projections')->allowFailures()
            ->before(static function (Batch $batch) use ($runId): void {
                self::recordBatch($runId, $batch, 'building');
            })
            ->then(static function (Batch $batch) use ($runId): void {
                self::recordBatch($runId, $batch, 'ready');
            })
            ->catch(static function (Batch $batch, Throwable $exception) use ($runId): void {
                self::recordBatch($runId, $batch, 'failed', $exception->getMessage());
            })->dispatch();
    }

    /** Persist cache state without overwriting concurrent season-rollup progress. */
    public static function recordBatch(int $runId, Batch $batch, string $status, ?string $error = null): void
    {
        DB::transaction(static function () use ($runId, $batch, $status, $error): void {
            $run = NhlGameImportRun::query()->lockForUpdate()->find($runId);
            if ($run === null || $run->action !== NhlGameImportRun::ACTION_SEASON_SYNC) {
                return;
            }
            $payload = $run->payload ?? [];
            $existing = $payload['stats_cache']['batch_id'] ?? null;
            if ($existing !== null && $existing !== $batch->id && (Bus::findBatch($existing)?->totalJobs ?? 0) > 0) {
                return;
            }
            if ($status === 'ready' && $run->status === NhlGameImportRun::STATUS_FAILED) {
                return;
            }
            $payload['stats_cache'] = ['status' => $status, 'batch_id' => $batch->id, 'error' => $error];
            $runStatus = $status === 'failed' ? NhlGameImportRun::STATUS_FAILED : NhlGameImportRun::STATUS_RUNNING;
            if ($status === 'ready' && isset($payload['aggregation_completed_at'])) {
                $runStatus = NhlGameImportRun::STATUS_COMPLETED;
                $payload['completed_at'] = now()->toIso8601String();
            }
            $run->update(['status' => $runStatus, 'payload' => $payload, 'last_error' => $error]);
        }, 3);
        try {
            broadcast(new NhlGameImportStatusUpdated('season-sync-progress', $runId));
        } catch (Throwable $exception) {
            // UI polling still observes persisted progress if the broadcast transport is unavailable.
            report($exception);
        }
    }

    /** Surface coordinator failures instead of leaving admin preparation indefinitely queued. */
    public function failed(NhlSeasonStatsSynced|NhlStatsSourceUpdated $event, Throwable $exception): void
    {
        if ($event->runId === null) {
            return;
        }
        DB::transaction(static function () use ($event, $exception): void {
            $run = NhlGameImportRun::query()->lockForUpdate()->find($event->runId);
            if ($run !== null) {
                $payload = $run->payload ?? [];
                $payload['stats_cache'] = ['status' => 'failed', 'error' => $exception->getMessage()];
                $run->update(['status' => NhlGameImportRun::STATUS_FAILED, 'payload' => $payload,
                    'last_error' => $exception->getMessage()]);
            }
        }, 3);
        broadcast(new NhlGameImportStatusUpdated('season-sync-progress', $event->runId));
    }
}
