<?php

declare(strict_types=1);

use App\Jobs\EvaluateNhlSatEngineGameJob;
use App\Jobs\RankNhlSatEngineCandidatesJob;
use App\Models\NhlModelRun;
use App\Models\NhlSatEngine;
use App\Models\NhlSatEngineRun;
use App\Models\NhlSatEngineStack;
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
    ['GET', '/stacks/1/predictions/today'], ['POST', '/stacks/1/predictions/2025020001'],
    ['GET', ''], ['POST', ''], ['GET', '/discover'], ['GET', '/1'], ['PUT', '/1'], ['DELETE', '/1'],
    ['POST', '/runs'], ['GET', '/runs/1'], ['POST', '/runs/1/pause'], ['POST', '/runs/1/resume'], ['POST', '/runs/1/cancel'], ['POST', '/runs/1/candidates/1/apply'], ['POST', '/runs/1/stacks'], ['GET', '/stacks/1'], ['PATCH', '/stacks/1'], ['POST', '/stacks/1/members'], ['PUT', '/stacks/1/members/order'], ['DELETE', '/stacks/1/members/1'], ['DELETE', '/stacks/1'], ['POST', '/stacks/1/default'],
]);

it('blocks ordinary users on every engine endpoint', function (string $verb, string $path): void {
    $this->actingAs(User::factory()->create())->json($verb, '/admin/nhl-sat-engines' . $path)->assertForbidden();
    Bus::assertNothingDispatched();
})->with([
    ['GET', '/stacks/1/predictions/today'], ['POST', '/stacks/1/predictions/2025020001'],
    ['GET', ''], ['POST', ''], ['GET', '/discover'], ['GET', '/1'], ['PUT', '/1'], ['DELETE', '/1'],
    ['POST', '/runs'], ['GET', '/runs/1'], ['POST', '/runs/1/pause'], ['POST', '/runs/1/resume'], ['POST', '/runs/1/cancel'], ['POST', '/runs/1/candidates/1/apply'], ['POST', '/runs/1/stacks'], ['GET', '/stacks/1'], ['PATCH', '/stacks/1'], ['POST', '/stacks/1/members'], ['PUT', '/stacks/1/members/order'], ['DELETE', '/stacks/1/members/1'], ['DELETE', '/stacks/1'], ['POST', '/stacks/1/default'],
]);

it('previews the selected non-default stack through the shared delegation and stops at the first pick', function (int $winner): void {
    $stack = NhlSatEngineStack::query()->create(['name' => 'Selected', 'production_model_run_id' => $this->model->id]);
    $engines = [];
    foreach (range(1, 3) as $priority) {
        $engine = (new NhlSatEngine())->saveDefinition([...$this->definition, 'name' => 'Priority ' . $priority]);
        $stack->members()->create(['engine_id' => $engine->id, 'priority' => $priority]);
        $engines[] = $engine;
    }
    $payload = Mockery::mock(\App\Services\NhlGamePredictionPayload::class)->makePartial();
    foreach ($engines as $index => $engine) {
        if ($index >= $winner) {
            $payload->shouldNotReceive('build')->withArgs(fn ($game, $overrides) => $overrides['_stack_engine_id'] === $engine->id);
            continue;
        }
        $payload->shouldReceive('build')->once()->ordered()->withArgs(fn ($game, $overrides) =>
            $game === 2025020001 && $overrides['_stack_engine_id'] === $engine->id
            && $overrides['sat_model_run_id'] === $this->model->id)
            ->andReturn(['prediction_available' => true, 'pick_qualified' => $index + 1 === $winner,
                'inputs' => ['engine_id' => $engine->id, 'sat_model_run_id' => $this->model->id],
                'prediction' => ['confidence_score' => 80], '_confidence_components' => ['skater' => 90, 'goalie' => 50],
                '_qualification_spread' => 5]);
    }
    $result = $payload->previewStack(2025020001, $stack);
    expect($result['engine_id'])->toBe($engines[$winner - 1]->id)->and($result['pick_qualified'])->toBeTrue()
        ->and($result['internal_confidence'])->toBe(80)->and($result['skater_confidence'])->toBe(90)
        ->and($stack->fresh()->is_default)->toBeFalse();
    expect($result['attempts'])->toHaveCount($winner);
    foreach ($result['attempts'] as $index => $attempt) {
        expect($attempt['engine_id'])->toBe($engines[$index]->id)
            ->and($attempt['engine_name'])->toBe('Priority ' . ($index + 1))
            ->and($attempt['internal_confidence'])->toBe(80)
            ->and($attempt['pick_qualified'])->toBe($index + 1 === $winner)
            ->and($attempt['prediction']['confidence_score'])->toEqual($index + 1 === $winner ? 92 : 80);
    }
    Bus::assertNothingDispatched();
})->with([1, 2, 3]);

it('returns the same selected prediction in the ordinary path and stack preview without leaking internal confidence', function (): void {
    $stack = NhlSatEngineStack::query()->create(['name' => 'Default', 'is_default' => true]);
    $stack->members()->create(['engine_id' => $this->engine->id, 'priority' => 1]);
    $payload = Mockery::mock(\App\Services\NhlGamePredictionPayload::class)->makePartial();
    $payload->shouldReceive('build')->twice()->andReturnUsing(function ($game, $overrides): array {
        $row = ['prediction_available' => true, 'pick_qualified' => true,
            'inputs' => ['engine_id' => $this->engine->id, 'sat_model_run_id' => $this->model->id],
            'prediction' => ['confidence_score' => 80, 'winner' => 'TOR', 'predicted_score' => ['away' => 3, 'home' => 2]],
            'market_probabilities' => [['confidence_score' => 80]]];
        if ($overrides['_include_confidence_components'] ?? false) {
            $row['_confidence_components'] = ['skater' => 90, 'goalie' => 50];
        }

        return $row;
    });
    $ordinary = (new ReflectionMethod(\App\Services\NhlGamePredictionPayload::class, 'buildPrediction'))
        ->invoke($payload, 2025020001, []);
    $preview = $payload->previewStack(2025020001, $stack);
    expect($preview['prediction'])->toBe($ordinary['prediction'])
        ->and($preview['engine_id'])->toBe($ordinary['inputs']['engine_id'])
        ->and($preview['internal_confidence'])->toBe(80)
        ->and($ordinary)->not->toHaveKey('_internal_confidence')->not->toHaveKey('_result_engine_id')
        ->not->toHaveKey('_engine_attempts');
});

it('retains the ordinary no-pick fallback and penalizes presentation only after all members decline', function (): void {
    $stack = NhlSatEngineStack::query()->create(['name' => 'Fallback']);
    $stack->members()->create(['engine_id' => $this->engine->id, 'priority' => 1]);
    $second = (new NhlSatEngine())->saveDefinition([...$this->definition, 'name' => 'Second']);
    $stack->members()->create(['engine_id' => $second->id, 'priority' => 2]);
    $payload = Mockery::mock(\App\Services\NhlGamePredictionPayload::class)->makePartial();
    foreach ([$this->engine, $second] as $engine) {
        $payload->shouldReceive('build')->once()->ordered()->withArgs(fn ($game, $overrides) =>
            $overrides['_stack_engine_id'] === $engine->id && $overrides['sat_model_run_id'] === $engine->model_run_id)
            ->andReturn(['prediction_available' => true, 'pick_qualified' => false,
                'inputs' => ['engine_id' => $engine->id, 'sat_model_run_id' => $engine->model_run_id],
                'prediction' => ['confidence_score' => 80], '_confidence_components' => ['skater' => 90, 'goalie' => 50]]);
    }
    $result = $payload->previewStack(2025020001, $stack);
    expect($result['pick_qualified'])->toBeFalse()->and($result['engine_id'])->toBeNull()
        ->and($result['result_engine_id'])->toBe($this->engine->id)
        ->and($result['internal_confidence'])->toBe(80)->and($result['prediction']['confidence_score'])->toEqual(60);
    expect($result['attempts'])->toHaveCount(2);
    foreach ($result['attempts'] as $attempt) {
        expect($attempt['pick_qualified'])->toBeFalse()->and($attempt['internal_confidence'])->toBe(80)
            ->and($attempt['prediction']['confidence_score'])->toEqual(60);
    }
});

it('persists and restores skipped engine names privately without recalculating predictions', function (): void {
    $snapshot = ['version' => 2, 'stack' => ['id' => 1, 'name' => 'Captured', 'production_model_run_id' => null],
        'sections' => [[
            'member' => ['id' => 1, 'engine' => ['id' => $this->engine->id, 'name' => 'Second', 'settings' => $this->settings,
                'model_run_id' => $this->model->id, 'test_model_run_id' => $this->model->id]],
            'date' => '2026-10-01', 'open' => true, 'error' => null, 'status' => 'Calculated',
            'sort' => ['key' => 'game', 'direction' => 1],
            'rows' => [['id' => 2025020001, 'key' => '2025020001-production', 'source' => 'production', 'model' => 'Production',
                'modelName' => null, 'game' => 'NYR @ WSH', 'status' => 'Skipped', 'pickedBy' => 'Foundation',
                'score' => null, 'spread' => null, 'skater' => null, 'goalie' => null, 'internal' => null,
                'presentation' => null, 'qualified' => null, 'error' => null]],
        ]]];
    $url = '/admin/admin-engine-game-predictions';
    $invalid = $snapshot;
    $invalid['sections'][0]['rows'][0]['pickedBy'] = null;
    $this->actingAs($this->admin)->postJson($url, ['name' => 'Invalid', 'snapshot' => $invalid])->assertUnprocessable();
    $saved = $this->postJson($url, ['name' => 'Named capture', 'snapshot' => $snapshot])->assertOk();
    $id = $saved->json('id');
    $this->assertDatabaseHas('admin_engine_game_predictions', ['id' => $id, 'user_id' => $this->admin->id]);
    $this->getJson($url . '/' . $id)->assertOk()
        ->assertJsonPath('snapshot.sections.0.rows.0.status', 'Skipped')
        ->assertJsonPath('snapshot.sections.0.rows.0.pickedBy', 'Foundation')
        ->assertJsonPath('snapshot.sections.0.rows.0.internal', null);
    $other = User::factory()->create();
    $other->roles()->attach(Role::query()->where('slug', 'super-admin')->value('id'), ['organization_id' => null]);
    $this->actingAs($other)->getJson($url . '/' . $id)->assertNotFound();
    Bus::assertNothingDispatched();
});

it('lists todays games and invokes only the selected stack preview without changing defaults', function (): void {
    $this->travelTo(\Illuminate\Support\Carbon::parse('2025-10-07 12:00:00 America/Toronto'));
    $stack = NhlSatEngineStack::query()->create(['name' => 'Non-default']);
    $stack->members()->create(['engine_id' => $this->engine->id, 'priority' => 1]);
    $this->mock(\App\Services\NhlGamePredictionPayload::class)->shouldReceive('previewStack')->once()
        ->withArgs(fn ($id, $selected) => $id === 2025020001 && $selected->id === $stack->id)
        ->andReturn(['prediction_available' => true, 'pick_qualified' => true, 'model_run_id' => $this->model->id,
            'engine_id' => $this->engine->id, 'internal_confidence' => 80, 'prediction' => ['confidence_score' => 92]]);
    $url = '/admin/nhl-sat-engines/stacks/' . $stack->id . '/predictions';
    $this->actingAs($this->admin)->getJson($url . '/today')->assertOk()->assertJsonCount(2, 'games');
    $this->postJson($url . '/2025020001')->assertOk()->assertJsonPath('engine_id', $this->engine->id)
        ->assertJsonPath('internal_confidence', 80)->assertJsonPath('prediction.confidence_score', 92)
        ->assertHeader('Cache-Control', 'no-store, private');
    $this->postJson($url . '/2025020003')->assertNotFound();
    expect($stack->fresh()->is_default)->toBeFalse();
    Bus::assertNothingDispatched();
});

it('rejects a stack preview without members instead of substituting the default stack', function (): void {
    $this->travelTo(\Illuminate\Support\Carbon::parse('2025-10-07 12:00:00 America/Toronto'));
    $stack = NhlSatEngineStack::query()->create(['name' => 'Empty']);
    $this->actingAs($this->admin)->postJson('/admin/nhl-sat-engines/stacks/' . $stack->id . '/predictions/2025020001')
        ->assertUnprocessable()->assertJsonValidationErrors('stack');
    Bus::assertNothingDispatched();
});

it('creates an engine without dispatching work and reads it through Inertia', function (): void {
    $this->actingAs($this->admin)->post('/admin/nhl-sat-engines', [...$this->definition, 'name' => 'Created'])->assertRedirect();
    $engine = NhlSatEngine::query()->where('name', 'Created')->firstOrFail();
    $this->assertDatabaseHas('nhl_sat_engines', ['id' => $engine->id, 'model_run_id' => $this->model->id, 'discovery_run_id' => null]);
    $this->get('/admin/nhl-sat-engines/' . $engine->id)->assertInertia(fn (Assert $page) => $page
        ->component('Admin/SatEngines/Workspace')->where('engine.name', 'Created')->where('engine.settings.offense', 88));
    Bus::assertNothingDispatched();
});

it('selects one non-empty stack as the default without starting an evaluation', function (): void {
    $first = NhlSatEngineStack::query()->create(['name' => 'First']);
    $first->members()->create(['engine_id' => $this->engine->id, 'priority' => 1]);
    $second = NhlSatEngineStack::query()->create(['name' => 'Second']);
    $second->members()->create(['engine_id' => $this->engine->id, 'priority' => 1]);

    $this->actingAs($this->admin)->post('/admin/nhl-sat-engines/stacks/' . $first->id . '/default')->assertRedirect();
    $this->actingAs($this->admin)->post('/admin/nhl-sat-engines/stacks/' . $second->id . '/default')->assertRedirect();

    expect($first->fresh()->is_default)->toBeFalse()->and($second->fresh()->is_default)->toBeTrue();
    Bus::assertNothingDispatched();
});

it('persists a stack production model without replacing member engine models', function (): void {
    $override = NhlModelRun::query()->create([
        'run_key' => 'stack-production-override', 'name' => 'Stack production model', 'model_family' => 'sat',
        'workflow_stage' => 'training', 'model_version' => 'stack-production-override', 'usage' => 'production', 'status' => 'complete',
        'train_season_ids' => ['20222023', '20232024', '20242025'], 'target_season_id' => '20252026', 'metrics' => [],
    ]);
    $stack = NhlSatEngineStack::query()->create(['name' => 'Production override']);
    $stack->members()->create(['engine_id' => $this->engine->id, 'priority' => 1]);

    $this->actingAs($this->admin)->patch('/admin/nhl-sat-engines/stacks/' . $stack->id, [
        'name' => 'Production override', 'production_model_run_id' => $override->id,
    ])->assertRedirect();

    expect($stack->fresh()->production_model_run_id)->toBe($override->id)
        ->and($this->engine->fresh()->model_run_id)->toBe($this->model->id);
    $this->actingAs($this->admin)->get('/admin/nhl-sat-engines/stacks/' . $stack->id)->assertInertia(fn (Assert $page) => $page
        ->where('stack.production_model_run_id', $override->id)
        ->where('models.0.id', $override->id));
    Bus::assertNothingDispatched();
});

it('rejects selecting an empty stack or removing the last default-stack engine', function (): void {
    $empty = NhlSatEngineStack::query()->create(['name' => 'Empty']);
    $this->actingAs($this->admin)->postJson('/admin/nhl-sat-engines/stacks/' . $empty->id . '/default')
        ->assertUnprocessable()->assertJsonValidationErrors('stack');

    $stack = NhlSatEngineStack::query()->create(['name' => 'Default']);
    $member = $stack->members()->create(['engine_id' => $this->engine->id, 'priority' => 1]);
    $this->actingAs($this->admin)->post('/admin/nhl-sat-engines/stacks/' . $stack->id . '/default')->assertRedirect();
    $this->actingAs($this->admin)->deleteJson('/admin/nhl-sat-engines/stacks/' . $stack->id . '/members/' . $member->id)
        ->assertUnprocessable()->assertJsonValidationErrors('stack');
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
    DB::table('nhl_sat_engine_candidates')->where('id', $candidate->id)->update([
        'metrics' => '{}', 'win_pct' => 75.5, 'coverage_pct' => 42.25,
    ]);
    $this->post($url, ['name' => 'Adopted'])->assertRedirect();
    $engine = NhlSatEngine::query()->where('name', 'Adopted')->firstOrFail();
    expect($engine->model_run_id)->toBe($this->model->id)->and($engine->settings)->toEqual($this->settings)
        ->and($engine->discovery_candidate_id)->toBe($candidate->id)
        ->and((float) $engine->discovery_win_pct)->toBe(75.5)
        ->and((float) $engine->discovery_coverage_pct)->toBe(42.25);
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

it('persists optional constraints from a request and exposes them on the run page', function (): void {
    $constraints = ['offense' => 0, 'defense' => 75, 'confidence_min' => 74, 'confidence_max' => 80, 'gap' => 5, 'gap_operator' => '<'];
    $this->actingAs($this->admin)->post('/admin/nhl-sat-engines/runs', [
        ...$this->input, 'kind' => 'discovery', 'engine_id' => null, 'constraints' => $constraints,
    ])->assertRedirect();
    $run = NhlSatEngineRun::query()->firstOrFail();
    expect($run->definition['constraints'])->toBe($constraints)->and($run->definition['gap_unit'])->toBe('percent');
    $candidate = json_decode(DB::table('nhl_sat_engine_candidates')->where('run_id', $run->id)->value('settings'), true);
    expect($candidate)->toMatchArray([...$constraints, 'gap_unit' => 'percent']);
    $this->get('/admin/nhl-sat-engines/runs/' . $run->id)->assertInertia(fn (Assert $page) => $page
        ->where('run.definition.constraints', $constraints)->where('run.definition.gap_unit', 'percent'));
});

it('rejects invalid discovery constraints before dispatch', function (array $constraints): void {
    $this->actingAs($this->admin)->postJson('/admin/nhl-sat-engines/runs', [
        ...$this->input, 'kind' => 'discovery', 'constraints' => $constraints,
    ])->assertUnprocessable();
    $this->assertDatabaseCount('nhl_sat_engine_runs', 0);
    Bus::assertNothingDispatched();
})->with([
    [['offense' => 201]], [['defense' => -1]], [['confidence_min' => 81, 'confidence_max' => 80]],
    [['gap' => 101]], [['gap' => 5.5]], [['gap_operator' => '>=']],
]);

it('keeps one-sided confidence limits and explicit zero weights', function (array $constraints, int $minimum, int $maximum): void {
    $service = app(NhlSatEngineSettings::class);
    $rows = $service->constrainedCandidates($service->discoveryConstraints($constraints));
    expect($rows[0]['confidence_min'])->toBe($minimum)->and($rows[0]['confidence_max'])->toBe($maximum);
    expect($rows[0]['offense'])->toBe($constraints['offense'] ?? 100);
})->with([
    [['confidence_min' => 74, 'confidence_max' => ''], 74, 100],
    [['confidence_max' => 80, 'offense' => 0], 0, 80],
    [['confidence_min' => '', 'confidence_max' => ''], 0, 100],
]);

it('varies only unspecified weights during stage three and never repeats a pair', function (): void {
    $service = app(NhlSatEngineSettings::class);
    $initial = $service->constrainedCandidates(['offense' => 113]);
    $coarse = $service->constrainedCandidates(['offense' => 113], 0, [], $initial);
    expect($coarse)->toHaveCount(8)->and(array_unique(array_column($coarse, 'offense')))->toBe([113]);
    $fine = $service->constrainedCandidates(['offense' => 113], 1, [['offense' => 113, 'defense' => 50]], [...$initial, ...$coarse]);
    expect(array_unique(array_column($fine, 'offense')))->toBe([113]);
    expect($service->constrainedCandidates(['offense' => 113, 'defense' => 50], 0, [], $initial))->toBe([]);
});

it('uses scale-independent percentage spreads while preserving legacy goal gaps', function (): void {
    $service = app(NhlSatEngineSettings::class);
    expect($service->scoreGap(2, 3, 'percent'))->toBe(20.0)
        ->and($service->scoreGap(4, 6, 'percent'))->toBe(20.0)
        ->and($service->scoreGap(0, 0, 'percent'))->toBe(0.0)
        ->and($service->scoreGap(2, 3))->toBe(1.0);
    expect($service->gapQualifies(5, ['gap' => 5, 'gap_operator' => '<']))->toBeFalse()
        ->and($service->gapQualifies(4, ['gap' => 5, 'gap_operator' => '<']))->toBeTrue()
        ->and($service->gapQualifies(6, ['gap' => 5]))->toBeTrue();
});

it('keeps fixed percentage spread and bounded confidence during ranking', function (string $operator): void {
    $constraints = ['offense' => 100, 'defense' => 50, 'confidence_min' => 74, 'confidence_max' => 80,
        'gap' => 5, 'gap_operator' => $operator];
    $run = app(NhlSatEngineEvaluator::class)->start([...$this->input, 'kind' => 'discovery', 'constraints' => $constraints], $this->engine);
    $settings = json_decode(DB::table('nhl_sat_engine_candidates')->where('run_id', $run->id)->value('settings'), true);
    foreach ([[73, 4], [74, 4], [75, 5], [80, 6], [81, 6]] as $index => [$confidence, $gap]) {
        DB::table('nhl_sat_engine_results')->insert(($this->result)($run, $index + 1, compact('confidence', 'gap')));
    }
    $evaluator = app(NhlSatEngineEvaluator::class);
    $rows = $evaluator->discoverQualifications($run->id, 0, $settings, 5);
    foreach ($rows as $row) {
        expect($row['settings']['confidence_min'])->toBeGreaterThanOrEqual(74);
        expect($row['settings']['confidence_max'])->toBeLessThanOrEqual(80);
        expect($row['settings']['gap'])->toBe(5)->and($row['settings']['gap_operator'])->toBe($operator);
        expect($row['metrics']['picks'])->toBe(1);
        expect($evaluator->metrics($run->id, 0, $row['settings'], 5)['picks'])->toBe(1);
    }
})->with(['>', '<']);

it('searches automatic percentage thresholds above ten without escaping confidence bounds', function (): void {
    $run = app(NhlSatEngineEvaluator::class)->start([...$this->input, 'kind' => 'discovery',
        'constraints' => ['confidence_min' => 74, 'confidence_max' => 80]], $this->engine);
    $settings = json_decode(DB::table('nhl_sat_engine_candidates')->where('run_id', $run->id)->value('settings'), true);
    DB::table('nhl_sat_engine_results')->insert([
        ($this->result)($run, 1, ['confidence' => 75, 'gap' => 25, 'correct' => false]),
        ($this->result)($run, 2, ['confidence' => 75, 'gap' => 35]),
        ($this->result)($run, 3, ['confidence' => 73, 'gap' => 50]),
    ]);
    $rows = app(NhlSatEngineEvaluator::class)->discoverQualifications($run->id, 0, $settings, 3);
    $best = collect($rows)->first(fn ($row) => $row['metrics']['picks'] === 1);
    expect($best['settings']['gap'])->toEqual(25)->and($best['settings']['gap_unit'])->toBe('percent')
        ->and($best['settings']['confidence_min'])->toBe(75)->and($best['metrics']['wins'])->toBe(1);
});

it('enters the qualification stage before scheduling any variable weight predictions', function (): void {
    $run = app(NhlSatEngineEvaluator::class)->start([...$this->input, 'kind' => 'discovery',
        'scope' => [...$this->scope, 'mode' => 'games', 'count' => 1]], $this->engine);
    Bus::fake();
    $evaluator = Mockery::mock(NhlSatEngineEvaluator::class);
    $evaluator->shouldReceive('assertModelUnchanged')->once();
    $evaluator->shouldReceive('predict')->once()->andReturn(($this->result)($run, 2025020001));
    $evaluator->shouldReceive('dispatchRankingJobs')->once()->andReturnUsing(function ($run): void {
        app(NhlSatEngineEvaluator::class)->dispatchRankingJobs($run);
    });
    (new EvaluateNhlSatEngineGameJob($run->id, 0, 0, 1))->handle($evaluator);
    expect($run->fresh()->status)->toBe('ranking')->and($run->fresh()->definition['automatic_search']['stage'])->toBe(1);
    Bus::assertNotDispatched(EvaluateNhlSatEngineGameJob::class);
    Bus::assertDispatchedTimes(RankNhlSatEngineCandidatesJob::class, 1);
});

it('starts stage three after ranking and preserves fixed weights and confidence bounds', function (): void {
    $run = app(NhlSatEngineEvaluator::class)->start([...$this->input, 'kind' => 'discovery',
        'constraints' => ['offense' => 113, 'confidence_min' => 74]], $this->engine);
    $run->update(['status' => 'ranking', 'predictions_completed' => 3]);
    foreach ($run->definition['game_ids'] as $id) {
        DB::table('nhl_sat_engine_results')->insert(($this->result)($run, $id, ['confidence' => 75]));
    }
    Bus::fake();
    $job = new RankNhlSatEngineCandidatesJob($run->id, 0, 1);
    $job->handle(app(NhlSatEngineEvaluator::class));
    $updated = $run->fresh();
    expect($updated->definition['automatic_search']['stage'])->toBe(2)
        ->and($updated->definition['automatic_search']['weight_stage'])->toBe(0)
        ->and($updated->definition['splits'])->toHaveCount(9);
    foreach (DB::table('nhl_sat_engine_candidates')->where('run_id', $run->id)->get() as $candidate) {
        $settings = json_decode($candidate->settings, true);
        expect($settings['offense'])->toBe(113)->and($settings['confidence_min'])->toBeGreaterThanOrEqual(74);
    }
    $job->handle(app(NhlSatEngineEvaluator::class));
    expect($run->fresh()->candidate_count)->toBe($updated->candidate_count);
    Bus::assertDispatchedTimes(EvaluateNhlSatEngineGameJob::class, 16);
});

it('finishes after qualification ranking when both weights are fixed', function (): void {
    $run = app(NhlSatEngineEvaluator::class)->start([...$this->input, 'kind' => 'discovery',
        'constraints' => ['offense' => 100, 'defense' => 50]], $this->engine);
    $run->update(['status' => 'ranking', 'predictions_completed' => 3]);
    foreach ($run->definition['game_ids'] as $id) {
        DB::table('nhl_sat_engine_results')->insert(($this->result)($run, $id));
    }
    Bus::fake();
    (new RankNhlSatEngineCandidatesJob($run->id, 0, 1))->handle(app(NhlSatEngineEvaluator::class));
    expect($run->fresh()->status)->toBe('complete')->and($run->fresh()->prediction_count)->toBe(3);
    Bus::assertNotDispatched(EvaluateNhlSatEngineGameJob::class);
});

it('preserves percentage direction when adopting and editing a discovered engine', function (): void {
    $run = app(NhlSatEngineEvaluator::class)->start([...$this->input, 'kind' => 'discovery',
        'constraints' => ['gap' => 25, 'gap_operator' => '<']], $this->engine);
    $candidate = DB::table('nhl_sat_engine_candidates')->where('run_id', $run->id)->first();
    DB::table('nhl_sat_engine_candidates')->where('id', $candidate->id)->update(['metrics' => '{}']);
    $this->actingAs($this->admin)->post('/admin/nhl-sat-engines/runs/' . $run->id . '/candidates/' . $candidate->id . '/apply', [
        'name' => 'Percentage candidate', 'model_run_id' => $this->model->id, 'test_model_run_id' => $this->model->id,
    ])->assertRedirect();
    $engine = NhlSatEngine::query()->where('name', 'Percentage candidate')->firstOrFail();
    $this->put('/admin/nhl-sat-engines/' . $engine->id, [...$this->definition, 'name' => $engine->name,
        'settings' => $engine->settings])->assertRedirect();
    $this->get('/admin/nhl-sat-engines/' . $engine->id)->assertInertia(fn (Assert $page) => $page
        ->where('engine.settings.gap_unit', 'percent')->where('engine.settings.gap_operator', '<')->where('engine.settings.gap', 25));
});

it('starts discovery with only the fixed starting pair before searching weights', function (): void {
    $this->actingAs($this->admin)->post('/admin/nhl-sat-engines/runs', [
        ...$this->input, 'kind' => 'discovery', 'engine_id' => null,
    ])->assertRedirect();
    $run = NhlSatEngineRun::query()->firstOrFail();
    expect($run->prediction_count)->toBe(3)->and($run->candidate_count)->toBe(1)
        ->and($run->definition['splits'][0])->toBe(['index' => 0, 'offense' => 100, 'defense' => 50])
        ->and($run->definition['automatic_search']['strategy'])->toBe('qualification_first_v1')
        ->and($run->definition['automatic_search']['lanes'])->toBe(16)
        ->and($run->definition['search'])->toBeNull();
    $this->assertDatabaseCount('nhl_sat_engine_candidates', 1);
    Bus::assertDispatchedTimes(EvaluateNhlSatEngineGameJob::class, 3);
    $this->get('/admin/nhl-sat-engines/runs/' . $run->id)->assertInertia(fn (Assert $page) => $page
        ->where('run.definition.automatic_search.stage', 0)->where('run.candidate_count', 1));
});

it('fills sixteen game slots even when discovery has only one weight pair', function (): void {
    foreach (range(10, 39) as $index) {
        ($this->seedGame)(2025020000 + $index, '2025-10-10');
    }
    $run = app(NhlSatEngineEvaluator::class)->start([...$this->input, 'kind' => 'discovery'], $this->engine);
    expect($run->definition['automatic_search']['work_scheduling'])->toBe('game_lanes_v1');
    Bus::assertDispatchedTimes(EvaluateNhlSatEngineGameJob::class, 16);
    foreach (range(0, 15) as $game) {
        Bus::assertDispatched(EvaluateNhlSatEngineGameJob::class, fn ($job) => $job->splitIndex === 0 && $job->gameIndex === $game);
    }
});

it('refills only its own game lane and skips previously committed coordinates', function (): void {
    foreach (range(10, 39) as $index) {
        ($this->seedGame)(2025020000 + $index, '2025-10-10');
    }
    $run = app(NhlSatEngineEvaluator::class)->start([...$this->input, 'kind' => 'discovery'], $this->engine);
    DB::table('nhl_sat_engine_results')->insert(($this->result)($run, $run->definition['game_ids'][16]));
    $run->update(['predictions_completed' => 1]);
    Bus::fake();
    $evaluator = Mockery::mock(NhlSatEngineEvaluator::class);
    $evaluator->shouldReceive('assertModelUnchanged')->once();
    $evaluator->shouldReceive('predict')->once()->andReturn(($this->result)($run, $run->definition['game_ids'][0]));
    $job = new EvaluateNhlSatEngineGameJob($run->id, 0, 0, 1);
    $job->handle($evaluator);
    $job->handle($evaluator);
    Bus::assertDispatchedTimes(EvaluateNhlSatEngineGameJob::class, 1);
    Bus::assertDispatched(EvaluateNhlSatEngineGameJob::class, fn ($next) => $next->gameIndex === 32 && $next->splitIndex === 0);
    expect($run->fresh()->predictions_completed)->toBe(2);
});

it('upgrades paused legacy discovery to game lanes without losing completed results', function (): void {
    $run = app(NhlSatEngineEvaluator::class)->start([...$this->input, 'kind' => 'discovery'], $this->engine);
    $definition = $run->definition;
    unset($definition['automatic_search']['work_scheduling']);
    $definition['automatic_search']['lanes'] = 4;
    DB::table('nhl_sat_engine_results')->insert(($this->result)($run, 2025020001));
    $run->update(['definition' => $definition, 'predictions_completed' => 1, 'status' => 'paused', 'paused_status' => 'running', 'work_generation' => 2]);
    Bus::fake();
    app(NhlSatEngineEvaluator::class)->resume($run);
    Bus::assertDispatchedTimes(EvaluateNhlSatEngineGameJob::class, 2);
    expect($run->fresh()->definition['automatic_search']['lanes'])->toBe(16)
        ->and($run->fresh()->definition['automatic_search']['work_scheduling'])->toBe('game_lanes_v1');
    $this->assertDatabaseCount('nhl_sat_engine_results', 1);
});

it('opens ranking exactly once after out-of-order game completion reaches the barrier', function (): void {
    $run = app(NhlSatEngineEvaluator::class)->start([...$this->input, 'kind' => 'discovery'], $this->engine);
    Bus::fake();
    $evaluator = Mockery::mock(NhlSatEngineEvaluator::class, [app(NhlSatEngineSettings::class)])->makePartial();
    $evaluator->shouldReceive('predict')->times(3)->andReturnUsing(fn ($snapshot, $split, $id) => ($this->result)($run, $id));
    foreach ([2, 0] as $game) {
        (new EvaluateNhlSatEngineGameJob($run->id, 0, $game, 1))->handle($evaluator);
    }
    Bus::assertNotDispatched(RankNhlSatEngineCandidatesJob::class);
    $last = new EvaluateNhlSatEngineGameJob($run->id, 0, 1, 1);
    $last->handle($evaluator);
    $last->handle($evaluator);
    Bus::assertDispatchedTimes(RankNhlSatEngineCandidatesJob::class, 1);
    expect($run->fresh()->status)->toBe('ranking')->and($run->fresh()->predictions_completed)->toBe(3);
});

it('fills independent ranking lanes while skipping completed splits', function (): void {
    $run = app(NhlSatEngineEvaluator::class)->start([...$this->input, 'kind' => 'discovery'], $this->engine);
    $definition = $run->definition;
    $definition['automatic_search']['stage_split_count'] = 33;
    $run->update(['definition' => $definition, 'status' => 'ranking']);
    $candidate = (array) DB::table('nhl_sat_engine_candidates')->where('run_id', $run->id)->first();
    unset($candidate['id']);
    foreach (range(1, 32) as $split) {
        DB::table('nhl_sat_engine_candidates')->insert([...$candidate, 'split_index' => $split]);
    }
    DB::table('nhl_sat_engine_candidates')->where('run_id', $run->id)->whereIn('split_index', [0, 16])
        ->update(['metrics' => '{}']);
    Bus::fake();
    app(NhlSatEngineEvaluator::class)->dispatchRankingJobs($run);
    Bus::assertDispatchedTimes(RankNhlSatEngineCandidatesJob::class, 16);
    foreach ([32, ...range(1, 15)] as $split) {
        Bus::assertDispatched(RankNhlSatEngineCandidatesJob::class, fn ($job) => $job->splitIndex === $split);
    }
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

it('stacks a distinct weight pair using the foundation confidence and gap settings', function (): void {
    $run = ($this->createRun)();
    $run->update(['definition' => [...$run->definition, 'desired_win_pct' => 60, 'min_coverage_pct' => 30]]);
    $foundation = DB::table('nhl_sat_engine_candidates')->where('run_id', $run->id)->first();
    DB::table('nhl_sat_engine_candidates')->where('id', $foundation->id)->update([
        'settings' => json_encode([...$this->settings, 'offense' => 75, 'defense' => 0]),
        'metrics' => '{}', 'win_pct' => 100, 'coverage_pct' => 33.3333,
    ]);
    $supplement = DB::table('nhl_sat_engine_candidates')->insertGetId([
        'run_id' => $run->id, 'split_index' => 1,
        'settings' => json_encode([...$this->settings, 'offense' => 50, 'defense' => 0]),
        'metrics' => '{}', 'win_pct' => 100, 'coverage_pct' => 33.3333, 'meets_targets' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('nhl_sat_engine_results')->insert([
        ($this->result)($run, 2025020001, ['split_index' => 0, 'correct' => true]),
        ($this->result)($run, 2025020002, ['split_index' => 1, 'correct' => true]),
    ]);

    $stack = collect(app(NhlSatEngineStackAnalyzer::class)->analyze($run,
        DB::table('nhl_sat_engine_candidates')->where('run_id', $run->id)->get()))
        ->first(fn (array $row): bool => $row['ids'] === [(int) $foundation->id, $supplement]);

    expect($stack)->not->toBeNull()->and($stack['coverage_pct'])->toBe(100.0)->and($stack['win_pct'])->toBe(100.0);
});

it('accepts a coverage-expanding supplement even when it also qualifies foundation picks', function (): void {
    $run = ($this->createRun)();
    $run->update(['definition' => [...$run->definition, 'min_coverage_pct' => 30]]);
    $foundation = DB::table('nhl_sat_engine_candidates')->where('run_id', $run->id)->first();
    DB::table('nhl_sat_engine_candidates')->where('id', $foundation->id)->update([
        'settings' => json_encode([...$this->settings, 'confidence_min' => 70, 'confidence_max' => 70, 'gap' => 0.5]),
        'metrics' => '{}', 'win_pct' => 100, 'coverage_pct' => 33.3333,
    ]);
    $supplement = DB::table('nhl_sat_engine_candidates')->insertGetId([
        'run_id' => $run->id, 'split_index' => 1,
        'settings' => json_encode([...$this->settings, 'confidence_min' => 67, 'confidence_max' => 70, 'gap' => 0]),
        'metrics' => '{}', 'win_pct' => 80, 'coverage_pct' => 66.6667, 'meets_targets' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('nhl_sat_engine_results')->insert([
        ($this->result)($run, 2025020001, ['split_index' => 0, 'confidence' => 70, 'correct' => true]),
        ($this->result)($run, 2025020001, ['split_index' => 1, 'confidence' => 70, 'correct' => true]),
        ($this->result)($run, 2025020002, ['split_index' => 1, 'confidence' => 68, 'correct' => true]),
    ]);

    $stacks = app(NhlSatEngineStackAnalyzer::class)->analyze($run,
        DB::table('nhl_sat_engine_candidates')->where('run_id', $run->id)->get());
    expect($stacks[0]['ids'])->toBe([(int) $foundation->id, $supplement]);
});

it('stops an automatic stack before a break-even coverage expansion', function (): void {
    $run = ($this->createRun)();
    $run->update(['definition' => [...$run->definition, 'desired_win_pct' => 50, 'min_coverage_pct' => 30]]);
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
        ($this->result)($run, 2025020002, ['split_index' => 1, 'correct' => true]),
        ($this->result)($run, 2025020003, ['split_index' => 1, 'correct' => false]),
    ]);

    $rows = DB::table('nhl_sat_engine_candidates')->where('run_id', $run->id)->get();
    $stack = collect(app(NhlSatEngineStackAnalyzer::class)->analyze($run, $rows))
        ->first(fn (array $row): bool => $row['ids'] === [(int) $foundation->id]);
    expect($stack)->not->toBeNull()->and($stack['coverage_pct'])->toBeLessThan(100.0);
});

it('does not use discovery targets to admit a break-even supplement', function (): void {
    $run = ($this->createRun)();
    $run->update(['definition' => [...$run->definition, 'desired_win_pct' => 60, 'min_coverage_pct' => 30]]);
    $foundation = DB::table('nhl_sat_engine_candidates')->where('run_id', $run->id)->first();
    DB::table('nhl_sat_engine_candidates')->where('id', $foundation->id)->update([
        'settings' => json_encode([...$this->settings, 'confidence_min' => 70, 'confidence_max' => 70, 'gap' => 0.5]),
        'metrics' => '{}', 'win_pct' => 100, 'coverage_pct' => 33.3333,
    ]);
    $supplement = DB::table('nhl_sat_engine_candidates')->insertGetId([
        'run_id' => $run->id, 'split_index' => 1,
        'settings' => json_encode([...$this->settings, 'confidence_min' => 67, 'confidence_max' => 69, 'gap' => 0]),
        'metrics' => '{}', 'win_pct' => 50, 'coverage_pct' => 66.6667, 'meets_targets' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('nhl_sat_engine_results')->insert([
        ($this->result)($run, 2025020001, ['split_index' => 0, 'confidence' => 70, 'correct' => true]),
        ($this->result)($run, 2025020002, ['split_index' => 1, 'confidence' => 68, 'correct' => true]),
        ($this->result)($run, 2025020003, ['split_index' => 1, 'confidence' => 68, 'correct' => false]),
    ]);

    $stacks = app(NhlSatEngineStackAnalyzer::class)->analyze($run,
        DB::table('nhl_sat_engine_candidates')->where('run_id', $run->id)->get());
    expect(collect($stacks)->contains(fn (array $stack): bool => $stack['ids'] === [(int) $foundation->id, $supplement]))->toBeFalse();
});

it('keeps automatic foundations independent of discovery targets', function (): void {
    $run = ($this->createRun)();
    $run->update(['definition' => [...$run->definition, 'desired_win_pct' => 70, 'min_coverage_pct' => 30]]);
    $foundation = DB::table('nhl_sat_engine_candidates')->where('run_id', $run->id)->first();
    DB::table('nhl_sat_engine_candidates')->where('id', $foundation->id)->update([
        'settings' => json_encode([...$this->settings, 'confidence_min' => 70, 'confidence_max' => 70, 'gap' => 0.5]),
        'metrics' => '{}', 'win_pct' => 66.6667, 'coverage_pct' => 33.3333,
    ]);
    DB::table('nhl_sat_engine_candidates')->insert([
        'run_id' => $run->id, 'split_index' => 1,
        'settings' => json_encode([...$this->settings, 'confidence_min' => 69, 'confidence_max' => 69, 'gap' => 0]),
        'metrics' => '{}', 'win_pct' => 100, 'coverage_pct' => 20, 'meets_targets' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('nhl_sat_engine_results')->insert([
        ($this->result)($run, 2025020001, ['split_index' => 0, 'confidence' => 70, 'correct' => true]),
        ($this->result)($run, 2025020002, ['split_index' => 0, 'confidence' => 70, 'correct' => false]),
        ($this->result)($run, 2025020003, ['split_index' => 0, 'confidence' => 69, 'correct' => true]),
        ($this->result)($run, 2025020003, ['split_index' => 1, 'confidence' => 69, 'correct' => true]),
    ]);

    expect(app(NhlSatEngineStackAnalyzer::class)->analyze($run,
        DB::table('nhl_sat_engine_candidates')->where('run_id', $run->id)->get()))->not->toBe([]);
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

it('uses the highest-win candidate as the automatic foundation regardless of coverage', function (): void {
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
    expect($selected['id'])->toBe($higherWinBelowTarget);
});

it('prefers the strongest non-zero offense and defense combination among tied highest-win foundations', function (): void {
    $run = ($this->createRun)();
    $zeroDefense = DB::table('nhl_sat_engine_candidates')->where('run_id', $run->id)->first();
    DB::table('nhl_sat_engine_candidates')->where('id', $zeroDefense->id)->update([
        'settings' => json_encode([...$this->settings, 'offense' => 200, 'defense' => 0]), 'metrics' => '{}', 'win_pct' => 100, 'coverage_pct' => 3.8,
    ]);
    $balanced = DB::table('nhl_sat_engine_candidates')->insertGetId([
        'run_id' => $run->id, 'split_index' => 1, 'settings' => json_encode([...$this->settings, 'offense' => 150, 'defense' => 100]), 'metrics' => '{}',
        'win_pct' => 100, 'coverage_pct' => 3.8, 'meets_targets' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $lowerCombined = DB::table('nhl_sat_engine_candidates')->insertGetId([
        'run_id' => $run->id, 'split_index' => 2, 'settings' => json_encode([...$this->settings, 'offense' => 125, 'defense' => 100]), 'metrics' => '{}',
        'win_pct' => 100, 'coverage_pct' => 3.8, 'meets_targets' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $rows = DB::table('nhl_sat_engine_candidates')->whereIn('id', [$zeroDefense->id, $balanced, $lowerCombined])->get();

    expect(app(NhlSatEngineStackAnalyzer::class)->foundation($run, $rows)['id'])->toBe($balanced);
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
    expect($run->definition['splits'])->toHaveCount(1)
        ->and($run->definition['splits'][0]['offense'])->toBe(100);
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

it('discovers separate home and away candidates for one selected team', function (): void {
    $run = app(NhlSatEngineEvaluator::class)->start([...$this->input, 'kind' => 'discovery',
        'scope' => [...$this->scope, 'teams' => ['TOR']]], $this->engine);
    DB::table('nhl_sat_engine_results')->insert([
        ($this->result)($run, 2025020001, ['game' => json_encode(['away' => 'TOR', 'home' => 'MTL'])]),
        ($this->result)($run, 2025020003, ['game' => json_encode(['away' => 'MTL', 'home' => 'TOR'])]),
    ]);

    $rows = app(NhlSatEngineEvaluator::class)->discoverQualifications($run->id, 0, $this->settings, 2);

    expect(collect($rows)->pluck('settings.venue')->sort()->values()->all())->toBe(['away', 'home'])
        ->and(collect($rows)->pluck('settings.team_abbrev')->unique()->all())->toBe(['TOR'])
        ->and(collect($rows)->pluck('metrics.eligible')->all())->toBe([1, 1]);
});

it('advances a bounded lane to its next split only after the last game', function (int $lanes): void {
    $run = app(NhlSatEngineEvaluator::class)->start([...$this->input, 'kind' => 'discovery',
        'scope' => [...$this->scope, 'mode' => 'games', 'count' => 1]], $this->engine);
    // A persisted legacy discovery retains its original lane scheduling.
    $run->update(['prediction_count' => 81, 'definition' => [...$run->definition,
        'automatic_search' => ['strategy' => 'coarse_to_fine_v1', 'stage' => 0,
            'stage_first_split' => 0, 'stage_split_count' => 81, 'lanes' => $lanes]]]);
    Bus::fake();
    $evaluator = Mockery::mock(NhlSatEngineEvaluator::class);
    $evaluator->shouldReceive('assertModelUnchanged')->once();
    $evaluator->shouldReceive('predict')->once()->andReturn(($this->result)($run, 2025020001));
    $job = new EvaluateNhlSatEngineGameJob($run->id, 0, 0);
    $job->handle($evaluator);
    $job->handle($evaluator);
    Bus::assertDispatchedTimes(EvaluateNhlSatEngineGameJob::class, 1);
    Bus::assertDispatched(EvaluateNhlSatEngineGameJob::class, fn ($next) => $next->splitIndex === $lanes && $next->gameIndex === 0);
    expect($run->fresh()->predictions_completed)->toBe(1);
})->with([4, 16]);

it('dispatches sixteen lanes when discovery opens its unrestricted weight search', function (): void {
    $run = app(NhlSatEngineEvaluator::class)->start([...$this->input, 'kind' => 'discovery'], $this->engine);
    $run->update(['status' => 'ranking', 'predictions_completed' => 3]);
    foreach ($run->definition['game_ids'] as $id) {
        DB::table('nhl_sat_engine_results')->insert(($this->result)($run, $id, ['confidence' => 75]));
    }
    Bus::fake();
    (new RankNhlSatEngineCandidatesJob($run->id, 0, 1))->handle(app(NhlSatEngineEvaluator::class));

    expect($run->fresh()->definition['automatic_search']['lanes'])->toBe(16);
    Bus::assertDispatchedTimes(EvaluateNhlSatEngineGameJob::class, 16);
    foreach (range(0, 15) as $offset) {
        Bus::assertDispatched(EvaluateNhlSatEngineGameJob::class, fn ($job) =>
            $job->splitIndex === 1 + intdiv($offset, 3) && $job->gameIndex === $offset % 3 && $job->queue === 'projections');
    }
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

it('persists a named recommendation as ordered engines without dispatching work', function (): void {
    $run = ($this->createRun)();
    $run->update(['kind' => 'discovery', 'status' => 'complete']);
    $candidate = DB::table('nhl_sat_engine_candidates')->where('run_id', $run->id)->first();
    DB::table('nhl_sat_engine_candidates')->where('id', $candidate->id)->update([
        'metrics' => '{}', 'win_pct' => 75.5, 'coverage_pct' => 42.25,
    ]);

    $this->actingAs($this->admin)->post('/admin/nhl-sat-engines/runs/' . $run->id . '/stacks', [
        'name' => 'Coverage stack', 'candidate_ids' => [$candidate->id],
    ])->assertRedirect('/admin/nhl-sat-engines?tab=stacks&created_stack=1');

    $stack = NhlSatEngineStack::query()->with('members.engine')->firstOrFail();
    expect($stack->name)->toBe('Coverage stack')->and($stack->members)->toHaveCount(1)
        ->and($stack->members->first()->priority)->toBe(1)
        ->and($stack->members->first()->engine->name)->toBe('Coverage stack — Foundation')
        ->and($stack->members->first()->engine->discovery_run_id)->toBe($run->id)
        ->and($stack->members->first()->engine->discovery_candidate_id)->toBe($candidate->id)
        ->and((float) $stack->members->first()->engine->discovery_win_pct)->toBe(75.5)
        ->and((float) $stack->members->first()->engine->discovery_coverage_pct)->toBe(42.25);
    Bus::assertNothingDispatched();
});

it('rejects incomplete or foreign candidates when creating a stack', function (): void {
    $run = ($this->createRun)();
    $other = ($this->createRun)();
    $run->update(['status' => 'complete']);
    $candidate = DB::table('nhl_sat_engine_candidates')->where('run_id', $other->id)->first();

    $this->actingAs($this->admin)->postJson('/admin/nhl-sat-engines/runs/' . $run->id . '/stacks', [
        'name' => 'Invalid', 'candidate_ids' => [$candidate->id],
    ])->assertUnprocessable()->assertJsonValidationErrors('candidate_ids');
    expect(NhlSatEngineStack::query()->count())->toBe(0);
});

it('manages saved stack membership without deleting the underlying engine', function (): void {
    $stack = NhlSatEngineStack::query()->create(['name' => 'Saved']);
    $other = (new NhlSatEngine())->saveDefinition([...$this->definition, 'name' => 'Additional']);
    $this->actingAs($this->admin)->post('/admin/nhl-sat-engines/stacks/' . $stack->id . '/members', ['engine_id' => $this->engine->id])->assertRedirect();
    $this->actingAs($this->admin)->post('/admin/nhl-sat-engines/stacks/' . $stack->id . '/members', ['engine_id' => $other->id])->assertRedirect();
    foreach (range(1, 4) as $index) {
        $engine = (new NhlSatEngine())->saveDefinition([...$this->definition, 'name' => 'Additional ' . $index]);
        $this->actingAs($this->admin)->post('/admin/nhl-sat-engines/stacks/' . $stack->id . '/members', ['engine_id' => $engine->id])->assertRedirect();
    }
    $members = $stack->fresh()->members;
    expect($members)->toHaveCount(6);
    $this->actingAs($this->admin)->put('/admin/nhl-sat-engines/stacks/' . $stack->id . '/members/order', ['member_ids' => $members->pluck('id')->reverse()->values()->all()])->assertRedirect();
    $member = $stack->fresh()->members()->firstOrFail();
    $this->actingAs($this->admin)->delete('/admin/nhl-sat-engines/stacks/' . $stack->id . '/members/' . $member->id)->assertRedirect();
    expect(NhlSatEngine::query()->find($member->engine_id))->not->toBeNull();
});

it('renders a stack index separately from the dedicated stack detail page', function (): void {
    $stack = NhlSatEngineStack::query()->create(['name' => 'Saved']);
    $stack->members()->create(['engine_id' => $this->engine->id, 'priority' => 1]);

    $this->actingAs($this->admin)->get('/admin/nhl-sat-engines?tab=stacks')->assertInertia(fn (Assert $page) => $page
        ->component('Admin/SatEngines/Index')->where('stacks.data.0.name', 'Saved')->where('stacks.data.0.members_count', 1));
    $this->get('/admin/nhl-sat-engines/stacks/' . $stack->id)->assertInertia(fn (Assert $page) => $page
        ->component('Admin/SatEngines/Stack')->where('stack.name', 'Saved')->where('stack.members.0.engine.id', $this->engine->id));
});

it('includes each engine stack membership in the engines index payload', function (): void {
    $stack = NhlSatEngineStack::query()->create(['name' => 'High confidence']);
    $stack->members()->create(['engine_id' => $this->engine->id, 'priority' => 1]);

    $this->actingAs($this->admin)->get('/admin/nhl-sat-engines')->assertInertia(fn (Assert $page) => $page
        ->where('engines.data.0.id', $this->engine->id)
        ->where('engines.data.0.stack_members.0.stack.name', 'High confidence'));
});

it('prevents deletion of an engine that remains in a saved stack', function (): void {
    $stack = NhlSatEngineStack::query()->create(['name' => 'Saved']);
    $stack->members()->create(['engine_id' => $this->engine->id, 'priority' => 1]);

    $this->actingAs($this->admin)->deleteJson('/admin/nhl-sat-engines/' . $this->engine->id)
        ->assertUnprocessable()->assertJsonValidationErrors('engine');
});
