<?php

declare(strict_types=1);

use App\Jobs\RefreshNhlGamePredictionJob;
use App\Jobs\RunNhlGamePredictionJob;
use App\Models\NhlGame;
use App\Models\NhlModelRun;
use App\Models\NhlSatEngine;
use App\Models\NhlSatEngineStack;
use App\Models\Role;
use App\Models\User;
use App\Services\NhlGamePredictionHistory;
use App\Services\NhlGamePredictionPayload;
use App\Services\NhlGamePredictionResponseCache;
use Illuminate\Bus\PendingBatch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-22 15:00:00 UTC'));
    config(['cache.default' => 'array']);
    Cache::flush();
    Bus::fake();
    Http::preventStrayRequests();
    $this->admin = User::factory()->create();
    $role = Role::create(['name' => 'Super Admin', 'slug' => 'super-admin', 'level' => 99, 'scope' => 'global', 'is_active' => true]);
    $this->admin->roles()->attach($role->id, ['organization_id' => null]);
    $this->model = NhlModelRun::create(['run_key' => 'operational-history', 'name' => 'History model', 'model_family' => 'sat',
        'workflow_stage' => 'training', 'model_version' => 'history-v1', 'usage' => 'production', 'status' => 'complete',
        'train_season_ids' => ['20252026'], 'target_season_id' => '20262027', 'metrics' => []]);
    $this->engine = NhlSatEngine::create(['name' => 'Current engine', 'model_run_id' => $this->model->id, 'settings' => ['offense' => 100]]);
    $this->stack = NhlSatEngineStack::create(['name' => 'Default', 'is_default' => true]);
    $this->stack->members()->create(['engine_id' => $this->engine->id, 'priority' => 1]);
    $this->game = function (int $id = 2026020001, string $date = '2026-10-22', string $state = 'FUT'): NhlGame {
        return NhlGame::create(['nhl_game_id' => $id, 'season_id' => '20262027', 'game_type' => 2,
            'game_date' => $date, 'game_dow' => 'Thursday', 'game_month' => 'October', 'game_state' => $state,
            'away_team_id' => 6, 'away_team_abbrev' => 'BOS', 'home_team_id' => 10, 'home_team_abbrev' => 'TOR']);
    };
    ($this->game)();
    $this->forecast = ['prediction_available' => true, 'pick_qualified' => true,
        'inputs' => ['engine_id' => $this->engine->id, 'stack_id' => $this->stack->id],
        'prediction' => ['winner' => ['team_abbrev' => 'TOR'], 'predicted_score' => ['away' => 2.2, 'home' => 3.1], 'confidence_score' => 67],
        'market_probabilities' => [['market_key' => 'moneyline', 'team_abbrev' => 'TOR', 'probability' => .61]]];
    $this->publish = function (array $changes = [], bool $force = true, array $input = [], ?int $runId = null): array {
        return app(NhlGamePredictionResponseCache::class)->respond(2026020001, ['nhl_game_id' => 2026020001, ...$input],
            fn (): array => array_replace_recursive($this->forecast, $changes), $force, $runId);
    };
    $this->start = fn () => $this->actingAs($this->admin)->postJson(route('admin.game-predictions.runs.store'), ['start_date' => '2026-10-22']);
    $this->index = fn () => $this->actingAs($this->admin)->getJson(route('admin.game-predictions.index', ['start_date' => '2026-10-22']));
});

afterEach(function (): void { $this->travelBack(); });

it('blocks guests on every operational endpoint', function (string $method, string $path): void {
    $this->{$method}('/admin/game-predictions'.$path)->assertUnauthorized();
})->with([['getJson', '?start_date=2026-10-22'], ['postJson', '/runs'], ['getJson', '/games/2026020001/revisions'], ['getJson', '/revisions/1']]);

it('blocks ordinary users on every operational endpoint', function (string $method, string $path): void {
    $this->actingAs(User::factory()->create())->{$method}('/admin/game-predictions'.$path)->assertForbidden();
})->with([['getJson', '?start_date=2026-10-22'], ['postJson', '/runs'], ['getJson', '/games/2026020001/revisions'], ['getJson', '/revisions/1']]);

it('allows super admins to read stored schedule rows without calculating', function (): void {
    ($this->index)()->assertOk()->assertJsonPath('games.0.away', 'BOS')->assertJsonPath('games.0.revision', null);
    Bus::assertNothingDispatched();
});

it('admits one date and persists the requesting admin and stack', function (): void {
    ($this->start)()->assertAccepted()->assertJsonPath('total_games', 1);
    $this->assertDatabaseHas('nhl_game_prediction_runs', ['created_by' => $this->admin->id, 'start_date' => '2026-10-22', 'end_date' => '2026-10-22']);
});

it('queues inclusive endpoints once per game on projections', function (): void {
    ($this->game)(2026020002, '2026-10-23');
    $this->actingAs($this->admin)->postJson('/admin/game-predictions/runs', ['start_date' => '2026-10-22', 'end_date' => '2026-10-23'])->assertAccepted()->assertJsonPath('total_games', 2);
    Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->jobs->count() === 2
        && $batch->jobs->every(fn ($job): bool => $job instanceof RunNhlGamePredictionJob && $job->queue === 'projections'));
});

it('rejects missing start dates', function (): void {
    $this->actingAs($this->admin)->postJson('/admin/game-predictions/runs')->assertUnprocessable()->assertJsonValidationErrors('start_date');
});

it('rejects malformed dates', function (): void {
    $this->actingAs($this->admin)->postJson('/admin/game-predictions/runs', ['start_date' => 'tomorrow'])->assertUnprocessable();
});

it('rejects reversed ranges', function (): void {
    $this->actingAs($this->admin)->postJson('/admin/game-predictions/runs', ['start_date' => '2026-10-23', 'end_date' => '2026-10-22'])->assertUnprocessable();
});

it('rejects ranges longer than 366 dates', function (): void {
    $this->actingAs($this->admin)->postJson('/admin/game-predictions/runs', ['start_date' => '2025-10-21', 'end_date' => '2026-10-22'])->assertUnprocessable();
});

it('rejects dates with no stored games without importing', function (): void {
    $this->actingAs($this->admin)->postJson('/admin/game-predictions/runs', ['start_date' => '2026-11-30'])->assertUnprocessable();
    Bus::assertNothingDispatched();
    $this->assertDatabaseCount('nhl_game_prediction_runs', 0);
});

it('rejects admission when the default stack is missing', function (): void {
    $this->stack->update(['is_default' => false]);
    ($this->start)()->assertUnprocessable()->assertJsonValidationErrors('stack');
});

it('rejects admission when the default stack is empty', function (): void {
    $this->stack->members()->delete();
    ($this->start)()->assertUnprocessable()->assertJsonValidationErrors('stack');
});

it('excludes postponed and cancelled games', function (): void {
    ($this->game)(2026020002, state: 'PPD');
    ($this->game)(2026020003, state: 'CNCL');
    ($this->start)()->assertAccepted()->assertJsonPath('total_games', 1);
});

it('archives each forced API recalculation and displays the newest one', function (): void {
    $first = ($this->publish)();
    $second = ($this->publish)(['prediction' => ['predicted_score' => ['home' => 4.1]]]);
    expect($second['meta']['prediction_revision_id'])->toBeGreaterThan($first['meta']['prediction_revision_id']);
    ($this->index)()->assertOk()->assertJsonPath('games.0.revision.home_goals', 4.1);
    $this->assertDatabaseCount('nhl_game_prediction_revisions', 2);
});

it('does not append history for fresh cache reads', function (): void {
    $first = ($this->publish)();
    $second = ($this->publish)(force: false);
    expect($second['meta']['prediction_revision_id'])->toBe($first['meta']['prediction_revision_id']);
    $this->assertDatabaseCount('nhl_game_prediction_revisions', 1);
});

it('does not append for stale cache reads but queues the existing refresh', function (): void {
    ($this->publish)();
    $this->travel(61)->seconds();
    expect(($this->publish)(force: false)['meta']['cache'])->toBe('stale');
    $this->assertDatabaseCount('nhl_game_prediction_revisions', 1);
    Bus::assertDispatched(RefreshNhlGamePredictionJob::class);
});

it('archives queued refreshes through the same publication boundary', function (): void {
    ($this->publish)();
    $this->travel(61)->seconds();
    ($this->publish)(force: false);
    $refresh = Bus::dispatched(RefreshNhlGamePredictionJob::class)->first();
    $builder = Mockery::mock(NhlGamePredictionPayload::class);
    $builder->shouldReceive('build')->once()->andReturn($this->forecast);
    $refresh->handle(app(NhlGamePredictionResponseCache::class), $builder);
    $this->assertDatabaseCount('nhl_game_prediction_revisions', 2);
});

it('keeps explicit goalie overrides out of the latest canonical display', function (): void {
    ($this->publish)();
    ($this->publish)(['prediction' => ['predicted_score' => ['home' => 8.0]]], input: ['home_goalie_id' => 999]);
    ($this->index)()->assertOk()->assertJsonPath('games.0.revision.home_goals', 3.1);
    $this->assertDatabaseCount('nhl_game_prediction_revisions', 2);
});

it('keeps market-only requests canonical', function (): void {
    ($this->publish)(input: ['total' => 5.5]);
    ($this->index)()->assertOk()->assertJsonPath('games.0.revision.is_canonical', true);
});

it('retains successful predictions when a later calculation is unavailable', function (): void {
    $first = ($this->publish)();
    ($this->publish)(['prediction_available' => false, 'reason' => 'Missing lineup']);
    ($this->index)()->assertOk()->assertJsonPath('games.0.revision.id', $first['meta']['prediction_revision_id']);
    $this->assertDatabaseCount('nhl_game_prediction_revisions', 2);
});

it('stores exact evidence without rewriting earlier revisions', function (): void {
    $first = ($this->publish)();
    $row = DB::table('nhl_game_prediction_revisions')->find($first['meta']['prediction_revision_id']);
    $this->engine->update(['settings' => ['offense' => 200]]);
    ($this->publish)();
    expect(DB::table('nhl_game_prediction_revisions')->find($row->id)->evidence)->toBe($row->evidence);
    expect(json_decode($row->evidence, true)['stack']['members'][0]['engine']['settings']['offense'])->toBe(100);
});

it('does not publish a revision if configuration changes during a build', function (): void {
    try {
        app(NhlGamePredictionResponseCache::class)->respond(2026020001, ['nhl_game_id' => 2026020001], function (): array {
            $this->engine->update(['settings' => ['offense' => 200]]);
            return $this->forecast;
        });
        $this->fail('Expected changed-input rejection.');
    } catch (\Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException) {
        $this->assertDatabaseCount('nhl_game_prediction_revisions', 0);
    }
});

it('persists manual job output then reads it through the results endpoint', function (): void {
    $run = ($this->start)()->assertAccepted()->json('run_id');
    $item = DB::table('nhl_game_prediction_run_games')->where('run_id', $run)->first();
    $builder = Mockery::mock(NhlGamePredictionPayload::class);
    $builder->shouldReceive('build')->once()->with(2026020001, ['nhl_game_id' => 2026020001])->andReturn($this->forecast);
    (new RunNhlGamePredictionJob((int) $item->id))->handle($builder, app(NhlGamePredictionResponseCache::class), app(NhlGamePredictionHistory::class));
    $this->assertDatabaseHas('nhl_game_prediction_run_games', ['id' => $item->id, 'status' => 'completed']);
    $this->assertDatabaseHas('nhl_game_prediction_revisions', ['run_id' => $run, 'source' => 'admin']);
    ($this->index)()->assertOk()->assertJsonPath('games.0.revision.home_goals', 3.1)->assertJsonPath('run.status', 'completed');
});

it('makes retry delivery idempotent after a revision was committed', function (): void {
    $run = ($this->start)()->json('run_id');
    ($this->publish)(runId: $run);
    $item = DB::table('nhl_game_prediction_run_games')->where('run_id', $run)->first();
    $builder = Mockery::mock(NhlGamePredictionPayload::class);
    $builder->shouldNotReceive('build');
    (new RunNhlGamePredictionJob((int) $item->id))->handle($builder, app(NhlGamePredictionResponseCache::class), app(NhlGamePredictionHistory::class));
    $this->assertDatabaseCount('nhl_game_prediction_revisions', 1);
    $this->assertDatabaseHas('nhl_game_prediction_run_games', ['id' => $item->id, 'status' => 'completed']);
});

it('forces a manual revision even when an API cache response is fresh', function (): void {
    ($this->publish)();
    $run = ($this->start)()->json('run_id');
    ($this->publish)(runId: $run);
    $this->assertDatabaseCount('nhl_game_prediction_revisions', 2);
});

it('records exhausted job failures without deleting earlier success', function (): void {
    ($this->publish)();
    $run = ($this->start)()->json('run_id');
    $item = DB::table('nhl_game_prediction_run_games')->where('run_id', $run)->first();
    (new RunNhlGamePredictionJob((int) $item->id))->failed(new \RuntimeException('Timed out'));
    ($this->index)()->assertOk()->assertJsonPath('run.status', 'completed_with_errors')
        ->assertJsonPath('games.0.work.last_error', 'Timed out')->assertJsonPath('games.0.revision.home_goals', 3.1);
});

it('keeps a completed item unchanged if an old failed callback arrives', function (): void {
    $run = ($this->start)()->json('run_id');
    $revision = ($this->publish)(runId: $run)['meta']['prediction_revision_id'];
    $item = DB::table('nhl_game_prediction_run_games')->where('run_id', $run)->first();
    app(NhlGamePredictionHistory::class)->finish((int) $item->id, 'completed', $revision);
    (new RunNhlGamePredictionJob((int) $item->id))->failed(new \RuntimeException('Old failure'));
    $this->assertDatabaseHas('nhl_game_prediction_run_games', ['id' => $item->id, 'status' => 'completed', 'last_error' => null]);
});

it('lets super admins inspect immutable revision history and full evidence', function (): void {
    $result = ($this->publish)();
    $this->actingAs($this->admin)->getJson('/admin/game-predictions/games/2026020001/revisions')->assertOk()
        ->assertJsonPath('revisions.0.confidence', 67)->assertJsonPath('revisions.0.win_probability', .61);
    $this->getJson('/admin/game-predictions/revisions/'.$result['meta']['prediction_revision_id'])->assertOk()
        ->assertJsonPath('evidence.stack.name', 'Default')->assertJsonPath('payload.prediction.predicted_score.home', 3.1);
});

it('returns 404 for nonexistent revision details', function (): void {
    $this->actingAs($this->admin)->getJson('/admin/game-predictions/revisions/999')->assertNotFound();
});

it('paginates revisions newest first without sending full payloads', function (): void {
    foreach (range(1, 12) as $ignored) { ($this->publish)(); }
    $this->actingAs($this->admin)->getJson('/admin/game-predictions/games/2026020001/revisions')->assertOk()
        ->assertJsonCount(10, 'revisions')->assertJsonPath('total', 12)->assertJsonPath('last_page', 2)
        ->assertJsonMissingPath('revisions.0.payload');
});

it('reads final scores only when the game is final', function (): void {
    NhlGame::where('nhl_game_id', 2026020001)->update(['away_team_score' => 3, 'home_team_score' => 4]);
    ($this->index)()->assertJsonPath('games.0.actual_home', null);
    NhlGame::where('nhl_game_id', 2026020001)->update(['game_state' => 'OFF']);
    ($this->index)()->assertJsonPath('games.0.actual_home', 4);
});
