<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Events\NhlGameImportStatusUpdated;
use App\Models\NhlGameImportRun;
use App\Services\NhlDuplicatePlayByPlayRepair;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Scans for duplicate NHL play-by-play rows and prepares an admin repair card.
 */
class ScanDuplicateNhlPlayByPlayRepairJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @var int
     */
    public int $tries = 1;

    /**
     * @var int
     */
    public int $timeout = 120;

    public function __construct(public int $runId)
    {
        $this->afterCommit = true;
    }

    public function uniqueId(): string
    {
        return 'duplicate-pbp-scan';
    }

    /**
     * @return array<int,string>
     */
    public function tags(): array
    {
        return [
            'nhl-duplicate-pbp-scan',
            "run-id:{$this->runId}",
        ];
    }

    public function handle(NhlDuplicatePlayByPlayRepair $repair): void
    {
        $run = NhlGameImportRun::query()->find($this->runId);

        if (! $run) {
            return;
        }

        try {
            $payload = $run->payload ?? [];
            $scan = $repair->scan();
            $ledger = $repair->ledgerSummary();
            $readyGameCount = max($scan['game_count'], $ledger['unqueued_games']);
            $readyDuplicateRows = max($scan['duplicate_rows'], $ledger['duplicate_rows']);

            $payload['repair_stage'] = 'ready';
            $payload['scan_completed_at'] = now()->toIso8601String();
            $payload['live_duplicate_game_count'] = $scan['game_count'];
            $payload['live_duplicate_row_count'] = $scan['duplicate_rows'];
            $payload['ledger_game_count'] = $ledger['game_count'];
            $payload['ledger_duplicate_row_count'] = $ledger['duplicate_rows'];
            $payload['unqueued_rebuild_game_count'] = $ledger['unqueued_games'];
            $payload['queued_rebuild_game_count'] = $ledger['queued_games'];
            $payload['repair_game_count'] = $readyGameCount;
            $payload['repair_duplicate_row_count'] = $readyDuplicateRows;

            $run->forceFill([
                'status' => NhlGameImportRun::STATUS_COMPLETED,
                'queued_jobs' => 1,
                'payload' => $payload,
                'last_error' => null,
                'updated_at' => now(),
            ])->save();

            $this->broadcastStatus('duplicate-pbp-scan-completed');
        } catch (Throwable $throwable) {
            $this->markFailed($run, $throwable);

            throw $throwable;
        }
    }

    private function markFailed(NhlGameImportRun $run, Throwable $throwable): void
    {
        $payload = $run->payload ?? [];
        $payload['repair_stage'] = 'failed';
        $payload['scan_failed_at'] = now()->toIso8601String();
        $payload['repair_last_error'] = mb_substr($throwable->getMessage(), 0, 1000);

        $run->forceFill([
            'status' => NhlGameImportRun::STATUS_FAILED,
            'payload' => $payload,
            'last_error' => $payload['repair_last_error'],
            'updated_at' => now(),
        ])->save();

        Log::error('Duplicate NHL play-by-play scan failed.', [
            'run_id' => $run->id,
            'error' => $throwable->getMessage(),
        ]);

        $this->broadcastStatus('duplicate-pbp-scan-failed');
    }

    private function broadcastStatus(string $status): void
    {
        try {
            broadcast(new NhlGameImportStatusUpdated($status, $this->runId));
        } catch (Throwable $throwable) {
            Log::warning('NHL duplicate PBP repair broadcast failed.', [
                'run_id' => $this->runId,
                'status' => $status,
                'error' => $throwable->getMessage(),
            ]);
        }
    }
}
