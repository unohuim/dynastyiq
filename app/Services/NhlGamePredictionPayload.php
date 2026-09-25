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
    private const PRESEASON_GAME_TYPE = 1;
    private const GOALIE_GSAX_WEIGHT = 0.70;
    private const GOALIE_MATCHUP_WEIGHT = 0.30;
    private const MONEYLINE_SCORE_DISTRIBUTION_MAX_GOALS = 15;
    private const DEFAULT_PUCKLINE_SPREAD = 1.5;
    private const DEFAULT_TOTAL_LINE = 6.0;
    private const INPUT_CONFIDENCE_SKATER_WEIGHT = 0.70;
    private const INPUT_CONFIDENCE_GOALIE_WEIGHT = 0.30;
    private const SKATER_CONFIDENCE_COVERAGE_TARGET = 0.99;

    public function __construct(
        private readonly NhlProjectedTeamMatchupSimulator $simulator,
        private readonly NhlAnticipatedLineupPayload $anticipatedLineups,
        private readonly NhlGameLineupProjectionBuilder $lineupProjections,
        private readonly NhlAnticipatedLineupImporter $lineupImporter,
        private readonly NhlStartingGoalieSelector $startingGoalies,
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
        $satModelId = $this->lineupProjections->latestUsableSatModelId($targetSeasonId);

        $awayLineup = $this->anticipatedLineups->forGameTeam($nhlGameId, $awayTeam, false);
        $homeLineup = $this->anticipatedLineups->forGameTeam($nhlGameId, $homeTeam, false);
        $awayOfficialRosterIds = ($awayLineup['manual_override'] ?? false)
            ? null : $this->officialSkaterIds($nhlGameId, $awayTeam);
        $homeOfficialRosterIds = ($homeLineup['manual_override'] ?? false)
            ? null : $this->officialSkaterIds($nhlGameId, $homeTeam);
        $awayRosterIds = $awayOfficialRosterIds ?? $this->resolvedSkaterIds($awayLineup, (int) $game->game_type);
        $homeRosterIds = $homeOfficialRosterIds ?? $this->resolvedSkaterIds($homeLineup, (int) $game->game_type);

        if ((int) $game->game_type === self::PRESEASON_GAME_TYPE
            && ($awayRosterIds === null || $homeRosterIds === null)) {
            $this->importMissingOfficialLineups(
                $game,
                $awayTeam,
                $homeTeam,
                $awayRosterIds === null,
                $homeRosterIds === null
            );
            $awayLineup = $this->anticipatedLineups->forGameTeam($nhlGameId, $awayTeam, false);
            $homeLineup = $this->anticipatedLineups->forGameTeam($nhlGameId, $homeTeam, false);
            $awayRosterIds = $awayOfficialRosterIds ?? $this->resolvedSkaterIds($awayLineup, (int) $game->game_type);
            $homeRosterIds = $homeOfficialRosterIds ?? $this->resolvedSkaterIds($homeLineup, (int) $game->game_type);
        }

        $awayOfficial = $awayOfficialRosterIds !== null || $this->isOfficialLineup($awayLineup);
        $homeOfficial = $homeOfficialRosterIds !== null || $this->isOfficialLineup($homeLineup);
        $awayGamePlayers = $this->gamePlayerProjections(
            $awayLineup,
            $awayRosterIds,
            $sourceSeasonId,
            $targetSeasonId,
            $projectionVersion,
            $toiProjectionVersion,
            (int) $game->game_type
        );
        $homeGamePlayers = $this->gamePlayerProjections(
            $homeLineup,
            $homeRosterIds,
            $sourceSeasonId,
            $targetSeasonId,
            $projectionVersion,
            $toiProjectionVersion,
            (int) $game->game_type
        );

        if ((int) $game->game_type === self::PRESEASON_GAME_TYPE
            && ($awayRosterIds === null || $homeRosterIds === null)) {
            return $this->unpredictablePreseasonPayload(
                $game,
                $awayTeam,
                $homeTeam,
                $nhlGameId,
                $sourceSeasonId,
                $targetSeasonId,
                $projectionVersion,
                $toiProjectionVersion,
                $goalieProjectionVersion,
                $awayLineup,
                $homeLineup,
                $awayRosterIds,
                $homeRosterIds,
                $awayGamePlayers,
                $homeGamePlayers,
                $awayOfficialRosterIds,
                $homeOfficialRosterIds,
                $awayOfficial,
                $homeOfficial,
                $overrides
            );
        }

        $awayGoalie = $this->resolveGoalie(
            $targetSeasonId,
            $goalieProjectionVersion,
            $awayTeam,
            $overrides['away_goalie_id'] ?? null,
            $nhlGameId
        );
        $homeGoalie = $this->resolveGoalie(
            $targetSeasonId,
            $goalieProjectionVersion,
            $homeTeam,
            $overrides['home_goalie_id'] ?? null,
            $nhlGameId
        );

        $simulationArguments = [
            $sourceSeasonId,
            $targetSeasonId,
            $projectionVersion,
            $toiProjectionVersion,
            $goalieProjectionVersion,
            $awayTeam,
            $homeTeam,
            (int) $awayGoalie['nhl_player_id'],
            (int) $homeGoalie['nhl_player_id'],
        ];
        $result = $awayRosterIds === null && $homeRosterIds === null
            ? $this->simulator->simulate(...$simulationArguments)
            : $this->simulator->simulateWithRosters(...[
                ...$simulationArguments,
                $awayRosterIds,
                $homeRosterIds,
            ]);

        if (($result['is_available'] ?? false) !== true) {
            throw ValidationException::withMessages([
                'nhl_game_id' => (string) ($result['error'] ?? 'The projected matchup simulator is unavailable.'),
            ]);
        }

        $awaySide = $result['sides'][0] ?? [];
        $homeSide = $result['sides'][1] ?? [];
        if ($awayGamePlayers !== null) {
            $awayGamePlayers = $this->lineupProjections->applySatModel($awayGamePlayers, $satModelId, $targetSeasonId, (int) $game->game_type);
        }
        if ($homeGamePlayers !== null) {
            $homeGamePlayers = $this->lineupProjections->applySatModel($homeGamePlayers, $satModelId, $targetSeasonId, (int) $game->game_type);
        }
        $awaySide = $this->applyGameLineupProjection($awaySide, $awayGamePlayers);
        $homeSide = $this->applyGameLineupProjection($homeSide, $homeGamePlayers);
        $awayGoals = (float) data_get($awaySide, 'summary.total_goalie_adjusted_xgf_per_game', 0);
        $homeGoals = (float) data_get($homeSide, 'summary.total_goalie_adjusted_xgf_per_game', 0);
        $awayGoalieAdjustment = (float) data_get($homeSide, 'summary.total_goalie_adjustment_per_game', 0);
        $homeGoalieAdjustment = (float) data_get($awaySide, 'summary.total_goalie_adjustment_per_game', 0);

        $awayGoalie = $this->withMatchupGoalieValues($awayGoalie, $awayGoalieAdjustment);
        $homeGoalie = $this->withMatchupGoalieValues($homeGoalie, $homeGoalieAdjustment);

        $prediction = $this->predictionPayload(
            $awayTeam,
            $homeTeam,
            $awayGoals,
            $homeGoals,
            $awayGoalie,
            $homeGoalie,
            $awaySide,
            $homeSide
        );

        return [
            'prediction_available' => true,
            'game' => $this->gamePayload($game),
            'inputs' => [
                'sat_model_run_id' => $satModelId,
                'source_season_id' => $sourceSeasonId,
                'target_season_id' => $targetSeasonId,
                'projection_version' => $projectionVersion,
                'toi_projection_version' => $toiProjectionVersion,
                'goalie_projection_version' => $goalieProjectionVersion,
                'away_goalie_id' => $awayGoalie['nhl_player_id'],
                'home_goalie_id' => $homeGoalie['nhl_player_id'],
                'away_lineup_source' => $awayOfficial
                    ? 'nhl_boxscore'
                    : ($awayRosterIds === null ? 'projected_roster' : 'anticipated_lineup'),
                'home_lineup_source' => $homeOfficial
                    ? 'nhl_boxscore'
                    : ($homeRosterIds === null ? 'projected_roster' : 'anticipated_lineup'),
            ],
            'prediction' => $prediction,
            'market_probabilities' => $this->marketProbabilities(
                $awayTeam,
                $homeTeam,
                $awayGoals,
                $homeGoals,
                $prediction,
                $overrides
            ),
            'goalies' => [
                'away' => $awayGoalie,
                'home' => $homeGoalie,
            ],
            'anticipated_lineups' => [
                'away' => $awayLineup,
                'home' => $homeLineup,
            ],
            'teams' => [
                'away' => $this->withDressedRoster($this->teamPayload($awaySide), $awayLineup, $awayGoalie),
                'home' => $this->withDressedRoster($this->teamPayload($homeSide), $homeLineup, $homeGoalie),
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

    /**
     * Return evidence and roster previews without publishing a preseason prediction.
     *
     * @param array<string, mixed>|null $awayLineup
     * @param array<string, mixed>|null $homeLineup
     * @param array<int, int>|null $awayRosterIds
     * @param array<int, int>|null $homeRosterIds
     * @param array<int, array<string, mixed>>|null $awayGamePlayers
     * @param array<int, array<string, mixed>>|null $homeGamePlayers
     * @param array<int, int>|null $awayOfficialRosterIds
     * @param array<int, int>|null $homeOfficialRosterIds
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function unpredictablePreseasonPayload(
        object $game,
        string $awayTeam,
        string $homeTeam,
        int $nhlGameId,
        string $sourceSeasonId,
        string $targetSeasonId,
        string $projectionVersion,
        string $toiProjectionVersion,
        string $goalieProjectionVersion,
        ?array $awayLineup,
        ?array $homeLineup,
        ?array $awayRosterIds,
        ?array $homeRosterIds,
        ?array $awayGamePlayers,
        ?array $homeGamePlayers,
        ?array $awayOfficialRosterIds,
        ?array $homeOfficialRosterIds,
        bool $awayOfficial,
        bool $homeOfficial,
        array $overrides
    ): array {
        $awaySource = $this->lineupSource($awayOfficialRosterIds, $awayRosterIds, $awayOfficial);
        $homeSource = $this->lineupSource($homeOfficialRosterIds, $homeRosterIds, $homeOfficial);
        $awayRoster = $awayGamePlayers ?? $this->projectedRosterPreview(
            $targetSeasonId,
            $toiProjectionVersion,
            $awayTeam,
            $awayRosterIds
        );
        $homeRoster = $homeGamePlayers ?? $this->projectedRosterPreview(
            $targetSeasonId,
            $toiProjectionVersion,
            $homeTeam,
            $homeRosterIds
        );
        $awayGoalie = $this->tryResolveGoalie($targetSeasonId, $goalieProjectionVersion, $awayTeam, $overrides['away_goalie_id'] ?? null, $nhlGameId);
        $homeGoalie = $this->tryResolveGoalie($targetSeasonId, $goalieProjectionVersion, $homeTeam, $overrides['home_goalie_id'] ?? null, $nhlGameId);

        return [
            'prediction_available' => false,
            'reason' => 'preseason_lineup_unresolved',
            'missing_lineups' => collect([
                $awayRosterIds === null ? $awayTeam : null,
                $homeRosterIds === null ? $homeTeam : null,
            ])->filter()->values()->all(),
            'game' => $this->gamePayload($game),
            'inputs' => [
                'source_season_id' => $sourceSeasonId,
                'target_season_id' => $targetSeasonId,
                'projection_version' => $projectionVersion,
                'toi_projection_version' => $toiProjectionVersion,
                'goalie_projection_version' => $goalieProjectionVersion,
                'away_lineup_source' => $awaySource,
                'home_lineup_source' => $homeSource,
            ],
            'prediction' => null,
            'market_probabilities' => [],
            'goalies' => [
                'away' => $awayGoalie,
                'home' => $homeGoalie,
            ],
            'anticipated_lineups' => ['away' => $awayLineup, 'home' => $homeLineup],
            'teams' => [
                'away' => $this->withDressedRoster([
                    'team_abbrev' => $awayTeam,
                    'opponent_team_abbrev' => $homeTeam,
                    'lineup_source' => $awaySource,
                    'roster' => $awayRoster,
                ], $awayLineup, $awayGoalie),
                'home' => $this->withDressedRoster([
                    'team_abbrev' => $homeTeam,
                    'opponent_team_abbrev' => $awayTeam,
                    'lineup_source' => $homeSource,
                    'roster' => $homeRoster,
                ], $homeLineup, $homeGoalie),
            ],
            'reasons' => [],
            'meta' => [
                'source_system' => 'dynastyiq',
                'source_fetched_at' => now()->toIso8601String(),
            ],
        ];
    }

    /**
     * Expose dressed identities separately from the existing skater projections.
     * A listed backup needs no performance projection and never enters simulation.
     *
     * @param array<string,mixed> $team
     * @param array<string,mixed>|null $lineup
     * @param array<string,mixed>|null $starter
     * @return array<string,mixed>
     */
    private function withDressedRoster(array $team, ?array $lineup, ?array $starter): array
    {
        $goalies = collect($lineup['players'] ?? [])->where('lineup_role', 'goalie')
            ->where('line_key', 'G')->whereIn('slot_index', [1, 2])->sortBy('slot_index')->values();
        if (in_array($starter['selection_source'] ?? null, ['manual_starter_override', 'nhl_boxscore'], true)) {
            $listedStarter = $goalies->firstWhere('slot_index', 1);
            $backup = $goalies->firstWhere('slot_index', 2);
            if (($backup['nhl_player_id'] ?? null) === $starter['nhl_player_id']) {
                $backup = $listedStarter;
            }
            $goalies = collect([[
                'nhl_player_id' => $starter['nhl_player_id'], 'player_name' => $starter['name'],
                'lineup_role' => 'goalie', 'line_key' => 'G', 'slot_index' => 1,
            ]]);
            if ($backup !== null && ($backup['nhl_player_id'] ?? null) !== $starter['nhl_player_id']) {
                $goalies->push([...$backup, 'slot_index' => 2]);
            }
        }
        if ($goalies->isEmpty() && $starter !== null) {
            $goalies->push([
                'nhl_player_id' => $starter['nhl_player_id'], 'player_name' => $starter['name'],
                'lineup_role' => 'goalie', 'line_key' => 'G', 'slot_index' => 1,
            ]);
        }
        $goalies = $goalies->map(fn (array $goalie): array => [
            ...$goalie,
            'position' => 'G',
            'is_starter' => $starter !== null
                ? ! empty($goalie['nhl_player_id']) && (int) $goalie['nhl_player_id'] === (int) $starter['nhl_player_id']
                : (int) $goalie['slot_index'] === 1,
        ]);
        $team['dressed_roster'] = collect($team['roster'] ?? [])->concat($goalies)->values()->all();

        return $team;
    }

    /** @param array<int, int>|null $officialIds @param array<int, int>|null $rosterIds */
    private function lineupSource(?array $officialIds, ?array $rosterIds, bool $official = false): string
    {
        return $officialIds !== null || $official
            ? 'nhl_boxscore'
            : ($rosterIds === null ? 'projected_roster' : 'anticipated_lineup');
    }

    private function isOfficialLineup(?array $lineup): bool
    {
        return ($lineup['evidence_status'] ?? null) === 'official';
    }

    /**
     * Persist complete live NHL rosters for missing preseason sides before prediction refusal.
     */
    private function importMissingOfficialLineups(
        object $game,
        string $awayTeam,
        string $homeTeam,
        bool $awayMissing,
        bool $homeMissing
    ): void {
        foreach ([[$awayTeam, $awayMissing], [$homeTeam, $homeMissing]] as [$teamAbbrev, $missing]) {
            if (! $missing) {
                continue;
            }
            $teamId = DB::table('nhl_teams')->where('abbrev', $teamAbbrev)->value('nhl_id');
            if ($teamId !== null) {
                $this->lineupImporter->importOfficial($game, $teamAbbrev, (int) $teamId);
            }
        }
    }

    /**
     * @param array<int, int>|null $rosterIds
     * @return array<int, array<string, mixed>>
     */
    private function projectedRosterPreview(
        string $targetSeasonId,
        string $toiProjectionVersion,
        string $team,
        ?array $rosterIds
    ): array {
        return $this->lineupProjections->projectedRosterPreview($targetSeasonId, $toiProjectionVersion, $team, $rosterIds);
    }

    /** @return array<string, mixed>|null */
    private function tryResolveGoalie(
        string $targetSeasonId,
        string $goalieProjectionVersion,
        string $team,
        mixed $providedGoalieId,
        int $nhlGameId
    ): ?array {
        try {
            return $this->resolveGoalie($targetSeasonId, $goalieProjectionVersion, $team, $providedGoalieId, $nhlGameId);
        } catch (ValidationException) {
            return null;
        }
    }

    /**
     * Use anticipated evidence only when all eighteen skaters resolve canonically.
     *
     * @param array<string,mixed>|null $lineup
     * @return array<int,int>|null
     */
    private function resolvedSkaterIds(?array $lineup, int $gameType = 2): ?array
    {
        if ($lineup === null) {
            return null;
        }

        return app(NhlLineupPlayerResolver::class)->verifiedLineupIds(
            collect($lineup['players'] ?? [])->values()->all(), null, $gameType
        );
    }

    /**
     * Apply the same NHL, NHLe, and replacement-level projection ladder to every resolved lineup source.
     *
     * @param array<string,mixed>|null $lineup
     * @param array<int,int>|null $rosterIds
     * @return array<int,array<string,mixed>>|null
     */
    private function gamePlayerProjections(
        ?array $lineup,
        ?array $rosterIds,
        string $sourceSeasonId,
        string $targetSeasonId,
        string $projectionVersion,
        string $toiProjectionVersion,
        int $gameType = 2
    ): ?array {
        if ($rosterIds === null) {
            return null;
        }

        return $lineup !== null
            ? $this->lineupProjections->build(
                $lineup,
                $sourceSeasonId,
                $targetSeasonId,
                $projectionVersion,
                $toiProjectionVersion,
                $gameType
            )
            : $this->lineupProjections->buildFromRosterIds(
                $rosterIds,
                $sourceSeasonId,
                $targetSeasonId,
                $projectionVersion,
                $toiProjectionVersion
            );
    }

    /**
     * @param array<string,mixed> $side
     * @param array<int,array<string,mixed>>|null $players
     * @return array<string,mixed>
     */
    private function applyGameLineupProjection(array $side, ?array $players): array
    {
        if ($players === null) {
            return $side;
        }

        $projectedGoals = (float) collect($players)->sum('projected_goals');
        $projectedSog = (float) collect($players)->sum('projected_sog');
        $goalieAdjustment = (float) data_get($side, 'summary.total_goalie_adjustment_per_game', 0);
        data_set($side, 'summary.baseline_xsog_per_game', round($projectedSog, 2));
        data_set($side, 'summary.adjusted_xsog_per_game', round($projectedSog, 2));
        data_set($side, 'summary.baseline_xgf_per_game', round($projectedGoals, 4));
        data_set($side, 'summary.adjusted_xgf_per_game', round($projectedGoals, 4));
        data_set($side, 'summary.total_goalie_adjusted_xgf_per_game', round(max(0.01, $projectedGoals + $goalieAdjustment), 4));
        $side['roster'] = $players;

        return $side;
    }

    /** @return array<int,int>|null */
    private function officialSkaterIds(int $nhlGameId, string $teamAbbrev): ?array
    {
        if (! Schema::hasTable('nhl_boxscores')) {
            return null;
        }

        $teamId = DB::table('nhl_teams')->where('abbrev', $teamAbbrev)->value('nhl_id');
        if ($teamId === null) {
            return null;
        }
        $ids = DB::table('nhl_boxscores')->where('nhl_game_id', $nhlGameId)
            ->where('nhl_team_id', $teamId)->whereNotNull('nhl_player_id')
            ->whereRaw("UPPER(COALESCE(position, '')) <> 'G'")
            ->pluck('nhl_player_id')->map(fn (mixed $id): int => (int) $id)->unique()->values();

        return $ids->count() >= 18 ? $ids->all() : null;
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
    private function resolveGoalie(
        string $targetSeasonId,
        string $goalieProjectionVersion,
        string $team,
        mixed $providedGoalieId,
        int $nhlGameId
    ): array
    {
        $selection = $this->startingGoalies->selectForPrediction(
            $nhlGameId,
            $team,
            $targetSeasonId,
            $goalieProjectionVersion,
            $providedGoalieId
        );
        if ($selection === null) {
            throw ValidationException::withMessages([
                'goalie' => "No projected starting goalie could be resolved for {$team}.",
            ]);
        }

        $goalieId = (int) $selection['nhl_player_id'];
        if ($goalieId <= 0) {
            throw ValidationException::withMessages(['goalie' => "The selected {$team} starter has no canonical NHL player ID."]);
        }

        $goalie = $this->goalieProjection($targetSeasonId, $goalieProjectionVersion, $team, $goalieId);

        if ($goalie === null) {
            throw ValidationException::withMessages([
                'goalie' => "Goalie {$goalieId} does not have a usable {$team} goalie performance projection.",
            ]);
        }

        $goalie['selection_source'] = $selection['selection_source'];

        return $goalie;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function goalieProjection(string $targetSeasonId, string $goalieProjectionVersion, string $team, int $goalieId): ?array
    {
        $goalieNameFallback = DB::connection()->getDriverName() === 'pgsql'
            ? 'projections.goalie_player_id::text'
            : 'CAST(projections.goalie_player_id AS TEXT)';

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
            ->selectRaw("COALESCE(players.full_name, {$goalieNameFallback}) as name")
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
    private function predictionPayload(
        string $awayTeam,
        string $homeTeam,
        float $awayGoals,
        float $homeGoals,
        array $awayGoalie,
        array $homeGoalie,
        array $awaySide,
        array $homeSide
    ): array {
        $goalDifferential = round($homeGoals - $awayGoals, 4);
        $winnerSide = $goalDifferential > 0 ? 'home' : ($goalDifferential < 0 ? 'away' : 'pickem');
        $winnerTeam = $winnerSide === 'home' ? $homeTeam : ($winnerSide === 'away' ? $awayTeam : null);

        return [
            'winner' => [
                'side' => $winnerSide,
                'team_abbrev' => $winnerTeam,
            ],
            'predicted_score' => [
                'away' => (float) round($awayGoals, 2),
                'home' => (float) round($homeGoals, 2),
            ],
            'goal_differential' => $goalDifferential,
            'confidence_score' => $this->confidenceScore($awaySide, $homeSide, $awayGoalie, $homeGoalie),
            'goalie_edge' => $this->goalieEdge($awayTeam, $homeTeam, $awayGoalie, $homeGoalie),
        ];
    }

    /**
     * @param array<string, mixed> $awaySide
     * @param array<string, mixed> $homeSide
     * @param array<string, mixed> $awayGoalie
     * @param array<string, mixed> $homeGoalie
     */
    private function confidenceScore(array $awaySide, array $homeSide, array $awayGoalie, array $homeGoalie): int
    {
        $awayConfidence = $this->teamInputConfidence($awaySide, $awayGoalie);
        $homeConfidence = $this->teamInputConfidence($homeSide, $homeGoalie);
        $score = (($awayConfidence + $homeConfidence) / 2) * 100;

        return max(1, min(100, (int) round($score)));
    }

    /**
     * @param array<string, mixed> $side
     * @param array<string, mixed> $goalie
     */
    private function teamInputConfidence(array $side, array $goalie): float
    {
        $skaterConfidence = $this->weightedSkaterConfidence($side);
        $goalieConfidence = max(0.0, min(1.0, ((float) ($goalie['confidence_score'] ?? 50)) / 100));

        return (self::INPUT_CONFIDENCE_SKATER_WEIGHT * $skaterConfidence)
            + (self::INPUT_CONFIDENCE_GOALIE_WEIGHT * $goalieConfidence);
    }

    /**
     * @param array<string, mixed> $side
     */
    private function weightedSkaterConfidence(array $side): float
    {
        $roster = collect($side['roster'] ?? [])
            ->map(static function (mixed $row): array {
                $row = (array) $row;

                return [
                    'xgf_per_game' => (float) ($row['adjusted_xgf_per_game'] ?? $row['baseline_xgf_per_game'] ?? 0),
                    'confidence_score' => $row['confidence_score'] ?? null,
                ];
            })
            ->filter(static fn (array $row): bool => $row['xgf_per_game'] > 0)
            ->sortByDesc('xgf_per_game')
            ->values();

        $teamXgfPerGame = (float) $roster->sum('xgf_per_game');

        if ($teamXgfPerGame <= 0) {
            return 0.5;
        }

        $includedXgfPerGame = 0.0;
        $weightedConfidence = 0.0;

        foreach ($roster as $row) {
            $xgfPerGame = (float) $row['xgf_per_game'];
            $confidence = max(0.0, min(1.0, (float) ($row['confidence_score'] ?? 0.5)));

            $includedXgfPerGame += $xgfPerGame;
            $weightedConfidence += $confidence * $xgfPerGame;

            if (($includedXgfPerGame / $teamXgfPerGame) >= self::SKATER_CONFIDENCE_COVERAGE_TARGET) {
                break;
            }
        }

        if ($includedXgfPerGame <= 0) {
            return 0.5;
        }

        return max(0.0, min(1.0, $weightedConfidence / $includedXgfPerGame));
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
     * @param array<string, mixed> $overrides
     * @return array<int, array<string, mixed>>
     */
    private function marketProbabilities(
        string $awayTeam,
        string $homeTeam,
        float $awayGoals,
        float $homeGoals,
        array $prediction,
        array $overrides
    ): array {
        $distribution = $this->scoreDistribution($awayGoals, $homeGoals);
        $markets = $this->requestedMarkets($overrides);
        $confidenceScore = (int) $prediction['confidence_score'];
        $probabilities = [];

        if (in_array('moneyline', $markets, true)) {
            $moneylineProbabilities = $this->moneylineProbabilitiesFromDistribution($distribution, $awayGoals, $homeGoals);
            $probabilities[] = $this->moneylineMarketProbability(
                'away',
                $awayTeam,
                $moneylineProbabilities['away'],
                $confidenceScore
            );
            $probabilities[] = $this->moneylineMarketProbability(
                'home',
                $homeTeam,
                $moneylineProbabilities['home'],
                $confidenceScore
            );
        }

        if (in_array('puckline', $markets, true)) {
            foreach ($this->requestedPucklineSpreads($overrides) as $spread) {
                array_push(
                    $probabilities,
                    ...$this->pucklineMarketProbabilities(
                        $awayTeam,
                        $homeTeam,
                        $awayGoals,
                        $homeGoals,
                        $distribution,
                        $spread,
                        $confidenceScore
                    )
                );
            }
        }

        if (in_array('total', $markets, true)) {
            foreach ($this->requestedTotalLines($overrides) as $line) {
                array_push(
                    $probabilities,
                    ...$this->totalMarketProbabilities($distribution, $line, $confidenceScore)
                );
            }
        }

        return $probabilities;
    }

    /**
     * @return array{away:float,home:float}
     */
    private function moneylineProbabilities(float $awayGoals, float $homeGoals): array
    {
        return $this->moneylineProbabilitiesFromDistribution(
            $this->scoreDistribution($awayGoals, $homeGoals),
            $awayGoals,
            $homeGoals
        );
    }

    /**
     * @param array<int,array<int,float>> $distribution
     * @return array{away:float,home:float}
     */
    private function moneylineProbabilitiesFromDistribution(array $distribution, float $awayGoals, float $homeGoals): array
    {
        $awayLambda = max(0.01, $awayGoals);
        $homeLambda = max(0.01, $homeGoals);
        $awayWinProbability = 0.0;
        $homeWinProbability = 0.0;
        $tieProbability = 0.0;
        $coveredProbability = 0.0;

        for ($awayScore = 0; $awayScore <= self::MONEYLINE_SCORE_DISTRIBUTION_MAX_GOALS; $awayScore++) {
            for ($homeScore = 0; $homeScore <= self::MONEYLINE_SCORE_DISTRIBUTION_MAX_GOALS; $homeScore++) {
                $scoreProbability = $distribution[$awayScore][$homeScore] ?? 0.0;
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

    /**
     * @return array<int,array<int,float>>
     */
    private function scoreDistribution(float $awayGoals, float $homeGoals): array
    {
        $awayLambda = max(0.01, $awayGoals);
        $homeLambda = max(0.01, $homeGoals);
        $distribution = [];

        for ($awayScore = 0; $awayScore <= self::MONEYLINE_SCORE_DISTRIBUTION_MAX_GOALS; $awayScore++) {
            $awayScoreProbability = $this->poissonProbability($awayScore, $awayLambda);

            for ($homeScore = 0; $homeScore <= self::MONEYLINE_SCORE_DISTRIBUTION_MAX_GOALS; $homeScore++) {
                $distribution[$awayScore][$homeScore] = $awayScoreProbability * $this->poissonProbability($homeScore, $homeLambda);
            }
        }

        return $distribution;
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
            'win_probability' => round($probability, 6),
            'push_probability' => 0.0,
            'loss_probability' => round(1 - $probability, 6),
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

    /**
     * @param array<string,mixed> $overrides
     * @return array<int,string>
     */
    private function requestedMarkets(array $overrides): array
    {
        $markets = array_values(array_unique(array_map(
            fn (mixed $market): string => (string) $market,
            is_array($overrides['markets'] ?? null) ? $overrides['markets'] : ['moneyline']
        )));

        if (! is_array($overrides['markets'] ?? null)) {
            if (array_key_exists('puckline', $overrides) || array_key_exists('puckline_spreads', $overrides)) {
                $markets[] = 'puckline';
            }

            if (array_key_exists('total', $overrides) || array_key_exists('total_lines', $overrides)) {
                $markets[] = 'total';
            }
        }

        return array_values(array_intersect(
            ['moneyline', 'puckline', 'total'],
            array_unique($markets)
        ));
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<int,float>
     */
    private function requestedPucklineSpreads(array $overrides): array
    {
        $spreads = [];

        if (array_key_exists('puckline', $overrides)) {
            $spreads[] = (float) $overrides['puckline'];
        }

        if (is_array($overrides['puckline_spreads'] ?? null)) {
            foreach ($overrides['puckline_spreads'] as $spread) {
                $spreads[] = (float) $spread;
            }
        }

        $spreads = array_values(array_filter(
            array_map(fn (float $spread): float => round(abs($spread), 3), $spreads),
            fn (float $spread): bool => $spread > 0
        ));

        if ($spreads === []) {
            return [self::DEFAULT_PUCKLINE_SPREAD];
        }

        return array_values(array_unique($spreads));
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<int,float>
     */
    private function requestedTotalLines(array $overrides): array
    {
        $lines = [];

        if (array_key_exists('total', $overrides)) {
            $lines[] = (float) $overrides['total'];
        }

        if (is_array($overrides['total_lines'] ?? null)) {
            foreach ($overrides['total_lines'] as $line) {
                $lines[] = (float) $line;
            }
        }

        $lines = array_values(array_filter(
            array_map(fn (float $line): float => round($line, 3), $lines),
            fn (float $line): bool => $line > 0
        ));

        if ($lines === []) {
            return [self::DEFAULT_TOTAL_LINE];
        }

        return array_values(array_unique($lines));
    }

    /**
     * @param array<int,array<int,float>> $distribution
     * @return array<int,array<string,mixed>>
     */
    private function pucklineMarketProbabilities(
        string $awayTeam,
        string $homeTeam,
        float $awayGoals,
        float $homeGoals,
        array $distribution,
        float $spread,
        int $confidenceScore
    ): array {
        $awayLine = $awayGoals >= $homeGoals ? -1 * $spread : $spread;
        $homeLine = -1 * $awayLine;

        return [
            $this->pucklineMarketProbability('away', $awayTeam, $awayLine, $distribution, $confidenceScore),
            $this->pucklineMarketProbability('home', $homeTeam, $homeLine, $distribution, $confidenceScore),
        ];
    }

    /**
     * @param array<int,array<int,float>> $distribution
     * @return array<string,mixed>
     */
    private function pucklineMarketProbability(
        string $selectionKey,
        string $teamAbbrev,
        float $line,
        array $distribution,
        int $confidenceScore
    ): array {
        $result = $this->lineResultProbabilities(
            $distribution,
            function (int $awayScore, int $homeScore) use ($selectionKey, $line): float {
                $scoreDifferential = $selectionKey === 'away'
                    ? $awayScore - $homeScore
                    : $homeScore - $awayScore;

                return $scoreDifferential + $line;
            }
        );

        return $this->lineMarketProbability(
            'puckline',
            $selectionKey,
            $teamAbbrev,
            $line,
            $result,
            $confidenceScore,
            'projected_margin_distribution'
        );
    }

    /**
     * @param array<int,array<int,float>> $distribution
     * @return array<int,array<string,mixed>>
     */
    private function totalMarketProbabilities(array $distribution, float $line, int $confidenceScore): array
    {
        return [
            $this->totalMarketProbability('over', $line, $distribution, $confidenceScore),
            $this->totalMarketProbability('under', $line, $distribution, $confidenceScore),
        ];
    }

    /**
     * @param array<int,array<int,float>> $distribution
     * @return array<string,mixed>
     */
    private function totalMarketProbability(
        string $selectionKey,
        float $line,
        array $distribution,
        int $confidenceScore
    ): array {
        $result = $this->lineResultProbabilities(
            $distribution,
            function (int $awayScore, int $homeScore) use ($selectionKey, $line): float {
                $totalGoals = $awayScore + $homeScore;

                return $selectionKey === 'over'
                    ? $totalGoals - $line
                    : $line - $totalGoals;
            }
        );

        return $this->lineMarketProbability(
            'total',
            $selectionKey,
            null,
            $line,
            $result,
            $confidenceScore,
            'projected_total_distribution'
        );
    }

    /**
     * @param array<int,array<int,float>> $distribution
     * @param callable(int,int):float $marginResolver
     * @return array{win:float,push:float,loss:float}
     */
    private function lineResultProbabilities(array $distribution, callable $marginResolver): array
    {
        $winProbability = 0.0;
        $pushProbability = 0.0;
        $lossProbability = 0.0;
        $coveredProbability = 0.0;

        foreach ($distribution as $awayScore => $homeScores) {
            foreach ($homeScores as $homeScore => $scoreProbability) {
                $coveredProbability += $scoreProbability;
                $margin = $marginResolver((int) $awayScore, (int) $homeScore);

                if ($margin > 0) {
                    $winProbability += $scoreProbability;
                } elseif ($margin < 0) {
                    $lossProbability += $scoreProbability;
                } else {
                    $pushProbability += $scoreProbability;
                }
            }
        }

        $coveredProbability = max(0.01, $coveredProbability);

        return [
            'win' => round($winProbability / $coveredProbability, 6),
            'push' => round($pushProbability / $coveredProbability, 6),
            'loss' => round($lossProbability / $coveredProbability, 6),
        ];
    }

    /**
     * @param array{win:float,push:float,loss:float} $result
     * @return array<string,mixed>
     */
    private function lineMarketProbability(
        string $marketKey,
        string $selectionKey,
        ?string $teamAbbrev,
        float $line,
        array $result,
        int $confidenceScore,
        string $method
    ): array {
        $probability = $result['win'];
        $payload = [
            'market_key' => $marketKey,
            'period_key' => 'full_game',
            'selection_key' => $selectionKey,
            'line' => $line,
            'line_unit' => 'goals',
            'probability' => $probability,
            'win_probability' => $result['win'],
            'push_probability' => $result['push'],
            'loss_probability' => $result['loss'],
            'fair_odds_american' => $this->americanOdds($probability),
            'fair_odds_decimal' => round(1 / max(0.000001, $probability), 3),
            'confidence_score' => $confidenceScore,
            'model' => [
                'method' => $method,
                'source' => 'prediction.predicted_score',
                'includes_overtime' => true,
                'max_score' => self::MONEYLINE_SCORE_DISTRIBUTION_MAX_GOALS,
            ],
        ];

        if ($teamAbbrev !== null) {
            $payload['team_abbrev'] = $teamAbbrev;
        }

        return $payload;
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
            'roster' => $side['roster'] ?? [],
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
