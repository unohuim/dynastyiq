<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Builds gner8-facing NHL game prediction payloads from projected matchup simulation.
 */
class NhlGamePredictionPayload
{
    private const GOALIE_GSAX_WEIGHT = 0.70;
    private const GOALIE_MATCHUP_WEIGHT = 0.30;
    private const MONEYLINE_SCORE_DISTRIBUTION_MAX_GOALS = 15;

    public function __construct(
        private readonly NhlProjectedTeamMatchupSimulator $simulator
    ) {
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public function build(int $nhlGameId, array $overrides = []): array
    {
        $game = $this->game($nhlGameId);
        $awayTeam = mb_strtoupper((string) $game->away_team_abbrev);
        $homeTeam = mb_strtoupper((string) $game->home_team_abbrev);

        if ($awayTeam === '' || $homeTeam === '') {
            throw ValidationException::withMessages([
                'nhl_game_id' => 'The NHL game is missing team abbreviations.',
            ]);
        }

        $targetSeasonId = (string) ($overrides['target_season_id'] ?? $this->latestTargetSeasonId());
        $sourceSeasonId = (string) ($overrides['source_season_id'] ?? $this->latestSourceSeasonId($targetSeasonId));
        $projectionVersion = (string) ($overrides['projection_version'] ?? $this->latestProjectionVersion($targetSeasonId));
        $toiProjectionVersion = (string) ($overrides['toi_projection_version'] ?? $this->latestToiProjectionVersion($targetSeasonId));
        $goalieProjectionVersion = (string) ($overrides['goalie_projection_version'] ?? $this->latestGoalieProjectionVersion($targetSeasonId));

        $this->assertSimulationInputs($sourceSeasonId, $targetSeasonId, $projectionVersion, $toiProjectionVersion, $goalieProjectionVersion);

        $awayGoalie = $this->resolveGoalie(
            $targetSeasonId,
            $goalieProjectionVersion,
            $awayTeam,
            $overrides['away_goalie_id'] ?? null
        );
        $homeGoalie = $this->resolveGoalie(
            $targetSeasonId,
            $goalieProjectionVersion,
            $homeTeam,
            $overrides['home_goalie_id'] ?? null
        );

        $result = $this->simulator->simulate(
            $sourceSeasonId,
            $targetSeasonId,
            $projectionVersion,
            $toiProjectionVersion,
            $goalieProjectionVersion,
            $awayTeam,
            $homeTeam,
            (int) $awayGoalie['nhl_player_id'],
            (int) $homeGoalie['nhl_player_id']
        );

        if (($result['is_available'] ?? false) !== true) {
            throw ValidationException::withMessages([
                'nhl_game_id' => (string) ($result['error'] ?? 'The projected matchup simulator is unavailable.'),
            ]);
        }

        $awaySide = $result['sides'][0] ?? [];
        $homeSide = $result['sides'][1] ?? [];
        $awayGoals = (float) data_get($awaySide, 'summary.total_goalie_adjusted_xgf_per_game', 0);
        $homeGoals = (float) data_get($homeSide, 'summary.total_goalie_adjusted_xgf_per_game', 0);
        $awayGoalieAdjustment = (float) data_get($homeSide, 'summary.total_goalie_adjustment_per_game', 0);
        $homeGoalieAdjustment = (float) data_get($awaySide, 'summary.total_goalie_adjustment_per_game', 0);

        $awayGoalie = $this->withMatchupGoalieValues($awayGoalie, $awayGoalieAdjustment);
        $homeGoalie = $this->withMatchupGoalieValues($homeGoalie, $homeGoalieAdjustment);

        $prediction = $this->predictionPayload($awayTeam, $homeTeam, $awayGoals, $homeGoals, $awayGoalie, $homeGoalie);

        return [
            'game' => $this->gamePayload($game),
            'inputs' => [
                'source_season_id' => $sourceSeasonId,
                'target_season_id' => $targetSeasonId,
                'projection_version' => $projectionVersion,
                'toi_projection_version' => $toiProjectionVersion,
                'goalie_projection_version' => $goalieProjectionVersion,
                'away_goalie_id' => $awayGoalie['nhl_player_id'],
                'home_goalie_id' => $homeGoalie['nhl_player_id'],
            ],
            'prediction' => $prediction,
            'market_probabilities' => $this->marketProbabilities($awayTeam, $homeTeam, $awayGoals, $homeGoals, $prediction),
            'goalies' => [
                'away' => $awayGoalie,
                'home' => $homeGoalie,
            ],
            'teams' => [
                'away' => $this->teamPayload($awaySide),
                'home' => $this->teamPayload($homeSide),
            ],
            'reasons' => [
                'away' => $this->reasonPayload($awaySide),
                'home' => $this->reasonPayload($homeSide),
            ],
            'meta' => [
                'source_system' => 'dynastyiq',
                'source_fetched_at' => now()->toIso8601String(),
            ],
        ];
    }

    private function game(int $nhlGameId): object
    {
        $game = DB::table('nhl_games')
            ->where('nhl_game_id', $nhlGameId)
            ->first();

        if ($game === null) {
            throw ValidationException::withMessages([
                'nhl_game_id' => 'The NHL game id was not found.',
            ]);
        }

        return $game;
    }

    private function latestTargetSeasonId(): ?string
    {
        if (! Schema::hasTable('nhl_player_season_projections')) {
            return null;
        }

        return DB::table('nhl_player_season_projections')
            ->max('target_season_id');
    }

    private function latestSourceSeasonId(string $targetSeasonId): ?string
    {
        if (! Schema::hasTable('nhl_player_season_projections')) {
            return null;
        }

        return DB::table('nhl_player_season_projections')
            ->where('target_season_id', $targetSeasonId)
            ->max('source_season_id');
    }

    private function latestProjectionVersion(string $targetSeasonId): ?string
    {
        if (! Schema::hasTable('nhl_player_season_projections')) {
            return null;
        }

        return DB::table('nhl_player_season_projections')
            ->where('target_season_id', $targetSeasonId)
            ->orderByDesc('projected_at')
            ->orderByDesc('projection_version')
            ->value('projection_version');
    }

    private function latestToiProjectionVersion(string $targetSeasonId): ?string
    {
        if (! Schema::hasTable('nhl_player_toi_projections')) {
            return null;
        }

        return DB::table('nhl_player_toi_projections')
            ->where('target_season_id', $targetSeasonId)
            ->orderByDesc('projected_at')
            ->orderByDesc('projection_version')
            ->value('projection_version');
    }

    private function latestGoalieProjectionVersion(string $targetSeasonId): ?string
    {
        if (! $this->goalieProjectionTablesExist()) {
            return null;
        }

        return DB::table('nhl_goalie_season_projections as projections')
            ->join('nhl_goalie_projection_chance_buckets as buckets', function ($join): void {
                $join->on('buckets.projection_version', '=', 'projections.projection_version')
                    ->on('buckets.target_season_id', '=', 'projections.target_season_id')
                    ->on('buckets.goalie_player_id', '=', 'projections.goalie_player_id')
                    ->where('buckets.projection_strength', '=', 'ev');
            })
            ->where('projections.target_season_id', $targetSeasonId)
            ->groupBy('projections.projection_version')
            ->orderByDesc(DB::raw('MAX(projections.projected_at)'))
            ->orderByDesc('projections.projection_version')
            ->value('projections.projection_version');
    }

    private function assertSimulationInputs(?string ...$inputs): void
    {
        if (collect($inputs)->contains(fn (?string $input): bool => $input === null || $input === '')) {
            throw ValidationException::withMessages([
                'projection' => 'Game prediction requires built skater, TOI, and goalie projection versions.',
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveGoalie(string $targetSeasonId, string $goalieProjectionVersion, string $team, mixed $providedGoalieId): array
    {
        $selectionSource = 'goalie_projection';
        $goalieId = $providedGoalieId === null || $providedGoalieId === ''
            ? $this->defaultGoalieFromSeasonProjection($targetSeasonId, $goalieProjectionVersion, $team)
            : (int) $providedGoalieId;

        if ($goalieId === null) {
            $goalieId = $this->defaultGoalieFromWorkloadProjection($targetSeasonId, $team);
            $selectionSource = 'workload_projection';
        } elseif ($providedGoalieId !== null && $providedGoalieId !== '') {
            $selectionSource = 'provided';
        }

        if ($goalieId === null) {
            throw ValidationException::withMessages([
                'goalie' => "No projected starting goalie could be resolved for {$team}.",
            ]);
        }

        $goalie = $this->goalieProjection($targetSeasonId, $goalieProjectionVersion, $team, $goalieId);

        if ($goalie === null) {
            throw ValidationException::withMessages([
                'goalie' => "Goalie {$goalieId} does not have a usable {$team} goalie performance projection.",
            ]);
        }

        $goalie['selection_source'] = $selectionSource;

        return $goalie;
    }

    private function defaultGoalieFromSeasonProjection(string $targetSeasonId, string $goalieProjectionVersion, string $team): ?int
    {
        if (! $this->goalieProjectionTablesExist()) {
            return null;
        }

        $goalieId = DB::table('nhl_goalie_season_projections')
            ->where('target_season_id', $targetSeasonId)
            ->where('projection_version', $goalieProjectionVersion)
            ->where('target_team_abbrev', $team)
            ->orderByDesc('projected_starts')
            ->orderByDesc('projected_games')
            ->value('goalie_player_id');

        return $goalieId === null ? null : (int) $goalieId;
    }

    private function defaultGoalieFromWorkloadProjection(string $targetSeasonId, string $team): ?int
    {
        if (! Schema::hasTable('nhl_goalie_workload_projections')) {
            return null;
        }

        $goalieId = DB::table('nhl_goalie_workload_projections')
            ->where('target_season_id', $targetSeasonId)
            ->where('target_team_abbrev', $team)
            ->orderByDesc('projected_starts')
            ->orderByDesc('projected_games')
            ->value('goalie_player_id');

        return $goalieId === null ? null : (int) $goalieId;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function goalieProjection(string $targetSeasonId, string $goalieProjectionVersion, string $team, int $goalieId): ?array
    {
        $row = DB::table('nhl_goalie_season_projections as projections')
            ->leftJoin('players', 'players.nhl_id', '=', 'projections.goalie_player_id')
            ->where('projections.target_season_id', $targetSeasonId)
            ->where('projections.projection_version', $goalieProjectionVersion)
            ->where('projections.target_team_abbrev', $team)
            ->where('projections.goalie_player_id', $goalieId)
            ->select([
                'projections.goalie_player_id',
                'projections.target_team_abbrev',
                'projections.projected_games',
                'projections.projected_starts',
                'projections.projected_toi_hours',
                'projections.projected_xga',
                'projections.projected_ga',
                'projections.projected_gsax',
                'projections.projected_ev_xga',
                'projections.projected_ev_ga',
                'projections.projected_pk_xga',
                'projections.projected_pk_ga',
                'projections.confidence_score',
                'projections.confidence_bucket',
            ])
            ->selectRaw("COALESCE(players.full_name, projections.goalie_player_id::text) as name")
            ->first();

        if ($row === null) {
            return null;
        }

        $projectedGames = (float) ($row->projected_games ?? 0);

        return [
            'nhl_player_id' => (int) $row->goalie_player_id,
            'name' => (string) $row->name,
            'team_abbrev' => $row->target_team_abbrev,
            'projected_games' => $row->projected_games === null ? null : round((float) $row->projected_games, 2),
            'projected_starts' => $row->projected_starts === null ? null : round((float) $row->projected_starts, 2),
            'projected_toi_hours' => $row->projected_toi_hours === null ? null : round((float) $row->projected_toi_hours, 2),
            'projected_xga_per_game' => $projectedGames > 0 && $row->projected_xga !== null ? round((float) $row->projected_xga / $projectedGames, 4) : null,
            'projected_ga_per_game' => $projectedGames > 0 && $row->projected_ga !== null ? round((float) $row->projected_ga / $projectedGames, 4) : null,
            'projected_gsax_per_game' => $projectedGames > 0 && $row->projected_gsax !== null ? round((float) $row->projected_gsax / $projectedGames, 4) : null,
            'projected_ev_xga_per_game' => $projectedGames > 0 && $row->projected_ev_xga !== null ? round((float) $row->projected_ev_xga / $projectedGames, 4) : null,
            'projected_ev_ga_per_game' => $projectedGames > 0 && $row->projected_ev_ga !== null ? round((float) $row->projected_ev_ga / $projectedGames, 4) : null,
            'projected_pk_xga_per_game' => $projectedGames > 0 && $row->projected_pk_xga !== null ? round((float) $row->projected_pk_xga / $projectedGames, 4) : null,
            'projected_pk_ga_per_game' => $projectedGames > 0 && $row->projected_pk_ga !== null ? round((float) $row->projected_pk_ga / $projectedGames, 4) : null,
            'confidence_score' => $row->confidence_score === null ? null : round(((float) $row->confidence_score) * 100),
            'confidence_bucket' => $row->confidence_bucket,
        ];
    }

    /**
     * @param array<string, mixed> $goalie
     * @return array<string, mixed>
     */
    private function withMatchupGoalieValues(array $goalie, float $adjustmentPerGame): array
    {
        $goalie['matchup_adjustment_per_game'] = round($adjustmentPerGame, 4);
        $goalie['matchup_value_per_game'] = round(-1 * $adjustmentPerGame, 4);

        return $goalie;
    }

    /**
     * @param array<string, mixed> $awayGoalie
     * @param array<string, mixed> $homeGoalie
     * @return array<string, mixed>
     */
    private function predictionPayload(string $awayTeam, string $homeTeam, float $awayGoals, float $homeGoals, array $awayGoalie, array $homeGoalie): array
    {
        $goalDifferential = round($homeGoals - $awayGoals, 4);
        $winnerSide = $goalDifferential > 0 ? 'home' : ($goalDifferential < 0 ? 'away' : 'pickem');
        $winnerTeam = $winnerSide === 'home' ? $homeTeam : ($winnerSide === 'away' ? $awayTeam : null);

        return [
            'winner' => [
                'side' => $winnerSide,
                'team_abbrev' => $winnerTeam,
            ],
            'predicted_score' => [
                'away' => round($awayGoals, 2),
                'home' => round($homeGoals, 2),
            ],
            'goal_differential' => $goalDifferential,
            'confidence_score' => $this->confidenceScore(abs($goalDifferential), $awayGoalie, $homeGoalie),
            'goalie_edge' => $this->goalieEdge($awayTeam, $homeTeam, $awayGoalie, $homeGoalie),
        ];
    }

    /**
     * @param array<string, mixed> $awayGoalie
     * @param array<string, mixed> $homeGoalie
     */
    private function confidenceScore(float $goalMargin, array $awayGoalie, array $homeGoalie): int
    {
        $awayConfidence = ((float) ($awayGoalie['confidence_score'] ?? 50)) / 100;
        $homeConfidence = ((float) ($homeGoalie['confidence_score'] ?? 50)) / 100;
        $dataConfidence = max(0.1, min(1.0, ($awayConfidence + $homeConfidence) / 2));
        $score = 50 + min(30, $goalMargin * 18) + (($dataConfidence - 0.5) * 20);

        return max(1, min(100, (int) round($score)));
    }

    /**
     * @param array<string, mixed> $awayGoalie
     * @param array<string, mixed> $homeGoalie
     * @return array<string, mixed>
     */
    private function goalieEdge(string $awayTeam, string $homeTeam, array $awayGoalie, array $homeGoalie): array
    {
        $projectedGsaxEdge = ((float) ($homeGoalie['projected_gsax_per_game'] ?? 0)) - ((float) ($awayGoalie['projected_gsax_per_game'] ?? 0));
        $matchupFitEdge = ((float) ($homeGoalie['matchup_value_per_game'] ?? 0)) - ((float) ($awayGoalie['matchup_value_per_game'] ?? 0));
        $score = round((self::GOALIE_GSAX_WEIGHT * $projectedGsaxEdge) + (self::GOALIE_MATCHUP_WEIGHT * $matchupFitEdge), 4);
        $side = $score > 0 ? 'home' : ($score < 0 ? 'away' : 'even');

        return [
            'side' => $side,
            'team_abbrev' => $side === 'home' ? $homeTeam : ($side === 'away' ? $awayTeam : null),
            'score' => $score,
            'unit' => 'goals_saved_per_game_edge',
            'label' => $this->goalieEdgeLabel(abs($score)),
            'scale' => [
                'normal_range' => [-0.40, 0.40],
                'display_clamp' => [-0.75, 0.75],
                'labels' => [
                    ['label' => 'even', 'min_abs' => 0.00, 'max_abs' => 0.05],
                    ['label' => 'slight', 'min_abs' => 0.05, 'max_abs' => 0.15],
                    ['label' => 'clear', 'min_abs' => 0.15, 'max_abs' => 0.30],
                    ['label' => 'strong', 'min_abs' => 0.30, 'max_abs' => 0.50],
                    ['label' => 'extreme', 'min_abs' => 0.50, 'max_abs' => 0.75],
                ],
            ],
            'components' => [
                'projected_gsax_edge' => round($projectedGsaxEdge, 4),
                'matchup_fit_edge' => round($matchupFitEdge, 4),
                'weights' => [
                    'projected_gsax_per_game' => self::GOALIE_GSAX_WEIGHT,
                    'matchup_fit' => self::GOALIE_MATCHUP_WEIGHT,
                ],
            ],
        ];
    }

    private function goalieEdgeLabel(float $absoluteScore): string
    {
        return match (true) {
            $absoluteScore < 0.05 => 'even',
            $absoluteScore < 0.15 => 'slight',
            $absoluteScore < 0.30 => 'clear',
            $absoluteScore < 0.50 => 'strong',
            default => 'extreme',
        };
    }

    /**
     * @param array<string, mixed> $prediction
     * @return array<int, array<string, mixed>>
     */
    private function marketProbabilities(
        string $awayTeam,
        string $homeTeam,
        float $awayGoals,
        float $homeGoals,
        array $prediction
    ): array {
        $probabilities = $this->moneylineProbabilities($awayGoals, $homeGoals);

        return [
            $this->moneylineMarketProbability('away', $awayTeam, $probabilities['away'], (int) $prediction['confidence_score']),
            $this->moneylineMarketProbability('home', $homeTeam, $probabilities['home'], (int) $prediction['confidence_score']),
        ];
    }

    /**
     * @return array{away:float,home:float}
     */
    private function moneylineProbabilities(float $awayGoals, float $homeGoals): array
    {
        $awayLambda = max(0.01, $awayGoals);
        $homeLambda = max(0.01, $homeGoals);
        $awayWinProbability = 0.0;
        $homeWinProbability = 0.0;
        $tieProbability = 0.0;
        $coveredProbability = 0.0;

        for ($awayScore = 0; $awayScore <= self::MONEYLINE_SCORE_DISTRIBUTION_MAX_GOALS; $awayScore++) {
            $awayScoreProbability = $this->poissonProbability($awayScore, $awayLambda);

            for ($homeScore = 0; $homeScore <= self::MONEYLINE_SCORE_DISTRIBUTION_MAX_GOALS; $homeScore++) {
                $scoreProbability = $awayScoreProbability * $this->poissonProbability($homeScore, $homeLambda);
                $coveredProbability += $scoreProbability;

                if ($awayScore > $homeScore) {
                    $awayWinProbability += $scoreProbability;
                } elseif ($homeScore > $awayScore) {
                    $homeWinProbability += $scoreProbability;
                } else {
                    $tieProbability += $scoreProbability;
                }
            }
        }

        $awayStrength = $awayLambda / max(0.01, $awayLambda + $homeLambda);
        $homeStrength = $homeLambda / max(0.01, $awayLambda + $homeLambda);
        $awayWinProbability += $tieProbability * $awayStrength;
        $homeWinProbability += $tieProbability * $homeStrength;
        $awayWinProbability = $awayWinProbability / max(0.01, $coveredProbability);
        $homeWinProbability = $homeWinProbability / max(0.01, $coveredProbability);
        $total = max(0.01, $awayWinProbability + $homeWinProbability);

        return [
            'away' => round($awayWinProbability / $total, 6),
            'home' => round($homeWinProbability / $total, 6),
        ];
    }

    private function poissonProbability(int $goals, float $lambda): float
    {
        return exp(-1 * $lambda) * ($lambda ** $goals) / $this->factorial($goals);
    }

    private function factorial(int $value): int
    {
        $factorial = 1;

        for ($i = 2; $i <= $value; $i++) {
            $factorial *= $i;
        }

        return $factorial;
    }

    /**
     * @return array<string, mixed>
     */
    private function moneylineMarketProbability(string $selectionKey, string $teamAbbrev, float $probability, int $confidenceScore): array
    {
        return [
            'market_key' => 'moneyline',
            'period_key' => 'full_game',
            'selection_key' => $selectionKey,
            'team_abbrev' => $teamAbbrev,
            'probability' => round($probability, 6),
            'fair_odds_american' => $this->americanOdds($probability),
            'fair_odds_decimal' => round(1 / max(0.000001, $probability), 3),
            'confidence_score' => $confidenceScore,
            'model' => [
                'method' => 'poisson_projected_score_moneyline',
                'source' => 'prediction.predicted_score',
                'includes_overtime' => true,
                'tie_resolution' => 'projected_goal_share',
                'max_score' => self::MONEYLINE_SCORE_DISTRIBUTION_MAX_GOALS,
            ],
        ];
    }

    private function americanOdds(float $probability): int
    {
        $probability = max(0.000001, min(0.999999, $probability));

        if ($probability >= 0.5) {
            return (int) round(-100 * $probability / (1 - $probability));
        }

        return (int) round(100 * (1 - $probability) / $probability);
    }

    /**
     * @return array<string, mixed>
     */
    private function gamePayload(object $game): array
    {
        return [
            'nhl_game_id' => (int) $game->nhl_game_id,
            'season_id' => (string) $game->season_id,
            'game_type' => (int) $game->game_type,
            'game_date' => $game->game_date,
            'start_time_utc' => $game->start_time_utc,
            'game_state' => $game->game_state,
            'game_schedule_state' => $game->game_schedule_state,
            'away_team_abbrev' => $game->away_team_abbrev,
            'home_team_abbrev' => $game->home_team_abbrev,
        ];
    }

    /**
     * @param array<string, mixed> $side
     * @return array<string, mixed>
     */
    private function teamPayload(array $side): array
    {
        return [
            'team_abbrev' => $side['offense_team'] ?? null,
            'opponent_team_abbrev' => $side['defense_team'] ?? null,
            'summary' => $side['summary'] ?? [],
        ];
    }

    /**
     * @param array<string, mixed> $side
     * @return array<string, mixed>
     */
    private function reasonPayload(array $side): array
    {
        return [
            'offense_profile' => $side['offense_profile'] ?? [],
            'defense_profile' => $side['defense_profile'] ?? [],
            'goalie_environment_profile' => $side['goalie_environment_profile'] ?? [],
            'goalie_reasons' => $side['goalie_reasons'] ?? [],
            'goalie_reason_summary' => $side['goalie_reason_summary'] ?? [],
        ];
    }

    private function goalieProjectionTablesExist(): bool
    {
        return Schema::hasTable('nhl_goalie_season_projections')
            && Schema::hasTable('nhl_goalie_projection_chance_buckets')
            && Schema::hasColumn('nhl_goalie_season_projections', 'projected_starts')
            && Schema::hasColumn('nhl_goalie_projection_chance_buckets', 'projection_strength');
    }
}
