<?php

declare(strict_types=1);

use App\Jobs\PrepareNhlPregameContextRunJob;
use App\Jobs\LoadNhlPregameContextRunJob;
use App\Models\NhlPregameContextRunGame;
use App\Models\NhlModelRun;
use App\Models\NhlPregameContextRun;
use App\Models\NhlPregameContextRunDay;
use App\Models\Role;
use App\Models\User;
use App\Services\NhlPregameContextOrchestrator;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-09 12:00:00', 'UTC'));
    Bus::fake();
    config(['cache.default' => 'array']);
    config(['cache.stores.redis.driver' => 'array']);
    Cache::store('redis')->flush();
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
        ->and($build->options['pipeline_version'])->toBe(NhlPregameContextOrchestrator::CHECKPOINT_PIPELINE)
        ->and($build->action)->toBe('backfill')
        ->and($build->created_by)->toBe($this->admin->id)
        ->and($this->model->fresh()->getRawOriginal())->toBe($before)
        ->and($response->json('progress_html'))->toContain('Building', 'Queued');
    Bus::assertDispatchedTimes(PrepareNhlPregameContextRunJob::class, 1);
    Bus::assertDispatched(PrepareNhlPregameContextRunJob::class, fn ($job): bool => $job->runId === $build->id && $job->queue === 'projections');
    $this->getJson(route('admin.nhl-sat-models.context.progress', $this->model))
        ->assertOk()->assertJsonPath('progress_html', $response->json('progress_html'));
    $this->get(route('admin.nhl-sat-models.index'))->assertOk()
        ->assertSee('Build Pregame')->assertSee('Analysis')->assertSee('Building')
        ->assertSee('View Pregame Impacts')->assertSee(route('admin.nhl-sat-models.context.effects'));
});

it('materializes one run-day row for each selected game date', function (): void {
    foreach ([2026020001 => '2025-10-08', 2026020002 => '2025-10-09', 2026020003 => '2025-10-09'] as $gameId => $date) {
        \Illuminate\Support\Facades\DB::table('nhl_games')->insert([
            'nhl_game_id' => $gameId,
            'season_id' => '20252026',
            'game_type' => 2,
            'game_date' => $date,
            'game_dow' => 'Wed',
            'game_month' => 'Oct',
            'game_state' => 'FINAL',
            'start_time_utc' => $date . ' 23:00:00',
            'home_team_id' => 1,
            'away_team_id' => 2,
        ]);
    }

    $build = app(NhlPregameContextOrchestrator::class)->queueModelBackfill($this->model);
    app(NhlPregameContextOrchestrator::class)->prepare($build->fresh());

    expect(NhlPregameContextRunDay::query()->where('run_id', $build->id)->count())->toBe(2)
        ->and(NhlPregameContextRunDay::query()->where('run_id', $build->id)->orderBy('game_date')->pluck('game_count')->all())->toBe([1, 2]);
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
    ['postJson', 'admin.nhl-sat-models.context.pause'],
    ['postJson', 'admin.nhl-sat-models.context.resume'],
    ['postJson', 'admin.nhl-sat-models.context.stop'],
]);

it('denies dashboard actions to guests', function (string $method, string $route): void {
    $this->{$method}(route($route, $this->model))->assertUnauthorized();
    Bus::assertNothingDispatched();
})->with([
    ['postJson', 'admin.nhl-sat-models.context.build'],
    ['getJson', 'admin.nhl-sat-models.context.progress'],
    ['postJson', 'admin.nhl-sat-models.context.pause'],
    ['postJson', 'admin.nhl-sat-models.context.resume'],
    ['postJson', 'admin.nhl-sat-models.context.stop'],
    ['getJson', 'admin.nhl-sat-models.context.effects'],
]);

it('denies dashboard actions to non super admins', function (string $method, string $route): void {
    $this->actingAs(User::factory()->create())->{$method}(route($route, $this->model))->assertForbidden();
    Bus::assertNothingDispatched();
})->with([
    ['postJson', 'admin.nhl-sat-models.context.build'],
    ['getJson', 'admin.nhl-sat-models.context.progress'],
    ['postJson', 'admin.nhl-sat-models.context.pause'],
    ['postJson', 'admin.nhl-sat-models.context.resume'],
    ['postJson', 'admin.nhl-sat-models.context.stop'],
    ['getJson', 'admin.nhl-sat-models.context.effects'],
]);

it('combines all seasons by default and respects an explicit season', function (?string $season, int $samples, float $average): void {
    $player = \App\Models\Player::create([
        'nhl_id' => 8470001, 'first_name' => 'Test', 'last_name' => 'Skater',
        'full_name' => 'Test Skater', 'position' => 'C', 'pos_type' => 'F',
    ]);
    foreach (['20242025', '20252026'] as $index => $seasonId) {
        $gameId = 2024020001 + $index * 1000000;
        $date = ((int) substr($seasonId, 0, 4)) . '-10-10';
        \Illuminate\Support\Facades\DB::table('nhl_games')->insert([
            'nhl_game_id' => $gameId, 'season_id' => $seasonId, 'game_type' => 2,
            'game_date' => $date, 'game_dow' => 'Fri', 'game_month' => 'Oct',
        ]);
        \Illuminate\Support\Facades\DB::table('nhl_player_game_pregame_contexts')->insert([
            'nhl_game_id' => $gameId, 'nhl_player_id' => 8470001,
            'nhl_team_id' => 1, 'opponent_team_id' => 2, 'game_date' => $date,
            'source_cutoff_at' => $date . ' 19:00:00', 'venue' => 'home',
            'participant_source' => 'boxscore', 'metrics' => json_encode([
                'all' => ['last_10' => ['sat_per_60' => 30], 'season_to_date' => ['sat_per_60' => 10]],
                'strength' => ['EV' => ['last_10' => ['sat_per_60' => 6], 'season_to_date' => ['sat_per_60' => 6]]],
            ], JSON_THROW_ON_ERROR),
            'context_version' => \App\Services\NhlPregameContextBuilder::VERSION,
        ]);
        \Illuminate\Support\Facades\DB::table('nhl_player_game_strength_summaries')->insert([
            'nhl_game_id' => $gameId, 'player_id' => $player->id,
            'nhl_player_id' => 8470001, 'strength' => 'EV', 'toi' => 600,
            'satf' => 10 * ($index + 1),
        ]);
    }
    $params = $season === null ? [] : ['season_id' => $season];
    $this->actingAs($this->admin)->get(route('admin.nhl-sat-models.context.effects', $params))
        ->assertOk()->assertViewHas('selectedSeasonId', $season ?? 'all')
        ->assertViewHas('baseline', fn (array $baseline): bool => $baseline['sample_size'] === $samples && $baseline['actual_average'] === $average)
        ->assertSee('All')->assertSee('season_id=' . ($season ?? 'all'));
    $this->get(route('admin.nhl-sat-models.context.effects', [...$params, 'factor' => 'sat_trend']))
        ->assertOk()->assertViewHas('rows', fn ($rows): bool => $rows->count() === 1 && $rows->first()['group'] === 'Within 0.5');
    Bus::assertNothingDispatched();
})->with([
    'default All' => [null, 2, 90.0],
    'explicit All' => ['all', 2, 90.0],
    'single season' => ['20242025', 1, 60.0],
]);

it('shows an empty All selection and rejects unknown seasons', function (): void {
    $this->actingAs($this->admin)->get(route('admin.nhl-sat-models.context.effects'))
        ->assertOk()->assertViewHas('selectedSeasonId', 'all')
        ->assertViewHas('baseline', ['sample_size' => 0, 'actual_average' => null]);
    $this->getJson(route('admin.nhl-sat-models.context.effects', ['season_id' => 'invalid']))->assertUnprocessable();
});

it('removes the standalone context routes without removing model builds', function (): void {
    expect(\Illuminate\Support\Facades\Route::has('admin.nhl-sat-models.context'))->toBeFalse()
        ->and(\Illuminate\Support\Facades\Route::has('admin.nhl-sat-models.context.store'))->toBeFalse()
        ->and(\Illuminate\Support\Facades\Route::has('admin.nhl-sat-models.context.build'))->toBeTrue();
    $this->actingAs($this->admin)->get('/admin/nhl-sat-models/context')->assertNotFound();
    $this->postJson('/admin/nhl-sat-models/context')->assertNotFound();
});

it('enables pregame inspection only for completed model builds with saved evidence', function (string $status, bool $saveData, bool $currentVersion, bool $enabled): void {
    $this->actingAs($this->admin)->postJson(route('admin.nhl-sat-models.context.build', $this->model))->assertAccepted();
    $build = NhlPregameContextRun::query()->sole();
    $build->update(['status' => $status]);
    if ($saveData) {
        \Illuminate\Support\Facades\DB::table('nhl_player_game_pregame_contexts')->insert([
            'run_id' => $build->id, 'nhl_game_id' => 2025020001,
            'nhl_player_id' => 8470001, 'nhl_team_id' => 1, 'opponent_team_id' => 2,
            'game_date' => '2025-10-10', 'source_cutoff_at' => '2025-10-10 19:00:00',
            'venue' => 'home', 'participant_source' => 'boxscore', 'metrics' => '{}',
            'context_version' => $currentVersion ? \App\Services\NhlPregameContextBuilder::VERSION : 'old-version',
        ]);
    }
    expect($build->canViewImpacts())->toBe($enabled);
    $html = $this->getJson(route('admin.nhl-sat-models.context.progress', $this->model))
        ->assertOk()->json('progress_html');
    expect($html)->toContain('data-pregame-viewable="' . ($enabled ? '1' : '0') . '"');
    $page = $this->get(route('admin.nhl-sat-models.index'))->assertOk()->getContent();
    expect($page)->toContain('data-pregame-viewable="' . ($enabled ? '1' : '0') . '"');
})->with([
    'completed with current data' => ['completed', true, true, true],
    'completed without data' => ['completed', false, true, false],
    'completed with stale data' => ['completed', true, false, false],
    'queued' => ['queued', true, true, false],
    'running' => ['running', true, true, false],
    'failed' => ['failed', true, true, false],
]);

it('does not enable a model menu from unrelated or manual builds', function (): void {
    NhlPregameContextRun::query()->create([
        'action' => 'backfill', 'status' => 'completed',
        'season_ids' => ['20252026'], 'options' => ['model_run_id' => $this->model->id + 1],
    ]);
    NhlPregameContextRun::query()->create([
        'action' => 'backfill', 'status' => 'completed', 'season_ids' => ['20252026'],
    ]);
    expect(NhlPregameContextRun::latestForModel($this->model->id))->toBeNull();
    $html = $this->actingAs($this->admin)->getJson(route('admin.nhl-sat-models.context.progress', $this->model))
        ->assertOk()->json('progress_html');
    expect($html)->toContain('data-pregame-viewable="0"');
});

it('pauses a queued build immediately and exposes resume controls', function (): void {
    $this->actingAs($this->admin)->postJson(route('admin.nhl-sat-models.context.build', $this->model))->assertAccepted();

    $response = $this->postJson(route('admin.nhl-sat-models.context.pause', $this->model))->assertOk();

    expect(NhlPregameContextRun::latestForModel($this->model->id)->status)->toBe(NhlPregameContextRun::STATUS_PAUSED)
        ->and($response->json('progress_html'))->toContain('Pregame paused', 'Resume', 'Stop');
});

it('moves a running build to pausing and requests a state refresh', function (): void {
    $run = NhlPregameContextRun::query()->create([
        'model_run_id' => $this->model->id, 'action' => 'backfill', 'status' => 'running',
        'season_ids' => ['20252026'], 'total_games' => 1,
    ]);

    $response = $this->actingAs($this->admin)->postJson(route('admin.nhl-sat-models.context.pause', $this->model))->assertOk();

    expect($run->fresh()->status)->toBe(NhlPregameContextRun::STATUS_PAUSING)
        ->and($response->json('progress_html'))->toContain('Pausing after claimed games');
    Bus::assertDispatched(LoadNhlPregameContextRunJob::class, fn ($job): bool => $job->runId === $run->id);
});

it('pauses after all claimed game jobs have finished', function (): void {
    $run = NhlPregameContextRun::query()->create([
        'model_run_id' => $this->model->id, 'action' => 'backfill', 'status' => 'pausing',
        'season_ids' => ['20252026'], 'total_games' => 1,
    ]);
    NhlPregameContextRunGame::query()->create([
        'run_id' => $run->id, 'nhl_game_id' => 2025020001, 'game_date' => '2025-10-10',
        'sequence' => 1, 'state' => 'complete',
    ]);

    app(\App\Services\NhlPregameContextOrchestrator::class)->load($run->id);

    expect($run->fresh()->status)->toBe(NhlPregameContextRun::STATUS_PAUSED);
    Bus::assertNothingDispatched();
});

it('keeps a pause pending while a claimed game is processing', function (): void {
    $run = NhlPregameContextRun::query()->create([
        'model_run_id' => $this->model->id, 'action' => 'backfill', 'status' => 'pausing',
        'season_ids' => ['20252026'], 'total_games' => 1,
    ]);
    NhlPregameContextRunGame::query()->create([
        'run_id' => $run->id, 'nhl_game_id' => 2025020001, 'game_date' => '2025-10-10',
        'sequence' => 1, 'state' => 'processing',
    ]);

    app(\App\Services\NhlPregameContextOrchestrator::class)->load($run->id);

    expect($run->fresh()->status)->toBe(NhlPregameContextRun::STATUS_PAUSING);
    Bus::assertNothingDispatched();
});

it('resumes a paused run with durable game rows using the loader', function (): void {
    $run = NhlPregameContextRun::query()->create([
        'model_run_id' => $this->model->id, 'action' => 'backfill', 'status' => 'paused',
        'season_ids' => ['20252026'], 'total_games' => 3,
    ]);

    $this->actingAs($this->admin)->postJson(route('admin.nhl-sat-models.context.resume', $this->model))->assertOk();

    expect($run->fresh()->status)->toBe(NhlPregameContextRun::STATUS_RUNNING);
    Bus::assertDispatched(LoadNhlPregameContextRunJob::class, fn ($job): bool => $job->runId === $run->id);
});

it('requeues preparation when resuming before a queued build is prepared', function (): void {
    $run = NhlPregameContextRun::query()->create([
        'model_run_id' => $this->model->id, 'action' => 'backfill', 'status' => 'paused',
        'season_ids' => ['20252026'], 'total_games' => 0,
    ]);

    $this->actingAs($this->admin)->postJson(route('admin.nhl-sat-models.context.resume', $this->model))->assertOk();

    expect($run->fresh()->status)->toBe(NhlPregameContextRun::STATUS_QUEUED);
    Bus::assertDispatched(PrepareNhlPregameContextRunJob::class, fn ($job): bool => $job->runId === $run->id);
});

it('stops active work, marks unfinished game rows, and retains completed rows', function (): void {
    $run = NhlPregameContextRun::query()->create([
        'model_run_id' => $this->model->id, 'action' => 'backfill', 'status' => 'running',
        'season_ids' => ['20252026'], 'total_games' => 2,
    ]);
    $complete = NhlPregameContextRunGame::query()->create([
        'run_id' => $run->id, 'nhl_game_id' => 2025020001, 'game_date' => '2025-10-10',
        'sequence' => 1, 'state' => 'complete',
    ]);
    $processing = NhlPregameContextRunGame::query()->create([
        'run_id' => $run->id, 'nhl_game_id' => 2025020002, 'game_date' => '2025-10-11',
        'sequence' => 2, 'state' => 'processing',
    ]);

    $response = $this->actingAs($this->admin)->postJson(route('admin.nhl-sat-models.context.stop', $this->model))->assertOk();

    expect($run->fresh()->status)->toBe(NhlPregameContextRun::STATUS_STOPPED)
        ->and($complete->fresh()->state)->toBe(NhlPregameContextRunGame::STATE_COMPLETE)
        ->and($processing->fresh()->state)->toBe(NhlPregameContextRunGame::STATE_STOPPED)
        ->and($response->json('progress_html'))->toContain('Pregame stopped');
    expect(Cache::store('redis')->get(NhlPregameContextRun::stopSignalKey($run->id)))->toBeTrue();
});

it('starts a fresh build after the previous run was stopped', function (): void {
    $stopped = NhlPregameContextRun::query()->create([
        'model_run_id' => $this->model->id, 'action' => 'backfill', 'status' => 'stopped',
        'season_ids' => ['20252026'],
    ]);

    $this->actingAs($this->admin)->postJson(route('admin.nhl-sat-models.context.build', $this->model))->assertAccepted();

    expect(NhlPregameContextRun::query()->count())->toBe(2)
        ->and(NhlPregameContextRun::latestForModel($this->model->id)->id)->not->toBe($stopped->id);
});

it('does not allow a fresh build while the prior run is paused', function (): void {
    NhlPregameContextRun::query()->create([
        'model_run_id' => $this->model->id, 'action' => 'backfill', 'status' => 'paused',
        'season_ids' => ['20252026'],
    ]);

    $this->actingAs($this->admin)->postJson(route('admin.nhl-sat-models.context.build', $this->model))->assertUnprocessable();

    expect(NhlPregameContextRun::query()->count())->toBe(1);
    Bus::assertNothingDispatched();
});

it('rejects pause, resume, and stop when their run transition is invalid', function (string $action, string $status): void {
    NhlPregameContextRun::query()->create([
        'model_run_id' => $this->model->id, 'action' => 'backfill', 'status' => $status,
        'season_ids' => ['20252026'],
    ]);

    $this->actingAs($this->admin)->postJson(route('admin.nhl-sat-models.context.' . $action, $this->model))->assertUnprocessable();
})->with([
    ['pause', 'completed'], ['resume', 'running'], ['stop', 'completed'],
]);

it('denies run controls to guests', function (string $action): void {
    $this->postJson(route('admin.nhl-sat-models.context.' . $action, $this->model))->assertUnauthorized();
})->with(['pause', 'resume', 'stop']);

it('denies run controls to non super admins', function (string $action): void {
    $this->actingAs(User::factory()->create())
        ->postJson(route('admin.nhl-sat-models.context.' . $action, $this->model))->assertForbidden();
})->with(['pause', 'resume', 'stop']);


it('dispatches league days before context exists and respects independent lane limits', function (): void {
    config(['nhl-pregame.context_concurrency' => 1, 'nhl-pregame.league_concurrency' => 2]);
    $run = NhlPregameContextRun::create(['model_run_id' => $this->model->id, 'action' => 'backfill',
        'status' => 'running', 'season_ids' => ['20252026'],
        'options' => ['pipeline_version' => NhlPregameContextOrchestrator::RESULTS_PIPELINE]]);
    foreach (['2025-10-08', '2025-10-09', '2025-10-10'] as $date) {
        NhlPregameContextRunDay::create(['run_id' => $run->id, 'game_date' => $date, 'game_count' => 1]);
    }
    app(NhlPregameContextOrchestrator::class)->load($run->id);
    Bus::assertNotDispatched(\App\Jobs\BuildNhlPregameContextDayJob::class);
    Bus::assertDispatchedTimes(\App\Jobs\CalculateNhlPregameContextRunDayLeagueAveragesJob::class, 2);
    Bus::assertNotDispatched(\App\Jobs\CompareNhlPregameContextRunDayJob::class);
    expect($run->days()->where('league_state', 'processing')->count())->toBe(2);
});

it('claims context only for dates whose league averages are complete', function (): void {
    $run = NhlPregameContextRun::create(['model_run_id' => $this->model->id, 'action' => 'backfill',
        'status' => 'running', 'season_ids' => ['20252026'],
        'options' => ['pipeline_version' => NhlPregameContextOrchestrator::RESULTS_PIPELINE]]);
    NhlPregameContextRunDay::create(['run_id' => $run->id, 'game_date' => '2025-10-10',
        'context_state' => 'pending', 'league_state' => 'processing', 'game_count' => 1]);
    $ready = NhlPregameContextRunDay::create(['run_id' => $run->id, 'game_date' => '2025-10-11',
        'context_state' => 'pending', 'league_state' => 'complete', 'game_count' => 1]);
    app(NhlPregameContextOrchestrator::class)->load($run->id);
    Bus::assertDispatchedTimes(\App\Jobs\BuildNhlPregameContextDayJob::class, 1);
    Bus::assertDispatched(\App\Jobs\BuildNhlPregameContextDayJob::class,
        fn ($job): bool => $job->runDayId === $ready->id && $job->queue === 'projections');
    Bus::assertNotDispatched(\App\Jobs\CompareNhlPregameContextRunDayJob::class);
});

it('waits for claimed comparison work before pausing', function (): void {
    $run = NhlPregameContextRun::create(['model_run_id' => $this->model->id, 'action' => 'backfill',
        'status' => 'pausing', 'season_ids' => ['20252026'],
        'options' => ['pipeline_version' => NhlPregameContextOrchestrator::THREE_STAGE_PIPELINE]]);
    $day = NhlPregameContextRunDay::create(['run_id' => $run->id, 'game_date' => '2025-10-10',
        'context_state' => 'complete', 'league_state' => 'complete', 'comparison_state' => 'processing']);
    app(NhlPregameContextOrchestrator::class)->load($run->id);
    expect($run->fresh()->status)->toBe('pausing');
    Bus::assertNothingDispatched();
    $day->update(['comparison_state' => 'complete']);
    app(NhlPregameContextOrchestrator::class)->load($run->id);
    expect($run->fresh()->status)->toBe('paused');
});

it('upgrades a paused batched run on resume without rebuilding completed context', function (string $pipeline): void {
    $run = NhlPregameContextRun::create(['model_run_id' => $this->model->id, 'action' => 'backfill',
        'status' => 'paused', 'season_ids' => ['20252026'], 'total_games' => 1, 'completed_games' => 1,
        'options' => ['pipeline_version' => $pipeline]]);
    $game = NhlPregameContextRunGame::create([
        'run_id' => $run->id, 'nhl_game_id' => 2025020001, 'game_date' => '2025-10-10',
        'sequence' => 1, 'state' => 'complete',
    ]);
    $day = NhlPregameContextRunDay::create(['run_id' => $run->id, 'game_date' => '2025-10-10',
        'context_state' => 'complete', 'league_state' => 'complete', 'comparison_state' => 'complete', 'completed_games' => 1, 'game_count' => 1]);
    $this->actingAs($this->admin)->postJson(route('admin.nhl-sat-models.context.resume', $this->model))->assertOk();
    expect($run->fresh()->options['pipeline_version'])->toBe(NhlPregameContextOrchestrator::CHECKPOINT_PIPELINE)
        ->and($run->fresh()->completed_games)->toBe(1)
        ->and($game->fresh()->state)->toBe('complete')
        ->and($day->fresh()->context_state)->toBe('complete')
        ->and($day->fresh()->league_state)->toBe('complete')
        ->and($day->fresh()->comparison_state)->toBe('complete');
    Bus::assertNotDispatched(PrepareNhlPregameContextRunJob::class);
    $html = $this->getJson(route('admin.nhl-sat-models.context.progress', $this->model))->assertOk()->json('progress_html');
    expect($html)->toContain('League averages: 1/1 days', 'Context, rankings &amp; deltas: 1/1');
})->with(['game_day_batch_v1', NhlPregameContextOrchestrator::THREE_STAGE_PIPELINE, NhlPregameContextOrchestrator::RESULTS_PIPELINE]);

it('rejects a paused-run upgrade while claimed old work is still processing', function (): void {
    $run = NhlPregameContextRun::create(['model_run_id' => $this->model->id, 'action' => 'backfill',
        'status' => 'paused', 'season_ids' => ['20252026'], 'total_games' => 1,
        'options' => ['pipeline_version' => 'game_day_batch_v1']]);
    NhlPregameContextRunDay::create(['run_id' => $run->id, 'game_date' => '2025-10-10', 'context_state' => 'processing']);
    $this->actingAs($this->admin)->postJson(route('admin.nhl-sat-models.context.resume', $this->model))->assertUnprocessable();
    expect($run->fresh()->status)->toBe('paused')
        ->and($run->fresh()->options['pipeline_version'])->toBe('game_day_batch_v1');
    Bus::assertNothingDispatched();
});

it('stops unfinished comparison work with the rest of the run', function (): void {
    $run = NhlPregameContextRun::create(['model_run_id' => $this->model->id, 'action' => 'backfill',
        'status' => 'running', 'season_ids' => ['20252026'],
        'options' => ['pipeline_version' => NhlPregameContextOrchestrator::RESULTS_PIPELINE]]);
    $day = NhlPregameContextRunDay::create(['run_id' => $run->id, 'game_date' => '2025-10-10',
        'context_state' => 'complete', 'league_state' => 'complete', 'comparison_state' => 'processing']);
    $this->actingAs($this->admin)->postJson(route('admin.nhl-sat-models.context.stop', $this->model))->assertOk();
    expect($day->fresh()->context_state)->toBe('complete')->and($day->fresh()->comparison_state)->toBe('stopped');
    Bus::assertNothingDispatched();
});

it('completes a league-first run when its integrated context finishes', function (): void {
    $run = NhlPregameContextRun::create(['model_run_id' => $this->model->id, 'action' => 'backfill',
        'status' => 'running', 'season_ids' => ['20252026'],
        'options' => ['pipeline_version' => NhlPregameContextOrchestrator::RESULTS_PIPELINE]]);
    $day = NhlPregameContextRunDay::create(['run_id' => $run->id, 'game_date' => '2025-10-10',
        'context_state' => 'processing', 'league_state' => 'complete', 'comparison_state' => 'pending']);
    $orchestrator = app(NhlPregameContextOrchestrator::class);
    $orchestrator->load($run->id);
    expect($run->fresh()->status)->toBe('running');
    $day->update(['context_state' => 'complete']);
    $orchestrator->load($run->id);
    expect($run->fresh()->status)->toBe('completed')->and($run->fresh()->stageProgress())->toBe([
        'total' => 1, 'context' => 1, 'league' => 1,
    ]);
});

it('attaches comparisons within the context job before marking the date complete', function (): void {
    $run = NhlPregameContextRun::create(['model_run_id' => $this->model->id, 'action' => 'backfill',
        'status' => 'running', 'season_ids' => ['20252026'],
        'options' => ['pipeline_version' => NhlPregameContextOrchestrator::RESULTS_PIPELINE]]);
    $day = NhlPregameContextRunDay::create(['run_id' => $run->id, 'game_date' => '2025-10-10',
        'context_state' => 'processing', 'league_state' => 'complete']);
    $game = NhlPregameContextRunGame::create([
        'run_id' => $run->id, 'nhl_game_id' => 2025020001, 'game_date' => '2025-10-10',
        'sequence' => 1, 'state' => 'processing',
    ]);
    $built = false;
    $builder = Mockery::mock(\App\Services\NhlPregameContextBuilder::class);
    $builder->shouldReceive('buildDay')->once()->withArgs(fn ($actualRun, $ids, $stop): bool =>
        $actualRun->id === $run->id && $ids === [2025020001] && ! $stop())
        ->andReturnUsing(function () use (&$built): array {
            $built = true;
            return ['games' => [2025020001 => ['team_context_count' => 2, 'player_context_count' => 40]], 'blocked_games' => 0];
        });
    $results = Mockery::mock(\App\Services\NhlPregameLeagueResults::class);
    $results->shouldReceive('attachComparisons')->once()->withArgs(function ($actualRun, $actualDay, $stop) use ($run, $day, &$built): bool {
        expect($built)->toBeTrue();
        expect($actualDay->fresh()->context_state)->toBe('processing');
        return $actualRun->id === $run->id && $actualDay->id === $day->id && ! $stop();
    });
    $this->app->instance(\App\Services\NhlPregameLeagueResults::class, $results);
    (new \App\Jobs\BuildNhlPregameContextDayJob($run->id, $day->id))->handle($builder);
    expect($day->fresh()->context_state)->toBe('complete')
        ->and($day->fresh()->completed_games)->toBe(1)
        ->and($game->fresh()->state)->toBe('complete')
        ->and($game->fresh()->player_context_count)->toBe(40);
    Bus::assertDispatched(LoadNhlPregameContextRunJob::class);
    Bus::assertNotDispatched(\App\Jobs\CompareNhlPregameContextRunDayJob::class);
});

it('refuses to build context before its league averages are available', function (): void {
    $run = NhlPregameContextRun::create(['model_run_id' => $this->model->id, 'action' => 'backfill',
        'status' => 'running', 'season_ids' => ['20252026'],
        'options' => ['pipeline_version' => NhlPregameContextOrchestrator::RESULTS_PIPELINE]]);
    $day = NhlPregameContextRunDay::create(['run_id' => $run->id, 'game_date' => '2025-10-10',
        'context_state' => 'processing', 'league_state' => 'pending']);
    $builder = Mockery::mock(\App\Services\NhlPregameContextBuilder::class);
    $builder->shouldNotReceive('buildDay');
    (new \App\Jobs\BuildNhlPregameContextDayJob($run->id, $day->id))->handle($builder);
    expect($day->fresh()->context_state)->toBe('processing')->and($day->fresh()->context_completed_at)->toBeNull();
    Bus::assertNothingDispatched();
});

it('fails integrated context when comparison calculation fails', function (): void {
    $run = NhlPregameContextRun::create(['model_run_id' => $this->model->id, 'action' => 'backfill',
        'status' => 'running', 'season_ids' => ['20252026'],
        'options' => ['pipeline_version' => NhlPregameContextOrchestrator::RESULTS_PIPELINE]]);
    $day = NhlPregameContextRunDay::create(['run_id' => $run->id, 'game_date' => '2025-10-10',
        'context_state' => 'processing', 'league_state' => 'complete']);
    $builder = Mockery::mock(\App\Services\NhlPregameContextBuilder::class);
    $results = Mockery::mock(\App\Services\NhlPregameLeagueResults::class);
    $results->shouldReceive('attachComparisons')->once()->andThrow(new RuntimeException('Comparison failed'));
    $this->app->instance(\App\Services\NhlPregameLeagueResults::class, $results);
    expect(fn () => (new \App\Jobs\BuildNhlPregameContextDayJob($run->id, $day->id))->handle($builder))
        ->toThrow(RuntimeException::class, 'Comparison failed');
    expect($day->fresh()->context_state)->toBe('failed')->and($run->fresh()->status)->toBe('failed');
    Bus::assertNothingDispatched();
});

it('refreshes comparisons for retained completed games without rebuilding snapshots', function (): void {
    $run = NhlPregameContextRun::create(['model_run_id' => $this->model->id, 'action' => 'backfill',
        'status' => 'running', 'season_ids' => ['20252026'],
        'options' => ['pipeline_version' => NhlPregameContextOrchestrator::RESULTS_PIPELINE]]);
    $day = NhlPregameContextRunDay::create(['run_id' => $run->id, 'game_date' => '2025-10-10',
        'context_state' => 'processing', 'league_state' => 'complete']);
    $game = NhlPregameContextRunGame::create(['run_id' => $run->id, 'nhl_game_id' => 2025020001,
        'game_date' => '2025-10-10', 'sequence' => 1, 'state' => 'complete', 'player_context_count' => 40]);
    $builder = Mockery::mock(\App\Services\NhlPregameContextBuilder::class);
    $builder->shouldNotReceive('buildDay');
    $results = Mockery::mock(\App\Services\NhlPregameLeagueResults::class);
    $results->shouldReceive('attachComparisons')->once();
    $this->app->instance(\App\Services\NhlPregameLeagueResults::class, $results);
    (new \App\Jobs\BuildNhlPregameContextDayJob($run->id, $day->id))->handle($builder);
    expect($day->fresh()->context_state)->toBe('complete')->and($day->fresh()->completed_games)->toBe(1)
        ->and($game->fresh()->player_context_count)->toBe(40);
});

it('fails instead of reporting completion when game work has no day rows', function (): void {
    $run = NhlPregameContextRun::create(['model_run_id' => $this->model->id, 'action' => 'backfill',
        'status' => 'running', 'season_ids' => ['20252026'], 'total_games' => 5,
        'options' => ['pipeline_version' => NhlPregameContextOrchestrator::RESULTS_PIPELINE]]);
    foreach (range(1, 5) as $sequence) {
        NhlPregameContextRunGame::create(['run_id' => $run->id, 'nhl_game_id' => 2025020000 + $sequence,
            'game_date' => '2025-10-10', 'sequence' => $sequence,
            'state' => $sequence <= 4 ? 'complete' : 'pending']);
    }
    app(NhlPregameContextOrchestrator::class)->load($run->id);
    expect($run->fresh()->status)->toBe('failed')->and($run->fresh()->completed_games)->toBe(4)
        ->and($run->fresh()->last_error)->toContain('game-day work is missing', 'Reload all projections workers');
    Bus::assertNothingDispatched();
});

it('rejects incomplete date coverage even when some day rows exist', function (): void {
    $run = NhlPregameContextRun::create(['model_run_id' => $this->model->id, 'action' => 'backfill',
        'status' => 'running', 'season_ids' => ['20252026'], 'total_games' => 1,
        'options' => ['pipeline_version' => NhlPregameContextOrchestrator::RESULTS_PIPELINE]]);
    NhlPregameContextRunDay::create(['run_id' => $run->id, 'game_date' => '2025-10-09',
        'context_state' => 'complete', 'league_state' => 'complete']);
    NhlPregameContextRunGame::create(['run_id' => $run->id, 'nhl_game_id' => 2025020001,
        'game_date' => '2025-10-10', 'sequence' => 1, 'state' => 'pending']);
    app(NhlPregameContextOrchestrator::class)->load($run->id);
    expect($run->fresh()->status)->toBe('failed')->and($run->fresh()->last_error)->toContain('game-day work is missing');
    Bus::assertNothingDispatched();
});

it('requires terminal game outcomes as well as completed day stages', function (): void {
    $run = NhlPregameContextRun::create(['model_run_id' => $this->model->id, 'action' => 'backfill',
        'status' => 'running', 'season_ids' => ['20252026'], 'total_games' => 1,
        'options' => ['pipeline_version' => NhlPregameContextOrchestrator::RESULTS_PIPELINE]]);
    NhlPregameContextRunDay::create(['run_id' => $run->id, 'game_date' => '2025-10-10',
        'context_state' => 'complete', 'league_state' => 'complete']);
    NhlPregameContextRunGame::create(['run_id' => $run->id, 'nhl_game_id' => 2025020001,
        'game_date' => '2025-10-10', 'sequence' => 1, 'state' => 'pending']);
    app(NhlPregameContextOrchestrator::class)->load($run->id);
    expect($run->fresh()->status)->toBe('failed')->and($run->fresh()->last_error)->toContain('does not match');
    Bus::assertNothingDispatched();
});


it('dispatches checkpointed league batches before context without claiming all games', function (): void {
    $run = NhlPregameContextRun::create(['model_run_id' => $this->model->id, 'action' => 'backfill',
        'status' => 'running', 'season_ids' => ['20252026'], 'total_games' => 1,
        'options' => ['pipeline_version' => NhlPregameContextOrchestrator::CHECKPOINT_PIPELINE]]);
    $day = NhlPregameContextRunDay::create(['run_id' => $run->id, 'game_date' => '2025-10-10']);
    $game = NhlPregameContextRunGame::create(['run_id' => $run->id, 'nhl_game_id' => 2025020001,
        'game_date' => '2025-10-10', 'sequence' => 1, 'state' => 'pending']);
    app(NhlPregameContextOrchestrator::class)->load($run->id);
    Bus::assertDispatched(\App\Jobs\CalculateNhlPregameLeagueBatchJob::class);
    Bus::assertNotDispatched(\App\Jobs\BuildNhlPregameContextDayBatchJob::class);
    expect($game->fresh()->state)->toBe('pending');
    $day->update(['league_state' => 'complete']);
    app(NhlPregameContextOrchestrator::class)->load($run->id);
    Bus::assertDispatched(\App\Jobs\BuildNhlPregameContextDayBatchJob::class);
    expect($game->fresh()->state)->toBe('pending');
});

it('checkpoints one game at a time and completes the day through continuations', function (): void {
    $run = NhlPregameContextRun::create(['model_run_id' => $this->model->id, 'action' => 'backfill',
        'status' => 'running', 'season_ids' => ['20252026'], 'total_games' => 2,
        'options' => ['pipeline_version' => NhlPregameContextOrchestrator::CHECKPOINT_PIPELINE]]);
    $day = NhlPregameContextRunDay::create(['run_id' => $run->id, 'game_date' => '2025-10-10',
        'league_state' => 'complete', 'context_state' => 'processing']);
    foreach ([1, 2] as $sequence) {
        NhlPregameContextRunGame::create(['run_id' => $run->id, 'nhl_game_id' => 2025020000 + $sequence,
            'game_date' => '2025-10-10', 'sequence' => $sequence, 'state' => 'pending']);
    }
    $builder = Mockery::mock(\App\Services\NhlPregameContextBuilder::class);
    $builder->shouldReceive('buildDay')->twice()->andReturnUsing(fn ($run, $ids): array => [
        'games' => [$ids[0] => ['team_context_count' => 2, 'player_context_count' => 40]], 'blocked_games' => 0]);
    $service = Mockery::mock(\App\Services\NhlPregameLeagueResults::class);
    $service->shouldReceive('attachGameComparisons')->twice()->withArgs(function ($actualRun, $actualDay, $gameId) use ($run): bool {
        expect($run->games()->where('nhl_game_id', $gameId)->first()->snapshot_completed_at)->not->toBeNull();
        return $actualRun->id === $run->id;
    });
    $service->shouldReceive('releaseDayPeers')->once();
    $this->app->instance(\App\Services\NhlPregameLeagueResults::class, $service);
    $job = new \App\Jobs\BuildNhlPregameContextDayBatchJob($run->id, $day->id);
    $job->handle($builder);
    expect($day->fresh()->context_state)->toBe('pending')->and($run->games()->where('state', 'complete')->count())->toBe(1)
        ->and($run->games()->where('state', 'pending')->count())->toBe(1);
    app(NhlPregameContextOrchestrator::class)->load($run->id);
    (new \App\Jobs\BuildNhlPregameContextDayBatchJob($run->id, $day->id, $day->fresh()->context_claim_token))->handle($builder);
    expect($day->fresh()->context_state)->toBe('complete')->and($run->fresh()->completed_games)->toBe(2)
        ->and($run->games()->whereNotNull('comparisons_completed_at')->count())->toBe(2);
});

it('retains a committed snapshot when comparisons fail and retries only unfinished work', function (): void {
    $run = NhlPregameContextRun::create(['model_run_id' => $this->model->id, 'action' => 'backfill',
        'status' => 'running', 'season_ids' => ['20252026'], 'total_games' => 1,
        'options' => ['pipeline_version' => NhlPregameContextOrchestrator::CHECKPOINT_PIPELINE]]);
    $day = NhlPregameContextRunDay::create(['run_id' => $run->id, 'game_date' => '2025-10-10',
        'league_state' => 'complete', 'context_state' => 'processing']);
    $game = NhlPregameContextRunGame::create(['run_id' => $run->id, 'nhl_game_id' => 2025020001,
        'game_date' => '2025-10-10', 'sequence' => 1, 'state' => 'pending']);
    $builder = Mockery::mock(\App\Services\NhlPregameContextBuilder::class);
    $builder->shouldReceive('buildDay')->once()->andReturn(['games' => [2025020001 => ['team_context_count' => 2]], 'blocked_games' => 0]);
    $service = Mockery::mock(\App\Services\NhlPregameLeagueResults::class);
    $service->shouldReceive('attachGameComparisons')->once()->andThrow(new RuntimeException('Comparison failure'));
    $this->app->instance(\App\Services\NhlPregameLeagueResults::class, $service);
    $job = new \App\Jobs\BuildNhlPregameContextDayBatchJob($run->id, $day->id);
    expect(fn () => $job->handle($builder))->toThrow(RuntimeException::class);
    expect($game->fresh()->snapshot_completed_at)->not->toBeNull()->and($game->fresh()->comparisons_completed_at)->toBeNull()
        ->and($day->fresh()->failed_games)->toBe(1);
    $this->actingAs($this->admin)->postJson(route('admin.nhl-sat-models.context.resume', $this->model))->assertOk();
    app(NhlPregameContextOrchestrator::class)->load($run->id);
    $retryService = Mockery::mock(\App\Services\NhlPregameLeagueResults::class);
    $retryService->shouldReceive('attachGameComparisons')->once();
    $retryService->shouldReceive('releaseDayPeers')->once();
    $this->app->instance(\App\Services\NhlPregameLeagueResults::class, $retryService);
    (new \App\Jobs\BuildNhlPregameContextDayBatchJob($run->id, $day->id, $day->fresh()->context_claim_token))->handle($builder);
    expect($game->fresh()->state)->toBe('complete')->and($run->fresh()->failed_games)->toBe(0);
});

it('marks only the active game failed after a checkpointed timeout', function (): void {
    $run = NhlPregameContextRun::create(['model_run_id' => $this->model->id, 'action' => 'backfill',
        'status' => 'running', 'season_ids' => ['20252026'], 'total_games' => 3,
        'options' => ['pipeline_version' => NhlPregameContextOrchestrator::CHECKPOINT_PIPELINE]]);
    $day = NhlPregameContextRunDay::create(['run_id' => $run->id, 'game_date' => '2025-10-10',
        'league_state' => 'complete', 'context_state' => 'processing', 'game_count' => 3]);
    foreach (['complete', 'processing', 'pending'] as $index => $state) {
        NhlPregameContextRunGame::create(['run_id' => $run->id, 'nhl_game_id' => 2025020001 + $index,
            'game_date' => '2025-10-10', 'sequence' => $index + 1, 'state' => $state]);
    }
    (new \App\Jobs\BuildNhlPregameContextDayBatchJob($run->id, $day->id))->failed(new RuntimeException('Timed out'));
    expect($run->fresh()->failed_games)->toBe(1)->and($day->fresh()->failed_games)->toBe(1)
        ->and($run->games()->orderBy('sequence')->pluck('state')->all())->toBe(['complete', 'failed', 'pending']);
});

it('keeps continuation checkpoints when a batch finishes during pause', function (): void {
    $run = NhlPregameContextRun::create(['model_run_id' => $this->model->id, 'action' => 'backfill',
        'status' => 'pausing', 'season_ids' => ['20252026'], 'total_games' => 1,
        'options' => ['pipeline_version' => NhlPregameContextOrchestrator::CHECKPOINT_PIPELINE]]);
    $day = NhlPregameContextRunDay::create(['run_id' => $run->id, 'game_date' => '2025-10-10',
        'league_state' => 'processing', 'league_cursor' => 1]);
    $service = Mockery::mock(\App\Services\NhlPregameLeagueResults::class);
    $service->shouldReceive('buildNextAverages')->once()->andReturnFalse();
    $builder = Mockery::mock(\App\Services\NhlPregameContextBuilder::class);
    (new \App\Jobs\CalculateNhlPregameLeagueBatchJob($run->id, $day->id))->handle($builder, $service);
    app(NhlPregameContextOrchestrator::class)->load($run->id);
    expect($run->fresh()->status)->toBe('paused')->and($day->fresh()->league_cursor)->toBe(1)
        ->and($day->fresh()->league_state)->toBe('pending');
});

it('bounds prepared league dates while continuing partially committed populations', function (): void {
    config(['nhl-pregame.league_concurrency' => 1, 'nhl-pregame.league_lookahead_days' => 1]);
    $run = NhlPregameContextRun::create(['model_run_id' => $this->model->id, 'action' => 'backfill',
        'status' => 'running', 'season_ids' => ['20252026'],
        'options' => ['pipeline_version' => NhlPregameContextOrchestrator::CHECKPOINT_PIPELINE]]);
    $partial = NhlPregameContextRunDay::create(['run_id' => $run->id, 'game_date' => '2025-10-10', 'league_cursor' => 1]);
    $later = NhlPregameContextRunDay::create(['run_id' => $run->id, 'game_date' => '2025-10-11']);
    app(NhlPregameContextOrchestrator::class)->load($run->id);
    Bus::assertDispatched(\App\Jobs\CalculateNhlPregameLeagueBatchJob::class, fn ($job): bool => $job->runDayId === $partial->id);
    expect($later->fresh()->league_state)->toBe('pending');
});

it('shows retry and separate timing totals for failed checkpointed builds', function (): void {
    $run = NhlPregameContextRun::create(['model_run_id' => $this->model->id, 'action' => 'backfill',
        'status' => 'failed', 'season_ids' => ['20252026'],
        'options' => ['pipeline_version' => NhlPregameContextOrchestrator::CHECKPOINT_PIPELINE]]);
    NhlPregameContextRunDay::create(['run_id' => $run->id, 'game_date' => '2025-10-10',
        'league_seconds' => 12, 'context_seconds' => 20, 'comparison_seconds' => 3]);
    $html = $this->actingAs($this->admin)->getJson(route('admin.nhl-sat-models.context.progress', $this->model))->assertOk()->json('progress_html');
    expect($html)->toContain('Retry unfinished', 'League 12s', 'Context 20s', 'Comparisons 3s');
});

it('offers Resume during Pausing and cancels the pause without replacing live claims', function (): void {
    $run = NhlPregameContextRun::create(['model_run_id' => $this->model->id, 'action' => 'backfill',
        'status' => 'pausing', 'season_ids' => ['20252026'], 'total_games' => 1,
        'options' => ['pipeline_version' => NhlPregameContextOrchestrator::CHECKPOINT_PIPELINE]]);
    $token = '11111111-1111-4111-8111-111111111111';
    $day = NhlPregameContextRunDay::create(['run_id' => $run->id, 'game_date' => '2025-10-10',
        'league_state' => 'processing', 'league_cursor' => 1, 'league_entity_cursor' => 101,
        'league_claim_token' => $token, 'league_claim_expires_at' => now()->addMinutes(5)]);
    $this->actingAs($this->admin)->getJson(route('admin.nhl-sat-models.context.progress', $this->model))
        ->assertOk()->assertSee('Resume');
    $this->postJson(route('admin.nhl-sat-models.context.resume', $this->model))->assertOk();
    expect($run->fresh()->status)->toBe('running')->and($day->fresh()->league_claim_token)->toBe($token)
        ->and($day->fresh()->league_state)->toBe('processing')->and($day->fresh()->league_entity_cursor)->toBe(101);
    $this->getJson(route('admin.nhl-sat-models.context.progress', $this->model))->assertOk()
        ->assertJsonPath('progress_html', fn (string $html): bool => str_contains($html, 'Building') && ! str_contains($html, '>Pausing<'));
    Bus::assertDispatched(LoadNhlPregameContextRunJob::class);
});

it('recovers an expired league claim on Resume without discarding league pages or completed dates', function (): void {
    $run = NhlPregameContextRun::create(['model_run_id' => $this->model->id, 'action' => 'backfill',
        'status' => 'pausing', 'season_ids' => ['20252026'], 'total_games' => 2,
        'options' => ['pipeline_version' => NhlPregameContextOrchestrator::CHECKPOINT_PIPELINE]]);
    $completed = NhlPregameContextRunDay::create(['run_id' => $run->id, 'game_date' => '2025-10-09',
        'context_state' => 'complete', 'league_state' => 'complete', 'league_cursor' => 4, 'league_seconds' => 100]);
    $expired = NhlPregameContextRunDay::create(['run_id' => $run->id, 'game_date' => '2025-10-10',
        'league_state' => 'processing', 'league_cursor' => 3, 'league_entity_cursor' => 108,
        'league_claim_token' => '11111111-1111-4111-8111-111111111111', 'league_claim_expires_at' => now()->subSecond()]);
    $this->actingAs($this->admin)->postJson(route('admin.nhl-sat-models.context.resume', $this->model))->assertOk();
    expect($expired->fresh()->league_state)->toBe('pending')->and($expired->fresh()->league_cursor)->toBe(3)
        ->and($expired->fresh()->league_entity_cursor)->toBe(108)
        ->and($completed->fresh()->context_state)->toBe('complete')->and($completed->fresh()->league_cursor)->toBe(4)
        ->and($completed->fresh()->league_seconds)->toBe(100.0);
});

it('settles Pausing after reclaiming an abandoned delivery from before leases existed', function (): void {
    $run = NhlPregameContextRun::create(['model_run_id' => $this->model->id, 'action' => 'backfill',
        'status' => 'pausing', 'season_ids' => ['20252026'],
        'options' => ['pipeline_version' => NhlPregameContextOrchestrator::CHECKPOINT_PIPELINE]]);
    $day = NhlPregameContextRunDay::create(['run_id' => $run->id, 'game_date' => '2025-10-10', 'context_state' => 'processing']);
    DB::table('nhl_pregame_context_run_days')->where('id', $day->id)->update(['updated_at' => now()->subHour()]);
    app(NhlPregameContextOrchestrator::class)->load($run->id);
    expect($run->fresh()->status)->toBe('paused')->and($day->fresh()->context_state)->toBe('pending')
        ->and(app(NhlPregameContextOrchestrator::class)->ownsDayClaim($run->id, $day->id, 'context', null))->toBeFalse();
});

it('does not reclaim a live legacy delivery before its timeout and grace period', function (): void {
    $run = NhlPregameContextRun::create(['model_run_id' => $this->model->id, 'action' => 'backfill',
        'status' => 'pausing', 'season_ids' => ['20252026'],
        'options' => ['pipeline_version' => NhlPregameContextOrchestrator::CHECKPOINT_PIPELINE]]);
    $day = NhlPregameContextRunDay::create(['run_id' => $run->id, 'game_date' => '2025-10-10', 'league_state' => 'processing']);
    app(NhlPregameContextOrchestrator::class)->load($run->id);
    expect($run->fresh()->status)->toBe('pausing')->and($day->fresh()->league_state)->toBe('processing');
});

it('ignores stale context deliveries and their late failure callbacks', function (): void {
    $run = NhlPregameContextRun::create(['model_run_id' => $this->model->id, 'action' => 'backfill',
        'status' => 'running', 'season_ids' => ['20252026'],
        'options' => ['pipeline_version' => NhlPregameContextOrchestrator::CHECKPOINT_PIPELINE]]);
    $day = NhlPregameContextRunDay::create(['run_id' => $run->id, 'game_date' => '2025-10-10',
        'league_state' => 'complete', 'context_state' => 'processing',
        'context_claim_token' => '22222222-2222-4222-8222-222222222222', 'context_claim_expires_at' => now()->addMinutes(5)]);
    $builder = Mockery::mock(\App\Services\NhlPregameContextBuilder::class);
    $builder->shouldNotReceive('buildDay');
    $job = new \App\Jobs\BuildNhlPregameContextDayBatchJob($run->id, $day->id, '11111111-1111-4111-8111-111111111111');
    $job->handle($builder);
    $job->failed(new \RuntimeException('Late timeout'));
    expect($run->fresh()->status)->toBe('running')->and($day->fresh()->context_state)->toBe('processing');
});

it('ignores stale league deliveries and their late failure callbacks', function (): void {
    $run = NhlPregameContextRun::create(['model_run_id' => $this->model->id, 'action' => 'backfill',
        'status' => 'running', 'season_ids' => ['20252026'],
        'options' => ['pipeline_version' => NhlPregameContextOrchestrator::CHECKPOINT_PIPELINE]]);
    $day = NhlPregameContextRunDay::create(['run_id' => $run->id, 'game_date' => '2025-10-10', 'league_state' => 'processing',
        'league_claim_token' => '22222222-2222-4222-8222-222222222222', 'league_claim_expires_at' => now()->addMinutes(5)]);
    $builder = Mockery::mock(\App\Services\NhlPregameContextBuilder::class);
    $service = Mockery::mock(\App\Services\NhlPregameLeagueResults::class);
    $service->shouldNotReceive('buildNextAverages');
    $job = new \App\Jobs\CalculateNhlPregameLeagueBatchJob($run->id, $day->id);
    $job->handle($builder, $service);
    $job->failed(new \RuntimeException('Late timeout'));
    expect($run->fresh()->status)->toBe('running')->and($day->fresh()->league_state)->toBe('processing');
});

it('keeps only one delayed recovery watchdog while active work is draining', function (): void {
    $run = NhlPregameContextRun::create(['model_run_id' => $this->model->id, 'action' => 'backfill',
        'status' => 'pausing', 'season_ids' => ['20252026'],
        'options' => ['pipeline_version' => NhlPregameContextOrchestrator::CHECKPOINT_PIPELINE]]);
    NhlPregameContextRunDay::create(['run_id' => $run->id, 'game_date' => '2025-10-10', 'league_state' => 'processing']);
    app(NhlPregameContextOrchestrator::class)->load($run->id);
    app(NhlPregameContextOrchestrator::class)->load($run->id);
    Bus::assertDispatchedTimes(LoadNhlPregameContextRunJob::class, 1);
    Bus::assertDispatched(LoadNhlPregameContextRunJob::class, fn ($job): bool => $job->runId === $run->id && $job->delay !== null);
});

it('ignores queued legacy day jobs and failures after a checkpointed upgrade', function (string $class): void {
    $run = NhlPregameContextRun::create(['model_run_id' => $this->model->id, 'action' => 'backfill',
        'status' => 'running', 'season_ids' => ['20252026'],
        'options' => ['pipeline_version' => NhlPregameContextOrchestrator::CHECKPOINT_PIPELINE]]);
    $day = NhlPregameContextRunDay::create(['run_id' => $run->id, 'game_date' => '2025-10-10',
        'context_state' => 'processing', 'league_state' => 'processing', 'comparison_state' => 'processing']);
    $builder = Mockery::mock(\App\Services\NhlPregameContextBuilder::class);
    $service = Mockery::mock(\App\Services\NhlPregameLeagueResults::class);
    $job = new $class($run->id, $day->id);
    if ($job instanceof \App\Jobs\CompareNhlPregameContextRunDayJob) {
        $job->handle($service);
    } elseif ($job instanceof \App\Jobs\CalculateNhlPregameContextRunDayLeagueAveragesJob) {
        $job->handle($builder, $service);
    } else {
        $job->handle($builder);
    }
    $job->failed(new \RuntimeException('Obsolete delivery'));
    expect($run->fresh()->status)->toBe('running')->and($day->fresh()->context_state)->toBe('processing')
        ->and($day->fresh()->league_state)->toBe('processing')->and($day->fresh()->comparison_state)->toBe('processing');
    Bus::assertNothingDispatched();
})->with([\App\Jobs\BuildNhlPregameContextDayJob::class, \App\Jobs\CalculateNhlPregameContextRunDayLeagueAveragesJob::class,
    \App\Jobs\CompareNhlPregameContextRunDayJob::class]);
