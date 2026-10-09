<?php

declare(strict_types=1);

use App\Jobs\PrepareNhlPregameContextRunJob;
use App\Models\NhlModelRun;
use App\Models\NhlPregameContextRun;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-09 12:00:00', 'UTC'));
    Bus::fake();
    config(['cache.default' => 'array']);
    $this->model = NhlModelRun::query()->create([
        'run_key' => 'pregame-test',
        'name' => 'Pregame Test',
        'model_family' => NhlModelRun::FAMILY_SAT,
        'workflow_stage' => NhlModelRun::STAGE_TRAINING,
        'model_version' => 'test',
        'train_season_ids' => ['20242025', '20232024', '20242025'],
        'target_season_id' => '20252026',
        'status' => 'complete',
    ]);
    $this->admin = User::factory()->create();
    $role = Role::firstOrCreate(['slug' => 'super-admin'], [
        'name' => 'Super Admin', 'level' => 99, 'scope' => 'global', 'is_active' => true,
    ]);
    $this->admin->roles()->attach($role->id, ['organization_id' => null]);
});

it('queues model seasons and exposes progress without changing the model', function (): void {
    $before = $this->model->getRawOriginal();
    $response = $this->actingAs($this->admin)->postJson(route('admin.nhl-sat-models.context.build', $this->model), [
        'season_ids' => ['19901991'],
    ])->assertAccepted();
    $build = NhlPregameContextRun::query()->sole();
    expect($build->season_ids)->toBe(['20232024', '20242025', '20252026'])
        ->and($build->options['model_run_id'])->toBe($this->model->id)
        ->and($build->action)->toBe('backfill')
        ->and($build->created_by)->toBe($this->admin->id)
        ->and($this->model->fresh()->getRawOriginal())->toBe($before)
        ->and($response->json('progress_html'))->toContain('Building', 'Queued');
    Bus::assertDispatchedTimes(PrepareNhlPregameContextRunJob::class, 1);
    Bus::assertDispatched(PrepareNhlPregameContextRunJob::class, fn ($job): bool => $job->runId === $build->id && $job->queue === 'projections');
    $this->getJson(route('admin.nhl-sat-models.context.progress', $this->model))
        ->assertOk()->assertJsonPath('progress_html', $response->json('progress_html'));
    $this->get(route('admin.nhl-sat-models.index'))->assertOk()
        ->assertSee('Build Pregame')->assertSee('Analysis')->assertSee('Building');
});

it('supports models with Train seasons and no Test season', function (): void {
    $this->model->update(['target_season_id' => null]);
    $this->actingAs($this->admin)->postJson(route('admin.nhl-sat-models.context.build', $this->model))->assertAccepted();
    expect(NhlPregameContextRun::query()->sole()->season_ids)->toBe(['20232024', '20242025']);
});

it('blocks overlapping seasons even when the overlap is not the first season', function (): void {
    NhlPregameContextRun::query()->create([
        'action' => 'backfill', 'status' => 'running', 'season_ids' => ['20252026'],
    ]);
    $this->actingAs($this->admin)->postJson(route('admin.nhl-sat-models.context.build', $this->model))->assertUnprocessable();
    Bus::assertNothingDispatched();
    expect(NhlPregameContextRun::query()->count())->toBe(1);
});

it('does not dispatch twice for a double click', function (): void {
    $this->actingAs($this->admin)->postJson(route('admin.nhl-sat-models.context.build', $this->model))->assertAccepted();
    $this->postJson(route('admin.nhl-sat-models.context.build', $this->model))->assertUnprocessable();
    Bus::assertDispatchedTimes(PrepareNhlPregameContextRunJob::class, 1);
});

it('rejects a competing admission without creating work', function (): void {
    $lock = Cache::lock('nhl-pregame-context-admission', 30);
    $lock->get();
    try {
        $this->actingAs($this->admin)->postJson(route('admin.nhl-sat-models.context.build', $this->model))->assertUnprocessable();
        Bus::assertNothingDispatched();
        expect(NhlPregameContextRun::query()->count())->toBe(0);
    } finally {
        $lock->release();
    }
});

it('allows rebuilding after a terminal build and selects the newest progress', function (string $status): void {
    $this->actingAs($this->admin)->postJson(route('admin.nhl-sat-models.context.build', $this->model))->assertAccepted();
    $build = NhlPregameContextRun::query()->sole();
    $build->update(['status' => $status, 'completed_games' => 8, 'total_games' => 10, 'blocked_games' => 2, 'last_error' => 'Source unavailable']);
    $html = $this->getJson(route('admin.nhl-sat-models.context.progress', $this->model))->assertOk()->json('progress_html');
    expect($html)->toContain('8 / 10 games', '2 excluded', 'data-pregame-active="0"', $status === 'failed' ? 'Pregame failed' : 'Pregame completed');
    $this->postJson(route('admin.nhl-sat-models.context.build', $this->model))->assertAccepted();
    expect(NhlPregameContextRun::latestForModel($this->model->id)->id)->not->toBe($build->id);
})->with(['completed', 'failed']);

it('rejects a model without seasons', function (): void {
    $this->model->update(['train_season_ids' => [], 'target_season_id' => null]);
    $this->actingAs($this->admin)->postJson(route('admin.nhl-sat-models.context.build', $this->model))->assertUnprocessable();
    Bus::assertNothingDispatched();
});

it('rejects non SAT models', function (string $method, string $route): void {
    $this->model->update(['model_family' => 'other']);
    $this->actingAs($this->admin)->{$method}(route($route, $this->model))->assertNotFound();
    Bus::assertNothingDispatched();
})->with([
    ['postJson', 'admin.nhl-sat-models.context.build'],
    ['getJson', 'admin.nhl-sat-models.context.progress'],
]);

it('denies dashboard actions to guests', function (string $method, string $route): void {
    $this->{$method}(route($route, $this->model))->assertUnauthorized();
    Bus::assertNothingDispatched();
})->with([
    ['postJson', 'admin.nhl-sat-models.context.build'],
    ['getJson', 'admin.nhl-sat-models.context.progress'],
]);

it('denies dashboard actions to non super admins', function (string $method, string $route): void {
    $this->actingAs(User::factory()->create())->{$method}(route($route, $this->model))->assertForbidden();
    Bus::assertNothingDispatched();
})->with([
    ['postJson', 'admin.nhl-sat-models.context.build'],
    ['getJson', 'admin.nhl-sat-models.context.progress'],
]);
