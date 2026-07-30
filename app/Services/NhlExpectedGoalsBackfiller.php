<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NhlExpectedGoalsModel;
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
        $version = $version ?: self::DEFAULT_VERSION;
        $predictionTarget = $this->normalizePredictionTarget($predictionTarget);
        $trainedAt = now();
        $bucketStats = $this->trainingBucketStats($seasonId, $predictionTarget);
        $baseline = $bucketStats[$this->bucketKey(99, ['baseline' => 'league'])] ?? ['attempts' => 0, 'successes' => 0];
        $baselineRate = $baseline['attempts'] > 0 ? $baseline['successes'] / $baseline['attempts'] : 0.0;
        $buckets = $this->eligibleBuckets($bucketStats, $minimumBucketAttempts, $smoothingPriorAttempts, $baselineRate);
        $bucketCount = count($buckets);

        if ($dryRun) {
            return [
                'model_version' => $version,
                'prediction_target' => $predictionTarget,
                'training_season_id' => $seasonId,
                'training_attempts' => $baseline['attempts'],
                'training_successes' => $baseline['successes'],
                'baseline_success_rate' => round($baselineRate, 6),
                'bucket_count' => $bucketCount,
                'predictions_upserted' => 0,
                'excluded_predictions' => 0,
                'dry_run' => 1,
            ];
        }

        $model = NhlExpectedGoalsModel::query()->updateOrCreate(
            ['name' => self::MODEL_NAME, 'version' => $version, 'prediction_target' => $predictionTarget],
            [
                'model_type' => self::MODEL_TYPE,
                'training_season_id' => $seasonId,
                'minimum_bucket_attempts' => $minimumBucketAttempts,
                'smoothing_prior_attempts' => $smoothingPriorAttempts,
                'training_filters' => $this->trainingFilters(),
                'feature_config' => [
                    'fallback_levels' => $this->fallbackDefinitions(),
                    'excluded_training_values' => [
                        'shot_type_bucket' => ['unknown'],
                        'period_type' => ['SO'],
                        'is_empty_net' => [true],
                    ],
                    'prediction_target' => $predictionTarget,
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

        $predictionCounts = $this->backfillPredictions((int) $model->id, $seasonId, $predictionTarget);

        $model->forceFill([
            'metrics' => [
                'training_attempts' => $baseline['attempts'],
                'training_successes' => $baseline['successes'],
                'baseline_success_rate' => round($baselineRate, 6),
                'bucket_count' => $bucketCount,
                'predictions_upserted' => $predictionCounts['upserted'],
                'excluded_predictions' => $predictionCounts['excluded'],
            ],
            'status' => 'draft',
            'trained_at' => $trainedAt,
        ])->save();

        return [
            'model_id' => (int) $model->id,
            'model_version' => $version,
            'prediction_target' => $predictionTarget,
            'training_season_id' => $seasonId,
            'training_attempts' => $baseline['attempts'],
            'training_successes' => $baseline['successes'],
            'baseline_success_rate' => round($baselineRate, 6),
            'bucket_count' => $bucketCount,
            'predictions_upserted' => $predictionCounts['upserted'],
            'excluded_predictions' => $predictionCounts['excluded'],
            'dry_run' => 0,
        ];
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
     * @return array<string, array{attempts:int,successes:int,level:int,dimensions:array<string,string>}>
     */
    private function trainingBucketStats(string $seasonId, string $predictionTarget): array
    {
        $stats = [];

        foreach ($this->aggregateDefinitions() as $level => $definition) {
            foreach ($this->aggregateBucketStats($seasonId, $predictionTarget, $level, $definition) as $bucket) {
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
    private function aggregateBucketStats(string $seasonId, string $predictionTarget, int $level, array $definition): array
    {
        $outcomeColumn = $this->outcomeColumn($predictionTarget);

        if ($level === 99) {
            $row = $this->eligibleTrainingBaseQuery($seasonId)
                ->selectRaw('COUNT(*) as attempts')
                ->selectRaw('SUM(CASE WHEN ' . $outcomeColumn . ' THEN 1 ELSE 0 END) as successes')
                ->first();

            return [[
                'attempts' => (int) ($row->attempts ?? 0),
                'successes' => (int) ($row->successes ?? 0),
                'dimensions' => ['baseline' => 'league'],
            ]];
        }

        $query = $this->eligibleTrainingBaseQuery($seasonId)
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
     * @return array<string, array{attempts:int,successes:int,level:int,dimensions:array<string,string>,raw_success_rate:float,smoothed_probability:float}>
     */
    private function eligibleBuckets(
        array $bucketStats,
        int $minimumBucketAttempts,
        int $smoothingPriorAttempts,
        float $baselineRate
    ): array {
        $buckets = [];

        foreach ($bucketStats as $key => $bucket) {
            if ($bucket['level'] !== 99 && $bucket['attempts'] < $minimumBucketAttempts) {
                continue;
            }

            $rawRate = $bucket['attempts'] > 0 ? $bucket['successes'] / $bucket['attempts'] : 0.0;
            $smoothed = ($bucket['successes'] + ($baselineRate * $smoothingPriorAttempts))
                / max(1, $bucket['attempts'] + $smoothingPriorAttempts);

            $buckets[$key] = [
                ...$bucket,
                'raw_success_rate' => $rawRate,
                'smoothed_probability' => $smoothed,
            ];
        }

        return $buckets;
    }

    /**
     * @param array<string, array{attempts:int,successes:int,level:int,dimensions:array<string,string>,raw_success_rate:float,smoothed_probability:float}> $buckets
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
    private function backfillPredictions(int $modelId, string $seasonId, string $predictionTarget): array
    {
        DB::table('nhl_shot_attempt_predictions')
            ->where('expected_goals_model_id', $modelId)
            ->where('prediction_target', $predictionTarget)
            ->where('season_id', $seasonId)
            ->delete();

        $exclusionReason = $this->exclusionReasonSql();
        $candidateKeys = implode(",\n                    ", $this->candidateBucketKeySql());

        DB::statement(<<<SQL
INSERT INTO nhl_shot_attempt_predictions (
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
SQL, [$modelId, $predictionTarget, $modelId, $seasonId]);

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

    private function eligibleTrainingBaseQuery(string $seasonId)
    {
        return DB::table('nhl_shot_attempts_facts')
            ->where('season_id', $seasonId)
            ->where('shot_type_bucket', '<>', 'unknown')
            ->where(function ($query): void {
                $query->whereNull('period_type')
                    ->orWhere('period_type', '<>', 'SO');
            })
            ->where(function ($query): void {
                $query->whereNull('is_empty_net')
                    ->orWhere('is_empty_net', false);
            });
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function aggregateDefinitions(): array
    {
        $offWingBucket = "CASE
            WHEN is_off_wing_attempt THEN 'off_wing'
            WHEN is_off_wing_attempt = false THEN 'strong_side'
            ELSE 'center_or_unknown'
        END";

        return [
            1 => [
                'distance_bucket' => "COALESCE(NULLIF(distance_bucket, ''), 'unknown')",
                'shot_type_bucket' => "COALESCE(NULLIF(shot_type_bucket, ''), 'unknown')",
                'rebound_bucket' => "COALESCE(NULLIF(rebound_bucket, ''), 'unknown')",
                'rush_bucket' => "COALESCE(NULLIF(rush_bucket, ''), 'unknown')",
                'strength_bucket' => "COALESCE(NULLIF(strength_bucket, ''), 'unknown')",
                'shot_side' => "COALESCE(NULLIF(shot_side, ''), 'unknown')",
                'off_wing' => $offWingBucket,
            ],
            2 => [
                'distance_bucket' => "COALESCE(NULLIF(distance_bucket, ''), 'unknown')",
                'shot_type_bucket' => "COALESCE(NULLIF(shot_type_bucket, ''), 'unknown')",
                'rebound_bucket' => "COALESCE(NULLIF(rebound_bucket, ''), 'unknown')",
                'strength_bucket' => "COALESCE(NULLIF(strength_bucket, ''), 'unknown')",
                'shot_side' => "COALESCE(NULLIF(shot_side, ''), 'unknown')",
            ],
            3 => [
                'distance_bucket' => "COALESCE(NULLIF(distance_bucket, ''), 'unknown')",
                'shot_type_bucket' => "COALESCE(NULLIF(shot_type_bucket, ''), 'unknown')",
                'strength_bucket' => "COALESCE(NULLIF(strength_bucket, ''), 'unknown')",
            ],
            4 => [
                'distance_bucket' => "COALESCE(NULLIF(distance_bucket, ''), 'unknown')",
                'shot_type_bucket' => "COALESCE(NULLIF(shot_type_bucket, ''), 'unknown')",
            ],
            5 => [
                'distance_bucket' => "COALESCE(NULLIF(distance_bucket, ''), 'unknown')",
            ],
            99 => [
                'baseline' => "'league'",
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function candidateBucketKeySql(): array
    {
        $distance = $this->factValueSql('distance_bucket');
        $shotType = $this->factValueSql('shot_type_bucket');
        $rebound = $this->factValueSql('rebound_bucket');
        $rush = $this->factValueSql('rush_bucket');
        $strength = $this->factValueSql('strength_bucket');
        $shotSide = $this->factValueSql('shot_side');
        $offWing = "CASE
            WHEN facts.is_off_wing_attempt THEN 'off_wing'
            WHEN facts.is_off_wing_attempt = false THEN 'strong_side'
            ELSE 'center_or_unknown'
        END";

        return [
            "(1, 'L01|distance_bucket=' || {$distance} || '|shot_type_bucket=' || {$shotType} || '|rebound_bucket=' || {$rebound} || '|rush_bucket=' || {$rush} || '|strength_bucket=' || {$strength} || '|shot_side=' || {$shotSide} || '|off_wing=' || {$offWing})",
            "(2, 'L02|distance_bucket=' || {$distance} || '|shot_type_bucket=' || {$shotType} || '|rebound_bucket=' || {$rebound} || '|strength_bucket=' || {$strength} || '|shot_side=' || {$shotSide})",
            "(3, 'L03|distance_bucket=' || {$distance} || '|shot_type_bucket=' || {$shotType} || '|strength_bucket=' || {$strength})",
            "(4, 'L04|distance_bucket=' || {$distance} || '|shot_type_bucket=' || {$shotType})",
            "(5, 'L05|distance_bucket=' || {$distance})",
            "(99, 'L99|baseline=league')",
        ];
    }

    private function factValueSql(string $column): string
    {
        return "COALESCE(NULLIF(facts.{$column}, ''), 'unknown')";
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
        return [
            1 => ['distance_bucket', 'shot_type_bucket', 'rebound_bucket', 'rush_bucket', 'strength_bucket', 'shot_side', 'off_wing'],
            2 => ['distance_bucket', 'shot_type_bucket', 'rebound_bucket', 'strength_bucket', 'shot_side'],
            3 => ['distance_bucket', 'shot_type_bucket', 'strength_bucket'],
            4 => ['distance_bucket', 'shot_type_bucket'],
            5 => ['distance_bucket'],
            99 => ['baseline'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function trainingFilters(): array
    {
        return [
            'shot_type_bucket' => 'exclude_unknown',
            'is_empty_net' => false,
            'period_type' => 'not_shootout',
        ];
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
}
