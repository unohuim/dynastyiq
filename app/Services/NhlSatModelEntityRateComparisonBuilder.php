<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NhlModelRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Builds held-out test-season comparisons for entity /60 projections.
 */
class NhlSatModelEntityRateComparisonBuilder
{
    private const OTHER_BUCKET_KEY = 'L99|other=low_volume';
    private const SKATER_OFFENSE_PROFILE_TYPE = 'skater_offense';

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
        if (Schema::hasTable('nhl_sat_model_entity_rate_comparison_splits')) {
            DB::table('nhl_sat_model_entity_rate_comparison_splits')
                ->where('model_run_id', $run->id)
                ->where('test_season_id', (string) $run->target_season_id)
                ->delete();
        }

        return DB::table('nhl_sat_model_entity_rate_projection_buckets')
            ->where('model_run_id', $run->id)
            ->where('profile_type', self::SKATER_OFFENSE_PROFILE_TYPE)
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

        if ($profileType !== self::SKATER_OFFENSE_PROFILE_TYPE) {
            return 0;
        }

        if ($profileType === self::SKATER_OFFENSE_PROFILE_TYPE) {
            app(NhlSatModelEntityRateProjectionBuilder::class)->refreshHighDangerSatForRun(
                run: $run,
                seasonId: (string) $run->target_season_id,
                gameType: (int) ($run->game_type ?? 2),
                entityKey: $entityKey
            );
        }

        $this->insertRawRows($run, $profileType, $entityKey);
        $this->insertAggregateRow($run, $profileType, $entityKey);
        $this->insertSplitRows($run, $profileType, $entityKey);

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
        $hasHdsatColumns = $this->hasAggregateHdsatColumns();
        $hasEvalColumns = $this->hasAggregateEvalColumns();
        $evalInsertColumns = $hasEvalColumns ? <<<SQL
    train_eval_gp_per_season,
    test_eval_gp_per_season,
    train_eval_toi_seconds,
    test_eval_toi_seconds,
    train_eval_toi_per_gp,
    test_eval_toi_per_gp,
    train_eval_sat,
    test_eval_sat,
    train_eval_sat_per_gp,
    test_eval_sat_per_gp,
    train_eval_sat_per_60,
    test_eval_sat_per_60,
    train_eval_hdsat,
    test_eval_hdsat,
    train_eval_hdsat_per_gp,
    test_eval_hdsat_per_gp,
    train_eval_hdsat_per_60,
    test_eval_hdsat_per_60,
    train_eval_hdsat_sat_rate,
    test_eval_hdsat_sat_rate,
    train_eval_sog,
    test_eval_sog,
    train_eval_sog_per_gp,
    test_eval_sog_per_gp,
    train_eval_sog_per_60,
    test_eval_sog_per_60,
    train_eval_goals,
    test_eval_goals,
    train_eval_goals_per_gp,
    test_eval_goals_per_gp,
    train_eval_goals_per_60,
    test_eval_goals_per_60,
SQL : '';
        $hdsatInsertColumns = $hasHdsatColumns ? <<<SQL
    train_hdsat,
    test_hdsat,
    train_hdsat_per_60,
    test_hdsat_per_60,
    hdsat_drift,
    hdsat_drift_rate,
SQL : '';
        $evalCtes = $hasEvalColumns ? <<<SQL
train_eval AS (
    SELECT
        ROUND((COUNT(DISTINCT summaries.nhl_game_id)::numeric / ?), 4) as train_eval_gp_per_season,
        COALESCE(SUM(summaries.toi), 0) as train_eval_toi_seconds,
        ROUND((COALESCE(SUM(summaries.toi), 0)::numeric / NULLIF(COUNT(DISTINCT summaries.nhl_game_id), 0)), 4) as train_eval_toi_per_gp,
        COALESCE(SUM(summaries.sat), 0) as train_eval_sat,
        ROUND((COALESCE(SUM(summaries.sat), 0)::numeric / NULLIF(COUNT(DISTINCT summaries.nhl_game_id), 0)), 4) as train_eval_sat_per_gp,
        ROUND(((COALESCE(SUM(summaries.sat), 0)::numeric * 3600) / NULLIF(SUM(summaries.toi), 0))::numeric, 4) as train_eval_sat_per_60,
        COALESCE(SUM(summaries.hdsat), 0) as train_eval_hdsat,
        ROUND((COALESCE(SUM(summaries.hdsat), 0)::numeric / NULLIF(COUNT(DISTINCT summaries.nhl_game_id), 0)), 4) as train_eval_hdsat_per_gp,
        ROUND(((COALESCE(SUM(summaries.hdsat), 0)::numeric * 3600) / NULLIF(SUM(summaries.toi), 0))::numeric, 4) as train_eval_hdsat_per_60,
        ROUND((COALESCE(SUM(summaries.hdsat), 0)::numeric / NULLIF(SUM(summaries.sat), 0)), 6) as train_eval_hdsat_sat_rate,
        COALESCE(SUM(summaries.sog), 0) as train_eval_sog,
        ROUND((COALESCE(SUM(summaries.sog), 0)::numeric / NULLIF(COUNT(DISTINCT summaries.nhl_game_id), 0)), 4) as train_eval_sog_per_gp,
        ROUND(((COALESCE(SUM(summaries.sog), 0)::numeric * 3600) / NULLIF(SUM(summaries.toi), 0))::numeric, 4) as train_eval_sog_per_60,
        COALESCE(SUM(summaries.g), 0) as train_eval_goals,
        ROUND((COALESCE(SUM(summaries.g), 0)::numeric / NULLIF(COUNT(DISTINCT summaries.nhl_game_id), 0)), 4) as train_eval_goals_per_gp,
        ROUND(((COALESCE(SUM(summaries.g), 0)::numeric * 3600) / NULLIF(SUM(summaries.toi), 0))::numeric, 4) as train_eval_goals_per_60
    FROM nhl_game_summaries summaries
    INNER JOIN nhl_games games ON games.nhl_game_id = summaries.nhl_game_id
    WHERE games.season_id IN ({$trainSeasonPlaceholders})
        AND games.game_type = ?
        AND summaries.nhl_player_id = (SELECT MAX(entity_id) FROM raw_rows)
),
test_eval AS (
    SELECT
        COUNT(DISTINCT summaries.nhl_game_id)::numeric as test_eval_gp_per_season,
        COALESCE(SUM(summaries.toi), 0) as test_eval_toi_seconds,
        ROUND((COALESCE(SUM(summaries.toi), 0)::numeric / NULLIF(COUNT(DISTINCT summaries.nhl_game_id), 0)), 4) as test_eval_toi_per_gp,
        COALESCE(SUM(summaries.sat), 0) as test_eval_sat,
        ROUND((COALESCE(SUM(summaries.sat), 0)::numeric / NULLIF(COUNT(DISTINCT summaries.nhl_game_id), 0)), 4) as test_eval_sat_per_gp,
        ROUND(((COALESCE(SUM(summaries.sat), 0)::numeric * 3600) / NULLIF(SUM(summaries.toi), 0))::numeric, 4) as test_eval_sat_per_60,
        COALESCE(SUM(summaries.hdsat), 0) as test_eval_hdsat,
        ROUND((COALESCE(SUM(summaries.hdsat), 0)::numeric / NULLIF(COUNT(DISTINCT summaries.nhl_game_id), 0)), 4) as test_eval_hdsat_per_gp,
        ROUND(((COALESCE(SUM(summaries.hdsat), 0)::numeric * 3600) / NULLIF(SUM(summaries.toi), 0))::numeric, 4) as test_eval_hdsat_per_60,
        ROUND((COALESCE(SUM(summaries.hdsat), 0)::numeric / NULLIF(SUM(summaries.sat), 0)), 6) as test_eval_hdsat_sat_rate,
        COALESCE(SUM(summaries.sog), 0) as test_eval_sog,
        ROUND((COALESCE(SUM(summaries.sog), 0)::numeric / NULLIF(COUNT(DISTINCT summaries.nhl_game_id), 0)), 4) as test_eval_sog_per_gp,
        ROUND(((COALESCE(SUM(summaries.sog), 0)::numeric * 3600) / NULLIF(SUM(summaries.toi), 0))::numeric, 4) as test_eval_sog_per_60,
        COALESCE(SUM(summaries.g), 0) as test_eval_goals,
        ROUND((COALESCE(SUM(summaries.g), 0)::numeric / NULLIF(COUNT(DISTINCT summaries.nhl_game_id), 0)), 4) as test_eval_goals_per_gp,
        ROUND(((COALESCE(SUM(summaries.g), 0)::numeric * 3600) / NULLIF(SUM(summaries.toi), 0))::numeric, 4) as test_eval_goals_per_60
    FROM nhl_game_summaries summaries
    INNER JOIN nhl_games games ON games.nhl_game_id = summaries.nhl_game_id
    WHERE games.season_id = ?
        AND games.game_type = ?
        AND summaries.nhl_player_id = (SELECT MAX(entity_id) FROM raw_rows)
),
SQL : '';
        $hdsatCtes = $hasHdsatColumns ? <<<SQL
train_hdsat AS (
    SELECT
        COALESCE(SUM(summaries.hdsat), 0) as train_hdsat,
        ROUND(((COALESCE(SUM(summaries.hdsat), 0)::numeric * 3600) / NULLIF(SUM(summaries.toi), 0))::numeric, 4) as train_hdsat_per_60
    FROM nhl_game_summaries summaries
    INNER JOIN nhl_games games ON games.nhl_game_id = summaries.nhl_game_id
    WHERE ? = 'skater_offense'
        AND games.season_id IN ({$trainSeasonPlaceholders})
        AND games.game_type = ?
        AND summaries.nhl_player_id = (SELECT MAX(entity_id) FROM raw_rows)
),
test_hdsat AS (
    SELECT
        COALESCE(SUM(summaries.hdsat), 0) as test_hdsat,
        ROUND(((COALESCE(SUM(summaries.hdsat), 0)::numeric * 3600) / NULLIF(SUM(summaries.toi), 0))::numeric, 4) as test_hdsat_per_60
    FROM nhl_game_summaries summaries
    INNER JOIN nhl_games games ON games.nhl_game_id = summaries.nhl_game_id
    WHERE ? = 'skater_offense'
        AND games.season_id = ?
        AND games.game_type = ?
        AND summaries.nhl_player_id = (SELECT MAX(entity_id) FROM raw_rows)
),
SQL : '';
        $evalAggregateSelects = $hasEvalColumns ? <<<SQL
        MAX(train_eval.train_eval_gp_per_season) as train_eval_gp_per_season,
        MAX(test_eval.test_eval_gp_per_season) as test_eval_gp_per_season,
        MAX(train_eval.train_eval_toi_seconds) as train_eval_toi_seconds,
        MAX(test_eval.test_eval_toi_seconds) as test_eval_toi_seconds,
        MAX(train_eval.train_eval_toi_per_gp) as train_eval_toi_per_gp,
        MAX(test_eval.test_eval_toi_per_gp) as test_eval_toi_per_gp,
        MAX(train_eval.train_eval_sat) as train_eval_sat,
        MAX(test_eval.test_eval_sat) as test_eval_sat,
        MAX(train_eval.train_eval_sat_per_gp) as train_eval_sat_per_gp,
        MAX(test_eval.test_eval_sat_per_gp) as test_eval_sat_per_gp,
        MAX(train_eval.train_eval_sat_per_60) as train_eval_sat_per_60,
        MAX(test_eval.test_eval_sat_per_60) as test_eval_sat_per_60,
        MAX(train_eval.train_eval_hdsat) as train_eval_hdsat,
        MAX(test_eval.test_eval_hdsat) as test_eval_hdsat,
        MAX(train_eval.train_eval_hdsat_per_gp) as train_eval_hdsat_per_gp,
        MAX(test_eval.test_eval_hdsat_per_gp) as test_eval_hdsat_per_gp,
        MAX(train_eval.train_eval_hdsat_per_60) as train_eval_hdsat_per_60,
        MAX(test_eval.test_eval_hdsat_per_60) as test_eval_hdsat_per_60,
        MAX(train_eval.train_eval_hdsat_sat_rate) as train_eval_hdsat_sat_rate,
        MAX(test_eval.test_eval_hdsat_sat_rate) as test_eval_hdsat_sat_rate,
        MAX(train_eval.train_eval_sog) as train_eval_sog,
        MAX(test_eval.test_eval_sog) as test_eval_sog,
        MAX(train_eval.train_eval_sog_per_gp) as train_eval_sog_per_gp,
        MAX(test_eval.test_eval_sog_per_gp) as test_eval_sog_per_gp,
        MAX(train_eval.train_eval_sog_per_60) as train_eval_sog_per_60,
        MAX(test_eval.test_eval_sog_per_60) as test_eval_sog_per_60,
        MAX(train_eval.train_eval_goals) as train_eval_goals,
        MAX(test_eval.test_eval_goals) as test_eval_goals,
        MAX(train_eval.train_eval_goals_per_gp) as train_eval_goals_per_gp,
        MAX(test_eval.test_eval_goals_per_gp) as test_eval_goals_per_gp,
        MAX(train_eval.train_eval_goals_per_60) as train_eval_goals_per_60,
        MAX(test_eval.test_eval_goals_per_60) as test_eval_goals_per_60,
SQL : '';
        $hdsatAggregateSelects = $hasHdsatColumns ? <<<SQL
        MAX(train_hdsat.train_hdsat) as train_hdsat,
        MAX(test_hdsat.test_hdsat) as test_hdsat,
        MAX(train_hdsat.train_hdsat_per_60) as train_hdsat_per_60,
        MAX(test_hdsat.test_hdsat_per_60) as test_hdsat_per_60,
SQL : '';
        $hdsatCrossJoins = $hasHdsatColumns ? <<<SQL
    CROSS JOIN train_hdsat
    CROSS JOIN test_hdsat
SQL : '';
        $hdsatFinalSelects = $hasHdsatColumns ? <<<SQL
    aggregate_rows.train_hdsat,
    aggregate_rows.test_hdsat,
    ROUND(aggregate_rows.train_hdsat_per_60::numeric, 4) as train_hdsat_per_60,
    ROUND(aggregate_rows.test_hdsat_per_60::numeric, 4) as test_hdsat_per_60,
    ROUND((aggregate_rows.test_hdsat_per_60 - aggregate_rows.train_hdsat_per_60)::numeric, 4) as hdsat_drift,
    ROUND(((aggregate_rows.test_hdsat_per_60 - aggregate_rows.train_hdsat_per_60) / NULLIF(ABS(aggregate_rows.train_hdsat_per_60), 0))::numeric, 6) as hdsat_drift_rate,
SQL : '';
        $hdsatUpdateColumns = $hasHdsatColumns ? <<<SQL
    train_hdsat = EXCLUDED.train_hdsat,
    test_hdsat = EXCLUDED.test_hdsat,
    train_hdsat_per_60 = EXCLUDED.train_hdsat_per_60,
    test_hdsat_per_60 = EXCLUDED.test_hdsat_per_60,
    hdsat_drift = EXCLUDED.hdsat_drift,
    hdsat_drift_rate = EXCLUDED.hdsat_drift_rate,
SQL : '';
        $evalCrossJoins = $hasEvalColumns ? <<<SQL
    CROSS JOIN train_eval
    CROSS JOIN test_eval
SQL : '';
        $evalFinalSelects = $hasEvalColumns ? <<<SQL
    ROUND(aggregate_rows.train_eval_gp_per_season::numeric, 4) as train_eval_gp_per_season,
    ROUND(aggregate_rows.test_eval_gp_per_season::numeric, 4) as test_eval_gp_per_season,
    aggregate_rows.train_eval_toi_seconds,
    aggregate_rows.test_eval_toi_seconds,
    ROUND(aggregate_rows.train_eval_toi_per_gp::numeric, 4) as train_eval_toi_per_gp,
    ROUND(aggregate_rows.test_eval_toi_per_gp::numeric, 4) as test_eval_toi_per_gp,
    aggregate_rows.train_eval_sat,
    aggregate_rows.test_eval_sat,
    ROUND(aggregate_rows.train_eval_sat_per_gp::numeric, 4) as train_eval_sat_per_gp,
    ROUND(aggregate_rows.test_eval_sat_per_gp::numeric, 4) as test_eval_sat_per_gp,
    ROUND(aggregate_rows.train_eval_sat_per_60::numeric, 4) as train_eval_sat_per_60,
    ROUND(aggregate_rows.test_eval_sat_per_60::numeric, 4) as test_eval_sat_per_60,
    aggregate_rows.train_eval_hdsat,
    aggregate_rows.test_eval_hdsat,
    ROUND(aggregate_rows.train_eval_hdsat_per_gp::numeric, 4) as train_eval_hdsat_per_gp,
    ROUND(aggregate_rows.test_eval_hdsat_per_gp::numeric, 4) as test_eval_hdsat_per_gp,
    ROUND(aggregate_rows.train_eval_hdsat_per_60::numeric, 4) as train_eval_hdsat_per_60,
    ROUND(aggregate_rows.test_eval_hdsat_per_60::numeric, 4) as test_eval_hdsat_per_60,
    ROUND(aggregate_rows.train_eval_hdsat_sat_rate::numeric, 6) as train_eval_hdsat_sat_rate,
    ROUND(aggregate_rows.test_eval_hdsat_sat_rate::numeric, 6) as test_eval_hdsat_sat_rate,
    aggregate_rows.train_eval_sog,
    aggregate_rows.test_eval_sog,
    ROUND(aggregate_rows.train_eval_sog_per_gp::numeric, 4) as train_eval_sog_per_gp,
    ROUND(aggregate_rows.test_eval_sog_per_gp::numeric, 4) as test_eval_sog_per_gp,
    ROUND(aggregate_rows.train_eval_sog_per_60::numeric, 4) as train_eval_sog_per_60,
    ROUND(aggregate_rows.test_eval_sog_per_60::numeric, 4) as test_eval_sog_per_60,
    aggregate_rows.train_eval_goals,
    aggregate_rows.test_eval_goals,
    ROUND(aggregate_rows.train_eval_goals_per_gp::numeric, 4) as train_eval_goals_per_gp,
    ROUND(aggregate_rows.test_eval_goals_per_gp::numeric, 4) as test_eval_goals_per_gp,
    ROUND(aggregate_rows.train_eval_goals_per_60::numeric, 4) as train_eval_goals_per_60,
    ROUND(aggregate_rows.test_eval_goals_per_60::numeric, 4) as test_eval_goals_per_60,
SQL : '';
        $evalUpdateColumns = $hasEvalColumns ? <<<SQL
    train_eval_gp_per_season = EXCLUDED.train_eval_gp_per_season,
    test_eval_gp_per_season = EXCLUDED.test_eval_gp_per_season,
    train_eval_toi_seconds = EXCLUDED.train_eval_toi_seconds,
    test_eval_toi_seconds = EXCLUDED.test_eval_toi_seconds,
    train_eval_toi_per_gp = EXCLUDED.train_eval_toi_per_gp,
    test_eval_toi_per_gp = EXCLUDED.test_eval_toi_per_gp,
    train_eval_sat = EXCLUDED.train_eval_sat,
    test_eval_sat = EXCLUDED.test_eval_sat,
    train_eval_sat_per_gp = EXCLUDED.train_eval_sat_per_gp,
    test_eval_sat_per_gp = EXCLUDED.test_eval_sat_per_gp,
    train_eval_sat_per_60 = EXCLUDED.train_eval_sat_per_60,
    test_eval_sat_per_60 = EXCLUDED.test_eval_sat_per_60,
    train_eval_hdsat = EXCLUDED.train_eval_hdsat,
    test_eval_hdsat = EXCLUDED.test_eval_hdsat,
    train_eval_hdsat_per_gp = EXCLUDED.train_eval_hdsat_per_gp,
    test_eval_hdsat_per_gp = EXCLUDED.test_eval_hdsat_per_gp,
    train_eval_hdsat_per_60 = EXCLUDED.train_eval_hdsat_per_60,
    test_eval_hdsat_per_60 = EXCLUDED.test_eval_hdsat_per_60,
    train_eval_hdsat_sat_rate = EXCLUDED.train_eval_hdsat_sat_rate,
    test_eval_hdsat_sat_rate = EXCLUDED.test_eval_hdsat_sat_rate,
    train_eval_sog = EXCLUDED.train_eval_sog,
    test_eval_sog = EXCLUDED.test_eval_sog,
    train_eval_sog_per_gp = EXCLUDED.train_eval_sog_per_gp,
    test_eval_sog_per_gp = EXCLUDED.test_eval_sog_per_gp,
    train_eval_sog_per_60 = EXCLUDED.train_eval_sog_per_60,
    test_eval_sog_per_60 = EXCLUDED.test_eval_sog_per_60,
    train_eval_goals = EXCLUDED.train_eval_goals,
    test_eval_goals = EXCLUDED.test_eval_goals,
    train_eval_goals_per_gp = EXCLUDED.train_eval_goals_per_gp,
    test_eval_goals_per_gp = EXCLUDED.test_eval_goals_per_gp,
    train_eval_goals_per_60 = EXCLUDED.train_eval_goals_per_60,
    test_eval_goals_per_60 = EXCLUDED.test_eval_goals_per_60,
SQL : '';
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
{$evalInsertColumns}
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
{$hdsatInsertColumns}
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
{$evalCtes}
{$hdsatCtes}
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
{$evalAggregateSelects}
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
{$hdsatAggregateSelects}
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
{$evalCrossJoins}
    CROSS JOIN train_variety
    CROSS JOIN latest_variety
    CROSS JOIN test_variety
{$hdsatCrossJoins}
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
{$evalFinalSelects}
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
{$hdsatFinalSelects}
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
{$evalUpdateColumns}
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
{$hdsatUpdateColumns}
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
            ...($hasEvalColumns ? [
                count($trainSeasonIds),
                ...$trainSeasonIds,
                $gameType,
                $testSeasonId,
                $gameType,
            ] : []),
            ...($hasHdsatColumns ? [
                $profileType,
                ...$trainSeasonIds,
                $gameType,
                $profileType,
                $testSeasonId,
                $gameType,
            ] : []),
            $now,
            $now,
            $now,
        ]);
    }

    private function insertSplitRows(NhlModelRun $run, string $profileType, string $entityKey): void
    {
        if (
            $profileType !== self::SKATER_OFFENSE_PROFILE_TYPE
            || ! Schema::hasTable('nhl_sat_model_entity_rate_comparison_splits')
            || ! $this->hasGameSummarySplitHdsatColumns()
        ) {
            return;
        }

        $trainSeasonIds = array_values(array_map('strval', $run->train_season_ids ?? []));

        if ($trainSeasonIds === []) {
            return;
        }

        $now = now();
        $gameType = (int) ($run->game_type ?? 2);
        $testSeasonId = (string) $run->target_season_id;
        $trainSeasonPlaceholders = implode(', ', array_fill(0, count($trainSeasonIds), '?'));

        $sql = <<<SQL
INSERT INTO nhl_sat_model_entity_rate_comparison_splits (
    model_run_id,
    test_season_id,
    profile_type,
    entity_key,
    entity_id,
    entity_name,
    entity_role,
    team_context,
    situation,
    train_gp_per_season,
    test_gp_per_season,
    train_toi_seconds,
    test_toi_seconds,
    train_toi_per_gp,
    test_toi_per_gp,
    train_sat,
    test_sat,
    train_sat_per_gp,
    test_sat_per_gp,
    train_sat_per_60,
    test_sat_per_60,
    train_hdsat,
    test_hdsat,
    train_hdsat_per_gp,
    test_hdsat_per_gp,
    train_hdsat_per_60,
    test_hdsat_per_60,
    train_hdsat_sat_rate,
    test_hdsat_sat_rate,
    train_sog,
    test_sog,
    train_sog_per_gp,
    test_sog_per_gp,
    train_sog_per_60,
    test_sog_per_60,
    train_goals,
    test_goals,
    train_goals_per_gp,
    test_goals_per_gp,
    train_goals_per_60,
    test_goals_per_60,
    metadata,
    compared_at,
    created_at,
    updated_at
)
WITH entity_row AS (
    SELECT
        MAX(entity_id) as entity_id,
        MAX(entity_name) as entity_name,
        MAX(entity_role) as entity_role,
        MAX(team_context) as team_context
    FROM nhl_sat_model_entity_rate_comparison_aggregates
    WHERE model_run_id = ?
        AND test_season_id = ?
        AND profile_type = ?
        AND entity_key = ?
),
situations AS (
    SELECT * FROM (VALUES
        ('all'::varchar, NULL::varchar, 'sat'::varchar, 'sog'::varchar, 'g'::varchar),
        ('ev'::varchar, 'EV'::varchar, 'evsat'::varchar, 'evsog'::varchar, 'evg'::varchar),
        ('pp'::varchar, 'PP'::varchar, 'ppsat'::varchar, 'ppsog'::varchar, 'ppg'::varchar),
        ('pk'::varchar, 'PK'::varchar, 'pksat'::varchar, 'pksog'::varchar, 'pkg'::varchar)
    ) as values(situation, strength, sat_column, sog_column, goal_column)
),
train_summary AS (
    SELECT
        situations.situation,
        ROUND((COUNT(DISTINCT summaries.nhl_game_id)::numeric / ?), 4) as gp_per_season,
        COALESCE(SUM(CASE WHEN situations.situation = 'all' THEN summaries.toi ELSE strength_summaries.toi END), 0) as toi_seconds,
        COALESCE(SUM(CASE situations.situation
            WHEN 'all' THEN summaries.sat
            WHEN 'ev' THEN summaries.evsat
            WHEN 'pp' THEN summaries.ppsat
            WHEN 'pk' THEN summaries.pksat
            ELSE 0
        END), 0) as sat,
        COALESCE(SUM(CASE situations.situation
            WHEN 'all' THEN summaries.hdsat
            WHEN 'ev' THEN summaries.evhdsat
            WHEN 'pp' THEN summaries.pphdsat
            WHEN 'pk' THEN summaries.pkhdsat
            ELSE 0
        END), 0) as hdsat,
        COALESCE(SUM(CASE situations.situation
            WHEN 'all' THEN summaries.sog
            WHEN 'ev' THEN summaries.evsog
            WHEN 'pp' THEN summaries.ppsog
            WHEN 'pk' THEN summaries.pksog
            ELSE 0
        END), 0) as sog,
        COALESCE(SUM(CASE situations.situation
            WHEN 'all' THEN summaries.g
            WHEN 'ev' THEN summaries.evg
            WHEN 'pp' THEN summaries.ppg
            WHEN 'pk' THEN summaries.pkg
            ELSE 0
        END), 0) as goals
    FROM situations
    CROSS JOIN entity_row
    INNER JOIN nhl_game_summaries summaries ON summaries.nhl_player_id = entity_row.entity_id
    INNER JOIN nhl_games games ON games.nhl_game_id = summaries.nhl_game_id
    LEFT JOIN nhl_player_game_strength_summaries strength_summaries
        ON strength_summaries.nhl_game_id = summaries.nhl_game_id
        AND strength_summaries.nhl_player_id = summaries.nhl_player_id
        AND strength_summaries.strength = situations.strength
    WHERE games.season_id IN ({$trainSeasonPlaceholders})
        AND games.game_type = ?
        AND (situations.situation = 'all' OR COALESCE(strength_summaries.toi, 0) > 0)
    GROUP BY situations.situation
),
test_summary AS (
    SELECT
        situations.situation,
        COUNT(DISTINCT summaries.nhl_game_id)::numeric as gp_per_season,
        COALESCE(SUM(CASE WHEN situations.situation = 'all' THEN summaries.toi ELSE strength_summaries.toi END), 0) as toi_seconds,
        COALESCE(SUM(CASE situations.situation
            WHEN 'all' THEN summaries.sat
            WHEN 'ev' THEN summaries.evsat
            WHEN 'pp' THEN summaries.ppsat
            WHEN 'pk' THEN summaries.pksat
            ELSE 0
        END), 0) as sat,
        COALESCE(SUM(CASE situations.situation
            WHEN 'all' THEN summaries.hdsat
            WHEN 'ev' THEN summaries.evhdsat
            WHEN 'pp' THEN summaries.pphdsat
            WHEN 'pk' THEN summaries.pkhdsat
            ELSE 0
        END), 0) as hdsat,
        COALESCE(SUM(CASE situations.situation
            WHEN 'all' THEN summaries.sog
            WHEN 'ev' THEN summaries.evsog
            WHEN 'pp' THEN summaries.ppsog
            WHEN 'pk' THEN summaries.pksog
            ELSE 0
        END), 0) as sog,
        COALESCE(SUM(CASE situations.situation
            WHEN 'all' THEN summaries.g
            WHEN 'ev' THEN summaries.evg
            WHEN 'pp' THEN summaries.ppg
            WHEN 'pk' THEN summaries.pkg
            ELSE 0
        END), 0) as goals
    FROM situations
    CROSS JOIN entity_row
    INNER JOIN nhl_game_summaries summaries ON summaries.nhl_player_id = entity_row.entity_id
    INNER JOIN nhl_games games ON games.nhl_game_id = summaries.nhl_game_id
    LEFT JOIN nhl_player_game_strength_summaries strength_summaries
        ON strength_summaries.nhl_game_id = summaries.nhl_game_id
        AND strength_summaries.nhl_player_id = summaries.nhl_player_id
        AND strength_summaries.strength = situations.strength
    WHERE games.season_id = ?
        AND games.game_type = ?
        AND (situations.situation = 'all' OR COALESCE(strength_summaries.toi, 0) > 0)
    GROUP BY situations.situation
),
split_rows AS (
    SELECT
        situations.situation,
        COALESCE(train_summary.gp_per_season, 0) as train_gp_per_season,
        COALESCE(test_summary.gp_per_season, 0) as test_gp_per_season,
        COALESCE(train_summary.toi_seconds, 0) as train_toi_seconds,
        COALESCE(test_summary.toi_seconds, 0) as test_toi_seconds,
        COALESCE(train_summary.sat, 0) as train_sat,
        COALESCE(test_summary.sat, 0) as test_sat,
        COALESCE(train_summary.hdsat, 0) as train_hdsat_value,
        COALESCE(test_summary.hdsat, 0) as test_hdsat_value,
        COALESCE(train_summary.sog, 0) as train_sog,
        COALESCE(test_summary.sog, 0) as test_sog,
        COALESCE(train_summary.goals, 0) as train_goals,
        COALESCE(test_summary.goals, 0) as test_goals
    FROM situations
    LEFT JOIN train_summary ON train_summary.situation = situations.situation
    LEFT JOIN test_summary ON test_summary.situation = situations.situation
)
SELECT
    ? as model_run_id,
    ?::varchar as test_season_id,
    ?::varchar as profile_type,
    ?::varchar as entity_key,
    entity_row.entity_id,
    entity_row.entity_name,
    entity_row.entity_role,
    entity_row.team_context,
    split_rows.situation,
    ROUND(split_rows.train_gp_per_season::numeric, 4) as train_gp_per_season,
    ROUND(split_rows.test_gp_per_season::numeric, 4) as test_gp_per_season,
    split_rows.train_toi_seconds,
    split_rows.test_toi_seconds,
    ROUND((split_rows.train_toi_seconds::numeric / NULLIF(split_rows.train_gp_per_season * ?, 0)), 4) as train_toi_per_gp,
    ROUND((split_rows.test_toi_seconds::numeric / NULLIF(split_rows.test_gp_per_season, 0)), 4) as test_toi_per_gp,
    split_rows.train_sat,
    split_rows.test_sat,
    ROUND((split_rows.train_sat::numeric / NULLIF(split_rows.train_gp_per_season * ?, 0)), 4) as train_sat_per_gp,
    ROUND((split_rows.test_sat::numeric / NULLIF(split_rows.test_gp_per_season, 0)), 4) as test_sat_per_gp,
    ROUND(((split_rows.train_sat::numeric * 3600) / NULLIF(split_rows.train_toi_seconds, 0)), 4) as train_sat_per_60,
    ROUND(((split_rows.test_sat::numeric * 3600) / NULLIF(split_rows.test_toi_seconds, 0)), 4) as test_sat_per_60,
    split_rows.train_hdsat_value,
    split_rows.test_hdsat_value,
    ROUND((split_rows.train_hdsat_value::numeric / NULLIF(split_rows.train_gp_per_season * ?, 0)), 4) as train_hdsat_per_gp,
    ROUND((split_rows.test_hdsat_value::numeric / NULLIF(split_rows.test_gp_per_season, 0)), 4) as test_hdsat_per_gp,
    ROUND(((split_rows.train_hdsat_value::numeric * 3600) / NULLIF(split_rows.train_toi_seconds, 0)), 4) as train_hdsat_per_60,
    ROUND(((split_rows.test_hdsat_value::numeric * 3600) / NULLIF(split_rows.test_toi_seconds, 0)), 4) as test_hdsat_per_60,
    ROUND((split_rows.train_hdsat_value::numeric / NULLIF(split_rows.train_sat, 0)), 6) as train_hdsat_sat_rate,
    ROUND((split_rows.test_hdsat_value::numeric / NULLIF(split_rows.test_sat, 0)), 6) as test_hdsat_sat_rate,
    split_rows.train_sog,
    split_rows.test_sog,
    ROUND((split_rows.train_sog::numeric / NULLIF(split_rows.train_gp_per_season * ?, 0)), 4) as train_sog_per_gp,
    ROUND((split_rows.test_sog::numeric / NULLIF(split_rows.test_gp_per_season, 0)), 4) as test_sog_per_gp,
    ROUND(((split_rows.train_sog::numeric * 3600) / NULLIF(split_rows.train_toi_seconds, 0)), 4) as train_sog_per_60,
    ROUND(((split_rows.test_sog::numeric * 3600) / NULLIF(split_rows.test_toi_seconds, 0)), 4) as test_sog_per_60,
    split_rows.train_goals,
    split_rows.test_goals,
    ROUND((split_rows.train_goals::numeric / NULLIF(split_rows.train_gp_per_season * ?, 0)), 4) as train_goals_per_gp,
    ROUND((split_rows.test_goals::numeric / NULLIF(split_rows.test_gp_per_season, 0)), 4) as test_goals_per_gp,
    ROUND(((split_rows.train_goals::numeric * 3600) / NULLIF(split_rows.train_toi_seconds, 0)), 4) as train_goals_per_60,
    ROUND(((split_rows.test_goals::numeric * 3600) / NULLIF(split_rows.test_toi_seconds, 0)), 4) as test_goals_per_60,
    json_build_object('source', 'nhl_game_summaries') as metadata,
    ?::timestamp as compared_at,
    ?::timestamp as created_at,
    ?::timestamp as updated_at
FROM split_rows
CROSS JOIN entity_row
ON CONFLICT (model_run_id, test_season_id, profile_type, entity_key, situation)
DO UPDATE SET
    entity_id = EXCLUDED.entity_id,
    entity_name = EXCLUDED.entity_name,
    entity_role = EXCLUDED.entity_role,
    team_context = EXCLUDED.team_context,
    train_gp_per_season = EXCLUDED.train_gp_per_season,
    test_gp_per_season = EXCLUDED.test_gp_per_season,
    train_toi_seconds = EXCLUDED.train_toi_seconds,
    test_toi_seconds = EXCLUDED.test_toi_seconds,
    train_toi_per_gp = EXCLUDED.train_toi_per_gp,
    test_toi_per_gp = EXCLUDED.test_toi_per_gp,
    train_sat = EXCLUDED.train_sat,
    test_sat = EXCLUDED.test_sat,
    train_sat_per_gp = EXCLUDED.train_sat_per_gp,
    test_sat_per_gp = EXCLUDED.test_sat_per_gp,
    train_sat_per_60 = EXCLUDED.train_sat_per_60,
    test_sat_per_60 = EXCLUDED.test_sat_per_60,
    train_hdsat = EXCLUDED.train_hdsat,
    test_hdsat = EXCLUDED.test_hdsat,
    train_hdsat_per_gp = EXCLUDED.train_hdsat_per_gp,
    test_hdsat_per_gp = EXCLUDED.test_hdsat_per_gp,
    train_hdsat_per_60 = EXCLUDED.train_hdsat_per_60,
    test_hdsat_per_60 = EXCLUDED.test_hdsat_per_60,
    train_hdsat_sat_rate = EXCLUDED.train_hdsat_sat_rate,
    test_hdsat_sat_rate = EXCLUDED.test_hdsat_sat_rate,
    train_sog = EXCLUDED.train_sog,
    test_sog = EXCLUDED.test_sog,
    train_sog_per_gp = EXCLUDED.train_sog_per_gp,
    test_sog_per_gp = EXCLUDED.test_sog_per_gp,
    train_sog_per_60 = EXCLUDED.train_sog_per_60,
    test_sog_per_60 = EXCLUDED.test_sog_per_60,
    train_goals = EXCLUDED.train_goals,
    test_goals = EXCLUDED.test_goals,
    train_goals_per_gp = EXCLUDED.train_goals_per_gp,
    test_goals_per_gp = EXCLUDED.test_goals_per_gp,
    train_goals_per_60 = EXCLUDED.train_goals_per_60,
    test_goals_per_60 = EXCLUDED.test_goals_per_60,
    metadata = EXCLUDED.metadata,
    compared_at = EXCLUDED.compared_at,
    updated_at = EXCLUDED.updated_at
SQL;

        DB::statement($sql, [
            $run->id,
            $testSeasonId,
            $profileType,
            $entityKey,
            count($trainSeasonIds),
            ...$trainSeasonIds,
            $gameType,
            $testSeasonId,
            $gameType,
            $run->id,
            $testSeasonId,
            $profileType,
            $entityKey,
            count($trainSeasonIds),
            count($trainSeasonIds),
            count($trainSeasonIds),
            count($trainSeasonIds),
            count($trainSeasonIds),
            $now,
            $now,
            $now,
        ]);
    }

    private function hasAggregateHdsatColumns(): bool
    {
        return Schema::hasColumn('nhl_game_summaries', 'hdsat')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'train_hdsat')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'test_hdsat')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'train_hdsat_per_60')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'test_hdsat_per_60')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'hdsat_drift')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'hdsat_drift_rate');
    }

    /**
     * Determine whether player-game summaries can support situation-split HDSAT reporting.
     */
    private function hasGameSummarySplitHdsatColumns(): bool
    {
        return Schema::hasColumn('nhl_game_summaries', 'hdsat')
            && Schema::hasColumn('nhl_game_summaries', 'evhdsat')
            && Schema::hasColumn('nhl_game_summaries', 'pphdsat')
            && Schema::hasColumn('nhl_game_summaries', 'pkhdsat');
    }

    private function hasAggregateEvalColumns(): bool
    {
        return Schema::hasColumn('nhl_game_summaries', 'hdsat')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'train_eval_gp_per_season')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'test_eval_gp_per_season')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'train_eval_toi_seconds')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'test_eval_toi_seconds')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'train_eval_toi_per_gp')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'test_eval_toi_per_gp')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'train_eval_sat')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'test_eval_sat')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'train_eval_sat_per_gp')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'test_eval_sat_per_gp')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'train_eval_sat_per_60')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'test_eval_sat_per_60')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'train_eval_hdsat')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'test_eval_hdsat')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'train_eval_hdsat_per_gp')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'test_eval_hdsat_per_gp')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'train_eval_hdsat_per_60')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'test_eval_hdsat_per_60')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'train_eval_hdsat_sat_rate')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'test_eval_hdsat_sat_rate')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'train_eval_sog')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'test_eval_sog')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'train_eval_sog_per_gp')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'test_eval_sog_per_gp')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'train_eval_sog_per_60')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'test_eval_sog_per_60')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'train_eval_goals')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'test_eval_goals')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'train_eval_goals_per_gp')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'test_eval_goals_per_gp')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'train_eval_goals_per_60')
            && Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'test_eval_goals_per_60');
    }
}
