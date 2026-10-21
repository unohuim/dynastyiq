<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\NhlPregameContextRunStopped;
use App\Models\NhlPregameContextRun;
use App\Models\NhlPregameContextRunDay;
use App\Services\NhlPregameContextBuilder;
use App\Services\NhlPregameLeagueResults;
use App\Services\NhlPregameContextOrchestrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Calculates raw-result league averages before the date's context work. */
class CalculateNhlPregameContextRunDayLeagueAveragesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public bool $failOnTimeout = true;
    public int $tries = 2;
    public int $timeout = 1800;

    /**
     * @param int $runId Owning context run.
     * @param int $runDayId Date work row.
     */
    public function __construct(public int $runId, public int $runDayId)
    {
        $this->onQueue('projections');
        $this->afterCommit = true;
    }

    /** Persist date-scoped team and player league averages and ranks. */
    public function handle(NhlPregameContextBuilder $builder, NhlPregameLeagueResults $results): void
    {
        $run = NhlPregameContextRun::query()->find($this->runId);
        $day = NhlPregameContextRunDay::query()->whereKey($this->runDayId)->where('run_id', $this->runId)->first();
        if ($run === null || $day === null) {
            return;
        }
        if ($run->status === NhlPregameContextRun::STATUS_FAILED && $day->league_state === NhlPregameContextRunDay::STATE_PROCESSING) {
            $day->update(['league_state' => NhlPregameContextRunDay::STATE_FAILED, 'last_error' => $run->last_error]);

            return;
        }
        $independent = in_array(data_get($run->options, 'pipeline_version'), [
            NhlPregameContextOrchestrator::THREE_STAGE_PIPELINE, NhlPregameContextOrchestrator::RESULTS_PIPELINE,
        ], true);
        if (! in_array($run->status, [
            NhlPregameContextRun::STATUS_RUNNING,
            NhlPregameContextRun::STATUS_PAUSING,
        ], true) || (! $independent && $day->context_state !== NhlPregameContextRunDay::STATE_COMPLETE)
            || $day->league_state !== NhlPregameContextRunDay::STATE_PROCESSING) {
            return;
        }

        try {
            if ($independent) {
                $day->update(['league_started_at' => now()]);
                $results->buildAverages($run, $day, fn (): bool => $this->stopWasRequested());
            } else {
                $builder->buildLeagueBenchmarksForDate(
                    $run->model_run_id,
                    (string) $day->game_date->format('Y-m-d'),
                    $run->id,
                    fn (): bool => $this->stopWasRequested(),
                    $day->id,
                );
            }
        } catch (NhlPregameContextRunStopped) {
            return;
        } catch (Throwable $exception) {
            NhlPregameContextRunDay::query()->whereKey($day->id)->update([
                'league_state' => NhlPregameContextRunDay::STATE_FAILED,
                'last_error' => mb_strimwidth($exception->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            NhlPregameContextRun::query()->whereKey($run->id)->whereIn('status', [
                NhlPregameContextRun::STATUS_RUNNING,
                NhlPregameContextRun::STATUS_PAUSING,
            ])->update([
                'status' => NhlPregameContextRun::STATUS_FAILED,
                'last_error' => mb_strimwidth($exception->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);

            throw $exception;
        }

        app(NhlPregameContextOrchestrator::class)->markRunDayLeagueComplete($run->id, $day->id);
        LoadNhlPregameContextRunJob::dispatch($run->id);
    }

    /** Mark league work failed after its final queue attempt. */
    public function failed(Throwable $exception): void
    {
        NhlPregameContextRunDay::query()->whereKey($this->runDayId)
            ->where('league_state', NhlPregameContextRunDay::STATE_PROCESSING)->update([
            'league_state' => NhlPregameContextRunDay::STATE_FAILED,
            'last_error' => mb_strimwidth($exception->getMessage(), 0, 2000),
            'updated_at' => now(),
        ]);
        NhlPregameContextRun::query()->whereKey($this->runId)
            ->whereIn('status', [NhlPregameContextRun::STATUS_RUNNING, NhlPregameContextRun::STATUS_PAUSING])
            ->update(['status' => NhlPregameContextRun::STATUS_FAILED,
                'last_error' => mb_strimwidth($exception->getMessage(), 0, 2000), 'updated_at' => now()]);
    }

    /** Check the run-scoped stop signal without terminating the worker process. */
    private function stopWasRequested(): bool
    {
        try {
            if (Cache::store('redis')->get(NhlPregameContextRun::stopSignalKey($this->runId))) {
                return true;
            }
        } catch (Throwable) {
            // The durable status check remains available if Redis is unavailable.
        }

        return DB::table('nhl_pregame_context_runs')->where('id', $this->runId)
            ->where('status', NhlPregameContextRun::STATUS_STOPPED)->exists();
    }
}
