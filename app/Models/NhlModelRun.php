<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Versioned NHL modeling experiment or projection run.
 */
class NhlModelRun extends Model
{
    public const FAMILY_SAT = 'sat';

    public const STAGE_TRAINING = 'training';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETE = 'complete';
    public const STATUS_FAILED = 'failed';
    public const STATUS_ARCHIVED = 'archived';

    /**
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'train_season_ids' => 'array',
        'season_weights' => 'array',
        'run_config' => 'array',
        'metrics' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /** Resolve forecast age/opportunity season independently of held-out evaluation. */
    public function projectionSeasonId(): ?string
    {
        $explicit = $this->run_config['projection_season_id'] ?? null;
        if (is_string($explicit) && preg_match('/^\d{8}$/', $explicit) === 1) {
            return $explicit;
        }
        if ($this->target_season_id !== null) {
            return (string) $this->target_season_id;
        }
        $latest = collect($this->train_season_ids ?? [])->filter(
            fn (mixed $season): bool => preg_match('/^\d{8}$/', (string) $season) === 1
        )->max();
        if ($latest === null) {
            return null;
        }
        $startYear = (int) substr((string) $latest, 0, 4) + 1;

        return (string) $startYear . ($startYear + 1);
    }

    /** Check that a queued stage still belongs to the active combined build. */
    public function acceptsPredictionStage(?string $buildId, string $stage): bool
    {
        return $buildId === null
            || ($this->status === self::STATUS_RUNNING
                && data_get($this->metrics, 'prediction_build.id') === $buildId
                && data_get($this->metrics, 'prediction_build.status') === 'running'
                && data_get($this->metrics, 'prediction_build.stage') === $stage);
    }

    /**
     * Finish a complete batch and advance the same model, once, under a row lock.
     *
     * @param array<string, mixed> $stageMetrics
     */
    public static function finishPredictionStage(
        int $modelRunId,
        string $buildId,
        string $stage,
        bool $failed,
        array $stageMetrics = [],
        ?string $error = null,
    ): void {
        \Illuminate\Support\Facades\DB::transaction(function () use ($modelRunId, $buildId, $stage, $failed, $stageMetrics, $error): void {
            $run = self::query()->whereKey($modelRunId)->lockForUpdate()->first();
            if ($run === null || ! $run->acceptsPredictionStage($buildId, $stage)) {
                return;
            }

            $metrics = array_merge($run->metrics ?? [], $stageMetrics);
            $build = $metrics['prediction_build'];
            $next = $failed ? null : match ($stage) {
                'profiles' => 'rates',
                'rates' => 'toi',
                default => null,
            };
            $build['stage'] = $next ?? $stage;
            $build['status'] = $failed ? 'failed' : ($next === null ? 'complete' : 'running');
            if ($failed) {
                $build['error'] = mb_substr($error ?? 'One or more stage jobs failed or were cancelled.', 0, 1000);
            }
            if ($next === null) {
                $build['completed_at'] = now()->toIso8601String();
            } else {
                $prefix = $next === 'rates' ? 'rate_projection' : 'toi_projection';
                $metrics[$prefix . 's_started_at'] = now()->toIso8601String();
                $metrics[$prefix . '_entities_queued'] = 0;
                $metrics[$prefix . '_entities_completed'] = 0;
            }
            $metrics['prediction_build'] = $build;
            $run->forceFill([
                'metrics' => $metrics,
                'status' => $failed ? self::STATUS_FAILED : ($next === null ? self::STATUS_COMPLETE : self::STATUS_RUNNING),
                'completed_at' => $next === null ? now() : null,
            ])->save();

            if ($next !== null) {
                \Illuminate\Support\Facades\DB::afterCommit(function () use ($modelRunId, $buildId, $next): void {
                    try {
                        $job = $next === 'rates'
                            ? new \App\Jobs\BuildNhlSatModelEntityRateProjectionsJob($modelRunId, $buildId)
                            : new \App\Jobs\BuildNhlSatModelEntityToiProjectionsJob($modelRunId, $buildId);
                        \Illuminate\Support\Facades\Bus::dispatch($job);
                    } catch (\Throwable $exception) {
                        self::finishPredictionStage($modelRunId, $buildId, $next, true, error: $exception->getMessage());
                    }
                });
            }
        });
    }

    /**
     * Allowed model families.
     *
     * @return array<int, string>
     */
    public static function families(): array
    {
        return [
            self::FAMILY_SAT,
        ];
    }

    /**
     * Allowed workflow stages.
     *
     * @return array<int, string>
     */
    public static function workflowStages(): array
    {
        return [
            self::STAGE_TRAINING,
        ];
    }

    /**
     * Allowed run statuses.
     *
     * @return array<int, string>
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_DRAFT,
            self::STATUS_RUNNING,
            self::STATUS_COMPLETE,
            self::STATUS_FAILED,
            self::STATUS_ARCHIVED,
        ];
    }
}
