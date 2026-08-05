<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NhlExpectedGoalsModel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Builds historical goalie chance-mix profiles with performance over expected.
 */
class NhlGoalieChanceProfileBuilder
{
    private const REGULAR_SEASON_GAME_TYPE = 2;
    private const MIN_GOALIE_SAT_AGAINST = 300;
    private const PROFILE_COVERAGE_TARGET = 0.95;
    private const SPLIT_COVERAGE_TARGET = 0.90;
    private const MIN_SPLIT_CORE_COVERAGE = 0.60;
    private const MIN_SPLIT_CHILD_CONFIDENCE = 0.50;
    private const MAX_RESOLVED_PROFILE_BUCKETS = 18;
    private const BASE_PROFILE_FALLBACK_LEVEL = 6;
    private const LEAGUE_BASELINE_FALLBACK_LEVEL = 99;
    private const LEAGUE_BASELINE_BUCKET_KEY = 'L99|baseline=league';
    private const MIN_SPLIT_CHILD_ROWS = 2;
    private const OTHER_BUCKET_VALUE = 'Other';

    public function __construct(private readonly NhlShotAttemptAnalysisBuckets $buckets)
    {
    }

    /**
     * Prepare one goalie chance-profile build and return eligible goalie ids.
     *
     * @return array{source_season_id:string,game_type:int,goal_model_id:int,sog_model_id:int,goalie_player_ids:array<int,int>}
     */
    public function prepareBuild(string $sourceSeasonId, int $gameType = self::REGULAR_SEASON_GAME_TYPE): array
    {
        $goalModel = $this->latestModel($sourceSeasonId, NhlExpectedGoalsBackfiller::TARGET_GOAL);
        $sogModel = $this->latestModel($sourceSeasonId, NhlExpectedGoalsBackfiller::TARGET_SHOT_ON_GOAL);

        if ($goalModel === null || $sogModel === null) {
            throw new RuntimeException('Build xG and xSOG models before building goalie chance profiles.');
        }

        return [
            'source_season_id' => $sourceSeasonId,
            'game_type' => $gameType,
            'goal_model_id' => (int) $goalModel->id,
            'sog_model_id' => (int) $sogModel->id,
            'goalie_player_ids' => $this->eligibleGoalieIds(
                $sourceSeasonId,
                $gameType,
                (int) $goalModel->id,
                (int) $sogModel->id
            )->all(),
        ];
    }

    /**
     * Build one goalie's chance profile buckets.
     *
     * @return array{goalie_player_id:int,bucket_rows:int}
     */
    public function buildGoalie(
        string $sourceSeasonId,
        int $gameType,
        int $goalModelId,
        int $sogModelId,
        int $goaliePlayerId
    ): array {
        return DB::transaction(function () use (
            $sourceSeasonId,
            $gameType,
            $goalModelId,
            $sogModelId,
            $goaliePlayerId
        ): array {
            $summary = $this->goalieSummary($sourceSeasonId, $gameType, $goalModelId, $sogModelId, $goaliePlayerId);

            if ($summary === null) {
                $summary = $this->playerFallbackSummary($goaliePlayerId);
            }

            $lowSample = (int) ($summary->source_sat_against ?? 0) < self::MIN_GOALIE_SAT_AGAINST;
            $rows = $lowSample
                ? collect()
                : $this->goalieBucketRows($sourceSeasonId, $gameType, $goalModelId, $sogModelId, $goaliePlayerId);
            $payloads = $lowSample
                ? $this->fallbackPayloads($summary, $sourceSeasonId, $gameType, $goalModelId, $sogModelId)
                : $this->payloads($summary, $rows, $sourceSeasonId, $gameType, $goalModelId, $sogModelId);

            if ($payloads === []) {
                return ['goalie_player_id' => $goaliePlayerId, 'bucket_rows' => 0];
            }

            foreach (array_chunk($payloads, 100) as $chunk) {
                DB::table('nhl_goalie_chance_profile_buckets')->upsert(
                    $chunk,
                    [
                        'source_season_id',
                        'game_type',
                        'goal_expected_goals_model_id',
                        'shot_on_goal_expected_goals_model_id',
                        'goalie_player_id',
                        'matched_bucket_key',
                    ],
                    $this->updateColumns()
                );
            }

            $this->deleteStaleProfiles($sourceSeasonId, $gameType, $goalModelId, $sogModelId, $goaliePlayerId, $payloads);

            return ['goalie_player_id' => $goaliePlayerId, 'bucket_rows' => count($payloads)];
        });
    }

    private function deleteExistingProfiles(
        string $sourceSeasonId,
        int $gameType,
        int $goaliePlayerId
    ): void {
        DB::table('nhl_goalie_chance_profile_buckets')
            ->where('source_season_id', $sourceSeasonId)
            ->where('game_type', $gameType)
            ->where('goalie_player_id', $goaliePlayerId)
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
        int $goaliePlayerId,
        array $payloads
    ): void {
        DB::table('nhl_goalie_chance_profile_buckets')
            ->where('source_season_id', $sourceSeasonId)
            ->where('game_type', $gameType)
            ->where('goalie_player_id', $goaliePlayerId)
            ->where(function ($query) use ($goalModelId, $sogModelId, $payloads): void {
                $query->where('goal_expected_goals_model_id', '<>', $goalModelId)
                    ->orWhere('shot_on_goal_expected_goals_model_id', '<>', $sogModelId)
                    ->orWhereNotIn('matched_bucket_key', array_column($payloads, 'matched_bucket_key'));
            })
            ->delete();
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
    private function eligibleGoalieIds(string $sourceSeasonId, int $gameType, int $goalModelId, int $sogModelId): Collection
    {
        $factGoalieIds = DB::table('nhl_shot_attempts_facts as facts')
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
            ->where('facts.season_id', $sourceSeasonId)
            ->where('games.game_type', $gameType)
            ->whereNotNull('facts.goalie_player_id')
            ->groupBy('facts.goalie_player_id')
            ->havingRaw('COUNT(*) >= ?', [self::MIN_GOALIE_SAT_AGAINST])
            ->orderBy('facts.goalie_player_id')
            ->pluck('facts.goalie_player_id')
            ->map(fn (mixed $goaliePlayerId): int => (int) $goaliePlayerId);

        $activeGoalieIds = DB::table('players')
            ->whereNotNull('nhl_id')
            ->where('status', 'active')
            ->where(function ($query): void {
                $query->where('is_goalie', true)
                    ->orWhere('position', 'G')
                    ->orWhere('pos_type', 'G');
            })
            ->orderBy('nhl_id')
            ->pluck('nhl_id')
            ->map(fn (mixed $goaliePlayerId): int => (int) $goaliePlayerId);

        return $factGoalieIds
            ->merge($activeGoalieIds)
            ->unique()
            ->sort()
            ->values();
    }

    private function goalieSummary(
        string $sourceSeasonId,
        int $gameType,
        int $goalModelId,
        int $sogModelId,
        int $goaliePlayerId
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
            ->leftJoin('nhl_teams as teams', 'teams.nhl_id', '=', 'facts.opponent_team_id')
            ->leftJoin('players', 'players.nhl_id', '=', 'facts.goalie_player_id')
            ->leftJoin('nhl_season_stats as season_stats', function ($join) use ($sourceSeasonId, $gameType): void {
                $join->on('season_stats.nhl_player_id', '=', 'facts.goalie_player_id')
                    ->where('season_stats.season_id', '=', $sourceSeasonId)
                    ->where('season_stats.game_type', '=', $gameType);
            })
            ->where('facts.season_id', $sourceSeasonId)
            ->where('games.game_type', $gameType)
            ->where('facts.goalie_player_id', $goaliePlayerId)
            ->selectRaw('facts.goalie_player_id')
            ->selectRaw('MAX(facts.opponent_team_id) as team_id')
            ->selectRaw("MAX(COALESCE(teams.abbrev, facts.opponent_team_id::text)) as team_abbrev")
            ->selectRaw("MAX(COALESCE(players.position, players.pos_type, 'G')) as position")
            ->selectRaw('COALESCE(MAX(season_stats.gp), COUNT(DISTINCT facts.nhl_game_id))::decimal as source_games')
            ->selectRaw('MAX(season_stats.toi) as source_toi_seconds')
            ->selectRaw('COUNT(*) as source_sat_against')
            ->selectRaw('SUM(CASE WHEN facts.is_shot_on_goal THEN 1 ELSE 0 END) as source_sog_against')
            ->selectRaw('COALESCE(MAX(season_stats.ga), SUM(CASE WHEN facts.is_goal THEN 1 ELSE 0 END)) as source_goals_against')
            ->selectRaw('ROUND(SUM(goal_predictions.xg)::numeric, 4) as source_xga')
            ->selectRaw('ROUND(SUM(sog_predictions.xg)::numeric, 4) as source_xsoga')
            ->groupBy('facts.goalie_player_id')
            ->first();
    }

    private function playerFallbackSummary(int $goaliePlayerId): object
    {
        $row = DB::table('players')
            ->where('nhl_id', $goaliePlayerId)
            ->select([
                'nhl_id',
                'nhl_team_id',
                'team_abbrev',
                'position',
                'pos_type',
            ])
            ->first();

        return (object) [
            'goalie_player_id' => $goaliePlayerId,
            'team_id' => $row?->nhl_team_id,
            'team_abbrev' => $row?->team_abbrev,
            'position' => $row?->position ?: ($row?->pos_type ?: 'G'),
            'source_games' => 0,
            'source_toi_seconds' => null,
            'source_sat_against' => 0,
            'source_sog_against' => 0,
            'source_goals_against' => 0,
            'source_xga' => null,
            'source_xsoga' => null,
        ];
    }

    /**
     * @return Collection<int, object>
     */
    private function goalieBucketRows(
        string $sourceSeasonId,
        int $gameType,
        int $goalModelId,
        int $sogModelId,
        int $goaliePlayerId
    ): Collection {
        $candidates = implode(",\n            ", $this->buckets->candidateBucketKeySql('scored_attempts'));
        $sql = <<<SQL
WITH scored_attempts AS (
    SELECT
        facts.id,
        facts.goalie_player_id,
        facts.opponent_team_id as team_id,
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
        AND facts.goalie_player_id = ?
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
    goalie_player_id,
    MAX(team_id) as team_id,
    bucket_key as matched_bucket_key,
    fallback_level,
    COUNT(*) as source_sat_against,
    SUM(CASE WHEN is_shot_on_goal THEN 1 ELSE 0 END) as source_sog_against,
    SUM(CASE WHEN is_goal THEN 1 ELSE 0 END) as source_goals_against,
    ROUND(SUM(goal_xg)::numeric, 4) as source_xga,
    ROUND(SUM(sog_xg)::numeric, 4) as source_xsoga,
    ROUND(AVG(goal_xg)::numeric, 6) as goal_probability_against,
    ROUND(AVG(sog_xg)::numeric, 6) as shot_on_goal_probability_against
FROM candidate_attempts
GROUP BY goalie_player_id, bucket_key, fallback_level
ORDER BY fallback_level, source_sat_against DESC, source_xga DESC
SQL;

        return collect(DB::select($sql, [
            $goalModelId,
            NhlExpectedGoalsBackfiller::TARGET_GOAL,
            $sogModelId,
            NhlExpectedGoalsBackfiller::TARGET_SHOT_ON_GOAL,
            $sourceSeasonId,
            $gameType,
            $goaliePlayerId,
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
        $sourceSatAgainst = max(1, (int) $summary->source_sat_against);
        $rowMap = $rows->keyBy(fn (object $row): string => (string) $row->matched_bucket_key);
        $now = now();

        $selectedRows = $this->resolvedProfileRows($rows);

        return $selectedRows
            ->map(function (object $row) use (
                $summary,
                $sourceSatAgainst,
                $rowMap,
                $sourceSeasonId,
                $gameType,
                $goalModelId,
                $sogModelId,
                $now
            ): array {
                $bucketSatAgainst = (int) $row->source_sat_against;
                $sourceGoalsAgainst = (int) $row->source_goals_against;
                $sourceXga = (float) $row->source_xga;
                $sourceGsax = round($sourceXga - $sourceGoalsAgainst, 4);
                $dimensions = $this->bucketDimensions((string) $row->matched_bucket_key);
                $shrinkage = $this->shrinkageRates($row, $rowMap);

                return [
                    'source_season_id' => $sourceSeasonId,
                    'game_type' => $gameType,
                    'goal_expected_goals_model_id' => $goalModelId,
                    'shot_on_goal_expected_goals_model_id' => $sogModelId,
                    'goalie_player_id' => (int) $row->goalie_player_id,
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
                    'source_toi_seconds' => $summary->source_toi_seconds === null ? null : (int) $summary->source_toi_seconds,
                    'source_sat_against' => $bucketSatAgainst,
                    'source_sog_against' => (int) $row->source_sog_against,
                    'source_goals_against' => $sourceGoalsAgainst,
                    'source_xga' => $row->source_xga,
                    'source_xsoga' => $row->source_xsoga,
                    'source_gsax' => $sourceGsax,
                    'source_gsax_per_100_sat_against' => round(($sourceGsax / max(1, $bucketSatAgainst)) * 100, 4),
                    'source_profile_share' => round($bucketSatAgainst / $sourceSatAgainst, 6),
                    'goal_probability_against' => $shrinkage['goal_probability_against'],
                    'shot_on_goal_probability_against' => $shrinkage['shot_on_goal_probability_against'],
                    'confidence_score' => $shrinkage['confidence_score'],
                    'confidence_bucket' => $this->confidenceBucket($shrinkage['confidence_score']),
                    'profile_inputs' => json_encode([
                        'method' => 'goalie_faced_chance_profile_with_adaptive_resolved_buckets',
                        'minimum_goalie_sat_against' => self::MIN_GOALIE_SAT_AGAINST,
                        'source_total_sat_against' => $sourceSatAgainst,
                        'coverage_target' => self::PROFILE_COVERAGE_TARGET,
                        'split_coverage_target' => self::SPLIT_COVERAGE_TARGET,
                        'max_resolved_profile_buckets' => self::MAX_RESOLVED_PROFILE_BUCKETS,
                        'resolved_fallback_level' => (int) $row->fallback_level,
                    ], JSON_THROW_ON_ERROR),
                    'flags' => json_encode($this->flags($shrinkage), JSON_THROW_ON_ERROR),
                    'metadata' => json_encode([
                        'builder' => 'NhlGoalieChanceProfileBuilder',
                        'shrinkage' => $shrinkage,
                    ], JSON_THROW_ON_ERROR),
                    'profiled_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            })->values()->all();
    }

    /**
     * Persist a neutral, auditable profile for active goalies whose source
     * season sample is too small to support bucket-level skill.
     *
     * @return array<int, array<string,mixed>>
     */
    private function fallbackPayloads(
        object $summary,
        string $sourceSeasonId,
        int $gameType,
        int $goalModelId,
        int $sogModelId
    ): array {
        $now = now();
        $sourceSatAgainst = (int) ($summary->source_sat_against ?? 0);
        $sourceSogAgainst = (int) ($summary->source_sog_against ?? 0);
        $sourceGoalsAgainst = (int) ($summary->source_goals_against ?? 0);
        $dimensions = ['baseline' => 'league'];
        $flags = ['goalie_profile_low_sample_neutral_fallback'];

        if ($sourceSatAgainst <= 0) {
            $flags[] = 'goalie_profile_no_source_sample';
        }

        return [[
            'source_season_id' => $sourceSeasonId,
            'game_type' => $gameType,
            'goal_expected_goals_model_id' => $goalModelId,
            'shot_on_goal_expected_goals_model_id' => $sogModelId,
            'goalie_player_id' => (int) $summary->goalie_player_id,
            'team_id' => $summary->team_id === null ? null : (int) $summary->team_id,
            'team_abbrev' => $summary->team_abbrev,
            'position' => $summary->position ?: 'G',
            'matched_bucket_key' => self::LEAGUE_BASELINE_BUCKET_KEY,
            'fallback_level' => self::LEAGUE_BASELINE_FALLBACK_LEVEL,
            'bucket_dimensions' => json_encode($dimensions, JSON_THROW_ON_ERROR),
            'shot_type_group' => null,
            'distance_group' => null,
            'angle_group' => null,
            'sequence_group' => null,
            'source_games' => $summary->source_games,
            'source_toi_seconds' => $summary->source_toi_seconds === null ? null : (int) $summary->source_toi_seconds,
            'source_sat_against' => $sourceSatAgainst,
            'source_sog_against' => $sourceSogAgainst,
            'source_goals_against' => $sourceGoalsAgainst,
            'source_xga' => null,
            'source_xsoga' => null,
            'source_gsax' => 0,
            'source_gsax_per_100_sat_against' => 0,
            'source_profile_share' => 1,
            'goal_probability_against' => null,
            'shot_on_goal_probability_against' => null,
            'confidence_score' => 0,
            'confidence_bucket' => 'low',
            'profile_inputs' => json_encode([
                'method' => 'active_goalie_low_sample_neutral_profile_fallback',
                'minimum_goalie_sat_against' => self::MIN_GOALIE_SAT_AGAINST,
                'source_total_sat_against' => $sourceSatAgainst,
                'source_total_sog_against' => $sourceSogAgainst,
                'source_total_goals_against' => $sourceGoalsAgainst,
                'fallback_bucket_key' => self::LEAGUE_BASELINE_BUCKET_KEY,
            ], JSON_THROW_ON_ERROR),
            'flags' => json_encode($flags, JSON_THROW_ON_ERROR),
            'metadata' => json_encode([
                'builder' => 'NhlGoalieChanceProfileBuilder',
                'fallback_reason' => $sourceSatAgainst <= 0 ? 'no_source_sample' : 'low_source_sample',
                'neutral_goalie_skill' => true,
            ], JSON_THROW_ON_ERROR),
            'profiled_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]];
    }

    /**
     * Resolve a compact, non-overlapping goalie profile by starting broad and
     * splitting only the biggest rows that can support useful child groups.
     *
     * @param Collection<int, object> $rows
     * @return Collection<int, object>
     */
    private function resolvedProfileRows(Collection $rows): Collection
    {
        $selectedRows = $rows
            ->where('fallback_level', self::BASE_PROFILE_FALLBACK_LEVEL)
            ->sortByDesc(fn (object $row): float => (float) $row->source_xsoga)
            ->values();

        if ($selectedRows->isEmpty()) {
            return $rows
                ->where('fallback_level', 99)
                ->values();
        }

        do {
            $bestSplit = $this->bestProfileSplit($selectedRows, $rows);

            if ($bestSplit === null) {
                break;
            }

            $selectedRows = $selectedRows
                ->reject(fn (object $row): bool => (string) $row->matched_bucket_key === $bestSplit['parent_key'])
                ->merge($bestSplit['children'])
                ->sortByDesc(fn (object $row): float => (float) $row->source_xsoga)
                ->values();
        } while ($selectedRows->count() < self::MAX_RESOLVED_PROFILE_BUCKETS);

        return $selectedRows;
    }

    /**
     * @param Collection<int, object> $rows
     * @return array{parent_key:string,children:Collection<int, object>,score:float}|null
     */
    private function bestProfileSplit(Collection $selectedRows, Collection $rows): ?array
    {
        $bestSplit = null;

        foreach ($selectedRows as $parentRow) {
            $availableSlots = self::MAX_RESOLVED_PROFILE_BUCKETS - $selectedRows->count() + 1;
            $children = $this->bestChildRowsForParent($parentRow, $rows, $availableSlots);

            if ($children === null) {
                continue;
            }

            $score = ((float) $parentRow->source_xsoga) * ((int) $parentRow->fallback_level - (float) $children->avg('fallback_level'));

            if ($bestSplit === null || $score > $bestSplit['score']) {
                $bestSplit = [
                    'parent_key' => (string) $parentRow->matched_bucket_key,
                    'children' => $children,
                    'score' => $score,
                ];
            }
        }

        return $bestSplit;
    }

    /**
     * @param Collection<int, object> $rows
     * @return Collection<int, object>|null
     */
    private function bestChildRowsForParent(object $parentRow, Collection $rows, int $availableSlots): ?Collection
    {
        if ($availableSlots < self::MIN_SPLIT_CHILD_ROWS) {
            return null;
        }

        $parentFallbackLevel = (int) $parentRow->fallback_level;
        $parentXsoga = max(0.0001, (float) $parentRow->source_xsoga);

        foreach ([5, 4, 3, 2, 1] as $fallbackLevel) {
            if ($fallbackLevel >= $parentFallbackLevel) {
                continue;
            }

            $children = $rows
                ->where('fallback_level', $fallbackLevel)
                ->filter(fn (object $row): bool => $this->rowContainsParentDimensions($parentRow, $row))
                ->sortByDesc(fn (object $row): float => (float) $row->source_xsoga)
                ->values();

            if ($children->count() < self::MIN_SPLIT_CHILD_ROWS) {
                continue;
            }

            $meaningfulChildren = $children
                ->filter(fn (object $row): bool => $this->sampleConfidence((int) $row->source_sat_against) >= self::MIN_SPLIT_CHILD_CONFIDENCE)
                ->values();

            if ($meaningfulChildren->count() < self::MIN_SPLIT_CHILD_ROWS) {
                continue;
            }

            $requiresTail = $meaningfulChildren->count() < $children->count()
                || $meaningfulChildren->count() > $availableSlots;
            $childLimit = $requiresTail ? $availableSlots - 1 : $availableSlots;

            if ($childLimit < self::MIN_SPLIT_CHILD_ROWS) {
                continue;
            }

            $selectedChildren = $meaningfulChildren
                ->take($childLimit)
                ->values();

            $selectedKeys = $selectedChildren->pluck('matched_bucket_key')->map(fn (mixed $key): string => (string) $key)->all();
            $tailRows = $children
                ->reject(fn (object $row): bool => in_array((string) $row->matched_bucket_key, $selectedKeys, true))
                ->values();

            $selectedCoverage = ((float) $selectedChildren->sum('source_xsoga')) / $parentXsoga;
            $totalCoverage = (((float) $selectedChildren->sum('source_xsoga')) + ((float) $tailRows->sum('source_xsoga'))) / $parentXsoga;

            if ($selectedCoverage < self::MIN_SPLIT_CORE_COVERAGE || $totalCoverage < self::SPLIT_COVERAGE_TARGET) {
                continue;
            }

            if ($tailRows->isNotEmpty()) {
                $selectedChildren->push($this->otherChildRow($parentRow, $tailRows, $fallbackLevel));
            }

            return $selectedChildren;
        }

        return null;
    }

    private function rowContainsParentDimensions(object $parentRow, object $childRow): bool
    {
        foreach ($this->bucketDimensions((string) $parentRow->matched_bucket_key) as $dimension => $value) {
            if ($dimension === 'baseline') {
                continue;
            }

            $childValue = $this->bucketDimensions((string) $childRow->matched_bucket_key)[$dimension] ?? null;

            if ($childValue !== $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param Collection<int, object> $tailRows
     */
    private function otherChildRow(object $parentRow, Collection $tailRows, int $fallbackLevel): object
    {
        $sourceSatAgainst = (int) $tailRows->sum('source_sat_against');
        $sourceSogAgainst = (int) $tailRows->sum('source_sog_against');
        $sourceGoalsAgainst = (int) $tailRows->sum('source_goals_against');
        $sourceXga = round((float) $tailRows->sum('source_xga'), 4);
        $sourceXsoga = round((float) $tailRows->sum('source_xsoga'), 4);

        return (object) [
            'goalie_player_id' => $parentRow->goalie_player_id,
            'team_id' => $parentRow->team_id,
            'matched_bucket_key' => $this->otherBucketKey($parentRow, $fallbackLevel),
            'fallback_level' => $fallbackLevel,
            'source_sat_against' => $sourceSatAgainst,
            'source_sog_against' => $sourceSogAgainst,
            'source_goals_against' => $sourceGoalsAgainst,
            'source_xga' => $sourceXga,
            'source_xsoga' => $sourceXsoga,
            'goal_probability_against' => round($sourceXga / max(1, $sourceSatAgainst), 6),
            'shot_on_goal_probability_against' => round($sourceXsoga / max(1, $sourceSatAgainst), 6),
        ];
    }

    private function otherBucketKey(object $parentRow, int $fallbackLevel): string
    {
        $parentDimensions = $this->bucketDimensions((string) $parentRow->matched_bucket_key);
        $dimensions = [];

        foreach ($this->buckets->fallbackDefinitions()[$fallbackLevel] ?? [] as $dimension) {
            $dimensions[$dimension] = $parentDimensions[$dimension] ?? self::OTHER_BUCKET_VALUE;
        }

        return $this->bucketKey($fallbackLevel, $dimensions)
            ?? ('L' . str_pad((string) $fallbackLevel, 2, '0', STR_PAD_LEFT) . '|bucket=' . self::OTHER_BUCKET_VALUE);
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
        $bucketSatAgainst = max(1, (int) $row->source_sat_against);
        $rawGoalProbability = (float) $row->source_xga / $bucketSatAgainst;
        $rawShotOnGoalProbability = (float) $row->source_xsoga / $bucketSatAgainst;
        $rawActualGoalRate = (int) $row->source_goals_against / $bucketSatAgainst;
        $rawActualShotOnGoalRate = (int) $row->source_sog_against / $bucketSatAgainst;
        $sampleConfidence = $this->sampleConfidence($bucketSatAgainst);
        $prior = $this->priorRow((string) $row->matched_bucket_key, $rowMap, $bucketSatAgainst);

        if ($prior === null || (int) $prior->fallback_level === 99) {
            return [
                'goal_probability_against' => round($rawGoalProbability, 6),
                'shot_on_goal_probability_against' => round($rawShotOnGoalProbability, 6),
                'confidence_score' => $sampleConfidence,
                'raw_goal_probability_against' => round($rawGoalProbability, 6),
                'raw_shot_on_goal_probability_against' => round($rawShotOnGoalProbability, 6),
                'raw_actual_goal_rate_against' => round($rawActualGoalRate, 6),
                'raw_actual_shot_on_goal_rate_against' => round($rawActualShotOnGoalRate, 6),
                'prior_bucket_key' => $prior === null ? null : (string) $prior->matched_bucket_key,
                'prior_fallback_level' => $prior === null ? null : (int) $prior->fallback_level,
                'prior_sat_against' => $prior === null ? 0 : (int) $prior->source_sat_against,
                'prior_weight_sat_against' => 0,
                'shrinkage_weight' => 0.0,
            ];
        }

        $priorSatAgainst = max(1, (int) $prior->source_sat_against);
        $priorWeight = (int) round(self::MIN_GOALIE_SAT_AGAINST * (1 - $sampleConfidence));
        $priorGoalProbability = (float) $prior->source_xga / $priorSatAgainst;
        $priorShotOnGoalProbability = (float) $prior->source_xsoga / $priorSatAgainst;
        $confidenceScore = $bucketSatAgainst / max(1, $bucketSatAgainst + $priorWeight);

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

    private function sampleConfidence(int $bucketSatAgainst): float
    {
        return round(min(1.0, sqrt($bucketSatAgainst / self::MIN_GOALIE_SAT_AGAINST)), 4);
    }

    /**
     * @param Collection<string, object> $rowMap
     */
    private function priorRow(string $bucketKey, Collection $rowMap, int $bucketSatAgainst): ?object
    {
        foreach ($this->parentBucketKeys($bucketKey) as $parentBucketKey) {
            $parent = $rowMap->get($parentBucketKey);

            if ($parent !== null && (int) $parent->source_sat_against > $bucketSatAgainst) {
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
    private function flags(array $shrinkage): array
    {
        $flags = [];

        if (($shrinkage['confidence_score'] ?? 1.0) < 0.5) {
            $flags[] = 'limited_goalie_bucket_sat_against';
        }

        if (($shrinkage['prior_fallback_level'] ?? null) === 99) {
            $flags[] = 'goalie_bucket_league_baseline';
        }

        if (($shrinkage['shrinkage_weight'] ?? 0.0) > 0.0) {
            $flags[] = 'goalie_bucket_shrunk_to_parent';
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
            'source_sat_against',
            'source_sog_against',
            'source_goals_against',
            'source_xga',
            'source_xsoga',
            'source_gsax',
            'source_gsax_per_100_sat_against',
            'source_profile_share',
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
