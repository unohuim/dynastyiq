<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NhlExpectedGoalsModel;
use App\Models\NhlGameOfficial;
use App\Models\NhlGameTeamStaff;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Builds historical SAT chance profiles for NHL officials and head coaches.
 */
class NhlGameContextSatProfileBuilder
{
    public const STAFF_CONTEXT_OFFENSE = 'offense';
    public const STAFF_CONTEXT_DEFENSE = 'defense';

    private const REGULAR_SEASON_GAME_TYPE = 2;
    private const MIN_ENTITY_SAT = 25;
    private const MIN_AGGREGATE_BUCKET_SAT = 50;
    private const MIN_AGGREGATE_BUCKETS = 2;
    private const MAX_AGGREGATE_BUCKETS = 5;

    public function __construct(private readonly NhlShotAttemptAnalysisBuckets $buckets)
    {
    }

    /**
     * Prepare one official SAT profile build and return eligible assignments.
     *
     * @return array{source_season_id:string,game_type:int,goal_model_id:int,sog_model_id:int,assignments:array<int,array{official_id:int,role:string}>}
     */
    public function prepareOfficialBuild(string $sourceSeasonId, int $gameType = self::REGULAR_SEASON_GAME_TYPE): array
    {
        $models = $this->latestModels($sourceSeasonId);

        return [
            'source_season_id' => $sourceSeasonId,
            'game_type' => $gameType,
            'goal_model_id' => $models['goal_model_id'],
            'sog_model_id' => $models['sog_model_id'],
            'assignments' => $this->eligibleOfficialAssignments($sourceSeasonId, $gameType, $models['goal_model_id'], $models['sog_model_id'])->all(),
        ];
    }

    /**
     * Prepare one staff SAT profile build and return eligible staff contexts.
     *
     * @return array{source_season_id:string,game_type:int,goal_model_id:int,sog_model_id:int,assignments:array<int,array{staff_id:int,role:string,team_context:string}>}
     */
    public function prepareStaffBuild(string $sourceSeasonId, int $gameType = self::REGULAR_SEASON_GAME_TYPE): array
    {
        $models = $this->latestModels($sourceSeasonId);

        return [
            'source_season_id' => $sourceSeasonId,
            'game_type' => $gameType,
            'goal_model_id' => $models['goal_model_id'],
            'sog_model_id' => $models['sog_model_id'],
            'assignments' => $this->eligibleStaffAssignments($sourceSeasonId, $gameType, $models['goal_model_id'], $models['sog_model_id'])->all(),
        ];
    }

    /**
     * Build one official's SAT profile buckets.
     *
     * @return array{official_id:int,role:string,bucket_rows:int,aggregate_rows:int}
     */
    public function buildOfficial(
        string $sourceSeasonId,
        int $gameType,
        int $goalModelId,
        int $sogModelId,
        int $officialId,
        string $role
    ): array {
        return DB::transaction(function () use ($sourceSeasonId, $gameType, $goalModelId, $sogModelId, $officialId, $role): array {
            $summary = $this->officialSummary($sourceSeasonId, $gameType, $goalModelId, $sogModelId, $officialId, $role);

            if ($summary === null || (int) $summary->source_sat < self::MIN_ENTITY_SAT) {
                $this->deleteExistingOfficialProfiles($sourceSeasonId, $gameType, $goalModelId, $sogModelId, $officialId, $role);
                $this->deleteExistingOfficialAggregateProfiles($sourceSeasonId, $gameType, $goalModelId, $sogModelId, $officialId, $role);

                return ['official_id' => $officialId, 'role' => $role, 'bucket_rows' => 0, 'aggregate_rows' => 0];
            }

            $rows = $this->officialBucketRows($sourceSeasonId, $gameType, $goalModelId, $sogModelId, $officialId, $role);
            $payloads = $this->officialPayloads($summary, $rows, $sourceSeasonId, $gameType, $goalModelId, $sogModelId, $officialId, $role);

            if ($payloads === []) {
                $this->deleteExistingOfficialAggregateProfiles($sourceSeasonId, $gameType, $goalModelId, $sogModelId, $officialId, $role);

                return ['official_id' => $officialId, 'role' => $role, 'bucket_rows' => 0, 'aggregate_rows' => 0];
            }

            foreach (array_chunk($payloads, 100) as $chunk) {
                DB::table('nhl_official_sat_profile_buckets')->upsert(
                    $chunk,
                    [
                        'source_season_id',
                        'game_type',
                        'goal_expected_goals_model_id',
                        'shot_on_goal_expected_goals_model_id',
                        'nhl_official_id',
                        'role',
                        'matched_bucket_key',
                    ],
                    $this->updateColumns()
                );
            }

            $this->deleteStaleOfficialProfiles($sourceSeasonId, $gameType, $goalModelId, $sogModelId, $officialId, $role, $payloads);
            $aggregatePayloads = $this->aggregatePayloads($summary, $payloads, 'official_sat_aggregate_profile');

            foreach (array_chunk($aggregatePayloads, 100) as $chunk) {
                DB::table('nhl_official_sat_aggregate_profile_buckets')->upsert(
                    $chunk,
                    [
                        'source_season_id',
                        'game_type',
                        'goal_expected_goals_model_id',
                        'shot_on_goal_expected_goals_model_id',
                        'nhl_official_id',
                        'role',
                        'aggregate_bucket_key',
                    ],
                    $this->aggregateUpdateColumns()
                );
            }

            $this->deleteStaleOfficialAggregateProfiles($sourceSeasonId, $gameType, $goalModelId, $sogModelId, $officialId, $role, $aggregatePayloads);

            return ['official_id' => $officialId, 'role' => $role, 'bucket_rows' => count($payloads), 'aggregate_rows' => count($aggregatePayloads)];
        });
    }

    /**
     * Build one staff member's SAT profile buckets for one team context.
     *
     * @return array{staff_id:int,role:string,team_context:string,bucket_rows:int,aggregate_rows:int}
     */
    public function buildStaff(
        string $sourceSeasonId,
        int $gameType,
        int $goalModelId,
        int $sogModelId,
        int $staffId,
        string $role,
        string $teamContext
    ): array {
        return DB::transaction(function () use ($sourceSeasonId, $gameType, $goalModelId, $sogModelId, $staffId, $role, $teamContext): array {
            $summary = $this->staffSummary($sourceSeasonId, $gameType, $goalModelId, $sogModelId, $staffId, $role, $teamContext);

            if ($summary === null || (int) $summary->source_sat < self::MIN_ENTITY_SAT) {
                $this->deleteExistingStaffProfiles($sourceSeasonId, $gameType, $goalModelId, $sogModelId, $staffId, $role, $teamContext);
                $this->deleteExistingStaffAggregateProfiles($sourceSeasonId, $gameType, $goalModelId, $sogModelId, $staffId, $role, $teamContext);

                return ['staff_id' => $staffId, 'role' => $role, 'team_context' => $teamContext, 'bucket_rows' => 0, 'aggregate_rows' => 0];
            }

            $rows = $this->staffBucketRows($sourceSeasonId, $gameType, $goalModelId, $sogModelId, $staffId, $role, $teamContext);
            $payloads = $this->staffPayloads($summary, $rows, $sourceSeasonId, $gameType, $goalModelId, $sogModelId, $staffId, $role, $teamContext);

            if ($payloads === []) {
                $this->deleteExistingStaffAggregateProfiles($sourceSeasonId, $gameType, $goalModelId, $sogModelId, $staffId, $role, $teamContext);

                return ['staff_id' => $staffId, 'role' => $role, 'team_context' => $teamContext, 'bucket_rows' => 0, 'aggregate_rows' => 0];
            }

            foreach (array_chunk($payloads, 100) as $chunk) {
                DB::table('nhl_staff_sat_profile_buckets')->upsert(
                    $chunk,
                    [
                        'source_season_id',
                        'game_type',
                        'goal_expected_goals_model_id',
                        'shot_on_goal_expected_goals_model_id',
                        'nhl_staff_id',
                        'role',
                        'team_context',
                        'matched_bucket_key',
                    ],
                    $this->updateColumns()
                );
            }

            $this->deleteStaleStaffProfiles($sourceSeasonId, $gameType, $goalModelId, $sogModelId, $staffId, $role, $teamContext, $payloads);
            $aggregatePayloads = $this->aggregatePayloads($summary, $payloads, 'staff_sat_aggregate_profile');

            foreach (array_chunk($aggregatePayloads, 100) as $chunk) {
                DB::table('nhl_staff_sat_aggregate_profile_buckets')->upsert(
                    $chunk,
                    [
                        'source_season_id',
                        'game_type',
                        'goal_expected_goals_model_id',
                        'shot_on_goal_expected_goals_model_id',
                        'nhl_staff_id',
                        'role',
                        'team_context',
                        'aggregate_bucket_key',
                    ],
                    $this->aggregateUpdateColumns()
                );
            }

            $this->deleteStaleStaffAggregateProfiles($sourceSeasonId, $gameType, $goalModelId, $sogModelId, $staffId, $role, $teamContext, $aggregatePayloads);

            return ['staff_id' => $staffId, 'role' => $role, 'team_context' => $teamContext, 'bucket_rows' => count($payloads), 'aggregate_rows' => count($aggregatePayloads)];
        });
    }

    /**
     * @return array{goal_model_id:int,sog_model_id:int}
     */
    private function latestModels(string $sourceSeasonId): array
    {
        $goalModel = $this->latestModel($sourceSeasonId, NhlExpectedGoalsBackfiller::TARGET_GOAL);
        $sogModel = $this->latestModel($sourceSeasonId, NhlExpectedGoalsBackfiller::TARGET_SHOT_ON_GOAL);

        if ($goalModel === null || $sogModel === null) {
            throw new RuntimeException('Build xG and xSOG models before building NHL game-context SAT profiles.');
        }

        return [
            'goal_model_id' => (int) $goalModel->id,
            'sog_model_id' => (int) $sogModel->id,
        ];
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
     * @return Collection<int, array{official_id:int,role:string}>
     */
    private function eligibleOfficialAssignments(string $sourceSeasonId, int $gameType, int $goalModelId, int $sogModelId): Collection
    {
        return DB::table('nhl_shot_attempts_facts as facts')
            ->join('nhl_games as games', 'games.nhl_game_id', '=', 'facts.nhl_game_id')
            ->join('nhl_game_officials as assignments', 'assignments.nhl_game_id', '=', 'facts.nhl_game_id')
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
            ->whereIn('assignments.role', [NhlGameOfficial::ROLE_REFEREE, NhlGameOfficial::ROLE_LINESMAN])
            ->groupBy('assignments.nhl_official_id', 'assignments.role')
            ->havingRaw('COUNT(*) >= ?', [self::MIN_ENTITY_SAT])
            ->orderBy('assignments.role')
            ->orderBy('assignments.nhl_official_id')
            ->get(['assignments.nhl_official_id as official_id', 'assignments.role'])
            ->map(static fn (object $row): array => [
                'official_id' => (int) $row->official_id,
                'role' => (string) $row->role,
            ]);
    }

    /**
     * @return Collection<int, array{staff_id:int,role:string,team_context:string}>
     */
    private function eligibleStaffAssignments(string $sourceSeasonId, int $gameType, int $goalModelId, int $sogModelId): Collection
    {
        return collect([self::STAFF_CONTEXT_OFFENSE, self::STAFF_CONTEXT_DEFENSE])
            ->flatMap(fn (string $teamContext): Collection => $this->eligibleStaffAssignmentsForContext($sourceSeasonId, $gameType, $goalModelId, $sogModelId, $teamContext))
            ->values();
    }

    /**
     * @return Collection<int, array{staff_id:int,role:string,team_context:string}>
     */
    private function eligibleStaffAssignmentsForContext(string $sourceSeasonId, int $gameType, int $goalModelId, int $sogModelId, string $teamContext): Collection
    {
        $teamIdColumn = $teamContext === self::STAFF_CONTEXT_OFFENSE ? 'facts.team_id' : 'facts.opponent_team_id';
        $teamSideSql = $this->teamSideSql($teamIdColumn);

        return DB::table('nhl_shot_attempts_facts as facts')
            ->join('nhl_games as games', 'games.nhl_game_id', '=', 'facts.nhl_game_id')
            ->join('nhl_game_team_staff as assignments', function ($join) use ($teamIdColumn, $teamSideSql): void {
                $join->on('assignments.nhl_game_id', '=', 'facts.nhl_game_id')
                    ->where('assignments.role', '=', NhlGameTeamStaff::ROLE_HEAD_COACH)
                    ->where(function ($query) use ($teamIdColumn, $teamSideSql): void {
                        $query->whereRaw('assignments.nhl_team_id = ' . $teamIdColumn)
                            ->orWhereRaw('assignments.team_side = ' . $teamSideSql);
                    });
            })
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
            ->groupBy('assignments.nhl_staff_id', 'assignments.role')
            ->havingRaw('COUNT(*) >= ?', [self::MIN_ENTITY_SAT])
            ->orderBy('assignments.role')
            ->orderBy('assignments.nhl_staff_id')
            ->get(['assignments.nhl_staff_id as staff_id', 'assignments.role'])
            ->map(static fn (object $row): array => [
                'staff_id' => (int) $row->staff_id,
                'role' => (string) $row->role,
                'team_context' => $teamContext,
            ]);
    }

    private function officialSummary(string $sourceSeasonId, int $gameType, int $goalModelId, int $sogModelId, int $officialId, string $role): ?object
    {
        $sql = $this->summarySql(
            'INNER JOIN nhl_game_officials assignments
                ON assignments.nhl_game_id = facts.nhl_game_id
                AND assignments.nhl_official_id = ?
                AND assignments.role = ?'
        );

        return DB::selectOne($sql, $this->baseBindings($goalModelId, $sogModelId, $sourceSeasonId, $gameType, [$officialId, $role]));
    }

    private function staffSummary(string $sourceSeasonId, int $gameType, int $goalModelId, int $sogModelId, int $staffId, string $role, string $teamContext): ?object
    {
        $teamIdColumn = $teamContext === self::STAFF_CONTEXT_OFFENSE ? 'facts.team_id' : 'facts.opponent_team_id';
        $sql = $this->summarySql($this->staffJoinSql($teamIdColumn));

        return DB::selectOne($sql, $this->baseBindings($goalModelId, $sogModelId, $sourceSeasonId, $gameType, [$staffId, $role]));
    }

    /**
     * @return Collection<int, object>
     */
    private function officialBucketRows(string $sourceSeasonId, int $gameType, int $goalModelId, int $sogModelId, int $officialId, string $role): Collection
    {
        $sql = $this->bucketRowsSql(
            'INNER JOIN nhl_game_officials assignments
                ON assignments.nhl_game_id = facts.nhl_game_id
                AND assignments.nhl_official_id = ?
                AND assignments.role = ?'
        );

        return collect(DB::select($sql, $this->baseBindings($goalModelId, $sogModelId, $sourceSeasonId, $gameType, [$officialId, $role])));
    }

    /**
     * @return Collection<int, object>
     */
    private function staffBucketRows(string $sourceSeasonId, int $gameType, int $goalModelId, int $sogModelId, int $staffId, string $role, string $teamContext): Collection
    {
        $teamIdColumn = $teamContext === self::STAFF_CONTEXT_OFFENSE ? 'facts.team_id' : 'facts.opponent_team_id';
        $sql = $this->bucketRowsSql($this->staffJoinSql($teamIdColumn));

        return collect(DB::select($sql, $this->baseBindings($goalModelId, $sogModelId, $sourceSeasonId, $gameType, [$staffId, $role])));
    }

    /**
     * @param array<int, mixed> $assignmentBindings
     * @return array<int, mixed>
     */
    private function baseBindings(int $goalModelId, int $sogModelId, string $sourceSeasonId, int $gameType, array $assignmentBindings): array
    {
        return [
            $goalModelId,
            NhlExpectedGoalsBackfiller::TARGET_GOAL,
            $sogModelId,
            NhlExpectedGoalsBackfiller::TARGET_SHOT_ON_GOAL,
            ...$assignmentBindings,
            $sourceSeasonId,
            $gameType,
        ];
    }

    private function summarySql(string $assignmentJoinSql): string
    {
        return <<<SQL
SELECT
    COUNT(DISTINCT facts.nhl_game_id)::decimal as source_games,
    COUNT(*) as source_sat,
    SUM(CASE WHEN facts.is_unblocked_attempt THEN 1 ELSE 0 END) as source_unblocked_sat,
    SUM(CASE WHEN facts.is_shot_on_goal THEN 1 ELSE 0 END) as source_sog,
    SUM(CASE WHEN facts.is_goal THEN 1 ELSE 0 END) as source_goals,
    ROUND(SUM(goal_predictions.xg)::numeric, 4) as source_xg,
    ROUND(SUM(sog_predictions.xg)::numeric, 4) as source_xsog
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
{$assignmentJoinSql}
WHERE facts.season_id = ?
    AND games.game_type = ?
SQL;
    }

    private function bucketRowsSql(string $assignmentJoinSql): string
    {
        $candidates = implode(",\n            ", $this->buckets->candidateBucketKeySql('scored_attempts'));

        return <<<SQL
WITH scored_attempts AS (
    SELECT
        facts.id,
        facts.is_unblocked_attempt,
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
    {$assignmentJoinSql}
    WHERE facts.season_id = ?
        AND games.game_type = ?
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
    bucket_key as matched_bucket_key,
    fallback_level,
    COUNT(*) as source_sat,
    SUM(CASE WHEN is_unblocked_attempt THEN 1 ELSE 0 END) as source_unblocked_sat,
    SUM(CASE WHEN is_shot_on_goal THEN 1 ELSE 0 END) as source_sog,
    SUM(CASE WHEN is_goal THEN 1 ELSE 0 END) as source_goals,
    ROUND(SUM(goal_xg)::numeric, 4) as source_xg,
    ROUND(SUM(sog_xg)::numeric, 4) as source_xsog,
    ROUND(AVG(goal_xg)::numeric, 6) as goal_probability,
    ROUND(AVG(sog_xg)::numeric, 6) as shot_on_goal_probability,
    ROUND(AVG(shot_distance)::numeric, 2) as avg_distance,
    ROUND(AVG(abs_shot_angle)::numeric, 2) as avg_angle
FROM candidate_attempts
GROUP BY bucket_key, fallback_level
ORDER BY fallback_level, source_sat DESC, source_xg DESC
SQL;
    }

    private function staffJoinSql(string $teamIdColumn): string
    {
        $teamSideSql = $this->teamSideSql($teamIdColumn);

        return <<<SQL
INNER JOIN nhl_game_team_staff assignments
    ON assignments.nhl_game_id = facts.nhl_game_id
    AND assignments.nhl_staff_id = ?
    AND assignments.role = ?
    AND (
        assignments.nhl_team_id = {$teamIdColumn}
        OR assignments.team_side = {$teamSideSql}
    )
SQL;
    }

    private function teamSideSql(string $teamIdColumn): string
    {
        return "CASE
            WHEN {$teamIdColumn} = games.home_team_id THEN 'home'
            WHEN {$teamIdColumn} = games.away_team_id THEN 'away'
            ELSE NULL
        END";
    }

    /**
     * @param Collection<int, object> $rows
     * @return array<int, array<string,mixed>>
     */
    private function officialPayloads(object $summary, Collection $rows, string $sourceSeasonId, int $gameType, int $goalModelId, int $sogModelId, int $officialId, string $role): array
    {
        return $this->payloads(
            summary: $summary,
            rows: $rows,
            base: [
                'source_season_id' => $sourceSeasonId,
                'game_type' => $gameType,
                'goal_expected_goals_model_id' => $goalModelId,
                'shot_on_goal_expected_goals_model_id' => $sogModelId,
                'nhl_official_id' => $officialId,
                'role' => $role,
            ],
            builderName: 'NhlGameContextSatProfileBuilder',
            method: 'official_sat_profile_with_empirical_bayes_shrinkage'
        );
    }

    /**
     * @param Collection<int, object> $rows
     * @return array<int, array<string,mixed>>
     */
    private function staffPayloads(object $summary, Collection $rows, string $sourceSeasonId, int $gameType, int $goalModelId, int $sogModelId, int $staffId, string $role, string $teamContext): array
    {
        return $this->payloads(
            summary: $summary,
            rows: $rows,
            base: [
                'source_season_id' => $sourceSeasonId,
                'game_type' => $gameType,
                'goal_expected_goals_model_id' => $goalModelId,
                'shot_on_goal_expected_goals_model_id' => $sogModelId,
                'nhl_staff_id' => $staffId,
                'role' => $role,
                'team_context' => $teamContext,
            ],
            builderName: 'NhlGameContextSatProfileBuilder',
            method: 'staff_sat_profile_with_empirical_bayes_shrinkage'
        );
    }

    /**
     * @param Collection<int, object> $rows
     * @param array<string,mixed> $base
     * @return array<int, array<string,mixed>>
     */
    private function payloads(object $summary, Collection $rows, array $base, string $builderName, string $method): array
    {
        $sourceSat = max(1, (int) $summary->source_sat);
        $rowMap = $rows->keyBy(fn (object $row): string => (string) $row->matched_bucket_key);
        $now = now();

        return $rows
            ->filter(fn (object $row): bool => (int) $row->fallback_level === 1)
            ->map(function (object $row) use ($base, $summary, $sourceSat, $rowMap, $builderName, $method, $now): array {
                $bucketSat = (int) $row->source_sat;
                $dimensions = $this->bucketDimensions((string) $row->matched_bucket_key);
                $shrinkage = $this->shrinkageRates($row, $rowMap);

                return array_merge($base, [
                    'matched_bucket_key' => (string) $row->matched_bucket_key,
                    'fallback_level' => (int) $row->fallback_level,
                    'bucket_dimensions' => json_encode($dimensions, JSON_THROW_ON_ERROR),
                    'shot_type_group' => $dimensions['shot_type_group'] ?? null,
                    'distance_group' => $dimensions['distance_group'] ?? null,
                    'angle_group' => $dimensions['angle_group'] ?? null,
                    'sequence_group' => $dimensions['sequence_group'] ?? null,
                    'source_games' => $summary->source_games,
                    'source_sat' => $bucketSat,
                    'source_unblocked_sat' => (int) $row->source_unblocked_sat,
                    'source_sog' => (int) $row->source_sog,
                    'source_goals' => (int) $row->source_goals,
                    'source_xg' => $row->source_xg,
                    'source_xsog' => $row->source_xsog,
                    'source_profile_share' => round($bucketSat / $sourceSat, 6),
                    'goal_probability' => $shrinkage['goal_probability'],
                    'shot_on_goal_probability' => $shrinkage['shot_on_goal_probability'],
                    'prior_bucket_key' => $shrinkage['prior_bucket_key'],
                    'prior_fallback_level' => $shrinkage['prior_fallback_level'],
                    'prior_sat' => $shrinkage['prior_sat'],
                    'prior_weight_sat' => $shrinkage['prior_weight_sat'],
                    'shrinkage_weight' => $shrinkage['shrinkage_weight'],
                    'confidence_score' => $shrinkage['confidence_score'],
                    'confidence_bucket' => $this->confidenceBucket($shrinkage['confidence_score']),
                    'profile_inputs' => json_encode([
                        'method' => $method,
                        'minimum_entity_sat' => self::MIN_ENTITY_SAT,
                        'source_total_sat' => $sourceSat,
                    ], JSON_THROW_ON_ERROR),
                    'flags' => json_encode($this->flags($shrinkage), JSON_THROW_ON_ERROR),
                    'metadata' => json_encode([
                        'builder' => $builderName,
                        'shrinkage' => $shrinkage,
                        'avg_distance' => $row->avg_distance === null ? null : round((float) $row->avg_distance, 2),
                        'avg_angle' => $row->avg_angle === null ? null : round((float) $row->avg_angle, 2),
                    ], JSON_THROW_ON_ERROR),
                    'profiled_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            })->values()->all();
    }

    /**
     * @param array<int, array<string,mixed>> $exactPayloads
     * @return array<int, array<string,mixed>>
     */
    private function aggregatePayloads(object $summary, array $exactPayloads, string $method): array
    {
        $selectedGroups = $this->selectedAggregateGroups(collect($exactPayloads));
        $sourceSat = max(1, (int) $summary->source_sat);
        $now = now();

        return $selectedGroups
            ->map(function (Collection $rows) use ($exactPayloads, $method, $sourceSat, $summary, $now): array {
                $first = $rows->first();
                $bucketSat = max(1, (int) $rows->sum('source_sat'));
                $aggregateDimensions = $this->aggregateDimensions($rows);
                $confidenceScore = $this->weightedAverage($rows, 'confidence_score', $bucketSat);
                $shrinkageWeight = $this->weightedAverage($rows, 'shrinkage_weight', $bucketSat);
                $avgDistance = $this->weightedMetadataAverage($rows, 'avg_distance');
                $avgAngle = $this->weightedMetadataAverage($rows, 'avg_angle');
                $includedBucketKeys = $rows
                    ->pluck('matched_bucket_key')
                    ->filter()
                    ->values()
                    ->all();

                return array_merge($this->aggregateBase($first), [
                    'aggregate_bucket_key' => $this->aggregateBucketKey($aggregateDimensions),
                    'aggregate_level' => (int) $aggregateDimensions['aggregate_level'],
                    'aggregate_label' => $this->aggregateLabel($aggregateDimensions),
                    'aggregate_dimensions' => json_encode($aggregateDimensions, JSON_THROW_ON_ERROR),
                    'source_games' => $summary->source_games,
                    'source_sat' => $bucketSat,
                    'source_unblocked_sat' => (int) $rows->sum('source_unblocked_sat'),
                    'source_sog' => (int) $rows->sum('source_sog'),
                    'source_goals' => (int) $rows->sum('source_goals'),
                    'source_xg' => round((float) $rows->sum('source_xg'), 4),
                    'source_xsog' => round((float) $rows->sum('source_xsog'), 4),
                    'source_profile_share' => round($bucketSat / $sourceSat, 6),
                    'goal_probability' => $this->weightedAverage($rows, 'goal_probability', $bucketSat),
                    'shot_on_goal_probability' => $this->weightedAverage($rows, 'shot_on_goal_probability', $bucketSat),
                    'confidence_score' => $confidenceScore,
                    'confidence_bucket' => $this->confidenceBucket($confidenceScore),
                    'shrinkage_weight' => $shrinkageWeight,
                    'included_bucket_count' => count($includedBucketKeys),
                    'included_bucket_keys' => json_encode($includedBucketKeys, JSON_THROW_ON_ERROR),
                    'profile_inputs' => json_encode([
                        'method' => $method,
                        'minimum_entity_sat' => self::MIN_ENTITY_SAT,
                        'minimum_aggregate_bucket_sat' => self::MIN_AGGREGATE_BUCKET_SAT,
                        'target_aggregate_bucket_min' => self::MIN_AGGREGATE_BUCKETS,
                        'target_aggregate_bucket_max' => self::MAX_AGGREGATE_BUCKETS,
                        'source_total_sat' => $sourceSat,
                    ], JSON_THROW_ON_ERROR),
                    'flags' => json_encode($this->aggregateFlags(count($includedBucketKeys), $shrinkageWeight), JSON_THROW_ON_ERROR),
                    'metadata' => json_encode([
                        'builder' => 'NhlGameContextSatProfileBuilder',
                        'exact_bucket_count' => count($exactPayloads),
                        'included_bucket_keys' => $includedBucketKeys,
                        'avg_distance' => $avgDistance,
                        'avg_angle' => $avgAngle,
                    ], JSON_THROW_ON_ERROR),
                    'profiled_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            })
            ->values()
            ->all();
    }

    /**
     * @param Collection<int, array<string,mixed>> $rows
     * @return Collection<int, Collection<int, array<string,mixed>>>
     */
    private function selectedAggregateGroups(Collection $rows): Collection
    {
        $fallback = collect();

        foreach ($this->aggregateLevelDefinitions() as $level => $columns) {
            $groups = $rows
                ->groupBy(fn (array $row): string => $this->aggregateGroupKey($row, (int) $level, $columns))
                ->map(fn (Collection $group): Collection => $group->values())
                ->filter(fn (Collection $group): bool => (int) $group->sum('source_sat') >= self::MIN_AGGREGATE_BUCKET_SAT)
                ->sortByDesc(fn (Collection $group): int => (int) $group->sum('source_sat'))
                ->values();

            if ($groups->count() >= self::MIN_AGGREGATE_BUCKETS && $groups->count() <= self::MAX_AGGREGATE_BUCKETS) {
                return $groups;
            }

            if ($groups->count() > 0 && $groups->count() <= self::MAX_AGGREGATE_BUCKETS && $fallback->isEmpty()) {
                $fallback = $groups;
            }
        }

        return $fallback;
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function aggregateLevelDefinitions(): array
    {
        return [
            1 => ['shot_type_group', 'distance_group', 'angle_group', 'sequence_group'],
            2 => ['shot_type_group', 'distance_zone', 'angle_group'],
            3 => ['shot_type_group', 'distance_zone'],
            4 => ['distance_zone', 'angle_group'],
            5 => ['shot_type_group'],
            6 => ['distance_zone'],
            99 => ['all'],
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @param array<int, string> $columns
     */
    private function aggregateGroupKey(array $row, int $aggregateLevel, array $columns): string
    {
        return $this->aggregateBucketKey($this->aggregateDimensionsForRow($row, $aggregateLevel, $columns));
    }

    /**
     * @param array<string,mixed> $row
     * @param array<int, string> $columns
     * @return array<string, string|int>
     */
    private function aggregateDimensionsForRow(array $row, int $aggregateLevel, array $columns): array
    {
        $dimensions = ['aggregate_level' => $aggregateLevel];

        foreach ($columns as $column) {
            if ($column === 'all') {
                $dimensions['scope'] = 'all_attempts';
                continue;
            }

            $dimensions[$column] = $column === 'distance_zone'
                ? $this->distanceZone((string) ($row['distance_group'] ?? 'unknown'))
                : (string) ($row[$column] ?? 'unknown');
        }

        return $dimensions;
    }

    /**
     * @param Collection<int, array<string,mixed>> $rows
     * @return array<string, string|int>
     */
    private function aggregateDimensions(Collection $rows): array
    {
        $first = $rows->first();

        foreach ($this->aggregateLevelDefinitions() as $level => $columns) {
            $dimensions = $this->aggregateDimensionsForRow($first, (int) $level, $columns);
            $key = $this->aggregateBucketKey($dimensions);

            if ($rows->every(fn (array $row): bool => $this->aggregateGroupKey($row, (int) $level, $columns) === $key)) {
                return $dimensions;
            }
        }

        return ['aggregate_level' => 99, 'scope' => 'all_attempts'];
    }

    private function distanceZone(string $distanceGroup): string
    {
        return match ($distanceGroup) {
            'slot' => 'slot',
            'mid_range', 'long', 'point_or_high' => 'outside_slot',
            default => $distanceGroup,
        };
    }

    /**
     * @param array<string, string|int> $dimensions
     */
    private function aggregateBucketKey(array $dimensions): string
    {
        $level = (int) ($dimensions['aggregate_level'] ?? 99);
        $parts = ['A' . str_pad((string) $level, 2, '0', STR_PAD_LEFT)];

        foreach ($dimensions as $key => $value) {
            if ($key === 'aggregate_level') {
                continue;
            }

            $parts[] = $key . '=' . $value;
        }

        return implode('|', $parts);
    }

    /**
     * @param array<string, string|int> $dimensions
     */
    private function aggregateLabel(array $dimensions): string
    {
        if (($dimensions['scope'] ?? null) === 'all_attempts') {
            return 'All attempts';
        }

        $parts = [];

        if (isset($dimensions['shot_type_group'])) {
            $parts[] = $this->shotTypeLabel((string) $dimensions['shot_type_group']);
        }

        if (isset($dimensions['distance_zone'])) {
            $parts[] = $this->distanceZoneLabel((string) $dimensions['distance_zone']);
        } elseif (isset($dimensions['distance_group'])) {
            $parts[] = $this->distanceGroupLabel((string) $dimensions['distance_group']);
        }

        if (isset($dimensions['angle_group'])) {
            $parts[] = $this->angleLabel((string) $dimensions['angle_group']);
        }

        if (isset($dimensions['sequence_group'])) {
            $parts[] = $this->sequenceLabel((string) $dimensions['sequence_group']);
        }

        return trim(implode(' ', array_filter($parts)));
    }

    private function shotTypeLabel(string $shotType): string
    {
        return match ($shotType) {
            'tip' => 'Tips',
            'wrist' => 'Wrist shots',
            'snap' => 'Snap shots',
            'slap' => 'Slap shots',
            'backhand' => 'Backhands',
            default => ucfirst(str_replace('_', ' ', $shotType)),
        };
    }

    private function distanceZoneLabel(string $distanceZone): string
    {
        return match ($distanceZone) {
            'slot' => 'from the slot',
            'outside_slot' => 'outside the slot',
            default => 'from ' . str_replace('_', ' ', $distanceZone),
        };
    }

    private function distanceGroupLabel(string $distanceGroup): string
    {
        return match ($distanceGroup) {
            'point_or_high' => 'from the point/high',
            'mid_range' => 'from mid range',
            'long' => 'from long range',
            'slot' => 'from the slot',
            default => 'from ' . str_replace('_', ' ', $distanceGroup),
        };
    }

    private function angleLabel(string $angleGroup): string
    {
        return match ($angleGroup) {
            'inside_lane' => 'inside lane',
            'central' => 'central',
            'sharp' => 'sharp angle',
            default => str_replace('_', ' ', $angleGroup),
        };
    }

    private function sequenceLabel(string $sequenceGroup): string
    {
        return match ($sequenceGroup) {
            'settled' => 'settled',
            'rush' => 'rush',
            'rebound' => 'rebound',
            default => str_replace('_', ' ', $sequenceGroup),
        };
    }

    /**
     * @param array<string,mixed> $first
     * @return array<string,mixed>
     */
    private function aggregateBase(array $first): array
    {
        $base = [
            'source_season_id' => $first['source_season_id'],
            'game_type' => $first['game_type'],
            'goal_expected_goals_model_id' => $first['goal_expected_goals_model_id'],
            'shot_on_goal_expected_goals_model_id' => $first['shot_on_goal_expected_goals_model_id'],
            'role' => $first['role'],
        ];

        if (isset($first['nhl_official_id'])) {
            $base['nhl_official_id'] = $first['nhl_official_id'];
        }

        if (isset($first['nhl_staff_id'])) {
            $base['nhl_staff_id'] = $first['nhl_staff_id'];
            $base['team_context'] = $first['team_context'];
        }

        return $base;
    }

    /**
     * @param Collection<int, array<string,mixed>> $rows
     */
    private function weightedAverage(Collection $rows, string $column, int $bucketSat): float
    {
        $weightedSum = $rows->sum(fn (array $row): float => (float) ($row[$column] ?? 0) * (int) ($row['source_sat'] ?? 0));

        return round($weightedSum / max(1, $bucketSat), 6);
    }

    /**
     * @param Collection<int, array<string,mixed>> $rows
     */
    private function weightedMetadataAverage(Collection $rows, string $key): ?float
    {
        $weightedSum = 0.0;
        $weightedSat = 0;

        foreach ($rows as $row) {
            $metadata = json_decode((string) ($row['metadata'] ?? '{}'), true);
            $value = is_array($metadata) ? ($metadata[$key] ?? null) : null;

            if ($value === null) {
                continue;
            }

            $sourceSat = (int) ($row['source_sat'] ?? 0);
            $weightedSum += (float) $value * $sourceSat;
            $weightedSat += $sourceSat;
        }

        if ($weightedSat === 0) {
            return null;
        }

        return round($weightedSum / $weightedSat, 2);
    }

    /**
     * @return array<int, string>
     */
    private function aggregateFlags(int $includedBucketCount, float $shrinkageWeight): array
    {
        $flags = [];

        if ($includedBucketCount > 1) {
            $flags[] = 'merged_context_sat_buckets';
        }

        if ($shrinkageWeight > 0.25) {
            $flags[] = 'aggregate_contains_shrunk_bucket_rates';
        }

        return $flags;
    }

    private function deleteExistingOfficialProfiles(string $sourceSeasonId, int $gameType, int $goalModelId, int $sogModelId, int $officialId, string $role): void
    {
        DB::table('nhl_official_sat_profile_buckets')
            ->where('source_season_id', $sourceSeasonId)
            ->where('game_type', $gameType)
            ->where('goal_expected_goals_model_id', $goalModelId)
            ->where('shot_on_goal_expected_goals_model_id', $sogModelId)
            ->where('nhl_official_id', $officialId)
            ->where('role', $role)
            ->delete();
    }

    private function deleteExistingOfficialAggregateProfiles(string $sourceSeasonId, int $gameType, int $goalModelId, int $sogModelId, int $officialId, string $role): void
    {
        DB::table('nhl_official_sat_aggregate_profile_buckets')
            ->where('source_season_id', $sourceSeasonId)
            ->where('game_type', $gameType)
            ->where('goal_expected_goals_model_id', $goalModelId)
            ->where('shot_on_goal_expected_goals_model_id', $sogModelId)
            ->where('nhl_official_id', $officialId)
            ->where('role', $role)
            ->delete();
    }

    /**
     * @param array<int, array<string,mixed>> $payloads
     */
    private function deleteStaleOfficialProfiles(string $sourceSeasonId, int $gameType, int $goalModelId, int $sogModelId, int $officialId, string $role, array $payloads): void
    {
        DB::table('nhl_official_sat_profile_buckets')
            ->where('source_season_id', $sourceSeasonId)
            ->where('game_type', $gameType)
            ->where('goal_expected_goals_model_id', $goalModelId)
            ->where('shot_on_goal_expected_goals_model_id', $sogModelId)
            ->where('nhl_official_id', $officialId)
            ->where('role', $role)
            ->whereNotIn('matched_bucket_key', array_column($payloads, 'matched_bucket_key'))
            ->delete();
    }

    /**
     * @param array<int, array<string,mixed>> $payloads
     */
    private function deleteStaleOfficialAggregateProfiles(string $sourceSeasonId, int $gameType, int $goalModelId, int $sogModelId, int $officialId, string $role, array $payloads): void
    {
        $query = DB::table('nhl_official_sat_aggregate_profile_buckets')
            ->where('source_season_id', $sourceSeasonId)
            ->where('game_type', $gameType)
            ->where('goal_expected_goals_model_id', $goalModelId)
            ->where('shot_on_goal_expected_goals_model_id', $sogModelId)
            ->where('nhl_official_id', $officialId)
            ->where('role', $role);

        if ($payloads === []) {
            $query->delete();

            return;
        }

        $query->whereNotIn('aggregate_bucket_key', array_column($payloads, 'aggregate_bucket_key'))
            ->delete();
    }

    private function deleteExistingStaffProfiles(string $sourceSeasonId, int $gameType, int $goalModelId, int $sogModelId, int $staffId, string $role, string $teamContext): void
    {
        DB::table('nhl_staff_sat_profile_buckets')
            ->where('source_season_id', $sourceSeasonId)
            ->where('game_type', $gameType)
            ->where('goal_expected_goals_model_id', $goalModelId)
            ->where('shot_on_goal_expected_goals_model_id', $sogModelId)
            ->where('nhl_staff_id', $staffId)
            ->where('role', $role)
            ->where('team_context', $teamContext)
            ->delete();
    }

    private function deleteExistingStaffAggregateProfiles(string $sourceSeasonId, int $gameType, int $goalModelId, int $sogModelId, int $staffId, string $role, string $teamContext): void
    {
        DB::table('nhl_staff_sat_aggregate_profile_buckets')
            ->where('source_season_id', $sourceSeasonId)
            ->where('game_type', $gameType)
            ->where('goal_expected_goals_model_id', $goalModelId)
            ->where('shot_on_goal_expected_goals_model_id', $sogModelId)
            ->where('nhl_staff_id', $staffId)
            ->where('role', $role)
            ->where('team_context', $teamContext)
            ->delete();
    }

    /**
     * @param array<int, array<string,mixed>> $payloads
     */
    private function deleteStaleStaffProfiles(string $sourceSeasonId, int $gameType, int $goalModelId, int $sogModelId, int $staffId, string $role, string $teamContext, array $payloads): void
    {
        DB::table('nhl_staff_sat_profile_buckets')
            ->where('source_season_id', $sourceSeasonId)
            ->where('game_type', $gameType)
            ->where('goal_expected_goals_model_id', $goalModelId)
            ->where('shot_on_goal_expected_goals_model_id', $sogModelId)
            ->where('nhl_staff_id', $staffId)
            ->where('role', $role)
            ->where('team_context', $teamContext)
            ->whereNotIn('matched_bucket_key', array_column($payloads, 'matched_bucket_key'))
            ->delete();
    }

    /**
     * @param array<int, array<string,mixed>> $payloads
     */
    private function deleteStaleStaffAggregateProfiles(string $sourceSeasonId, int $gameType, int $goalModelId, int $sogModelId, int $staffId, string $role, string $teamContext, array $payloads): void
    {
        $query = DB::table('nhl_staff_sat_aggregate_profile_buckets')
            ->where('source_season_id', $sourceSeasonId)
            ->where('game_type', $gameType)
            ->where('goal_expected_goals_model_id', $goalModelId)
            ->where('shot_on_goal_expected_goals_model_id', $sogModelId)
            ->where('nhl_staff_id', $staffId)
            ->where('role', $role)
            ->where('team_context', $teamContext);

        if ($payloads === []) {
            $query->delete();

            return;
        }

        $query->whereNotIn('aggregate_bucket_key', array_column($payloads, 'aggregate_bucket_key'))
            ->delete();
    }

    /**
     * @return array<int, string>
     */
    private function updateColumns(): array
    {
        return [
            'fallback_level',
            'bucket_dimensions',
            'shot_type_group',
            'distance_group',
            'angle_group',
            'sequence_group',
            'source_games',
            'source_sat',
            'source_unblocked_sat',
            'source_sog',
            'source_goals',
            'source_xg',
            'source_xsog',
            'source_profile_share',
            'goal_probability',
            'shot_on_goal_probability',
            'prior_bucket_key',
            'prior_fallback_level',
            'prior_sat',
            'prior_weight_sat',
            'shrinkage_weight',
            'confidence_score',
            'confidence_bucket',
            'profile_inputs',
            'flags',
            'metadata',
            'profiled_at',
            'updated_at',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function aggregateUpdateColumns(): array
    {
        return [
            'aggregate_level',
            'aggregate_label',
            'aggregate_dimensions',
            'source_games',
            'source_sat',
            'source_unblocked_sat',
            'source_sog',
            'source_goals',
            'source_xg',
            'source_xsog',
            'source_profile_share',
            'goal_probability',
            'shot_on_goal_probability',
            'confidence_score',
            'confidence_bucket',
            'shrinkage_weight',
            'included_bucket_count',
            'included_bucket_keys',
            'profile_inputs',
            'flags',
            'metadata',
            'profiled_at',
            'updated_at',
        ];
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
        $bucketSat = max(1, (int) $row->source_sat);
        $rawGoalProbability = (float) $row->source_xg / $bucketSat;
        $rawShotOnGoalProbability = (float) $row->source_xsog / $bucketSat;
        $rawActualGoalRate = (int) $row->source_goals / $bucketSat;
        $rawActualShotOnGoalRate = (int) $row->source_sog / $bucketSat;
        $prior = $this->priorRow((string) $row->matched_bucket_key, $rowMap, $bucketSat);

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
                'prior_sat' => 0,
                'prior_weight_sat' => 0,
                'shrinkage_weight' => 0.0,
            ];
        }

        $priorSat = max(1, (int) $prior->source_sat);
        $priorWeight = max(0, $priorSat - $bucketSat);
        $priorGoalProbability = ((float) $prior->source_xg - (float) $row->source_xg) / max(1, $priorWeight);
        $priorShotOnGoalProbability = ((float) $prior->source_xsog - (float) $row->source_xsog) / max(1, $priorWeight);
        $confidenceScore = $bucketSat / ($bucketSat + $priorWeight);

        return [
            'goal_probability' => round((($bucketSat * $rawGoalProbability) + ($priorWeight * $priorGoalProbability)) / max(1, $bucketSat + $priorWeight), 6),
            'shot_on_goal_probability' => round((($bucketSat * $rawShotOnGoalProbability) + ($priorWeight * $priorShotOnGoalProbability)) / max(1, $bucketSat + $priorWeight), 6),
            'confidence_score' => round($confidenceScore, 4),
            'raw_goal_probability' => round($rawGoalProbability, 6),
            'raw_shot_on_goal_probability' => round($rawShotOnGoalProbability, 6),
            'raw_actual_goal_rate' => round($rawActualGoalRate, 6),
            'raw_actual_shot_on_goal_rate' => round($rawActualShotOnGoalRate, 6),
            'prior_bucket_key' => (string) $prior->matched_bucket_key,
            'prior_fallback_level' => (int) $prior->fallback_level,
            'prior_sat' => $priorSat,
            'prior_weight_sat' => $priorWeight,
            'prior_goal_probability' => round($priorGoalProbability, 6),
            'prior_shot_on_goal_probability' => round($priorShotOnGoalProbability, 6),
            'shrinkage_weight' => round(1 - $confidenceScore, 4),
        ];
    }

    /**
     * @param Collection<string, object> $rowMap
     */
    private function priorRow(string $bucketKey, Collection $rowMap, int $bucketSat): ?object
    {
        foreach ($this->parentBucketKeys($bucketKey) as $parentBucketKey) {
            $parent = $rowMap->get($parentBucketKey);

            if ($parent !== null && (int) $parent->source_sat > $bucketSat) {
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
        return match (true) {
            $confidenceScore >= 0.8 => 'high',
            $confidenceScore >= 0.5 => 'medium',
            default => 'low',
        };
    }

    /**
     * @param array<string, mixed> $shrinkage
     * @return array<int, string>
     */
    private function flags(array $shrinkage): array
    {
        $flags = [];

        if (($shrinkage['confidence_score'] ?? 1.0) < 0.5) {
            $flags[] = 'limited_context_bucket_sat';
        }

        if (($shrinkage['shrinkage_weight'] ?? 0.0) > 0.5) {
            $flags[] = 'high_context_bucket_shrinkage';
        }

        return $flags;
    }
}
