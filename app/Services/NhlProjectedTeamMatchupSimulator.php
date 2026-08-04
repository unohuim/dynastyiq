<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Computes projected team-vs-team shot profile matchups without persisting team buckets.
 */
class NhlProjectedTeamMatchupSimulator
{
    private const REGULAR_SEASON_GAME_TYPE = 2;
    private const TARGET_SEASON_GAMES = 84.0;
    private const STRENGTH_EV = 'ev';
    private const STRENGTH_PK = 'pk';
    private const OFFENSE_ENVIRONMENT_WEIGHT = 0.70;
    private const DEFENSE_ENVIRONMENT_WEIGHT = 0.30;
    private const MIN_PK_OFFENSE_MIX_WEIGHT = 0.75;
    private const MAX_PK_OFFENSE_MIX_WEIGHT = 1.25;
    private const GOALIE_REASON_COVERAGE_TARGET = 0.90;
    private const MAX_EV_GOALIE_REASON_ROWS = 5;
    private const MAX_PP_GOALIE_REASON_ROWS = 3;
    private const MIN_GOALIE_REASON_ROWS = 3;
    private const PROFILE_COVERAGE_TARGET = 0.90;
    private const MAX_PROFILE_REASON_ROWS = 5;

    /**
     * @return array<string, mixed>
     */
    public function simulate(
        string $sourceSeasonId,
        string $targetSeasonId,
        string $projectionVersion,
        string $toiProjectionVersion,
        string $goalieProjectionVersion,
        string $teamA,
        string $teamB,
        ?int $teamAGoalieId = null,
        ?int $teamBGoalieId = null
    ): array {
        $teamA = mb_strtoupper($teamA);
        $teamB = mb_strtoupper($teamB);

        if (!$this->tablesExist()) {
            return [
                'is_available' => false,
                'error' => 'Build TOI projections, skater projections, skater O profiles, and skater D profiles before simulating matchups.',
            ];
        }

        return [
            'is_available' => true,
            'inputs' => [
                'source_season_id' => $sourceSeasonId,
                'target_season_id' => $targetSeasonId,
                'projection_version' => $projectionVersion,
                'toi_projection_version' => $toiProjectionVersion,
                'goalie_projection_version' => $goalieProjectionVersion,
                'team_a' => $teamA,
                'team_b' => $teamB,
                'team_a_goalie_id' => $teamAGoalieId,
                'team_b_goalie_id' => $teamBGoalieId,
                'method' => 'projected_ev_offense_with_composed_defensive_chance_context_and_projected_goalie_ev_pk_buckets',
            ],
            'sides' => [
                $this->simulateSide($sourceSeasonId, $targetSeasonId, $projectionVersion, $toiProjectionVersion, $goalieProjectionVersion, $teamA, $teamB, $teamBGoalieId),
                $this->simulateSide($sourceSeasonId, $targetSeasonId, $projectionVersion, $toiProjectionVersion, $goalieProjectionVersion, $teamB, $teamA, $teamAGoalieId),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function simulateSide(
        string $sourceSeasonId,
        string $targetSeasonId,
        string $projectionVersion,
        string $toiProjectionVersion,
        string $goalieProjectionVersion,
        string $offenseTeam,
        string $defenseTeam,
        ?int $goalieId
    ): array {
        $offenseBuckets = $this->offenseBuckets($targetSeasonId, $projectionVersion, $toiProjectionVersion, $offenseTeam);
        $teamDefenseBuckets = $this->defenseBuckets($sourceSeasonId, $targetSeasonId, $toiProjectionVersion, $offenseTeam);
        $opponentDefenseBuckets = $this->defenseBuckets($sourceSeasonId, $targetSeasonId, $toiProjectionVersion, $defenseTeam);
        $baseline = $this->summary($offenseBuckets, 'baseline_');
        $projectedGames = $this->projectedGames($offenseBuckets);
        $baselineXsat = max(0.01, $baseline['baseline_xsat']);
        $offenseRows = $offenseBuckets
            ->map(function (object $bucket) use ($baselineXsat, $projectedGames): array {
                $key = (string) $bucket->matched_bucket_key;
                $offenseShare = ((float) $bucket->baseline_xsat) / $baselineXsat;
                $adjustedXsat = round((float) $bucket->baseline_xsat, 2);
                $adjustedXsog = round((float) $bucket->baseline_xsog, 2);
                $adjustedXgf = round((float) $bucket->baseline_xgf, 4);

                return [
                    'matched_bucket_key' => $key,
                    'shot_type_group' => $bucket->shot_type_group,
                    'distance_group' => $bucket->distance_group,
                    'angle_group' => $bucket->angle_group,
                    'sequence_group' => $bucket->sequence_group,
                    'baseline_xsat' => round((float) $bucket->baseline_xsat, 2),
                    'baseline_xsog' => round((float) $bucket->baseline_xsog, 2),
                    'baseline_xgf' => round((float) $bucket->baseline_xgf, 4),
                    'adjusted_xsat' => $adjustedXsat,
                    'adjusted_xsog' => $adjustedXsog,
                    'adjusted_xgf' => $adjustedXgf,
                    'xgf_delta' => round($adjustedXgf - (float) $bucket->baseline_xgf, 4),
                    'xsog_delta' => round($adjustedXsog - (float) $bucket->baseline_xsog, 2),
                    'xsat_delta' => round($adjustedXsat - (float) $bucket->baseline_xsat, 2),
                    'baseline_xgf_per_game' => round(((float) $bucket->baseline_xgf) / $projectedGames, 4),
                    'xgf_delta_per_game' => round(($adjustedXgf - (float) $bucket->baseline_xgf) / $projectedGames, 4),
                    'offense_share' => round($offenseShare, 6),
                    'suppressed' => false,
                ];
            })
            ->sortByDesc('baseline_xgf')
            ->values();
        $goalieEnvironmentRows = $this->adjustedGoalieEnvironmentRows($offenseRows, $opponentDefenseBuckets, $baseline);
        $goalie = $this->goalie($targetSeasonId, $goalieProjectionVersion, $defenseTeam, $goalieId);
        $evGoalieBuckets = $goalie === null
            ? collect()
            : $this->goalieBuckets($targetSeasonId, $goalieProjectionVersion, (int) $goalie['goalie_player_id'], self::STRENGTH_EV);
        $pkGoalieBuckets = $goalie === null
            ? collect()
            : $this->goalieBuckets($targetSeasonId, $goalieProjectionVersion, (int) $goalie['goalie_player_id'], self::STRENGTH_PK);
        $rows = $this->applyEvGoalieAdjustments($goalieEnvironmentRows, $evGoalieBuckets, $goalie, $projectedGames);
        $pk = $this->applyPkGoalieAdjustments($goalieEnvironmentRows, $pkGoalieBuckets, $goalie, $projectedGames);
        $goalieReasons = $this->goalieReasons($rows->merge($pk['rows']));

        $adjusted = [
            'adjusted_xsat' => round((float) $rows->sum('adjusted_xsat'), 2),
            'adjusted_xsog' => round((float) $rows->sum('adjusted_xsog'), 2),
            'adjusted_xgf' => round((float) $rows->sum('adjusted_xgf'), 4),
            'goalie_adjusted_xgf' => round((float) $rows->sum('goalie_adjusted_xgf'), 4),
        ];
        $totalGoalieAdjustedXgf = $adjusted['goalie_adjusted_xgf'] + $pk['goalie_adjusted_xgf'];
        $penaltiesByBucket = $rows->mapWithKeys(fn (array $row): array => [
            (string) $row['matched_bucket_key'] => 0.0,
        ]);
        $perGame = [
            'baseline_xsat_per_game' => round($baseline['baseline_xsat'] / $projectedGames, 2),
            'baseline_xsog_per_game' => round($baseline['baseline_xsog'] / $projectedGames, 2),
            'baseline_xgf_per_game' => round($baseline['baseline_xgf'] / $projectedGames, 4),
            'adjusted_xsat_per_game' => round($adjusted['adjusted_xsat'] / $projectedGames, 2),
            'adjusted_xsog_per_game' => round($adjusted['adjusted_xsog'] / $projectedGames, 2),
            'adjusted_xgf_per_game' => round($adjusted['adjusted_xgf'] / $projectedGames, 4),
            'goalie_adjusted_xgf_per_game' => round($adjusted['goalie_adjusted_xgf'] / $projectedGames, 4),
            'pk_xgf_per_game' => round($pk['xgf'] / $projectedGames, 4),
            'pk_goalie_adjusted_xgf_per_game' => round($pk['goalie_adjusted_xgf'] / $projectedGames, 4),
            'pk_goalie_adjustment_per_game' => round($pk['goalie_adjustment'] / $projectedGames, 4),
            'total_goalie_adjusted_xgf_per_game' => round($totalGoalieAdjustedXgf / $projectedGames, 4),
            'xsat_delta_per_game' => round(($adjusted['adjusted_xsat'] - $baseline['baseline_xsat']) / $projectedGames, 2),
            'xsog_delta_per_game' => round(($adjusted['adjusted_xsog'] - $baseline['baseline_xsog']) / $projectedGames, 2),
            'xgf_delta_per_game' => round(($adjusted['adjusted_xgf'] - $baseline['baseline_xgf']) / $projectedGames, 4),
            'goalie_adjustment_per_game' => round(($adjusted['goalie_adjusted_xgf'] - $adjusted['adjusted_xgf']) / $projectedGames, 4),
            'total_goalie_adjustment_per_game' => round(($totalGoalieAdjustedXgf - $adjusted['adjusted_xgf'] - $pk['xgf']) / $projectedGames, 4),
        ];

        return [
            'offense_team' => $offenseTeam,
            'defense_team' => $defenseTeam,
            'goalie' => $goalie,
            'summary' => array_merge($baseline, $adjusted, [
                'projected_games' => round($projectedGames, 2),
                'xgf_delta' => round($adjusted['adjusted_xgf'] - $baseline['baseline_xgf'], 4),
                'goalie_adjustment' => round($adjusted['goalie_adjusted_xgf'] - $adjusted['adjusted_xgf'], 4),
                'pk_xgf' => round($pk['xgf'], 4),
                'pk_goalie_adjusted_xgf' => round($pk['goalie_adjusted_xgf'], 4),
                'pk_goalie_adjustment' => round($pk['goalie_adjustment'], 4),
                'total_goalie_adjusted_xgf' => round($totalGoalieAdjustedXgf, 4),
                'total_goalie_adjustment' => round($totalGoalieAdjustedXgf - $adjusted['adjusted_xgf'] - $pk['xgf'], 4),
                'xsog_delta' => round($adjusted['adjusted_xsog'] - $baseline['baseline_xsog'], 2),
                'xsat_delta' => round($adjusted['adjusted_xsat'] - $baseline['baseline_xsat'], 2),
                'offense_bucket_count' => $rows->count(),
            ], $perGame),
            'offense_profile' => $this->profileReasons($offenseRows, $projectedGames),
            'defense_profile' => $this->profileReasons($teamDefenseBuckets, $projectedGames),
            'goalie_environment_profile' => $this->profileReasons($goalieEnvironmentRows, $projectedGames),
            'goalie_reasons' => $goalieReasons['rows'],
            'goalie_reason_summary' => $goalieReasons['summary'],
            'roster' => $this->rosterRows(
                $targetSeasonId,
                $projectionVersion,
                $toiProjectionVersion,
                $offenseTeam,
                $adjusted['adjusted_xgf'],
                $projectedGames,
                $penaltiesByBucket
            ),
            'buckets' => $rows->take(40)->values(),
        ];
    }

    /**
     * @return Collection<int, object>
     */
    private function offenseBuckets(
        string $targetSeasonId,
        string $projectionVersion,
        string $toiProjectionVersion,
        string $team
    ): Collection {
        return DB::table('nhl_player_projection_profile_buckets as buckets')
            ->join('nhl_player_season_projections as projections', function ($join) use ($targetSeasonId, $projectionVersion): void {
                $join->on('projections.player_id', '=', 'buckets.player_id')
                    ->where('projections.target_season_id', '=', $targetSeasonId)
                    ->where('projections.projection_version', '=', $projectionVersion);
            })
            ->join('nhl_player_toi_projections as toi', function ($join) use ($targetSeasonId, $toiProjectionVersion): void {
                $join->on('toi.player_id', '=', 'buckets.player_id')
                    ->where('toi.target_season_id', '=', $targetSeasonId)
                    ->where('toi.projection_version', '=', $toiProjectionVersion);
            })
            ->where('buckets.target_season_id', $targetSeasonId)
            ->where('buckets.projection_version', $projectionVersion)
            ->where('toi.target_team_abbrev', $team)
            ->selectRaw('buckets.matched_bucket_key')
            ->selectRaw('MAX(buckets.shot_type_group) as shot_type_group')
            ->selectRaw('MAX(buckets.distance_group) as distance_group')
            ->selectRaw('MAX(buckets.angle_group) as angle_group')
            ->selectRaw('MAX(buckets.sequence_group) as sequence_group')
            ->selectRaw('MAX(projections.projected_games) as projected_games')
            ->selectRaw('SUM(buckets.projected_xsat) as baseline_xsat')
            ->selectRaw('SUM(buckets.projected_xsog) as baseline_xsog')
            ->selectRaw('SUM(buckets.projected_xgf) as baseline_xgf')
            ->groupBy('buckets.matched_bucket_key')
            ->havingRaw('SUM(buckets.projected_xsat) > 0')
            ->orderByDesc('baseline_xgf')
            ->get();
    }

    /**
     * @return Collection<int, object>
     */
    private function defenseBuckets(
        string $sourceSeasonId,
        string $targetSeasonId,
        string $toiProjectionVersion,
        string $team
    ): Collection {
        $rows = DB::table('nhl_skater_defensive_chance_profile_buckets as profiles')
            ->join('nhl_player_toi_projections as toi', function ($join) use ($sourceSeasonId, $targetSeasonId, $toiProjectionVersion): void {
                $join->on('toi.player_id', '=', 'profiles.player_id')
                    ->where('toi.source_season_id', '=', $sourceSeasonId)
                    ->where('toi.target_season_id', '=', $targetSeasonId)
                    ->where('toi.projection_version', '=', $toiProjectionVersion);
            })
            ->where('profiles.source_season_id', $sourceSeasonId)
            ->where('profiles.game_type', self::REGULAR_SEASON_GAME_TYPE)
            ->where('profiles.fallback_level', 1)
            ->where('toi.target_team_abbrev', $team)
            ->selectRaw('profiles.matched_bucket_key')
            ->selectRaw('MAX(profiles.shot_type_group) as shot_type_group')
            ->selectRaw('MAX(profiles.distance_group) as distance_group')
            ->selectRaw('MAX(profiles.angle_group) as angle_group')
            ->selectRaw('MAX(profiles.sequence_group) as sequence_group')
            ->selectRaw('SUM((profiles.source_sat_against_on_ice * (COALESCE(toi.projected_toi_hours, 0) / NULLIF(profiles.source_toi_seconds::numeric / 3600, 0))) / 5) as baseline_xsat')
            ->selectRaw('SUM((profiles.source_xsoga_on_ice * (COALESCE(toi.projected_toi_hours, 0) / NULLIF(profiles.source_toi_seconds::numeric / 3600, 0))) / 5) as baseline_xsog')
            ->selectRaw('SUM((profiles.source_xga_on_ice * (COALESCE(toi.projected_toi_hours, 0) / NULLIF(profiles.source_toi_seconds::numeric / 3600, 0))) / 5) as baseline_xgf')
            ->selectRaw('AVG(profiles.confidence_score) as confidence_score')
            ->groupBy('profiles.matched_bucket_key')
            ->havingRaw('SUM((profiles.source_sat_against_on_ice * (COALESCE(toi.projected_toi_hours, 0) / NULLIF(profiles.source_toi_seconds::numeric / 3600, 0))) / 5) > 0')
            ->get();

        return $rows->values();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function goalie(
        string $targetSeasonId,
        string $goalieProjectionVersion,
        string $team,
        ?int $goalieId
    ): ?array
    {
        if (
            $goalieId === null
            || $goalieProjectionVersion === ''
            || !$this->goalieProjectionTablesExist()
        ) {
            return null;
        }

        $row = DB::table('nhl_goalie_season_projections as projections')
            ->leftJoin('players', 'players.nhl_id', '=', 'projections.goalie_player_id')
            ->where('projections.target_season_id', $targetSeasonId)
            ->where('projections.projection_version', $goalieProjectionVersion)
            ->where('projections.target_team_abbrev', mb_strtoupper($team))
            ->where('projections.goalie_player_id', $goalieId)
            ->selectRaw('projections.goalie_player_id')
            ->selectRaw("COALESCE(players.full_name, projections.goalie_player_id::text) as goalie_name")
            ->selectRaw('projections.source_team_abbrev')
            ->selectRaw('projections.target_team_abbrev')
            ->selectRaw('projections.source_games')
            ->selectRaw('projections.source_gsax')
            ->selectRaw('projections.projected_games')
            ->selectRaw('projections.projected_starts')
            ->selectRaw('projections.projected_toi_seconds')
            ->selectRaw('projections.projected_xga')
            ->selectRaw('projections.projected_ga')
            ->selectRaw('projections.projected_ev_xga')
            ->selectRaw('projections.projected_ev_ga')
            ->selectRaw('projections.projected_pk_sata')
            ->selectRaw('projections.projected_pk_soga')
            ->selectRaw('projections.projected_pk_xga')
            ->selectRaw('projections.projected_pk_ga')
            ->selectRaw('projections.confidence_score')
            ->selectRaw('projections.confidence_bucket')
            ->first();

        if ($row === null) {
            return null;
        }

        return [
            'goalie_player_id' => (int) $row->goalie_player_id,
            'goalie_name' => (string) $row->goalie_name,
            'source_team_abbrev' => $row->source_team_abbrev,
            'team_abbrev' => $row->target_team_abbrev,
            'source_games' => $row->source_games === null ? null : round((float) $row->source_games, 2),
            'source_gsax' => $row->source_gsax === null ? null : round((float) $row->source_gsax, 4),
            'projected_games' => $row->projected_games === null ? null : round((float) $row->projected_games, 2),
            'projected_starts' => $row->projected_starts === null ? null : round((float) $row->projected_starts, 2),
            'projected_xgaa' => $this->projectedGoalsAgainstAverage($row->projected_xga, $row->projected_toi_seconds),
            'projected_gaa' => $this->projectedGoalsAgainstAverage($row->projected_ga, $row->projected_toi_seconds),
            'projected_ev_xga' => $row->projected_ev_xga === null ? null : round((float) $row->projected_ev_xga, 4),
            'projected_ev_ga' => $row->projected_ev_ga === null ? null : round((float) $row->projected_ev_ga, 4),
            'projected_pk_sata' => $row->projected_pk_sata === null ? null : round((float) $row->projected_pk_sata, 2),
            'projected_pk_soga' => $row->projected_pk_soga === null ? null : round((float) $row->projected_pk_soga, 2),
            'projected_pk_xga' => $row->projected_pk_xga === null ? null : round((float) $row->projected_pk_xga, 4),
            'projected_pk_ga' => $row->projected_pk_ga === null ? null : round((float) $row->projected_pk_ga, 4),
            'confidence_score' => $row->confidence_score === null ? null : round((float) $row->confidence_score, 4),
            'confidence_bucket' => $row->confidence_bucket,
            'is_auto' => false,
        ];
    }

    /**
     * @return Collection<int, object>
     */
    private function goalieBuckets(
        string $targetSeasonId,
        string $goalieProjectionVersion,
        int $goalieId,
        string $strength
    ): Collection
    {
        return DB::table('nhl_goalie_projection_chance_buckets')
            ->where('target_season_id', $targetSeasonId)
            ->where('projection_version', $goalieProjectionVersion)
            ->where('goalie_player_id', $goalieId)
            ->where('projection_strength', $strength)
            ->select([
                'matched_bucket_key',
                'shot_type_group',
                'distance_group',
                'angle_group',
                'sequence_group',
                'projected_profile_share',
                'projected_sata',
                'projected_soga',
                'projected_xga',
                'projected_ga',
                'confidence_score',
            ])
            ->get()
            ->keyBy('matched_bucket_key');
    }

    /**
     * @param Collection<int, array<string, mixed>> $rows
     * @param Collection<string, object> $goalieBuckets
     * @param array<string, mixed>|null $goalie
     * @return Collection<int, array<string, mixed>>
     */
    private function applyEvGoalieAdjustments(
        Collection $rows,
        Collection $goalieBuckets,
        ?array $goalie,
        float $projectedGames
    ): Collection
    {
        $projectedGames = $projectedGames > 0 ? $projectedGames : self::TARGET_SEASON_GAMES;
        $hasGoalieProjection = $goalie !== null;
        $bucketProjectedGa = (float) $goalieBuckets->sum('projected_ga');
        $bucketProjectedXga = (float) $goalieBuckets->sum('projected_xga');
        $seasonEvProjectedXga = $goalie === null ? 0.0 : (float) ($goalie['projected_ev_xga'] ?? 0);
        $seasonEvProjectedGa = $goalie === null ? 0.0 : (float) ($goalie['projected_ev_ga'] ?? 0);
        $seasonCalibration = $bucketProjectedGa > 0 && $seasonEvProjectedGa > 0
            ? $seasonEvProjectedGa / $bucketProjectedGa
            : 1.0;
        $seasonGoalieRatio = $seasonEvProjectedXga > 0 && $seasonEvProjectedGa > 0
            ? $seasonEvProjectedGa / $seasonEvProjectedXga
            : ($bucketProjectedXga > 0 && $bucketProjectedGa > 0 ? $bucketProjectedGa / $bucketProjectedXga : 1.0);

        return $rows
            ->map(function (array $row) use ($goalieBuckets, $projectedGames, $seasonCalibration, $seasonGoalieRatio, $hasGoalieProjection): array {
                $goalieBucket = $goalieBuckets->get((string) $row['matched_bucket_key']);
                $goalieConfidence = 0.0;
                $goalieRatio = $seasonGoalieRatio;
                $goalieProfileShare = null;
                $reasonSource = 'season';

                if ($goalieBucket !== null) {
                    $projectedXga = (float) ($goalieBucket->projected_xga ?? 0);
                    $projectedGa = (float) ($goalieBucket->projected_ga ?? 0) * $seasonCalibration;
                    $goalieConfidence = (float) ($goalieBucket->confidence_score ?? 0);
                    $goalieProfileShare = $goalieBucket->projected_profile_share;
                    $reasonSource = 'bucket';

                    if ($projectedXga > 0) {
                        $bucketRatio = $projectedGa / $projectedXga;
                        $goalieRatio = $seasonGoalieRatio + (($bucketRatio - $seasonGoalieRatio) * $goalieConfidence);
                    }
                }

                $goalieAdjustment = ((float) $row['adjusted_xgf']) * ($goalieRatio - 1);
                $goalieAdjustedXgf = max(0.0, ((float) $row['adjusted_xgf']) + $goalieAdjustment);
                $row['projection_strength'] = self::STRENGTH_EV;
                $row['goalie_adjustment'] = round($goalieAdjustment, 4);
                $row['goalie_adjustment_per_game'] = round($goalieAdjustment / $projectedGames, 4);
                $row['goalie_adjusted_xgf'] = round($goalieAdjustedXgf, 4);
                $row['goalie_adjusted_xgf_per_game'] = round($goalieAdjustedXgf / $projectedGames, 4);
                $row['adjusted_xsat_per_game'] = round(((float) ($row['adjusted_xsat'] ?? 0)) / $projectedGames, 3);
                $row['adjusted_xsog_per_game'] = round(((float) ($row['adjusted_xsog'] ?? 0)) / $projectedGames, 3);
                $row['adjusted_xgf_per_game'] = round(((float) ($row['adjusted_xgf'] ?? 0)) / $projectedGames, 4);
                $row['goalie_confidence'] = round($goalieConfidence, 4);
                $row['goalie_ga_xga_ratio'] = round($goalieRatio, 4);
                $row['season_goalie_ga_xga_ratio'] = round($seasonGoalieRatio, 4);
                $row['goalie_profile_share'] = $goalieProfileShare === null ? null : round((float) $goalieProfileShare, 6);
                $row['goalie_reason_source'] = $reasonSource;
                $row['goalie_reason_eligible'] = $hasGoalieProjection;

                return $row;
            })
            ->values();
    }

    /**
     * @param Collection<int, array<string, mixed>> $offenseRows
     * @param Collection<int, object> $defenseRows
     * @param array<string, float> $offenseTotals
     * @return Collection<int, array<string, mixed>>
     */
    private function adjustedGoalieEnvironmentRows(Collection $offenseRows, Collection $defenseRows, array $offenseTotals): Collection
    {
        $offenseByBucket = $offenseRows->keyBy('matched_bucket_key');
        $defenseByBucket = $defenseRows->keyBy('matched_bucket_key');
        $keys = $offenseByBucket
            ->keys()
            ->merge($defenseByBucket->keys())
            ->unique()
            ->values();
        $offenseTotalXsat = max(0.01, (float) ($offenseTotals['baseline_xsat'] ?? 0));
        $offenseTotalXsog = max(0.01, (float) ($offenseTotals['baseline_xsog'] ?? 0));
        $offenseTotalXgf = max(0.01, (float) ($offenseTotals['baseline_xgf'] ?? 0));
        $defenseTotalXsat = max(0.01, (float) $defenseRows->sum('baseline_xsat'));
        $defenseTotalXsog = max(0.01, (float) $defenseRows->sum('baseline_xsog'));
        $defenseTotalXgf = max(0.01, (float) $defenseRows->sum('baseline_xgf'));

        return $keys
            ->map(function (mixed $key) use (
                $offenseByBucket,
                $defenseByBucket,
                $offenseTotalXsat,
                $offenseTotalXsog,
                $offenseTotalXgf,
                $defenseTotalXsat,
                $defenseTotalXsog,
                $defenseTotalXgf
            ): array {
                $bucketKey = (string) $key;
                $offense = $offenseByBucket->get($bucketKey);
                $defense = $defenseByBucket->get($bucketKey);
                $dimensionSource = $offense ?? $defense;
                $xsatShare = (self::OFFENSE_ENVIRONMENT_WEIGHT * $this->ratio((float) $this->rowValue($offense, 'adjusted_xsat'), $offenseTotalXsat))
                    + (self::DEFENSE_ENVIRONMENT_WEIGHT * $this->ratio((float) $this->rowValue($defense, 'baseline_xsat'), $defenseTotalXsat));
                $xsogShare = (self::OFFENSE_ENVIRONMENT_WEIGHT * $this->ratio((float) $this->rowValue($offense, 'adjusted_xsog'), $offenseTotalXsog))
                    + (self::DEFENSE_ENVIRONMENT_WEIGHT * $this->ratio((float) $this->rowValue($defense, 'baseline_xsog'), $defenseTotalXsog));
                $xgfShare = (self::OFFENSE_ENVIRONMENT_WEIGHT * $this->ratio((float) $this->rowValue($offense, 'adjusted_xgf'), $offenseTotalXgf))
                    + (self::DEFENSE_ENVIRONMENT_WEIGHT * $this->ratio((float) $this->rowValue($defense, 'baseline_xgf'), $defenseTotalXgf));
                $adjustedXsat = $xsatShare * $offenseTotalXsat;
                $adjustedXsog = $xsogShare * $offenseTotalXsog;
                $adjustedXgf = $xgfShare * $offenseTotalXgf;

                return [
                    'matched_bucket_key' => $bucketKey,
                    'shot_type_group' => $this->rowValue($dimensionSource, 'shot_type_group') ?? 'Any',
                    'distance_group' => $this->rowValue($dimensionSource, 'distance_group') ?? 'Any',
                    'angle_group' => $this->rowValue($dimensionSource, 'angle_group') ?? 'Any',
                    'sequence_group' => $this->rowValue($dimensionSource, 'sequence_group') ?? 'Any',
                    'baseline_xsat' => round((float) $this->rowValue($offense, 'baseline_xsat'), 2),
                    'baseline_xsog' => round((float) $this->rowValue($offense, 'baseline_xsog'), 2),
                    'baseline_xgf' => round((float) $this->rowValue($offense, 'baseline_xgf'), 4),
                    'adjusted_xsat' => round($adjustedXsat, 2),
                    'adjusted_xsog' => round($adjustedXsog, 2),
                    'adjusted_xgf' => round($adjustedXgf, 4),
                    'xgf_delta' => round($adjustedXgf - (float) $this->rowValue($offense, 'baseline_xgf'), 4),
                    'xsog_delta' => round($adjustedXsog - (float) $this->rowValue($offense, 'baseline_xsog'), 2),
                    'xsat_delta' => round($adjustedXsat - (float) $this->rowValue($offense, 'baseline_xsat'), 2),
                    'offense_share' => round($xsatShare, 6),
                    'environment_offense_weight' => self::OFFENSE_ENVIRONMENT_WEIGHT,
                    'environment_defense_weight' => self::DEFENSE_ENVIRONMENT_WEIGHT,
                    'suppressed' => false,
                ];
            })
            ->filter(fn (array $row): bool => (float) $row['adjusted_xsat'] > 0 || (float) $row['adjusted_xsog'] > 0 || (float) $row['adjusted_xgf'] > 0)
            ->sortByDesc('adjusted_xgf')
            ->values();
    }

    /**
     * @param Collection<int, array<string, mixed>|object> $offenseBuckets
     * @param Collection<string, object> $goalieBuckets
     * @param array<string, mixed>|null $goalie
     * @return array{xgf:float,goalie_adjusted_xgf:float,goalie_adjustment:float,rows:Collection<int,array<string,mixed>>}
     */
    private function applyPkGoalieAdjustments(
        Collection $offenseBuckets,
        Collection $goalieBuckets,
        ?array $goalie,
        float $projectedGames
    ): array
    {
        $projectedGames = $projectedGames > 0 ? $projectedGames : self::TARGET_SEASON_GAMES;
        $seasonPkSata = $goalie === null ? 0.0 : (float) ($goalie['projected_pk_sata'] ?? 0);
        $seasonPkSoga = $goalie === null ? 0.0 : (float) ($goalie['projected_pk_soga'] ?? 0);
        $seasonPkXga = $goalie === null ? 0.0 : (float) ($goalie['projected_pk_xga'] ?? 0);
        $seasonPkGa = $goalie === null ? 0.0 : (float) ($goalie['projected_pk_ga'] ?? 0);

        if ($seasonPkXga <= 0 || $goalieBuckets->isEmpty()) {
            return [
                'xgf' => 0.0,
                'goalie_adjusted_xgf' => 0.0,
                'goalie_adjustment' => 0.0,
                'rows' => collect(),
            ];
        }

        $offenseXsat = max(0.01, (float) $offenseBuckets->sum(
            fn (array|object $bucket): float => (float) $this->rowValue($bucket, 'adjusted_xsat', $this->rowValue($bucket, 'baseline_xsat'))
        ));
        $offenseShares = $offenseBuckets->mapWithKeys(fn (array|object $bucket): array => [
            (string) $this->rowValue($bucket, 'matched_bucket_key') => (float) $this->rowValue($bucket, 'adjusted_xsat', $this->rowValue($bucket, 'baseline_xsat')) / $offenseXsat,
        ]);
        $bucketProjectedXga = max(0.01, (float) $goalieBuckets->sum('projected_xga'));
        $bucketProjectedGa = max(0.01, (float) $goalieBuckets->sum('projected_ga'));
        $bucketProjectedSata = max(0.01, (float) $goalieBuckets->sum('projected_sata'));
        $bucketProjectedSoga = max(0.01, (float) $goalieBuckets->sum('projected_soga'));
        $sataCalibration = $seasonPkSata > 0 ? $seasonPkSata / $bucketProjectedSata : 1.0;
        $sogaCalibration = $seasonPkSoga > 0 ? $seasonPkSoga / $bucketProjectedSoga : 1.0;
        $xgaCalibration = $seasonPkXga / $bucketProjectedXga;
        $gaCalibration = $seasonPkGa > 0 ? $seasonPkGa / $bucketProjectedGa : $xgaCalibration;
        $seasonGoalieRatio = $seasonPkGa > 0 ? $seasonPkGa / $seasonPkXga : 1.0;

        $weighted = $goalieBuckets
            ->map(function (object $bucket) use ($offenseShares, $sataCalibration, $sogaCalibration, $xgaCalibration, $gaCalibration): array {
                $bucketShare = max(0.001, (float) ($bucket->projected_profile_share ?? 0));
                $offenseShare = (float) ($offenseShares->get((string) $bucket->matched_bucket_key) ?? $bucketShare);
                $mixWeight = max(
                    self::MIN_PK_OFFENSE_MIX_WEIGHT,
                    min(self::MAX_PK_OFFENSE_MIX_WEIGHT, $offenseShare / $bucketShare)
                );

                return [
                    'bucket' => $bucket,
                    'offense_share' => $offenseShare,
                    'mix_weight' => $mixWeight,
                    'weighted_sata' => ((float) ($bucket->projected_sata ?? 0)) * $sataCalibration * $mixWeight,
                    'weighted_soga' => ((float) ($bucket->projected_soga ?? 0)) * $sogaCalibration * $mixWeight,
                    'weighted_xga' => ((float) ($bucket->projected_xga ?? 0)) * $xgaCalibration * $mixWeight,
                    'weighted_ga' => ((float) ($bucket->projected_ga ?? 0)) * $gaCalibration * $mixWeight,
                ];
            })
            ->values();

        $weightedXga = max(0.01, (float) $weighted->sum('weighted_xga'));
        $weightedSata = max(0.01, (float) $weighted->sum('weighted_sata'));
        $weightedSoga = max(0.01, (float) $weighted->sum('weighted_soga'));
        $xgaRenormalizer = $seasonPkXga / $weightedXga;
        $sataRenormalizer = $seasonPkSata > 0 ? $seasonPkSata / $weightedSata : $xgaRenormalizer;
        $sogaRenormalizer = $seasonPkSoga > 0 ? $seasonPkSoga / $weightedSoga : $xgaRenormalizer;
        $rows = $weighted
            ->map(function (array $row) use ($xgaRenormalizer, $sataRenormalizer, $sogaRenormalizer, $projectedGames, $seasonGoalieRatio): array {
                $bucket = $row['bucket'];
                $adjustedXsat = ((float) $row['weighted_sata']) * $sataRenormalizer;
                $adjustedXsog = ((float) $row['weighted_soga']) * $sogaRenormalizer;
                $adjustedXgf = ((float) $row['weighted_xga']) * $xgaRenormalizer;
                $projectedGa = ((float) $row['weighted_ga']) * $xgaRenormalizer;
                $confidence = (float) ($bucket->confidence_score ?? 0);
                $bucketRatio = $adjustedXgf > 0 ? $projectedGa / $adjustedXgf : $seasonGoalieRatio;
                $goalieRatio = $seasonGoalieRatio + (($bucketRatio - $seasonGoalieRatio) * $confidence);
                $goalieAdjustment = $adjustedXgf * ($goalieRatio - 1);

                $goalieAdjustedXgf = max(0.0, $adjustedXgf + $goalieAdjustment);

                return [
                    'projection_strength' => self::STRENGTH_PK,
                    'matched_bucket_key' => (string) $bucket->matched_bucket_key,
                    'shot_type_group' => $bucket->shot_type_group,
                    'distance_group' => $bucket->distance_group,
                    'angle_group' => $bucket->angle_group,
                    'sequence_group' => $bucket->sequence_group,
                    'adjusted_xsat' => round($adjustedXsat, 2),
                    'adjusted_xsog' => round($adjustedXsog, 2),
                    'adjusted_xgf' => round($adjustedXgf, 4),
                    'adjusted_xsat_per_game' => round($adjustedXsat / $projectedGames, 3),
                    'adjusted_xsog_per_game' => round($adjustedXsog / $projectedGames, 3),
                    'adjusted_xgf_per_game' => round($adjustedXgf / $projectedGames, 4),
                    'goalie_adjustment' => round($goalieAdjustment, 4),
                    'goalie_adjustment_per_game' => round($goalieAdjustment / $projectedGames, 4),
                    'goalie_adjusted_xgf' => round($goalieAdjustedXgf, 4),
                    'goalie_adjusted_xgf_per_game' => round($goalieAdjustedXgf / $projectedGames, 4),
                    'goalie_confidence' => round($confidence, 4),
                    'goalie_ga_xga_ratio' => round($goalieRatio, 4),
                    'season_goalie_ga_xga_ratio' => round($seasonGoalieRatio, 4),
                    'offense_share' => round((float) $row['offense_share'], 6),
                    'goalie_profile_share' => round((float) ($bucket->projected_profile_share ?? 0), 6),
                    'pk_mix_weight' => round((float) $row['mix_weight'], 4),
                    'goalie_reason_source' => 'bucket',
                    'goalie_reason_eligible' => true,
                ];
            })
            ->values();

        return [
            'xgf' => round((float) $rows->sum('adjusted_xgf'), 4),
            'goalie_adjusted_xgf' => round((float) $rows->sum('goalie_adjusted_xgf'), 4),
            'goalie_adjustment' => round((float) $rows->sum('goalie_adjustment'), 4),
            'rows' => $rows,
        ];
    }

    /**
     * @param Collection<int, array<string, mixed>|object> $rows
     * @return array{rows:Collection<int,array<string,mixed>>,summary:array<string,mixed>}
     */
    private function profileReasons(Collection $rows, float $projectedGames): array
    {
        $projectedGames = $projectedGames > 0 ? $projectedGames : self::TARGET_SEASON_GAMES;
        $normalized = $rows
            ->map(fn (array|object $row): array => $this->normalizeProfileRow($row, $projectedGames))
            ->filter(fn (array $row): bool => (
                (float) ($row['xsat_per_game'] ?? 0) > 0
                || (float) ($row['xsog_per_game'] ?? 0) > 0
                || (float) ($row['xgf_per_game'] ?? 0) > 0
            ))
            ->values();
        $selected = $this->profileRowsForCoverage($normalized);
        $shownXsatPerGame = (float) $selected->sum('xsat_per_game');
        $shownXsogPerGame = (float) $selected->sum('xsog_per_game');
        $shownXgfPerGame = (float) $selected->sum('xgf_per_game');
        $totalXsatPerGame = (float) $normalized->sum('xsat_per_game');
        $totalXsogPerGame = (float) $normalized->sum('xsog_per_game');
        $totalXgfPerGame = (float) $normalized->sum('xgf_per_game');

        return [
            'rows' => $selected,
            'summary' => [
                'row_count' => $selected->count(),
                'total_row_count' => $normalized->count(),
                'coverage_target' => self::PROFILE_COVERAGE_TARGET,
                'shown_xsat_per_game' => round($shownXsatPerGame, 3),
                'shown_xsog_per_game' => round($shownXsogPerGame, 3),
                'shown_xgf_per_game' => round($shownXgfPerGame, 4),
                'total_xsat_per_game' => round($totalXsatPerGame, 3),
                'total_xsog_per_game' => round($totalXsogPerGame, 3),
                'total_xgf_per_game' => round($totalXgfPerGame, 4),
                'represented_xsat' => round($this->ratio($shownXsatPerGame, $totalXsatPerGame), 4),
                'represented_xsog' => round($this->ratio($shownXsogPerGame, $totalXsogPerGame), 4),
                'represented_xgf' => round($this->ratio($shownXgfPerGame, $totalXgfPerGame), 4),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeProfileRow(array|object $row, float $projectedGames): array
    {
        $value = static fn (string $key): mixed => is_array($row) ? ($row[$key] ?? null) : ($row->{$key} ?? null);
        $xsat = (float) ($value('adjusted_xsat') ?? $value('baseline_xsat') ?? 0);
        $xsog = (float) ($value('adjusted_xsog') ?? $value('baseline_xsog') ?? 0);
        $xgf = (float) ($value('adjusted_xgf') ?? $value('baseline_xgf') ?? 0);

        return [
            'shot_type_group' => $value('shot_type_group') ?? 'Any',
            'distance_group' => $value('distance_group') ?? 'Any',
            'angle_group' => $value('angle_group') ?? 'Any',
            'sequence_group' => $value('sequence_group') ?? 'Any',
            'xsat_per_game' => round($xsat / $projectedGames, 3),
            'xsog_per_game' => round($xsog / $projectedGames, 3),
            'xgf_per_game' => round($xgf / $projectedGames, 4),
        ];
    }

    /**
     * @param Collection<int, array<string, mixed>> $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function profileRowsForCoverage(Collection $rows): Collection
    {
        if ($rows->isEmpty()) {
            return collect();
        }

        $dimensionSets = [
            ['shot_type_group', 'distance_group', 'angle_group', 'sequence_group'],
            ['distance_group', 'angle_group', 'sequence_group'],
            ['distance_group', 'sequence_group'],
            ['distance_group'],
            [],
        ];
        $fallback = collect();

        foreach ($dimensionSets as $dimensions) {
            $rolled = $this->rollupProfileRows($rows, $dimensions);
            $selected = $this->selectProfileRows($rolled);
            $fallback = $selected;

            if (
                $this->ratio((float) $selected->sum('xsat_per_game'), (float) $rows->sum('xsat_per_game')) >= self::PROFILE_COVERAGE_TARGET
                && $this->ratio((float) $selected->sum('xsog_per_game'), (float) $rows->sum('xsog_per_game')) >= self::PROFILE_COVERAGE_TARGET
            ) {
                return $selected;
            }
        }

        return $fallback;
    }

    /**
     * @param Collection<int, array<string, mixed>> $rows
     * @param array<int, string> $dimensions
     * @return Collection<int, array<string, mixed>>
     */
    private function rollupProfileRows(Collection $rows, array $dimensions): Collection
    {
        $totalXsatPerGame = (float) $rows->sum('xsat_per_game');
        $totalXsogPerGame = (float) $rows->sum('xsog_per_game');
        $totalXgfPerGame = (float) $rows->sum('xgf_per_game');
        $dimensionLookup = array_fill_keys($dimensions, true);

        return $rows
            ->groupBy(fn (array $row): string => implode('|', array_map(
                fn (string $dimension): string => (string) ($row[$dimension] ?? 'Any'),
                $dimensions
            )))
            ->map(function (Collection $group) use ($dimensionLookup, $totalXsatPerGame, $totalXsogPerGame, $totalXgfPerGame): array {
                $first = $group->first();
                $xsatPerGame = (float) $group->sum('xsat_per_game');
                $xsogPerGame = (float) $group->sum('xsog_per_game');
                $xgfPerGame = (float) $group->sum('xgf_per_game');

                return [
                    'shot_type_group' => isset($dimensionLookup['shot_type_group']) ? ($first['shot_type_group'] ?? 'Any') : 'Mixed',
                    'distance_group' => isset($dimensionLookup['distance_group']) ? ($first['distance_group'] ?? 'Any') : 'Mixed',
                    'angle_group' => isset($dimensionLookup['angle_group']) ? ($first['angle_group'] ?? 'Any') : 'Mixed',
                    'sequence_group' => isset($dimensionLookup['sequence_group']) ? ($first['sequence_group'] ?? 'Any') : 'Mixed',
                    'xsat_per_game' => round($xsatPerGame, 3),
                    'xsog_per_game' => round($xsogPerGame, 3),
                    'xgf_per_game' => round($xgfPerGame, 4),
                    'represented_xsat' => round($this->ratio($xsatPerGame, $totalXsatPerGame), 6),
                    'represented_xsog' => round($this->ratio($xsogPerGame, $totalXsogPerGame), 6),
                    'represented_xgf' => round($this->ratio($xgfPerGame, $totalXgfPerGame), 6),
                    'bucket_count' => $group->count(),
                ];
            })
            ->sortByDesc(fn (array $row): float => (
                ((float) ($row['xsog_per_game'] ?? 0) * 1000)
                + ((float) ($row['xsat_per_game'] ?? 0) * 10)
                + (float) ($row['xgf_per_game'] ?? 0)
            ))
            ->values();
    }

    /**
     * @param Collection<int, array<string, mixed>> $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function selectProfileRows(Collection $rows): Collection
    {
        $selected = collect();
        $shownXsatPerGame = 0.0;
        $shownXsogPerGame = 0.0;
        $totalXsatPerGame = (float) $rows->sum('xsat_per_game');
        $totalXsogPerGame = (float) $rows->sum('xsog_per_game');

        foreach ($rows as $row) {
            if ($selected->count() >= self::MAX_PROFILE_REASON_ROWS) {
                break;
            }

            $selected->push($row);
            $shownXsatPerGame += (float) ($row['xsat_per_game'] ?? 0);
            $shownXsogPerGame += (float) ($row['xsog_per_game'] ?? 0);

            if (
                $selected->count() >= self::MIN_GOALIE_REASON_ROWS
                && $this->ratio($shownXsatPerGame, $totalXsatPerGame) >= self::PROFILE_COVERAGE_TARGET
                && $this->ratio($shownXsogPerGame, $totalXsogPerGame) >= self::PROFILE_COVERAGE_TARGET
            ) {
                break;
            }
        }

        return $selected->values();
    }

    /**
     * @param Collection<int, array<string, mixed>> $rows
     * @return array{rows:Collection<int,array<string,mixed>>,summary:array<string,mixed>}
     */
    private function goalieReasons(Collection $rows): array
    {
        $eligible = $rows
            ->filter(fn (array $row): bool => (bool) ($row['goalie_reason_eligible'] ?? false))
            ->filter(fn (array $row): bool => (
                (float) ($row['adjusted_xsat_per_game'] ?? 0) > 0
                || (float) ($row['adjusted_xsog_per_game'] ?? 0) > 0
                || (float) ($row['adjusted_xgf_per_game'] ?? 0) > 0
            ))
            ->values();

        $shown = collect([self::STRENGTH_EV, self::STRENGTH_PK])
            ->flatMap(fn (string $strength): Collection => $this->goalieReasonRowsForStrength($eligible, $strength))
            ->values();

        $shownGoalieAdjustmentPerGame = (float) $shown->sum('goalie_adjustment_per_game');
        $shownGoalieAdjustedXgfPerGame = (float) $shown->sum('goalie_adjusted_xgf_per_game');
        $shownXsatPerGame = (float) $shown->sum('adjusted_xsat_per_game');
        $shownXsogPerGame = (float) $shown->sum('adjusted_xsog_per_game');
        $shownXgfPerGame = (float) $shown->sum('adjusted_xgf_per_game');
        $totalXsatPerGame = (float) $eligible->sum('adjusted_xsat_per_game');
        $totalXsogPerGame = (float) $eligible->sum('adjusted_xsog_per_game');
        $totalXgfPerGame = (float) $eligible->sum('adjusted_xgf_per_game');

        return [
            'rows' => $shown->values(),
            'summary' => [
                'row_count' => $shown->count(),
                'total_row_count' => $eligible->count(),
                'coverage_target' => self::GOALIE_REASON_COVERAGE_TARGET,
                'hit_max_rows' => false,
                'shown_xsat_per_game' => round($shownXsatPerGame, 3),
                'shown_xsog_per_game' => round($shownXsogPerGame, 3),
                'shown_xgf_per_game' => round($shownXgfPerGame, 4),
                'shown_goalie_adjustment_per_game' => round($shownGoalieAdjustmentPerGame, 4),
                'shown_goalie_saves_above_expected_per_game' => round(-1 * $shownGoalieAdjustmentPerGame, 4),
                'shown_goalie_adjusted_xgf_per_game' => round($shownGoalieAdjustedXgfPerGame, 4),
                'total_xsat_per_game' => round($totalXsatPerGame, 3),
                'total_xsog_per_game' => round($totalXsogPerGame, 3),
                'total_xgf_per_game' => round($totalXgfPerGame, 4),
                'represented_xsat' => round($this->ratio($shownXsatPerGame, $totalXsatPerGame), 4),
                'represented_xsog' => round($this->ratio($shownXsogPerGame, $totalXsogPerGame), 4),
                'represented_xgf' => round($this->ratio($shownXgfPerGame, $totalXgfPerGame), 4),
            ],
        ];
    }

    /**
     * @param Collection<int, array<string, mixed>> $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function goalieReasonRowsForStrength(Collection $rows, string $strength): Collection
    {
        $strengthRows = $rows
            ->filter(fn (array $row): bool => ($row['projection_strength'] ?? self::STRENGTH_EV) === $strength)
            ->values();

        if ($strengthRows->isEmpty()) {
            return collect();
        }

        $maxRows = $strength === self::STRENGTH_PK
            ? self::MAX_PP_GOALIE_REASON_ROWS
            : self::MAX_EV_GOALIE_REASON_ROWS;
        $dimensionSets = [
            ['shot_type_group', 'distance_group', 'angle_group', 'sequence_group'],
            ['distance_group', 'angle_group', 'sequence_group'],
            ['distance_group', 'sequence_group'],
            ['distance_group'],
            [],
        ];
        $fallback = collect();

        foreach ($dimensionSets as $dimensions) {
            $rolled = $this->rollupGoalieReasonRows($strengthRows, $strength, $dimensions);
            $selected = $this->selectGoalieReasonRows($rolled, $maxRows);
            $fallback = $selected;

            if (
                $this->ratio((float) $selected->sum('adjusted_xsat_per_game'), (float) $strengthRows->sum('adjusted_xsat_per_game')) >= self::GOALIE_REASON_COVERAGE_TARGET
                && $this->ratio((float) $selected->sum('adjusted_xsog_per_game'), (float) $strengthRows->sum('adjusted_xsog_per_game')) >= self::GOALIE_REASON_COVERAGE_TARGET
            ) {
                return $selected;
            }
        }

        return $fallback;
    }

    /**
     * @param Collection<int, array<string, mixed>> $rows
     * @param array<int, string> $dimensions
     * @return Collection<int, array<string, mixed>>
     */
    private function rollupGoalieReasonRows(Collection $rows, string $strength, array $dimensions): Collection
    {
        $totalXsatPerGame = (float) $rows->sum('adjusted_xsat_per_game');
        $totalXsogPerGame = (float) $rows->sum('adjusted_xsog_per_game');
        $totalXgfPerGame = (float) $rows->sum('adjusted_xgf_per_game');
        $dimensionLookup = array_fill_keys($dimensions, true);

        return $rows
            ->groupBy(fn (array $row): string => implode('|', array_map(
                fn (string $dimension): string => (string) ($row[$dimension] ?? 'Any'),
                $dimensions
            )))
            ->map(function (Collection $group) use ($strength, $dimensionLookup, $totalXsatPerGame, $totalXsogPerGame, $totalXgfPerGame): array {
                $first = $group->first();
                $xsatPerGame = (float) $group->sum('adjusted_xsat_per_game');
                $xsogPerGame = (float) $group->sum('adjusted_xsog_per_game');
                $xgfPerGame = (float) $group->sum('adjusted_xgf_per_game');
                $goalieAdjustmentPerGame = (float) $group->sum('goalie_adjustment_per_game');
                $goalieAdjustedXgfPerGame = (float) $group->sum('goalie_adjusted_xgf_per_game');

                return [
                    'projection_strength' => $strength,
                    'shot_type_group' => isset($dimensionLookup['shot_type_group']) ? ($first['shot_type_group'] ?? 'Any') : 'Mixed',
                    'distance_group' => isset($dimensionLookup['distance_group']) ? ($first['distance_group'] ?? 'Any') : 'Mixed',
                    'angle_group' => isset($dimensionLookup['angle_group']) ? ($first['angle_group'] ?? 'Any') : 'Mixed',
                    'sequence_group' => isset($dimensionLookup['sequence_group']) ? ($first['sequence_group'] ?? 'Any') : 'Mixed',
                    'adjusted_xsat_per_game' => round($xsatPerGame, 3),
                    'adjusted_xsog_per_game' => round($xsogPerGame, 3),
                    'adjusted_xgf_per_game' => round($xgfPerGame, 4),
                    'goalie_adjustment_per_game' => round($goalieAdjustmentPerGame, 4),
                    'goalie_saves_above_expected_per_game' => round(-1 * $goalieAdjustmentPerGame, 4),
                    'goalie_adjusted_xgf_per_game' => round($goalieAdjustedXgfPerGame, 4),
                    'goalie_ga_xga_ratio' => round($this->weightedAverage($group, 'goalie_ga_xga_ratio', 'adjusted_xgf_per_game'), 4),
                    'goalie_confidence' => round($this->weightedAverage($group, 'goalie_confidence', 'adjusted_xsog_per_game'), 4),
                    'represented_xsat' => round($this->ratio($xsatPerGame, $totalXsatPerGame), 6),
                    'represented_xsog' => round($this->ratio($xsogPerGame, $totalXsogPerGame), 6),
                    'represented_xgf' => round($this->ratio($xgfPerGame, $totalXgfPerGame), 6),
                    'bucket_count' => $group->count(),
                    'goalie_reason_source' => $group->count() > 1 ? 'rolled' : (string) ($first['goalie_reason_source'] ?? 'bucket'),
                ];
            })
            ->sortByDesc(fn (array $row): float => (
                ((float) ($row['adjusted_xsog_per_game'] ?? 0) * 1000)
                + ((float) ($row['adjusted_xsat_per_game'] ?? 0) * 10)
                + (float) ($row['adjusted_xgf_per_game'] ?? 0)
            ))
            ->values();
    }

    /**
     * @param Collection<int, array<string, mixed>> $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function selectGoalieReasonRows(Collection $rows, int $maxRows): Collection
    {
        $selected = collect();
        $shownXsatPerGame = 0.0;
        $shownXsogPerGame = 0.0;
        $totalXsatPerGame = (float) $rows->sum('adjusted_xsat_per_game');
        $totalXsogPerGame = (float) $rows->sum('adjusted_xsog_per_game');

        foreach ($rows as $row) {
            if ($selected->count() >= $maxRows) {
                break;
            }

            $selected->push($row);
            $shownXsatPerGame += (float) ($row['adjusted_xsat_per_game'] ?? 0);
            $shownXsogPerGame += (float) ($row['adjusted_xsog_per_game'] ?? 0);

            if (
                $selected->count() >= min(self::MIN_GOALIE_REASON_ROWS, $maxRows)
                && $this->ratio($shownXsatPerGame, $totalXsatPerGame) >= self::GOALIE_REASON_COVERAGE_TARGET
                && $this->ratio($shownXsogPerGame, $totalXsogPerGame) >= self::GOALIE_REASON_COVERAGE_TARGET
            ) {
                break;
            }
        }

        return $selected->values();
    }

    /**
     * @param Collection<int, array<string, mixed>> $rows
     */
    private function weightedAverage(Collection $rows, string $valueKey, string $weightKey): float
    {
        $weighted = 0.0;
        $weightTotal = 0.0;

        foreach ($rows as $row) {
            $weight = max(0.0, (float) ($row[$weightKey] ?? 0));
            $weighted += ((float) ($row[$valueKey] ?? 0)) * $weight;
            $weightTotal += $weight;
        }

        return $weightTotal > 0 ? $weighted / $weightTotal : 0.0;
    }

    private function ratio(float $numerator, float $denominator): float
    {
        if ($denominator <= 0) {
            return 0.0;
        }

        return $numerator / $denominator;
    }

    private function rowValue(array|object|null $row, string $key, mixed $default = null): mixed
    {
        if ($row === null) {
            return $default;
        }

        if (is_array($row)) {
            return $row[$key] ?? $default;
        }

        return $row->{$key} ?? $default;
    }

    /**
     * @param Collection<string, float> $penaltiesByBucket
     * @return Collection<int, array<string, mixed>>
     */
    private function rosterRows(
        string $targetSeasonId,
        string $projectionVersion,
        string $toiProjectionVersion,
        string $team,
        float $teamAdjustedXgf,
        float $projectedGames,
        Collection $penaltiesByBucket
    ): Collection {
        $projectedGames = $projectedGames > 0 ? $projectedGames : self::TARGET_SEASON_GAMES;
        $teamAdjustedXgf = max(0.01, $teamAdjustedXgf);

        $rows = DB::table('nhl_player_projection_profile_buckets as buckets')
            ->join('nhl_player_season_projections as projections', function ($join) use ($targetSeasonId, $projectionVersion): void {
                $join->on('projections.player_id', '=', 'buckets.player_id')
                    ->where('projections.target_season_id', '=', $targetSeasonId)
                    ->where('projections.projection_version', '=', $projectionVersion);
            })
            ->join('nhl_player_toi_projections as toi', function ($join) use ($targetSeasonId, $toiProjectionVersion): void {
                $join->on('toi.player_id', '=', 'buckets.player_id')
                    ->where('toi.target_season_id', '=', $targetSeasonId)
                    ->where('toi.projection_version', '=', $toiProjectionVersion);
            })
            ->leftJoin('players', 'players.nhl_id', '=', 'buckets.player_id')
            ->where('buckets.target_season_id', $targetSeasonId)
            ->where('buckets.projection_version', $projectionVersion)
            ->where('toi.target_team_abbrev', $team)
            ->selectRaw('buckets.player_id')
            ->selectRaw("MAX(COALESCE(players.full_name, buckets.player_id::text)) as player_name")
            ->selectRaw("MAX(COALESCE(buckets.position, projections.position, toi.position)) as position")
            ->selectRaw('SUM(buckets.projected_xgf) as baseline_xgf')
            ->selectRaw('MAX(projections.confidence_score) as confidence_score')
            ->selectRaw('MAX(projections.confidence_bucket) as confidence_bucket')
            ->selectRaw("jsonb_object_agg(buckets.matched_bucket_key, buckets.projected_xgf) as bucket_xgf")
            ->groupBy('buckets.player_id')
            ->havingRaw('SUM(buckets.projected_xgf) > 0')
            ->get();

        return $rows
            ->map(function (object $row) use ($penaltiesByBucket, $teamAdjustedXgf, $projectedGames): array {
                $bucketXgf = json_decode((string) $row->bucket_xgf, true, 512, JSON_THROW_ON_ERROR);
                $adjustedXgf = 0.0;

                foreach ($bucketXgf as $bucketKey => $xgf) {
                    $adjustedXgf += ((float) $xgf) * (1 - (float) ($penaltiesByBucket->get((string) $bucketKey) ?? 0));
                }

                return [
                    'player_id' => (int) $row->player_id,
                    'player_name' => (string) $row->player_name,
                    'position' => $row->position,
                    'baseline_xgf' => round((float) $row->baseline_xgf, 4),
                    'adjusted_xgf' => round($adjustedXgf, 4),
                    'baseline_xgf_per_game' => round(((float) $row->baseline_xgf) / $projectedGames, 4),
                    'adjusted_xgf_per_game' => round($adjustedXgf / $projectedGames, 4),
                    'team_xgf_share' => round($adjustedXgf / $teamAdjustedXgf, 6),
                    'confidence_score' => $row->confidence_score === null ? null : round((float) $row->confidence_score, 4),
                    'confidence_bucket' => $row->confidence_bucket,
                ];
            })
            ->sortByDesc('adjusted_xgf')
            ->take(18)
            ->values();
    }

    /**
     * @param Collection<int, object> $rows
     * @return array<string, float>
     */
    private function summary(Collection $rows, string $prefix): array
    {
        return [
            $prefix . 'xsat' => round((float) $rows->sum('baseline_xsat'), 2),
            $prefix . 'xsog' => round((float) $rows->sum('baseline_xsog'), 2),
            $prefix . 'xgf' => round((float) $rows->sum('baseline_xgf'), 4),
        ];
    }

    /**
     * @param Collection<int, object> $rows
     */
    private function projectedGames(Collection $rows): float
    {
        $projectedGames = (float) $rows->max('projected_games');

        return $projectedGames > 0 ? $projectedGames : self::TARGET_SEASON_GAMES;
    }

    private function tablesExist(): bool
    {
        return Schema::hasTable('nhl_player_projection_profile_buckets')
            && Schema::hasTable('nhl_player_toi_projections')
            && Schema::hasTable('nhl_skater_defensive_chance_profile_buckets');
    }

    private function goalieProjectionTablesExist(): bool
    {
        return Schema::hasTable('nhl_goalie_season_projections')
            && Schema::hasTable('nhl_goalie_projection_chance_buckets')
            && Schema::hasColumn('nhl_goalie_season_projections', 'projected_ev_ga')
            && Schema::hasColumn('nhl_goalie_season_projections', 'projected_pk_xga')
            && Schema::hasColumn('nhl_goalie_season_projections', 'projected_pk_ga')
            && Schema::hasColumn('nhl_goalie_projection_chance_buckets', 'projection_strength');
    }

    private function projectedGoalsAgainstAverage(mixed $goalsAgainst, mixed $toiSeconds): ?float
    {
        $toi = (float) ($toiSeconds ?? 0);

        if ($goalsAgainst === null || $toi <= 0) {
            return null;
        }

        return round(((float) $goalsAgainst) * 3600 / $toi, 4);
    }
}
