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
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-05 12:00:00 UTC'));
    Http::preventStrayRequests();
    config(['nhl-custom-prediction' => require base_path('config/nhl-custom-prediction.php')]);
    $this->engine = new NhlCustomGamePredictionEngine();
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
    $this->forecastFixture = function (string $state = 'FUT'): array {
        $model = NhlModelRun::query()->create(['run_key' => 'future-custom', 'name' => 'SAT_V2 fixture',
            'model_family' => 'sat', 'workflow_stage' => 'training', 'model_version' => 'fixture', 'status' => 'complete',
            'train_season_ids' => ['20232024', '20242025', '20252026'], 'target_season_id' => null]);
        $builder = Mockery::mock(NhlPregameContextBuilder::class);
        $builder->shouldReceive('evaluationBucketFilter')->once()->with($model->id, ['20232024', '20242025', '20252026'])
            ->andReturn(['keys' => ['B'], 'feature_config' => [], 'sat_model_id' => 4, 'minimum_confidence' => 0.5]);
        $builder->shouldReceive('predictionTeamEvidence')->twice()->andReturn(['metrics' => [], 'opponent_metrics' => [],
            'schedule' => ['back_to_back' => false, 'games_including_target_last_7_days' => 2]]);
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
            $records[$id] = $record;
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

        return [$reader, $model->id, [2026020002 => $lineup]];
    };
    $this->testModel = null;
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

it('keeps win probability separate from uncalibrated confidence', function (): void {
    $result = $this->engine->predict($this->input);
    expect($result['home_win_probability'])->toBeGreaterThan(0.5)->and($result['confidence_score'])->toBeNull();
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

it('reuses prepared date inputs for separate game requests without replaying history twice', function (): void {
    config(['cache.default' => 'array']);
    \Illuminate\Support\Facades\Cache::flush();
    $model = NhlModelRun::query()->create(['run_key' => 'shared-date-inputs', 'name' => 'sat_v2',
        'model_family' => 'sat', 'workflow_stage' => 'training', 'model_version' => 'fixture', 'status' => 'complete',
        'train_season_ids' => ['20232024', '20242025', '20252026']]);
    $events = [];
    foreach ([2026020001, 2026020002] as $id) {
        NhlGame::query()->create(['nhl_game_id' => $id, 'season_id' => '20262027', 'game_type' => 2,
            'game_date' => '2026-11-05', 'game_dow' => 'Thursday', 'game_month' => 'November',
            'game_state' => 'FUT', 'start_time_utc' => '2026-11-05 23:00:00',
            'away_team_id' => 1, 'home_team_id' => 2, 'away_team_abbrev' => 'BUF', 'home_team_abbrev' => 'COL']);
        $input = $this->input;
        $input['game_id'] = $id;
        $events[] = ['game' => (object) ['nhl_game_id' => $id], 'input' => $input, 'error' => null];
    }
    $reader = Mockery::mock(NhlCustomGamePredictionInputs::class);
    $reader->shouldReceive('anticipatedParticipants')->twice()->andReturn([]);
    $reader->shouldReceive('forecasts')->once()->with($model->id, '2026-11-05', [2026020001 => [], 2026020002 => []])
        ->andReturnUsing(function () use ($events): \Generator {
            yield from $events;
        });
    $reader->shouldReceive('metadata')->once()->andReturn(['model_id' => $model->id]);
    app()->instance(NhlCustomGamePredictionInputs::class, $reader);
    $service = new \App\Services\NhlCustomGameForecast();
    expect($service->predict(2026020001)['prediction']['game_id'])->toBe(2026020001)
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
        ->expectsOutputToContain('--date is a read-only prediction mode')->assertFailed();
})->with([['--save', true], ['--compare-schedule', true], ['--from', '2026-11-01'], ['--to', '2026-11-05']]);

it('prints requested-date predictions through the registered custom command', function (): void {
    $reader = Mockery::mock(NhlCustomGamePredictionInputs::class);
    $event = ($this->event)($this->input);
    $reader->shouldReceive('forecasts')->once()->with(2, '2026-11-05', [], null)
        ->andReturnUsing(function () use ($event): \Generator {
            yield $event;
        });
    $reader->shouldReceive('metadata')->andReturn(['model_id' => 2]);
    app()->instance(NhlCustomGamePredictionInputs::class, $reader);
    $this->artisan('nhl:custom-predict', ['--model' => 2, '--date' => '2026-11-05', '--json' => true])
        ->expectsOutputToContain('"date": "2026-11-05"')->expectsOutputToContain('"recommendation"')->assertSuccessful();
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
