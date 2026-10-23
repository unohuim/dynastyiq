<?php

declare(strict_types=1);

namespace App\Jobs;

use Throwable;
use App\Events\NhlGameImportStatusUpdated;
use App\Models\NhlGameImportRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Services\SumNhlSeasonStats;

class SeasonSumJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var string */
    public string $seasonId;

    /** @var int|null */
    public ?int $runId;

    /** @var int */
    public int $tries = 5;

    /** @var array<int,int> */
    public array $backoff = [60, 120, 300, 600];

    /** @var int */
    public int $timeout = 600;

    public function __construct(string $seasonId, ?int $runId = null)
    {
        $this->seasonId = $seasonId;
        $this->runId = $runId;
    }

    public function handle(SumNhlSeasonStats $service): void
    {
        $run = $this->adminRun();

        try {
            $run?->update([
                'status' => NhlGameImportRun::STATUS_RUNNING,
                'last_error' => null,
                'payload' => [...($run->payload ?? []), 'stats_cache' => $run->payload['stats_cache'] ?? ['status' => 'queued']],
            ]);

            if ($run) {
                broadcast(new NhlGameImportStatusUpdated('season-sync-running', $run->id));
            }

            $rows = $service->sum($this->seasonId, $this->runId);

            $this->markCompleted($run, $rows);
        } catch (Throwable $e) {
            $this->markFailed($run, $e);
            report($e);
            $this->fail($e);
        }
    }

    /**
     * @return array<int,string>
     */
    public function tags(): array
    {
        return [
            'nhl-season-sum',
            "season-id:{$this->seasonId}",
        ];
    }

    /**
     * Resolve the optional admin run for UI progress.
     */
    private function adminRun(): ?NhlGameImportRun
    {
        if (! $this->runId) {
            return null;
        }

        return NhlGameImportRun::query()->find($this->runId);
    }

    /**
     * Record rollup completion; keep the run active until every prepared report is published.
     */
    private function markCompleted(?NhlGameImportRun $run, int $rows): void
    {
        if (! $run) {
            return;
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($run, $rows): void {
            $current = NhlGameImportRun::query()->lockForUpdate()->findOrFail($run->id);
            $payload = $current->payload ?? [];
            $payload['rows_upserted'] = $rows;
            $payload['aggregation_completed_at'] = now()->toIso8601String();
            $cacheStatus = $payload['stats_cache']['status'] ?? 'queued';
            $status = match ($cacheStatus) {
                'ready' => NhlGameImportRun::STATUS_COMPLETED,
                'failed' => NhlGameImportRun::STATUS_FAILED,
                default => NhlGameImportRun::STATUS_RUNNING,
            };
            if ($status === NhlGameImportRun::STATUS_COMPLETED) {
                $payload['completed_at'] = now()->toIso8601String();
            }
            $current->update(['status' => $status, 'payload' => $payload]);
        }, 3);

        broadcast(new NhlGameImportStatusUpdated('season-sync-progress', $run->id));
    }

    /**
     * Mark an admin-triggered season sync as failed.
     */
    private function markFailed(?NhlGameImportRun $run, Throwable $e): void
    {
        if (! $run) {
            return;
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($run, $e): void {
            $current = NhlGameImportRun::query()->lockForUpdate()->findOrFail($run->id);
            $payload = $current->payload ?? [];
            $payload['failed_at'] = now()->toIso8601String();
            $current->update([
                'status' => NhlGameImportRun::STATUS_FAILED,
                'payload' => $payload,
                'last_error' => $e->getMessage(),
            ]);
        }, 3);

        broadcast(new NhlGameImportStatusUpdated('season-sync-failed', $run->id));
    }
}
