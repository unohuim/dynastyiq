<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\NhlGameImportRun;
use App\Services\NhlScheduleRefresh;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Refresh one NHL schedule date for prediction workflows.
 */
class RefreshNhlScheduleDateJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Batchable;
    use Queueable;
    use SerializesModels;

    /** @var int */
    public int $timeout = 30;

    /** @var int */
    public int $tries = 1;

    public function __construct(
        public Carbon $date,
        public int $runId
    ) {
        $this->date = $this->date->copy()->startOfDay();
    }

    public function handle(NhlScheduleRefresh $refresh): void
    {
        $this->markRunning();

        try {
            $result = $refresh->refreshDate($this->date);
            $this->recordResult($result);
        } catch (Throwable $throwable) {
            $this->recordFailure($throwable);
        }
    }

    public function failed(Throwable $throwable): void
    {
        $this->recordFailure($throwable);
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [
            'nhl-schedule-refresh',
            'run-id:' . $this->runId,
            'date:' . $this->date->toDateString(),
        ];
    }

    private function markRunning(): void
    {
        NhlGameImportRun::query()
            ->whereKey($this->runId)
            ->where('status', NhlGameImportRun::STATUS_QUEUED)
            ->update([
                'status' => NhlGameImportRun::STATUS_RUNNING,
                'updated_at' => now(),
            ]);
    }

    /**
     * @param array{date:string,fetched:int,deleted:int,inserted:int,upserted:int,mode:string,replaceable:bool,skipped:bool} $result
     */
    private function recordResult(array $result): void
    {
        $this->updateSummary(function (array $summary) use ($result): array {
            $summary['dates_processed'] = ((int) ($summary['dates_processed'] ?? 0)) + 1;
            $summary['fetched'] = ((int) ($summary['fetched'] ?? 0)) + $result['fetched'];
            $summary['deleted'] = ((int) ($summary['deleted'] ?? 0)) + $result['deleted'];
            $summary['inserted'] = ((int) ($summary['inserted'] ?? 0)) + $result['inserted'];
            $summary['upserted'] = ((int) ($summary['upserted'] ?? 0)) + $result['upserted'];

            if ($result['mode'] === 'replace') {
                $summary['replaced_dates'] = ((int) ($summary['replaced_dates'] ?? 0)) + 1;
            } else {
                $summary['upserted_dates'] = ((int) ($summary['upserted_dates'] ?? 0)) + 1;
            }

            return $summary;
        });
    }

    private function recordFailure(Throwable $throwable): void
    {
        $this->updateSummary(function (array $summary) use ($throwable): array {
            $failedDates = is_array($summary['failed_dates'] ?? null) ? $summary['failed_dates'] : [];
            $alreadyRecorded = collect($failedDates)
                ->contains(fn (array $failure): bool => ($failure['date'] ?? null) === $this->date->toDateString());

            if (! $alreadyRecorded) {
                $summary['dates_processed'] = ((int) ($summary['dates_processed'] ?? 0)) + 1;
                $failedDates[] = [
                    'date' => $this->date->toDateString(),
                    'error' => $throwable->getMessage(),
                ];
            }

            $summary['failed_dates'] = $failedDates;

            return $summary;
        });
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $mutate
     */
    private function updateSummary(callable $mutate): void
    {
        DB::transaction(function () use ($mutate): void {
            $run = NhlGameImportRun::query()
                ->whereKey($this->runId)
                ->lockForUpdate()
                ->firstOrFail();
            $payload = $run->payload ?? [];
            $summary = is_array($payload['schedule_refresh'] ?? null) ? $payload['schedule_refresh'] : [];

            $summary = $mutate($summary);
            $total = max(1, (int) ($summary['dates'] ?? $run->date_count));
            $processed = min($total, (int) ($summary['dates_processed'] ?? 0));
            $failedDates = is_array($summary['failed_dates'] ?? null) ? $summary['failed_dates'] : [];
            $updates = [
                'payload' => array_merge($payload, [
                    'schedule_refresh' => $summary,
                ]),
                'updated_at' => now(),
            ];

            if ($processed >= $total) {
                $summary['completed_at'] = now()->toIso8601String();
                $updates['payload'] = array_merge($payload, [
                    'schedule_refresh' => $summary,
                ]);
                $updates['status'] = $failedDates === []
                    ? NhlGameImportRun::STATUS_COMPLETED
                    : NhlGameImportRun::STATUS_FAILED;
                $updates['last_error'] = $failedDates === []
                    ? null
                    : sprintf('%d schedule dates failed to refresh.', count($failedDates));
            }

            $run->forceFill($updates)->save();
        });
    }
}
