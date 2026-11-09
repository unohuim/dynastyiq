<?php

declare(strict_types=1);

use App\Models\ApiClient;
use App\Models\NhlGame;
use App\Models\NhlModelRun;
use App\Models\NhlSatEngine;
use App\Models\NhlSatEngineStack;
use App\Services\NhlCustomGameForecast;
use App\Services\NhlCustomGamePredictionEngine;
use App\Services\NhlGamePredictionPayload;
use App\Services\NhlProjectedTeamMatchupSimulator;
use App\Services\NhlStartingGoalieSelector;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\Fluent\AssertableJson;

beforeEach(function (): void {
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-05 12:00:00 UTC'));
    config(['cache.default' => 'array', 'nhl-custom-prediction' => require base_path('config/nhl-custom-prediction.php')]);
    Cache::flush();
    Bus::fake();
    Http::preventStrayRequests();
    $this->model = NhlModelRun::query()->create(['run_key' => 'default-custom-api', 'name' => 'sat_v2',
        'model_family' => 'sat', 'workflow_stage' => 'training', 'model_version' => 'fixture', 'status' => 'complete',
        'train_season_ids' => ['20232024', '20242025', '20252026']]);
    $this->game = NhlGame::query()->create(['nhl_game_id' => 2026020001, 'season_id' => '20262027',
        'game_type' => 2, 'game_date' => '2026-11-05', 'game_dow' => 'Thursday', 'game_month' => 'November',
        'game_state' => 'FUT', 'start_time_utc' => '2026-11-05 23:00:00',
        'away_team_id' => 1, 'home_team_id' => 2, 'away_team_abbrev' => 'BUF', 'home_team_abbrev' => 'COL']);
    $this->token = 'custom-default-api-fixture';
    $this->client = ApiClient::query()->create(['name' => 'Custom API fixture', 'slug' => 'custom-api',
        'token_hash' => ApiClient::hashToken($this->token), 'scopes' => ['nhl-stats:read']]);
    $this->input = ['game_id' => 2026020001, 'source_cutoff_at' => '2026-11-05 12:00:00',
        'retained_bucket_keys' => ['B'], 'league_usage_seconds' => ['EV' => 600.0, 'PP' => 60.0],
        'league_pk_probability' => 0.8, 'teams' => []];
    foreach (['away' => 1, 'home' => 2] as $venue => $team) {
        $side = ['id' => $team, 'abbrev' => $venue === 'away' ? 'BUF' : 'COL',
            'lineup_source' => 'anticipated_lineup', 'context_id' => null, 'starting_goalie_id' => null,
            'train_opportunity_share' => 0.5, 'pk_opportunities_l10' => 3.0,
            'pp_offense_l20' => 0.8, 'pp_allowed_l5' => 0.6, 'pk_probability' => 0.8,
            'schedule' => ['back_to_back' => false, 'games_including_target_last_7_days' => 2], 'players' => []];
        foreach (['F', 'D'] as $slot => $role) {
            $id = $team * 100 + $slot;
            $player = ['name' => 'Fixture '.$id, 'role' => $role, 'usage' => ['EV' => 600.0, 'PP' => 60.0],
                'complete' => ['EV' => true, 'PP' => true], 'buckets' => []];
            foreach (['EV' => ($venue === 'home' ? 14.0 : 12.0), 'PP' => 24.0] as $lane => $rate) {
                $player['buckets'][$lane]['B'] = ['sat_per_60' => $rate, 'defense_allowance' => 1.0,
                    'defense_reference' => 1.0, 'sog_probability' => 0.5, 'goal_probability' => 0.1];
            }
            $side['players'][$id] = $player;
        }
        $this->input['teams'][$venue] = $side;
    }
    $this->calculate = fn (): array => (new NhlCustomGamePredictionEngine())->predict($this->input);
    $service = Mockery::mock(NhlCustomGameForecast::class)->makePartial();
    $service->shouldReceive('predict')->andReturnUsing(fn (): array => [
        'game' => (object) $this->game->fresh()->getAttributes(), 'model' => $this->model,
        'input' => $this->input, 'metadata' => ['context_version' => 'fixture', 'coefficients_transferred' => true],
        'prediction' => ($this->calculate)(),
    ]);
    app()->instance(NhlCustomGameForecast::class, $service);
    $goalies = Mockery::mock(NhlStartingGoalieSelector::class);
    $goalies->shouldReceive('selectForPrediction')->andReturn(null);
    app()->instance(NhlStartingGoalieSelector::class, $goalies);
    $this->requestPrediction = fn (array $query = []) => $this->withToken($this->token)->getJson(
        '/api/nhl-game-predictions?'.http_build_query(['nhl_game_id' => 2026020001, ...$query])
    );
    $this->predictionRequest = function (array $query = [], float $awayGoals = 2.4, float $homeGoals = 3.2) {
        $current = ($this->calculate)();
        foreach (['away' => $awayGoals, 'home' => $homeGoals] as $venue => $goals) {
            $rate = ($goals - $current['teams'][$venue]['strengths']['PP']['goals']) * 3600
                / (5 * $current['teams'][$venue]['time']['EV'] * 0.5 * 0.1);
            foreach ($this->input['teams'][$venue]['players'] as &$player) {
                $player['buckets']['EV']['B']['sat_per_60'] = $rate;
            }
            unset($player);
        }

        return ($this->requestPrediction)($query);
    };
});

afterEach(function (): void {
    $this->travelBack();
});

it('requires API authentication for new engine predictions', function (): void {
    $this->getJson('/api/nhl-game-predictions?nhl_game_id=2026020001')->assertUnauthorized();
});

it('requires the NHL statistics scope for new engine predictions', function (): void {
    $this->client->update(['scopes' => ['nhl-reference:read']]);
    ($this->requestPrediction)()->assertForbidden();
});

it('requires a game identifier', function (): void {
    $this->withToken($this->token)->getJson('/api/nhl-game-predictions')->assertUnprocessable();
});

it('rejects nonnumeric game identifiers', function (): void {
    ($this->requestPrediction)(['nhl_game_id' => 'bad'])->assertUnprocessable();
});

it('rejects unsupported market keys', function (): void {
    ($this->requestPrediction)(['markets' => ['bad']])->assertUnprocessable();
});

it('rejects scalar market lists', function (): void {
    ($this->requestPrediction)(['markets' => 'moneyline'])->assertUnprocessable();
});

it('rejects zero puckline spreads', function (): void {
    ($this->requestPrediction)(['puckline' => 0])->assertUnprocessable();
});

it('rejects nonpositive totals', function (): void {
    ($this->requestPrediction)(['total' => 0])->assertUnprocessable();
});

it('uses the new shared engine and SAT_V2 for an ordinary API request', function (): void {
    ($this->requestPrediction)()->assertOk()->assertJsonPath('prediction_available', true)
        ->assertJsonPath('inputs.sat_model_run_id', $this->model->id)
        ->assertJsonPath('inputs.algorithm_version', 'custom_ev_pp_v3')
        ->assertJsonPath('inputs.engine_id', null)->assertJsonPath('inputs.stack_id', null);
});

it('predicts every requested game state without using target scores', function (string $state): void {
    $this->game->update(['game_state' => $state, 'away_team_score' => 99, 'home_team_score' => 0]);
    ($this->requestPrediction)()->assertOk()->assertJsonPath('prediction_available', true)
        ->assertJsonPath('prediction.winner.team_abbrev', 'COL');
})->with(['FUT', 'LIVE', 'FINAL', 'OFF', 'PPD', 'CNCL']);

it('includes prior season form and the last ten cross-season meetings in API Matchup', function (): void {
    $games = [];
    foreach (range(1, 11) as $day) {
        $games[] = ['nhl_game_id' => 2025020100 + $day, 'season_id' => '20252026', 'game_type' => 2,
            'game_date' => '2026-03-'.str_pad((string) $day, 2, '0', STR_PAD_LEFT), 'game_state' => 'OFF',
            'home_team_id' => 2, 'away_team_id' => 1, 'home_team_score' => 1, 'away_team_score' => 2];
    }
    $games[] = ['nhl_game_id' => 2026020002, 'season_id' => '20262027', 'game_type' => 2,
        'game_date' => '2026-11-04', 'game_state' => 'OFF', 'home_team_id' => 2, 'away_team_id' => 1,
        'home_team_score' => 4, 'away_team_score' => 2];
    DB::table('nhl_games')->insert(array_map(static fn (array $row): array => [...$row,
        'game_dow' => \Carbon\CarbonImmutable::parse($row['game_date'])->format('D'),
        'game_month' => \Carbon\CarbonImmutable::parse($row['game_date'])->format('M'),
        'start_time_utc' => $row['game_date'].' 23:00:00'], $games));
    DB::table('nhl_games')->where('nhl_game_id', 2026020002)->update([
        'home_pp_goals' => 1, 'home_pp_opportunities' => 4, 'away_pp_goals' => 1, 'away_pp_opportunities' => 5,
    ]);
    $reader = Mockery::mock(\App\Services\NhlStatsDashboardPayload::class);
    $reader->shouldNotReceive('teamExpectationsForGames');
    app()->instance(\App\Services\NhlStatsDashboardPayload::class, $reader);
    $first = ($this->requestPrediction)()->assertOk()
        ->assertJsonPath('matchup.through_date', '2026-11-04')->assertJsonPath('matchup.head_to_head_limit', 10)
        ->assertJsonPath('matchup.away.team_id', 1)->assertJsonPath('matchup.home.team_abbrev', 'COL')
        ->assertJsonPath('matchup.home.form.gp', 1)->assertJsonPath('matchup.home.form.wins', 1)
        ->assertJsonPath('matchup.home.form.h2h_gp', 10)->assertJsonPath('matchup.home.form.h2h_losses', 9)
        ->assertJsonPath('matchup.home.form.gf_gp', 4)->assertJsonPath('matchup.home.form.ga_gp', 2)
        ->assertJsonPath('matchup.home.form.pp_pct', 25)->assertJsonPath('matchup.home.form.pk_pct', 80)
        ->assertJsonPath('matchup.home.form.pp_opps_gp', 4)->assertJsonPath('matchup.home.form.pp_opps_allowed_gp', 5)
        ->assertJsonPath('matchup.home.form.xgf_gp', null)->assertJsonPath('matchup.expected_pending', true);
    $id = $first->json('meta.prediction_revision_id');
    $this->assertDatabaseHas('nhl_game_prediction_revisions', ['id' => $id, 'nhl_game_id' => 2026020001]);
    expect(json_decode(DB::table('nhl_game_prediction_revisions')->where('id', $id)->value('payload'), true))
        ->not->toHaveKey('matchup');
    ($this->requestPrediction)()->assertOk()->assertJsonPath('meta.prediction_revision_id', $id)
        ->assertJsonPath('matchup.home.form.pp_opps_allowed_gp', 5);
    $this->assertDatabaseCount('nhl_game_prediction_revisions', 1);
    Bus::assertDispatchedTimes(\App\Jobs\RefreshNhlPublicGamePageJob::class, 1);
    Http::assertNothingSent();
});

it('includes pregame Matchup for live and finished prediction requests without target results', function (string $state): void {
    $this->game->update(['game_state' => $state, 'home_team_score' => 99, 'away_team_score' => 0]);
    DB::table('nhl_games')->insert(['nhl_game_id' => 2026020003, 'season_id' => '20262027', 'game_type' => 2,
        'game_date' => '2026-11-06', 'game_dow' => 'Fri', 'game_month' => 'Nov', 'game_state' => 'OFF', 'home_team_id' => 2, 'away_team_id' => 1,
        'home_team_score' => 20, 'away_team_score' => 0]);
    ($this->requestPrediction)()->assertOk()->assertJsonPath('matchup.home.form.gp', 0)
        ->assertJsonPath('matchup.home.form.gf_gp', null)->assertJsonPath('matchup.home.form.h2h_gp', 0)
        ->assertJsonPath('matchup.expected_pending', false);
    Bus::assertNotDispatched(\App\Jobs\RefreshNhlPublicGamePageJob::class);
    Http::assertNothingSent();
})->with(['LIVE', 'FINAL', 'OFF']);

it('completes missing observed player evidence instead of rejecting the game', function (): void {
    foreach ($this->input['teams'] as &$side) {
        foreach ($side['players'] as &$player) {
            $player['complete'] = ['EV' => false, 'PP' => false];
            $player['usage'] = ['EV' => null, 'PP' => null];
        }
        unset($player);
    }
    unset($side);
    ($this->requestPrediction)()->assertOk()->assertJsonPath('prediction_available', true)
        ->assertJsonPath('prediction.confidence_inputs.input_completeness.EV', 0.0)
        ->assertJsonPath('prediction.confidence_inputs.effective_input_completeness.EV', 1.0);
});

it('preserves measured zero player usage while predicting the full game', function (): void {
    $this->input['teams']['away']['players'][100]['usage']['EV'] = 0.0;
    ($this->requestPrediction)()->assertOk()->assertJsonPath('teams.away.roster.0.strengths.EV.seconds', 0.0);
});

it('returns predictions without any saved legacy stack', function (): void {
    expect(NhlSatEngineStack::query()->count())->toBe(0);
    ($this->requestPrediction)()->assertOk()->assertJsonPath('prediction_available', true);
});

it('does not dilute new goals using saved legacy engine percentages', function (): void {
    $engine = NhlSatEngine::query()->create(['name' => 'Old percentages', 'model_run_id' => $this->model->id,
        'settings' => ['offense' => 200, 'defense' => 200, 'confidence_min' => 99]]);
    $stack = NhlSatEngineStack::query()->create(['name' => 'Old default', 'is_default' => true]);
    $stack->members()->create(['engine_id' => $engine->id, 'priority' => 1]);
    $expected = ($this->calculate)();
    ($this->requestPrediction)()->assertOk()
        ->assertJsonPath('prediction.predicted_score.home', round($expected['teams']['home']['goals'], 2))
        ->assertJsonPath('pick_qualified', true);
});

it('returns internal input confidence separately from winning probability', function (): void {
    ($this->requestPrediction)()->assertOk()->assertJsonPath('prediction.confidence_score', 50)
        ->assertJsonPath('market_probabilities.0.confidence_score', 50);
});

it('publishes numeric internal confidence in the API and immutable revision without changing the pick', function (): void {
    $before = ($this->calculate)();
    foreach (['away', 'home'] as $venue) {
        foreach ($this->input['teams'][$venue]['players'] as &$player) {
            $player['confidence_score'] = 0.8;
        }
        unset($player);
        $this->input['teams'][$venue]['goalie_confidence_buckets'] = ['B' => ['confidence_score' => 0.6, 'source_sat' => 100]];
    }
    $response = ($this->requestPrediction)()->assertOk()->assertJsonPath('prediction.confidence_score', 74)
        ->assertJsonPath('market_probabilities.0.confidence_score', 74)
        ->assertJsonPath('teams.away.roster.0.confidence_score', 0.8)
        ->assertJsonPath('prediction.home_win_probability', $before['home_win_probability'])
        ->assertJsonPath('prediction.predicted_total_goals', round($before['total_goals'], 2));
    $row = DB::table('nhl_game_prediction_revisions')->find($response->json('meta.prediction_revision_id'));
    expect(json_decode($row->summary, true)['confidence'])->toBe(74)
        ->and(json_decode($row->evidence, true)['custom_prediction']['input']['teams']['away']['players'][100]['confidence_score'])
        ->toBe(0.8);
});

it('uses the engines exact winning probability including its schedule adjustment', function (): void {
    $this->input['teams']['away']['schedule'] = ['back_to_back' => true, 'games_including_target_last_7_days' => 4];
    $expected = ($this->calculate)();
    $rows = collect(($this->requestPrediction)()->assertOk()->json('market_probabilities'))->keyBy('selection_key');
    expect($rows['home']['probability'])->toEqualWithDelta($expected['home_win_probability'], 0.000001)
        ->and($rows['away']['probability'] + $rows['home']['probability'])->toEqualWithDelta(1.0, 0.000001);
});

it('preserves the computed game total without applying legacy normalization again', function (): void {
    $expected = ($this->calculate)();
    ($this->requestPrediction)()->assertOk()->assertJsonPath('prediction.predicted_total_goals', round($expected['total_goals'], 2));
});

it('returns only moneyline markets by default', function (): void {
    $rows = ($this->requestPrediction)()->assertOk()->json('market_probabilities');
    expect($rows)->toHaveCount(2)->and(array_unique(array_column($rows, 'market_key')))->toBe(['moneyline']);
});

it('returns requested puckline markets from the new goal distribution', function (): void {
    $rows = ($this->requestPrediction)(['markets' => ['puckline'], 'puckline' => 1.5])->assertOk()->json('market_probabilities');
    expect($rows)->toHaveCount(2)->and(array_unique(array_column($rows, 'market_key')))->toBe(['puckline']);
});

it('returns requested total markets from the new goal distribution', function (): void {
    $rows = ($this->requestPrediction)(['markets' => ['total'], 'total' => 6])->assertOk()->json('market_probabilities');
    expect($rows)->toHaveCount(2)->and(array_column($rows, 'selection_key'))->toBe(['over', 'under']);
});

it('returns complementary total probabilities including pushes', function (): void {
    foreach (($this->requestPrediction)(['markets' => ['total'], 'total' => 6])->assertOk()->json('market_probabilities') as $row) {
        expect($row['probability'] + $row['push_probability'] + $row['loss_probability'])->toEqualWithDelta(1.0, 0.000002);
    }
});

it('retains canonical NHL identities on both roster views', function (): void {
    ($this->requestPrediction)()->assertOk()->assertJsonPath('teams.away.roster.0.nhl_player_id', 100)
        ->assertJsonPath('teams.home.roster.0.nhl_player_id', 200)
        ->assertJsonPath('teams.away.dressed_roster.0.nhl_player_id', 100);
});

it('feeds the goalie section the opposing new SOG and goal projections', function (): void {
    $expected = ($this->calculate)();
    ($this->requestPrediction)()->assertOk()
        ->assertJsonPath('goalies.away.matchup_sog_against_per_game', $expected['teams']['home']['sog'])
        ->assertJsonPath('goalies.away.goal_adjustment_applied', false)
        ->assertJsonPath('prediction.goalie_edge.available', false);
});

it('archives exact private inputs without exposing them in the API response', function (): void {
    $response = ($this->requestPrediction)()->assertOk()->assertJsonMissingPath('_custom_prediction_evidence');
    $revision = DB::table('nhl_game_prediction_revisions')->find($response->json('meta.prediction_revision_id'));
    $evidence = json_decode($revision->evidence, true);
    expect($evidence['custom_prediction']['input'])->toEqual($this->input)
        ->and(json_decode($revision->payload, true))->not->toHaveKey('_custom_prediction_evidence');
});

it('reuses a fresh response without appending another revision', function (): void {
    $first = ($this->requestPrediction)()->assertOk()->json('meta.prediction_revision_id');
    ($this->requestPrediction)()->assertOk()->assertJsonPath('meta.prediction_revision_id', $first);
    expect(DB::table('nhl_game_prediction_revisions')->count())->toBe(1);
});

it('appends a fresh immutable revision when explicitly refreshed', function (): void {
    $first = ($this->requestPrediction)()->assertOk()->json('meta.prediction_revision_id');
    $next = ($this->requestPrediction)(['force_refresh' => true])->assertOk()->json('meta.prediction_revision_id');
    expect($next)->toBeGreaterThan($first)->and(DB::table('nhl_game_prediction_revisions')->count())->toBe(2);
});

it('invalidates cached responses when the new algorithm configuration changes', function (): void {
    $first = ($this->requestPrediction)()->assertOk()->json('meta.prediction_revision_id');
    config(['nhl-custom-prediction.opponent_opportunity_weight' => 0.5]);
    $next = ($this->requestPrediction)()->assertOk()->json('meta.prediction_revision_id');
    expect($next)->toBeGreaterThan($first);
});

it('retains explicit goalie request variants separately from canonical results', function (): void {
    ($this->requestPrediction)()->assertOk();
    ($this->requestPrediction)(['home_goalie_id' => 999])->assertOk();
    expect(DB::table('nhl_game_prediction_revisions')->where('is_canonical', true)->count())->toBe(1)
        ->and(DB::table('nhl_game_prediction_revisions')->where('is_canonical', false)->count())->toBe(1);
});

it('uses the same new calculation in individual engine previews', function (): void {
    $engine = NhlSatEngine::query()->create(['name' => 'Old container', 'model_run_id' => $this->model->id,
        'settings' => ['offense' => 200, 'defense' => 200]]);
    $preview = app(NhlGamePredictionPayload::class)->previewEngine(2026020001, $engine, null);
    expect($preview['prediction']['predicted_total_goals'])->toBe(round(($this->calculate)()['total_goals'], 2))
        ->and($preview['prediction']['confidence_score'])->toBe(50);
});

it('uses the same new calculation in empty saved stack previews', function (): void {
    $stack = NhlSatEngineStack::query()->create(['name' => 'Empty old container']);
    $preview = app(NhlGamePredictionPayload::class)->previewStack(2026020001, $stack);
    expect($preview['prediction']['predicted_total_goals'])->toBe(round(($this->calculate)()['total_goals'], 2));
});

it('routes the standalone matchup simulator through the same new engine', function (): void {
    $result = app(NhlProjectedTeamMatchupSimulator::class)->simulate('20252026', '20262027', '', '', '', 'BUF', 'COL');
    expect($result['inputs']['algorithm_version'])->toBe('custom_ev_pp_v3')
        ->and($result['sides'][0]['summary']['total_goalie_adjusted_xgf_per_game'])->toBe(($this->calculate)()['teams']['away']['goals']);
});

it('returns current algorithm provenance in saved revision summaries', function (): void {
    $response = ($this->requestPrediction)()->assertOk();
    $row = DB::table('nhl_game_prediction_revisions')->find($response->json('meta.prediction_revision_id'));
    expect(json_decode($row->summary, true)['algorithm_version'])->toBe('custom_ev_pp_v3');
});

it('reuses a two hour old API revision with a queued refresh and replaces it after three hours', function (): void {
    $first = ($this->requestPrediction)()->assertOk()->json('meta.prediction_revision_id');
    $this->travel(7200)->seconds();
    ($this->requestPrediction)()->assertOk()->assertJsonPath('meta.cache', 'stale')
        ->assertJsonPath('meta.prediction_revision_id', $first);
    $this->assertDatabaseCount('nhl_game_prediction_revisions', 1);
    Bus::assertDispatchedTimes(\App\Jobs\RefreshNhlGamePredictionJob::class, 1);
    $this->travel(3601)->seconds();
    $second = ($this->requestPrediction)()->assertOk()->assertJsonPath('meta.cache', 'fresh')
        ->json('meta.prediction_revision_id');
    expect($second)->not->toBe($first);
    $this->assertDatabaseCount('nhl_game_prediction_revisions', 2);
});

it('publishes L5 adjusted scores and probabilities while archiving its source evidence privately', function (): void {
    $this->input['teams']['home']['team_metrics']['last_5'] = ['games' => 5, 'win_pct' => 60];
    $this->input['teams']['away']['team_metrics']['last_5'] = ['games' => 5, 'win_pct' => 40];
    $expected = ($this->calculate)();
    $response = ($this->requestPrediction)()->assertOk()
        ->assertJsonPath('inputs.algorithm_version', 'custom_ev_pp_v3')
        ->assertJsonPath('inputs.algorithm_definition.team_l5_win_pct_weight', 0.5)
        ->assertJsonPath('prediction.predicted_score.home', round($expected['teams']['home']['goals'], 2))
        ->assertJsonPath('prediction.home_win_probability', $expected['home_win_probability'])
        ->assertJsonMissingPath('_custom_prediction_evidence');
    $revision = DB::table('nhl_game_prediction_revisions')->find($response->json('meta.prediction_revision_id'));
    $evidence = json_decode($revision->evidence, true)['custom_prediction'];
    expect($evidence['team_form_adjustment'])->toEqual($expected['team_form_adjustment'])
        ->and($evidence['input']['teams']['home']['team_metrics']['last_5']['win_pct'])->toBe(60);
});

it('invalidates earlier algorithm cache entries while retaining the previous immutable revision', function (): void {
    $this->input['teams']['home']['team_metrics']['last_5'] = ['games' => 5, 'win_pct' => 60];
    $this->input['teams']['away']['team_metrics']['last_5'] = ['games' => 5, 'win_pct' => 40];
    config(['nhl-custom-prediction.algorithm_version' => 'custom_ev_pp_v2',
        'nhl-custom-prediction.team_l5_win_pct_weight' => 0]);
    $first = ($this->requestPrediction)()->assertOk()->json('meta.prediction_revision_id');
    $original = DB::table('nhl_game_prediction_revisions')->find($first);
    config(['nhl-custom-prediction.algorithm_version' => 'custom_ev_pp_v3',
        'nhl-custom-prediction.team_l5_win_pct_weight' => 0.50]);
    $second = ($this->requestPrediction)()->assertOk()
        ->assertJsonPath('inputs.algorithm_version', 'custom_ev_pp_v3')->json('meta.prediction_revision_id');
    expect($second)->not->toBe($first)
        ->and(DB::table('nhl_game_prediction_revisions')->where('id', $first)->value('payload'))->toBe($original->payload)
        ->and(json_decode(DB::table('nhl_game_prediction_revisions')->where('id', $first)->value('summary'), true)['algorithm_version'])
        ->toBe('custom_ev_pp_v2');
    $this->assertDatabaseCount('nhl_game_prediction_revisions', 2);
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

it('returns null fair odds for certain or impossible market outcomes', function (): void {
    $service = app(\App\Services\NhlGamePredictionPayload::class);
    $moneyline = new ReflectionMethod($service, 'moneylineMarketProbability');
    $line = new ReflectionMethod($service, 'lineMarketProbability');

    $impossibleMoneyline = $moneyline->invoke($service, 'away', 'BUF', 0.0, 60);
    $certainMoneyline = $moneyline->invoke($service, 'home', 'COL', 1.0, 60);
    $impossibleTotal = $line->invoke($service, 'total', 'over', null, 6.5,
        ['win' => 0.0, 'push' => 0.0, 'loss' => 1.0], 60, 'projected_total_distribution');

    expect($impossibleMoneyline['fair_odds_american'])->toBeNull()
        ->and($impossibleMoneyline['fair_odds_decimal'])->toBeNull()
        ->and($certainMoneyline['fair_odds_american'])->toBeNull()
        ->and($certainMoneyline['fair_odds_decimal'])->toBeNull()
        ->and($impossibleTotal['fair_odds_american'])->toBeNull()
        ->and($impossibleTotal['fair_odds_decimal'])->toBeNull();
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

    expect($rows->firstWhere('selection_key', 'away')['team_abbrev'])->toBe('BUF')
        ->and($rows->firstWhere('selection_key', 'home')['team_abbrev'])->toBe('COL');
});

it('includes fair odds and model metadata for line-dependent rows', function (): void {
    ($this->predictionRequest)(['markets' => ['puckline', 'total']])
        ->assertOk()
        ->assertJson(fn (AssertableJson $json) => $json
            ->has('market_probabilities.0.fair_odds_american')
            ->has('market_probabilities.0.fair_odds_decimal')
            ->where('market_probabilities.0.model.source', 'prediction.selection_score')
            ->where('market_probabilities.0.model.includes_overtime', true)
            ->etc()
        );
});


it('returns an explicit preparing response and queues automatic prediction continuation', function (): void {
    $service = Mockery::mock(NhlCustomGameForecast::class)->makePartial();
    $service->shouldReceive('predict')->andThrow(new \App\Exceptions\NhlGamePredictionPreparing(2026020001));
    app()->instance(NhlCustomGameForecast::class, $service);
    ($this->requestPrediction)()->assertStatus(202)->assertHeader('Retry-After', '15')
        ->assertJsonPath('status', 'preparing')->assertJsonPath('prediction_available', false)
        ->assertJsonPath('nhl_game_id', 2026020001)->assertJsonMissingPath('prediction.predicted_score');
    $this->assertDatabaseCount('nhl_game_prediction_revisions', 0);
    Bus::assertDispatched(\App\Jobs\RefreshNhlGamePredictionJob::class,
        fn ($job): bool => $job->gameId === 2026020001 && $job->queue === 'lineups');
});

it('coalesces repeated pending API requests without saving placeholder predictions', function (): void {
    $service = Mockery::mock(NhlCustomGameForecast::class)->makePartial();
    $service->shouldReceive('predict')->andThrow(new \App\Exceptions\NhlGamePredictionPreparing(2026020001));
    app()->instance(NhlCustomGameForecast::class, $service);
    foreach (range(1, 3) as $attempt) {
        ($this->requestPrediction)()->assertStatus(202)->assertJsonPath('status', 'preparing');
    }
    Bus::assertDispatchedTimes(\App\Jobs\RefreshNhlGamePredictionJob::class, 1);
    $this->assertDatabaseCount('nhl_game_prediction_revisions', 0);
});

it('automatically saves and publishes the prediction when queued dependencies become ready', function (): void {
    $ready = false;
    $service = Mockery::mock(NhlCustomGameForecast::class)->makePartial();
    $service->shouldReceive('predict')->andReturnUsing(function () use (&$ready): array {
        if (! $ready) {
            throw new \App\Exceptions\NhlGamePredictionPreparing(2026020001);
        }

        return ['game' => (object) $this->game->getAttributes(), 'model' => $this->model,
            'input' => $this->input, 'metadata' => ['context_version' => 'fixture'], 'prediction' => ($this->calculate)()];
    });
    app()->instance(NhlCustomGameForecast::class, $service);
    ($this->requestPrediction)()->assertStatus(202);
    $ready = true;
    $job = Bus::dispatched(\App\Jobs\RefreshNhlGamePredictionJob::class)->first();
    $job->handle(app(\App\Services\NhlGamePredictionResponseCache::class), app(NhlGamePredictionPayload::class));
    $this->assertDatabaseCount('nhl_game_prediction_revisions', 1);
    ($this->requestPrediction)()->assertOk()->assertJsonPath('prediction_available', true)
        ->assertJsonPath('inputs.algorithm_version', 'custom_ev_pp_v3');
    $this->assertDatabaseCount('nhl_game_prediction_revisions', 1);
});

it('releases pending refresh dependencies without exhausting delivery attempts', function (): void {
    $builder = Mockery::mock(NhlGamePredictionPayload::class);
    $builder->shouldReceive('build')->once()->andThrow(new \App\Exceptions\NhlGamePredictionPreparing(2026020001));
    $queue = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
    $queue->shouldReceive('release')->once()->with(15);
    $job = new \App\Jobs\RefreshNhlGamePredictionJob(2026020001, ['nhl_game_id' => 2026020001], 'initial');
    $job->setJob($queue);
    $deadline = $job->retryUntil();
    $job->handle(app(\App\Services\NhlGamePredictionResponseCache::class), $builder);
    $this->travel(30)->minutes();
    expect($job->tries)->toBe(0)->and($job->retryUntil())->toEqual($deadline);
    $this->assertDatabaseCount('nhl_game_prediction_revisions', 0);
});


it('admits a new refresh after a terminal queued refresh failure', function (): void {
    $service = Mockery::mock(NhlCustomGameForecast::class)->makePartial();
    $service->shouldReceive('predict')->andThrow(new \App\Exceptions\NhlGamePredictionPreparing(2026020001));
    app()->instance(NhlCustomGameForecast::class, $service);
    ($this->requestPrediction)()->assertStatus(202);
    $job = Bus::dispatched(\App\Jobs\RefreshNhlGamePredictionJob::class)->first();
    $job->failed(new \RuntimeException('Worker deadline'));
    ($this->requestPrediction)()->assertStatus(202);
    Bus::assertDispatchedTimes(\App\Jobs\RefreshNhlGamePredictionJob::class, 2);
    $this->assertDatabaseCount('nhl_game_prediction_revisions', 0);
});


it('retains a published revision after timestamp only syncs and rebuilds after an actual source correction', function (): void {
    DB::table('nhl_games')->insert(['nhl_game_id' => 2025020001, 'season_id' => '20252026', 'game_type' => 2,
        'game_date' => '2025-11-01', 'game_dow' => 'Saturday', 'game_month' => 'November',
        'game_state' => 'OFF', 'start_time_utc' => '2025-11-01 23:00:00', 'home_team_score' => 3, 'away_team_score' => 2]);
    DB::table('nhl_game_summaries')->insert(['nhl_game_id' => 2025020001, 'nhl_player_id' => 101,
        'nhl_team_id' => 1, 'g' => 1, 'updated_at' => now()->subHour()]);
    $first = ($this->requestPrediction)()->assertOk()->json('meta.prediction_revision_id');
    DB::table('nhl_games')->where('nhl_game_id', 2025020001)->update(['updated_at' => now(), 'boxscore_synced_at' => now()]);
    DB::table('nhl_games')->where('nhl_game_id', 2026020001)->update(['updated_at' => now(), 'boxscore_synced_at' => now()]);
    DB::table('nhl_game_summaries')->where('nhl_game_id', 2025020001)->update(['updated_at' => now()]);
    ($this->requestPrediction)()->assertOk()->assertJsonPath('meta.prediction_revision_id', $first);
    $this->assertDatabaseCount('nhl_game_prediction_revisions', 1);
    $this->travel(1)->seconds();
    DB::table('nhl_game_summaries')->where('nhl_game_id', 2025020001)->update(['g' => 2, 'updated_at' => now()]);
    $second = ($this->requestPrediction)()->assertOk()->json('meta.prediction_revision_id');
    expect($second)->not->toBe($first);
    $this->assertDatabaseCount('nhl_game_prediction_revisions', 2);
});
