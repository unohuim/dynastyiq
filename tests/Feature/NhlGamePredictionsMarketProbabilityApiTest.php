<?php

declare(strict_types=1);

use App\Models\ApiClient;
use App\Services\NhlProjectedTeamMatchupSimulator;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\Fluent\AssertableJson;
use Symfony\Component\HttpFoundation\Response;

it('keeps an unmodelled manual starter with labelled league average predictions', function (?int $goalieId): void {
    $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-10 12:00:00 America/Toronto'));
    $token = ($this->seedPredictionInputs)();
    \App\Models\NhlStartingGoalieObservation::query()->create([
        'nhl_game_id' => 2026020001, 'game_date' => '2026-10-10', 'team_abbrev' => 'AWY',
        'opponent_abbrev' => 'HOM', 'is_home' => false, 'nhl_player_id' => $goalieId,
        'player_name' => 'Joshua Kotai', 'provider' => 'manual', 'status' => 'expected',
        'fetched_at' => now(), 'raw_evidence' => [],
    ]);
    $this->withToken($token)->getJson('/api/nhl-game-predictions?' . http_build_query([
        'nhl_game_id' => 2026020001, 'source_season_id' => '20252026', 'target_season_id' => '20262027',
        'projection_version' => 'skater-market', 'toi_projection_version' => 'toi-market',
        'goalie_projection_version' => 'goalie-market', 'home_goalie_id' => 9002,
    ]))->assertOk()->assertJsonPath('goalies.away.name', 'Joshua Kotai')
        ->assertJsonPath('goalies.away.nhl_player_id', $goalieId)
        ->assertJsonPath('goalies.away.projection_source', 'league_average')
        ->assertJsonPath('goalies.away.projected_games', null)
        ->assertJsonPath('goalies.away.confidence_bucket', 'low')
        ->assertJsonPath('goalies.home.projection_source', 'goalie_model');
    $this->assertDatabaseCount('nhl_goalie_season_projections', 2);
    $this->travelBack();
})->with(['canonical rookie' => [9999], 'name only starter' => [null]]);

it('uses neutral league expected rates without saving a goalie projection', function (): void {
    ($this->seedPredictionInputs)();
    $baseline = (new NhlProjectedTeamMatchupSimulator())->leagueAverageGoalieProjection('20262027', 'goalie-market');
    expect(round($baseline->projected_xga / $baseline->projected_games, 4))->toBe(2.8)
        ->and(round($baseline->projected_pk_xga / $baseline->projected_games, 4))->toBe(0.8)
        ->and($baseline->projected_ga)->toBe($baseline->projected_xga)
        ->and($baseline->projected_pk_ga)->toBe($baseline->projected_pk_xga)
        ->and($baseline->projected_gsax)->toBe(0.0);
    $this->assertDatabaseCount('nhl_goalie_season_projections', 2);
});

it('uses the designated boxscore starter in live predictions and dressed rosters', function (string $state): void {
    $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-10 23:00:00 UTC'));
    $token = ($this->seedPredictionInputs)();
    \App\Models\NhlStartingGoalieObservation::query()->create([
        'nhl_game_id' => 2026020001, 'game_date' => '2026-10-10', 'team_abbrev' => 'AWY',
        'opponent_abbrev' => 'HOM', 'is_home' => false, 'nhl_player_id' => 9003,
        'player_name' => 'Old Manual Goalie', 'provider' => 'manual', 'status' => 'expected',
        'fetched_at' => now(), 'raw_evidence' => [],
    ]);
    Http::fake(['api-web.nhle.com/*' => Http::response([
        'id' => 2026020001, 'gameState' => $state,
        'awayTeam' => ['abbrev' => 'AWY'], 'homeTeam' => ['abbrev' => 'HOM'],
        'playerByGameStats' => [
            'awayTeam' => ['goalies' => [
                ['playerId' => 9003, 'starter' => false, 'toi' => '45:00'],
                ['playerId' => 9001, 'starter' => true, 'toi' => '15:00'],
            ]],
            'homeTeam' => ['goalies' => [['playerId' => 9002, 'starter' => true]]],
        ],
    ])]);
    $this->withToken($token)->getJson('/api/nhl-game-predictions?' . http_build_query([
        'nhl_game_id' => 2026020001, 'source_season_id' => '20252026', 'target_season_id' => '20262027',
        'projection_version' => 'skater-market', 'toi_projection_version' => 'toi-market',
        'goalie_projection_version' => 'goalie-market', 'away_goalie_id' => 9003,
    ]))->assertOk()->assertJsonPath('goalies.away.nhl_player_id', 9001)
        ->assertJsonPath('goalies.away.selection_source', 'nhl_boxscore')
        ->assertJsonPath('inputs.away_goalie_id', 9001)
        ->assertJsonFragment(['nhl_player_id' => 9001, 'is_starter' => true]);
    Http::assertSentCount(1);
    $this->assertDatabaseHas('nhl_starting_goalie_observations', ['nhl_player_id' => 9003, 'provider' => 'manual']);
    $this->travelBack();
})->with(['LIVE', 'CRIT', 'FINAL', 'OFF']);

it('retains pregame or unavailable boxscore fallback selection', function (string $state, bool $starter): void {
    ($this->seedPredictionInputs)();
    Http::fake(['api-web.nhle.com/*' => Http::response([
        'id' => 2026020001, 'gameState' => $state, 'awayTeam' => ['abbrev' => 'AWY'],
        'playerByGameStats' => ['awayTeam' => ['goalies' => [['playerId' => 9001, 'starter' => $starter]]]],
    ])]);
    $result = app(\App\Services\NhlStartingGoalieSelector::class)->selectForPrediction(
        2026020001, 'AWY', providedGoalieId: 9003
    );
    expect($result['nhl_player_id'])->toBe(9003)->and($result['selection_source'])->toBe('provided');
})->with([['FUT', true], ['PRE', true], ['LIVE', false], ['', false]]);

beforeEach(function (): void {
    Http::fake(['api-web.nhle.com/*' => Http::response([])]);
    config(['cache.default' => 'array']);
    \Illuminate\Support\Facades\Cache::forget('nhl:gamecenter:boxscore:2026020001');
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

    $this->insertReportedLineup = function (
        string $teamAbbrev,
        int $teamId,
        int $firstNhlPlayerId,
        string $suffix
    ): void {
        $sourceId = DB::table('sources')->insertGetId([
            'platform' => 'x',
            'name' => "{$teamAbbrev} Reporter",
            'handle' => mb_strtolower($teamAbbrev) . "_reporter_{$suffix}",
            'canonical_url' => "https://x.com/{$teamAbbrev}_reporter_{$suffix}",
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $structureHash = hash('sha256', "{$teamAbbrev}-{$suffix}");
        $observationId = DB::table('nhl_lineup_observations')->insertGetId([
            'nhl_game_id' => 2026020001,
            'team_id' => $teamId,
            'team_abbrev' => $teamAbbrev,
            'source_id' => $sourceId,
            'post_url' => "https://x.com/{$teamAbbrev}_reporter_{$suffix}/status/1",
            'post_text' => "Full {$teamAbbrev} lineup",
            'provider_published_at' => now(),
            'observed_at' => now(),
            'completeness' => 'full',
            'structure_hash' => $structureHash,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $players = [];

        foreach (range(1, 18) as $index) {
            $isForward = $index <= 12;
            $positionIndex = $isForward ? $index : $index - 12;
            $lineSize = $isForward ? 3 : 2;
            $players[] = [
                'nhl_lineup_observation_id' => $observationId,
                'team_id' => $teamId,
                'team_abbrev' => $teamAbbrev,
                'nhl_player_id' => $firstNhlPlayerId + $index - 1,
                'player_name' => "{$teamAbbrev} Skater {$index}",
                'lineup_role' => $isForward ? 'forward' : 'defense',
                'line_key' => ($isForward ? 'F' : 'D') . (int) ceil($positionIndex / $lineSize),
                'slot_index' => (($positionIndex - 1) % $lineSize) + 1,
                'resolution_status' => 'resolved',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::table('nhl_lineup_observation_players')->insert($players);
        DB::table('nhl_current_lineups')->insert([
            'nhl_game_id' => 2026020001,
            'team_id' => $teamId,
            'team_abbrev' => $teamAbbrev,
            'nhl_lineup_observation_id' => $observationId,
            'structure_hash' => $structureHash,
            'evidence_status' => 'reported',
            'source_count' => 1,
            'first_observed_at' => now(),
            'last_observed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    };
});

it('requires a scoped API client for game prediction market probabilities', function (): void {
    ($this->bindMatchupSimulator)();

    $this->getJson('/api/nhl-game-predictions?nhl_game_id=2026020001')
        ->assertStatus(Response::HTTP_UNAUTHORIZED);
});

it('uses manual G1 and includes unprojected G2 in the dressed prediction roster', function (bool $missingPreseasonOpponent): void {
    $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-10 12:00:00 America/Toronto'));
    Http::fake();
    $token = ($this->seedPredictionInputs)();
    DB::table('nhl_games')->where('nhl_game_id', 2026020001)->update([
        'game_type' => $missingPreseasonOpponent ? 1 : 2,
        'start_time_utc' => '2026-10-10 23:00:00',
    ]);
    DB::table('nhl_teams')->insert([
        ['nhl_id' => 1, 'abbrev' => 'AWY'], ['nhl_id' => 2, 'abbrev' => 'HOM'],
    ]);
    $names = [];
    foreach (range(1, 18) as $index) {
        $name = 'Skater ' . $index;
        $names[] = $name;
        \App\Models\Player::query()->create([
            'nhl_id' => 8483000 + $index, 'full_name' => $name, 'first_name' => 'Skater',
            'last_name' => (string) $index, 'team_abbrev' => 'AWY', 'position' => $index <= 12 ? 'C' : 'D',
        ]);
    }
    foreach ([9001 => 'Starter Test', 9003 => 'Backup Test'] as $id => $name) {
        \App\Models\Player::query()->create([
            'nhl_id' => $id, 'full_name' => $name, 'first_name' => explode(' ', $name)[0],
            'last_name' => 'Test', 'team_abbrev' => 'AWY', 'position' => 'G',
        ]);
    }
    $text = collect(array_chunk(array_slice($names, 0, 12), 3))->map(fn ($line): string => implode(' - ', $line))->implode("\n")
        . "\nDefense\n" . collect(array_chunk(array_slice($names, 12), 2))->map(fn ($line): string => implode(' - ', $line))->implode("\n")
        . "\nGoalies\nStarter Test\nBackup Test";
    $user = \App\Models\User::factory()->create();
    $role = \App\Models\Role::query()->create(['name' => 'Super Admin', 'slug' => 'super-admin', 'level' => 99]);
    $user->roles()->attach($role->id, ['organization_id' => null]);
    $this->actingAs($user)->postJson('/games/2026020001/lineup', ['team_abbrev' => 'AWY', 'text' => $text])->assertOk();
    $this->assertDatabaseHas('nhl_starting_goalie_observations', [
        'nhl_game_id' => 2026020001, 'team_abbrev' => 'AWY', 'nhl_player_id' => 9001,
        'provider' => 'manual', 'status' => 'expected',
    ]);
    $this->assertDatabaseHas('nhl_lineup_observation_players', [
        'nhl_player_id' => 9003, 'line_key' => 'G', 'slot_index' => 2,
    ]);
    $this->assertDatabaseMissing('nhl_goalie_season_projections', ['goalie_player_id' => 9003]);
    if (! $missingPreseasonOpponent) {
        $this->postJson('/games/2026020001/starting-goalie', [
            'team_abbrev' => 'AWY', 'player_id' => \App\Models\Player::query()->where('nhl_id', 9001)->value('id'),
        ])->assertOk()->assertJsonPath('starting_goalie.selection_source', 'manual_starter_override');
    }
    // Even newer generic expectations cannot replace the accepted manual G1.
    foreach ([2026020001, 2026020002] as $gameId) {
        \App\Models\NhlStartingGoalieObservation::query()->create([
            'nhl_game_id' => $gameId, 'game_date' => '2026-10-10', 'team_abbrev' => 'AWY',
            'opponent_abbrev' => 'HOM', 'is_home' => false, 'nhl_player_id' => $gameId === 2026020001 ? 9004 : 9005,
            'player_name' => 'Other Goalie ' . $gameId, 'provider' => 'rotowire', 'status' => 'expected',
            'fetched_at' => now()->addMinute(), 'raw_evidence' => [],
        ]);
    }
    expect(app(\App\Services\NhlStartingGoalieSelector::class)->select(2026020002, 'AWY')['nhl_player_id'])->toBe(9005);
    $simulator = \Mockery::mock(NhlProjectedTeamMatchupSimulator::class);
    if ($missingPreseasonOpponent) {
        $simulator->shouldNotReceive('simulateWithRosters');
        $simulator->shouldNotReceive('simulate');
    } else {
        $simulator->shouldReceive('simulateWithRosters')->once()
            ->withArgs(fn (...$args): bool => $args[7] === 9001 && count($args[9]) === 18 && ! in_array(9003, $args[9], true))
            ->andReturn(['is_available' => true, 'sides' => [
                ['offense_team' => 'AWY', 'defense_team' => 'HOM', 'summary' => [
                    'total_goalie_adjusted_xgf_per_game' => 2.4, 'total_goalie_adjustment_per_game' => 0.0,
                ], 'roster' => []],
                ['offense_team' => 'HOM', 'defense_team' => 'AWY', 'summary' => [
                    'total_goalie_adjusted_xgf_per_game' => 3.2, 'total_goalie_adjustment_per_game' => 0.0,
                ], 'roster' => []],
            ]]);
    }
    app()->instance(NhlProjectedTeamMatchupSimulator::class, $simulator);
    $response = $this->withHeader('Authorization', 'Bearer ' . $token)->getJson('/api/nhl-game-predictions?' . http_build_query([
        'nhl_game_id' => 2026020001, 'source_season_id' => '20252026', 'target_season_id' => '20262027',
        'projection_version' => 'skater-market', 'toi_projection_version' => 'toi-market',
        'goalie_projection_version' => 'goalie-market', 'home_goalie_id' => 9002,
    ]))->assertOk()->assertJsonPath('prediction_available', ! $missingPreseasonOpponent)
        ->assertJsonPath('goalies.away.nhl_player_id', 9001)
        ->assertJsonCount(18, 'teams.away.roster')->assertJsonCount(20, 'teams.away.dressed_roster');
    $goalies = collect($response->json('teams.away.dressed_roster'))->where('lineup_role', 'goalie');
    if (! $missingPreseasonOpponent) {
        $response->assertJsonPath('goalies.away.selection_source', 'manual_starter_override');
    }
    expect($goalies)->toHaveCount(2)
        ->and($goalies->firstWhere('nhl_player_id', 9001)['is_starter'])->toBeTrue()
        ->and($goalies->firstWhere('nhl_player_id', 9003)['is_starter'])->toBeFalse()
        ->and($goalies->firstWhere('nhl_player_id', 9003)['slot_index'])->toBe(2);
    $this->travelBack();
})->with([false, true]);

it('reconciles the dressed goalie slots after an explicit starter selection', function (int $chosen, int $backup): void {
    $method = new ReflectionMethod(\App\Services\NhlGamePredictionPayload::class, 'withDressedRoster');
    $result = $method->invoke(app(\App\Services\NhlGamePredictionPayload::class), ['roster' => []], ['players' => [
        ['nhl_player_id' => 11, 'player_name' => 'First Goalie', 'lineup_role' => 'goalie', 'line_key' => 'G', 'slot_index' => 1],
        ['nhl_player_id' => 12, 'player_name' => 'Second Goalie', 'lineup_role' => 'goalie', 'line_key' => 'G', 'slot_index' => 2],
    ]], ['nhl_player_id' => $chosen, 'name' => 'Chosen Goalie', 'selection_source' => 'manual_starter_override']);
    expect($result['dressed_roster'])->toHaveCount(2)
        ->and($result['dressed_roster'][0]['nhl_player_id'])->toBe($chosen)
        ->and($result['dressed_roster'][0]['is_starter'])->toBeTrue()
        ->and($result['dressed_roster'][1]['nhl_player_id'])->toBe($backup)
        ->and($result['dressed_roster'][1]['slot_index'])->toBe(2)
        ->and($result['dressed_roster'][1]['is_starter'])->toBeFalse();
})->with(['promote backup' => [12, 11], 'select different goalie' => [13, 12]]);

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
        ->assertJsonPath('prediction_available', true)
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

it('returns evidence without a prediction when one preseason lineup is unresolved', function (): void {
    $token = ($this->seedPredictionInputs)();
    DB::table('nhl_games')->where('nhl_game_id', 2026020001)->update(['game_type' => 1]);
    DB::table('nhl_teams')->insert([
        ['nhl_id' => 1, 'abbrev' => 'AWY', 'created_at' => now(), 'updated_at' => now()],
        ['nhl_id' => 2, 'abbrev' => 'HOM', 'created_at' => now(), 'updated_at' => now()],
    ]);
    ($this->insertReportedLineup)('AWY', 1, 8481001, 'away-only');
    Http::fake(['api-web.nhle.com/*' => Http::response([])]);

    $simulator = \Mockery::mock(NhlProjectedTeamMatchupSimulator::class);
    $simulator->shouldNotReceive('simulate');
    $simulator->shouldNotReceive('simulateWithRosters');
    app()->instance(NhlProjectedTeamMatchupSimulator::class, $simulator);

    $this->withHeader('Authorization', 'Bearer ' . $token)
        ->getJson('/api/nhl-game-predictions?' . http_build_query([
            'nhl_game_id' => 2026020001,
            'source_season_id' => '20252026', 'target_season_id' => '20262027',
            'projection_version' => 'skater-market', 'toi_projection_version' => 'toi-market',
            'goalie_projection_version' => 'goalie-market', 'away_goalie_id' => 9001, 'home_goalie_id' => 9002,
        ]))
        ->assertOk()
        ->assertJsonPath('prediction_available', false)
        ->assertJsonPath('reason', 'preseason_lineup_unresolved')
        ->assertJsonPath('missing_lineups.0', 'HOM')
        ->assertJsonPath('inputs.away_lineup_source', 'anticipated_lineup')
        ->assertJsonPath('inputs.home_lineup_source', 'projected_roster')
        ->assertJsonPath('prediction', null)
        ->assertJsonCount(0, 'market_probabilities')
        ->assertJsonCount(18, 'teams.away.roster');
});

it('returns both missing teams when neither preseason lineup is resolved', function (): void {
    $token = ($this->seedPredictionInputs)();
    DB::table('nhl_games')->where('nhl_game_id', 2026020001)->update(['game_type' => 1]);
    DB::table('nhl_teams')->insert([
        ['nhl_id' => 1, 'abbrev' => 'AWY', 'created_at' => now(), 'updated_at' => now()],
        ['nhl_id' => 2, 'abbrev' => 'HOM', 'created_at' => now(), 'updated_at' => now()],
    ]);
    Http::fake(['api-web.nhle.com/*' => Http::response([])]);

    $simulator = \Mockery::mock(NhlProjectedTeamMatchupSimulator::class);
    $simulator->shouldNotReceive('simulate');
    $simulator->shouldNotReceive('simulateWithRosters');
    app()->instance(NhlProjectedTeamMatchupSimulator::class, $simulator);

    $this->withHeader('Authorization', 'Bearer ' . $token)
        ->getJson('/api/nhl-game-predictions?' . http_build_query([
            'nhl_game_id' => 2026020001,
            'source_season_id' => '20252026', 'target_season_id' => '20262027',
            'projection_version' => 'skater-market', 'toi_projection_version' => 'toi-market',
            'goalie_projection_version' => 'goalie-market', 'away_goalie_id' => 9001, 'home_goalie_id' => 9002,
        ]))
        ->assertOk()
        ->assertJsonPath('prediction_available', false)
        ->assertJsonPath('missing_lineups.0', 'AWY')
        ->assertJsonPath('missing_lineups.1', 'HOM')
        ->assertJsonPath('teams.away.lineup_source', 'projected_roster')
        ->assertJsonPath('teams.home.lineup_source', 'projected_roster')
        ->assertJsonCount(0, 'market_probabilities');
});

it('fetches and persists complete NHL boxscore lineups before withholding a preseason prediction', function (): void {
    $token = ($this->seedPredictionInputs)();
    DB::table('nhl_games')->where('nhl_game_id', 2026020001)->update(['game_type' => 1]);
    DB::table('nhl_teams')->insert([
        ['nhl_id' => 1, 'abbrev' => 'AWY', 'created_at' => now(), 'updated_at' => now()],
        ['nhl_id' => 2, 'abbrev' => 'HOM', 'created_at' => now(), 'updated_at' => now()],
    ]);
    $teamPlayers = function (int $firstPlayerId): array {
        return [
            'forwards' => collect(range(0, 11))->map(fn (int $index): array => [
                'playerId' => $firstPlayerId + $index,
                'name' => ['default' => 'Forward ' . ($firstPlayerId + $index)],
            ])->all(),
            'defense' => collect(range(12, 17))->map(fn (int $index): array => [
                'playerId' => $firstPlayerId + $index,
                'name' => ['default' => 'Defense ' . ($firstPlayerId + $index)],
            ])->all(),
            'goalies' => [],
        ];
    };
    Http::fake(['api-web.nhle.com/*' => Http::response([
        'awayTeam' => ['abbrev' => 'AWY'],
        'homeTeam' => ['abbrev' => 'HOM'],
        'playerByGameStats' => [
            'awayTeam' => $teamPlayers(8481001),
            'homeTeam' => $teamPlayers(8482001),
        ],
    ])]);
    $simulator = \Mockery::mock(NhlProjectedTeamMatchupSimulator::class);
    $simulator->shouldReceive('simulateWithRosters')->once()
        ->withArgs(fn (...$arguments): bool => count($arguments[9]) === 18 && count($arguments[10]) === 18)
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
        ->assertJsonPath('prediction_available', true)
        ->assertJsonPath('inputs.away_lineup_source', 'nhl_boxscore')
        ->assertJsonPath('inputs.home_lineup_source', 'nhl_boxscore')
        ->assertJsonPath('anticipated_lineups.away.evidence_status', 'official')
        ->assertJsonPath('anticipated_lineups.home.evidence_status', 'official')
        ->assertJsonCount(18, 'teams.away.roster')
        ->assertJsonCount(18, 'teams.home.roster')
        ->assertJsonPath('teams.away.roster.0.projection_source', 'replacement_level');

    $this->assertDatabaseCount('nhl_current_lineups', 2)
        ->assertDatabaseHas('nhl_current_lineups', ['team_abbrev' => 'AWY', 'evidence_status' => 'official'])
        ->assertDatabaseHas('nhl_current_lineups', ['team_abbrev' => 'HOM', 'evidence_status' => 'official']);
});

it('applies nhle and replacement values to rookies in a resolved game lineup', function (): void {
    $rookie = DB::table('players')->insertGetId([
        'nhl_id' => 8487001, 'first_name' => 'Nhle', 'last_name' => 'Rookie',
        'full_name' => 'Nhle Rookie', 'position' => 'C', 'team_abbrev' => 'AWY',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('stats')->insert([
        'player_id' => $rookie, 'player_name' => 'Nhle Rookie', 'season_id' => '20252026',
        'league_abbrev' => 'AHL', 'team_name' => 'Affiliate', 'game_type_id' => 2,
        'gp' => 50, 'g' => 20, 'a' => 30, 'pts' => 50, 'sog' => 150,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('nhle_league_factors')->insert([
        'source' => 'nl_ice_data', 'source_version' => 'test', 'model_name' => 'Test',
        'model_window' => 'Test', 'source_league_name' => 'AHL', 'mapped_league_codes' => json_encode(['AHL']),
        'points_factor' => 0.45, 'win_shares_factor' => 0.45,
        'source_url' => 'https://example.test/nhle', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $players = collect(range(1, 18))->map(function (int $index) use ($rookie): array {
        $forward = $index <= 12;
        $positionIndex = $forward ? $index - 1 : $index - 13;

        return [
            'player_id' => $index === 1 ? $rookie : null,
            'nhl_player_id' => 8487000 + $index,
            'player_name' => $index === 1 ? 'Nhle Rookie' : "Replacement Rookie {$index}",
            'lineup_role' => $forward ? 'forward' : 'defense',
            'line_key' => ($forward ? 'F' : 'D') . ((int) floor($positionIndex / ($forward ? 3 : 2)) + 1),
            'slot_index' => ($positionIndex % ($forward ? 3 : 2)) + 1,
        ];
    })->all();

    $result = app(\App\Services\NhlGameLineupProjectionBuilder::class)->build(
        ['players' => $players],
        '20252026',
        '20262027',
        'skater-market',
        'toi-market'
    );

    expect($result)->toHaveCount(18)
        ->and(collect($result)->firstWhere('nhl_player_id', 8487001)['projection_source'])
        ->toBe('nhle_non_nhl_history')
        ->and(collect($result)->firstWhere('nhl_player_id', 8487002)['projection_source'])
        ->toBe('replacement_level');
});

it('predicts with an unresolved fourth-line player using resolved line-peer averages', function (): void {
    $token = ($this->seedPredictionInputs)(2.4, 3.2);
    DB::table('nhl_games')->where('nhl_game_id', 2026020001)->update(['game_type' => 1]);
    ($this->insertReportedLineup)('AWY', 1, 8481001, 'away-depth-average');
    ($this->insertReportedLineup)('HOM', 2, 8482001, 'home-depth-average');
    DB::table('nhl_lineup_observation_players')->where('team_abbrev', 'AWY')
        ->where('line_key', 'F4')->where('slot_index', 2)->update([
            'nhl_player_id' => null,
            'player_name' => 'Unresolved Fourth Liner',
            'resolution_status' => 'unresolved',
        ]);
    DB::table('nhl_lineup_observation_players')->where('team_abbrev', 'AWY')
        ->where('line_key', 'F4')->where('slot_index', 3)->update([
            'nhl_player_id' => null,
            'player_name' => 'Second Unresolved Fourth Liner',
            'resolution_status' => 'unresolved',
        ]);
    $simulator = \Mockery::mock(NhlProjectedTeamMatchupSimulator::class);
    $simulator->shouldReceive('simulateWithRosters')->once()->andReturn([
        'is_available' => true,
        'sides' => [
            ['offense_team' => 'AWY', 'defense_team' => 'HOM', 'summary' => [
                'total_goalie_adjusted_xgf_per_game' => 2.4, 'total_goalie_adjustment_per_game' => 0.0,
            ], 'roster' => []],
            ['offense_team' => 'HOM', 'defense_team' => 'AWY', 'summary' => [
                'total_goalie_adjusted_xgf_per_game' => 3.2, 'total_goalie_adjustment_per_game' => 0.0,
            ], 'roster' => []],
        ],
    ]);
    app()->instance(NhlProjectedTeamMatchupSimulator::class, $simulator);

    $response = $this->withHeader('Authorization', 'Bearer ' . $token)
        ->getJson('/api/nhl-game-predictions?' . http_build_query([
            'nhl_game_id' => 2026020001,
            'source_season_id' => '20252026', 'target_season_id' => '20262027',
            'projection_version' => 'skater-market', 'toi_projection_version' => 'toi-market',
            'goalie_projection_version' => 'goalie-market', 'away_goalie_id' => 9001, 'home_goalie_id' => 9002,
        ]))->assertOk()->assertJsonPath('prediction_available', true)->assertJsonCount(18, 'teams.away.roster');
    $roster = collect($response->json('teams.away.roster'));
    $unresolved = $roster->firstWhere('player_name', 'Unresolved Fourth Liner');
    $peers = $roster->where('line_key', 'F4')->where('projection_source', '!=', 'line_peer_average');

    expect($unresolved['nhl_player_id'])->toBeNull()
        ->and($unresolved['projection_source'])->toBe('line_peer_average')
        ->and($unresolved['projected_sog'])->toBe(round((float) $peers->avg('projected_sog'), 3))
        ->and($roster->where('projection_source', 'line_peer_average'))->toHaveCount(2);
});

it('still withholds a preseason prediction for an unresolved top-nine forward', function (): void {
    $token = ($this->seedPredictionInputs)();
    DB::table('nhl_games')->where('nhl_game_id', 2026020001)->update(['game_type' => 1]);
    ($this->insertReportedLineup)('AWY', 1, 8481001, 'away-core-unresolved');
    ($this->insertReportedLineup)('HOM', 2, 8482001, 'home-core-unresolved');
    DB::table('nhl_lineup_observation_players')->where('team_abbrev', 'AWY')
        ->where('line_key', 'F3')->where('slot_index', 1)->update([
            'nhl_player_id' => null, 'resolution_status' => 'unresolved',
        ]);

    $this->withHeader('Authorization', 'Bearer ' . $token)
        ->getJson('/api/nhl-game-predictions?' . http_build_query([
            'nhl_game_id' => 2026020001,
            'source_season_id' => '20252026', 'target_season_id' => '20262027',
            'projection_version' => 'skater-market', 'toi_projection_version' => 'toi-market',
            'goalie_projection_version' => 'goalie-market', 'away_goalie_id' => 9001, 'home_goalie_id' => 9002,
        ]))->assertOk()
        ->assertJsonPath('prediction_available', false)
        ->assertJsonPath('reason', 'preseason_lineup_unresolved');
});

it('requires at least one resolved peer in each unresolved depth group', function (): void {
    $players = collect(range(1, 18))->map(function (int $index): array {
        $forward = $index <= 12;
        $positionIndex = $forward ? $index - 1 : $index - 13;
        $lineKey = ($forward ? 'F' : 'D') . ((int) floor($positionIndex / ($forward ? 3 : 2)) + 1);

        return [
            'player_id' => null,
            'nhl_player_id' => $lineKey === 'D3' ? null : 8488000 + $index,
            'player_name' => "Depth Player {$index}",
            'lineup_role' => $forward ? 'forward' : 'defense',
            'line_key' => $lineKey,
            'slot_index' => ($positionIndex % ($forward ? 3 : 2)) + 1,
        ];
    })->all();

    expect(app(\App\Services\NhlGameLineupProjectionBuilder::class)->build(
        ['players' => $players], '20252026', '20262027', 'skater-market', 'toi-market'
    ))->toBeNull();
});

it('uses the resolved third-pair defenseman for an unresolved d3 projection', function (): void {
    $players = collect(range(1, 18))->map(function (int $index): array {
        $forward = $index <= 12;
        $positionIndex = $forward ? $index - 1 : $index - 13;
        $lineKey = ($forward ? 'F' : 'D') . ((int) floor($positionIndex / ($forward ? 3 : 2)) + 1);

        return [
            'player_id' => null,
            'nhl_player_id' => $index === 18 ? null : 8489000 + $index,
            'player_name' => $index === 18 ? 'Unresolved D3' : "Resolved Player {$index}",
            'lineup_role' => $forward ? 'forward' : 'defense',
            'line_key' => $lineKey,
            'slot_index' => ($positionIndex % ($forward ? 3 : 2)) + 1,
        ];
    })->all();

    $result = app(\App\Services\NhlGameLineupProjectionBuilder::class)->build(
        ['players' => $players], '20252026', '20262027', 'skater-market', 'toi-market'
    );
    $unresolved = collect($result)->firstWhere('player_name', 'Unresolved D3');

    expect($result)->toHaveCount(18)
        ->and($unresolved['projection_source'])->toBe('line_peer_average')
        ->and($unresolved['nhl_player_id'])->toBeNull();
});

it('uses complete reported or manual override lineups for both teams in a preseason prediction', function (bool $manualOverride): void {
    $token = ($this->seedPredictionInputs)(2.4, 3.2);
    DB::table('nhl_games')->where('nhl_game_id', 2026020001)->update(['game_type' => 1]);
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
    ($this->insertReportedLineup)('HOM', 2, 8482001, 'home');

    if ($manualOverride) {
        DB::table('nhl_lineup_observations')->where('id', $observationId)
            ->update(['raw_evidence' => json_encode(['manual_override' => true])]);
    }

    $simulator = \Mockery::mock(NhlProjectedTeamMatchupSimulator::class);
    $simulator->shouldReceive('simulateWithRosters')->once()
        ->withArgs(fn (...$arguments): bool => count($arguments[9]) === 18 && count($arguments[10]) === 18)
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
        ->assertJsonPath('prediction_available', true)
        ->assertJsonPath('inputs.away_lineup_source', 'anticipated_lineup')
        ->assertJsonPath('inputs.home_lineup_source', 'anticipated_lineup')
        ->assertJsonPath('anticipated_lineups.away.manual_override', $manualOverride)
        ->assertJsonCount(18, 'anticipated_lineups.away.players')
        ->assertJsonCount(18, 'anticipated_lineups.home.players')
        ->assertJsonCount(18, 'teams.away.roster')
        ->assertJsonPath('teams.away.roster.0.projection_source', 'replacement_level');
})->with([false, true]);
