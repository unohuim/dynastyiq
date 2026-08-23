<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NhlExpectedGoalsModel;
use App\Models\NhlModelRun;
use Illuminate\Support\Facades\DB;

/**
 * Builds and backfills a first-pass bucket-smoothed NHL expected-goals model.
 */
class NhlExpectedGoalsBackfiller
{
    public const MODEL_NAME = 'nhl_bucket_smoothed_xg';
    public const DEFAULT_VERSION = 'bucket_smoothed_xg_v1';
    public const TARGET_GOAL = 'goal';
    public const TARGET_SHOT_ON_GOAL = 'shot_on_goal';

    private const MODEL_TYPE = 'bucket_smoothed';
    private const SAMPLE_SAT = 'sat';
    private const SAMPLE_SOG = 'sog';

    /**
     * Train a bucket-smoothed model and backfill predictions for one season.
     *
     * @return array<string, int|float|string>
     */
    public function backfill(
        string $seasonId,
        ?string $version = null,
        int $minimumBucketAttempts = 300,
        int $smoothingPriorAttempts = 100,
        bool $dryRun = false,
        string $predictionTarget = self::TARGET_GOAL
    ): array {
        return $this->backfillTrainingWindow(
            trainingSeasonIds: [$seasonId],
            scoringSeasonId: $seasonId,
            trainingSeasonId: $seasonId,
            version: $version ?: self::DEFAULT_VERSION,
            minimumBucketAttempts: $minimumBucketAttempts,
            smoothingPriorAttempts: $smoothingPriorAttempts,
            dryRun: $dryRun,
            predictionTarget: $predictionTarget,
        );
    }

    /**
     * Train bucket-smoothed probabilities from a SAT model's training seasons.
     *
     * @return array<string, int|float|string>
     */
    public function trainBucketsForRun(
        NhlModelRun $run,
        ?string $version = null,
        int $minimumBucketAttempts = 300,
        int $smoothingPriorAttempts = 100,
        bool $dryRun = false,
        string $predictionTarget = self::TARGET_GOAL
    ): array {
        unset($minimumBucketAttempts);

        $trainingSeasonIds = $this->normalizeSeasonIds($run->train_season_ids ?? []);

        if ($trainingSeasonIds === []) {
            return [
                'model_run_id' => (int) $run->id,
                'model_version' => $version ?: $this->versionForRun($run),
                'prediction_target' => $this->normalizePredictionTarget($predictionTarget),
                'training_season_ids' => implode(',', $trainingSeasonIds),
                'training_attempts' => 0,
                'training_successes' => 0,
                'baseline_success_rate' => 0.0,
                'bucket_count' => 0,
                'dry_run' => $dryRun ? 1 : 0,
            ];
        }

        if ($this->normalizePredictionTarget($predictionTarget) === self::TARGET_SHOT_ON_GOAL) {
            return $this->trainSatDangerWindow(
                trainingSeasonIds: $trainingSeasonIds,
                trainingSeasonId: $run->train_end_season_id ?? max($trainingSeasonIds),
                version: $version ?: $this->versionForRun($run),
                smoothingPriorAttempts: $smoothingPriorAttempts,
                dryRun: $dryRun,
                modelRunId: (int) $run->id,
            );
        }

        return $this->trainSogDangerWindow(
            trainingSeasonIds: $trainingSeasonIds,
            trainingSeasonId: $run->train_end_season_id ?? max($trainingSeasonIds),
            version: $version ?: $this->versionForRun($run),
            smoothingPriorAttempts: $smoothingPriorAttempts,
            dryRun: $dryRun,
            modelRunId: (int) $run->id,
        );
    }

    /**
     * Train goal probability from SOG rows only after evaluating candidate factor sets.
     *
     * @param array<int, string> $trainingSeasonIds
     * @return array<string, int|float|string>
     */
    private function trainSogDangerWindow(
        array $trainingSeasonIds,
        string $trainingSeasonId,
        string $version,
        int $smoothingPriorAttempts,
        bool $dryRun,
        ?int $modelRunId = null
    ): array {
        $factorEvaluation = $this->evaluateFactorCandidates(
            seasonIds: $trainingSeasonIds,
            sampleMode: self::SAMPLE_SOG,
            predictionTarget: self::TARGET_GOAL,
            baselineAttemptKey: 'baseline_sog',
            baselineSuccessKey: 'baseline_goals',
            scoreAttemptKey: 'sog',
            scoreSuccessKey: 'goals',
            method: 'weighted_absolute_lift_on_training_sog'
        );
        $winner = $factorEvaluation['winner'] ?? ['factor_keys' => ['distance_group', 'angle_group']];

        return $this->trainBucketWindow(
            trainingSeasonIds: $trainingSeasonIds,
            trainingSeasonId: $trainingSeasonId,
            version: $version,
            minimumBucketAttempts: 0,
            smoothingPriorAttempts: $smoothingPriorAttempts,
            dryRun: $dryRun,
            predictionTarget: self::TARGET_GOAL,
            modelRunId: $modelRunId,
            aggregateDefinitions: $this->sogDangerAggregateDefinitions($winner['factor_keys']),
            sampleMode: self::SAMPLE_SOG,
            evaluationMetrics: $factorEvaluation,
        );
    }

    /**
     * Train SOG probability from SAT rows after evaluating candidate factor sets.
     *
     * @param array<int, string> $trainingSeasonIds
     * @return array<string, int|float|string>
     */
    private function trainSatDangerWindow(
        array $trainingSeasonIds,
        string $trainingSeasonId,
        string $version,
        int $smoothingPriorAttempts,
        bool $dryRun,
        ?int $modelRunId = null
    ): array {
        $factorEvaluation = $this->evaluateFactorCandidates(
            seasonIds: $trainingSeasonIds,
            sampleMode: self::SAMPLE_SAT,
            predictionTarget: self::TARGET_SHOT_ON_GOAL,
            baselineAttemptKey: 'baseline_sat',
            baselineSuccessKey: 'baseline_sog',
            scoreAttemptKey: 'sat',
            scoreSuccessKey: 'sog',
            method: 'weighted_absolute_lift_on_training_sat'
        );
        $winner = $factorEvaluation['winner'] ?? ['factor_keys' => ['distance_group', 'angle_group']];

        return $this->trainBucketWindow(
            trainingSeasonIds: $trainingSeasonIds,
            trainingSeasonId: $trainingSeasonId,
            version: $version,
            minimumBucketAttempts: 0,
            smoothingPriorAttempts: $smoothingPriorAttempts,
            dryRun: $dryRun,
            predictionTarget: self::TARGET_SHOT_ON_GOAL,
            modelRunId: $modelRunId,
            aggregateDefinitions: $this->sogDangerAggregateDefinitions($winner['factor_keys']),
            sampleMode: self::SAMPLE_SAT,
            evaluationMetrics: $factorEvaluation,
        );
    }

    /**
     * @param array<int, string> $trainingSeasonIds
     * @return array<string, int|float|string>
     */
    private function trainBucketWindow(
        array $trainingSeasonIds,
        string $trainingSeasonId,
        string $version,
        int $minimumBucketAttempts,
        int $smoothingPriorAttempts,
        bool $dryRun,
        string $predictionTarget,
        ?int $modelRunId = null,
        ?array $aggregateDefinitions = null,
        string $sampleMode = self::SAMPLE_SAT,
        ?array $evaluationMetrics = null
    ): array {
        $version = $version ?: self::DEFAULT_VERSION;
        $predictionTarget = $this->normalizePredictionTarget($predictionTarget);
        $trainedAt = now();
        $trainingSeasonIds = $this->normalizeSeasonIds($trainingSeasonIds);
        $bucketStats = $this->trainingBucketStats(
            $trainingSeasonIds,
            $predictionTarget,
            $aggregateDefinitions,
            $sampleMode
        );
        $baseline = $bucketStats[$this->bucketKey(99, ['baseline' => 'league'])] ?? ['attempts' => 0, 'successes' => 0];
        $trainingEligibility = $sampleMode === self::SAMPLE_SOG
            ? $this->sogTrainingEligibilityCounts($trainingSeasonIds)
            : $this->trainingEligibilityCounts($trainingSeasonIds);
        $baselineRate = $baseline['attempts'] > 0 ? $baseline['successes'] / $baseline['attempts'] : 0.0;
        $buckets = $this->trainedBuckets($bucketStats, $smoothingPriorAttempts, $baselineRate);
        $bucketCount = count($buckets);
        $workflowAction = $sampleMode === self::SAMPLE_SAT && $predictionTarget === self::TARGET_SHOT_ON_GOAL
            ? 'eval_sat'
            : 'eval_sog';

        if ($dryRun) {
            return [
                'model_version' => $version,
                'prediction_target' => $predictionTarget,
                'model_run_id' => (int) ($modelRunId ?? 0),
                'training_season_id' => $trainingSeasonId,
                'training_season_ids' => implode(',', $trainingSeasonIds),
                'training_total_sat' => $trainingEligibility['total'],
                'training_total_sog' => $sampleMode === self::SAMPLE_SOG ? $trainingEligibility['total'] : null,
                'training_attempts' => $baseline['attempts'],
                'training_excluded_sat' => $trainingEligibility['excluded'],
                'training_excluded_sog' => $sampleMode === self::SAMPLE_SOG ? $trainingEligibility['excluded'] : null,
                'training_excluded_sat_rate' => $trainingEligibility['excluded_rate'],
                'training_excluded_sog_rate' => $sampleMode === self::SAMPLE_SOG ? $trainingEligibility['excluded_rate'] : null,
                'training_successes' => $baseline['successes'],
                'baseline_success_rate' => round($baselineRate, 6),
                'bucket_count' => $bucketCount,
                'sample_mode' => $sampleMode,
                'workflow_action' => $workflowAction,
                'dry_run' => 1,
            ];
        }

        $model = NhlExpectedGoalsModel::query()->updateOrCreate(
            ['name' => self::MODEL_NAME, 'version' => $version, 'prediction_target' => $predictionTarget],
            [
                'model_run_id' => $modelRunId,
                'model_type' => self::MODEL_TYPE,
                'training_season_id' => $trainingSeasonId,
                'minimum_bucket_attempts' => $minimumBucketAttempts,
                'smoothing_prior_attempts' => $smoothingPriorAttempts,
                'training_filters' => $this->trainingFilters($sampleMode),
                'feature_config' => [
                    'fallback_levels' => $aggregateDefinitions !== null
                        ? array_map(fn (array $definition): array => array_keys($definition), $aggregateDefinitions)
                        : $this->fallbackDefinitions(),
                    'bucket_selection' => 'shrinkage_confidence_no_hard_minimum',
                    'excluded_training_values' => [
                        'shot_type_bucket' => $sampleMode === self::SAMPLE_SOG ? ['unknown', 'other'] : ['unknown'],
                        'period_type' => ['SO'],
                        'is_empty_net' => [true],
                    ],
                    'prediction_target' => $predictionTarget,
                    'sample_mode' => $sampleMode,
                    'workflow_action' => $workflowAction,
                    'training_season_ids' => $trainingSeasonIds,
                    'sat_factor_evaluation' => $sampleMode === self::SAMPLE_SAT
                        && $predictionTarget === self::TARGET_SHOT_ON_GOAL ? $evaluationMetrics : null,
                    'sog_factor_evaluation' => $sampleMode === self::SAMPLE_SOG ? $evaluationMetrics : null,
                ],
                'calibration_config' => [
                    'method' => 'identity_v1',
                    'reason' => 'First-pass bucket smoothing; calibration bands are intentionally deferred.',
                ],
                'metrics' => ['started_at' => $trainedAt->toIso8601String()],
                'status' => 'running',
                'trained_at' => $trainedAt,
            ]
        );

        DB::table('nhl_expected_goals_model_buckets')
            ->where('expected_goals_model_id', $model->id)
            ->delete();

        $this->insertBuckets((int) $model->id, $buckets);

        unset($buckets, $bucketStats);

        $model->forceFill([
            'metrics' => [
                'model_run_id' => $modelRunId,
                'training_season_ids' => $trainingSeasonIds,
                'training_total_sat' => $trainingEligibility['total'],
                'training_total_sog' => $sampleMode === self::SAMPLE_SOG ? $trainingEligibility['total'] : null,
                'training_attempts' => $baseline['attempts'],
                'training_excluded_sat' => $trainingEligibility['excluded'],
                'training_excluded_sog' => $sampleMode === self::SAMPLE_SOG ? $trainingEligibility['excluded'] : null,
                'training_excluded_sat_rate' => $trainingEligibility['excluded_rate'],
                'training_excluded_sog_rate' => $sampleMode === self::SAMPLE_SOG ? $trainingEligibility['excluded_rate'] : null,
                'training_successes' => $baseline['successes'],
                'baseline_success_rate' => round($baselineRate, 6),
                'bucket_count' => $bucketCount,
                'sample_mode' => $sampleMode,
                'workflow_action' => $workflowAction,
                'sat_factor_evaluation' => $sampleMode === self::SAMPLE_SAT
                    && $predictionTarget === self::TARGET_SHOT_ON_GOAL ? $evaluationMetrics : null,
                'sog_factor_evaluation' => $sampleMode === self::SAMPLE_SOG ? $evaluationMetrics : null,
            ],
            'status' => 'draft',
            'trained_at' => $trainedAt,
        ])->save();

        return [
            'model_id' => (int) $model->id,
            'model_version' => $version,
            'prediction_target' => $predictionTarget,
            'model_run_id' => (int) ($modelRunId ?? 0),
            'training_season_id' => $trainingSeasonId,
            'training_season_ids' => implode(',', $trainingSeasonIds),
            'training_total_sat' => $trainingEligibility['total'],
            'training_total_sog' => $sampleMode === self::SAMPLE_SOG ? $trainingEligibility['total'] : null,
            'training_attempts' => $baseline['attempts'],
            'training_excluded_sat' => $trainingEligibility['excluded'],
            'training_excluded_sog' => $sampleMode === self::SAMPLE_SOG ? $trainingEligibility['excluded'] : null,
            'training_excluded_sat_rate' => $trainingEligibility['excluded_rate'],
            'training_excluded_sog_rate' => $sampleMode === self::SAMPLE_SOG ? $trainingEligibility['excluded_rate'] : null,
            'training_successes' => $baseline['successes'],
            'baseline_success_rate' => round($baselineRate, 6),
            'bucket_count' => $bucketCount,
            'sample_mode' => $sampleMode,
            'workflow_action' => $workflowAction,
            'dry_run' => 0,
        ];
    }

    /**
     * @param array<int, string> $trainingSeasonIds
     * @return array<string, int|float|string>
     */
    private function backfillTrainingWindow(
        array $trainingSeasonIds,
        string $scoringSeasonId,
        string $trainingSeasonId,
        string $version,
        int $minimumBucketAttempts,
        int $smoothingPriorAttempts,
        bool $dryRun,
        string $predictionTarget,
        ?int $modelRunId = null
    ): array {
        $result = $this->trainBucketWindow(
            trainingSeasonIds: $trainingSeasonIds,
            trainingSeasonId: $trainingSeasonId,
            version: $version,
            minimumBucketAttempts: $minimumBucketAttempts,
            smoothingPriorAttempts: $smoothingPriorAttempts,
            dryRun: $dryRun,
            predictionTarget: $predictionTarget,
            modelRunId: $modelRunId,
        );

        if ($dryRun) {
            return array_merge($result, [
                'scoring_season_id' => $scoringSeasonId,
                'predictions_upserted' => 0,
                'excluded_predictions' => 0,
            ]);
        }

        $predictionCounts = $this->backfillPredictions(
            modelId: (int) $result['model_id'],
            seasonId: $scoringSeasonId,
            predictionTarget: (string) $result['prediction_target'],
            modelRunId: $modelRunId,
        );

        $model = NhlExpectedGoalsModel::query()->find((int) $result['model_id']);

        $model?->forceFill([
                'metrics' => array_merge(
                    $model->metrics ?? [],
                    [
                        'scoring_season_id' => $scoringSeasonId,
                        'predictions_upserted' => $predictionCounts['upserted'],
                        'excluded_predictions' => $predictionCounts['excluded'],
                    ]
                ),
            ])->save();

        return array_merge($result, [
            'scoring_season_id' => $scoringSeasonId,
            'predictions_upserted' => $predictionCounts['upserted'],
            'excluded_predictions' => $predictionCounts['excluded'],
        ]);
    }

    public function markFailed(string $version, string $message, string $predictionTarget = self::TARGET_GOAL): void
    {
        $model = NhlExpectedGoalsModel::query()
            ->where('name', self::MODEL_NAME)
            ->where('version', $version)
            ->where('prediction_target', $this->normalizePredictionTarget($predictionTarget))
            ->first();

        if ($model === null) {
            return;
        }

        $model->forceFill([
            'status' => 'failed',
            'metrics' => [
                'failed_at' => now()->toIso8601String(),
                'error' => mb_substr($message, 0, 1000),
            ],
        ])->save();
    }

    /**
     * @param array<int, array<string, string>>|null $aggregateDefinitions
     * @return array<string, array{attempts:int,successes:int,level:int,dimensions:array<string,string>}>
     */
    private function trainingBucketStats(
        array $seasonIds,
        string $predictionTarget,
        ?array $aggregateDefinitions = null,
        string $sampleMode = self::SAMPLE_SAT
    ): array {
        $stats = [];
        $definitions = $aggregateDefinitions ?? $this->aggregateDefinitions();

        foreach ($definitions as $level => $definition) {
            foreach ($this->aggregateBucketStats($seasonIds, $predictionTarget, $level, $definition, $sampleMode) as $bucket) {
                $key = $this->bucketKey($level, $bucket['dimensions']);
                $stats[$key] = [
                    'attempts' => $bucket['attempts'],
                    'successes' => $bucket['successes'],
                    'level' => $level,
                    'dimensions' => $bucket['dimensions'],
                ];
            }
        }

        return $stats;
    }

    /**
     * @param array<string, string> $definition
     * @return array<int, array{attempts:int,successes:int,dimensions:array<string,string>}>
     */
    private function aggregateBucketStats(
        array $seasonIds,
        string $predictionTarget,
        int $level,
        array $definition,
        string $sampleMode = self::SAMPLE_SAT
    ): array {
        $outcomeColumn = $this->outcomeColumn($predictionTarget);

        if ($level === 99) {
            $row = $this->eligibleTrainingBaseQuery($seasonIds, $sampleMode)
                ->selectRaw('COUNT(*) as attempts')
                ->selectRaw('SUM(CASE WHEN ' . $outcomeColumn . ' THEN 1 ELSE 0 END) as successes')
                ->first();

            return [[
                'attempts' => (int) ($row->attempts ?? 0),
                'successes' => (int) ($row->successes ?? 0),
                'dimensions' => ['baseline' => 'league'],
            ]];
        }

        $query = $this->eligibleTrainingBaseQuery($seasonIds, $sampleMode)
            ->selectRaw('COUNT(*) as attempts')
            ->selectRaw('SUM(CASE WHEN ' . $outcomeColumn . ' THEN 1 ELSE 0 END) as successes');

        foreach ($definition as $alias => $expression) {
            $query->selectRaw($expression . ' as ' . $alias)
                ->groupByRaw($expression);
        }

        return $query
            ->get()
            ->map(function (object $row) use ($definition): array {
                $dimensions = [];

                foreach (array_keys($definition) as $alias) {
                    $dimensions[$alias] = $this->value($row->{$alias} ?? null);
                }

                return [
                    'attempts' => (int) $row->attempts,
                    'successes' => (int) $row->successes,
                    'dimensions' => $dimensions,
                ];
            })
            ->all();
    }

    /**
     * @param array<string, array{attempts:int,successes:int,level:int,dimensions:array<string,string>}> $bucketStats
     * @return array<string, array{attempts:int,successes:int,level:int,dimensions:array<string,string>,raw_success_rate:float,smoothed_probability:float,confidence_score:float,confidence_bucket:string,shrinkage_weight:float}>
     */
    private function trainedBuckets(
        array $bucketStats,
        int $smoothingPriorAttempts,
        float $baselineRate
    ): array {
        $buckets = [];

        foreach ($bucketStats as $key => $bucket) {
            $rawRate = $bucket['attempts'] > 0 ? $bucket['successes'] / $bucket['attempts'] : 0.0;
            $priorAttempts = $bucket['level'] === 99 ? 0 : $smoothingPriorAttempts;
            $denominator = max(1, $bucket['attempts'] + $priorAttempts);
            $smoothed = ($bucket['successes'] + ($baselineRate * $priorAttempts)) / $denominator;
            $confidenceScore = $bucket['level'] === 99 ? 1.0 : $bucket['attempts'] / $denominator;
            $shrinkageWeight = $bucket['level'] === 99 ? 0.0 : $priorAttempts / $denominator;

            $buckets[$key] = [
                ...$bucket,
                'raw_success_rate' => $rawRate,
                'smoothed_probability' => $smoothed,
                'confidence_score' => $confidenceScore,
                'confidence_bucket' => $this->confidenceBucket($confidenceScore),
                'shrinkage_weight' => $shrinkageWeight,
            ];
        }

        return $buckets;
    }

    /**
     * @param array<string, array{attempts:int,successes:int,level:int,dimensions:array<string,string>,raw_success_rate:float,smoothed_probability:float,confidence_score:float,confidence_bucket:string,shrinkage_weight:float}> $buckets
     */
    private function insertBuckets(int $modelId, array $buckets): void
    {
        $now = now();
        $rows = [];

        foreach ($buckets as $key => $bucket) {
            $rows[] = [
                'expected_goals_model_id' => $modelId,
                'bucket_key' => $key,
                'fallback_level' => $bucket['level'],
                'bucket_dimensions' => json_encode($bucket['dimensions'], JSON_THROW_ON_ERROR),
                'attempts' => $bucket['attempts'],
                'goals' => $bucket['successes'],
                'raw_goal_rate' => round($bucket['raw_success_rate'], 6),
                'smoothed_goal_probability' => round($bucket['smoothed_probability'], 6),
                'confidence_score' => round($bucket['confidence_score'], 4),
                'confidence_bucket' => $bucket['confidence_bucket'],
                'shrinkage_weight' => round($bucket['shrinkage_weight'], 4),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($rows) >= 1000) {
                DB::table('nhl_expected_goals_model_buckets')->insert($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            DB::table('nhl_expected_goals_model_buckets')->insert($rows);
        }
    }

    /**
     * @return array{upserted:int,excluded:int}
     */
    private function backfillPredictions(
        int $modelId,
        string $seasonId,
        string $predictionTarget,
        ?int $modelRunId = null
    ): array {
        DB::table('nhl_shot_attempt_predictions')
            ->where('expected_goals_model_id', $modelId)
            ->where('prediction_target', $predictionTarget)
            ->where('season_id', $seasonId)
            ->delete();

        $exclusionReason = $this->exclusionReasonSql();
        $candidateKeys = implode(",\n                    ", $this->candidateBucketKeySql());

        DB::statement(<<<SQL
INSERT INTO nhl_shot_attempt_predictions (
    model_run_id,
    expected_goals_model_id,
    prediction_target,
    shot_attempt_fact_id,
    play_by_play_id,
    nhl_game_id,
    season_id,
    game_date,
    team_id,
    opponent_team_id,
    shooter_player_id,
    goalie_player_id,
    is_scored,
    exclusion_reason,
    raw_xg,
    calibrated_xg,
    xg,
    matched_bucket_key,
    fallback_level,
    matched_bucket_payload,
    created_at,
    updated_at
)
SELECT
    ? as model_run_id,
    ? as expected_goals_model_id,
    ? as prediction_target,
    facts.id as shot_attempt_fact_id,
    facts.play_by_play_id,
    facts.nhl_game_id,
    facts.season_id,
    facts.game_date,
    facts.team_id,
    facts.opponent_team_id,
    facts.shooter_player_id,
    facts.goalie_player_id,
    CASE WHEN exclusions.exclusion_reason IS NULL AND matched.bucket_key IS NOT NULL THEN true ELSE false END as is_scored,
    exclusions.exclusion_reason,
    matched.smoothed_goal_probability as raw_xg,
    matched.smoothed_goal_probability as calibrated_xg,
    matched.smoothed_goal_probability as xg,
    matched.bucket_key as matched_bucket_key,
    matched.fallback_level,
    NULL as matched_bucket_payload,
    now() as created_at,
    now() as updated_at
FROM nhl_shot_attempts_facts facts
CROSS JOIN LATERAL (
    SELECT {$exclusionReason} as exclusion_reason
) exclusions
LEFT JOIN LATERAL (
    SELECT buckets.bucket_key, buckets.fallback_level, buckets.smoothed_goal_probability
    FROM (
        VALUES
            {$candidateKeys}
    ) candidates(sort_order, bucket_key)
    INNER JOIN nhl_expected_goals_model_buckets buckets
        ON buckets.expected_goals_model_id = ?
        AND buckets.bucket_key = candidates.bucket_key
    WHERE exclusions.exclusion_reason IS NULL
    ORDER BY candidates.sort_order
    LIMIT 1
) matched ON true
WHERE facts.season_id = ?
SQL, [$modelRunId, $modelId, $predictionTarget, $modelId, $seasonId]);

        $row = DB::table('nhl_shot_attempt_predictions')
            ->where('expected_goals_model_id', $modelId)
            ->where('prediction_target', $predictionTarget)
            ->where('season_id', $seasonId)
            ->selectRaw('COUNT(*) as upserted')
            ->selectRaw('SUM(CASE WHEN exclusion_reason IS NOT NULL THEN 1 ELSE 0 END) as excluded')
            ->first();

        return [
            'upserted' => (int) ($row->upserted ?? 0),
            'excluded' => (int) ($row->excluded ?? 0),
        ];
    }

    private function exclusionReasonSql(): string
    {
        return "CASE
            WHEN COALESCE(NULLIF(facts.shot_type_bucket, ''), 'unknown') = 'unknown' THEN 'unknown_shot_type'
            WHEN facts.is_empty_net = true THEN 'empty_net'
            WHEN COALESCE(NULLIF(facts.period_type, ''), 'unknown') = 'SO' THEN 'shootout'
            ELSE NULL
        END";
    }

    private function eligibleTrainingBaseQuery(array $seasonIds, string $sampleMode = self::SAMPLE_SAT)
    {
        $query = DB::table('nhl_shot_attempts_facts')
            ->whereIn('season_id', $seasonIds)
            ->where('shot_type_bucket', '<>', 'unknown')
            ->where(function ($query): void {
                $query->whereNull('period_type')
                    ->orWhere('period_type', '<>', 'SO');
            })
            ->where(function ($query): void {
                $query->whereNull('is_empty_net')
                    ->orWhere('is_empty_net', false);
            });

        if ($sampleMode === self::SAMPLE_SOG) {
            $query->where('is_shot_on_goal', true)
                ->where('shot_type_bucket', '<>', 'other');
        }

        return $query;
    }

    /**
     * @return array{total:int,eligible:int,excluded:int,excluded_rate:float}
     */
    public function trainingEligibilityCounts(array $seasonIds): array
    {
        $seasonIds = $this->normalizeSeasonIds($seasonIds);

        if ($seasonIds === []) {
            return [
                'total' => 0,
                'eligible' => 0,
                'excluded' => 0,
                'excluded_rate' => 0.0,
            ];
        }

        $total = DB::table('nhl_shot_attempts_facts')
            ->whereIn('season_id', $seasonIds)
            ->count();
        $eligible = $this->eligibleTrainingBaseQuery($seasonIds)->count();
        $excluded = max(0, $total - $eligible);

        return [
            'total' => $total,
            'eligible' => $eligible,
            'excluded' => $excluded,
            'excluded_rate' => $total > 0 ? round($excluded / $total, 6) : 0.0,
        ];
    }

    /**
     * @param array<int, string>|array<int, int> $seasonIds
     * @return array{total:int,eligible:int,excluded:int,excluded_rate:float}
     */
    public function sogTrainingEligibilityCounts(array $seasonIds): array
    {
        $seasonIds = $this->normalizeSeasonIds($seasonIds);

        if ($seasonIds === []) {
            return [
                'total' => 0,
                'eligible' => 0,
                'excluded' => 0,
                'excluded_rate' => 0.0,
            ];
        }

        $total = DB::table('nhl_shot_attempts_facts')
            ->whereIn('season_id', $seasonIds)
            ->where('is_shot_on_goal', true)
            ->count();
        $eligible = $this->eligibleTrainingBaseQuery($seasonIds, self::SAMPLE_SOG)->count();
        $excluded = max(0, $total - $eligible);

        return [
            'total' => $total,
            'eligible' => $eligible,
            'excluded' => $excluded,
            'excluded_rate' => $total > 0 ? round($excluded / $total, 6) : 0.0,
        ];
    }

    /**
     * @param array<int, string> $seasonIds
     * @return array<string, mixed>
     */
    private function evaluateFactorCandidates(
        array $seasonIds,
        string $sampleMode,
        string $predictionTarget,
        string $baselineAttemptKey,
        string $baselineSuccessKey,
        string $scoreAttemptKey,
        string $scoreSuccessKey,
        string $method
    ): array
    {
        $outcomeColumn = $this->outcomeColumn($predictionTarget);
        $baseline = $this->eligibleTrainingBaseQuery($seasonIds, $sampleMode)
            ->selectRaw('COUNT(*) as attempts')
            ->selectRaw('SUM(CASE WHEN ' . $outcomeColumn . ' THEN 1 ELSE 0 END) as successes')
            ->first();
        $attempts = (int) ($baseline->attempts ?? 0);
        $successes = (int) ($baseline->successes ?? 0);
        $baselineRate = $attempts > 0 ? $successes / $attempts : 0.0;
        $singles = [];

        foreach ($this->evalFactorDefinitions() as $key => $definition) {
            $singles[] = $this->scoreFactorSet(
                seasonIds: $seasonIds,
                factorKeys: [$key],
                label: $definition['label'],
                baselineRate: $baselineRate,
                sampleMode: $sampleMode,
                predictionTarget: $predictionTarget,
                scoreAttemptKey: $scoreAttemptKey,
                scoreSuccessKey: $scoreSuccessKey
            );
        }

        $singles = collect($singles)
            ->sortByDesc('score')
            ->values()
            ->all();
        $topSingles = array_slice($singles, 0, 5);
        $doubles = [];

        foreach ($topSingles as $leftIndex => $left) {
            foreach (array_slice($topSingles, $leftIndex + 1) as $right) {
                $keys = array_values(array_unique(array_merge($left['factor_keys'], $right['factor_keys'])));

                if (count($keys) !== 2) {
                    continue;
                }

                $doubles[] = $this->scoreFactorSet(
                    seasonIds: $seasonIds,
                    factorKeys: $keys,
                    label: $left['label'] . ' + ' . $right['label'],
                    baselineRate: $baselineRate,
                    sampleMode: $sampleMode,
                    predictionTarget: $predictionTarget,
                    scoreAttemptKey: $scoreAttemptKey,
                    scoreSuccessKey: $scoreSuccessKey
                );
            }
        }

        $doubles = collect($doubles)
            ->sortByDesc('score')
            ->values()
            ->all();
        $winner = collect(array_merge($singles, $doubles))
            ->sortByDesc('score')
            ->first();

        return [
            $baselineAttemptKey => $attempts,
            $baselineSuccessKey => $successes,
            'baseline_rate' => round($baselineRate, 6),
            'candidate_method' => $method,
            'winner' => $winner ?? [
                'factor_keys' => ['distance_group', 'angle_group'],
                'label' => 'Distance + Angle',
                'score' => 0.0,
                'rows' => 0,
                $scoreAttemptKey => 0,
            ],
            'singles' => array_slice($singles, 0, 10),
            'doubles' => array_slice($doubles, 0, 10),
        ];
    }

    /**
     * @param array<int, string> $seasonIds
     * @param array<int, string> $factorKeys
     * @return array<string, mixed>
     */
    private function scoreFactorSet(
        array $seasonIds,
        array $factorKeys,
        string $label,
        float $baselineRate,
        string $sampleMode,
        string $predictionTarget,
        string $scoreAttemptKey,
        string $scoreSuccessKey
    ): array {
        $definitions = $this->evalFactorDefinitions();
        $outcomeColumn = $this->outcomeColumn($predictionTarget);
        $query = $this->eligibleTrainingBaseQuery($seasonIds, $sampleMode)
            ->selectRaw('COUNT(*) as attempts')
            ->selectRaw('SUM(CASE WHEN ' . $outcomeColumn . ' THEN 1 ELSE 0 END) as successes');

        foreach ($factorKeys as $key) {
            $expression = $definitions[$key]['expression'];
            $query->selectRaw($expression . ' as ' . $key)
                ->groupByRaw($expression);
        }

        $rows = $query->get();
        $covered = 0;
        $goals = 0;
        $score = 0.0;
        $minRate = null;
        $maxRate = null;

        foreach ($rows as $row) {
            $attempts = (int) $row->attempts;
            $successes = (int) $row->successes;

            if ($attempts <= 0) {
                continue;
            }

            $rate = $successes / $attempts;
            $covered += $attempts;
            $goals += $successes;
            $score += $attempts * abs($rate - $baselineRate);
            $minRate = $minRate === null ? $rate : min($minRate, $rate);
            $maxRate = $maxRate === null ? $rate : max($maxRate, $rate);
        }

        return [
            'factor_keys' => $factorKeys,
            'label' => $label,
            'score' => round($covered > 0 ? $score / $covered : 0.0, 6),
            'rows' => $rows->count(),
            $scoreAttemptKey => $covered,
            $scoreSuccessKey => $goals,
            'min_rate' => round((float) ($minRate ?? 0.0), 6),
            'max_rate' => round((float) ($maxRate ?? 0.0), 6),
        ];
    }

    /**
     * @param array<int, string> $factorKeys
     * @return array<int, array<string, string>>
     */
    private function sogDangerAggregateDefinitions(array $factorKeys): array
    {
        $definitions = $this->evalFactorDefinitions();
        $aggregate = [];

        foreach ($factorKeys as $key) {
            if (isset($definitions[$key])) {
                $aggregate[$key] = $definitions[$key]['expression'];
            }
        }

        if ($aggregate === []) {
            $aggregate = [
                'distance_group' => $definitions['distance_group']['expression'],
                'angle_group' => $definitions['angle_group']['expression'],
            ];
        }

        return [
            1 => $aggregate,
            99 => ['baseline' => "'league'"],
        ];
    }

    /**
     * @return array<string, array{label:string,expression:string}>
     */
    private function evalFactorDefinitions(): array
    {
        return [
            'distance_group' => [
                'label' => 'Distance',
                'expression' => "CASE
                    WHEN shot_distance IS NULL THEN 'unknown'
                    WHEN shot_distance < 5 THEN 'd_000_005'
                    WHEN shot_distance < 10 THEN 'd_005_010'
                    WHEN shot_distance < 15 THEN 'd_010_015'
                    WHEN shot_distance < 20 THEN 'd_015_020'
                    WHEN shot_distance < 25 THEN 'd_020_025'
                    WHEN shot_distance < 30 THEN 'd_025_030'
                    WHEN shot_distance < 35 THEN 'd_030_035'
                    WHEN shot_distance < 40 THEN 'd_035_040'
                    WHEN shot_distance < 45 THEN 'd_040_045'
                    WHEN shot_distance < 50 THEN 'd_045_050'
                    WHEN shot_distance < 55 THEN 'd_050_055'
                    WHEN shot_distance < 60 THEN 'd_055_060'
                    ELSE 'd_060_plus'
                END",
            ],
            'angle_group' => [
                'label' => 'Angle',
                'expression' => "CASE
                    WHEN abs_shot_angle IS NULL THEN 'unknown'
                    WHEN abs_shot_angle < 10 THEN 'a_000_010'
                    WHEN abs_shot_angle < 20 THEN 'a_010_020'
                    WHEN abs_shot_angle < 30 THEN 'a_020_030'
                    WHEN abs_shot_angle < 40 THEN 'a_030_040'
                    WHEN abs_shot_angle < 50 THEN 'a_040_050'
                    WHEN abs_shot_angle < 60 THEN 'a_050_060'
                    WHEN abs_shot_angle < 70 THEN 'a_060_070'
                    WHEN abs_shot_angle < 80 THEN 'a_070_080'
                    WHEN abs_shot_angle <= 90 THEN 'a_080_090'
                    ELSE 'invalid_gt_90'
                END",
            ],
            'shot_type_group' => [
                'label' => 'Shot Type',
                'expression' => "COALESCE(NULLIF(shot_type_bucket, ''), 'unknown')",
            ],
            'sequence_group' => [
                'label' => 'Sequence',
                'expression' => "CASE
                    WHEN is_rush = true AND is_rebound = true THEN 'rush_rebound'
                    WHEN is_rebound = true THEN 'rebound'
                    WHEN is_rush = true THEN 'rush'
                    ELSE 'settled'
                END",
            ],
            'strength_group' => [
                'label' => 'Strength',
                'expression' => "COALESCE(NULLIF(strength_bucket, ''), NULLIF(strength, ''), 'unknown')",
            ],
            'period_group' => [
                'label' => 'Period',
                'expression' => "CASE
                    WHEN period = 1 THEN 'p1'
                    WHEN period = 2 THEN 'p2'
                    WHEN period = 3 THEN 'p3'
                    WHEN period > 3 THEN 'ot'
                    ELSE 'unknown'
                END",
            ],
            'score_state_group' => [
                'label' => 'Score State',
                'expression' => "COALESCE(NULLIF(score_state_bucket, ''), 'unknown')",
            ],
            'shooter_age_group' => [
                'label' => 'Shooter Age',
                'expression' => "CASE
                    WHEN shooter_age_years IS NULL THEN 'unknown'
                    WHEN shooter_age_years <= 21 THEN 'age_le_21'
                    WHEN shooter_age_years <= 25 THEN 'age_22_25'
                    WHEN shooter_age_years <= 29 THEN 'age_26_29'
                    WHEN shooter_age_years <= 33 THEN 'age_30_33'
                    ELSE 'age_34_plus'
                END",
            ],
            'shooter_height_group' => [
                'label' => 'Shooter Height',
                'expression' => "CASE
                    WHEN shooter_height_inches IS NULL THEN 'unknown'
                    WHEN shooter_height_inches <= 68 THEN 'h_le_68'
                    WHEN shooter_height_inches <= 71 THEN 'h_69_71'
                    WHEN shooter_height_inches <= 74 THEN 'h_72_74'
                    WHEN shooter_height_inches <= 77 THEN 'h_75_77'
                    ELSE 'h_78_plus'
                END",
            ],
            'shooter_weight_group' => [
                'label' => 'Shooter Weight',
                'expression' => "CASE
                    WHEN shooter_weight_lbs IS NULL THEN 'unknown'
                    WHEN shooter_weight_lbs < 180 THEN 'w_lt_180'
                    WHEN shooter_weight_lbs < 200 THEN 'w_180_199'
                    WHEN shooter_weight_lbs < 220 THEN 'w_200_219'
                    ELSE 'w_220_plus'
                END",
            ],
        ];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function aggregateDefinitions(): array
    {
        return $this->analysisBuckets()->aggregateDefinitions('nhl_shot_attempts_facts');
    }

    /**
     * @return array<int, string>
     */
    private function candidateBucketKeySql(): array
    {
        return $this->analysisBuckets()->candidateBucketKeySql('facts');
    }

    private function normalizePredictionTarget(string $predictionTarget): string
    {
        return $predictionTarget === self::TARGET_SHOT_ON_GOAL
            ? self::TARGET_SHOT_ON_GOAL
            : self::TARGET_GOAL;
    }

    private function outcomeColumn(string $predictionTarget): string
    {
        return $this->normalizePredictionTarget($predictionTarget) === self::TARGET_SHOT_ON_GOAL
            ? 'is_shot_on_goal'
            : 'is_goal';
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function fallbackDefinitions(): array
    {
        return $this->analysisBuckets()->fallbackDefinitions();
    }

    /**
     * @return array<string, mixed>
     */
    private function trainingFilters(string $sampleMode = self::SAMPLE_SAT): array
    {
        $filters = [
            'shot_type_bucket' => $sampleMode === self::SAMPLE_SOG ? 'exclude_unknown_and_other' : 'exclude_unknown',
            'is_empty_net' => false,
            'period_type' => 'not_shootout',
        ];

        if ($sampleMode === self::SAMPLE_SOG) {
            $filters['is_shot_on_goal'] = true;
        }

        return $filters;
    }

    /**
     * @param array<string, string> $dimensions
     */
    private function bucketKey(int $level, array $dimensions): string
    {
        return 'L' . str_pad((string) $level, 2, '0', STR_PAD_LEFT) . '|'
            . collect($dimensions)
                ->map(fn (string $value, string $key): string => $key . '=' . $value)
                ->implode('|');
    }

    private function value(mixed $value): string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? 'unknown' : $normalized;
    }

    private function confidenceBucket(float $confidenceScore): string
    {
        if ($confidenceScore >= 0.75) {
            return 'high';
        }

        if ($confidenceScore >= 0.4) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * @param array<int, string>|array<int, int> $seasonIds
     * @return array<int, string>
     */
    private function normalizeSeasonIds(array $seasonIds): array
    {
        return collect($seasonIds)
            ->map(fn (mixed $seasonId): string => trim((string) $seasonId))
            ->filter(fn (string $seasonId): bool => preg_match('/^\d{8}$/', $seasonId) === 1)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function versionForRun(NhlModelRun $run): string
    {
        return $run->model_version . '__run_' . $run->id;
    }

    private function analysisBuckets(): NhlShotAttemptAnalysisBuckets
    {
        return app(NhlShotAttemptAnalysisBuckets::class);
    }
}
