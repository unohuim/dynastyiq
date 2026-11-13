<?php

declare(strict_types=1);

use App\Events\NhlGameImportStatusUpdated;
use App\Events\NhlSeasonStatsSynced;
use App\Jobs\RefreshNhlGoalieProjectionsJob;
use App\Listeners\QueueNhlGoalieProjectionRefresh;
use App\Models\NhlGameImportRun;
use App\Models\NhlModelRun;
use App\Models\Player;
use App\Models\Role;
use App\Models\User;
use App\Services\NhlGoalieProjectionRefresh;
use App\Services\NhlPregameContextBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-11-13 12:00:00 UTC'));
    config(['cache.default' => 'array', 'nhl-custom-prediction.default_model_name' => 'sat_v2']);
    Cache::flush();
    Bus::fake();
    Event::fake([NhlGameImportStatusUpdated::class]);
    Http::preventStrayRequests();
    $this->model = NhlModelRun::create(['run_key' => 'sat-goalie-test', 'name' => 'sat_v2', 'model_family' => 'sat',
        'workflow_stage' => 'training', 'model_version' => 'fixture', 'status' => 'complete',
        'train_season_ids' => ['20232024', '20242025', '20252026']]);
    $this->bucketModel = DB::table('nhl_expected_goals_models')->insertGetId(['name' => 'SAT fixture', 'version' => 'v1']);
    $this->profile = function (int $id = 901, string $key = 'B', string $strength = 'all', array $values = []): int {
        return DB::table('nhl_sat_model_entity_profile_buckets')->insertGetId(array_replace([
            'model_run_id' => $this->model->id, 'sat_expected_goals_model_id' => $this->bucketModel,
            'source_season_ids' => json_encode($this->model->train_season_ids), 'game_type' => 2,
            'profile_type' => 'goalie_faced', 'strength' => $strength, 'entity_key' => 'goalie_faced:'.$id,
            'entity_id' => $id, 'matched_bucket_key' => $key, 'fallback_level' => 1, 'bucket_dimensions' => '{}',
            'source_sat' => 80, 'source_sog' => 40, 'source_goals' => 4, 'expected_goals' => 5,
            'source_toi_seconds' => 14400, 'confidence_score' => 0.8,
        ], $values));
    };
    $this->refresh = app(NhlGoalieProjectionRefresh::class);
    $this->mock(NhlPregameContextBuilder::class)->shouldReceive('evaluationBucketFilter')
        ->andReturn(['keys' => ['B']])->byDefault();
    $this->run = fn (): NhlGameImportRun => NhlGameImportRun::create([
        'action' => 'season-sync', 'mode' => 'season', 'status' => 'running',
        'start_date' => '2026-09-01', 'end_date' => '2027-08-31', 'date_count' => 1, 'queued_jobs' => 1,
        'payload' => ['season' => '20262027', 'aggregation_completed_at' => now()->toIso8601String(),
            'stats_cache' => ['status' => 'ready']],
    ]);
    $this->context = ['target' => '20262027', 'model_id' => $this->model->id, 'version' => 'ratings-fixture', 'run_id' => null];
});

afterEach(function (): void {
    Http::assertNothingSent();
    $this->travelBack();
});

it('listens to the existing season event after commit', function (): void {
    Event::assertListening(NhlSeasonStatsSynced::class, QueueNhlGoalieProjectionRefresh::class);
    expect(new QueueNhlGoalieProjectionRefresh())->toBeInstanceOf(\Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit::class);
});

it('queues the production SAT model on projections without an admin run', function (): void {
    (new QueueNhlGoalieProjectionRefresh())->handle(new NhlSeasonStatsSynced('20262027', 2));
    Bus::assertDispatched(RefreshNhlGoalieProjectionsJob::class, fn ($job): bool =>
        $job->context['model_id'] === $this->model->id && $job->context['run_id'] === null
        && $job->queue === 'projections' && $job->afterCommit && $job->stage === 'ratings');
});

it('does not substitute September testing models for the named production model', function (): void {
    $this->model->update(['name' => 'sep2026']);
    $run = ($this->run)();
    $this->refresh->queueSeason('20262027', $run->id);
    Bus::assertNothingDispatched();
    expect(data_get($run->refresh()->payload, 'goalie_refresh.status'))->toBe('skipped');
});

it('does not apply later training history to earlier seasons', function (): void {
    $this->refresh->queueSeason('20242025');
    Bus::assertNothingDispatched();
});

it('coalesces duplicate deliveries for the same admin sync', function (): void {
    $run = ($this->run)();
    $this->refresh->queueSeason('20262027', $run->id);
    $this->refresh->queueSeason('20262027', $run->id);
    Bus::assertDispatchedTimes(RefreshNhlGoalieProjectionsJob::class, 1);
    expect($run->refresh()->status)->toBe('running')->and(data_get($run->payload, 'goalie_refresh.status'))->toBe('queued');
});

it('calculates GSAx per sixty from pooled totals and one exposure denominator', function (): void {
    ($this->profile)();
    ($this->profile)(901, 'C', 'all', ['expected_goals' => 7, 'source_goals' => 6]);
    $rating = $this->refresh->snapshot($this->model, ['B', 'C'])['ratings'][901];
    expect($rating['source_toi_seconds'])->toBe(14400)->and($rating['source_xga'])->toBe(12.0)
        ->and($rating['source_goals'])->toBe(10)->and($rating['projected_xga_per_game'])->toBe(3.0)
        ->and($rating['projected_ga_per_game'])->toBe(2.5)->and($rating['projected_gsax_per_game'])->toBe(0.5);
});

it('uses only retained model buckets and avoids double counting strength splits', function (): void {
    ($this->profile)();
    ($this->profile)(901, 'C', 'all', ['expected_goals' => 200]);
    ($this->profile)(901, 'B', 'ev', ['expected_goals' => 200]);
    expect($this->refresh->snapshot($this->model, ['B'])['ratings'][901]['projected_gsax_per_game'])->toBe(0.25);
});

it('keeps model histories isolated when explicitly calculating testing-model ratings', function (): void {
    ($this->profile)();
    $other = NhlModelRun::create(['run_key' => 'testing-goalie', 'name' => 'sep2026', 'model_family' => 'sat',
        'status' => 'complete', 'train_season_ids' => ['20222023', '20232024', '20242025']]);
    ($this->profile)(901, 'B', 'all', ['model_run_id' => $other->id, 'expected_goals' => 8]);
    expect($this->refresh->snapshot($this->model, ['B'])['ratings'][901]['projected_gsax_per_game'])->toBe(0.25)
        ->and($this->refresh->snapshot($other, ['B'])['ratings'][901]['projected_gsax_per_game'])->toBe(1.0);
});

it('keeps signed and genuinely zero goalie ratings available', function (float $xga, float $expected): void {
    ($this->profile)(901, 'B', 'all', ['expected_goals' => $xga]);
    $goalie = $this->refresh->goalie($this->model, 901, 'VAN', ['B']);
    expect($goalie['projected_gsax_per_game'])->toBe($expected)->and($goalie['projection_source'])->toBe('goalie_model');
})->with([[0.0, -1.0], [4.0, 0.0], [5.0, 0.25]]);

it('marks unusable personal evidence unavailable instead of inventing zero skill', function (array $values): void {
    ($this->profile)(901, 'B', 'all', $values);
    $goalie = $this->refresh->goalie($this->model, 901, 'VAN', ['B']);
    expect($goalie['projection_source'])->toBe('unavailable')->and($goalie['projected_gsax_per_game'])->toBeNull();
})->with([[['source_toi_seconds' => 0]], [['source_toi_seconds' => null]], [['source_sog' => 0]], [['source_sat' => 0]]]);

it('does not combine bucket rates with inconsistent exposure', function (): void {
    ($this->profile)();
    ($this->profile)(901, 'C', 'all', ['source_toi_seconds' => 7200]);
    expect($this->refresh->snapshot($this->model, ['B', 'C'])['ratings'])->toBe([]);
});

it('retains the selected goalie and current team independently of stored historical team context', function (): void {
    ($this->profile)(901, 'B', 'all', ['team_context' => 'OTT']);
    Player::create(['nhl_id' => 901, 'full_name' => 'Selected Starter', 'team_abbrev' => 'VAN', 'position' => 'G']);
    $goalie = $this->refresh->goalie($this->model, 901, 'VAN', ['B']);
    expect($goalie['nhl_player_id'])->toBe(901)->and($goalie['name'])->toBe('Selected Starter')
        ->and($goalie['team_abbrev'])->toBe('VAN')->and($goalie['sat_model_run_id'])->toBe($this->model->id)
        ->and($goalie['selection_source'])->toBe('prediction_starter')->and($goalie['comparison_minutes'])->toBe(60);
});

it('keeps missing starters and missing personal profiles explicitly unavailable', function (?int $id): void {
    ($this->profile)();
    $goalie = $this->refresh->goalie($this->model, $id, 'VAN', ['B']);
    expect($goalie['nhl_player_id'])->toBe($id)->and($goalie['projection_source'])->toBe('unavailable')
        ->and($goalie['projection_fallback_reason'])->toBe('missing_sat_goalie_profile');
})->with([null, 999]);

it('reuses prepared ratings until the profile publication changes', function (): void {
    $id = ($this->profile)();
    $first = $this->refresh->snapshot($this->model, ['B']);
    DB::table('nhl_sat_model_entity_profile_buckets')->where('id', $id)->update(['expected_goals' => 8]);
    expect($this->refresh->snapshot($this->model, ['B']))->toBe($first);
    $this->model->update(['metrics' => ['profiles_completed_at' => '2026-11-13T12:01:00Z']]);
    expect($this->refresh->snapshot($this->model, ['B'])['ratings'][901]['projected_gsax_per_game'])->toBe(1.0);
});

it('forces fresh preparation during season sync without rebuilding profiles', function (): void {
    $id = ($this->profile)();
    $this->refresh->snapshot($this->model, ['B']);
    DB::table('nhl_sat_model_entity_profile_buckets')->where('id', $id)->update(['expected_goals' => 8]);
    expect($this->refresh->start($this->context))->toBeTrue()
        ->and($this->refresh->snapshot($this->model, ['B'])['ratings'][901]['projected_gsax_per_game'])->toBe(1.0);
    Bus::assertNothingBatched();
    Bus::assertNothingDispatched();
    $this->assertDatabaseCount('nhl_goalie_workload_projections', 0);
    $this->assertDatabaseCount('nhl_goalie_season_projections', 0);
    $this->assertDatabaseCount('nhl_goalie_chance_profile_buckets', 0);
});

it('does not publish partial ratings during an active SAT profile build', function (): void {
    ($this->profile)();
    $this->model->update(['status' => 'running']);
    expect($this->refresh->snapshot($this->model, ['B']))->toBeNull()->and($this->refresh->start($this->context))->toBeFalse();
});

it('retains a prior snapshot while the same SAT model is rebuilding', function (): void {
    ($this->profile)();
    $saved = $this->refresh->snapshot($this->model, ['B']);
    $this->model->update(['status' => 'running']);
    expect($this->refresh->snapshot($this->model, ['B']))->toBe($saved);
});

it('defers worker preparation when another snapshot publisher holds the lock', function (): void {
    ($this->profile)();
    $key = 'nhl:sat-goalie-ratings:v1:'.$this->model->id.':'.hash('sha256', json_encode(['B']));
    Cache::lock($key.':build', 120, 'other-owner')->get();
    expect($this->refresh->start($this->context))->toBeFalse();
});

it('reports a wholly missing SAT goalie build without requiring legacy profiles', function (): void {
    expect(fn () => $this->refresh->start($this->context))->toThrow(RuntimeException::class, 'Build Goalies');
});

it('does not let a missing unprofiled prospect block the modeled goalie snapshot', function (): void {
    ($this->profile)();
    Player::create(['nhl_id' => 999, 'full_name' => 'No History', 'position' => 'G', 'team_abbrev' => 'VAN']);
    expect($this->refresh->start($this->context))->toBeTrue()
        ->and($this->refresh->goalie($this->model, 999, 'VAN', ['B'])['projection_source'])->toBe('unavailable');
});

it('redirects old serialized workload and performance stages to SAT ratings', function (string $stage): void {
    ($this->profile)();
    $old = ['target' => '20262027', 'version' => 'sync-goalie-old', 'run_id' => null, 'source' => '20252026'];
    (new RefreshNhlGoalieProjectionsJob($old, $stage))->handle($this->refresh);
    Bus::assertNothingBatched();
    $this->assertDatabaseCount('nhl_goalie_season_projections', 0);
    expect($this->refresh->goalie($this->model, 901, 'VAN', ['B'])['projected_gsax_per_game'])->toBe(0.25);
})->with(['workload', 'performance']);

it('redirects old completion callbacks without publishing legacy rows', function (): void {
    $this->refresh->publish(['target' => '20262027', 'version' => 'sync-goalie-old']);
    Bus::assertDispatched(RefreshNhlGoalieProjectionsJob::class, fn ($job): bool => $job->stage === 'ratings');
    $this->assertDatabaseCount('nhl_goalie_season_projections', 0);
});

it('keeps old automatic projection drafts excluded from remaining legacy reads', function (): void {
    DB::table('nhl_goalie_season_projections')->insert(['projection_version' => 'sync-goalie-old',
        'source_season_id' => '20252026', 'target_season_id' => '20262027', 'goalie_player_id' => 901, 'status' => 'draft']);
    expect(NhlGoalieProjectionRefresh::usable(DB::table('nhl_goalie_season_projections as projections'))->count())->toBe(0);
});

it('reports readiness only after successful rating publication and other sync work', function (): void {
    ($this->profile)();
    $run = ($this->run)();
    $this->refresh->start([...$this->context, 'run_id' => $run->id]);
    expect($run->refresh()->status)->toBe('completed')
        ->and(data_get($run->payload, 'goalie_refresh.model_run_id'))->toBe($this->model->id)
        ->and(data_get($run->payload, 'goalie_refresh.status'))->toBe('ready');
});

it('preserves failure and cache state across repeated error callbacks', function (): void {
    $run = ($this->run)();
    $context = [...$this->context, 'run_id' => $run->id];
    $this->refresh->fail($context, 'Original error');
    $this->refresh->fail($context, 'Later error');
    expect($run->refresh()->status)->toBe('failed')->and($run->last_error)->toBe('Original error')
        ->and(data_get($run->payload, 'stats_cache.status'))->toBe('ready');
});

it('waits for both cache and goalie preparation before completing season sync', function (string $cache, string $goalies, string $expected): void {
    expect(NhlGoalieProjectionRefresh::runStatus(['aggregation_completed_at' => '2026-11-13',
        'stats_cache' => ['status' => $cache], 'goalie_refresh' => ['status' => $goalies]]))->toBe($expected);
})->with([['ready', 'queued', 'running'], ['building', 'ready', 'running'], ['ready', 'ready', 'completed'],
    ['ready', 'failed', 'failed'], ['failed', 'ready', 'failed'], ['ready', 'skipped', 'completed']]);

it('exposes a sync-triggered rating preparation failure through the authorized status endpoint', function (): void {
    $user = User::factory()->create();
    $role = Role::create(['name' => 'Super Admin', 'slug' => 'super-admin', 'level' => 99]);
    $user->roles()->attach($role->id, ['organization_id' => null]);
    $id = $this->actingAs($user)->postJson(route('admin.nhl-game-imports.season-sync'), ['season' => '20262027'])
        ->assertAccepted()->json('run.id');
    (new QueueNhlGoalieProjectionRefresh())->handle(new NhlSeasonStatsSynced('20262027', 1, $id));
    (new RefreshNhlGoalieProjectionsJob([...$this->context, 'run_id' => $id]))->handle($this->refresh);
    $this->assertDatabaseHas('nhl_game_import_runs', ['id' => $id, 'status' => 'failed']);
    $this->getJson(route('admin.nhl-game-imports.status'))->assertOk()
        ->assertJsonPath('runs.0.payload.goalie_refresh.status', 'failed')
        ->assertJsonPath('runs.0.status', 'failed');
});

it('retains guest and ordinary-user restrictions on sync and progress', function (bool $signedIn): void {
    if ($signedIn) {
        $this->actingAs(User::factory()->create());
    }
    $this->postJson(route('admin.nhl-game-imports.season-sync'), ['season' => '20262027'])->assertStatus($signedIn ? 403 : 401);
    $this->getJson(route('admin.nhl-game-imports.status'))->assertStatus($signedIn ? 403 : 401);
    Bus::assertNothingDispatched();
})->with([false, true]);
