<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\NhlSatEngineRun;
use App\Services\NhlSatEngineEvaluator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Rank bounded weight/gap work units without loading game or model payloads. */
class RankNhlSatEngineCandidatesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;
    public int $timeout = 120;
    public bool $failOnTimeout = true;

    /** Resume ranking after a stable candidate ID. */
    public function __construct(public int $runId, public int $afterId, public ?int $workGeneration = null)
    {
        $this->afterCommit = true;
    }

    /** Rank a bounded page after all game predictions have been persisted. */
    public function handle(NhlSatEngineEvaluator $evaluator): void
    {
        $run = NhlSatEngineRun::query()->find($this->runId);
        if ($run === null || $run->status !== 'ranking' || ! $this->matchesGeneration($run)) {
            return;
        }
        $evaluator->assertModelUnchanged($run);
        if ((int) $run->predictions_completed !== (int) $run->prediction_count
            || DB::table('nhl_sat_engine_results')->where('run_id', $run->id)->count() !== (int) $run->prediction_count) {
            throw new \RuntimeException('Cannot rank an incomplete game evaluation.');
        }
        if (! DB::table('nhl_sat_engine_results')->where('run_id', $run->id)->where('status', 'complete')->exists()) {
            DB::transaction(function (): void {
                $run = NhlSatEngineRun::query()->whereKey($this->runId)->lock('for no key update')->first();
                if ($run === null || $run->status !== 'ranking' || ! $this->matchesGeneration($run)) {
                    return;
                }
                $run->update([
                    'status' => 'failed',
                    'error' => 'No eligible games: every evaluated game was excluded. Review game exclusion reasons, correct the missing inputs, and start a new run.',
                    'completed_at' => now(),
                ]);
            });

            return;
        }
        $automatic = ($run->definition['confidence_search'] ?? null) === 'automatic';
        $rows = DB::table('nhl_sat_engine_candidates')->where('run_id', $run->id)->whereNull('metrics')
            ->where('id', '>', $this->afterId)->orderBy('id')->limit($automatic ? 1 : 10)->get();
        $computed = [];
        foreach ($rows as $candidate) {
            $settings = json_decode($candidate->settings, true, 512, JSON_THROW_ON_ERROR);
            if (($run->definition['automatic_search']['strategy'] ?? null) === 'coarse_to_fine_v1') {
                $computed[$candidate->id] = $evaluator->discoverQualifications($run->id, $candidate->split_index, $settings, $run->game_count);
            } else {
                $computed[$candidate->id] = $automatic
                    ? $evaluator->discoverConfidence($run->id, $candidate->split_index, $settings, $run->game_count)
                    : [['settings' => $settings, 'metrics' => $evaluator->metrics($run->id, $candidate->split_index, $settings, $run->game_count)]];
            }
        }
        $evaluator->assertModelUnchanged($run);
        DB::transaction(function () use ($computed, $rows, $evaluator): void {
            $run = NhlSatEngineRun::query()->whereKey($this->runId)->lock('for no key update')->first();
            if ($run === null || $run->status !== 'ranking' || ! $this->matchesGeneration($run)) {
                return;
            }
            $changed = 0;
            foreach ($computed as $id => $variants) {
                $attributes = function (array $variant) use ($run): array {
                    $metrics = $variant['metrics'];

                    return [
                        'settings' => json_encode($variant['settings'], JSON_THROW_ON_ERROR),
                        'metrics' => json_encode($metrics, JSON_THROW_ON_ERROR),
                        'win_pct' => $metrics['win_pct'], 'coverage_pct' => $metrics['coverage_pct'],
                        'meets_targets' => $metrics['picks'] > 0 && $metrics['win_pct'] >= $run->definition['desired_win_pct']
                            && $metrics['coverage_pct'] >= $run->definition['min_coverage_pct'],
                        'updated_at' => now(),
                    ];
                };
                $updated = DB::table('nhl_sat_engine_candidates')->where('id', $id)->whereNull('metrics')
                    ->update($attributes($variants[0]));
                if ($updated === 0) {
                    continue;
                }
                $changed++;
                $split = $rows->firstWhere('id', $id)->split_index;
                foreach (array_chunk(array_slice($variants, 1), 100) as $chunk) {
                    DB::table('nhl_sat_engine_candidates')->insert(array_map(fn (array $variant): array => [
                        ...$attributes($variant), 'run_id' => $run->id, 'split_index' => $split, 'created_at' => now(),
                    ], $chunk));
                }
            }
            if ($changed === 0) {
                return;
            }
            // Count each search work unit once, independent of its retained qualification ranges.
            $run->candidates_completed += $changed;
            if ((int) $run->candidates_completed === (int) $run->candidate_count) {
                if ($evaluator->advanceDiscovery($run)) {
                    return;
                }
                $run->status = 'complete';
                $run->completed_at = now();
            }
            $run->save();
            if ($run->status === 'ranking') {
                self::dispatch($run->id, (int) $rows->last()->id, $run->work_generation)->afterCommit();
            }
        });
    }

    /** Preserve failed-run evidence without certifying partial results. */
    public function failed(?Throwable $exception): void
    {
        $run = NhlSatEngineRun::query()->find($this->runId);
        if ($run === null || $run->status !== 'ranking' || ! $this->matchesGeneration($run)) {
            return;
        }
        $run->update(['status' => 'failed', 'error' => 'Candidate ranking failed. Review the failed job before starting a new run.', 'completed_at' => now()]);
    }

    /** Reject ranking work queued before an administrator paused and resumed this run. */
    private function matchesGeneration(NhlSatEngineRun $run): bool
    {
        return $this->workGeneration === (int) $run->work_generation
            || ($this->workGeneration === null && (int) $run->work_generation === 1);
    }
}
