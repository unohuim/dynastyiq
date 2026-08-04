<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NhlExpectedGoalsModel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Builds historical skater offensive chance profiles with empirical-Bayes bucket shrinkage.
 */
class NhlSkaterOffensiveChanceProfileBuilder
{
    private const REGULAR_SEASON_GAME_TYPE = 2;
    private const MIN_PLAYER_SAT_FOR = 25;

    public function __construct(private readonly NhlShotAttemptAnalysisBuckets $buckets)
    {
    }

    /**
     * Prepare one skater offensive chance-profile build and return eligible player ids.
     *
     * @return array{source_season_id:string,game_type:int,goal_model_id:int,sog_model_id:int,player_ids:array<int,int>}
     */
    public function prepareBuild(string $sourceSeasonId, int $gameType = self::REGULAR_SEASON_GAME_TYPE): array
    {
        $goalModel = $this->latestModel($sourceSeasonId, NhlExpectedGoalsBackfiller::TARGET_GOAL);
        $sogModel = $this->latestModel($sourceSeasonId, NhlExpectedGoalsBackfiller::TARGET_SHOT_ON_GOAL);

        if ($goalModel === null || $sogModel === null) {
            throw new RuntimeException('Build xG and xSOG models before building skater offensive chance profiles.');
        }

        return [
            'source_season_id' => $sourceSeasonId,
            'game_type' => $gameType,
            'goal_model_id' => (int) $goalModel->id,
            'sog_model_id' => (int) $sogModel->id,
            'player_ids' => $this->eligiblePlayerIds($sourceSeasonId, $gameType, (int) $goalModel->id, (int) $sogModel->id)->all(),
        ];
    }

    /**
     * Build one skater's offensive chance profile buckets.
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

            if ($summary === null || (int) $summary->source_sat_for < self::MIN_PLAYER_SAT_FOR) {
                $this->deleteExistingProfiles($sourceSeasonId, $gameType, $goalModelId, $sogModelId, $playerId);

                return ['player_id' => $playerId, 'bucket_rows' => 0];
            }

            $rows = $this->playerBucketRows($sourceSeasonId, $gameType, $goalModelId, $sogModelId, $playerId);
            $payloads = $this->payloads($summary, $rows, $sourceSeasonId, $gameType, $goalModelId, $sogModelId);

            if ($payloads === []) {
                return ['player_id' => $playerId, 'bucket_rows' => 0];
            }

            foreach (array_chunk($payloads, 100) as $chunk) {
                DB::table('nhl_skater_offensive_chance_profile_buckets')->upsert(
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
            ->leftJoin('players', 'players.nhl_id', '=', 'facts.shooter_player_id')
            ->where('facts.season_id', $sourceSeasonId)
            ->where('games.game_type', $gameType)
            ->whereNotNull('facts.shooter_player_id')
            ->where(function ($query): void {
                $query->whereNull('players.pos_type')
                    ->orWhere('players.pos_type', '<>', 'G');
            })
            ->groupBy('facts.shooter_player_id')
            ->havingRaw('COUNT(*) >= ?', [self::MIN_PLAYER_SAT_FOR])
            ->orderBy('facts.shooter_player_id')
            ->pluck('facts.shooter_player_id')
            ->map(fn (mixed $playerId): int => (int) $playerId);
    }

    private function playerSummary(
        string $sourceSeasonId,
        int $gameType,
        int $goalModelId,
        int $sogModelId,
        int $playerId
    ): ?object {
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
            ->leftJoin('nhl_teams as teams', 'teams.nhl_id', '=', 'facts.team_id')
            ->leftJoin('players', 'players.nhl_id', '=', 'facts.shooter_player_id')
            ->leftJoin('nhl_season_stats as season_stats', function ($join) use ($sourceSeasonId, $gameType): void {
                $join->on('season_stats.nhl_player_id', '=', 'facts.shooter_player_id')
                    ->where('season_stats.season_id', '=', $sourceSeasonId)
                    ->where('season_stats.game_type', '=', $gameType);
            })
            ->where('facts.season_id', $sourceSeasonId)
            ->where('games.game_type', $gameType)
            ->where('facts.shooter_player_id', $playerId)
            ->selectRaw('facts.shooter_player_id as player_id')
            ->selectRaw('MAX(facts.team_id) as team_id')
            ->selectRaw("MAX(COALESCE(teams.abbrev, facts.team_id::text)) as team_abbrev")
            ->selectRaw('MAX(COALESCE(players.position, players.pos_type)) as position')
            ->selectRaw('COUNT(DISTINCT facts.nhl_game_id)::decimal as source_games')
            ->selectRaw('MAX(season_stats.toi) as source_toi_seconds')
            ->selectRaw('COUNT(*) as source_sat_for')
            ->selectRaw('SUM(CASE WHEN facts.is_shot_on_goal THEN 1 ELSE 0 END) as source_sog_for')
            ->selectRaw('COALESCE(MAX(season_stats.g), SUM(CASE WHEN facts.is_goal THEN 1 ELSE 0 END)) as source_goals_for')
            ->selectRaw('ROUND(SUM(goal_predictions.xg)::numeric, 4) as source_xgf')
            ->selectRaw('ROUND(SUM(sog_predictions.xg)::numeric, 4) as source_xsog')
            ->groupBy('facts.shooter_player_id')
            ->first();
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
    SELECT
        facts.id,
        facts.shooter_player_id as player_id,
        facts.team_id,
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
    WHERE facts.season_id = ?
        AND games.game_type = ?
        AND facts.shooter_player_id = ?
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
    COUNT(*) as source_sat_for,
    SUM(CASE WHEN is_shot_on_goal THEN 1 ELSE 0 END) as source_sog_for,
    SUM(CASE WHEN is_goal THEN 1 ELSE 0 END) as source_goals_for,
    ROUND(SUM(goal_xg)::numeric, 4) as source_xgf,
    ROUND(SUM(sog_xg)::numeric, 4) as source_xsog,
    ROUND(AVG(goal_xg)::numeric, 6) as goal_probability,
    ROUND(AVG(sog_xg)::numeric, 6) as shot_on_goal_probability
FROM candidate_attempts
GROUP BY player_id, bucket_key, fallback_level
ORDER BY fallback_level, source_sat_for DESC, source_xgf DESC
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
        $sourceSatFor = max(1, (int) $summary->source_sat_for);
        $sourceToiSeconds = (int) ($summary->source_toi_seconds ?? 0);
        $sourceToiHours = $sourceToiSeconds > 0 ? $sourceToiSeconds / 3600 : 0.0;
        $rowMap = $rows->keyBy(fn (object $row): string => (string) $row->matched_bucket_key);
        $now = now();

        return $rows
            ->filter(fn (object $row): bool => (int) $row->fallback_level === 1)
            ->map(function (object $row) use (
                $summary,
                $sourceSatFor,
                $sourceToiSeconds,
                $sourceToiHours,
                $rowMap,
                $sourceSeasonId,
                $gameType,
                $goalModelId,
                $sogModelId,
                $now
            ): array {
                $bucketSatFor = (int) $row->source_sat_for;
                $sourceXgf = (float) $row->source_xgf;
                $sourceXsog = (float) $row->source_xsog;
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
                    'source_sat_for' => $bucketSatFor,
                    'source_sog_for' => (int) $row->source_sog_for,
                    'source_goals_for' => (int) $row->source_goals_for,
                    'source_xgf' => $row->source_xgf,
                    'source_xsog' => $row->source_xsog,
                    'source_xgf_per_60' => $sourceToiHours > 0 ? round($sourceXgf / $sourceToiHours, 4) : null,
                    'source_xsog_per_60' => $sourceToiHours > 0 ? round($sourceXsog / $sourceToiHours, 4) : null,
                    'source_profile_share' => round($bucketSatFor / $sourceSatFor, 6),
                    'goal_probability' => $shrinkage['goal_probability'],
                    'shot_on_goal_probability' => $shrinkage['shot_on_goal_probability'],
                    'confidence_score' => $shrinkage['confidence_score'],
                    'confidence_bucket' => $this->confidenceBucket($shrinkage['confidence_score']),
                    'profile_inputs' => json_encode([
                        'method' => 'skater_offensive_chance_profile_with_empirical_bayes_shrinkage',
                        'minimum_player_sat_for' => self::MIN_PLAYER_SAT_FOR,
                        'source_total_sat_for' => $sourceSatFor,
                    ], JSON_THROW_ON_ERROR),
                    'flags' => json_encode($this->flags($shrinkage, $sourceToiSeconds), JSON_THROW_ON_ERROR),
                    'metadata' => json_encode([
                        'builder' => 'NhlSkaterOffensiveChanceProfileBuilder',
                        'shrinkage' => $shrinkage,
                    ], JSON_THROW_ON_ERROR),
                    'profiled_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            })->values()->all();
    }

    private function deleteExistingProfiles(string $sourceSeasonId, int $gameType, int $goalModelId, int $sogModelId, int $playerId): void
    {
        DB::table('nhl_skater_offensive_chance_profile_buckets')
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
    private function deleteStaleProfiles(string $sourceSeasonId, int $gameType, int $goalModelId, int $sogModelId, int $playerId, array $payloads): void
    {
        DB::table('nhl_skater_offensive_chance_profile_buckets')
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
        $bucketSatFor = max(1, (int) $row->source_sat_for);
        $rawGoalProbability = (float) $row->source_xgf / $bucketSatFor;
        $rawShotOnGoalProbability = (float) $row->source_xsog / $bucketSatFor;
        $rawActualGoalRate = (int) $row->source_goals_for / $bucketSatFor;
        $rawActualShotOnGoalRate = (int) $row->source_sog_for / $bucketSatFor;
        $prior = $this->priorRow((string) $row->matched_bucket_key, $rowMap, $bucketSatFor);

        if ($prior === null) {
            return [
                'goal_probability' => round($rawGoalProbability, 6),
                'shot_on_goal_probability' => round($rawShotOnGoalProbability, 6),
                'confidence_score' => 1.0,
                'raw_goal_probability' => round($rawGoalProbability, 6),
                'raw_shot_on_goal_probability' => round($rawShotOnGoalProbability, 6),
                'raw_actual_goal_rate' => round($rawActualGoalRate, 6),
                'raw_actual_shot_on_goal_rate' => round($rawActualShotOnGoalRate, 6),
                'prior_bucket_key' => null,
                'prior_fallback_level' => null,
                'prior_sat_for' => 0,
                'prior_weight_sat_for' => 0,
                'shrinkage_weight' => 0.0,
            ];
        }

        $priorSatFor = max(1, (int) $prior->source_sat_for);
        $priorWeight = max(0, $priorSatFor - $bucketSatFor);
        $priorGoalProbability = ((float) $prior->source_xgf - (float) $row->source_xgf) / max(1, $priorWeight);
        $priorShotOnGoalProbability = ((float) $prior->source_xsog - (float) $row->source_xsog) / max(1, $priorWeight);
        $confidenceScore = $bucketSatFor / ($bucketSatFor + $priorWeight);

        return [
            'goal_probability' => round((($bucketSatFor * $rawGoalProbability) + ($priorWeight * $priorGoalProbability)) / max(1, $bucketSatFor + $priorWeight), 6),
            'shot_on_goal_probability' => round((($bucketSatFor * $rawShotOnGoalProbability) + ($priorWeight * $priorShotOnGoalProbability)) / max(1, $bucketSatFor + $priorWeight), 6),
            'confidence_score' => round($confidenceScore, 4),
            'raw_goal_probability' => round($rawGoalProbability, 6),
            'raw_shot_on_goal_probability' => round($rawShotOnGoalProbability, 6),
            'raw_actual_goal_rate' => round($rawActualGoalRate, 6),
            'raw_actual_shot_on_goal_rate' => round($rawActualShotOnGoalRate, 6),
            'prior_bucket_key' => (string) $prior->matched_bucket_key,
            'prior_fallback_level' => (int) $prior->fallback_level,
            'prior_sat_for' => $priorSatFor,
            'prior_weight_sat_for' => $priorWeight,
            'prior_goal_probability' => round($priorGoalProbability, 6),
            'prior_shot_on_goal_probability' => round($priorShotOnGoalProbability, 6),
            'shrinkage_weight' => round(1 - $confidenceScore, 4),
        ];
    }

    /**
     * @param Collection<string, object> $rowMap
     */
    private function priorRow(string $bucketKey, Collection $rowMap, int $bucketSatFor): ?object
    {
        foreach ($this->parentBucketKeys($bucketKey) as $parentBucketKey) {
            $parent = $rowMap->get($parentBucketKey);

            if ($parent !== null && (int) $parent->source_sat_for > $bucketSatFor) {
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

        $parts = ['L' . str_pad((string) $fallbackLevel, 2, '0', STR_PAD_LEFT)];

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
     * @param array<string, mixed> $shrinkage
     * @return array<int, string>
     */
    private function flags(array $shrinkage, int $sourceToiSeconds): array
    {
        $flags = [];

        if (($shrinkage['confidence_score'] ?? 1.0) < 0.5) {
            $flags[] = 'limited_skater_offensive_bucket_sat_for';
        }

        if (($shrinkage['prior_fallback_level'] ?? null) === 99) {
            $flags[] = 'skater_offensive_bucket_league_baseline';
        }

        if (($shrinkage['shrinkage_weight'] ?? 0.0) > 0.0) {
            $flags[] = 'skater_offensive_bucket_shrunk_to_parent';
        }

        if ($sourceToiSeconds <= 0) {
            $flags[] = 'missing_skater_offensive_source_toi';
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
            'source_sat_for',
            'source_sog_for',
            'source_goals_for',
            'source_xgf',
            'source_xsog',
            'source_xgf_per_60',
            'source_xsog_per_60',
            'source_profile_share',
            'goal_probability',
            'shot_on_goal_probability',
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
