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
    \Illuminate\Support\Facades\Http::preventStrayRequests();
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
    $this->updateGame = function (int $id, string $date, string $season = '20252026', string $state = 'FINAL'): void {
        \Illuminate\Support\Facades\DB::table('nhl_games')->insert([
            'nhl_game_id' => $id, 'season_id' => $season, 'game_type' => 2, 'game_state' => $state,
            'game_date' => $date, 'start_time_utc' => $date.' 23:00:00',
            'game_dow' => 'Wed', 'game_month' => 'Oct', 'home_team_id' => 1, 'away_team_id' => 2,
        ]);
    };
    $this->savedUpdateContext = function (int $gameId, string $date): array {
        $db = \Illuminate\Support\Facades\DB::class;
        $owner = NhlPregameContextRun::create(['model_run_id' => $this->model->id, 'action' => 'backfill',
            'status' => 'completed', 'season_ids' => ['20252026']]);
        $contexts = [];
        foreach ([1 => 'home', 2 => 'away'] as $team => $venue) {
            $contexts[$team] = $db::table('nhl_team_game_pregame_contexts')->insertGetId([
                'model_run_id' => $this->model->id, 'run_id' => $owner->id,
                'nhl_game_id' => $gameId, 'nhl_team_id' => $team, 'opponent_team_id' => $team === 1 ? 2 : 1,
                'game_date' => $date, 'source_cutoff_at' => $date.' 23:00:00', 'venue' => $venue,
                'context_version' => \App\Services\NhlPregameContextBuilder::VERSION,
                'schedule_metrics' => '{}', 'opponent_metrics' => '{}', 'metrics' => '{}',
            ]);
            $db::table('nhl_team_game_pregame_context_metrics')->insert([
                'team_context_id' => $contexts[$team], 'metric_key' => 'games', 'window_key' => 'last_5',
                'strength' => 'All', 'measure_key' => 'value', 'value' => 5, 'league_average' => null,
            ]);
        }
        $db::table('nhl_game_summaries')->insert(['nhl_game_id' => $gameId,
            'nhl_player_id' => 101, 'nhl_team_id' => 1, 'toi' => 900]);
        $player = $db::table('nhl_player_game_pregame_contexts')->insertGetId([
            'model_run_id' => $this->model->id, 'run_id' => $owner->id, 'team_context_id' => $contexts[1],
            'nhl_game_id' => $gameId, 'nhl_player_id' => 101, 'nhl_team_id' => 1, 'opponent_team_id' => 2,
            'game_date' => $date, 'source_cutoff_at' => $date.' 23:00:00', 'venue' => 'home',
            'participant_source' => 'boxscore', 'context_version' => \App\Services\NhlPregameContextBuilder::VERSION,
            'metrics' => '{}',
        ]);
        $db::table('nhl_player_game_pregame_context_metrics')->insert([
            'player_context_id' => $player, 'metric_key' => 'games', 'window_key' => 'last_5',
            'strength' => 'All', 'measure_key' => 'value', 'value' => 5, 'league_average' => null,
        ]);

        return ['run' => $owner, 'teams' => $contexts, 'player' => $player];
    };
    $this->fingerprintFixture = function (): array {
        Cache::flush();
        ($this->updateGame)(2024020001, '2024-10-08', '20242025');
        ($this->updateGame)(2025020001, '2025-10-08', '20252026', 'FUT');
        $db = \Illuminate\Support\Facades\DB::class;
        $db::table('nhl_game_summaries')->insert(['nhl_game_id' => 2024020001, 'nhl_player_id' => 101,
            'nhl_team_id' => 1, 'toi' => 900, 'g' => 2, 'sog' => 5, 'updated_at' => now()->subHour()]);
        $target = $db::table('nhl_games')->where('nhl_game_id', 2025020001)->first();
        $record = ['players' => [101 => ['team' => 1]], 'starters' => [], 'lineup_sources' => [1 => 'anticipated_lineup']];

        return [app(\App\Services\NhlCustomGameForecast::class), $target, $record];
    };
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
    ['postJson', 'admin.nhl-sat-models.context.update'],
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
    ['postJson', 'admin.nhl-sat-models.context.update'],
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
    ['postJson', 'admin.nhl-sat-models.context.update'],
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
    $run->update(['options' => [...$run->options, 'timeout_retries' => ['context:'.$day->id => [
        'checkpoint' => '2025020001:snapshot', 'attempts' => 2,
    ]]]]);
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
    $transactionLevel = \Illuminate\Support\Facades\DB::transactionLevel();
    $service->shouldReceive('releaseDayPeerPage')->once()->andReturnUsing(function () use ($transactionLevel): bool {
        expect(\Illuminate\Support\Facades\DB::transactionLevel())->toBe($transactionLevel);

        return false;
    });
    $this->app->instance(\App\Services\NhlPregameLeagueResults::class, $service);
    $job = new \App\Jobs\BuildNhlPregameContextDayBatchJob($run->id, $day->id);
    $job->handle($builder);
    expect($day->fresh()->context_state)->toBe('pending')->and($run->games()->where('state', 'complete')->count())->toBe(0)
        ->and($run->games()->where('state', 'pending')->count())->toBe(1)
        ->and($run->fresh()->options)->not->toHaveKey('timeout_retries');
    foreach (range(1, 4) as $delivery) {
        app(NhlPregameContextOrchestrator::class)->load($run->id);
        (new \App\Jobs\BuildNhlPregameContextDayBatchJob($run->id, $day->id, $day->fresh()->context_claim_token))->handle($builder);
    }
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
    $job->handle($builder);
    app(NhlPregameContextOrchestrator::class)->load($run->id);
    $job = new \App\Jobs\BuildNhlPregameContextDayBatchJob($run->id, $day->id, $day->fresh()->context_claim_token);
    expect(fn () => $job->handle($builder))->toThrow(RuntimeException::class);
    expect($game->fresh()->snapshot_completed_at)->not->toBeNull()->and($game->fresh()->comparisons_completed_at)->toBeNull()
        ->and($day->fresh()->failed_games)->toBe(1);
    $this->actingAs($this->admin)->postJson(route('admin.nhl-sat-models.context.resume', $this->model))->assertOk();
    app(NhlPregameContextOrchestrator::class)->load($run->id);
    $retryService = Mockery::mock(\App\Services\NhlPregameLeagueResults::class);
    $retryService->shouldReceive('attachGameComparisons')->once();
    $this->app->instance(\App\Services\NhlPregameLeagueResults::class, $retryService);
    (new \App\Jobs\BuildNhlPregameContextDayBatchJob($run->id, $day->id, $day->fresh()->context_claim_token))->handle($builder);
    expect($game->fresh()->state)->toBe('complete')->and($run->fresh()->failed_games)->toBe(0);
});

it('marks only the active game failed after a permanent checkpoint error', function (): void {
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

it('renews an owned execution lease without replacing its delivery token', function (string $stage, int $seconds): void {
    $token = '11111111-1111-4111-8111-111111111111';
    $run = NhlPregameContextRun::create(['model_run_id' => $this->model->id, 'action' => 'backfill',
        'status' => 'running', 'season_ids' => ['20252026'],
        'options' => ['pipeline_version' => NhlPregameContextOrchestrator::CHECKPOINT_PIPELINE]]);
    $day = NhlPregameContextRunDay::create(['run_id' => $run->id, 'game_date' => '2025-10-10',
        $stage.'_state' => 'processing', $stage.'_claim_token' => $token, $stage.'_claim_expires_at' => now()->addSecond()]);
    config(['nhl-pregame.claim_grace_seconds' => 120]);

    expect(app(NhlPregameContextOrchestrator::class)->startDayClaim($run->id, $day->id, $stage, $token))->toBeTrue()
        ->and($day->fresh()->getAttribute($stage.'_claim_token'))->toBe($token)
        ->and($day->fresh()->getAttribute($stage.'_claim_expires_at')->equalTo(now()->addSeconds($seconds)))->toBeTrue();
})->with([['league', 300], ['context', 420]]);

it('cannot renew an expired replaced or terminal delivery', function (string $status, bool $replaced, int $remaining): void {
    $token = '11111111-1111-4111-8111-111111111111';
    $run = NhlPregameContextRun::create(['model_run_id' => $this->model->id, 'action' => 'backfill',
        'status' => $status, 'season_ids' => ['20252026'],
        'options' => ['pipeline_version' => NhlPregameContextOrchestrator::CHECKPOINT_PIPELINE]]);
    $day = NhlPregameContextRunDay::create(['run_id' => $run->id, 'game_date' => '2025-10-10',
        'context_state' => 'processing', 'context_claim_token' => $token, 'context_claim_expires_at' => now()->addSeconds($remaining)]);
    $before = $day->getRawOriginal();

    expect(app(NhlPregameContextOrchestrator::class)->startDayClaim($run->id, $day->id, 'context',
        $replaced ? '22222222-2222-4222-8222-222222222222' : $token))->toBeFalse()
        ->and($day->fresh()->getRawOriginal())->toBe($before);
})->with([['running', false, -1], ['running', true, 60], ['paused', false, 60], ['stopped', false, 60]]);

it('requeues genuine timeouts while retaining committed checkpoints and pause intent', function (string $stage, string $status): void {
    $token = '11111111-1111-4111-8111-111111111111';
    $run = NhlPregameContextRun::create(['model_run_id' => $this->model->id, 'action' => 'backfill',
        'status' => $status, 'season_ids' => ['20252026'], 'total_games' => 1,
        'options' => ['pipeline_version' => NhlPregameContextOrchestrator::CHECKPOINT_PIPELINE]]);
    $day = NhlPregameContextRunDay::create(['run_id' => $run->id, 'game_date' => '2025-10-10',
        'league_cursor' => 1, 'league_entity_cursor' => \App\Services\NhlPregameLeagueResults::FINALIZING_ENTITY_CURSOR,
        $stage.'_state' => 'processing', $stage.'_claim_token' => $token, $stage.'_claim_expires_at' => now()->addMinutes(5)]);
    $game = NhlPregameContextRunGame::create(['run_id' => $run->id, 'nhl_game_id' => 2025020001,
        'game_date' => '2025-10-10', 'sequence' => 1, 'state' => 'processing', 'snapshot_completed_at' => now()]);
    $job = $stage === 'league'
        ? new \App\Jobs\CalculateNhlPregameLeagueBatchJob($run->id, $day->id, $token)
        : new \App\Jobs\BuildNhlPregameContextDayBatchJob($run->id, $day->id, $token);
    $job->failed(new \Illuminate\Queue\TimeoutExceededException('Worker deadline'));

    expect($run->fresh()->status)->toBe($status)->and($day->fresh()->getAttribute($stage.'_state'))->toBe('pending')
        ->and($day->fresh()->getAttribute($stage.'_claim_token'))->not->toBe($token)
        ->and($day->fresh()->league_entity_cursor)->toBe(\App\Services\NhlPregameLeagueResults::FINALIZING_ENTITY_CURSOR)
        ->and($game->fresh()->snapshot_completed_at)->not->toBeNull()->and($game->fresh()->state)->toBe('processing')
        ->and($run->fresh()->options['timeout_retries'][$stage.':'.$day->id]['attempts'])->toBe(1);
    Bus::assertDispatched(LoadNhlPregameContextRunJob::class, fn ($queued): bool => $queued->runId === $run->id);
    // A late failure callback from the replaced delivery cannot fail the new work.
    $job->failed(new \Illuminate\Queue\TimeoutExceededException('Late callback'));
    expect($run->fresh()->status)->toBe($status);
})->with([['league', 'running'], ['context', 'running'], ['league', 'pausing'], ['context', 'pausing']]);

it('fails visibly after the bounded timeout budget is exhausted without progress', function (): void {
    config(['nhl-pregame.timeout_retries' => 3]);
    $run = NhlPregameContextRun::create(['model_run_id' => $this->model->id, 'action' => 'backfill',
        'status' => 'running', 'season_ids' => ['20252026'],
        'options' => ['pipeline_version' => NhlPregameContextOrchestrator::CHECKPOINT_PIPELINE]]);
    $day = NhlPregameContextRunDay::create(['run_id' => $run->id, 'game_date' => '2025-10-10', 'league_state' => 'processing']);
    foreach (range(1, 4) as $attempt) {
        $token = '11111111-1111-4111-8111-11111111111'.$attempt;
        $day->refresh()->update(['league_state' => 'processing', 'league_claim_token' => $token,
            'league_claim_expires_at' => now()->addMinutes(5)]);
        (new \App\Jobs\CalculateNhlPregameLeagueBatchJob($run->id, $day->id, $token))
            ->failed(new \Illuminate\Queue\TimeoutExceededException('No progress'));
        expect($run->fresh()->status)->toBe($attempt <= 3 ? 'running' : 'failed');
    }
    expect($day->fresh()->league_state)->toBe('failed');
    Bus::assertDispatchedTimes(LoadNhlPregameContextRunJob::class, 3);
});

it('starts a fresh timeout budget when the durable league checkpoint advances', function (): void {
    config(['nhl-pregame.timeout_retries' => 1]);
    $run = NhlPregameContextRun::create(['model_run_id' => $this->model->id, 'action' => 'backfill',
        'status' => 'running', 'season_ids' => ['20252026'],
        'options' => ['pipeline_version' => NhlPregameContextOrchestrator::CHECKPOINT_PIPELINE]]);
    $day = NhlPregameContextRunDay::create(['run_id' => $run->id, 'game_date' => '2025-10-10', 'league_state' => 'processing']);
    $service = app(NhlPregameContextOrchestrator::class);
    foreach ([0, 32] as $cursor) {
        $token = '11111111-1111-4111-8111-111111111111';
        $day->refresh()->update(['league_state' => 'processing', 'league_entity_cursor' => $cursor,
            'league_claim_token' => $token, 'league_claim_expires_at' => now()->addMinutes(5)]);
        expect($service->retryTimedOutDay($run->id, $day->id, 'league', $token,
            new \Illuminate\Queue\TimeoutExceededException('Worker deadline')))->toBeTrue();
    }
    expect($run->fresh()->options['timeout_retries']['league:'.$day->id]['attempts'])->toBe(1);
});

it('yields bounded peer cleanup while preserving completed games', function (): void {
    config(['nhl-pregame.peer_cleanup_pages' => 1]);
    $run = NhlPregameContextRun::create(['model_run_id' => $this->model->id, 'action' => 'backfill',
        'status' => 'running', 'season_ids' => ['20252026'], 'total_games' => 1,
        'options' => ['pipeline_version' => NhlPregameContextOrchestrator::CHECKPOINT_PIPELINE]]);
    $day = NhlPregameContextRunDay::create(['run_id' => $run->id, 'game_date' => '2025-10-10',
        'league_state' => 'complete', 'context_state' => 'processing']);
    $game = NhlPregameContextRunGame::create(['run_id' => $run->id, 'nhl_game_id' => 2025020001,
        'game_date' => '2025-10-10', 'sequence' => 1, 'state' => 'complete',
        'snapshot_completed_at' => now(), 'comparisons_completed_at' => now()]);
    $builder = Mockery::mock(\App\Services\NhlPregameContextBuilder::class);
    $builder->shouldNotReceive('buildDay');
    $service = Mockery::mock(\App\Services\NhlPregameLeagueResults::class);
    $service->shouldNotReceive('attachGameComparisons');
    $service->shouldReceive('releaseDayPeerPage')->twice()->andReturn(true, false);
    $this->app->instance(\App\Services\NhlPregameLeagueResults::class, $service);
    (new \App\Jobs\BuildNhlPregameContextDayBatchJob($run->id, $day->id))->handle($builder);
    expect($day->fresh()->context_state)->toBe('pending')->and($game->fresh()->state)->toBe('complete');
    app(NhlPregameContextOrchestrator::class)->load($run->id);
    (new \App\Jobs\BuildNhlPregameContextDayBatchJob($run->id, $day->id, $day->fresh()->context_claim_token))->handle($builder);
    expect($day->fresh()->context_state)->toBe('complete');
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

it('independently wakes active checkpointed builds without resuming terminal or paused runs', function (string $status, ?string $jobClass): void {
    $run = NhlPregameContextRun::create(['model_run_id' => $this->model->id, 'action' => 'backfill',
        'status' => $status, 'season_ids' => ['20252026'],
        'options' => ['pipeline_version' => NhlPregameContextOrchestrator::CHECKPOINT_PIPELINE]]);
    $before = $run->getRawOriginal();
    app(NhlPregameContextOrchestrator::class)->recoverActiveRuns();
    expect($run->fresh()->getRawOriginal())->toBe($before);
    if ($jobClass === null) {
        Bus::assertNothingDispatched();
    } else {
        Bus::assertDispatchedTimes($jobClass, 1);
        Bus::assertDispatched($jobClass, fn ($job): bool => $job->runId === $run->id && $job->queue === 'projections');
    }
})->with([
    ['queued', PrepareNhlPregameContextRunJob::class],
    ['running', LoadNhlPregameContextRunJob::class],
    ['pausing', LoadNhlPregameContextRunJob::class],
    ['paused', null], ['stopped', null], ['completed', null], ['failed', null],
]);

it('leaves older pipeline versions unchanged during automatic recovery', function (): void {
    NhlPregameContextRun::create(['action' => 'backfill', 'status' => 'running', 'season_ids' => ['20252026'],
        'options' => ['pipeline_version' => NhlPregameContextOrchestrator::RESULTS_PIPELINE]]);
    app(NhlPregameContextOrchestrator::class)->recoverActiveRuns();
    Bus::assertNothingDispatched();
});

it('retains a delayed wakeup when another loader holds the run lock', function (): void {
    $run = NhlPregameContextRun::create(['action' => 'backfill', 'status' => 'running', 'season_ids' => ['20252026'],
        'options' => ['pipeline_version' => NhlPregameContextOrchestrator::CHECKPOINT_PIPELINE]]);
    $lock = Cache::lock('nhl-pregame-context-run-loader:'.$run->id, 30);
    $lock->get();
    try {
        app(NhlPregameContextOrchestrator::class)->load($run->id);
        app(NhlPregameContextOrchestrator::class)->load($run->id);
    } finally {
        $lock->release();
    }
    Bus::assertDispatchedTimes(LoadNhlPregameContextRunJob::class, 1);
    Bus::assertDispatched(LoadNhlPregameContextRunJob::class, fn ($job): bool => $job->runId === $run->id && $job->delay !== null);
    expect($run->fresh()->status)->toBe('running');
});

it('reclaims abandoned work through automatic wakeup while preserving completed dates and entity cursors', function (): void {
    config(['nhl-pregame.league_concurrency' => 1]);
    $run = NhlPregameContextRun::create(['model_run_id' => $this->model->id, 'action' => 'backfill', 'status' => 'running', 'season_ids' => ['20252026'],
        'total_games' => 2, 'options' => ['pipeline_version' => NhlPregameContextOrchestrator::CHECKPOINT_PIPELINE]]);
    $complete = NhlPregameContextRunDay::create(['run_id' => $run->id, 'game_date' => '2025-10-09',
        'league_state' => 'complete', 'context_state' => 'complete', 'league_cursor' => 4]);
    $partial = NhlPregameContextRunDay::create(['run_id' => $run->id, 'game_date' => '2025-10-10',
        'league_state' => 'processing', 'league_cursor' => 1, 'league_entity_cursor' => 108,
        'league_claim_token' => '11111111-1111-4111-8111-111111111111', 'league_claim_expires_at' => now()->subSecond()]);
    foreach (['2025-10-09', '2025-10-10'] as $index => $date) {
        NhlPregameContextRunGame::create(['run_id' => $run->id, 'nhl_game_id' => 2025020001 + $index,
            'game_date' => $date, 'sequence' => $index + 1, 'state' => $index === 0 ? 'complete' : 'pending']);
    }
    $before = $complete->getRawOriginal();
    $orchestrator = app(NhlPregameContextOrchestrator::class);
    $orchestrator->recoverActiveRuns();
    (new LoadNhlPregameContextRunJob($run->id))->handle($orchestrator);
    expect($complete->fresh()->getRawOriginal())->toBe($before)
        ->and($partial->fresh()->league_cursor)->toBe(1)->and($partial->fresh()->league_entity_cursor)->toBe(108)
        ->and($partial->fresh()->league_state)->toBe('processing')
        ->and($partial->fresh()->league_claim_token)->not->toBe('11111111-1111-4111-8111-111111111111');
    Bus::assertDispatched(\App\Jobs\CalculateNhlPregameLeagueBatchJob::class, fn ($job): bool => $job->runDayId === $partial->id);
    $this->actingAs($this->admin)->getJson(route('admin.nhl-sat-models.context.progress', $this->model))
        ->assertOk()->assertJsonPath('progress_html', fn (string $html): bool => str_contains($html, 'Building'));
});

it('finishes several committed league pages within one bounded delivery', function (): void {
    config(['nhl-pregame.league_continuation_pages' => 2, 'nhl-pregame.league_continuation_seconds' => 3600]);
    $run = NhlPregameContextRun::create(['action' => 'backfill', 'status' => 'running', 'season_ids' => ['20252026'],
        'options' => ['pipeline_version' => NhlPregameContextOrchestrator::CHECKPOINT_PIPELINE]]);
    $day = NhlPregameContextRunDay::create(['run_id' => $run->id, 'game_date' => '2025-10-10',
        'league_state' => 'processing', 'league_cursor' => 1, 'league_entity_cursor' => 108]);
    $service = Mockery::mock(\App\Services\NhlPregameLeagueResults::class);
    $service->shouldReceive('buildNextAverages')->twice()->andReturnUsing(function ($owner, $page) use ($day): bool {
        if ((int) $page->league_entity_cursor === 108) {
            $day->update(['league_entity_cursor' => 116]);

            return false;
        }
        expect((int) $page->league_entity_cursor)->toBe(116);
        $day->update(['league_cursor' => 2, 'league_entity_cursor' => 0]);

        return true;
    });
    (new \App\Jobs\CalculateNhlPregameLeagueBatchJob($run->id, $day->id))->handle(
        Mockery::mock(\App\Services\NhlPregameContextBuilder::class), $service,
    );
    expect($day->fresh()->league_state)->toBe('complete')->and($day->fresh()->league_cursor)->toBe(2);
    Bus::assertDispatchedTimes(LoadNhlPregameContextRunJob::class, 1);
});

it('yields league work at its page limit with the last committed cursor intact', function (): void {
    config(['nhl-pregame.league_continuation_pages' => 2, 'nhl-pregame.league_continuation_seconds' => 3600]);
    $run = NhlPregameContextRun::create(['action' => 'backfill', 'status' => 'running', 'season_ids' => ['20252026'],
        'options' => ['pipeline_version' => NhlPregameContextOrchestrator::CHECKPOINT_PIPELINE]]);
    $day = NhlPregameContextRunDay::create(['run_id' => $run->id, 'game_date' => '2025-10-10',
        'league_state' => 'processing', 'league_cursor' => 1]);
    $service = Mockery::mock(\App\Services\NhlPregameLeagueResults::class);
    $service->shouldReceive('buildNextAverages')->twice()->andReturnUsing(function ($owner, $page): bool {
        $page->increment('league_entity_cursor', 8);

        return false;
    });
    (new \App\Jobs\CalculateNhlPregameLeagueBatchJob($run->id, $day->id))->handle(
        Mockery::mock(\App\Services\NhlPregameContextBuilder::class), $service,
    );
    expect($day->fresh()->league_state)->toBe('pending')->and($day->fresh()->league_entity_cursor)->toBe(16);
});

it('honors a pause between league pages without losing the committed page', function (): void {
    config(['nhl-pregame.league_continuation_pages' => 4, 'nhl-pregame.league_continuation_seconds' => 3600]);
    $run = NhlPregameContextRun::create(['action' => 'backfill', 'status' => 'running', 'season_ids' => ['20252026'],
        'options' => ['pipeline_version' => NhlPregameContextOrchestrator::CHECKPOINT_PIPELINE]]);
    $day = NhlPregameContextRunDay::create(['run_id' => $run->id, 'game_date' => '2025-10-10',
        'league_state' => 'processing', 'league_cursor' => 1]);
    $service = Mockery::mock(\App\Services\NhlPregameLeagueResults::class);
    $service->shouldReceive('buildNextAverages')->once()->andReturnUsing(function () use ($run, $day): bool {
        $day->update(['league_entity_cursor' => 108]);
        $run->update(['status' => 'pausing']);

        return false;
    });
    (new \App\Jobs\CalculateNhlPregameLeagueBatchJob($run->id, $day->id))->handle(
        Mockery::mock(\App\Services\NhlPregameContextBuilder::class), $service,
    );
    expect($run->fresh()->status)->toBe('pausing')->and($day->fresh()->league_state)->toBe('pending')
        ->and($day->fresh()->league_entity_cursor)->toBe(108);
});


it('queues a manual update only for Test and exposes its status through HTTP', function (): void {
    $before = $this->model->getRawOriginal();
    $this->actingAs($this->admin)->postJson(route('admin.nhl-sat-models.context.update', $this->model), [
        'season_ids' => ['19901991'], 'only_missing' => false,
    ])->assertAccepted()->assertJsonPath('message', 'Queued Update Pregame.');
    $run = NhlPregameContextRun::query()->sole();
    expect($run->season_ids)->toBe(['20252026'])->and($run->options['only_missing'])->toBeTrue()
        ->and($run->options['train_season_ids'])->toBe($this->model->train_season_ids)
        ->and($run->options['trigger'])->toBe('manual_update')->and($run->created_by)->toBe($this->admin->id)
        ->and($this->model->fresh()->getRawOriginal())->toBe($before);
    Bus::assertDispatched(PrepareNhlPregameContextRunJob::class, fn ($job): bool => $job->runId === $run->id && $job->queue === 'projections');
    $this->getJson(route('admin.nhl-sat-models.context.progress', $this->model))->assertOk()->assertSee('Building');
});

it('updates the prediction season when production has no Test season', function (?string $explicit, string $expected): void {
    $this->model->update(['target_season_id' => null,
        'run_config' => $explicit === null ? [] : ['projection_season_id' => $explicit]]);
    $this->actingAs($this->admin)->postJson(route('admin.nhl-sat-models.context.update', $this->model))->assertAccepted();
    expect(NhlPregameContextRun::query()->sole()->season_ids)->toBe([$expected]);
})->with([[null, '20252026'], ['20262027', '20262027']]);

it('prefers Test over a separately configured prediction season for updates', function (): void {
    $this->model->update(['run_config' => ['projection_season_id' => '20262027']]);
    $run = app(NhlPregameContextOrchestrator::class)->queueModelUpdate($this->model);
    expect($run->season_ids)->toBe(['20252026']);
});

it('rejects updates without a resolvable Test or prediction season', function (): void {
    $this->model->update(['train_season_ids' => [], 'target_season_id' => null]);
    $this->actingAs($this->admin)->postJson(route('admin.nhl-sat-models.context.update', $this->model))
        ->assertUnprocessable()->assertJsonValidationErrors('season_ids');
    Bus::assertNothingDispatched();
});

it('rejects updates for a model outside the training registry', function (): void {
    $this->model->update(['workflow_stage' => 'projection']);
    $this->actingAs($this->admin)->postJson(route('admin.nhl-sat-models.context.update', $this->model))->assertNotFound();
    Bus::assertNothingDispatched();
});

it('supports the existing non JSON redirect for manual updates', function (): void {
    $this->actingAs($this->admin)->post(route('admin.nhl-sat-models.context.update', $this->model))
        ->assertRedirect(route('admin.nhl-sat-models.index'))->assertSessionHas('status', 'Queued Update Pregame.');
});

it('displays Update Pregame immediately below Build Pregame', function (): void {
    $html = $this->actingAs($this->admin)->get(route('admin.nhl-sat-models.index'))->assertOk()->getContent();
    expect($html)->toContain('data-sat-model-pregame-update-form', route('admin.nhl-sat-models.context.update', $this->model))
        ->and(strpos($html, '>Build Pregame</button>'))->toBeLessThan(strpos($html, '>Update Pregame</button>'));
});

it('prevents duplicate incremental runs while their target season is active', function (string $state): void {
    $first = app(NhlPregameContextOrchestrator::class)->queueModelUpdate($this->model);
    $first->update(['status' => $state]);
    $this->actingAs($this->admin)->postJson(route('admin.nhl-sat-models.context.update', $this->model))->assertUnprocessable();
    expect(NhlPregameContextRun::query()->count())->toBe(1);
    Bus::assertDispatchedTimes(PrepareNhlPregameContextRunJob::class, 1);
})->with(['queued', 'running', 'paused', 'pausing']);

it('fills gaps before the latest existing date and new trailing dates without rewriting Train', function (): void {
    ($this->updateGame)(2024020001, '2024-10-08', '20242025');
    ($this->updateGame)(2025020001, '2025-10-08');
    ($this->updateGame)(2025020002, '2025-10-09');
    ($this->updateGame)(2025020003, '2025-10-10');
    ($this->updateGame)(2025020004, '2025-10-11');
    ($this->savedUpdateContext)(2025020001, '2025-10-08');
    ($this->savedUpdateContext)(2025020003, '2025-10-10');
    $db = \Illuminate\Support\Facades\DB::class;
    $before = $db::table('nhl_team_game_pregame_contexts')->orderBy('id')->get()->toJson();
    $run = app(NhlPregameContextOrchestrator::class)->queueModelUpdate($this->model);
    app(NhlPregameContextOrchestrator::class)->prepare($run);
    expect($run->games()->orderBy('sequence')->pluck('nhl_game_id')->all())->toBe([2025020002, 2025020004])
        ->and($run->days()->count())->toBe(2)
        ->and($db::table('nhl_team_game_pregame_contexts')->orderBy('id')->get()->toJson())->toBe($before);
});

it('ignores unavailable league comparisons when stored context is structurally complete', function (): void {
    ($this->updateGame)(2025020001, '2025-10-08');
    ($this->savedUpdateContext)(2025020001, '2025-10-08');
    $this->actingAs($this->admin)->postJson(route('admin.nhl-sat-models.context.update', $this->model))->assertAccepted();
    $run = NhlPregameContextRun::latestForModel($this->model->id);
    app(NhlPregameContextOrchestrator::class)->prepare($run);
    expect($run->fresh()->status)->toBe('completed')->and($run->fresh()->options['up_to_date'])->toBeTrue()
        ->and($run->games()->count())->toBe(0)->and($run->days()->count())->toBe(0);
    Bus::assertNotDispatched(LoadNhlPregameContextRunJob::class);
    $this->getJson(route('admin.nhl-sat-models.context.progress', $this->model))->assertOk()->assertSee('Pregame is up to date.');
});

it('repairs missing player snapshots or normalized metric rows', function (string $missing): void {
    ($this->updateGame)(2025020001, '2025-10-08');
    $saved = ($this->savedUpdateContext)(2025020001, '2025-10-08');
    $db = \Illuminate\Support\Facades\DB::class;
    match ($missing) {
        'player' => $db::table('nhl_player_game_pregame_contexts')->where('id', $saved['player'])->delete(),
        'player_metrics' => $db::table('nhl_player_game_pregame_context_metrics')->where('player_context_id', $saved['player'])->delete(),
        'team_metrics' => $db::table('nhl_team_game_pregame_context_metrics')->where('team_context_id', $saved['teams'][2])->delete(),
    };
    $run = app(NhlPregameContextOrchestrator::class)->queueModelUpdate($this->model);
    app(NhlPregameContextOrchestrator::class)->prepare($run);
    expect($run->games()->pluck('nhl_game_id')->all())->toBe([2025020001]);
})->with(['player', 'player_metrics', 'team_metrics']);

it('repairs snapshot work whose comparisons never committed', function (): void {
    ($this->updateGame)(2025020001, '2025-10-08');
    $saved = ($this->savedUpdateContext)(2025020001, '2025-10-08');
    $saved['run']->update(['status' => 'failed']);
    NhlPregameContextRunGame::create(['run_id' => $saved['run']->id, 'nhl_game_id' => 2025020001,
        'game_date' => '2025-10-08', 'sequence' => 1, 'state' => 'failed', 'snapshot_completed_at' => now()]);
    $run = app(NhlPregameContextOrchestrator::class)->queueModelUpdate($this->model);
    app(NhlPregameContextOrchestrator::class)->prepare($run);
    expect($run->games()->pluck('nhl_game_id')->all())->toBe([2025020001]);
});

it('does not queue live future or playoff games during a historical context update', function (): void {
    foreach (['FUT', 'LIVE', 'FINAL'] as $i => $state) {
        ($this->updateGame)(2025020001 + $i, '2025-10-08', '20252026', $state);
    }
    \Illuminate\Support\Facades\DB::table('nhl_games')->where('nhl_game_id', 2025020003)->update(['game_type' => 3]);
    $run = app(NhlPregameContextOrchestrator::class)->queueModelUpdate($this->model);
    app(NhlPregameContextOrchestrator::class)->prepare($run);
    expect($run->fresh()->status)->toBe('completed')->and($run->games()->count())->toBe(0);
});

it('uses the same target season update for scheduled production models', function (): void {
    $this->model->update(['target_season_id' => null, 'train_season_ids' => ['20232024', '20242025', '20252026']]);
    \App\Models\ScheduledProcess::query()->update(['enabled' => false]);
    $process = \App\Models\ScheduledProcess::create([
        'key' => \App\Services\ScheduledProcessManager::pregameContextKey($this->model->id),
        'enabled' => true, 'timezone' => 'America/Toronto', 'start_time' => '03:50',
        'frequency_hours' => 24, 'settings' => ['include_update_pregame' => true],
    ]);
    app(\App\Services\ScheduledProcessManager::class)->tick();
    $run = NhlPregameContextRun::query()->sole();
    expect($run->season_ids)->toBe(['20262027'])->and($run->options['only_missing'])->toBeTrue()
        ->and($run->options['trigger'])->toBe('auto_sync')->and($process->fresh()->last_dispatched_at)->not->toBeNull();
    Bus::assertDispatchedTimes(PrepareNhlPregameContextRunJob::class, 1);
});

it('leaves overlapping scheduled updates due without creating another run', function (): void {
    app(NhlPregameContextOrchestrator::class)->queueModelUpdate($this->model);
    \App\Models\ScheduledProcess::query()->update(['enabled' => false]);
    $process = \App\Models\ScheduledProcess::create([
        'key' => \App\Services\ScheduledProcessManager::pregameContextKey($this->model->id),
        'enabled' => true, 'timezone' => 'America/Toronto', 'start_time' => '03:50',
        'frequency_hours' => 24, 'settings' => ['include_update_pregame' => true],
    ]);
    app(\App\Services\ScheduledProcessManager::class)->tick();
    expect(NhlPregameContextRun::query()->count())->toBe(1)->and($process->fresh()->last_dispatched_at)->toBeNull();
    Bus::assertDispatchedTimes(PrepareNhlPregameContextRunJob::class, 1);
});


it('does not accept another models snapshots as complete context', function (): void {
    ($this->updateGame)(2025020001, '2025-10-08');
    ($this->savedUpdateContext)(2025020001, '2025-10-08');
    $other = $this->model->replicate();
    $other->run_key = 'other-update-model';
    $other->save();
    $run = app(NhlPregameContextOrchestrator::class)->queueModelUpdate($other);
    app(NhlPregameContextOrchestrator::class)->prepare($run);
    expect($run->model_run_id)->toBe($other->id)
        ->and($run->games()->pluck('nhl_game_id')->all())->toBe([2025020001]);
});

it('retains the owning models stopped update and admits a fresh incremental run', function (): void {
    $first = app(NhlPregameContextOrchestrator::class)->queueModelUpdate($this->model);
    $first->update(['status' => 'stopped']);
    $next = app(NhlPregameContextOrchestrator::class)->queueModelUpdate($this->model);
    expect($next->id)->not->toBe($first->id)->and($first->fresh()->status)->toBe('stopped')
        ->and($next->season_ids)->toBe(['20252026']);
});


it('includes todays unfinished games in Update Pregame using anticipated evidence', function (): void {
    $this->model->update(['target_season_id' => '20262027']);
    ($this->updateGame)(2026020001, '2026-10-09', '20262027', 'FUT');
    ($this->updateGame)(2026020002, '2026-10-10', '20262027', 'FUT');
    ($this->updateGame)(2026020003, '2026-10-08', '20262027', 'LIVE');
    $record = ['players' => [101 => ['team' => 1, 'name' => 'Skater', 'role' => 'F']], 'starters' => [],
        'lineup_sources' => [1 => 'anticipated_lineup', 2 => 'projected_roster']];
    $service = Mockery::mock(\App\Services\NhlCustomGameForecast::class)->makePartial();
    $service->shouldReceive('contextEvidence')->once()->andReturn(['fingerprint' => 'lineup-v1',
        'cutoff_at' => '2026-10-09 12:00:00', 'players' => $record['players'], 'team_ids' => [1, 2], 'participants' => $record]);
    $service->shouldReceive('contextReady')->once()->andReturnFalse();
    app()->instance(\App\Services\NhlCustomGameForecast::class, $service);
    $this->actingAs($this->admin)->postJson(route('admin.nhl-sat-models.context.update', $this->model))->assertAccepted();
    $run = NhlPregameContextRun::latestForModel($this->model->id);
    app(NhlPregameContextOrchestrator::class)->prepare($run);
    expect($run->games()->pluck('nhl_game_id')->all())->toBe([2026020001])
        ->and(data_get($run->fresh()->options, 'prediction_evidence.2026020001.fingerprint'))->toBe('lineup-v1')
        ->and(data_get($run->fresh()->options, 'prediction_participants.2026020001.players.101.team'))->toBe(1);
    $this->getJson(route('admin.nhl-sat-models.context.progress', $this->model))->assertOk()->assertSee('0 / 1 games');
});

it('skips fresh unfinished context instead of rebuilding every automatic update', function (): void {
    $this->model->update(['target_season_id' => '20262027']);
    ($this->updateGame)(2026020001, '2026-10-09', '20262027', 'LIVE');
    $service = Mockery::mock(\App\Services\NhlCustomGameForecast::class)->makePartial();
    $service->shouldReceive('contextEvidence')->once()->andReturn(['fingerprint' => 'current']);
    $service->shouldReceive('contextReady')->once()->andReturnTrue();
    app()->instance(\App\Services\NhlCustomGameForecast::class, $service);
    $run = app(NhlPregameContextOrchestrator::class)->queueModelUpdate($this->model);
    app(NhlPregameContextOrchestrator::class)->prepare($run);
    expect($run->games()->count())->toBe(0)->and($run->fresh()->options['up_to_date'])->toBeTrue();
});

it('queues explicit prediction dependencies for scheduled games outside todays date', function (): void {
    ($this->updateGame)(2026020001, '2026-10-10', '20262027', 'FUT');
    ($this->updateGame)(2026020002, '2026-10-11', '20262027', 'FUT');
    $evidence = [2026020001 => ['fingerprint' => 'requested', 'cutoff_at' => '2026-10-09 12:00:00']];
    $run = app(NhlPregameContextOrchestrator::class)->queuePredictionContexts($this->model, $evidence, []);
    app(NhlPregameContextOrchestrator::class)->prepare($run);
    expect($run->games()->pluck('nhl_game_id')->all())->toBe([2026020001])
        ->and($run->options['trigger'])->toBe('prediction')->and($run->season_ids)->toBe(['20262027']);
    Bus::assertDispatched(PrepareNhlPregameContextRunJob::class, fn ($job): bool => $job->queue === 'projections');
});

it('saves upcoming player and starter context without target boxscores or target outcomes', function (string $state): void {
    ($this->updateGame)(2026020001, '2026-10-09', '20262027', $state);
    $record = ['players' => [101 => ['team' => 1, 'name' => 'Away forward', 'role' => 'F'],
        201 => ['team' => 2, 'name' => 'Home forward', 'role' => 'F']],
        'starters' => [1 => 102, 2 => null], 'lineup_sources' => [1 => 'anticipated_lineup', 2 => 'projected_roster']];
    $evidence = [2026020001 => ['fingerprint' => 'frozen-lineup', 'cutoff_at' => '2026-10-09 12:00:00']];
    $run = app(NhlPregameContextOrchestrator::class)->queuePredictionContexts($this->model, $evidence, [2026020001 => $record]);
    $result = app(\App\Services\NhlPregameContextBuilder::class)->buildDay($run, [2026020001]);
    expect($result['games'][2026020001]['team_context_count'])->toBe(2)
        ->and($result['games'][2026020001]['player_context_count'])->toBe(3);
    $db = \Illuminate\Support\Facades\DB::class;
    expect($db::table('nhl_game_summaries')->where('nhl_game_id', 2026020001)->count())->toBe(0);
    $players = $db::table('nhl_player_game_pregame_contexts')->where('model_run_id', $this->model->id)->get();
    expect($players->pluck('participant_source')->all())->toContain('anticipated_lineup', 'projected_roster', 'anticipated_goalie');
    foreach ($players as $player) {
        expect($player->source_cutoff_at)->toBe('2026-10-09 12:00:00')->and($player->team_context_id)->not->toBeNull();
    }
    $team = $db::table('nhl_team_game_pregame_contexts')->where('model_run_id', $this->model->id)->first();
    expect(data_get(json_decode($team->schedule_metrics, true), 'prediction_evidence.fingerprint'))->toBe('frozen-lineup');
})->with(['FUT', 'PRE', 'LIVE', 'CRIT']);

it('does not treat missing projected identities as permission to use live target summaries', function (): void {
    ($this->updateGame)(2026020001, '2026-10-09', '20262027', 'LIVE');
    $record = ['players' => [], 'starters' => [], 'lineup_sources' => []];
    $run = app(NhlPregameContextOrchestrator::class)->queuePredictionContexts($this->model,
        [2026020001 => ['fingerprint' => 'missing-lineup', 'cutoff_at' => '2026-10-09 12:00:00']], [2026020001 => $record]);
    $result = app(\App\Services\NhlPregameContextBuilder::class)->buildDay($run, [2026020001]);
    expect($result['games'][2026020001]['blocked'])->toBeTrue();
});


it('requires committed comparisons and the same lineup before accepting saved prediction context', function (): void {
    ($this->updateGame)(2025020001, '2025-10-08');
    $saved = ($this->savedUpdateContext)(2025020001, '2025-10-08');
    $db = \Illuminate\Support\Facades\DB::class;
    $db::table('nhl_team_game_pregame_contexts')->whereIn('id', $saved['teams'])->update([
        'schedule_metrics' => json_encode(['prediction_evidence' => ['fingerprint' => 'selected-lineup']], JSON_THROW_ON_ERROR),
    ]);
    $work = NhlPregameContextRunGame::create(['run_id' => $saved['run']->id, 'nhl_game_id' => 2025020001,
        'game_date' => '2025-10-08', 'sequence' => 1, 'state' => 'complete', 'snapshot_completed_at' => now()]);
    $evidence = ['fingerprint' => 'selected-lineup', 'team_ids' => [1, 2],
        'players' => [101 => ['team' => 1]], 'starters' => []];
    $service = app(\App\Services\NhlCustomGameForecast::class);
    expect($service->contextReady($this->model->id, 2025020001, $evidence))->toBeFalse();
    $work->update(['comparisons_completed_at' => now()]);
    expect($service->contextReady($this->model->id, 2025020001, $evidence))->toBeTrue();
    $changed = array_replace($evidence, ['fingerprint' => 'changed-lineup']);
    expect($service->contextReady($this->model->id, 2025020001, $changed))->toBeFalse();
    $evidence['players'][102] = ['team' => 1];
    expect($service->contextReady($this->model->id, 2025020001, $evidence))->toBeFalse();
});

it('rejects a selected starter without the correct same-game team context link', function (): void {
    ($this->updateGame)(2025020001, '2025-10-08');
    $saved = ($this->savedUpdateContext)(2025020001, '2025-10-08');
    $db = \Illuminate\Support\Facades\DB::class;
    $db::table('nhl_team_game_pregame_contexts')->whereIn('id', $saved['teams'])->update([
        'schedule_metrics' => json_encode(['prediction_evidence' => ['fingerprint' => 'starter']], JSON_THROW_ON_ERROR),
    ]);
    NhlPregameContextRunGame::create(['run_id' => $saved['run']->id, 'nhl_game_id' => 2025020001,
        'game_date' => '2025-10-08', 'sequence' => 1, 'state' => 'complete', 'comparisons_completed_at' => now()]);
    $evidence = ['fingerprint' => 'starter', 'team_ids' => [1, 2], 'players' => [101 => ['team' => 1]],
        'starters' => [2 => 101]];
    expect(app(\App\Services\NhlCustomGameForecast::class)->contextReady($this->model->id, 2025020001, $evidence))->toBeFalse();
});

it('freezes forecast context before scheduled start and excludes the targets own result updates', function (): void {
    ($this->updateGame)(2025020001, '2025-10-08');
    ($this->updateGame)(2025020002, '2025-10-09');
    $db = \Illuminate\Support\Facades\DB::class;
    $record = ['players' => [101 => ['team' => 1, 'role' => 'F']], 'starters' => [], 'lineup_sources' => []];
    $game = $db::table('nhl_games')->where('nhl_game_id', 2025020002)->first();
    $service = app(\App\Services\NhlCustomGameForecast::class);
    $asOf = \Carbon\CarbonImmutable::parse('2025-10-09 12:00:00', 'UTC');
    $first = $service->contextEvidence($this->model, $game, $record, $asOf);
    $db::table('nhl_game_summaries')->insert(['nhl_game_id' => 2025020002, 'nhl_player_id' => 101,
        'nhl_team_id' => 1, 'g' => 9, 'updated_at' => now()]);
    $second = $service->contextEvidence($this->model, $game, $record, $asOf);
    expect($first['fingerprint'])->toBe($second['fingerprint'])->and($first['cutoff_at'])->toBe('2025-10-09 12:00:00');
    $db::table('nhl_game_summaries')->insert(['nhl_game_id' => 2025020001, 'nhl_player_id' => 101,
        'nhl_team_id' => 1, 'g' => 1, 'updated_at' => now()]);
    expect($service->contextEvidence($this->model, $game, $record, $asOf)['fingerprint'])->not->toBe($first['fingerprint']);
});


it('uses actual finished-game participants even when anticipated identities were frozen earlier', function (string $state): void {
    $this->model->update(['target_season_id' => null, 'train_season_ids' => ['20252026']]);
    ($this->updateGame)(2026020001, '2026-10-09', '20262027', $state);
    $db = \Illuminate\Support\Facades\DB::class;
    $db::table('nhl_game_summaries')->insert(['nhl_game_id' => 2026020001, 'nhl_player_id' => 101,
        'nhl_team_id' => 1, 'toi' => 900, 'g' => 9]);
    $reader = Mockery::mock(\App\Services\NhlCustomGamePredictionInputs::class);
    $reader->shouldNotReceive('anticipatedParticipants');
    app()->instance(\App\Services\NhlCustomGamePredictionInputs::class, $reader);
    $record = ['players' => [999 => ['team' => 1]], 'starters' => [], 'lineup_sources' => [1 => 'anticipated_lineup']];
    $run = app(NhlPregameContextOrchestrator::class)->queuePredictionContexts($this->model,
        [2026020001 => ['fingerprint' => 'earlier-roster', 'cutoff_at' => '2026-10-09 12:00:00']], [2026020001 => $record]);
    $result = app(\App\Services\NhlPregameContextBuilder::class)->buildDay($run, [2026020001]);
    expect($result['games'][2026020001]['player_context_count'])->toBe(1);
    $player = $db::table('nhl_player_game_pregame_contexts')->where('nhl_game_id', 2026020001)->sole();
    expect((int) $player->nhl_player_id)->toBe(101)->and($player->participant_source)->toBe('completed_game_summary')
        ->and(data_get(json_decode($player->metrics, true), 'all.last_5.games'))->toBe(0);
    $this->assertDatabaseMissing('nhl_player_game_pregame_contexts', ['nhl_game_id' => 2026020001, 'nhl_player_id' => 999]);
})->with(['FINAL', 'OFF']);

it('keeps finished context blocked if actual participants are unavailable instead of using a frozen anticipated roster', function (): void {
    ($this->updateGame)(2025020001, '2025-10-08');
    $record = ['players' => [999 => ['team' => 1]], 'starters' => [], 'lineup_sources' => [1 => 'anticipated_lineup']];
    $run = app(NhlPregameContextOrchestrator::class)->queuePredictionContexts($this->model,
        [2025020001 => ['fingerprint' => 'old-anticipated', 'cutoff_at' => '2025-10-08 12:00:00']], [2025020001 => $record]);
    $result = app(\App\Services\NhlPregameContextBuilder::class)->buildDay($run, [2025020001]);
    expect($result['games'][2025020001]['blocked'])->toBeTrue();
    $this->assertDatabaseCount('nhl_player_game_pregame_contexts', 0);
});

it('resolves anticipated players for unfinished prediction-season games without target summaries', function (string $state): void {
    $this->model->update(['target_season_id' => null, 'train_season_ids' => ['20252026']]);
    ($this->updateGame)(2026020001, '2026-10-09', '20262027', $state);
    $reader = Mockery::mock(\App\Services\NhlCustomGamePredictionInputs::class);
    $reader->shouldReceive('anticipatedParticipants')->once()->andReturn(['players' => [101 => ['team' => 1]],
        'starters' => [], 'lineup_sources' => [1 => 'anticipated_lineup']]);
    app()->instance(\App\Services\NhlCustomGamePredictionInputs::class, $reader);
    $run = app(NhlPregameContextOrchestrator::class)->queueModelUpdate($this->model);
    $result = app(\App\Services\NhlPregameContextBuilder::class)->buildDay($run, [2026020001]);
    expect($result['games'][2026020001]['player_context_count'])->toBe(1);
    $this->assertDatabaseHas('nhl_player_game_pregame_contexts', ['nhl_game_id' => 2026020001,
        'nhl_player_id' => 101, 'participant_source' => 'anticipated_lineup']);
    $this->assertDatabaseCount('nhl_game_summaries', 0);
})->with(['FUT', 'PRE', 'LIVE']);

it('updates previously anticipated context to actual participants once a game finishes', function (): void {
    ($this->updateGame)(2025020001, '2025-10-08');
    $saved = ($this->savedUpdateContext)(2025020001, '2025-10-08');
    $db = \Illuminate\Support\Facades\DB::class;
    $db::table('nhl_player_game_pregame_contexts')->where('id', $saved['player'])->update(['participant_source' => 'anticipated_lineup']);
    $this->actingAs($this->admin)->postJson(route('admin.nhl-sat-models.context.update', $this->model))->assertAccepted();
    $run = NhlPregameContextRun::latestForModel($this->model->id);
    app(NhlPregameContextOrchestrator::class)->prepare($run);
    expect($run->games()->pluck('nhl_game_id')->all())->toBe([2025020001]);
    $result = app(\App\Services\NhlPregameContextBuilder::class)->buildDay($run, [2025020001]);
    expect($result['games'][2025020001]['player_context_count'])->toBe(1);
    $this->assertDatabaseHas('nhl_player_game_pregame_contexts', ['model_run_id' => $this->model->id,
        'nhl_game_id' => 2025020001, 'nhl_player_id' => 101, 'participant_source' => 'completed_game_summary']);
    $this->getJson(route('admin.nhl-sat-models.context.progress', $this->model))->assertOk()->assertSee('0 / 1 games');
});

it('selects actual or anticipated evidence according to whether the requested game has finished', function (string $state, bool $actual): void {
    ($this->updateGame)(2025020001, '2025-10-08', '20252026', $state);
    $game = \Illuminate\Support\Facades\DB::table('nhl_games')->where('nhl_game_id', 2025020001)->first();
    $record = ['players' => [101 => ['team' => 1]], 'starters' => [],
        'lineup_sources' => [1 => $actual ? 'historical_participants' : 'anticipated_lineup']];
    $reader = Mockery::mock(\App\Services\NhlCustomGamePredictionInputs::class);
    $reader->shouldReceive('anticipatedParticipants')->once()->with($game, $actual)->andReturn($record);
    app()->instance(\App\Services\NhlCustomGamePredictionInputs::class, $reader);
    $evidence = app(\App\Services\NhlCustomGameForecast::class)->contextEvidence($this->model, $game);
    expect($evidence['participants'])->toBe($record);
})->with([['FUT', false], ['LIVE', false], ['FINAL', true], ['OFF', true]]);


it('keeps pregame fingerprints stable when sync timestamps change without statistical changes', function (): void {
    [$service, $target, $record] = ($this->fingerprintFixture)();
    $before = $service->contextEvidence($this->model, $target, $record)['fingerprint'];
    $db = \Illuminate\Support\Facades\DB::class;
    $db::table('nhl_games')->where('nhl_game_id', 2024020001)->update(['updated_at' => now(), 'boxscore_synced_at' => now()]);
    $db::table('nhl_game_summaries')->where('nhl_game_id', 2024020001)->update(['updated_at' => now()]);
    expect($service->contextEvidence($this->model, $target, $record)['fingerprint'])->toBe($before);
});

it('invalidates pregame fingerprints when real result values are corrected', function (string $kind): void {
    [$service, $target, $record] = ($this->fingerprintFixture)();
    $before = $service->contextEvidence($this->model, $target, $record)['fingerprint'];
    $db = \Illuminate\Support\Facades\DB::class;
    match ($kind) {
        'score' => $db::table('nhl_games')->where('nhl_game_id', 2024020001)->update(['home_team_score' => 4]),
        'opportunities' => $db::table('nhl_games')->where('nhl_game_id', 2024020001)->update(['home_pp_opportunities' => 4]),
        'player goals' => $db::table('nhl_game_summaries')->where('nhl_game_id', 2024020001)->update(['g' => 3, 'updated_at' => now()]),
    };
    expect($service->contextEvidence($this->model, $target, $record)['fingerprint'])->not->toBe($before);
})->with(['score', 'opportunities', 'player goals']);

it('detects added and removed historical participants without using target outcomes', function (): void {
    [$service, $target, $record] = ($this->fingerprintFixture)();
    $before = $service->contextEvidence($this->model, $target, $record)['fingerprint'];
    $db = \Illuminate\Support\Facades\DB::class;
    $id = $db::table('nhl_game_summaries')->insertGetId(['nhl_game_id' => 2024020001, 'nhl_player_id' => 102,
        'nhl_team_id' => 1, 'toi' => 600, 'g' => 1, 'updated_at' => now()]);
    expect($service->contextEvidence($this->model, $target, $record)['fingerprint'])->not->toBe($before);
    $db::table('nhl_game_summaries')->where('id', $id)->delete();
    expect($service->contextEvidence($this->model, $target, $record)['fingerprint'])->toBe($before);
});

it('invalidates pregame evidence when derived facts are republished by a completed import', function (): void {
    [$service, $target, $record] = ($this->fingerprintFixture)();
    $before = $service->contextEvidence($this->model, $target, $record)['fingerprint'];
    \Illuminate\Support\Facades\DB::table('nhl_import_progress')->insert(['season_id' => '20242025',
        'game_date' => '2024-10-08', 'game_id' => '2024020001', 'game_type' => 2, 'import_type' => 'summary',
        'status' => 'completed', 'items_count' => 40, 'created_at' => now(), 'updated_at' => now()]);
    expect($service->contextEvidence($this->model, $target, $record)['fingerprint'])->not->toBe($before);
});

it('keeps lineup changes independent from unchanged historical statistics', function (): void {
    [$service, $target, $record] = ($this->fingerprintFixture)();
    $before = $service->contextEvidence($this->model, $target, $record)['fingerprint'];
    $record['players'] = [102 => ['team' => 1]];
    expect($service->contextEvidence($this->model, $target, $record)['fingerprint'])->not->toBe($before);
});


it('invalidates on processed PBP corrections while ignoring repeated observation timestamps', function (): void {
    [$service, $target, $record] = ($this->fingerprintFixture)();
    $db = \Illuminate\Support\Facades\DB::class;
    $db::table('nhl_pbp_processing_states')->insert(['nhl_game_id' => 2024020001,
        'pending_version' => 1, 'processed_version' => 1, 'observed_at' => now()->subHour()]);
    $first = $service->contextEvidence($this->model, $target, $record)['fingerprint'];
    $db::table('nhl_pbp_processing_states')->where('nhl_game_id', 2024020001)->update(['observed_at' => now()]);
    expect($service->contextEvidence($this->model, $target, $record)['fingerprint'])->toBe($first);
    $db::table('nhl_pbp_processing_states')->where('nhl_game_id', 2024020001)->update(['processed_version' => 2, 'pending_version' => 2]);
    expect($service->contextEvidence($this->model, $target, $record)['fingerprint'])->not->toBe($first);
});
