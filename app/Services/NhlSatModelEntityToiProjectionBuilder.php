<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NhlModelRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Builds model-run scoped player TOI projections from SAT model training seasons.
 */
class NhlSatModelEntityToiProjectionBuilder
{
    private const REGULAR_SEASON_GAME_TYPE = 2;
    private const TARGET_SEASON_GAMES = 84.0;
    private const TABLE = 'nhl_sat_model_entity_toi_projections';

    /**
     * Clear projection rows and list eligible player entities.
     *
     * @return array<int, array{profile_type:string,entity_key:string}>
     */
    public function prepareBuild(NhlModelRun $run): array
    {
        DB::table(self::TABLE)
            ->where('model_run_id', $run->id)
            ->delete();

        return DB::table('nhl_sat_model_entity_profile_buckets as profiles')
            ->leftJoin('players', 'players.nhl_id', '=', 'profiles.entity_id')
            ->where('profiles.model_run_id', $run->id)
            ->whereIn('profiles.profile_type', ['skater_offense', 'skater_defense'])
            ->whereNotNull('profiles.entity_id')
            ->where(function ($query): void {
                $query->whereNull('players.position')
                    ->orWhere('players.position', '<>', 'G');
            })
            ->select(['profiles.profile_type', 'profiles.entity_key'])
            ->distinct()
            ->orderBy('profiles.profile_type')
            ->orderBy('profiles.entity_key')
            ->get()
            ->map(fn (object $row): array => [
                'profile_type' => (string) $row->profile_type,
                'entity_key' => (string) $row->entity_key,
            ])
            ->all();
    }

    /**
     * Build one entity's TOI projection row.
     */
    public function buildEntity(NhlModelRun $run, string $profileType, string $entityKey): int
    {
        $seasonIds = $this->seasonIds($run);

        if ($seasonIds === []) {
            throw new RuntimeException('This SAT model has no training seasons.');
        }

        $profile = DB::table('nhl_sat_model_entity_profile_buckets')
            ->where('model_run_id', $run->id)
            ->where('profile_type', $profileType)
            ->where('entity_key', $entityKey)
            ->whereNotNull('entity_id')
            ->select([
                'profile_type',
                'entity_key',
                'entity_id',
                'entity_name',
                'entity_role',
                'team_context',
            ])
            ->first();

        if ($profile === null || $profile->entity_id === null) {
            return 0;
        }

        $playerId = (int) $profile->entity_id;
        $priorSeasonId = $seasonIds[0] ?? null;
        $latestSeasonId = $seasonIds[count($seasonIds) - 1] ?? null;
        $prior = $priorSeasonId === null ? null : $this->seasonRow($priorSeasonId, $playerId);
        $latest = $latestSeasonId === null ? null : $this->seasonRow($latestSeasonId, $playerId);
        $training = $this->trainingRow($seasonIds, $playerId);

        if ($training === null || (float) $training->games <= 0 || (int) $training->toi_seconds <= 0) {
            return 0;
        }

        $anchor = $latest ?? $prior ?? $training;
        $trainGames = (float) $training->games;
        $trainingSeasonCount = max(1, count($seasonIds));
        $averageSeasonGames = round($trainGames / $trainingSeasonCount, 2);
        $trainToiPerGame = round(((int) $training->toi_seconds) / max(1.0, $trainGames), 2);
        $priorPp = $priorSeasonId === null ? null : $this->ppSeasonRow($priorSeasonId, $playerId);
        $latestPp = $latestSeasonId === null ? null : $this->ppSeasonRow($latestSeasonId, $playerId);
        $sourceRoleBucket = $this->roleBucket((string) ($anchor->position ?? ''), $prior?->team_points_rank === null ? null : (int) $prior->team_points_rank);
        $targetRoleBucket = $this->roleBucket((string) ($anchor->position ?? ''), $latest?->team_points_rank === null ? null : (int) $latest->team_points_rank);
        $sourceRoleBucket ??= $targetRoleBucket;
        $targetRoleBucket ??= $sourceRoleBucket;
        $age = $this->ageAtTargetSeason($anchor->dob ?? null, (string) ($run->target_season_id ?? $latestSeasonId ?? ''));
        $ageAdjustment = $this->ageAdjustmentSeconds($age, $targetRoleBucket);
        $roleAdjustment = $this->roleAdjustmentSeconds($sourceRoleBucket, $targetRoleBucket);
        $sourceToiPerGame = $this->toiPerGame($latest) ?? $trainToiPerGame;
        $trainingScoringRank = $this->trainingScoringRank($seasonIds, $playerId, $trainingSeasonCount);
        $ppContext = $this->ppContext($priorPp, $latestPp);
        $lateToiContext = $latestSeasonId === null
            ? $this->emptyLateToiContext()
            : $this->lateSeasonToiContext($latestSeasonId, $playerId);
        $toiProjection = $this->toiProjection(
            sourceToiPerGame: $sourceToiPerGame,
            trainToiPerGame: $trainToiPerGame,
            roleAdjustment: $roleAdjustment,
            ageAdjustment: $ageAdjustment,
            trainingScoringRank: $trainingScoringRank,
            sourceRoleBucket: $sourceRoleBucket,
            targetRoleBucket: $targetRoleBucket,
            ppContext: $ppContext,
            lateToiContext: $lateToiContext
        );
        $gameProjection = $this->gameProjection(
            averageSeasonGames: $averageSeasonGames,
            priorGames: $prior === null ? null : (float) $prior->games,
            latestGames: $latest === null ? null : (float) $latest->games,
            trainingScoringRank: $trainingScoringRank,
            sourceRoleBucket: $sourceRoleBucket,
            targetRoleBucket: $targetRoleBucket,
            ppContext: $ppContext
        );
        $projectedGames = $gameProjection['projected_games'];
        $projectedToiPerGame = $toiProjection['projected_toi_per_game_seconds'];
        $projectedToiSeconds = (int) round($projectedToiPerGame * $projectedGames);
        $confidenceScore = $this->confidenceScore(
            averageSeasonGames: $averageSeasonGames,
            prior: $prior,
            latest: $latest,
            sourceRoleBucket: $sourceRoleBucket,
            targetRoleBucket: $targetRoleBucket
        );
        $now = now();

        DB::table(self::TABLE)->upsert([[
            'model_run_id' => (int) $run->id,
            'source_season_ids' => json_encode($seasonIds, JSON_THROW_ON_ERROR),
            'prior_training_season_id' => $priorSeasonId,
            'latest_training_season_id' => $latestSeasonId,
            'target_season_id' => $run->target_season_id === null ? null : (string) $run->target_season_id,
            'game_type' => self::REGULAR_SEASON_GAME_TYPE,
            'profile_type' => $profileType,
            'entity_key' => $entityKey,
            'entity_id' => $playerId,
            'entity_name' => $profile->entity_name,
            'entity_role' => $profile->entity_role,
            'team_context' => $profile->team_context,
            'position' => $anchor->position ?? null,
            'age_years' => $age,
            'source_team_id' => $prior?->team_id === null ? null : (int) $prior->team_id,
            'source_team_abbrev' => $prior?->team_abbrev,
            'target_team_id' => $latest?->team_id === null ? null : (int) $latest->team_id,
            'target_team_abbrev' => $latest?->team_abbrev,
            'prior_games' => $prior?->games,
            'prior_toi_seconds' => (int) ($prior?->toi_seconds ?? 0),
            'prior_toi_per_game_seconds' => $this->toiPerGame($prior),
            'latest_games' => $latest?->games,
            'latest_toi_seconds' => (int) ($latest?->toi_seconds ?? 0),
            'latest_toi_per_game_seconds' => $this->toiPerGame($latest),
            'train_games' => $trainGames,
            'train_toi_seconds' => (int) $training->toi_seconds,
            'train_toi_per_game_seconds' => $trainToiPerGame,
            'source_role_bucket' => $sourceRoleBucket,
            'target_role_bucket' => $targetRoleBucket,
            'projected_games' => $projectedGames,
            'projected_toi_seconds' => $projectedToiSeconds,
            'projected_toi_per_game_seconds' => $projectedToiPerGame,
            'projected_toi_hours' => round($projectedToiSeconds / 3600, 4),
            'age_adjustment_seconds_per_game' => $toiProjection['applied_age_adjustment_seconds_per_game'],
            'role_adjustment_seconds_per_game' => $toiProjection['applied_role_adjustment_seconds_per_game'],
            'team_change_adjustment_seconds_per_game' => 0.0,
            'confidence_score' => $confidenceScore,
            'confidence_bucket' => $this->confidenceBucket($confidenceScore),
            'projection_inputs' => json_encode([
                'method' => 'sat_model_segmented_toi_v1',
                'toi_formula_segment' => $toiProjection['segment'],
                'game_formula_segment' => $gameProjection['segment'],
                'train_toi_per_game_seconds' => $trainToiPerGame,
                'source_toi_per_game_seconds' => $sourceToiPerGame,
                'formula_base_toi_per_game_seconds' => $toiProjection['formula_base_toi_per_game_seconds'],
                'average_season_games' => $averageSeasonGames,
                's1_s2_games_delta' => $gameProjection['s1_s2_games_delta'],
                'games_movement_bucket' => $gameProjection['games_movement_bucket'],
                'game_formula_base_games' => $gameProjection['formula_base_games'],
                'game_adjustment' => $gameProjection['game_adjustment'],
                'games_projection_reason' => $gameProjection['games_projection_reason'],
                'projected_games' => $projectedGames,
                'training_points_per_season' => $trainingScoringRank?->points_per_season === null ? null : round((float) $trainingScoringRank->points_per_season, 4),
                'training_scoring_group' => $trainingScoringRank?->scoring_group,
                'top_forward_rank' => $trainingScoringRank?->scoring_group === 'forward' ? (int) $trainingScoringRank->scoring_rank : null,
                'top_defense_rank' => $trainingScoringRank?->scoring_group === 'defense' ? (int) $trainingScoringRank->scoring_rank : null,
                'prior_pp_toi_per_game_seconds' => $ppContext['prior_pp_toi_per_game_seconds'],
                'latest_pp_toi_per_game_seconds' => $ppContext['latest_pp_toi_per_game_seconds'],
                'pp_toi_per_game_drift_seconds' => $ppContext['pp_toi_per_game_drift_seconds'],
                'pp_role_bucket' => $ppContext['pp_role_bucket'],
                'pp_adjustment_seconds_per_game' => $toiProjection['pp_adjustment_seconds_per_game'],
                'pre_march_games' => $lateToiContext['pre_march_games'],
                'late_games' => $lateToiContext['late_games'],
                'pre_march_toi_per_game_seconds' => $lateToiContext['pre_march_toi_per_game_seconds'],
                'late_toi_per_game_seconds' => $lateToiContext['late_toi_per_game_seconds'],
                'late_toi_per_game_delta_seconds' => $lateToiContext['late_toi_per_game_delta_seconds'],
                'late_toi_signal' => $lateToiContext['late_toi_signal'],
                'late_toi_adjustment_seconds_per_game' => $toiProjection['late_toi_adjustment_seconds_per_game'],
                's2_weight' => $toiProjection['s2_weight'],
                'train_weight' => $toiProjection['train_weight'],
                'role_adjustment_weight' => $toiProjection['role_adjustment_weight'],
                'age_adjustment_weight' => $toiProjection['age_adjustment_weight'],
                'adjustment_cap_seconds_per_game' => $toiProjection['adjustment_cap_seconds_per_game'],
                'raw_role_adjustment_seconds_per_game' => $roleAdjustment,
                'raw_age_adjustment_seconds_per_game' => $ageAdjustment,
                'applied_role_adjustment_seconds_per_game' => $toiProjection['applied_role_adjustment_seconds_per_game'],
                'applied_age_adjustment_seconds_per_game' => $toiProjection['applied_age_adjustment_seconds_per_game'],
                'source_role_bucket' => $sourceRoleBucket,
                'target_role_bucket' => $targetRoleBucket,
            ], JSON_THROW_ON_ERROR),
            'flags' => json_encode($this->flags($averageSeasonGames, $prior, $latest, $sourceRoleBucket, $targetRoleBucket), JSON_THROW_ON_ERROR),
            'metadata' => json_encode(['builder' => self::class], JSON_THROW_ON_ERROR),
            'projected_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['model_run_id', 'profile_type', 'entity_key'], $this->updateColumns());

        return 1;
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

    /**
     * @param array<int, string> $seasonIds
     */
    private function trainingRow(array $seasonIds, int $playerId): ?object
    {
        return $this->trainingRowFromGameSummaries($seasonIds, $playerId)
            ?? $this->trainingRowFromSeasonStats($seasonIds, $playerId);
    }

    /**
     * @param array<int, string> $seasonIds
     */
    private function trainingRowFromGameSummaries(array $seasonIds, int $playerId): ?object
    {
        return DB::table('nhl_game_summaries as summaries')
            ->join('nhl_games as games', 'games.nhl_game_id', '=', 'summaries.nhl_game_id')
            ->whereIn('games.season_id', $seasonIds)
            ->where('games.game_type', self::REGULAR_SEASON_GAME_TYPE)
            ->where('summaries.nhl_player_id', $playerId)
            ->whereNotNull('summaries.toi')
            ->selectRaw('summaries.nhl_player_id')
            ->selectRaw('COUNT(DISTINCT summaries.nhl_game_id) * 1.0 as games')
            ->selectRaw('SUM(summaries.toi) as toi_seconds')
            ->selectRaw('SUM(COALESCE(summaries.pts, 0)) as points')
            ->groupBy('summaries.nhl_player_id')
            ->first();
    }

    /**
     * @param array<int, string> $seasonIds
     */
    private function trainingRowFromSeasonStats(array $seasonIds, int $playerId): ?object
    {
        return DB::table('nhl_season_stats')
            ->whereIn('season_id', $seasonIds)
            ->where('game_type', self::REGULAR_SEASON_GAME_TYPE)
            ->where('nhl_player_id', $playerId)
            ->selectRaw('nhl_player_id')
            ->selectRaw('SUM(gp) * 1.0 as games')
            ->selectRaw('SUM(toi) as toi_seconds')
            ->selectRaw('SUM(pts) as points')
            ->groupBy('nhl_player_id')
            ->first();
    }

    private function seasonRow(string $seasonId, int $playerId): ?object
    {
        return $this->seasonRowFromGameSummaries($seasonId, $playerId)
            ?? $this->seasonRowFromSeasonStats($seasonId, $playerId);
    }

    private function seasonRowFromGameSummaries(string $seasonId, int $playerId): ?object
    {
        $rankSubquery = $this->gameSummaryRankSubquery($seasonId);

        return DB::query()
            ->fromSub($rankSubquery, 'ranked_summaries')
            ->leftJoin('nhl_teams', 'nhl_teams.nhl_id', '=', 'ranked_summaries.team_id')
            ->where('ranked_summaries.nhl_player_id', $playerId)
            ->selectRaw('ranked_summaries.nhl_player_id')
            ->selectRaw('ranked_summaries.team_id')
            ->selectRaw('nhl_teams.abbrev as team_abbrev')
            ->selectRaw('ranked_summaries.games')
            ->selectRaw('ranked_summaries.toi_seconds')
            ->selectRaw('ranked_summaries.points')
            ->selectRaw('ranked_summaries.position')
            ->selectRaw('ranked_summaries.dob')
            ->selectRaw('ranked_summaries.team_points_rank')
            ->orderByDesc('ranked_summaries.games')
            ->orderByDesc('ranked_summaries.toi_seconds')
            ->first();
    }

    private function seasonRowFromSeasonStats(string $seasonId, int $playerId): ?object
    {
        $rankSubquery = $this->seasonStatsRankSubquery($seasonId);

        return DB::table('nhl_season_stats as stats')
            ->join('players', 'players.nhl_id', '=', 'stats.nhl_player_id')
            ->leftJoin('nhl_teams', 'nhl_teams.nhl_id', '=', 'stats.nhl_team_id')
            ->leftJoinSub($rankSubquery, 'ranks', function ($join): void {
                $join->on('ranks.nhl_player_id', '=', 'stats.nhl_player_id')
                    ->on('ranks.team_id', '=', 'stats.nhl_team_id');
            })
            ->where('stats.season_id', $seasonId)
            ->where('stats.game_type', self::REGULAR_SEASON_GAME_TYPE)
            ->where('stats.nhl_player_id', $playerId)
            ->selectRaw('stats.nhl_player_id')
            ->selectRaw('stats.nhl_team_id as team_id')
            ->selectRaw('nhl_teams.abbrev as team_abbrev')
            ->selectRaw('stats.gp * 1.0 as games')
            ->selectRaw('stats.toi as toi_seconds')
            ->selectRaw('stats.pts as points')
            ->selectRaw('players.position')
            ->selectRaw('players.dob')
            ->selectRaw('ranks.team_points_rank')
            ->first();
    }

    private function gameSummaryRankSubquery(string $seasonId)
    {
        $seasonRows = DB::table('nhl_game_summaries as summaries')
            ->join('nhl_games as games', 'games.nhl_game_id', '=', 'summaries.nhl_game_id')
            ->join('players', 'players.nhl_id', '=', 'summaries.nhl_player_id')
            ->where('games.season_id', $seasonId)
            ->where('games.game_type', self::REGULAR_SEASON_GAME_TYPE)
            ->whereNotNull('summaries.toi')
            ->where('summaries.toi', '>', 0)
            ->where(function ($query): void {
                $query->whereNull('players.position')
                    ->orWhere('players.position', '<>', 'G');
            })
            ->selectRaw('summaries.nhl_player_id')
            ->selectRaw('summaries.nhl_team_id as team_id')
            ->selectRaw('COUNT(DISTINCT summaries.nhl_game_id) * 1.0 as games')
            ->selectRaw('SUM(summaries.toi) as toi_seconds')
            ->selectRaw('SUM(COALESCE(summaries.pts, 0)) as points')
            ->selectRaw('MAX(players.position) as position')
            ->selectRaw('MAX(players.pos_type) as pos_type')
            ->selectRaw('MAX(players.dob) as dob')
            ->groupBy('summaries.nhl_player_id', 'summaries.nhl_team_id');

        return DB::query()
            ->fromSub($seasonRows, 'season_rows')
            ->selectRaw('season_rows.*')
            ->selectRaw(
                "ROW_NUMBER() OVER (
                    PARTITION BY season_rows.team_id, COALESCE(NULLIF(season_rows.pos_type, ''), season_rows.position)
                    ORDER BY season_rows.points DESC, season_rows.toi_seconds DESC, season_rows.nhl_player_id ASC
                ) as team_points_rank"
            );
    }

    private function seasonStatsRankSubquery(string $seasonId)
    {
        return DB::table('nhl_season_stats as stats')
            ->join('players', 'players.nhl_id', '=', 'stats.nhl_player_id')
            ->selectRaw('stats.nhl_player_id')
            ->selectRaw('stats.nhl_team_id as team_id')
            ->selectRaw(
                "ROW_NUMBER() OVER (
                    PARTITION BY stats.nhl_team_id, COALESCE(NULLIF(players.pos_type, ''), players.position)
                    ORDER BY stats.pts DESC, stats.toi DESC, stats.nhl_player_id ASC
                ) as team_points_rank"
            )
            ->where('stats.season_id', $seasonId)
            ->where('stats.game_type', self::REGULAR_SEASON_GAME_TYPE)
            ->where('stats.gp', '>', 0)
            ->where('stats.toi', '>', 0)
            ->where(function ($query): void {
                $query->whereNull('players.position')
                    ->orWhere('players.position', '<>', 'G');
            });
    }

    private function toiPerGame(?object $row): ?float
    {
        if ($row === null || (float) $row->games <= 0) {
            return null;
        }

        return round(((int) $row->toi_seconds) / (float) $row->games, 2);
    }

    private function ppSeasonRow(string $seasonId, int $playerId): ?object
    {
        return DB::table('nhl_game_summaries as summaries')
            ->join('nhl_games as games', 'games.nhl_game_id', '=', 'summaries.nhl_game_id')
            ->leftJoin('nhl_player_game_strength_summaries as strength_summaries', function ($join): void {
                $join->on('strength_summaries.nhl_game_id', '=', 'summaries.nhl_game_id')
                    ->on('strength_summaries.nhl_player_id', '=', 'summaries.nhl_player_id')
                    ->where('strength_summaries.strength', '=', 'PP');
            })
            ->where('games.season_id', $seasonId)
            ->where('games.game_type', self::REGULAR_SEASON_GAME_TYPE)
            ->where('summaries.nhl_player_id', $playerId)
            ->selectRaw('COUNT(DISTINCT summaries.nhl_game_id) * 1.0 as games')
            ->selectRaw('SUM(COALESCE(strength_summaries.toi, 0)) as pp_toi_seconds')
            ->first();
    }

    /**
     * @return array{
     *     prior_pp_toi_per_game_seconds:?float,
     *     latest_pp_toi_per_game_seconds:?float,
     *     pp_toi_per_game_drift_seconds:float,
     *     pp_role_bucket:string
     * }
     */
    private function ppContext(?object $priorPp, ?object $latestPp): array
    {
        $priorPpPerGame = $this->ppToiPerGame($priorPp);
        $latestPpPerGame = $this->ppToiPerGame($latestPp);
        $drift = $priorPpPerGame === null || $latestPpPerGame === null
            ? 0.0
            : $latestPpPerGame - $priorPpPerGame;

        return [
            'prior_pp_toi_per_game_seconds' => $priorPpPerGame,
            'latest_pp_toi_per_game_seconds' => $latestPpPerGame,
            'pp_toi_per_game_drift_seconds' => round($drift, 2),
            'pp_role_bucket' => $this->ppRoleBucket($latestPpPerGame),
        ];
    }

    private function ppToiPerGame(?object $row): ?float
    {
        if ($row === null || (float) $row->games <= 0) {
            return null;
        }

        return round(((int) $row->pp_toi_seconds) / (float) $row->games, 2);
    }

    private function ppRoleBucket(?float $ppToiPerGame): string
    {
        return match (true) {
            $ppToiPerGame === null => 'unknown',
            $ppToiPerGame < 3.0 => 'none',
            $ppToiPerGame < 45.0 => 'low',
            $ppToiPerGame < 120.0 => 'medium',
            default => 'high',
        };
    }

    /**
     * @return array{
     *     pre_march_games:int,
     *     late_games:int,
     *     pre_march_toi_seconds:int,
     *     late_toi_seconds:int,
     *     pre_march_toi_per_game_seconds:?float,
     *     late_toi_per_game_seconds:?float,
     *     late_toi_per_game_delta_seconds:?float,
     *     late_toi_signal:string
     * }
     */
    private function lateSeasonToiContext(string $seasonId, int $playerId): array
    {
        $marchDate = mb_substr($seasonId, 4, 4) . '-03-01';
        $row = DB::table('nhl_game_summaries as summaries')
            ->join('nhl_games as games', 'games.nhl_game_id', '=', 'summaries.nhl_game_id')
            ->where('games.season_id', $seasonId)
            ->where('games.game_type', self::REGULAR_SEASON_GAME_TYPE)
            ->where('summaries.nhl_player_id', $playerId)
            ->whereNotNull('summaries.toi')
            ->selectRaw("COUNT(DISTINCT summaries.nhl_game_id) FILTER (WHERE games.game_date < ?::date) as pre_march_games", [$marchDate])
            ->selectRaw("COUNT(DISTINCT summaries.nhl_game_id) FILTER (WHERE games.game_date >= ?::date) as late_games", [$marchDate])
            ->selectRaw("SUM(summaries.toi) FILTER (WHERE games.game_date < ?::date) as pre_march_toi_seconds", [$marchDate])
            ->selectRaw("SUM(summaries.toi) FILTER (WHERE games.game_date >= ?::date) as late_toi_seconds", [$marchDate])
            ->first();

        if ($row === null) {
            return $this->emptyLateToiContext();
        }

        $preGames = (int) ($row->pre_march_games ?? 0);
        $lateGames = (int) ($row->late_games ?? 0);
        $preToiSeconds = (int) ($row->pre_march_toi_seconds ?? 0);
        $lateToiSeconds = (int) ($row->late_toi_seconds ?? 0);
        $preToiPerGame = $preGames > 0 ? round($preToiSeconds / $preGames, 2) : null;
        $lateToiPerGame = $lateGames > 0 ? round($lateToiSeconds / $lateGames, 2) : null;
        $delta = $preToiPerGame === null || $lateToiPerGame === null ? null : round($lateToiPerGame - $preToiPerGame, 2);

        return [
            'pre_march_games' => $preGames,
            'late_games' => $lateGames,
            'pre_march_toi_seconds' => $preToiSeconds,
            'late_toi_seconds' => $lateToiSeconds,
            'pre_march_toi_per_game_seconds' => $preToiPerGame,
            'late_toi_per_game_seconds' => $lateToiPerGame,
            'late_toi_per_game_delta_seconds' => $delta,
            'late_toi_signal' => $this->lateToiSignal($lateGames, $delta),
        ];
    }

    /**
     * @return array{
     *     pre_march_games:int,
     *     late_games:int,
     *     pre_march_toi_seconds:int,
     *     late_toi_seconds:int,
     *     pre_march_toi_per_game_seconds:?float,
     *     late_toi_per_game_seconds:?float,
     *     late_toi_per_game_delta_seconds:?float,
     *     late_toi_signal:string
     * }
     */
    private function emptyLateToiContext(): array
    {
        return [
            'pre_march_games' => 0,
            'late_games' => 0,
            'pre_march_toi_seconds' => 0,
            'late_toi_seconds' => 0,
            'pre_march_toi_per_game_seconds' => null,
            'late_toi_per_game_seconds' => null,
            'late_toi_per_game_delta_seconds' => null,
            'late_toi_signal' => 'late_toi_insufficient',
        ];
    }

    private function lateToiSignal(int $lateGames, ?float $deltaSeconds): string
    {
        if ($lateGames < 8 || $deltaSeconds === null) {
            return 'late_toi_insufficient';
        }

        if ($deltaSeconds > 60.0) {
            return 'late_toi_up';
        }

        if ($deltaSeconds < -60.0) {
            return 'late_toi_down';
        }

        return 'late_toi_flat';
    }

    private function roleBucket(string $positionType, ?int $rank): ?string
    {
        if ($rank === null) {
            return null;
        }

        if (mb_strtoupper($positionType) === 'D') {
            return match (true) {
                $rank <= 4 => 'top4_d',
                $rank <= 6 => 'third_pair_d',
                default => 'depth_d',
            };
        }

        return match (true) {
            $rank <= 6 => 'top6_f',
            $rank <= 9 => 'middle6_f',
            $rank <= 12 => 'bottom6_f',
            default => 'depth_f',
        };
    }

    private function ageAtTargetSeason(mixed $dob, string $targetSeasonId): ?float
    {
        if ($dob === null || $dob === '' || preg_match('/^\d{8}$/', $targetSeasonId) !== 1) {
            return null;
        }

        $targetDate = Carbon::create((int) mb_substr($targetSeasonId, 0, 4), 9, 15);

        return round(Carbon::parse($dob)->floatDiffInYears($targetDate), 2);
    }

    private function ageAdjustmentSeconds(?float $age, ?string $targetRoleBucket): float
    {
        if ($age === null) {
            return 0.0;
        }

        if ($age < 25 && in_array($targetRoleBucket, ['top6_f', 'top4_d'], true)) {
            return 45.0;
        }

        if ($age >= 34) {
            return -60.0;
        }

        if ($age >= 31) {
            return -30.0;
        }

        return 0.0;
    }

    private function roleAdjustmentSeconds(?string $sourceRoleBucket, ?string $targetRoleBucket): float
    {
        $sourceScore = $this->roleScore($sourceRoleBucket);
        $targetScore = $this->roleScore($targetRoleBucket);

        if ($sourceScore === null || $targetScore === null) {
            return 0.0;
        }

        return (float) (($targetScore - $sourceScore) * 45);
    }

    /**
     * @param array<int, string> $seasonIds
     */
    private function trainingScoringRank(array $seasonIds, int $playerId, int $trainingSeasonCount): ?object
    {
        return $this->trainingScoringRankFromGameSummaries($seasonIds, $playerId, $trainingSeasonCount)
            ?? $this->trainingScoringRankFromSeasonStats($seasonIds, $playerId, $trainingSeasonCount);
    }

    /**
     * @param array<int, string> $seasonIds
     */
    private function trainingScoringRankFromGameSummaries(array $seasonIds, int $playerId, int $trainingSeasonCount): ?object
    {
        $seasonCount = max(1, $trainingSeasonCount);
        $scoreRows = DB::table('nhl_game_summaries as summaries')
            ->join('nhl_games as games', 'games.nhl_game_id', '=', 'summaries.nhl_game_id')
            ->join('players', 'players.nhl_id', '=', 'summaries.nhl_player_id')
            ->whereIn('games.season_id', $seasonIds)
            ->where('games.game_type', self::REGULAR_SEASON_GAME_TYPE)
            ->whereNotNull('summaries.nhl_player_id')
            ->where(function ($query): void {
                $query->whereNull('players.position')
                    ->orWhere('players.position', '<>', 'G');
            })
            ->selectRaw('summaries.nhl_player_id')
            ->selectRaw('COUNT(DISTINCT summaries.nhl_game_id) * 1.0 as games')
            ->selectRaw("SUM(COALESCE(summaries.pts, 0))::numeric / {$seasonCount} as points_per_season")
            ->selectRaw("CASE WHEN MAX(players.position) = 'D' THEN 'defense' ELSE 'forward' END as scoring_group")
            ->groupBy('summaries.nhl_player_id');

        return $this->rankedTrainingScoringRow($scoreRows, $playerId);
    }

    /**
     * @param array<int, string> $seasonIds
     */
    private function trainingScoringRankFromSeasonStats(array $seasonIds, int $playerId, int $trainingSeasonCount): ?object
    {
        $seasonCount = max(1, $trainingSeasonCount);
        $scoreRows = DB::table('nhl_season_stats as stats')
            ->join('players', 'players.nhl_id', '=', 'stats.nhl_player_id')
            ->whereIn('stats.season_id', $seasonIds)
            ->where('stats.game_type', self::REGULAR_SEASON_GAME_TYPE)
            ->where('stats.gp', '>', 0)
            ->where(function ($query): void {
                $query->whereNull('players.position')
                    ->orWhere('players.position', '<>', 'G');
            })
            ->selectRaw('stats.nhl_player_id')
            ->selectRaw('SUM(stats.gp) * 1.0 as games')
            ->selectRaw("SUM(COALESCE(stats.pts, 0))::numeric / {$seasonCount} as points_per_season")
            ->selectRaw("CASE WHEN MAX(players.position) = 'D' THEN 'defense' ELSE 'forward' END as scoring_group")
            ->groupBy('stats.nhl_player_id');

        return $this->rankedTrainingScoringRow($scoreRows, $playerId);
    }

    private function rankedTrainingScoringRow(mixed $scoreRows, int $playerId): ?object
    {
        $rankedRows = DB::query()
            ->fromSub($scoreRows, 'training_scores')
            ->selectRaw('training_scores.*')
            ->selectRaw(
                "ROW_NUMBER() OVER (
                    PARTITION BY training_scores.scoring_group
                    ORDER BY training_scores.points_per_season DESC, training_scores.games DESC, training_scores.nhl_player_id ASC
                ) as scoring_rank"
            );

        return DB::query()
            ->fromSub($rankedRows, 'ranked_training_scores')
            ->where('ranked_training_scores.nhl_player_id', $playerId)
            ->first();
    }

    /**
     * @return array{
     *     projected_toi_per_game_seconds:float,
     *     formula_base_toi_per_game_seconds:float,
     *     applied_role_adjustment_seconds_per_game:float,
     *     applied_age_adjustment_seconds_per_game:float,
     *     segment:string,
     *     s2_weight:float,
     *     train_weight:float,
     *     role_adjustment_weight:float,
     *     age_adjustment_weight:float,
     *     adjustment_cap_seconds_per_game:?float,
     *     pp_adjustment_seconds_per_game:float,
     *     late_toi_adjustment_seconds_per_game:float
     * }
     * @param array{prior_pp_toi_per_game_seconds:?float,latest_pp_toi_per_game_seconds:?float,pp_toi_per_game_drift_seconds:float,pp_role_bucket:string} $ppContext
     * @param array{late_toi_signal:string,late_toi_per_game_delta_seconds:?float} $lateToiContext
     */
    private function toiProjection(
        float $sourceToiPerGame,
        float $trainToiPerGame,
        float $roleAdjustment,
        float $ageAdjustment,
        ?object $trainingScoringRank,
        ?string $sourceRoleBucket,
        ?string $targetRoleBucket,
        array $ppContext,
        array $lateToiContext
    ): array {
        $scoringGroup = $trainingScoringRank?->scoring_group;
        $scoringRank = $trainingScoringRank?->scoring_rank === null ? null : (int) $trainingScoringRank->scoring_rank;
        $isForward = $scoringGroup === 'forward';
        $isDefense = $scoringGroup === 'defense';
        $isTopForward = $isForward && $scoringRank !== null && $scoringRank <= 100;
        $isTopDefense = $isDefense && $scoringRank !== null && $scoringRank <= 200;
        $roleMovedUp = $this->roleScore($targetRoleBucket) !== null
            && $this->roleScore($sourceRoleBucket) !== null
            && $this->roleScore($targetRoleBucket) > $this->roleScore($sourceRoleBucket);
        $roleMovedDown = $this->roleScore($targetRoleBucket) !== null
            && $this->roleScore($sourceRoleBucket) !== null
            && $this->roleScore($targetRoleBucket) < $this->roleScore($sourceRoleBucket);
        $ppDrift = (float) $ppContext['pp_toi_per_game_drift_seconds'];
        $ppRoleBucket = (string) $ppContext['pp_role_bucket'];
        $segment = 's2_anchor_standard';
        $s2Weight = 1.0;
        $trainWeight = 0.0;
        $roleWeight = 0.15;
        $ageWeight = 0.10;
        $adjustmentCap = 24.0;
        $ppAdjustmentWeight = 0.10;

        if ($isTopForward) {
            $segment = 'top_100_forward_s2_pp_secure';
            $s2Weight = 0.95;
            $trainWeight = 0.05;
            $roleWeight = $roleMovedUp ? 0.35 : ($roleMovedDown ? 0.10 : 0.0);
            $ageWeight = 0.05;
            $adjustmentCap = 18.0;
            $ppAdjustmentWeight = 0.15;
        } elseif ($isTopDefense) {
            $segment = 'top_200_defense_s2_hold';
            $roleWeight = $roleMovedDown && $ppDrift < -15.0 ? 0.20 : 0.05;
            $ageWeight = 0.05;
            $adjustmentCap = 12.0;
            $ppAdjustmentWeight = 0.05;
        } elseif ($isForward && $sourceRoleBucket === 'middle6_f' && $targetRoleBucket === 'top6_f') {
            $segment = 'forward_middle6_to_top6_modest_bump';
            $roleWeight = 0.45;
            $ageWeight = 0.10;
            $adjustmentCap = 30.0;
        } elseif ($isDefense && $roleMovedUp) {
            $segment = 'defense_role_up_muted';
            $roleWeight = 0.05;
            $ageWeight = 0.05;
            $adjustmentCap = 10.0;
        } elseif ($roleMovedDown) {
            $segment = 'role_down_pp_confirmed_only';
            $roleWeight = $ppDrift < -15.0 ? 0.25 : 0.08;
            $ageWeight = 0.05;
            $adjustmentCap = $ppDrift < -15.0 ? 24.0 : 10.0;
        } elseif (str_starts_with((string) $sourceRoleBucket, 'depth_') || str_starts_with((string) $targetRoleBucket, 'depth_')) {
            $segment = 'depth_s2_shrink';
            $s2Weight = 0.90;
            $trainWeight = 0.10;
            $roleWeight = 0.08;
            $ageWeight = 0.05;
            $adjustmentCap = 15.0;
        }

        if ($ppRoleBucket === 'high') {
            $s2Weight = max($s2Weight, 0.95);
            $trainWeight = min($trainWeight, 0.05);
        } elseif (in_array($ppRoleBucket, ['none', 'low'], true) && str_contains($segment, 'depth')) {
            $s2Weight = 0.85;
            $trainWeight = 0.15;
        }

        $formulaBase = ($sourceToiPerGame * $s2Weight) + ($trainToiPerGame * $trainWeight);
        $appliedRoleAdjustment = $roleAdjustment * $roleWeight;
        $appliedAgeAdjustment = $ageAdjustment * $ageWeight;
        $ppAdjustment = $this->bounded(-1 * $ppDrift * $ppAdjustmentWeight, -12.0, 12.0);
        $lateToiAdjustment = $this->lateToiAdjustmentSeconds($lateToiContext);

        if ($adjustmentCap !== null) {
            [$appliedRoleAdjustment, $appliedAgeAdjustment, $ppAdjustment] = $this->capCombinedAdjustments(
                roleAdjustment: $appliedRoleAdjustment,
                ageAdjustment: $appliedAgeAdjustment,
                ppAdjustment: $ppAdjustment,
                cap: $adjustmentCap
            );
        }

        $projectedToiPerGame = max(0.0, min(
            1800.0,
            round($formulaBase + $appliedRoleAdjustment + $appliedAgeAdjustment + $ppAdjustment + $lateToiAdjustment, 2)
        ));

        return [
            'projected_toi_per_game_seconds' => $projectedToiPerGame,
            'formula_base_toi_per_game_seconds' => round($formulaBase, 2),
            'applied_role_adjustment_seconds_per_game' => round($appliedRoleAdjustment, 2),
            'applied_age_adjustment_seconds_per_game' => round($appliedAgeAdjustment, 2),
            'segment' => $segment,
            's2_weight' => $s2Weight,
            'train_weight' => $trainWeight,
            'role_adjustment_weight' => $roleWeight,
            'age_adjustment_weight' => $ageWeight,
            'adjustment_cap_seconds_per_game' => $adjustmentCap,
            'pp_adjustment_seconds_per_game' => round($ppAdjustment, 2),
            'late_toi_adjustment_seconds_per_game' => round($lateToiAdjustment, 2),
        ];
    }

    /**
     * @param array{late_toi_signal:string,late_toi_per_game_delta_seconds:?float} $lateToiContext
     */
    private function lateToiAdjustmentSeconds(array $lateToiContext): float
    {
        $delta = $lateToiContext['late_toi_per_game_delta_seconds'];

        if ($delta === null) {
            return 0.0;
        }

        return match ($lateToiContext['late_toi_signal']) {
            'late_toi_down' => $this->bounded($delta * 0.35, -45.0, 0.0),
            'late_toi_up' => $this->bounded($delta * 0.125, 0.0, 15.0),
            default => 0.0,
        };
    }

    /**
     * @return array{projected_games:float,formula_base_games:float,game_adjustment:float,segment:string,s1_s2_games_delta:?float,games_movement_bucket:string,games_projection_reason:string}
     * @param array{prior_pp_toi_per_game_seconds:?float,latest_pp_toi_per_game_seconds:?float,pp_toi_per_game_drift_seconds:float,pp_role_bucket:string} $ppContext
     */
    private function gameProjection(
        float $averageSeasonGames,
        ?float $priorGames,
        ?float $latestGames,
        ?object $trainingScoringRank,
        ?string $sourceRoleBucket,
        ?string $targetRoleBucket,
        array $ppContext
    ): array {
        $priorGames = $priorGames === null || $priorGames <= 0 ? null : $priorGames;
        $latestGames = $latestGames === null || $latestGames <= 0 ? $averageSeasonGames : $latestGames;
        $scoringGroup = $trainingScoringRank?->scoring_group;
        $scoringRank = $trainingScoringRank?->scoring_rank === null ? null : (int) $trainingScoringRank->scoring_rank;
        $ppRoleBucket = (string) $ppContext['pp_role_bucket'];
        $gamesDelta = $priorGames === null ? null : $latestGames - $priorGames;
        $movementBucket = $this->gamesMovementBucket($gamesDelta);
        $segment = 'games_s2_minus_haircut_stable';
        $baseGames = $latestGames;
        $haircut = 3.0;
        $reason = 'stable S1/S2 games, projected as S2 minus league-wide games haircut';

        if ($movementBucket === 's2_big_increase') {
            $segment = 'games_s2_big_increase_haircut';
            $haircut = 8.0;
            $reason = 'large S2 GP increase is fragile, so S2 receives a large games haircut';
        } elseif ($movementBucket === 's2_increase') {
            $segment = 'games_s2_increase_haircut';
            $haircut = 5.0;
            $reason = 'moderate S2 GP increase is fragile, so S2 receives a moderate games haircut';
        } elseif ($movementBucket === 's2_decline') {
            $segment = 'games_s2_decline_persist_haircut';
            $haircut = 4.0;
            $reason = 'S2 GP decline is treated as persistent, so projection stays below S2';
        } elseif ($movementBucket === 's2_big_decline') {
            $segment = 'games_s2_big_decline_hold';
            $haircut = 0.0;
            $reason = 'large S2 GP decline is treated as persistent, so projection holds S2';
        } elseif ($movementBucket === 'missing_s1') {
            $segment = 'games_missing_s1_s2_anchor';
            $haircut = 1.0;
            $reason = 'missing S1 games, so projection uses a conservative S2 anchor';
        }

        if ($scoringGroup === 'forward' && $scoringRank !== null && $scoringRank <= 100) {
            $segment = $segment . '_top_forward';
            $haircut = min($haircut, 4.0);
            $reason .= '; top forwards cap the games haircut but receive no automatic games bonus';
        } elseif ($scoringGroup === 'defense' && $scoringRank !== null && $scoringRank <= 200) {
            $segment = $segment . '_top_defense';
            $haircut = max($haircut, 4.0);
            $reason .= '; top defensemen receive no automatic games bonus and keep at least a four-game haircut';
        } elseif (str_starts_with((string) $sourceRoleBucket, 'depth_') || str_starts_with((string) $targetRoleBucket, 'depth_')) {
            $segment = 'games_depth_conservative';
            $haircut = $movementBucket === 'missing_s1' ? 0.0 : max($haircut, 2.0);
            $reason = 'depth players stay on a conservative S2 anchor without an upward games bonus';
        }

        if ($ppRoleBucket === 'high') {
            $haircut = max(0.0, $haircut - 1.0);
            $reason .= '; high PP role reduces the games haircut by one';
        } elseif ($ppRoleBucket === 'low') {
            $haircut += 1.0;
            $reason .= '; low PP role adds one game to the haircut';
        } elseif ($ppRoleBucket === 'none') {
            $haircut += 2.0;
            $reason .= '; no PP role adds two games to the haircut';
        }

        if ($movementBucket === 'missing_s1' && str_starts_with((string) $targetRoleBucket, 'depth_') && $latestGames < 20.0) {
            $baseGames = max($latestGames, min(20.0, $averageSeasonGames));
            $reason .= '; missing-S1 low-games depth entity may rise only to the bounded training average';
        }

        $projectedGames = round($this->bounded($baseGames - $haircut, 0.0, self::TARGET_SEASON_GAMES), 2);

        return [
            'projected_games' => $projectedGames,
            'formula_base_games' => round($baseGames, 2),
            'game_adjustment' => round(-1 * $haircut, 2),
            'segment' => $segment,
            's1_s2_games_delta' => $gamesDelta === null ? null : round($gamesDelta, 2),
            'games_movement_bucket' => $movementBucket,
            'games_projection_reason' => $reason,
        ];
    }

    private function gamesMovementBucket(?float $gamesDelta): string
    {
        return match (true) {
            $gamesDelta === null => 'missing_s1',
            $gamesDelta > 20.0 => 's2_big_increase',
            $gamesDelta > 5.0 => 's2_increase',
            $gamesDelta >= -5.0 => 'stable',
            $gamesDelta >= -20.0 => 's2_decline',
            default => 's2_big_decline',
        };
    }

    /**
     * @return array{0:float,1:float,2:float}
     */
    private function capCombinedAdjustments(float $roleAdjustment, float $ageAdjustment, float $ppAdjustment, float $cap): array
    {
        $combinedAdjustment = $roleAdjustment + $ageAdjustment + $ppAdjustment;

        if ($combinedAdjustment === 0.0 || abs($combinedAdjustment) <= $cap) {
            return [$roleAdjustment, $ageAdjustment, $ppAdjustment];
        }

        $scale = ($combinedAdjustment > 0 ? $cap : -$cap) / $combinedAdjustment;

        return [$roleAdjustment * $scale, $ageAdjustment * $scale, $ppAdjustment * $scale];
    }

    private function bounded(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }

    private function roleScore(?string $roleBucket): ?int
    {
        return match ($roleBucket) {
            'top6_f', 'top4_d' => 3,
            'middle6_f', 'third_pair_d' => 2,
            'bottom6_f', 'depth_d' => 1,
            'depth_f' => 0,
            default => null,
        };
    }

    private function confidenceScore(
        float $averageSeasonGames,
        ?object $prior,
        ?object $latest,
        ?string $sourceRoleBucket,
        ?string $targetRoleBucket
    ): float {
        $score = min(1.0, $averageSeasonGames / self::TARGET_SEASON_GAMES);

        if ($prior === null || $latest === null) {
            $score -= 0.15;
        }

        if ($sourceRoleBucket === null || $targetRoleBucket === null) {
            $score -= 0.10;
        }

        if ($prior?->team_abbrev !== null && $latest?->team_abbrev !== null && $prior->team_abbrev !== $latest->team_abbrev) {
            $score -= 0.10;
        }

        return round(max(0.1, min(1.0, $score)), 4);
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
     * @return array<int, string>
     */
    private function flags(
        float $averageSeasonGames,
        ?object $prior,
        ?object $latest,
        ?string $sourceRoleBucket,
        ?string $targetRoleBucket
    ): array {
        $flags = [];

        if ($averageSeasonGames < 40) {
            $flags[] = 'limited_source_games';
        }

        if ($prior === null) {
            $flags[] = 'missing_prior_training_season';
        }

        if ($latest === null) {
            $flags[] = 'missing_latest_training_season';
        }

        if ($sourceRoleBucket === null || $targetRoleBucket === null) {
            $flags[] = 'missing_role_bucket';
        }

        if ($prior?->team_abbrev !== null && $latest?->team_abbrev !== null && $prior->team_abbrev !== $latest->team_abbrev) {
            $flags[] = 'team_changed_between_training_seasons';
        }

        return $flags;
    }

    /**
     * @return array<int, string>
     */
    private function updateColumns(): array
    {
        return [
            'source_season_ids',
            'prior_training_season_id',
            'latest_training_season_id',
            'target_season_id',
            'game_type',
            'entity_id',
            'entity_name',
            'entity_role',
            'team_context',
            'position',
            'age_years',
            'source_team_id',
            'source_team_abbrev',
            'target_team_id',
            'target_team_abbrev',
            'prior_games',
            'prior_toi_seconds',
            'prior_toi_per_game_seconds',
            'latest_games',
            'latest_toi_seconds',
            'latest_toi_per_game_seconds',
            'train_games',
            'train_toi_seconds',
            'train_toi_per_game_seconds',
            'source_role_bucket',
            'target_role_bucket',
            'projected_games',
            'projected_toi_seconds',
            'projected_toi_per_game_seconds',
            'projected_toi_hours',
            'age_adjustment_seconds_per_game',
            'role_adjustment_seconds_per_game',
            'team_change_adjustment_seconds_per_game',
            'confidence_score',
            'confidence_bucket',
            'projection_inputs',
            'flags',
            'metadata',
            'projected_at',
            'updated_at',
        ];
    }
}
