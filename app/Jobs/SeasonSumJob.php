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
            $run?->update(['status' => NhlGameImportRun::STATUS_RUNNING]);

            if ($run) {
                broadcast(new NhlGameImportStatusUpdated('season-sync-running', $run->id));
            }

            $rows = $service->sum($this->seasonId);

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
     * Mark an admin-triggered season sync as completed.
     */
    private function markCompleted(?NhlGameImportRun $run, int $rows): void
    {
        if (! $run) {
            return;
        }

        $payload = $run->payload ?? [];
        $payload['rows_upserted'] = $rows;
        $payload['completed_at'] = now()->toIso8601String();

        $run->update([
            'status' => NhlGameImportRun::STATUS_COMPLETED,
            'payload' => $payload,
            'last_error' => null,
        ]);

        broadcast(new NhlGameImportStatusUpdated('season-sync-completed', $run->id));
    }

    /**
     * Mark an admin-triggered season sync as failed.
     */
    private function markFailed(?NhlGameImportRun $run, Throwable $e): void
    {
        if (! $run) {
            return;
        }

        $payload = $run->payload ?? [];
        $payload['failed_at'] = now()->toIso8601String();

        $run->update([
            'status' => NhlGameImportRun::STATUS_FAILED,
            'payload' => $payload,
            'last_error' => $e->getMessage(),
        ]);

        broadcast(new NhlGameImportStatusUpdated('season-sync-failed', $run->id));
    }
}
