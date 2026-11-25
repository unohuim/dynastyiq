<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\NhlGame;
use App\Models\NhlModelRun;
use App\Services\NhlCustomGameForecast;
use App\Services\NhlCustomGamePredictionInputs;
use App\Services\NhlPregameContextOrchestrator;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/** Refresh missing pregame data only after the requesting prediction has been published. */
class UpdateNhlPredictionPregameContextJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 0;
    public int $maxExceptions = 3;
    public int $timeout = 120;

    /** Capture publication identities, never serialized model history or bucket trees. */
    public function __construct(public int $gameId, public int $modelId, public int $revisionId)
    {
        $this->onQueue('projections');
        $this->afterCommit = true;
    }

    /** Coalesce all revisions requesting the same model/game repair. */
    public function uniqueId(): string
    {
        return $this->modelId.':'.$this->gameId;
    }

    /** Wait for the game day or an existing build without spending the exception budget. */
    public function handle(NhlCustomGameForecast $forecast, NhlPregameContextOrchestrator $contexts): void
    {
        if (! DB::table('nhl_game_prediction_revisions')->where('id', $this->revisionId)
            ->where('nhl_game_id', $this->gameId)->where('prediction_available', true)->exists()) {
            return;
        }
        $game = NhlGame::query()->find($this->gameId);
        $model = NhlModelRun::query()->find($this->modelId);
        if ($game === null || $model === null) {
            return;
        }
        if ($game->game_date->toDateString() > now('America/Toronto')->toDateString()) {
            $start = CarbonImmutable::parse($game->game_date->toDateString(), 'America/Toronto')->startOfDay();
            $this->release(max(1, (int) now()->diffInSeconds($start, false)));
            return;
        }
        // Paused builds stay paused; wait instead of starting another model-wide writer.
        if (DB::table('nhl_pregame_context_runs')->where('model_run_id', $this->modelId)
            ->whereIn('status', ['queued', 'running', 'pausing', 'paused'])->exists()) {
            $this->release(60);
            return;
        }
        $finished = in_array($game->game_state, ['FINAL', 'OFF'], true);
        $record = app(NhlCustomGamePredictionInputs::class)->anticipatedParticipants($game, $finished);
        $evidence = $forecast->contextEvidence($model, $game, $record);
        if ($forecast->contextReady($this->modelId, $this->gameId, $evidence)) {
            return;
        }
        if ($contexts->queuePredictionContexts($model, [$this->gameId => $evidence], [$this->gameId => $record]) === null) {
            $this->release(30);
        }
    }
}
