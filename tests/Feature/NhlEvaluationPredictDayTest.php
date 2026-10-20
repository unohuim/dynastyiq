<?php

declare(strict_types=1);

use App\Jobs\ForecastNhlEvaluationPlayerJob;
use App\Jobs\PredictNhlEvaluationDayJob;
use App\Models\NhlEvaluationGameForecast;
use App\Models\NhlGame;
use App\Models\NhlModelRun;
use App\Models\NhlNextGameEvaluation;
use App\Models\Role;
use App\Models\User;
use App\Services\NhlEvaluationGameForecaster;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-13 12:00:00 UTC'));
    Bus::fake();
    config(['queue.default' => 'redis']);
    $this->mock(\App\Services\PlatformState::class)->shouldReceive('seeded')->andReturn(true);
    $this->admin = User::factory()->create();
    $role = Role::firstOrCreate(['slug' => 'super-admin'], ['name' => 'Super Admin', 'level' => 99]);
    $this->admin->roles()->attach($role->id, ['organization_id' => null]);
    $model = NhlModelRun::create(['run_key' => 'predict-day', 'name' => 'Day model', 'model_family' => 'sat',
        'workflow_stage' => 'training', 'model_version' => 'test', 'train_season_ids' => ['20242025'],
        'target_season_id' => '20252026', 'status' => 'complete']);
    $this->evaluation = NhlNextGameEvaluation::create(['model_run_id' => $model->id, 'season_id' => '20252026',
        'version' => NhlNextGameEvaluation::OUTLOOK_VERSION, 'status' => 'ready', 'inputs' => ['preserved' => true]]);
    foreach ([2025020001, 2025020002] as $id) {
        NhlGame::create(['nhl_game_id' => $id, 'season_id' => '20252026', 'game_type' => 2,
            'game_date' => '2025-10-07', 'game_dow' => 'Tue', 'game_month' => 'Oct',
            'start_time_utc' => '2025-10-07 21:00:00', 'game_state' => 'OFF']);
    }
    $this->url = route('admin.nhl-sat-models.next-game.predict-day', $this->evaluation);
    $this->input = ['date' => '2025-10-07', 'strength' => 'ev', 'period' => 'q1'];
    $this->service = Mockery::mock(NhlEvaluationGameForecaster::class)->makePartial();
    $this->service->shouldReceive('lineup')->andReturn([['player_id' => 1, 'team_id' => 1, 'name' => 'Player', 'position' => 'C']]);
    $this->tick = function (): void {
        $day = $this->evaluation->fresh()->inputs['predict_day'];
        (new PredictNhlEvaluationDayJob($this->evaluation->id, $day['token']))->handle($this->service);
    };
    $this->displayRow = function (int $forecastId, int $playerId, int $teamId, array $overrides = []): void {
        DB::table('nhl_evaluation_game_player_forecasts')->insert(array_replace([
            'forecast_id' => $forecastId, 'nhl_player_id' => $playerId, 'nhl_team_id' => $teamId,
            'player_name' => 'Player '.$playerId, 'sat_per_60' => 20, 'toi_per_game_seconds' => 900,
            'sat_direction' => 'higher',
            'inputs' => json_encode(['private_provenance' => str_repeat('unused', 1000),
                'quarter_sat_per_60' => 18, 'quarter_toi_seconds' => 840,
                'context' => ['version' => NhlEvaluationGameForecaster::CONTEXT_VERSION, 'role' => 'skater', 'position' => 'C',
                    'team_records' => ['season' => ['games' => 1, 'wins' => 1, 'losses' => 0, 'win_pct' => 100]],
                    'windows' => ['train' => ['sat_per_60' => 17, 'games' => 2, 'toi_seconds' => 1800, 'pdo' => 101]]]]),
            'comparison' => json_encode(['evidence_version' => NhlEvaluationGameForecaster::CONTEXT_VERSION,
                'sat_per_60' => 22, 'toi_seconds' => 990, 'unused_evidence' => 'not displayed']),
        ], $overrides));
    };
});

afterEach(function (): void {
    $this->travelBack();
});

it('simulates unfinished games chronologically through the inclusive selected date with one coordinator', function (): void {
    NhlGame::whereKey(2025020002)->update(['game_date' => '2025-10-09', 'start_time_utc' => '2025-10-09 21:00:00']);
    $url = route('admin.nhl-sat-models.next-game.simulate', $this->evaluation);
    $input = [...$this->input, 'date' => '2025-10-09'];
    $this->actingAs($this->admin)->postJson($url, $input)->assertAccepted();
    $day = $this->evaluation->fresh()->inputs['predict_day'];
    expect($day['game_ids'])->toBe([2025020001, 2025020002])->and($day['mode'])->toBe('simulation');
    $this->postJson($url, $input)->assertAccepted();
    Bus::assertDispatchedTimes(PredictNhlEvaluationDayJob::class, 1);
    Bus::assertNotDispatched(ForecastNhlEvaluationPlayerJob::class);
    $this->get(route('admin.nhl-sat-models.next-game.games', $this->evaluation).'?date=2025-10-09')
        ->assertOk()->assertInertia(fn (Assert $page) => $page->where('dayWork.mode', 'simulation')
            ->where('dayWork.start_date', '2025-10-07')->where('dayWork.date', '2025-10-09')->where('dayWork.total', 2));
    ($this->tick)();
    expect(NhlEvaluationGameForecast::sole()->nhl_game_id)->toBe(2025020001);
    $this->postJson($this->url, $this->input)->assertConflict();
});

it('skips compared simulation games and excludes dates after the target', function (): void {
    $this->actingAs($this->admin)->postJson($this->url, $this->input)->assertAccepted();
    ($this->tick)();
    $work = NhlEvaluationGameForecast::sole();
    $work->update(['status' => 'compared', 'lineup' => [[...$work->lineup[0], 'evidence_version' => NhlEvaluationGameForecaster::CONTEXT_VERSION]]]);
    $day = $this->evaluation->fresh()->inputs['predict_day'];
    $this->evaluation->update(['inputs' => ['predict_day' => [...$day, 'status' => 'completed']]]);
    NhlGame::whereKey(2025020002)->update(['game_date' => '2025-10-09', 'start_time_utc' => '2025-10-09 21:00:00']);
    Bus::fake();
    $this->postJson(route('admin.nhl-sat-models.next-game.simulate', $this->evaluation), $this->input)->assertAccepted();
    expect($this->evaluation->fresh()->inputs['predict_day']['game_ids'])->toBe([])
        ->and($this->evaluation->fresh()->inputs['predict_day']['status'])->toBe('completed');
    Bus::assertNotDispatched(PredictNhlEvaluationDayJob::class);
    $this->postJson(route('admin.nhl-sat-models.next-game.simulate', $this->evaluation), [...$this->input, 'date' => '2025-10-09'])->assertAccepted();
    expect($this->evaluation->fresh()->inputs['predict_day']['game_ids'])->toBe([2025020002]);
});

it('protects simulation with the existing admin authorization', function (): void {
    $url = route('admin.nhl-sat-models.next-game.simulate', $this->evaluation);
    $this->postJson($url, $this->input)->assertUnauthorized();
    $this->actingAs(User::factory()->create())->postJson($url, $this->input)->assertForbidden();
    Bus::assertNotDispatched(PredictNhlEvaluationDayJob::class);
});

it('persists the selected day and exposes progress without dispatching every game', function (): void {
    $this->actingAs($this->admin)->postJson($this->url, $this->input)->assertAccepted();
    $day = $this->evaluation->fresh()->inputs['predict_day'];
    expect($day['game_ids'])->toBe([2025020001, 2025020002])
        ->and($day['strength'])->toBe('ev')->and($day['period'])->toBe('q1')
        ->and($this->evaluation->fresh()->inputs['preserved'])->toBeTrue();
    Bus::assertDispatchedTimes(PredictNhlEvaluationDayJob::class, 1);
    Bus::assertNotDispatched(ForecastNhlEvaluationPlayerJob::class);
    $this->get(route('admin.nhl-sat-models.next-game.games', $this->evaluation).'?date=2025-10-07')
        ->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/Evaluations/Games')
            ->where('dayWork.total', 2)->where('dayWork.cursor', 0)->where('dayWork.status', 'running')
            ->missing('dayWork.token')->missing('dayWork.game_ids'));
});

it('deduplicates repeat clicks and rejects a different active selection', function (): void {
    $this->actingAs($this->admin)->postJson($this->url, $this->input)->assertAccepted();
    $this->postJson($this->url, $this->input)->assertAccepted();
    $this->postJson($this->url, [...$this->input, 'strength' => 'pp'])->assertConflict();
    Bus::assertDispatchedTimes(PredictNhlEvaluationDayJob::class, 1);
});

it('admits only one game and yields while its player chain is active', function (): void {
    $this->actingAs($this->admin)->postJson($this->url, $this->input)->assertAccepted();
    ($this->tick)();
    ($this->tick)();
    expect(NhlEvaluationGameForecast::count())->toBe(1)
        ->and($this->evaluation->fresh()->inputs['predict_day']['cursor'])->toBe(0);
    Bus::assertDispatchedTimes(ForecastNhlEvaluationPlayerJob::class, 1);
    Bus::assertDispatched(PredictNhlEvaluationDayJob::class, fn ($job): bool => $job->queue === 'projections' && $job->delay === 30);
});

it('advances after completion and preserves saved forecasts on another day request', function (): void {
    $this->actingAs($this->admin)->postJson($this->url, $this->input)->assertAccepted();
    ($this->tick)();
    $first = NhlEvaluationGameForecast::sole();
    $first->update(['status' => 'compared', 'phase' => 'compare', 'forecasted_at' => now(),
        'lineup' => [[...$first->lineup[0], 'evidence_version' => NhlEvaluationGameForecaster::CONTEXT_VERSION]]]);
    $original = $first->fresh()->getRawOriginal();
    ($this->tick)();
    ($this->tick)();
    expect(NhlEvaluationGameForecast::count())->toBe(2);
    NhlEvaluationGameForecast::where('nhl_game_id', 2025020002)->update(['status' => 'compared', 'phase' => 'compare']);
    ($this->tick)();
    expect($this->evaluation->fresh()->inputs['predict_day']['status'])->toBe('completed');
    $this->postJson($this->url, $this->input)->assertAccepted();
    ($this->tick)();
    ($this->tick)();
    expect($first->fresh()->getRawOriginal())->toBe($original);
    Bus::assertDispatchedTimes(ForecastNhlEvaluationPlayerJob::class, 2);
});

it('stops the day on a failed game instead of starting the next game', function (): void {
    $this->actingAs($this->admin)->postJson($this->url, $this->input)->assertAccepted();
    ($this->tick)();
    NhlEvaluationGameForecast::query()->update(['status' => 'failed']);
    ($this->tick)();
    expect($this->evaluation->fresh()->inputs['predict_day']['status'])->toBe('failed')
        ->and(NhlEvaluationGameForecast::count())->toBe(1);
});

it('ignores old coordinator deliveries and failure callbacks after progress', function (): void {
    $this->actingAs($this->admin)->postJson($this->url, $this->input)->assertAccepted();
    $token = $this->evaluation->fresh()->inputs['predict_day']['token'];
    ($this->tick)();
    $stale = new PredictNhlEvaluationDayJob($this->evaluation->id, $token);
    $stale->handle($this->service);
    $stale->failed(new \RuntimeException('Old failure'));
    expect($this->evaluation->fresh()->inputs['predict_day']['status'])->toBe('running');
    Bus::assertDispatchedTimes(ForecastNhlEvaluationPlayerJob::class, 1);
});

it('rejects days without eligible games and evaluations still building', function (): void {
    $this->actingAs($this->admin)->postJson($this->url, [...$this->input, 'date' => '2024-10-07'])->assertUnprocessable();
    $this->postJson($this->url, [...$this->input, 'strength' => 'invalid'])->assertUnprocessable();
    $this->evaluation->update(['status' => 'building']);
    $this->postJson($this->url, $this->input)->assertUnprocessable();
    Bus::assertNothingDispatched();
});

it('blocks guests and ordinary users from Predict Day', function (): void {
    $this->postJson($this->url, $this->input)->assertUnauthorized();
    $this->actingAs(User::factory()->create())->postJson($this->url, $this->input)->assertForbidden();
    Bus::assertNothingDispatched();
});

it('resumes a stalled coordinator with a new token without resetting forecasts', function (): void {
    $this->actingAs($this->admin)->postJson($this->url, $this->input)->assertAccepted();
    $old = $this->evaluation->fresh()->inputs['predict_day']['token'];
    $this->travel(4)->minutes();
    $this->postJson($this->url, $this->input)->assertAccepted();
    expect($this->evaluation->fresh()->inputs['predict_day']['token'])->not->toBe($old);
    Bus::assertDispatchedTimes(PredictNhlEvaluationDayJob::class, 2);
});

it('does not allow deleting an evaluation between day games', function (): void {
    $this->actingAs($this->admin)->postJson($this->url, $this->input)->assertAccepted();
    $this->deleteJson(route('admin.nhl-sat-models.next-game.destroy', $this->evaluation))->assertConflict();
    expect($this->evaluation->fresh())->not->toBeNull();
});

it('automatically starts bounded comparison after the final prediction checkpoint', function (): void {
    $this->actingAs($this->admin)->postJson($this->url, $this->input)->assertAccepted();
    ($this->tick)();
    $work = NhlEvaluationGameForecast::sole();
    $work->update(['cursor' => $work->total]);
    $originalLineup = $work->lineup;
    Bus::fake();
    (new ForecastNhlEvaluationPlayerJob($work->id, $work->work_token))->handle($this->service);
    $work->refresh();
    expect($work->status)->toBe('comparing')->and($work->phase)->toBe('compare')
        ->and($work->cursor)->toBe(0)->and($work->forecasted_at)->not->toBeNull()
        ->and($work->lineup)->toBe($originalLineup);
    Bus::assertDispatchedTimes(ForecastNhlEvaluationPlayerJob::class, 1);
    Bus::assertDispatched(ForecastNhlEvaluationPlayerJob::class,
        fn ($job): bool => $job->queue === 'projections' && $job->forecastId === $work->id);
});

it('waits for actuals before advancing to the next game', function (): void {
    $this->actingAs($this->admin)->postJson($this->url, $this->input)->assertAccepted();
    ($this->tick)();
    $work = NhlEvaluationGameForecast::sole();
    $work->update(['status' => 'comparing', 'phase' => 'compare', 'forecasted_at' => now()]);
    ($this->tick)();
    expect($this->evaluation->fresh()->inputs['predict_day']['cursor'])->toBe(0)
        ->and(NhlEvaluationGameForecast::count())->toBe(1);
    $work->update(['cursor' => $work->total]);
    (new ForecastNhlEvaluationPlayerJob($work->id, $work->work_token))->handle($this->service);
    expect($work->fresh()->status)->toBe('compared')->and($work->fresh()->compared_at)->not->toBeNull();
    ($this->tick)();
    expect($this->evaluation->fresh()->inputs['predict_day']['cursor'])->toBe(1);
});

it('compares a current ready forecast without rebuilding its predictions', function (): void {
    $this->actingAs($this->admin)->postJson($this->url, $this->input)->assertAccepted();
    ($this->tick)();
    $work = NhlEvaluationGameForecast::sole();
    $work->update(['status' => 'ready', 'forecasted_at' => now(),
        'lineup' => [[...$work->lineup[0], 'evidence_version' => NhlEvaluationGameForecaster::CONTEXT_VERSION]]]);
    $before = $work->lineup;
    Bus::fake();
    ($this->tick)();
    ($this->tick)();
    expect($work->fresh()->status)->toBe('comparing')
        ->and($work->fresh()->lineup)->toBe($before)
        ->and($this->evaluation->fresh()->inputs['predict_day']['cursor'])->toBe(0);
    Bus::assertDispatchedTimes(ForecastNhlEvaluationPlayerJob::class, 1);
});

it('does not start comparison early or alter context-only completion', function (): void {
    $this->actingAs($this->admin)->postJson($this->url, $this->input)->assertAccepted();
    ($this->tick)();
    $work = NhlEvaluationGameForecast::sole();
    expect($work->phase)->toBe('forecast')->and($work->forecasted_at)->toBeNull();
    $work->update(['phase' => 'context', 'cursor' => 1,
        'lineup' => [['context_return_status' => 'compared']]]);
    $this->service->advance($work);
    expect($work->status)->toBe('compared')->and($work->phase)->toBe('context');
});

it('returns only selected-player display fields and compact league totals through HTTP', function (): void {
    $this->actingAs($this->admin)->postJson($this->url, $this->input)->assertAccepted();
    ($this->tick)();
    $work = NhlEvaluationGameForecast::sole();
    $work->update(['status' => 'compared']);
    ($this->displayRow)($work->id, 1, 1);
    ($this->displayRow)($work->id, 2, 1, ['sat_per_60' => 12, 'toi_per_game_seconds' => 1200]);
    ($this->displayRow)($work->id, 3, 2);
    $this->get(route('admin.nhl-sat-models.next-game.games', $this->evaluation).'?date=2025-10-07')
        ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('players', 3)->has('leagueSummaries', 3)->missing('leagueLineups')
            ->missing('players.0.inputs.private_provenance')
            ->missing('players.0.inputs.context.windows.train.pdo')
            ->missing('players.1.inputs.context.team_records')
            ->missing('players.0.comparison.unused_evidence')
            ->where('players.0.inputs.context.windows.train.sat_per_60', 17)
            ->where('leagueSummaries.2.predicted', 7)
            ->where('leagueSummaries.0.history.train', 25.5)
            ->where('leagueSummaries.0.teams', 2)
            ->where('leagueSummaries.0.measuredPlayers', 3));
});

it('preserves estimated inputs and mismatched comparison coverage in league summaries', function (): void {
    $this->actingAs($this->admin)->postJson($this->url, $this->input)->assertAccepted();
    ($this->tick)();
    $work = NhlEvaluationGameForecast::sole();
    $work->update(['status' => 'compared']);
    ($this->displayRow)($work->id, 1, 1);
    ($this->displayRow)($work->id, 2, 1, ['sat_per_60' => null, 'comparison' => null]);
    ($this->displayRow)($work->id, 3, 2, ['comparison' => null]);
    $this->get(route('admin.nhl-sat-models.next-game.games', $this->evaluation).'?date=2025-10-07')
        ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('leagueSummaries.0.predicted', 30)
            ->where('leagueSummaries.0.estimated.predicted', 1)
            ->where('leagueSummaries.0.coverage.predicted', 2)
            ->where('leagueSummaries.0.coverage.measured', 1)
            ->where('leagueSummaries.0.error', null)
            ->where('leagueSummaries.0.history.last_5', null)
            ->where('leagueSummaries.0.measuredPlayers', 1));
});

it('includes other published lineups in totals without publishing their player data', function (): void {
    $this->actingAs($this->admin)->postJson($this->url, $this->input)->assertAccepted();
    ($this->tick)();
    $first = NhlEvaluationGameForecast::sole();
    $first->update(['status' => 'compared']);
    ($this->displayRow)($first->id, 1, 1);
    ($this->tick)();
    ($this->tick)();
    $second = NhlEvaluationGameForecast::where('nhl_game_id', 2025020002)->firstOrFail();
    $second->update(['status' => 'ready']);
    ($this->displayRow)($second->id, 2, 2, ['sat_per_60' => 40]);
    $this->get(route('admin.nhl-sat-models.next-game.games', $this->evaluation).'?date=2025-10-07&forecast='.$first->id)
        ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('players', 1)->where('players.0.nhl_player_id', 1)->missing('leagueLineups')
            ->where('leagueSummaries.0.predicted', 30)
            ->where('leagueSummaries.0.coverage.measured', 1));
});

it('keeps a fourteen-game response bounded to selected-player data and league summaries', function (): void {
    $this->actingAs($this->admin)->postJson($this->url, $this->input)->assertAccepted();
    ($this->tick)();
    $first = NhlEvaluationGameForecast::sole();
    $first->update(['status' => 'compared']);
    $source = NhlGame::findOrFail(2025020001);
    for ($game = 0; $game < 14; $game++) {
        $id = 2025020001 + $game;
        if (! NhlGame::find($id)) {
            $copy = $source->replicate();
            $copy->nhl_game_id = $id;
            $copy->save();
        }
        $work = $game === 0 ? $first : $first->replicate();
        if ($game !== 0) {
            $work->nhl_game_id = $id;
            $work->save();
        }
        for ($player = 1; $player <= 36; $player++) {
            ($this->displayRow)($work->id, $game * 100 + $player, $player <= 18 ? 1 : 2);
        }
    }
    $response = $this->getJson(route('admin.nhl-sat-models.next-game.games', $this->evaluation)
        .'?date=2025-10-07&forecast='.$first->id, ['X-Inertia' => 'true'])->assertOk();
    expect($response->json('props.players'))->toHaveCount(36)
        ->and($response->json('props.leagueSummaries.0.teams'))->toBe(28)
        ->and($response->json('props.leagueLineups'))->toBeNull()
        ->and(strlen($response->getContent()))->toBeLessThan(100000);
});

it('publishes separate quarter blend errors and player accuracy without replacing saved predictions', function (): void {
    $this->actingAs($this->admin)->postJson($this->url, $this->input)->assertAccepted();
    ($this->tick)();
    $work = NhlEvaluationGameForecast::sole();
    $work->update(['status' => 'compared']);
    ($this->displayRow)($work->id, 1, 1, ['inputs' => json_encode([
        'quarter_sat_per_60' => 999,
        'context' => ['version' => NhlEvaluationGameForecaster::CONTEXT_VERSION, 'role' => 'skater', 'windows' => [
            'last_season' => ['sat_per_60' => 20, 'games' => 1, 'toi_seconds' => 900],
            'last_20' => ['sat_per_60' => 24, 'games' => 1, 'toi_seconds' => 1080],
        ]],
    ])]);
    $this->get(route('admin.nhl-sat-models.next-game.games', $this->evaluation).'?date=2025-10-07')
        ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('leagueSummaries.0.quarter', 22)->where('leagueSummaries.0.predicted', 20)
            ->where('leagueSummaries.0.error', 10)->where('leagueSummaries.0.quarterError', 0)
            ->where('leagueSummaries.0.quarterUnder10', 100)->where('leagueSummaries.0.quarterUnder20', 100)
            ->where('leagueSummaries.1.quarter', 16.5)->where('leagueSummaries.2.quarter', 6.05));
    $this->assertDatabaseHas('nhl_evaluation_game_player_forecasts', ['forecast_id' => $work->id, 'sat_per_60' => 20]);
});

it('includes stale compared evidence in simulation without dispatching a game burst', function (): void {
    $this->actingAs($this->admin)->postJson($this->url, $this->input)->assertAccepted();
    ($this->tick)();
    $work = NhlEvaluationGameForecast::sole();
    $work->update(['status' => 'compared', 'forecasted_at' => now()]);
    $day = $this->evaluation->fresh()->inputs['predict_day'];
    $this->evaluation->update(['inputs' => ['predict_day' => [...$day, 'status' => 'completed']]]);
    Bus::fake();
    $this->postJson(route('admin.nhl-sat-models.next-game.simulate', $this->evaluation), $this->input)->assertAccepted();
    expect($this->evaluation->fresh()->inputs['predict_day']['game_ids'])->toBe([2025020001, 2025020002]);
    ($this->tick)();
    expect($work->fresh()->phase)->toBe('context')->and($work->fresh()->status)->toBe('queued');
    Bus::assertDispatchedTimes(ForecastNhlEvaluationPlayerJob::class, 1);
    ($this->tick)();
    Bus::assertDispatchedTimes(ForecastNhlEvaluationPlayerJob::class, 1);
});

it('refreshes saved windows previous and actuals through bounded jobs while preserving the prediction', function (): void {
    $this->evaluation->update(['inputs' => ['training_seasons' => ['20242025']]]);
    $game = NhlGame::findOrFail(2025020001);
    $game->update(['home_team_id' => 10, 'away_team_id' => 20]);
    $previous = $game->replicate();
    $previous->fill(['nhl_game_id' => 2024020001, 'season_id' => '20242025',
        'game_date' => '2025-04-01', 'start_time_utc' => '2025-04-01 21:00:00'])->save();
    $player = DB::table('players')->insertGetId(['nhl_id' => 101, 'first_name' => 'Test',
        'last_name' => 'Skater', 'full_name' => 'Test Skater', 'position' => 'C', 'pos_type' => 'F']);
    foreach ([$previous->nhl_game_id, $game->nhl_game_id] as $id) {
        DB::table('nhl_game_summaries')->insert(['nhl_game_id' => $id, 'nhl_player_id' => 101,
            'nhl_team_id' => 10, 'toi' => 600, 'sat' => 2, 'sog' => 2]);
        DB::table('nhl_player_game_strength_summaries')->insert(['nhl_game_id' => $id,
            'player_id' => $player, 'nhl_player_id' => 101, 'team_id' => 10, 'strength' => 'EV', 'toi' => 600]);
        foreach (['wrist', 'backhand'] as $type) {
            $play = DB::table('play_by_plays')->insertGetId(['nhl_game_id' => $id,
                'period' => 1, 'seconds_in_game' => 120, 'type_desc_key' => 'shot-on-goal']);
            DB::table('nhl_shot_attempts_facts')->insert(['play_by_play_id' => $play, 'nhl_game_id' => $id,
                'shooter_player_id' => 101, 'attempt_result' => 'shot', 'is_shot_attempt' => true,
                'is_shot_on_goal' => true, 'strength' => 'EV', 'strength_bucket' => 'ev',
                'shot_type_bucket' => $type, 'period_type' => 'REG']);
        }
    }
    $run = \App\Models\NhlPregameContextRun::create(['action' => 'backfill', 'status' => 'running', 'season_ids' => ['20252026']]);
    app(\App\Services\NhlPregameContextBuilder::class)->build($run, $game->nhl_game_id);
    $service = app(NhlEvaluationGameForecaster::class);
    $lineup = $service->lineup($this->evaluation, $game);
    $lineup[0]['bucket_filter'] = ['version' => 1, 'minimum_confidence' => 0.5,
        'feature_config' => ['fallback_levels' => [1 => ['shot_type_group']]],
        'keys' => ['L01|shot_type_group=wrist']];
    $work = NhlEvaluationGameForecast::create(['evaluation_id' => $this->evaluation->id,
        'nhl_game_id' => $game->nhl_game_id, 'strength' => 'ev', 'period' => 'q1',
        'status' => 'compared', 'phase' => 'compare', 'total' => 1, 'cursor' => 1,
        'work_token' => '00000000-0000-4000-8000-000000000001',
        'lineup' => $lineup, 'forecasted_at' => now(), 'source_cutoff_at' => $game->start_time_utc]);
    $original = ['method' => 'quarter_direction_l20_toi_v1', 'previous_game_id' => $previous->nhl_game_id,
        'previous' => ['sat_per_60' => 12, 'toi_seconds' => 600],
        'windows' => ['last_20' => ['sat_per_60' => 12, 'games' => 1, 'toi_seconds' => 600]],
        'context' => ['version' => 5, 'role' => 'skater', 'windows' => []]];
    ($this->displayRow)($work->id, 101, 10, ['sat_per_60' => 9, 'toi_per_game_seconds' => 600,
        'inputs' => json_encode($original), 'comparison' => json_encode(['sat_per_60' => 12, 'toi_seconds' => 600])]);
    $read = route('admin.nhl-sat-models.next-game.games', $this->evaluation).'?date=2025-10-07';
    $this->actingAs($this->admin)->get($read)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('players.0.comparison', null)->missing('players.0.inputs.context.windows.last_20'));
    Bus::assertNothingDispatched();
    $url = route('admin.nhl-sat-models.next-game.games.update', [$this->evaluation, $work]);
    $this->postJson($url, ['action' => 'context'])->assertAccepted();
    $this->postJson($url, ['action' => 'context'])->assertAccepted();
    Bus::assertDispatchedTimes(ForecastNhlEvaluationPlayerJob::class, 1);
    // Context player, transition, comparison player, then final publication.
    for ($checkpoint = 0; $checkpoint < 4; $checkpoint++) {
        $work->refresh();
        $job = new ForecastNhlEvaluationPlayerJob($work->id, $work->work_token);
        $job->handle($service);
        if ($checkpoint === 0) {
            $cursor = $work->fresh()->cursor;
            $job->handle($service);
            $job->failed(new \RuntimeException('Obsolete delivery'));
            expect($work->fresh()->cursor)->toBe($cursor)->and($work->fresh()->status)->toBe('running');
        }
    }
    expect($work->fresh()->status)->toBe('compared')->and($work->fresh()->hasCurrentEvidence())->toBeTrue();
    $row = DB::table('nhl_evaluation_game_player_forecasts')->where('forecast_id', $work->id)->first();
    $inputs = json_decode($row->inputs, true);
    expect($inputs['windows'])->toBe($original['windows'])->and($inputs['previous'])->toBe($original['previous'])
        ->and((float) $row->sat_per_60)->toBe(9.0)->and((float) $row->toi_per_game_seconds)->toBe(600.0)
        ->and(json_decode($row->comparison, true)['sat_error_pct'])->toEqual(-33.3333);
    $this->get($read)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('players.0.inputs.context.windows.train.sat_per_60', 6)
        ->where('players.0.inputs.context.windows.last_20.sat_per_60', 6)
        ->where('players.0.inputs.previous.sat_per_60', 6)
        ->where('players.0.comparison.sat_per_60', 6)
        ->where('players.0.comparison.toi_seconds', 600)
        ->where('players.0.sat_per_60', fn ($value): bool => (float) $value === 9.0));
    $before = $row->updated_at;
    Bus::fake();
    $this->postJson($url, ['action' => 'context'])->assertAccepted();
    Bus::assertNothingDispatched();
    expect(DB::table('nhl_evaluation_game_player_forecasts')->where('id', $row->id)->value('updated_at'))->toBe($before);
});

it('protects evidence refresh from guests ordinary users and a different evaluation', function (): void {
    $work = NhlEvaluationGameForecast::create(['evaluation_id' => $this->evaluation->id,
        'nhl_game_id' => 2025020001, 'strength' => 'ev', 'period' => 'q1', 'status' => 'compared',
        'lineup' => [], 'source_cutoff_at' => '2025-10-07 21:00:00',
        'work_token' => '00000000-0000-4000-8000-000000000002']);
    $url = route('admin.nhl-sat-models.next-game.games.update', [$this->evaluation, $work]);
    $this->postJson($url, ['action' => 'context'])->assertUnauthorized();
    $this->actingAs(User::factory()->create())->postJson($url, ['action' => 'context'])->assertForbidden();
    $other = $this->evaluation->replicate();
    $other->save();
    $this->actingAs($this->admin)->postJson(route('admin.nhl-sat-models.next-game.games.update', [$other, $work]),
        ['action' => 'context'])->assertNotFound();
    Bus::assertNothingDispatched();
});
