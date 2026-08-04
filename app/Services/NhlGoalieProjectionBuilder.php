<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Builds goalie projections from workload, projected team defensive buckets, and goalie bucket skill.
 */
class NhlGoalieProjectionBuilder
{
    public const DEFAULT_VERSION_PREFIX = 'first_pass_goalie';

    private const REGULAR_SEASON_GAME_TYPE = 2;
    private const BUCKET_COVERAGE_TARGET = 0.95;
    private const SKATERS_ON_ICE_NORMALIZER = 5.0;
    private const TOP_FORWARD_COUNT = 12;
    private const TOP_DEFENSE_COUNT = 7;
    private const STRENGTH_EV = 'ev';
    private const STRENGTH_PK = 'pk';
    private const FACT_STRENGTH_PP = 'pp';
    private const PK_GOALIE_SKILL_SHRINK = 0.25;
    private const BROAD_GA_ADJUSTMENT_PRIOR_SOGA = 1200.0;
    private const BROAD_GA_ADJUSTMENT_CAP_PER_60 = 0.45;
    private const RELEVANT_GOALIE_MIN_PROJECTED_GAMES = 5.0;

    public function __construct(private readonly NhlShotAttemptAnalysisBuckets $buckets)
    {
    }

    public function defaultVersion(string $targetSeasonId): string
    {
        return self::DEFAULT_VERSION_PREFIX . '_' . $targetSeasonId . '_v1';
    }

    /**
     * Prepare one goalie projection version and return goalies to queue.
     *
     * @return array{projection_version:string,source_season_id:string,target_season_id:string,goalie_workload_projection_version:string,toi_projection_version:string,goalie_player_ids:array<int,int>}
     */
    public function prepareBuild(
        string $sourceSeasonId,
        string $targetSeasonId,
        string $goalieWorkloadProjectionVersion,
        string $toiProjectionVersion,
        ?string $version = null
    ): array {
        $version = $version ?: $this->defaultVersion($targetSeasonId);
        $goaliePlayerIds = $this->eligibleGoalieIds($targetSeasonId, $goalieWorkloadProjectionVersion);
        $goalModel = $this->latestModel($sourceSeasonId, NhlExpectedGoalsBackfiller::TARGET_GOAL);
        $sogModel = $this->latestModel($sourceSeasonId, NhlExpectedGoalsBackfiller::TARGET_SHOT_ON_GOAL);

        if ($goalModel === null || $sogModel === null) {
            throw new RuntimeException('Build xG and xSOG models before building goalie projections.');
        }

        $this->assertGoalieProfileCoverage(
            $sourceSeasonId,
            $targetSeasonId,
            $goalieWorkloadProjectionVersion,
            (int) $goalModel->id,
            (int) $sogModel->id
        );

        return [
            'projection_version' => $version,
            'source_season_id' => $sourceSeasonId,
            'target_season_id' => $targetSeasonId,
            'goalie_workload_projection_version' => $goalieWorkloadProjectionVersion,
            'toi_projection_version' => $toiProjectionVersion,
            'goalie_player_ids' => $goaliePlayerIds->all(),
        ];
    }

    /**
     * Build one goalie's projection row and bucket rows.
     *
     * @return array{goalie_player_id:int,season_rows:int,bucket_rows:int}
     */
    public function buildGoalie(
        string $sourceSeasonId,
        string $targetSeasonId,
        string $goalieWorkloadProjectionVersion,
        string $toiProjectionVersion,
        string $version,
        int $goaliePlayerId
    ): array {
        return DB::transaction(function () use (
            $sourceSeasonId,
            $targetSeasonId,
            $goalieWorkloadProjectionVersion,
            $toiProjectionVersion,
            $version,
            $goaliePlayerId
        ): array {
            $workload = $this->workloadRow($targetSeasonId, $goalieWorkloadProjectionVersion, $goaliePlayerId);

            if ($workload === null || $workload->target_team_abbrev === null || $workload->target_team_abbrev === '') {
                return ['goalie_player_id' => $goaliePlayerId, 'season_rows' => 0, 'bucket_rows' => 0];
            }

            $goalModel = $this->latestModel($sourceSeasonId, NhlExpectedGoalsBackfiller::TARGET_GOAL);
            $sogModel = $this->latestModel($sourceSeasonId, NhlExpectedGoalsBackfiller::TARGET_SHOT_ON_GOAL);

            if ($goalModel === null || $sogModel === null) {
                return ['goalie_player_id' => $goaliePlayerId, 'season_rows' => 0, 'bucket_rows' => 0];
            }

            $teamBuckets = $this->retainedTeamDefenseBuckets(
                $sourceSeasonId,
                $targetSeasonId,
                $toiProjectionVersion,
                (string) $workload->target_team_abbrev,
                (int) $goalModel->id,
                (int) $sogModel->id
            );

            if ($teamBuckets->isEmpty()) {
                return ['goalie_player_id' => $goaliePlayerId, 'season_rows' => 0, 'bucket_rows' => 0];
            }

            $goalieWorkloadShare = $this->goalieWorkloadShare(
                $targetSeasonId,
                $goalieWorkloadProjectionVersion,
                $workload
            );

            $teamBuckets = $this->scaleBucketsForGoalieWorkload($teamBuckets, $goalieWorkloadShare);

            if ($teamBuckets->isEmpty()) {
                return ['goalie_player_id' => $goaliePlayerId, 'season_rows' => 0, 'bucket_rows' => 0];
            }

            $goalieBuckets = $this->goalieSkillBuckets($sourceSeasonId, $goaliePlayerId, (int) $goalModel->id, (int) $sogModel->id);

            if ($goalieBuckets->isEmpty()) {
                throw new RuntimeException(sprintf(
                    'Missing G SAT profile rows for goalie %d in %s. Build G SAT Profiles before G Projections.',
                    $goaliePlayerId,
                    $sourceSeasonId
                ));
            }

            $bucketPayloads = $this->bucketPayloads(
                $teamBuckets,
                $goalieBuckets,
                $workload,
                $sourceSeasonId,
                $targetSeasonId,
                $version,
                $goalieWorkloadShare
            );
            $seasonPayload = $this->seasonPayload(
                $bucketPayloads,
                $workload,
                $sourceSeasonId,
                $targetSeasonId,
                $goalieWorkloadProjectionVersion,
                $toiProjectionVersion,
                $version,
                $goalieWorkloadShare
            );

            DB::table('nhl_goalie_season_projections')->upsert(
                [$seasonPayload],
                ['projection_version', 'target_season_id', 'goalie_player_id'],
                $this->seasonUpdateColumns()
            );

            $projectionId = (int) DB::table('nhl_goalie_season_projections')
                ->where('projection_version', $version)
                ->where('target_season_id', $targetSeasonId)
                ->where('goalie_player_id', $goaliePlayerId)
                ->value('id');

            DB::table('nhl_goalie_projection_chance_buckets')
                ->where('projection_version', $version)
                ->where('target_season_id', $targetSeasonId)
                ->where('goalie_player_id', $goaliePlayerId)
                ->delete();

            $bucketPayloads = array_map(
                static fn (array $payload): array => $payload + ['goalie_season_projection_id' => $projectionId],
                $bucketPayloads
            );

            foreach (array_chunk($bucketPayloads, 100) as $chunk) {
                DB::table('nhl_goalie_projection_chance_buckets')->insert($chunk);
            }

            return ['goalie_player_id' => $goaliePlayerId, 'season_rows' => 1, 'bucket_rows' => count($bucketPayloads)];
        });
    }

    /**
     * @return Collection<int, int>
     */
    private function eligibleGoalieIds(string $targetSeasonId, string $goalieWorkloadProjectionVersion): Collection
    {
        return DB::table('nhl_goalie_workload_projections')
            ->where('target_season_id', $targetSeasonId)
            ->where('projection_version', $goalieWorkloadProjectionVersion)
            ->whereNotNull('target_team_abbrev')
            ->orderBy('goalie_player_id')
            ->pluck('goalie_player_id')
            ->map(fn (mixed $goaliePlayerId): int => (int) $goaliePlayerId);
    }

    private function workloadRow(string $targetSeasonId, string $goalieWorkloadProjectionVersion, int $goaliePlayerId): ?object
    {
        return DB::table('nhl_goalie_workload_projections')
            ->where('target_season_id', $targetSeasonId)
            ->where('projection_version', $goalieWorkloadProjectionVersion)
            ->where('goalie_player_id', $goaliePlayerId)
            ->first();
    }

    private function assertGoalieProfileCoverage(
        string $sourceSeasonId,
        string $targetSeasonId,
        string $goalieWorkloadProjectionVersion,
        int $goalModelId,
        int $sogModelId
    ): void {
        if (! Schema::hasTable('nhl_goalie_chance_profile_buckets')) {
            throw new RuntimeException('Run the G SAT profile migration before building G Projections.');
        }

        $relevantGoalieIds = DB::table('nhl_goalie_workload_projections')
            ->where('target_season_id', $targetSeasonId)
            ->where('projection_version', $goalieWorkloadProjectionVersion)
            ->where('projected_games', '>=', self::RELEVANT_GOALIE_MIN_PROJECTED_GAMES)
            ->whereNotNull('target_team_abbrev')
            ->pluck('goalie_player_id')
            ->map(fn (mixed $goaliePlayerId): int => (int) $goaliePlayerId)
            ->unique()
            ->values();

        if ($relevantGoalieIds->isEmpty()) {
            throw new RuntimeException('No relevant goalie workload rows exist for the selected workload version.');
        }

        $profileGoalieIds = DB::table('nhl_goalie_chance_profile_buckets')
            ->where('source_season_id', $sourceSeasonId)
            ->where('game_type', self::REGULAR_SEASON_GAME_TYPE)
            ->where('goal_expected_goals_model_id', $goalModelId)
            ->where('shot_on_goal_expected_goals_model_id', $sogModelId)
            ->whereIn('goalie_player_id', $relevantGoalieIds->all())
            ->distinct()
            ->pluck('goalie_player_id')
            ->map(fn (mixed $goaliePlayerId): int => (int) $goaliePlayerId);

        $missingGoalieIds = $relevantGoalieIds
            ->diff($profileGoalieIds)
            ->values();

        if ($missingGoalieIds->isNotEmpty()) {
            throw new RuntimeException(sprintf(
                'G Projections require G SAT Profiles for every relevant workload goalie. Missing %d goalie profile(s): %s.',
                $missingGoalieIds->count(),
                $missingGoalieIds->take(10)->implode(', ')
            ));
        }
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function retainedTeamDefenseBuckets(
        string $sourceSeasonId,
        string $targetSeasonId,
        string $toiProjectionVersion,
        string $teamAbbrev,
        int $goalModelId,
        int $sogModelId
    ): Collection {
        $evRows = $this->evTeamDefenseBuckets(
            $sourceSeasonId,
            $targetSeasonId,
            $toiProjectionVersion,
            $teamAbbrev,
            $goalModelId,
            $sogModelId
        );
        $pkRows = $this->pkTeamDefenseBuckets($sourceSeasonId, $teamAbbrev, $goalModelId, $sogModelId);

        return $this->retainBuckets($evRows, self::STRENGTH_EV)
            ->merge($this->retainBuckets($pkRows, self::STRENGTH_PK))
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function evTeamDefenseBuckets(
        string $sourceSeasonId,
        string $targetSeasonId,
        string $toiProjectionVersion,
        string $teamAbbrev,
        int $goalModelId,
        int $sogModelId
    ): Collection {
        $selectedSkaterIds = $this->selectedEvSkaterIds($targetSeasonId, $toiProjectionVersion, $teamAbbrev);

        if ($selectedSkaterIds->isEmpty()) {
            return collect();
        }

        $placeholders = implode(', ', array_fill(0, $selectedSkaterIds->count(), '?'));
        $candidates = implode(",\n            ", $this->buckets->candidateBucketKeySql('scored_attempts'));
        $sql = <<<SQL
WITH selected_skaters AS (
    SELECT
        toi.player_id,
        toi.projected_toi_seconds
    FROM nhl_player_toi_projections toi
    WHERE toi.target_season_id = ?
        AND toi.projection_version = ?
        AND toi.player_id IN ({$placeholders})
),
scored_attempts AS (
    SELECT DISTINCT
        facts.id,
        players.nhl_id as player_id,
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
    INNER JOIN selected_skaters ON selected_skaters.player_id = players.nhl_id
    WHERE unit_shifts.team_id = facts.opponent_team_id
        AND facts.season_id = ?
        AND games.game_type = ?
        AND facts.strength_bucket = ?
),
source_toi AS (
    SELECT
        summaries.nhl_player_id as player_id,
        SUM(summaries.toi) as source_toi_seconds
    FROM nhl_player_game_strength_summaries summaries
    INNER JOIN nhl_games source_games ON source_games.nhl_game_id = summaries.nhl_game_id
    WHERE summaries.nhl_player_id IN ({$placeholders})
        AND summaries.strength = 'EV'
        AND source_games.season_id = ?
        AND source_games.game_type = ?
    GROUP BY summaries.nhl_player_id
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
    WHERE candidate.fallback_level = 1
)
SELECT
    bucket_key as matched_bucket_key,
    fallback_level,
    COUNT(*) as source_sat_against,
    SUM(CASE WHEN is_shot_on_goal THEN 1 ELSE 0 END) as source_sog_against,
    SUM(CASE WHEN is_goal THEN 1 ELSE 0 END) as source_goals_against,
    ROUND(SUM(goal_xg)::numeric, 4) as source_xga,
    ROUND(SUM(sog_xg)::numeric, 4) as source_xsoga,
    SUM((1::numeric * selected_skaters.projected_toi_seconds::numeric / NULLIF(source_toi.source_toi_seconds::numeric, 0)) / ?) as projected_sata,
    SUM(((CASE WHEN is_shot_on_goal THEN 1 ELSE 0 END)::numeric * selected_skaters.projected_toi_seconds::numeric / NULLIF(source_toi.source_toi_seconds::numeric, 0)) / ?) as projected_soga,
    SUM((goal_xg::numeric * selected_skaters.projected_toi_seconds::numeric / NULLIF(source_toi.source_toi_seconds::numeric, 0)) / ?) as projected_xga,
    SUM((sog_xg::numeric * selected_skaters.projected_toi_seconds::numeric / NULLIF(source_toi.source_toi_seconds::numeric, 0)) / ?) as projected_xsoga
FROM candidate_attempts
INNER JOIN selected_skaters ON selected_skaters.player_id = candidate_attempts.player_id
INNER JOIN source_toi ON source_toi.player_id = candidate_attempts.player_id
GROUP BY bucket_key, fallback_level
HAVING SUM((1::numeric * selected_skaters.projected_toi_seconds::numeric / NULLIF(source_toi.source_toi_seconds::numeric, 0)) / ?) > 0
ORDER BY projected_sata DESC
SQL;

        $bindings = array_merge(
            [$targetSeasonId, $toiProjectionVersion],
            $selectedSkaterIds->all(),
            [
                $goalModelId,
                NhlExpectedGoalsBackfiller::TARGET_GOAL,
                $sogModelId,
                NhlExpectedGoalsBackfiller::TARGET_SHOT_ON_GOAL,
                $sourceSeasonId,
                self::REGULAR_SEASON_GAME_TYPE,
                self::STRENGTH_EV,
            ],
            $selectedSkaterIds->all(),
            [
                $sourceSeasonId,
                self::REGULAR_SEASON_GAME_TYPE,
            ],
            [
                self::SKATERS_ON_ICE_NORMALIZER,
                self::SKATERS_ON_ICE_NORMALIZER,
                self::SKATERS_ON_ICE_NORMALIZER,
                self::SKATERS_ON_ICE_NORMALIZER,
                self::SKATERS_ON_ICE_NORMALIZER,
            ]
        );

        return collect(DB::select($sql, $bindings))
            ->map(fn (object $row): array => $this->teamBucketPayload($row, self::STRENGTH_EV));
    }

    /**
     * @return Collection<int, int>
     */
    private function selectedEvSkaterIds(string $targetSeasonId, string $toiProjectionVersion, string $teamAbbrev): Collection
    {
        $base = DB::table('nhl_player_toi_projections as toi')
            ->leftJoin('players', 'players.nhl_id', '=', 'toi.player_id')
            ->where('toi.target_season_id', $targetSeasonId)
            ->where('toi.projection_version', $toiProjectionVersion)
            ->where('toi.target_team_abbrev', mb_strtoupper($teamAbbrev))
            ->where('toi.projected_toi_seconds', '>', 0);

        $forwards = (clone $base)
            ->where(function ($query): void {
                $query->where('players.pos_type', 'F')
                    ->orWhereIn('toi.position', ['C', 'L', 'R', 'F']);
            })
            ->orderByDesc('toi.projected_toi_seconds')
            ->limit(self::TOP_FORWARD_COUNT)
            ->pluck('toi.player_id');

        $defense = (clone $base)
            ->where(function ($query): void {
                $query->where('players.pos_type', 'D')
                    ->orWhere('toi.position', 'D');
            })
            ->orderByDesc('toi.projected_toi_seconds')
            ->limit(self::TOP_DEFENSE_COUNT)
            ->pluck('toi.player_id');

        return $forwards
            ->merge($defense)
            ->map(fn (mixed $playerId): int => (int) $playerId)
            ->unique()
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function pkTeamDefenseBuckets(string $sourceSeasonId, string $teamAbbrev, int $goalModelId, int $sogModelId): Collection
    {
        $teamId = DB::table('nhl_teams')
            ->where('abbrev', mb_strtoupper($teamAbbrev))
            ->value('nhl_id');

        if ($teamId === null) {
            return collect();
        }

        $candidates = implode(",\n            ", $this->buckets->candidateBucketKeySql('facts'));
        $sql = <<<SQL
WITH candidate_attempts AS (
    SELECT
        facts.*,
        goal_predictions.xg as goal_xg,
        sog_predictions.xg as sog_xg,
        candidate.fallback_level,
        candidate.bucket_key
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
    CROSS JOIN LATERAL (
        VALUES
            {$candidates}
    ) AS candidate(fallback_level, bucket_key)
    WHERE facts.season_id = ?
        AND games.game_type = ?
        AND facts.opponent_team_id = ?
        AND facts.strength_bucket = ?
        AND candidate.fallback_level = 1
)
SELECT
    bucket_key as matched_bucket_key,
    fallback_level,
    COUNT(*) as source_sat_against,
    SUM(CASE WHEN is_shot_on_goal THEN 1 ELSE 0 END) as source_sog_against,
    SUM(CASE WHEN is_goal THEN 1 ELSE 0 END) as source_goals_against,
    ROUND(SUM(goal_xg)::numeric, 4) as source_xga,
    ROUND(SUM(sog_xg)::numeric, 4) as source_xsoga,
    COUNT(*)::numeric as projected_sata,
    SUM(CASE WHEN is_shot_on_goal THEN 1 ELSE 0 END)::numeric as projected_soga,
    SUM(goal_xg)::numeric as projected_xga,
    SUM(sog_xg)::numeric as projected_xsoga
FROM candidate_attempts
GROUP BY bucket_key, fallback_level
HAVING COUNT(*) > 0
ORDER BY projected_sata DESC
SQL;

        return collect(DB::select($sql, [
            $goalModelId,
            NhlExpectedGoalsBackfiller::TARGET_GOAL,
            $sogModelId,
            NhlExpectedGoalsBackfiller::TARGET_SHOT_ON_GOAL,
            $sourceSeasonId,
            self::REGULAR_SEASON_GAME_TYPE,
            (int) $teamId,
            self::FACT_STRENGTH_PP,
        ]))->map(fn (object $row): array => $this->teamBucketPayload($row, self::STRENGTH_PK));
    }

    /**
     * @return array<string, mixed>
     */
    private function teamBucketPayload(object $row, string $strength): array
    {
        $dimensions = $this->bucketDimensions((string) $row->matched_bucket_key);

        return [
            'projection_strength' => $strength,
            'matched_bucket_key' => (string) $row->matched_bucket_key,
            'bucket_dimensions' => json_encode($dimensions, JSON_THROW_ON_ERROR),
            'shot_type_group' => $dimensions['shot_type_group'] ?? 'Any',
            'distance_group' => $dimensions['distance_group'] ?? 'Any',
            'angle_group' => $dimensions['angle_group'] ?? 'Any',
            'sequence_group' => $dimensions['sequence_group'] ?? 'Any',
            'source_sat_against' => (int) $row->source_sat_against,
            'source_sog_against' => (int) $row->source_sog_against,
            'source_goals_against' => (int) $row->source_goals_against,
            'source_xga' => round((float) $row->source_xga, 4),
            'source_xsoga' => round((float) $row->source_xsoga, 4),
            'projected_sata' => (float) $row->projected_sata,
            'projected_soga' => (float) $row->projected_soga,
            'projected_xga' => (float) $row->projected_xga,
            'projected_xsoga' => (float) $row->projected_xsoga,
        ];
    }

    private function goalieWorkloadShare(string $targetSeasonId, string $goalieWorkloadProjectionVersion, object $workload): float
    {
        $teamTotalToiSeconds = (float) DB::table('nhl_goalie_workload_projections')
            ->where('target_season_id', $targetSeasonId)
            ->where('projection_version', $goalieWorkloadProjectionVersion)
            ->where('target_team_abbrev', $workload->target_team_abbrev)
            ->sum('projected_toi_seconds');

        if ($teamTotalToiSeconds <= 0.0) {
            return 0.0;
        }

        return max(0.0, min(1.0, ((float) ($workload->projected_toi_seconds ?? 0)) / $teamTotalToiSeconds));
    }

    /**
     * @param Collection<int, array<string, mixed>> $buckets
     * @return Collection<int, array<string, mixed>>
     */
    private function scaleBucketsForGoalieWorkload(Collection $buckets, float $goalieWorkloadShare): Collection
    {
        if ($goalieWorkloadShare <= 0.0) {
            return collect();
        }

        return $buckets
            ->map(function (array $bucket) use ($goalieWorkloadShare): array {
                foreach (['projected_sata', 'projected_soga', 'projected_xga', 'projected_xsoga'] as $key) {
                    $bucket[$key] = (float) $bucket[$key] * $goalieWorkloadShare;
                }

                return $bucket;
            })
            ->filter(fn (array $bucket): bool => (float) $bucket['projected_sata'] > 0.0)
            ->values();
    }

    /**
     * @param Collection<int, array<string, mixed>> $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function retainBuckets(Collection $rows, string $strength): Collection
    {
        $totalSata = max(0.01, (float) $rows->sum(fn (array $row): float => (float) $row['projected_sata']));
        $runningSata = 0.0;
        $retained = [];
        $tail = [];

        foreach ($rows as $row) {
            if (($runningSata / $totalSata) < self::BUCKET_COVERAGE_TARGET) {
                $runningSata += (float) $row['projected_sata'];
                $retained[] = $row + ['is_other' => false];

                continue;
            }

            $tail[] = $row;
        }

        if ($tail !== []) {
            $retained[] = [
                'projection_strength' => $strength,
                'matched_bucket_key' => 'other',
                'bucket_dimensions' => json_encode(['bucket' => 'other'], JSON_THROW_ON_ERROR),
                'shot_type_group' => 'Other',
                'distance_group' => 'Other',
                'angle_group' => 'Other',
                'sequence_group' => 'Other',
                'projected_sata' => array_sum(array_map(static fn (array $row): float => (float) $row['projected_sata'], $tail)),
                'projected_soga' => array_sum(array_map(static fn (array $row): float => (float) $row['projected_soga'], $tail)),
                'projected_xga' => array_sum(array_map(static fn (array $row): float => (float) $row['projected_xga'], $tail)),
                'projected_xsoga' => array_sum(array_map(static fn (array $row): float => (float) $row['projected_xsoga'], $tail)),
                'is_other' => true,
            ];
        }

        return collect($retained);
    }

    /**
     * @return Collection<int, object>
     */
    private function goalieSkillBuckets(string $sourceSeasonId, int $goaliePlayerId, int $goalModelId, int $sogModelId): Collection
    {
        return DB::table('nhl_goalie_chance_profile_buckets')
            ->where('source_season_id', $sourceSeasonId)
            ->where('game_type', self::REGULAR_SEASON_GAME_TYPE)
            ->where('goal_expected_goals_model_id', $goalModelId)
            ->where('shot_on_goal_expected_goals_model_id', $sogModelId)
            ->where('goalie_player_id', $goaliePlayerId)
            ->orderByDesc('fallback_level')
            ->orderByDesc('source_xsoga')
            ->get();
    }

    /**
     * Match a projected environment bucket to the most specific persisted goalie
     * chance profile row that can explain it.
     *
     * @param array<string, mixed> $bucket
     * @param Collection<int, object> $goalieBuckets
     */
    private function matchedGoalieBucket(array $bucket, Collection $goalieBuckets): ?object
    {
        $bucketDimensions = [
            'shot_type_group' => (string) ($bucket['shot_type_group'] ?? 'Any'),
            'distance_group' => (string) ($bucket['distance_group'] ?? 'Any'),
            'angle_group' => (string) ($bucket['angle_group'] ?? 'Any'),
            'sequence_group' => (string) ($bucket['sequence_group'] ?? 'Any'),
        ];

        return $goalieBuckets
            ->filter(fn (object $goalieBucket): bool => $this->goalieBucketMatches($goalieBucket, $bucketDimensions))
            ->sortByDesc(fn (object $goalieBucket): float => $this->goalieBucketMatchScore($goalieBucket))
            ->first();
    }

    /**
     * @param array<string, string> $bucketDimensions
     */
    private function goalieBucketMatches(object $goalieBucket, array $bucketDimensions): bool
    {
        foreach (['shot_type_group', 'distance_group', 'angle_group', 'sequence_group'] as $dimension) {
            $profileValue = $goalieBucket->{$dimension} ?? null;

            if ($profileValue === null || $profileValue === 'Any') {
                continue;
            }

            if ($profileValue === 'Other') {
                continue;
            }

            if ((string) $profileValue !== ($bucketDimensions[$dimension] ?? 'Any')) {
                return false;
            }
        }

        return true;
    }

    private function goalieBucketMatchScore(object $goalieBucket): float
    {
        $score = 0.0;

        foreach (['shot_type_group', 'distance_group', 'angle_group', 'sequence_group'] as $dimension) {
            $profileValue = $goalieBucket->{$dimension} ?? null;

            if ($profileValue === null || $profileValue === 'Any') {
                continue;
            }

            $score += $profileValue === 'Other' ? 4.0 : 10.0;
        }

        return $score
            + ((float) ($goalieBucket->confidence_score ?? 0.0))
            + (((float) ($goalieBucket->source_sat_against ?? 0)) / 100000);
    }

    /**
     * @param Collection<int, array<string, mixed>> $teamBuckets
     * @param Collection<int, object> $goalieBuckets
     * @return array<int, array<string, mixed>>
     */
    private function bucketPayloads(
        Collection $teamBuckets,
        Collection $goalieBuckets,
        object $workload,
        string $sourceSeasonId,
        string $targetSeasonId,
        string $version,
        float $goalieWorkloadShare
    ): array {
        $totalSata = max(0.01, (float) $teamBuckets->sum(fn (array $bucket): float => (float) $bucket['projected_sata']));
        $now = now();

        return $teamBuckets
            ->map(function (array $bucket) use ($goalieBuckets, $workload, $sourceSeasonId, $targetSeasonId, $version, $goalieWorkloadShare, $totalSata, $now): array {
                $projectionStrength = (string) ($bucket['projection_strength'] ?? self::STRENGTH_EV);
                $goalieBucket = $this->matchedGoalieBucket($bucket, $goalieBuckets);
                $projectedSata = (float) $bucket['projected_sata'];
                $projectedXga = (float) $bucket['projected_xga'];
                $projectedXsoga = (float) $bucket['projected_xsoga'];
                $projectedSoga = (float) $bucket['projected_soga'];
                $confidenceScore = $goalieBucket === null ? 0.0 : (float) ($goalieBucket->confidence_score ?? 0);
                $sourceSata = $goalieBucket === null ? 0 : (int) $goalieBucket->source_sat_against;
                $sourceGsax = $goalieBucket === null ? 0.0 : (float) ($goalieBucket->source_gsax ?? 0);
                $gsaxPerSat = $sourceSata > 0 ? $sourceGsax / $sourceSata : 0.0;
                $skillWeight = $confidenceScore * ($projectionStrength === self::STRENGTH_PK ? self::PK_GOALIE_SKILL_SHRINK : 1.0);
                $projectedGsax = round($projectedSata * $gsaxPerSat * $skillWeight, 4);
                $flags = [];
                $isProjectionOtherTail = (bool) ($bucket['is_other'] ?? false);

                if ($goalieBucket === null && ! $isProjectionOtherTail) {
                    throw new RuntimeException(sprintf(
                        'Missing persisted G SAT profile bucket skill for goalie %d bucket %s.',
                        (int) $workload->goalie_player_id,
                        (string) $bucket['matched_bucket_key']
                    ));
                }

                if ($goalieBucket === null) {
                    $flags[] = 'missing_goalie_bucket_skill';
                }

                if ($goalieBucket !== null && (string) $goalieBucket->matched_bucket_key !== (string) $bucket['matched_bucket_key']) {
                    $flags[] = 'goalie_skill_matched_to_resolved_profile_bucket';
                }

                if ($isProjectionOtherTail) {
                    $flags[] = 'projection_profile_tail_other';
                }

                return [
                    'source_profile_bucket_id' => $goalieBucket === null ? null : (int) $goalieBucket->id,
                    'projection_version' => $version,
                    'source_season_id' => $sourceSeasonId,
                    'target_season_id' => $targetSeasonId,
                    'goalie_player_id' => (int) $workload->goalie_player_id,
                    'source_team_id' => $workload->source_team_id === null ? null : (int) $workload->source_team_id,
                    'source_team_abbrev' => $workload->source_team_abbrev,
                    'target_team_id' => $workload->target_team_id === null ? null : (int) $workload->target_team_id,
                    'target_team_abbrev' => $workload->target_team_abbrev,
                    'position' => $workload->position ?: 'G',
                    'projection_strength' => $projectionStrength,
                    'matched_bucket_key' => (string) $bucket['matched_bucket_key'],
                    'fallback_level' => $goalieBucket === null ? (int) ($bucket['fallback_level'] ?? 99) : (int) $goalieBucket->fallback_level,
                    'bucket_dimensions' => is_string($bucket['bucket_dimensions'])
                        ? $bucket['bucket_dimensions']
                        : json_encode($bucket['bucket_dimensions'], JSON_THROW_ON_ERROR),
                    'shot_type_group' => $bucket['shot_type_group'],
                    'distance_group' => $bucket['distance_group'],
                    'angle_group' => $bucket['angle_group'],
                    'sequence_group' => $bucket['sequence_group'],
                    'source_sat_against' => $sourceSata,
                    'source_sog_against' => $goalieBucket === null ? 0 : (int) $goalieBucket->source_sog_against,
                    'source_goals_against' => $goalieBucket === null ? 0 : (int) $goalieBucket->source_goals_against,
                    'source_xga' => $goalieBucket === null ? null : round((float) $goalieBucket->source_xga, 4),
                    'source_xsoga' => $goalieBucket === null ? null : round((float) $goalieBucket->source_xsoga, 4),
                    'source_gsax' => $goalieBucket === null ? null : round($sourceGsax, 4),
                    'source_gsax_per_100_sat_against' => $sourceSata > 0 ? round($gsaxPerSat * 100, 4) : null,
                    'source_profile_share' => $goalieBucket === null ? null : (float) $goalieBucket->source_profile_share,
                    'projected_sata' => round($projectedSata, 2),
                    'projected_soga' => round($projectedSoga, 2),
                    'projected_xga' => round($projectedXga, 4),
                    'projected_ga' => round(max(0.0, $projectedXga - $projectedGsax), 4),
                    'projected_gsax' => $projectedGsax,
                    'projected_xsoga' => round($projectedXsoga, 4),
                    'projected_profile_share' => round($projectedSata / $totalSata, 6),
                    'goal_probability_against' => $projectedSata > 0 ? round($projectedXga / $projectedSata, 6) : null,
                    'shot_on_goal_probability_against' => $projectedSata > 0 ? round($projectedXsoga / $projectedSata, 6) : null,
                    'confidence_score' => round($confidenceScore, 4),
                    'confidence_bucket' => $this->confidenceBucket($confidenceScore),
                    'projection_inputs' => json_encode([
                        'method' => 'team_defensive_bucket_mix_with_persisted_goalie_profile_skill',
                        'skaters_on_ice_normalizer' => self::SKATERS_ON_ICE_NORMALIZER,
                        'goalie_workload_share' => round($goalieWorkloadShare, 6),
                        'projection_strength' => $projectionStrength,
                        'goalie_gsax_shrinkage_weight' => round($skillWeight, 6),
                        'goalie_profile_bucket_key' => $goalieBucket === null ? null : (string) $goalieBucket->matched_bucket_key,
                    ], JSON_THROW_ON_ERROR),
                    'flags' => json_encode($flags, JSON_THROW_ON_ERROR),
                    'metadata' => json_encode(['builder' => 'NhlGoalieProjectionBuilder'], JSON_THROW_ON_ERROR),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param array<int, array<string, mixed>> $bucketPayloads
     * @return array<string, mixed>
     */
    private function seasonPayload(
        array $bucketPayloads,
        object $workload,
        string $sourceSeasonId,
        string $targetSeasonId,
        string $goalieWorkloadProjectionVersion,
        string $toiProjectionVersion,
        string $version,
        float $goalieWorkloadShare
    ): array {
        if ($bucketPayloads === []) {
            throw new RuntimeException('Goalie projection bucket payloads cannot be empty.');
        }

        $now = now();
        $projectedSata = array_sum(array_column($bucketPayloads, 'projected_sata'));
        $projectedSoga = array_sum(array_column($bucketPayloads, 'projected_soga'));
        $projectedXga = array_sum(array_column($bucketPayloads, 'projected_xga'));
        $projectedXsoga = array_sum(array_column($bucketPayloads, 'projected_xsoga'));
        $projectedGsax = array_sum(array_column($bucketPayloads, 'projected_gsax'));
        $evTotals = $this->strengthTotals($bucketPayloads, self::STRENGTH_EV);
        $pkTotals = $this->strengthTotals($bucketPayloads, self::STRENGTH_PK);
        $broadGaAdjustment = $this->broadGaAdjustment($workload);
        $projectedGa = max(0.0, $projectedXga - $projectedGsax + $broadGaAdjustment['total']);
        $evAdjustedGa = $this->adjustedStrengthGa($evTotals['ga'], $evTotals['xga'], $projectedXga, $broadGaAdjustment['total']);
        $pkAdjustedGa = $this->adjustedStrengthGa($pkTotals['ga'], $pkTotals['xga'], $projectedXga, $broadGaAdjustment['total']);
        $confidenceScore = $this->seasonConfidence($bucketPayloads, (float) ($workload->confidence_score ?? 0));

        return [
            'projection_version' => $version,
            'goalie_workload_projection_version' => $goalieWorkloadProjectionVersion,
            'toi_projection_version' => $toiProjectionVersion,
            'source_season_id' => $sourceSeasonId,
            'target_season_id' => $targetSeasonId,
            'goalie_player_id' => (int) $workload->goalie_player_id,
            'source_team_id' => $workload->source_team_id === null ? null : (int) $workload->source_team_id,
            'source_team_abbrev' => $workload->source_team_abbrev,
            'target_team_id' => $workload->target_team_id === null ? null : (int) $workload->target_team_id,
            'target_team_abbrev' => $workload->target_team_abbrev,
            'position' => $workload->position ?: 'G',
            'source_games' => $workload->source_games,
            'source_toi_seconds' => $workload->source_toi_seconds,
            'source_sat_against' => (int) $workload->source_sat_against,
            'source_sog_against' => (int) $workload->source_sog_against,
            'source_goals_against' => (int) $workload->source_goals_against,
            'source_xga' => $workload->source_xga,
            'source_xsoga' => $workload->source_xsoga,
            'source_gsax' => $workload->source_gsax,
            'projected_games' => $workload->projected_games,
            'projected_starts' => $workload->projected_starts,
            'projected_relief_games' => $workload->projected_relief_games,
            'projected_toi_seconds' => $workload->projected_toi_seconds,
            'projected_toi_hours' => $workload->projected_toi_hours,
            'projected_sata' => round($projectedSata, 2),
            'projected_soga' => round($projectedSoga, 2),
            'projected_xga' => round($projectedXga, 4),
            'projected_ga' => round($projectedGa, 4),
            'projected_gsax' => round($projectedGsax, 4),
            'projected_xsoga' => round($projectedXsoga, 4),
            'projected_ev_sata' => $evTotals['sata'],
            'projected_ev_soga' => $evTotals['soga'],
            'projected_ev_xga' => $evTotals['xga'],
            'projected_ev_ga' => $evAdjustedGa,
            'projected_ev_gsax' => $evTotals['gsax'],
            'projected_ev_xsoga' => $evTotals['xsoga'],
            'projected_pk_sata' => $pkTotals['sata'],
            'projected_pk_soga' => $pkTotals['soga'],
            'projected_pk_xga' => $pkTotals['xga'],
            'projected_pk_ga' => $pkAdjustedGa,
            'projected_pk_gsax' => $pkTotals['gsax'],
            'projected_pk_xsoga' => $pkTotals['xsoga'],
            'confidence_score' => $confidenceScore,
            'confidence_bucket' => $this->confidenceBucket($confidenceScore),
            'status' => 'draft',
            'projection_inputs' => json_encode([
                'method' => 'team_defensive_bucket_mix_with_persisted_goalie_profile_skill',
                'goalie_workload_projection_version' => $goalieWorkloadProjectionVersion,
                'toi_projection_version' => $toiProjectionVersion,
                'goalie_workload_share' => round($goalieWorkloadShare, 6),
                'source_ga_above_xga_per_60' => $broadGaAdjustment['source_ga_above_xga_per_60'],
                'broad_ga_adjustment_reliability' => $broadGaAdjustment['reliability'],
                'broad_ga_adjustment_per_60' => $broadGaAdjustment['per_60'],
                'broad_ga_adjustment_total' => $broadGaAdjustment['total'],
                'bucket_coverage_target' => self::BUCKET_COVERAGE_TARGET,
                'bucket_count' => count($bucketPayloads),
                'missing_goalie_bucket_count' => count(array_filter(
                    $bucketPayloads,
                    static fn (array $bucket): bool => in_array('missing_goalie_bucket_skill', json_decode((string) $bucket['flags'], true, 512, JSON_THROW_ON_ERROR), true)
                )),
            ], JSON_THROW_ON_ERROR),
            'flags' => json_encode([], JSON_THROW_ON_ERROR),
            'metadata' => json_encode(['builder' => 'NhlGoalieProjectionBuilder'], JSON_THROW_ON_ERROR),
            'projected_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $bucketPayloads
     */
    private function strengthTotals(array $bucketPayloads, string $strength): array
    {
        $rows = array_filter(
            $bucketPayloads,
            static fn (array $bucket): bool => (string) ($bucket['projection_strength'] ?? '') === $strength
        );

        return [
            'sata' => round(array_sum(array_column($rows, 'projected_sata')), 2),
            'soga' => round(array_sum(array_column($rows, 'projected_soga')), 2),
            'xga' => round(array_sum(array_column($rows, 'projected_xga')), 4),
            'ga' => round(array_sum(array_column($rows, 'projected_ga')), 4),
            'gsax' => round(array_sum(array_column($rows, 'projected_gsax')), 4),
            'xsoga' => round(array_sum(array_column($rows, 'projected_xsoga')), 4),
        ];
    }

    /**
     * @return array{source_ga_above_xga_per_60:float,reliability:float,per_60:float,total:float}
     */
    private function broadGaAdjustment(object $workload): array
    {
        $sourceToiSeconds = (float) ($workload->source_toi_seconds ?? 0);
        $sourceGoalsAgainst = (float) ($workload->source_goals_against ?? 0);
        $sourceXga = (float) ($workload->source_xga ?? 0);
        $sourceSogAgainst = (float) ($workload->source_sog_against ?? 0);
        $projectedToiSeconds = (float) ($workload->projected_toi_seconds ?? 0);

        if ($sourceToiSeconds <= 0.0 || $projectedToiSeconds <= 0.0) {
            return [
                'source_ga_above_xga_per_60' => 0.0,
                'reliability' => 0.0,
                'per_60' => 0.0,
                'total' => 0.0,
            ];
        }

        $sourceGaAboveXgaPer60 = (($sourceGoalsAgainst - $sourceXga) * 3600) / $sourceToiSeconds;
        $reliability = $sourceSogAgainst / max(1.0, $sourceSogAgainst + self::BROAD_GA_ADJUSTMENT_PRIOR_SOGA);
        $adjustmentPer60 = max(
            -self::BROAD_GA_ADJUSTMENT_CAP_PER_60,
            min(self::BROAD_GA_ADJUSTMENT_CAP_PER_60, $sourceGaAboveXgaPer60 * $reliability)
        );

        return [
            'source_ga_above_xga_per_60' => round($sourceGaAboveXgaPer60, 4),
            'reliability' => round($reliability, 4),
            'per_60' => round($adjustmentPer60, 4),
            'total' => round(($adjustmentPer60 * $projectedToiSeconds) / 3600, 4),
        ];
    }

    private function adjustedStrengthGa(float $baseGa, float $strengthXga, float $totalXga, float $broadAdjustmentTotal): float
    {
        if ($totalXga <= 0.0 || $broadAdjustmentTotal === 0.0) {
            return round(max(0.0, $baseGa), 4);
        }

        return round(max(0.0, $baseGa + ($broadAdjustmentTotal * ($strengthXga / $totalXga))), 4);
    }

    /**
     * @param array<int, array<string, mixed>> $bucketPayloads
     */
    private function seasonConfidence(array $bucketPayloads, float $workloadConfidence): float
    {
        $totalSata = max(0.01, array_sum(array_column($bucketPayloads, 'projected_sata')));
        $bucketConfidence = array_sum(array_map(
            static fn (array $bucket): float => (float) $bucket['projected_sata'] * (float) $bucket['confidence_score'],
            $bucketPayloads
        )) / $totalSata;

        return round(max(0.1, min(1.0, ($workloadConfidence * 0.45) + ($bucketConfidence * 0.55))), 4);
    }

    private function confidenceBucket(float $confidenceScore): string
    {
        return match (true) {
            $confidenceScore >= 0.8 => 'high',
            $confidenceScore >= 0.5 => 'medium',
            default => 'low',
        };
    }

    private function latestModel(string $sourceSeasonId, string $predictionTarget): ?object
    {
        return DB::table('nhl_expected_goals_models')
            ->where('training_season_id', $sourceSeasonId)
            ->where('prediction_target', $predictionTarget)
            ->orderByDesc('trained_at')
            ->orderByDesc('id')
            ->first();
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
     * @return array<int, string>
     */
    private function seasonUpdateColumns(): array
    {
        return [
            'goalie_workload_projection_version',
            'toi_projection_version',
            'source_season_id',
            'source_team_id',
            'source_team_abbrev',
            'target_team_id',
            'target_team_abbrev',
            'position',
            'source_games',
            'source_toi_seconds',
            'source_sat_against',
            'source_sog_against',
            'source_goals_against',
            'source_xga',
            'source_xsoga',
            'source_gsax',
            'projected_games',
            'projected_starts',
            'projected_relief_games',
            'projected_toi_seconds',
            'projected_toi_hours',
            'projected_sata',
            'projected_soga',
            'projected_xga',
            'projected_ga',
            'projected_gsax',
            'projected_xsoga',
            'projected_ev_sata',
            'projected_ev_soga',
            'projected_ev_xga',
            'projected_ev_ga',
            'projected_ev_gsax',
            'projected_ev_xsoga',
            'projected_pk_sata',
            'projected_pk_soga',
            'projected_pk_xga',
            'projected_pk_ga',
            'projected_pk_gsax',
            'projected_pk_xsoga',
            'confidence_score',
            'confidence_bucket',
            'status',
            'projection_inputs',
            'flags',
            'metadata',
            'projected_at',
            'updated_at',
        ];
    }
}
