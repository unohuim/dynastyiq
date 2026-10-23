<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\NhlExpectedGoalsModel;
use App\Models\NhlGame;
use App\Models\NhlModelRun;
use App\Services\NhlExpectedGoalsBackfiller;
use App\Services\NhlShotAttemptModelScorer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/** Apply one model run's existing SAT and SOG buckets to one imported game. */
class ScoreNhlPredictionGameSatJob implements ShouldQueue
{
    use Queueable;
    use InteractsWithQueue;

    private const HIGH_DANGER_THRESHOLD = 0.10;

    public int $tries = 10;
    public int $timeout = 180;
    public int $backoff = 30;

    /** Keep queued payloads independent of mutable model and source objects. */
    public function __construct(public int $modelRunId, public int $nhlGameId, public string $seasonId)
    {
        $this->onQueue('projections');
        $this->afterCommit = true;
    }

    /** Serialize duplicate scoring for this model/game while retaining reimport jobs. */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('nhl-prediction-sat:'.$this->modelRunId.':'.$this->nhlGameId))
            ->releaseAfter(15)->expireAfter(240)];
    }

    /** Expose model and source identities in Horizon. */
    public function tags(): array
    {
        return ['nhl-prediction-sat', 'model-run:'.$this->modelRunId, 'game:'.$this->nhlGameId, 'season:'.$this->seasonId];
    }

    /** Resolve the newest completed training for each existing prediction target.
     *
     * @return Collection<string, NhlExpectedGoalsModel>
     */
    public static function trainedModels(NhlModelRun $run, bool $lock = false): Collection
    {
        return NhlExpectedGoalsModel::query()
            ->where('model_run_id', $run->id)
            ->whereIn('prediction_target', [NhlExpectedGoalsBackfiller::TARGET_SHOT_ON_GOAL, NhlExpectedGoalsBackfiller::TARGET_GOAL])
            ->where('status', 'draft')
            ->whereNotNull('trained_at')
            ->whereHas('buckets')
            ->orderByDesc('trained_at')->orderByDesc('id')
            ->when($lock, fn ($query) => $query->sharedLock())
            ->get()->unique('prediction_target')->keyBy('prediction_target');
    }

    /** Recheck eligibility and commit both sets of ratings together without retraining. */
    public function handle(NhlShotAttemptModelScorer $scorer): void
    {
        $run = NhlModelRun::query()->find($this->modelRunId);
        $game = NhlGame::query()->find($this->nhlGameId);

        if ($run === null || $game === null || $run->model_family !== NhlModelRun::FAMILY_SAT
            || in_array($run->status, [NhlModelRun::STATUS_ARCHIVED, NhlModelRun::STATUS_FAILED], true)
            || $run->projectionSeasonId() !== $this->seasonId || (string) $game->season_id !== $this->seasonId
            || (int) ($run->game_type ?? 2) !== (int) $game->game_type) {
            return;
        }

        DB::transaction(function () use ($scorer, $run, $game): void {
            $models = self::trainedModels($run, true);
            if (! $models->has(NhlExpectedGoalsBackfiller::TARGET_SHOT_ON_GOAL)
                || ! $models->has(NhlExpectedGoalsBackfiller::TARGET_GOAL)) {
                throw new RuntimeException('Prediction-season SAT scoring requires trained SAT and SOG buckets.');
            }

            // A fact upsert must wait until both targets finish reading this game's existing facts.
            DB::table('nhl_shot_attempts_facts')->where('nhl_game_id', $this->nhlGameId)->sharedLock()->get(['id']);

            foreach ([NhlExpectedGoalsBackfiller::TARGET_SHOT_ON_GOAL, NhlExpectedGoalsBackfiller::TARGET_GOAL] as $target) {
                $model = $models->get($target);
                if (! $scorer->scoreGame($model, $this->nhlGameId, $this->seasonId, (int) $game->game_type, self::HIGH_DANGER_THRESHOLD)) {
                    throw new RuntimeException('The SAT scoring table is unavailable.');
                }
            }

            DB::afterCommit(function (): void {
                \App\Events\NhlStatsSourceUpdated::dispatch($this->seasonId);
            });
        });
    }

    /** Leave exhausted attempts visible as failed jobs with source and model context. */
    public function failed(Throwable $exception): void
    {
        Log::error('Prediction-season SAT scoring failed.', [
            'model_run_id' => $this->modelRunId,
            'game_id' => $this->nhlGameId,
            'season_id' => $this->seasonId,
            'error' => $exception->getMessage(),
        ]);
    }
}
