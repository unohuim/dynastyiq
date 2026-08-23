<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Events\NhlSatModelUpdated;
use App\Models\NhlExpectedGoalsModel;
use App\Models\NhlModelRun;
use App\Services\NhlExpectedGoalsBackfiller;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Builds and stores expected-goals predictions for a season from shot-attempt facts.
 */
class BackfillNhlExpectedGoalsJob implements ShouldQueue
{
    use Queueable;
    use InteractsWithQueue;
    use SerializesModels;

    /**
     * @var int
     */
    public int $tries = 1;

    /**
     * @var int
     */
    public int $timeout = 3600;

    public function __construct(
        public string $seasonId,
        public string $version,
        public int $minimumBucketAttempts,
        public int $smoothingPriorAttempts,
        public string $predictionTarget = NhlExpectedGoalsBackfiller::TARGET_GOAL,
        public ?int $modelRunId = null
    ) {
        $this->afterCommit = true;
    }

    /**
     * Prevent duplicate xG builds for the same season and version from running concurrently.
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->uniqueId()))
                ->expireAfter($this->timeout + 300),
        ];
    }

    public function uniqueId(): string
    {
        if ($this->modelRunId !== null) {
            return 'nhl-shot-outcome-backfill:run:' . $this->modelRunId . ':' . $this->predictionTarget;
        }

        return 'nhl-shot-outcome-backfill:' . $this->seasonId . ':' . $this->version . ':' . $this->predictionTarget;
    }

    /**
     * @return array<int,string>
     */
    public function tags(): array
    {
        return [
            'nhl-xg-backfill',
            'season:' . $this->seasonId,
            'version:' . $this->version,
            'target:' . $this->predictionTarget,
            $this->modelRunId !== null ? 'model-run:' . $this->modelRunId : 'model-run:none',
        ];
    }

    public function handle(NhlExpectedGoalsBackfiller $backfiller): void
    {
        if ($this->modelRunId !== null) {
            $run = NhlModelRun::query()->findOrFail($this->modelRunId);

            $backfiller->trainBucketsForRun(
                run: $run,
                version: $this->version,
                minimumBucketAttempts: $this->minimumBucketAttempts,
                smoothingPriorAttempts: $this->smoothingPriorAttempts,
                dryRun: false,
                predictionTarget: $this->predictionTarget
            );

            $this->refreshSatModelStatus($run);

            return;
        }

        $backfiller->backfill(
            seasonId: $this->seasonId,
            version: $this->version,
            minimumBucketAttempts: $this->minimumBucketAttempts,
            smoothingPriorAttempts: $this->smoothingPriorAttempts,
            dryRun: false,
            predictionTarget: $this->predictionTarget
        );
    }

    public function failed(Throwable $exception): void
    {
        app(NhlExpectedGoalsBackfiller::class)->markFailed($this->version, $exception->getMessage(), $this->predictionTarget);

        if ($this->modelRunId !== null) {
            $run = NhlModelRun::query()->find($this->modelRunId);

            $run?->forceFill([
                'status' => NhlModelRun::STATUS_FAILED,
                'metrics' => array_merge($run->metrics ?? [], [
                    'failed_at' => now()->toIso8601String(),
                    'error' => mb_substr($exception->getMessage(), 0, 1000),
                ]),
                'completed_at' => now(),
            ])->save();

            $this->broadcastSatModelUpdate($this->modelRunId, $this->broadcastReason('failed'));
        }

        Log::error('NHL expected-goals backfill job failed.', [
            'season_id' => $this->seasonId,
            'version' => $this->version,
            'prediction_target' => $this->predictionTarget,
            'model_run_id' => $this->modelRunId,
            'error' => $exception->getMessage(),
        ]);
    }

    private function refreshSatModelStatus(NhlModelRun $run): void
    {
        $statuses = NhlExpectedGoalsModel::query()
            ->where('model_run_id', $run->id)
            ->where('version', $this->version)
            ->whereIn('prediction_target', [
                $this->predictionTarget,
            ])
            ->pluck('status', 'prediction_target');

        $targetStatus = $statuses[$this->predictionTarget] ?? null;

        if ($targetStatus === 'failed') {
            $run->forceFill([
                'status' => NhlModelRun::STATUS_FAILED,
                'completed_at' => now(),
            ])->save();

            $this->broadcastSatModelUpdate((int) $run->id, $this->broadcastReason('failed'));

            return;
        }

        if ($targetStatus === 'draft') {
            $model = NhlExpectedGoalsModel::query()
                ->where('model_run_id', $run->id)
                ->where('version', $this->version)
                ->where('prediction_target', $this->predictionTarget)
                ->first();
            $hasPendingEval = $this->hasPendingQueuedEvaluation($run);

            $metrics = array_filter([
                'trained_at' => now()->toIso8601String(),
                'training_total_sat' => data_get($model?->metrics, 'training_total_sat'),
                'training_total_sog' => data_get($model?->metrics, 'training_total_sog'),
                'training_attempts' => data_get($model?->metrics, 'training_attempts'),
                'training_excluded_sat' => data_get($model?->metrics, 'training_excluded_sat'),
                'training_excluded_sog' => data_get($model?->metrics, 'training_excluded_sog'),
                'training_excluded_sat_rate' => data_get($model?->metrics, 'training_excluded_sat_rate'),
                'training_excluded_sog_rate' => data_get($model?->metrics, 'training_excluded_sog_rate'),
                'sat_factor_evaluation' => data_get($model?->metrics, 'sat_factor_evaluation'),
                'sog_factor_evaluation' => data_get($model?->metrics, 'sog_factor_evaluation'),
            ], static fn (mixed $value): bool => $value !== null);

            $run->forceFill([
                'status' => $hasPendingEval ? NhlModelRun::STATUS_RUNNING : NhlModelRun::STATUS_COMPLETE,
                'metrics' => array_merge($run->metrics ?? [], $metrics),
                'completed_at' => $hasPendingEval ? null : now(),
            ])->save();

            $this->broadcastSatModelUpdate((int) $run->id, $this->broadcastReason('completed'));

            return;
        }

        $this->broadcastSatModelUpdate((int) $run->id, $this->broadcastReason('progress'));
    }

    private function hasPendingQueuedEvaluation(NhlModelRun $run): bool
    {
        $version = $this->version;
        $queuedTargets = [];

        if (data_get($run->run_config, 'eval_sog_queued') === true) {
            $queuedTargets[] = NhlExpectedGoalsBackfiller::TARGET_GOAL;
        }

        if (data_get($run->run_config, 'eval_sat_queued') === true) {
            $queuedTargets[] = NhlExpectedGoalsBackfiller::TARGET_SHOT_ON_GOAL;
        }

        foreach (array_unique($queuedTargets) as $target) {
            $model = NhlExpectedGoalsModel::query()
                ->where('model_run_id', $run->id)
                ->where('version', $version)
                ->where('prediction_target', $target)
                ->first();

            if ($model === null || $model->status !== 'draft' || $model->trained_at === null || ! $model->buckets()->exists()) {
                return true;
            }
        }

        $otherModels = NhlExpectedGoalsModel::query()
            ->where('model_run_id', $run->id)
            ->where('version', $version)
            ->where('prediction_target', '!=', $this->predictionTarget)
            ->get();

        foreach ($otherModels as $model) {
            if ($model->status !== 'draft' || $model->trained_at === null || ! $model->buckets()->exists()) {
                return true;
            }
        }

        return false;
    }

    private function broadcastReason(string $status): string
    {
        $prefix = $this->predictionTarget === NhlExpectedGoalsBackfiller::TARGET_SHOT_ON_GOAL
            ? 'sat-eval'
            : 'sog-eval';

        return $prefix . '-' . $status;
    }

    private function broadcastSatModelUpdate(int $modelId, string $reason): void
    {
        try {
            broadcast(new NhlSatModelUpdated($modelId, $reason));
        } catch (Throwable $throwable) {
            Log::warning('NHL SAT model broadcast failed.', [
                'model_id' => $modelId,
                'reason' => $reason,
                'error' => $throwable->getMessage(),
            ]);
        }
    }
}
