<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\NhlPregameContextRunStopped;
use App\Models\NhlPregameContextRun;
use App\Models\NhlPregameContextRunDay;
use App\Models\NhlPregameContextRunGame;
use App\Services\NhlPregameContextBuilder;
use App\Services\NhlPregameContextOrchestrator;
use App\Services\NhlPregameLeagueResults;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Builds a bounded set of player and team snapshots for one game day. */
class BuildNhlPregameContextDayJob implements ShouldQueue
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

    /** Run the day batch and persist per-game terminal outcomes. */
    public function handle(NhlPregameContextBuilder $builder): void
    {
        $run = NhlPregameContextRun::query()->find($this->runId);
        $day = NhlPregameContextRunDay::query()->whereKey($this->runDayId)->where('run_id', $this->runId)->first();
        if ($run === null || $day === null || data_get($run->options, 'pipeline_version') === NhlPregameContextOrchestrator::CHECKPOINT_PIPELINE) {
            return;
        }
        if ($run->status === NhlPregameContextRun::STATUS_FAILED && $day->context_state === NhlPregameContextRunDay::STATE_PROCESSING) {
            NhlPregameContextRunGame::query()->where('run_id', $run->id)->whereDate('game_date', $day->game_date)
                ->where('state', NhlPregameContextRunGame::STATE_PROCESSING)->update([
                    'state' => NhlPregameContextRunGame::STATE_FAILED,
                    'last_error' => $run->last_error,
                    'updated_at' => now(),
                ]);
            $day->update(['context_state' => NhlPregameContextRunDay::STATE_FAILED, 'last_error' => $run->last_error]);

            return;
        }
        if (! in_array($run->status, [
            NhlPregameContextRun::STATUS_RUNNING,
            NhlPregameContextRun::STATUS_PAUSING,
        ], true) || $day->context_state !== NhlPregameContextRunDay::STATE_PROCESSING) {
            return;
        }

        $leagueFirst = data_get($run->options, 'pipeline_version') === NhlPregameContextOrchestrator::RESULTS_PIPELINE;
        if ($leagueFirst && $day->league_state !== NhlPregameContextRunDay::STATE_COMPLETE) {
            return;
        }
        if ($leagueFirst) {
            $day->update(['context_started_at' => now()]);
        }
        $gameRows = NhlPregameContextRunGame::query()->where('run_id', $run->id)
            ->whereDate('game_date', $day->game_date)
            ->where('state', NhlPregameContextRunGame::STATE_PROCESSING)
            ->orderBy('sequence')->get();

        try {
            $results = $gameRows->isEmpty() ? ['games' => [], 'blocked_games' => 0] : $builder->buildDay(
                $run,
                $gameRows->pluck('nhl_game_id')->map(fn (mixed $id): int => (int) $id)->all(),
                fn (): bool => $this->stopWasRequested(),
            );
            if ($leagueFirst) {
                app(NhlPregameLeagueResults::class)->attachComparisons(
                    $run, $day, fn (): bool => $this->stopWasRequested(),
                );
            }
            if ($this->stopWasRequested()) {
                throw new NhlPregameContextRunStopped();
            }
        } catch (NhlPregameContextRunStopped) {
            NhlPregameContextRunDay::query()->whereKey($day->id)->update([
                'context_state' => NhlPregameContextRunDay::STATE_STOPPED,
                'updated_at' => now(),
            ]);

            return;
        } catch (Throwable $exception) {
            NhlPregameContextRunGame::query()->where('run_id', $run->id)->whereDate('game_date', $day->game_date)
                ->where('state', NhlPregameContextRunGame::STATE_PROCESSING)
                ->update([
                    'state' => NhlPregameContextRunGame::STATE_FAILED,
                    'last_error' => mb_strimwidth($exception->getMessage(), 0, 2000),
                    'updated_at' => now(),
                ]);
            NhlPregameContextRunDay::query()->whereKey($day->id)->update([
                'context_state' => NhlPregameContextRunDay::STATE_FAILED,
                'failed_games' => $gameRows->count(),
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
            app(NhlPregameContextOrchestrator::class)->refreshRunCounts($run->fresh());

            throw $exception;
        }

        foreach ($gameRows as $gameRow) {
            $gameResult = $results['games'][$gameRow->nhl_game_id] ?? [];
            $blocked = (bool) ($gameResult['blocked'] ?? false);
            $gameRow->update($blocked ? [
                'state' => NhlPregameContextRunGame::STATE_BLOCKED,
                'last_error' => 'The game is missing completed schedule or player summary context.',
            ] : [
                'state' => NhlPregameContextRunGame::STATE_COMPLETE,
                'team_context_count' => $gameResult['team_context_count'] ?? 0,
                'player_context_count' => $gameResult['player_context_count'] ?? 0,
                'completed_at' => now(),
                'last_error' => null,
            ]);
        }

        NhlPregameContextRunDay::query()->whereKey($day->id)->update([
            'context_state' => NhlPregameContextRunDay::STATE_COMPLETE,
            'completed_games' => NhlPregameContextRunGame::query()->where('run_id', $run->id)
                ->whereDate('game_date', $day->game_date)->where('state', NhlPregameContextRunGame::STATE_COMPLETE)->count(),
            'blocked_games' => NhlPregameContextRunGame::query()->where('run_id', $run->id)
                ->whereDate('game_date', $day->game_date)->where('state', NhlPregameContextRunGame::STATE_BLOCKED)->count(),
            'context_completed_at' => now(),
            'last_error' => null,
            'updated_at' => now(),
        ]);

        app(NhlPregameContextOrchestrator::class)->refreshRunCounts($run->fresh());
        LoadNhlPregameContextRunJob::dispatch($run->id);
    }

    /** Mark the day and its unfinished game rows failed after final queue failure. */
    public function failed(Throwable $exception): void
    {
        if (data_get(NhlPregameContextRun::find($this->runId)?->options, 'pipeline_version') === NhlPregameContextOrchestrator::CHECKPOINT_PIPELINE) {
            return;
        }
        $day = NhlPregameContextRunDay::query()->whereKey($this->runDayId)->where('run_id', $this->runId)->first();
        if ($day !== null) {
            NhlPregameContextRunGame::query()->where('run_id', $this->runId)->whereDate('game_date', $day->game_date)
                ->where('state', NhlPregameContextRunGame::STATE_PROCESSING)
                ->update([
                    'state' => NhlPregameContextRunGame::STATE_FAILED,
                    'last_error' => mb_strimwidth($exception->getMessage(), 0, 2000),
                    'updated_at' => now(),
                ]);
        }
        NhlPregameContextRunDay::query()->whereKey($this->runDayId)
            ->where('context_state', NhlPregameContextRunDay::STATE_PROCESSING)->update([
            'context_state' => NhlPregameContextRunDay::STATE_FAILED,
            'failed_games' => $day?->game_count ?? 0,
            'last_error' => mb_strimwidth($exception->getMessage(), 0, 2000),
            'updated_at' => now(),
        ]);
        $run = NhlPregameContextRun::query()->find($this->runId);
        if ($run !== null) {
            NhlPregameContextRun::query()->whereKey($run->id)
                ->whereIn('status', [NhlPregameContextRun::STATUS_RUNNING, NhlPregameContextRun::STATUS_PAUSING])
                ->update(['status' => NhlPregameContextRun::STATUS_FAILED,
                    'last_error' => mb_strimwidth($exception->getMessage(), 0, 2000), 'updated_at' => now()]);
            app(NhlPregameContextOrchestrator::class)->refreshRunCounts($run);
        }
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
