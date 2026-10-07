<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\NhlPregameContextRun;
use App\Models\NhlPregameContextRunGame;
use App\Services\NhlPregameContextBuilder;
use App\Services\NhlPregameContextOrchestrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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
        $this->afterCommit = true;
    }

    public function handle(NhlPregameContextBuilder $builder, NhlPregameContextOrchestrator $orchestrator): void
    {
        $run = NhlPregameContextRun::query()->find($this->runId);
        $work = NhlPregameContextRunGame::query()->where('run_id', $this->runId)->where('nhl_game_id', $this->gameId)->first();
        if ($run === null || $work === null || $run->status !== NhlPregameContextRun::STATUS_RUNNING
            || $work->state !== NhlPregameContextRunGame::STATE_PROCESSING) {
            return;
        }

        try {
            $counts = $builder->build($run, $this->gameId);
            $work->update([
                'state' => NhlPregameContextRunGame::STATE_COMPLETE,
                'team_context_count' => $counts['team_context_count'],
                'player_context_count' => $counts['player_context_count'],
                'completed_at' => now(),
                'last_error' => null,
            ]);
        } catch (Throwable $exception) {
            $work->update([
                'state' => NhlPregameContextRunGame::STATE_BLOCKED,
                'last_error' => mb_strimwidth($exception->getMessage(), 0, 2000),
            ]);
            $run->update([
                'status' => NhlPregameContextRun::STATUS_FAILED,
                'last_error' => mb_strimwidth($exception->getMessage(), 0, 2000),
            ]);
        }

        $orchestrator->refreshRunCounts($run->fresh());
        LoadNhlPregameContextRunJob::dispatch($this->runId);
    }
}
