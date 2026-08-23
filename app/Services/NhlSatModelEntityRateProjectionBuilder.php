<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NhlModelRun;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Builds entity bucket-level projected SAT, SOG, and goal rates per 60 minutes.
 */
class NhlSatModelEntityRateProjectionBuilder
{
    private const OTHER_BUCKET_KEY = 'L99|other=low_volume';
    private const SKATER_OFFENSE_PROFILE_TYPE = 'skater_offense';
    private const PROFILE_INPUT_SHARE_COVERAGE = 0.95;
    private const MIN_SAT_PER_SEASON = 4;

    /**
     * Build /60 projection rows from already-built entity profiles.
     *
     * @return array<string, int>
     */
    public function build(NhlModelRun $run): array
    {
        $profileTypes = DB::table('nhl_sat_model_entity_profile_buckets')
            ->where('model_run_id', $run->id)
            ->distinct()
            ->orderBy('profile_type')
            ->pluck('profile_type')
            ->map(fn (mixed $profileType): string => (string) $profileType)
            ->all();

        if ($profileTypes === []) {
            throw new RuntimeException('Build profiles before building /60 projections.');
        }

        DB::table('nhl_sat_model_entity_rate_projection_buckets')
            ->where('model_run_id', $run->id)
            ->delete();

        $counts = [];
        $minimumSourceSat = $this->minimumSourceSat($run);

        foreach ($profileTypes as $profileType) {
            $this->insertProfileType(run: $run, profileType: $profileType, minimumSourceSat: $minimumSourceSat);

            $counts[$profileType] = DB::table('nhl_sat_model_entity_rate_projection_buckets')
                ->where('model_run_id', $run->id)
                ->where('profile_type', $profileType)
                ->count();
        }

        $counts['total'] = array_sum($counts);

        return $counts;
    }

    /**
     * Clear projection rows and list entities with built profiles.
     *
     * @return array<int, array{profile_type:string,entity_key:string}>
     */
    public function prepareBuild(NhlModelRun $run): array
    {
        DB::table('nhl_sat_model_entity_rate_projection_buckets')
            ->where('model_run_id', $run->id)
            ->delete();

        return DB::table('nhl_sat_model_entity_profile_buckets')
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
     * Build one entity's /60 projection rows.
     */
    public function buildEntity(NhlModelRun $run, string $profileType, string $entityKey): int
    {
        $this->insertProfileType(
            run: $run,
            profileType: $profileType,
            minimumSourceSat: $this->minimumSourceSat($run),
            entityKey: $entityKey
        );

        return DB::table('nhl_sat_model_entity_rate_projection_buckets')
            ->where('model_run_id', $run->id)
            ->where('profile_type', $profileType)
            ->where('entity_key', $entityKey)
            ->count();
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
            ->values()
            ->all();
    }

    private function minimumSourceSat(NhlModelRun $run): int
    {
        return self::MIN_SAT_PER_SEASON * max(1, count($this->seasonIds($run)));
    }

    private function insertSkaterOffenseProfileType(
        NhlModelRun $run,
        int $minimumSourceSat,
        ?string $entityKey = null
    ): void {
        $now = now();
        $seasonIds = $this->seasonIds($run);
        $latestTrainingSeasonId = max($seasonIds);
        $priorTrainingSeasonId = min($seasonIds);
        $gameType = (int) ($run->game_type ?? 2);
        $entityWhereSql = $entityKey === null ? '' : 'AND adjusted_rows.entity_key = ?';

        $sql = <<<SQL
INSERT INTO nhl_sat_model_entity_rate_projection_buckets (
    model_run_id,
    source_season_ids,
    game_type,
    profile_type,
    entity_key,
    entity_id,
    entity_name,
    entity_role,
    team_context,
    matched_bucket_key,
    bucket_dimensions,
    is_other_bucket,
    source_sat,
    source_sog,
    source_goals,
    source_profile_share,
    source_xsat_per_60,
    source_xsog_per_60,
    source_xg_per_60,
    peer_xsat_per_60,
    peer_profile_share,
    entity_xsat_per_60,
    peer_entity_xsat_per_60,
    overall_rate_multiplier,
    raw_tendency_multiplier,
    shrunk_tendency_multiplier,
    projected_xsat_per_60,
    projected_xsog_per_60,
    projected_xg_per_60,
    sat_probability,
    goal_probability,
    confidence_score,
    shrinkage_weight,
    confidence_bucket,
    metadata,
    projected_at,
    created_at,
    updated_at
)
WITH profile_rows AS (
    SELECT
        profiles.*,
        ROW_NUMBER() OVER (
            PARTITION BY profiles.entity_key
            ORDER BY profiles.source_profile_share DESC, profiles.source_sat DESC, profiles.matched_bucket_key
        ) as profile_share_rank,
        SUM(profiles.source_profile_share) OVER (
            PARTITION BY profiles.entity_key
            ORDER BY profiles.source_profile_share DESC, profiles.source_sat DESC, profiles.matched_bucket_key
            ROWS UNBOUNDED PRECEDING
        ) as cumulative_profile_share
    FROM nhl_sat_model_entity_profile_buckets profiles
    WHERE profiles.model_run_id = ?
        AND profiles.profile_type = ?
        AND profiles.source_xsat_per_60 IS NOT NULL
),
qualified_source_rows AS (
    SELECT
        profile_rows.*,
        (
            profile_rows.source_sat >= ?
            AND (
                profile_rows.profile_share_rank = 1
                OR profile_rows.cumulative_profile_share <= ?
                OR (profile_rows.cumulative_profile_share - profile_rows.source_profile_share) < ?
            )
        ) as is_core_projection
    FROM profile_rows
),
source_rows AS (
    SELECT
        qualified_source_rows.*,
        CASE
            WHEN qualified_source_rows.is_core_projection THEN qualified_source_rows.matched_bucket_key
            ELSE ?
        END as projection_bucket_key,
        CASE
            WHEN qualified_source_rows.is_core_projection THEN qualified_source_rows.bucket_dimensions
            ELSE json_build_object('other', 'low_volume')
        END as projection_bucket_dimensions,
        NOT qualified_source_rows.is_core_projection as is_other_projection
    FROM qualified_source_rows
),
grouped_rows AS (
    SELECT
        MAX(source_rows.model_run_id) as model_run_id,
        MAX(source_rows.source_season_ids::text)::json as source_season_ids,
        MAX(source_rows.game_type) as game_type,
        MAX(source_rows.profile_type) as profile_type,
        source_rows.entity_key,
        MAX(source_rows.entity_id) as entity_id,
        MAX(source_rows.entity_name) as entity_name,
        MAX(source_rows.entity_role) as entity_role,
        MAX(source_rows.team_context) as team_context,
        source_rows.projection_bucket_key as matched_bucket_key,
        MAX(source_rows.projection_bucket_dimensions::text)::json as bucket_dimensions,
        BOOL_OR(source_rows.is_other_projection) as is_other_bucket,
        SUM(source_rows.source_sat) as source_sat,
        SUM(source_rows.source_sog) as source_sog,
        SUM(source_rows.source_goals) as source_goals,
        ROUND(SUM(source_rows.source_profile_share)::numeric, 6) as source_profile_share,
        MAX(source_rows.source_toi_seconds) as source_toi_seconds,
        ROUND(SUM(COALESCE(source_rows.source_xsat_per_60, 0))::numeric, 4) as source_xsat_per_60,
        ROUND(SUM(COALESCE(source_rows.source_xsog_per_60, 0))::numeric, 4) as source_xsog_per_60,
        ROUND(SUM(COALESCE(source_rows.source_xg_per_60, 0))::numeric, 4) as source_xg_per_60,
        ROUND((SUM(source_rows.expected_sog)::numeric / NULLIF(SUM(source_rows.source_sat), 0)), 6) as sat_probability,
        ROUND((SUM(source_rows.expected_goals)::numeric / NULLIF(SUM(source_rows.expected_sog), 0)), 6) as goal_probability,
        ROUND((SUM(source_rows.confidence_score * source_rows.source_sat)::numeric / NULLIF(SUM(source_rows.source_sat), 0)), 4) as confidence_score,
        ROUND((SUM(source_rows.shrinkage_weight * source_rows.source_sat)::numeric / NULLIF(SUM(source_rows.source_sat), 0)), 4) as shrinkage_weight,
        MAX(source_rows.confidence_bucket) as confidence_bucket
    FROM source_rows
    GROUP BY source_rows.entity_key, source_rows.projection_bucket_key
),
core_grouped_keys AS (
    SELECT entity_key, matched_bucket_key
    FROM grouped_rows
    WHERE is_other_bucket = false
),
latest_source_rows AS (
    SELECT
        latest_profiles.*,
        CASE
            WHEN core_grouped_keys.matched_bucket_key IS NOT NULL THEN latest_profiles.matched_bucket_key
            ELSE ?
        END as projection_bucket_key
    FROM nhl_sat_model_entity_test_profile_buckets latest_profiles
    LEFT JOIN core_grouped_keys
        ON core_grouped_keys.entity_key = latest_profiles.entity_key
        AND core_grouped_keys.matched_bucket_key = latest_profiles.matched_bucket_key
    WHERE latest_profiles.model_run_id = ?
        AND latest_profiles.test_season_id = ?
        AND latest_profiles.profile_type = ?
        AND latest_profiles.source_xsat_per_60 IS NOT NULL
),
latest_grouped_rows AS (
    SELECT
        latest_source_rows.entity_key,
        latest_source_rows.projection_bucket_key as matched_bucket_key,
        SUM(latest_source_rows.source_sat) as latest_sat,
        ROUND(SUM(COALESCE(latest_source_rows.source_xsat_per_60, 0))::numeric, 4) as latest_xsat_per_60,
        ROUND(SUM(COALESCE(latest_source_rows.source_xsog_per_60, 0))::numeric, 4) as latest_xsog_per_60,
        ROUND(SUM(COALESCE(latest_source_rows.source_xg_per_60, 0))::numeric, 4) as latest_xg_per_60
    FROM latest_source_rows
    GROUP BY latest_source_rows.entity_key, latest_source_rows.projection_bucket_key
),
prior_source_rows AS (
    SELECT
        prior_profiles.*,
        CASE
            WHEN core_grouped_keys.matched_bucket_key IS NOT NULL THEN prior_profiles.matched_bucket_key
            ELSE ?
        END as projection_bucket_key
    FROM nhl_sat_model_entity_test_profile_buckets prior_profiles
    LEFT JOIN core_grouped_keys
        ON core_grouped_keys.entity_key = prior_profiles.entity_key
        AND core_grouped_keys.matched_bucket_key = prior_profiles.matched_bucket_key
    WHERE prior_profiles.model_run_id = ?
        AND prior_profiles.test_season_id = ?
        AND prior_profiles.profile_type = ?
        AND prior_profiles.source_xsat_per_60 IS NOT NULL
),
prior_grouped_rows AS (
    SELECT
        prior_source_rows.entity_key,
        prior_source_rows.projection_bucket_key as matched_bucket_key,
        SUM(prior_source_rows.source_sat) as prior_sat,
        ROUND(SUM(COALESCE(prior_source_rows.source_xsat_per_60, 0))::numeric, 4) as prior_xsat_per_60,
        ROUND(SUM(COALESCE(prior_source_rows.source_xsog_per_60, 0))::numeric, 4) as prior_xsog_per_60,
        ROUND(SUM(COALESCE(prior_source_rows.source_xg_per_60, 0))::numeric, 4) as prior_xg_per_60
    FROM prior_source_rows
    GROUP BY prior_source_rows.entity_key, prior_source_rows.projection_bucket_key
),
production_rows AS (
    SELECT
        'skater_offense:' || summaries.nhl_player_id::text as entity_key,
        MAX(UPPER(NULLIF(players.position, ''))) as player_position,
        COUNT(DISTINCT summaries.nhl_game_id) as train_games,
        SUM(COALESCE(summaries.g, 0)) as train_goals,
        SUM(COALESCE(summaries.pts, 0)) as train_points
    FROM nhl_game_summaries summaries
    INNER JOIN nhl_games games ON games.nhl_game_id = summaries.nhl_game_id
    LEFT JOIN players ON players.nhl_id = summaries.nhl_player_id
    WHERE games.season_id IN (?, ?)
        AND games.game_type = ?
    GROUP BY summaries.nhl_player_id
),
production_features AS (
    SELECT
        production_rows.entity_key,
        production_rows.player_position,
        CASE
            WHEN production_rows.player_position IN ('C', 'L', 'R') THEN 'forward'
            WHEN production_rows.player_position = 'D' THEN 'defense'
            WHEN production_rows.player_position = 'G' THEN 'goalie'
            ELSE 'unknown'
        END as position_type,
        ROUND((production_rows.train_goals::numeric / NULLIF(production_rows.train_games, 0)), 6) as avg_goal_per_game,
        ROUND((production_rows.train_points::numeric / NULLIF(production_rows.train_games, 0)), 6) as avg_points_per_game,
        CASE
            WHEN production_rows.player_position = 'D'
                AND production_rows.train_goals::numeric / NULLIF(production_rows.train_games, 0) >= 0.15 THEN 'high_goal'
            WHEN production_rows.player_position = 'D'
                AND production_rows.train_goals::numeric / NULLIF(production_rows.train_games, 0) >= 0.07 THEN 'mid_goal'
            WHEN production_rows.player_position IN ('C', 'L', 'R')
                AND production_rows.train_goals::numeric / NULLIF(production_rows.train_games, 0) >= 0.40 THEN 'high_goal'
            WHEN production_rows.player_position IN ('C', 'L', 'R')
                AND production_rows.train_goals::numeric / NULLIF(production_rows.train_games, 0) >= 0.20 THEN 'mid_goal'
            ELSE 'low_goal'
        END as goal_tier,
        CASE
            WHEN production_rows.train_points::numeric / NULLIF(production_rows.train_games, 0) >= 1.00 THEN 'elite_points'
            WHEN production_rows.train_points::numeric / NULLIF(production_rows.train_games, 0) >= 0.65 THEN 'top_points'
            WHEN production_rows.train_points::numeric / NULLIF(production_rows.train_games, 0) >= 0.35 THEN 'mid_points'
            ELSE 'low_points'
        END as point_tier
    FROM production_rows
),
bucket_rows AS (
    SELECT
        grouped_rows.*,
        COALESCE(latest_grouped_rows.latest_xsat_per_60, grouped_rows.source_xsat_per_60) as season_two_xsat_per_60,
        COALESCE(latest_grouped_rows.latest_xsog_per_60, grouped_rows.source_xsog_per_60) as season_two_xsog_per_60,
        COALESCE(latest_grouped_rows.latest_xg_per_60, grouped_rows.source_xg_per_60) as season_two_xg_per_60,
        COALESCE(prior_grouped_rows.prior_xsat_per_60, grouped_rows.source_xsat_per_60) as season_one_xsat_per_60,
        COALESCE(prior_grouped_rows.prior_xsog_per_60, grouped_rows.source_xsog_per_60) as season_one_xsog_per_60,
        COALESCE(prior_grouped_rows.prior_xg_per_60, grouped_rows.source_xg_per_60) as season_one_xg_per_60,
        COALESCE(latest_grouped_rows.latest_sat, 0) as latest_sat,
        (
            SUM(grouped_rows.source_xsat_per_60) OVER (PARTITION BY grouped_rows.matched_bucket_key)
            - grouped_rows.source_xsat_per_60
        ) / NULLIF((COUNT(*) OVER (PARTITION BY grouped_rows.matched_bucket_key)) - 1, 0) as peer_xsat_per_60,
        (
            SUM(grouped_rows.source_profile_share) OVER (PARTITION BY grouped_rows.matched_bucket_key)
            - grouped_rows.source_profile_share
        ) / NULLIF((COUNT(*) OVER (PARTITION BY grouped_rows.matched_bucket_key)) - 1, 0) as peer_profile_share
    FROM grouped_rows
    LEFT JOIN latest_grouped_rows
        ON latest_grouped_rows.entity_key = grouped_rows.entity_key
        AND latest_grouped_rows.matched_bucket_key = grouped_rows.matched_bucket_key
    LEFT JOIN prior_grouped_rows
        ON prior_grouped_rows.entity_key = grouped_rows.entity_key
        AND prior_grouped_rows.matched_bucket_key = grouped_rows.matched_bucket_key
),
bucket_preliminary_rows AS (
    SELECT
        bucket_rows.*,
        GREATEST(0, bucket_rows.season_two_xsat_per_60) as preliminary_xsat_per_60,
        GREATEST(0, bucket_rows.season_two_xsog_per_60) as preliminary_xsog_per_60,
        GREATEST(0, bucket_rows.season_two_xg_per_60) as preliminary_xg_per_60
    FROM bucket_rows
),
entity_features AS (
    SELECT
        bucket_preliminary_rows.entity_key,
        SUM(bucket_preliminary_rows.source_xsat_per_60) as entity_xsat_per_60,
        SUM(bucket_preliminary_rows.season_one_xsat_per_60) as entity_season_one_xsat_per_60,
        SUM(bucket_preliminary_rows.season_two_xsat_per_60) as entity_latest_xsat_per_60,
        SUM(bucket_preliminary_rows.preliminary_xsat_per_60) as entity_preliminary_xsat_per_60,
        COUNT(*) FILTER (WHERE bucket_preliminary_rows.latest_sat > 0 AND bucket_preliminary_rows.is_other_bucket = false) as latest_active_bucket_count
    FROM bucket_preliminary_rows
    GROUP BY bucket_preliminary_rows.entity_key
),
entity_peer_totals AS (
    SELECT
        entity_features.entity_key,
        entity_features.entity_xsat_per_60,
        (
            SUM(entity_features.entity_xsat_per_60) OVER ()
            - entity_features.entity_xsat_per_60
        ) / NULLIF((COUNT(*) OVER ()) - 1, 0) as peer_entity_xsat_per_60
    FROM entity_features
),
entity_scored_features AS (
    SELECT
        entity_features.*,
        production_features.player_position,
        production_features.position_type,
        production_features.avg_goal_per_game,
        production_features.avg_points_per_game,
        production_features.goal_tier,
        production_features.point_tier,
        CASE
            WHEN entity_features.latest_active_bucket_count <= 2 THEN 's2_buckets_0_2'
            WHEN entity_features.latest_active_bucket_count <= 6 THEN 's2_buckets_3_6'
            ELSE 's2_buckets_7_plus'
        END as bucket_count_tier
    FROM entity_features
    LEFT JOIN production_features ON production_features.entity_key = entity_features.entity_key
),
goal_tier_baselines AS (
    SELECT
        entity_scored_features.position_type,
        entity_scored_features.goal_tier,
        AVG(entity_scored_features.entity_xsat_per_60) as goal_tier_baseline_xsat_per_60
    FROM entity_scored_features
    WHERE entity_scored_features.goal_tier IS NOT NULL
    GROUP BY entity_scored_features.position_type, entity_scored_features.goal_tier
),
bucket_tier_baselines AS (
    SELECT
        entity_scored_features.bucket_count_tier,
        AVG(entity_scored_features.entity_xsat_per_60) as bucket_tier_baseline_xsat_per_60
    FROM entity_scored_features
    GROUP BY entity_scored_features.bucket_count_tier
),
entity_targets AS (
    SELECT
        entity_scored_features.*,
        goal_tier_baselines.goal_tier_baseline_xsat_per_60,
        bucket_tier_baselines.bucket_tier_baseline_xsat_per_60,
        COALESCE(goal_tier_baselines.goal_tier_baseline_xsat_per_60, entity_scored_features.entity_xsat_per_60) as cohort_xsat_per_60,
        CASE
            WHEN entity_scored_features.entity_latest_xsat_per_60 >= entity_scored_features.entity_season_one_xsat_per_60
                THEN 's2_up_or_flat'
            ELSE 's2_down'
        END as season_direction,
        CASE
            WHEN entity_scored_features.latest_active_bucket_count <= 2 THEN
                GREATEST(
                    0,
                    (0.25 * ((entity_scored_features.entity_xsat_per_60 + entity_scored_features.entity_latest_xsat_per_60) / 2))
                    + (0.75 * COALESCE(goal_tier_baselines.goal_tier_baseline_xsat_per_60, entity_scored_features.entity_xsat_per_60))
                )
            WHEN entity_scored_features.latest_active_bucket_count <= 6 THEN
                GREATEST(
                    0,
                    entity_scored_features.entity_latest_xsat_per_60
                    + (0.25 * (entity_scored_features.entity_xsat_per_60 - entity_scored_features.entity_latest_xsat_per_60))
                )
            WHEN entity_scored_features.position_type = 'forward'
                AND entity_scored_features.goal_tier = 'high_goal'
                AND entity_scored_features.entity_latest_xsat_per_60 < entity_scored_features.entity_season_one_xsat_per_60 THEN
                GREATEST(
                    0,
                    (0.90 * ((entity_scored_features.entity_xsat_per_60 + entity_scored_features.entity_latest_xsat_per_60) / 2))
                    + (0.10 * COALESCE(goal_tier_baselines.goal_tier_baseline_xsat_per_60, entity_scored_features.entity_xsat_per_60))
                )
            WHEN entity_scored_features.position_type = 'forward'
                AND entity_scored_features.goal_tier IN ('mid_goal', 'low_goal')
                AND entity_scored_features.entity_latest_xsat_per_60 < entity_scored_features.entity_season_one_xsat_per_60 THEN
                GREATEST(
                    0,
                    (0.90 * entity_scored_features.entity_latest_xsat_per_60)
                    + (0.10 * COALESCE(goal_tier_baselines.goal_tier_baseline_xsat_per_60, entity_scored_features.entity_xsat_per_60))
                )
            WHEN entity_scored_features.position_type = 'forward'
                AND entity_scored_features.goal_tier = 'mid_goal' THEN
                GREATEST(
                    0,
                    (0.70 * ((entity_scored_features.entity_xsat_per_60 + entity_scored_features.entity_latest_xsat_per_60) / 2))
                    + (0.30 * COALESCE(goal_tier_baselines.goal_tier_baseline_xsat_per_60, entity_scored_features.entity_xsat_per_60))
                )
            WHEN entity_scored_features.position_type = 'forward'
                AND entity_scored_features.goal_tier = 'low_goal' THEN
                GREATEST(
                    0,
                    entity_scored_features.entity_latest_xsat_per_60
                    - (0.50 * (entity_scored_features.entity_latest_xsat_per_60 - entity_scored_features.entity_season_one_xsat_per_60))
                )
            WHEN entity_scored_features.position_type = 'defense'
                AND entity_scored_features.goal_tier = 'mid_goal'
                AND entity_scored_features.entity_latest_xsat_per_60 < entity_scored_features.entity_season_one_xsat_per_60 THEN
                GREATEST(
                    0,
                    (0.90 * entity_scored_features.entity_latest_xsat_per_60)
                    + (0.10 * COALESCE(goal_tier_baselines.goal_tier_baseline_xsat_per_60, entity_scored_features.entity_xsat_per_60))
                )
            WHEN entity_scored_features.position_type = 'defense'
                AND entity_scored_features.goal_tier = 'mid_goal' THEN
                GREATEST(
                    0,
                    entity_scored_features.entity_latest_xsat_per_60
                    - (0.50 * (entity_scored_features.entity_latest_xsat_per_60 - entity_scored_features.entity_season_one_xsat_per_60))
                )
            WHEN entity_scored_features.position_type = 'defense'
                AND entity_scored_features.goal_tier = 'low_goal'
                AND entity_scored_features.entity_latest_xsat_per_60 < entity_scored_features.entity_season_one_xsat_per_60 THEN
                GREATEST(
                    0,
                    entity_scored_features.entity_latest_xsat_per_60
                    + (0.25 * (entity_scored_features.entity_latest_xsat_per_60 - entity_scored_features.entity_season_one_xsat_per_60))
                )
            WHEN entity_scored_features.position_type = 'defense'
                AND entity_scored_features.goal_tier = 'low_goal' THEN
                GREATEST(0, entity_scored_features.entity_season_one_xsat_per_60)
            WHEN entity_scored_features.position_type = 'defense'
                AND entity_scored_features.goal_tier = 'high_goal' THEN
                GREATEST(
                    0,
                    (0.70 * entity_scored_features.entity_latest_xsat_per_60)
                    + (0.20 * entity_scored_features.entity_xsat_per_60)
                    + (0.10 * COALESCE(goal_tier_baselines.goal_tier_baseline_xsat_per_60, entity_scored_features.entity_xsat_per_60))
                )
            ELSE
                (
                    (0.70 * entity_scored_features.entity_latest_xsat_per_60)
                    + (0.20 * entity_scored_features.entity_xsat_per_60)
                    + (0.10 * COALESCE(goal_tier_baselines.goal_tier_baseline_xsat_per_60, entity_scored_features.entity_xsat_per_60))
                )
        END as entity_target_xsat_per_60
    FROM entity_scored_features
    LEFT JOIN goal_tier_baselines
        ON goal_tier_baselines.position_type = entity_scored_features.position_type
        AND goal_tier_baselines.goal_tier = entity_scored_features.goal_tier
    LEFT JOIN bucket_tier_baselines ON bucket_tier_baselines.bucket_count_tier = entity_scored_features.bucket_count_tier
    WHERE COALESCE(entity_scored_features.player_position, '') <> 'G'
),
adjusted_rows AS (
    SELECT
        bucket_preliminary_rows.*,
        entity_peer_totals.entity_xsat_per_60,
        entity_peer_totals.peer_entity_xsat_per_60,
        entity_targets.entity_latest_xsat_per_60,
        entity_targets.entity_season_one_xsat_per_60,
        entity_targets.entity_preliminary_xsat_per_60,
        entity_targets.entity_target_xsat_per_60,
        entity_targets.player_position,
        entity_targets.position_type,
        entity_targets.avg_goal_per_game,
        entity_targets.avg_points_per_game,
        entity_targets.goal_tier,
        entity_targets.point_tier,
        entity_targets.season_direction,
        entity_targets.latest_active_bucket_count,
        entity_targets.bucket_count_tier,
        entity_targets.cohort_xsat_per_60,
        entity_targets.goal_tier_baseline_xsat_per_60,
        entity_targets.bucket_tier_baseline_xsat_per_60,
        COALESCE(entity_targets.entity_target_xsat_per_60 / NULLIF(entity_targets.entity_preliminary_xsat_per_60, 0), 1) as entity_projection_scale,
        1::numeric as overall_rate_multiplier,
        1::numeric as raw_tendency_multiplier,
        1::numeric as shrunk_tendency_multiplier,
        ROUND(
            GREATEST(
                0,
                bucket_preliminary_rows.preliminary_xsat_per_60
                    * COALESCE(entity_targets.entity_target_xsat_per_60 / NULLIF(entity_targets.entity_preliminary_xsat_per_60, 0), 1)
            )::numeric,
            4
        ) as projected_xsat_per_60,
        ROUND(
            GREATEST(
                0,
                bucket_preliminary_rows.preliminary_xsog_per_60
                    * COALESCE(entity_targets.entity_target_xsat_per_60 / NULLIF(entity_targets.entity_preliminary_xsat_per_60, 0), 1)
            )::numeric,
            4
        ) as projected_xsog_per_60,
        ROUND(
            GREATEST(
                0,
                bucket_preliminary_rows.preliminary_xg_per_60
                    * COALESCE(entity_targets.entity_target_xsat_per_60 / NULLIF(entity_targets.entity_preliminary_xsat_per_60, 0), 1)
            )::numeric,
            4
        ) as projected_xg_per_60
    FROM bucket_preliminary_rows
    INNER JOIN entity_peer_totals ON entity_peer_totals.entity_key = bucket_preliminary_rows.entity_key
    INNER JOIN entity_targets ON entity_targets.entity_key = bucket_preliminary_rows.entity_key
)
SELECT
    adjusted_rows.model_run_id,
    adjusted_rows.source_season_ids,
    adjusted_rows.game_type,
    adjusted_rows.profile_type,
    adjusted_rows.entity_key,
    adjusted_rows.entity_id,
    adjusted_rows.entity_name,
    adjusted_rows.entity_role,
    adjusted_rows.team_context,
    adjusted_rows.matched_bucket_key,
    adjusted_rows.bucket_dimensions,
    adjusted_rows.is_other_bucket,
    adjusted_rows.source_sat,
    adjusted_rows.source_sog,
    adjusted_rows.source_goals,
    adjusted_rows.source_profile_share,
    adjusted_rows.source_xsat_per_60,
    adjusted_rows.source_xsog_per_60,
    adjusted_rows.source_xg_per_60,
    ROUND(COALESCE(adjusted_rows.peer_xsat_per_60, adjusted_rows.source_xsat_per_60, 0)::numeric, 4) as peer_xsat_per_60,
    ROUND(COALESCE(adjusted_rows.peer_profile_share, adjusted_rows.source_profile_share, 0)::numeric, 6) as peer_profile_share,
    ROUND(adjusted_rows.entity_xsat_per_60::numeric, 4) as entity_xsat_per_60,
    ROUND(COALESCE(adjusted_rows.peer_entity_xsat_per_60, adjusted_rows.entity_xsat_per_60, 0)::numeric, 4) as peer_entity_xsat_per_60,
    ROUND(adjusted_rows.overall_rate_multiplier::numeric, 6) as overall_rate_multiplier,
    ROUND(adjusted_rows.raw_tendency_multiplier::numeric, 6) as raw_tendency_multiplier,
    ROUND(adjusted_rows.shrunk_tendency_multiplier::numeric, 6) as shrunk_tendency_multiplier,
    adjusted_rows.projected_xsat_per_60,
    adjusted_rows.projected_xsog_per_60,
    adjusted_rows.projected_xg_per_60,
    COALESCE(adjusted_rows.sat_probability, 0) as sat_probability,
    COALESCE(adjusted_rows.goal_probability, 0) as goal_probability,
    COALESCE(adjusted_rows.confidence_score, 0) as confidence_score,
    COALESCE(adjusted_rows.shrinkage_weight, 0) as shrinkage_weight,
    adjusted_rows.confidence_bucket,
    json_build_object(
        'source', 'entity_profile_buckets',
        'minimum_source_sat', ?::int,
        'minimum_sat_per_season', ?::int,
        'profile_input_share_coverage', ?::numeric,
        'prior_training_season_id', ?::text,
        'latest_training_season_id', ?::text,
        'formula_version', 'skater_offense_segmented_xsat_v2',
        'season_one_xsat_per_60', adjusted_rows.season_one_xsat_per_60,
        'season_two_xsat_per_60', adjusted_rows.season_two_xsat_per_60,
        'entity_train_xsat_per_60', adjusted_rows.entity_xsat_per_60,
        'entity_season_one_xsat_per_60', adjusted_rows.entity_season_one_xsat_per_60,
        'entity_latest_xsat_per_60', adjusted_rows.entity_latest_xsat_per_60,
        'entity_preliminary_xsat_per_60', adjusted_rows.entity_preliminary_xsat_per_60,
        'entity_target_xsat_per_60', adjusted_rows.entity_target_xsat_per_60,
        'entity_projection_scale', adjusted_rows.entity_projection_scale,
        'player_position', adjusted_rows.player_position,
        'position_type', adjusted_rows.position_type,
        'avg_goal_per_game', adjusted_rows.avg_goal_per_game,
        'avg_points_per_game', adjusted_rows.avg_points_per_game,
        'goal_tier', adjusted_rows.goal_tier,
        'point_tier', adjusted_rows.point_tier,
        'season_direction', adjusted_rows.season_direction,
        'latest_active_bucket_count', adjusted_rows.latest_active_bucket_count,
        'bucket_count_tier', adjusted_rows.bucket_count_tier,
        'cohort_xsat_per_60', adjusted_rows.cohort_xsat_per_60,
        'goal_tier_baseline_xsat_per_60', adjusted_rows.goal_tier_baseline_xsat_per_60,
        'bucket_tier_baseline_xsat_per_60', adjusted_rows.bucket_tier_baseline_xsat_per_60,
        'formula', 'S2 bucket shape scaled to S2 bucket-count + position + G/GP tier + S2-vs-S1 entity SAT target'
    ) as metadata,
    ?::timestamp as projected_at,
    ?::timestamp as created_at,
    ?::timestamp as updated_at
FROM adjusted_rows
WHERE adjusted_rows.source_sat > 0
    {$entityWhereSql}
ON CONFLICT (model_run_id, profile_type, entity_key, matched_bucket_key)
DO UPDATE SET
    source_season_ids = EXCLUDED.source_season_ids,
    game_type = EXCLUDED.game_type,
    entity_id = EXCLUDED.entity_id,
    entity_name = EXCLUDED.entity_name,
    entity_role = EXCLUDED.entity_role,
    team_context = EXCLUDED.team_context,
    bucket_dimensions = EXCLUDED.bucket_dimensions,
    is_other_bucket = EXCLUDED.is_other_bucket,
    source_sat = EXCLUDED.source_sat,
    source_sog = EXCLUDED.source_sog,
    source_goals = EXCLUDED.source_goals,
    source_profile_share = EXCLUDED.source_profile_share,
    source_xsat_per_60 = EXCLUDED.source_xsat_per_60,
    source_xsog_per_60 = EXCLUDED.source_xsog_per_60,
    source_xg_per_60 = EXCLUDED.source_xg_per_60,
    peer_xsat_per_60 = EXCLUDED.peer_xsat_per_60,
    peer_profile_share = EXCLUDED.peer_profile_share,
    entity_xsat_per_60 = EXCLUDED.entity_xsat_per_60,
    peer_entity_xsat_per_60 = EXCLUDED.peer_entity_xsat_per_60,
    overall_rate_multiplier = EXCLUDED.overall_rate_multiplier,
    raw_tendency_multiplier = EXCLUDED.raw_tendency_multiplier,
    shrunk_tendency_multiplier = EXCLUDED.shrunk_tendency_multiplier,
    projected_xsat_per_60 = EXCLUDED.projected_xsat_per_60,
    projected_xsog_per_60 = EXCLUDED.projected_xsog_per_60,
    projected_xg_per_60 = EXCLUDED.projected_xg_per_60,
    sat_probability = EXCLUDED.sat_probability,
    goal_probability = EXCLUDED.goal_probability,
    confidence_score = EXCLUDED.confidence_score,
    shrinkage_weight = EXCLUDED.shrinkage_weight,
    confidence_bucket = EXCLUDED.confidence_bucket,
    metadata = EXCLUDED.metadata,
    projected_at = EXCLUDED.projected_at,
    updated_at = EXCLUDED.updated_at
SQL;

        DB::statement($sql, [
            $run->id,
            self::SKATER_OFFENSE_PROFILE_TYPE,
            $minimumSourceSat,
            self::PROFILE_INPUT_SHARE_COVERAGE,
            self::PROFILE_INPUT_SHARE_COVERAGE,
            self::OTHER_BUCKET_KEY,
            self::OTHER_BUCKET_KEY,
            $run->id,
            $latestTrainingSeasonId,
            self::SKATER_OFFENSE_PROFILE_TYPE,
            self::OTHER_BUCKET_KEY,
            $run->id,
            $priorTrainingSeasonId,
            self::SKATER_OFFENSE_PROFILE_TYPE,
            $priorTrainingSeasonId,
            $latestTrainingSeasonId,
            $gameType,
            $minimumSourceSat,
            self::MIN_SAT_PER_SEASON,
            self::PROFILE_INPUT_SHARE_COVERAGE,
            $priorTrainingSeasonId,
            $latestTrainingSeasonId,
            $now,
            $now,
            $now,
            ...($entityKey === null ? [] : [$entityKey]),
        ]);
    }

    private function insertProfileType(
        NhlModelRun $run,
        string $profileType,
        int $minimumSourceSat,
        ?string $entityKey = null
    ): void {
        if ($profileType === self::SKATER_OFFENSE_PROFILE_TYPE) {
            $this->insertSkaterOffenseProfileType($run, $minimumSourceSat, $entityKey);

            return;
        }

        $now = now();
        $entityWhereSql = $entityKey === null ? '' : 'AND adjusted_rows.entity_key = ?';
        $latestTrainingSeasonId = max($this->seasonIds($run));

        $sql = <<<SQL
INSERT INTO nhl_sat_model_entity_rate_projection_buckets (
    model_run_id,
    source_season_ids,
    game_type,
    profile_type,
    entity_key,
    entity_id,
    entity_name,
    entity_role,
    team_context,
    matched_bucket_key,
    bucket_dimensions,
    is_other_bucket,
    source_sat,
    source_sog,
    source_goals,
    source_profile_share,
    source_xsat_per_60,
    source_xsog_per_60,
    source_xg_per_60,
    peer_xsat_per_60,
    peer_profile_share,
    entity_xsat_per_60,
    peer_entity_xsat_per_60,
    overall_rate_multiplier,
    raw_tendency_multiplier,
    shrunk_tendency_multiplier,
    projected_xsat_per_60,
    projected_xsog_per_60,
    projected_xg_per_60,
    sat_probability,
    goal_probability,
    confidence_score,
    shrinkage_weight,
    confidence_bucket,
    metadata,
    projected_at,
    created_at,
    updated_at
)
WITH profile_rows AS (
    SELECT
        profiles.*,
        ROW_NUMBER() OVER (
            PARTITION BY profiles.entity_key
            ORDER BY profiles.source_profile_share DESC, profiles.source_sat DESC, profiles.matched_bucket_key
        ) as profile_share_rank,
        SUM(profiles.source_profile_share) OVER (
            PARTITION BY profiles.entity_key
            ORDER BY profiles.source_profile_share DESC, profiles.source_sat DESC, profiles.matched_bucket_key
            ROWS UNBOUNDED PRECEDING
        ) as cumulative_profile_share
    FROM nhl_sat_model_entity_profile_buckets profiles
    WHERE profiles.model_run_id = ?
        AND profiles.profile_type = ?
        AND profiles.source_xsat_per_60 IS NOT NULL
),
qualified_source_rows AS (
    SELECT
        profile_rows.*,
        (
            profile_rows.source_sat >= ?
            AND (
                profile_rows.profile_share_rank = 1
                OR profile_rows.cumulative_profile_share <= ?
                OR (profile_rows.cumulative_profile_share - profile_rows.source_profile_share) < ?
            )
        ) as is_core_projection
    FROM profile_rows
),
source_rows AS (
    SELECT
        qualified_source_rows.*,
        CASE
            WHEN qualified_source_rows.is_core_projection THEN qualified_source_rows.matched_bucket_key
            ELSE ?
        END as projection_bucket_key,
        CASE
            WHEN qualified_source_rows.is_core_projection THEN qualified_source_rows.bucket_dimensions
            ELSE json_build_object('other', 'low_volume')
        END as projection_bucket_dimensions,
        NOT qualified_source_rows.is_core_projection as is_other_projection
    FROM qualified_source_rows
),
grouped_rows AS (
    SELECT
        MAX(source_rows.model_run_id) as model_run_id,
        MAX(source_rows.source_season_ids::text)::json as source_season_ids,
        MAX(source_rows.game_type) as game_type,
        MAX(source_rows.profile_type) as profile_type,
        source_rows.entity_key,
        MAX(source_rows.entity_id) as entity_id,
        MAX(source_rows.entity_name) as entity_name,
        MAX(source_rows.entity_role) as entity_role,
        MAX(source_rows.team_context) as team_context,
        source_rows.projection_bucket_key as matched_bucket_key,
        MAX(source_rows.projection_bucket_dimensions::text)::json as bucket_dimensions,
        BOOL_OR(source_rows.is_other_projection) as is_other_bucket,
        SUM(source_rows.source_sat) as source_sat,
        SUM(source_rows.source_sog) as source_sog,
        SUM(source_rows.source_goals) as source_goals,
        ROUND(SUM(source_rows.source_profile_share)::numeric, 6) as source_profile_share,
        MAX(source_rows.source_toi_seconds) as source_toi_seconds,
        ROUND(SUM(COALESCE(source_rows.source_xsat_per_60, 0))::numeric, 4) as source_xsat_per_60,
        ROUND(SUM(COALESCE(source_rows.source_xsog_per_60, 0))::numeric, 4) as source_xsog_per_60,
        ROUND(SUM(COALESCE(source_rows.source_xg_per_60, 0))::numeric, 4) as source_xg_per_60,
        ROUND((SUM(source_rows.expected_sog)::numeric / NULLIF(SUM(source_rows.source_sat), 0)), 6) as sat_probability,
        ROUND((SUM(source_rows.expected_goals)::numeric / NULLIF(SUM(source_rows.expected_sog), 0)), 6) as goal_probability,
        ROUND((SUM(source_rows.confidence_score * source_rows.source_sat)::numeric / NULLIF(SUM(source_rows.source_sat), 0)), 4) as confidence_score,
        ROUND((SUM(source_rows.shrinkage_weight * source_rows.source_sat)::numeric / NULLIF(SUM(source_rows.source_sat), 0)), 4) as shrinkage_weight,
        MAX(source_rows.confidence_bucket) as confidence_bucket
    FROM source_rows
    GROUP BY source_rows.entity_key, source_rows.projection_bucket_key
),
core_grouped_keys AS (
    SELECT entity_key, matched_bucket_key
    FROM grouped_rows
    WHERE is_other_bucket = false
),
latest_source_rows AS (
    SELECT
        latest_profiles.*,
        CASE
            WHEN core_grouped_keys.matched_bucket_key IS NOT NULL THEN latest_profiles.matched_bucket_key
            ELSE ?
        END as projection_bucket_key,
        core_grouped_keys.matched_bucket_key IS NULL as is_other_projection
    FROM nhl_sat_model_entity_test_profile_buckets latest_profiles
    LEFT JOIN core_grouped_keys
        ON core_grouped_keys.entity_key = latest_profiles.entity_key
        AND core_grouped_keys.matched_bucket_key = latest_profiles.matched_bucket_key
    WHERE latest_profiles.model_run_id = ?
        AND latest_profiles.test_season_id = ?
        AND latest_profiles.profile_type = ?
        AND latest_profiles.source_xsat_per_60 IS NOT NULL
),
latest_grouped_rows AS (
    SELECT
        latest_source_rows.entity_key,
        latest_source_rows.projection_bucket_key as matched_bucket_key,
        SUM(latest_source_rows.source_sat) as latest_sat,
        SUM(latest_source_rows.source_sog) as latest_sog,
        SUM(latest_source_rows.source_goals) as latest_goals,
        ROUND(SUM(latest_source_rows.source_profile_share)::numeric, 6) as latest_profile_share,
        MAX(latest_source_rows.source_toi_seconds) as latest_toi_seconds,
        ROUND(SUM(COALESCE(latest_source_rows.source_xsat_per_60, 0))::numeric, 4) as latest_xsat_per_60,
        ROUND(SUM(COALESCE(latest_source_rows.source_xsog_per_60, 0))::numeric, 4) as latest_xsog_per_60,
        ROUND(SUM(COALESCE(latest_source_rows.source_xg_per_60, 0))::numeric, 4) as latest_xg_per_60
    FROM latest_source_rows
    GROUP BY latest_source_rows.entity_key, latest_source_rows.projection_bucket_key
),
entity_totals AS (
    SELECT
        grouped_rows.entity_key,
        SUM(grouped_rows.source_xsat_per_60) as entity_xsat_per_60
    FROM grouped_rows
    GROUP BY grouped_rows.entity_key
),
entity_peer_totals AS (
    SELECT
        entity_totals.entity_key,
        entity_totals.entity_xsat_per_60,
        (
            SUM(entity_totals.entity_xsat_per_60) OVER ()
            - entity_totals.entity_xsat_per_60
        ) / NULLIF((COUNT(*) OVER ()) - 1, 0) as peer_entity_xsat_per_60
    FROM entity_totals
),
bucket_peer_totals AS (
    SELECT
        grouped_rows.*,
        latest_grouped_rows.latest_toi_seconds,
        latest_grouped_rows.latest_xsat_per_60,
        latest_grouped_rows.latest_xsog_per_60,
        latest_grouped_rows.latest_xg_per_60,
        (
            SUM(grouped_rows.source_xsat_per_60) OVER (PARTITION BY grouped_rows.matched_bucket_key)
            - grouped_rows.source_xsat_per_60
        ) / NULLIF((COUNT(*) OVER (PARTITION BY grouped_rows.matched_bucket_key)) - 1, 0) as peer_xsat_per_60,
        (
            SUM(grouped_rows.source_xsog_per_60) OVER (PARTITION BY grouped_rows.matched_bucket_key)
            - grouped_rows.source_xsog_per_60
        ) / NULLIF((COUNT(*) OVER (PARTITION BY grouped_rows.matched_bucket_key)) - 1, 0) as peer_xsog_per_60,
        (
            SUM(grouped_rows.source_xg_per_60) OVER (PARTITION BY grouped_rows.matched_bucket_key)
            - grouped_rows.source_xg_per_60
        ) / NULLIF((COUNT(*) OVER (PARTITION BY grouped_rows.matched_bucket_key)) - 1, 0) as peer_xg_per_60,
        (
            SUM(grouped_rows.source_profile_share) OVER (PARTITION BY grouped_rows.matched_bucket_key)
            - grouped_rows.source_profile_share
        ) / NULLIF((COUNT(*) OVER (PARTITION BY grouped_rows.matched_bucket_key)) - 1, 0) as peer_profile_share
    FROM grouped_rows
    LEFT JOIN latest_grouped_rows
        ON latest_grouped_rows.entity_key = grouped_rows.entity_key
        AND latest_grouped_rows.matched_bucket_key = grouped_rows.matched_bucket_key
),
scored_rows AS (
    SELECT
        bucket_peer_totals.*,
        entity_peer_totals.entity_xsat_per_60,
        entity_peer_totals.peer_entity_xsat_per_60,
        CASE
            WHEN COALESCE(entity_peer_totals.peer_entity_xsat_per_60, 0) > 0
                THEN entity_peer_totals.entity_xsat_per_60 / entity_peer_totals.peer_entity_xsat_per_60
            ELSE 1
        END as overall_rate_multiplier,
        CASE
            WHEN COALESCE(bucket_peer_totals.peer_profile_share, 0) > 0
                THEN bucket_peer_totals.source_profile_share / bucket_peer_totals.peer_profile_share
            ELSE 1
        END as raw_tendency_multiplier,
        LEAST(1, GREATEST(0, COALESCE(bucket_peer_totals.confidence_score, 0))) as tendency_weight
    FROM bucket_peer_totals
    INNER JOIN entity_peer_totals ON entity_peer_totals.entity_key = bucket_peer_totals.entity_key
),
projected_rows AS (
    SELECT
        scored_rows.*,
        1 + ((scored_rows.raw_tendency_multiplier - 1) * scored_rows.tendency_weight) as shrunk_tendency_multiplier,
        COALESCE(scored_rows.peer_xsat_per_60, scored_rows.source_xsat_per_60, 0)
            * scored_rows.overall_rate_multiplier
            * (1 + ((scored_rows.raw_tendency_multiplier - 1) * scored_rows.tendency_weight)) as peer_adjusted_xsat_per_60,
        COALESCE(scored_rows.peer_xsog_per_60, scored_rows.source_xsog_per_60, 0)
            * scored_rows.overall_rate_multiplier
            * (1 + ((scored_rows.raw_tendency_multiplier - 1) * scored_rows.tendency_weight)) as peer_adjusted_xsog_per_60,
        COALESCE(scored_rows.peer_xg_per_60, scored_rows.source_xg_per_60, 0)
            * scored_rows.overall_rate_multiplier
            * (1 + ((scored_rows.raw_tendency_multiplier - 1) * scored_rows.tendency_weight)) as peer_adjusted_xg_per_60
    FROM scored_rows
),
adjusted_rows AS (
    SELECT
        projected_rows.*,
        CASE
            WHEN COALESCE(projected_rows.source_toi_seconds, 0) > COALESCE(projected_rows.latest_toi_seconds, 0)
                THEN GREATEST(
                    0,
                    (
                        (projected_rows.source_xsat_per_60 * projected_rows.source_toi_seconds / 60)
                        - (COALESCE(projected_rows.latest_xsat_per_60, 0) * COALESCE(projected_rows.latest_toi_seconds, 0) / 60)
                    )
                    / NULLIF((projected_rows.source_toi_seconds - COALESCE(projected_rows.latest_toi_seconds, 0)) / 60, 0)
                )
            ELSE projected_rows.source_xsat_per_60
        END as season_one_xsat_per_60,
        CASE
            WHEN COALESCE(projected_rows.source_toi_seconds, 0) > COALESCE(projected_rows.latest_toi_seconds, 0)
                THEN GREATEST(
                    0,
                    (
                        (projected_rows.source_xsog_per_60 * projected_rows.source_toi_seconds / 60)
                        - (COALESCE(projected_rows.latest_xsog_per_60, 0) * COALESCE(projected_rows.latest_toi_seconds, 0) / 60)
                    )
                    / NULLIF((projected_rows.source_toi_seconds - COALESCE(projected_rows.latest_toi_seconds, 0)) / 60, 0)
                )
            ELSE projected_rows.source_xsog_per_60
        END as season_one_xsog_per_60,
        CASE
            WHEN COALESCE(projected_rows.source_toi_seconds, 0) > COALESCE(projected_rows.latest_toi_seconds, 0)
                THEN GREATEST(
                    0,
                    (
                        (projected_rows.source_xg_per_60 * projected_rows.source_toi_seconds / 60)
                        - (COALESCE(projected_rows.latest_xg_per_60, 0) * COALESCE(projected_rows.latest_toi_seconds, 0) / 60)
                    )
                    / NULLIF((projected_rows.source_toi_seconds - COALESCE(projected_rows.latest_toi_seconds, 0)) / 60, 0)
                )
            ELSE projected_rows.source_xg_per_60
        END as season_one_xg_per_60,
        COALESCE(projected_rows.latest_xsat_per_60, projected_rows.source_xsat_per_60) as season_two_xsat_per_60,
        COALESCE(projected_rows.latest_xsog_per_60, projected_rows.source_xsog_per_60) as season_two_xsog_per_60,
        COALESCE(projected_rows.latest_xg_per_60, projected_rows.source_xg_per_60) as season_two_xg_per_60,
        LEAST(
            ABS(COALESCE(projected_rows.latest_xsat_per_60, projected_rows.source_xsat_per_60)) * 0.20,
            GREATEST(
                ABS(COALESCE(projected_rows.latest_xsat_per_60, projected_rows.source_xsat_per_60)) * -0.20,
                -0.25 * (
                    COALESCE(projected_rows.latest_xsat_per_60, projected_rows.source_xsat_per_60)
                    - CASE
                        WHEN COALESCE(projected_rows.source_toi_seconds, 0) > COALESCE(projected_rows.latest_toi_seconds, 0)
                            THEN GREATEST(
                                0,
                                (
                                    (projected_rows.source_xsat_per_60 * projected_rows.source_toi_seconds / 60)
                                    - (COALESCE(projected_rows.latest_xsat_per_60, 0) * COALESCE(projected_rows.latest_toi_seconds, 0) / 60)
                                )
                                / NULLIF((projected_rows.source_toi_seconds - COALESCE(projected_rows.latest_toi_seconds, 0)) / 60, 0)
                            )
                        ELSE projected_rows.source_xsat_per_60
                    END
                )
            )
        ) as recent_xsat_adjustment,
        LEAST(
            ABS(COALESCE(projected_rows.latest_xsog_per_60, projected_rows.source_xsog_per_60)) * 0.20,
            GREATEST(
                ABS(COALESCE(projected_rows.latest_xsog_per_60, projected_rows.source_xsog_per_60)) * -0.20,
                -0.25 * (
                    COALESCE(projected_rows.latest_xsog_per_60, projected_rows.source_xsog_per_60)
                    - CASE
                        WHEN COALESCE(projected_rows.source_toi_seconds, 0) > COALESCE(projected_rows.latest_toi_seconds, 0)
                            THEN GREATEST(
                                0,
                                (
                                    (projected_rows.source_xsog_per_60 * projected_rows.source_toi_seconds / 60)
                                    - (COALESCE(projected_rows.latest_xsog_per_60, 0) * COALESCE(projected_rows.latest_toi_seconds, 0) / 60)
                                )
                                / NULLIF((projected_rows.source_toi_seconds - COALESCE(projected_rows.latest_toi_seconds, 0)) / 60, 0)
                            )
                        ELSE projected_rows.source_xsog_per_60
                    END
                )
            )
        ) as recent_xsog_adjustment,
        LEAST(
            ABS(COALESCE(projected_rows.latest_xg_per_60, projected_rows.source_xg_per_60)) * 0.20,
            GREATEST(
                ABS(COALESCE(projected_rows.latest_xg_per_60, projected_rows.source_xg_per_60)) * -0.20,
                -0.25 * (
                    COALESCE(projected_rows.latest_xg_per_60, projected_rows.source_xg_per_60)
                    - CASE
                        WHEN COALESCE(projected_rows.source_toi_seconds, 0) > COALESCE(projected_rows.latest_toi_seconds, 0)
                            THEN GREATEST(
                                0,
                                (
                                    (projected_rows.source_xg_per_60 * projected_rows.source_toi_seconds / 60)
                                    - (COALESCE(projected_rows.latest_xg_per_60, 0) * COALESCE(projected_rows.latest_toi_seconds, 0) / 60)
                                )
                                / NULLIF((projected_rows.source_toi_seconds - COALESCE(projected_rows.latest_toi_seconds, 0)) / 60, 0)
                            )
                        ELSE projected_rows.source_xg_per_60
                    END
                )
            )
        ) as recent_xg_adjustment,
        0::numeric as peer_xsat_adjustment,
        0::numeric as peer_xsog_adjustment,
        0::numeric as peer_xg_adjustment
    FROM projected_rows
)
SELECT
    adjusted_rows.model_run_id,
    adjusted_rows.source_season_ids,
    adjusted_rows.game_type,
    adjusted_rows.profile_type,
    adjusted_rows.entity_key,
    adjusted_rows.entity_id,
    adjusted_rows.entity_name,
    adjusted_rows.entity_role,
    adjusted_rows.team_context,
    adjusted_rows.matched_bucket_key,
    adjusted_rows.bucket_dimensions,
    adjusted_rows.is_other_bucket,
    adjusted_rows.source_sat,
    adjusted_rows.source_sog,
    adjusted_rows.source_goals,
    adjusted_rows.source_profile_share,
    adjusted_rows.source_xsat_per_60,
    adjusted_rows.source_xsog_per_60,
    adjusted_rows.source_xg_per_60,
    ROUND(COALESCE(adjusted_rows.peer_xsat_per_60, adjusted_rows.source_xsat_per_60, 0)::numeric, 4) as peer_xsat_per_60,
    ROUND(COALESCE(adjusted_rows.peer_profile_share, adjusted_rows.source_profile_share, 0)::numeric, 6) as peer_profile_share,
    ROUND(adjusted_rows.entity_xsat_per_60::numeric, 4) as entity_xsat_per_60,
    ROUND(COALESCE(adjusted_rows.peer_entity_xsat_per_60, adjusted_rows.entity_xsat_per_60, 0)::numeric, 4) as peer_entity_xsat_per_60,
    ROUND(adjusted_rows.overall_rate_multiplier::numeric, 6) as overall_rate_multiplier,
    ROUND(adjusted_rows.raw_tendency_multiplier::numeric, 6) as raw_tendency_multiplier,
    ROUND(adjusted_rows.shrunk_tendency_multiplier::numeric, 6) as shrunk_tendency_multiplier,
    ROUND(GREATEST(0, adjusted_rows.season_two_xsat_per_60 + adjusted_rows.recent_xsat_adjustment)::numeric, 4) as projected_xsat_per_60,
    ROUND(GREATEST(0, adjusted_rows.season_two_xsog_per_60 + adjusted_rows.recent_xsog_adjustment)::numeric, 4) as projected_xsog_per_60,
    ROUND(GREATEST(0, adjusted_rows.season_two_xg_per_60 + adjusted_rows.recent_xg_adjustment)::numeric, 4) as projected_xg_per_60,
    COALESCE(adjusted_rows.sat_probability, 0) as sat_probability,
    COALESCE(adjusted_rows.goal_probability, 0) as goal_probability,
    COALESCE(adjusted_rows.confidence_score, 0) as confidence_score,
    COALESCE(adjusted_rows.shrinkage_weight, 0) as shrinkage_weight,
    adjusted_rows.confidence_bucket,
    json_build_object(
        'source', 'entity_profile_buckets',
        'minimum_source_sat', ?::int,
        'minimum_sat_per_season', ?::int,
        'profile_input_share_coverage', ?::numeric,
        'latest_training_season_id', ?::text,
        'formula_version', 's2_partial_reversal_v1',
        'season_one_xsat_per_60', adjusted_rows.season_one_xsat_per_60,
        'season_one_xsog_per_60', adjusted_rows.season_one_xsog_per_60,
        'season_one_xg_per_60', adjusted_rows.season_one_xg_per_60,
        'season_two_xsat_per_60', adjusted_rows.season_two_xsat_per_60,
        'season_two_xsog_per_60', adjusted_rows.season_two_xsog_per_60,
        'season_two_xg_per_60', adjusted_rows.season_two_xg_per_60,
        'latest_xsat_per_60', adjusted_rows.latest_xsat_per_60,
        'latest_xsog_per_60', adjusted_rows.latest_xsog_per_60,
        'latest_xg_per_60', adjusted_rows.latest_xg_per_60,
        'peer_adjusted_xsat_per_60', adjusted_rows.peer_adjusted_xsat_per_60,
        'peer_adjusted_xsog_per_60', adjusted_rows.peer_adjusted_xsog_per_60,
        'peer_adjusted_xg_per_60', adjusted_rows.peer_adjusted_xg_per_60,
        'recent_xsat_adjustment', adjusted_rows.recent_xsat_adjustment,
        'recent_xsog_adjustment', adjusted_rows.recent_xsog_adjustment,
        'recent_xg_adjustment', adjusted_rows.recent_xg_adjustment,
        'peer_xsat_adjustment', adjusted_rows.peer_xsat_adjustment,
        'peer_xsog_adjustment', adjusted_rows.peer_xsog_adjustment,
        'peer_xg_adjustment', adjusted_rows.peer_xg_adjustment,
        'formula', 'season_two_rate + capped(-0.25 * (season_two_rate - season_one_rate), +/-20% of season_two_rate)'
    ) as metadata,
    ?::timestamp as projected_at,
    ?::timestamp as created_at,
    ?::timestamp as updated_at
FROM adjusted_rows
WHERE adjusted_rows.source_sat > 0
    {$entityWhereSql}
ON CONFLICT (model_run_id, profile_type, entity_key, matched_bucket_key)
DO UPDATE SET
    source_season_ids = EXCLUDED.source_season_ids,
    game_type = EXCLUDED.game_type,
    entity_id = EXCLUDED.entity_id,
    entity_name = EXCLUDED.entity_name,
    entity_role = EXCLUDED.entity_role,
    team_context = EXCLUDED.team_context,
    bucket_dimensions = EXCLUDED.bucket_dimensions,
    is_other_bucket = EXCLUDED.is_other_bucket,
    source_sat = EXCLUDED.source_sat,
    source_sog = EXCLUDED.source_sog,
    source_goals = EXCLUDED.source_goals,
    source_profile_share = EXCLUDED.source_profile_share,
    source_xsat_per_60 = EXCLUDED.source_xsat_per_60,
    source_xsog_per_60 = EXCLUDED.source_xsog_per_60,
    source_xg_per_60 = EXCLUDED.source_xg_per_60,
    peer_xsat_per_60 = EXCLUDED.peer_xsat_per_60,
    peer_profile_share = EXCLUDED.peer_profile_share,
    entity_xsat_per_60 = EXCLUDED.entity_xsat_per_60,
    peer_entity_xsat_per_60 = EXCLUDED.peer_entity_xsat_per_60,
    overall_rate_multiplier = EXCLUDED.overall_rate_multiplier,
    raw_tendency_multiplier = EXCLUDED.raw_tendency_multiplier,
    shrunk_tendency_multiplier = EXCLUDED.shrunk_tendency_multiplier,
    projected_xsat_per_60 = EXCLUDED.projected_xsat_per_60,
    projected_xsog_per_60 = EXCLUDED.projected_xsog_per_60,
    projected_xg_per_60 = EXCLUDED.projected_xg_per_60,
    sat_probability = EXCLUDED.sat_probability,
    goal_probability = EXCLUDED.goal_probability,
    confidence_score = EXCLUDED.confidence_score,
    shrinkage_weight = EXCLUDED.shrinkage_weight,
    confidence_bucket = EXCLUDED.confidence_bucket,
    metadata = EXCLUDED.metadata,
    projected_at = EXCLUDED.projected_at,
    updated_at = EXCLUDED.updated_at
SQL;

        DB::statement($sql, [
            $run->id,
            $profileType,
            $minimumSourceSat,
            self::PROFILE_INPUT_SHARE_COVERAGE,
            self::PROFILE_INPUT_SHARE_COVERAGE,
            self::OTHER_BUCKET_KEY,
            self::OTHER_BUCKET_KEY,
            $run->id,
            $latestTrainingSeasonId,
            $profileType,
            $minimumSourceSat,
            self::MIN_SAT_PER_SEASON,
            self::PROFILE_INPUT_SHARE_COVERAGE,
            $latestTrainingSeasonId,
            $now,
            $now,
            $now,
            ...($entityKey === null ? [] : [$entityKey]),
        ]);
    }
}
