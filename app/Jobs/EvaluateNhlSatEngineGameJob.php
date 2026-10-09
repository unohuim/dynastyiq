<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\NhlSatEngineRun;
use App\Services\NhlSatEngineEvaluator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

/** One game and weight pair per job; identifiers only in queue payloads. */
class EvaluateNhlSatEngineGameJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;
    public int $timeout = 300;
    public bool $failOnTimeout = true;
    public int $backoff = 30;

    /** Carry only the run and bounded work coordinates. */
    public function __construct(
        public int $runId,
        public int $splitIndex,
        public int $gameIndex,
        public ?int $workGeneration = null,
    )
    {
        $this->onQueue('projections');
        $this->afterCommit = true;
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['nhl-sat-engine', 'engine-run:' . $this->runId];
    }

    /** Calculate outside the progress lock; persist each result exactly once. */
    public function handle(NhlSatEngineEvaluator $evaluator): void
    {
        $run = NhlSatEngineRun::query()->find($this->runId);
        if ($run === null || ! in_array($run->status, ['queued', 'running'], true)
            || ! $this->matchesGeneration($run)) {
            return;
        }
        $gameId = $run->definition['game_ids'][$this->gameIndex];
        $resultQuery = DB::table('nhl_sat_engine_results')->where('run_id', $run->id)
            ->where('split_index', $this->splitIndex)->where('nhl_game_id', $gameId);
        if ($resultQuery->exists()) {
            return;
        }
        $row = $evaluator->predict($run, $this->splitIndex, $gameId);
        $evaluator->assertModelUnchanged($run);
        DB::transaction(function () use ($row, $evaluator): void {
            $run = NhlSatEngineRun::query()->whereKey($this->runId)->lock('for no key update')->first();
            if ($run === null || ! in_array($run->status, ['queued', 'running'], true)
                || ! $this->matchesGeneration($run)) {
                return;
            }
            $exists = DB::table('nhl_sat_engine_results')->where('run_id', $run->id)
                ->where('split_index', $this->splitIndex)->where('nhl_game_id', $row['nhl_game_id'])->exists();
            if ($exists) {
                return;
            }
            DB::table('nhl_sat_engine_results')->insert($row);
            $run->predictions_completed++;
            $run->status = (int) $run->predictions_completed === (int) $run->prediction_count ? 'ranking' : 'running';
            if ($run->status === 'ranking' && ($run->definition['automatic_search']['strategy'] ?? null) === 'qualification_first_v1'
                && $run->definition['automatic_search']['stage'] === 0) {
                $definition = $run->definition;
                $definition['automatic_search']['stage'] = 1;
                $run->definition = $definition;
            }
            $run->save();
            $search = $run->definition['automatic_search'] ?? [];
            if (($search['work_scheduling'] ?? null) === 'stage_games_v1') {
                // All current-stage games were queued at stage start. Do not chain duplicates.
            } elseif (($search['work_scheduling'] ?? null) === 'game_lanes_v1') {
                $first = (int) $search['stage_first_split'];
                $offset = ($this->splitIndex - $first) * $run->game_count + $this->gameIndex + $search['lanes'];
                // A resumed legacy run can have completed coordinates later in this lane.
                while ($offset < $search['stage_split_count'] * $run->game_count
                    && DB::table('nhl_sat_engine_results')->where('run_id', $run->id)
                        ->where('split_index', $first + intdiv($offset, $run->game_count))
                        ->where('nhl_game_id', $run->definition['game_ids'][$offset % $run->game_count])->exists()) {
                    $offset += $search['lanes'];
                }
                if ($offset < $search['stage_split_count'] * $run->game_count) {
                    self::dispatch($run->id, $first + intdiv($offset, $run->game_count),
                        $offset % $run->game_count, $run->work_generation)->afterCommit();
                }
            } elseif ($this->gameIndex + 1 < $run->game_count) {
                self::dispatch($run->id, $this->splitIndex, $this->gameIndex + 1, $run->work_generation)->afterCommit();
            } elseif (in_array($run->definition['automatic_search']['strategy'] ?? null, ['coarse_to_fine_v1', 'qualification_first_v1'], true)) {
                $search = $run->definition['automatic_search'];
                $next = $this->splitIndex + $search['lanes'];
                if ($next < $search['stage_first_split'] + $search['stage_split_count']) {
                    self::dispatch($run->id, $next, 0, $run->work_generation)->afterCommit();
                }
            }
            if ($run->status === 'ranking') {
                $evaluator->dispatchRankingJobs($run);
            }
        });
    }

    /** Keep diagnostics bounded and never overwrite terminal runs. */
    public function failed(?Throwable $exception): void
    {
        $run = NhlSatEngineRun::query()->find($this->runId);
        if ($run === null || ! $run->active() || ! $this->matchesGeneration($run)) {
            return;
        }
        $run->update(['status' => 'failed', 'error' => 'Game evaluation failed (' . class_basename($exception ?? new \RuntimeException())
            . '). Review the failed job before starting a new run.', 'completed_at' => now()]);
    }

    /** Reject work queued before an administrator paused and resumed this run. */
    private function matchesGeneration(NhlSatEngineRun $run): bool
    {
        return $this->workGeneration === (int) $run->work_generation
            || ($this->workGeneration === null && (int) $run->work_generation === 1);
    }
}
