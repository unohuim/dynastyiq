<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NhlExpectedGoalsModel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Builds historical skater on-ice defensive chance profiles.
 */
class NhlSkaterDefensiveChanceProfileBuilder
{
    private const REGULAR_SEASON_GAME_TYPE = 2;
    private const MIN_PLAYER_SAT_AGAINST = 300;

    public function __construct(private readonly NhlShotAttemptAnalysisBuckets $buckets)
    {
    }

    /**
     * Prepare one skater defensive chance-profile build and return eligible skater ids.
     *
     * @return array{source_season_id:string,game_type:int,goal_model_id:int,sog_model_id:int,player_ids:array<int,int>}
     */
    public function prepareBuild(string $sourceSeasonId, int $gameType = self::REGULAR_SEASON_GAME_TYPE): array
    {
        $goalModel = $this->latestModel($sourceSeasonId, NhlExpectedGoalsBackfiller::TARGET_GOAL);
        $sogModel = $this->latestModel($sourceSeasonId, NhlExpectedGoalsBackfiller::TARGET_SHOT_ON_GOAL);

        if ($goalModel === null || $sogModel === null) {
            throw new RuntimeException('Build xG and xSOG models before building skater defensive chance profiles.');
        }

        return [
            'source_season_id' => $sourceSeasonId,
            'game_type' => $gameType,
            'goal_model_id' => (int) $goalModel->id,
            'sog_model_id' => (int) $sogModel->id,
            'player_ids' => $this->eligiblePlayerIds(
                $sourceSeasonId,
                $gameType,
                (int) $goalModel->id,
                (int) $sogModel->id
            )->all(),
        ];
    }

    /**
     * Build one skater's on-ice defensive chance profile buckets.
     *
     * @return array{player_id:int,bucket_rows:int}
     */
    public function buildPlayer(
        string $sourceSeasonId,
        int $gameType,
        int $goalModelId,
        int $sogModelId,
        int $playerId
    ): array {
        return DB::transaction(function () use (
            $sourceSeasonId,
            $gameType,
            $goalModelId,
            $sogModelId,
            $playerId
        ): array {
            $summary = $this->playerSummary($sourceSeasonId, $gameType, $goalModelId, $sogModelId, $playerId);

            if ($summary === null || (int) $summary->source_sat_against_on_ice < self::MIN_PLAYER_SAT_AGAINST) {
                $this->deleteExistingProfiles($sourceSeasonId, $gameType, $goalModelId, $sogModelId, $playerId);

                return ['player_id' => $playerId, 'bucket_rows' => 0];
            }

            $rows = $this->playerBucketRows($sourceSeasonId, $gameType, $goalModelId, $sogModelId, $playerId);
            $payloads = $this->payloads($summary, $rows, $sourceSeasonId, $gameType, $goalModelId, $sogModelId);

            if ($payloads === []) {
                return ['player_id' => $playerId, 'bucket_rows' => 0];
            }

            foreach (array_chunk($payloads, 100) as $chunk) {
                DB::table('nhl_skater_defensive_chance_profile_buckets')->upsert(
                    $chunk,
                    [
                        'source_season_id',
                        'game_type',
                        'goal_expected_goals_model_id',
                        'shot_on_goal_expected_goals_model_id',
                        'player_id',
                        'matched_bucket_key',
                    ],
                    $this->updateColumns()
                );
            }

            $this->deleteStaleProfiles($sourceSeasonId, $gameType, $goalModelId, $sogModelId, $playerId, $payloads);

            return ['player_id' => $playerId, 'bucket_rows' => count($payloads)];
        });
    }

    private function latestModel(string $seasonId, string $predictionTarget): ?object
    {
        return NhlExpectedGoalsModel::query()
            ->where('training_season_id', $seasonId)
            ->where('prediction_target', $predictionTarget)
            ->where('status', 'draft')
            ->orderByDesc('trained_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return Collection<int, int>
     */
    private function eligiblePlayerIds(string $sourceSeasonId, int $gameType, int $goalModelId, int $sogModelId): Collection
    {
        return DB::table('nhl_shot_attempts_facts as facts')
            ->join('nhl_games as games', 'games.nhl_game_id', '=', 'facts.nhl_game_id')
            ->join('nhl_shot_attempt_predictions as goal_predictions', function ($join) use ($goalModelId): void {
                $join->on('goal_predictions.shot_attempt_fact_id', '=', 'facts.id')
                    ->where('goal_predictions.expected_goals_model_id', '=', $goalModelId)
                    ->where('goal_predictions.prediction_target', '=', NhlExpectedGoalsBackfiller::TARGET_GOAL)
                    ->where('goal_predictions.is_scored', '=', true);
            })
            ->join('nhl_shot_attempt_predictions as sog_predictions', function ($join) use ($sogModelId): void {
                $join->on('sog_predictions.shot_attempt_fact_id', '=', 'facts.id')
                    ->where('sog_predictions.expected_goals_model_id', '=', $sogModelId)
                    ->where('sog_predictions.prediction_target', '=', NhlExpectedGoalsBackfiller::TARGET_SHOT_ON_GOAL)
                    ->where('sog_predictions.is_scored', '=', true);
            })
            ->join('event_unit_shifts as event_links', 'event_links.event_id', '=', 'facts.play_by_play_id')
            ->join('nhl_unit_shifts as unit_shifts', 'unit_shifts.id', '=', 'event_links.unit_shift_id')
            ->join('nhl_unit_shift_players as shift_players', 'shift_players.unit_shift_id', '=', 'unit_shifts.id')
            ->join('players', 'players.id', '=', 'shift_players.player_id')
            ->whereColumn('unit_shifts.team_id', 'facts.opponent_team_id')
            ->where('facts.season_id', $sourceSeasonId)
            ->where('games.game_type', $gameType)
            ->whereNotNull('players.nhl_id')
            ->where(function ($query): void {
                $query->whereNull('players.pos_type')
                    ->orWhere('players.pos_type', '<>', 'G');
            })
            ->groupBy('players.nhl_id')
            ->havingRaw('COUNT(DISTINCT facts.id) >= ?', [self::MIN_PLAYER_SAT_AGAINST])
            ->orderBy('players.nhl_id')
            ->pluck('players.nhl_id')
            ->map(fn (mixed $playerId): int => (int) $playerId);
    }

    private function playerSummary(
        string $sourceSeasonId,
        int $gameType,
        int $goalModelId,
        int $sogModelId,
        int $playerId
    ): ?object {
        $sql = <<<SQL
WITH scored_attempts AS (
    SELECT DISTINCT
        facts.id,
        facts.nhl_game_id,
        players.id as canonical_player_id,
        players.nhl_id as player_id,
        unit_shifts.team_id,
        COALESCE(teams.abbrev, unit_shifts.team_id::text) as team_abbrev,
        COALESCE(players.position, players.pos_type) as position,
        facts.is_shot_on_goal,
        facts.is_goal,
        goal_predictions.xg as goal_xg,
        sog_predictions.xg as sog_xg
    FROM nhl_shot_attempts_facts facts
    INNER JOIN nhl_games games ON games.nhl_game_id = facts.nhl_game_id
    INNER JOIN nhl_shot_attempt_predictions goal_predictions
        ON goal_predictions.shot_attempt_fact_id = facts.id
        AND goal_predictions.expected_goals_model_id = ?
        AND goal_predictions.prediction_target = ?
        AND goal_predictions.is_scored = true
    INNER JOIN nhl_shot_attempt_predictions sog_predictions
        ON sog_predictions.shot_attempt_fact_id = facts.id
        AND sog_predictions.expected_goals_model_id = ?
        AND sog_predictions.prediction_target = ?
        AND sog_predictions.is_scored = true
    INNER JOIN event_unit_shifts event_links ON event_links.event_id = facts.play_by_play_id
    INNER JOIN nhl_unit_shifts unit_shifts ON unit_shifts.id = event_links.unit_shift_id
    INNER JOIN nhl_unit_shift_players shift_players ON shift_players.unit_shift_id = unit_shifts.id
    INNER JOIN players ON players.id = shift_players.player_id
    LEFT JOIN nhl_teams teams ON teams.nhl_id = unit_shifts.team_id
    WHERE unit_shifts.team_id = facts.opponent_team_id
        AND facts.season_id = ?
        AND games.game_type = ?
        AND players.nhl_id = ?
),
toi_summary AS (
    SELECT
        strength_summaries.player_id,
        SUM(strength_summaries.toi) as source_toi_seconds
    FROM nhl_player_game_strength_summaries strength_summaries
    INNER JOIN (
        SELECT DISTINCT canonical_player_id, nhl_game_id
        FROM scored_attempts
    ) defensive_games ON defensive_games.canonical_player_id = strength_summaries.player_id
        AND defensive_games.nhl_game_id = strength_summaries.nhl_game_id
    GROUP BY strength_summaries.player_id
)
SELECT
    scored_attempts.player_id,
    MAX(scored_attempts.team_id) as team_id,
    MAX(scored_attempts.team_abbrev) as team_abbrev,
    MAX(scored_attempts.position) as position,
    COUNT(DISTINCT scored_attempts.nhl_game_id)::decimal as source_games,
    MAX(toi_summary.source_toi_seconds) as source_toi_seconds,
    COUNT(*) as source_sat_against_on_ice,
    SUM(CASE WHEN scored_attempts.is_shot_on_goal THEN 1 ELSE 0 END) as source_sog_against_on_ice,
    SUM(CASE WHEN scored_attempts.is_goal THEN 1 ELSE 0 END) as source_goals_against_on_ice,
    ROUND(SUM(scored_attempts.goal_xg)::numeric, 4) as source_xga_on_ice,
    ROUND(SUM(scored_attempts.sog_xg)::numeric, 4) as source_xsoga_on_ice
FROM scored_attempts
LEFT JOIN toi_summary ON toi_summary.player_id = scored_attempts.canonical_player_id
GROUP BY scored_attempts.player_id
SQL;

        return collect(DB::select($sql, [
            $goalModelId,
            NhlExpectedGoalsBackfiller::TARGET_GOAL,
            $sogModelId,
            NhlExpectedGoalsBackfiller::TARGET_SHOT_ON_GOAL,
            $sourceSeasonId,
            $gameType,
            $playerId,
        ]))->first();
    }

    /**
     * @return Collection<int, object>
     */
    private function playerBucketRows(
        string $sourceSeasonId,
        int $gameType,
        int $goalModelId,
        int $sogModelId,
        int $playerId
    ): Collection {
        $candidates = implode(",\n            ", $this->buckets->candidateBucketKeySql('scored_attempts'));
        $sql = <<<SQL
WITH scored_attempts AS (
    SELECT DISTINCT
        facts.id,
        players.nhl_id as player_id,
        unit_shifts.team_id,
        facts.is_shot_on_goal,
        facts.is_goal,
        goal_predictions.xg as goal_xg,
        sog_predictions.xg as sog_xg,
        facts.shot_type_bucket,
        facts.shot_distance,
        facts.abs_shot_angle,
        facts.is_rush,
        facts.is_rebound
    FROM nhl_shot_attempts_facts facts
    INNER JOIN nhl_games games ON games.nhl_game_id = facts.nhl_game_id
    INNER JOIN nhl_shot_attempt_predictions goal_predictions
        ON goal_predictions.shot_attempt_fact_id = facts.id
        AND goal_predictions.expected_goals_model_id = ?
        AND goal_predictions.prediction_target = ?
        AND goal_predictions.is_scored = true
    INNER JOIN nhl_shot_attempt_predictions sog_predictions
        ON sog_predictions.shot_attempt_fact_id = facts.id
        AND sog_predictions.expected_goals_model_id = ?
        AND sog_predictions.prediction_target = ?
        AND sog_predictions.is_scored = true
    INNER JOIN event_unit_shifts event_links ON event_links.event_id = facts.play_by_play_id
    INNER JOIN nhl_unit_shifts unit_shifts ON unit_shifts.id = event_links.unit_shift_id
    INNER JOIN nhl_unit_shift_players shift_players ON shift_players.unit_shift_id = unit_shifts.id
    INNER JOIN players ON players.id = shift_players.player_id
    WHERE unit_shifts.team_id = facts.opponent_team_id
        AND facts.season_id = ?
        AND games.game_type = ?
        AND players.nhl_id = ?
),
candidate_attempts AS (
    SELECT
        scored_attempts.*,
        candidate.fallback_level,
        candidate.bucket_key
    FROM scored_attempts
    CROSS JOIN LATERAL (
        VALUES
            {$candidates}
    ) AS candidate(fallback_level, bucket_key)
)
SELECT
    player_id,
    MAX(team_id) as team_id,
    bucket_key as matched_bucket_key,
    fallback_level,
    COUNT(*) as source_sat_against_on_ice,
    SUM(CASE WHEN is_shot_on_goal THEN 1 ELSE 0 END) as source_sog_against_on_ice,
    SUM(CASE WHEN is_goal THEN 1 ELSE 0 END) as source_goals_against_on_ice,
    ROUND(SUM(goal_xg)::numeric, 4) as source_xga_on_ice,
    ROUND(SUM(sog_xg)::numeric, 4) as source_xsoga_on_ice,
    ROUND(AVG(goal_xg)::numeric, 6) as goal_probability_against,
    ROUND(AVG(sog_xg)::numeric, 6) as shot_on_goal_probability_against
FROM candidate_attempts
GROUP BY player_id, bucket_key, fallback_level
ORDER BY fallback_level, source_sat_against_on_ice DESC, source_xga_on_ice DESC
SQL;

        return collect(DB::select($sql, [
            $goalModelId,
            NhlExpectedGoalsBackfiller::TARGET_GOAL,
            $sogModelId,
            NhlExpectedGoalsBackfiller::TARGET_SHOT_ON_GOAL,
            $sourceSeasonId,
            $gameType,
            $playerId,
        ]));
    }

    /**
     * @param Collection<int, object> $rows
     * @return array<int, array<string,mixed>>
     */
    private function payloads(
        object $summary,
        Collection $rows,
        string $sourceSeasonId,
        int $gameType,
        int $goalModelId,
        int $sogModelId
    ): array {
        $sourceSatAgainst = max(1, (int) $summary->source_sat_against_on_ice);
        $sourceToiSeconds = (int) ($summary->source_toi_seconds ?? 0);
        $sourceToiHours = $sourceToiSeconds > 0 ? $sourceToiSeconds / 3600 : 0.0;
        $rowMap = $rows->keyBy(fn (object $row): string => (string) $row->matched_bucket_key);
        $now = now();

        return $rows
            ->filter(fn (object $row): bool => (int) $row->fallback_level === 1)
            ->map(function (object $row) use (
                $summary,
                $sourceSatAgainst,
                $sourceToiSeconds,
                $sourceToiHours,
                $rowMap,
                $sourceSeasonId,
                $gameType,
                $goalModelId,
                $sogModelId,
                $now
            ): array {
                $bucketSatAgainst = (int) $row->source_sat_against_on_ice;
                $sourceXga = (float) $row->source_xga_on_ice;
                $sourceXsoga = (float) $row->source_xsoga_on_ice;
                $dimensions = $this->bucketDimensions((string) $row->matched_bucket_key);
                $shrinkage = $this->shrinkageRates($row, $rowMap);

                return [
                    'source_season_id' => $sourceSeasonId,
                    'game_type' => $gameType,
                    'goal_expected_goals_model_id' => $goalModelId,
                    'shot_on_goal_expected_goals_model_id' => $sogModelId,
                    'player_id' => (int) $row->player_id,
                    'team_id' => $summary->team_id === null ? null : (int) $summary->team_id,
                    'team_abbrev' => $summary->team_abbrev,
                    'position' => $summary->position,
                    'matched_bucket_key' => (string) $row->matched_bucket_key,
                    'fallback_level' => (int) $row->fallback_level,
                    'bucket_dimensions' => json_encode($dimensions, JSON_THROW_ON_ERROR),
                    'shot_type_group' => $dimensions['shot_type_group'] ?? null,
                    'distance_group' => $dimensions['distance_group'] ?? null,
                    'angle_group' => $dimensions['angle_group'] ?? null,
                    'sequence_group' => $dimensions['sequence_group'] ?? null,
                    'source_games' => $summary->source_games,
                    'source_toi_seconds' => $sourceToiSeconds > 0 ? $sourceToiSeconds : null,
                    'source_sat_against_on_ice' => $bucketSatAgainst,
                    'source_sog_against_on_ice' => (int) $row->source_sog_against_on_ice,
                    'source_goals_against_on_ice' => (int) $row->source_goals_against_on_ice,
                    'source_xga_on_ice' => $row->source_xga_on_ice,
                    'source_xsoga_on_ice' => $row->source_xsoga_on_ice,
                    'source_xga_per_60' => $sourceToiHours > 0 ? round($sourceXga / $sourceToiHours, 4) : null,
                    'source_xsoga_per_60' => $sourceToiHours > 0 ? round($sourceXsoga / $sourceToiHours, 4) : null,
                    'source_profile_share_against' => round($bucketSatAgainst / $sourceSatAgainst, 6),
                    'goal_probability_against' => $shrinkage['goal_probability_against'],
                    'shot_on_goal_probability_against' => $shrinkage['shot_on_goal_probability_against'],
                    'confidence_score' => $shrinkage['confidence_score'],
                    'confidence_bucket' => $this->confidenceBucket($shrinkage['confidence_score']),
                    'profile_inputs' => json_encode([
                        'method' => 'skater_on_ice_defensive_chance_profile_with_empirical_bayes_shrinkage',
                        'minimum_player_sat_against' => self::MIN_PLAYER_SAT_AGAINST,
                        'source_total_sat_against_on_ice' => $sourceSatAgainst,
                    ], JSON_THROW_ON_ERROR),
                    'flags' => json_encode($this->flags($shrinkage, $sourceToiSeconds), JSON_THROW_ON_ERROR),
                    'metadata' => json_encode([
                        'builder' => 'NhlSkaterDefensiveChanceProfileBuilder',
                        'shrinkage' => $shrinkage,
                    ], JSON_THROW_ON_ERROR),
                    'profiled_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            })->values()->all();
    }

    private function deleteExistingProfiles(
        string $sourceSeasonId,
        int $gameType,
        int $goalModelId,
        int $sogModelId,
        int $playerId
    ): void {
        DB::table('nhl_skater_defensive_chance_profile_buckets')
            ->where('source_season_id', $sourceSeasonId)
            ->where('game_type', $gameType)
            ->where('goal_expected_goals_model_id', $goalModelId)
            ->where('shot_on_goal_expected_goals_model_id', $sogModelId)
            ->where('player_id', $playerId)
            ->delete();
    }

    /**
     * @param array<int, array<string,mixed>> $payloads
     */
    private function deleteStaleProfiles(
        string $sourceSeasonId,
        int $gameType,
        int $goalModelId,
        int $sogModelId,
        int $playerId,
        array $payloads
    ): void {
        DB::table('nhl_skater_defensive_chance_profile_buckets')
            ->where('source_season_id', $sourceSeasonId)
            ->where('game_type', $gameType)
            ->where('goal_expected_goals_model_id', $goalModelId)
            ->where('shot_on_goal_expected_goals_model_id', $sogModelId)
            ->where('player_id', $playerId)
            ->whereNotIn('matched_bucket_key', array_column($payloads, 'matched_bucket_key'))
            ->delete();
    }

    /**
     * @return array<string, string>
     */
    private function bucketDimensions(string $bucketKey): array
    {
        $dimensions = [];

        foreach (explode('|', $bucketKey) as $part) {
            if (! str_contains($part, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $part, 2);
            $dimensions[$key] = $value;
        }

        return $dimensions;
    }

    /**
     * @param Collection<string, object> $rowMap
     * @return array<string, mixed>
     */
    private function shrinkageRates(object $row, Collection $rowMap): array
    {
        $bucketSatAgainst = max(1, (int) $row->source_sat_against_on_ice);
        $rawGoalProbability = (float) $row->source_xga_on_ice / $bucketSatAgainst;
        $rawShotOnGoalProbability = (float) $row->source_xsoga_on_ice / $bucketSatAgainst;
        $rawActualGoalRate = (int) $row->source_goals_against_on_ice / $bucketSatAgainst;
        $rawActualShotOnGoalRate = (int) $row->source_sog_against_on_ice / $bucketSatAgainst;
        $prior = $this->priorRow((string) $row->matched_bucket_key, $rowMap, $bucketSatAgainst);

        if ($prior === null) {
            return [
                'goal_probability_against' => round($rawGoalProbability, 6),
                'shot_on_goal_probability_against' => round($rawShotOnGoalProbability, 6),
                'confidence_score' => 1.0,
                'raw_goal_probability_against' => round($rawGoalProbability, 6),
                'raw_shot_on_goal_probability_against' => round($rawShotOnGoalProbability, 6),
                'raw_actual_goal_rate_against' => round($rawActualGoalRate, 6),
                'raw_actual_shot_on_goal_rate_against' => round($rawActualShotOnGoalRate, 6),
                'prior_bucket_key' => null,
                'prior_fallback_level' => null,
                'prior_sat_against' => 0,
                'prior_weight_sat_against' => 0,
                'shrinkage_weight' => 0.0,
            ];
        }

        $priorSatAgainst = max(1, (int) $prior->source_sat_against_on_ice);
        $priorWeight = max(0, $priorSatAgainst - $bucketSatAgainst);
        $priorGoalProbability = ((float) $prior->source_xga_on_ice - (float) $row->source_xga_on_ice) / max(1, $priorWeight);
        $priorShotOnGoalProbability = ((float) $prior->source_xsoga_on_ice - (float) $row->source_xsoga_on_ice) / max(1, $priorWeight);
        $confidenceScore = $bucketSatAgainst / ($bucketSatAgainst + $priorWeight);

        return [
            'goal_probability_against' => round(
                (($bucketSatAgainst * $rawGoalProbability) + ($priorWeight * $priorGoalProbability))
                    / max(1, $bucketSatAgainst + $priorWeight),
                6
            ),
            'shot_on_goal_probability_against' => round(
                (($bucketSatAgainst * $rawShotOnGoalProbability) + ($priorWeight * $priorShotOnGoalProbability))
                    / max(1, $bucketSatAgainst + $priorWeight),
                6
            ),
            'confidence_score' => round($confidenceScore, 4),
            'raw_goal_probability_against' => round($rawGoalProbability, 6),
            'raw_shot_on_goal_probability_against' => round($rawShotOnGoalProbability, 6),
            'raw_actual_goal_rate_against' => round($rawActualGoalRate, 6),
            'raw_actual_shot_on_goal_rate_against' => round($rawActualShotOnGoalRate, 6),
            'prior_bucket_key' => (string) $prior->matched_bucket_key,
            'prior_fallback_level' => (int) $prior->fallback_level,
            'prior_sat_against' => $priorSatAgainst,
            'prior_weight_sat_against' => $priorWeight,
            'prior_goal_probability_against' => round($priorGoalProbability, 6),
            'prior_shot_on_goal_probability_against' => round($priorShotOnGoalProbability, 6),
            'shrinkage_weight' => round(1 - $confidenceScore, 4),
        ];
    }

    /**
     * @param Collection<string, object> $rowMap
     */
    private function priorRow(string $bucketKey, Collection $rowMap, int $bucketSatAgainst): ?object
    {
        foreach ($this->parentBucketKeys($bucketKey) as $parentBucketKey) {
            $parent = $rowMap->get($parentBucketKey);

            if ($parent !== null && (int) $parent->source_sat_against_on_ice > $bucketSatAgainst) {
                return $parent;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function parentBucketKeys(string $bucketKey): array
    {
        $dimensions = $this->bucketDimensions($bucketKey);

        return array_values(array_filter([
            $this->bucketKey(2, [
                'shot_type_group' => $dimensions['shot_type_group'] ?? null,
                'distance_group' => $dimensions['distance_group'] ?? null,
                'angle_group' => $dimensions['angle_group'] ?? null,
            ]),
            $this->bucketKey(3, [
                'distance_group' => $dimensions['distance_group'] ?? null,
                'angle_group' => $dimensions['angle_group'] ?? null,
                'sequence_group' => $dimensions['sequence_group'] ?? null,
            ]),
            $this->bucketKey(4, [
                'distance_group' => $dimensions['distance_group'] ?? null,
                'angle_group' => $dimensions['angle_group'] ?? null,
            ]),
            $this->bucketKey(5, [
                'shot_type_group' => $dimensions['shot_type_group'] ?? null,
                'distance_group' => $dimensions['distance_group'] ?? null,
            ]),
            $this->bucketKey(6, [
                'distance_group' => $dimensions['distance_group'] ?? null,
            ]),
            'L99|baseline=league',
        ]));
    }

    /**
     * @param array<string, string|null> $dimensions
     */
    private function bucketKey(int $fallbackLevel, array $dimensions): ?string
    {
        foreach ($dimensions as $value) {
            if ($value === null) {
                return null;
            }
        }

        $prefix = 'L' . str_pad((string) $fallbackLevel, 2, '0', STR_PAD_LEFT);
        $parts = [$prefix];

        foreach ($dimensions as $key => $value) {
            $parts[] = $key . '=' . $value;
        }

        return implode('|', $parts);
    }

    private function confidenceBucket(float $confidenceScore): string
    {
        if ($confidenceScore >= 0.75) {
            return 'high';
        }

        if ($confidenceScore >= 0.5) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * @return array<int, string>
     */
    private function flags(array $shrinkage, int $sourceToiSeconds): array
    {
        $flags = [];

        if (($shrinkage['confidence_score'] ?? 1.0) < 0.5) {
            $flags[] = 'limited_skater_defensive_bucket_sat_against';
        }

        if (($shrinkage['prior_fallback_level'] ?? null) === 99) {
            $flags[] = 'skater_defensive_bucket_league_baseline';
        }

        if (($shrinkage['shrinkage_weight'] ?? 0.0) > 0.0) {
            $flags[] = 'skater_defensive_bucket_shrunk_to_parent';
        }

        if ($sourceToiSeconds <= 0) {
            $flags[] = 'missing_skater_defensive_source_toi';
        }

        return $flags;
    }

    /**
     * @return array<int, string>
     */
    private function updateColumns(): array
    {
        return [
            'team_id',
            'team_abbrev',
            'position',
            'fallback_level',
            'bucket_dimensions',
            'shot_type_group',
            'distance_group',
            'angle_group',
            'sequence_group',
            'source_games',
            'source_toi_seconds',
            'source_sat_against_on_ice',
            'source_sog_against_on_ice',
            'source_goals_against_on_ice',
            'source_xga_on_ice',
            'source_xsoga_on_ice',
            'source_xga_per_60',
            'source_xsoga_per_60',
            'source_profile_share_against',
            'goal_probability_against',
            'shot_on_goal_probability_against',
            'confidence_score',
            'confidence_bucket',
            'profile_inputs',
            'flags',
            'metadata',
            'profiled_at',
            'updated_at',
        ];
    }
}
