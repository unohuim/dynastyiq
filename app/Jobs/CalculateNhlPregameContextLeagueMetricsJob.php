<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\NhlPregameContextRunStopped;
use App\Models\NhlPregameContextRun;
use App\Services\NhlPregameContextBuilder;
use App\Services\NhlPregameContextOrchestrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Writes league comparisons after every snapshot for one game day is persisted. */
class CalculateNhlPregameContextLeagueMetricsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;
    public int $timeout = 900;

    public function __construct(public int $runId, public string $gameDate)
    {
        $this->onQueue('projections');
        $this->afterCommit = true;
    }

    public function handle(
        NhlPregameContextBuilder $builder,
        NhlPregameContextOrchestrator $orchestrator
    ): void {
        $run = NhlPregameContextRun::query()->find($this->runId);
        if ($run === null || ! in_array($run->status, [
            NhlPregameContextRun::STATUS_RUNNING,
            NhlPregameContextRun::STATUS_PAUSING,
        ], true)) {
            return;
        }

        try {
            $builder->buildLeagueBenchmarksForDate(
                $run->model_run_id,
                $this->gameDate,
                $run->id,
                fn (): bool => $this->stopWasRequested(),
            );
        } catch (NhlPregameContextRunStopped) {
            return;
        }
        $orchestrator->markLeagueBenchmarksComplete($this->runId, $this->gameDate);
        LoadNhlPregameContextRunJob::dispatch($this->runId);
    }

    public function failed(Throwable $exception): void
    {
        NhlPregameContextRun::query()->whereKey($this->runId)->whereIn('status', [
            NhlPregameContextRun::STATUS_RUNNING,
            NhlPregameContextRun::STATUS_PAUSING,
        ])->update([
            'status' => NhlPregameContextRun::STATUS_FAILED,
            'last_error' => mb_strimwidth($exception->getMessage(), 0, 2000),
            'updated_at' => now(),
        ]);
    }

    /** Read the shared stop signal without relying on a transaction snapshot. */
    private function stopWasRequested(): bool
    {
        try {
            if (Cache::store('redis')->get(NhlPregameContextRun::stopSignalKey($this->runId))) {
                return true;
            }
        } catch (\Throwable) {
            // The durable status check remains available when Redis is unavailable.
        }

        return DB::table('nhl_pregame_context_runs')->where('id', $this->runId)
            ->where('status', NhlPregameContextRun::STATUS_STOPPED)->exists();
    }
}
