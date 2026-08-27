<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NhlExpectedGoalsModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Scores shot-attempt facts against a specific expected-goals model.
 */
class NhlShotAttemptModelScorer
{
    /**
     * Score every shot-attempt fact in a season for one model.
     */
    public function scoreSeason(
        NhlExpectedGoalsModel $model,
        string $seasonId,
        int $gameType,
        float $highDangerThreshold,
        ?string $entityKey = null
    ): bool {
        if (! Schema::hasTable('nhl_shot_attempt_model_scores')) {
            return false;
        }

        $entityWhere = '';
        $deleteEntityWhere = '';
        $bindings = [
            (int) ($model->model_run_id ?? 0) ?: null,
            (int) $model->id,
            (string) $model->prediction_target,
            $highDangerThreshold,
            (int) $model->id,
            $seasonId,
            $gameType,
        ];
        $deleteBindings = [
            (int) $model->id,
            $seasonId,
            $gameType,
        ];

        if ($entityKey !== null) {
            $entityWhere = "AND ('skater_offense:' || facts.shooter_player_id::text) = ?";
            $deleteEntityWhere = "AND ('skater_offense:' || facts.shooter_player_id::text) = ?";
            $bindings[] = $entityKey;
            $deleteBindings[] = $entityKey;
        }

        DB::statement(<<<SQL
DELETE FROM nhl_shot_attempt_model_scores scores
USING nhl_shot_attempts_facts facts
WHERE facts.id = scores.shot_attempt_fact_id
    AND scores.expected_goals_model_id = ?
    AND scores.season_id = ?
    AND scores.game_type = ?
    {$deleteEntityWhere}
SQL, $deleteBindings);

        $candidateKeys = implode(",\n                    ", $this->candidateBucketKeySql($model, 'facts'));

        DB::statement(<<<SQL
INSERT INTO nhl_shot_attempt_model_scores (
    model_run_id,
    expected_goals_model_id,
    prediction_target,
    shot_attempt_fact_id,
    play_by_play_id,
    nhl_game_id,
    season_id,
    game_type,
    game_date,
    team_id,
    opponent_team_id,
    shooter_player_id,
    goalie_player_id,
    is_scored,
    exclusion_reason,
    probability,
    is_high_danger,
    high_danger_threshold,
    matched_bucket_key,
    fallback_level,
    matched_bucket_payload,
    scored_at,
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
    games.game_type,
    facts.game_date,
    facts.team_id,
    facts.opponent_team_id,
    facts.shooter_player_id,
    facts.goalie_player_id,
    CASE WHEN exclusions.exclusion_reason IS NULL AND matched.bucket_key IS NOT NULL THEN true ELSE false END as is_scored,
    exclusions.exclusion_reason,
    matched.smoothed_goal_probability as probability,
    CASE
        WHEN exclusions.exclusion_reason IS NULL
            AND matched.smoothed_goal_probability >= ?
            THEN true
        ELSE false
    END as is_high_danger,
    ?::numeric as high_danger_threshold,
    matched.bucket_key as matched_bucket_key,
    matched.fallback_level,
    CASE
        WHEN matched.bucket_key IS NULL THEN NULL
        ELSE json_build_object(
            'bucket_key', matched.bucket_key,
            'fallback_level', matched.fallback_level,
            'probability', matched.smoothed_goal_probability,
            'attempts', matched.attempts,
            'goals', matched.goals
        )
    END as matched_bucket_payload,
    now() as scored_at,
    now() as created_at,
    now() as updated_at
FROM nhl_shot_attempts_facts facts
INNER JOIN nhl_games games ON games.nhl_game_id = facts.nhl_game_id
CROSS JOIN LATERAL (
    SELECT CASE
        WHEN facts.shooter_player_id IS NULL THEN 'missing_shooter'
        WHEN COALESCE(facts.period_type, '') = 'SO' THEN 'shootout'
        WHEN COALESCE(facts.is_empty_net, false) = true THEN 'empty_net'
        WHEN COALESCE(NULLIF(facts.shot_type_bucket, ''), 'unknown') = 'unknown' THEN 'unknown_shot_type'
        ELSE NULL
    END as exclusion_reason
) exclusions
LEFT JOIN LATERAL (
    SELECT
        buckets.bucket_key,
        buckets.fallback_level,
        buckets.smoothed_goal_probability,
        buckets.attempts,
        buckets.goals
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
    AND games.game_type = ?
    {$entityWhere}
SQL, array_merge(
            array_slice($bindings, 0, 4),
            [$highDangerThreshold],
            array_slice($bindings, 4),
        ));

        return true;
    }

    /**
     * Score a season, then refresh player-game HDSAT aggregates from model scores.
     */
    public function refreshGameSummaryHighDangerSat(
        NhlExpectedGoalsModel $model,
        string $seasonId,
        int $gameType,
        float $highDangerThreshold,
        ?string $entityKey = null
    ): bool {
        if (
            ! Schema::hasTable('nhl_shot_attempt_model_scores')
            || ! Schema::hasColumn('nhl_game_summaries', 'hdsat')
        ) {
            return false;
        }

        if (! $this->hasScoresForScope($model, $seasonId, $gameType, $entityKey)) {
            $this->scoreSeason($model, $seasonId, $gameType, $highDangerThreshold, $entityKey);
        }

        $hasSplitHighDangerColumns = $this->hasGameSummarySplitHighDangerSatColumns();
        $splitHighDangerSelects = $hasSplitHighDangerColumns ? <<<SQL
,
        COUNT(*) FILTER (
            WHERE scores.is_high_danger = true
                AND (facts.strength = 'EV' OR facts.strength_bucket = 'EV')
        ) as evhdsat,
        COUNT(*) FILTER (
            WHERE scores.is_high_danger = true
                AND (facts.strength = 'PP' OR facts.strength_bucket = 'PP')
        ) as pphdsat,
        COUNT(*) FILTER (
            WHERE scores.is_high_danger = true
                AND (facts.strength = 'PK' OR facts.strength_bucket = 'PK')
        ) as pkhdsat
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
            (int) $model->id,
            $seasonId,
            $gameType,
        ];

        if ($entityKey !== null) {
            $entityWhere = "AND ('skater_offense:' || scores.shooter_player_id::text) = ?";
            $resetEntityWhere = "AND ('skater_offense:' || reset_scores.shooter_player_id::text) = ?";
            $bindings[] = $entityKey;
        }

        DB::statement(<<<SQL
WITH high_danger_totals AS (
    SELECT
        scores.nhl_game_id,
        scores.shooter_player_id as nhl_player_id,
        COUNT(*) FILTER (WHERE scores.is_high_danger = true) as hdsat
        {$splitHighDangerSelects}
    FROM nhl_shot_attempt_model_scores scores
    INNER JOIN nhl_shot_attempts_facts facts ON facts.id = scores.shot_attempt_fact_id
    WHERE scores.expected_goals_model_id = ?
        AND scores.season_id = ?
        AND scores.game_type = ?
        AND scores.is_scored = true
        AND scores.shooter_player_id IS NOT NULL
        {$entityWhere}
    GROUP BY scores.nhl_game_id, scores.shooter_player_id
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
        FROM nhl_shot_attempt_model_scores reset_scores
        WHERE reset_scores.expected_goals_model_id = ?
            AND reset_scores.nhl_game_id = summaries.nhl_game_id
            AND reset_scores.shooter_player_id = summaries.nhl_player_id
            AND reset_scores.season_id = ?
            AND reset_scores.game_type = ?
            {$resetEntityWhere}
    )
SQL, array_merge(
            [$bindings[0], $bindings[1], $bindings[2]],
            array_slice($bindings, 3),
            [$seasonId, $gameType, $bindings[0], $seasonId, $gameType],
            array_slice($bindings, 3),
        ));

        return true;
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

    private function hasScoresForScope(
        NhlExpectedGoalsModel $model,
        string $seasonId,
        int $gameType,
        ?string $entityKey
    ): bool {
        $facts = DB::table('nhl_shot_attempts_facts as facts')
            ->join('nhl_games as games', 'games.nhl_game_id', '=', 'facts.nhl_game_id')
            ->where('facts.season_id', $seasonId)
            ->where('games.game_type', $gameType);

        $scores = DB::table('nhl_shot_attempt_model_scores')
            ->where('expected_goals_model_id', (int) $model->id)
            ->where('season_id', $seasonId)
            ->where('game_type', $gameType);

        $playerId = $this->skaterOffensePlayerId($entityKey);

        if ($playerId !== null) {
            $facts->where('facts.shooter_player_id', $playerId);
            $scores->where('shooter_player_id', $playerId);
        }

        $factCount = (int) $facts->count();

        return $factCount > 0 && (int) $scores->count() >= $factCount;
    }

    private function skaterOffensePlayerId(?string $entityKey): ?int
    {
        if ($entityKey === null || ! str_starts_with($entityKey, 'skater_offense:')) {
            return null;
        }

        $playerId = substr($entityKey, strlen('skater_offense:'));

        return ctype_digit($playerId) ? (int) $playerId : null;
    }

    /**
     * @return array<int, string>
     */
    private function candidateBucketKeySql(NhlExpectedGoalsModel $model, string $tableAlias): array
    {
        $fallbackLevels = collect((array) data_get($model->feature_config, 'fallback_levels', []))
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
                    ->map(fn (string $factorKey): string => "'{$factorKey}=' || " . $this->factorExpression($factorKey, $tableAlias))
                    ->implode(" || '|' || ");

                return '(' . $levelNumber . ", 'L" . str_pad((string) $levelNumber, 2, '0', STR_PAD_LEFT) . "|' || {$parts})";
            })
            ->values()
            ->all();
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
            'baseline' => "'league'",
            default => "'unknown'",
        };
    }
}
