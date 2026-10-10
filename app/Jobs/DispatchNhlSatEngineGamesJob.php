<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\NhlSatEngineRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

/** Submit a resumable page of discovery games without holding a browser request open. */
class DispatchNhlSatEngineGamesJob implements ShouldQueue
{
    use Queueable;

    private const PAGE_SIZE = 100;

    public int $tries = 3;
    public int $timeout = 120;
    public int $backoff = 10;

    /** Carry only the run identity and dispatch generation. */
    public function __construct(public int $runId, public int $workGeneration, public string $dispatchToken)
    {
        $this->onQueue('projections');
        $this->afterCommit = true;
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['nhl-sat-engine', 'engine-run:' . $this->runId];
    }

    /** Advance the durable cursor only after this page has reached the queue. */
    public function handle(): void
    {
        $connection = $this->job?->getConnectionName() ?? $this->connection ?? config('queue.default');
        DB::transaction(function () use ($connection): void {
            $run = NhlSatEngineRun::query()->whereKey($this->runId)->lock('for no key update')->first();
            if (! $this->current($run) || ($run->definition['evaluation_dispatch']['complete'] ?? false)) {
                return;
            }

            $definition = $run->definition;
            $search = $definition['automatic_search'];
            $gameIds = $definition['game_ids'];
            $gameCount = count($gameIds);
            $total = $gameCount * (int) $search['stage_split_count'];
            $offset = (int) $definition['evaluation_dispatch']['next_offset'];
            $end = min($offset + self::PAGE_SIZE, $total);
            $completedBySplit = [];

            for ($position = $offset; $position < $end; $position++) {
                $split = (int) $search['stage_first_split'] + intdiv($position, $gameCount);
                $gameIndex = $position % $gameCount;
                $completedBySplit[$split] ??= DB::table('nhl_sat_engine_results')
                    ->where('run_id', $run->id)->where('split_index', $split)
                    ->pluck('nhl_game_id')->flip();
                if ($completedBySplit[$split]->has($gameIds[$gameIndex])) {
                    continue;
                }

                // Publish before checkpointing. A failed push rolls back the cursor;
                // retries may duplicate deliveries, which evaluation results already fence.
                $job = (new EvaluateNhlSatEngineGameJob($run->id, $split, $gameIndex, $this->workGeneration))
                    ->onConnection($connection)->beforeCommit();
                Queue::connection($connection)->push($job, '', 'projections');
            }

            $definition['evaluation_dispatch']['next_offset'] = $end;
            $definition['evaluation_dispatch']['complete'] = $end >= $total;
            $run->definition = $definition;
            $run->save();
            if ($end < $total) {
                // If this enqueue fails after commit, retry continues from the saved cursor.
                self::dispatch($run->id, $this->workGeneration, $this->dispatchToken)
                    ->onConnection($connection)->afterCommit();
            }
        });
    }

    /** Pause with actionable diagnostics if dispatch exhausts its retries. */
    public function failed(?Throwable $exception): void
    {
        DB::transaction(function (): void {
            $run = NhlSatEngineRun::query()->whereKey($this->runId)->lock('for no key update')->first();
            if (! $this->current($run) || ($run->definition['evaluation_dispatch']['complete'] ?? false)) {
                return;
            }
            $run->update([
                'paused_status' => $run->status,
                'status' => 'paused',
                'work_generation' => $run->work_generation + 1,
                'error' => 'Discovery job dispatch failed. Review the failed dispatcher job, then Resume to queue missing games. Saved results are retained.',
            ]);
        });
    }

    /** Ignore paused, terminal, replaced-stage, and earlier-generation deliveries. */
    private function current(?NhlSatEngineRun $run): bool
    {
        return $run !== null && in_array($run->status, ['queued', 'running'], true)
            && (int) $run->work_generation === $this->workGeneration
            && ($run->definition['evaluation_dispatch']['token'] ?? null) === $this->dispatchToken;
    }
}
