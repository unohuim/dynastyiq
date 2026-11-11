<?php

declare(strict_types=1);

use App\Jobs\RefreshNhlPublicGamePageJob;
use App\Models\NhlGame;
use App\Models\User;
use App\Services\NhlAnticipatedLineupPayload;
use App\Services\NhlGameLiveContext;
use App\Services\NhlGamePredictionHistory;
use App\Services\NhlPublicGamePage;
use App\Services\NhlStartingGoalieSelector;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-27 12:00:00 UTC'));
    config(['cache.default' => 'array']);
    Cache::flush();
    Bus::fake();
    Http::preventStrayRequests();
    $this->mock(\App\Services\PlatformState::class)->shouldReceive('seeded')->andReturn(true);
    $this->live = $this->mock(NhlGameLiveContext::class);
    $this->live->shouldReceive('publicDetail')->andReturnNull()->byDefault();
    $this->lineups = $this->mock(NhlAnticipatedLineupPayload::class);
    $this->lineups->shouldReceive('gamePage')->andReturn(['game' => ['away' => [], 'home' => []]])->byDefault();
    $this->mock(NhlStartingGoalieSelector::class)->shouldReceive('select')->andReturnNull();
    $this->makeGame = fn (int $id = 2026020001, array $extra = []): NhlGame => NhlGame::create([
        'nhl_game_id' => $id, 'season_id' => '20262027', 'game_type' => 2,
        'game_date' => '2026-10-26', 'game_dow' => 'Mon', 'game_month' => 'Oct',
        'start_time_utc' => '2026-10-26 23:00:00', 'game_state' => 'OFF',
        'away_team_id' => 6, 'home_team_id' => 10, 'away_team_abbrev' => 'BOS', 'home_team_abbrev' => 'TOR',
        'away_team_common_name' => 'Bruins', 'home_team_common_name' => 'Maple Leafs',
        'away_team_score' => 2, 'home_team_score' => 4, 'away_team_sog' => 20, 'home_team_sog' => 30,
        ...$extra,
    ]);
    $this->timelineProvider = static function (): array {
        return ['game' => ['game_state' => 'LIVE', 'game_date' => '2026-10-27',
            'away' => ['team_id' => 6, 'team_abbrev' => 'BOS', 'score' => 1, 'sog' => 1],
            'home' => ['team_id' => 10, 'team_abbrev' => 'TOR', 'score' => 0, 'sog' => 1]],
            'boxscore' => ['clock' => ['timeRemaining' => '18:00'], 'periodDescriptor' => ['number' => 1]],
            'events_available' => true,
            'roster' => [['playerId' => 100, 'firstName' => ['default' => 'First'], 'lastName' => ['default' => 'Scorer']]],
            'plays' => [
                ['eventId' => 1, 'typeDescKey' => 'goal', 'periodDescriptor' => ['number' => 1, 'periodType' => 'REG'],
                    'timeInPeriod' => '01:00', 'details' => ['scoringPlayerId' => 100, 'eventOwnerTeamId' => 6,
                        'xCoord' => 0, 'yCoord' => 0, 'awayScore' => 1, 'homeScore' => 0]],
                ['eventId' => 2, 'typeDescKey' => 'shot-on-goal', 'periodDescriptor' => ['number' => 1, 'periodType' => 'REG'],
                    'timeInPeriod' => '02:00', 'details' => ['shootingPlayerId' => 200, 'eventOwnerTeamId' => 10,
                        'xCoord' => 75, 'yCoord' => 10, 'private_field' => 'never public']],
            ]];
    };
    $this->readGame = fn (int $id = 2026020001, bool $refresh = false): array => app(NhlPublicGamePage::class)->payload($id, $refresh)['game'];
    $this->savePrediction = function (array $inputs = [], array $extra = []): array {
        return app(NhlGamePredictionHistory::class)->record(2026020001, ['nhl_game_id' => 2026020001, ...$inputs],
            str_repeat('a', 64), ['private_evidence' => 'secret'], [
                'prediction_available' => true,
                'prediction' => ['predicted_score' => ['away' => 2.4, 'home' => 3.1]],
                'market_probabilities' => [
                    ['market_key' => 'moneyline', 'period_key' => 'full_game', 'selection_key' => 'home', 'team_abbrev' => 'TOR', 'probability' => .57],
                ], ...$extra,
            ]);
    };
});

afterEach(function (): void { $this->travelBack(); });

it('opens the public recap for guests at the existing numeric URL', function (): void {
    ($this->makeGame)();
    $this->get('/games/2026020001')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Games/Show')->where('game.mode', 'recap')->where('canManageLineups', false));
});

describe('saved pregame explanations and insights', function (): void {
    beforeEach(function (): void {
        ($this->makeGame)(2026020001, ['game_state' => 'FUT', 'game_date' => '2026-10-27', 'start_time_utc' => '2026-10-27 23:00:00']);
        $model = \App\Models\NhlModelRun::create(['run_key' => 'public-insights', 'name' => 'SAT_V2', 'model_family' => 'sat',
            'workflow_stage' => 'training', 'model_version' => 'fixture', 'status' => 'complete', 'train_season_ids' => ['20252026']]);
        $this->insightModel = $model;
        $run = \App\Models\NhlPregameContextRun::create(['model_run_id' => $model->id, 'action' => 'backfill',
            'status' => 'completed', 'season_ids' => ['20262027']]);
        DB::table('nhl_pregame_context_run_games')->insert(['run_id' => $run->id, 'nhl_game_id' => 2026020001,
            'game_date' => '2026-10-27', 'sequence' => 1, 'state' => 'complete', 'comparisons_completed_at' => now()]);
        $this->insightRun = $run;
        $teams = $inputs = [];
        foreach (['away' => [6, 'BOS', 10], 'home' => [10, 'TOR', 6]] as $side => [$id, $abbrev, $opponent]) {
            $parent = DB::table('nhl_team_game_pregame_contexts')->insertGetId([
                'model_run_id' => $model->id, 'run_id' => $run->id, 'nhl_game_id' => 2026020001,
                'nhl_team_id' => $id, 'opponent_team_id' => $opponent, 'game_date' => '2026-10-27',
                'source_cutoff_at' => '2026-10-27 10:00:00', 'venue' => $side,
                'context_version' => \App\Services\NhlPregameContextBuilder::VERSION,
                'schedule_metrics' => '{}', 'opponent_metrics' => '{}', 'updated_at' => now(),
                'metrics' => json_encode(['train' => ['games' => 82, 'goals_for_per_game' => 3],
                    'last_5' => ['games' => 5, 'goals_for_per_game' => 4]]),
            ]);
            $teams[$side] = ['team_abbrev' => $abbrev, 'summary' => ['adjusted_xgf_per_game' => 4.0,
                'ev_xgf_per_game' => 3.0, 'pp_xgf_per_game' => 1.0, 'adjusted_xsog_per_game' => 30.0], 'roster' => []];
            foreach ([1 => .8, 2 => .6, 3 => .4, 4 => .2] as $slot => $goals) {
                $playerId = $id * 100 + $slot;
                $name = $abbrev.' Player '.$slot;
                $teams[$side]['roster'][] = ['nhl_player_id' => $playerId, 'player_name' => $name, 'projected_goals' => $goals,
                    'strengths' => ['EV' => ['goals' => .75 * $goals], 'PP' => ['goals' => .25 * $goals]]];
                $inputs[$side]['players'][$playerId] = ['history' => [
                    'EV' => ['train' => ['games' => 100, 'seconds' => 60000, 'sat' => 400, 'sog' => 200, 'goals' => 20],
                        'last_10' => ['games' => 10, 'seconds' => 6000, 'sat' => 50, 'sog' => 25, 'goals' => 3],
                        'last_20' => ['games' => 20, 'seconds' => 12000, 'sat' => 100, 'sog' => 60, 'goals' => 6],
                        'last_season' => ['games' => 82, 'seconds' => 49200, 'sat' => 300, 'sog' => 150, 'goals' => 18]],
                    'PP' => ['train' => ['games' => 100, 'seconds' => 12000, 'goals' => 10],
                        'last_10' => ['games' => 10, 'seconds' => 1200, 'goals' => 2],
                        'last_20' => ['games' => 20, 'seconds' => 2400, 'goals' => 4]],
                ]];
                $inputs[$side]['pp_offense_sources'][$playerId] = 'last_20';
                DB::table('nhl_player_game_pregame_contexts')->insert([
                    'run_id' => $run->id, 'model_run_id' => $model->id, 'team_context_id' => $parent,
                    'nhl_game_id' => 2026020001, 'nhl_player_id' => $playerId, 'nhl_team_id' => $id,
                    'opponent_team_id' => $opponent, 'game_date' => '2026-10-27', 'source_cutoff_at' => '2026-10-27 10:00:00',
                    'venue' => $side, 'participant_source' => 'anticipated_lineup', 'updated_at' => now(),
                    'context_version' => \App\Services\NhlPregameContextBuilder::VERSION,
                    'metrics' => json_encode(['all' => ['train' => ['games' => 20, 'sog' => 40], 'last_5' => ['games' => 5, 'sog' => 20]]]),
                ]);
            }
        }
        foreach (['away', 'home'] as $side) {
            $inputs[$side]['team_metrics']['last_5'] = ['games' => 5, 'wins' => $side === 'away' ? 3 : 1, 'win_pct' => $side === 'away' ? 60 : 20];
            $inputs[$side]['pp_offense_l20'] = $side === 'away' ? .7 : .9;
            $inputs[$side]['pp_allowed_l5'] = $side === 'away' ? .6 : 1.2;
            $inputs[$side]['pk_probability'] = .85;
            $inputs[$side]['schedule'] = ['back_to_back' => false, 'games_including_target_last_7_days' => 3];
        }
        $this->insightTeams = $teams;
        $this->insightInputs = $inputs;
        $this->publishInsights = fn () => ($this->savePrediction)([], [
            'inputs' => ['sat_model_run_id' => $model->id, 'training_season_ids' => $model->train_season_ids,
                'algorithm_version' => 'custom_ev_pp_v3',
                'algorithm_definition' => ['train_rate_weight' => .5, 'team_l5_win_pct_weight' => .5,
                    'schedule' => ['forward_sat_multiplier' => .98, 'defense_sat_multiplier' => .97,
                        'away_fourth_b2b_home_probability_delta' => .04]]],
            'teams' => $this->insightTeams,
            '_custom_prediction_evidence' => ['input' => ['teams' => $this->insightInputs, 'league_pk_probability' => .8]],
        ]);
        ($this->publishInsights)();
        $this->analysis = fn () => app(NhlPublicGamePage::class)->panel(2026020001, 'factors')['data']['preview_analysis'];
    });

    it('allows guests and authenticated viewers to read curated saved contributors', function (bool $signedIn): void {
        if ($signedIn) {
            $this->actingAs(User::factory()->create());
        }
        $this->getJson('/games/2026020001/details?section=factors')->assertOk()
            ->assertJsonCount(3, 'data.preview_analysis.teams.away.contributors')
            ->assertJsonPath('data.preview_analysis.teams.away.contributors.0.name', 'BOS Player 1')
            ->assertJsonPath('data.preview_analysis.teams.away.contributors.0.share_pct', 20)
            ->assertJsonMissingPath('data.preview_analysis.inputs')->assertJsonMissingPath('data.preview_analysis.evidence');
        Http::assertNothingSent();
    })->with([false, true]);

    it('shows saved historical rates without formula or fallback descriptions', function (): void {
        $values = ($this->analysis)()['teams']['away']['contributors'][0]['comparisons'];
        expect($values[0])->toMatchArray(['metric' => 'EV SAT/60', 'recent' => 30.0, 'delta_pct' => 25.0,
            'recent_label' => 'Last 10 Games']);
        expect($values[1])->toMatchArray(['metric' => 'EV SAT/GP', 'recent' => 5.0, 'delta_pct' => 25.0]);
        expect($values[2])->toMatchArray(['metric' => 'EV SH%', 'recent' => 12.0, 'delta_pct' => 20.0, 'is_percentage' => true]);
        expect($values[3])->toMatchArray(['metric' => 'PP G/GP', 'recent' => .2, 'delta_pct' => 100.0]);
        expect(array_unique(array_column($values, 'recent_label')))->toBe(['Last 10 Games']);
        foreach ($values as $comparison) {
            expect($comparison)->not->toHaveKey('baseline');
        }
        config(['nhl-custom-prediction.train_rate_weight' => .99]);
        expect(($this->analysis)()['teams']['away']['contributors'][0]['comparisons'])->toBe($values);
    });

    it('uses plain Last 3 Seasons labels from the displayed revisions frozen training horizon', function (): void {
        $this->insightModel->update(['train_season_ids' => ['20232024', '20242025', '20252026']]);
        ($this->publishInsights)();
        $first = ($this->analysis)()['teams']['away']['contributors'][0]['comparisons'][0];
        expect($first['baseline_label'])->toBe('Last 3 Seasons')->and($first['recent_label'])->toBe('Last 10 Games');
        $this->insightModel->update(['train_season_ids' => ['20252026']]);
        expect(($this->analysis)()['baseline_label'])->toBe('Last 3 Seasons');
    });

    it('ranks the top three contributions and keeps EV and PP separately', function (): void {
        $away = ($this->analysis)()['teams']['away'];
        expect(array_column($away['contributors'], 'id'))->toBe([601, 602, 603])
            ->and($away['contributors'][0]['ev_goals'])->toEqualWithDelta(.6, 1e-9)
            ->and($away['contributors'][0]['pp_goals'])->toEqualWithDelta(.2, 1e-9)
            ->and($away['goals'])->toEqual(4)->and($away['pp_goals'])->toEqual(1);
    });

    it('includes the leading L10 points player even outside the top three goal projections', function (): void {
        $row = DB::table('nhl_player_game_pregame_contexts')->where('nhl_player_id', 604)->first();
        $metrics = json_decode($row->metrics, true);
        $metrics['all']['last_10'] = ['games' => 10, 'points' => 21];
        DB::table('nhl_player_game_pregame_contexts')->where('nhl_player_id', 604)->update(['metrics' => json_encode($metrics)]);
        $this->getJson('/games/2026020001/details?section=factors')->assertOk()
            ->assertJsonCount(4, 'data.preview_analysis.teams.away.contributors')
            ->assertJsonPath('data.preview_analysis.teams.away.contributors.0.id', 604)
            ->assertJsonPath('data.preview_analysis.teams.away.contributors.0.points_per_game', 2.1);
        expect(array_column(($this->analysis)()['teams']['away']['contributors'], 'id'))->toBe([604, 601, 602, 603]);
    });

    it('uses committed personal L10 strength context even when engine sources differ', function (): void {
        $row = DB::table('nhl_player_game_pregame_contexts')->where('nhl_player_id', 601)->first();
        $metrics = json_decode($row->metrics, true);
        $metrics['strength'] = ['EV' => [
            'train' => ['games' => 100, 'sat' => 400, 'sat_per_60' => 24, 'goals' => 20, 'sog' => 200],
            'last_10' => ['games' => 10, 'sat' => 30, 'sat_per_60' => 18, 'goals' => 0, 'sog' => 20]],
            'PP' => ['train' => ['games' => 100, 'goals' => 0], 'last_10' => ['games' => 10, 'goals' => 0]]];
        DB::table('nhl_player_game_pregame_contexts')->where('nhl_player_id', 601)->update(['metrics' => json_encode($metrics)]);
        $rates = ($this->analysis)()['teams']['away']['contributors'][0]['comparisons'];
        expect($rates[0])->toMatchArray(['recent' => 18.0, 'delta_pct' => -25.0])
            ->and($rates[1])->toMatchArray(['recent' => 3.0, 'delta_pct' => -25.0])
            ->and($rates[2])->toMatchArray(['recent' => 0.0, 'delta_pct' => -100.0])
            ->and($rates[3])->toMatchArray(['recent' => 0.0, 'delta_pct' => null]);
    });

    it('leaves missing L10 rates unknown instead of using another recent period', function (): void {
        unset($this->insightInputs['away']['players'][601]['history']['EV']['last_10']);
        ($this->publishInsights)();
        $rates = ($this->analysis)()['teams']['away']['contributors'][0]['comparisons'];
        expect($rates[0])->toMatchArray(['recent' => null, 'delta_pct' => null, 'recent_label' => 'Last 10 Games'])
            ->and($rates[2])->toMatchArray(['recent' => null, 'delta_pct' => null]);
    });

    it('does not rank an opponent or mismatched parent as an own points leader', function (): void {
        $row = DB::table('nhl_player_game_pregame_contexts')->where('nhl_player_id', 604)->first();
        $metrics = json_decode($row->metrics, true);
        $metrics['all']['last_10'] = ['games' => 10, 'points' => 99];
        DB::table('nhl_player_game_pregame_contexts')->where('nhl_player_id', 604)
            ->update(['metrics' => json_encode($metrics), 'opponent_team_id' => 99]);
        expect(array_column(($this->analysis)()['teams']['away']['contributors'], 'id'))->toBe([601, 602, 603]);
    });

    it('exposes saved Matchup drivers without raw algorithm definitions or bucket evidence', function (bool $signedIn): void {
        if ($signedIn) {
            $this->actingAs(User::factory()->create());
        }
        $response = $this->getJson('/games/2026020001/details?section=matchup')->assertOk()
            ->assertJsonPath('data.prediction_drivers.teams.away.l5_wins', 3)
            ->assertJsonPath('data.prediction_drivers.teams.away.ev_form_change_pct', 20)
            ->assertJsonPath('data.prediction_drivers.teams.home.ev_form_change_pct', -20)
            ->assertJsonPath('data.prediction_drivers.teams.away.pp_source', 'TOR goals allowed · Last 5')
            ->assertJsonPath('data.prediction_drivers.teams.away.ev_goals', 3)
            ->assertJsonPath('data.prediction_drivers.teams.away.pp_goals', 1)
            ->assertJsonMissingPath('data.prediction_drivers.definition')
            ->assertJsonMissingPath('data.prediction_drivers.input');
        expect($response->json('data.prediction_drivers.teams.away.ev_goals_before_form'))->toEqualWithDelta(2.5, 1e-9);
        config(['nhl-custom-prediction.team_l5_win_pct_weight' => 0]);
        $this->getJson('/games/2026020001/details?section=matchup')
            ->assertJsonPath('data.prediction_drivers.teams.away.ev_form_change_pct', 20);
        Http::assertNothingSent();
    })->with([false, true]);

    it('pins Matchup drivers to the displayed revision and rejects another games revision', function (): void {
        $old = ($this->analysis)()['revision_id'];
        $this->insightInputs['away']['team_metrics']['last_5']['win_pct'] = 20;
        ($this->publishInsights)();
        $this->getJson('/games/2026020001/details?section=matchup&prediction_revision_id='.$old)->assertOk()
            ->assertJsonPath('data.prediction_drivers.revision_id', $old)
            ->assertJsonPath('data.prediction_drivers.teams.away.ev_form_change_pct', 20);
        DB::table('nhl_game_prediction_revisions')->where('id', $old)->update(['nhl_game_id' => 2026020999]);
        $this->getJson('/games/2026020001/details?section=matchup&prediction_revision_id='.$old)->assertOk()
            ->assertJsonPath('data.prediction_drivers', null);
    });

    it('leaves missing historical driver inputs unknown rather than reading current settings', function (): void {
        $this->insightInputs = [];
        ($this->publishInsights)();
        $this->getJson('/games/2026020001/details?section=matchup')->assertOk()
            ->assertJsonPath('data.prediction_drivers.teams.away.ev_form_change_pct', null)
            ->assertJsonPath('data.prediction_drivers.teams.away.pp_source', null);
    });

    it('reports only the exact fourth-in-seven B2B schedule effects from frozen settings', function (): void {
        $this->insightInputs['away']['schedule'] = ['back_to_back' => true, 'games_including_target_last_7_days' => 4];
        $saved = ($this->publishInsights)();
        $id = $saved['meta']['prediction_revision_id'];
        $row = DB::table('nhl_game_prediction_revisions')->where('id', $id)->first();
        $payload = json_decode($row->payload, true);
        $payload['prediction']['confidence_inputs']['schedule_enabled'] = true;
        $payload['prediction']['home_win_probability'] = .6;
        DB::table('nhl_game_prediction_revisions')->where('id', $id)->update(['payload' => json_encode($payload)]);
        $data = $this->getJson('/games/2026020001/details?section=matchup')->assertOk()
            ->assertJsonPath('data.prediction_drivers.teams.away.schedule_applied', true)
            ->assertJsonPath('data.prediction_drivers.teams.home.schedule_applied', false)
            ->assertJsonPath('data.prediction_drivers.home_probability_schedule_change_points', 4)
            ->json('data.prediction_drivers.teams.away');
        expect($data['forward_sat_change_pct'])->toEqualWithDelta(-2, 1e-9)
            ->and($data['defense_sat_change_pct'])->toEqualWithDelta(-3, 1e-9);
    });

    it('reads matching committed context for L5 goals and its previous-season norm', function (): void {
        foreach (range(1, 5) as $day) {
            ($this->makeGame)(2026020700 + $day, ['game_date' => '2026-10-'.(27 - $day), 'home_team_score' => 4, 'away_team_score' => 2]);
        }
        ($this->makeGame)(2025020700, ['season_id' => '20252026', 'game_date' => '2026-04-01', 'home_team_score' => 3, 'away_team_score' => 4]);
        DB::table('nhl_team_game_pregame_contexts')->where('nhl_team_id', 10)->update(['metrics' => json_encode([
            'last_5' => ['games' => 5, 'goals_for_per_game' => 4, 'goals_against_per_game' => 2],
            'last_season' => ['games' => 1, 'goals_for_per_game' => 3, 'goals_against_per_game' => 4],
        ])]);
        $this->getJson('/games/2026020001/details?section=matchup')->assertOk()
            ->assertJsonPath('data.home.form.scoring_trend.gf_gp.value', 4)
            ->assertJsonPath('data.home.form.scoring_trend.gf_gp.norm', 3)
            ->assertJsonPath('data.home.form.scoring_trend.ga_gp.value', 2)
            ->assertJsonPath('data.home.form.scoring_trend.ga_gp.norm', 4);
    });

    it('balances six distinct facts across the teams and includes numeric comparisons', function (): void {
        $facts = ($this->analysis)()['insights'];
        expect($facts)->toHaveCount(6)->and(array_count_values(array_column($facts, 'side')))->toBe(['away' => 3, 'home' => 3]);
        expect($facts[0])->toMatchArray(['metric' => 'SOG/GP', 'value' => 4.0, 'baseline' => 2.0,
            'games' => 5, 'baseline_games' => 20, 'delta_pct' => 100.0, 'direction' => 'higher']);
    });

    it('rejects short samples and differences below the approved threshold', function (int $games, float $sog): void {
        DB::table('nhl_player_game_pregame_contexts')->update(['metrics' => json_encode(['all' => [
            'train' => ['games' => 20, 'sog' => 40], 'last_5' => ['games' => $games, 'sog' => $sog],
        ]])]);
        DB::table('nhl_team_game_pregame_contexts')->update(['metrics' => '{}']);
        expect(($this->analysis)()['insights'])->toBe([]);
    })->with([[4, 20.0], [5, 12.0]]);

    it('accepts exactly five games and exactly twenty five percent difference', function (): void {
        DB::table('nhl_player_game_pregame_contexts')->update(['metrics' => json_encode(['all' => [
            'train' => ['games' => 20, 'sog' => 40], 'last_5' => ['games' => 5, 'sog' => 12.5],
        ]])]);
        $facts = ($this->analysis)()['insights'];
        expect($facts[0]['delta_pct'])->toEqual(25);
    });

    it('deduplicates repeated windows for the same player metric', function (): void {
        DB::table('nhl_player_game_pregame_contexts')->update(['metrics' => json_encode(['all' => [
            'train' => ['games' => 20, 'sog' => 40], 'last_5' => ['games' => 5, 'sog' => 20],
            'last_10' => ['games' => 10, 'sog' => 40],
        ]])]);
        $facts = ($this->analysis)()['insights'];
        expect(collect($facts)->where('name', 'BOS Player 1'))->toHaveCount(1)
            ->and($facts[0]['period'])->toBe('Last 10 Games');
    });

    it('includes opponent history and labels the sample explicitly', function (): void {
        DB::table('nhl_player_game_pregame_contexts')->update(['metrics' => json_encode(['all' => [
            'train' => ['games' => 20, 'sog' => 40], 'opponent_last_5' => ['games' => 5, 'sog' => 25],
        ]])]);
        expect(($this->analysis)()['insights'][0])->toMatchArray(['period' => 'Last 5 vs TOR', 'value' => 5.0, 'baseline' => 2.0]);
    });

    it('does not use other models or uncommitted context', function (string $scenario): void {
        if ($scenario === 'uncommitted') {
            DB::table('nhl_pregame_context_run_games')->update(['comparisons_completed_at' => null]);
        } else {
            $other = $this->insightModel->replicate();
            $other->run_key = 'other-public-insight-model';
            $other->save();
            ($this->savePrediction)([], ['inputs' => ['sat_model_run_id' => $other->id], 'teams' => $this->insightTeams]);
        }
        expect(($this->analysis)()['insights'])->toBe([]);
    })->with(['uncommitted', 'other model']);

    it('omits player history outside the displayed prediction roster', function (): void {
        $this->insightTeams['away']['roster'] = [];
        ($this->publishInsights)();
        expect(collect(($this->analysis)()['insights'])->where('team', 'BOS')->pluck('name')->all())->not->toContain('BOS Player 1');
    });

    it('refreshes cached facts when committed context is updated', function (): void {
        $before = ($this->analysis)();
        $this->travel(1)->seconds();
        DB::table('nhl_player_game_pregame_contexts')->update(['metrics' => '{}', 'updated_at' => now()]);
        $after = ($this->analysis)();
        expect($after['insights'])->not->toBe($before['insights']);
    });

    it('does not turn a zero Train baseline into an infinite percentage', function (): void {
        DB::table('nhl_team_game_pregame_contexts')->update(['metrics' => '{}']);
        DB::table('nhl_player_game_pregame_contexts')->update(['metrics' => json_encode(['all' => [
            'train' => ['games' => 20, 'sog' => 0], 'last_5' => ['games' => 5, 'sog' => 20],
        ]])]);
        expect(($this->analysis)()['insights'])->toBe([]);
    });

    it('excludes contexts whose source cutoff is still in the future', function (): void {
        DB::table('nhl_team_game_pregame_contexts')->update(['source_cutoff_at' => '2026-10-27 13:00:00']);
        DB::table('nhl_player_game_pregame_contexts')->update(['source_cutoff_at' => '2026-10-27 13:00:00']);
        expect(($this->analysis)()['insights'])->toBe([]);
    });

    it('formats team head to head percentage facts as percentages', function (): void {
        DB::table('nhl_player_game_pregame_contexts')->update(['metrics' => '{}']);
        DB::table('nhl_team_game_pregame_contexts')->update([
            'metrics' => json_encode(['train' => ['games' => 82, 'corsi_pct' => 50]]),
            'opponent_metrics' => json_encode(['last_5' => ['games' => 5, 'corsi_pct' => 65]]),
        ]);
        expect(($this->analysis)()['insights'][0])->toMatchArray([
            'metric' => 'Corsi%', 'value' => 65.0, 'baseline' => 50.0, 'is_percentage' => true, 'period' => 'Last 5 vs TOR',
        ]);
    });

    it('shows unavailable analysis when no saved canonical prediction exists', function (): void {
        DB::table('nhl_game_prediction_revisions')->delete();
        $analysis = ($this->analysis)();
        expect($analysis['revision_id'])->toBeNull()->and($analysis['teams'])->toBe([])->and($analysis['insights'])->toBe([]);
    });

    it('pins explanations to the displayed revision when a newer prediction arrives', function (): void {
        $old = ($this->analysis)()['revision_id'];
        $this->insightTeams['away']['roster'][0]['player_name'] = 'Updated Contributor';
        ($this->publishInsights)();
        $this->getJson('/games/2026020001/details?section=factors&prediction_revision_id='.$old)->assertOk()
            ->assertJsonPath('data.preview_analysis.revision_id', $old)
            ->assertJsonPath('data.preview_analysis.teams.away.contributors.0.name', 'BOS Player 1');
        expect(($this->analysis)()['teams']['away']['contributors'][0]['name'])->toBe('Updated Contributor');
    });

    it('does not guess source periods when a historical revision lacks provenance', function (): void {
        $this->insightInputs = [];
        ($this->publishInsights)();
        expect(($this->analysis)()['teams']['away']['contributors'][0]['comparisons'])->toBe([]);
    });

    it('rejects malformed revision selections', function (string $id): void {
        $this->getJson('/games/2026020001/details?section=factors&prediction_revision_id='.$id)
            ->assertUnprocessable()->assertJsonValidationErrors('prediction_revision_id');
    })->with(['0', '-1', 'invalid']);

    it('does not substitute another revision when a requested saved revision is absent', function (): void {
        $this->getJson('/games/2026020001/details?section=factors&prediction_revision_id=999999999')->assertOk()
            ->assertJsonPath('data.preview_analysis.revision_id', null)->assertJsonPath('data.preview_analysis.teams', []);
    });

    it('does not expose another games revision through a pinned explanation request', function (): void {
        $other = ($this->savePrediction)();
        $id = $other['meta']['prediction_revision_id'];
        DB::table('nhl_game_prediction_revisions')->where('id', $id)->update(['nhl_game_id' => 2026020002]);
        $this->getJson('/games/2026020001/details?section=factors&prediction_revision_id='.$id)->assertOk()
            ->assertJsonPath('data.preview_analysis.revision_id', null)->assertJsonPath('data.preview_analysis.teams', []);
    });

    it('does not publish a request variant as the canonical pregame explanation', function (): void {
        $other = ($this->savePrediction)(['home_goalie_id' => 999]);
        $id = $other['meta']['prediction_revision_id'];
        $this->getJson('/games/2026020001/details?section=factors&prediction_revision_id='.$id)->assertOk()
            ->assertJsonPath('data.preview_analysis.revision_id', null);
        expect(($this->analysis)()['revision_id'])->not->toBe($id);
    });

    it('keeps the first paint free of analysis and does not generate a prediction', function (): void {
        $count = DB::table('nhl_game_prediction_revisions')->count();
        $this->get('/games/2026020001')->assertInertia(fn (Assert $page) => $page->missing('game.preview_analysis'));
        ($this->analysis)();
        expect(DB::table('nhl_game_prediction_revisions')->count())->toBe($count);
        Http::assertNothingSent();
    });
});

describe('Matchup season statistics', function (): void {
    beforeEach(function (): void {
        ($this->makeGame)(2026020001, ['game_state' => 'FUT', 'game_date' => '2026-10-27', 'start_time_utc' => '2026-10-27 23:00:00']);
        ($this->makeGame)(2026020002, ['home_pp_goals' => 2, 'home_pp_opportunities' => 4,
            'away_pp_goals' => 1, 'away_pp_opportunities' => 5]);
        $this->matchup = fn () => app(NhlPublicGamePage::class)->panel(2026020001, 'matchup')['data'];
    });

    it('includes PP PK opportunities and head to head records without exposing Factors', function (): void {
        $this->getJson('/games/2026020001/details?section=matchup')->assertOk()
            ->assertJsonPath('data.home.form.gf_gp', 4)->assertJsonPath('data.home.form.ga_gp', 2)
            ->assertJsonPath('data.home.form.pp_pct', 50)->assertJsonPath('data.home.form.pk_pct', 80)
            ->assertJsonPath('data.home.form.pp_opps_gp', 4)->assertJsonPath('data.home.form.h2h_wins', 1)
            ->assertJsonMissingPath('data.preview_analysis');
    });

    it('calculates special teams percentages from summed goals and opportunities', function (): void {
        ($this->makeGame)(2026020003, ['game_date' => '2026-10-25', 'home_team_score' => 2, 'away_team_score' => 3,
            'ot_in_use' => true, 'game_outcome' => ['lastPeriodType' => 'OT'], 'home_pp_goals' => 0, 'home_pp_opportunities' => 1,
            'away_pp_goals' => 2, 'away_pp_opportunities' => 3]);
        $form = ($this->matchup)()['home']['form'];
        expect($form)->toMatchArray(['gp' => 2, 'pp_pct' => 40, 'pk_pct' => 62.5, 'pp_opps_gp' => 2.5,
            'gf_gp' => 3.0, 'ga_gp' => 2.5, 'h2h_wins' => 1, 'h2h_otl' => 1]);
    });

    it('keeps known opportunities available when goal totals are missing', function (): void {
        DB::table('nhl_games')->where('nhl_game_id', 2026020002)->update(['home_pp_goals' => null]);
        $form = ($this->matchup)()['home']['form'];
        expect($form['pp_pct'])->toBeNull()->and($form['pp_opps_gp'])->toEqual(4);
    });

    it('keeps zero opportunity success rates unknown and the opportunity average zero', function (): void {
        DB::table('nhl_games')->where('nhl_game_id', 2026020002)->update(['home_pp_opportunities' => 0, 'home_pp_goals' => 0]);
        $form = ($this->matchup)()['home']['form'];
        expect($form['pp_pct'])->toBeNull()->and($form['pp_opps_gp'])->toEqual(0);
    });

    it('does not call the expected goal reader during a public Matchup request', function (): void {
        $reader = Mockery::mock(\App\Services\NhlStatsDashboardPayload::class);
        $reader->shouldNotReceive('teamExpectationsForGames');
        app()->instance(\App\Services\NhlStatsDashboardPayload::class, $reader);
        ($this->matchup)();
        ($this->matchup)();
        Bus::assertDispatched(RefreshNhlPublicGamePageJob::class, 1);
    });

    it('uses available prepared xG without blanking partially rated samples', function (): void {
        $reader = Mockery::mock(\App\Services\NhlStatsDashboardPayload::class);
        $reader->shouldReceive('teamExpectationsForGames')->once()->with('20262027', [2026020002], '2026-10-26', 2, true)
            ->andReturn([collect(['2026020002:10' => (object) ['games' => 1, 'scored' => 3, 'attempts' => 3, 'xg' => 2.5]]),
                collect(['2026020002:10' => (object) ['games' => 1, 'scored' => 1, 'attempts' => 2, 'xg' => 1.2]])]);
        app()->instance(\App\Services\NhlStatsDashboardPayload::class, $reader);
        app(NhlPublicGamePage::class)->prepare(2026020001);
        $data = ($this->matchup)();
        expect($data['matchup_expected_pending'])->toBeFalse()->and($data['home']['form']['xgf_gp'])->toEqual(2.5)
            ->and($data['home']['form']['xga_gp'])->toEqual(1.2)
            ->and($data['home']['form']['scoring_trend']['xga_gp'])->toMatchArray(['value' => 1.2, 'norm' => null, 'change_pct' => null]);
    });

    it('uses season norm at ten completed games and last season below ten', function (int $games, string $period): void {
        foreach (range(1, $games - 1) as $day) {
            ($this->makeGame)(2026020100 + $day, ['game_date' => '2026-10-'.(26 - $day),
                'home_team_score' => $day < 5 ? 4 : 2, 'away_team_score' => $day < 5 ? 2 : 4]);
        }
        ($this->makeGame)(2025020100, ['season_id' => '20252026', 'game_date' => '2026-04-01', 'home_team_score' => 2, 'away_team_score' => 4]);
        ($this->makeGame)(2026020999, ['game_date' => '2026-10-28', 'home_team_score' => 99]);
        $data = $this->getJson('/games/2026020001/details?section=matchup')->assertOk()
            ->assertJsonPath('data.home.form.scoring_trend.norm_period', $period)
            ->assertJsonPath('data.home.form.scoring_trend.games', 5)
            ->assertJsonPath('data.home.form.scoring_trend.gf_gp.value', 4)
            ->json('data.home.form.scoring_trend');
        if ($games === 10) {
            expect($data['norm_games'])->toBe(10)->and($data['gf_gp']['norm'])->toEqual(3)
                ->and($data['gf_gp']['change_pct'])->toEqualWithDelta(100 / 3, 1e-7)
                ->and($data['ga_gp']['change_pct'])->toEqualWithDelta(-100 / 3, 1e-7);
        } else {
            expect($data['norm_games'])->toBe(1)->and($data['gf_gp']['norm'])->toEqual(2)
                ->and($data['gf_gp']['change_pct'])->toEqual(100);
        }
    })->with([[9, 'last_season'], [10, 'season_to_date']]);

    it('uses prior-season games in early L5 while retaining a zero norm without dividing by zero', function (): void {
        ($this->makeGame)(2025020100, ['season_id' => '20252026', 'game_date' => '2026-04-01', 'home_team_score' => 0, 'away_team_score' => 2]);
        $trend = ($this->matchup)()['home']['form']['scoring_trend'];
        expect($trend['games'])->toBe(2)->and($trend['norm_period'])->toBe('last_season')
            ->and($trend['gf_gp'])->toMatchArray(['value' => 2, 'norm' => 0, 'change_pct' => null]);
    });

    it('aggregates per-game xG over exact L5 and season memberships using full game denominators', function (): void {
        $for = collect();
        foreach (range(0, 9) as $day) {
            $id = $day === 0 ? 2026020002 : 2026020100 + $day;
            if ($day > 0) {
                ($this->makeGame)($id, ['game_date' => '2026-10-'.(26 - $day)]);
            }
            $for->put($id.':10', (object) ['scored' => 1, 'attempts' => $day === 0 ? 2 : 1, 'xg' => $day === 0 ? 2 : ($day < 5 ? 3 : 1)]);
        }
        $reader = Mockery::mock(\App\Services\NhlStatsDashboardPayload::class);
        $reader->shouldReceive('teamExpectationsForGames')->once()->andReturn([$for, collect()]);
        app()->instance(\App\Services\NhlStatsDashboardPayload::class, $reader);
        app(NhlPublicGamePage::class)->prepare(2026020001);
        $this->getJson('/games/2026020001/details?section=matchup')->assertOk()
            ->assertJsonPath('data.home.form.scoring_trend.xgf_gp.value', 2.8)
            ->assertJsonPath('data.home.form.scoring_trend.xgf_gp.norm', 1.9)
            ->assertJsonPath('data.home.form.xgf_gp', 1.9);
        expect(($this->matchup)()['home']['form']['scoring_trend']['xgf_gp']['change_pct'])->toEqualWithDelta(100 * .9 / 1.9, 1e-7);
        ($this->makeGame)(2026020998, ['game_date' => '2026-10-16']);
        $this->getJson('/games/2026020001/details?section=matchup')->assertOk()
            ->assertJsonPath('data.matchup_expected_pending', true)
            ->assertJsonPath('data.home.form.scoring_trend.xgf_gp.value', null);
        Http::assertNothingSent();
    });

    it('counts allowed PP opportunities independently of missing opponent goals', function (): void {
        DB::table('nhl_games')->where('nhl_game_id', 2026020002)->update(['away_pp_goals' => null]);
        $this->getJson('/games/2026020001/details?section=matchup')->assertOk()
            ->assertJsonPath('data.home.form.pp_opps_allowed_gp', 5)
            ->assertJsonPath('data.away.form.pp_opps_allowed_gp', 4)
            ->assertJsonPath('data.home.form.pk_pct', null);
        DB::table('nhl_games')->where('nhl_game_id', 2026020002)->update(['away_pp_opportunities' => null]);
        expect(($this->matchup)()['home']['form']['pp_opps_allowed_gp'])->toBeNull();
    });

    it('limits head to head to ten prior meetings across seasons without expanding season form', function (): void {
        foreach (range(1, 11) as $index) {
            ($this->makeGame)(2025020100 + $index, ['season_id' => '20252026',
                'game_date' => '2026-03-'.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
                'home_team_score' => 1, 'away_team_score' => 3, 'game_outcome' => ['lastPeriodType' => 'REG']]);
        }
        ($this->makeGame)(2025030100, ['season_id' => '20252026', 'game_type' => 3, 'game_date' => '2026-04-01']);
        ($this->makeGame)(2026020003, ['game_date' => '2026-10-28']);
        $this->getJson('/games/2026020001/details?section=matchup')->assertOk()
            ->assertJsonPath('data.home.form.gp', 1)->assertJsonPath('data.home.form.h2h_gp', 10)
            ->assertJsonPath('data.home.form.h2h_wins', 1)->assertJsonPath('data.home.form.h2h_losses', 9)
            ->assertJsonPath('data.away.form.h2h_wins', 9);
    });

    it('does not count the requested game or future games in its season baseline', function (): void {
        ($this->makeGame)(2026020003, ['game_date' => '2026-10-28', 'home_team_score' => 20]);
        expect(($this->matchup)()['home']['form']['gp'])->toBe(1);
    });
});


describe('prediction Matchup completion', function (): void {
    beforeEach(function (): void {
        ($this->makeGame)(2026020001, ['game_state' => 'FUT', 'game_date' => '2026-10-27', 'start_time_utc' => '2026-10-27 23:00:00']);
        ($this->makeGame)(2026020002);
    });

    it('coalesces source-bound expected-stat work separately from public refreshes', function (): void {
        $pages = app(NhlPublicGamePage::class);
        foreach (range(1, 2) as $attempt) {
            expect(fn () => $pages->predictionMatchup(2026020001, 'source-one'))
                ->toThrow(\App\Exceptions\NhlGamePredictionPreparing::class);
        }
        Bus::assertDispatchedTimes(RefreshNhlPublicGamePageJob::class, 1);
        $pending = new RefreshNhlPublicGamePageJob(2026020001, 'source-one');
        expect($pending->queue)->toBe('default')->and($pending->uniqueId())
            ->not->toBe((new RefreshNhlPublicGamePageJob(2026020001))->uniqueId());
    });

    it('prepares complete expected statistics independently of game state and public cache version', function (string $state, float $goals): void {
        DB::table('nhl_games')->where('nhl_game_id', 2026020001)->update(['game_state' => $state]);
        $ids = DB::table('nhl_games')->where('nhl_game_id', 2026020001)->first(['home_team_id', 'away_team_id']);
        $totals = collect();
        foreach ([$ids->away_team_id, $ids->home_team_id] as $team) {
            $totals->put('2026020002:'.$team, (object) ['scored' => 3, 'attempts' => 4, 'xg' => $goals]);
        }
        $reader = Mockery::mock(\App\Services\NhlStatsDashboardPayload::class);
        $reader->shouldReceive('teamExpectationsForGames')->once()->andReturn([$totals, $totals]);
        app()->instance(\App\Services\NhlStatsDashboardPayload::class, $reader);
        $pages = app(NhlPublicGamePage::class);
        (new RefreshNhlPublicGamePageJob(2026020001, 'source-two'))->handle($pages);
        $pages->forget(2026020001);
        $this->travel(6)->minutes();
        $matchup = $pages->predictionMatchup(2026020001, 'source-two');
        expect($matchup['expected_pending'])->toBeFalse()
            ->and($matchup['away']['form']['xgf_gp'])->toEqual($goals)
            ->and($matchup['home']['form']['xga_gp'])->toEqual($goals)
            ->and($matchup['home']['form']['scoring_trend']['xgf_gp']['value'])->toEqual($goals);
        expect(fn () => $pages->predictionMatchup(2026020001, 'new-source'))
            ->toThrow(\App\Exceptions\NhlGamePredictionPreparing::class);
        Http::assertNothingSent();
    })->with([['FUT', 2.5], ['LIVE', 2.5], ['FINAL', 2.5], ['FUT', 0.0]]);

    it('reports entirely unrated windows without manufacturing expected goals', function (): void {
        $reader = Mockery::mock(\App\Services\NhlStatsDashboardPayload::class);
        $reader->shouldReceive('teamExpectationsForGames')->once()->andReturn([collect(), collect()]);
        app()->instance(\App\Services\NhlStatsDashboardPayload::class, $reader);
        $pages = app(NhlPublicGamePage::class);
        $pages->preparePredictionMatchup(2026020001, 'unrated');
        expect(fn () => $pages->predictionMatchup(2026020001, 'unrated'))
            ->toThrow(\Illuminate\Validation\ValidationException::class);
        $prepared = Cache::get('nhl:prediction-matchup:v1:2026020001:unrated');
        expect($prepared)->not->toHaveKey('matchup')->and($prepared['error'])->toContain('xgf_gp', 'xga_gp', 'SAT scoring');
    });

    it('exposes exhausted Matchup preparation errors and leaves ordinary public refreshes unchanged', function (): void {
        $error = new RuntimeException('Expected statistics query timed out.');
        (new RefreshNhlPublicGamePageJob(2026020001))->failed($error);
        expect(Cache::get('nhl:prediction-matchup:v1:2026020001:failed'))->toBeNull();
        (new RefreshNhlPublicGamePageJob(2026020001, 'failed'))->failed($error);
        expect(fn () => app(NhlPublicGamePage::class)->predictionMatchup(2026020001, 'failed'))
            ->toThrow(\Illuminate\Validation\ValidationException::class);
        expect(Cache::get('nhl:prediction-matchup:v1:2026020001:failed')['error'])->toContain('query timed out');
    });
});

describe('recorded ending display classification', function (): void {
    beforeEach(function (): void {
        ($this->makeGame)(2026020001, ['game_state' => 'FUT', 'game_date' => '2026-10-27', 'start_time_utc' => '2026-10-27 23:00:00']);
        $this->endingHistory = function (array $endings): void {
            foreach ($endings as $index => $ending) {
                $date = now('UTC')->startOfDay()->subDays($index + 1);
                ($this->makeGame)(2026020100 + $index, [
                    'game_date' => $date->toDateString(), 'start_time_utc' => $date->copy()->setTime(1, 0),
                    'home_team_score' => $ending === 'W' ? 4 : 2, 'away_team_score' => $ending === 'W' ? 2 : 3,
                    'ot_in_use' => true, 'shootout_in_use' => true,
                    'game_outcome' => ['lastPeriodType' => $ending === 'W' ? 'REG' : $ending],
                ]);
            }
        };
        $this->endingForm = fn () => app(NhlPublicGamePage::class)->panel(2026020001, 'matchup')['data'];
    });

    it('keeps regulation losses out of OTL in season last ten and opponent records', function (): void {
        ($this->endingHistory)(['W', 'W', 'W', 'W', 'REG', 'REG', 'REG', 'REG', 'REG', 'OT']);
        $this->getJson('/games/2026020001/details?section=matchup')->assertOk()
            ->assertJsonPath('data.home.form.wins', 4)->assertJsonPath('data.home.form.losses', 5)->assertJsonPath('data.home.form.otl', 1)
            ->assertJsonPath('data.home.form.l10_wins', 4)->assertJsonPath('data.home.form.l10_losses', 5)->assertJsonPath('data.home.form.l10_otl', 1)
            ->assertJsonPath('data.home.form.h2h_wins', 4)->assertJsonPath('data.home.form.h2h_losses', 5)->assertJsonPath('data.home.form.h2h_otl', 1)
            ->assertJsonPath('data.away.form.wins', 6)->assertJsonPath('data.away.form.losses', 4)->assertJsonPath('data.away.form.otl', 0);
    });

    it('counts actual overtime and shootout losses even when rule flags are false', function (string $ending): void {
        ($this->endingHistory)([$ending]);
        DB::table('nhl_games')->where('nhl_game_id', 2026020100)->update(['ot_in_use' => false, 'shootout_in_use' => false]);
        $form = ($this->endingForm)()['home']['form'];
        expect($form)->toMatchArray(['wins' => 0, 'losses' => 0, 'otl' => 1, 'l10_otl' => 1, 'h2h_otl' => 1]);
    })->with(['OT', 'SO']);

    it('keeps the last ten and opponent windows separate from older season games', function (): void {
        ($this->endingHistory)(['W', 'W', 'W', 'W', 'REG', 'REG', 'REG', 'REG', 'REG', 'SO', 'W']);
        DB::table('nhl_games')->where('nhl_game_id', 2026020110)->update(['away_team_id' => 8, 'away_team_abbrev' => 'MTL']);
        $form = ($this->endingForm)()['home']['form'];
        expect($form)->toMatchArray(['gp' => 11, 'wins' => 5, 'losses' => 5, 'otl' => 1,
            'l10_gp' => 10, 'l10_wins' => 4, 'l10_losses' => 5, 'l10_otl' => 1,
            'h2h_gp' => 10, 'h2h_wins' => 4, 'h2h_losses' => 5, 'h2h_otl' => 1]);
    });

    it('adds last five record while preserving API-compatible last ten fields', function (): void {
        ($this->endingHistory)(['W', 'W', 'W', 'REG', 'OT', 'W', 'W', 'W', 'W', 'W']);
        $this->getJson('/games/2026020001/details?section=matchup')->assertOk()
            ->assertJsonPath('data.home.form.l5_gp', 5)->assertJsonPath('data.home.form.l5_wins', 3)
            ->assertJsonPath('data.home.form.l5_losses', 1)->assertJsonPath('data.home.form.l5_otl', 1)
            ->assertJsonPath('data.home.form.l10_gp', 10)->assertJsonPath('data.home.form.l10_wins', 8);
    });

    it('falls back to the recorded period descriptor when the outcome is absent', function (string $period, int $losses, int $otl): void {
        ($this->endingHistory)(['REG']);
        \App\Models\NhlGame::findOrFail(2026020100)->update(['game_outcome' => null, 'period_type' => $period]);
        expect(($this->endingForm)()['home']['form'])->toMatchArray(['losses' => $losses, 'otl' => $otl]);
    })->with([['REG', 1, 0], ['OT', 0, 1], ['SO', 0, 1]]);

    it('does not infer an overtime loss from enabled rules when ending evidence is missing', function (): void {
        ($this->endingHistory)(['REG']);
        \App\Models\NhlGame::findOrFail(2026020100)->update(['game_outcome' => null, 'period_type' => null]);
        expect(($this->endingForm)()['home']['form'])->toMatchArray(['losses' => 1, 'otl' => 0]);
    });

    it('uses outcome ending for both first paint and prepared final labels', function (string $ending, string $label): void {
        $game = \App\Models\NhlGame::findOrFail(2026020001);
        $game->update(['game_state' => 'OFF', 'game_date' => '2026-10-26', 'ot_in_use' => true, 'shootout_in_use' => true,
            'period_type' => 'SO', 'game_outcome' => ['lastPeriodType' => $ending]]);
        $pages = app(NhlPublicGamePage::class);
        expect($pages->overview(2026020001)['game']['status'])->toBe($label)
            ->and($pages->payload(2026020001)['game']['status'])->toBe($label);
        $this->get('/games/2026020001')->assertInertia(fn (Assert $page) => $page->where('game.status', $label));
    })->with([['REG', 'Final'], ['OT', 'Final / OT'], ['SO', 'Final / SO']]);

    it('supports the stored outcome periodType fallback and normalizes casing', function (): void {
        ($this->endingHistory)(['REG']);
        \App\Models\NhlGame::findOrFail(2026020100)->update(['game_outcome' => ['periodType' => ' so '], 'period_type' => 'REG']);
        expect(($this->endingForm)()['home']['form']['otl'])->toBe(1);
    });
});

it('allows ordinary authenticated viewers without granting lineup management', function (): void {
    ($this->makeGame)();
    $this->actingAs(User::factory()->create())->get('/games/2026020001')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('canManageLineups', false));
});

it('returns not found for an unknown schedule game', function (): void {
    $this->get('/games/9999999999')->assertNotFound();
});

it('renders upcoming games as previews', function (string $state): void {
    ($this->makeGame)(2026020001, ['game_state' => $state, 'game_date' => '2026-10-28']);
    expect(($this->readGame)()['mode'])->toBe('preview');
})->with(['FUT', 'PRE']);

it('treats both stored terminal states as recaps', function (string $state): void {
    ($this->makeGame)(2026020001, ['game_state' => $state]);
    expect(($this->readGame)()['mode'])->toBe('recap');
})->with(['OFF', 'FINAL']);

it('hides stored placeholder scores in previews', function (): void {
    ($this->makeGame)(2026020001, ['game_state' => 'FUT', 'game_date' => '2026-10-28']);
    expect(($this->readGame)()['away']['score'])->toBeNull()->and(($this->readGame)()['home']['score'])->toBeNull();
});

it('reuses prepared recap facts without rereading changed source rows', function (): void {
    ($this->makeGame)();
    ($this->readGame)();
    DB::table('nhl_boxscores')->insert(['nhl_game_id' => 2026020001, 'nhl_player_id' => 100, 'nhl_team_id' => 6, 'position' => 'C', 'goals' => 2]);
    expect(($this->readGame)()['away']['boxscore'])->toBe([]);
});

it('rebuilds a requested snapshot after source preparation', function (): void {
    ($this->makeGame)();
    ($this->readGame)();
    DB::table('nhl_boxscores')->insert(['nhl_game_id' => 2026020001, 'nhl_player_id' => 100, 'nhl_team_id' => 6, 'position' => 'C', 'goals' => 2]);
    expect(($this->readGame)(2026020001, true)['away']['boxscore'][0]['goals'])->toBe(2);
});

it('uses a new snapshot when the stored game changes', function (): void {
    $game = ($this->makeGame)();
    ($this->readGame)();
    $this->travel(1)->seconds();
    $game->update(['home_team_score' => 5]);
    expect(($this->readGame)()['home']['score'])->toBe(5);
});

it('publishes saved public probabilities without private prediction evidence', function (): void {
    ($this->makeGame)(2026020001, ['game_state' => 'FUT', 'game_date' => '2026-10-28']);
    ($this->savePrediction)();
    $this->assertDatabaseHas('nhl_game_prediction_revisions', ['nhl_game_id' => 2026020001, 'is_canonical' => true]);
    $this->get('/games/2026020001')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('game.prediction.probabilities.home', .57)->missing('game.prediction.evidence')->missing('game.prediction.request_inputs'));
});

it('ignores overridden prediction revisions', function (): void {
    ($this->makeGame)();
    ($this->savePrediction)(['home_goalie_id' => 123]);
    expect(($this->readGame)()['prediction'])->toBeNull();
});

it('ignores unsuccessful saved predictions', function (): void {
    ($this->makeGame)();
    ($this->savePrediction)([], ['prediction_available' => false]);
    expect(($this->readGame)()['prediction'])->toBeNull();
});

it('does not invent predictions when no revision exists', function (): void {
    ($this->makeGame)();
    expect(($this->readGame)()['prediction'])->toBeNull();
});

it('excludes the selected and later results from pregame form', function (): void {
    ($this->makeGame)();
    ($this->makeGame)(2026020002, ['game_date' => '2026-10-25']);
    ($this->makeGame)(2026020003, ['game_date' => '2026-10-28']);
    $form = ($this->readGame)()['home']['form'];
    expect($form['gp'])->toBe(1)->and($form['wins'])->toBe(1)->and($form['gf_gp'])->toEqual(4);
});

it('limits recent form to the previous ten completed games', function (): void {
    ($this->makeGame)();
    foreach (range(1, 12) as $day) {
        ($this->makeGame)(2026020001 + $day, ['game_date' => sprintf('2026-10-%02d', $day)]);
    }
    expect(($this->readGame)()['home']['form']['l10_gp'])->toBe(10)
        ->and(($this->readGame)()['home']['form']['gp'])->toBe(12);
});

it('lists the five most recent prior meetings without the selected result', function (): void {
    ($this->makeGame)();
    foreach (range(1, 6) as $day) {
        ($this->makeGame)(2026020001 + $day, ['game_date' => sprintf('2026-10-%02d', $day)]);
    }
    $meetings = ($this->readGame)()['head_to_head'];
    expect($meetings)->toHaveCount(5)->and($meetings[0]['date'])->toBe('2026-10-06')
        ->and(array_column($meetings, 'id'))->not->toContain(2026020001);
});

it('leaves missing imported statistics unknown', function (): void {
    ($this->makeGame)();
    $game = ($this->readGame)();
    expect($game['away']['sat'])->toBeNull()->and($game['away']['hits'])->toBeNull()->and($game['away']['boxscore'])->toBe([]);
});

it('preserves actual zero power play totals', function (): void {
    ($this->makeGame)(2026020001, ['away_pp_goals' => 0, 'away_pp_opportunities' => 3]);
    expect(($this->readGame)()['away']['pp_goals'])->toBe(0)->and(($this->readGame)()['away']['pp_opportunities'])->toBe(3);
});

it('reads imported scoring events in period order', function (): void {
    ($this->makeGame)();
    foreach ([3, 1] as $period) {
        DB::table('play_by_plays')->insert(['nhl_game_id' => 2026020001, 'type_desc_key' => 'goal', 'period' => $period,
            'scoring_player_id' => 100, 'event_owner_team_id' => 6, 'time_in_period' => '01:00']);
    }
    expect(array_column(($this->readGame)()['events'], 'period'))->toBe([1, 3]);
});

it('prepares a recap using the bounded refresh job', function (): void {
    ($this->makeGame)();
    (new RefreshNhlPublicGamePageJob(2026020001))->handle(app(NhlPublicGamePage::class));
    expect(($this->readGame)()['mode'])->toBe('recap');
    Http::assertNothingSent();
});

it('lets a refresh job for a deleted game exit harmlessly', function (): void {
    (new RefreshNhlPublicGamePageJob(9999999999))->handle(app(NhlPublicGamePage::class));
    expect(NhlGame::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('queues public page preparation after a canonical prediction', function (): void {
    ($this->makeGame)();
    ($this->savePrediction)();
    Bus::assertDispatched(RefreshNhlPublicGamePageJob::class, fn ($job): bool => $job->gameId === 2026020001 && $job->queue === 'default');
});

it('uses provider identities and boxscores for live games', function (): void {
    ($this->makeGame)(2026020001, ['game_date' => '2026-10-27', 'game_state' => 'LIVE']);
    Cache::put('nhl:live:snapshot:v1:2026020001', [
        'sequence' => 1, 'observed_at' => now()->toIso8601String(),
        'game' => ['nhl_game_id' => 2026020001, 'game_state' => 'LIVE', 'mode' => 'live',
            'away' => ['team_abbrev' => 'MTL', 'score' => 0, 'sog' => 12],
            'home' => ['team_abbrev' => 'NYR', 'score' => 1, 'sog' => 14]],
    ], 120);
    $this->get('/games/2026020001')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('game.mode', 'live')->where('game.away.team_abbrev', 'MTL')->where('game.away.score', 0));
});

it('does not expose stored scores when live provider evidence is unavailable', function (): void {
    ($this->makeGame)(2026020001, ['game_date' => '2026-10-27', 'game_state' => 'LIVE']);
    $this->live->shouldReceive('publicDetail')->once()->andReturnNull();
    $game = ($this->readGame)();
    expect($game['mode'])->toBe('live')->and($game['live_data_unavailable'])->toBeTrue()
        ->and($game['away'])->toBe([])->and($game['home'])->toBe([]);
});

it('invalidates a prepared snapshot explicitly', function (): void {
    ($this->makeGame)();
    ($this->readGame)();
    DB::table('nhl_games')->where('nhl_game_id', 2026020001)->update(['home_team_score' => 7]);
    app(NhlPublicGamePage::class)->forget(2026020001);
    expect(($this->readGame)()['home']['score'])->toBe(7);
});


it('keeps administrator controls outside the shared public payload cache', function (): void {
    ($this->makeGame)();
    $admin = User::factory()->create();
    $role = \App\Models\Role::query()->firstOrCreate(['slug' => 'super-admin'], ['name' => 'Super Admin', 'level' => 99]);
    $admin->roles()->attach($role->id, ['organization_id' => null]);
    $this->actingAs($admin)->get('/games/2026020001')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('canManageLineups', true));
    $this->actingAs(User::factory()->create())->get('/games/2026020001')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('canManageLineups', false));
});


it('renders the overview without calling the player projection builder on a cold cache', function (): void {
    ($this->makeGame)(2026020001, ['game_state' => 'FUT', 'game_date' => '2026-10-28']);
    $this->lineups->shouldNotReceive('gamePage');
    $this->get('/games/2026020001')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('game.away.team_abbrev', 'BOS')->where('game.home.team_abbrev', 'TOR')
        ->where('detailsUrl', route('games.details', 2026020001))
        ->missing('game.home.predictions')->missing('game.home.form')->missing('game.head_to_head'));
    Http::assertNothingSent();
});

it('defers recap boxscores and events while showing the final result immediately', function (): void {
    ($this->makeGame)();
    $this->get('/games/2026020001')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('game.home.score', 4)->missing('game.events')->missing('game.home.boxscore'));
});

it('queues cold lineup preparation instead of calculating during the section request', function (): void {
    ($this->makeGame)(2026020001, ['game_state' => 'FUT', 'game_date' => '2026-10-28']);
    $this->lineups->shouldNotReceive('gamePage');
    $this->getJson('/games/2026020001/details?section=lineups')->assertStatus(202)->assertJsonPath('status', 'preparing');
    Bus::assertDispatched(RefreshNhlPublicGamePageJob::class);
});

it('returns prepared lineup data without leaking overview fields into the deferred response', function (): void {
    ($this->makeGame)(2026020001, ['game_state' => 'FUT', 'game_date' => '2026-10-28']);
    app(NhlPublicGamePage::class)->prepare(2026020001);
    $this->getJson('/games/2026020001/details?section=lineups')->assertOk()
        ->assertJsonPath('section', 'lineups')->assertJsonMissingPath('data.home.score')
        ->assertJsonMissingPath('data.game_state')->assertJsonMissingPath('data.prediction');
});

it('allows guests to read matchup form without preparing player projections', function (): void {
    ($this->makeGame)(2026020001, ['game_state' => 'FUT', 'game_date' => '2026-10-28']);
    $this->lineups->shouldNotReceive('gamePage');
    $this->getJson('/games/2026020001/details?section=matchup')->assertOk()->assertJsonPath('data.home.form.gp', 0);
});

it('allows ordinary authenticated viewers to read the same deferred sections', function (): void {
    ($this->makeGame)();
    $this->actingAs(User::factory()->create())->getJson('/games/2026020001/details?section=meetings')->assertOk();
});

it('reads previous meetings independently of lineup preparation', function (): void {
    ($this->makeGame)(2026020001, ['game_state' => 'FUT', 'game_date' => '2026-10-28']);
    ($this->makeGame)(2026020002, ['game_date' => '2026-10-25']);
    $this->lineups->shouldNotReceive('gamePage');
    $this->getJson('/games/2026020001/details?section=meetings')->assertOk()->assertJsonPath('data.head_to_head.0.id', 2026020002);
});

it('rejects missing or invalid deferred section names', function (string $suffix): void {
    ($this->makeGame)();
    $this->getJson('/games/2026020001/details'.$suffix)->assertUnprocessable()->assertJsonValidationErrors('section');
})->with(['', '?section=private']);

it('returns not found for deferred sections of an unknown game', function (): void {
    $this->getJson('/games/9999999999/details?section=lineups')->assertNotFound();
});

it('does not run concurrent detail preparation while another builder holds the game lease', function (): void {
    ($this->makeGame)();
    $lock = Cache::lock('nhl:public-game:prepare:2026020001', 65);
    $lock->get();
    try {
        expect(app(NhlPublicGamePage::class)->prepare(2026020001))->toBeNull();
    } finally {
        $lock->release();
    }
});

it('exposes imported recap sections after the preparation job without changing the overview', function (): void {
    ($this->makeGame)();
    DB::table('nhl_boxscores')->insert(['nhl_game_id' => 2026020001, 'nhl_player_id' => 100,
        'nhl_team_id' => 6, 'position' => 'C', 'goals' => 2]);
    (new RefreshNhlPublicGamePageJob(2026020001))->handle(app(NhlPublicGamePage::class));
    $this->getJson('/games/2026020001/details?section=boxscore')->assertOk()->assertJsonPath('data.away.boxscore.0.goals', 2);
    $this->get('/games/2026020001')->assertOk()->assertInertia(fn (Assert $page) => $page->where('game.home.score', 4));
});


it('shows the saved normalized goal total on the lightweight public preview', function (): void {
    ($this->makeGame)(2026020001, ['game_state' => 'FUT', 'game_date' => '2026-10-28']);
    ($this->savePrediction)([], ['prediction' => ['predicted_score' => ['away' => 2.4, 'home' => 3.1],
        'predicted_total_goals' => 5.5, 'selection_score' => ['away' => 4.8, 'home' => 6.2]]]);
    $this->assertDatabaseHas('nhl_game_prediction_revisions', ['nhl_game_id' => 2026020001, 'is_canonical' => true]);
    $this->get('/games/2026020001')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('game.prediction.total_goals', 5.5)->where('game.prediction.score.home', 3.1)
        ->missing('game.prediction.selection_score'));
    Http::assertNothingSent();
});


it('projects shared live plays shot locations and names without exposing raw provider details', function (): void {
    $result = app(NhlPublicGamePage::class)->livePayload(2026020001, ($this->timelineProvider)())['game'];
    expect($result['plays'])->toHaveCount(2)
        ->and($result['plays'][0]['player'])->toBe('First Scorer')
        ->and($result['plays'][1]['player'])->toBe('Player #200')
        ->and($result['plays'][1])->not->toHaveKey('details')
        ->and($result['shots'][0]['x'])->toBe(0.0)
        ->and($result['shots'][0]['y'])->toBe(0.0)
        ->and($result['shots'][0]['side'])->toBe('away');
    Http::assertNothingSent();
});

it('omits invalid or unknown-team rink coordinates', function (mixed $x, mixed $y, ?int $team): void {
    $provider = ($this->timelineProvider)();
    $provider['plays'][1]['details'] = ['xCoord' => $x, 'yCoord' => $y, 'eventOwnerTeamId' => $team];
    $result = app(NhlPublicGamePage::class)->livePayload(2026020001, $provider)['game'];
    expect($result['plays'])->toHaveCount(2)->and($result['shots'])->toHaveCount(1);
})->with([[null, 10, 10], [101, 10, 10], [20, 43, 10], [20, -43, 10], [20, 10, null], [20, 10, 999]]);

it('keeps unstarted periods unknown while showing verified current-period goals', function (): void {
    $result = app(NhlPublicGamePage::class)->livePayload(2026020001, ($this->timelineProvider)())['game'];
    expect($result['period_scores'][0])->toBe(['period' => 1, 'label' => '1st', 'away' => 1, 'home' => 0])
        ->and($result['period_scores'][1]['away'])->toBeNull()
        ->and($result['period_scores'][2]['home'])->toBeNull();
});

it('does not publish period zeros when the score and play source are inconsistent', function (): void {
    $provider = ($this->timelineProvider)();
    $provider['game']['away']['score'] = 2;
    $result = app(NhlPublicGamePage::class)->livePayload(2026020001, $provider)['game'];
    expect($result['period_scores'][0]['away'])->toBeNull()->and($result['away']['score'])->toBe(2);
});

it('keeps missing play-by-play explicitly unavailable', function (): void {
    $provider = ($this->timelineProvider)();
    $provider['events_available'] = false;
    $provider['plays'] = [];
    $result = app(NhlPublicGamePage::class)->livePayload(2026020001, $provider)['game'];
    expect($result['plays_available'])->toBeFalse()->and($result['plays'])->toBeEmpty()
        ->and($result['period_scores'][0]['home'])->toBeNull();
});

it('excludes shootout attempts from normal shot and scoring rows while showing the deciding goal separately', function (): void {
    $provider = ($this->timelineProvider)();
    $provider['game']['away']['score'] = 2;
    $provider['boxscore']['periodDescriptor'] = ['number' => 5, 'periodType' => 'SO'];
    $provider['game']['home']['score'] = 1;
    $homeGoal = $provider['plays'][0];
    $homeGoal['eventId'] = 3;
    $homeGoal['periodDescriptor'] = ['number' => 3, 'periodType' => 'REG'];
    $homeGoal['details']['eventOwnerTeamId'] = 10;
    $provider['plays'][] = $homeGoal;
    $shootout = $provider['plays'][0];
    $shootout['eventId'] = 4;
    $shootout['periodDescriptor'] = ['number' => 5, 'periodType' => 'SO'];
    $provider['plays'][] = $shootout;
    $result = app(NhlPublicGamePage::class)->livePayload(2026020001, $provider)['game'];
    expect($result['events'])->toHaveCount(2)->and($result['shots'])->toHaveCount(3)
        ->and($result['period_scores'][4])->toBe(['period' => 5, 'label' => 'SO', 'away' => 1, 'home' => 0]);
});

it('reads prepared imported play-by-play publicly with batched participant names and an allowlisted payload', function (bool $authenticated): void {
    if ($authenticated) {
        $this->actingAs(User::factory()->create());
    }
    ($this->makeGame)(2026020001, ['away_team_score' => 2, 'home_team_score' => 0]);
    foreach ([100 => 'First Scorer', 101 => 'Second Scorer'] as $id => $name) {
        DB::table('players')->insert(['nhl_id' => $id, 'full_name' => $name, 'first_name' => 'Scorer', 'last_name' => (string) $id]);
        DB::table('play_by_plays')->insert(['nhl_game_id' => 2026020001, 'nhl_event_id' => (string) $id,
            'type_desc_key' => 'goal', 'period' => 1, 'period_type' => 'REG', 'scoring_player_id' => $id,
            'event_owner_team_id' => 6, 'time_in_period' => '01:00', 'x_coord' => 70, 'y_coord' => 10]);
    }
    $this->assertDatabaseCount('play_by_plays', 2);
    (new RefreshNhlPublicGamePageJob(2026020001))->handle(app(NhlPublicGamePage::class));
    $this->getJson('/games/2026020001/details?section=play_by_play')->assertOk()
        ->assertJsonPath('data.plays_available', true)->assertJsonCount(2, 'data.shots')
        ->assertJsonPath('data.plays.0.player', 'First Scorer')->assertJsonPath('data.plays.1.player', 'Second Scorer')
        ->assertJsonMissingPath('data.away')->assertJsonMissingPath('data.evidence');
    $this->get('/games/2026020001')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->missing('game.plays')->missing('game.shots')->missing('game.period_scores'));
    Http::assertNothingSent();
})->with(['guest' => [false], 'ordinary user' => [true]]);


it('counts live attempts independently of rink coordinates and excludes shootouts and unknown teams', function (): void {
    $provider = ($this->timelineProvider)();
    foreach (['missed-shot', 'blocked-shot'] as $index => $type) {
        $provider['plays'][] = ['eventId' => 3 + $index, 'typeDescKey' => $type,
            'periodDescriptor' => ['number' => 1, 'periodType' => 'REG'],
            'details' => ['eventOwnerTeamId' => 6]];
    }
    $provider['plays'][] = ['eventId' => 5, 'typeDescKey' => 'goal',
        'periodDescriptor' => ['number' => 5, 'periodType' => 'SO'], 'details' => ['eventOwnerTeamId' => 6]];
    $provider['plays'][] = ['eventId' => 6, 'typeDescKey' => 'shot-on-goal',
        'periodDescriptor' => ['number' => 1, 'periodType' => 'REG'], 'details' => ['eventOwnerTeamId' => 999]];
    $game = app(NhlPublicGamePage::class)->livePayload(2026020001, $provider)['game'];
    expect($game['away']['sat'])->toBe(3)->and($game['home']['sat'])->toBe(1)
        ->and($game['shots'])->toHaveCount(2)
        ->and(collect($game['comparison'])->firstWhere('label', 'Shot attempts'))
        ->toBe(['label' => 'Shot attempts', 'away' => 3, 'home' => 1]);
    Http::assertNothingSent();
});

it('distinguishes unknown live attempts from a genuine recorded zero', function (): void {
    $provider = ($this->timelineProvider)();
    $provider['plays'] = [];
    $game = app(NhlPublicGamePage::class)->livePayload(2026020001, $provider)['game'];
    expect($game['away']['sat'])->toBe(0);
    $provider['events_available'] = false;
    $game = app(NhlPublicGamePage::class)->livePayload(2026020001, $provider)['game'];
    expect($game['away']['sat'])->toBeNull()->and($game['home']['sat'])->toBeNull();
});

it('derives goalie saves only from known provider shot and goal counts', function (): void {
    $provider = ($this->timelineProvider)();
    $provider['boxscore']['playerByGameStats']['awayTeam']['goalies'] = [
        ['playerId' => 301, 'shotsAgainst' => 20, 'goalsAgainst' => 1],
        ['playerId' => 302, 'shotsAgainst' => 10],
        ['playerId' => 303, 'shotsAgainst' => 0, 'goalsAgainst' => 0],
    ];
    $rows = app(NhlPublicGamePage::class)->livePayload(2026020001, $provider)['game']['away']['boxscore'];
    expect($rows[0]['saves'])->toBe(19)->and($rows[0]['shots_against'])->toBe(20)
        ->and($rows[1]['saves'])->toBeNull()->and($rows[2]['saves'])->toBe(0);
});

it('serves newly calculated live SAT and goalie facts through public deferred reads', function (bool $authenticated): void {
    if ($authenticated) {
        $this->actingAs(User::factory()->create());
    }
    ($this->makeGame)(2026020001, ['game_date' => '2026-10-27', 'game_state' => 'LIVE']);
    $provider = ($this->timelineProvider)();
    $provider['boxscore']['playerByGameStats']['awayTeam']['goalies'] = [
        ['playerId' => 301, 'shotsAgainst' => 20, 'goalsAgainst' => 1, 'toi' => '20:00'],
    ];
    $game = app(NhlPublicGamePage::class)->livePayload(2026020001, $provider)['game'];
    Cache::put('nhl:live:snapshot:v1:2026020001', [
        'sequence' => 1, 'observed_at' => now()->toIso8601String(), 'game' => $game,
    ], 120);
    $this->getJson('/games/2026020001/details?section=summary')->assertOk()
        ->assertJsonPath('data.comparison.1.label', 'Shot attempts')
        ->assertJsonPath('data.comparison.1.away', 1)->assertJsonPath('data.comparison.1.home', 1);
    $this->getJson('/games/2026020001/details?section=boxscore')->assertOk()
        ->assertJsonPath('data.away.boxscore.0.saves', 19)->assertJsonPath('data.away.boxscore.0.shots_against', 20);
    Http::assertNothingSent();
})->with(['guest' => [false], 'ordinary user' => [true]]);
