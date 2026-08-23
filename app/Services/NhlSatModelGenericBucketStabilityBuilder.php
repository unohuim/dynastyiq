<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NhlModelRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Builds generic SAT bucket stability rows from entity season snapshots.
 */
class NhlSatModelGenericBucketStabilityBuilder
{
    private const STABILITY_TABLE = 'nhl_sat_model_generic_bucket_stabilities';
    private const PROFILE_TABLE = 'nhl_sat_model_entity_profile_buckets';
    private const SNAPSHOT_TABLE = 'nhl_sat_model_entity_test_profile_buckets';

    /**
     * Build generic bucket stability rows for a SAT model run.
     *
     * @return array<string, int>
     */
    public function build(NhlModelRun $run): array
    {
        if (! Schema::hasTable(self::STABILITY_TABLE)) {
            throw new RuntimeException('Run migrations before building generic bucket stability.');
        }

        $seasonIds = $this->seasonIds($run);
        $testSeasonId = $this->testSeasonId($run);

        if (count($seasonIds) < 2) {
            return ['total' => 0];
        }

        $priorSeasonId = $seasonIds[count($seasonIds) - 2];
        $latestSeasonId = $seasonIds[count($seasonIds) - 1];

        DB::table(self::STABILITY_TABLE)
            ->where('model_run_id', $run->id)
            ->delete();

        $this->insertRows(
            run: $run,
            seasonIds: $seasonIds,
            priorSeasonId: $priorSeasonId,
            latestSeasonId: $latestSeasonId,
            testSeasonId: $testSeasonId
        );

        $counts = DB::table(self::STABILITY_TABLE)
            ->where('model_run_id', $run->id)
            ->selectRaw('profile_type, COUNT(*) as rows')
            ->groupBy('profile_type')
            ->pluck('rows', 'profile_type')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
        $counts['total'] = array_sum($counts);

        return $counts;
    }

    /**
     * @return array<int, string>
     */
    private function seasonIds(NhlModelRun $run): array
    {
        return collect($run->train_season_ids ?? [])
            ->map(fn (mixed $seasonId): string => trim((string) $seasonId))
            ->filter(fn (string $seasonId): bool => preg_match('/^\d{8}$/', $seasonId) === 1)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function testSeasonId(NhlModelRun $run): ?string
    {
        $seasonId = trim((string) ($run->target_season_id ?? ''));

        return preg_match('/^\d{8}$/', $seasonId) === 1 ? $seasonId : null;
    }

    /**
     * @param array<int, string> $seasonIds
     */
    private function insertRows(
        NhlModelRun $run,
        array $seasonIds,
        string $priorSeasonId,
        string $latestSeasonId,
        ?string $testSeasonId
    ): void {
        $now = now();
        $seasonJson = json_encode($seasonIds, JSON_THROW_ON_ERROR);

        $sql = <<<SQL
INSERT INTO nhl_sat_model_generic_bucket_stabilities (
    model_run_id,
    source_season_ids,
    prior_season_id,
    latest_season_id,
    test_season_id,
    game_type,
    profile_type,
    matched_bucket_key,
    fallback_level,
    bucket_dimensions,
    train_entity_count,
    prior_entity_count,
    latest_entity_count,
    test_entity_count,
    train_sat,
    prior_sat,
    latest_sat,
    test_sat,
    train_sog,
    prior_sog,
    latest_sog,
    test_sog,
    train_goals,
    prior_goals,
    latest_goals,
    test_goals,
    train_toi_seconds,
    prior_toi_seconds,
    latest_toi_seconds,
    test_toi_seconds,
    train_xsat_per_60,
    prior_xsat_per_60,
    latest_xsat_per_60,
    test_xsat_per_60,
    latest_minus_prior_xsat_per_60,
    test_minus_latest_xsat_per_60,
    test_minus_train_xsat_per_60,
    latest_minus_prior_xsat_rate,
    test_minus_latest_xsat_rate,
    test_minus_train_xsat_rate,
    latest_direction,
    test_direction,
    reversed_after_latest,
    metadata,
    calculated_at,
    created_at,
    updated_at
)
WITH training_rows AS (
    SELECT
        profiles.profile_type,
        profiles.matched_bucket_key,
        MAX(profiles.fallback_level) as fallback_level,
        MAX(profiles.bucket_dimensions::text)::json as bucket_dimensions,
        COUNT(DISTINCT profiles.entity_key) as entity_count,
        SUM(profiles.source_sat) as source_sat,
        SUM(profiles.source_sog) as source_sog,
        SUM(profiles.source_goals) as source_goals,
        SUM(COALESCE(profiles.source_toi_seconds, 0)) as toi_seconds
    FROM nhl_sat_model_entity_profile_buckets profiles
    WHERE profiles.model_run_id = ?
    GROUP BY profiles.profile_type, profiles.matched_bucket_key
),
snapshot_rows AS (
    SELECT
        snapshots.test_season_id,
        snapshots.profile_type,
        snapshots.matched_bucket_key,
        MAX(snapshots.fallback_level) as fallback_level,
        MAX(snapshots.bucket_dimensions::text)::json as bucket_dimensions,
        COUNT(DISTINCT snapshots.entity_key) as entity_count,
        SUM(snapshots.source_sat) as source_sat,
        SUM(snapshots.source_sog) as source_sog,
        SUM(snapshots.source_goals) as source_goals,
        SUM(COALESCE(snapshots.source_toi_seconds, 0)) as toi_seconds
    FROM nhl_sat_model_entity_test_profile_buckets snapshots
    WHERE snapshots.model_run_id = ?
        AND snapshots.test_season_id IN (?, ?, ?)
    GROUP BY snapshots.test_season_id, snapshots.profile_type, snapshots.matched_bucket_key
),
bucket_keys AS (
    SELECT training_rows.profile_type, training_rows.matched_bucket_key FROM training_rows
    UNION
    SELECT snapshot_rows.profile_type, snapshot_rows.matched_bucket_key FROM snapshot_rows
),
joined_rows AS (
    SELECT
        bucket_keys.profile_type,
        bucket_keys.matched_bucket_key,
        COALESCE(training_rows.fallback_level, prior_rows.fallback_level, latest_rows.fallback_level, test_rows.fallback_level) as fallback_level,
        COALESCE(training_rows.bucket_dimensions, prior_rows.bucket_dimensions, latest_rows.bucket_dimensions, test_rows.bucket_dimensions) as bucket_dimensions,
        COALESCE(training_rows.entity_count, 0) as train_entity_count,
        COALESCE(prior_rows.entity_count, 0) as prior_entity_count,
        COALESCE(latest_rows.entity_count, 0) as latest_entity_count,
        COALESCE(test_rows.entity_count, 0) as test_entity_count,
        COALESCE(training_rows.source_sat, 0) as train_sat,
        COALESCE(prior_rows.source_sat, 0) as prior_sat,
        COALESCE(latest_rows.source_sat, 0) as latest_sat,
        COALESCE(test_rows.source_sat, 0) as test_sat,
        COALESCE(training_rows.source_sog, 0) as train_sog,
        COALESCE(prior_rows.source_sog, 0) as prior_sog,
        COALESCE(latest_rows.source_sog, 0) as latest_sog,
        COALESCE(test_rows.source_sog, 0) as test_sog,
        COALESCE(training_rows.source_goals, 0) as train_goals,
        COALESCE(prior_rows.source_goals, 0) as prior_goals,
        COALESCE(latest_rows.source_goals, 0) as latest_goals,
        COALESCE(test_rows.source_goals, 0) as test_goals,
        NULLIF(training_rows.toi_seconds, 0) as train_toi_seconds,
        NULLIF(prior_rows.toi_seconds, 0) as prior_toi_seconds,
        NULLIF(latest_rows.toi_seconds, 0) as latest_toi_seconds,
        NULLIF(test_rows.toi_seconds, 0) as test_toi_seconds
    FROM bucket_keys
    LEFT JOIN training_rows
        ON training_rows.profile_type = bucket_keys.profile_type
        AND training_rows.matched_bucket_key = bucket_keys.matched_bucket_key
    LEFT JOIN snapshot_rows prior_rows
        ON prior_rows.profile_type = bucket_keys.profile_type
        AND prior_rows.matched_bucket_key = bucket_keys.matched_bucket_key
        AND prior_rows.test_season_id = ?
    LEFT JOIN snapshot_rows latest_rows
        ON latest_rows.profile_type = bucket_keys.profile_type
        AND latest_rows.matched_bucket_key = bucket_keys.matched_bucket_key
        AND latest_rows.test_season_id = ?
    LEFT JOIN snapshot_rows test_rows
        ON test_rows.profile_type = bucket_keys.profile_type
        AND test_rows.matched_bucket_key = bucket_keys.matched_bucket_key
        AND test_rows.test_season_id = ?
),
rate_rows AS (
    SELECT
        joined_rows.*,
        ROUND((joined_rows.train_sat::numeric * 3600 / NULLIF(joined_rows.train_toi_seconds, 0)), 4) as train_xsat_per_60,
        ROUND((joined_rows.prior_sat::numeric * 3600 / NULLIF(joined_rows.prior_toi_seconds, 0)), 4) as prior_xsat_per_60,
        ROUND((joined_rows.latest_sat::numeric * 3600 / NULLIF(joined_rows.latest_toi_seconds, 0)), 4) as latest_xsat_per_60,
        ROUND((joined_rows.test_sat::numeric * 3600 / NULLIF(joined_rows.test_toi_seconds, 0)), 4) as test_xsat_per_60
    FROM joined_rows
)
SELECT
    ?::bigint as model_run_id,
    ?::json as source_season_ids,
    ?::varchar as prior_season_id,
    ?::varchar as latest_season_id,
    ?::varchar as test_season_id,
    ?::integer as game_type,
    rate_rows.profile_type,
    rate_rows.matched_bucket_key,
    rate_rows.fallback_level,
    rate_rows.bucket_dimensions,
    rate_rows.train_entity_count,
    rate_rows.prior_entity_count,
    rate_rows.latest_entity_count,
    rate_rows.test_entity_count,
    rate_rows.train_sat,
    rate_rows.prior_sat,
    rate_rows.latest_sat,
    rate_rows.test_sat,
    rate_rows.train_sog,
    rate_rows.prior_sog,
    rate_rows.latest_sog,
    rate_rows.test_sog,
    rate_rows.train_goals,
    rate_rows.prior_goals,
    rate_rows.latest_goals,
    rate_rows.test_goals,
    rate_rows.train_toi_seconds,
    rate_rows.prior_toi_seconds,
    rate_rows.latest_toi_seconds,
    rate_rows.test_toi_seconds,
    rate_rows.train_xsat_per_60,
    rate_rows.prior_xsat_per_60,
    rate_rows.latest_xsat_per_60,
    rate_rows.test_xsat_per_60,
    ROUND((rate_rows.latest_xsat_per_60 - rate_rows.prior_xsat_per_60)::numeric, 4) as latest_minus_prior_xsat_per_60,
    ROUND((rate_rows.test_xsat_per_60 - rate_rows.latest_xsat_per_60)::numeric, 4) as test_minus_latest_xsat_per_60,
    ROUND((rate_rows.test_xsat_per_60 - rate_rows.train_xsat_per_60)::numeric, 4) as test_minus_train_xsat_per_60,
    ROUND(((rate_rows.latest_xsat_per_60 - rate_rows.prior_xsat_per_60) / NULLIF(ABS(rate_rows.prior_xsat_per_60), 0))::numeric, 6) as latest_minus_prior_xsat_rate,
    ROUND(((rate_rows.test_xsat_per_60 - rate_rows.latest_xsat_per_60) / NULLIF(ABS(rate_rows.latest_xsat_per_60), 0))::numeric, 6) as test_minus_latest_xsat_rate,
    ROUND(((rate_rows.test_xsat_per_60 - rate_rows.train_xsat_per_60) / NULLIF(ABS(rate_rows.train_xsat_per_60), 0))::numeric, 6) as test_minus_train_xsat_rate,
    CASE
        WHEN rate_rows.latest_xsat_per_60 > rate_rows.prior_xsat_per_60 THEN 'up'
        WHEN rate_rows.latest_xsat_per_60 < rate_rows.prior_xsat_per_60 THEN 'down'
        WHEN rate_rows.latest_xsat_per_60 IS NULL OR rate_rows.prior_xsat_per_60 IS NULL THEN NULL
        ELSE 'flat'
    END as latest_direction,
    CASE
        WHEN rate_rows.test_xsat_per_60 > rate_rows.latest_xsat_per_60 THEN 'up'
        WHEN rate_rows.test_xsat_per_60 < rate_rows.latest_xsat_per_60 THEN 'down'
        WHEN rate_rows.test_xsat_per_60 IS NULL OR rate_rows.latest_xsat_per_60 IS NULL THEN NULL
        ELSE 'flat'
    END as test_direction,
    CASE
        WHEN rate_rows.latest_xsat_per_60 > rate_rows.prior_xsat_per_60
            AND rate_rows.test_xsat_per_60 < rate_rows.latest_xsat_per_60 THEN true
        WHEN rate_rows.latest_xsat_per_60 < rate_rows.prior_xsat_per_60
            AND rate_rows.test_xsat_per_60 > rate_rows.latest_xsat_per_60 THEN true
        ELSE false
    END as reversed_after_latest,
    json_build_object('source', 'entity_season_snapshots', 'rate_denominator', 'bucket_entity_exposure') as metadata,
    ?::timestamp as calculated_at,
    ?::timestamp as created_at,
    ?::timestamp as updated_at
FROM rate_rows
WHERE rate_rows.bucket_dimensions IS NOT NULL
ON CONFLICT (model_run_id, profile_type, matched_bucket_key)
DO UPDATE SET
    source_season_ids = EXCLUDED.source_season_ids,
    prior_season_id = EXCLUDED.prior_season_id,
    latest_season_id = EXCLUDED.latest_season_id,
    test_season_id = EXCLUDED.test_season_id,
    game_type = EXCLUDED.game_type,
    fallback_level = EXCLUDED.fallback_level,
    bucket_dimensions = EXCLUDED.bucket_dimensions,
    train_entity_count = EXCLUDED.train_entity_count,
    prior_entity_count = EXCLUDED.prior_entity_count,
    latest_entity_count = EXCLUDED.latest_entity_count,
    test_entity_count = EXCLUDED.test_entity_count,
    train_sat = EXCLUDED.train_sat,
    prior_sat = EXCLUDED.prior_sat,
    latest_sat = EXCLUDED.latest_sat,
    test_sat = EXCLUDED.test_sat,
    train_sog = EXCLUDED.train_sog,
    prior_sog = EXCLUDED.prior_sog,
    latest_sog = EXCLUDED.latest_sog,
    test_sog = EXCLUDED.test_sog,
    train_goals = EXCLUDED.train_goals,
    prior_goals = EXCLUDED.prior_goals,
    latest_goals = EXCLUDED.latest_goals,
    test_goals = EXCLUDED.test_goals,
    train_toi_seconds = EXCLUDED.train_toi_seconds,
    prior_toi_seconds = EXCLUDED.prior_toi_seconds,
    latest_toi_seconds = EXCLUDED.latest_toi_seconds,
    test_toi_seconds = EXCLUDED.test_toi_seconds,
    train_xsat_per_60 = EXCLUDED.train_xsat_per_60,
    prior_xsat_per_60 = EXCLUDED.prior_xsat_per_60,
    latest_xsat_per_60 = EXCLUDED.latest_xsat_per_60,
    test_xsat_per_60 = EXCLUDED.test_xsat_per_60,
    latest_minus_prior_xsat_per_60 = EXCLUDED.latest_minus_prior_xsat_per_60,
    test_minus_latest_xsat_per_60 = EXCLUDED.test_minus_latest_xsat_per_60,
    test_minus_train_xsat_per_60 = EXCLUDED.test_minus_train_xsat_per_60,
    latest_minus_prior_xsat_rate = EXCLUDED.latest_minus_prior_xsat_rate,
    test_minus_latest_xsat_rate = EXCLUDED.test_minus_latest_xsat_rate,
    test_minus_train_xsat_rate = EXCLUDED.test_minus_train_xsat_rate,
    latest_direction = EXCLUDED.latest_direction,
    test_direction = EXCLUDED.test_direction,
    reversed_after_latest = EXCLUDED.reversed_after_latest,
    metadata = EXCLUDED.metadata,
    calculated_at = EXCLUDED.calculated_at,
    updated_at = EXCLUDED.updated_at
SQL;

        DB::statement($sql, [
            $run->id,
            $run->id,
            $priorSeasonId,
            $latestSeasonId,
            $testSeasonId ?? $latestSeasonId,
            $priorSeasonId,
            $latestSeasonId,
            $testSeasonId ?? $latestSeasonId,
            $run->id,
            $seasonJson,
            $priorSeasonId,
            $latestSeasonId,
            $testSeasonId,
            (int) ($run->game_type ?? 2),
            $now,
            $now,
            $now,
        ]);
    }
}
