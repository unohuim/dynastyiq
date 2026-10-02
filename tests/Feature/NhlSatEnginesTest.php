<?php

declare(strict_types=1);

use App\Jobs\EvaluateNhlSatEngineGameJob;
use App\Jobs\RankNhlSatEngineCandidatesJob;
use App\Models\NhlModelRun;
use App\Models\NhlSatEngine;
use App\Models\NhlSatEngineRun;
use App\Models\Role;
use App\Models\User;
use App\Services\NhlSatEngineEvaluator;
use App\Services\NhlSatEngineStackAnalyzer;
use App\Services\NhlSatEngineSettings;
use App\Services\NhlSatModelPredictionService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-01 12:00:00 UTC'));
    Http::preventStrayRequests();
    Bus::fake();
    $this->mock(\App\Services\PlatformState::class)->shouldReceive('seeded')->andReturn(true);
    $this->admin = User::factory()->create();
    $role = Role::query()->create(['name' => 'Super Admin', 'slug' => 'super-admin', 'level' => 99]);
    $this->admin->roles()->attach($role->id, ['organization_id' => null]);
    $this->model = NhlModelRun::query()->create([
        'run_key' => 'engine-test', 'name' => 'Engine test model', 'model_family' => 'sat',
        'workflow_stage' => 'training', 'model_version' => 'engine-test', 'usage' => 'production', 'status' => 'complete',
        'train_season_ids' => ['20222023', '20232024', '20242025'], 'target_season_id' => '20252026',
        'metrics' => [
            'rate_projections_completed_at' => '2026-09-30T12:00:00Z', 'rate_projection_entities_queued' => 1, 'rate_projection_entities_completed' => 1,
            'toi_projections_completed_at' => '2026-09-30T12:00:00Z', 'toi_projection_entities_queued' => 1, 'toi_projection_entities_completed' => 1,
        ],
    ]);
    \App\Models\NhlExpectedGoalsModel::query()->create([
        'model_run_id' => $this->model->id, 'name' => 'Engine goal model', 'version' => 'engine-goal', 'prediction_target' => 'goal',
    ]);
    DB::table('nhl_sat_model_entity_rate_projection_buckets')->insert([
        'model_run_id' => $this->model->id, 'source_season_ids' => '["20242025"]',
        'profile_type' => 'skater_offense', 'entity_key' => 'skater_offense:1', 'entity_id' => 1,
        'matched_bucket_key' => 'a', 'projected_xsat_per_60' => 10,
    ]);
    DB::table('nhl_sat_model_entity_toi_projections')->insert([
        'model_run_id' => $this->model->id, 'source_season_ids' => '["20242025"]',
        'profile_type' => 'skater_offense', 'entity_key' => 'skater_offense:1', 'entity_id' => 1,
        'projected_toi_per_game_seconds' => 900,
    ]);
    $this->settings = app(NhlSatEngineSettings::class)->defaults();
    $this->definition = ['name' => 'Test engine', 'test_model_run_id' => $this->model->id, 'model_run_id' => $this->model->id, 'settings' => $this->settings];
    $this->engine = (new NhlSatEngine())->saveDefinition($this->definition);
    $this->scope = ['mode' => 'season', 'teams' => [], 'game_ids' => []];
    $this->seedGame = function (int $id, string $date, string $away = 'TOR', string $home = 'MTL', string $season = '20252026'): void {
        DB::table('nhl_games')->insert([
            'nhl_game_id' => $id, 'season_id' => $season, 'game_type' => 2, 'game_date' => $date,
            'game_dow' => 'Tue', 'game_month' => 'Oct', 'game_state' => 'OFF',
            'away_team_abbrev' => $away, 'home_team_abbrev' => $home,
            'away_team_id' => 10, 'home_team_id' => 8, 'away_team_score' => 3, 'home_team_score' => 2,
            'away_team_sog' => 30, 'home_team_sog' => 20,
        ]);
    };
    ($this->seedGame)(2025020001, '2025-10-07');
    ($this->seedGame)(2025020002, '2025-10-07', 'BOS', 'NYR');
    ($this->seedGame)(2025020003, '2025-10-09', 'MTL', 'TOR');
    $this->input = [
        'kind' => 'build', 'engine_id' => $this->engine->id, 'model_run_id' => $this->model->id,
        'desired_win_pct' => 60, 'min_coverage_pct' => 40, 'scope' => $this->scope,
    ];
    $this->createRun = fn (): NhlSatEngineRun => app(NhlSatEngineEvaluator::class)->start($this->input, $this->engine);
    $this->result = fn (NhlSatEngineRun $run, int $id, array $extra = []): array => [
        'run_id' => $run->id, 'split_index' => 0, 'nhl_game_id' => $id, 'game' => '{}',
        'status' => 'complete', 'confidence' => 68, 'gap' => 0.7, 'correct' => true,
        'pred_sat' => 100, 'pred_sog' => 50, 'pred_goals' => 5,
        'actual_sat' => 110, 'actual_sog' => 55, 'actual_goals' => 6, ...$extra,
    ];
});

afterEach(function (): void {
    Http::assertNothingSent();
    $this->travelBack();
});

it('blocks guests on every engine endpoint', function (string $verb, string $path): void {
    $this->json($verb, '/admin/nhl-sat-engines' . $path)->assertUnauthorized();
    Bus::assertNothingDispatched();
})->with([
    ['GET', ''], ['POST', ''], ['GET', '/discover'], ['GET', '/1'], ['PUT', '/1'], ['DELETE', '/1'],
    ['POST', '/runs'], ['GET', '/runs/1'], ['POST', '/runs/1/pause'], ['POST', '/runs/1/resume'], ['POST', '/runs/1/cancel'], ['POST', '/runs/1/candidates/1/apply'], ['POST', '/1/default'],
]);

it('blocks ordinary users on every engine endpoint', function (string $verb, string $path): void {
    $this->actingAs(User::factory()->create())->json($verb, '/admin/nhl-sat-engines' . $path)->assertForbidden();
    Bus::assertNothingDispatched();
})->with([
    ['GET', ''], ['POST', ''], ['GET', '/discover'], ['GET', '/1'], ['PUT', '/1'], ['DELETE', '/1'],
    ['POST', '/runs'], ['GET', '/runs/1'], ['POST', '/runs/1/pause'], ['POST', '/runs/1/resume'], ['POST', '/runs/1/cancel'], ['POST', '/runs/1/candidates/1/apply'], ['POST', '/1/default'],
]);

it('creates an engine without dispatching work and reads it through Inertia', function (): void {
    $this->actingAs($this->admin)->post('/admin/nhl-sat-engines', [...$this->definition, 'name' => 'Created'])->assertRedirect();
    $engine = NhlSatEngine::query()->where('name', 'Created')->firstOrFail();
    $this->assertDatabaseHas('nhl_sat_engines', ['id' => $engine->id, 'model_run_id' => $this->model->id, 'discovery_run_id' => null]);
    $this->get('/admin/nhl-sat-engines/' . $engine->id)->assertInertia(fn (Assert $page) => $page
        ->component('Admin/SatEngines/Workspace')->where('engine.name', 'Created')->where('engine.settings.offense', 88));
    Bus::assertNothingDispatched();
});

it('makes the only engine the default and exposes that state in the index', function (): void {
    expect($this->engine->fresh()->is_default)->toBeTrue();
    $this->actingAs($this->admin)->get('/admin/nhl-sat-engines')->assertInertia(fn (Assert $page) => $page
        ->where('engines.data.0.id', $this->engine->id)->where('engines.data.0.is_default', true));
});

it('switches the default engine without starting an evaluation', function (): void {
    $other = (new NhlSatEngine())->saveDefinition([...$this->definition, 'name' => 'Other engine']);
    Bus::fake();
    $this->actingAs($this->admin)->post('/admin/nhl-sat-engines/' . $other->id . '/default')->assertRedirect();
    expect($this->engine->fresh()->is_default)->toBeFalse()->and($other->fresh()->is_default)->toBeTrue();
    Bus::assertNothingDispatched();
});

it('promotes the remaining engine when deleting the default', function (): void {
    $other = (new NhlSatEngine())->saveDefinition([...$this->definition, 'name' => 'Other engine']);
    $this->actingAs($this->admin)->delete('/admin/nhl-sat-engines/' . $this->engine->id)->assertRedirect();
    expect($other->fresh()->is_default)->toBeTrue();
});

it('renders the engine index as an Inertia page', function (): void {
    $this->actingAs($this->admin)->get('/admin/nhl-sat-engines')->assertInertia(fn (Assert $page) => $page
        ->component('Admin/SatEngines/Index')->has('engines.data', 1));
});

it('renders discovery without creating an engine', function (): void {
    $this->actingAs($this->admin)->get('/admin/nhl-sat-engines/discover')->assertInertia(fn (Assert $page) => $page
        ->component('Admin/SatEngines/Workspace')->where('engine', null));
    expect(NhlSatEngine::query()->count())->toBe(1);
});

it('updates settings while preserving prior run definitions', function (): void {
    $run = ($this->createRun)();
    $before = $run->definition;
    $this->actingAs($this->admin)->put('/admin/nhl-sat-engines/' . $this->engine->id,
        [...$this->definition, 'settings' => [...$this->settings, 'offense' => 105, 'defense' => 5]])->assertRedirect();
    expect($this->engine->fresh()->settings['offense'])->toBe(105)->and($run->fresh()->definition)->toBe($before);
});

it('rejects reversed confidence bounds', function (): void {
    $this->actingAs($this->admin)->postJson('/admin/nhl-sat-engines', [...$this->definition,
        'settings' => [...$this->settings, 'confidence_min' => 80, 'confidence_max' => 70]])->assertUnprocessable();
});

it('rejects negative and excessive weights', function (float $weight): void {
    expect(fn () => app(NhlSatEngineSettings::class)->validate([...$this->settings, 'offense' => $weight]))
        ->toThrow(ValidationException::class);
})->with([-1.0, 201.0]);

it('starts a build through HTTP and persists its snapshot before dispatch', function (): void {
    $this->actingAs($this->admin)->post('/admin/nhl-sat-engines/runs', $this->input)->assertRedirect();
    $run = NhlSatEngineRun::query()->firstOrFail();
    expect($run->definition['game_ids'])->toBe([2025020001, 2025020002, 2025020003]);
    $this->assertDatabaseCount('nhl_sat_engine_candidates', 1);
    Bus::assertDispatched(EvaluateNhlSatEngineGameJob::class, fn ($job) => $job->runId === $run->id && $job->gameIndex === 0);
    $this->get('/admin/nhl-sat-engines/runs/' . $run->id)->assertInertia(fn (Assert $page) => $page
        ->component('Admin/SatEngines/Run')->where('run.game_count', 3));
});

it('keeps selected games inside the model test season', function (): void {
    ($this->seedGame)(2026020001, '2026-10-07', 'TOR', 'MTL', '20262027');
    $ids = app(NhlSatEngineEvaluator::class)->gameIds($this->model, $this->scope);
    expect($ids)->not->toContain(2026020001);
});

it('counts game days rather than calendar days', function (): void {
    expect(app(NhlSatEngineEvaluator::class)->gameIds($this->model, [...$this->scope, 'mode' => 'days', 'count' => 2]))
        ->toBe([2025020001, 2025020002, 2025020003]);
});

it('limits games after applying team filters without duplicates', function (): void {
    expect(app(NhlSatEngineEvaluator::class)->gameIds($this->model,
        [...$this->scope, 'mode' => 'games', 'count' => 1, 'teams' => ['TOR', 'MTL']]))->toBe([2025020001]);
});

it('honors the start date for a team game subset', function (): void {
    expect(app(NhlSatEngineEvaluator::class)->gameIds($this->model,
        [...$this->scope, 'teams' => ['TOR'], 'start_date' => '2025-10-08']))->toBe([2025020003]);
});

it('rejects selected IDs that do not belong to the evaluation scope', function (): void {
    expect(fn () => app(NhlSatEngineEvaluator::class)->gameIds($this->model,
        [...$this->scope, 'mode' => 'selected', 'game_ids' => [999]]))->toThrow(ValidationException::class);
});

it('rejects models without a test season', function (): void {
    $this->model->update(['target_season_id' => null]);
    expect(fn () => ($this->createRun)())->toThrow(ValidationException::class);
});

it('rejects training seasons that leak into the test season', function (): void {
    $this->model->update(['target_season_id' => '20242025']);
    expect(fn () => ($this->createRun)())->toThrow(ValidationException::class);
});

it('constructs exact decimal gap steps and inclusive weight bounds', function (): void {
    expect(app(NhlSatEngineSettings::class)->values(['min' => 0.1, 'max' => 0.3, 'step' => 0.1], 10, 0.1))
        ->toEqual([0.1, 0.2, 0.3]);
});

it('rejects unbounded discovery products', function (): void {
    $search = array_fill_keys(array_keys($this->settings), ['min' => 0, 'max' => 100, 'step' => 1]);
    $search['gap'] = ['min' => 0, 'max' => 1, 'step' => 0.1];
    expect(fn () => app(NhlSatEngineSettings::class)->candidates($search))->toThrow(ValidationException::class);
});

it('includes confidence endpoints but excludes exact gap equality and ties', function (): void {
    $settings = [...$this->settings, 'gap' => 0.5];
    $service = app(NhlSatEngineSettings::class);
    expect($service->qualifies(67, 0.6, $settings))->toBeTrue()
        ->and($service->qualifies(70, 0.6, $settings))->toBeTrue()
        ->and($service->qualifies(70, 0.5, $settings))->toBeFalse()
        ->and($service->qualifies(66, 0.6, $settings))->toBeFalse()
        ->and($service->qualifies(68, 0, $this->settings))->toBeFalse();
});

it('uses all-game totals and eligible coverage while recording exclusions', function (): void {
    $run = ($this->createRun)();
    DB::table('nhl_sat_engine_results')->insert([
        ($this->result)($run, 1), ($this->result)($run, 2, ['confidence' => 90, 'correct' => false]),
        ($this->result)($run, 3, ['status' => 'excluded']),
    ]);
    $metrics = app(NhlSatEngineEvaluator::class)->metrics($run->id, 0, $this->settings, 3);
    expect((int) $metrics['eligible'])->toBe(2)->and($metrics['excluded'])->toBe(1)
        ->and($metrics['wins'])->toBe(1)->and($metrics['losses'])->toBe(0)
        ->and((float) $metrics['coverage_pct'])->toBe(50.0)->and((float) $metrics['pred_sat'])->toBe(200.0)
        ->and((float) $metrics['actual_sat'])->toBe(220.0);
});

it('reports no win percentage when no games qualify', function (): void {
    $run = ($this->createRun)();
    $metrics = app(NhlSatEngineEvaluator::class)->metrics($run->id, 0, $this->settings, 3);
    expect($metrics['win_pct'])->toBeNull()->and($metrics['coverage_pct'])->toBe(0);
});

it('cancels a run through HTTP and makes queued jobs inert', function (): void {
    $run = ($this->createRun)();
    $this->actingAs($this->admin)->post('/admin/nhl-sat-engines/runs/' . $run->id . '/cancel')->assertRedirect();
    $evaluator = Mockery::mock(NhlSatEngineEvaluator::class);
    $evaluator->shouldNotReceive('predict');
    (new EvaluateNhlSatEngineGameJob($run->id, 0, 0))->handle($evaluator);
    expect($run->fresh()->status)->toBe('cancelled');
});

it('pauses a run, preserves its evidence, and resumes only missing evaluation work', function (): void {
    $run = ($this->createRun)();
    DB::table('nhl_sat_engine_results')->insert(($this->result)($run, 2025020001));
    $run->update(['status' => 'running', 'predictions_completed' => 1]);

    $this->actingAs($this->admin)->post('/admin/nhl-sat-engines/runs/' . $run->id . '/pause')->assertRedirect();
    $paused = $run->fresh();
    expect($paused->status)->toBe('paused')->and($paused->paused_status)->toBe('running')
        ->and($paused->work_generation)->toBe(2);
    $this->assertDatabaseCount('nhl_sat_engine_results', 1);

    $evaluator = Mockery::mock(NhlSatEngineEvaluator::class);
    $evaluator->shouldNotReceive('predict');
    (new EvaluateNhlSatEngineGameJob($run->id, 0, 1, 1))->handle($evaluator);

    Bus::fake();
    $this->actingAs($this->admin)->post('/admin/nhl-sat-engines/runs/' . $run->id . '/resume')->assertRedirect();
    expect($run->fresh()->status)->toBe('running')->and($run->fresh()->paused_status)->toBeNull();
    Bus::assertDispatched(EvaluateNhlSatEngineGameJob::class, fn ($job) => $job->runId === $run->id
        && $job->splitIndex === 0 && $job->gameIndex === 1 && $job->workGeneration === 2);
});

it('resumes paused ranking from pending candidates and permits pausing to be cancelled', function (): void {
    $run = ($this->createRun)();
    $run->update(['status' => 'ranking']);
    $this->actingAs($this->admin)->post('/admin/nhl-sat-engines/runs/' . $run->id . '/pause')->assertRedirect();
    Bus::fake();
    $this->actingAs($this->admin)->post('/admin/nhl-sat-engines/runs/' . $run->id . '/resume')->assertRedirect();
    Bus::assertDispatched(RankNhlSatEngineCandidatesJob::class, fn ($job) => $job->runId === $run->id
        && $job->afterId === 0 && $job->workGeneration === 2);

    $this->actingAs($this->admin)->post('/admin/nhl-sat-engines/runs/' . $run->id . '/pause')->assertRedirect();
    $this->actingAs($this->admin)->post('/admin/nhl-sat-engines/runs/' . $run->id . '/cancel')->assertRedirect();
    expect($run->fresh()->status)->toBe('cancelled');
});

it('recovers a paused legacy run with no recorded prior phase', function (): void {
    $run = ($this->createRun)();
    $run->update(['status' => 'paused', 'paused_status' => null]);
    Bus::fake();

    $this->actingAs($this->admin)->post('/admin/nhl-sat-engines/runs/' . $run->id . '/resume')->assertRedirect();

    expect($run->fresh()->status)->toBe('running')->and($run->fresh()->work_generation)->toBe(2);
    Bus::assertDispatched(EvaluateNhlSatEngineGameJob::class, fn ($job) => $job->runId === $run->id
        && $job->splitIndex === 0 && $job->gameIndex === 0 && $job->workGeneration === 2);
});

it('does not count a duplicate completed game twice', function (): void {
    $run = ($this->createRun)();
    DB::table('nhl_sat_engine_results')->insert(($this->result)($run, 2025020001));
    $evaluator = Mockery::mock(NhlSatEngineEvaluator::class);
    $evaluator->shouldNotReceive('predict');
    (new EvaluateNhlSatEngineGameJob($run->id, 0, 0))->handle($evaluator);
    expect($run->fresh()->predictions_completed)->toBe(0);
});

it('rejects model mutation during a run', function (): void {
    $run = ($this->createRun)();
    $this->model->update(['status' => 'running']);
    expect(fn () => app(NhlSatEngineEvaluator::class)->assertModelUnchanged($run))->toThrow(\RuntimeException::class);
});

it('prevents deletion while a run is active', function (): void {
    ($this->createRun)();
    $this->actingAs($this->admin)->deleteJson('/admin/nhl-sat-engines/' . $this->engine->id)->assertUnprocessable();
});

it('deletes an engine while preserving finished run evidence', function (): void {
    $run = ($this->createRun)();
    $run->update(['status' => 'complete']);
    $this->actingAs($this->admin)->delete('/admin/nhl-sat-engines/' . $this->engine->id)->assertRedirect();
    expect($run->fresh()->engine_id)->toBeNull();
});

it('only applies completed candidates and preserves their model', function (): void {
    $run = ($this->createRun)();
    $candidate = DB::table('nhl_sat_engine_candidates')->where('run_id', $run->id)->first();
    $url = '/admin/nhl-sat-engines/runs/' . $run->id . '/candidates/' . $candidate->id . '/apply';
    $this->actingAs($this->admin)->postJson($url, ['name' => 'Adopted'])->assertStatus(409);
    $run->update(['status' => 'complete']);
    DB::table('nhl_sat_engine_candidates')->where('id', $candidate->id)->update(['metrics' => '{}']);
    $this->post($url, ['name' => 'Adopted'])->assertRedirect();
    $engine = NhlSatEngine::query()->where('name', 'Adopted')->firstOrFail();
    expect($engine->model_run_id)->toBe($this->model->id)->and($engine->settings)->toEqual($this->settings);
    $this->get('/admin/nhl-sat-engines/' . $engine->id)->assertInertia(fn (Assert $page) => $page->where('engine.name', 'Adopted'));
});

it('rejects adoption of a candidate from another run', function (): void {
    $first = ($this->createRun)();
    $second = ($this->createRun)();
    $first->update(['status' => 'complete']);
    $candidate = DB::table('nhl_sat_engine_candidates')->where('run_id', $second->id)->value('id');
    $this->actingAs($this->admin)->postJson('/admin/nhl-sat-engines/runs/' . $first->id . '/candidates/' . $candidate . '/apply', ['name' => 'Wrong'])->assertNotFound();
});

it('weights offense and only matching defense without normalization', function (): void {
    $bucket = fn (string $key, float $sat): object => (object) [
        'matched_bucket_key' => $key, 'baseline_xsat' => $sat, 'baseline_xsog' => $sat / 2, 'baseline_xgf' => $sat / 20,
        'shot_type_group' => 'wrist', 'distance_group' => 'd_010_015', 'angle_group' => 'all', 'sequence_group' => 'all',
    ];
    $service = app(NhlSatModelPredictionService::class);
    $offense = collect([$bucket('a', 10), $bucket('b', 5)]);
    $defense = collect([$bucket('a', 20), $bucket('c', 100)]);
    $custom = $service->environment($this->model->id, $offense, $defense, 1.05, 0.10)->keyBy('matched_bucket_key');
    expect($custom->keys()->sort()->values()->all())->toBe(['a', 'b'])
        ->and($custom['a']['adjusted_xsat'])->toBe(12.5)->and($custom['b']['adjusted_xsat'])->toBe(5.25);
    $default = $service->environment($this->model->id, $offense, $defense)->keyBy('matched_bucket_key');
    expect($default['a']['adjusted_xsat'])->toEqualWithDelta(9.2, 0.000001);
});

it('marks ranking complete only after persisting all candidate metrics', function (): void {
    $run = ($this->createRun)();
    foreach ([2025020001, 2025020002, 2025020003] as $id) {
        DB::table('nhl_sat_engine_results')->insert(($this->result)($run, $id));
    }
    $run->update(['status' => 'ranking', 'predictions_completed' => $run->prediction_count]);
    (new RankNhlSatEngineCandidatesJob($run->id, 0))->handle(app(NhlSatEngineEvaluator::class));
    expect($run->fresh()->status)->toBe('complete')->and($run->fresh()->candidates_completed)->toBe(1);
    $candidate = DB::table('nhl_sat_engine_candidates')->where('run_id', $run->id)->first();
    expect((float) $candidate->win_pct)->toBe(100.0)->and((bool) $candidate->meets_targets)->toBeTrue();
});

it('failure callbacks never overwrite cancelled or completed runs', function (): void {
    $run = ($this->createRun)();
    $run->update(['status' => 'complete']);
    (new EvaluateNhlSatEngineGameJob($run->id, 0, 0))->failed(new \RuntimeException('test'));
    expect($run->fresh()->status)->toBe('complete');
});

it('rejects incomplete rate and TOI builds before queuing games', function (): void {
    $this->model->update(['metrics' => []]);
    $this->actingAs($this->admin)->postJson('/admin/nhl-sat-engines/runs', $this->input)->assertUnprocessable();
    $this->assertDatabaseCount('nhl_sat_engine_runs', 0);
    Bus::assertNothingDispatched();
});

it('starts automatic discovery from model scope and targets with four bounded lanes', function (): void {
    $this->actingAs($this->admin)->post('/admin/nhl-sat-engines/runs', [
        ...$this->input, 'kind' => 'discovery', 'engine_id' => null,
    ])->assertRedirect();
    $run = NhlSatEngineRun::query()->firstOrFail();
    expect($run->prediction_count)->toBe(243)->and($run->candidate_count)->toBe(81)
        ->and($run->definition['automatic_search']['strategy'])->toBe('coarse_to_fine_v1')
        ->and($run->definition['search'])->toBeNull();
    $this->assertDatabaseCount('nhl_sat_engine_candidates', 81);
    Bus::assertDispatchedTimes(EvaluateNhlSatEngineGameJob::class, 4);
    $this->get('/admin/nhl-sat-engines/runs/' . $run->id)->assertInertia(fn (Assert $page) => $page
        ->where('run.definition.automatic_search.stage', 0)->where('run.candidate_count', 81));
});

it('persists a calculated game and enqueues only the next game in its pair', function (): void {
    $run = ($this->createRun)();
    Bus::fake();
    $evaluator = Mockery::mock(NhlSatEngineEvaluator::class);
    $evaluator->shouldReceive('assertModelUnchanged')->once();
    $evaluator->shouldReceive('predict')->once()->withArgs(fn ($snapshot, $split, $game) =>
        $snapshot->id === $run->id && $split === 0 && $game === 2025020001)
        ->andReturn(($this->result)($run, 2025020001));
    (new EvaluateNhlSatEngineGameJob($run->id, 0, 0))->handle($evaluator);
    expect($run->fresh()->predictions_completed)->toBe(1)->and($run->fresh()->status)->toBe('running');
    $this->assertDatabaseHas('nhl_sat_engine_results', ['run_id' => $run->id, 'nhl_game_id' => 2025020001]);
    Bus::assertDispatched(EvaluateNhlSatEngineGameJob::class, fn ($job) => $job->gameIndex === 1 && $job->splitIndex === 0);
    Bus::assertNotDispatched(RankNhlSatEngineCandidatesJob::class);
});

it('rejects late game writes after cancellation during prediction', function (): void {
    $run = ($this->createRun)();
    $evaluator = Mockery::mock(NhlSatEngineEvaluator::class);
    $evaluator->shouldReceive('assertModelUnchanged')->once();
    $evaluator->shouldReceive('predict')->once()->andReturnUsing(function () use ($run): array {
        $run->update(['status' => 'cancelled']);

        return ($this->result)($run, 2025020001);
    });
    (new EvaluateNhlSatEngineGameJob($run->id, 0, 0))->handle($evaluator);
    expect($run->fresh()->status)->toBe('cancelled');
    $this->assertDatabaseCount('nhl_sat_engine_results', 0);
});

it('applies a candidate to an existing engine without starting a build', function (): void {
    $run = ($this->createRun)();
    $run->update(['status' => 'complete']);
    $candidate = DB::table('nhl_sat_engine_candidates')->where('run_id', $run->id)->first();
    DB::table('nhl_sat_engine_candidates')->where('id', $candidate->id)->update([
        'metrics' => '{}', 'settings' => json_encode([...$this->settings, 'offense' => 105, 'defense' => 5]),
    ]);
    Bus::fake();
    $this->actingAs($this->admin)->post('/admin/nhl-sat-engines/runs/' . $run->id . '/candidates/' . $candidate->id . '/apply',
        ['engine_id' => $this->engine->id])->assertRedirect();
    expect($this->engine->fresh()->settings['offense'])->toBe(105)->and(NhlSatEngine::query()->count())->toBe(1);
    Bus::assertNothingDispatched();
});

it('records a discovery source and exposes its candidates through the engine relationship', function (): void {
    $run = app(NhlSatEngineEvaluator::class)->start([
        ...$this->input, 'kind' => 'discovery', 'engine_id' => null,
    ]);
    $candidate = DB::table('nhl_sat_engine_candidates')->where('run_id', $run->id)->first();
    DB::table('nhl_sat_engine_candidates')->where('id', $candidate->id)->update(['metrics' => '{}']);

    $this->actingAs($this->admin)->post('/admin/nhl-sat-engines/runs/' . $run->id . '/candidates/' . $candidate->id . '/apply', [
        'engine_id' => $this->engine->id,
    ])->assertRedirect();

    $engine = $this->engine->fresh();
    expect($engine->discovery_run_id)->toBe($run->id)->and($engine->discovery->id)->toBe($run->id)
        ->and($engine->discovery->candidates()->whereKey($candidate->id)->exists())->toBeTrue();
    $this->get('/admin/nhl-sat-engines/' . $engine->id)->assertInertia(fn (Assert $page) => $page
        ->where('engine.discovery_run_id', $run->id));
    Bus::assertNothingDispatched();
});

it('discovers confidence without accepting manual confidence search bounds', function (): void {
    $search = ['offense' => ['min' => 88, 'max' => 88, 'step' => 1],
        'defense' => ['min' => 2, 'max' => 2, 'step' => 1], 'gap' => ['min' => 0, 'max' => 0, 'step' => 0.1],
        'confidence_min' => ['min' => 90, 'max' => 90, 'step' => 1],
        'confidence_max' => ['min' => 95, 'max' => 95, 'step' => 1]];
    $candidates = app(NhlSatEngineSettings::class)->candidates($search);
    expect($candidates)->toHaveCount(1)->and($candidates[0]['confidence_min'])->toBe(0)
        ->and($candidates[0]['confidence_max'])->toBe(100);
});

it('finds ranges outside the former manually selected confidence band', function (): void {
    $ranges = app(NhlSatEngineSettings::class)->confidenceFrontier([
        ['confidence' => 10, 'picks' => 4, 'wins' => 4],
        ['confidence' => 68, 'picks' => 6, 'wins' => 1],
    ]);
    expect(collect($ranges)->contains(fn ($row) => $row['confidence_min'] === 10 && $row['confidence_max'] === 10))->toBeTrue();
});

it('retains a stronger 38 percent range alongside a 40 percent range', function (): void {
    $ranges = app(NhlSatEngineSettings::class)->confidenceFrontier([
        ['confidence' => 68, 'picks' => 19, 'wins' => 18],
        ['confidence' => 69, 'picks' => 1, 'wins' => 0],
        ['confidence' => 70, 'picks' => 30, 'wins' => 0],
    ]);
    expect(collect($ranges)->pluck('picks')->all())->toBe([50, 20, 19]);
});

it('removes only confidence alternatives dominated in win rate and coverage', function (): void {
    $ranges = app(NhlSatEngineSettings::class)->confidenceFrontier([
        ['confidence' => 0, 'picks' => 2, 'wins' => 2],
        ['confidence' => 100, 'picks' => 3, 'wins' => 3],
    ]);
    expect($ranges)->toBe([['confidence_min' => 100, 'confidence_max' => 100, 'picks' => 3, 'wins' => 3]]);
});

it('limits automatic confidence discovery to three inclusive score values', function (): void {
    $ranges = app(NhlSatEngineSettings::class)->confidenceFrontier([
        ['confidence' => 70, 'picks' => 2, 'wins' => 2],
        ['confidence' => 72, 'picks' => 2, 'wins' => 2],
        ['confidence' => 77, 'picks' => 2, 'wins' => 2],
    ]);

    expect(collect($ranges)->contains(fn ($range): bool => $range['confidence_min'] === 70 && $range['confidence_max'] === 72))->toBeTrue()
        ->and(collect($ranges)->contains(fn ($range): bool => $range['confidence_min'] === 70 && $range['confidence_max'] === 77))->toBeFalse();
});

it('matches an exhaustive three-score confidence search for every minimum pick count', function (): void {
    $groups = [
        ['confidence' => 68, 'picks' => 2, 'wins' => 2],
        ['confidence' => 69, 'picks' => 3, 'wins' => 0],
        ['confidence' => 70, 'picks' => 4, 'wins' => 3],
        ['confidence' => 72, 'picks' => 1, 'wins' => 1],
    ];
    $frontier = app(NhlSatEngineSettings::class)->confidenceFrontier($groups);
    $exhaustive = [];
    for ($min = 0; $min <= 100; $min++) {
        for ($max = $min; $max <= min(100, $min + 2); $max++) {
            $selected = array_filter($groups, fn ($row) => $row['confidence'] >= $min && $row['confidence'] <= $max);
            $count = array_sum(array_column($selected, 'picks'));
            if ($count > 0) {
                $exhaustive[] = ['picks' => $count, 'wins' => array_sum(array_column($selected, 'wins'))];
            }
        }
    }
    for ($minimumPicks = 1; $minimumPicks <= 9; $minimumPicks++) {
        $score = fn (array $rows): float => max(array_map(fn ($row): float => $row['wins'] / $row['picks'],
            array_filter($rows, fn ($row): bool => $row['picks'] >= $minimumPicks)));
        expect($score($frontier))->toBe($score($exhaustive));
    }
});

it('aggregates confidence discovery with bounded queries and all-game totals', function (): void {
    $run = ($this->createRun)();
    DB::table('nhl_sat_engine_results')->insert([
        ($this->result)($run, 1, ['confidence' => 20]),
        ($this->result)($run, 2, ['confidence' => 80, 'correct' => false]),
        ($this->result)($run, 3, ['confidence' => 90, 'gap' => 0, 'correct' => null]),
    ]);
    DB::enableQueryLog();
    try {
        DB::flushQueryLog();
        $rows = app(NhlSatEngineEvaluator::class)->discoverConfidence($run->id, 0, $this->settings, 3);
        $queries = DB::getQueryLog();
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    expect(count($queries))->toBeLessThanOrEqual(7);
    $best = collect($rows)->first(fn ($row) => $row['settings']['confidence_min'] === 20 && $row['settings']['confidence_max'] === 20);
    expect($best['metrics']['wins'])->toBe(1)->and((float) $best['metrics']['pred_sat'])->toBe(300.0)
        ->and((float) $best['metrics']['actual_sat'])->toBe(330.0)
        ->and($best['metrics']['coverage_pct'])->toBe(100 / 3);
});

it('keeps no-pick searches inspectable without manufacturing a win rate', function (): void {
    $run = ($this->createRun)();
    $rows = app(NhlSatEngineEvaluator::class)->discoverConfidence($run->id, 0, $this->settings, 3);
    expect($rows)->toHaveCount(1)->and($rows[0]['metrics']['win_pct'])->toBeNull()
        ->and($rows[0]['metrics']['picks'])->toBe(0);
});

it('persists automatic ranges once while counting the search once', function (): void {
    $run = ($this->createRun)();
    $run->update(['status' => 'ranking', 'predictions_completed' => 3,
        'definition' => [...$run->definition, 'confidence_search' => 'automatic']]);
    DB::table('nhl_sat_engine_results')->insert([
        ($this->result)($run, 1, ['confidence' => 20]),
        ($this->result)($run, 2, ['confidence' => 80, 'correct' => false]),
        ($this->result)($run, 3, ['confidence' => 90, 'correct' => false]),
    ]);
    $job = new RankNhlSatEngineCandidatesJob($run->id, 0);
    $job->handle(app(NhlSatEngineEvaluator::class));
    $count = DB::table('nhl_sat_engine_candidates')->where('run_id', $run->id)->count();
    expect($count)->toBe(3)->and($run->fresh()->candidates_completed)->toBe(1)
        ->and($run->fresh()->status)->toBe('complete');
    $job->handle(app(NhlSatEngineEvaluator::class));
    expect(DB::table('nhl_sat_engine_candidates')->where('run_id', $run->id)->count())->toBe($count);
});

it('keeps a below-target candidate in the filterable main list', function (): void {
    $run = ($this->createRun)();
    $run->update(['status' => 'complete']);
    $seed = DB::table('nhl_sat_engine_candidates')->where('run_id', $run->id)->first();
    DB::table('nhl_sat_engine_candidates')->where('id', $seed->id)->update([
        'metrics' => json_encode(['wins' => 18, 'losses' => 1, 'picks' => 19, 'eligible' => 50]),
        'win_pct' => 100 * 18 / 19, 'coverage_pct' => 38, 'meets_targets' => false,
    ]);
    for ($i = 0; $i < 26; $i++) {
        DB::table('nhl_sat_engine_candidates')->insert([
            'run_id' => $run->id, 'split_index' => 0, 'settings' => json_encode($this->settings),
            'metrics' => '{}', 'win_pct' => 70, 'coverage_pct' => 40, 'meets_targets' => true,
        ]);
    }
    $this->actingAs($this->admin)->get('/admin/nhl-sat-engines/runs/' . $run->id . '?target_status=not_met')
        ->assertInertia(fn (Assert $page) => $page->has('candidates.data', 1)
            ->where('candidates.data.0.id', $seed->id)
            ->where('candidates.data.0.coverage_pct', fn ($value) => (float) $value === 38.0)
            ->where('candidates.data.0.meets_targets', fn ($value) => ! (bool) $value));
    $this->post('/admin/nhl-sat-engines/runs/' . $run->id . '/candidates/' . $seed->id . '/apply', ['name' => 'Near miss engine'])->assertRedirect();
    $this->assertDatabaseHas('nhl_sat_engines', ['name' => 'Near miss engine']);
});

it('expands coverage with a supplement that preserves foundation precedence', function (): void {
    expect(NhlSatEngineStackAnalyzer::MAX_DEPTH)->toBe(5);
    $run = ($this->createRun)();
    $run->update(['definition' => [...$run->definition, 'min_coverage_pct' => 30]]);
    $foundation = DB::table('nhl_sat_engine_candidates')->where('run_id', $run->id)->first();
    DB::table('nhl_sat_engine_candidates')->where('id', $foundation->id)->update([
        'settings' => json_encode([...$this->settings, 'confidence_min' => 70, 'confidence_max' => 70, 'gap' => 0.5]), 'metrics' => '{}', 'win_pct' => 100, 'coverage_pct' => 33.3333,
    ]);
    $supplement = DB::table('nhl_sat_engine_candidates')->insertGetId([
        'run_id' => $run->id, 'split_index' => 1, 'settings' => json_encode([...$this->settings, 'confidence_min' => 67, 'confidence_max' => 69, 'gap' => 0]), 'metrics' => '{}',
        'win_pct' => 66.6667, 'coverage_pct' => 100, 'meets_targets' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('nhl_sat_engine_results')->insert([
        ($this->result)($run, 2025020001, ['split_index' => 0, 'confidence' => 70, 'correct' => true]),
        ($this->result)($run, 2025020001, ['split_index' => 1, 'confidence' => 70, 'correct' => false]),
        ($this->result)($run, 2025020002, ['split_index' => 1, 'correct' => true]),
        ($this->result)($run, 2025020003, ['split_index' => 1, 'correct' => true]),
    ]);
    $stacks = app(NhlSatEngineStackAnalyzer::class)->analyze($run, DB::table('nhl_sat_engine_candidates')->where('run_id', $run->id)->get());
    $stack = collect($stacks)->first(fn (array $row): bool => $row['ids'] === [(int) $foundation->id, $supplement]);
    expect($stack)->not->toBeNull()->and($stack['wins'])->toBe(3)->and($stack['losses'])->toBe(0)
        ->and($stack['coverage_pct'])->toBe(100.0)->and($stack['foundation_wins'])->toBe(1)
        ->and($stack['foundation_losses'])->toBe(0)->and($stack['foundation_win_pct'])->toBe(100.0)
        ->and($stack['foundation_coverage_pct'])->toBe(100.0)->and($stack['unique_added_picks'])->toBe(2)
        ->and($stack['unique_added_wins'])->toBe(2)->and($stack['unique_added_losses'])->toBe(0)
        ->and($stack['candidates'][0]['stack_picks'])->toBe(1)->and($stack['candidates'][1]['stack_picks'])->toBe(2)
        ->and($stack['excluded'])->toBe(0);
});

it('continues automatic stack analysis through three contributing engines', function (): void {
    $run = ($this->createRun)();
    $run->update(['definition' => [...$run->definition, 'min_coverage_pct' => 30]]);
    $foundation = DB::table('nhl_sat_engine_candidates')->where('run_id', $run->id)->first();
    DB::table('nhl_sat_engine_candidates')->where('id', $foundation->id)->update([
        'settings' => json_encode([...$this->settings, 'confidence_min' => 70, 'confidence_max' => 70, 'gap' => 0.5]),
        'metrics' => '{}', 'win_pct' => 100, 'coverage_pct' => 33.3333,
    ]);
    DB::table('nhl_sat_engine_candidates')->insertGetId([
        'run_id' => $run->id, 'split_index' => 1,
        'settings' => json_encode([...$this->settings, 'confidence_min' => 67, 'confidence_max' => 69, 'gap' => 0]),
        'metrics' => '{}', 'win_pct' => 100, 'coverage_pct' => 33.3333, 'meets_targets' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('nhl_sat_engine_candidates')->insertGetId([
        'run_id' => $run->id, 'split_index' => 2,
        'settings' => json_encode([...$this->settings, 'confidence_min' => 60, 'confidence_max' => 66, 'gap' => 0]),
        'metrics' => '{}', 'win_pct' => 100, 'coverage_pct' => 33.3333, 'meets_targets' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('nhl_sat_engine_results')->insert([
        ($this->result)($run, 2025020001, ['split_index' => 0, 'confidence' => 70, 'correct' => true]),
        ($this->result)($run, 2025020002, ['split_index' => 1, 'confidence' => 68, 'correct' => true]),
        ($this->result)($run, 2025020003, ['split_index' => 2, 'confidence' => 65, 'correct' => true]),
    ]);

    $stacks = app(NhlSatEngineStackAnalyzer::class)->analyze($run,
        DB::table('nhl_sat_engine_candidates')->where('run_id', $run->id)->get());
    $stack = collect($stacks)->first(fn (array $row): bool => count($row['ids']) === 3);

    expect($stacks[0]['ids'])->toHaveCount(3)->and($stack)->not->toBeNull()->and($stack['candidates'][0]['stack_picks'])->toBe(1)
        ->and($stack['candidates'][1]['stack_picks'])->toBe(1)->and($stack['candidates'][2]['stack_picks'])->toBe(1);
});

it('rejects an automatic supplement that replaces every foundation pick', function (): void {
    $run = ($this->createRun)();
    $run->update(['definition' => [...$run->definition, 'min_coverage_pct' => 30]]);
    $foundation = DB::table('nhl_sat_engine_candidates')->where('run_id', $run->id)->first();
    DB::table('nhl_sat_engine_candidates')->where('id', $foundation->id)->update([
        'settings' => json_encode([...$this->settings, 'confidence_min' => 70, 'confidence_max' => 70, 'gap' => 0.5]),
        'metrics' => '{}', 'win_pct' => 100, 'coverage_pct' => 33.3333,
    ]);
    DB::table('nhl_sat_engine_candidates')->insert([
        'run_id' => $run->id, 'split_index' => 1,
        'settings' => json_encode([...$this->settings, 'confidence_min' => 67, 'confidence_max' => 70, 'gap' => 0]),
        'metrics' => '{}', 'win_pct' => 80, 'coverage_pct' => 66.6667, 'meets_targets' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('nhl_sat_engine_results')->insert([
        ($this->result)($run, 2025020001, ['split_index' => 0, 'confidence' => 70, 'correct' => true]),
        ($this->result)($run, 2025020001, ['split_index' => 1, 'confidence' => 70, 'correct' => true]),
        ($this->result)($run, 2025020002, ['split_index' => 1, 'confidence' => 68, 'correct' => true]),
    ]);

    expect(app(NhlSatEngineStackAnalyzer::class)->analyze($run,
        DB::table('nhl_sat_engine_candidates')->where('run_id', $run->id)->get()))->toBe([]);
});

it('recommends coverage-expanding stacks even when they lower the foundation win rate', function (): void {
    $run = ($this->createRun)();
    $run->update(['definition' => [...$run->definition, 'min_coverage_pct' => 30]]);
    $foundation = DB::table('nhl_sat_engine_candidates')->where('run_id', $run->id)->first();
    DB::table('nhl_sat_engine_candidates')->where('id', $foundation->id)->update([
        'settings' => json_encode([...$this->settings, 'gap' => 0.5]), 'metrics' => '{}', 'win_pct' => 100, 'coverage_pct' => 33.3333,
    ]);
    $supplement = DB::table('nhl_sat_engine_candidates')->insertGetId([
        'run_id' => $run->id, 'split_index' => 1, 'settings' => json_encode([...$this->settings, 'gap' => 0]), 'metrics' => '{}',
        'win_pct' => 0, 'coverage_pct' => 100, 'meets_targets' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('nhl_sat_engine_results')->insert([
        ($this->result)($run, 2025020001, ['split_index' => 0, 'correct' => true]),
        ($this->result)($run, 2025020002, ['split_index' => 1, 'correct' => false]),
        ($this->result)($run, 2025020003, ['split_index' => 1, 'correct' => false]),
    ]);

    $rows = DB::table('nhl_sat_engine_candidates')->where('run_id', $run->id)->get();
    $stack = collect(app(NhlSatEngineStackAnalyzer::class)->analyze($run, $rows))
        ->first(fn (array $row): bool => $row['ids'] === [(int) $foundation->id, $supplement]);
    expect($stack)->not->toBeNull()->and($stack['coverage_pct'])->toBe(100.0)->and($stack['win_pct'])->toBeLessThan(100.0);
});

it('prioritizes supplementary win rate on new picks over standalone coverage', function (): void {
    $run = ($this->createRun)();
    $run->update(['definition' => [...$run->definition, 'min_coverage_pct' => 30]]);
    $foundation = DB::table('nhl_sat_engine_candidates')->where('run_id', $run->id)->first();
    DB::table('nhl_sat_engine_candidates')->where('id', $foundation->id)->update([
        'settings' => json_encode([...$this->settings, 'gap' => 0.5]), 'metrics' => '{}', 'win_pct' => 100, 'coverage_pct' => 33.3333,
    ]);
    DB::table('nhl_sat_engine_candidates')->insertGetId([
        'run_id' => $run->id, 'split_index' => 1,
        'settings' => json_encode([...$this->settings, 'confidence_min' => 0, 'confidence_max' => 100, 'gap' => 0.1]),
        'metrics' => '{}', 'win_pct' => 90, 'coverage_pct' => 100, 'meets_targets' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $highMarginalWin = DB::table('nhl_sat_engine_candidates')->insertGetId([
        'run_id' => $run->id, 'split_index' => 2,
        'settings' => json_encode([...$this->settings, 'confidence_min' => 60, 'confidence_max' => 70, 'gap' => 0]),
        'metrics' => '{}', 'win_pct' => 80, 'coverage_pct' => 66.6667, 'meets_targets' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('nhl_sat_engine_results')->insert([
        ($this->result)($run, 2025020001, ['split_index' => 0, 'correct' => true]),
        ($this->result)($run, 2025020002, ['split_index' => 1, 'correct' => false]),
        ($this->result)($run, 2025020003, ['split_index' => 1, 'correct' => false]),
        ($this->result)($run, 2025020002, ['split_index' => 2, 'correct' => true]),
    ]);

    $stacks = app(NhlSatEngineStackAnalyzer::class)->analyze($run, DB::table('nhl_sat_engine_candidates')->where('run_id', $run->id)->get());

    expect($stacks[0]['ids'])->toBe([(int) $foundation->id, $highMarginalWin]);
});

it('screens redundant high-win supplements before applying the automatic candidate limit', function (): void {
    $run = ($this->createRun)();
    $run->update(['definition' => [...$run->definition, 'min_coverage_pct' => 30]]);
    $foundation = DB::table('nhl_sat_engine_candidates')->where('run_id', $run->id)->first();
    DB::table('nhl_sat_engine_candidates')->where('id', $foundation->id)->update([
        'settings' => json_encode([...$this->settings, 'gap' => 0.5]), 'metrics' => '{}', 'win_pct' => 90, 'coverage_pct' => 33.3333,
    ]);
    for ($index = 1; $index <= 49; $index++) {
        DB::table('nhl_sat_engine_candidates')->insert([
            'run_id' => $run->id, 'split_index' => 1,
            'settings' => json_encode([...$this->settings, 'confidence_min' => 72, 'confidence_max' => 77, 'gap' => $index / 100]),
            'metrics' => '{}', 'win_pct' => 100, 'coverage_pct' => 25, 'meets_targets' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    $contributor = DB::table('nhl_sat_engine_candidates')->insertGetId([
        'run_id' => $run->id, 'split_index' => 2,
        'settings' => json_encode([...$this->settings, 'confidence_min' => 60, 'confidence_max' => 70, 'gap' => 0]),
        'metrics' => '{}', 'win_pct' => 60, 'coverage_pct' => 50, 'meets_targets' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('nhl_sat_engine_results')->insert([
        ($this->result)($run, 2025020001, ['split_index' => 0, 'confidence' => 73, 'correct' => true]),
        ($this->result)($run, 2025020001, ['split_index' => 1, 'confidence' => 73, 'correct' => true]),
        ($this->result)($run, 2025020002, ['split_index' => 2, 'confidence' => 68, 'correct' => true]),
    ]);

    $stacks = app(NhlSatEngineStackAnalyzer::class)->analyze($run, DB::table('nhl_sat_engine_candidates')->where('run_id', $run->id)->get());

    expect($stacks[0]['ids'])->toBe([(int) $foundation->id, $contributor]);
});

it('uses the highest-win and then highest-offense candidate meeting the coverage target as the automatic foundation', function (): void {
    $run = ($this->createRun)();
    $foundation = DB::table('nhl_sat_engine_candidates')->where('run_id', $run->id)->first();
    DB::table('nhl_sat_engine_candidates')->where('id', $foundation->id)->update([
        'settings' => json_encode([...$this->settings, 'offense' => 25]),
        'metrics' => '{}', 'win_pct' => 84.6154, 'coverage_pct' => 41.9355,
    ]);
    $higherWinBelowTarget = DB::table('nhl_sat_engine_candidates')->insertGetId([
        'run_id' => $run->id, 'split_index' => 1, 'settings' => json_encode($this->settings), 'metrics' => '{}',
        'win_pct' => 100, 'coverage_pct' => 29.0323, 'meets_targets' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $higherOffense = DB::table('nhl_sat_engine_candidates')->insertGetId([
        'run_id' => $run->id, 'split_index' => 2, 'settings' => json_encode([...$this->settings, 'offense' => 50]), 'metrics' => '{}',
        'win_pct' => 84.6154, 'coverage_pct' => 41.9355, 'meets_targets' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $rows = DB::table('nhl_sat_engine_candidates')->whereIn('id', [$foundation->id, $higherWinBelowTarget, $higherOffense])->get();

    $selected = app(NhlSatEngineStackAnalyzer::class)->foundation($run, $rows);
    expect($selected['id'])->toBe($higherOffense);
});

it('analyzes a manually ordered stack and preserves its precedence', function (): void {
    $run = ($this->createRun)();
    $foundation = DB::table('nhl_sat_engine_candidates')->where('run_id', $run->id)->first();
    DB::table('nhl_sat_engine_candidates')->where('id', $foundation->id)->update(['metrics' => '{}', 'win_pct' => 100, 'coverage_pct' => 33.3333]);
    $supplement = DB::table('nhl_sat_engine_candidates')->insertGetId([
        'run_id' => $run->id, 'split_index' => 1, 'settings' => json_encode($this->settings), 'metrics' => '{}',
        'win_pct' => 50, 'coverage_pct' => 100, 'meets_targets' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('nhl_sat_engine_results')->insert([
        ($this->result)($run, 2025020001, ['split_index' => 0, 'correct' => true]),
        ($this->result)($run, 2025020001, ['split_index' => 1, 'correct' => false]),
        ($this->result)($run, 2025020002, ['split_index' => 1, 'correct' => true]),
    ]);
    $rows = DB::table('nhl_sat_engine_candidates')->whereIn('id', [$foundation->id, $supplement])->get()
        ->sortBy(fn ($row) => array_search($row->id, [$foundation->id, $supplement], true))->values();

    $manual = app(NhlSatEngineStackAnalyzer::class)->manual($run, $rows);
    expect($manual['ids'])->toBe([(int) $foundation->id, $supplement])->and($manual['wins'])->toBe(2)->and($manual['losses'])->toBe(0);
});

it('selects the last games chronologically after filtering teams and dates', function (): void {
    ($this->seedGame)(2025020004, '2025-10-10', 'BOS', 'NYR');
    $ids = app(NhlSatEngineEvaluator::class)->gameIds($this->model, [...$this->scope,
        'mode' => 'games', 'selection' => 'last', 'count' => 1,
        'teams' => ['TOR'], 'start_date' => '2025-10-01', 'end_date' => '2025-10-10']);
    expect($ids)->toBe([2025020003]);
});

it('breaks same-day last-game ties by NHL game ID', function (): void {
    $ids = app(NhlSatEngineEvaluator::class)->gameIds($this->model, [...$this->scope,
        'mode' => 'games', 'selection' => 'last', 'count' => 1, 'end_date' => '2025-10-07']);
    expect($ids)->toBe([2025020002]);
});

it('selects the last matching game day and includes all games on that date', function (): void {
    ($this->seedGame)(2025020004, '2025-10-09', 'BOS', 'NYR');
    $ids = app(NhlSatEngineEvaluator::class)->gameIds($this->model,
        [...$this->scope, 'mode' => 'days', 'selection' => 'last', 'count' => 1]);
    expect($ids)->toBe([2025020003, 2025020004]);
});

it('selects reproducible random games without replacement from the filtered pool', function (): void {
    $scope = [...$this->scope, 'mode' => 'games', 'selection' => 'random', 'selection_seed' => 'fixed-test-seed',
        'count' => 1, 'teams' => ['TOR'], 'end_date' => '2025-10-09'];
    $evaluator = app(NhlSatEngineEvaluator::class);
    $first = $evaluator->gameIds($this->model, $scope);
    expect($first)->toHaveCount(1)->and($evaluator->gameIds($this->model, $scope))->toBe($first)
        ->and(array_diff($first, [2025020001, 2025020003]))->toBe([]);
    expect($evaluator->gameIds($this->model, [...$scope, 'count' => 5]))->toBe([2025020001, 2025020003]);
});

it('random game-day selection includes the entire selected date rather than sampling individual games', function (): void {
    ($this->seedGame)(2025020004, '2025-10-09', 'BOS', 'NYR');
    $scope = [...$this->scope, 'mode' => 'days', 'selection' => 'random', 'selection_seed' => 'fixed-days', 'count' => 1];
    $evaluator = app(NhlSatEngineEvaluator::class);
    $ids = $evaluator->gameIds($this->model, $scope);
    expect($ids)->toHaveCount(2)->and(in_array($ids, [[2025020001, 2025020002], [2025020003, 2025020004]], true))->toBeTrue()
        ->and($evaluator->gameIds($this->model, $scope))->toBe($ids);
});

it('rejects invalid selection values at the HTTP boundary', function (): void {
    $this->actingAs($this->admin)->postJson('/admin/nhl-sat-engines/runs', [...$this->input,
        'scope' => [...$this->scope, 'mode' => 'games', 'count' => 2, 'selection' => 'middle'],
    ])->assertUnprocessable()->assertJsonValidationErrors('scope.selection');
    Bus::assertNothingDispatched();
});

it('freezes the random sample and server seed in the run before queuing its splits', function (): void {
    \Illuminate\Support\Str::createUuidsUsing(fn () => \Ramsey\Uuid\Uuid::fromString('11111111-1111-4111-8111-111111111111'));
    try {
        $run = app(NhlSatEngineEvaluator::class)->start([...$this->input,
            'scope' => [...$this->scope, 'mode' => 'games', 'count' => 2, 'selection' => 'random', 'selection_seed' => 'ignored-client-seed'],
        ], $this->engine);
    } finally {
        \Illuminate\Support\Str::createUuidsNormally();
    }
    expect($run->definition['scope']['selection_seed'])->toBe('11111111-1111-4111-8111-111111111111')
        ->and($run->definition['game_ids'])->toHaveCount(2);
    $ids = $run->definition['game_ids'];
    ($this->seedGame)(2025020004, '2025-10-10');
    expect($run->fresh()->definition['game_ids'])->toBe($ids);
    Bus::assertDispatched(EvaluateNhlSatEngineGameJob::class, fn ($job) => $job->runId === $run->id && $job->gameIndex === 0);
});

it('ignores supplied discovery ranges instead of restricting automatic search', function (): void {
    $this->actingAs($this->admin)->post('/admin/nhl-sat-engines/runs', [...$this->input,
        'kind' => 'discovery', 'search' => ['offense' => ['min' => 88, 'max' => 88, 'step' => 1]],
    ])->assertRedirect();
    $run = NhlSatEngineRun::query()->firstOrFail();
    expect($run->definition['splits'])->toHaveCount(81)
        ->and(collect($run->definition['splits'])->pluck('offense')->unique()->count())->toBe(9);
});

it('searches independent full-domain weights and refines without repeating evaluated splits', function (): void {
    $settings = app(NhlSatEngineSettings::class);
    $coarse = $settings->automaticCandidates();
    expect($coarse)->toHaveCount(81)->and(collect($coarse)->contains(fn ($row) => $row['offense'] === 200 && $row['defense'] === 200))->toBeTrue();
    $refined = $settings->automaticCandidates(1, [['offense' => 100, 'defense' => 25]], $coarse);
    $keys = fn ($rows) => array_map(fn ($row) => $row['offense'] . ':' . $row['defense'], $rows);
    expect(array_intersect($keys($coarse), $keys($refined)))->toBe([])
        ->and(collect($refined)->contains(fn ($row) => $row['offense'] === 95 && $row['defense'] === 10))->toBeTrue();
    $fine = $settings->automaticCandidates(2, [['offense' => 80, 'defense' => 10]], [...$coarse, ...$refined]);
    expect(collect($fine)->contains(fn ($row) => $row['offense'] === 82 && $row['defense'] === 9))->toBeTrue()
        ->and($settings->automaticCandidates(3, $fine))->toBe([]);
});

it('bounds refinements to three centers and legal unique percentages', function (): void {
    $rows = app(NhlSatEngineSettings::class)->automaticCandidates(1, [
        ['offense' => 0, 'defense' => 0], ['offense' => 200, 'defense' => 200],
        ['offense' => 100, 'defense' => 100], ['offense' => 50, 'defense' => 150],
    ]);
    expect(count($rows))->toBeLessThanOrEqual(363)
        ->and(count(array_unique(array_map(fn ($row) => $row['offense'] . ':' . $row['defense'], $rows))))->toBe(count($rows));
    foreach ($rows as $row) {
        expect($row['offense'])->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(200);
        expect($row['defense'])->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(200);
    }
});

it('discovers exact score-gap cutoffs with strict equality and all-game totals', function (): void {
    $run = ($this->createRun)();
    DB::table('nhl_sat_engine_results')->insert([
        ($this->result)($run, 1, ['gap' => 0.050123, 'correct' => false]),
        ($this->result)($run, 2, ['gap' => 0.4]),
        ($this->result)($run, 3, ['gap' => 0.7]),
    ]);
    DB::enableQueryLog();
    try {
        DB::flushQueryLog();
        $rows = app(NhlSatEngineEvaluator::class)->discoverQualifications($run->id, 0, $this->settings, 3);
        $queries = DB::getQueryLog();
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    expect(count($queries))->toBeLessThanOrEqual(6);
    $best = collect($rows)->first(fn ($row) => $row['metrics']['picks'] === 2);
    expect($best['settings']['gap'])->toBe(0.050123)->and($best['metrics']['wins'])->toBe(2)
        ->and((float) $best['metrics']['actual_sat'])->toBe(330.0)
        ->and((float) $best['metrics']['pred_sat'])->toBe(300.0);
    $actual = app(NhlSatEngineEvaluator::class)->metrics($run->id, 0, $best['settings'], 3);
    expect($actual['picks'])->toBe(2)->and($actual['wins'])->toBe(2);
});

it('matches exhaustive gap and three-score confidence selections', function (): void {
    $run = ($this->createRun)();
    $games = [
        ['confidence' => 10, 'gap' => 0.05, 'correct' => false],
        ['confidence' => 10, 'gap' => 0.4, 'correct' => true],
        ['confidence' => 70, 'gap' => 0.2, 'correct' => true],
        ['confidence' => 80, 'gap' => 0.4, 'correct' => false],
        ['confidence' => 100, 'gap' => 11, 'correct' => true],
    ];
    foreach ($games as $index => $game) {
        DB::table('nhl_sat_engine_results')->insert(($this->result)($run, $index + 1, $game));
    }
    $found = app(NhlSatEngineEvaluator::class)->discoverQualifications($run->id, 0, $this->settings, 5);
    $exhaustive = [];
    foreach ([0, 0.05, 0.2, 0.4, 10] as $gap) {
        for ($low = 0; $low <= 100; $low++) {
            for ($high = $low; $high <= min(100, $low + 2); $high++) {
                $picked = array_filter($games, fn ($game) => $game['gap'] > $gap && $game['confidence'] >= $low && $game['confidence'] <= $high);
                if ($picked !== []) {
                    $exhaustive[] = ['picks' => count($picked), 'wins' => array_sum(array_column($picked, 'correct'))];
                }
            }
        }
    }
    for ($minimum = 1; $minimum <= 2; $minimum++) {
        $score = fn ($rows) => max(array_map(fn ($row) => $row['wins'] / $row['picks'],
            array_filter($rows, fn ($row) => $row['picks'] >= $minimum)));
        expect($score(array_column($found, 'metrics')))->toBe($score($exhaustive));
    }
});

it('retains above-domain gaps as picks while ignoring ties and excluded games during discovery', function (): void {
    $run = ($this->createRun)();
    DB::table('nhl_sat_engine_results')->insert([
        ($this->result)($run, 1, ['gap' => 11]),
        ($this->result)($run, 2, ['gap' => 0, 'correct' => null]),
        ($this->result)($run, 3, ['status' => 'excluded']),
    ]);
    $rows = app(NhlSatEngineEvaluator::class)->discoverQualifications($run->id, 0, $this->settings, 3);
    expect($rows)->toHaveCount(1)->and($rows[0]['metrics']['picks'])->toBe(1)
        ->and($rows[0]['metrics']['coverage_pct'])->toEqual(50)
        ->and($rows[0]['metrics']['excluded'])->toBe(1);
});

it('keeps automatic discovery with no qualifying evidence inspectable', function (): void {
    $run = ($this->createRun)();
    $rows = app(NhlSatEngineEvaluator::class)->discoverQualifications($run->id, 0, $this->settings, 3);
    expect($rows)->toHaveCount(1)->and($rows[0]['metrics']['win_pct'])->toBeNull()
        ->and($rows[0]['metrics']['picks'])->toBe(0);
});

it('advances a bounded lane to its next split only after the last game', function (): void {
    $run = app(NhlSatEngineEvaluator::class)->start([...$this->input, 'kind' => 'discovery',
        'scope' => [...$this->scope, 'mode' => 'games', 'count' => 1]], $this->engine);
    Bus::fake();
    $evaluator = Mockery::mock(NhlSatEngineEvaluator::class);
    $evaluator->shouldReceive('assertModelUnchanged')->once();
    $evaluator->shouldReceive('predict')->once()->andReturn(($this->result)($run, 2025020001));
    $job = new EvaluateNhlSatEngineGameJob($run->id, 0, 0);
    $job->handle($evaluator);
    $job->handle($evaluator);
    Bus::assertDispatchedTimes(EvaluateNhlSatEngineGameJob::class, 1);
    Bus::assertDispatched(EvaluateNhlSatEngineGameJob::class, fn ($next) => $next->splitIndex === 4 && $next->gameIndex === 0);
    expect($run->fresh()->predictions_completed)->toBe(1);
});

it('appends refinement work once and preserves the original game sample', function (): void {
    $run = ($this->createRun)();
    $games = $run->definition['game_ids'];
    $run->update(['kind' => 'discovery', 'status' => 'ranking', 'predictions_completed' => 3,
        'definition' => [...$run->definition, 'confidence_search' => 'automatic',
            'automatic_search' => ['strategy' => 'coarse_to_fine_v1', 'stage' => 0, 'stage_first_split' => 0, 'stage_split_count' => 1, 'lanes' => 4]]]);
    foreach ($games as $id) {
        DB::table('nhl_sat_engine_results')->insert(($this->result)($run, $id));
    }
    Bus::fake();
    $job = new RankNhlSatEngineCandidatesJob($run->id, 0);
    $job->handle(app(NhlSatEngineEvaluator::class));
    $updated = $run->fresh();
    expect($updated->status)->toBe('queued')->and($updated->definition['automatic_search']['stage'])->toBe(1)
        ->and($updated->definition['game_ids'])->toBe($games)->and($updated->candidates_completed)->toBe(1)
        ->and($updated->candidate_count)->toBeGreaterThan(1)->and($updated->completed_at)->toBeNull();
    $job->handle(app(NhlSatEngineEvaluator::class));
    expect($run->fresh()->candidate_count)->toBe($updated->candidate_count);
    Bus::assertDispatchedTimes(EvaluateNhlSatEngineGameJob::class, 4);
});

it('completes automatic discovery only after the final refinement is ranked', function (): void {
    $run = ($this->createRun)();
    $run->update(['kind' => 'discovery', 'status' => 'ranking', 'predictions_completed' => 3,
        'definition' => [...$run->definition, 'confidence_search' => 'automatic',
            'automatic_search' => ['strategy' => 'coarse_to_fine_v1', 'stage' => 2, 'stage_first_split' => 0, 'stage_split_count' => 1, 'lanes' => 4]]]);
    foreach ($run->definition['game_ids'] as $id) {
        DB::table('nhl_sat_engine_results')->insert(($this->result)($run, $id));
    }
    Bus::fake();
    (new RankNhlSatEngineCandidatesJob($run->id, 0))->handle(app(NhlSatEngineEvaluator::class));
    expect($run->fresh()->status)->toBe('complete')->and($run->fresh()->completed_at)->not->toBeNull();
    Bus::assertNothingDispatched();
});

it('creates an engine from an evaluated candidate while discovery continues', function (string $status): void {
    $run = ($this->createRun)();
    $run->update(['kind' => 'discovery', 'status' => $status]);
    $candidate = DB::table('nhl_sat_engine_candidates')->where('run_id', $run->id)->first();
    $settings = ['offense' => 175, 'defense' => 25, 'confidence_min' => 70, 'confidence_max' => 75, 'gap' => 0.4985];
    DB::table('nhl_sat_engine_candidates')->where('id', $candidate->id)->update(['metrics' => '{}', 'settings' => json_encode($settings)]);
    Bus::fake();
    $this->actingAs($this->admin)->post('/admin/nhl-sat-engines/runs/' . $run->id . '/candidates/' . $candidate->id . '/apply',
        ['name' => 'From result', 'settings' => [...$settings, 'offense' => 1]])->assertRedirect();
    $engine = NhlSatEngine::query()->where('name', 'From result')->firstOrFail();
    expect($engine->model_run_id)->toBe($run->model_run_id)->and($engine->settings)->toEqual($settings)
        ->and($run->fresh()->status)->toBe($status);
    $this->get('/admin/nhl-sat-engines/' . $engine->id)->assertInertia(fn (Assert $page) => $page
        ->where('engine.name', 'From result')->where('engine.settings.gap', 0.4985)->where('engine.settings.offense', 175));
    Bus::assertNothingDispatched();
})->with(['queued', 'running', 'ranking', 'complete']);

it('rejects candidate adoption from failed or cancelled runs', function (string $status): void {
    $run = ($this->createRun)(); $run->update(['status' => $status]);
    $candidate = DB::table('nhl_sat_engine_candidates')->where('run_id', $run->id)->first();
    DB::table('nhl_sat_engine_candidates')->where('id', $candidate->id)->update(['metrics' => '{}']);
    $this->actingAs($this->admin)->postJson('/admin/nhl-sat-engines/runs/' . $run->id . '/candidates/' . $candidate->id . '/apply',
        ['name' => 'Rejected'])->assertStatus(409);
    $this->assertDatabaseMissing('nhl_sat_engines', ['name' => 'Rejected']);
})->with(['failed', 'cancelled']);

it('requires an engine name without changing the evaluated candidate', function (): void {
    $run = ($this->createRun)();
    $candidate = DB::table('nhl_sat_engine_candidates')->where('run_id', $run->id)->first();
    DB::table('nhl_sat_engine_candidates')->where('id', $candidate->id)->update(['metrics' => '{}']);
    $this->actingAs($this->admin)->postJson('/admin/nhl-sat-engines/runs/' . $run->id . '/candidates/' . $candidate->id . '/apply',
        ['name' => ''])->assertUnprocessable()->assertJsonValidationErrors('name');
    expect(NhlSatEngine::query()->count())->toBe(1)
        ->and(DB::table('nhl_sat_engine_candidates')->where('id', $candidate->id)->value('settings'))->toBe($candidate->settings);
});
