<?php

declare(strict_types=1);

use App\Jobs\PrepareNhlPregameContextRunJob;
use App\Models\NhlModelRun;
use App\Models\NhlPregameContextRun;
use App\Models\Role;
use App\Models\User;
use App\Services\NhlPregameContextBuilder;
use App\Services\NhlPregameContextOrchestrator;
use App\Services\NhlSatPregameWorkspace;
use App\Services\NhlSeasonGameEvaluation;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-26 16:00:00 UTC'));
    $this->withoutVite();
    $this->mock(\App\Services\NhlPregameWorkerActivity::class)->shouldReceive('snapshot')->andReturn([
        'available' => true, 'workers' => [], 'truncated' => false, 'observed_at' => '2026-11-26T16:00:00+00:00',
    ]);
    Bus::fake();
    Http::preventStrayRequests();
    config(['cache.default' => 'array', 'cache.stores.redis.driver' => 'array', 'nhl-pregame.workspace_model_name' => 'Sep2026', 'nhl-pregame.league_concurrency' => 4, 'nhl-pregame.context_concurrency' => 2]);
    Cache::flush();
    $this->mock(\App\Services\PlatformState::class)->shouldReceive('seeded')->andReturn(true);
    $this->mock(NhlSeasonGameEvaluation::class)->shouldReceive('readiness')->andReturn(['ready' => false, 'reasons' => ['Build profiles first.']]);
    $this->admin = User::factory()->create();
    $role = Role::firstOrCreate(['slug' => 'super-admin'], ['name' => 'Super Admin', 'level' => 99]);
    $this->admin->roles()->attach($role->id, ['organization_id' => null]);
    $this->pilot = NhlModelRun::create(['run_key' => 'workspace-pilot', 'name' => 'Sep2026', 'model_family' => 'sat',
        'workflow_stage' => 'training', 'model_version' => 'sat_v1', 'status' => 'complete',
        'train_season_ids' => ['20222023', '20232024', '20242025'], 'target_season_id' => '20252026',
        'metrics' => ['training_total_sog' => 100, 'training_attempts' => 90, 'training_excluded_sog' => 10, 'training_excluded_sog_rate' => 0.1]]);
    $this->production = NhlModelRun::create(['run_key' => 'workspace-production', 'name' => 'sat_v2', 'model_family' => 'sat',
        'workflow_stage' => 'training', 'model_version' => 'sat_v2', 'status' => 'complete', 'train_season_ids' => ['20252026'],
        'run_config' => ['projection_season_id' => '20262027'], 'metrics' => $this->pilot->metrics]);
    $this->build = fn (array $extra = []) => NhlPregameContextRun::create(['model_run_id' => $this->pilot->id,
        'action' => 'backfill', 'status' => 'running', 'season_ids' => ['20252026'], 'total_games' => 2,
        'options' => ['pipeline_version' => NhlPregameContextOrchestrator::CHECKPOINT_PIPELINE], ...$extra]);
    $this->read = fn () => $this->actingAs($this->admin)->withHeaders(['X-Inertia' => 'true',
        'X-Inertia-Partial-Component' => 'Admin/SatModels/Index', 'X-Inertia-Partial-Data' => 'pregame'])
        ->get('/admin/nhl-sat-models');
    $this->game = function (): void {
        DB::table('nhl_games')->insert(['nhl_game_id' => 2025020001, 'season_id' => '20252026', 'game_type' => 2,
            'game_date' => '2025-10-10', 'game_dow' => 'Fri', 'game_month' => 'Oct', 'game_state' => 'FINAL',
            'start_time_utc' => '2025-10-10 23:00:00', 'home_team_id' => 1, 'away_team_id' => 2]);
    };
});

afterEach(function (): void {
    Http::assertNothingSent();
    $this->travelBack();
});

it('blocks guests from every workspace endpoint', function (string $method, string $url): void {
    $this->json($method, $url, ['league' => 3, 'context' => 2])->assertUnauthorized();
})->with([['GET', '/admin/nhl-sat-models'], ['PUT', '/admin/nhl-sat-models/pregame/workers'], ['GET', '/admin/nhl-sat-models/pregame/inspect']]);

it('blocks ordinary members from every workspace endpoint', function (string $method, string $url): void {
    $this->actingAs(User::factory()->create())->json($method, $url, ['league' => 3, 'context' => 2])->assertForbidden();
})->with([['GET', '/admin/nhl-sat-models'], ['PUT', '/admin/nhl-sat-models/pregame/workers'], ['GET', '/admin/nhl-sat-models/pregame/inspect']]);

it('serves the shared Vue shell and structured model actions to super admins', function (): void {
    $this->actingAs($this->admin)->get('/admin/nhl-sat-models')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Admin/SatModels/Index')->has('models', 2)->has('shell')->missing('row_html'));
});

it('keeps the selected pilot independent of the newest production model', function (): void {
    ($this->read)()->assertOk()->assertJsonPath('props.pregame.source.id', $this->pilot->id);
});

it('does not select production if the pilot is missing', function (): void {
    $this->pilot->delete();
    ($this->read)()->assertOk()->assertJsonPath('props.pregame.source', null)->assertJsonPath('props.pregame.health', 'unavailable');
});

it('refuses an ambiguous historical pilot instead of guessing', function (): void {
    $copy = $this->pilot->replicate();
    $copy->run_key = 'ambiguous-pilot';
    $copy->save();
    expect(app(NhlSatPregameWorkspace::class)->pilot())->toBeNull();
});

it('does not accept a production-season model merely named Sep2026', function (): void {
    $this->pilot->update(['target_season_id' => null]);
    expect(app(NhlSatPregameWorkspace::class)->pilot())->toBeNull();
});

it('normalizes spacing and case in the explicitly configured pilot name', function (): void {
    $this->pilot->update(['name' => 'SEP 2026']);
    expect(app(NhlSatPregameWorkspace::class)->pilot()?->id)->toBe($this->pilot->id);
});

it('persists pilot limits through HTTP and exposes them through workspace reads', function (): void {
    $run = ($this->build)();
    $this->actingAs($this->admin)->putJson('/admin/nhl-sat-models/pregame/workers', ['league' => 5, 'context' => 3])->assertOk();
    expect(data_get($this->pilot->fresh()->run_config, 'pregame_workspace'))->toBe(['league' => 5, 'context' => 3]);
    expect(data_get($run->fresh()->options, 'workspace_concurrency'))->toBe(['league' => 5, 'context' => 3]);
    ($this->read)()->assertOk()->assertJsonPath('props.pregame.limits.league', 5)->assertJsonPath('props.pregame.limits.context', 3);
    Bus::assertNothingDispatched();
});

it('preserves production model state when pilot allocation is changed', function (): void {
    $before = $this->production->getRawOriginal();
    $this->actingAs($this->admin)->putJson('/admin/nhl-sat-models/pregame/workers', ['league' => 3, 'context' => 2])->assertOk();
    expect($this->production->fresh()->getRawOriginal())->toBe($before);
});

it('rejects missing worker limits', function (): void {
    $this->actingAs($this->admin)->putJson('/admin/nhl-sat-models/pregame/workers', [])->assertUnprocessable()->assertJsonValidationErrors(['league', 'context']);
});

it('rejects nonpositive and excessive worker limits', function (int $value): void {
    $this->actingAs($this->admin)->putJson('/admin/nhl-sat-models/pregame/workers', ['league' => $value, 'context' => 2])->assertUnprocessable();
})->with([0, -1, 26]);

it('rejects fractional and nonnumeric worker limits', function (mixed $value): void {
    $this->actingAs($this->admin)->putJson('/admin/nhl-sat-models/pregame/workers', ['league' => $value, 'context' => 2])->assertUnprocessable();
})->with([1.5, 'many']);

it('bounds combined claim concurrency', function (): void {
    $this->actingAs($this->admin)->putJson('/admin/nhl-sat-models/pregame/workers', ['league' => 20, 'context' => 10])->assertUnprocessable();
});

it('cannot allocate workers when the pilot is unavailable', function (): void {
    $this->pilot->delete();
    $this->actingAs($this->admin)->putJson('/admin/nhl-sat-models/pregame/workers', ['league' => 3, 'context' => 2])->assertConflict();
});

it('retains nonpilot default limits even if its metadata resembles pilot settings', function (): void {
    $this->production->update(['run_config' => ['pregame_workspace' => ['league' => 20, 'context' => 5]]]);
    expect(NhlSatPregameWorkspace::limits($this->production))->toBe(['league' => 4, 'context' => 2]);
});

it('carries pilot limits into future explicit maintenance runs on projections', function (): void {
    $this->pilot->update(['run_config' => ['pregame_workspace' => ['league' => 5, 'context' => 3]]]);
    $run = app(NhlPregameContextOrchestrator::class)->queueModelBackfill($this->pilot);
    expect(data_get($run->options, 'workspace_concurrency'))->toBe(['league' => 5, 'context' => 3]);
    Bus::assertDispatched(PrepareNhlPregameContextRunJob::class, fn ($job): bool => $job->runId === $run->id && $job->queue === 'projections');
});

it('does not add pilot options to production context runs', function (): void {
    $run = app(NhlPregameContextOrchestrator::class)->queueModelBackfill($this->production);
    expect($run->options)->not->toHaveKey('workspace_concurrency');
});

it('returns structured pregame action progress for Vue without rendering fragments', function (): void {
    $this->actingAs($this->admin)->withHeader('X-DIQ-SAT-Workspace', '1')
        ->postJson(route('admin.nhl-sat-models.context.build', $this->pilot))->assertAccepted()
        ->assertJsonPath('progress.status', 'queued')->assertJsonMissingPath('progress_html');
});

it('reads committed progress through HTTP after admitting a maintenance action', function (): void {
    $response = $this->actingAs($this->admin)->withHeader('X-DIQ-SAT-Workspace', '1')
        ->postJson(route('admin.nhl-sat-models.context.build', $this->pilot))->assertAccepted();
    $run = NhlPregameContextRun::findOrFail($response->json('progress.id'));
    $this->assertDatabaseHas('nhl_pregame_context_runs', ['id' => $run->id, 'model_run_id' => $this->pilot->id]);
    $run->update(['total_games' => 4]);
    foreach (['complete', 'blocked', 'failed', 'ready'] as $index => $state) {
        DB::table('nhl_pregame_context_run_games')->insert([
            'run_id' => $run->id, 'nhl_game_id' => 2025020001 + $index,
            'game_date' => '2025-10-10', 'sequence' => $index + 1, 'state' => $state,
        ]);
    }
    app(NhlPregameContextOrchestrator::class)->refreshRunCounts($run);
    $this->assertDatabaseHas('nhl_pregame_context_runs', ['id' => $run->id,
        'completed_games' => 1, 'blocked_games' => 1, 'failed_games' => 1]);
    ($this->read)()->assertOk()->assertJsonPath('props.pregame.run.progress_pct', 75)
        ->assertJsonPath('props.pregame.run.processed', 3)->assertJsonPath('props.pregame.run.total', 4)
        ->assertJsonPath('props.pregame.run.completed', 1)->assertJsonPath('props.pregame.run.excluded', 1)
        ->assertJsonPath('props.pregame.run.failed', 1);
});

it('returns structured model creation acknowledgement for Vue', function (): void {
    $response = $this->actingAs($this->admin)->withHeader('X-DIQ-SAT-Workspace', '1')->postJson('/admin/nhl-sat-models', [
        'name' => 'Another experiment', 'model_version' => 'v1', 'train_season_ids' => ['20242025'],
    ])->assertCreated()->assertJsonMissingPath('row_html');
    $this->assertDatabaseHas('nhl_model_runs', ['id' => $response->json('model_id'), 'name' => 'Another experiment']);
    $this->get('/admin/nhl-sat-models')->assertInertia(fn (Assert $page) => $page->where('models.0.name', 'Another experiment'));
});

it('never queues work while reading workspace health', function (): void {
    ($this->read)()->assertOk();
    Bus::assertNothingDispatched();
});

it('does not label saved team coverage as full player readiness', function (): void {
    ($this->game)();
    ($this->read)()->assertOk()->assertJsonPath('props.pregame.coverage.3.games', 1)
        ->assertJsonPath('props.pregame.coverage.3.covered', 0)->assertJsonPath('props.pregame.health', 'attention');
});

it('reports no claims while idle even when history exists', function (): void {
    ($this->build)(['status' => 'completed', 'completed_games' => 2]);
    ($this->read)()->assertOk()->assertJsonPath('props.pregame.workers', []);
});

it('reports expired claims without exposing claim tokens', function (): void {
    $run = ($this->build)();
    DB::table('nhl_pregame_context_run_days')->insert(['run_id' => $run->id, 'game_date' => '2025-10-10',
        'league_state' => 'processing', 'league_claim_expires_at' => now()->subMinute(), 'league_claim_token' => '00000000-0000-4000-8000-000000000001']);
    ($this->read)()->assertOk()->assertJsonPath('props.pregame.workers.0.state', 'expired')
        ->assertJsonMissingPath('props.pregame.workers.0.claim_token');
});

it('validates inspector inputs without starting maintenance', function (): void {
    $this->actingAs($this->admin)->getJson('/admin/nhl-sat-models/pregame/inspect?game_id=no&entity=roster')->assertUnprocessable();
    Bus::assertNothingDispatched();
});

it('returns empty inspector evidence instead of borrowing production snapshots', function (): void {
    $this->actingAs($this->admin)->getJson('/admin/nhl-sat-models/pregame/inspect?game_id=2026020001&entity=team')
        ->assertOk()->assertJsonPath('contexts', [])->assertJsonPath('metrics', [])->assertJsonPath('source_model_id', $this->pilot->id);
});

it('returns only bounded current-version pilot metric rows', function (): void {
    ($this->game)();
    $context = DB::table('nhl_team_game_pregame_contexts')->insertGetId(['model_run_id' => $this->pilot->id,
        'nhl_game_id' => 2025020001, 'nhl_team_id' => 1, 'opponent_team_id' => 2, 'game_date' => '2025-10-10',
        'source_cutoff_at' => '2025-10-10 23:00:00', 'venue' => 'home', 'context_version' => NhlPregameContextBuilder::VERSION, 'metrics' => '{}']);
    DB::table('nhl_team_game_pregame_context_metrics')->insert(['team_context_id' => $context,
        'metric_key' => 'sat', 'window_key' => 'last_10', 'strength' => 'EV', 'measure_key' => 'per_60', 'value' => 59.2]);
    $this->actingAs($this->admin)->getJson('/admin/nhl-sat-models/pregame/inspect?game_id=2025020001&entity=team')
        ->assertOk()->assertJsonCount(1, 'contexts')->assertJsonCount(1, 'metrics')->assertJsonPath('metrics.0.metric_key', 'sat');
});

it('keeps a finished league stage completed when context maintenance has failed', function (): void {
    $run = ($this->build)(['status' => 'failed', 'last_error' => 'Context source missing.']);
    DB::table('nhl_pregame_context_run_days')->insert(['run_id' => $run->id, 'game_date' => '2025-10-10',
        'league_state' => 'complete', 'context_state' => 'failed']);
    ($this->read)()->assertOk()->assertJsonPath('props.pregame.workstreams.3.state', 'completed')
        ->assertJsonPath('props.pregame.workstreams.0.state', 'failed');
});

it('ignores a supplied production identity on pilot allocation requests', function (): void {
    $before = $this->production->getRawOriginal();
    $this->actingAs($this->admin)->putJson('/admin/nhl-sat-models/pregame/workers', [
        'model_id' => $this->production->id, 'league' => 6, 'context' => 2,
    ])->assertOk();
    expect($this->production->fresh()->getRawOriginal())->toBe($before)
        ->and(data_get($this->pilot->fresh()->run_config, 'pregame_workspace.league'))->toBe(6);
});


it('reports real registry summary counts without manufacturing performance metrics', function (): void {
    $this->actingAs($this->admin)->get('/admin/nhl-sat-models')->assertInertia(fn (Assert $page) => $page
        ->where('headerSummary.total', 2)->where('headerSummary.research', 1)->where('headerSummary.production', 1)
        ->where('headerSummary.best_test_correlation', null)->where('headerSummary.average_lift', null));
});

it('uses actual completed Test dates for the coverage strip', function (): void {
    ($this->game)();
    ($this->read)()->assertOk()->assertJsonPath('props.pregame.dates.0.date', '2025-10-10')
        ->assertJsonPath('props.pregame.dates.0.games', 1)->assertJsonPath('props.pregame.dates.0.covered', 0);
});


it('measures handled work separately from successful context updates', function (): void {
    ($this->build)(['total_games' => 10, 'completed_games' => 3, 'blocked_games' => 1, 'failed_games' => 1]);
    ($this->read)()->assertOk()->assertJsonPath('props.pregame.run.processed', 5)
        ->assertJsonPath('props.pregame.run.progress_pct', 50)->assertJsonPath('props.pregame.run.completed', 3)
        ->assertJsonPath('props.pregame.run.excluded', 1)->assertJsonPath('props.pregame.run.failed', 1);
});

it('keeps selection progress unknown until the denominator exists', function (): void {
    ($this->build)(['status' => 'queued', 'total_games' => 0]);
    ($this->read)()->assertOk()->assertJsonPath('props.pregame.run.progress_pct', null);
});

it('shows a completed empty selection as processed without claiming updated games', function (): void {
    ($this->build)(['status' => 'completed', 'total_games' => 0]);
    ($this->read)()->assertOk()->assertJsonPath('props.pregame.run.progress_pct', 100)
        ->assertJsonPath('props.pregame.run.completed', 0);
});

it('does not turn failed processed work into a successful run', function (): void {
    ($this->build)(['status' => 'failed', 'total_games' => 2, 'completed_games' => 1, 'failed_games' => 1]);
    ($this->read)()->assertOk()->assertJsonPath('props.pregame.run.progress_pct', 100)
        ->assertJsonPath('props.pregame.run.status', 'failed')->assertJsonPath('props.pregame.health', 'attention');
});

it('exposes actual queue observations independently of durable claimed dates', function (): void {
    $this->mock(\App\Services\NhlPregameWorkerActivity::class)->shouldReceive('snapshot')->andReturn([
        'available' => true, 'workers' => [['pid' => 123, 'task' => 'Preparing game selection', 'state' => 'running']],
        'truncated' => false, 'observed_at' => '2026-11-26T16:00:00+00:00',
    ]);
    ($this->read)()->assertOk()->assertJsonPath('props.pregame.worker_activity.workers.0.pid', 123)
        ->assertJsonPath('props.pregame.workers', []);
});

it('publishes run timestamps and phase counts alongside the progress bar inputs', function (): void {
    $run = ($this->build)();
    DB::table('nhl_pregame_context_run_days')->insert(['run_id' => $run->id, 'game_date' => '2025-10-10',
        'league_state' => 'complete', 'context_state' => 'pending']);
    ($this->read)()->assertOk()->assertJsonPath('props.pregame.run.stages.league', 1)
        ->assertJsonPath('props.pregame.run.stages.context', 0)->assertJsonPath('props.pregame.run.stages.total', 1)
        ->assertJsonPath('props.pregame.run.created_at', '2026-11-26T16:00:00+00:00');
});
