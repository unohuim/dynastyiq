<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NhlExpectedGoalsModel;
use App\Models\NhlModelRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
    private const HIGH_DANGER_GOAL_PROBABILITY = 0.10;

    /**
     * Build /60 projection rows from already-built entity profiles.
     *
     * @return array<string, int>
     */
    public function build(NhlModelRun $run): array
    {
        $profileTypes = DB::table('nhl_sat_model_entity_profile_buckets')
            ->where('model_run_id', $run->id)
            ->where('profile_type', self::SKATER_OFFENSE_PROFILE_TYPE)
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

        if (Schema::hasTable('nhl_sat_model_entity_rate_projection_splits')) {
            DB::table('nhl_sat_model_entity_rate_projection_splits')
                ->where('model_run_id', $run->id)
                ->delete();
        }

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

        if (Schema::hasTable('nhl_sat_model_entity_rate_projection_splits')) {
            DB::table('nhl_sat_model_entity_rate_projection_splits')
                ->where('model_run_id', $run->id)
                ->delete();
        }

        return DB::table('nhl_sat_model_entity_profile_buckets')
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
     * Build one entity's /60 projection rows.
     */
    public function buildEntity(NhlModelRun $run, string $profileType, string $entityKey): int
    {
        if ($profileType !== self::SKATER_OFFENSE_PROFILE_TYPE) {
            return 0;
        }

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
        $latestTrainingMarchDate = mb_substr($latestTrainingSeasonId, 4, 4) . '-03-01';
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
latest_late_toi_features AS (
    SELECT
        'skater_offense:' || summaries.nhl_player_id::text as entity_key,
        COUNT(DISTINCT summaries.nhl_game_id) FILTER (WHERE games.game_date < ?::date) as pre_march_games,
        COUNT(DISTINCT summaries.nhl_game_id) FILTER (WHERE games.game_date >= ?::date) as late_games,
        SUM(summaries.toi) FILTER (WHERE games.game_date < ?::date) as pre_march_toi_seconds,
        SUM(summaries.toi) FILTER (WHERE games.game_date >= ?::date) as late_toi_seconds
    FROM nhl_game_summaries summaries
    INNER JOIN nhl_games games ON games.nhl_game_id = summaries.nhl_game_id
    WHERE games.season_id = ?
        AND games.game_type = ?
        AND summaries.toi IS NOT NULL
    GROUP BY summaries.nhl_player_id
),
latest_late_sat_features AS (
    SELECT
        'skater_offense:' || facts.shooter_player_id::text as entity_key,
        COUNT(*) FILTER (WHERE games.game_date < ?::date) as pre_march_sat,
        COUNT(*) FILTER (WHERE games.game_date >= ?::date) as late_sat
    FROM nhl_shot_attempts_facts facts
    INNER JOIN nhl_games games ON games.nhl_game_id = facts.nhl_game_id
    WHERE games.season_id = ?
        AND games.game_type = ?
        AND facts.shooter_player_id IS NOT NULL
        AND COALESCE(facts.period_type, '') <> 'SO'
        AND COALESCE(facts.is_empty_net, false) = false
        AND COALESCE(NULLIF(facts.shot_type_bucket, ''), 'unknown') <> 'unknown'
    GROUP BY facts.shooter_player_id
),
latest_late_rate_features AS (
    SELECT
        latest_late_toi_features.entity_key,
        latest_late_toi_features.pre_march_games,
        latest_late_toi_features.late_games,
        latest_late_toi_features.pre_march_toi_seconds,
        latest_late_toi_features.late_toi_seconds,
        latest_late_sat_features.pre_march_sat,
        latest_late_sat_features.late_sat,
        ROUND((latest_late_sat_features.pre_march_sat::numeric / NULLIF(latest_late_toi_features.pre_march_toi_seconds, 0)) * 3600, 4) as pre_march_sat_per_60,
        ROUND((latest_late_sat_features.late_sat::numeric / NULLIF(latest_late_toi_features.late_toi_seconds, 0)) * 3600, 4) as late_sat_per_60,
        ROUND(latest_late_sat_features.pre_march_sat::numeric / NULLIF(latest_late_toi_features.pre_march_games, 0), 4) as pre_march_sat_per_game,
        ROUND(latest_late_sat_features.late_sat::numeric / NULLIF(latest_late_toi_features.late_games, 0), 4) as late_sat_per_game
    FROM latest_late_toi_features
    LEFT JOIN latest_late_sat_features ON latest_late_sat_features.entity_key = latest_late_toi_features.entity_key
),
latest_late_signals AS (
    SELECT
        latest_late_rate_features.*,
        ROUND((latest_late_rate_features.late_sat_per_60 - latest_late_rate_features.pre_march_sat_per_60)::numeric, 4) as late_sat_per_60_delta,
        ROUND((latest_late_rate_features.late_sat_per_game - latest_late_rate_features.pre_march_sat_per_game)::numeric, 4) as late_sat_per_game_delta,
        CASE
            WHEN latest_late_rate_features.late_games < 8
                OR latest_late_rate_features.pre_march_games < 20
                OR latest_late_rate_features.late_sat_per_60 IS NULL
                OR latest_late_rate_features.pre_march_sat_per_60 IS NULL THEN 'late_sat_insufficient'
            WHEN latest_late_rate_features.late_sat_per_60 - latest_late_rate_features.pre_march_sat_per_60 > 2 THEN 'late_sat_spike'
            WHEN latest_late_rate_features.late_sat_per_60 - latest_late_rate_features.pre_march_sat_per_60 < -2 THEN 'late_sat_drop'
            ELSE 'late_sat_stable'
        END as late_sat_signal,
        CASE
            WHEN latest_late_rate_features.late_games < 8
                OR latest_late_rate_features.pre_march_games < 20
                OR latest_late_rate_features.late_sat_per_60 IS NULL
                OR latest_late_rate_features.pre_march_sat_per_60 IS NULL THEN 0::numeric
            WHEN latest_late_rate_features.late_sat_per_60 - latest_late_rate_features.pre_march_sat_per_60 > 2
                THEN -1 * LEAST(0.25, ABS(latest_late_rate_features.late_sat_per_60 - latest_late_rate_features.pre_march_sat_per_60) * 0.05)
            WHEN latest_late_rate_features.late_sat_per_60 - latest_late_rate_features.pre_march_sat_per_60 < -2
                THEN GREATEST(-0.50, (latest_late_rate_features.late_sat_per_60 - latest_late_rate_features.pre_march_sat_per_60) * 0.10)
            ELSE 0::numeric
        END as late_sat_adjustment_xsat_per_60,
        CASE
            WHEN latest_late_rate_features.late_games < 8
                OR latest_late_rate_features.pre_march_games < 20
                OR latest_late_rate_features.late_sat_per_60 IS NULL
                OR latest_late_rate_features.pre_march_sat_per_60 IS NULL THEN -0.08
            WHEN ABS(latest_late_rate_features.late_sat_per_60 - latest_late_rate_features.pre_march_sat_per_60) <= 2 THEN 0.03
            ELSE -0.05
        END as late_sat_confidence_adjustment
    FROM latest_late_rate_features
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
        latest_late_signals.pre_march_games,
        latest_late_signals.late_games,
        latest_late_signals.pre_march_sat_per_60,
        latest_late_signals.late_sat_per_60,
        latest_late_signals.late_sat_per_60_delta,
        latest_late_signals.pre_march_sat_per_game,
        latest_late_signals.late_sat_per_game,
        latest_late_signals.late_sat_per_game_delta,
        latest_late_signals.late_sat_signal,
        COALESCE(latest_late_signals.late_sat_adjustment_xsat_per_60, 0) as late_sat_adjustment_xsat_per_60,
        COALESCE(latest_late_signals.late_sat_confidence_adjustment, 0) as late_sat_confidence_adjustment,
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
    LEFT JOIN latest_late_signals ON latest_late_signals.entity_key = entity_scored_features.entity_key
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
        entity_targets.pre_march_games,
        entity_targets.late_games,
        entity_targets.pre_march_sat_per_60,
        entity_targets.late_sat_per_60,
        entity_targets.late_sat_per_60_delta,
        entity_targets.pre_march_sat_per_game,
        entity_targets.late_sat_per_game,
        entity_targets.late_sat_per_game_delta,
        entity_targets.late_sat_signal,
        entity_targets.late_sat_adjustment_xsat_per_60,
        entity_targets.late_sat_confidence_adjustment,
        COALESCE(
            GREATEST(0, entity_targets.entity_target_xsat_per_60 + entity_targets.late_sat_adjustment_xsat_per_60)
                / NULLIF(entity_targets.entity_preliminary_xsat_per_60, 0),
            1
        ) as entity_projection_scale,
        1::numeric as overall_rate_multiplier,
        1::numeric as raw_tendency_multiplier,
        1::numeric as shrunk_tendency_multiplier,
        ROUND(
            GREATEST(
                0,
                bucket_preliminary_rows.preliminary_xsat_per_60
                    * COALESCE(
                        GREATEST(0, entity_targets.entity_target_xsat_per_60 + entity_targets.late_sat_adjustment_xsat_per_60)
                            / NULLIF(entity_targets.entity_preliminary_xsat_per_60, 0),
                        1
                    )
            )::numeric,
            4
        ) as projected_xsat_per_60,
        ROUND(
            GREATEST(
                0,
                bucket_preliminary_rows.preliminary_xsog_per_60
                    * COALESCE(
                        GREATEST(0, entity_targets.entity_target_xsat_per_60 + entity_targets.late_sat_adjustment_xsat_per_60)
                            / NULLIF(entity_targets.entity_preliminary_xsat_per_60, 0),
                        1
                    )
            )::numeric,
            4
        ) as projected_xsog_per_60,
        ROUND(
            GREATEST(
                0,
                bucket_preliminary_rows.preliminary_xg_per_60
                    * COALESCE(
                        GREATEST(0, entity_targets.entity_target_xsat_per_60 + entity_targets.late_sat_adjustment_xsat_per_60)
                            / NULLIF(entity_targets.entity_preliminary_xsat_per_60, 0),
                        1
                    )
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
    LEAST(1, GREATEST(0, COALESCE(adjusted_rows.confidence_score, 0) + adjusted_rows.late_sat_confidence_adjustment)) as confidence_score,
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
        'pre_march_games', adjusted_rows.pre_march_games,
        'late_games', adjusted_rows.late_games,
        'pre_march_sat60', adjusted_rows.pre_march_sat_per_60,
        'late_sat60', adjusted_rows.late_sat_per_60,
        'late_sat60_delta', adjusted_rows.late_sat_per_60_delta,
        'pre_march_sat_gp', adjusted_rows.pre_march_sat_per_game,
        'late_sat_gp', adjusted_rows.late_sat_per_game,
        'late_sat_gp_delta', adjusted_rows.late_sat_per_game_delta,
        'late_sat_signal', adjusted_rows.late_sat_signal,
        'late_sat_adjustment_xsat_per_60', adjusted_rows.late_sat_adjustment_xsat_per_60,
        'late_sat_confidence_adjustment', adjusted_rows.late_sat_confidence_adjustment,
        'formula', 'S2 bucket shape scaled to S2 bucket-count + position + G/GP tier + S2-vs-S1 entity SAT target, with conservative late-season SAT signal adjustment'
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
            $latestTrainingMarchDate,
            $latestTrainingMarchDate,
            $latestTrainingMarchDate,
            $latestTrainingMarchDate,
            $latestTrainingSeasonId,
            $gameType,
            $latestTrainingMarchDate,
            $latestTrainingMarchDate,
            $latestTrainingSeasonId,
            $gameType,
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

        $goalModel = $this->goalModelForRun($run);

        if ($goalModel !== null) {
            foreach ($seasonIds as $sourceSeasonId) {
                $this->refreshGameSummaryHighDangerSat($sourceSeasonId, $gameType, $entityKey, $goalModel);
            }
        }

        $this->insertSkaterOffenseProjectionSplits($run, $entityKey);
    }

    private function insertSkaterOffenseProjectionSplits(NhlModelRun $run, ?string $entityKey = null): void
    {
        if (
            ! Schema::hasTable('nhl_sat_model_entity_rate_projection_splits')
            || ! Schema::hasColumn('nhl_game_summaries', 'hdsat')
            || ! $this->hasGameSummarySplitHighDangerSatColumns()
        ) {
            return;
        }

        $seasonIds = $this->seasonIds($run);

        if ($seasonIds === []) {
            return;
        }

        $priorTrainingSeasonId = min($seasonIds);
        $latestTrainingSeasonId = max($seasonIds);
        $trainSeasonCount = max(1, count($seasonIds));
        $trainSeasonPlaceholders = implode(', ', array_fill(0, count($seasonIds), '?'));
        $targetSeasonId = (string) ($run->target_season_id ?? $latestTrainingSeasonId);
        $gameType = (int) ($run->game_type ?? 2);
        $now = now();
        $entityWhereSql = $entityKey === null ? '' : 'AND projection_entities.entity_key = ?';
        $latestTrainingSeasonStartYear = (int) mb_substr((string) $latestTrainingSeasonId, 0, 4);
        $targetSeasonStartYear = (int) mb_substr($targetSeasonId, 0, 4);

        $sql = <<<SQL
INSERT INTO nhl_sat_model_entity_rate_projection_splits (
    model_run_id,
    profile_type,
    entity_key,
    entity_id,
    entity_name,
    entity_role,
    team_context,
    situation,
    age_group,
    sat_momentum_bucket,
    hdsat_momentum_bucket,
    toi_momentum_bucket,
    sh_regression_bucket,
    s1_gp,
    s2_gp,
    train_gp_per_season,
    projected_gp,
    s1_toi_seconds,
    s2_toi_seconds,
    train_toi_seconds,
    s1_toi_per_gp,
    s2_toi_per_gp,
    train_toi_per_gp,
    projected_toi_per_gp,
    s1_sat,
    s2_sat,
    train_sat,
    s1_sat_per_gp,
    s2_sat_per_gp,
    train_sat_per_gp,
    projected_sat_per_gp,
    s1_sat_per_60,
    s2_sat_per_60,
    train_sat_per_60,
    projected_sat_per_60,
    projected_sat_season,
    s1_hdsat,
    s2_hdsat,
    train_hdsat,
    s1_hdsat_per_gp,
    s2_hdsat_per_gp,
    train_hdsat_per_gp,
    projected_hdsat_per_gp,
    s1_hdsat_per_60,
    s2_hdsat_per_60,
    train_hdsat_per_60,
    projected_hdsat_per_60,
    s1_hdsat_sat_rate,
    s2_hdsat_sat_rate,
    train_hdsat_sat_rate,
    projected_hdsat_sat_rate,
    projected_hdsat_season,
    s1_sog,
    s2_sog,
    train_sog,
    s1_goals,
    s2_goals,
    train_goals,
    s1_sh_pct,
    s2_sh_pct,
    train_sh_pct,
    formula_version,
    formula_segment,
    metadata,
    projected_at,
    created_at,
    updated_at
)
WITH projection_entities AS (
    SELECT
        profile_type,
        entity_key,
        MAX(entity_id) as entity_id,
        MAX(entity_name) as entity_name,
        MAX(entity_role) as entity_role,
        MAX(team_context) as team_context,
        SUM(projected_xsat_per_60) as anchor_projected_sat_per_60
    FROM nhl_sat_model_entity_rate_projection_buckets
    WHERE model_run_id = ?
        AND profile_type = ?
    GROUP BY profile_type, entity_key
),
eligible_entities AS (
    SELECT
        projection_entities.*,
        players.position,
        CASE
            WHEN players.dob IS NULL THEN NULL
            ELSE EXTRACT(YEAR FROM age(make_date(?::int, 9, 15), players.dob))::int
        END as target_age,
        CASE
            WHEN players.dob IS NULL THEN NULL
            ELSE EXTRACT(YEAR FROM age(make_date(?::int, 9, 15), players.dob))::int
        END as latest_train_age,
        players.pos_type
    FROM projection_entities
    LEFT JOIN players ON players.nhl_id = projection_entities.entity_id
    WHERE COALESCE(players.position, '') <> 'G'
        {$entityWhereSql}
),
situations AS (
    SELECT * FROM (VALUES
        ('all'::varchar, NULL::varchar),
        ('ev'::varchar, 'EV'::varchar),
        ('pp'::varchar, 'PP'::varchar),
        ('pk'::varchar, 'PK'::varchar)
    ) as values(situation, strength)
),
latest_player_teams AS (
    SELECT DISTINCT ON (summaries.nhl_player_id)
        summaries.nhl_player_id,
        summaries.nhl_team_id
    FROM nhl_game_summaries summaries
    INNER JOIN nhl_games games ON games.nhl_game_id = summaries.nhl_game_id
    INNER JOIN players ON players.nhl_id = summaries.nhl_player_id
    WHERE games.season_id = ?
        AND games.game_type = ?
        AND players.pos_type = 'F'
    GROUP BY summaries.nhl_player_id, summaries.nhl_team_id
    ORDER BY summaries.nhl_player_id, COUNT(*) DESC, summaries.nhl_team_id
),
latest_forward_stats AS (
    SELECT
        ('skater_offense:' || players.nhl_id::text)::varchar as entity_key,
        latest_player_teams.nhl_team_id,
        COUNT(DISTINCT summaries.nhl_game_id) as latest_train_gp,
        SUM(COALESCE(summaries.pts, 0)) as latest_train_points,
        SUM(COALESCE(summaries.g, 0)) as latest_train_goals,
        SUM(COALESCE(summaries.sog, 0)) as latest_train_sog,
        SUM(COALESCE(summaries.sat, 0)) as latest_train_sat,
        SUM(COALESCE(summaries.hdsat, 0)) as latest_train_hdsat,
        SUM(COALESCE(summaries.pksat, 0)) as latest_train_pk_sat,
        SUM(COALESCE(pk_strength_summaries.toi, 0)) as latest_train_pk_toi_seconds,
        ROUND((SUM(COALESCE(summaries.pts, 0))::numeric / NULLIF(COUNT(DISTINCT summaries.nhl_game_id), 0)), 6) as latest_train_points_per_gp,
        ROUND((SUM(COALESCE(summaries.g, 0))::numeric / NULLIF(COUNT(DISTINCT summaries.nhl_game_id), 0)), 6) as latest_train_goals_per_gp,
        ROUND((SUM(COALESCE(summaries.sog, 0))::numeric / NULLIF(COUNT(DISTINCT summaries.nhl_game_id), 0)), 6) as latest_train_sog_per_gp,
        ROUND((SUM(COALESCE(summaries.sat, 0))::numeric / NULLIF(COUNT(DISTINCT summaries.nhl_game_id), 0)), 6) as latest_train_sat_per_gp,
        ROUND((SUM(COALESCE(summaries.hdsat, 0))::numeric / NULLIF(COUNT(DISTINCT summaries.nhl_game_id), 0)), 6) as latest_train_hdsat_per_gp,
        ROUND((SUM(COALESCE(pk_strength_summaries.toi, 0))::numeric / NULLIF(COUNT(DISTINCT summaries.nhl_game_id), 0)), 6) as latest_train_pk_toi_per_gp
    FROM players
    INNER JOIN latest_player_teams ON latest_player_teams.nhl_player_id = players.nhl_id
    INNER JOIN nhl_game_summaries summaries ON summaries.nhl_player_id = players.nhl_id
    INNER JOIN nhl_games games ON games.nhl_game_id = summaries.nhl_game_id
    LEFT JOIN nhl_player_game_strength_summaries pk_strength_summaries
        ON pk_strength_summaries.nhl_game_id = summaries.nhl_game_id
        AND pk_strength_summaries.nhl_player_id = summaries.nhl_player_id
        AND pk_strength_summaries.strength = 'PK'
    WHERE games.season_id = ?
        AND games.game_type = ?
        AND players.pos_type = 'F'
    GROUP BY players.nhl_id, latest_player_teams.nhl_team_id
),
latest_forward_pk_toi_top_400 AS (
    SELECT
        latest_forward_stats.entity_key,
        ROW_NUMBER() OVER (
            ORDER BY latest_forward_stats.latest_train_pk_toi_seconds DESC,
                latest_forward_stats.latest_train_pk_sat DESC,
                latest_forward_stats.latest_train_gp DESC
        ) as latest_forward_pk_toi_rank
    FROM latest_forward_stats
),
latest_forward_pts_gp_top_400 AS (
    SELECT
        latest_forward_stats.entity_key,
        ROW_NUMBER() OVER (
            ORDER BY latest_forward_stats.latest_train_points_per_gp DESC NULLS LAST,
                latest_forward_stats.latest_train_points DESC,
                latest_forward_stats.latest_train_gp DESC
        ) as latest_forward_points_per_gp_rank,
        ROW_NUMBER() OVER (
            PARTITION BY latest_forward_stats.nhl_team_id
            ORDER BY latest_forward_stats.latest_train_points_per_gp DESC NULLS LAST,
                latest_forward_stats.latest_train_points DESC,
                latest_forward_stats.latest_train_gp DESC
        ) as latest_team_forward_qualified_points_per_gp_rank
    FROM latest_forward_stats
    WHERE latest_forward_stats.latest_train_gp >= 25
),
latest_forward_total_points_top_400 AS (
    SELECT
        latest_forward_stats.entity_key,
        ROW_NUMBER() OVER (
            ORDER BY latest_forward_stats.latest_train_points DESC,
                latest_forward_stats.latest_train_points_per_gp DESC NULLS LAST,
                latest_forward_stats.latest_train_gp DESC
        ) as latest_forward_total_points_rank
    FROM latest_forward_stats
),
latest_forward_team_ranks AS (
    SELECT
        latest_forward_stats.*,
        latest_forward_pts_gp_top_400.latest_forward_points_per_gp_rank,
        latest_forward_pts_gp_top_400.latest_team_forward_qualified_points_per_gp_rank,
        latest_forward_total_points_top_400.latest_forward_total_points_rank,
        latest_forward_pk_toi_top_400.latest_forward_pk_toi_rank,
        ROW_NUMBER() OVER (
            PARTITION BY latest_forward_stats.nhl_team_id
            ORDER BY latest_forward_stats.latest_train_points_per_gp DESC NULLS LAST,
                latest_forward_stats.latest_train_points DESC
        ) as latest_team_forward_points_per_gp_rank,
        ROW_NUMBER() OVER (
            PARTITION BY latest_forward_stats.nhl_team_id
            ORDER BY latest_forward_stats.latest_train_points DESC,
                latest_forward_stats.latest_train_points_per_gp DESC NULLS LAST
        ) as latest_team_forward_total_points_rank,
        ROW_NUMBER() OVER (
            PARTITION BY latest_forward_stats.nhl_team_id
            ORDER BY latest_forward_stats.latest_train_sat_per_gp DESC NULLS LAST,
                latest_forward_stats.latest_train_sat DESC
        ) as latest_team_forward_sat_per_gp_rank,
        ROW_NUMBER() OVER (
            PARTITION BY latest_forward_stats.nhl_team_id
            ORDER BY latest_forward_stats.latest_train_sog_per_gp DESC NULLS LAST,
                latest_forward_stats.latest_train_sog DESC
        ) as latest_team_forward_sog_per_gp_rank,
        ROW_NUMBER() OVER (
            PARTITION BY latest_forward_stats.nhl_team_id
            ORDER BY latest_forward_stats.latest_train_hdsat_per_gp DESC NULLS LAST,
                latest_forward_stats.latest_train_hdsat DESC
        ) as latest_team_forward_hdsat_per_gp_rank
    FROM latest_forward_stats
    LEFT JOIN latest_forward_pts_gp_top_400
        ON latest_forward_pts_gp_top_400.entity_key = latest_forward_stats.entity_key
    LEFT JOIN latest_forward_total_points_top_400
        ON latest_forward_total_points_top_400.entity_key = latest_forward_stats.entity_key
    LEFT JOIN latest_forward_pk_toi_top_400
        ON latest_forward_pk_toi_top_400.entity_key = latest_forward_stats.entity_key
),
latest_defense_player_teams AS (
    SELECT DISTINCT ON (summaries.nhl_player_id)
        summaries.nhl_player_id,
        summaries.nhl_team_id
    FROM nhl_game_summaries summaries
    INNER JOIN nhl_games games ON games.nhl_game_id = summaries.nhl_game_id
    INNER JOIN players ON players.nhl_id = summaries.nhl_player_id
    WHERE games.season_id = ?
        AND games.game_type = ?
        AND players.pos_type = 'D'
    GROUP BY summaries.nhl_player_id, summaries.nhl_team_id
    ORDER BY summaries.nhl_player_id, COUNT(*) DESC, summaries.nhl_team_id
),
latest_defense_stats AS (
    SELECT
        ('skater_offense:' || players.nhl_id::text)::varchar as entity_key,
        latest_defense_player_teams.nhl_team_id,
        COUNT(DISTINCT summaries.nhl_game_id) as latest_defense_train_gp,
        SUM(COALESCE(summaries.pts, 0)) as latest_defense_train_points,
        SUM(COALESCE(summaries.ppp, 0)) as latest_defense_train_pp_points,
        SUM(COALESCE(summaries.ppsog, 0)) as latest_defense_train_pp_sog,
        SUM(COALESCE(summaries.ppsat, 0)) as latest_defense_train_pp_sat,
        SUM(COALESCE(summaries.pksat, 0)) as latest_defense_train_pk_sat,
        SUM(COALESCE(pk_strength_summaries.toi, 0)) as latest_defense_train_pk_toi_seconds,
        ROUND((SUM(COALESCE(summaries.pts, 0))::numeric / NULLIF(COUNT(DISTINCT summaries.nhl_game_id), 0)), 6) as latest_defense_points_per_gp,
        ROUND((SUM(COALESCE(summaries.ppp, 0))::numeric / NULLIF(COUNT(DISTINCT summaries.nhl_game_id), 0)), 6) as latest_defense_pp_points_per_gp,
        ROUND((SUM(COALESCE(summaries.ppsog, 0))::numeric / NULLIF(COUNT(DISTINCT summaries.nhl_game_id), 0)), 6) as latest_defense_pp_sog_per_gp,
        ROUND((SUM(COALESCE(summaries.ppsat, 0))::numeric / NULLIF(COUNT(DISTINCT summaries.nhl_game_id), 0)), 6) as latest_defense_pp_sat_per_gp,
        ROUND((SUM(COALESCE(pk_strength_summaries.toi, 0))::numeric / NULLIF(COUNT(DISTINCT summaries.nhl_game_id), 0)), 6) as latest_defense_pk_toi_per_gp
    FROM players
    INNER JOIN latest_defense_player_teams ON latest_defense_player_teams.nhl_player_id = players.nhl_id
    INNER JOIN nhl_game_summaries summaries ON summaries.nhl_player_id = players.nhl_id
    INNER JOIN nhl_games games ON games.nhl_game_id = summaries.nhl_game_id
    LEFT JOIN nhl_player_game_strength_summaries pk_strength_summaries
        ON pk_strength_summaries.nhl_game_id = summaries.nhl_game_id
        AND pk_strength_summaries.nhl_player_id = summaries.nhl_player_id
        AND pk_strength_summaries.strength = 'PK'
    WHERE games.season_id = ?
        AND games.game_type = ?
        AND players.pos_type = 'D'
    GROUP BY players.nhl_id, latest_defense_player_teams.nhl_team_id
),
latest_defense_pk_toi_top_200 AS (
    SELECT
        latest_defense_stats.entity_key,
        ROW_NUMBER() OVER (
            ORDER BY latest_defense_stats.latest_defense_train_pk_toi_seconds DESC,
                latest_defense_stats.latest_defense_train_pk_sat DESC,
                latest_defense_stats.latest_defense_train_gp DESC
        ) as latest_defense_pk_toi_rank
    FROM latest_defense_stats
),
latest_defense_pts_gp_top_200 AS (
    SELECT
        latest_defense_stats.entity_key,
        ROW_NUMBER() OVER (
            ORDER BY latest_defense_stats.latest_defense_points_per_gp DESC NULLS LAST,
                latest_defense_stats.latest_defense_train_points DESC,
                latest_defense_stats.latest_defense_train_gp DESC
        ) as latest_defense_points_per_gp_rank,
        ROW_NUMBER() OVER (
            PARTITION BY latest_defense_stats.nhl_team_id
            ORDER BY latest_defense_stats.latest_defense_points_per_gp DESC NULLS LAST,
                latest_defense_stats.latest_defense_train_points DESC,
                latest_defense_stats.latest_defense_train_gp DESC
        ) as latest_team_defense_points_per_gp_rank
    FROM latest_defense_stats
    WHERE latest_defense_stats.latest_defense_train_gp >= 25
),
latest_defense_team_ranks AS (
    SELECT
        latest_defense_stats.*,
        latest_defense_pts_gp_top_200.latest_defense_points_per_gp_rank,
        latest_defense_pk_toi_top_200.latest_defense_pk_toi_rank,
        latest_defense_pts_gp_top_200.latest_team_defense_points_per_gp_rank,
        ROW_NUMBER() OVER (
            PARTITION BY latest_defense_stats.nhl_team_id
            ORDER BY latest_defense_stats.latest_defense_pp_points_per_gp DESC NULLS LAST,
                latest_defense_stats.latest_defense_train_pp_points DESC
        ) as latest_team_defense_pp_points_per_gp_rank,
        ROW_NUMBER() OVER (
            PARTITION BY latest_defense_stats.nhl_team_id
            ORDER BY latest_defense_stats.latest_defense_pp_sat_per_gp DESC NULLS LAST,
                latest_defense_stats.latest_defense_train_pp_sat DESC
        ) as latest_team_defense_pp_sat_per_gp_rank,
        ROW_NUMBER() OVER (
            PARTITION BY latest_defense_stats.nhl_team_id
            ORDER BY latest_defense_stats.latest_defense_pp_sog_per_gp DESC NULLS LAST,
                latest_defense_stats.latest_defense_train_pp_sog DESC
        ) as latest_team_defense_pp_sog_per_gp_rank
    FROM latest_defense_stats
    LEFT JOIN latest_defense_pts_gp_top_200
        ON latest_defense_pts_gp_top_200.entity_key = latest_defense_stats.entity_key
    LEFT JOIN latest_defense_pk_toi_top_200
        ON latest_defense_pk_toi_top_200.entity_key = latest_defense_stats.entity_key
),
base_metrics AS (
    SELECT
        eligible_entities.entity_key,
        situations.situation,
        games.season_id,
        COUNT(DISTINCT summaries.nhl_game_id)::numeric as gp,
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
        END), 0) as goals,
        COALESCE(SUM(CASE situations.situation
            WHEN 'all' THEN summaries.a
            WHEN 'ev' THEN summaries.eva
            WHEN 'pp' THEN summaries.ppa
            WHEN 'pk' THEN summaries.pka
            ELSE 0
        END), 0) as assists,
        COALESCE(SUM(CASE situations.situation
            WHEN 'all' THEN summaries.a2
            WHEN 'ev' THEN summaries.eva2
            WHEN 'pp' THEN summaries.ppa2
            ELSE 0
        END), 0) as secondary_assists,
        COALESCE(SUM(CASE WHEN situations.situation = 'all' THEN summaries.pts ELSE 0 END), 0) as points,
        COALESCE(SUM(summaries.tk), 0) as takeaways,
        COALESCE(SUM(summaries.gv), 0) as giveaways,
        COALESCE(SUM(summaries.b), 0) as blocks,
        COALESCE(SUM(CASE WHEN situations.situation = 'all' THEN summaries.sm ELSE 0 END), 0) as missed_shots
    FROM eligible_entities
    CROSS JOIN situations
    INNER JOIN nhl_game_summaries summaries ON summaries.nhl_player_id = eligible_entities.entity_id
    INNER JOIN nhl_games games ON games.nhl_game_id = summaries.nhl_game_id
    LEFT JOIN nhl_player_game_strength_summaries strength_summaries
        ON strength_summaries.nhl_game_id = summaries.nhl_game_id
        AND strength_summaries.nhl_player_id = summaries.nhl_player_id
        AND strength_summaries.strength = situations.strength
    WHERE games.season_id IN ({$trainSeasonPlaceholders})
        AND games.game_type = ?
        AND (situations.situation = 'all' OR COALESCE(strength_summaries.toi, 0) > 0)
    GROUP BY eligible_entities.entity_key, situations.situation, games.season_id
),
season_metrics AS (
    SELECT
        base_metrics.entity_key,
        base_metrics.situation,
        CASE
            WHEN base_metrics.season_id = ? THEN 's1'
            WHEN base_metrics.season_id = ? THEN 's2'
            ELSE 'other'
        END as season_bucket,
        base_metrics.gp,
        base_metrics.toi_seconds,
        base_metrics.sat,
        base_metrics.hdsat,
        base_metrics.sog,
        base_metrics.goals,
        base_metrics.assists,
        base_metrics.secondary_assists,
        base_metrics.points,
        base_metrics.takeaways,
        base_metrics.giveaways,
        base_metrics.blocks,
        base_metrics.missed_shots
    FROM base_metrics
    WHERE base_metrics.season_id IN (?, ?)
    UNION ALL
    SELECT
        base_metrics.entity_key,
        base_metrics.situation,
        'train'::varchar as season_bucket,
        SUM(base_metrics.gp) as gp,
        SUM(base_metrics.toi_seconds) as toi_seconds,
        SUM(base_metrics.sat) as sat,
        SUM(base_metrics.hdsat) as hdsat,
        SUM(base_metrics.sog) as sog,
        SUM(base_metrics.goals) as goals,
        SUM(base_metrics.assists) as assists,
        SUM(base_metrics.secondary_assists) as secondary_assists,
        SUM(base_metrics.points) as points,
        SUM(base_metrics.takeaways) as takeaways,
        SUM(base_metrics.giveaways) as giveaways,
        SUM(base_metrics.blocks) as blocks,
        SUM(base_metrics.missed_shots) as missed_shots
    FROM base_metrics
    GROUP BY base_metrics.entity_key, base_metrics.situation
),
pivot_rows AS (
    SELECT
        eligible_entities.*,
        situations.situation,
        COALESCE(MAX(season_metrics.gp) FILTER (WHERE season_metrics.season_bucket = 's1'), 0) as s1_gp,
        COALESCE(MAX(season_metrics.gp) FILTER (WHERE season_metrics.season_bucket = 's2'), 0) as s2_gp,
        COALESCE(MAX(season_metrics.gp) FILTER (WHERE season_metrics.season_bucket = 'train'), 0) / ?::numeric as train_gp_per_season,
        COALESCE(MAX(season_metrics.toi_seconds) FILTER (WHERE season_metrics.season_bucket = 's1'), 0) as s1_toi_seconds,
        COALESCE(MAX(season_metrics.toi_seconds) FILTER (WHERE season_metrics.season_bucket = 's2'), 0) as s2_toi_seconds,
        COALESCE(MAX(season_metrics.toi_seconds) FILTER (WHERE season_metrics.season_bucket = 'train'), 0) as train_toi_seconds,
        COALESCE(MAX(season_metrics.sat) FILTER (WHERE season_metrics.season_bucket = 's1'), 0) as s1_sat,
        COALESCE(MAX(season_metrics.sat) FILTER (WHERE season_metrics.season_bucket = 's2'), 0) as s2_sat,
        COALESCE(MAX(season_metrics.sat) FILTER (WHERE season_metrics.season_bucket = 'train'), 0) as train_sat,
        COALESCE(MAX(season_metrics.hdsat) FILTER (WHERE season_metrics.season_bucket = 's1'), 0) as s1_hdsat,
        COALESCE(MAX(season_metrics.hdsat) FILTER (WHERE season_metrics.season_bucket = 's2'), 0) as s2_hdsat,
        COALESCE(MAX(season_metrics.hdsat) FILTER (WHERE season_metrics.season_bucket = 'train'), 0) as train_hdsat,
        COALESCE(MAX(season_metrics.sog) FILTER (WHERE season_metrics.season_bucket = 's1'), 0) as s1_sog,
        COALESCE(MAX(season_metrics.sog) FILTER (WHERE season_metrics.season_bucket = 's2'), 0) as s2_sog,
        COALESCE(MAX(season_metrics.sog) FILTER (WHERE season_metrics.season_bucket = 'train'), 0) as train_sog,
        COALESCE(MAX(season_metrics.goals) FILTER (WHERE season_metrics.season_bucket = 's1'), 0) as s1_goals,
        COALESCE(MAX(season_metrics.goals) FILTER (WHERE season_metrics.season_bucket = 's2'), 0) as s2_goals,
        COALESCE(MAX(season_metrics.goals) FILTER (WHERE season_metrics.season_bucket = 'train'), 0) as train_goals,
        COALESCE(MAX(season_metrics.assists) FILTER (WHERE season_metrics.season_bucket = 's2'), 0) as s2_assists,
        COALESCE(MAX(season_metrics.secondary_assists) FILTER (WHERE season_metrics.season_bucket = 's2'), 0) as s2_secondary_assists,
        COALESCE(MAX(season_metrics.points) FILTER (WHERE season_metrics.season_bucket = 'train'), 0) as train_points,
        COALESCE(MAX(season_metrics.takeaways) FILTER (WHERE season_metrics.season_bucket = 's2'), 0) as s2_takeaways,
        COALESCE(MAX(season_metrics.takeaways) FILTER (WHERE season_metrics.season_bucket = 'train'), 0) as train_takeaways,
        COALESCE(MAX(season_metrics.giveaways) FILTER (WHERE season_metrics.season_bucket = 's2'), 0) as s2_giveaways,
        COALESCE(MAX(season_metrics.giveaways) FILTER (WHERE season_metrics.season_bucket = 'train'), 0) as train_giveaways,
        COALESCE(MAX(season_metrics.blocks) FILTER (WHERE season_metrics.season_bucket = 's2'), 0) as s2_blocks,
        COALESCE(MAX(season_metrics.blocks) FILTER (WHERE season_metrics.season_bucket = 'train'), 0) as train_blocks,
        COALESCE(MAX(season_metrics.missed_shots) FILTER (WHERE season_metrics.season_bucket = 's2'), 0) as s2_missed_shots
    FROM eligible_entities
    CROSS JOIN situations
    LEFT JOIN season_metrics
        ON season_metrics.entity_key = eligible_entities.entity_key
        AND season_metrics.situation = situations.situation
    GROUP BY
        eligible_entities.profile_type,
        eligible_entities.entity_key,
        eligible_entities.entity_id,
        eligible_entities.entity_name,
        eligible_entities.entity_role,
        eligible_entities.team_context,
        eligible_entities.anchor_projected_sat_per_60,
        eligible_entities.position,
        eligible_entities.pos_type,
        eligible_entities.target_age,
        eligible_entities.latest_train_age,
        situations.situation
),
rate_rows AS (
    SELECT
        pivot_rows.*,
        CASE
            WHEN target_age IS NULL THEN 'unknown'
            WHEN target_age <= 25 THEN '25u'
            WHEN target_age <= 29 THEN '26_29'
            WHEN target_age <= 33 THEN '30_33'
            ELSE '34_plus'
        END as age_group,
        ROUND((s1_toi_seconds::numeric / NULLIF(s1_gp, 0)), 4) as s1_toi_per_gp,
        ROUND((s2_toi_seconds::numeric / NULLIF(s2_gp, 0)), 4) as s2_toi_per_gp,
        ROUND((train_toi_seconds::numeric / NULLIF(train_gp_per_season * ?::numeric, 0)), 4) as train_toi_per_gp,
        ROUND((s1_sat::numeric / NULLIF(s1_gp, 0)), 4) as s1_sat_per_gp,
        ROUND((s2_sat::numeric / NULLIF(s2_gp, 0)), 4) as s2_sat_per_gp,
        ROUND((train_sat::numeric / NULLIF(train_gp_per_season * ?::numeric, 0)), 4) as train_sat_per_gp,
        ROUND(((s1_sat::numeric * 3600) / NULLIF(s1_toi_seconds, 0)), 4) as s1_sat_per_60,
        ROUND(((s2_sat::numeric * 3600) / NULLIF(s2_toi_seconds, 0)), 4) as s2_sat_per_60,
        ROUND(((train_sat::numeric * 3600) / NULLIF(train_toi_seconds, 0)), 4) as train_sat_per_60,
        ROUND(((s1_sog::numeric * 3600) / NULLIF(s1_toi_seconds, 0)), 4) as s1_sog_per_60,
        ROUND(((s2_sog::numeric * 3600) / NULLIF(s2_toi_seconds, 0)), 4) as s2_sog_per_60,
        ROUND(((train_sog::numeric * 3600) / NULLIF(train_toi_seconds, 0)), 4) as train_sog_per_60,
        ROUND((s1_hdsat::numeric / NULLIF(s1_gp, 0)), 4) as s1_hdsat_per_gp,
        ROUND((s2_hdsat::numeric / NULLIF(s2_gp, 0)), 4) as s2_hdsat_per_gp,
        ROUND((train_hdsat::numeric / NULLIF(train_gp_per_season * ?::numeric, 0)), 4) as train_hdsat_per_gp,
        ROUND(((s1_hdsat::numeric * 3600) / NULLIF(s1_toi_seconds, 0)), 4) as s1_hdsat_per_60,
        ROUND(((s2_hdsat::numeric * 3600) / NULLIF(s2_toi_seconds, 0)), 4) as s2_hdsat_per_60,
        ROUND(((train_hdsat::numeric * 3600) / NULLIF(train_toi_seconds, 0)), 4) as train_hdsat_per_60,
        ROUND((s1_hdsat::numeric / NULLIF(s1_sat, 0)), 6) as s1_hdsat_sat_rate,
        ROUND((s2_hdsat::numeric / NULLIF(s2_sat, 0)), 6) as s2_hdsat_sat_rate,
        ROUND((train_hdsat::numeric / NULLIF(train_sat, 0)), 6) as train_hdsat_sat_rate,
        ROUND((s1_goals::numeric / NULLIF(s1_sog, 0)), 6) as s1_sh_pct,
        ROUND((s2_goals::numeric / NULLIF(s2_sog, 0)), 6) as s2_sh_pct,
        ROUND((train_goals::numeric / NULLIF(train_sog, 0)), 6) as train_sh_pct,
        ROUND((train_points::numeric / NULLIF(train_gp_per_season * ?::numeric, 0)), 6) as train_points_per_gp,
        ROUND((s2_blocks::numeric / NULLIF(s2_gp, 0)), 6) as s2_blocks_per_gp,
        ROUND((train_blocks::numeric / NULLIF(train_gp_per_season * ?::numeric, 0)), 6) as train_blocks_per_gp,
        ROUND(((s2_blocks::numeric / NULLIF(s2_gp, 0)) - (train_blocks::numeric / NULLIF(train_gp_per_season * ?::numeric, 0)))::numeric, 6) as blocks_delta_train,
        ROUND(((s2_takeaways + s2_giveaways)::numeric / NULLIF(s2_gp, 0)), 6) as s2_tkgv_per_gp,
        ROUND(((train_takeaways + train_giveaways)::numeric / NULLIF(train_gp_per_season * ?::numeric, 0)), 6) as train_tkgv_per_gp,
        ROUND((((s2_takeaways + s2_giveaways)::numeric / NULLIF(s2_gp, 0)) - ((train_takeaways + train_giveaways)::numeric / NULLIF(train_gp_per_season * ?::numeric, 0)))::numeric, 6) as tkgv_delta_train,
        ROUND((s2_missed_shots::numeric / NULLIF(s2_gp, 0)), 6) as s2_missed_shots_per_gp,
        ROUND((s2_assists::numeric / NULLIF(s2_gp, 0)), 6) as s2_assists_per_gp
    FROM pivot_rows
),
feature_rows AS (
    SELECT
        rate_rows.*,
        CASE
            WHEN s1_sat_per_gp IS NULL OR s2_sat_per_gp IS NULL THEN 'sat_momentum_unknown'
            WHEN s2_sat_per_gp >= s1_sat_per_gp * 1.20 THEN 'sat_spike'
            WHEN s2_sat_per_gp <= s1_sat_per_gp * 0.80 THEN 'sat_drop'
            ELSE 'sat_stable'
        END as sat_momentum_bucket,
        CASE
            WHEN s1_hdsat_per_gp IS NULL OR s2_hdsat_per_gp IS NULL THEN 'hdsat_momentum_unknown'
            WHEN s2_hdsat_per_gp >= s1_hdsat_per_gp * 1.20 THEN 'hdsat_spike'
            WHEN s2_hdsat_per_gp <= s1_hdsat_per_gp * 0.80 THEN 'hdsat_drop'
            ELSE 'hdsat_stable'
        END as hdsat_momentum_bucket,
        CASE
            WHEN s1_toi_per_gp IS NULL OR s2_toi_per_gp IS NULL THEN 'toi_momentum_unknown'
            WHEN s2_toi_per_gp >= s1_toi_per_gp * 1.10 THEN 'toi_gain'
            WHEN s2_toi_per_gp <= s1_toi_per_gp * 0.90 THEN 'toi_drop'
            ELSE 'toi_stable'
        END as toi_momentum_bucket,
        CASE
            WHEN s1_sh_pct IS NULL OR s2_sh_pct IS NULL THEN 'sh_regression_unknown'
            WHEN s2_sh_pct >= s1_sh_pct + 0.02 THEN 'sh_spike'
            WHEN s2_sh_pct <= s1_sh_pct - 0.02 THEN 'sh_drop'
            ELSE 'sh_stable'
        END as sh_regression_bucket
    FROM rate_rows
),
entity_context_rows AS (
    SELECT
        feature_rows.*,
        MAX(train_points_per_gp) FILTER (WHERE situation = 'all') OVER (PARTITION BY feature_rows.entity_key) as all_train_points_per_gp,
        MAX(train_goals::numeric / NULLIF(train_gp_per_season * ?::numeric, 0)) FILTER (WHERE situation = 'all') OVER (PARTITION BY feature_rows.entity_key) as all_train_goals_per_gp,
        MAX(train_sat_per_gp) FILTER (WHERE situation = 'all') OVER (PARTITION BY feature_rows.entity_key) as all_train_sat_per_gp,
        MAX(train_toi_per_gp) FILTER (WHERE situation = 'all') OVER (PARTITION BY feature_rows.entity_key) as all_train_toi_per_gp,
        ROUND(
            (
                MAX(train_sat) FILTER (WHERE situation = 'ev') OVER (PARTITION BY feature_rows.entity_key)::numeric
                * 3600
            )
            / NULLIF(MAX(train_toi_seconds) FILTER (WHERE situation = 'all') OVER (PARTITION BY feature_rows.entity_key), 0),
            4
        ) as train_ev_sat_per_60_all_toi,
        MAX(train_sog_per_60) FILTER (WHERE situation = 'ev') OVER (PARTITION BY feature_rows.entity_key) as train_ev_sog_per_60_ev_toi,
        MAX(s2_sog_per_60) FILTER (WHERE situation = 'pk') OVER (PARTITION BY feature_rows.entity_key) as s2_pk_sog_per_60_pk_toi,
        ROUND(
            (
                MAX(s2_sat) FILTER (WHERE situation = 'pk') OVER (PARTITION BY feature_rows.entity_key)::numeric
                * 3600
            )
            / NULLIF(MAX(s2_toi_seconds) FILTER (WHERE situation = 'all') OVER (PARTITION BY feature_rows.entity_key), 0),
            4
        ) as s2_pk_sat_per_60_all_toi,
        MAX(s2_missed_shots_per_gp) FILTER (WHERE situation = 'all') OVER (PARTITION BY feature_rows.entity_key) as s2_all_missed_shots_per_gp,
        ROUND(
            (
                MAX(s2_secondary_assists) FILTER (WHERE situation = 'ev') OVER (PARTITION BY feature_rows.entity_key)::numeric
                * 3600
            )
            / NULLIF(MAX(s2_toi_seconds) FILTER (WHERE situation = 'all') OVER (PARTITION BY feature_rows.entity_key), 0),
            4
        ) as s2_ev_secondary_assists_per_60_all_toi,
        MAX(s2_assists_per_gp) FILTER (WHERE situation = 'pk') OVER (PARTITION BY feature_rows.entity_key) as s2_pk_assists_per_gp,
        latest_forward_team_ranks.latest_train_gp,
        latest_forward_team_ranks.latest_train_points,
        latest_forward_team_ranks.latest_train_points_per_gp,
        latest_forward_team_ranks.latest_train_goals_per_gp,
        latest_forward_team_ranks.latest_train_sat_per_gp,
        latest_forward_team_ranks.latest_train_sog_per_gp,
        latest_forward_team_ranks.latest_train_hdsat_per_gp,
        latest_forward_team_ranks.latest_train_pk_toi_per_gp,
        latest_forward_team_ranks.latest_forward_points_per_gp_rank,
        latest_forward_team_ranks.latest_team_forward_qualified_points_per_gp_rank,
        latest_forward_team_ranks.latest_forward_total_points_rank,
        latest_forward_team_ranks.latest_forward_pk_toi_rank,
        latest_forward_team_ranks.latest_team_forward_points_per_gp_rank,
        latest_forward_team_ranks.latest_team_forward_total_points_rank,
        latest_forward_team_ranks.latest_team_forward_sat_per_gp_rank,
        latest_forward_team_ranks.latest_team_forward_sog_per_gp_rank,
        latest_forward_team_ranks.latest_team_forward_hdsat_per_gp_rank,
        latest_defense_team_ranks.latest_defense_train_gp,
        latest_defense_team_ranks.latest_defense_points_per_gp,
        latest_defense_team_ranks.latest_defense_pp_points_per_gp,
        latest_defense_team_ranks.latest_defense_pp_sat_per_gp,
        latest_defense_team_ranks.latest_defense_pp_sog_per_gp,
        latest_defense_team_ranks.latest_defense_pk_toi_per_gp,
        latest_defense_team_ranks.latest_defense_points_per_gp_rank,
        latest_defense_team_ranks.latest_defense_pk_toi_rank,
        latest_defense_team_ranks.latest_team_defense_points_per_gp_rank,
        latest_defense_team_ranks.latest_team_defense_pp_points_per_gp_rank,
        latest_defense_team_ranks.latest_team_defense_pp_sat_per_gp_rank,
        latest_defense_team_ranks.latest_team_defense_pp_sog_per_gp_rank
    FROM feature_rows
    LEFT JOIN latest_forward_team_ranks
        ON latest_forward_team_ranks.entity_key = feature_rows.entity_key
    LEFT JOIN latest_defense_team_ranks
        ON latest_defense_team_ranks.entity_key = feature_rows.entity_key
),
cohort_rows AS (
    SELECT
        entity_context_rows.*,
        CASE
            WHEN situation IN ('pp', 'pk') AND COALESCE(train_toi_per_gp, 0) <= 20 THEN 'no_or_low_usage'
            WHEN situation = 'pp' AND COALESCE(train_toi_per_gp, 0) >= 150 THEN 'primary_usage'
            WHEN situation = 'pp' AND COALESCE(train_toi_per_gp, 0) >= 75 THEN 'secondary_usage'
            WHEN situation = 'pp' THEN 'fringe_usage'
            WHEN situation = 'pk' AND COALESCE(train_toi_per_gp, 0) >= 120 THEN 'primary_usage'
            WHEN situation = 'pk' AND COALESCE(train_toi_per_gp, 0) >= 60 THEN 'secondary_usage'
            WHEN situation = 'pk' THEN 'fringe_usage'
            WHEN COALESCE(train_toi_per_gp, 0) >= 900 THEN 'primary_usage'
            WHEN COALESCE(train_toi_per_gp, 0) >= 600 THEN 'secondary_usage'
            ELSE 'fringe_usage'
        END as usage_tier,
        CASE
            WHEN train_gp_per_season < 25 OR train_sat < 50 THEN 'low_volume'
            WHEN pos_type = 'F' AND all_train_points_per_gp >= 0.90 THEN 'top_forward_points'
            WHEN pos_type = 'F' AND all_train_goals_per_gp >= 0.35 THEN 'top_forward_goals'
            WHEN pos_type = 'F' AND all_train_sat_per_gp >= 4.00 THEN 'top_forward_sat'
            WHEN position = 'D' AND all_train_toi_per_gp >= 1320 THEN 'high_toi_defense'
            WHEN position = 'D' THEN 'defense'
            WHEN pos_type = 'F' THEN 'forward'
            ELSE 'skater'
        END as player_projection_cohort,
        CASE
            WHEN COALESCE(pos_type, '') <> 'F' THEN 'not_forward'
            WHEN latest_forward_points_per_gp_rank <= 400
                AND latest_forward_total_points_rank <= 400 THEN 'latest_top400_f_both'
            WHEN latest_forward_points_per_gp_rank <= 400 THEN 'latest_top400_f_points_per_gp_only'
            WHEN latest_forward_total_points_rank <= 400 THEN 'latest_top400_f_total_points_only'
            ELSE 'outside_latest_top400_f'
        END as pp_forward_projection_cohort,
        CASE
            WHEN COALESCE(pos_type, '') <> 'D' THEN NULL::int
            ELSE LEAST(
                COALESCE(latest_team_defense_pp_points_per_gp_rank, 999),
                COALESCE(latest_team_defense_pp_sat_per_gp_rank, 999),
                COALESCE(latest_team_defense_pp_sog_per_gp_rank, 999)
            )::int
        END as d_pp_role_rank,
        CASE
            WHEN COALESCE(pos_type, '') <> 'D' THEN 'not_defense'
            WHEN latest_defense_points_per_gp_rank > 200 OR latest_defense_points_per_gp_rank IS NULL THEN 'outside_latest_top200_d'
            WHEN LEAST(
                COALESCE(latest_team_defense_pp_points_per_gp_rank, 999),
                COALESCE(latest_team_defense_pp_sat_per_gp_rank, 999),
                COALESCE(latest_team_defense_pp_sog_per_gp_rank, 999)
            ) = 1 THEN 'latest_top200_d_team_pp_rank_1'
            WHEN LEAST(
                COALESCE(latest_team_defense_pp_points_per_gp_rank, 999),
                COALESCE(latest_team_defense_pp_sat_per_gp_rank, 999),
                COALESCE(latest_team_defense_pp_sog_per_gp_rank, 999)
            ) = 2 THEN 'latest_top200_d_team_pp_rank_2'
            WHEN LEAST(
                COALESCE(latest_team_defense_pp_points_per_gp_rank, 999),
                COALESCE(latest_team_defense_pp_sat_per_gp_rank, 999),
                COALESCE(latest_team_defense_pp_sog_per_gp_rank, 999)
            ) = 3 THEN 'latest_top200_d_team_pp_rank_3'
            ELSE 'latest_top200_d_team_pp_rank_4_plus'
        END as d_pp_role_cohort,
        CASE
            WHEN COALESCE(pos_type, '') <> 'F' THEN 'not_forward'
            WHEN latest_forward_pk_toi_rank <= 100 THEN 'latest_pk_toi_f_rank_1_100'
            WHEN latest_forward_pk_toi_rank <= 200 THEN 'latest_pk_toi_f_rank_101_200'
            WHEN latest_forward_pk_toi_rank <= 400 THEN 'latest_pk_toi_f_rank_201_400'
            ELSE 'outside_latest_pk_toi_top400_f'
        END as f_pk_sat_role_cohort,
        CASE
            WHEN COALESCE(pos_type, '') <> 'F' THEN 'not_forward'
            WHEN latest_team_forward_qualified_points_per_gp_rank <= 3 THEN 'latest_team_f_points_rank_1_3'
            WHEN latest_team_forward_qualified_points_per_gp_rank <= 6 THEN 'latest_team_f_points_rank_4_6'
            WHEN latest_team_forward_qualified_points_per_gp_rank <= 9 THEN 'latest_team_f_points_rank_7_9'
            WHEN latest_forward_points_per_gp_rank <= 400 THEN 'latest_top400_f_points_rank_10_plus'
            ELSE 'outside_latest_top400_f'
        END as f_sat_role_cohort,
        CASE
            WHEN COALESCE(pos_type, '') <> 'D' THEN 'not_defense'
            WHEN latest_team_defense_points_per_gp_rank = 1 THEN 'latest_team_d_points_rank_1'
            WHEN latest_team_defense_points_per_gp_rank = 2 THEN 'latest_team_d_points_rank_2'
            WHEN latest_team_defense_points_per_gp_rank = 3 THEN 'latest_team_d_points_rank_3'
            WHEN latest_defense_points_per_gp_rank <= 200 THEN 'latest_top200_d_points_rank_4_plus'
            ELSE 'outside_latest_top200_d'
        END as d_sat_role_cohort,
        CASE
            WHEN COALESCE(pos_type, '') <> 'D' THEN 'not_defense'
            WHEN latest_defense_pk_toi_rank <= 50 THEN 'latest_pk_toi_d_rank_1_50'
            WHEN latest_defense_pk_toi_rank <= 100 THEN 'latest_pk_toi_d_rank_51_100'
            WHEN latest_defense_pk_toi_rank <= 200 THEN 'latest_pk_toi_d_rank_101_200'
            ELSE 'outside_latest_pk_toi_top200_d'
        END as d_pk_sat_role_cohort
    FROM entity_context_rows
),
projection_base_rows AS (
    SELECT
        cohort_rows.*,
        GREATEST(
            0,
            CASE situation
                WHEN 'ev' THEN (0.74 * COALESCE(s2_sat_per_60, train_sat_per_60, 0)) + (0.26 * COALESCE(train_sat_per_60, s2_sat_per_60, 0))
                WHEN 'pp' THEN
                    CASE
                        WHEN player_projection_cohort IN ('top_forward_points', 'top_forward_goals', 'top_forward_sat', 'high_toi_defense')
                            OR latest_forward_points_per_gp_rank <= 100
                            OR latest_forward_total_points_rank <= 100
                            OR latest_defense_points_per_gp_rank <= 50
                            THEN (0.48 * COALESCE(s2_sat_per_60, train_sat_per_60, 0)) + (0.42 * COALESCE(train_sat_per_60, s2_sat_per_60, 0)) + (0.10 * COALESCE(s1_sat_per_60, train_sat_per_60, s2_sat_per_60, 0))
                        ELSE (0.30 * COALESCE(s2_sat_per_60, train_sat_per_60, 0)) + (0.60 * COALESCE(train_sat_per_60, s2_sat_per_60, 0)) + (0.10 * COALESCE(s1_sat_per_60, train_sat_per_60, s2_sat_per_60, 0))
                    END
                WHEN 'pk' THEN
                    CASE
                        WHEN pos_type = 'F' AND latest_forward_pk_toi_rank <= 100 THEN GREATEST(
                            0,
                            -0.4487
                            + (0.3536 * COALESCE(train_sat_per_60, 0))
                            + (0.3941 * COALESCE(train_ev_sat_per_60_all_toi, 0))
                        )
                        WHEN pos_type = 'F' AND latest_forward_pk_toi_rank <= 200 THEN GREATEST(
                            0,
                            -1.9131
                            + (0.9404 * COALESCE(train_ev_sog_per_60_ev_toi, 0))
                            + (0.1478 * COALESCE(s2_pk_sog_per_60_pk_toi, 0))
                        )
                        WHEN pos_type = 'D' AND latest_defense_pk_toi_rank <= 50 THEN GREATEST(
                            0,
                            -0.0410
                            + (2.5554 * COALESCE(s2_pk_sat_per_60_all_toi, 0))
                            + (1.5080 * COALESCE(s2_all_missed_shots_per_gp, 0))
                        )
                        WHEN pos_type = 'D' AND latest_defense_pk_toi_rank <= 100 THEN LEAST(
                            1.15 * COALESCE(train_sat_per_60, s2_sat_per_60, 0),
                            GREATEST(
                                0,
                                1.0377
                                + (1.6444 * COALESCE(s2_ev_secondary_assists_per_60_all_toi, 0))
                                - (14.3187 * COALESCE(s2_pk_assists_per_gp, 0))
                            )
                        )
                        ELSE 0.75 * (
                            (0.20 * COALESCE(s2_sat_per_60, train_sat_per_60, 0))
                            + (0.70 * COALESCE(train_sat_per_60, s2_sat_per_60, 0))
                            + (0.10 * COALESCE(s1_sat_per_60, train_sat_per_60, s2_sat_per_60, 0))
                        )
                    END
                ELSE (0.68 * COALESCE(s2_sat_per_60, train_sat_per_60, 0)) + (0.32 * COALESCE(train_sat_per_60, s2_sat_per_60, 0))
            END
        ) as base_projected_sat_per_60,
        GREATEST(
            0,
            CASE situation
                WHEN 'ev' THEN (0.74 * COALESCE(s2_hdsat_per_60, train_hdsat_per_60, 0)) + (0.26 * COALESCE(train_hdsat_per_60, s2_hdsat_per_60, 0))
                WHEN 'pp' THEN (0.58 * COALESCE(s2_hdsat_per_60, train_hdsat_per_60, 0)) + (0.32 * COALESCE(train_hdsat_per_60, s2_hdsat_per_60, 0)) + (0.10 * COALESCE(s1_hdsat_per_60, train_hdsat_per_60, s2_hdsat_per_60, 0))
                WHEN 'pk' THEN (0.50 * COALESCE(s2_hdsat_per_60, train_hdsat_per_60, 0)) + (0.40 * COALESCE(train_hdsat_per_60, s2_hdsat_per_60, 0)) + (0.10 * COALESCE(s1_hdsat_per_60, train_hdsat_per_60, s2_hdsat_per_60, 0))
                ELSE (0.72 * COALESCE(s2_hdsat_per_60, train_hdsat_per_60, 0)) + (0.28 * COALESCE(train_hdsat_per_60, s2_hdsat_per_60, 0))
            END
        ) as base_projected_hdsat_per_60,
        GREATEST(
            0,
            CASE situation
                WHEN 'pk' THEN (0.35 * COALESCE(s2_toi_per_gp, train_toi_per_gp, 0)) + (0.65 * COALESCE(train_toi_per_gp, s2_toi_per_gp, 0))
                ELSE ((0.90 * COALESCE(s2_toi_per_gp, train_toi_per_gp, 0)) + (0.10 * COALESCE(train_toi_per_gp, s2_toi_per_gp, 0)))
            END
        ) as base_projected_toi_per_gp
    FROM cohort_rows
),
unanchored_projected_rows AS (
    SELECT
        projection_base_rows.*,
        ROUND((
            base_projected_sat_per_60
            * CASE
                WHEN situation = 'pk' THEN 1.00
                WHEN sat_momentum_bucket = 'sat_spike' AND age_group = '34_plus' THEN 0.85
                WHEN sat_momentum_bucket = 'sat_spike' AND age_group = '30_33' THEN 0.90
                WHEN sat_momentum_bucket = 'sat_spike' AND age_group = '25u' THEN 0.93
                WHEN sat_momentum_bucket = 'sat_spike' THEN 0.96
                WHEN situation = 'pp' AND sat_momentum_bucket = 'sat_drop' AND age_group IN ('25u', '26_29') THEN 1.03
                WHEN situation <> 'pp' AND sat_momentum_bucket = 'sat_drop' AND age_group IN ('25u', '26_29') THEN 1.06
                WHEN sat_momentum_bucket = 'sat_drop' THEN 1.03
                ELSE 1.0
            END
            * CASE
                WHEN situation = 'ev' AND player_projection_cohort = 'low_volume' THEN 0.94
                WHEN situation = 'ev' AND pos_type = 'F' AND COALESCE(latest_train_goals_per_gp, all_train_goals_per_gp, 0) < 0.20 THEN 0.97
                WHEN situation = 'ev' AND pos_type = 'D' AND COALESCE(latest_defense_points_per_gp, 0) < 0.20 THEN 0.97
                WHEN situation = 'pp' AND usage_tier = 'no_or_low_usage' THEN 0.70
                WHEN situation = 'pp' AND pos_type = 'F' AND COALESCE(latest_train_goals_per_gp, all_train_goals_per_gp, 0) < 0.20 THEN 0.82
                WHEN situation = 'pp' AND pos_type = 'D' AND COALESCE(latest_defense_points_per_gp, 0) < 0.20 THEN 0.75
                WHEN situation = 'pp' AND age_group = '34_plus' AND player_projection_cohort NOT IN ('top_forward_points', 'top_forward_goals', 'top_forward_sat', 'high_toi_defense') THEN 0.92
                WHEN situation = 'pk' THEN 1.00
                ELSE 1.0
            END
            * CASE
                WHEN situation = 'pp'
                    AND player_projection_cohort IN ('top_forward_points', 'top_forward_goals', 'top_forward_sat')
                    AND toi_momentum_bucket = 'toi_gain'
                    AND s2_sh_pct >= 0.18
                    AND age_group IN ('25u', '26_29') THEN 1.02
                ELSE 1.0
            END
        )::numeric, 4) as projected_sat_per_60,
        CASE
            WHEN situation = 'all'
                AND d_sat_role_cohort = 'latest_team_d_points_rank_1'
                THEN ROUND((
                    (0.70 * COALESCE(s2_sat_per_60, train_sat_per_60, 0))
                    + (0.30 * COALESCE(train_sat_per_60, s2_sat_per_60, 0))
                )::numeric, 4)
            ELSE NULL::numeric
        END as d1_all_sat_per_60,
        ROUND((
            base_projected_hdsat_per_60
            * CASE
                WHEN hdsat_momentum_bucket = 'hdsat_spike' AND age_group = '34_plus' THEN 0.82
                WHEN hdsat_momentum_bucket = 'hdsat_spike' AND age_group = '30_33' THEN 0.87
                WHEN hdsat_momentum_bucket = 'hdsat_spike' AND age_group = '25u' THEN 0.90
                WHEN hdsat_momentum_bucket = 'hdsat_spike' THEN 0.94
                WHEN hdsat_momentum_bucket = 'hdsat_drop' AND age_group IN ('25u', '26_29') THEN 1.15
                WHEN hdsat_momentum_bucket = 'hdsat_drop' THEN 1.10
                ELSE 1.0
            END
            * CASE
                WHEN situation = 'pp' AND toi_momentum_bucket = 'toi_gain' AND s2_sh_pct >= 0.18 AND age_group IN ('25u', '26_29') THEN 1.06
                WHEN situation = 'pp' AND toi_momentum_bucket = 'toi_gain' THEN 1.03
                ELSE 1.0
            END
        )::numeric, 4) as projected_hdsat_per_60,
        ROUND((
            base_projected_toi_per_gp
            * CASE
                WHEN toi_momentum_bucket = 'toi_gain' AND situation = 'pp' AND age_group IN ('25u', '26_29') THEN 1.03
                WHEN toi_momentum_bucket = 'toi_gain' AND age_group = '34_plus' THEN 0.96
                WHEN toi_momentum_bucket = 'toi_gain' THEN 0.98
                WHEN toi_momentum_bucket = 'toi_drop' AND age_group IN ('30_33', '34_plus') THEN 0.97
                WHEN toi_momentum_bucket = 'toi_drop' THEN 0.99
                ELSE 1.0
            END
        )::numeric, 4) as projected_toi_per_gp,
        ROUND(GREATEST(
            0,
            LEAST(
                84,
                s2_gp
                - CASE
                    WHEN s1_gp <= 0 THEN 1.0
                    WHEN s2_gp - s1_gp > 20 THEN 8.0
                    WHEN s2_gp - s1_gp > 5 THEN 5.0
                    WHEN s2_gp - s1_gp < -20 THEN 0.0
                    WHEN s2_gp - s1_gp < -5 THEN 4.0
                    ELSE 3.0
                END
                - CASE
                    WHEN age_group = '34_plus' THEN 2.0
                    WHEN age_group = '30_33' THEN 1.0
                    WHEN age_group = '25u' AND toi_momentum_bucket = 'toi_gain' THEN -1.0
                    ELSE 0.0
                END
                + CASE
                    WHEN situation = 'pp' AND s2_toi_per_gp >= 120 THEN 1.0
                    ELSE 0.0
                END
            )
        )::numeric, 4) as projected_gp
    FROM projection_base_rows
),
anchored_projected_rows AS (
    SELECT
        unanchored_projected_rows.*,
        MAX(projected_sat_per_60) FILTER (WHERE situation = 'all') OVER (PARTITION BY unanchored_projected_rows.entity_key) as unanchored_all_sat_per_60,
        GREATEST(
            0,
            CASE
                WHEN d1_all_sat_per_60 IS NOT NULL THEN d1_all_sat_per_60
                ELSE COALESCE(anchor_projected_sat_per_60, MAX(projected_sat_per_60) FILTER (WHERE situation = 'all') OVER (PARTITION BY unanchored_projected_rows.entity_key), 0)
            END
        ) as anchored_all_sat_per_60,
        LEAST(
            0.85,
            GREATEST(
                0.05,
                CASE situation
                    WHEN 'pp' THEN
                        COALESCE(
                            (0.60 * s2_hdsat_sat_rate) + (0.30 * train_hdsat_sat_rate) + (0.10 * s1_hdsat_sat_rate),
                            train_hdsat_sat_rate,
                            s2_hdsat_sat_rate,
                            s1_hdsat_sat_rate,
                            0
                        )
                    WHEN 'pk' THEN
                        COALESCE(
                            (0.50 * s2_hdsat_sat_rate) + (0.40 * train_hdsat_sat_rate) + (0.10 * s1_hdsat_sat_rate),
                            train_hdsat_sat_rate,
                            s2_hdsat_sat_rate,
                            s1_hdsat_sat_rate,
                            0
                        )
                    ELSE
                        COALESCE(
                            (0.65 * s2_hdsat_sat_rate) + (0.30 * train_hdsat_sat_rate) + (0.05 * s1_hdsat_sat_rate),
                            train_hdsat_sat_rate,
                            s2_hdsat_sat_rate,
                            s1_hdsat_sat_rate,
                            0
                        )
                END
                * CASE
                    WHEN hdsat_momentum_bucket = 'hdsat_spike' AND age_group = '34_plus' THEN 0.92
                    WHEN hdsat_momentum_bucket = 'hdsat_spike' AND age_group = '30_33' THEN 0.95
                    WHEN hdsat_momentum_bucket = 'hdsat_spike' THEN 0.98
                    WHEN hdsat_momentum_bucket = 'hdsat_drop' AND age_group IN ('25u', '26_29') THEN 1.08
                    WHEN hdsat_momentum_bucket = 'hdsat_drop' THEN 1.05
                    ELSE 1.0
                END
            )
        ) as projected_hdsat_sat_rate
    FROM unanchored_projected_rows
),
calibration_rows AS (
    SELECT
        anchored_projected_rows.*,
        CASE situation
            WHEN 'all' THEN
                CASE
                    WHEN d_sat_role_cohort = 'latest_team_d_points_rank_1' THEN 1.00::numeric
                    WHEN f_sat_role_cohort = 'latest_top400_f_points_rank_10_plus' THEN 0.96::numeric
                    ELSE 1.20::numeric
                END
            WHEN 'ev' THEN
                CASE
                    WHEN d_sat_role_cohort = 'latest_team_d_points_rank_1' THEN 1.39::numeric
                    WHEN d_sat_role_cohort = 'latest_team_d_points_rank_2' THEN 1.31::numeric
                    WHEN d_sat_role_cohort = 'latest_team_d_points_rank_3' THEN 1.27::numeric
                    WHEN f_sat_role_cohort = 'latest_top400_f_points_rank_10_plus' THEN 1.00::numeric
                    ELSE 1.19::numeric
                END
            WHEN 'pp' THEN
                CASE
                    WHEN d_sat_role_cohort = 'latest_team_d_points_rank_1' THEN
                        CASE
                            WHEN COALESCE(s2_toi_per_gp, 0) >= 2.00 THEN
                                CASE
                                    WHEN usage_tier = 'primary_usage' THEN 1.49::numeric
                                    WHEN usage_tier = 'secondary_usage' THEN 1.32::numeric
                                    WHEN usage_tier = 'fringe_usage' THEN 1.10::numeric
                                    ELSE 0.83::numeric
                                END
                            WHEN usage_tier = 'primary_usage' THEN 1.35::numeric
                            WHEN usage_tier = 'secondary_usage' THEN 1.20::numeric
                            WHEN usage_tier = 'fringe_usage' THEN 1.00::numeric
                            ELSE 0.75::numeric
                        END
                    WHEN d_sat_role_cohort = 'latest_team_d_points_rank_2' THEN
                        CASE
                            WHEN COALESCE(s2_toi_per_gp, 0) >= 1.00 THEN
                                CASE
                                    WHEN usage_tier = 'primary_usage' THEN 1.62::numeric
                                    WHEN usage_tier = 'secondary_usage' THEN 1.44::numeric
                                    WHEN usage_tier = 'fringe_usage' THEN 1.20::numeric
                                    ELSE 0.90::numeric
                                END
                            WHEN usage_tier = 'primary_usage' THEN 0.81::numeric
                            WHEN usage_tier = 'secondary_usage' THEN 0.72::numeric
                            WHEN usage_tier = 'fringe_usage' THEN 0.60::numeric
                            ELSE 0.45::numeric
                        END
                    WHEN d_sat_role_cohort = 'latest_team_d_points_rank_3' THEN
                        CASE
                            WHEN COALESCE(s2_toi_per_gp, 0) >= 1.00 THEN
                                CASE
                                    WHEN usage_tier = 'primary_usage' THEN 1.62::numeric
                                    WHEN usage_tier = 'secondary_usage' THEN 1.44::numeric
                                    WHEN usage_tier = 'fringe_usage' THEN 1.20::numeric
                                    ELSE 0.90::numeric
                                END
                            WHEN usage_tier = 'primary_usage' THEN 1.01::numeric
                            WHEN usage_tier = 'secondary_usage' THEN 0.90::numeric
                            WHEN usage_tier = 'fringe_usage' THEN 0.75::numeric
                            ELSE 0.56::numeric
                        END
                    WHEN usage_tier = 'primary_usage' THEN 1.35::numeric
                    WHEN usage_tier = 'secondary_usage' THEN 1.20::numeric
                    WHEN usage_tier = 'fringe_usage' THEN 1.00::numeric
                    ELSE 0.75::numeric
                END
            WHEN 'pk' THEN 1.00::numeric
            ELSE 1.0::numeric
        END as sat_rate_calibration,
        CASE situation
            WHEN 'all' THEN 1.00::numeric
            WHEN 'ev' THEN 1.03::numeric
            WHEN 'pp' THEN
                CASE
                    WHEN usage_tier IN ('primary_usage', 'secondary_usage') THEN 1.03::numeric
                    WHEN usage_tier = 'fringe_usage' THEN 0.95::numeric
                    ELSE 0.85::numeric
                END
            WHEN 'pk' THEN
                CASE
                    WHEN usage_tier IN ('primary_usage', 'secondary_usage') THEN 0.80::numeric
                    ELSE 0.70::numeric
                END
            ELSE 1.0::numeric
        END as hdsat_share_calibration,
        CASE situation
            WHEN 'all' THEN 1.00::numeric
            WHEN 'ev' THEN 1.00::numeric
            WHEN 'pp' THEN
                CASE
                    WHEN usage_tier = 'primary_usage' THEN 1.10::numeric
                    WHEN usage_tier = 'secondary_usage' THEN 1.05::numeric
                    WHEN usage_tier = 'fringe_usage' THEN 0.95::numeric
                    ELSE 0.70::numeric
                END
            WHEN 'pk' THEN
                CASE
                    WHEN usage_tier = 'primary_usage' THEN 1.10::numeric
                    WHEN usage_tier = 'secondary_usage' THEN 1.05::numeric
                    WHEN usage_tier = 'fringe_usage' THEN 0.95::numeric
                    ELSE 0.70::numeric
                END
            ELSE 1.0::numeric
        END as toi_gp_calibration,
        CASE situation
            WHEN 'all' THEN 1.00::numeric
            WHEN 'ev' THEN 1.00::numeric
            ELSE 1.00::numeric
        END as gp_calibration,
        CASE
            WHEN situation <> 'pk' OR usage_tier = 'no_or_low_usage' THEN 1.00::numeric
            ELSE LEAST(
                1.10,
                GREATEST(
                    0.94,
                    1.00
                    + CASE
                        WHEN position = 'D' AND s2_blocks_per_gp >= 3.50 THEN 0.04
                        WHEN position = 'D' AND s2_blocks_per_gp >= 2.50 THEN 0.02
                        WHEN position = 'D' AND train_blocks_per_gp >= 2.50 THEN 0.02
                        ELSE 0.00
                    END
                )
            )
        END as pk_toi_event_modifier
    FROM anchored_projected_rows
),
projected_rows AS (
    SELECT
        calibration_rows.*,
        ROUND((
            CASE
                WHEN situation = 'all' THEN anchored_all_sat_per_60
                WHEN situation = 'pk' THEN projected_sat_per_60
                WHEN unanchored_all_sat_per_60 IS NOT NULL AND unanchored_all_sat_per_60 > 0
                    THEN projected_sat_per_60 * anchored_all_sat_per_60 / unanchored_all_sat_per_60
                ELSE projected_sat_per_60
            END
            * sat_rate_calibration
        )::numeric, 4) as anchored_projected_sat_per_60,
        ROUND((
            CASE
                WHEN situation = 'all' THEN anchored_all_sat_per_60
                WHEN situation = 'pk' THEN projected_sat_per_60
                WHEN unanchored_all_sat_per_60 IS NOT NULL AND unanchored_all_sat_per_60 > 0
                    THEN projected_sat_per_60 * anchored_all_sat_per_60 / unanchored_all_sat_per_60
                ELSE projected_sat_per_60
            END
            * sat_rate_calibration
            * LEAST(0.85, GREATEST(0.05, projected_hdsat_sat_rate * hdsat_share_calibration))
        )::numeric, 4) as anchored_projected_hdsat_per_60
    FROM calibration_rows
),
pp_toi_adjustment_rows AS (
    SELECT
        projected_rows.*,
        CASE
            WHEN situation <> 'pp' OR pos_type <> 'F' THEN 1.00::numeric
            WHEN pp_forward_projection_cohort IN (
                'latest_top400_f_both',
                'latest_top400_f_points_per_gp_only',
                'latest_top400_f_total_points_only'
            ) THEN LEAST(
                1.18,
                GREATEST(
                    0.88,
                    1.00
                    + CASE
                        WHEN latest_team_forward_points_per_gp_rank <= 3 THEN 0.08
                        WHEN latest_team_forward_points_per_gp_rank <= 5 THEN 0.05
                        WHEN latest_team_forward_points_per_gp_rank <= 8 THEN 0.02
                        ELSE 0.00
                    END
                    + CASE
                        WHEN latest_train_age <= 24 AND latest_team_forward_sat_per_gp_rank <= 5 THEN 0.05
                        WHEN latest_train_age <= 24 AND latest_team_forward_sat_per_gp_rank <= 8 THEN 0.03
                        WHEN latest_train_age >= 29 AND latest_team_forward_total_points_rank <= 5 THEN 0.03
                        ELSE 0.00
                    END
                    - CASE
                        WHEN pp_forward_projection_cohort = 'latest_top400_f_total_points_only' THEN 0.03
                        ELSE 0.00
                    END
                )
            )
            ELSE 1.00::numeric
        END as pp_toi_role_modifier,
        CASE
            WHEN situation <> 'pp' OR pos_type <> 'F' THEN NULL::numeric
            WHEN pp_forward_projection_cohort = 'outside_latest_top400_f' AND latest_train_age <= 24 THEN 100::numeric
            WHEN pp_forward_projection_cohort = 'outside_latest_top400_f' AND latest_train_age <= 28 THEN 60::numeric
            WHEN pp_forward_projection_cohort = 'outside_latest_top400_f' THEN 35::numeric
            ELSE 300::numeric
        END as pp_toi_cap_seconds,
        CASE
            WHEN situation <> 'pp' OR pos_type <> 'F' THEN 1.00::numeric
            WHEN f_sat_role_cohort = 'latest_team_f_points_rank_7_9' THEN 0.60::numeric
            WHEN f_sat_role_cohort = 'latest_top400_f_points_rank_10_plus' THEN 0.50::numeric
            ELSE 1.00::numeric
        END as f_pp_toi_rank_modifier,
        CASE
            WHEN situation <> 'pp' OR pos_type <> 'F' THEN NULL::numeric
            WHEN f_sat_role_cohort = 'latest_team_f_points_rank_7_9' THEN 140::numeric
            WHEN f_sat_role_cohort = 'latest_top400_f_points_rank_10_plus' THEN 95::numeric
            ELSE NULL::numeric
        END as f_pp_toi_rank_cap_seconds,
        CASE
            WHEN situation <> 'pp' OR pos_type <> 'D' OR d_pp_role_cohort = 'outside_latest_top200_d' THEN 1.00::numeric
            WHEN d_pp_role_rank = 1 THEN 0.92::numeric
            WHEN d_pp_role_rank = 2 AND latest_train_age <= 24 THEN 0.65::numeric
            WHEN d_pp_role_rank = 2 AND latest_train_age <= 28 THEN 1.00::numeric
            WHEN d_pp_role_rank = 2 THEN 0.80::numeric
            WHEN d_pp_role_rank = 3 THEN 0.85::numeric
            ELSE 0.35::numeric
        END as d_pp_toi_role_modifier,
        CASE
            WHEN situation <> 'pp' OR pos_type <> 'D' OR d_pp_role_cohort = 'outside_latest_top200_d' THEN NULL::numeric
            WHEN d_pp_role_rank = 1 THEN 260::numeric
            WHEN d_pp_role_rank = 2 AND latest_train_age <= 24 THEN 150::numeric
            WHEN d_pp_role_rank = 2 AND latest_train_age <= 28 THEN 180::numeric
            WHEN d_pp_role_rank = 2 THEN 160::numeric
            WHEN d_pp_role_rank = 3 THEN 80::numeric
            ELSE 25::numeric
        END as d_pp_toi_cap_seconds,
        CASE
            WHEN situation = 'pp'
                AND pos_type = 'F'
                AND pp_forward_projection_cohort = 'outside_latest_top400_f'
                THEN LEAST(
                    CASE
                        WHEN latest_train_age <= 24 THEN 100::numeric
                        WHEN latest_train_age <= 28 THEN 60::numeric
                        ELSE 35::numeric
                    END,
                    GREATEST(
                        0,
                        (
                            CASE
                                WHEN latest_train_age <= 24 THEN 1.00
                                WHEN latest_train_age <= 28 THEN 0.65
                                ELSE 0.40
                            END
                            * COALESCE(s2_toi_per_gp, projected_toi_per_gp, 0)
                        )
                        + (GREATEST(0, 8 - COALESCE(latest_team_forward_sat_per_gp_rank, 99)) * 10)
                    )
                )
            ELSE NULL::numeric
        END as pp_toi_outside_top400_projection
    FROM projected_rows
),
final_projected_rows AS (
    SELECT
        pp_toi_adjustment_rows.*,
        ROUND((
            CASE
                WHEN situation = 'pp'
                    AND pos_type = 'F'
                    AND pp_forward_projection_cohort = 'outside_latest_top400_f'
                    THEN LEAST(
                        projected_toi_per_gp * toi_gp_calibration,
                        pp_toi_outside_top400_projection
                    )
                WHEN situation = 'pp' AND pos_type = 'F'
                    THEN LEAST(
                        COALESCE(f_pp_toi_rank_cap_seconds, 999999::numeric),
                        projected_toi_per_gp * toi_gp_calibration * pp_toi_role_modifier * f_pp_toi_rank_modifier
                    )
                WHEN situation = 'pp'
                    AND pos_type = 'D'
                    AND d_pp_role_cohort <> 'outside_latest_top200_d'
                    THEN LEAST(
                        COALESCE(s2_toi_per_gp, projected_toi_per_gp * toi_gp_calibration) * d_pp_toi_role_modifier,
                        d_pp_toi_cap_seconds
                    )
                ELSE projected_toi_per_gp * toi_gp_calibration * pk_toi_event_modifier
            END
        )::numeric, 4) as final_projected_toi_per_gp
    FROM pp_toi_adjustment_rows
)
SELECT
    ? as model_run_id,
    ?::varchar as profile_type,
    projected_rows.entity_key,
    projected_rows.entity_id,
    projected_rows.entity_name,
    projected_rows.entity_role,
    projected_rows.team_context,
    projected_rows.situation,
    projected_rows.age_group,
    projected_rows.sat_momentum_bucket,
    projected_rows.hdsat_momentum_bucket,
    projected_rows.toi_momentum_bucket,
    projected_rows.sh_regression_bucket,
    ROUND(projected_rows.s1_gp::numeric, 4),
    ROUND(projected_rows.s2_gp::numeric, 4),
    ROUND(projected_rows.train_gp_per_season::numeric, 4),
    ROUND(LEAST(84, GREATEST(0, projected_rows.projected_gp * projected_rows.gp_calibration))::numeric, 4) as projected_gp,
    projected_rows.s1_toi_seconds,
    projected_rows.s2_toi_seconds,
    projected_rows.train_toi_seconds,
    projected_rows.s1_toi_per_gp,
    projected_rows.s2_toi_per_gp,
    projected_rows.train_toi_per_gp,
    projected_rows.final_projected_toi_per_gp as projected_toi_per_gp,
    projected_rows.s1_sat,
    projected_rows.s2_sat,
    projected_rows.train_sat,
    projected_rows.s1_sat_per_gp,
    projected_rows.s2_sat_per_gp,
    projected_rows.train_sat_per_gp,
    ROUND((projected_rows.anchored_projected_sat_per_60 * projected_rows.final_projected_toi_per_gp / 3600)::numeric, 4) as projected_sat_per_gp,
    projected_rows.s1_sat_per_60,
    projected_rows.s2_sat_per_60,
    projected_rows.train_sat_per_60,
    projected_rows.anchored_projected_sat_per_60,
    ROUND((projected_rows.anchored_projected_sat_per_60 * projected_rows.final_projected_toi_per_gp / 3600 * LEAST(84, GREATEST(0, projected_rows.projected_gp * projected_rows.gp_calibration)))::numeric, 4) as projected_sat_season,
    projected_rows.s1_hdsat,
    projected_rows.s2_hdsat,
    projected_rows.train_hdsat,
    projected_rows.s1_hdsat_per_gp,
    projected_rows.s2_hdsat_per_gp,
    projected_rows.train_hdsat_per_gp,
    ROUND((projected_rows.anchored_projected_hdsat_per_60 * projected_rows.final_projected_toi_per_gp / 3600)::numeric, 4) as projected_hdsat_per_gp,
    projected_rows.s1_hdsat_per_60,
    projected_rows.s2_hdsat_per_60,
    projected_rows.train_hdsat_per_60,
    projected_rows.anchored_projected_hdsat_per_60,
    projected_rows.s1_hdsat_sat_rate,
    projected_rows.s2_hdsat_sat_rate,
    projected_rows.train_hdsat_sat_rate,
    ROUND(LEAST(0.85, GREATEST(0.05, projected_rows.projected_hdsat_sat_rate * projected_rows.hdsat_share_calibration))::numeric, 6) as projected_hdsat_sat_rate,
    ROUND((projected_rows.anchored_projected_hdsat_per_60 * projected_rows.final_projected_toi_per_gp / 3600 * LEAST(84, GREATEST(0, projected_rows.projected_gp * projected_rows.gp_calibration)))::numeric, 4) as projected_hdsat_season,
    projected_rows.s1_sog,
    projected_rows.s2_sog,
    projected_rows.train_sog,
    projected_rows.s1_goals,
    projected_rows.s2_goals,
    projected_rows.train_goals,
    projected_rows.s1_sh_pct,
    projected_rows.s2_sh_pct,
    projected_rows.train_sh_pct,
    'skater_offense_strength_rate_v15'::varchar as formula_version,
    (
        projected_rows.situation || ':' ||
        projected_rows.age_group || ':' ||
        projected_rows.sat_momentum_bucket || ':' ||
        projected_rows.hdsat_momentum_bucket || ':' ||
        projected_rows.toi_momentum_bucket || ':' ||
        CASE projected_rows.pp_forward_projection_cohort
            WHEN 'latest_top400_f_both' THEN 'f400b'
            WHEN 'latest_top400_f_points_per_gp_only' THEN 'f400pgp'
            WHEN 'latest_top400_f_total_points_only' THEN 'f400pts'
            WHEN 'outside_latest_top400_f' THEN 'fout400'
            ELSE 'nf'
        END || ':' ||
        CASE projected_rows.f_sat_role_cohort
            WHEN 'latest_team_f_points_rank_1_3' THEN 'fr13'
            WHEN 'latest_team_f_points_rank_4_6' THEN 'fr46'
            WHEN 'latest_team_f_points_rank_7_9' THEN 'fr79'
            WHEN 'latest_top400_f_points_rank_10_plus' THEN 'fr10p'
            WHEN 'outside_latest_top400_f' THEN 'frout'
            ELSE 'nfr'
        END || ':' ||
        CASE projected_rows.d_pp_role_cohort
            WHEN 'latest_top200_d_team_pp_rank_1' THEN 'dr1'
            WHEN 'latest_top200_d_team_pp_rank_2' THEN 'dr2'
            WHEN 'latest_top200_d_team_pp_rank_3' THEN 'dr3'
            WHEN 'latest_top200_d_team_pp_rank_4_plus' THEN 'dr4p'
            WHEN 'outside_latest_top200_d' THEN 'dout200'
            ELSE 'nd'
        END || ':' ||
        CASE projected_rows.f_pk_sat_role_cohort
            WHEN 'latest_pk_toi_f_rank_1_100' THEN 'fpk1'
            WHEN 'latest_pk_toi_f_rank_101_200' THEN 'fpk2'
            WHEN 'latest_pk_toi_f_rank_201_400' THEN 'fpk3'
            WHEN 'outside_latest_pk_toi_top400_f' THEN 'fpkout'
            ELSE 'nfpk'
        END || ':' ||
        CASE projected_rows.d_pk_sat_role_cohort
            WHEN 'latest_pk_toi_d_rank_1_50' THEN 'dpk1'
            WHEN 'latest_pk_toi_d_rank_51_100' THEN 'dpk2'
            WHEN 'latest_pk_toi_d_rank_101_200' THEN 'dpk3'
            WHEN 'outside_latest_pk_toi_top200_d' THEN 'dpkout'
            ELSE 'ndpk'
        END
    )::varchar as formula_segment,
    (
    jsonb_build_object(
        'source', 'nhl_game_summaries',
        'formula_version', 'skater_offense_strength_rate_v15',
        'prior_training_season_id', ?::text,
        'latest_training_season_id', ?::text,
        'target_season_id_for_age', ?::text,
        'train_season_count', ?::int,
        'anchor_projected_sat_per_60', projected_rows.anchor_projected_sat_per_60,
        'anchored_all_sat_per_60', projected_rows.anchored_all_sat_per_60,
        'unanchored_all_sat_per_60', projected_rows.unanchored_all_sat_per_60,
        'usage_tier', projected_rows.usage_tier,
        'player_projection_cohort', projected_rows.player_projection_cohort,
        'all_train_points_per_gp', projected_rows.all_train_points_per_gp,
        'all_train_goals_per_gp', projected_rows.all_train_goals_per_gp,
        'all_train_sat_per_gp', projected_rows.all_train_sat_per_gp,
        'all_train_toi_per_gp', projected_rows.all_train_toi_per_gp,
        'latest_train_age', projected_rows.latest_train_age,
        'latest_train_gp', projected_rows.latest_train_gp,
        'latest_train_points', projected_rows.latest_train_points,
        'latest_train_points_per_gp', projected_rows.latest_train_points_per_gp,
        'latest_train_goals_per_gp', projected_rows.latest_train_goals_per_gp,
        'latest_train_sat_per_gp', projected_rows.latest_train_sat_per_gp,
        'latest_train_sog_per_gp', projected_rows.latest_train_sog_per_gp,
        'latest_train_hdsat_per_gp', projected_rows.latest_train_hdsat_per_gp
    )
    || jsonb_build_object(
        'latest_forward_points_per_gp_rank', projected_rows.latest_forward_points_per_gp_rank,
        'latest_team_forward_qualified_points_per_gp_rank', projected_rows.latest_team_forward_qualified_points_per_gp_rank,
        'latest_forward_total_points_rank', projected_rows.latest_forward_total_points_rank,
        'latest_forward_pk_toi_rank', projected_rows.latest_forward_pk_toi_rank,
        'latest_train_pk_toi_per_gp', projected_rows.latest_train_pk_toi_per_gp,
        'latest_team_forward_points_per_gp_rank', projected_rows.latest_team_forward_points_per_gp_rank,
        'latest_team_forward_total_points_rank', projected_rows.latest_team_forward_total_points_rank,
        'latest_team_forward_sat_per_gp_rank', projected_rows.latest_team_forward_sat_per_gp_rank,
        'latest_team_forward_sog_per_gp_rank', projected_rows.latest_team_forward_sog_per_gp_rank,
        'latest_team_forward_hdsat_per_gp_rank', projected_rows.latest_team_forward_hdsat_per_gp_rank,
        'pp_forward_projection_cohort', projected_rows.pp_forward_projection_cohort,
        'f_sat_role_cohort', projected_rows.f_sat_role_cohort,
        'pp_toi_role_modifier', projected_rows.pp_toi_role_modifier,
        'pp_toi_cap_seconds', projected_rows.pp_toi_cap_seconds
    )
    || jsonb_build_object(
        'latest_defense_train_gp', projected_rows.latest_defense_train_gp,
        'latest_defense_points_per_gp', projected_rows.latest_defense_points_per_gp,
        'latest_defense_pp_points_per_gp', projected_rows.latest_defense_pp_points_per_gp,
        'latest_defense_pp_sat_per_gp', projected_rows.latest_defense_pp_sat_per_gp,
        'latest_defense_pp_sog_per_gp', projected_rows.latest_defense_pp_sog_per_gp,
        'latest_defense_pk_toi_per_gp', projected_rows.latest_defense_pk_toi_per_gp,
        'latest_defense_points_per_gp_rank', projected_rows.latest_defense_points_per_gp_rank,
        'latest_defense_pk_toi_rank', projected_rows.latest_defense_pk_toi_rank,
        'latest_team_defense_points_per_gp_rank', projected_rows.latest_team_defense_points_per_gp_rank,
        'latest_team_defense_pp_points_per_gp_rank', projected_rows.latest_team_defense_pp_points_per_gp_rank,
        'latest_team_defense_pp_sat_per_gp_rank', projected_rows.latest_team_defense_pp_sat_per_gp_rank,
        'latest_team_defense_pp_sog_per_gp_rank', projected_rows.latest_team_defense_pp_sog_per_gp_rank,
        'd_sat_role_cohort', projected_rows.d_sat_role_cohort,
        'd1_all_sat_per_60', projected_rows.d1_all_sat_per_60,
        'd_pp_role_rank', projected_rows.d_pp_role_rank,
        'd_pp_role_cohort', projected_rows.d_pp_role_cohort,
        'd_pp_toi_role_modifier', projected_rows.d_pp_toi_role_modifier,
        'd_pp_toi_cap_seconds', projected_rows.d_pp_toi_cap_seconds,
        'f_pk_sat_role_cohort', projected_rows.f_pk_sat_role_cohort,
        'd_pk_sat_role_cohort', projected_rows.d_pk_sat_role_cohort,
        'train_ev_sat_per_60_all_toi', projected_rows.train_ev_sat_per_60_all_toi,
        'train_ev_sog_per_60_ev_toi', projected_rows.train_ev_sog_per_60_ev_toi,
        's2_pk_sog_per_60_pk_toi', projected_rows.s2_pk_sog_per_60_pk_toi,
        's2_pk_sat_per_60_all_toi', projected_rows.s2_pk_sat_per_60_all_toi,
        's2_all_missed_shots_per_gp', projected_rows.s2_all_missed_shots_per_gp,
        's2_ev_secondary_assists_per_60_all_toi', projected_rows.s2_ev_secondary_assists_per_60_all_toi,
        's2_pk_assists_per_gp', projected_rows.s2_pk_assists_per_gp
    )
    || jsonb_build_object(
        'uncalibrated_projected_toi_per_gp', projected_rows.projected_toi_per_gp,
        'final_projected_toi_per_gp', projected_rows.final_projected_toi_per_gp,
        'f_pp_toi_rank_modifier', projected_rows.f_pp_toi_rank_modifier,
        'f_pp_toi_rank_cap_seconds', projected_rows.f_pp_toi_rank_cap_seconds,
        'pk_toi_event_modifier', projected_rows.pk_toi_event_modifier,
        's2_blocks_per_gp', projected_rows.s2_blocks_per_gp,
        'train_blocks_per_gp', projected_rows.train_blocks_per_gp,
        'blocks_delta_train', projected_rows.blocks_delta_train,
        'pk_toi_anchor_strategy', CASE WHEN projected_rows.situation = 'pk' THEN 'train_65_s2_35_blocks_d_modifier' ELSE NULL END,
        'sat_rate_anchor_strategy', CASE projected_rows.situation
            WHEN 'all' THEN
                CASE
                    WHEN projected_rows.d_sat_role_cohort = 'latest_team_d_points_rank_1' THEN 'd1_s2_train_blend'
                    WHEN projected_rows.f_sat_role_cohort = 'latest_top400_f_points_rank_10_plus' THEN 'f10_plus_all_sat_suppression'
                    ELSE 'anchored_all'
                END
            WHEN 'ev' THEN
                CASE
                    WHEN projected_rows.d_sat_role_cohort IN (
                        'latest_team_d_points_rank_1',
                        'latest_team_d_points_rank_2',
                        'latest_team_d_points_rank_3'
                    ) THEN 'd_rank_ev_sat_calibration'
                    WHEN projected_rows.f_sat_role_cohort = 'latest_top400_f_points_rank_10_plus' THEN 'f10_plus_ev_sat_suppression'
                    ELSE 's2_heavy_weak_offense_regression'
                END
            WHEN 'pp' THEN
                CASE
                    WHEN projected_rows.d_sat_role_cohort IN (
                        'latest_team_d_points_rank_1',
                        'latest_team_d_points_rank_2',
                        'latest_team_d_points_rank_3'
                    ) THEN 'd_rank_pp_toi_sat_calibration'
                    ELSE 'train_heavy_elite_s2_exception_low_offense_suppression'
                END
            WHEN 'pk' THEN
                CASE
                    WHEN projected_rows.f_pk_sat_role_cohort = 'latest_pk_toi_f_rank_1_100' THEN 'pk_sat_f_1_100_pk_sat_ev_sat'
                    WHEN projected_rows.f_pk_sat_role_cohort = 'latest_pk_toi_f_rank_101_200' THEN 'pk_sat_f_101_200_ev_sog_pk_sog'
                    WHEN projected_rows.d_pk_sat_role_cohort = 'latest_pk_toi_d_rank_1_50' THEN 'pk_sat_d_1_50_pk_sat_all_toi_sm'
                    WHEN projected_rows.d_pk_sat_role_cohort = 'latest_pk_toi_d_rank_51_100' THEN 'pk_sat_d_51_100_eva2_pka_experimental'
                    ELSE 'pk_sat_low_confidence_suppressed_fallback'
                END
            ELSE 'anchored_all'
        END,
        's2_tkgv_per_gp', projected_rows.s2_tkgv_per_gp,
        'train_tkgv_per_gp', projected_rows.train_tkgv_per_gp,
        'tkgv_delta_train', projected_rows.tkgv_delta_train,
        'sat_rate_calibration', projected_rows.sat_rate_calibration,
        'hdsat_share_calibration', projected_rows.hdsat_share_calibration,
        'toi_gp_calibration', projected_rows.toi_gp_calibration,
        'gp_calibration', projected_rows.gp_calibration,
        'base_projected_sat_per_60', projected_rows.base_projected_sat_per_60,
        'base_projected_hdsat_per_60', projected_rows.base_projected_hdsat_per_60,
        'base_projected_toi_per_gp', projected_rows.base_projected_toi_per_gp
    )
    )::json as metadata,
    ?::timestamp as projected_at,
    ?::timestamp as created_at,
    ?::timestamp as updated_at
FROM final_projected_rows projected_rows
WHERE projected_rows.train_toi_seconds > 0
ON CONFLICT (model_run_id, profile_type, entity_key, situation)
DO UPDATE SET
    entity_id = EXCLUDED.entity_id,
    entity_name = EXCLUDED.entity_name,
    entity_role = EXCLUDED.entity_role,
    team_context = EXCLUDED.team_context,
    age_group = EXCLUDED.age_group,
    sat_momentum_bucket = EXCLUDED.sat_momentum_bucket,
    hdsat_momentum_bucket = EXCLUDED.hdsat_momentum_bucket,
    toi_momentum_bucket = EXCLUDED.toi_momentum_bucket,
    sh_regression_bucket = EXCLUDED.sh_regression_bucket,
    s1_gp = EXCLUDED.s1_gp,
    s2_gp = EXCLUDED.s2_gp,
    train_gp_per_season = EXCLUDED.train_gp_per_season,
    projected_gp = EXCLUDED.projected_gp,
    s1_toi_seconds = EXCLUDED.s1_toi_seconds,
    s2_toi_seconds = EXCLUDED.s2_toi_seconds,
    train_toi_seconds = EXCLUDED.train_toi_seconds,
    s1_toi_per_gp = EXCLUDED.s1_toi_per_gp,
    s2_toi_per_gp = EXCLUDED.s2_toi_per_gp,
    train_toi_per_gp = EXCLUDED.train_toi_per_gp,
    projected_toi_per_gp = EXCLUDED.projected_toi_per_gp,
    s1_sat = EXCLUDED.s1_sat,
    s2_sat = EXCLUDED.s2_sat,
    train_sat = EXCLUDED.train_sat,
    s1_sat_per_gp = EXCLUDED.s1_sat_per_gp,
    s2_sat_per_gp = EXCLUDED.s2_sat_per_gp,
    train_sat_per_gp = EXCLUDED.train_sat_per_gp,
    projected_sat_per_gp = EXCLUDED.projected_sat_per_gp,
    s1_sat_per_60 = EXCLUDED.s1_sat_per_60,
    s2_sat_per_60 = EXCLUDED.s2_sat_per_60,
    train_sat_per_60 = EXCLUDED.train_sat_per_60,
    projected_sat_per_60 = EXCLUDED.projected_sat_per_60,
    projected_sat_season = EXCLUDED.projected_sat_season,
    s1_hdsat = EXCLUDED.s1_hdsat,
    s2_hdsat = EXCLUDED.s2_hdsat,
    train_hdsat = EXCLUDED.train_hdsat,
    s1_hdsat_per_gp = EXCLUDED.s1_hdsat_per_gp,
    s2_hdsat_per_gp = EXCLUDED.s2_hdsat_per_gp,
    train_hdsat_per_gp = EXCLUDED.train_hdsat_per_gp,
    projected_hdsat_per_gp = EXCLUDED.projected_hdsat_per_gp,
    s1_hdsat_per_60 = EXCLUDED.s1_hdsat_per_60,
    s2_hdsat_per_60 = EXCLUDED.s2_hdsat_per_60,
    train_hdsat_per_60 = EXCLUDED.train_hdsat_per_60,
    projected_hdsat_per_60 = EXCLUDED.projected_hdsat_per_60,
    s1_hdsat_sat_rate = EXCLUDED.s1_hdsat_sat_rate,
    s2_hdsat_sat_rate = EXCLUDED.s2_hdsat_sat_rate,
    train_hdsat_sat_rate = EXCLUDED.train_hdsat_sat_rate,
    projected_hdsat_sat_rate = EXCLUDED.projected_hdsat_sat_rate,
    projected_hdsat_season = EXCLUDED.projected_hdsat_season,
    s1_sog = EXCLUDED.s1_sog,
    s2_sog = EXCLUDED.s2_sog,
    train_sog = EXCLUDED.train_sog,
    s1_goals = EXCLUDED.s1_goals,
    s2_goals = EXCLUDED.s2_goals,
    train_goals = EXCLUDED.train_goals,
    s1_sh_pct = EXCLUDED.s1_sh_pct,
    s2_sh_pct = EXCLUDED.s2_sh_pct,
    train_sh_pct = EXCLUDED.train_sh_pct,
    formula_version = EXCLUDED.formula_version,
    formula_segment = EXCLUDED.formula_segment,
    metadata = EXCLUDED.metadata,
    projected_at = EXCLUDED.projected_at,
    updated_at = EXCLUDED.updated_at
SQL;

        DB::statement($sql, [
            $run->id,
            self::SKATER_OFFENSE_PROFILE_TYPE,
            $targetSeasonStartYear,
            $latestTrainingSeasonStartYear,
            ...($entityKey === null ? [] : [$entityKey]),
            $latestTrainingSeasonId,
            $gameType,
            $latestTrainingSeasonId,
            $gameType,
            $latestTrainingSeasonId,
            $gameType,
            $latestTrainingSeasonId,
            $gameType,
            ...$seasonIds,
            $gameType,
            $priorTrainingSeasonId,
            $latestTrainingSeasonId,
            $priorTrainingSeasonId,
            $latestTrainingSeasonId,
            $trainSeasonCount,
            $trainSeasonCount,
            $trainSeasonCount,
            $trainSeasonCount,
            $trainSeasonCount,
            $trainSeasonCount,
            $trainSeasonCount,
            $trainSeasonCount,
            $trainSeasonCount,
            $trainSeasonCount,
            $run->id,
            self::SKATER_OFFENSE_PROFILE_TYPE,
            $priorTrainingSeasonId,
            $latestTrainingSeasonId,
            $targetSeasonId,
            $trainSeasonCount,
            $now,
            $now,
            $now,
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

        return;

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

    /**
     * Refresh player-game HDSAT counts for a model-run season when reporting needs actual labels.
     */
    public function refreshHighDangerSatForRun(
        NhlModelRun $run,
        string $seasonId,
        int $gameType,
        ?string $entityKey = null
    ): void {
        $goalModel = $this->goalModelForRun($run);

        if ($goalModel === null) {
            return;
        }

        $this->refreshGameSummaryHighDangerSat($seasonId, $gameType, $entityKey, $goalModel);
    }

    /**
     * Refresh player-game HDSAT counts for the skater offense /60 build pass.
     */
    private function refreshGameSummaryHighDangerSat(
        string $seasonId,
        int $gameType,
        ?string $entityKey,
        NhlExpectedGoalsModel $goalModel
    ): void {
        if (app(NhlShotAttemptModelScorer::class)->refreshGameSummaryHighDangerSat(
            model: $goalModel,
            seasonId: $seasonId,
            gameType: $gameType,
            highDangerThreshold: self::HIGH_DANGER_GOAL_PROBABILITY,
            entityKey: $entityKey
        )) {
            $this->refreshSeasonStatsHighDangerSat($seasonId, $gameType, $entityKey);

            return;
        }

        if (! Schema::hasColumn('nhl_game_summaries', 'hdsat')) {
            return;
        }

        $hasSplitHighDangerColumns = $this->hasGameSummarySplitHighDangerSatColumns();
        $splitHighDangerAttemptSelect = $hasSplitHighDangerColumns ? <<<SQL
,
        CASE
            WHEN facts.strength = 'EV' OR facts.strength_bucket = 'EV' THEN 'ev'
            WHEN facts.strength = 'PP' OR facts.strength_bucket = 'PP' THEN 'pp'
            WHEN facts.strength = 'PK' OR facts.strength_bucket = 'PK' THEN 'pk'
            ELSE 'unknown'
        END as situation
SQL : '';
        $splitHighDangerAggregateSelects = $hasSplitHighDangerColumns ? <<<SQL
,
        SUM(is_high_danger_sat) FILTER (WHERE situation = 'ev') as evhdsat,
        SUM(is_high_danger_sat) FILTER (WHERE situation = 'pp') as pphdsat,
        SUM(is_high_danger_sat) FILTER (WHERE situation = 'pk') as pkhdsat
SQL : '';
        $splitHighDangerUpdates = $hasSplitHighDangerColumns ? <<<SQL
,
    evhdsat = COALESCE((
        SELECT high_danger_totals.evhdsat
        FROM high_danger_totals
        WHERE high_danger_totals.nhl_game_id = summaries.nhl_game_id
            AND high_danger_totals.nhl_player_id = summaries.nhl_player_id
    ), 0),
    pphdsat = COALESCE((
        SELECT high_danger_totals.pphdsat
        FROM high_danger_totals
        WHERE high_danger_totals.nhl_game_id = summaries.nhl_game_id
            AND high_danger_totals.nhl_player_id = summaries.nhl_player_id
    ), 0),
    pkhdsat = COALESCE((
        SELECT high_danger_totals.pkhdsat
        FROM high_danger_totals
        WHERE high_danger_totals.nhl_game_id = summaries.nhl_game_id
            AND high_danger_totals.nhl_player_id = summaries.nhl_player_id
    ), 0)
SQL : '';

        $entityWhere = '';
        $resetEntityWhere = '';
        $bindings = [
            (int) $goalModel->id,
            self::HIGH_DANGER_GOAL_PROBABILITY,
            $seasonId,
            $gameType,
        ];

        if ($entityKey !== null) {
            $entityWhere = "AND ('skater_offense:' || facts.shooter_player_id::text) = ?";
            $resetEntityWhere = "AND ('skater_offense:' || reset_facts.shooter_player_id::text) = ?";
            $bindings[] = $entityKey;
        }

        $candidateKeys = implode(",\n                    ", $this->goalModelCandidateBucketKeySql($goalModel, 'facts'));

        DB::statement(<<<SQL
WITH scored_attempts AS (
    SELECT
        facts.nhl_game_id,
        facts.shooter_player_id as nhl_player_id,
        CASE
            WHEN matched.smoothed_goal_probability >= ? THEN 1
            ELSE 0
        END as is_high_danger_sat
        {$splitHighDangerAttemptSelect}
    FROM nhl_shot_attempts_facts facts
    INNER JOIN nhl_games games ON games.nhl_game_id = facts.nhl_game_id
    LEFT JOIN LATERAL (
        SELECT buckets.smoothed_goal_probability
        FROM (
            VALUES
                {$candidateKeys}
        ) candidates(sort_order, bucket_key)
        INNER JOIN nhl_expected_goals_model_buckets buckets
            ON buckets.expected_goals_model_id = ?
            AND buckets.bucket_key = candidates.bucket_key
        ORDER BY candidates.sort_order
        LIMIT 1
    ) matched ON true
    WHERE facts.season_id = ?
        AND games.game_type = ?
        AND facts.shooter_player_id IS NOT NULL
        AND COALESCE(facts.period_type, '') <> 'SO'
        AND COALESCE(facts.is_empty_net, false) = false
        AND COALESCE(NULLIF(facts.shot_type_bucket, ''), 'unknown') <> 'unknown'
        {$entityWhere}
),
high_danger_totals AS (
    SELECT
        nhl_game_id,
        nhl_player_id,
        SUM(is_high_danger_sat) as hdsat
        {$splitHighDangerAggregateSelects}
    FROM scored_attempts
    GROUP BY nhl_game_id, nhl_player_id
)
UPDATE nhl_game_summaries summaries
SET hdsat = COALESCE((
        SELECT high_danger_totals.hdsat
        FROM high_danger_totals
        WHERE high_danger_totals.nhl_game_id = summaries.nhl_game_id
            AND high_danger_totals.nhl_player_id = summaries.nhl_player_id
    ), 0)
    {$splitHighDangerUpdates},
    updated_at = now()
FROM nhl_games games
WHERE games.nhl_game_id = summaries.nhl_game_id
    AND games.season_id = ?
    AND games.game_type = ?
    AND EXISTS (
        SELECT 1
        FROM nhl_shot_attempts_facts reset_facts
        WHERE reset_facts.nhl_game_id = summaries.nhl_game_id
            AND reset_facts.shooter_player_id = summaries.nhl_player_id
            AND reset_facts.season_id = ?
            AND COALESCE(reset_facts.period_type, '') <> 'SO'
            AND COALESCE(reset_facts.is_empty_net, false) = false
            AND COALESCE(NULLIF(reset_facts.shot_type_bucket, ''), 'unknown') <> 'unknown'
            {$resetEntityWhere}
    )
SQL, array_merge(
            [$bindings[1], $bindings[0], $bindings[2], $bindings[3]],
            array_slice($bindings, 4),
            [$seasonId, $gameType, $seasonId],
            array_slice($bindings, 4),
        ));

        $this->refreshSeasonStatsHighDangerSat($seasonId, $gameType, $entityKey);
    }

    /**
     * Keep existing season summary rows aligned with refreshed player-game HDSAT counts.
     */
    private function refreshSeasonStatsHighDangerSat(string $seasonId, int $gameType, ?string $entityKey): void
    {
        if (! $this->hasSeasonStatsHighDangerSatColumns()) {
            return;
        }

        $hasSplitHighDangerColumns = $this->hasSeasonStatsSplitHighDangerSatColumns()
            && $this->hasGameSummarySplitHighDangerSatColumns();
        $splitHighDangerSelects = $hasSplitHighDangerColumns ? <<<SQL
,
        SUM(COALESCE(summaries.evhdsat, 0)) as evhdsat,
        SUM(COALESCE(summaries.pphdsat, 0)) as pphdsat,
        SUM(COALESCE(summaries.pkhdsat, 0)) as pkhdsat
SQL : '';
        $splitHighDangerUpdates = $hasSplitHighDangerColumns ? <<<SQL
,
    evhdsat = COALESCE(season_hdsat.evhdsat, 0),
    pphdsat = COALESCE(season_hdsat.pphdsat, 0),
    pkhdsat = COALESCE(season_hdsat.pkhdsat, 0),
    evhdsat_p60 = CASE
        WHEN COALESCE(stats.toi, 0) > 0
            THEN ROUND((COALESCE(season_hdsat.evhdsat, 0)::numeric * 3600 / stats.toi)::numeric, 3)
        ELSE 0
    END,
    pphdsat_p60 = CASE
        WHEN COALESCE(stats.toi, 0) > 0
            THEN ROUND((COALESCE(season_hdsat.pphdsat, 0)::numeric * 3600 / stats.toi)::numeric, 3)
        ELSE 0
    END,
    pkhdsat_p60 = CASE
        WHEN COALESCE(stats.toi, 0) > 0
            THEN ROUND((COALESCE(season_hdsat.pkhdsat, 0)::numeric * 3600 / stats.toi)::numeric, 3)
        ELSE 0
    END
SQL : '';

        $entityWhere = '';
        $bindings = [$seasonId, $gameType];

        if ($entityKey !== null) {
            $entityWhere = "AND ('skater_offense:' || summaries.nhl_player_id::text) = ?";
            $bindings[] = $entityKey;
        }

        DB::statement(<<<SQL
WITH season_hdsat AS (
    SELECT
        summaries.nhl_player_id,
        SUM(COALESCE(summaries.hdsat, 0)) as hdsat
        {$splitHighDangerSelects}
    FROM nhl_game_summaries summaries
    INNER JOIN nhl_games games ON games.nhl_game_id = summaries.nhl_game_id
    WHERE games.season_id = ?
        AND games.game_type = ?
        {$entityWhere}
    GROUP BY summaries.nhl_player_id
)
UPDATE nhl_season_stats stats
SET hdsat = COALESCE(season_hdsat.hdsat, 0),
    hdsat_p60 = CASE
        WHEN COALESCE(stats.toi, 0) > 0
            THEN ROUND((COALESCE(season_hdsat.hdsat, 0)::numeric * 3600 / stats.toi)::numeric, 3)
        ELSE 0
    END
    {$splitHighDangerUpdates},
    updated_at = now()
FROM season_hdsat
WHERE stats.season_id = ?
    AND stats.game_type = ?
    AND stats.nhl_player_id = season_hdsat.nhl_player_id
SQL, array_merge($bindings, [$seasonId, $gameType]));
    }

    /**
     * Determine whether player-game summaries can store strength-split HDSAT.
     */
    private function hasGameSummarySplitHighDangerSatColumns(): bool
    {
        return Schema::hasColumn('nhl_game_summaries', 'evhdsat')
            && Schema::hasColumn('nhl_game_summaries', 'pphdsat')
            && Schema::hasColumn('nhl_game_summaries', 'pkhdsat');
    }

    /**
     * Determine whether season summaries can store total HDSAT.
     */
    private function hasSeasonStatsHighDangerSatColumns(): bool
    {
        return Schema::hasColumn('nhl_season_stats', 'hdsat')
            && Schema::hasColumn('nhl_season_stats', 'hdsat_p60');
    }

    /**
     * Determine whether season summaries can store strength-split HDSAT.
     */
    private function hasSeasonStatsSplitHighDangerSatColumns(): bool
    {
        return Schema::hasColumn('nhl_season_stats', 'evhdsat')
            && Schema::hasColumn('nhl_season_stats', 'pphdsat')
            && Schema::hasColumn('nhl_season_stats', 'pkhdsat')
            && Schema::hasColumn('nhl_season_stats', 'evhdsat_p60')
            && Schema::hasColumn('nhl_season_stats', 'pphdsat_p60')
            && Schema::hasColumn('nhl_season_stats', 'pkhdsat_p60');
    }

    private function goalModelForRun(NhlModelRun $run): ?NhlExpectedGoalsModel
    {
        return NhlExpectedGoalsModel::query()
            ->where('model_run_id', $run->id)
            ->where('prediction_target', NhlExpectedGoalsBackfiller::TARGET_GOAL)
            ->where('status', 'draft')
            ->whereNotNull('trained_at')
            ->whereExists(function ($query): void {
                $query
                    ->selectRaw('1')
                    ->from('nhl_expected_goals_model_buckets')
                    ->whereColumn(
                        'nhl_expected_goals_model_buckets.expected_goals_model_id',
                        'nhl_expected_goals_models.id'
                    );
            })
            ->orderByDesc('trained_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return array<int, string>
     */
    private function goalModelCandidateBucketKeySql(NhlExpectedGoalsModel $goalModel, string $tableAlias): array
    {
        $fallbackLevels = collect((array) data_get($goalModel->feature_config, 'fallback_levels', []))
            ->map(function (mixed $level): array {
                return collect((array) $level)
                    ->map(fn (mixed $factorKey): string => (string) $factorKey)
                    ->reject(fn (string $factorKey): bool => $factorKey === '')
                    ->values()
                    ->all();
            })
            ->filter(fn (array $level): bool => $level !== [])
            ->values();

        if ($fallbackLevels->isEmpty()) {
            return (new NhlShotAttemptAnalysisBuckets())->candidateBucketKeySql($tableAlias);
        }

        return $fallbackLevels
            ->map(function (array $factorKeys, int $index) use ($tableAlias): string {
                $levelNumber = in_array('baseline', $factorKeys, true) ? 99 : $index + 1;
                $parts = collect($factorKeys)
                    ->map(fn (string $factorKey): string => "'{$factorKey}=' || " . $this->goalModelFactorExpression($factorKey, $tableAlias))
                    ->implode(" || '|' || ");

                return '(' . $levelNumber . ", 'L" . str_pad((string) $levelNumber, 2, '0', STR_PAD_LEFT) . "|' || {$parts})";
            })
            ->values()
            ->all();
    }

    private function goalModelFactorExpression(string $factorKey, string $tableAlias): string
    {
        return match ($factorKey) {
            'distance_group' => "CASE
                WHEN {$tableAlias}.shot_distance IS NULL THEN 'unknown'
                WHEN {$tableAlias}.shot_distance < 5 THEN 'd_000_005'
                WHEN {$tableAlias}.shot_distance < 10 THEN 'd_005_010'
                WHEN {$tableAlias}.shot_distance < 15 THEN 'd_010_015'
                WHEN {$tableAlias}.shot_distance < 20 THEN 'd_015_020'
                WHEN {$tableAlias}.shot_distance < 25 THEN 'd_020_025'
                WHEN {$tableAlias}.shot_distance < 30 THEN 'd_025_030'
                WHEN {$tableAlias}.shot_distance < 35 THEN 'd_030_035'
                WHEN {$tableAlias}.shot_distance < 40 THEN 'd_035_040'
                WHEN {$tableAlias}.shot_distance < 45 THEN 'd_040_045'
                WHEN {$tableAlias}.shot_distance < 50 THEN 'd_045_050'
                WHEN {$tableAlias}.shot_distance < 55 THEN 'd_050_055'
                WHEN {$tableAlias}.shot_distance < 60 THEN 'd_055_060'
                ELSE 'd_060_plus'
            END",
            'angle_group' => "CASE
                WHEN {$tableAlias}.abs_shot_angle IS NULL THEN 'unknown'
                WHEN {$tableAlias}.abs_shot_angle < 10 THEN 'a_000_010'
                WHEN {$tableAlias}.abs_shot_angle < 20 THEN 'a_010_020'
                WHEN {$tableAlias}.abs_shot_angle < 30 THEN 'a_020_030'
                WHEN {$tableAlias}.abs_shot_angle < 40 THEN 'a_030_040'
                WHEN {$tableAlias}.abs_shot_angle < 50 THEN 'a_040_050'
                WHEN {$tableAlias}.abs_shot_angle < 60 THEN 'a_050_060'
                WHEN {$tableAlias}.abs_shot_angle < 70 THEN 'a_060_070'
                WHEN {$tableAlias}.abs_shot_angle < 80 THEN 'a_070_080'
                WHEN {$tableAlias}.abs_shot_angle <= 90 THEN 'a_080_090'
                ELSE 'invalid_gt_90'
            END",
            'shot_type_group' => "COALESCE(NULLIF({$tableAlias}.shot_type_bucket, ''), 'unknown')",
            'sequence_group' => "CASE
                WHEN {$tableAlias}.is_rush = true AND {$tableAlias}.is_rebound = true THEN 'rush_rebound'
                WHEN {$tableAlias}.is_rebound = true THEN 'rebound'
                WHEN {$tableAlias}.is_rush = true THEN 'rush'
                ELSE 'settled'
            END",
            'strength_group' => "COALESCE(NULLIF({$tableAlias}.strength_bucket, ''), NULLIF({$tableAlias}.strength, ''), 'unknown')",
            'period_group' => "CASE
                WHEN {$tableAlias}.period = 1 THEN 'p1'
                WHEN {$tableAlias}.period = 2 THEN 'p2'
                WHEN {$tableAlias}.period = 3 THEN 'p3'
                WHEN {$tableAlias}.period > 3 THEN 'ot'
                ELSE 'unknown'
            END",
            'score_state_group' => "COALESCE(NULLIF({$tableAlias}.score_state_bucket, ''), 'unknown')",
            'shooter_age_group' => "CASE
                WHEN {$tableAlias}.shooter_age_years IS NULL THEN 'unknown'
                WHEN {$tableAlias}.shooter_age_years <= 21 THEN 'age_le_21'
                WHEN {$tableAlias}.shooter_age_years <= 25 THEN 'age_22_25'
                WHEN {$tableAlias}.shooter_age_years <= 29 THEN 'age_26_29'
                WHEN {$tableAlias}.shooter_age_years <= 33 THEN 'age_30_33'
                ELSE 'age_34_plus'
            END",
            'shooter_height_group' => "CASE
                WHEN {$tableAlias}.shooter_height_inches IS NULL THEN 'unknown'
                WHEN {$tableAlias}.shooter_height_inches <= 68 THEN 'h_le_68'
                WHEN {$tableAlias}.shooter_height_inches <= 71 THEN 'h_69_71'
                WHEN {$tableAlias}.shooter_height_inches <= 74 THEN 'h_72_74'
                WHEN {$tableAlias}.shooter_height_inches <= 77 THEN 'h_75_77'
                ELSE 'h_78_plus'
            END",
            'shooter_weight_group' => "CASE
                WHEN {$tableAlias}.shooter_weight_lbs IS NULL THEN 'unknown'
                WHEN {$tableAlias}.shooter_weight_lbs < 180 THEN 'w_lt_180'
                WHEN {$tableAlias}.shooter_weight_lbs < 200 THEN 'w_180_199'
                WHEN {$tableAlias}.shooter_weight_lbs < 220 THEN 'w_200_219'
                ELSE 'w_220_plus'
            END",
            'baseline' => "'league'",
            default => "'unknown'",
        };
    }
}
