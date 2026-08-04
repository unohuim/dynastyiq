<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NhlExpectedGoalsModel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Builds first-pass skater season projections from resolved shot profile buckets.
 */
class NhlPlayerProjectionBuilder
{
    public const DEFAULT_VERSION_PREFIX = 'first_pass';
    private const REGULAR_SEASON_GAME_TYPE = 2;
    private const MIN_FINISHING_REGRESSION_WEIGHT = 0.25;
    private const MAX_FINISHING_REGRESSION_WEIGHT = 0.80;
    private const FULL_FINISHING_CONFIDENCE_SAT = 600;
    private const PROJECTION_BUCKET_COVERAGE_TARGET = 0.95;
    private const MIN_PROJECTED_BUCKET_XSAT = 1.0;
    private const MIN_PROJECTED_BUCKET_SHARE = 0.0025;

    public function defaultVersion(string $targetSeasonId): string
    {
        return self::DEFAULT_VERSION_PREFIX . '_' . $targetSeasonId . '_v1';
    }

    /**
     * Prepare one projection version and return the player jobs to queue.
     *
     * @return array{projection_version:string,source_season_id:string,target_season_id:string,goal_model_id:int,sog_model_id:int,player_ids:array<int,int>}
     */
    public function prepareBuild(string $sourceSeasonId, string $targetSeasonId, ?string $version = null): array
    {
        $version = $version ?: $this->defaultVersion($targetSeasonId);
        $goalModel = $this->latestModel($sourceSeasonId, NhlExpectedGoalsBackfiller::TARGET_GOAL);
        $sogModel = $this->latestModel($sourceSeasonId, NhlExpectedGoalsBackfiller::TARGET_SHOT_ON_GOAL);

        if ($goalModel === null || $sogModel === null) {
            throw new RuntimeException('Build xG and xSOG models before building player projections.');
        }

        DB::table('nhl_player_season_projections')
            ->where('projection_version', $version)
            ->where('target_season_id', $targetSeasonId)
            ->delete();

        return [
            'projection_version' => $version,
            'source_season_id' => $sourceSeasonId,
            'target_season_id' => $targetSeasonId,
            'goal_model_id' => (int) $goalModel->id,
            'sog_model_id' => (int) $sogModel->id,
            'player_ids' => $this->eligiblePlayerIds($sourceSeasonId, (int) $goalModel->id, (int) $sogModel->id)->all(),
        ];
    }

    /**
     * Build one player's projection rows.
     *
     * @return array{player_id:int,season_rows:int,bucket_rows:int}
     */
    public function buildPlayer(
        string $sourceSeasonId,
        string $targetSeasonId,
        string $version,
        int $goalModelId,
        int $sogModelId,
        int $playerId
    ): array {
        return DB::transaction(function () use (
            $sourceSeasonId,
            $targetSeasonId,
            $version,
            $goalModelId,
            $sogModelId,
            $playerId
        ): array {
            DB::table('nhl_player_season_projections')
                ->where('projection_version', $version)
                ->where('target_season_id', $targetSeasonId)
                ->where('player_id', $playerId)
                ->delete();

            $season = $this->seasonProjectionSource(
                $sourceSeasonId,
                $targetSeasonId,
                $goalModelId,
                $sogModelId,
                $playerId
            );

            if ($season === null) {
                return [
                    'player_id' => $playerId,
                    'season_rows' => 0,
                    'bucket_rows' => 0,
                ];
            }

            $projectionId = $this->insertSeasonProjection(
                $season,
                $sourceSeasonId,
                $targetSeasonId,
                $version,
                $goalModelId,
                $sogModelId
            );

            $bucketRows = $this->insertBucketProjections(
                $projectionId,
                $season,
                $this->opportunityProjection($season),
                $sourceSeasonId,
                $targetSeasonId,
                $version,
                $goalModelId,
                $sogModelId,
                $playerId
            );

            return [
                'player_id' => $playerId,
                'season_rows' => 1,
                'bucket_rows' => $bucketRows,
            ];
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

    private function defaultToiProjectionVersion(string $targetSeasonId): string
    {
        return NhlPlayerToiProjectionBuilder::DEFAULT_VERSION_PREFIX . '_' . $targetSeasonId . '_v1';
    }

    /**
     * @return Collection<int, int>
     */
    private function eligiblePlayerIds(string $sourceSeasonId, int $goalModelId, int $sogModelId): Collection
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
            ->where('facts.season_id', $sourceSeasonId)
            ->where('games.game_type', self::REGULAR_SEASON_GAME_TYPE)
            ->whereNotNull('facts.shooter_player_id')
            ->distinct()
            ->orderBy('facts.shooter_player_id')
            ->pluck('facts.shooter_player_id')
            ->map(fn (mixed $playerId): int => (int) $playerId);
    }

    private function seasonProjectionSource(
        string $sourceSeasonId,
        string $targetSeasonId,
        int $goalModelId,
        int $sogModelId,
        int $playerId
    ): ?object
    {
        $toiProjectionVersion = $this->defaultToiProjectionVersion($targetSeasonId);

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
            ->leftJoin('nhl_season_stats as season_stats', function ($join) use ($sourceSeasonId): void {
                $join->on('season_stats.nhl_player_id', '=', 'facts.shooter_player_id')
                    ->where('season_stats.season_id', '=', $sourceSeasonId)
                    ->where('season_stats.game_type', '=', self::REGULAR_SEASON_GAME_TYPE);
            })
            ->leftJoin('nhl_player_toi_projections as toi_projections', function ($join) use (
                $sourceSeasonId,
                $targetSeasonId,
                $toiProjectionVersion
            ): void {
                $join->on('toi_projections.player_id', '=', 'facts.shooter_player_id')
                    ->where('toi_projections.source_season_id', '=', $sourceSeasonId)
                    ->where('toi_projections.target_season_id', '=', $targetSeasonId)
                    ->where('toi_projections.projection_version', '=', $toiProjectionVersion);
            })
            ->where('facts.season_id', $sourceSeasonId)
            ->where('games.game_type', self::REGULAR_SEASON_GAME_TYPE)
            ->where('facts.shooter_player_id', $playerId)
            ->selectRaw('facts.shooter_player_id as player_id')
            ->selectRaw('MAX(facts.team_id) as team_id')
            ->selectRaw("MAX(COALESCE(teams.abbrev, facts.team_id::text)) as team_abbrev")
            ->selectRaw('MAX(COALESCE(players.position, players.pos_type)) as position')
            ->selectRaw('COUNT(DISTINCT facts.nhl_game_id)::decimal as source_games')
            ->selectRaw('COUNT(*) as source_sat')
            ->selectRaw('SUM(CASE WHEN facts.is_shot_on_goal THEN 1 ELSE 0 END) as source_sog')
            ->selectRaw('COALESCE(MAX(season_stats.g), SUM(CASE WHEN facts.is_goal THEN 1 ELSE 0 END)) as source_goals')
            ->selectRaw('MAX(season_stats.gp) as season_stat_games')
            ->selectRaw('MAX(season_stats.toi) as season_stat_toi_seconds')
            ->selectRaw('MAX(toi_projections.id) as toi_projection_id')
            ->selectRaw('MAX(toi_projections.projection_version) as toi_projection_version')
            ->selectRaw('MAX(toi_projections.source_games) as toi_source_games')
            ->selectRaw('MAX(toi_projections.source_toi_per_game_seconds) as toi_source_toi_per_game_seconds')
            ->selectRaw('MAX(toi_projections.projected_games) as toi_projected_games')
            ->selectRaw('MAX(toi_projections.projected_toi_per_game_seconds) as toi_projected_toi_per_game_seconds')
            ->selectRaw('MAX(toi_projections.projected_toi_hours) as toi_projected_toi_hours')
            ->selectRaw('SUM(CASE WHEN facts.is_goal THEN 1 ELSE 0 END) as source_model_goals')
            ->selectRaw('ROUND(SUM(goal_predictions.xg)::numeric, 4) as source_xgf')
            ->selectRaw('ROUND(SUM(sog_predictions.xg)::numeric, 4) as source_xsog')
            ->groupBy('facts.shooter_player_id')
            ->first();
    }

    private function insertSeasonProjection(
        object $season,
        string $sourceSeasonId,
        string $targetSeasonId,
        string $version,
        int $goalModelId,
        int $sogModelId
    ): int {
        $sourceSat = (int) $season->source_sat;
        $sourceGoals = (int) $season->source_goals;
        $sourceXgf = (float) $season->source_xgf;
        $sourceXsog = (float) $season->source_xsog;
        $opportunity = $this->opportunityProjection($season);
        $projectedXsat = round($sourceSat * $opportunity['multiplier'], 2);
        $projectedXsog = round($sourceXsog * $opportunity['multiplier'], 2);
        $projectedXgf = round($sourceXgf * $opportunity['multiplier'], 4);
        $sourceGoalsAboveXgf = round($sourceGoals - $sourceXgf, 4);
        $finishingRegressionWeight = $this->finishingRegressionWeight($sourceSat);
        $projectedGoalsAdjustment = round($sourceGoalsAboveXgf * $opportunity['multiplier'] * $finishingRegressionWeight, 4);
        $projectedGoals = round(max(0, $projectedXgf + $projectedGoalsAdjustment), 4);
        $confidenceScore = round(min(1, $sourceSat / 300), 4);
        $confidenceBucket = match (true) {
            $sourceSat >= 300 => 'high',
            $sourceSat >= 100 => 'medium',
            default => 'low',
        };
        $now = now();

        return (int) DB::table('nhl_player_season_projections')->insertGetId([
            'projection_version' => $version,
            'source_season_id' => $sourceSeasonId,
            'target_season_id' => $targetSeasonId,
            'goal_expected_goals_model_id' => $goalModelId,
            'shot_on_goal_expected_goals_model_id' => $sogModelId,
            'player_id' => (int) $season->player_id,
            'team_id' => $season->team_id === null ? null : (int) $season->team_id,
            'team_abbrev' => $season->team_abbrev,
            'position' => $season->position,
            'source_games' => $season->source_games,
            'source_sat' => $sourceSat,
            'source_sog' => (int) $season->source_sog,
            'source_goals' => $sourceGoals,
            'source_model_goals' => (int) $season->source_model_goals,
            'source_xgf' => $season->source_xgf,
            'source_goals_above_xgf' => $sourceGoalsAboveXgf,
            'source_xsog' => $season->source_xsog,
            'projected_games' => $opportunity['projected_games'],
            'projected_xsat' => $projectedXsat,
            'projected_xsog' => $projectedXsog,
            'projected_xgf' => $projectedXgf,
            'finishing_regression_weight' => $finishingRegressionWeight,
            'projected_goals_adjustment' => $projectedGoalsAdjustment,
            'projected_goals' => $projectedGoals,
            'confidence_score' => $confidenceScore,
            'confidence_bucket' => $confidenceBucket,
            'status' => 'draft',
            'projection_inputs' => json_encode([
                'method' => 'source_profile_per_60_scaled_by_projected_toi_with_regressed_finishing',
                'source_season_id' => $sourceSeasonId,
                'target_season_id' => $targetSeasonId,
                'goal_model_id' => $goalModelId,
                'sog_model_id' => $sogModelId,
                'source_goals_above_xgf' => $sourceGoalsAboveXgf,
                'source_toi_hours' => $opportunity['source_toi_hours'],
                'projected_toi_hours' => $opportunity['projected_toi_hours'],
                'opportunity_multiplier' => $opportunity['multiplier'],
                'source_xsat_per_60' => $opportunity['source_toi_hours'] > 0 ? round($sourceSat / $opportunity['source_toi_hours'], 4) : null,
                'source_xsog_per_60' => $opportunity['source_toi_hours'] > 0 ? round($sourceXsog / $opportunity['source_toi_hours'], 4) : null,
                'source_xgf_per_60' => $opportunity['source_toi_hours'] > 0 ? round($sourceXgf / $opportunity['source_toi_hours'], 4) : null,
                'toi_projection_id' => $opportunity['toi_projection_id'],
                'toi_projection_version' => $opportunity['toi_projection_version'],
                'finishing_regression_weight' => $finishingRegressionWeight,
                'projected_goals_adjustment' => $projectedGoalsAdjustment,
            ], JSON_THROW_ON_ERROR),
            'flags' => json_encode($this->projectionFlags($sourceSat, $opportunity), JSON_THROW_ON_ERROR),
            'metadata' => json_encode(['builder' => 'NhlPlayerProjectionBuilder'], JSON_THROW_ON_ERROR),
            'projected_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function finishingRegressionWeight(int $sourceSat): float
    {
        return round(max(
            self::MIN_FINISHING_REGRESSION_WEIGHT,
            min(self::MAX_FINISHING_REGRESSION_WEIGHT, $sourceSat / self::FULL_FINISHING_CONFIDENCE_SAT)
        ), 4);
    }

    /**
     * @return array{
     *     source_toi_hours:float,
     *     projected_toi_hours:float,
     *     multiplier:float,
     *     projected_games:float,
     *     has_toi_projection:bool,
     *     toi_projection_id:int|null,
     *     toi_projection_version:string|null
     * }
     */
    private function opportunityProjection(object $season): array
    {
        $sourceToiHours = $this->sourceToiHours($season);
        $hasToiProjection = $season->toi_projection_id !== null;
        $projectedToiHours = (float) ($season->toi_projected_toi_hours ?? 0);
        $projectedGames = (float) ($season->toi_projected_games ?? $season->source_games);

        if ($projectedToiHours <= 0) {
            $projectedToiPerGameSeconds = (float) ($season->toi_projected_toi_per_game_seconds ?? 0);

            if ($projectedGames > 0 && $projectedToiPerGameSeconds > 0) {
                $projectedToiHours = ($projectedGames * $projectedToiPerGameSeconds) / 3600;
            }
        }

        if ($sourceToiHours <= 0 || $projectedToiHours <= 0) {
            $projectedToiHours = $sourceToiHours;
            $projectedGames = (float) $season->source_games;
        }

        return [
            'source_toi_hours' => round($sourceToiHours, 6),
            'projected_toi_hours' => round($projectedToiHours, 6),
            'multiplier' => $sourceToiHours > 0 ? round($projectedToiHours / $sourceToiHours, 6) : 1.0,
            'projected_games' => round($projectedGames, 2),
            'has_toi_projection' => $hasToiProjection,
            'toi_projection_id' => $hasToiProjection ? (int) $season->toi_projection_id : null,
            'toi_projection_version' => $season->toi_projection_version === null
                ? null
                : (string) $season->toi_projection_version,
        ];
    }

    private function sourceToiHours(object $season): float
    {
        $toiSourceGames = (float) ($season->toi_source_games ?? 0);
        $toiSourcePerGameSeconds = (float) ($season->toi_source_toi_per_game_seconds ?? 0);

        if ($toiSourceGames > 0 && $toiSourcePerGameSeconds > 0) {
            return ($toiSourceGames * $toiSourcePerGameSeconds) / 3600;
        }

        $seasonStatToiSeconds = (float) ($season->season_stat_toi_seconds ?? 0);

        if ($seasonStatToiSeconds > 0) {
            return $seasonStatToiSeconds / 3600;
        }

        return 0.0;
    }

    /**
     * @param array{source_toi_hours:float,has_toi_projection:bool} $opportunity
     * @return array<int, string>
     */
    private function projectionFlags(int $sourceSat, array $opportunity): array
    {
        $flags = [];

        if ($sourceSat < 100) {
            $flags[] = 'limited_source_sat';
        }

        if (!$opportunity['has_toi_projection']) {
            $flags[] = 'missing_toi_projection';
        }

        if ($opportunity['source_toi_hours'] <= 0) {
            $flags[] = 'missing_source_toi';
        }

        return $flags;
    }

    /**
     * @param array{
     *     source_toi_hours:float,
     *     projected_toi_hours:float,
     *     multiplier:float,
     *     projected_games:float,
     *     has_toi_projection:bool,
     *     toi_projection_id:int|null,
     *     toi_projection_version:string|null
     * } $opportunity
     */
    private function insertBucketProjections(
        int $projectionId,
        object $season,
        array $opportunity,
        string $sourceSeasonId,
        string $targetSeasonId,
        string $version,
        int $goalModelId,
        int $sogModelId,
        int $playerId
    ): int {
        $sourceSat = max(1, (int) $season->source_sat);
        $rows = $this->bucketProjectionSource($sourceSeasonId, $goalModelId, $sogModelId, $playerId);
        $now = now();
        $inserts = [];
        $projectedRows = $rows
            ->map(function (object $row) use ($sourceSat, $opportunity): object {
                $bucketSat = (int) $row->source_sat;
                $row->source_profile_share = round($bucketSat / $sourceSat, 6);
                $row->projected_xsat = round($bucketSat * $opportunity['multiplier'], 2);
                $row->projected_xsog = round(((float) $row->source_xsog) * $opportunity['multiplier'], 2);
                $row->projected_xgf = round(((float) $row->source_xgf) * $opportunity['multiplier'], 4);

                return $row;
            })
            ->sortByDesc(fn (object $row): float => (float) $row->projected_xgf)
            ->values();
        $retainedBucketKeys = $this->retainedProjectionBucketKeys($projectedRows);

        foreach ($projectedRows->filter(fn (object $row): bool => $retainedBucketKeys->contains((string) $row->matched_bucket_key)) as $row) {
            $bucketSat = (int) $row->source_sat;

            $inserts[] = [
                'player_season_projection_id' => $projectionId,
                'projection_version' => $version,
                'source_season_id' => $sourceSeasonId,
                'target_season_id' => $targetSeasonId,
                'player_id' => $playerId,
                'team_id' => $row->team_id === null ? null : (int) $row->team_id,
                'team_abbrev' => $row->team_abbrev,
                'position' => $row->position,
                'matched_bucket_key' => $row->matched_bucket_key,
                'fallback_level' => (int) $row->fallback_level,
                'bucket_dimensions' => $row->bucket_dimensions,
                'shot_type_group' => $row->shot_type_group,
                'distance_group' => $row->distance_group,
                'angle_group' => $row->angle_group,
                'sequence_group' => $row->sequence_group,
                'source_sat' => $bucketSat,
                'source_sog' => (int) $row->source_sog,
                'source_goals' => (int) $row->source_goals,
                'source_model_goals' => (int) $row->source_model_goals,
                'source_xgf' => $row->source_xgf,
                'source_xsog' => $row->source_xsog,
                'source_profile_share' => $row->source_profile_share,
                'projected_xsat' => $row->projected_xsat,
                'projected_xsog' => $row->projected_xsog,
                'projected_xgf' => $row->projected_xgf,
                'projected_goals' => $row->projected_xgf,
                'goal_probability' => $row->goal_probability,
                'shot_on_goal_probability' => $row->shot_on_goal_probability,
                'projection_inputs' => json_encode([
                    'method' => 'source_profile_per_60_scaled_by_projected_toi',
                    'source_profile_share' => $row->source_profile_share,
                    'source_toi_hours' => $opportunity['source_toi_hours'],
                    'projected_toi_hours' => $opportunity['projected_toi_hours'],
                    'opportunity_multiplier' => $opportunity['multiplier'],
                    'source_xsat_per_60' => $opportunity['source_toi_hours'] > 0 ? round($bucketSat / $opportunity['source_toi_hours'], 4) : null,
                    'source_xsog_per_60' => $opportunity['source_toi_hours'] > 0 ? round(((float) $row->source_xsog) / $opportunity['source_toi_hours'], 4) : null,
                    'source_xgf_per_60' => $opportunity['source_toi_hours'] > 0 ? round(((float) $row->source_xgf) / $opportunity['source_toi_hours'], 4) : null,
                    'toi_projection_id' => $opportunity['toi_projection_id'],
                    'toi_projection_version' => $opportunity['toi_projection_version'],
                ], JSON_THROW_ON_ERROR),
                'flags' => json_encode($this->projectionFlags($sourceSat, $opportunity), JSON_THROW_ON_ERROR),
                'metadata' => json_encode([
                    'builder' => 'NhlPlayerProjectionBuilder',
                    'retention' => [
                        'coverage_target' => self::PROJECTION_BUCKET_COVERAGE_TARGET,
                        'minimum_projected_xsat' => self::MIN_PROJECTED_BUCKET_XSAT,
                        'minimum_projected_share' => self::MIN_PROJECTED_BUCKET_SHARE,
                        'bucket_role' => 'retained',
                    ],
                ], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $tailRows = $projectedRows->reject(fn (object $row): bool => $retainedBucketKeys->contains((string) $row->matched_bucket_key));
        if ($tailRows->isNotEmpty()) {
            $inserts[] = $this->otherBucketProjectionInsert(
                $projectionId,
                $season,
                $tailRows,
                $opportunity,
                $sourceSeasonId,
                $targetSeasonId,
                $version,
                $playerId,
                $sourceSat,
                $now
            );
        }

        if ($inserts === []) {
            return 0;
        }

        foreach (array_chunk($inserts, 100) as $chunk) {
            DB::table('nhl_player_projection_profile_buckets')->insert($chunk);
        }

        return count($inserts);
    }

    /**
     * @return Collection<int, object>
     */
    private function bucketProjectionSource(string $sourceSeasonId, int $goalModelId, int $sogModelId, int $playerId): Collection
    {
        return DB::table('nhl_skater_offensive_chance_profile_buckets as profiles')
            ->where('profiles.source_season_id', $sourceSeasonId)
            ->where('profiles.game_type', self::REGULAR_SEASON_GAME_TYPE)
            ->where('profiles.goal_expected_goals_model_id', $goalModelId)
            ->where('profiles.shot_on_goal_expected_goals_model_id', $sogModelId)
            ->where('profiles.player_id', $playerId)
            ->select([
                'profiles.team_id',
                'profiles.team_abbrev',
                'profiles.position',
                'profiles.matched_bucket_key',
                'profiles.fallback_level',
                'profiles.bucket_dimensions',
                'profiles.shot_type_group',
                'profiles.distance_group',
                'profiles.angle_group',
                'profiles.sequence_group',
                'profiles.source_sat_for as source_sat',
                'profiles.source_sog_for as source_sog',
                'profiles.source_goals_for as source_goals',
                'profiles.source_goals_for as source_model_goals',
                'profiles.source_xgf',
                'profiles.source_xsog',
                'profiles.goal_probability',
                'profiles.shot_on_goal_probability',
            ])
            ->orderByDesc('profiles.source_sat_for')
            ->get();
    }

    /**
     * @param Collection<int, object> $rows
     * @return Collection<int, string>
     */
    private function retainedProjectionBucketKeys(Collection $rows): Collection
    {
        $totalProjectedXsat = max(0.01, (float) $rows->sum('projected_xsat'));
        $cumulativeShare = 0.0;
        $retained = [];

        foreach ($rows as $row) {
            $share = (float) $row->projected_xsat / $totalProjectedXsat;

            if ((float) $row->projected_xsat >= self::MIN_PROJECTED_BUCKET_XSAT || $share >= self::MIN_PROJECTED_BUCKET_SHARE || $cumulativeShare < self::PROJECTION_BUCKET_COVERAGE_TARGET) {
                $retained[] = (string) $row->matched_bucket_key;
                $cumulativeShare += $share;
            }

            if ($cumulativeShare >= self::PROJECTION_BUCKET_COVERAGE_TARGET && (float) $row->projected_xsat < self::MIN_PROJECTED_BUCKET_XSAT && $share < self::MIN_PROJECTED_BUCKET_SHARE) {
                break;
            }
        }

        return collect($retained);
    }

    /**
     * @param Collection<int, object> $tailRows
     * @param array{
     *     source_toi_hours:float,
     *     projected_toi_hours:float,
     *     multiplier:float,
     *     projected_games:float,
     *     has_toi_projection:bool,
     *     toi_projection_id:int|null,
     *     toi_projection_version:string|null
     * } $opportunity
     * @return array<string, mixed>
     */
    private function otherBucketProjectionInsert(
        int $projectionId,
        object $season,
        Collection $tailRows,
        array $opportunity,
        string $sourceSeasonId,
        string $targetSeasonId,
        string $version,
        int $playerId,
        int $sourceSat,
        object $now
    ): array {
        $tailSourceSat = (int) $tailRows->sum('source_sat');
        $tailSourceSog = (int) $tailRows->sum('source_sog');
        $tailSourceGoals = (int) $tailRows->sum('source_goals');
        $tailSourceModelGoals = (int) $tailRows->sum('source_model_goals');
        $tailSourceXgf = round((float) $tailRows->sum('source_xgf'), 4);
        $tailSourceXsog = round((float) $tailRows->sum('source_xsog'), 4);
        $tailProjectedXsat = round((float) $tailRows->sum('projected_xsat'), 2);
        $tailProjectedXsog = round((float) $tailRows->sum('projected_xsog'), 2);
        $tailProjectedXgf = round((float) $tailRows->sum('projected_xgf'), 4);

        return [
            'player_season_projection_id' => $projectionId,
            'projection_version' => $version,
            'source_season_id' => $sourceSeasonId,
            'target_season_id' => $targetSeasonId,
            'player_id' => $playerId,
            'team_id' => $season->team_id === null ? null : (int) $season->team_id,
            'team_abbrev' => $season->team_abbrev,
            'position' => $season->position,
            'matched_bucket_key' => 'OTHER|profile_tail=projection',
            'fallback_level' => 100,
            'bucket_dimensions' => json_encode(['profile_tail' => 'projection'], JSON_THROW_ON_ERROR),
            'shot_type_group' => 'Other',
            'distance_group' => 'Other',
            'angle_group' => 'Other',
            'sequence_group' => 'Other',
            'source_sat' => $tailSourceSat,
            'source_sog' => $tailSourceSog,
            'source_goals' => $tailSourceGoals,
            'source_model_goals' => $tailSourceModelGoals,
            'source_xgf' => $tailSourceXgf,
            'source_xsog' => $tailSourceXsog,
            'source_profile_share' => round($tailSourceSat / max(1, $sourceSat), 6),
            'projected_xsat' => $tailProjectedXsat,
            'projected_xsog' => $tailProjectedXsog,
            'projected_xgf' => $tailProjectedXgf,
            'projected_goals' => $tailProjectedXgf,
            'goal_probability' => $tailSourceSat > 0 ? round($tailSourceXgf / $tailSourceSat, 6) : null,
            'shot_on_goal_probability' => $tailSourceSat > 0 ? round($tailSourceXsog / $tailSourceSat, 6) : null,
            'projection_inputs' => json_encode([
                'method' => 'source_profile_per_60_scaled_by_projected_toi_other_tail',
                'source_toi_hours' => $opportunity['source_toi_hours'],
                'projected_toi_hours' => $opportunity['projected_toi_hours'],
                'opportunity_multiplier' => $opportunity['multiplier'],
                'tail_bucket_count' => $tailRows->count(),
            ], JSON_THROW_ON_ERROR),
            'flags' => json_encode(array_merge($this->projectionFlags($sourceSat, $opportunity), ['projection_profile_tail_other']), JSON_THROW_ON_ERROR),
            'metadata' => json_encode([
                'builder' => 'NhlPlayerProjectionBuilder',
                'retention' => [
                    'coverage_target' => self::PROJECTION_BUCKET_COVERAGE_TARGET,
                    'minimum_projected_xsat' => self::MIN_PROJECTED_BUCKET_XSAT,
                    'minimum_projected_share' => self::MIN_PROJECTED_BUCKET_SHARE,
                    'bucket_role' => 'other_tail',
                    'tail_bucket_count' => $tailRows->count(),
                ],
            ], JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
