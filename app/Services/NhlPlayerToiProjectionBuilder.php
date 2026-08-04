<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds first-pass player time-on-ice projections from season stats and roster context.
 */
class NhlPlayerToiProjectionBuilder
{
    public const DEFAULT_VERSION_PREFIX = 'first_pass_toi';
    private const REGULAR_SEASON_GAME_TYPE = 2;
    private const TARGET_SEASON_GAMES = 84.0;

    public function defaultVersion(string $targetSeasonId): string
    {
        return self::DEFAULT_VERSION_PREFIX . '_' . $targetSeasonId . '_v1';
    }

    /**
     * Prepare one TOI projection version and return player jobs to queue.
     *
     * @return array{projection_version:string,source_season_id:string,target_season_id:string,player_ids:array<int,int>}
     */
    public function prepareBuild(string $sourceSeasonId, string $targetSeasonId, ?string $version = null): array
    {
        $version = $version ?: $this->defaultVersion($targetSeasonId);

        return [
            'projection_version' => $version,
            'source_season_id' => $sourceSeasonId,
            'target_season_id' => $targetSeasonId,
            'player_ids' => $this->eligiblePlayerIds($sourceSeasonId)->all(),
        ];
    }

    /**
     * Build one player's TOI projection row.
     *
     * @return array{player_id:int,rows:int}
     */
    public function buildPlayer(
        string $sourceSeasonId,
        string $targetSeasonId,
        string $version,
        int $playerId
    ): array {
        return DB::transaction(function () use ($sourceSeasonId, $targetSeasonId, $version, $playerId): array {
            $source = $this->sourceRow($sourceSeasonId, $targetSeasonId, $playerId);

            if ($source === null) {
                return ['player_id' => $playerId, 'rows' => 0];
            }

            DB::table('nhl_player_toi_projections')->upsert(
                [$this->projectionPayload(
                    source: $source,
                    sourceSeasonId: $sourceSeasonId,
                    targetSeasonId: $targetSeasonId,
                    version: $version
                )],
                ['projection_version', 'target_season_id', 'player_id'],
                $this->projectionUpdateColumns()
            );

            return ['player_id' => $playerId, 'rows' => 1];
        });
    }

    /**
     * @return array<int, string>
     */
    private function projectionUpdateColumns(): array
    {
        return [
            'source_season_id',
            'source_team_id',
            'source_team_abbrev',
            'target_team_id',
            'target_team_abbrev',
            'position',
            'age_years',
            'source_games',
            'source_toi_seconds',
            'source_toi_per_game_seconds',
            'source_points',
            'source_team_points_rank',
            'source_role_bucket',
            'target_team_points_rank',
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
            'status',
            'projection_inputs',
            'flags',
            'metadata',
            'projected_at',
            'updated_at',
        ];
    }

    /**
     * @return Collection<int, int>
     */
    private function eligiblePlayerIds(string $sourceSeasonId): Collection
    {
        return DB::table('nhl_season_stats as stats')
            ->join('players', 'players.nhl_id', '=', 'stats.nhl_player_id')
            ->where('stats.season_id', $sourceSeasonId)
            ->where('stats.game_type', self::REGULAR_SEASON_GAME_TYPE)
            ->where('stats.gp', '>', 0)
            ->where('stats.toi', '>', 0)
            ->where(function ($query): void {
                $query->whereNull('players.pos_type')
                    ->orWhere('players.pos_type', '<>', 'G');
            })
            ->orderBy('stats.nhl_player_id')
            ->pluck('stats.nhl_player_id')
            ->map(fn (mixed $playerId): int => (int) $playerId);
    }

    private function sourceRow(string $sourceSeasonId, string $targetSeasonId, int $playerId): ?object
    {
        $sourceRankSubquery = $this->rankSubquery($sourceSeasonId, 'nhl_team_id');
        $targetRankSubquery = $this->targetRankSubquery($sourceSeasonId);

        return DB::table('nhl_season_stats as stats')
            ->join('players', 'players.nhl_id', '=', 'stats.nhl_player_id')
            ->leftJoin('nhl_teams as source_teams', 'source_teams.nhl_id', '=', 'stats.nhl_team_id')
            ->leftJoin('nhl_teams as target_teams', 'target_teams.abbrev', '=', 'players.team_abbrev')
            ->leftJoinSub($sourceRankSubquery, 'source_ranks', function ($join): void {
                $join->on('source_ranks.nhl_player_id', '=', 'stats.nhl_player_id')
                    ->on('source_ranks.team_id', '=', 'stats.nhl_team_id');
            })
            ->leftJoinSub($targetRankSubquery, 'target_ranks', 'target_ranks.nhl_player_id', '=', 'stats.nhl_player_id')
            ->where('stats.season_id', $sourceSeasonId)
            ->where('stats.game_type', self::REGULAR_SEASON_GAME_TYPE)
            ->where('stats.nhl_player_id', $playerId)
            ->where('stats.gp', '>', 0)
            ->where('stats.toi', '>', 0)
            ->select([
                'stats.nhl_player_id',
                'stats.nhl_team_id as source_team_id',
                'stats.gp',
                'stats.toi',
                'stats.pts',
                'players.team_abbrev as target_team_abbrev',
                'players.position',
                'players.pos_type',
                'players.dob',
                'source_teams.abbrev as source_team_abbrev',
                'target_teams.nhl_id as target_team_id',
                'source_ranks.team_points_rank as source_team_points_rank',
                'target_ranks.team_points_rank as target_team_points_rank',
            ])
            ->first();
    }

    private function projectionPayload(object $source, string $sourceSeasonId, string $targetSeasonId, string $version): array
    {
        $sourceGames = (float) $source->gp;
        $sourceToiSeconds = (int) $source->toi;
        $sourceToiPerGame = $sourceGames > 0 ? round($sourceToiSeconds / $sourceGames, 2) : 0.0;
        $positionType = (string) ($source->pos_type ?: $source->position ?: '');
        $sourceRank = $source->source_team_points_rank === null ? null : (int) $source->source_team_points_rank;
        $targetRank = $source->target_team_points_rank === null ? $sourceRank : (int) $source->target_team_points_rank;
        $sourceRoleBucket = $this->roleBucket($positionType, $sourceRank);
        $targetRoleBucket = $this->roleBucket($positionType, $targetRank);
        $age = $this->ageAtTargetSeason($source->dob, $targetSeasonId);
        $projectedGames = min(
            self::TARGET_SEASON_GAMES,
            max($sourceGames, min(self::TARGET_SEASON_GAMES, round(($sourceGames + self::TARGET_SEASON_GAMES) / 2, 2)))
        );
        $ageAdjustment = $this->ageAdjustmentSeconds($age, $targetRoleBucket);
        $roleAdjustment = $this->roleAdjustmentSeconds($sourceRoleBucket, $targetRoleBucket);
        $projectedToiPerGame = max(0.0, min(
            1800.0,
            round($sourceToiPerGame + $ageAdjustment + $roleAdjustment, 2)
        ));
        $projectedToiSeconds = (int) round($projectedToiPerGame * $projectedGames);
        $confidenceScore = $this->confidenceScore($sourceGames, $sourceRank, $targetRank, $source);
        $now = now();

        return [
            'projection_version' => $version,
            'source_season_id' => $sourceSeasonId,
            'target_season_id' => $targetSeasonId,
            'player_id' => (int) $source->nhl_player_id,
            'source_team_id' => $source->source_team_id === null ? null : (int) $source->source_team_id,
            'source_team_abbrev' => $source->source_team_abbrev,
            'target_team_id' => $source->target_team_id === null ? null : (int) $source->target_team_id,
            'target_team_abbrev' => $source->target_team_abbrev ?: $source->source_team_abbrev,
            'position' => $source->position ?: $source->pos_type,
            'age_years' => $age,
            'source_games' => $sourceGames,
            'source_toi_seconds' => $sourceToiSeconds,
            'source_toi_per_game_seconds' => $sourceToiPerGame,
            'source_points' => (int) $source->pts,
            'source_team_points_rank' => $sourceRank,
            'source_role_bucket' => $sourceRoleBucket,
            'target_team_points_rank' => $targetRank,
            'target_role_bucket' => $targetRoleBucket,
            'projected_games' => $projectedGames,
            'projected_toi_seconds' => $projectedToiSeconds,
            'projected_toi_per_game_seconds' => $projectedToiPerGame,
            'projected_toi_hours' => round($projectedToiSeconds / 3600, 4),
            'age_adjustment_seconds_per_game' => $ageAdjustment,
            'role_adjustment_seconds_per_game' => $roleAdjustment,
            'team_change_adjustment_seconds_per_game' => 0.0,
            'confidence_score' => $confidenceScore,
            'confidence_bucket' => $this->confidenceBucket($confidenceScore),
            'status' => 'draft',
            'projection_inputs' => json_encode([
                'method' => 'source_toi_with_age_role_rank_adjustments',
                'source_toi_per_game_seconds' => $sourceToiPerGame,
                'source_role_bucket' => $sourceRoleBucket,
                'target_role_bucket' => $targetRoleBucket,
            ], JSON_THROW_ON_ERROR),
            'flags' => json_encode($this->flags($sourceGames, $age, $sourceRank, $targetRank, $source), JSON_THROW_ON_ERROR),
            'metadata' => json_encode(['builder' => 'NhlPlayerToiProjectionBuilder'], JSON_THROW_ON_ERROR),
            'projected_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function rankSubquery(string $sourceSeasonId, string $teamColumn)
    {
        return DB::table('nhl_season_stats as stats')
            ->join('players', 'players.nhl_id', '=', 'stats.nhl_player_id')
            ->selectRaw('stats.nhl_player_id')
            ->selectRaw('stats.' . $teamColumn . ' as team_id')
            ->selectRaw(
                'ROW_NUMBER() OVER (
                    PARTITION BY stats.' . $teamColumn . ", COALESCE(NULLIF(players.pos_type, ''), players.position)
                    ORDER BY stats.pts DESC, stats.toi DESC, stats.nhl_player_id ASC
                ) as team_points_rank"
            )
            ->where('stats.season_id', $sourceSeasonId)
            ->where('stats.game_type', self::REGULAR_SEASON_GAME_TYPE)
            ->where('stats.gp', '>', 0)
            ->where(function ($query): void {
                $query->whereNull('players.pos_type')
                    ->orWhere('players.pos_type', '<>', 'G');
            });
    }

    private function targetRankSubquery(string $sourceSeasonId)
    {
        return DB::table('nhl_season_stats as stats')
            ->join('players', 'players.nhl_id', '=', 'stats.nhl_player_id')
            ->selectRaw('stats.nhl_player_id')
            ->selectRaw('players.team_abbrev')
            ->selectRaw(
                "ROW_NUMBER() OVER (
                    PARTITION BY players.team_abbrev, COALESCE(NULLIF(players.pos_type, ''), players.position)
                    ORDER BY stats.pts DESC, stats.toi DESC, stats.nhl_player_id ASC
                ) as team_points_rank"
            )
            ->where('stats.season_id', $sourceSeasonId)
            ->where('stats.game_type', self::REGULAR_SEASON_GAME_TYPE)
            ->where('stats.gp', '>', 0)
            ->whereNotNull('players.team_abbrev')
            ->where(function ($query): void {
                $query->whereNull('players.pos_type')
                    ->orWhere('players.pos_type', '<>', 'G');
            });
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
        if ($dob === null || $dob === '') {
            return null;
        }

        $targetYear = (int) mb_substr($targetSeasonId, 0, 4);
        $targetDate = Carbon::create($targetYear, 9, 15);

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

    private function confidenceScore(float $sourceGames, ?int $sourceRank, ?int $targetRank, object $source): float
    {
        $score = min(1.0, $sourceGames / self::TARGET_SEASON_GAMES);

        if ($sourceRank === null || $targetRank === null) {
            $score -= 0.15;
        }

        if ($source->source_team_abbrev !== null && $source->target_team_abbrev !== null && $source->source_team_abbrev !== $source->target_team_abbrev) {
            $score -= 0.15;
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
    private function flags(float $sourceGames, ?float $age, ?int $sourceRank, ?int $targetRank, object $source): array
    {
        $flags = [];

        if ($sourceGames < 40) {
            $flags[] = 'limited_source_games';
        }

        if ($age === null) {
            $flags[] = 'missing_age';
        }

        if ($sourceRank === null || $targetRank === null) {
            $flags[] = 'missing_team_rank';
        }

        if ($source->source_team_abbrev !== null && $source->target_team_abbrev !== null && $source->source_team_abbrev !== $source->target_team_abbrev) {
            $flags[] = 'team_changed';
        }

        return $flags;
    }
}
