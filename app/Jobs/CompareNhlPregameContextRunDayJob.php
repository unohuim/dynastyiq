<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\NhlPregameContextRunStopped;
use App\Models\NhlPregameContextRun;
use App\Models\NhlPregameContextRunDay;
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

/** Attaches league comparisons once independent context and league stages are complete. */
class CompareNhlPregameContextRunDayJob implements ShouldQueue
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

    /** Attach ranks and deltas without rebuilding any context or prediction. */
    public function handle(NhlPregameLeagueResults $results): void
    {
        $run = NhlPregameContextRun::query()->find($this->runId);
        $day = NhlPregameContextRunDay::query()->whereKey($this->runDayId)->where('run_id', $this->runId)->first();
        if ($run === null || $day === null) {
            return;
        }
        if ($run->status === NhlPregameContextRun::STATUS_FAILED && $day->comparison_state === NhlPregameContextRunDay::STATE_PROCESSING) {
            $day->update(['comparison_state' => NhlPregameContextRunDay::STATE_FAILED, 'last_error' => $run->last_error]);

            return;
        }
        $independent = data_get($run->options, 'pipeline_version') === NhlPregameContextOrchestrator::THREE_STAGE_PIPELINE;
        if (! in_array($run->status, [
            NhlPregameContextRun::STATUS_RUNNING,
            NhlPregameContextRun::STATUS_PAUSING,
        ], true) || ! $independent || $day->context_state !== NhlPregameContextRunDay::STATE_COMPLETE
            || $day->league_state !== NhlPregameContextRunDay::STATE_COMPLETE
            || $day->comparison_state !== NhlPregameContextRunDay::STATE_PROCESSING) {
            return;
        }

        try {
            $day->update(['comparison_started_at' => now()]);
            $results->attachComparisons($run, $day, fn (): bool => $this->stopWasRequested());
        } catch (NhlPregameContextRunStopped) {
            return;
        } catch (Throwable $exception) {
            NhlPregameContextRunDay::query()->whereKey($day->id)->update([
                'comparison_state' => NhlPregameContextRunDay::STATE_FAILED,
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

        app(NhlPregameContextOrchestrator::class)->markRunDayComparisonsComplete($run->id, $day->id);
        LoadNhlPregameContextRunJob::dispatch($run->id);
    }

    /** Record final comparison failure, including timeout failures. */
    public function failed(Throwable $exception): void
    {
        NhlPregameContextRunDay::query()->whereKey($this->runDayId)
            ->where('comparison_state', NhlPregameContextRunDay::STATE_PROCESSING)->update([
            'comparison_state' => NhlPregameContextRunDay::STATE_FAILED,
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
