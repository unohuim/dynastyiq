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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Queues explicit rebuilds for games affected by a completed duplicate-PBP repair.
 */
class QueueDuplicatePbpAffectedRebuildsJob implements ShouldQueue, ShouldBeUnique
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
    public int $timeout = 300;

    public function __construct(public int $runId)
    {
        $this->afterCommit = true;
    }

    public function uniqueId(): string
    {
        return "duplicate-pbp-rebuild:{$this->runId}";
    }

    /**
     * @return array<int,string>
     */
    public function tags(): array
    {
        return [
            'nhl-duplicate-pbp-rebuild',
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
            $gameIds = $this->affectedGameIds($payload);

            if ($gameIds === []) {
                $payload['repair_stage'] = 'completed';
                $payload['rebuild_completed_at'] = now()->toIso8601String();

                $run->forceFill([
                    'status' => NhlGameImportRun::STATUS_COMPLETED,
                    'queued_jobs' => 0,
                    'payload' => $payload,
                    'last_error' => null,
                    'updated_at' => now(),
                ])->save();

                $this->broadcastStatus('duplicate-pbp-rebuild-completed');

                return;
            }

            foreach ($gameIds as $gameId) {
                RebuildNhlGameImportJob::dispatch($gameId, $this->runId);
            }

            $repair->markQueued($gameIds);

            $bounds = $this->boundsForGames($gameIds);
            $payload['repair_stage'] = 'rebuilding';
            $payload['rebuild_queued_at'] = now()->toIso8601String();
            $payload['queued_rebuild_game_count'] = count($gameIds);

            $run->forceFill([
                'status' => NhlGameImportRun::STATUS_RUNNING,
                'start_date' => $bounds['start_date'] ?? $run->start_date,
                'end_date' => $bounds['end_date'] ?? $run->end_date,
                'date_count' => $bounds['date_count'] ?? $run->date_count,
                'queued_jobs' => count($gameIds),
                'payload' => $payload,
                'last_error' => null,
                'updated_at' => now(),
            ])->save();

            $this->broadcastStatus('duplicate-pbp-rebuild-queued');
        } catch (Throwable $throwable) {
            $this->markFailed($run, $throwable);

            throw $throwable;
        }
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<int,int>
     */
    private function affectedGameIds(array $payload): array
    {
        $gameIds = $payload['affected_game_ids'] ?? [];

        if (! is_array($gameIds)) {
            return [];
        }

        return collect($gameIds)
            ->filter(fn (mixed $gameId): bool => is_numeric($gameId))
            ->map(fn (mixed $gameId): int => (int) $gameId)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param array<int,int> $gameIds
     * @return array{start_date?:string, end_date?:string, date_count?:int}
     */
    private function boundsForGames(array $gameIds): array
    {
        if ($gameIds === []) {
            return [];
        }

        $bounds = DB::table('nhl_games')
            ->whereIn('nhl_game_id', $gameIds)
            ->selectRaw('MIN(game_date) as earliest_date, MAX(game_date) as latest_date, COUNT(DISTINCT game_date) as date_count')
            ->first();

        return [
            'start_date' => (string) ($bounds->latest_date ?? now()->toDateString()),
            'end_date' => (string) ($bounds->earliest_date ?? now()->toDateString()),
            'date_count' => (int) ($bounds->date_count ?? count($gameIds)),
        ];
    }

    private function markFailed(NhlGameImportRun $run, Throwable $throwable): void
    {
        $payload = $run->payload ?? [];
        $payload['repair_stage'] = 'failed';
        $payload['rebuild_failed_at'] = now()->toIso8601String();
        $payload['repair_last_error'] = mb_substr($throwable->getMessage(), 0, 1000);

        $run->forceFill([
            'status' => NhlGameImportRun::STATUS_FAILED,
            'payload' => $payload,
            'last_error' => $payload['repair_last_error'],
            'updated_at' => now(),
        ])->save();

        Log::error('Duplicate NHL play-by-play affected-game rebuild queueing failed.', [
            'run_id' => $run->id,
            'error' => $throwable->getMessage(),
        ]);

        $this->broadcastStatus('duplicate-pbp-rebuild-failed');
    }

    private function broadcastStatus(string $status): void
    {
        try {
            broadcast(new NhlGameImportStatusUpdated($status, $this->runId));
        } catch (Throwable $throwable) {
            Log::warning('NHL duplicate PBP rebuild broadcast failed.', [
                'run_id' => $this->runId,
                'status' => $status,
                'error' => $throwable->getMessage(),
            ]);
        }
    }
}
