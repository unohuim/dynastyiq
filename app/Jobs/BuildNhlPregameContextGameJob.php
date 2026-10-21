<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\NhlPregameContextDataUnavailable;
use App\Exceptions\NhlPregameContextRunStopped;
use App\Models\NhlPregameContextRun;
use App\Models\NhlPregameContextRunGame;
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

/** Produces all team/player snapshots for one claimed historical game. */
class BuildNhlPregameContextGameJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;
    public int $timeout = 120;

    public function __construct(public int $runId, public int $gameId)
    {
        $this->onQueue('projections');
        $this->afterCommit = true;
    }

    public function handle(NhlPregameContextBuilder $builder, NhlPregameContextOrchestrator $orchestrator): void
    {
        $run = NhlPregameContextRun::query()->find($this->runId);
        $work = NhlPregameContextRunGame::query()->where('run_id', $this->runId)->where('nhl_game_id', $this->gameId)->first();
        if ($run === null || $work === null || ! in_array($run->status, [
            NhlPregameContextRun::STATUS_RUNNING,
            NhlPregameContextRun::STATUS_PAUSING,
        ], true)
            || $work->state !== NhlPregameContextRunGame::STATE_PROCESSING) {
            return;
        }

        try {
            $counts = $builder->build($run, $this->gameId, fn (): bool => $this->stopWasRequested());
            $updated = NhlPregameContextRunGame::query()->whereKey($work->id)
                ->where('state', NhlPregameContextRunGame::STATE_PROCESSING)
                ->update([
                    'state' => NhlPregameContextRunGame::STATE_COMPLETE,
                    'team_context_count' => $counts['team_context_count'],
                    'player_context_count' => $counts['player_context_count'],
                    'completed_at' => now(),
                    'last_error' => null,
                ]);
            if ($updated === 0) {
                return;
            }
        } catch (NhlPregameContextRunStopped) {
            NhlPregameContextRunGame::query()->whereKey($work->id)
                ->where('state', NhlPregameContextRunGame::STATE_PROCESSING)
                ->update(['state' => NhlPregameContextRunGame::STATE_STOPPED, 'updated_at' => now()]);

            return;
        } catch (NhlPregameContextDataUnavailable $exception) {
            NhlPregameContextRunGame::query()->whereKey($work->id)
                ->where('state', NhlPregameContextRunGame::STATE_PROCESSING)->update([
                    'state' => NhlPregameContextRunGame::STATE_BLOCKED,
                    'last_error' => mb_strimwidth($exception->getMessage(), 0, 2000),
                ]);
        } catch (Throwable $exception) {
            NhlPregameContextRunGame::query()->whereKey($work->id)
                ->where('state', NhlPregameContextRunGame::STATE_PROCESSING)->update([
                    'state' => NhlPregameContextRunGame::STATE_FAILED,
                    'last_error' => mb_strimwidth($exception->getMessage(), 0, 2000),
                ]);
            NhlPregameContextRun::query()->whereKey($run->id)->whereIn('status', [
                NhlPregameContextRun::STATUS_RUNNING,
                NhlPregameContextRun::STATUS_PAUSING,
            ])->update([
                'status' => NhlPregameContextRun::STATUS_FAILED,
                'last_error' => mb_strimwidth($exception->getMessage(), 0, 2000),
            ]);
        }

        $orchestrator->refreshRunCounts($run->fresh());
        LoadNhlPregameContextRunJob::dispatch($this->runId);
    }

    /** Read the shared stop signal without relying on the game transaction snapshot. */
    private function stopWasRequested(): bool
    {
        try {
            if (Cache::store('redis')->get(NhlPregameContextRun::stopSignalKey($this->runId))) {
                return true;
            }
        } catch (Throwable) {
            // The durable status check remains available when Redis is unavailable.
        }

        return DB::table('nhl_pregame_context_runs')->where('id', $this->runId)
            ->where('status', NhlPregameContextRun::STATUS_STOPPED)->exists();
    }
}
