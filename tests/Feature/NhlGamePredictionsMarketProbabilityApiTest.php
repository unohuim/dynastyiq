<?php

declare(strict_types=1);

use App\Models\ApiClient;
use App\Services\NhlProjectedTeamMatchupSimulator;
use Illuminate\Testing\Fluent\AssertableJson;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function (): void {
    $this->createNhlStatsApiToken = function (array $scopes = ['nhl-stats:read']): string {
        $token = 'diq_gner8_market-probability-token';

        ApiClient::query()->create([
            'name' => 'Gner8 Market Probability',
            'slug' => 'gner8-market-probability',
            'token_prefix' => substr($token, 0, 24),
            'token_hash' => ApiClient::hashToken($token),
            'scopes' => $scopes,
        ]);

        return $token;
    };

    $this->bindMatchupSimulator = function (float $awayGoals = 2.4, float $homeGoals = 3.2): void {
        app()->bind(
            NhlProjectedTeamMatchupSimulator::class,
            fn (): NhlProjectedTeamMatchupSimulator => new class($awayGoals, $homeGoals) extends NhlProjectedTeamMatchupSimulator {
                public function __construct(
                    private readonly float $awayGoals,
                    private readonly float $homeGoals
                ) {
                }

                /**
                 * @return array<string,mixed>
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
                    return [
                        'is_available' => true,
                        'sides' => [
                            [
                                'offense_team' => $teamA,
                                'defense_team' => $teamB,
                                'summary' => [
                                    'total_goalie_adjusted_xgf_per_game' => $this->awayGoals,
                                    'total_goalie_adjustment_per_game' => 0.0,
                                ],
                                'roster' => [
                                    ['adjusted_xgf_per_game' => $this->awayGoals, 'confidence_score' => 0.8],
                                ],
                            ],
                            [
                                'offense_team' => $teamB,
                                'defense_team' => $teamA,
                                'summary' => [
                                    'total_goalie_adjusted_xgf_per_game' => $this->homeGoals,
                                    'total_goalie_adjustment_per_game' => 0.0,
                                ],
                                'roster' => [
                                    ['adjusted_xgf_per_game' => $this->homeGoals, 'confidence_score' => 0.8],
                                ],
                            ],
                        ],
                    ];
                }
            }
        );
    };

    $this->seedPredictionInputs = function (float $awayGoals = 2.4, float $homeGoals = 3.2): string {
        ($this->bindMatchupSimulator)($awayGoals, $homeGoals);
        $token = ($this->createNhlStatsApiToken)();

        DB::table('nhl_games')->insert([
            'nhl_game_id' => 2026020001,
            'season_id' => '20262027',
            'game_type' => 2,
            'game_date' => '2026-10-10',
            'game_dow' => 'Sat',
            'game_month' => 'Oct',
            'away_team_abbrev' => 'AWY',
            'home_team_abbrev' => 'HOM',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([['AWY', 9001], ['HOM', 9002]] as [$team, $goalieId]) {
            DB::table('nhl_goalie_season_projections')->insert([
                'projection_version' => 'goalie-market',
                'source_season_id' => '20252026',
                'target_season_id' => '20262027',
                'goalie_player_id' => $goalieId,
                'target_team_abbrev' => $team,
                'position' => 'G',
                'projected_games' => 50,
                'projected_starts' => 50,
                'projected_toi_hours' => 50,
                'projected_toi_seconds' => 180000,
                'projected_xga' => 140,
                'projected_ga' => 135,
                'projected_gsax' => 5,
                'projected_ev_xga' => 100,
                'projected_ev_ga' => 96,
                'projected_pk_xga' => 40,
                'projected_pk_ga' => 39,
                'confidence_score' => 0.8,
                'confidence_bucket' => 'high',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $token;
    };

    $this->predictionRequest = function (array $params = [], float $awayGoals = 2.4, float $homeGoals = 3.2) {
        $token = ($this->seedPredictionInputs)($awayGoals, $homeGoals);

        return $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/nhl-game-predictions?' . http_build_query(array_merge([
                'nhl_game_id' => 2026020001,
                'source_season_id' => '20252026',
                'target_season_id' => '20262027',
                'projection_version' => 'skater-market',
                'toi_projection_version' => 'toi-market',
                'goalie_projection_version' => 'goalie-market',
                'away_goalie_id' => 9001,
                'home_goalie_id' => 9002,
            ], $params)));
    };
});

it('requires a scoped API client for game prediction market probabilities', function (): void {
    ($this->bindMatchupSimulator)();

    $this->getJson('/api/nhl-game-predictions?nhl_game_id=2026020001')
        ->assertStatus(Response::HTTP_UNAUTHORIZED);
});

it('rejects API clients without the NHL stats scope', function (): void {
    ($this->bindMatchupSimulator)();
    $token = ($this->createNhlStatsApiToken)(['nhl-reference:read']);

    $this->withHeader('Authorization', 'Bearer ' . $token)
        ->getJson('/api/nhl-game-predictions?nhl_game_id=2026020001')
        ->assertStatus(Response::HTTP_FORBIDDEN);
});

it('rejects unsupported market keys', function (): void {
    ($this->predictionRequest)(['markets' => ['spread']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['markets.0']);
});

it('rejects scalar market lists', function (): void {
    ($this->predictionRequest)(['markets' => 'moneyline'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['markets']);
});

it('rejects zero puckline spreads', function (): void {
    ($this->predictionRequest)(['puckline' => 0])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['puckline']);
});

it('rejects scalar puckline spread lists', function (): void {
    ($this->predictionRequest)(['puckline_spreads' => '1.5'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['puckline_spreads']);
});

it('rejects non-positive total lines', function (): void {
    ($this->predictionRequest)(['total' => 0])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['total']);
});

it('rejects scalar total line lists', function (): void {
    ($this->predictionRequest)(['total_lines' => '6.0'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['total_lines']);
});

it('keeps the default game prediction response limited to moneyline probabilities', function (): void {
    ($this->predictionRequest)()
        ->assertOk()
        ->assertJsonCount(2, 'market_probabilities')
        ->assertJsonPath('market_probabilities.0.market_key', 'moneyline')
        ->assertJsonPath('market_probabilities.1.market_key', 'moneyline');
});

it('adds puckline rows when the simple puckline parameter is provided', function (): void {
    $rows = collect(($this->predictionRequest)(['puckline' => 1.5])->assertOk()->json('market_probabilities'));

    expect($rows->where('market_key', 'moneyline')->count())->toBe(2)
        ->and($rows->where('market_key', 'puckline')->count())->toBe(2);
});

it('adds total rows when the simple total parameter is provided', function (): void {
    $rows = collect(($this->predictionRequest)(['total' => 6.0])->assertOk()->json('market_probabilities'));

    expect($rows->where('market_key', 'moneyline')->count())->toBe(2)
        ->and($rows->where('market_key', 'total')->count())->toBe(2);
});

it('returns only requested explicit markets', function (): void {
    $rows = collect(($this->predictionRequest)(['markets' => ['puckline']])->assertOk()->json('market_probabilities'));

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('market_key')->unique()->values()->all())->toBe(['puckline']);
});

it('defaults explicit puckline requests to the NHL standard 1.5 spread', function (): void {
    $rows = collect(($this->predictionRequest)(['markets' => ['puckline']])->assertOk()->json('market_probabilities'));

    expect($rows->pluck('line')->unique()->sort()->values()->all())->toBe([-1.5, 1.5]);
});

it('defaults explicit total requests to a 6.0 goal line', function (): void {
    $rows = collect(($this->predictionRequest)(['markets' => ['total']])->assertOk()->json('market_probabilities'));

    expect($rows->pluck('line')->unique()->values()->all())->toBe([6.0]);
});

it('uses the projected favorite as the negative standard puckline side', function (): void {
    $rows = collect(($this->predictionRequest)(['markets' => ['puckline']], 2.4, 3.2)->assertOk()->json('market_probabilities'));

    expect($rows->firstWhere('selection_key', 'home')['line'])->toBe(-1.5)
        ->and($rows->firstWhere('selection_key', 'away')['line'])->toBe(1.5);
});

it('flips the negative puckline side when the away team is projected stronger', function (): void {
    $rows = collect(($this->predictionRequest)(['markets' => ['puckline']], 3.5, 2.2)->assertOk()->json('market_probabilities'));

    expect($rows->firstWhere('selection_key', 'away')['line'])->toBe(-1.5)
        ->and($rows->firstWhere('selection_key', 'home')['line'])->toBe(1.5);
});

it('returns one puckline pair for each requested spread', function (): void {
    $rows = collect(($this->predictionRequest)([
        'markets' => ['puckline'],
        'puckline_spreads' => [1.5, 2.5],
    ])->assertOk()->json('market_probabilities'));

    expect($rows)->toHaveCount(4)
        ->and($rows->pluck('line')->sort()->values()->all())->toBe([-2.5, -1.5, 1.5, 2.5]);
});

it('returns one over and under pair for each requested total line', function (): void {
    $rows = collect(($this->predictionRequest)([
        'markets' => ['total'],
        'total_lines' => [5.5, 6.0, 6.5],
    ])->assertOk()->json('market_probabilities'));

    expect($rows)->toHaveCount(6)
        ->and($rows->pluck('selection_key')->unique()->sort()->values()->all())->toBe(['over', 'under'])
        ->and($rows->pluck('line')->unique()->sort()->values()->all())->toBe([5.5, 6.0, 6.5]);
});

it('includes push probability for whole-number total lines', function (): void {
    $rows = collect(($this->predictionRequest)([
        'markets' => ['total'],
        'total' => 6.0,
    ])->assertOk()->json('market_probabilities'));

    expect($rows->firstWhere('selection_key', 'over')['push_probability'])->toBeGreaterThan(0)
        ->and($rows->firstWhere('selection_key', 'under')['push_probability'])->toBeGreaterThan(0);
});

it('does not create push probability for half-goal total lines', function (): void {
    $rows = collect(($this->predictionRequest)([
        'markets' => ['total'],
        'total' => 6.5,
    ])->assertOk()->json('market_probabilities'));

    expect($rows->firstWhere('selection_key', 'over')['push_probability'])->toBe(0.0)
        ->and($rows->firstWhere('selection_key', 'under')['push_probability'])->toBe(0.0);
});

it('includes push probability for whole-number puckline spreads', function (): void {
    $rows = collect(($this->predictionRequest)([
        'markets' => ['puckline'],
        'puckline' => 1.0,
    ])->assertOk()->json('market_probabilities'));

    expect($rows->firstWhere('selection_key', 'away')['push_probability'])->toBeGreaterThan(0)
        ->and($rows->firstWhere('selection_key', 'home')['push_probability'])->toBeGreaterThan(0);
});

it('does not create push probability for half-goal puckline spreads', function (): void {
    $rows = collect(($this->predictionRequest)([
        'markets' => ['puckline'],
        'puckline' => 1.5,
    ])->assertOk()->json('market_probabilities'));

    expect($rows->firstWhere('selection_key', 'away')['push_probability'])->toBe(0.0)
        ->and($rows->firstWhere('selection_key', 'home')['push_probability'])->toBe(0.0);
});

it('returns line result probabilities that sum to one for totals', function (): void {
    $row = collect(($this->predictionRequest)([
        'markets' => ['total'],
        'total' => 6.0,
    ])->assertOk()->json('market_probabilities'))->firstWhere('selection_key', 'over');

    $sum = $row['win_probability'] + $row['push_probability'] + $row['loss_probability'];

    expect(round($sum, 5))->toBe(1.0);
});

it('returns line result probabilities that sum to one for pucklines', function (): void {
    $row = collect(($this->predictionRequest)([
        'markets' => ['puckline'],
        'puckline' => 1.5,
    ])->assertOk()->json('market_probabilities'))->firstWhere('selection_key', 'home');

    $sum = $row['win_probability'] + $row['push_probability'] + $row['loss_probability'];

    expect(round($sum, 5))->toBe(1.0);
});

it('omits team abbreviations for total probability rows', function (): void {
    ($this->predictionRequest)(['markets' => ['total']])
        ->assertOk()
        ->assertJson(fn (AssertableJson $json) => $json
            ->where('market_probabilities.0.market_key', 'total')
            ->missing('market_probabilities.0.team_abbrev')
            ->etc()
        );
});

it('includes team abbreviations for puckline probability rows', function (): void {
    $rows = collect(($this->predictionRequest)(['markets' => ['puckline']])->assertOk()->json('market_probabilities'));

    expect($rows->firstWhere('selection_key', 'away')['team_abbrev'])->toBe('AWY')
        ->and($rows->firstWhere('selection_key', 'home')['team_abbrev'])->toBe('HOM');
});

it('includes fair odds and model metadata for line-dependent rows', function (): void {
    ($this->predictionRequest)(['markets' => ['puckline', 'total']])
        ->assertOk()
        ->assertJson(fn (AssertableJson $json) => $json
            ->has('market_probabilities.0.fair_odds_american')
            ->has('market_probabilities.0.fair_odds_decimal')
            ->where('market_probabilities.0.model.source', 'prediction.predicted_score')
            ->where('market_probabilities.0.model.includes_overtime', true)
            ->etc()
        );
});

it('uses a fully resolved single-source reported lineup for prediction simulation', function (): void {
    $token = ($this->seedPredictionInputs)(2.4, 3.2);
    $sourceId = DB::table('sources')->insertGetId([
        'platform' => 'x', 'name' => 'Practice Reporter', 'handle' => 'practice_reporter',
        'canonical_url' => 'https://x.com/practice_reporter',
        'first_seen_at' => now(), 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $observationId = DB::table('nhl_lineup_observations')->insertGetId([
        'nhl_game_id' => 2026020001, 'team_id' => 1, 'team_abbrev' => 'AWY',
        'source_id' => $sourceId, 'post_url' => 'https://x.com/practice_reporter/status/1',
        'post_text' => 'Full practice lineup', 'provider_published_at' => now(), 'observed_at' => now(),
        'completeness' => 'full', 'structure_hash' => str_repeat('b', 64),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $players = [];
    foreach (range(1, 18) as $index) {
        $isForward = $index <= 12;
        $positionIndex = $isForward ? $index : $index - 12;
        $lineSize = $isForward ? 3 : 2;
        $players[] = [
            'nhl_lineup_observation_id' => $observationId,
            'team_id' => 1,
            'team_abbrev' => 'AWY',
            'nhl_player_id' => 8480000 + $index,
            'player_name' => "Resolved Skater {$index}",
            'lineup_role' => $isForward ? 'forward' : 'defense',
            'line_key' => ($isForward ? 'F' : 'D') . (int) ceil($positionIndex / $lineSize),
            'slot_index' => (($positionIndex - 1) % $lineSize) + 1,
            'resolution_status' => 'resolved', 'created_at' => now(), 'updated_at' => now(),
        ];
    }
    DB::table('nhl_lineup_observation_players')->insert($players);
    DB::table('nhl_current_lineups')->insert([
        'nhl_game_id' => 2026020001, 'team_id' => 1, 'team_abbrev' => 'AWY',
        'nhl_lineup_observation_id' => $observationId, 'structure_hash' => str_repeat('b', 64),
        'evidence_status' => 'reported', 'source_count' => 1,
        'first_observed_at' => now(), 'last_observed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $simulator = \Mockery::mock(NhlProjectedTeamMatchupSimulator::class);
    $simulator->shouldReceive('simulateWithRosters')->once()
        ->withArgs(fn (...$arguments): bool => count($arguments[9]) === 18 && $arguments[10] === null)
        ->andReturn([
            'is_available' => true,
            'sides' => [
                [
                    'offense_team' => 'AWY', 'defense_team' => 'HOM',
                    'summary' => ['total_goalie_adjusted_xgf_per_game' => 2.4, 'total_goalie_adjustment_per_game' => 0.0],
                    'roster' => [['adjusted_xgf_per_game' => 2.4, 'confidence_score' => 0.8]],
                ],
                [
                    'offense_team' => 'HOM', 'defense_team' => 'AWY',
                    'summary' => ['total_goalie_adjusted_xgf_per_game' => 3.2, 'total_goalie_adjustment_per_game' => 0.0],
                    'roster' => [['adjusted_xgf_per_game' => 3.2, 'confidence_score' => 0.8]],
                ],
            ],
        ]);
    app()->instance(NhlProjectedTeamMatchupSimulator::class, $simulator);

    $this->withHeader('Authorization', 'Bearer ' . $token)
        ->getJson('/api/nhl-game-predictions?' . http_build_query([
            'nhl_game_id' => 2026020001,
            'source_season_id' => '20252026', 'target_season_id' => '20262027',
            'projection_version' => 'skater-market', 'toi_projection_version' => 'toi-market',
            'goalie_projection_version' => 'goalie-market', 'away_goalie_id' => 9001, 'home_goalie_id' => 9002,
        ]))
        ->assertOk()
        ->assertJsonPath('inputs.away_lineup_source', 'anticipated_lineup')
        ->assertJsonPath('inputs.home_lineup_source', 'projected_roster')
        ->assertJsonCount(18, 'anticipated_lineups.away.players')
        ->assertJsonCount(18, 'teams.away.roster')
        ->assertJsonPath('teams.away.roster.0.projection_source', 'replacement_level');
});
