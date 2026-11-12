<?php

declare(strict_types=1);

use App\Models\NhlModelRun;
use App\Models\NhlGame;
use App\Services\NhlCustomGameDefense;
use App\Services\NhlCustomGamePredictionEngine;
use App\Services\NhlCustomGamePredictionInputs;
use App\Services\NhlCustomGamePredictionReplay;
use App\Services\NhlPregameContextBuilder;
use App\Services\NhlSatModelEntityProfileBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-05 12:00:00 UTC'));
    Http::preventStrayRequests();
    Bus::fake();
    config(['cache.default' => 'array']);
    Cache::flush();
    config(['nhl-custom-prediction' => require base_path('config/nhl-custom-prediction.php')]);
    $this->engine = new NhlCustomGamePredictionEngine();
    $this->checkpointWrite = fn (string $key, array $work) =>
        (new ReflectionMethod(\App\Services\NhlCustomGameForecast::class, 'writePreparationCheckpoint'))
            ->invoke(new \App\Services\NhlCustomGameForecast(), $key, $work);
    $this->checkpointRead = fn (string $key) =>
        (new ReflectionMethod(\App\Services\NhlCustomGameForecast::class, 'readPreparationCheckpoint'))
            ->invoke(new \App\Services\NhlCustomGameForecast(), $key);

    $this->defenseFixture = function (): NhlCustomGameDefense {
        $records = [];
        foreach (['EV', 'PP'] as $lane) {
            foreach ([[1, 2, 20], [2, 1, 8]] as [$team, $opponent, $attempts]) {
                $records[] = ['valid' => true, 'team' => $team, 'opponent' => $opponent,
                    'clock' => [$lane => 600], 'counts' => [$lane => ['B' => ['sat' => $attempts]]]];
            }
        }
        $defense = new NhlCustomGameDefense();
        $defense->fit($records);

        return $defense;
    };
    $this->forecastFixture = function (string $state = 'FUT', bool $saved = false, bool $outsideRoster = false, ?\Closure $transformHistory = null): array {
        $model = NhlModelRun::query()->create(['run_key' => 'future-custom', 'name' => 'SAT_V2 fixture',
            'model_family' => 'sat', 'workflow_stage' => 'training', 'model_version' => 'fixture', 'status' => 'complete',
            'train_season_ids' => ['20232024', '20242025', '20252026'], 'target_season_id' => null]);
        $builder = Mockery::mock(NhlPregameContextBuilder::class);
        $builder->shouldReceive('evaluationBucketFilter')->once()->with($model->id, ['20232024', '20242025', '20252026'])
            ->andReturn(['keys' => ['B'], 'feature_config' => [], 'sat_model_id' => 4, 'minimum_confidence' => 0.5]);
        if ($saved) {
            $builder->shouldNotReceive('predictionTeamEvidence');
        } else {
            $builder->shouldReceive('predictionTeamEvidence')->twice()->andReturn(['metrics' => [], 'opponent_metrics' => [],
                'schedule' => ['back_to_back' => false, 'games_including_target_last_7_days' => 2]]);
        }
        app()->instance(NhlPregameContextBuilder::class, $builder);
        $bucketSql = Mockery::mock(NhlSatModelEntityProfileBuilder::class);
        $bucketSql->shouldReceive('modelBucketKeySql')->once()->andReturn("'B'");
        app()->instance(NhlSatModelEntityProfileBuilder::class, $bucketSql);
        $records = [];
        $lineup = ['players' => [], 'starters' => [], 'lineup_sources' => [1 => 'anticipated_lineup', 2 => 'anticipated_lineup']];
        foreach ([2023020001 => ['20232024', '2023-11-01'], 2024020001 => ['20242025', '2024-11-01'],
            2025020001 => ['20252026', '2025-11-01'], 2026020001 => ['20262027', '2026-11-01'],
            2026020002 => ['20262027', '2026-11-05']] as $id => [$season, $date]) {
            NhlGame::query()->create(['nhl_game_id' => $id, 'season_id' => $season, 'game_type' => 2,
                'game_date' => $date, 'game_dow' => 'Thursday', 'game_month' => 'November',
                'game_state' => $id === 2026020002 ? $state : 'FINAL', 'start_time_utc' => $date.' 23:00:00',
                'away_team_id' => 1, 'home_team_id' => 2, 'away_team_abbrev' => 'BUF', 'home_team_abbrev' => 'COL',
                'away_team_score' => 99, 'home_team_score' => 0,
                'home_pp_opportunities' => 3, 'away_pp_opportunities' => 3, 'home_pp_goals' => 1, 'away_pp_goals' => 1]);
            if ($id === 2026020002) {
                continue;
            }
            $record = ['source' => true, 'valid' => true, 'unmatched_shooter' => false, 'players' => [], 'counts' => [], 'clocks' => []];
            foreach ([1, 2] as $team) {
                $record['clocks'][$team] = ['EV' => 3000.0, 'PP' => 300.0, 'PK' => 300.0];
                foreach ([0, 1] as $slot) {
                    $player = $team * 100 + $slot;
                    $identity = ['id' => $player, 'team' => $team, 'name' => 'Fixture '.$player, 'role' => $slot === 0 ? 'F' : 'D'];
                    $lineup['players'][$player] = $identity;
                    $record['players'][$player] = [...$identity, 'unclassified' => false,
                        'seconds' => ['EV' => 1500.0, 'PP' => 150.0],
                        'counts' => ['EV' => ['B' => ['sat' => 20, 'sog' => 8, 'goals' => 1]],
                            'PP' => ['B' => ['sat' => 4, 'sog' => 2, 'goals' => 1]]]];
                }
                $record['counts'][$team] = ['EV' => ['B' => ['sat' => 40, 'sog' => 16, 'goals' => 2]],
                    'PP' => ['B' => ['sat' => 8, 'sog' => 4, 'goals' => 2]]];
            }
            if ($outsideRoster) {
                $record['players'][999] = [...$record['players'][100], 'id' => 999,
                    'seconds' => ['EV' => 900.0, 'PP' => 90.0],
                    'counts' => ['EV' => [], 'PP' => []]];
            }
            $records[$id] = $record;
        }
        if ($transformHistory !== null) {
            $records = $transformHistory($records);
        }
        $reader = new class($records) extends NhlCustomGamePredictionInputs
        {
            /** Supply prior fixtures only; a target read is deliberately an error. */
            public function __construct(private readonly array $fixtures)
            {
            }

            /** Exercise forecast history advancement without database-specific clock queries. */
            protected function records(array $games): array
            {
                $out = [];
                foreach ($games as $game) {
                    if (! isset($this->fixtures[$game->nhl_game_id])) {
                        throw new \RuntimeException('Prediction attempted to read target results.');
                    }
                    $out[$game->nhl_game_id] = $this->fixtures[$game->nhl_game_id];
                }

                return $out;
            }
        };

        if ($saved) {
            $run = \App\Models\NhlPregameContextRun::create(['model_run_id' => $model->id,
                'action' => 'backfill', 'status' => 'completed', 'season_ids' => ['20262027']]);
            foreach ([1 => 'away', 2 => 'home'] as $team => $venue) {
                $parent = DB::table('nhl_team_game_pregame_contexts')->insertGetId([
                    'model_run_id' => $model->id, 'run_id' => $run->id, 'nhl_game_id' => 2026020002,
                    'nhl_team_id' => $team, 'opponent_team_id' => $team === 1 ? 2 : 1,
                    'game_date' => '2026-11-05', 'source_cutoff_at' => '2026-11-05 12:00:00', 'venue' => $venue,
                    'context_version' => NhlPregameContextBuilder::VERSION, 'metrics' => '{}', 'opponent_metrics' => '{}',
                    'schedule_metrics' => json_encode(['back_to_back' => false, 'games_including_target_last_7_days' => 2]),
                ]);
                foreach ([0, 1] as $slot) {
                    $strength = [];
                    foreach (['EV' => [1500, 20, 8], 'PP' => [150, 4, 2]] as $lane => [$seconds, $sat, $sog]) {
                        foreach (['train' => 3, 'last_game' => 1, 'last_5' => 4, 'last_10' => 4,
                            'last_20' => 4, 'last_season' => 1, 'season_to_date' => 1] as $window => $games) {
                            $strength[$lane][$window] = ['games' => $games, 'toi_seconds' => $games * $seconds,
                                'sat' => $games * $sat, 'sog' => $games * $sog, 'goals' => $games,
                                'individual_missing_games' => 0];
                        }
                    }
                    DB::table('nhl_player_game_pregame_contexts')->insert([
                        'model_run_id' => $model->id, 'run_id' => $run->id, 'team_context_id' => $parent,
                        'nhl_game_id' => 2026020002, 'nhl_player_id' => $team * 100 + $slot, 'nhl_team_id' => $team,
                        'opponent_team_id' => $team === 1 ? 2 : 1, 'game_date' => '2026-11-05',
                        'source_cutoff_at' => '2026-11-05 12:00:00', 'venue' => $venue,
                        'participant_source' => 'anticipated_lineup', 'context_version' => NhlPregameContextBuilder::VERSION,
                        'metrics' => json_encode(['individual_strength_version' => 1, 'strength' => $strength]),
                    ]);
                }
            }
        }
        return [$reader, $model->id, [2026020002 => $lineup]];
    };
    $this->testModel = null;
    $this->finishQueuedForecast = function (NhlCustomGamePredictionInputs $reader, int $model, array $lineups): array {
        $reader->beginQueuedForecast($model, '2026-11-05', $lineups);
        for ($page = 0; $page < 30; $page++) {
            $step = $reader->advanceQueuedForecast();
            if ($step['complete']) {
                return $step;
            }
        }

        throw new \RuntimeException('Fixture preparation did not reach its bounded terminal step.');
    };
    $this->bucketReliabilityHistory = fn (int $sat, int $sog, int $goals): \Closure => function (array $records) use ($sat, $sog, $goals): array {
        foreach ($records as $gameId => &$record) {
            if ($gameId >= 2026020000) {
                continue;
            }
            $record['players'][100]['counts']['EV']['B'] = $gameId === 2023020001
                ? compact('sat', 'sog', 'goals') : ['sat' => 0, 'sog' => 0, 'goals' => 0];
            foreach (['sat', 'sog', 'goals'] as $metric) {
                $record['counts'][1]['EV']['B'][$metric]
                    = $record['players'][100]['counts']['EV']['B'][$metric]
                        + $record['players'][101]['counts']['EV']['B'][$metric];
            }
        }
        unset($record);

        return $records;
    };
    $this->input = ['game_id' => 2025020001, 'source_cutoff_at' => '2025-11-01 23:00:00',
        'retained_bucket_keys' => ['B'], 'league_usage_seconds' => ['EV' => 600.0, 'PP' => 60.0],
        'league_pk_probability' => 0.8, 'teams' => []];
    foreach (['away' => 1, 'home' => 2] as $venue => $team) {
        $side = ['id' => $team, 'abbrev' => $venue === 'away' ? 'BUF' : 'COL',
            'context_id' => null, 'team_metrics' => [], 'starting_goalie_id' => null,
            'lineup_source' => 'historical_participants', 'train_opportunity_share' => 0.5,
            'pk_opportunities_l10' => 3.0, 'pp_offense_l20' => 0.8, 'pp_allowed_l5' => 0.6,
            'pk_probability' => 0.8, 'schedule' => ['back_to_back' => false, 'games_including_target_last_7_days' => 3],
            'players' => []];
        foreach (['F', 'D'] as $index => $role) {
            $id = $team * 100 + $index;
            $side['players'][$id] = ['name' => 'Fixture '.$id, 'role' => $role, 'context_id' => null,
                'usage' => ['EV' => 600.0, 'PP' => 60.0], 'complete' => ['EV' => true, 'PP' => true],
                'buckets' => [], 'history' => []];
            foreach (['EV' => ($venue === 'home' ? 14.0 : 12.0), 'PP' => 24.0] as $lane => $rate) {
                $side['players'][$id]['buckets'][$lane]['B'] = ['sat_per_60' => $rate,
                    'defense_allowance' => 1.0, 'defense_reference' => 1.0,
                    'sog_probability' => 0.5, 'goal_probability' => $lane === 'EV' ? 0.1 : 0.15];
            }
        }
        $this->input['teams'][$venue] = $side;
    }
    $this->event = fn (array $input): array => ['input' => $input, 'error' => null,
        'game' => (object) ['nhl_game_id' => $input['game_id'], 'game_date' => '2025-11-01',
            'start_time_utc' => $input['source_cutoff_at'], 'home_team_id' => 2, 'away_team_id' => 1,
            'home_team_score' => 3, 'away_team_score' => 2, 'home_pp_opportunities' => 3, 'away_pp_opportunities' => 3],
        'actual' => [1 => ['EV' => ['goals' => 2, 'sat' => 40, 'sog' => 20], 'PP' => ['goals' => 0, 'sat' => 8, 'sog' => 4]],
            2 => ['EV' => ['goals' => 2, 'sat' => 45, 'sog' => 22], 'PP' => ['goals' => 1, 'sat' => 10, 'sog' => 5]]],
        'player_actual' => []];
    $this->source = function (?array $events = null): array {
        $this->testModel ??= NhlModelRun::query()->create(['run_key' => 'custom-fixture', 'name' => 'Custom fixture',
            'model_family' => 'sat', 'workflow_stage' => 'training', 'model_version' => 'fixture', 'status' => 'complete',
            'train_season_ids' => ['20222023', '20232024', '20242025'], 'target_season_id' => '20252026']);
        $events ??= [($this->event)($this->input)];
        $metadata = ['model_id' => $this->testModel->id, 'model_name' => 'Custom fixture',
            'test_season' => '20252026', 'selected_games' => count($events), 'train_seasons' => ['20222023', '20232024', '20242025']];
        $source = Mockery::mock(NhlCustomGamePredictionInputs::class);
        $source->shouldReceive('prepare')->once()->with($this->testModel->id)->andReturn($metadata);
        $source->shouldReceive('metadata')->andReturn($metadata);
        $source->shouldReceive('games')->once()->with(null, null)->andReturnUsing(function () use ($events): \Generator {
            foreach ($events as $event) {
                yield $event;
            }
        });

        return [new NhlCustomGamePredictionReplay($this->engine, $source), $this->testModel->id];
    };
});

afterEach(function (): void {
    $this->travelBack();
});

describe('lineup independent context reuse', function (): void {
    beforeEach(function (): void {
        [$reader, $modelId, $lineups] = ($this->forecastFixture)('FUT', true);
        $reader->prepare($modelId, '20262027');
        $this->contextModel = NhlModelRun::findOrFail($modelId);
        $this->contextGame = DB::table('nhl_games')->where('nhl_game_id', 2026020002)->first();
        $this->contextLineup = $lineups[2026020002];
        $this->contextForecast = app(\App\Services\NhlCustomGameForecast::class);
        $this->contextEvidence = $this->contextForecast->contextEvidence($this->contextModel, $this->contextGame, $this->contextLineup);
        $runId = DB::table('nhl_team_game_pregame_contexts')->where('model_run_id', $modelId)->value('run_id');
        $this->contextRun = \App\Models\NhlPregameContextRun::findOrFail($runId);
        $this->contextRun->update(['options' => ['train_season_ids' => $this->contextModel->train_season_ids,
            'prediction_evidence' => [2026020002 => $this->contextEvidence],
            'prediction_participants' => [2026020002 => $this->contextLineup]]]);
        DB::table('nhl_team_game_pregame_contexts')->where('model_run_id', $modelId)->update([
            'schedule_metrics' => json_encode(['prediction_evidence' => array_intersect_key($this->contextEvidence,
                array_flip(['fingerprint', 'history_fingerprint', 'cutoff_at']))]),
        ]);
        DB::table('nhl_pregame_context_run_games')->insert([
            'run_id' => $runId, 'nhl_game_id' => 2026020002, 'game_date' => '2026-11-05', 'sequence' => 1,
            'state' => 'complete', 'comparisons_completed_at' => now(),
        ]);
    });

    it('reuses committed history when only line assignments change', function (): void {
        $record = $this->contextLineup;
        $record['players'][100]['line_key'] = 'F2';
        $evidence = $this->contextForecast->contextEvidence($this->contextModel, $this->contextGame, $record);
        expect($evidence['fingerprint'])->not->toBe($this->contextEvidence['fingerprint'])
            ->and($evidence['history_fingerprint'])->toBe($this->contextEvidence['history_fingerprint'])
            ->and($this->contextForecast->contextReady($this->contextModel->id, 2026020002, $evidence))->toBeTrue();
    });

    it('retains the original cutoff when unchanged history is requested later', function (): void {
        $this->travel(30)->minutes();
        $evidence = $this->contextForecast->contextEvidence($this->contextModel, $this->contextGame, $this->contextLineup);
        expect($evidence['cutoff_at'])->toBe('2026-11-05 12:00:00')
            ->and($this->contextForecast->contextReady($this->contextModel->id, 2026020002, $evidence))->toBeTrue();
    });

    it('accepts a smaller selected roster without deleting unused player history', function (): void {
        $record = $this->contextLineup;
        unset($record['players'][100]);
        $evidence = $this->contextForecast->contextEvidence($this->contextModel, $this->contextGame, $record);
        expect($this->contextForecast->contextReady($this->contextModel->id, 2026020002, $evidence))->toBeTrue();
        $this->assertDatabaseHas('nhl_player_game_pregame_contexts', ['nhl_game_id' => 2026020002, 'nhl_player_id' => 100]);
    });

    it('requires missing starter context while retaining the existing teams', function (): void {
        $record = $this->contextLineup;
        $record['starters'][1] = 999;
        $evidence = $this->contextForecast->contextEvidence($this->contextModel, $this->contextGame, $record);
        expect($this->contextForecast->reusableContextTeams($this->contextModel->id, 2026020002, $evidence))->toHaveCount(2)
            ->and($this->contextForecast->contextReady($this->contextModel->id, 2026020002, $evidence))->toBeFalse();
    });

    it('rejects context reuse when historical result values change', function (): void {
        DB::table('nhl_games')->where('nhl_game_id', 2025020001)->update(['home_team_score' => 98]);
        $evidence = $this->contextForecast->contextEvidence($this->contextModel, $this->contextGame, $this->contextLineup);
        expect($evidence['history_fingerprint'])->not->toBe($this->contextEvidence['history_fingerprint'])
            ->and($this->contextForecast->contextReady($this->contextModel->id, 2026020002, $evidence))->toBeFalse();
    });

    it('verifies legacy participant hashes against unchanged source data before reuse', function (): void {
        DB::table('nhl_team_game_pregame_contexts')->where('model_run_id', $this->contextModel->id)->update([
            'schedule_metrics' => json_encode(['prediction_evidence' => ['fingerprint' => $this->contextEvidence['fingerprint']]]),
        ]);
        $record = $this->contextLineup;
        $record['players'][100]['line_key'] = 'F4';
        $evidence = $this->contextForecast->contextEvidence($this->contextModel, $this->contextGame, $record);
        expect($this->contextForecast->contextReady($this->contextModel->id, 2026020002, $evidence))->toBeTrue();
    });

    it('rejects uncommitted comparisons as reusable context', function (): void {
        DB::table('nhl_pregame_context_run_games')->where('run_id', $this->contextRun->id)->update(['comparisons_completed_at' => null]);
        expect($this->contextForecast->contextReady($this->contextModel->id, 2026020002, $this->contextEvidence))->toBeFalse();
    });

    it('adds only missing participants and leaves existing context rows untouched', function (): void {
        $beforeTeams = DB::table('nhl_team_game_pregame_contexts')->where('model_run_id', $this->contextModel->id)->orderBy('id')->get()->toJson();
        $beforePlayers = DB::table('nhl_player_game_pregame_contexts')->where('model_run_id', $this->contextModel->id)->orderBy('id')->get()->toJson();
        $record = $this->contextLineup;
        $record['players'][999] = ['id' => 999, 'team' => 1, 'name' => 'New Player', 'role' => 'F'];
        unset($record['players'][100]);
        $evidence = $this->contextForecast->contextEvidence($this->contextModel, $this->contextGame, $record);
        $run = \App\Models\NhlPregameContextRun::create(['model_run_id' => $this->contextModel->id,
            'action' => 'backfill', 'status' => 'running', 'season_ids' => ['20262027'],
            'options' => ['train_season_ids' => $this->contextModel->train_season_ids,
                'prediction_evidence' => [2026020002 => $evidence], 'prediction_participants' => [2026020002 => $record]]]);
        (new NhlPregameContextBuilder())->buildDay($run, [2026020002]);
        expect(DB::table('nhl_team_game_pregame_contexts')->where('model_run_id', $this->contextModel->id)->orderBy('id')->get()->toJson())->toBe($beforeTeams)
            ->and(DB::table('nhl_player_game_pregame_contexts')->where('model_run_id', $this->contextModel->id)->where('nhl_player_id', '<>', 999)->orderBy('id')->get()->toJson())->toBe($beforePlayers)
            ->and($this->contextForecast->contextReady($this->contextModel->id, 2026020002, $evidence))->toBeFalse();
        $parent = DB::table('nhl_team_game_pregame_contexts')->where('model_run_id', $this->contextModel->id)->where('nhl_team_id', 1)->value('id');
        $this->assertDatabaseHas('nhl_player_game_pregame_contexts', ['run_id' => $run->id, 'nhl_player_id' => 999, 'team_context_id' => $parent]);
        DB::table('nhl_pregame_context_run_games')->insert([
            'run_id' => $run->id, 'nhl_game_id' => 2026020002, 'game_date' => '2026-11-05', 'sequence' => 1,
            'state' => 'complete', 'comparisons_completed_at' => now(),
        ]);
        expect($this->contextForecast->contextReady($this->contextModel->id, 2026020002, $evidence))->toBeTrue();
    });
});

it('allocates five skaters worth of each predicted strength clock', function (): void {
    $result = $this->engine->predict($this->input);
    foreach ($result['teams'] as $team) {
        foreach (['EV', 'PP'] as $lane) {
            $seconds = array_sum(array_map(fn (array $p): float => $p['strengths'][$lane]['seconds'], $team['players']));
            expect($seconds)->toEqualWithDelta(5 * $team['time'][$lane], 1.0e-9);
        }
    }
});

it('derives PK from opposing PP and leaves sixty minutes per team', function (): void {
    $this->input['teams']['away']['pk_opportunities_l10'] = 4.0;
    $result = $this->engine->predict($this->input);
    expect($result['teams']['away']['time']['PK'])->toBe($result['teams']['home']['time']['PP'])
        ->and(array_sum($result['teams']['away']['time']))->toEqualWithDelta(3600.0, 1.0e-9);
});

it('uses the opponents allowed opportunities rather than its attacking opportunities', function (): void {
    $this->input['teams']['home']['pk_opportunities_l10'] = 4.0;
    $result = $this->engine->predict($this->input);
    expect($result['teams']['away']['pp_opportunities'])->toEqualWithDelta(0.25 * 2.95 + 0.75 * 4, 1.0e-10)
        ->and($result['teams']['home']['pp_opportunities'])->toEqualWithDelta(0.25 * 2.95 + 0.75 * 3, 1.0e-10);
});

it('retains the allocated opportunity baseline when opponent L10 is unavailable', function (): void {
    $this->input['teams']['home']['pk_opportunities_l10'] = null;
    expect($this->engine->predict($this->input)['teams']['away']['pp_opportunities'])->toBe(2.95);
});

it('normalizes Train opportunity shares without renormalizing the adjusted opportunities', function (): void {
    $this->input['teams']['away']['train_opportunity_share'] = 0.6;
    $this->input['teams']['home']['train_opportunity_share'] = 0.4;
    $result = $this->engine->predict($this->input);
    expect($result['teams']['away']['pp_opportunities'])->toEqualWithDelta(0.25 * 5.9 * 0.6 + 0.75 * 3, 1.0e-10)
        ->and($result['teams']['away']['pp_opportunities'] + $result['teams']['home']['pp_opportunities'])->not->toBe(5.9);
});

it('rejects a missing Train opportunity share instead of inventing one', function (): void {
    $this->input['teams']['away']['train_opportunity_share'] = null;
    expect(fn () => $this->engine->predict($this->input))->toThrow(\DomainException::class);
});

it('retains zero player usage while allocating the full lineup budget', function (): void {
    $this->input['teams']['away']['players'][100]['usage']['EV'] = 0.0;
    $team = $this->engine->predict($this->input)['teams']['away'];
    expect($team['players'][100]['strengths']['EV']['seconds'])->toBe(0.0)
        ->and($team['players'][101]['strengths']['EV']['seconds'])->toEqualWithDelta(5 * $team['time']['EV'], 1.0e-9);
});

it('fills unavailable usage from Train league without dropping the player', function (): void {
    $this->input['teams']['away']['players'][100]['usage']['EV'] = null;
    $team = $this->engine->predict($this->input)['teams']['away'];
    expect($team['players'])->toHaveCount(2)
        ->and($team['players'][100]['strengths']['EV']['share'])->toBe(0.5);
});

it('predicts below ninety percent observed coverage using completed fallback inputs', function (): void {
    $this->input['teams']['away']['players'][100]['complete']['EV'] = false;
    $prediction = $this->engine->predict($this->input);
    expect($prediction['total_goals'])->toBeGreaterThan(0)
        ->and($prediction['confidence_inputs']['input_completeness']['EV'])->toBe(0.75)
        ->and($prediction['confidence_inputs']['effective_input_completeness']['EV'])->toBe(1.0)
        ->and(config('nhl-custom-prediction.minimum_input_completeness'))->toBe(1.0);
});

it('accepts exactly ninety percent complete inputs', function (): void {
    foreach (['away' => 1000, 'home' => 2000] as $venue => $start) {
        $template = array_values($this->input['teams'][$venue]['players'])[0];
        $this->input['teams'][$venue]['players'] = [];
        for ($i = 0; $i < 10; $i++) {
            $this->input['teams'][$venue]['players'][$start + $i] = $template;
        }
    }
    $this->input['teams']['away']['players'][1000]['complete']['EV'] = false;
    $this->input['teams']['away']['players'][1001]['complete']['EV'] = false;
    expect($this->engine->predict($this->input)['confidence_inputs']['input_completeness']['EV'])->toBe(0.9);
});

it('rejects buckets outside the frozen reliable Train set', function (): void {
    $this->input['teams']['away']['players'][100]['buckets']['EV']['unretained']
        = $this->input['teams']['away']['players'][100]['buckets']['EV']['B'];
    expect(fn () => $this->engine->predict($this->input))->toThrow(\DomainException::class, 'outside the frozen');
});

it('keeps a supported zero goal probability instead of replacing it with league scoring', function (): void {
    foreach ($this->input['teams']['away']['players'] as &$player) {
        $player['buckets']['EV']['B']['goal_probability'] = 0.0;
    }
    unset($player);
    expect($this->engine->predict($this->input)['teams']['away']['strengths']['EV']['goals'])->toBe(0.0);
});

it('rejects impossible bucket probabilities', function (): void {
    $this->input['teams']['away']['players'][100]['buckets']['EV']['sog_probability'] = 1.1;
    expect(fn () => $this->engine->predict($this->input))->toThrow(\DomainException::class, 'Probability exceeds');
});

it('keeps equal upcoming and historical defensive allowances neutral', function (): void {
    $this->input['teams']['away']['players'][100]['buckets']['EV']['B']['defense_allowance'] = 2.0;
    $this->input['teams']['away']['players'][100]['buckets']['EV']['B']['defense_reference'] = 2.0;
    $bucket = $this->engine->predict($this->input)['teams']['away']['players'][100]['strengths']['EV']['buckets']['B'];
    expect($bucket['defense_multiplier'])->toBe(1.0);
});

it('lets a stronger opposing defense reduce volume without changing conversion ratios', function (): void {
    $baseline = $this->engine->predict($this->input);
    $this->input['teams']['away']['players'][100]['buckets']['EV']['B']['defense_allowance'] = 0.5;
    $bucket = $this->engine->predict($this->input)['teams']['away']['players'][100]['strengths']['EV']['buckets']['B'];
    expect($bucket['sat'])->toBeLessThan($baseline['teams']['away']['players'][100]['strengths']['EV']['sat'])
        ->and($bucket['sog'] / $bucket['sat'])->toBe(0.5)
        ->and($bucket['goals'] / $bucket['sog'])->toEqualWithDelta(0.1, 1.0e-12);
});

it('rejects an unavailable historical defensive denominator', function (): void {
    $this->input['teams']['away']['players'][100]['buckets']['EV']['B']['defense_reference'] = null;
    expect(fn () => $this->engine->predict($this->input))->toThrow(\DomainException::class);
});

it('reduces qualifying forwards bucket SAT by two percent', function (): void {
    $baseline = $this->engine->predict($this->input);
    $this->input['teams']['away']['schedule'] = ['back_to_back' => true, 'games_including_target_last_7_days' => 4];
    $adjusted = $this->engine->predict($this->input);
    expect($adjusted['teams']['away']['players'][100]['strengths']['EV']['sat'])
        ->toEqualWithDelta($baseline['teams']['away']['players'][100]['strengths']['EV']['sat'] * 0.98, 1.0e-10);
});

it('reduces qualifying defensemen bucket SAT by three percent', function (): void {
    $baseline = $this->engine->predict($this->input);
    $this->input['teams']['away']['schedule'] = ['back_to_back' => true, 'games_including_target_last_7_days' => 4];
    $adjusted = $this->engine->predict($this->input);
    expect($adjusted['teams']['away']['players'][101]['strengths']['EV']['sat'])
        ->toEqualWithDelta($baseline['teams']['away']['players'][101]['strengths']['EV']['sat'] * 0.97, 1.0e-10);
});

it('leaves a third-in-seven back to back unadjusted', function (): void {
    $this->input['teams']['away']['schedule'] = ['back_to_back' => true, 'games_including_target_last_7_days' => 3];
    expect($this->engine->predict($this->input)['teams']['away']['schedule_adjustment_applied'])->toBeFalse();
});

it('leaves a fourth-in-seven without a back to back unadjusted', function (): void {
    $this->input['teams']['away']['schedule'] = ['back_to_back' => false, 'games_including_target_last_7_days' => 4];
    expect($this->engine->predict($this->input)['teams']['away']['schedule_adjustment_applied'])->toBeFalse();
});

it('does not extend the exact-fourth rule to a fifth game in seven', function (): void {
    expect($this->engine->fourthGameBackToBack(['back_to_back' => true, 'games_including_target_last_7_days' => 5]))->toBeFalse();
});

it('does not reduce the other teams bucket production', function (): void {
    $baseline = $this->engine->predict($this->input);
    $this->input['teams']['away']['schedule'] = ['back_to_back' => true, 'games_including_target_last_7_days' => 4];
    $adjusted = $this->engine->predict($this->input);
    expect($adjusted['teams']['home']['sat'])->toBe($baseline['teams']['home']['sat']);
});

it('disables both schedule stages for the unchanged comparison', function (): void {
    $baseline = $this->engine->predict($this->input);
    $this->input['teams']['away']['schedule'] = ['back_to_back' => true, 'games_including_target_last_7_days' => 4];
    $adjusted = $this->engine->predict($this->input, false);
    expect($adjusted['teams']['away']['sat'])->toBe($baseline['teams']['away']['sat'])
        ->and($adjusted['home_probability_schedule_delta'])->toBe(0.0);
});

it('adds the approved probability delta only for a qualifying away team', function (): void {
    $this->input['teams']['away']['schedule'] = ['back_to_back' => true, 'games_including_target_last_7_days' => 4];
    $result = $this->engine->predict($this->input);
    expect($result['home_win_probability'] - $result['base_home_win_probability'])->toEqualWithDelta(0.0433712599, 1.0e-12)
        ->and($result['home_win_probability'] + $result['away_win_probability'])->toBe(1.0);
});

it('does not add a win probability bonus for a qualifying home team', function (): void {
    $this->input['teams']['home']['schedule'] = ['back_to_back' => true, 'games_including_target_last_7_days' => 4];
    expect($this->engine->predict($this->input)['home_probability_schedule_delta'])->toBe(0.0);
});

it('preserves the separately predicted seventy five twenty five PP game total', function (): void {
    $result = $this->engine->predict($this->input);
    expect($result['teams']['away']['strengths']['PP']['goals'] + $result['teams']['home']['strengths']['PP']['goals'])
        ->toEqualWithDelta(0.75 * 1.6 + 0.25 * 1.2, 1.0e-12);
});

it('uses the good opponents PK defense in the initial PP split', function (): void {
    $this->input['teams']['home']['pk_probability'] = 0.9;
    $this->input['teams']['home']['pp_allowed_l5'] = 0.2;
    $result = $this->engine->predict($this->input);
    expect($result['teams']['away']['pp_allocation']['initial_goals'])->toBe(0.2)
        ->and($result['teams']['home']['pp_allocation']['initial_goals'])->toBe(0.8)
        ->and($result['teams']['away']['strengths']['PP']['goals'] / $result['teams']['home']['strengths']['PP']['goals'])
        ->toEqualWithDelta(0.25, 1.0e-12);
});

it('splits the PP game total evenly when both initial splits are zero', function (): void {
    foreach (['away', 'home'] as $venue) {
        $this->input['teams'][$venue]['pk_probability'] = 0.9;
        $this->input['teams'][$venue]['pp_allowed_l5'] = 0.0;
    }
    $result = $this->engine->predict($this->input);
    expect($result['teams']['away']['strengths']['PP']['goals'])->toEqualWithDelta(0.6, 1.0e-12)
        ->and($result['teams']['home']['strengths']['PP']['goals'])->toEqualWithDelta(0.6, 1.0e-12);
});

it('replaces rather than adds the final PP allocation', function (): void {
    $team = $this->engine->predict($this->input)['teams']['away'];
    expect($team['goals'])->toBe($team['strengths']['EV']['goals'] + $team['pp_allocation']['final_goals']);
});

it('keeps final player PP goals summing to the final team allocation', function (): void {
    $team = $this->engine->predict($this->input)['teams']['away'];
    $players = array_sum(array_map(fn (array $p): float => $p['strengths']['PP']['goals'], $team['players']));
    expect($players)->toEqualWithDelta($team['strengths']['PP']['goals'], 1.0e-12);
});

it('predicts no short handed scoring lane while retaining PK clock time', function (): void {
    $team = $this->engine->predict($this->input)['teams']['away'];
    expect(array_keys($team['strengths']))->toBe(['EV', 'PP'])->and($team['time']['PK'])->toBeGreaterThan(0);
});

it('does not change goals when starting goalie identity changes in neutral mode', function (): void {
    $baseline = $this->engine->predict($this->input);
    $this->input['teams']['home']['starting_goalie_id'] = 999999;
    expect($this->engine->predict($this->input)['total_goals'])->toBe($baseline['total_goals']);
});

it('keeps win probability separate from numeric internal input confidence', function (): void {
    $result = $this->engine->predict($this->input);
    expect($result['home_win_probability'])->toBeGreaterThan(0.5)->and($result['confidence_score'])->toBe(50);
});

it('reuses the unchanged internal confidence formula with actual skater and goalie inputs', function (): void {
    $baseline = $this->engine->predict($this->input);
    foreach (['away', 'home'] as $venue) {
        foreach ($this->input['teams'][$venue]['players'] as &$player) {
            $player['confidence_score'] = 0.8;
        }
        unset($player);
        $this->input['teams'][$venue]['goalie_confidence_buckets'] = ['B' => ['confidence_score' => 0.6, 'source_sat' => 100]];
    }
    $result = $this->engine->predict($this->input);
    expect($result['confidence_score'])->toBe(74)
        ->and($result['teams']['away']['goalie_input_confidence_score'])->toBe(60.0)
        ->and($result['teams']['home']['input_confidence_score'])->toBe(74.0)
        ->and($result['total_goals'])->toBe($baseline['total_goals'])
        ->and($result['home_win_probability'])->toBe($baseline['home_win_probability'])
        ->and($result['recommendation'])->toBe($baseline['recommendation']);
});

it('preserves zero evidence without confusing filled inputs with confidence', function (): void {
    foreach (['away', 'home'] as $venue) {
        foreach ($this->input['teams'][$venue]['players'] as &$player) {
            $player['confidence_score'] = 0.0;
        }
        unset($player);
        $this->input['teams'][$venue]['goalie_confidence_buckets'] = [];
    }
    $result = $this->engine->predict($this->input);
    expect($result['confidence_score'])->toBe(1)
        ->and($result['confidence_inputs']['effective_input_completeness']['EV'])->toBe(1.0)
        ->and($result['teams']['away']['goalie_input_confidence_score'])->toBe(0.0);
});

it('weights goalie confidence by the opponents EV bucket attempts including missing buckets', function (): void {
    foreach (['away', 'home'] as $venue) {
        foreach ($this->input['teams'][$venue]['players'] as &$player) {
            $player['buckets']['EV']['C'] = $player['buckets']['EV']['B'];
        }
        unset($player);
        $this->input['teams'][$venue]['goalie_confidence_buckets'] = ['B' => ['confidence_score' => 0.8]];
    }
    $this->input['retained_bucket_keys'][] = 'C';
    $result = $this->engine->predict($this->input);
    expect($result['teams']['away']['goalie_input_confidence_score'])->toBe(40.0)
        ->and($result['teams']['home']['goalie_input_confidence_score'])->toBe(40.0);
});

it('retains the existing ninety nine percent goal contribution weighting', function (): void {
    foreach (['away', 'home'] as $venue) {
        foreach ($this->input['teams'][$venue]['players'] as &$player) {
            $player['confidence_score'] = 0.8;
        }
        unset($player);
    }
    $base = $this->engine->predict($this->input);
    $tiny = $this->input['teams']['away']['players'][100];
    $tiny['name'] = 'Tiny contribution';
    $tiny['usage'] = ['EV' => 6.0, 'PP' => 6.0];
    $tiny['confidence_score'] = 0.0;
    $this->input['teams']['away']['players'][102] = $tiny;
    $result = $this->engine->predict($this->input);
    expect($result['confidence_score'])->toBe($base['confidence_score'])
        ->and($result['teams']['away']['input_confidence_score'])->toBe($base['teams']['away']['input_confidence_score'])
        ->and($result['teams']['away']['players'][102]['strengths']['EV']['goals'])->toBeGreaterThan(0);
});

it('splits tied regulation outcomes evenly with no invented home advantage', function (): void {
    $probabilities = $this->engine->winProbabilities(3.0, 3.0);
    expect($probabilities['home'])->toEqualWithDelta(0.5, 1.0e-12)
        ->and($probabilities['tie'])->toBeGreaterThan(0);
});

it('handles a zero mean without NaN or division by zero', function (): void {
    $probabilities = $this->engine->winProbabilities(0.0, 0.0);
    expect($probabilities)->toBe(['home' => 0.5, 'away' => 0.5, 'tie' => 1.0]);
});

it('reports pooled error rather than averaging individual errors', function (): void {
    $result = $this->engine->accuracy([[1.0, 2.0], [9.0, 8.0]]);
    expect($result['err_pct'])->toBe(0.0)->and($result['p10'])->toBe(0.0)->and($result['p20'])->toBe(50.0);
});

it('uses strict prediction denominator p10 and p20 boundaries', function (): void {
    $result = $this->engine->accuracy([[10.0, 9.0], [10.0, 8.0], [10.0, 10.0]]);
    expect($result['p10_count'])->toBe(1)->and($result['p20_count'])->toBe(2);
});

it('counts zero prediction positive actual as a miss and both zero separately', function (): void {
    $result = $this->engine->accuracy([[0.0, 0.0], [0.0, 1.0], [1.0, 1.0]]);
    expect($result['count'])->toBe(2)->and($result['both_zero'])->toBe(1)->and($result['p20'])->toBe(50.0);
});

it('does not mutate inputs or leak schedule changes into another prediction', function (): void {
    $original = $this->input;
    $this->engine->predict($this->input);
    expect($this->input)->toBe($original)
        ->and($this->engine->predict($this->input))->toBe($this->engine->predict($original));
});

it('runs the custom replay read only unless save is explicitly requested', function (): void {
    [$replay, $model] = ($this->source)();
    $report = $replay->run($model);
    expect($report['summary']['with_schedule']['predicted_games'])->toBe(1)
        ->and(DB::table('nhl_custom_game_prediction_runs')->count())->toBe(0);
});

it('saves both team snapshots and linked player inputs in one isolated run', function (): void {
    [$replay, $model] = ($this->source)();
    $report = $replay->run($model, save: true);
    $run = DB::table('nhl_custom_game_prediction_runs')->find($report['summary']['with_schedule']['run_id']);
    $teams = DB::table('nhl_custom_game_prediction_teams')->orderBy('id')->get();
    expect($run->status)->toBe('complete')->and($run->completed_games)->toBe(1)
        ->and($teams)->toHaveCount(2)->and(DB::table('nhl_custom_game_prediction_player_inputs')->count())->toBe(4);
    foreach (DB::table('nhl_custom_game_prediction_player_inputs')->get() as $player) {
        expect($player->team_prediction_id)->not->toBe($player->opponent_team_prediction_id)
            ->and(json_decode($player->prediction, true)['strengths'])->toHaveKeys(['EV', 'PP']);
    }
    expect($teams[0]->pk_goals_prediction)->toBeNull();
});

it('creates a new run for a repeated game without replacing the prior forecast', function (): void {
    [$first, $model] = ($this->source)();
    $first->run($model, save: true);
    $previous = DB::table('nhl_custom_game_predictions')->first();
    [$second] = ($this->source)();
    $second->run($model, save: true);
    expect(DB::table('nhl_custom_game_prediction_runs')->count())->toBe(2)
        ->and(DB::table('nhl_custom_game_predictions')->count())->toBe(2)
        ->and(DB::table('nhl_custom_game_predictions')->find($previous->id))->toEqual($previous);
});

it('compares schedule variants on identical games and actual goal totals', function (): void {
    [$replay, $model] = ($this->source)();
    $report = $replay->run($model, compareSchedule: true);
    expect($report['summary']['with_schedule']['predicted_games'])->toBe(1)
        ->and($report['summary']['without_schedule']['predicted_games'])->toBe(1)
        ->and($report['summary']['with_schedule']['game_goals']['actual_sum'])
        ->toBe($report['summary']['without_schedule']['game_goals']['actual_sum']);
});

it('excludes a missing input from both schedule variants instead of inventing goals', function (): void {
    $event = ($this->event)($this->input);
    $event['input'] = null;
    $event['error'] = 'Missing model-owned snapshot';
    [$replay, $model] = ($this->source)([$event]);
    $report = $replay->run($model, save: true, compareSchedule: true);
    expect($report['excluded_games'])->toBe(1)
        ->and($report['summary']['with_schedule']['predicted_games'])->toBe(0)
        ->and(DB::table('nhl_custom_game_predictions')->where('status', 'excluded')->count())->toBe(2);
});

it('scores an official OT or shootout winner without treating the game as a loss for the winning pick', function (): void {
    $event = ($this->event)($this->input);
    $event['game']->home_team_score = 4;
    $event['game']->away_team_score = 3;
    [$replay, $model] = ($this->source)([$event]);
    expect($replay->run($model)['summary']['with_schedule']['win_pct'])->toBe(100.0);
});

it('keeps official final goals separate from retained regulation goal error targets', function (): void {
    $event = ($this->event)($this->input);
    $event['game']->home_team_score = 7;
    [$replay, $model] = ($this->source)([$event]);
    $report = $replay->run($model);
    expect($report['summary']['with_schedule']['game_goals']['actual_sum'])->toBe(5.0)
        ->and($report['games']['with_schedule'][0]['home_actual_final'])->toBe(7);
});

it('rejects an invalid model option before invoking the replay', function (): void {
    $this->artisan('nhl:custom-predict', ['--model' => 'wrong'])->assertFailed();
});

it('rejects relative dates rather than silently changing the selected season scope', function (): void {
    $this->artisan('nhl:custom-predict', ['--model' => 1, '--from' => 'yesterday'])->assertFailed();
});

it('rejects a reversed replay range', function (): void {
    $this->artisan('nhl:custom-predict', ['--model' => 1, '--from' => '2025-12-01', '--to' => '2025-11-01'])->assertFailed();
});

it('exposes the actual custom replay summary through the registered command', function (): void {
    [$replay, $model] = ($this->source)();
    app()->instance(NhlCustomGamePredictionReplay::class, $replay);
    $this->artisan('nhl:custom-predict', ['--model' => $model, '--json' => true])
        ->expectsOutputToContain('"selected_games": 1')->expectsOutputToContain('"p20"')->assertSuccessful();
});

it('keeps Train defensive allowance fitting deterministic', function (): void {
    $records = [];
    foreach (['EV', 'PP'] as $lane) {
        foreach ([[1, 2], [2, 1]] as [$team, $opponent]) {
            $records[] = ['valid' => true, 'team' => $team, 'opponent' => $opponent,
                'clock' => [$lane => 600], 'counts' => [$lane => ['B' => ['sat' => 10]]]];
        }
    }
    $first = new NhlCustomGameDefense();
    $second = new NhlCustomGameDefense();
    $first->fit($records);
    $second->fit($records);
    expect($first->snapshot())->toBe($second->snapshot())
        ->and($first->allowance(1, 'EV', 'B'))->toEqualWithDelta(1.0, 1.0e-10);
});

it('uses a complete recent defensive window before team Train', function (): void {
    $defense = ($this->defenseFixture)();
    $resolved = $defense->resolveAllowance(2, 'EV', 'B', [
        ['valid' => true, 'opponent' => 999, 'clock' => ['EV' => 600],
            'against' => ['EV' => ['B' => ['sat' => 3]]]],
    ]);
    expect($resolved['source'])->toBe('team_recent')
        ->and($resolved['value'])->not->toBe($defense->allowance(2, 'EV', 'B'));
});

it('falls back to team Train when any recent defensive game is incomplete', function (): void {
    $defense = ($this->defenseFixture)();
    $resolved = $defense->resolveAllowance(2, 'EV', 'B', [
        ['valid' => true, 'opponent' => 1, 'clock' => ['EV' => 600], 'against' => []],
        ['valid' => false, 'opponent' => 1, 'clock' => ['EV' => 600], 'against' => []],
    ]);
    expect($resolved['source'])->toBe('team_train')
        ->and($resolved['value'])->toBe($defense->allowance(2, 'EV', 'B'));
});

it('falls back to league Train when recent and team defensive history are unavailable', function (): void {
    $defense = ($this->defenseFixture)();
    $resolved = $defense->resolveAllowance(999, 'EV', 'B', [['valid' => false]]);
    expect($resolved)->toBe(['value' => 1.0, 'source' => 'league_train']);
});

it('uses team Train rather than a neutral recent estimate for an empty window', function (): void {
    $defense = ($this->defenseFixture)();
    expect($defense->resolveAllowance(2, 'EV', 'B', []))
        ->toBe(['value' => $defense->allowance(2, 'EV', 'B'), 'source' => 'team_train']);
});

it('retains observed zero attempts in a recent defensive window with exposure', function (): void {
    $defense = ($this->defenseFixture)();
    $resolved = $defense->resolveAllowance(2, 'EV', 'B', [
        ['valid' => true, 'opponent' => 999, 'clock' => ['EV' => 600],
            'against' => ['EV' => ['B' => ['sat' => 0]]]],
    ]);
    expect($resolved['source'])->toBe('team_recent')
        ->and($resolved['value'])->toEqualWithDelta(150 / 160, 1.0e-10);
});

it('counts an absent bucket in complete recent facts as an observed zero', function (): void {
    $defense = ($this->defenseFixture)();
    $resolved = $defense->resolveAllowance(2, 'EV', 'B', [
        ['valid' => true, 'opponent' => 999, 'clock' => ['EV' => 600], 'against' => []],
    ]);
    expect($resolved['source'])->toBe('team_recent')
        ->and($resolved['value'])->toEqualWithDelta(150 / 160, 1.0e-10);
});

it('falls back rather than treating missing defensive clock evidence as zero', function (): void {
    $defense = ($this->defenseFixture)();
    foreach ([[], ['EV' => 0], ['EV' => -1], ['EV' => INF]] as $clock) {
        $resolved = $defense->resolveAllowance(2, 'EV', 'B', [
            ['valid' => true, 'opponent' => 1, 'clock' => $clock, 'against' => []],
        ]);
        expect($resolved['source'])->toBe('team_train')
            ->and($resolved['value'])->toBe($defense->allowance(2, 'EV', 'B'));
    }
});

it('uses PK exposure for a defensive window facing PP attempts', function (): void {
    $defense = ($this->defenseFixture)();
    $resolved = $defense->resolveAllowance(2, 'PP', 'B', [
        ['valid' => true, 'opponent' => 999, 'clock' => ['EV' => 3000, 'PK' => 300], 'against' => []],
    ]);
    expect($resolved['source'])->toBe('team_recent')
        ->and($resolved['value'])->toEqualWithDelta(150 / 155, 1.0e-10);
});

it('uses neutral league defense for an unseen bucket without borrowing another strength', function (): void {
    $defense = ($this->defenseFixture)();
    expect($defense->resolveAllowance(999, 'EV', 'missing'))->toBe(['value' => 1.0, 'source' => 'league_train'])
        ->and(fn () => $defense->resolveAllowance(999, 'PK', 'B'))
        ->toThrow(\DomainException::class, 'Matching Train league defensive strength evidence');
});

it('retains defensive fallback provenance in calculated player buckets', function (): void {
    $this->input['teams']['away']['players'][100]['buckets']['EV']['B']['defense_allowance_source'] = 'league_train';
    $this->input['teams']['away']['players'][100]['buckets']['EV']['B']['defense_reference_sources'] = ['team_train' => 0.5, 'league_train' => 0.5];
    $bucket = $this->engine->predict($this->input)['teams']['away']['players'][100]['strengths']['EV']['buckets']['B'];
    expect($bucket['defense_allowance_source'])->toBe('league_train')
        ->and($bucket['defense_reference_sources'])->toBe(['team_train' => 0.5, 'league_train' => 0.5])
        ->and($this->engine->definition()['defense_fallback'])->toBe(['team_recent', 'team_train', 'league_train']);
});

it('predicts requested games in every state without target results or saved context', function (string $state): void {
    [$reader, $model, $lineups] = ($this->forecastFixture)($state);
    $events = iterator_to_array($reader->forecasts($model, '2026-11-05', $lineups), false);
    expect($events)->toHaveCount(1)->and($events[0]['error'])->toBeNull()
        ->and($events[0]['input']['teams']['away']['context_id'])->toBeNull()
        ->and($events[0]['input']['teams']['away']['context_source'])->toBe('transient_prior_results')
        ->and($events[0]['input']['teams']['away']['players'][100]['history']['EV']['last_20']['games'])->toBe(4)
        ->and($events[0]['input']['teams']['away']['players'][100]['history']['EV']['season_to_date']['games'])->toBe(1)
        ->and($reader->metadata()['train_seasons'])->toBe(['20232024', '20242025', '20252026'])
        ->and($reader->metadata()['coefficients_transferred'])->toBeTrue()
        ->and($this->engine->predict($events[0]['input'])['total_goals'])->toBeGreaterThan(0)
        ->and(DB::table('nhl_team_game_pregame_contexts')->count())->toBe(0);
})->with(['FUT', 'LIVE', 'FINAL', 'OFF']);

it('rejects malformed scheduled prediction dates before selecting games', function (): void {
    expect(fn () => iterator_to_array((new NhlCustomGamePredictionInputs())->forecasts(2, '2026-02-30')))
        ->toThrow(\DomainException::class, 'real prediction date');
});

it('returns no forecasts when the requested schedule date has no games', function (): void {
    expect(iterator_to_array((new NhlCustomGamePredictionInputs())->forecasts(2, '2026-11-05')))->toBe([]);
});

it('predicts a scheduled game with unknown start time using request time without inventing a start', function (): void {
    [$reader, $model, $lineups] = ($this->forecastFixture)('PPD');
    NhlGame::query()->where('nhl_game_id', 2026020002)->update(['start_time_utc' => null]);
    $events = iterator_to_array($reader->forecasts($model, '2026-11-05', $lineups), false);
    expect($events[0]['error'])->toBeNull()->and($events[0]['game']->start_time_utc)->toBeNull()
        ->and($events[0]['input']['source_cutoff_at'])->toBe('2026-11-05 12:00:00');
});

it('queues a cold date once and shares completed prepared inputs across its game requests', function (): void {
    $model = NhlModelRun::query()->create(['run_key' => 'shared-date-inputs', 'name' => 'sat_v2',
        'model_family' => 'sat', 'workflow_stage' => 'training', 'model_version' => 'fixture', 'status' => 'complete',
        'train_season_ids' => ['20232024', '20242025', '20252026']]);
    $inputs = [];
    foreach ([2026020001, 2026020002] as $id) {
        NhlGame::query()->create(['nhl_game_id' => $id, 'season_id' => '20262027', 'game_type' => 2,
            'game_date' => '2026-11-05', 'game_dow' => 'Thursday', 'game_month' => 'November',
            'game_state' => 'FUT', 'start_time_utc' => '2026-11-05 23:00:00',
            'away_team_id' => 1, 'home_team_id' => 2, 'away_team_abbrev' => 'BUF', 'home_team_abbrev' => 'COL']);
        $inputs[$id] = [...$this->input, 'game_id' => $id];
    }
    $reader = Mockery::mock(NhlCustomGamePredictionInputs::class)->makePartial();
    $reader->shouldReceive('anticipatedParticipants')->twice()->andReturn(['players' => [], 'starters' => []]);
    $reader->shouldReceive('beginQueuedForecast')->once();
    $reader->shouldReceive('advanceQueuedForecast')->once()->andReturn(['complete' => true,
        'phase' => 'complete', 'cursor' => 2, 'inputs' => $inputs, 'errors' => [], 'metadata' => ['model_id' => $model->id]]);
    app()->instance(NhlCustomGamePredictionInputs::class, $reader);
    $service = Mockery::mock(\App\Services\NhlCustomGameForecast::class)->makePartial();
    $service->shouldReceive('contextEvidence')->twice()->andReturn(['fingerprint' => 'fixture']);
    $service->shouldReceive('contextReady')->twice()->andReturnTrue();
    foreach (array_keys($inputs) as $id) {
        expect(fn () => $service->predict($id))->toThrow(\App\Exceptions\NhlGamePredictionPreparing::class);
    }
    Bus::assertDispatchedTimes(\App\Jobs\PrepareNhlGamePredictionJob::class, 1);
    expect($service->advancePreparation(2026020001, $model->id))->toBeFalse()
        ->and($service->preparationDelay())->toBe(0)
        ->and($service->advancePreparation(2026020001, $model->id))->toBeTrue()
        ->and($service->predict(2026020001)['prediction']['game_id'])->toBe(2026020001)
        ->and($service->predict(2026020002)['prediction']['game_id'])->toBe(2026020002);
});

it('requires a complete selected SAT model for requested-date predictions', function (): void {
    $model = NhlModelRun::query()->create(['run_key' => 'unfinished-forecast', 'name' => 'Unfinished',
        'model_family' => 'sat', 'workflow_stage' => 'training', 'model_version' => 'fixture', 'status' => 'draft',
        'train_season_ids' => ['20232024', '20242025', '20252026'], 'target_season_id' => null]);
    expect(fn () => (new NhlCustomGamePredictionInputs())->prepare($model->id, '20262027'))
        ->toThrow(\DomainException::class, 'requires a complete SAT model');
});

it('rejects requested-date prediction combined with replay-only options', function (string $option, mixed $value): void {
    $this->artisan('nhl:custom-predict', ['--model' => 2, '--date' => '2026-11-05', $option => $value])
        ->expectsOutputToContain('--date is a requested-date prediction mode')->assertFailed();
})->with([['--save', true], ['--compare-schedule', true], ['--from', '2026-11-01'], ['--to', '2026-11-05']]);

it('prints requested-date predictions through the shared operational command pathway', function (): void {
    $model = NhlModelRun::create(['run_key' => 'date-command', 'name' => 'sat_v2',
        'model_family' => 'sat', 'workflow_stage' => 'training', 'model_version' => 'fixture', 'status' => 'complete',
        'train_season_ids' => ['20232024', '20242025', '20252026']]);
    NhlGame::create(['nhl_game_id' => 2026020001, 'season_id' => '20262027', 'game_type' => 2,
        'game_date' => '2026-11-05', 'game_dow' => 'Thursday', 'game_month' => 'November', 'game_state' => 'LIVE',
        'start_time_utc' => '2026-11-05 23:00:00', 'away_team_id' => 1, 'home_team_id' => 2]);
    $builder = Mockery::mock(\App\Services\NhlGamePredictionPayload::class);
    $builder->shouldReceive('build')->once()->andReturn(['prediction_available' => true, 'prediction' => [
        'winner' => ['team_abbrev' => 'COL'], 'predicted_score' => ['away' => 2.0, 'home' => 3.0],
        'away_win_probability' => 0.4, 'home_win_probability' => 0.6]]);
    app()->instance(\App\Services\NhlGamePredictionPayload::class, $builder);
    $this->artisan('nhl:custom-predict', ['--model' => $model->id, '--date' => '2026-11-05', '--json' => true])
        ->expectsOutputToContain('"date": "2026-11-05"')->expectsOutputToContain('"recommendation"')->assertSuccessful();
    $this->assertDatabaseCount('nhl_game_prediction_revisions', 1);
});

it('reports an unavailable requested lineup without inventing target participants', function (): void {
    [$reader, $model, $lineups] = ($this->forecastFixture)();
    $lineups[2026020002]['players'] = [];
    $events = iterator_to_array($reader->forecasts($model, '2026-11-05', $lineups), false);
    expect($events[0]['input'])->toBeNull()->and($events[0]['error'])->toBe('No skater lineup is available.');
});

it('clamps the approved probability delta and preserves complementary probabilities', function (): void {
    foreach ($this->input['teams']['home']['players'] as &$player) {
        $player['buckets']['EV']['B']['sat_per_60'] = 200.0;
    }
    unset($player);
    $this->input['teams']['away']['schedule'] = ['back_to_back' => true, 'games_including_target_last_7_days' => 4];
    $result = $this->engine->predict($this->input);
    expect($result['home_win_probability'])->toBe(1.0)->and($result['away_win_probability'])->toBe(0.0);
});

it('excludes a game from both modes when only the adjusted PP reconciliation fails', function (): void {
    foreach ($this->input['teams']['away']['players'] as &$player) {
        $player['buckets']['PP']['B']['sat_per_60'] = 3.7;
    }
    unset($player);
    $this->input['teams']['away']['schedule'] = ['back_to_back' => true, 'games_including_target_last_7_days' => 4];
    expect($this->engine->predict($this->input, false)['teams']['away']['strengths']['PP']['sog'])->toBeGreaterThan(0.75);
    [$replay, $model] = ($this->source)();
    $report = $replay->run($model, compareSchedule: true);
    expect($report['excluded_games'])->toBe(1)
        ->and($report['summary']['with_schedule']['predicted_games'])->toBe(0)
        ->and($report['summary']['without_schedule']['predicted_games'])->toBe(0);
});

it('does not use an outcome attached outside the prepared pregame input contract', function (): void {
    $baseline = $this->engine->predict($this->input);
    $this->input['actual'] = ['home_goals' => 99, 'away_goals' => 0, 'winner' => 'home'];
    expect($this->engine->predict($this->input))->toBe($baseline);
});

it('preserves an observed zero Train opportunity share when the opposing share is available', function (): void {
    $this->input['teams']['away']['train_opportunity_share'] = 0.0;
    $result = $this->engine->predict($this->input);
    expect($result['teams']['away']['pp_opportunities'])->toEqualWithDelta(0.75 * 3.0, 1.0e-12);
});

it('rejects a model whose Train horizon does not match the frozen coefficient definition', function (): void {
    $model = NhlModelRun::query()->create(['run_key' => 'wrong-horizon', 'name' => 'Wrong horizon',
        'model_family' => 'sat', 'workflow_stage' => 'training', 'model_version' => 'fixture', 'status' => 'complete',
        'train_season_ids' => ['20232024', '20242025'], 'target_season_id' => '20252026']);
    expect(fn () => (new NhlCustomGamePredictionInputs())->prepare($model->id))
        ->toThrow(\DomainException::class, 'frozen v1 coefficients');
});

it('rejects a Test season that overlaps the frozen Train horizon', function (): void {
    $model = NhlModelRun::query()->create(['run_key' => 'overlapping-horizon', 'name' => 'Overlapping horizon',
        'model_family' => 'sat', 'workflow_stage' => 'training', 'model_version' => 'fixture', 'status' => 'complete',
        'train_season_ids' => ['20222023', '20232024', '20242025'], 'target_season_id' => '20242025']);
    expect(fn () => (new NhlCustomGamePredictionInputs())->prepare($model->id))
        ->toThrow(\DomainException::class, 'frozen v1 coefficients');
});

it('requires a completed model-owned pregame build before reading results', function (): void {
    $model = NhlModelRun::query()->create(['run_key' => 'missing-context', 'name' => 'Missing context',
        'model_family' => 'sat', 'workflow_stage' => 'training', 'model_version' => 'fixture', 'status' => 'complete',
        'train_season_ids' => ['20222023', '20232024', '20242025'], 'target_season_id' => '20252026']);
    expect(fn () => (new NhlCustomGamePredictionInputs())->prepare($model->id))
        ->toThrow(\DomainException::class, 'model-owned pregame context');
});

it('builds both sides before advancing history and excludes target and later outcomes from every window', function (bool $incompleteDefense): void {
    $model = NhlModelRun::query()->create(['run_key' => 'chronological-custom', 'name' => 'Chronological custom',
        'model_family' => 'sat', 'workflow_stage' => 'training', 'model_version' => 'fixture', 'status' => 'complete',
        'train_season_ids' => ['20222023', '20232024', '20242025'], 'target_season_id' => '20252026']);
    DB::table('nhl_pregame_context_runs')->insert(['model_run_id' => $model->id, 'action' => 'build',
        'status' => 'completed', 'season_ids' => '["20222023","20232024","20242025","20252026"]']);
    $filter = Mockery::mock(NhlPregameContextBuilder::class);
    $filter->shouldReceive('evaluationBucketFilter')->twice()->with($model->id, ['20222023', '20232024', '20242025'])
        ->andReturn(['keys' => ['B'], 'feature_config' => [], 'sat_model_id' => 4, 'minimum_confidence' => 0.5]);
    app()->instance(NhlPregameContextBuilder::class, $filter);
    $bucketSql = Mockery::mock(NhlSatModelEntityProfileBuilder::class);
    $bucketSql->shouldReceive('modelBucketKeySql')->twice()->andReturn("'B'");
    app()->instance(NhlSatModelEntityProfileBuilder::class, $bucketSql);
    $fixtures = [];
    foreach ([2022020001 => ['20222023', '2022-11-01'], 2023020001 => ['20232024', '2023-11-01'],
        2024020001 => ['20242025', '2024-11-01'], 2025020001 => ['20252026', '2025-11-01'],
        2025020002 => ['20252026', '2025-11-03']] as $id => [$season, $date]) {
        NhlGame::query()->create(['nhl_game_id' => $id, 'season_id' => $season, 'game_type' => 2,
            'game_date' => $date, 'game_dow' => 'Saturday', 'game_month' => 'November', 'game_state' => 'FINAL',
            'start_time_utc' => $date.' 23:00:00', 'away_team_id' => 1, 'home_team_id' => 2,
            'away_team_abbrev' => 'BUF', 'home_team_abbrev' => 'COL', 'away_team_score' => 2, 'home_team_score' => 3,
            'home_pp_opportunities' => 3, 'away_pp_opportunities' => 3, 'home_pp_goals' => 1, 'away_pp_goals' => 1]);
        $record = ['source' => true, 'valid' => true, 'unmatched_shooter' => false,
            'players' => [], 'counts' => [], 'clocks' => [], 'actual' => [], 'player_actual' => []];
        foreach ([1, 2] as $team) {
            $record['clocks'][$team] = ['EV' => 3000.0, 'PP' => 300.0, 'PK' => 300.0];
            foreach ([0, 1] as $slot) {
                $player = $team * 100 + $slot;
                $record['players'][$player] = ['id' => $player, 'team' => $team, 'name' => 'Fixture '.$player,
                    'role' => $slot === 0 ? 'F' : 'D', 'unclassified' => false,
                    'seconds' => ['EV' => 1500.0, 'PP' => 150.0],
                    'counts' => ['EV' => ['B' => ['sat' => 20, 'sog' => 8, 'goals' => 2]],
                        'PP' => ['B' => ['sat' => 4, 'sog' => 2, 'goals' => 1]]]];
            }
            $record['counts'][$team] = ['EV' => ['B' => ['sat' => 40, 'sog' => 16, 'goals' => 4]],
                'PP' => ['B' => ['sat' => 8, 'sog' => 4, 'goals' => 2]]];
            $record['actual'][$team] = ['EV' => ['goals' => 4], 'PP' => ['goals' => 2]];
        }
        $fixtures[$id] = $record;
    }
    if ($incompleteDefense) {
        // Player histories remain complete; the prior game's team clock fails validation.
        $fixtures[2024020001]['valid'] = false;
    }
    $makeReader = fn (array $records): NhlCustomGamePredictionInputs => new class($records) extends NhlCustomGamePredictionInputs
    {
        /** Supply deterministic already-read result batches while exercising the real history builder. */
        public function __construct(private readonly array $fixtures)
        {
        }

        /** Avoid provider/database-specific raw clock SQL in this chronological contract test. */
        protected function records(array $games): array
        {
            return array_intersect_key($this->fixtures, array_fill_keys(array_map(fn (object $g): int => $g->nhl_game_id, $games), true));
        }

        /** Supply correctly linked same-cutoff snapshots for the frozen synthetic lineups. */
        protected function contexts(array $games): array
        {
            $out = ['teams' => [], 'players' => []];
            foreach ($games as $game) {
                foreach ([1, 2] as $team) {
                    $out['teams'][$game->nhl_game_id][$team] = (object) ['id' => $team,
                        'source_cutoff_at' => $game->start_time_utc, 'metrics' => '{}',
                        'schedule_metrics' => '{"back_to_back":false,"games_including_target_last_7_days":3}'];
                    foreach ([$team * 100, $team * 100 + 1] as $player) {
                        $out['players'][$game->nhl_game_id][$player] = (object) ['id' => $player,
                            'team_context_id' => $team, 'source_cutoff_at' => $game->start_time_utc];
                    }
                }
            }

            return $out;
        }
    };
    $reader = $makeReader($fixtures);
    $reader->prepare($model->id);
    $first = iterator_to_array($reader->games(), false);
    expect($first)->toHaveCount(2)->and($first[0]['error'])->toBeNull()->and($first[1]['error'])->toBeNull();
    foreach (['away' => 100, 'home' => 200] as $venue => $player) {
        expect($first[0]['input']['teams'][$venue]['players'][$player]['history']['PP']['season_to_date'])->toBeNull()
            ->and($first[0]['input']['teams'][$venue]['players'][$player]['history']['EV']['last_20']['games'])->toBe(3)
            ->and($first[1]['input']['teams'][$venue]['players'][$player]['history']['PP']['season_to_date']['games'])->toBe(1)
            ->and($first[1]['input']['teams'][$venue]['players'][$player]['history']['EV']['last_20']['games'])->toBe(4)
            ->and($first[0]['input']['teams'][$venue]['players'][$player]['buckets']['EV']['B']['defense_allowance_source'])
            ->toBe($incompleteDefense ? 'team_train' : 'team_recent')
            ->and(array_sum($first[0]['input']['teams'][$venue]['players'][$player]['buckets']['EV']['B']['defense_reference_sources']))
            ->toEqualWithDelta(1.0, 1.0e-10);
    }
    $fixtures[2025020002]['players'][100]['counts']['EV']['B']['goals'] = 7;
    $changed = $makeReader($fixtures);
    $changed->prepare($model->id);
    $second = iterator_to_array($changed->games(), false);
    expect($second[0]['input'])->toBe($first[0]['input'])->and($second[1]['input'])->toBe($first[1]['input']);
})->with([false, true]);

it('marks an interrupted saved run failed while retaining already recorded game history', function (): void {
    $model = NhlModelRun::query()->create(['run_key' => 'interrupted-custom', 'name' => 'Interrupted custom',
        'model_family' => 'sat', 'workflow_stage' => 'training', 'model_version' => 'fixture', 'status' => 'complete',
        'train_season_ids' => ['20222023', '20232024', '20242025'], 'target_season_id' => '20252026']);
    $metadata = ['model_id' => $model->id, 'model_name' => 'Interrupted custom', 'test_season' => '20252026', 'selected_games' => 2];
    $event = ($this->event)($this->input);
    $source = Mockery::mock(NhlCustomGamePredictionInputs::class);
    $source->shouldReceive('prepare')->once()->with($model->id)->andReturn($metadata);
    $source->shouldReceive('metadata')->andReturn($metadata);
    $source->shouldReceive('games')->once()->with(null, null)->andReturnUsing(function () use ($event): \Generator {
        yield $event;
        throw new \RuntimeException('Fixture source failure');
    });
    $replay = new NhlCustomGamePredictionReplay($this->engine, $source);
    expect(fn () => $replay->run($model->id, save: true))->toThrow(\RuntimeException::class, 'Fixture source failure');
    $run = DB::table('nhl_custom_game_prediction_runs')->first();
    expect($run->status)->toBe('failed')->and($run->completed_games)->toBe(1)
        ->and($run->last_error)->toContain('Fixture source failure')
        ->and(DB::table('nhl_custom_game_predictions')->where('status', 'complete')->count())->toBe(1);
});


it('preserves the defensive equation across serialized collection and fit checkpoints', function (): void {
    $records = [];
    foreach ([[1, 2, 20], [2, 1, 8]] as [$team, $opponent, $sat]) {
        $records[] = ['valid' => true, 'team' => $team, 'opponent' => $opponent,
            'clock' => ['EV' => 600, 'PP' => 120],
            'counts' => ['EV' => ['B' => ['sat' => $sat]], 'PP' => ['B' => ['sat' => $sat / 2]]]];
    }
    $baseline = new NhlCustomGameDefense();
    $baseline->fit($records);
    $checkpointed = new NhlCustomGameDefense();
    $checkpointed->beginFit();
    foreach ($records as $record) {
        $checkpointed->collectFit([$record]);
        $checkpointed = unserialize(serialize($checkpointed));
    }
    expect($checkpointed->finishFitPage(1))->toBeFalse();
    $checkpointed = unserialize(serialize($checkpointed));
    expect($checkpointed->finishFitPage(1))->toBeTrue()
        ->and($checkpointed->snapshot())->toBe($baseline->snapshot())
        ->and($checkpointed->resolveAllowance(2, 'EV', 'B'))->toBe($baseline->resolveAllowance(2, 'EV', 'B'));
});

it('builds queued inputs from saved contexts in bounded pages without reading target outcomes', function (): void {
    [$reader, $model, $lineups] = ($this->forecastFixture)('LIVE', true);
    config(['nhl-custom-prediction.batch_games' => 1, 'nhl-custom-prediction.fit_bucket_batch' => 1]);
    $reader->beginQueuedForecast($model, '2026-11-05', $lineups);
    $phases = [];
    for ($i = 0; $i < 30; $i++) {
        $step = $reader->advanceQueuedForecast();
        $phases[] = $step['phase'];
        if ($step['complete']) {
            break;
        }
    }
    expect($step['complete'])->toBeTrue()->and($step['errors'])->toBe([])
        ->and($phases)->toContain('collect', 'fit', 'history', 'targets', 'complete');
    $input = $step['inputs'][2026020002];
    expect($input['source_cutoff_at'])->toBe('2026-11-05 12:00:00')
        ->and($input['teams']['away']['context_source'])->toBe('saved_model_snapshot')
        ->and($input['teams']['away']['context_id'])->not->toBeNull()
        ->and($input['teams']['away']['players'][100]['history']['EV']['last_20']['games'])->toBe(4)
        ->and($input['teams']['away']['players'][100]['history']['EV']['train']['games'])->toBe(3)
        ->and($this->engine->predict($input)['total_goals'])->toBeLessThan(99);
});

it('rejects queued preparation without saved lineup context before any raw replay', function (): void {
    [$reader, $model, $lineups] = ($this->forecastFixture)('FUT', true);
    DB::table('nhl_player_game_pregame_contexts')->where('nhl_player_id', 100)->delete();
    expect(fn () => $reader->beginQueuedForecast($model, '2026-11-05', $lineups))
        ->toThrow(\DomainException::class, 'Saved pregame context is incomplete');
});

it('releases preparation work immediately after progress and polls pending context gently', function (): void {
    $forecast = Mockery::mock(\App\Services\NhlCustomGameForecast::class);
    $forecast->shouldReceive('advancePreparation')->once()->with(1, 2, false, [])->andReturnFalse();
    $forecast->shouldReceive('preparationDelay')->once()->andReturn(0);
    $queue = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
    $queue->shouldReceive('release')->once()->with(0);
    $job = new \App\Jobs\PrepareNhlGamePredictionJob(1, 2);
    $job->setJob($queue);
    $job->handle($forecast);
    expect($job->queue)->toBe('projections')->and($job->tries)->toBe(0)
        ->and($job->retryUntil()->format('c'))->toBe('2026-11-06T12:00:00+00:00');
});

it('does not release a completed preparation or dispatch another job', function (): void {
    $forecast = Mockery::mock(\App\Services\NhlCustomGameForecast::class);
    $forecast->shouldReceive('advancePreparation')->once()->andReturnTrue();
    $forecast->shouldNotReceive('preparationDelay');
    $job = new \App\Jobs\PrepareNhlGamePredictionJob(1, 2);
    $job->handle($forecast);
    Bus::assertNothingDispatched();
});


it('keeps the full Train league fallback while checkpointing only selected player histories', function (): void {
    [$reader, $model, $lineups] = ($this->forecastFixture)('FUT', true, true);
    $reader->beginQueuedForecast($model, '2026-11-05', $lineups);
    for ($page = 0; $page < 30; $page++) {
        $step = $reader->advanceQueuedForecast();
        if ($step['complete']) {
            break;
        }
    }
    expect($step['complete'])->toBeTrue()->and($step['errors'])->toBe([]);
    $input = $step['inputs'][2026020002];
    expect($input['league_usage_seconds']['EV'])->toBe(1380.0)
        ->and($input['league_usage_seconds']['PP'])->toBe(138.0)
        ->and($input['teams']['away']['players'])->not->toHaveKey(999)
        ->and($input['teams']['away']['players'][100]['history']['EV']['train']['games'])->toBe(3);
});

it('reads saved player exposure and overall totals while preserving the separate bucket history', function (): void {
    [$reader, $model, $lineups] = ($this->forecastFixture)('FUT', true);
    $row = DB::table('nhl_player_game_pregame_contexts')->where('nhl_player_id', 100)->first();
    $metrics = json_decode($row->metrics, true);
    $metrics['strength']['EV']['last_10']['toi_seconds'] = 6002;
    $metrics['strength']['EV']['last_20']['sat'] = 81;
    DB::table('nhl_player_game_pregame_contexts')->where('id', $row->id)->update(['metrics' => json_encode($metrics)]);

    $step = ($this->finishQueuedForecast)($reader, $model, $lineups);
    expect($step['errors'])->toBe([]);
    $player = $step['inputs'][2026020002]['teams']['away']['players'][100];
    expect($player['usage']['EV'])->toBe(1500.5)
        ->and($player['history']['EV']['last_20']['sat'])->toBe(81)
        ->and($player['buckets']['EV']['B']['sog_probability'])->toBe(0.4)
        ->and($player['buckets']['EV']['B']['defense_reference'])->toBeGreaterThan(0);
});

it('does not accumulate duplicated personal counts or exposure during operational history preparation', function (): void {
    [$reader, $model, $lineups] = ($this->forecastFixture)('FUT', true);
    $step = ($this->finishQueuedForecast)($reader, $model, $lineups);
    expect($step['errors'])->toBe([]);
    $property = new \ReflectionProperty(NhlCustomGamePredictionInputs::class, 'train');
    $train = $property->getValue($reader);
    expect($train[100]['EV']['games'])->toBe(0)
        ->and($train[100]['EV']['seconds'])->toBe(0.0)
        ->and($train[100]['EV']['buckets']['B'])->toBe(['sat' => 60, 'sog' => 24, 'goals' => 3])
        ->and($step['inputs'][2026020002]['teams']['away']['players'][100]['history']['EV']['train']['games'])->toBe(3);
});

it('uses saved player PP goals per appearance for the L20 offense allocation', function (): void {
    [$reader, $model, $lineups] = ($this->forecastFixture)('FUT', true);
    $row = DB::table('nhl_player_game_pregame_contexts')->where('nhl_player_id', 100)->first();
    $metrics = json_decode($row->metrics, true);
    $metrics['strength']['PP']['last_20']['goals'] = 2;
    DB::table('nhl_player_game_pregame_contexts')->where('id', $row->id)->update(['metrics' => json_encode($metrics)]);

    $step = ($this->finishQueuedForecast)($reader, $model, $lineups);
    expect($step['errors'])->toBe([])
        ->and($step['inputs'][2026020002]['teams']['away']['pp_offense_l20'])->toBe(1.5)
        ->and($step['inputs'][2026020002]['teams']['away']['pp_offense_sources'][100])->toBe('last_20');
});

it('does not silently replay missing or invalid saved individual aggregates', function (string $case): void {
    [$reader, $model, $lineups] = ($this->forecastFixture)('FUT', true);
    $row = DB::table('nhl_player_game_pregame_contexts')->where('nhl_player_id', 100)->first();
    $metrics = json_decode($row->metrics, true);
    if ($case === 'version') {
        unset($metrics['individual_strength_version']);
    } elseif ($case === 'window') {
        unset($metrics['strength']['EV']['last_10']);
    } elseif ($case === 'field') {
        unset($metrics['strength']['EV']['last_10']['toi_seconds']);
    } else {
        $metrics['strength']['EV']['last_10']['toi_seconds'] = -1;
    }
    DB::table('nhl_player_game_pregame_contexts')->where('id', $row->id)->update(['metrics' => json_encode($metrics)]);

    $step = ($this->finishQueuedForecast)($reader, $model, $lineups);
    expect($step['inputs'])->not->toHaveKey(2026020002)
        ->and($step['errors'][2026020002])->toContain('Saved individual-strength pregame');
})->with(['version', 'window', 'field', 'negative']);

it('retains incomplete recent-window fallback and usable Train samples from saved context', function (): void {
    [$reader, $model, $lineups] = ($this->forecastFixture)('FUT', true);
    $row = DB::table('nhl_player_game_pregame_contexts')->where('nhl_player_id', 100)->first();
    $metrics = json_decode($row->metrics, true);
    $metrics['strength']['PP']['last_20']['individual_missing_games'] = 1;
    foreach (['sat', 'sog', 'goals'] as $metric) {
        $metrics['strength']['PP']['last_20'][$metric] = null;
    }
    $metrics['strength']['PP']['train']['individual_missing_games'] = 1;
    DB::table('nhl_player_game_pregame_contexts')->where('id', $row->id)->update(['metrics' => json_encode($metrics)]);

    $step = ($this->finishQueuedForecast)($reader, $model, $lineups);
    expect($step['errors'])->toBe([])
        ->and($step['inputs'][2026020002]['teams']['away']['players'][100]['history']['PP']['last_20'])->toBeNull()
        ->and($step['inputs'][2026020002]['teams']['away']['pp_offense_sources'][100])->toBe('player_train')
        ->and($step['inputs'][2026020002]['teams']['away']['pp_offense_l20'])->toBe(2.0);
});

it('retains zero-strength appearances when reusing saved exposure', function (): void {
    [$reader, $model, $lineups] = ($this->forecastFixture)('FUT', true);
    $row = DB::table('nhl_player_game_pregame_contexts')->where('nhl_player_id', 100)->first();
    $metrics = json_decode($row->metrics, true);
    $metrics['strength']['PP']['last_10'] = ['games' => 4, 'toi_seconds' => 0,
        'sat' => 0, 'sog' => 0, 'goals' => 0, 'individual_missing_games' => 0];
    DB::table('nhl_player_game_pregame_contexts')->where('id', $row->id)->update(['metrics' => json_encode($metrics)]);

    $step = ($this->finishQueuedForecast)($reader, $model, $lineups);
    expect($step['errors'])->toBe([]);
    $player = $step['inputs'][2026020002]['teams']['away']['players'][100];
    expect($player['usage']['PP'])->toBe(0.0)
        ->and($player['complete']['PP'])->toBeTrue()
        ->and($player['history']['PP']['last_10']['games'])->toBe(4);
});

it('scales every EV bucket using the saved L5 record gap while preserving PP and minutes', function (): void {
    $this->input['teams']['home']['team_metrics']['last_5'] = ['games' => 5, 'win_pct' => 60];
    $this->input['teams']['away']['team_metrics']['last_5'] = ['games' => 5, 'win_pct' => 40];
    config(['nhl-custom-prediction.team_l5_win_pct_weight' => 0]);
    $baseline = $this->engine->predict($this->input);
    config(['nhl-custom-prediction.team_l5_win_pct_weight' => 0.50]);
    $adjusted = $this->engine->predict($this->input);

    expect($adjusted['team_form_adjustment']['available'])->toBeTrue()
        ->and($adjusted['team_form_adjustment']['win_pct'])->toBe(['home' => 60.0, 'away' => 40.0])
        ->and($adjusted['team_form_adjustment']['sample_games'])->toBe(['home' => 5, 'away' => 5]);
    foreach (['home' => 1.1, 'away' => 0.9] as $venue => $multiplier) {
        $before = $baseline['teams'][$venue];
        $after = $adjusted['teams'][$venue];
        expect($after['time'])->toBe($before['time'])
            ->and($after['pp_opportunities'])->toBe($before['pp_opportunities'])
            ->and($after['strengths']['PP'])->toBe($before['strengths']['PP'])
            ->and($after['pp_allocation'])->toBe($before['pp_allocation'])
            ->and($after['ev_form_multiplier'])->toBe($multiplier);
        foreach ($after['players'] as $id => $player) {
            $old = $before['players'][$id];
            expect($player['strengths']['EV']['seconds'])->toBe($old['strengths']['EV']['seconds'])
                ->and($player['strengths']['EV']['share'])->toBe($old['strengths']['EV']['share'])
                ->and($player['strengths']['PP'])->toBe($old['strengths']['PP']);
            foreach (['sat', 'sog', 'goals'] as $metric) {
                expect(abs($player['strengths']['EV']['buckets']['B'][$metric]
                    - $old['strengths']['EV']['buckets']['B'][$metric] * $multiplier))->toBeLessThan(1.0e-12);
            }
        }
    }
    $expected = $this->engine->winProbabilities($adjusted['teams']['home']['goals'], $adjusted['teams']['away']['goals']);
    expect($adjusted['home_win_probability'])->toBe($expected['home'])
        ->and($adjusted['home_win_probability'])->toBeGreaterThan($baseline['home_win_probability'])
        ->and($adjusted['away_win_probability'])->toBe(1 - $adjusted['home_win_probability']);
});

it('applies the same L5 factor in every quarter and at mismatched quarter boundaries', function (int $homeGame, int $awayGame): void {
    $this->input['teams']['home']['team_metrics'] = ['last_5' => ['games' => 5, 'win_pct' => 60],
        'season_to_date' => ['games' => $homeGame - 1]];
    $this->input['teams']['away']['team_metrics'] = ['last_5' => ['games' => 5, 'win_pct' => 40],
        'season_to_date' => ['games' => $awayGame - 1]];
    $prediction = $this->engine->predict($this->input);
    expect($prediction['teams']['home']['ev_form_multiplier'])->toBe(1.1)
        ->and($prediction['teams']['away']['ev_form_multiplier'])->toBe(0.9);
})->with([[1, 1], [20, 20], [21, 21], [40, 40], [41, 41], [60, 60], [61, 61], [82, 82], [20, 21], [60, 61]]);

it('keeps both teams neutral when either saved L5 sample is missing or invalid', function (string $venue, string $case): void {
    foreach (['home', 'away'] as $side) {
        $this->input['teams'][$side]['team_metrics']['last_5'] = ['games' => 5, 'win_pct' => 60];
    }
    config(['nhl-custom-prediction.team_l5_win_pct_weight' => 0]);
    $baseline = $this->engine->predict($this->input);
    config(['nhl-custom-prediction.team_l5_win_pct_weight' => 0.50]);
    $this->input['teams'][$venue]['team_metrics']['last_5'] = match ($case) {
        'missing' => null,
        'no_games' => ['games' => 0, 'win_pct' => 100],
        'fractional_games' => ['games' => 2.5, 'win_pct' => 100],
        'no_rate' => ['games' => 5, 'win_pct' => null],
        'negative_rate' => ['games' => 5, 'win_pct' => -1],
        'excess_rate' => ['games' => 5, 'win_pct' => 101],
        'nonfinite_rate' => ['games' => 5, 'win_pct' => INF],
    };
    $adjusted = $this->engine->predict($this->input);
    expect($adjusted['team_form_adjustment']['available'])->toBeFalse()
        ->and($adjusted['teams']['home']['ev_form_multiplier'])->toBe(1.0)
        ->and($adjusted['teams']['away']['ev_form_multiplier'])->toBe(1.0)
        ->and($adjusted['total_goals'])->toBe($baseline['total_goals'])
        ->and($adjusted['home_win_probability'])->toBe($baseline['home_win_probability']);
})->with(['home', 'away'])->with(['missing', 'no_games', 'fractional_games', 'no_rate', 'negative_rate', 'excess_rate', 'nonfinite_rate']);

it('accepts observed zero win percentages and nonempty opening-season samples', function (): void {
    $this->input['teams']['home']['team_metrics']['last_5'] = ['games' => 2, 'win_pct' => 0];
    $this->input['teams']['away']['team_metrics']['last_5'] = ['games' => 3, 'win_pct' => 100];
    $prediction = $this->engine->predict($this->input);
    expect($prediction['team_form_adjustment']['available'])->toBeTrue()
        ->and($prediction['team_form_adjustment']['sample_games'])->toBe(['home' => 2, 'away' => 3])
        ->and($prediction['teams']['home']['ev_form_multiplier'])->toBe(0.5)
        ->and($prediction['teams']['away']['ev_form_multiplier'])->toBe(1.5);
});

it('leaves equal L5 records neutral and reverses the adjustment when records reverse', function (): void {
    foreach (['home', 'away'] as $venue) {
        $this->input['teams'][$venue]['team_metrics']['last_5'] = ['games' => 5, 'win_pct' => 60];
    }
    $equal = $this->engine->predict($this->input);
    expect($equal['team_form_adjustment']['available'])->toBeTrue()
        ->and($equal['teams']['home']['ev_form_multiplier'])->toBe(1.0)
        ->and($equal['teams']['away']['ev_form_multiplier'])->toBe(1.0);
    $this->input['teams']['away']['team_metrics']['last_5']['win_pct'] = 80;
    $reversed = $this->engine->predict($this->input);
    expect($reversed['teams']['home']['ev_form_multiplier'])->toBe(0.9)
        ->and($reversed['teams']['away']['ev_form_multiplier'])->toBe(1.1)
        ->and($reversed['home_win_probability'])->toBeLessThan($equal['home_win_probability']);
});

it('keeps the L5 layer independent of the schedule switch and preserves the separate travel delta', function (bool $withSchedule): void {
    $this->input['teams']['home']['team_metrics']['last_5'] = ['games' => 5, 'win_pct' => 60];
    $this->input['teams']['away']['team_metrics']['last_5'] = ['games' => 5, 'win_pct' => 40];
    $this->input['teams']['away']['schedule'] = ['back_to_back' => true, 'games_including_target_last_7_days' => 4];
    config(['nhl-custom-prediction.team_l5_win_pct_weight' => 0]);
    $baseline = $this->engine->predict($this->input, $withSchedule);
    config(['nhl-custom-prediction.team_l5_win_pct_weight' => 0.50]);
    $adjusted = $this->engine->predict($this->input, $withSchedule);
    expect($adjusted['teams']['away']['ev_form_multiplier'])->toBe(0.9)
        ->and($adjusted['teams']['away']['schedule_adjustment_applied'])->toBe($withSchedule)
        ->and($adjusted['home_probability_schedule_delta'])->toBe($baseline['home_probability_schedule_delta'])
        ->and($adjusted['teams']['away']['players'][100]['strengths']['EV']['schedule_multiplier'])
        ->toBe($withSchedule ? 0.98 : 1.0)
        ->and($adjusted['teams']['away']['players'][101]['strengths']['EV']['schedule_multiplier'])
        ->toBe($withSchedule ? 0.97 : 1.0)
        ->and($adjusted['teams']['away']['strengths']['PP'])->toBe($baseline['teams']['away']['strengths']['PP']);
    $probability = $this->engine->winProbabilities($adjusted['teams']['home']['goals'], $adjusted['teams']['away']['goals']);
    expect(abs($adjusted['home_win_probability'] - min(1.0, $probability['home']
        + $adjusted['home_probability_schedule_delta'])))->toBeLessThan(1.0e-12);
})->with([true, false]);


it('reuses committed context for future targets without building target context or reading target results', function (): void {
    [$reader, $model, $lineups] = ($this->forecastFixture)('FUT', true);
    // Today is Nov 5; the requested target is Nov 7. Preserve today's saved context identities.
    $target = DB::table('nhl_games')->where('nhl_game_id', 2026020002)->first();
    $source = (array) $target;
    unset($source['id']);
    $source['nhl_game_id'] = 2026020003;
    DB::table('nhl_games')->insert($source);
    foreach (DB::table('nhl_team_game_pregame_contexts')->get() as $team) {
        $parent = (array) $team;
        unset($parent['id']);
        $parent['nhl_game_id'] = 2026020003;
        $newId = DB::table('nhl_team_game_pregame_contexts')->insertGetId($parent);
        DB::table('nhl_player_game_pregame_contexts')->where('team_context_id', $team->id)
            ->update(['nhl_game_id' => 2026020003, 'team_context_id' => $newId]);
    }
    $run = DB::table('nhl_pregame_context_runs')->first();
    DB::table('nhl_pregame_context_run_games')->insert(['run_id' => $run->id, 'nhl_game_id' => 2026020003,
        'sequence' => 1, 'game_date' => '2026-11-05', 'state' => 'complete',
        'comparisons_completed_at' => '2026-11-05 12:00:00']);
    DB::table('nhl_games')->where('nhl_game_id', 2026020002)
        ->update(['game_date' => '2026-11-07', 'start_time_utc' => '2026-11-07 23:00:00']);
    app(NhlPregameContextBuilder::class)->shouldReceive('predictionScheduleEvidence')->twice()
        ->andReturn(['back_to_back' => false, 'games_including_target_last_7_days' => 3]);
    $reader->beginQueuedForecast($model, '2026-11-07', $lineups);
    for ($page = 0; $page < 30; $page++) {
        $step = $reader->advanceQueuedForecast();
        if ($step['complete']) {
            break;
        }
    }
    expect($step['complete'])->toBeTrue()->and($step['errors'])->toBe([])
        ->and($step['metadata']['context_mode'])->toBe('latest_available')
        ->and($step['inputs'][2026020002]['teams']['away']['context_source'])->toBe('latest_available_model_snapshot')
        ->and($step['inputs'][2026020002]['teams']['away']['context_provenance']['game_id'])->toBe(2026020003)
        ->and($step['inputs'][2026020002]['teams']['away']['players'][100]['history']['EV']['last_20']['games'])->toBe(4);
    Bus::assertNotDispatched(\App\Jobs\BuildNhlPregameContextDayJob::class);
});

it('uses prior player history when future context is absent without weakening same-day requirements', function (): void {
    [$reader, $model, $lineups] = ($this->forecastFixture)('FUT', true);
    DB::table('nhl_player_game_pregame_contexts')->delete();
    DB::table('nhl_team_game_pregame_contexts')->delete();
    DB::table('nhl_games')->where('nhl_game_id', 2026020002)
        ->update(['game_date' => '2026-11-07', 'start_time_utc' => '2026-11-07 23:00:00']);
    app(NhlPregameContextBuilder::class)->shouldReceive('predictionScheduleEvidence')->twice()
        ->andReturn(['back_to_back' => false, 'games_including_target_last_7_days' => 2]);
    $reader->beginQueuedForecast($model, '2026-11-07', $lineups);
    for ($page = 0; $page < 30; $page++) {
        $step = $reader->advanceQueuedForecast();
        if ($step['complete']) {
            break;
        }
    }
    expect($step['errors'])->toBe([])
        ->and($step['inputs'][2026020002]['teams']['away']['context_id'])->toBeNull()
        ->and($step['inputs'][2026020002]['teams']['away']['players'][100]['context_provenance']['source'])->toBe('prior_results_fallback')
        ->and($step['inputs'][2026020002]['teams']['away']['players'][100]['history']['EV']['last_20']['games'])->toBe(4);
});

it('derives future rest and density from intervening scheduled games instead of old context', function (): void {
    [$reader] = ($this->forecastFixture)('FUT', true);
    $target = DB::table('nhl_games')->where('nhl_game_id', 2026020002)->first();
    $target->game_date = '2026-11-06';
    $target->start_time_utc = '2026-11-06 23:00:00';
    $schedule = (new NhlPregameContextBuilder())->predictionScheduleEvidence($target, 1, 2, 'away');
    expect($schedule['back_to_back'])->toBeTrue()->and($schedule['days_rest'])->toBe(0)
        ->and($schedule['opponent_days_rest'])->toBe(0)
        ->and($schedule['games_including_target_last_7_days'])->toBe(3);
});

it('does not reuse uncommitted context rows for future forecasts', function (): void {
    [$reader, $model] = ($this->forecastFixture)('FUT', true);
    $identity = NhlCustomGamePredictionInputs::futureContextIdentity($model);
    expect((int) $identity['commit']->games)->toBe(0);
});


it('skips the pregame dependency queue when preparing a future date through the shared forecast boundary', function (): void {
    [$fixture, $model, $lineups] = ($this->forecastFixture)('FUT', true);
    DB::table('nhl_games')->where('nhl_game_id', 2026020002)
        ->update(['game_date' => '2026-11-07', 'start_time_utc' => '2026-11-07 23:00:00']);
    $reader = Mockery::mock(NhlCustomGamePredictionInputs::class)->makePartial();
    $reader->shouldReceive('anticipatedParticipants')->once()->andReturn($lineups[2026020002]);
    $reader->shouldReceive('beginQueuedForecast')->once()->with($model, '2026-11-07', $lineups);
    $reader->shouldReceive('advanceQueuedForecast')->once()->andReturn(['complete' => true,
        'phase' => 'complete', 'cursor' => 1, 'inputs' => [2026020002 => ['game_id' => 2026020002]],
        'errors' => [], 'metadata' => ['context_mode' => 'latest_available']]);
    app()->instance(NhlCustomGamePredictionInputs::class, $reader);
    $orchestrator = Mockery::mock(\App\Services\NhlPregameContextOrchestrator::class);
    $orchestrator->shouldNotReceive('queuePredictionContexts');
    app()->instance(\App\Services\NhlPregameContextOrchestrator::class, $orchestrator);
    $forecast = new \App\Services\NhlCustomGameForecast();
    expect($forecast->advancePreparation(2026020002, $model))->toBeFalse()
        ->and($forecast->advancePreparation(2026020002, $model))->toBeTrue();
    Bus::assertNothingDispatched();
});


it('aligns future personal bucket counts to the reused snapshot cutoff rather than newer results', function (): void {
    [$reader, $model, $lineups] = ($this->forecastFixture)('FUT', true);
    $run = DB::table('nhl_pregame_context_runs')->first();
    $parent = (array) DB::table('nhl_team_game_pregame_contexts')->where('nhl_team_id', 1)->first();
    unset($parent['id']);
    $parent['nhl_game_id'] = 2026020001;
    $parent['game_date'] = '2026-11-01';
    $parent['source_cutoff_at'] = '2026-10-31 12:00:00';
    $parentId = DB::table('nhl_team_game_pregame_contexts')->insertGetId($parent);
    $player = DB::table('nhl_player_game_pregame_contexts')->where('nhl_player_id', 100)->first();
    $metrics = json_decode($player->metrics, true, 512, JSON_THROW_ON_ERROR);
    foreach (['EV' => [1500, 20, 8], 'PP' => [150, 4, 2]] as $lane => [$seconds, $sat, $sog]) {
        foreach (['last_5', 'last_10', 'last_20', 'season_to_date'] as $window) {
            $games = $window === 'season_to_date' ? 0 : 3;
            $metrics['strength'][$lane][$window] = ['games' => $games, 'toi_seconds' => $games * $seconds,
                'sat' => $games * $sat, 'sog' => $games * $sog, 'goals' => $games, 'individual_missing_games' => 0];
        }
    }
    DB::table('nhl_player_game_pregame_contexts')->where('id', $player->id)->update([
        'nhl_game_id' => 2026020001, 'team_context_id' => $parentId, 'game_date' => '2026-11-01',
        'source_cutoff_at' => '2026-10-31 12:00:00', 'metrics' => json_encode($metrics, JSON_THROW_ON_ERROR)]);
    DB::table('nhl_pregame_context_run_games')->insert(['run_id' => $run->id, 'nhl_game_id' => 2026020001,
        'sequence' => 1, 'game_date' => '2026-11-01', 'state' => 'complete',
        'comparisons_completed_at' => '2026-11-01 12:00:00']);
    DB::table('nhl_games')->where('nhl_game_id', 2026020002)
        ->update(['game_date' => '2026-11-07', 'start_time_utc' => '2026-11-07 23:00:00']);
    app(NhlPregameContextBuilder::class)->shouldReceive('predictionScheduleEvidence')->twice()
        ->andReturn(['back_to_back' => false, 'games_including_target_last_7_days' => 3]);
    $reader->beginQueuedForecast($model, '2026-11-07', $lineups);
    for ($page = 0; $page < 30; $page++) {
        $step = $reader->advanceQueuedForecast();
        if ($step['complete']) {
            break;
        }
    }
    expect($step['errors'])->toBe([]);
    $prepared = $step['inputs'][2026020002]['teams']['away']['players'][100];
    expect($prepared['history']['EV']['last_20']['games'])->toBe(3)
        ->and($prepared['history']['EV']['last_20']['sat'])->toBe(60)
        ->and($prepared['buckets']['EV']['B']['sat_per_60'])->toBe(48.0)
        ->and($prepared['context_provenance']['game_id'])->toBe(2026020001);
});

it('uses actual Train bucket SAT to select EV conversion across the twelve attempt boundary', function (int $sat, int $sog, int $goals): void {
    [$reader, $model, $lineups] = ($this->forecastFixture)('FUT', true, false,
        ($this->bucketReliabilityHistory)($sat, $sog, $goals));
    $step = ($this->finishQueuedForecast)($reader, $model, $lineups);
    expect($step['errors'])->toBe([]);
    $bucket = $step['inputs'][2026020002]['teams']['away']['players'][100]['buckets']['EV']['B'];
    $league = ($goals + 9) / ($sog + 72);
    $personal = $sog > 0 ? $goals / $sog : $league;
    $team = ($goals + 3) / ($sog + 24);
    $expected = $sat >= 12 ? $personal : 0.47 * $personal + 0.11 * $team + 0.42 * $league;
    expect(abs($bucket['goal_probability'] - $expected))->toBeLessThan(1e-12)
        ->and($bucket['goal_conversion']['train_sat'])->toBe($sat)
        ->and($bucket['personal_goal_sample'])->toBe($sog)
        ->and($bucket['goal_conversion']['weights'])->toBe($sat >= 12
            ? ['player' => 1.0, 'team' => 0.0, 'league' => 0.0]
            : ['player' => 0.47, 'team' => 0.11, 'league' => 0.42]);
})->with([[1, 1, 1], [11, 1, 1], [12, 1, 1], [15, 1, 1], [12, 3, 0], [15, 0, 0]]);

it('uses the same Train reliability rule in the standalone forecast pathway', function (): void {
    [$reader, $model, $lineups] = ($this->forecastFixture)('FUT', false, false,
        ($this->bucketReliabilityHistory)(1, 1, 1));
    $events = iterator_to_array($reader->forecasts($model, '2026-11-05', $lineups), false);
    expect($events[0]['error'])->toBeNull();
    $bucket = $events[0]['input']['teams']['away']['players'][100]['buckets']['EV']['B'];
    $expected = 0.47 + 0.11 * (4 / 25) + 0.42 * (10 / 73);
    expect(abs($bucket['goal_probability'] - $expected))->toBeLessThan(1e-12)
        ->and($bucket['goal_probability'])->toBeLessThan(1)
        ->and($bucket['goal_source'])->toBe('train_player_team_league_blend');
});

it('leaves SOG conversion and final team PP allocation unchanged by EV reliability weights', function (): void {
    [$reader, $model, $lineups] = ($this->forecastFixture)('FUT', true, false,
        ($this->bucketReliabilityHistory)(1, 1, 1));
    $step = ($this->finishQueuedForecast)($reader, $model, $lineups);
    expect($step['errors'])->toBe([]);
    $input = $step['inputs'][2026020002];
    $player = $input['teams']['away']['players'][100];
    // L20 still pools the three Train appearances and the one prior target-season appearance.
    expect(abs($player['buckets']['EV']['B']['sog_probability'] - 9 / 21))->toBeLessThan(1e-12)
        ->and($player['buckets']['PP']['B']['goal_probability'])->toBe(0.5)
        ->and($player['buckets']['PP']['B'])->not->toHaveKey('goal_conversion');
    $actual = $this->engine->predict($input);
    foreach ($input['teams'] as &$team) {
        foreach ($team['players'] as &$skater) {
            foreach ($skater['buckets']['EV'] ?? [] as &$bucket) {
                $bucket['goal_probability'] = 0.5;
            }
            unset($bucket);
        }
        unset($skater);
    }
    unset($team);
    $comparison = $this->engine->predict($input);
    foreach (['away', 'home'] as $venue) {
        expect($actual['teams'][$venue]['sat'])->toBe($comparison['teams'][$venue]['sat'])
            ->and($actual['teams'][$venue]['sog'])->toBe($comparison['teams'][$venue]['sog'])
            ->and($actual['teams'][$venue]['time'])->toBe($comparison['teams'][$venue]['time'])
            ->and($actual['teams'][$venue]['strengths']['PP']['goals'])
                ->toBe($comparison['teams'][$venue]['strengths']['PP']['goals']);
    }
});

it('keeps nonselected skaters in the frozen team Train bucket average', function (): void {
    $transform = function (array $records): array {
        foreach ($records as $id => &$record) {
            if ($id < 2026020000) {
                $record['players'][999]['counts']['EV']['B'] = ['sat' => 3, 'sog' => 3, 'goals' => 3];
            }
        }
        unset($record);

        return $records;
    };
    [$reader, $model, $lineups] = ($this->forecastFixture)('FUT', true, true, $transform);
    $step = ($this->finishQueuedForecast)($reader, $model, $lineups);
    expect($step['errors'])->toBe([]);
    $away = $step['inputs'][2026020002]['teams']['away'];
    expect($away['players'])->not->toHaveKey(999)
        ->and(abs($away['players'][100]['buckets']['EV']['B']['goal_conversion']['probabilities']['team'] - 15 / 57))
            ->toBeLessThan(1e-12);
});

it('uses league fallbacks only for missing EV component denominators and preserves measured zeros', function (): void {
    $method = new \ReflectionMethod(NhlCustomGamePredictionInputs::class, 'evGoalConversion');
    $reader = new NhlCustomGamePredictionInputs();
    $league = ['buckets' => ['B' => ['sat' => 100, 'sog' => 20, 'goals' => 2],
        'Other' => ['sat' => 100, 'sog' => 20, 'goals' => 18]]];
    $missing = $method->invoke($reader, null, [], $league, 'B');
    expect(abs($missing['probability'] - 0.1))->toBeLessThan(1e-12);
    $zero = $method->invoke($reader, ['buckets' => ['B' => ['sat' => 1, 'sog' => 1, 'goals' => 0]]],
        ['buckets' => ['B' => ['sat' => 30, 'sog' => 10, 'goals' => 2]]], $league, 'B');
    expect(abs($zero['probability'] - 0.064))->toBeLessThan(1e-12)
        ->and($zero['evidence']['probabilities']['player'])->toBe(0.0);
    $strengthFallback = $method->invoke($reader, null, [],
        ['buckets' => ['B' => ['sat' => 2, 'sog' => 0, 'goals' => 0],
            'Other' => ['sat' => 100, 'sog' => 20, 'goals' => 2]]], 'B');
    expect(abs($strengthFallback['probability'] - 0.1))->toBeLessThan(1e-12);
});

it('retains frozen team Train bucket evidence across serialized preparation checkpoints', function (): void {
    [$fixture, $model] = ($this->forecastFixture)('FUT', true, false, ($this->bucketReliabilityHistory)(1, 1, 1));
    $fixture->prepare($model, '20262027');
    $records = (new \ReflectionProperty($fixture, 'fixtures'))->getValue($fixture);
    $advance = new \ReflectionMethod(NhlCustomGamePredictionInputs::class, 'advance');
    $reader = new NhlCustomGamePredictionInputs();
    foreach ($records as $id => $record) {
        $game = DB::table('nhl_games')->where('nhl_game_id', $id)->first();
        $advance->invoke($reader, $game, $record, $id < 2026020000);
        $reader = unserialize(serialize($reader));
    }
    $teams = (new \ReflectionProperty(NhlCustomGamePredictionInputs::class, 'teamTrainBuckets'))->getValue($reader);
    expect($teams[1]['buckets']['B'])->toBe(['sat' => 61, 'sog' => 25, 'goals' => 4])
        ->and($teams[2]['buckets']['B'])->toBe(['sat' => 120, 'sog' => 48, 'goals' => 6]);
});

it('rejects an invalid EV conversion blend instead of silently changing the rule', function (): void {
    config(['nhl-custom-prediction.ev_goal_conversion.low_volume_weights' => ['player' => 0.9, 'team' => 0.2, 'league' => 0.4]]);
    $method = new \ReflectionMethod(NhlCustomGamePredictionInputs::class, 'evGoalConversion');
    expect(fn () => $method->invoke(new NhlCustomGamePredictionInputs(), null, [],
        ['buckets' => ['B' => ['sat' => 100, 'sog' => 20, 'goals' => 2]]], 'B'))
        ->toThrow(\DomainException::class, 'sum to one');
});


it('round trips a preparation reader through bounded parts without changing counts or types', function (): void {
    $reader = new NhlCustomGamePredictionInputs();
    $state = $reader->checkpointState();
    $state['train'] = [12 => ['EV' => ['buckets' => ['B' => ['sat' => 12, 'sog' => 5, 'goals' => 0]]]]];
    $state['metadata'] = ['zero' => 0.0, 'unknown' => null, 'empty' => [], 'measured' => false];
    $reader->restoreCheckpointState($state);
    ($this->checkpointWrite)('parts-test', ['fingerprint' => 'fixture', 'phase' => 'inputs', 'reader' => $reader]);
    $manifest = Cache::get('parts-test:work');
    $restored = ($this->checkpointRead)('parts-test');
    expect($manifest['checkpoint_format'])->toBe(1)->and($manifest)->not->toHaveKey('reader')
        ->and($restored['reader']->checkpointState())->toBe($state);
    foreach ($manifest['groups']['core'] as $part) {
        expect($part['bytes'])->toBeLessThanOrEqual(524288);
    }
});

it('splits large strings and nested bucket arrays without a monolithic cached value', function (): void {
    $buckets = [];
    foreach (range(1, 3000) as $id) {
        $buckets[$id] = ['sat' => $id, 'sog' => $id / 2, 'goals' => 0, 'label' => str_repeat('bucket', 50)];
    }
    $work = ['fingerprint' => 'large', 'phase' => 'context',
        'buckets' => $buckets, 'large_string' => str_repeat('history', 150000),
        'nested' => (object) ['nullable' => null, 'empty' => (object) [], 'value' => 0.0]];
    ($this->checkpointWrite)('large-parts', $work);
    $manifest = Cache::get('large-parts:work');
    $read = ($this->checkpointRead)('large-parts');
    unset($read['_checkpoint_manifest']);
    expect($read)->toEqual($work)->and(count($manifest['groups']['core']))->toBeGreaterThan(2)
        ->and($manifest['diagnostics']['max_part_bytes'])->toBeLessThanOrEqual(524288)
        ->and(strlen(serialize($manifest)))->toBeLessThan(32768);
});

it('restores a legacy checkpoint at its cursor before converting it without replay', function (): void {
    $reader = new NhlCustomGamePredictionInputs();
    $state = $reader->checkpointState();
    $state['forecastWork'] = ['phase' => 'history', 'cursor' => 3275, 'contexts' => [],
        'history' => [(object) ['nhl_game_id' => 9]], 'defense' => []];
    $state['teamTrainBuckets'] = [1 => ['buckets' => ['B' => ['sat' => 12, 'sog' => 5, 'goals' => 1]]]];
    $reader->restoreCheckpointState($state);
    Cache::put('legacy-parts:work', ['fingerprint' => 'same', 'phase' => 'inputs', 'reader' => $reader], 21600);
    $old = ($this->checkpointRead)('legacy-parts');
    expect($old['_checkpoint_legacy'])->toBeTrue();
    ($this->checkpointWrite)('legacy-parts', $old);
    $read = ($this->checkpointRead)('legacy-parts');
    expect($read['reader']->checkpointState())->toEqual($reader->checkpointState())
        ->and($read['reader']->checkpointState()['forecastWork']['cursor'])->toBe(3275)
        ->and($read)->not->toHaveKey('_checkpoint_legacy');
});

it('retains fit accumulators and fitted allowance values through partitioned restoration', function (): void {
    $defense = ($this->defenseFixture)();
    $copy = new NhlCustomGameDefense();
    $copy->restoreCheckpointState($defense->checkpointState());
    expect($copy->checkpointState())->toBe($defense->checkpointState())
        ->and($copy->snapshot())->toBe($defense->snapshot())
        ->and($copy->resolveAllowance(2, 'EV', 'B'))->toBe($defense->resolveAllowance(2, 'EV', 'B'));
});

it('produces identical game inputs and engine outcomes across partitioned page boundaries', function (): void {
    [$reader, $model, $lineups] = ($this->forecastFixture)('FUT', true);
    config(['nhl-custom-prediction.batch_games' => 1, 'nhl-custom-prediction.fit_bucket_batch' => 1]);
    $reader->beginQueuedForecast($model, '2026-11-05', $lineups);
    $baseline = clone $reader;
    $baseline->restoreCheckpointState($reader->checkpointState());
    do {
        $expected = $baseline->advanceQueuedForecast();
    } while (! $expected['complete']);
    app()->instance(NhlCustomGamePredictionInputs::class, $reader);
    $work = ['fingerprint' => 'fixed', 'phase' => 'inputs', 'reader' => $reader];
    $phases = [];
    for ($page = 0; $page < 30; $page++) {
        ($this->checkpointWrite)('equivalent', $work);
        $work = ($this->checkpointRead)('equivalent');
        $actual = $work['reader']->advanceQueuedForecast();
        $phases[] = $actual['phase'];
        if ($actual['complete']) {
            break;
        }
    }
    expect($phases)->toContain('collect', 'fit', 'history', 'targets', 'complete')
        ->and($actual['complete'])->toBeTrue()->and($actual['inputs'])->toBe($expected['inputs'])
        ->and($this->engine->predict($actual['inputs'][2026020002]))
        ->toBe($this->engine->predict($expected['inputs'][2026020002]));
});

it('loads only the selected targets saved context while retaining the other targets parts', function (): void {
    $reader = new NhlCustomGamePredictionInputs();
    $state = $reader->checkpointState();
    $state['forecastWork'] = ['phase' => 'history', 'cursor' => 10,
        'targets' => [(object) ['nhl_game_id' => 11], (object) ['nhl_game_id' => 22]],
        'contexts' => [11 => ['players' => [1 => 'first']], 22 => ['players' => [2 => 'second']]]];
    $reader->restoreCheckpointState($state);
    ($this->checkpointWrite)('lazy-contexts', ['fingerprint' => 'fixed', 'phase' => 'inputs', 'reader' => $reader]);
    $work = ($this->checkpointRead)('lazy-contexts');
    $state = $work['reader']->checkpointState();
    expect($state['forecastWork']['contexts'])->toBe([]);
    $state['forecastWork']['phase'] = 'targets';
    $state['forecastWork']['cursor'] = 1;
    $work['reader']->restoreCheckpointState($state);
    ($this->checkpointWrite)('lazy-contexts', $work);
    $read = ($this->checkpointRead)('lazy-contexts');
    expect($read['reader']->checkpointState()['forecastWork']['contexts'])->toBe([22 => ['players' => [2 => 'second']]])
        ->and(array_keys(Cache::get('lazy-contexts:work')['groups']['contexts']))->toBe([11, 22]);
});

it('does not hydrate deferred contexts that are not needed for the current history page', function (): void {
    $reader = new NhlCustomGamePredictionInputs();
    $state = $reader->checkpointState();
    $state['forecastWork'] = ['phase' => 'history', 'cursor' => 4, 'contexts' => [22 => ['players' => [2 => 'deferred']]]];
    $reader->restoreCheckpointState($state);
    ($this->checkpointWrite)('not-needed', ['fingerprint' => 'fixed', 'phase' => 'inputs', 'reader' => $reader]);
    $part = Cache::get('not-needed:work')['groups']['contexts'][22][0];
    Cache::forget($part['key']);
    expect(($this->checkpointRead)('not-needed')['reader']->checkpointState()['forecastWork']['cursor'])->toBe(4);
    expect(fn () => ($this->checkpointWrite)('not-needed', ($this->checkpointRead)('not-needed')))
        ->toThrow(\RuntimeException::class, 'retain deferred');
});

it('rejects missing or corrupt checkpoint parts without publishing guessed state', function (string $failure): void {
    ($this->checkpointWrite)('broken-part', ['fingerprint' => 'same', 'phase' => 'context', 'errors' => []]);
    $part = Cache::get('broken-part:work')['groups']['core'][0];
    if ($failure === 'missing') {
        Cache::forget($part['key']);
    } else {
        Cache::put($part['key'], 'invalid data', 21600);
    }
    expect(fn () => ($this->checkpointRead)('broken-part'))->toThrow(\DomainException::class, 'missing or corrupt');
})->with(['missing', 'corrupt']);

it('keeps the preceding committed cursor when a part or manifest write fails', function (string $failure): void {
    ($this->checkpointWrite)('atomic-parts', ['fingerprint' => 'same', 'phase' => 'context', 'cursor' => 5]);
    $old = Cache::get('atomic-parts:work');
    $work = ($this->checkpointRead)('atomic-parts');
    $work['cursor'] = 6;
    $repository = Cache::store();
    $proxy = Mockery::mock($repository);
    $proxy->shouldReceive('put')->andReturnUsing(function ($key, $value, $ttl) use ($repository, $failure): bool {
        if (($failure === 'manifest' && $key === 'atomic-parts:work')
            || ($failure === 'part' && str_contains($key, ':parts:'))) {
            return false;
        }

        return $repository->put($key, $value, $ttl);
    });
    Cache::swap($proxy);
    try {
        expect(fn () => ($this->checkpointWrite)('atomic-parts', $work))->toThrow(\RuntimeException::class);
    } finally {
        Cache::swap($repository);
    }
    expect(Cache::get('atomic-parts:work'))->toBe($old)
        ->and(($this->checkpointRead)('atomic-parts')['cursor'])->toBe(5);
})->with(['part', 'manifest']);

it('expires abandoned checkpoint parts without evicting a live committed generation', function (): void {
    ($this->checkpointWrite)('retained-parts', ['fingerprint' => 'same', 'phase' => 'context', 'cursor' => 1]);
    $work = ($this->checkpointRead)('retained-parts');
    $oldParts = Cache::get('retained-parts:work')['groups']['core'];
    ($this->checkpointWrite)('retained-parts', [...$work, 'cursor' => 2]);
    foreach ($oldParts as $part) {
        expect(Cache::get($part['key']))->toBeNull();
    }
    expect(($this->checkpointRead)('retained-parts')['cursor'])->toBe(2);
    $this->travel(21601)->seconds();
    expect(($this->checkpointRead)('retained-parts'))->toBeNull();
});

it('records bounded preparation diagnostics without exposing bucket state', function (): void {
    ($this->checkpointWrite)('diagnostic-parts', ['fingerprint' => 'same', 'phase' => 'context']);
    $manifest = Cache::get('diagnostic-parts:work');
    expect($manifest['diagnostics'])->toHaveKeys(['phase', 'cursor', 'part_count', 'max_part_bytes',
        'total_part_bytes', 'peak_memory_bytes', 'checkpoint_write_ms', 'saved_at'])
        ->and($manifest['diagnostics']['phase'])->toBe('context')
        ->and($manifest['diagnostics']['max_part_bytes'])->toBeLessThanOrEqual(524288)
        ->and($manifest['diagnostics'])->not->toHaveKey('reader');
});


it('converts matching operational legacy preparation before advancing another historical page', function (): void {
    [$reader, $model, $lineups] = ($this->forecastFixture)('FUT', true);
    $reader->beginQueuedForecast($model, '2026-11-05', $lineups);
    $state = $reader->checkpointState();
    $state['forecastWork']['phase'] = 'history';
    $state['forecastWork']['cursor'] = 2;
    $reader->restoreCheckpointState($state);
    $forecast = new \App\Services\NhlCustomGameForecast();
    [$key, $fingerprint] = (new ReflectionMethod($forecast, 'preparationIdentity'))->invoke($forecast,
        DB::table('nhl_games')->where('nhl_game_id', 2026020002)->first(), NhlModelRun::findOrFail($model), false, []);
    Cache::put($key.':work', ['fingerprint' => $fingerprint, 'phase' => 'inputs', 'reader' => $reader], 21600);
    expect($forecast->advancePreparation(2026020002, $model))->toBeFalse()
        ->and($forecast->preparationDelay())->toBe(0)
        ->and(Cache::get($key.':work')['checkpoint'])->toBe(['phase' => 'history', 'cursor' => 2])
        ->and(($this->checkpointRead)($key)['reader']->checkpointState())->toEqual($reader->checkpointState());
    Bus::assertNothingDispatched();
});

it('does not decode stale checkpoint parts when source evidence requires a clean rebuild', function (): void {
    ($this->checkpointWrite)('stale-parts', ['fingerprint' => 'old', 'phase' => 'context']);
    $manifest = Cache::get('stale-parts:work');
    Cache::forget($manifest['groups']['core'][0]['key']);
    $forecast = new \App\Services\NhlCustomGameForecast();
    $method = new ReflectionMethod($forecast, 'readPreparationCheckpoint');
    expect($method->invoke($forecast, 'stale-parts', 'new'))->toBeNull()
        ->and(Cache::get('stale-parts:work'))->toBe($manifest);
});

it('requires an intact saved context when its target phase is reached', function (): void {
    $reader = new NhlCustomGamePredictionInputs();
    $state = $reader->checkpointState();
    $state['forecastWork'] = ['phase' => 'targets', 'cursor' => 0,
        'targets' => [(object) ['nhl_game_id' => 22]], 'contexts' => [22 => ['players' => []]]];
    $reader->restoreCheckpointState($state);
    ($this->checkpointWrite)('required-context', ['fingerprint' => 'same', 'phase' => 'inputs', 'reader' => $reader]);
    $part = Cache::get('required-context:work')['groups']['contexts'][22][0];
    Cache::forget($part['key']);
    expect(fn () => ($this->checkpointRead)('required-context'))->toThrow(\DomainException::class, 'missing or corrupt');
});
