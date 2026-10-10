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
        ->assertSee('Build Pregame')->assertSee('Analysis')->assertSee('Building')
        ->assertSee('View Pregame Impacts')->assertSee(route('admin.nhl-sat-models.context.effects'));
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
    ['getJson', 'admin.nhl-sat-models.context.effects'],
]);

it('denies dashboard actions to non super admins', function (string $method, string $route): void {
    $this->actingAs(User::factory()->create())->{$method}(route($route, $this->model))->assertForbidden();
    Bus::assertNothingDispatched();
})->with([
    ['postJson', 'admin.nhl-sat-models.context.build'],
    ['getJson', 'admin.nhl-sat-models.context.progress'],
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
