<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NhlExpectedGoalsModel;
use App\Models\NhlModelRun;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Builds entity-level SAT profile rows for a trained SAT model run.
 */
class NhlSatModelEntityProfileBuilder
{
    private const REGULAR_SEASON_GAME_TYPE = 2;
    private const PROFILE_TABLE = 'nhl_sat_model_entity_profile_buckets';
    private const TEST_PROFILE_TABLE = 'nhl_sat_model_entity_test_profile_buckets';

    /**
     * Build all supported entity profile buckets for one SAT model run.
     *
     * @return array<string, int>
     */
    public function build(NhlModelRun $run, NhlExpectedGoalsModel $satModel, ?NhlExpectedGoalsModel $sogModel = null): array
    {
        $seasonIds = $this->seasonIds($run);

        if ($seasonIds === []) {
            throw new RuntimeException('This SAT model has no training seasons.');
        }

        $satBucketKeySql = $this->modelBucketKeySql($satModel, 'profile_facts');
        $sogBucketKeySql = $sogModel === null ? null : $this->modelBucketKeySql($sogModel, 'profile_facts');
        $counts = [];

        DB::table(self::PROFILE_TABLE)
            ->where('model_run_id', $run->id)
            ->delete();

        foreach ($this->profileDefinitions() as $profileType => $definition) {
            $this->insertProfileRows(
                run: $run,
                satModel: $satModel,
                sogModel: $sogModel,
                profileType: $profileType,
                definition: $definition,
                seasonIds: $seasonIds,
                satBucketKeySql: $satBucketKeySql,
                sogBucketKeySql: $sogBucketKeySql
            );

            $counts[$profileType] = DB::table(self::PROFILE_TABLE)
                ->where('model_run_id', $run->id)
                ->where('profile_type', $profileType)
                ->count();
        }

        $counts['total'] = array_sum($counts);

        return $counts;
    }

    /**
     * Clear existing rows and list profile entities that should be queued.
     *
     * @return array<int, array{profile_type:string,entity_key:string}>
     */
    public function prepareBuild(NhlModelRun $run): array
    {
        DB::table(self::PROFILE_TABLE)
            ->where('model_run_id', $run->id)
            ->delete();

        $entities = [];

        foreach ($this->profileDefinitions() as $profileType => $definition) {
            foreach ($this->profileEntities($this->seasonIds($run), $definition) as $entityKey) {
                $entities[] = [
                    'profile_type' => $profileType,
                    'entity_key' => $entityKey,
                ];
            }
        }

        return $entities;
    }

    /**
     * Clear existing single-season snapshot rows and list entities that should be queued.
     *
     * @return array<int, array{profile_type:string,entity_key:string,season_id:string}>
     */
    public function prepareSeasonSnapshotBuilds(NhlModelRun $run): array
    {
        $seasonIds = $this->snapshotSeasonIds($run);

        if ($seasonIds === []) {
            return [];
        }

        DB::table(self::TEST_PROFILE_TABLE)
            ->where('model_run_id', $run->id)
            ->whereIn('test_season_id', $seasonIds)
            ->delete();

        $entities = [];

        foreach ($seasonIds as $seasonId) {
            foreach ($this->profileDefinitions() as $profileType => $definition) {
                foreach ($this->profileEntities([$seasonId], $definition) as $entityKey) {
                    $entities[] = [
                        'profile_type' => $profileType,
                        'entity_key' => $entityKey,
                        'season_id' => $seasonId,
                    ];
                }
            }
        }

        return $entities;
    }

    /**
     * Build one entity's profile rows.
     */
    public function buildEntity(
        NhlModelRun $run,
        NhlExpectedGoalsModel $satModel,
        ?NhlExpectedGoalsModel $sogModel,
        string $profileType,
        string $entityKey
    ): int {
        $seasonIds = $this->seasonIds($run);

        if ($seasonIds === []) {
            throw new RuntimeException('This SAT model has no training seasons.');
        }

        $definition = $this->profileDefinitions()[$profileType] ?? null;

        if ($definition === null) {
            throw new RuntimeException('Unknown SAT model profile type.');
        }

        $this->insertProfileRows(
            run: $run,
            satModel: $satModel,
            sogModel: $sogModel,
            profileType: $profileType,
            definition: $definition,
            seasonIds: $seasonIds,
            satBucketKeySql: $this->modelBucketKeySql($satModel, 'profile_facts'),
            sogBucketKeySql: $sogModel === null ? null : $this->modelBucketKeySql($sogModel, 'profile_facts'),
            entityKey: $entityKey
        );

        return DB::table(self::PROFILE_TABLE)
            ->where('model_run_id', $run->id)
            ->where('profile_type', $profileType)
            ->where('entity_key', $entityKey)
            ->count();
    }

    /**
     * Build one entity's single-season snapshot rows.
     */
    public function buildSeasonSnapshotEntity(
        NhlModelRun $run,
        NhlExpectedGoalsModel $satModel,
        ?NhlExpectedGoalsModel $sogModel,
        string $profileType,
        string $entityKey,
        string $seasonId
    ): int {
        if (preg_match('/^\d{8}$/', $seasonId) !== 1) {
            throw new RuntimeException('Invalid season snapshot id.');
        }

        $definition = $this->profileDefinitions()[$profileType] ?? null;

        if ($definition === null) {
            throw new RuntimeException('Unknown SAT model profile type.');
        }

        $this->insertProfileRows(
            run: $run,
            satModel: $satModel,
            sogModel: $sogModel,
            profileType: $profileType,
            definition: $definition,
            seasonIds: [$seasonId],
            satBucketKeySql: $this->modelBucketKeySql($satModel, 'profile_facts'),
            sogBucketKeySql: $sogModel === null ? null : $this->modelBucketKeySql($sogModel, 'profile_facts'),
            entityKey: $entityKey,
            tableName: self::TEST_PROFILE_TABLE,
            testSeasonId: $seasonId
        );

        return DB::table(self::TEST_PROFILE_TABLE)
            ->where('model_run_id', $run->id)
            ->where('test_season_id', $seasonId)
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
     * @return array<int, string>
     */
    private function snapshotSeasonIds(NhlModelRun $run): array
    {
        $seasonIds = $this->seasonIds($run);
        $testSeasonId = $this->testSeasonId($run);

        if ($testSeasonId !== null) {
            $seasonIds[] = $testSeasonId;
        }

        return collect($seasonIds)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return array<string, array{joins:string,entity_id:string,entity_key:string,entity_name:string,entity_role:string,team_context:string,where:string}>
     */
    public function profileDefinitions(): array
    {
        return [
            'skater_offense' => [
                'joins' => "LEFT JOIN players shooter_player ON shooter_player.nhl_id = facts.shooter_player_id",
                'entity_id' => 'facts.shooter_player_id',
                'entity_key' => "'skater_offense:' || facts.shooter_player_id::text",
                'entity_name' => "COALESCE(shooter_player.full_name, CONCAT(shooter_player.first_name, ' ', shooter_player.last_name), facts.shooter_player_id::text)",
                'entity_role' => "'skater'",
                'team_context' => "'offense'",
                'where' => 'facts.shooter_player_id IS NOT NULL',
            ],
            'skater_defense' => [
                'joins' => <<<SQL
INNER JOIN event_unit_shifts event_links ON event_links.event_id = facts.play_by_play_id
INNER JOIN nhl_unit_shifts unit_shifts ON unit_shifts.id = event_links.unit_shift_id
INNER JOIN nhl_unit_shift_players shift_players ON shift_players.unit_shift_id = unit_shifts.id
INNER JOIN players shift_players_meta ON shift_players_meta.id = shift_players.player_id
SQL,
                'entity_id' => 'shift_players_meta.nhl_id',
                'entity_key' => "'skater_defense:' || shift_players_meta.nhl_id::text",
                'entity_name' => "COALESCE(shift_players_meta.full_name, CONCAT(shift_players_meta.first_name, ' ', shift_players_meta.last_name), shift_players_meta.nhl_id::text)",
                'entity_role' => "'skater'",
                'team_context' => "'defense'",
                'where' => "unit_shifts.team_id = facts.opponent_team_id
                    AND shift_players_meta.nhl_id IS NOT NULL
                    AND COALESCE(shift_players_meta.pos_type, '') <> 'G'",
            ],
            'goalie_faced' => [
                'joins' => "LEFT JOIN players goalie_player ON goalie_player.nhl_id = facts.goalie_player_id",
                'entity_id' => 'facts.goalie_player_id',
                'entity_key' => "'goalie_faced:' || facts.goalie_player_id::text",
                'entity_name' => "COALESCE(goalie_player.full_name, CONCAT(goalie_player.first_name, ' ', goalie_player.last_name), facts.goalie_player_id::text)",
                'entity_role' => "'goalie'",
                'team_context' => "'faced'",
                'where' => 'facts.goalie_player_id IS NOT NULL',
            ],
            'team_offense' => [
                'joins' => 'LEFT JOIN nhl_teams entity_team ON entity_team.nhl_id = facts.team_id',
                'entity_id' => 'facts.team_id',
                'entity_key' => "'team_offense:' || facts.team_id::text",
                'entity_name' => 'COALESCE(entity_team.abbrev, entity_team.full_name, facts.team_id::text)',
                'entity_role' => "'team'",
                'team_context' => "'offense'",
                'where' => 'facts.team_id IS NOT NULL',
            ],
            'team_defense' => [
                'joins' => 'LEFT JOIN nhl_teams entity_team ON entity_team.nhl_id = facts.opponent_team_id',
                'entity_id' => 'facts.opponent_team_id',
                'entity_key' => "'team_defense:' || facts.opponent_team_id::text",
                'entity_name' => 'COALESCE(entity_team.abbrev, entity_team.full_name, facts.opponent_team_id::text)',
                'entity_role' => "'team'",
                'team_context' => "'defense'",
                'where' => 'facts.opponent_team_id IS NOT NULL',
            ],
            'official' => [
                'joins' => <<<SQL
INNER JOIN nhl_game_officials assignments ON assignments.nhl_game_id = facts.nhl_game_id
LEFT JOIN nhl_officials officials ON officials.id = assignments.nhl_official_id
SQL,
                'entity_id' => 'assignments.nhl_official_id',
                'entity_key' => "'official:' || assignments.role || ':' || assignments.nhl_official_id::text",
                'entity_name' => 'COALESCE(officials.display_name, assignments.nhl_official_id::text)',
                'entity_role' => 'assignments.role',
                'team_context' => "'game'",
                'where' => 'assignments.nhl_official_id IS NOT NULL',
            ],
            'staff_offense' => [
                'joins' => <<<SQL
INNER JOIN nhl_game_team_staff assignments
    ON assignments.nhl_game_id = facts.nhl_game_id
    AND assignments.role = 'head_coach'
    AND (
        assignments.nhl_team_id = facts.team_id
        OR assignments.team_side = CASE
            WHEN facts.team_id = games.home_team_id THEN 'home'
            WHEN facts.team_id = games.away_team_id THEN 'away'
            ELSE NULL
        END
    )
LEFT JOIN nhl_staff staff ON staff.id = assignments.nhl_staff_id
SQL,
                'entity_id' => 'assignments.nhl_staff_id',
                'entity_key' => "'staff:offense:' || assignments.role || ':' || assignments.nhl_staff_id::text",
                'entity_name' => 'COALESCE(staff.display_name, assignments.nhl_staff_id::text)',
                'entity_role' => 'assignments.role',
                'team_context' => "'offense'",
                'where' => 'assignments.nhl_staff_id IS NOT NULL',
            ],
            'staff_defense' => [
                'joins' => <<<SQL
INNER JOIN nhl_game_team_staff assignments
    ON assignments.nhl_game_id = facts.nhl_game_id
    AND assignments.role = 'head_coach'
    AND (
        assignments.nhl_team_id = facts.opponent_team_id
        OR assignments.team_side = CASE
            WHEN facts.opponent_team_id = games.home_team_id THEN 'home'
            WHEN facts.opponent_team_id = games.away_team_id THEN 'away'
            ELSE NULL
        END
    )
LEFT JOIN nhl_staff staff ON staff.id = assignments.nhl_staff_id
SQL,
                'entity_id' => 'assignments.nhl_staff_id',
                'entity_key' => "'staff:defense:' || assignments.role || ':' || assignments.nhl_staff_id::text",
                'entity_name' => 'COALESCE(staff.display_name, assignments.nhl_staff_id::text)',
                'entity_role' => 'assignments.role',
                'team_context' => "'defense'",
                'where' => 'assignments.nhl_staff_id IS NOT NULL',
            ],
        ];
    }

    /**
     * @param array{joins:string,entity_id:string,entity_key:string,entity_name:string,entity_role:string,team_context:string,where:string} $definition
     * @param array<int, string> $seasonIds
     */
    private function insertProfileRows(
        NhlModelRun $run,
        NhlExpectedGoalsModel $satModel,
        ?NhlExpectedGoalsModel $sogModel,
        string $profileType,
        array $definition,
        array $seasonIds,
        string $satBucketKeySql,
        ?string $sogBucketKeySql,
        ?string $entityKey = null,
        string $tableName = self::PROFILE_TABLE,
        ?string $testSeasonId = null
    ): void {
        $now = now();
        $seasonJson = json_encode($seasonIds, JSON_THROW_ON_ERROR);
        $seasonPlaceholders = implode(', ', array_fill(0, count($seasonIds), '?'));
        $goalModelId = $sogModel?->id;
        $goalBucketJoin = $sogModel === null || $sogBucketKeySql === null
            ? ''
            : "LEFT JOIN nhl_expected_goals_model_buckets goal_buckets
                ON goal_buckets.expected_goals_model_id = {$goalModelId}
                AND goal_buckets.bucket_key = {$sogBucketKeySql}
            LEFT JOIN nhl_expected_goals_model_buckets goal_baseline
                ON goal_baseline.expected_goals_model_id = {$goalModelId}
                AND goal_baseline.bucket_key = 'L99|baseline=league'";
        $goalProbability = $sogModel === null
            ? '0'
            : 'COALESCE(goal_buckets.smoothed_goal_probability, goal_baseline.smoothed_goal_probability, 0)';
        $usesPlayerExposure = in_array($profileType, ['skater_offense', 'skater_defense', 'goalie_faced'], true);
        $sourceExposureSeconds = $usesPlayerExposure
            ? 'COALESCE(game_summaries.toi, boxscores.toi_seconds, 0)'
            : '3600';
        $entityWhereSql = $entityKey === null ? '' : "AND {$definition['entity_key']} = ?";
        $testColumnSql = $testSeasonId === null ? '' : "    test_season_id,\n";
        $testSelectSql = $testSeasonId === null ? '' : "    ? as test_season_id,\n";
        $conflictColumns = $testSeasonId === null
            ? 'model_run_id, profile_type, entity_key, matched_bucket_key'
            : 'model_run_id, test_season_id, profile_type, entity_key, matched_bucket_key';
        $profileSample = $testSeasonId === null ? 'training' : 'test';

        $sql = <<<SQL
INSERT INTO {$tableName} (
    model_run_id,
    sat_expected_goals_model_id,
    sog_expected_goals_model_id,
    source_season_ids,
{$testColumnSql}
    game_type,
    profile_type,
    entity_key,
    entity_id,
    entity_name,
    entity_role,
    team_context,
    matched_bucket_key,
    fallback_level,
    bucket_dimensions,
    source_sat,
    source_sog,
    source_goals,
    source_profile_share,
    source_toi_seconds,
    source_xsat_per_60,
    source_xsog_per_60,
    source_xg_per_60,
    expected_sog,
    expected_goals,
    sog_above_expected,
    goals_above_expected,
    sat_probability,
    goal_probability,
    confidence_score,
    shrinkage_weight,
    confidence_bucket,
    metadata,
    profiled_at,
    created_at,
    updated_at
)
WITH profile_facts AS (
    SELECT DISTINCT
        facts.id,
        facts.nhl_game_id,
        {$definition['entity_id']} as entity_id,
        {$definition['entity_key']} as entity_key,
        {$definition['entity_name']} as entity_name,
        {$definition['entity_role']} as entity_role,
        {$definition['team_context']} as team_context,
        facts.is_shot_on_goal,
        facts.is_goal,
        facts.shot_type_bucket,
        facts.shot_distance,
        facts.abs_shot_angle,
        facts.is_rush,
        facts.is_rebound,
        facts.strength_bucket,
        facts.strength,
        facts.period,
        facts.score_state_bucket,
        facts.shooter_age_years,
        facts.shooter_height_inches,
        facts.shooter_weight_lbs
    FROM nhl_shot_attempts_facts facts
    INNER JOIN nhl_games games ON games.nhl_game_id = facts.nhl_game_id
    {$definition['joins']}
    WHERE facts.season_id IN ({$seasonPlaceholders})
        AND games.game_type = ?
        AND COALESCE(facts.period_type, '') <> 'SO'
        AND COALESCE(facts.is_empty_net, false) = false
        AND COALESCE(NULLIF(facts.shot_type_bucket, ''), 'unknown') <> 'unknown'
        AND {$definition['where']}
        {$entityWhereSql}
),
scored_facts AS (
    SELECT
        profile_facts.*,
        COALESCE(sat_buckets.bucket_key, sat_baseline.bucket_key) as matched_bucket_key,
        COALESCE(sat_buckets.fallback_level, sat_baseline.fallback_level) as fallback_level,
        COALESCE(sat_buckets.bucket_dimensions, sat_baseline.bucket_dimensions) as bucket_dimensions,
        COALESCE(sat_buckets.smoothed_goal_probability, sat_baseline.smoothed_goal_probability, 0) as sat_probability,
        {$goalProbability} as goal_probability,
        COALESCE(sat_buckets.confidence_score, sat_baseline.confidence_score, 0) as confidence_score,
        COALESCE(sat_buckets.shrinkage_weight, sat_baseline.shrinkage_weight, 0) as shrinkage_weight,
        COALESCE(sat_buckets.confidence_bucket, sat_baseline.confidence_bucket) as confidence_bucket
    FROM profile_facts
    LEFT JOIN nhl_expected_goals_model_buckets sat_buckets
        ON sat_buckets.expected_goals_model_id = ?
        AND sat_buckets.bucket_key = {$satBucketKeySql}
    LEFT JOIN nhl_expected_goals_model_buckets sat_baseline
        ON sat_baseline.expected_goals_model_id = ?
        AND sat_baseline.bucket_key = 'L99|baseline=league'
    {$goalBucketJoin}
),
entity_totals AS (
    SELECT entity_key, COUNT(*) as total_sat
    FROM scored_facts
    GROUP BY entity_key
),
entity_games AS (
    SELECT DISTINCT entity_key, entity_id, nhl_game_id
    FROM profile_facts
),
entity_exposure AS (
    SELECT
        entity_games.entity_key,
        SUM({$sourceExposureSeconds}) as source_toi_seconds
    FROM entity_games
    LEFT JOIN nhl_game_summaries game_summaries
        ON game_summaries.nhl_game_id = entity_games.nhl_game_id
        AND game_summaries.nhl_player_id = entity_games.entity_id
    LEFT JOIN nhl_boxscores boxscores
        ON boxscores.nhl_game_id = entity_games.nhl_game_id
        AND boxscores.nhl_player_id = entity_games.entity_id
    GROUP BY entity_games.entity_key
)
SELECT
    ? as model_run_id,
    ? as sat_expected_goals_model_id,
    ? as sog_expected_goals_model_id,
    ?::json as source_season_ids,
{$testSelectSql}
    ? as game_type,
    ? as profile_type,
    scored_facts.entity_key,
    MAX(scored_facts.entity_id) as entity_id,
    MAX(scored_facts.entity_name) as entity_name,
    MAX(scored_facts.entity_role) as entity_role,
    MAX(scored_facts.team_context) as team_context,
    scored_facts.matched_bucket_key,
    MAX(scored_facts.fallback_level) as fallback_level,
    MAX(scored_facts.bucket_dimensions::text)::json as bucket_dimensions,
    COUNT(*) as source_sat,
    SUM(CASE WHEN scored_facts.is_shot_on_goal THEN 1 ELSE 0 END) as source_sog,
    SUM(CASE WHEN scored_facts.is_goal THEN 1 ELSE 0 END) as source_goals,
    ROUND((COUNT(*)::numeric / NULLIF(MAX(entity_totals.total_sat), 0)), 6) as source_profile_share,
    MAX(entity_exposure.source_toi_seconds) as source_toi_seconds,
    ROUND((COUNT(*)::numeric * 3600 / NULLIF(MAX(entity_exposure.source_toi_seconds), 0)), 4) as source_xsat_per_60,
    ROUND((SUM(scored_facts.sat_probability)::numeric * 3600 / NULLIF(MAX(entity_exposure.source_toi_seconds), 0)), 4) as source_xsog_per_60,
    ROUND((SUM(CASE WHEN scored_facts.is_shot_on_goal THEN scored_facts.goal_probability ELSE 0 END)::numeric * 3600 / NULLIF(MAX(entity_exposure.source_toi_seconds), 0)), 4) as source_xg_per_60,
    ROUND(SUM(scored_facts.sat_probability)::numeric, 4) as expected_sog,
    ROUND(SUM(CASE WHEN scored_facts.is_shot_on_goal THEN scored_facts.goal_probability ELSE 0 END)::numeric, 4) as expected_goals,
    ROUND((SUM(CASE WHEN scored_facts.is_shot_on_goal THEN 1 ELSE 0 END) - SUM(scored_facts.sat_probability))::numeric, 4) as sog_above_expected,
    ROUND((SUM(CASE WHEN scored_facts.is_goal THEN 1 ELSE 0 END) - SUM(CASE WHEN scored_facts.is_shot_on_goal THEN scored_facts.goal_probability ELSE 0 END))::numeric, 4) as goals_above_expected,
    ROUND(AVG(scored_facts.sat_probability)::numeric, 6) as sat_probability,
    ROUND(AVG(scored_facts.goal_probability)::numeric, 6) as goal_probability,
    ROUND(AVG(scored_facts.confidence_score)::numeric, 4) as confidence_score,
    ROUND(AVG(scored_facts.shrinkage_weight)::numeric, 4) as shrinkage_weight,
    MAX(scored_facts.confidence_bucket) as confidence_bucket,
    json_build_object('bucket_source', 'model_run_eval', 'profile_type', ?::text, 'profile_sample', ?::text) as metadata,
    ?::timestamp as profiled_at,
    ?::timestamp as created_at,
    ?::timestamp as updated_at
FROM scored_facts
INNER JOIN entity_totals ON entity_totals.entity_key = scored_facts.entity_key
INNER JOIN entity_exposure ON entity_exposure.entity_key = scored_facts.entity_key
WHERE scored_facts.matched_bucket_key IS NOT NULL
GROUP BY scored_facts.entity_key, scored_facts.matched_bucket_key
HAVING COUNT(*) >= 1
ON CONFLICT ({$conflictColumns})
DO UPDATE SET
    sat_expected_goals_model_id = EXCLUDED.sat_expected_goals_model_id,
    sog_expected_goals_model_id = EXCLUDED.sog_expected_goals_model_id,
    source_season_ids = EXCLUDED.source_season_ids,
    game_type = EXCLUDED.game_type,
    entity_id = EXCLUDED.entity_id,
    entity_name = EXCLUDED.entity_name,
    entity_role = EXCLUDED.entity_role,
    team_context = EXCLUDED.team_context,
    fallback_level = EXCLUDED.fallback_level,
    bucket_dimensions = EXCLUDED.bucket_dimensions,
    source_sat = EXCLUDED.source_sat,
    source_sog = EXCLUDED.source_sog,
    source_goals = EXCLUDED.source_goals,
    source_profile_share = EXCLUDED.source_profile_share,
    source_toi_seconds = EXCLUDED.source_toi_seconds,
    source_xsat_per_60 = EXCLUDED.source_xsat_per_60,
    source_xsog_per_60 = EXCLUDED.source_xsog_per_60,
    source_xg_per_60 = EXCLUDED.source_xg_per_60,
    expected_sog = EXCLUDED.expected_sog,
    expected_goals = EXCLUDED.expected_goals,
    sog_above_expected = EXCLUDED.sog_above_expected,
    goals_above_expected = EXCLUDED.goals_above_expected,
    sat_probability = EXCLUDED.sat_probability,
    goal_probability = EXCLUDED.goal_probability,
    confidence_score = EXCLUDED.confidence_score,
    shrinkage_weight = EXCLUDED.shrinkage_weight,
    confidence_bucket = EXCLUDED.confidence_bucket,
    metadata = EXCLUDED.metadata,
    profiled_at = EXCLUDED.profiled_at,
    updated_at = EXCLUDED.updated_at
SQL;

        DB::statement($sql, [
            ...$seasonIds,
            self::REGULAR_SEASON_GAME_TYPE,
            ...($entityKey === null ? [] : [$entityKey]),
            $satModel->id,
            $satModel->id,
            $run->id,
            $satModel->id,
            $sogModel?->id,
            $seasonJson,
            ...($testSeasonId === null ? [] : [$testSeasonId]),
            self::REGULAR_SEASON_GAME_TYPE,
            $profileType,
            $profileType,
            $profileSample,
            $now,
            $now,
            $now,
        ]);
    }

    /**
     * @param array{joins:string,entity_id:string,entity_key:string,entity_name:string,entity_role:string,team_context:string,where:string} $definition
     * @return array<int, string>
     */
    private function profileEntities(array $seasonIds, array $definition): array
    {
        if ($seasonIds === []) {
            return [];
        }

        $seasonPlaceholders = implode(', ', array_fill(0, count($seasonIds), '?'));
        $sql = <<<SQL
SELECT DISTINCT {$definition['entity_key']} as entity_key
FROM nhl_shot_attempts_facts facts
INNER JOIN nhl_games games ON games.nhl_game_id = facts.nhl_game_id
{$definition['joins']}
WHERE facts.season_id IN ({$seasonPlaceholders})
    AND games.game_type = ?
    AND COALESCE(facts.period_type, '') <> 'SO'
    AND COALESCE(facts.is_empty_net, false) = false
    AND COALESCE(NULLIF(facts.shot_type_bucket, ''), 'unknown') <> 'unknown'
    AND {$definition['where']}
ORDER BY entity_key
SQL;

        return collect(DB::select($sql, [
            ...$seasonIds,
            self::REGULAR_SEASON_GAME_TYPE,
        ]))
            ->pluck('entity_key')
            ->map(fn (mixed $entityKey): string => (string) $entityKey)
            ->filter(fn (string $entityKey): bool => $entityKey !== '')
            ->values()
            ->all();
    }

    private function modelBucketKeySql(NhlExpectedGoalsModel $model, string $tableAlias): string
    {
        $fallbackLevels = collect((array) data_get($model->feature_config, 'fallback_levels', []));
        $factorKeys = $fallbackLevels
            ->first(function (mixed $level): bool {
                $keys = collect((array) $level)
                    ->map(fn (mixed $key): string => (string) $key)
                    ->reject(fn (string $key): bool => $key === '' || $key === 'baseline');

                return $keys->isNotEmpty();
            });
        $factorKeys = collect((array) $factorKeys)
            ->map(fn (mixed $key): string => (string) $key)
            ->reject(fn (string $key): bool => $key === '' || $key === 'baseline')
            ->values()
            ->all();

        if ($factorKeys === []) {
            $factorKeys = ['distance_group', 'angle_group'];
        }

        $parts = [];

        foreach ($factorKeys as $factorKey) {
            $parts[] = "'{$factorKey}=' || " . $this->factorExpression($factorKey, $tableAlias);
        }

        return "'L01|' || " . implode(" || '|' || ", $parts);
    }

    private function factorExpression(string $factorKey, string $tableAlias): string
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
            default => "'unknown'",
        };
    }
}
