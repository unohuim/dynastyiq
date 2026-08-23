<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NhlModelRun;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Builds held-out test-season comparisons for entity /60 projections.
 */
class NhlSatModelEntityRateComparisonBuilder
{
    private const OTHER_BUCKET_KEY = 'L99|other=low_volume';

    /**
     * Clear comparison rows and list projected entities to compare.
     *
     * @return array<int, array{profile_type:string,entity_key:string}>
     */
    public function prepareBuild(NhlModelRun $run): array
    {
        if ($run->target_season_id === null) {
            throw new RuntimeException('Choose a test season before comparing /60.');
        }

        if (! DB::table('nhl_sat_model_entity_rate_projection_buckets')->where('model_run_id', $run->id)->exists()) {
            throw new RuntimeException('Build /60 before comparing /60.');
        }

        if (! DB::table('nhl_sat_model_entity_test_profile_buckets')
            ->where('model_run_id', $run->id)
            ->where('test_season_id', (string) $run->target_season_id)
            ->exists()
        ) {
            throw new RuntimeException('Build test profiles before comparing /60.');
        }

        DB::table('nhl_sat_model_entity_rate_comparison_buckets')
            ->where('model_run_id', $run->id)
            ->where('test_season_id', (string) $run->target_season_id)
            ->delete();
        DB::table('nhl_sat_model_entity_rate_comparison_aggregates')
            ->where('model_run_id', $run->id)
            ->where('test_season_id', (string) $run->target_season_id)
            ->delete();

        return DB::table('nhl_sat_model_entity_rate_projection_buckets')
            ->where('model_run_id', $run->id)
            ->select(['profile_type', 'entity_key'])
            ->distinct()
            ->orderBy('profile_type')
            ->orderBy('entity_key')
            ->get()
            ->map(fn (object $row): array => [
                'profile_type' => (string) $row->profile_type,
                'entity_key' => (string) $row->entity_key,
            ])
            ->all();
    }

    /**
     * Build comparison rows for one projected entity.
     */
    public function buildEntity(NhlModelRun $run, string $profileType, string $entityKey): int
    {
        if ($run->target_season_id === null) {
            throw new RuntimeException('Choose a test season before comparing /60.');
        }

        $this->insertRawRows($run, $profileType, $entityKey);
        $this->insertAggregateRow($run, $profileType, $entityKey);

        return DB::table('nhl_sat_model_entity_rate_comparison_buckets')
            ->where('model_run_id', $run->id)
            ->where('test_season_id', (string) $run->target_season_id)
            ->where('profile_type', $profileType)
            ->where('entity_key', $entityKey)
            ->count();
    }

    private function insertRawRows(NhlModelRun $run, string $profileType, string $entityKey): void
    {
        $now = now();
        $sql = <<<SQL
INSERT INTO nhl_sat_model_entity_rate_comparison_buckets (
    model_run_id,
    test_season_id,
    profile_type,
    entity_key,
    entity_id,
    entity_name,
    entity_role,
    team_context,
    matched_bucket_key,
    bucket_dimensions,
    is_other_bucket,
    train_sat,
    train_sog,
    train_goals,
    test_sat,
    test_sog,
    test_goals,
    train_profile_share,
    test_profile_share,
    share_drift,
    share_drift_rate,
    train_xsat_per_60,
    projected_xsat_per_60,
    test_xsat_per_60,
    xsat_drift,
    xsat_drift_rate,
    xsat_error,
    xsat_error_rate,
    train_xsog_per_60,
    projected_xsog_per_60,
    test_xsog_per_60,
    xsog_drift,
    xsog_drift_rate,
    xsog_error,
    xsog_error_rate,
    train_xg_per_60,
    projected_xg_per_60,
    test_xg_per_60,
    xg_drift,
    xg_drift_rate,
    xg_error,
    xg_error_rate,
    confidence_score,
    shrinkage_weight,
    metadata,
    compared_at,
    created_at,
    updated_at
)
WITH projections AS (
    SELECT *
    FROM nhl_sat_model_entity_rate_projection_buckets
    WHERE model_run_id = ?
        AND profile_type = ?
        AND entity_key = ?
),
core_projection_keys AS (
    SELECT matched_bucket_key
    FROM projections
    WHERE is_other_bucket = false
),
test_exact AS (
    SELECT
        test_profiles.matched_bucket_key,
        SUM(test_profiles.source_sat) as test_sat,
        SUM(test_profiles.source_sog) as test_sog,
        SUM(test_profiles.source_goals) as test_goals,
        SUM(test_profiles.source_profile_share) as test_profile_share,
        SUM(COALESCE(test_profiles.source_xsat_per_60, 0)) as test_xsat_per_60,
        SUM(COALESCE(test_profiles.source_xsog_per_60, 0)) as test_xsog_per_60,
        SUM(COALESCE(test_profiles.source_xg_per_60, 0)) as test_xg_per_60
    FROM nhl_sat_model_entity_test_profile_buckets test_profiles
    INNER JOIN core_projection_keys ON core_projection_keys.matched_bucket_key = test_profiles.matched_bucket_key
    WHERE test_profiles.model_run_id = ?
        AND test_profiles.test_season_id = ?
        AND test_profiles.profile_type = ?
        AND test_profiles.entity_key = ?
    GROUP BY test_profiles.matched_bucket_key
),
test_other AS (
    SELECT
        ?::varchar as matched_bucket_key,
        SUM(test_profiles.source_sat) as test_sat,
        SUM(test_profiles.source_sog) as test_sog,
        SUM(test_profiles.source_goals) as test_goals,
        SUM(test_profiles.source_profile_share) as test_profile_share,
        SUM(COALESCE(test_profiles.source_xsat_per_60, 0)) as test_xsat_per_60,
        SUM(COALESCE(test_profiles.source_xsog_per_60, 0)) as test_xsog_per_60,
        SUM(COALESCE(test_profiles.source_xg_per_60, 0)) as test_xg_per_60
    FROM nhl_sat_model_entity_test_profile_buckets test_profiles
    WHERE test_profiles.model_run_id = ?
        AND test_profiles.test_season_id = ?
        AND test_profiles.profile_type = ?
        AND test_profiles.entity_key = ?
        AND NOT EXISTS (
            SELECT 1
            FROM core_projection_keys
            WHERE core_projection_keys.matched_bucket_key = test_profiles.matched_bucket_key
        )
),
test_rows AS (
    SELECT * FROM test_exact
    UNION ALL
    SELECT * FROM test_other WHERE test_sat IS NOT NULL
),
comparison_rows AS (
    SELECT
        projections.*,
        test_rows.test_sat,
        test_rows.test_sog,
        test_rows.test_goals,
        test_rows.test_profile_share,
        test_rows.test_xsat_per_60,
        test_rows.test_xsog_per_60,
        test_rows.test_xg_per_60
    FROM projections
    LEFT JOIN test_rows ON test_rows.matched_bucket_key = projections.matched_bucket_key
)
SELECT
    comparison_rows.model_run_id,
    ?::varchar as test_season_id,
    comparison_rows.profile_type,
    comparison_rows.entity_key,
    comparison_rows.entity_id,
    comparison_rows.entity_name,
    comparison_rows.entity_role,
    comparison_rows.team_context,
    comparison_rows.matched_bucket_key,
    comparison_rows.bucket_dimensions,
    comparison_rows.is_other_bucket,
    comparison_rows.source_sat as train_sat,
    comparison_rows.source_sog as train_sog,
    comparison_rows.source_goals as train_goals,
    COALESCE(comparison_rows.test_sat, 0) as test_sat,
    COALESCE(comparison_rows.test_sog, 0) as test_sog,
    COALESCE(comparison_rows.test_goals, 0) as test_goals,
    comparison_rows.source_profile_share as train_profile_share,
    comparison_rows.test_profile_share,
    ROUND((comparison_rows.test_profile_share - comparison_rows.source_profile_share)::numeric, 6) as share_drift,
    ROUND(((comparison_rows.test_profile_share - comparison_rows.source_profile_share) / NULLIF(ABS(comparison_rows.source_profile_share), 0))::numeric, 6) as share_drift_rate,
    comparison_rows.source_xsat_per_60 as train_xsat_per_60,
    comparison_rows.projected_xsat_per_60,
    comparison_rows.test_xsat_per_60,
    ROUND((comparison_rows.test_xsat_per_60 - comparison_rows.source_xsat_per_60)::numeric, 4) as xsat_drift,
    ROUND(((comparison_rows.test_xsat_per_60 - comparison_rows.source_xsat_per_60) / NULLIF(ABS(comparison_rows.source_xsat_per_60), 0))::numeric, 6) as xsat_drift_rate,
    ROUND((comparison_rows.test_xsat_per_60 - comparison_rows.projected_xsat_per_60)::numeric, 4) as xsat_error,
    ROUND(((comparison_rows.test_xsat_per_60 - comparison_rows.projected_xsat_per_60) / NULLIF(ABS(comparison_rows.projected_xsat_per_60), 0))::numeric, 6) as xsat_error_rate,
    comparison_rows.source_xsog_per_60 as train_xsog_per_60,
    comparison_rows.projected_xsog_per_60,
    comparison_rows.test_xsog_per_60,
    ROUND((comparison_rows.test_xsog_per_60 - comparison_rows.source_xsog_per_60)::numeric, 4) as xsog_drift,
    ROUND(((comparison_rows.test_xsog_per_60 - comparison_rows.source_xsog_per_60) / NULLIF(ABS(comparison_rows.source_xsog_per_60), 0))::numeric, 6) as xsog_drift_rate,
    ROUND((comparison_rows.test_xsog_per_60 - comparison_rows.projected_xsog_per_60)::numeric, 4) as xsog_error,
    ROUND(((comparison_rows.test_xsog_per_60 - comparison_rows.projected_xsog_per_60) / NULLIF(ABS(comparison_rows.projected_xsog_per_60), 0))::numeric, 6) as xsog_error_rate,
    comparison_rows.source_xg_per_60 as train_xg_per_60,
    comparison_rows.projected_xg_per_60,
    comparison_rows.test_xg_per_60,
    ROUND((comparison_rows.test_xg_per_60 - comparison_rows.source_xg_per_60)::numeric, 4) as xg_drift,
    ROUND(((comparison_rows.test_xg_per_60 - comparison_rows.source_xg_per_60) / NULLIF(ABS(comparison_rows.source_xg_per_60), 0))::numeric, 6) as xg_drift_rate,
    ROUND((comparison_rows.test_xg_per_60 - comparison_rows.projected_xg_per_60)::numeric, 4) as xg_error,
    ROUND(((comparison_rows.test_xg_per_60 - comparison_rows.projected_xg_per_60) / NULLIF(ABS(comparison_rows.projected_xg_per_60), 0))::numeric, 6) as xg_error_rate,
    comparison_rows.confidence_score,
    comparison_rows.shrinkage_weight,
    json_build_object('source', 'rate_projection_and_test_profile_buckets', 'test_other_grouped', comparison_rows.is_other_bucket) as metadata,
    ?::timestamp as compared_at,
    ?::timestamp as created_at,
    ?::timestamp as updated_at
FROM comparison_rows
ON CONFLICT (model_run_id, test_season_id, profile_type, entity_key, matched_bucket_key)
DO UPDATE SET
    entity_id = EXCLUDED.entity_id,
    entity_name = EXCLUDED.entity_name,
    entity_role = EXCLUDED.entity_role,
    team_context = EXCLUDED.team_context,
    bucket_dimensions = EXCLUDED.bucket_dimensions,
    is_other_bucket = EXCLUDED.is_other_bucket,
    train_sat = EXCLUDED.train_sat,
    train_sog = EXCLUDED.train_sog,
    train_goals = EXCLUDED.train_goals,
    test_sat = EXCLUDED.test_sat,
    test_sog = EXCLUDED.test_sog,
    test_goals = EXCLUDED.test_goals,
    train_profile_share = EXCLUDED.train_profile_share,
    test_profile_share = EXCLUDED.test_profile_share,
    share_drift = EXCLUDED.share_drift,
    share_drift_rate = EXCLUDED.share_drift_rate,
    train_xsat_per_60 = EXCLUDED.train_xsat_per_60,
    projected_xsat_per_60 = EXCLUDED.projected_xsat_per_60,
    test_xsat_per_60 = EXCLUDED.test_xsat_per_60,
    xsat_drift = EXCLUDED.xsat_drift,
    xsat_drift_rate = EXCLUDED.xsat_drift_rate,
    xsat_error = EXCLUDED.xsat_error,
    xsat_error_rate = EXCLUDED.xsat_error_rate,
    train_xsog_per_60 = EXCLUDED.train_xsog_per_60,
    projected_xsog_per_60 = EXCLUDED.projected_xsog_per_60,
    test_xsog_per_60 = EXCLUDED.test_xsog_per_60,
    xsog_drift = EXCLUDED.xsog_drift,
    xsog_drift_rate = EXCLUDED.xsog_drift_rate,
    xsog_error = EXCLUDED.xsog_error,
    xsog_error_rate = EXCLUDED.xsog_error_rate,
    train_xg_per_60 = EXCLUDED.train_xg_per_60,
    projected_xg_per_60 = EXCLUDED.projected_xg_per_60,
    test_xg_per_60 = EXCLUDED.test_xg_per_60,
    xg_drift = EXCLUDED.xg_drift,
    xg_drift_rate = EXCLUDED.xg_drift_rate,
    xg_error = EXCLUDED.xg_error,
    xg_error_rate = EXCLUDED.xg_error_rate,
    confidence_score = EXCLUDED.confidence_score,
    shrinkage_weight = EXCLUDED.shrinkage_weight,
    metadata = EXCLUDED.metadata,
    compared_at = EXCLUDED.compared_at,
    updated_at = EXCLUDED.updated_at
SQL;

        DB::statement($sql, [
            $run->id,
            $profileType,
            $entityKey,
            $run->id,
            (string) $run->target_season_id,
            $profileType,
            $entityKey,
            self::OTHER_BUCKET_KEY,
            $run->id,
            (string) $run->target_season_id,
            $profileType,
            $entityKey,
            (string) $run->target_season_id,
            $now,
            $now,
            $now,
        ]);
    }

    private function insertAggregateRow(NhlModelRun $run, string $profileType, string $entityKey): void
    {
        $now = now();
        $definition = app(NhlSatModelEntityProfileBuilder::class)->profileDefinitions()[$profileType] ?? null;

        if ($definition === null) {
            throw new RuntimeException('Unknown profile type for /60 comparison.');
        }

        $trainSeasonIds = array_values(array_map('strval', $run->train_season_ids ?? []));

        if ($trainSeasonIds === []) {
            throw new RuntimeException('This SAT model has no training seasons.');
        }

        $gameType = (int) ($run->game_type ?? 2);
        $trainSeasonPlaceholders = implode(', ', array_fill(0, count($trainSeasonIds), '?'));
        $latestTrainingSeasonId = max($trainSeasonIds);
        $testSeasonId = (string) $run->target_season_id;
        $sql = <<<SQL
INSERT INTO nhl_sat_model_entity_rate_comparison_aggregates (
    model_run_id,
    test_season_id,
    profile_type,
    entity_key,
    entity_id,
    entity_name,
    entity_role,
    team_context,
    bucket_rows,
    matched_bucket_rows,
    train_games,
    test_games,
    train_active_bucket_count,
    last_active_bucket_count,
    test_active_bucket_count,
    train_top_3_bucket_share,
    last_top_3_bucket_share,
    test_top_3_bucket_share,
    train_other_share,
    last_other_share,
    test_other_share,
    train_bucket_entropy,
    last_bucket_entropy,
    test_bucket_entropy,
    train_sat,
    train_sog,
    train_goals,
    test_sat,
    test_sog,
    test_goals,
    train_profile_share,
    test_profile_share,
    share_drift,
    share_drift_rate,
    train_xsat_per_60,
    projected_xsat_per_60,
    test_xsat_per_60,
    xsat_drift,
    xsat_drift_rate,
    xsat_error,
    xsat_error_rate,
    train_xsog_per_60,
    projected_xsog_per_60,
    test_xsog_per_60,
    xsog_drift,
    xsog_drift_rate,
    xsog_error,
    xsog_error_rate,
    train_xg_per_60,
    projected_xg_per_60,
    test_xg_per_60,
    xg_drift,
    xg_drift_rate,
    xg_error,
    xg_error_rate,
    metadata,
    compared_at,
    created_at,
    updated_at
)
WITH raw_rows AS (
    SELECT *
    FROM nhl_sat_model_entity_rate_comparison_buckets
    WHERE model_run_id = ?
        AND test_season_id = ?
        AND profile_type = ?
        AND entity_key = ?
),
core_bucket_keys AS (
    SELECT matched_bucket_key
    FROM raw_rows
    WHERE is_other_bucket = false
),
latest_exact_rows AS (
    SELECT
        latest_profiles.matched_bucket_key,
        SUM(latest_profiles.source_sat) as source_sat
    FROM nhl_sat_model_entity_test_profile_buckets latest_profiles
    INNER JOIN core_bucket_keys ON core_bucket_keys.matched_bucket_key = latest_profiles.matched_bucket_key
    WHERE latest_profiles.model_run_id = ?
        AND latest_profiles.test_season_id = ?
        AND latest_profiles.profile_type = ?
        AND latest_profiles.entity_key = ?
    GROUP BY latest_profiles.matched_bucket_key
),
latest_other_rows AS (
    SELECT
        ?::varchar as matched_bucket_key,
        SUM(latest_profiles.source_sat) as source_sat
    FROM nhl_sat_model_entity_test_profile_buckets latest_profiles
    WHERE latest_profiles.model_run_id = ?
        AND latest_profiles.test_season_id = ?
        AND latest_profiles.profile_type = ?
        AND latest_profiles.entity_key = ?
        AND NOT EXISTS (
            SELECT 1
            FROM core_bucket_keys
            WHERE core_bucket_keys.matched_bucket_key = latest_profiles.matched_bucket_key
        )
),
latest_rows AS (
    SELECT * FROM latest_exact_rows
    UNION ALL
    SELECT * FROM latest_other_rows WHERE source_sat IS NOT NULL
),
train_variety_source AS (
    SELECT
        matched_bucket_key,
        train_sat as source_sat,
        train_sat::numeric / NULLIF(SUM(train_sat) OVER (), 0) as bucket_share
    FROM raw_rows
    WHERE train_sat > 0
),
latest_variety_source AS (
    SELECT
        matched_bucket_key,
        source_sat,
        source_sat::numeric / NULLIF(SUM(source_sat) OVER (), 0) as bucket_share
    FROM latest_rows
    WHERE source_sat > 0
),
test_variety_source AS (
    SELECT
        matched_bucket_key,
        test_sat as source_sat,
        test_sat::numeric / NULLIF(SUM(test_sat) OVER (), 0) as bucket_share
    FROM raw_rows
    WHERE test_sat > 0
),
train_variety_ranked AS (
    SELECT *, ROW_NUMBER() OVER (ORDER BY source_sat DESC, matched_bucket_key) as bucket_rank
    FROM train_variety_source
),
latest_variety_ranked AS (
    SELECT *, ROW_NUMBER() OVER (ORDER BY source_sat DESC, matched_bucket_key) as bucket_rank
    FROM latest_variety_source
),
test_variety_ranked AS (
    SELECT *, ROW_NUMBER() OVER (ORDER BY source_sat DESC, matched_bucket_key) as bucket_rank
    FROM test_variety_source
),
train_variety AS (
    SELECT
        COUNT(*) as active_bucket_count,
        SUM(bucket_share) FILTER (WHERE bucket_rank <= 3) as top_3_bucket_share,
        COALESCE(SUM(bucket_share) FILTER (WHERE matched_bucket_key = ?), 0) as other_share,
        -SUM(bucket_share * LN(bucket_share)) as bucket_entropy
    FROM train_variety_ranked
),
latest_variety AS (
    SELECT
        COUNT(*) as active_bucket_count,
        SUM(bucket_share) FILTER (WHERE bucket_rank <= 3) as top_3_bucket_share,
        COALESCE(SUM(bucket_share) FILTER (WHERE matched_bucket_key = ?), 0) as other_share,
        -SUM(bucket_share * LN(bucket_share)) as bucket_entropy
    FROM latest_variety_ranked
),
test_variety AS (
    SELECT
        COUNT(*) as active_bucket_count,
        SUM(bucket_share) FILTER (WHERE bucket_rank <= 3) as top_3_bucket_share,
        COALESCE(SUM(bucket_share) FILTER (WHERE matched_bucket_key = ?), 0) as other_share,
        -SUM(bucket_share * LN(bucket_share)) as bucket_entropy
    FROM test_variety_ranked
),
train_games AS (
    SELECT COUNT(DISTINCT facts.nhl_game_id) as games
    FROM nhl_shot_attempts_facts facts
    INNER JOIN nhl_games games ON games.nhl_game_id = facts.nhl_game_id
    {$definition['joins']}
    WHERE facts.season_id IN ({$trainSeasonPlaceholders})
        AND games.game_type = ?
        AND COALESCE(facts.period_type, '') <> 'SO'
        AND COALESCE(facts.is_empty_net, false) = false
        AND COALESCE(NULLIF(facts.shot_type_bucket, ''), 'unknown') <> 'unknown'
        AND {$definition['where']}
        AND {$definition['entity_key']} = ?
),
test_games AS (
    SELECT COUNT(DISTINCT facts.nhl_game_id) as games
    FROM nhl_shot_attempts_facts facts
    INNER JOIN nhl_games games ON games.nhl_game_id = facts.nhl_game_id
    {$definition['joins']}
    WHERE facts.season_id = ?
        AND games.game_type = ?
        AND COALESCE(facts.period_type, '') <> 'SO'
        AND COALESCE(facts.is_empty_net, false) = false
        AND COALESCE(NULLIF(facts.shot_type_bucket, ''), 'unknown') <> 'unknown'
        AND {$definition['where']}
        AND {$definition['entity_key']} = ?
),
aggregate_rows AS (
    SELECT
        MAX(model_run_id) as model_run_id,
        MAX(test_season_id) as test_season_id,
        MAX(profile_type) as profile_type,
        entity_key,
        MAX(entity_id) as entity_id,
        MAX(entity_name) as entity_name,
        MAX(entity_role) as entity_role,
        MAX(team_context) as team_context,
        COUNT(*) as bucket_rows,
        COUNT(*) FILTER (WHERE test_profile_share IS NOT NULL) as matched_bucket_rows,
        MAX(train_games.games) as train_games,
        MAX(test_games.games) as test_games,
        MAX(train_variety.active_bucket_count) as train_active_bucket_count,
        MAX(latest_variety.active_bucket_count) as last_active_bucket_count,
        MAX(test_variety.active_bucket_count) as test_active_bucket_count,
        MAX(train_variety.top_3_bucket_share) as train_top_3_bucket_share,
        MAX(latest_variety.top_3_bucket_share) as last_top_3_bucket_share,
        MAX(test_variety.top_3_bucket_share) as test_top_3_bucket_share,
        MAX(train_variety.other_share) as train_other_share,
        MAX(latest_variety.other_share) as last_other_share,
        MAX(test_variety.other_share) as test_other_share,
        MAX(train_variety.bucket_entropy) as train_bucket_entropy,
        MAX(latest_variety.bucket_entropy) as last_bucket_entropy,
        MAX(test_variety.bucket_entropy) as test_bucket_entropy,
        SUM(train_sat) as train_sat,
        SUM(train_sog) as train_sog,
        SUM(train_goals) as train_goals,
        SUM(test_sat) as test_sat,
        SUM(test_sog) as test_sog,
        SUM(test_goals) as test_goals,
        SUM(train_profile_share) as train_profile_share,
        SUM(test_profile_share) as test_profile_share,
        SUM(train_xsat_per_60) as train_xsat_per_60,
        SUM(projected_xsat_per_60) as projected_xsat_per_60,
        SUM(test_xsat_per_60) as test_xsat_per_60,
        SUM(train_xsog_per_60) as train_xsog_per_60,
        SUM(projected_xsog_per_60) as projected_xsog_per_60,
        SUM(test_xsog_per_60) as test_xsog_per_60,
        SUM(train_xg_per_60) as train_xg_per_60,
        SUM(projected_xg_per_60) as projected_xg_per_60,
        SUM(test_xg_per_60) as test_xg_per_60
    FROM raw_rows
    CROSS JOIN train_games
    CROSS JOIN test_games
    CROSS JOIN train_variety
    CROSS JOIN latest_variety
    CROSS JOIN test_variety
    GROUP BY entity_key
)
SELECT
    aggregate_rows.model_run_id,
    aggregate_rows.test_season_id,
    aggregate_rows.profile_type,
    aggregate_rows.entity_key,
    aggregate_rows.entity_id,
    aggregate_rows.entity_name,
    aggregate_rows.entity_role,
    aggregate_rows.team_context,
    aggregate_rows.bucket_rows,
    aggregate_rows.matched_bucket_rows,
    COALESCE(aggregate_rows.train_games, 0) as train_games,
    COALESCE(aggregate_rows.test_games, 0) as test_games,
    COALESCE(aggregate_rows.train_active_bucket_count, 0) as train_active_bucket_count,
    COALESCE(aggregate_rows.last_active_bucket_count, 0) as last_active_bucket_count,
    COALESCE(aggregate_rows.test_active_bucket_count, 0) as test_active_bucket_count,
    ROUND(aggregate_rows.train_top_3_bucket_share::numeric, 6) as train_top_3_bucket_share,
    ROUND(aggregate_rows.last_top_3_bucket_share::numeric, 6) as last_top_3_bucket_share,
    ROUND(aggregate_rows.test_top_3_bucket_share::numeric, 6) as test_top_3_bucket_share,
    ROUND(aggregate_rows.train_other_share::numeric, 6) as train_other_share,
    ROUND(aggregate_rows.last_other_share::numeric, 6) as last_other_share,
    ROUND(aggregate_rows.test_other_share::numeric, 6) as test_other_share,
    ROUND(aggregate_rows.train_bucket_entropy::numeric, 6) as train_bucket_entropy,
    ROUND(aggregate_rows.last_bucket_entropy::numeric, 6) as last_bucket_entropy,
    ROUND(aggregate_rows.test_bucket_entropy::numeric, 6) as test_bucket_entropy,
    aggregate_rows.train_sat,
    aggregate_rows.train_sog,
    aggregate_rows.train_goals,
    aggregate_rows.test_sat,
    aggregate_rows.test_sog,
    aggregate_rows.test_goals,
    ROUND(aggregate_rows.train_profile_share::numeric, 6) as train_profile_share,
    ROUND(aggregate_rows.test_profile_share::numeric, 6) as test_profile_share,
    ROUND((aggregate_rows.test_profile_share - aggregate_rows.train_profile_share)::numeric, 6) as share_drift,
    ROUND(((aggregate_rows.test_profile_share - aggregate_rows.train_profile_share) / NULLIF(ABS(aggregate_rows.train_profile_share), 0))::numeric, 6) as share_drift_rate,
    ROUND(aggregate_rows.train_xsat_per_60::numeric, 4) as train_xsat_per_60,
    ROUND(aggregate_rows.projected_xsat_per_60::numeric, 4) as projected_xsat_per_60,
    ROUND(aggregate_rows.test_xsat_per_60::numeric, 4) as test_xsat_per_60,
    ROUND((aggregate_rows.test_xsat_per_60 - aggregate_rows.train_xsat_per_60)::numeric, 4) as xsat_drift,
    ROUND(((aggregate_rows.test_xsat_per_60 - aggregate_rows.train_xsat_per_60) / NULLIF(ABS(aggregate_rows.train_xsat_per_60), 0))::numeric, 6) as xsat_drift_rate,
    ROUND((aggregate_rows.test_xsat_per_60 - aggregate_rows.projected_xsat_per_60)::numeric, 4) as xsat_error,
    ROUND(((aggregate_rows.test_xsat_per_60 - aggregate_rows.projected_xsat_per_60) / NULLIF(ABS(aggregate_rows.projected_xsat_per_60), 0))::numeric, 6) as xsat_error_rate,
    ROUND(aggregate_rows.train_xsog_per_60::numeric, 4) as train_xsog_per_60,
    ROUND(aggregate_rows.projected_xsog_per_60::numeric, 4) as projected_xsog_per_60,
    ROUND(aggregate_rows.test_xsog_per_60::numeric, 4) as test_xsog_per_60,
    ROUND((aggregate_rows.test_xsog_per_60 - aggregate_rows.train_xsog_per_60)::numeric, 4) as xsog_drift,
    ROUND(((aggregate_rows.test_xsog_per_60 - aggregate_rows.train_xsog_per_60) / NULLIF(ABS(aggregate_rows.train_xsog_per_60), 0))::numeric, 6) as xsog_drift_rate,
    ROUND((aggregate_rows.test_xsog_per_60 - aggregate_rows.projected_xsog_per_60)::numeric, 4) as xsog_error,
    ROUND(((aggregate_rows.test_xsog_per_60 - aggregate_rows.projected_xsog_per_60) / NULLIF(ABS(aggregate_rows.projected_xsog_per_60), 0))::numeric, 6) as xsog_error_rate,
    ROUND(aggregate_rows.train_xg_per_60::numeric, 4) as train_xg_per_60,
    ROUND(aggregate_rows.projected_xg_per_60::numeric, 4) as projected_xg_per_60,
    ROUND(aggregate_rows.test_xg_per_60::numeric, 4) as test_xg_per_60,
    ROUND((aggregate_rows.test_xg_per_60 - aggregate_rows.train_xg_per_60)::numeric, 4) as xg_drift,
    ROUND(((aggregate_rows.test_xg_per_60 - aggregate_rows.train_xg_per_60) / NULLIF(ABS(aggregate_rows.train_xg_per_60), 0))::numeric, 6) as xg_drift_rate,
    ROUND((aggregate_rows.test_xg_per_60 - aggregate_rows.projected_xg_per_60)::numeric, 4) as xg_error,
    ROUND(((aggregate_rows.test_xg_per_60 - aggregate_rows.projected_xg_per_60) / NULLIF(ABS(aggregate_rows.projected_xg_per_60), 0))::numeric, 6) as xg_error_rate,
    json_build_object('source', 'rate_comparison_buckets') as metadata,
    ?::timestamp as compared_at,
    ?::timestamp as created_at,
    ?::timestamp as updated_at
FROM aggregate_rows
ON CONFLICT (model_run_id, test_season_id, profile_type, entity_key)
DO UPDATE SET
    entity_id = EXCLUDED.entity_id,
    entity_name = EXCLUDED.entity_name,
    entity_role = EXCLUDED.entity_role,
    team_context = EXCLUDED.team_context,
    bucket_rows = EXCLUDED.bucket_rows,
    matched_bucket_rows = EXCLUDED.matched_bucket_rows,
    train_games = EXCLUDED.train_games,
    test_games = EXCLUDED.test_games,
    train_active_bucket_count = EXCLUDED.train_active_bucket_count,
    last_active_bucket_count = EXCLUDED.last_active_bucket_count,
    test_active_bucket_count = EXCLUDED.test_active_bucket_count,
    train_top_3_bucket_share = EXCLUDED.train_top_3_bucket_share,
    last_top_3_bucket_share = EXCLUDED.last_top_3_bucket_share,
    test_top_3_bucket_share = EXCLUDED.test_top_3_bucket_share,
    train_other_share = EXCLUDED.train_other_share,
    last_other_share = EXCLUDED.last_other_share,
    test_other_share = EXCLUDED.test_other_share,
    train_bucket_entropy = EXCLUDED.train_bucket_entropy,
    last_bucket_entropy = EXCLUDED.last_bucket_entropy,
    test_bucket_entropy = EXCLUDED.test_bucket_entropy,
    train_sat = EXCLUDED.train_sat,
    train_sog = EXCLUDED.train_sog,
    train_goals = EXCLUDED.train_goals,
    test_sat = EXCLUDED.test_sat,
    test_sog = EXCLUDED.test_sog,
    test_goals = EXCLUDED.test_goals,
    train_profile_share = EXCLUDED.train_profile_share,
    test_profile_share = EXCLUDED.test_profile_share,
    share_drift = EXCLUDED.share_drift,
    share_drift_rate = EXCLUDED.share_drift_rate,
    train_xsat_per_60 = EXCLUDED.train_xsat_per_60,
    projected_xsat_per_60 = EXCLUDED.projected_xsat_per_60,
    test_xsat_per_60 = EXCLUDED.test_xsat_per_60,
    xsat_drift = EXCLUDED.xsat_drift,
    xsat_drift_rate = EXCLUDED.xsat_drift_rate,
    xsat_error = EXCLUDED.xsat_error,
    xsat_error_rate = EXCLUDED.xsat_error_rate,
    train_xsog_per_60 = EXCLUDED.train_xsog_per_60,
    projected_xsog_per_60 = EXCLUDED.projected_xsog_per_60,
    test_xsog_per_60 = EXCLUDED.test_xsog_per_60,
    xsog_drift = EXCLUDED.xsog_drift,
    xsog_drift_rate = EXCLUDED.xsog_drift_rate,
    xsog_error = EXCLUDED.xsog_error,
    xsog_error_rate = EXCLUDED.xsog_error_rate,
    train_xg_per_60 = EXCLUDED.train_xg_per_60,
    projected_xg_per_60 = EXCLUDED.projected_xg_per_60,
    test_xg_per_60 = EXCLUDED.test_xg_per_60,
    xg_drift = EXCLUDED.xg_drift,
    xg_drift_rate = EXCLUDED.xg_drift_rate,
    xg_error = EXCLUDED.xg_error,
    xg_error_rate = EXCLUDED.xg_error_rate,
    metadata = EXCLUDED.metadata,
    compared_at = EXCLUDED.compared_at,
    updated_at = EXCLUDED.updated_at
SQL;

        DB::statement($sql, [
            $run->id,
            $testSeasonId,
            $profileType,
            $entityKey,
            $run->id,
            $latestTrainingSeasonId,
            $profileType,
            $entityKey,
            self::OTHER_BUCKET_KEY,
            $run->id,
            $latestTrainingSeasonId,
            $profileType,
            $entityKey,
            self::OTHER_BUCKET_KEY,
            self::OTHER_BUCKET_KEY,
            self::OTHER_BUCKET_KEY,
            ...$trainSeasonIds,
            $gameType,
            $entityKey,
            $testSeasonId,
            $gameType,
            $entityKey,
            $now,
            $now,
            $now,
        ]);
    }
}
