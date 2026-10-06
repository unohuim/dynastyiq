<?php

declare(strict_types=1);

use App\Events\NhlSatModelUpdated;
use App\Jobs\BuildNhlSatModelEntityProfilesJob;
use App\Jobs\BuildNhlSatModelEntityProfileForEntityJob;
use App\Jobs\BuildNhlSatModelEntityRateComparisonForEntityJob;
use App\Jobs\BuildNhlSatModelEntityRateComparisonsJob;
use App\Jobs\BuildNhlSatModelEntityRateProjectionsJob;
use App\Jobs\BuildNhlSatModelEntityToiProjectionsJob;
use App\Jobs\LoadNhlSatModelProfileBatchJob;
use App\Models\NhlExpectedGoalsModel;
use App\Models\NhlModelRun;
use App\Models\Role;
use App\Models\User;
use App\Services\NhlSatModelEntityProfileBuilder;
use App\Services\NhlSatModelEntityRateComparisonBuilder;
use App\Services\NhlSatModelPredictionService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->travelTo(\Illuminate\Support\Carbon::parse('2026-09-29 12:00:00 UTC'));
    Http::preventStrayRequests();
    $this->realBus = Bus::getFacadeRoot();
    Bus::fake();
    Event::fake([NhlSatModelUpdated::class]);
    $this->mock(\App\Services\PlatformState::class)->shouldReceive('seeded')->andReturn(true);
    $this->admin = User::factory()->create();
    $role = Role::query()->create(['name' => 'Super Admin', 'slug' => 'super-admin', 'level' => 99]);
    $this->admin->roles()->attach($role->id, ['organization_id' => null]);
    $this->run = NhlModelRun::query()->create([
        'run_key' => 'build-predictions', 'name' => 'Build predictions', 'model_family' => 'sat',
        'workflow_stage' => 'training', 'model_version' => 'test', 'status' => 'complete',
        'train_season_ids' => ['20232024', '20242025'], 'target_season_id' => '20252026',
    ]);
    $this->sat = NhlExpectedGoalsModel::query()->create([
        'model_run_id' => $this->run->id, 'name' => 'SAT', 'version' => 'test__run_' . $this->run->id,
        'prediction_target' => 'shot_on_goal',
    ]);
    $this->profileRow = [
        'model_run_id' => $this->run->id, 'sat_expected_goals_model_id' => $this->sat->id,
        'source_season_ids' => '["20232024"]', 'profile_type' => 'skater_offense',
        'entity_key' => 'skater_offense:101', 'matched_bucket_key' => 'L01|unknown',
        'fallback_level' => 1, 'bucket_dimensions' => '{}',
    ];
    $this->pageBuilder = function (array $keys = ['skater_offense:101']): mixed {
        $builder = Mockery::mock(NhlSatModelEntityProfileBuilder::class);
        $builder->shouldReceive('clearProfileOutputs')->once();
        $builder->shouldReceive('profilePartitions')->once()->andReturn([
            ['profile_type' => 'skater_offense', 'season_id' => null],
        ]);
        $builder->shouldReceive('profileEntityPage')->once()->andReturn($keys);

        return $builder;
    };
    $this->readyMetrics = [
        'profile_build' => ['id' => 'profiles-1', 'status' => 'complete', 'loading_complete' => true],
        'profiles_started_at' => '2026-09-29T10:00:00+00:00',
        'profiles_completed_at' => '2026-09-29T11:00:00+00:00',
        'profile_entities_queued' => 1, 'profile_entities_completed' => 1,
        'season_snapshot_entities_queued' => 2, 'season_snapshot_entities_completed' => 2,
    ];
    $this->url = route('admin.nhl-sat-models.predictions.build', $this->run);
    $this->activate = function (string $stage = 'profiles'): void {
        $this->run->update(['status' => 'running', 'completed_at' => null,
            'metrics' => ['prediction_build' => ['id' => 'build-1', 'status' => 'running', 'stage' => $stage]]]);
        // Execute commit callbacks deterministically inside the isolated test transaction.
        DB::partialMock()->shouldReceive('afterCommit')->andReturnUsing(fn (Closure $callback) => $callback());
    };
});

afterEach(function (): void {
    Http::assertNothingSent();
    $this->travelBack();
});

it('blocks guests from starting prediction builds', function (): void {
    $this->postJson($this->url)->assertUnauthorized();
    Bus::assertNothingDispatched();
});

it('blocks ordinary users from starting prediction builds', function (): void {
    $this->actingAs(User::factory()->create())->postJson($this->url)->assertForbidden();
    Bus::assertNothingDispatched();
});

it('starts profiles on the same model and exposes the running stage through HTTP', function (): void {
    $response = $this->actingAs($this->admin)->postJson($this->url)->assertOk();
    expect($response->json('row_html'))->toContain('Build Predictions', 'Profiles', '0/0');
    $this->assertDatabaseHas('nhl_model_runs', ['id' => $this->run->id, 'status' => 'running']);
    $this->assertDatabaseCount('nhl_model_runs', 1);
    Bus::assertDispatched(BuildNhlSatModelEntityProfilesJob::class, fn ($job) =>
        $job->modelRunId === $this->run->id && $job->satModelId === $this->sat->id && $job->predictionBuildId !== null);
    Bus::assertNotDispatched(BuildNhlSatModelEntityRateProjectionsJob::class);
    Bus::assertNotDispatched(BuildNhlSatModelEntityToiProjectionsJob::class);
    $this->get(route('admin.nhl-sat-models.index'))->assertOk()->assertSee('Build Predictions')->assertSee('Profiles');
});

it('rejects duplicate prediction requests', function (): void {
    $this->actingAs($this->admin)->postJson($this->url)->assertOk();
    $this->postJson($this->url)->assertUnprocessable();
    Bus::assertDispatchedTimes(BuildNhlSatModelEntityProfilesJob::class, 1);
});

it('rejects a model with work already running', function (): void {
    $this->run->update(['status' => 'running']);
    $this->actingAs($this->admin)->postJson($this->url)->assertUnprocessable();
    Bus::assertNothingDispatched();
});

it('requires evaluated SAT inputs', function (): void {
    $this->sat->delete();
    $this->actingAs($this->admin)->postJson($this->url)->assertUnprocessable();
    expect($this->run->fresh()->status)->toBe('complete');
    Bus::assertNothingDispatched();
});

it('requires training seasons', function (): void {
    $this->run->update(['train_season_ids' => []]);
    $this->actingAs($this->admin)->postJson($this->url)->assertUnprocessable();
    Bus::assertNothingDispatched();
});

it('rejects models outside the SAT workflow', function (): void {
    $this->run->update(['model_family' => 'other']);
    $this->actingAs($this->admin)->postJson($this->url)->assertNotFound();
    Bus::assertNothingDispatched();
});

it('returns not found for a missing model', function (): void {
    $this->actingAs($this->admin)->postJson('/admin/nhl-sat-models/999999/predictions/build')->assertNotFound();
});

it('supports the normal form redirect', function (): void {
    $this->actingAs($this->admin)->post($this->url)->assertRedirect(route('admin.nhl-sat-models.index'));
    Bus::assertDispatched(BuildNhlSatModelEntityProfilesJob::class);
});

it('advances successful profiles to rates without completing the run', function (): void {
    ($this->activate)();
    NhlModelRun::finishPredictionStage($this->run->id, 'build-1', 'profiles', false, ['profile_rows' => ['total' => 2]]);
    $run = $this->run->fresh();
    expect($run->status)->toBe('running')->and($run->completed_at)->toBeNull()
        ->and(data_get($run->metrics, 'prediction_build.stage'))->toBe('rates');
    Bus::assertDispatched(BuildNhlSatModelEntityRateProjectionsJob::class, fn ($job) => $job->predictionBuildId === 'build-1');
    Bus::assertNotDispatched(BuildNhlSatModelEntityToiProjectionsJob::class);
});

it('advances successful rates to TOI', function (): void {
    ($this->activate)('rates');
    NhlModelRun::finishPredictionStage($this->run->id, 'build-1', 'rates', false);
    expect(data_get($this->run->fresh()->metrics, 'prediction_build.stage'))->toBe('toi');
    Bus::assertDispatched(BuildNhlSatModelEntityToiProjectionsJob::class);
});

it('completes only after TOI succeeds', function (): void {
    ($this->activate)('toi');
    NhlModelRun::finishPredictionStage($this->run->id, 'build-1', 'toi', false);
    $run = $this->run->fresh();
    expect($run->status)->toBe('complete')->and($run->completed_at)->not->toBeNull()
        ->and(data_get($run->metrics, 'prediction_build.status'))->toBe('complete');
    Bus::assertNothingDispatched();
});

it('stops on profile batch failure', function (): void {
    ($this->activate)();
    NhlModelRun::finishPredictionStage($this->run->id, 'build-1', 'profiles', true);
    expect($this->run->fresh()->status)->toBe('failed');
    Bus::assertNothingDispatched();
});

it('stops on rate batch failure and keeps the failed stage', function (): void {
    ($this->activate)('rates');
    NhlModelRun::finishPredictionStage($this->run->id, 'build-1', 'rates', true);
    expect(data_get($this->run->fresh()->metrics, 'prediction_build.stage'))->toBe('rates');
    Bus::assertNothingDispatched();
});

it('records TOI failure instead of successful completion', function (): void {
    ($this->activate)('toi');
    NhlModelRun::finishPredictionStage($this->run->id, 'build-1', 'toi', true, error: 'TOI failed');
    expect(data_get($this->run->fresh()->metrics, 'prediction_build.error'))->toBe('TOI failed')
        ->and($this->run->fresh()->status)->toBe('failed');
});

it('ignores duplicate batch completion callbacks', function (): void {
    ($this->activate)();
    NhlModelRun::finishPredictionStage($this->run->id, 'build-1', 'profiles', false);
    NhlModelRun::finishPredictionStage($this->run->id, 'build-1', 'profiles', false);
    Bus::assertDispatchedTimes(BuildNhlSatModelEntityRateProjectionsJob::class, 1);
});

it('ignores callbacks from an older prediction build', function (): void {
    ($this->activate)();
    NhlModelRun::finishPredictionStage($this->run->id, 'older-build', 'profiles', true);
    expect($this->run->fresh()->status)->toBe('running');
    Bus::assertNothingDispatched();
});

it('does not advance an out of order stage', function (): void {
    ($this->activate)();
    NhlModelRun::finishPredictionStage($this->run->id, 'build-1', 'rates', false);
    expect(data_get($this->run->fresh()->metrics, 'prediction_build.stage'))->toBe('profiles');
    Bus::assertNothingDispatched();
});

it('records a parent job failure in the combined build', function (): void {
    ($this->activate)('rates');
    (new BuildNhlSatModelEntityRateProjectionsJob($this->run->id, 'build-1'))->failed(new RuntimeException('Parent failed'));
    expect($this->run->fresh()->status)->toBe('failed')
        ->and(data_get($this->run->fresh()->metrics, 'prediction_build.error'))->toBe('Parent failed');
    Bus::assertNothingDispatched();
});

it('does not start rates merely because the profiles parent has dispatched its batch', function (): void {
    ($this->activate)();
    $builder = Mockery::mock(NhlSatModelEntityProfileBuilder::class);
    $builder->shouldNotReceive('prepareBuild');
    $builder->shouldNotReceive('prepareSeasonSnapshotBuilds');
    (new BuildNhlSatModelEntityProfilesJob($this->run->id, $this->sat->id, null, 'build-1'))->handle($builder);
    Bus::assertBatchCount(1);
    Bus::assertBatched(fn (\Illuminate\Bus\PendingBatch $batch) =>
        $batch->jobs->count() === 1 && $batch->jobs->first() instanceof LoadNhlSatModelProfileBatchJob);
    Bus::assertNotDispatched(BuildNhlSatModelEntityRateProjectionsJob::class);
    expect(data_get($this->run->fresh()->metrics, 'prediction_build.stage'))->toBe('profiles');
});

it('blocks competing manual profile and evaluation actions during the combined build', function (): void {
    ($this->activate)();
    $this->actingAs($this->admin)->postJson(route('admin.nhl-sat-models.profiles.build', $this->run))->assertUnprocessable();
    $this->postJson(route('admin.nhl-sat-models.train', $this->run), ['evaluation' => 'sat'])->assertUnprocessable();
    Bus::assertNothingDispatched();
});

it('treats a cancelled batch as failure before advancing', function (): void {
    ($this->activate)();
    $builder = Mockery::mock(NhlSatModelEntityProfileBuilder::class);
    $builder->shouldReceive('prepareBuild')->andReturn([
        ['profile_type' => 'skater_offense', 'entity_key' => 'skater_offense:101'],
    ]);
    $builder->shouldReceive('prepareSeasonSnapshotBuilds')->andReturn([]);
    $this->mock(\App\Services\NhlSatModelGenericBucketStabilityBuilder::class)
        ->shouldReceive('build')->andReturn(['total' => 0]);
    (new BuildNhlSatModelEntityProfilesJob($this->run->id, $this->sat->id, null, 'build-1'))->handle($builder);
    Bus::assertBatched(function (\Illuminate\Bus\PendingBatch $pending): bool {
        $batch = Mockery::mock(\Illuminate\Bus\Batch::class);
        $batch->failedJobs = 0;
        $batch->shouldReceive('cancelled')->andReturn(true);
        ($pending->options['finally'][0])($batch);
        return true;
    });
    expect($this->run->fresh()->status)->toBe('failed');
    Bus::assertNotDispatched(BuildNhlSatModelEntityRateProjectionsJob::class);
});

it('keeps standalone rate builds outside the combined workflow', function (): void {
    $job = new BuildNhlSatModelEntityRateProjectionsJob($this->run->id);
    $finish = new ReflectionMethod($job, 'markFinishedForRun');
    $finish->invoke(null, $this->run->id, false);
    expect($this->run->fresh()->status)->toBe('complete')
        ->and(data_get($this->run->fresh()->metrics, 'prediction_build'))->toBeNull();
    Bus::assertNotDispatched(BuildNhlSatModelEntityToiProjectionsJob::class);
});

it('exposes persisted current stage counts in refreshed row HTML', function (): void {
    $this->actingAs($this->admin)->postJson($this->url)->assertOk();
    $metrics = $this->run->fresh()->metrics;
    $this->run->update(['metrics' => [...$metrics,
        'profile_entities_queued' => 20, 'profile_entities_completed' => 7,
        'season_snapshot_entities_queued' => 30, 'season_snapshot_entities_completed' => 10,
    ]]);
    $this->get(route('admin.nhl-sat-models.index'))->assertOk()->assertSee('17/50')->assertSee('Profiles');
    $payload = (new NhlSatModelUpdated($this->run->id, 'predictions-progress'))->broadcastWith();
    expect($payload['row_html'])->toContain('17/50', 'aria-live="polite"');
});

it('marks an empty profile batch as failed instead of building from old outputs', function (): void {
    ($this->activate)();
    $builder = Mockery::mock(NhlSatModelEntityProfileBuilder::class);
    $builder = ($this->pageBuilder)([]);
    [$loader, $batch] = (new LoadNhlSatModelProfileBatchJob($this->run->id, $this->sat->id, null, 'build-1'))->withFakeBatch();
    try {
        $loader->handle($builder);
        $this->fail('Empty combined builds must fail.');
    } catch (\RuntimeException $exception) {
        expect($exception->getMessage())->toContain('No training profile entities are available.');
    }
    expect($this->run->fresh()->status)->toBe('failed');
    expect($batch->cancelled())->toBeTrue();
    Bus::assertNothingDispatched();
});

it('blocks guests from viewing prediction build progress', function (): void {
    $this->getJson(route('admin.nhl-sat-models.index'))->assertUnauthorized();
});

it('blocks ordinary users from viewing prediction build progress', function (): void {
    $this->actingAs(User::factory()->create())->getJson(route('admin.nhl-sat-models.index'))->assertForbidden();
});

it('shows the failed stage and escapes its error in the model row', function (): void {
    ($this->activate)('rates');
    NhlModelRun::finishPredictionStage($this->run->id, 'build-1', 'rates', true, error: '<script>failed</script>');
    $response = $this->actingAs($this->admin)->get(route('admin.nhl-sat-models.index'))->assertOk();
    $response->assertSee('Build Predictions failed')->assertSee('/60')
        ->assertSee('&lt;script&gt;failed&lt;/script&gt;', false)->assertDontSee('<script>failed</script>', false);
});

it('does not execute a parent stage from a stale build', function (): void {
    ($this->activate)('rates');
    $builder = Mockery::mock(\App\Services\NhlSatModelEntityRateProjectionBuilder::class);
    $builder->shouldNotReceive('prepareBuild');
    (new BuildNhlSatModelEntityRateProjectionsJob($this->run->id, 'old-build'))->handle($builder);
    Bus::assertNothingDispatched();
    expect($this->run->fresh()->status)->toBe('running');
});


it('queues a full page in bounded inserts and preserves the pending continuation', function (): void {
    ($this->activate)();
    config(['queue.default' => 'database', 'queue.connections.database.connection' => config('database.default'),
        'queue.batching.database' => config('database.default')]);
    Bus::swap($this->realBus);
    $bindings = [];
    DB::listen(function (\Illuminate\Database\Events\QueryExecuted $query) use (&$bindings): void {
        if (preg_match('/^insert into ["`]?jobs["`]?\s/i', $query->sql) === 1) {
            $bindings[] = count($query->bindings);
        }
    });
    $keys = array_map(fn (int $id): string => 'skater_offense:' . $id, range(100, 199));
    $builder = ($this->pageBuilder)($keys);
    (new BuildNhlSatModelEntityProfilesJob($this->run->id, $this->sat->id, null, 'build-1'))->handle($builder);
    $batchId = DB::table('job_batches')->sole()->id;
    (new LoadNhlSatModelProfileBatchJob($this->run->id, $this->sat->id, null, 'build-1'))
        ->withBatchId($batchId)->handle($builder);
    expect(max($bindings))->toBeLessThanOrEqual(600)
        ->and(DB::table('jobs')->count())->toBe(102)
        ->and(Bus::findBatch($batchId)->pendingJobs)->toBe(102)
        ->and(data_get($this->run->fresh()->metrics, 'profile_build.loading_complete'))->toBeNull()
        ->and(data_get($this->run->fresh()->metrics, 'profile_entities_queued'))->toBe(100);
    $payloads = DB::table('jobs')->pluck('payload')->map(fn (string $payload): array => json_decode($payload, true));
    expect($payloads->where('displayName', LoadNhlSatModelProfileBatchJob::class))->toHaveCount(2);
});

it('continues with the same cursor and then moves to the next season partition', function (): void {
    ($this->activate)();
    $builder = Mockery::mock(NhlSatModelEntityProfileBuilder::class);
    $builder->shouldReceive('clearProfileOutputs')->once();
    $builder->shouldReceive('profilePartitions')->once()->andReturn([
        ['profile_type' => 'skater_offense', 'season_id' => null],
        ['profile_type' => 'skater_offense', 'season_id' => '20242025'],
    ]);
    $keys = array_map(fn (int $id): string => 'skater_offense:' . $id, range(100, 199));
    $builder->shouldReceive('profileEntityPage')->with(Mockery::type(NhlModelRun::class), 'skater_offense', null, null)->once()->andReturn($keys);
    $builder->shouldReceive('profileEntityPage')->with(Mockery::type(NhlModelRun::class), 'skater_offense', null, 'skater_offense:199')->once()->andReturn([]);
    $builder->shouldReceive('profileEntityPage')->with(Mockery::type(NhlModelRun::class), 'skater_offense', '20242025', null)->once()->andReturn(['skater_offense:100']);
    [$first, $batch] = (new LoadNhlSatModelProfileBatchJob($this->run->id, $this->sat->id, null, 'build-1'))->withFakeBatch();
    $first->handle($builder);
    $next = collect($batch->added)->flatten(1)->first();
    expect($next)->toBeInstanceOf(LoadNhlSatModelProfileBatchJob::class)
        ->and($next->after)->toBe('skater_offense:199')->and($next->partition)->toBe(0);
    [$next, $nextBatch] = $next->withFakeBatch();
    $next->handle($builder);
    $snapshot = collect($nextBatch->added)->flatten(1)->sole();
    expect($snapshot->partition)->toBe(1)->and($snapshot->after)->toBeNull();
    [$snapshot, $snapshotBatch] = $snapshot->withFakeBatch();
    $snapshot->handle($builder);
    $child = collect($snapshotBatch->added)->flatten(1)->sole();
    expect($child->snapshotSeasonId)->toBe('20242025')->and($child->profileType)->toBe('skater_offense')
        ->and($child->predictionBuildId)->toBe('build-1')
        ->and(data_get($this->run->fresh()->metrics, 'profile_build.loading_complete'))->toBeTrue()
        ->and(data_get($this->run->fresh()->metrics, 'season_snapshot_entities_queued'))->toBe(1);
});

it('does not clear or enqueue a profile page twice', function (): void {
    ($this->activate)();
    $builder = ($this->pageBuilder)();
    [$loader, $batch] = (new LoadNhlSatModelProfileBatchJob($this->run->id, $this->sat->id, null, 'build-1'))->withFakeBatch();
    $loader->handle($builder);
    $loader->handle($builder);
    expect(collect($batch->added)->flatten(1))->toHaveCount(1)
        ->and(data_get($this->run->fresh()->metrics, 'profile_entities_queued'))->toBe(1);
});

it('does not dispatch a second batch when a profile parent is redelivered', function (): void {
    ($this->activate)();
    $builder = Mockery::mock(NhlSatModelEntityProfileBuilder::class);
    $job = new BuildNhlSatModelEntityProfilesJob($this->run->id, $this->sat->id, null, 'build-1');
    $job->handle($builder);
    $job->handle($builder);
    Bus::assertBatchCount(1);
});

it('does not prepare or queue work for a cancelled profile loader', function (): void {
    ($this->activate)();
    [$loader, $batch] = (new LoadNhlSatModelProfileBatchJob($this->run->id, $this->sat->id, null, 'build-1'))->withFakeBatch();
    $batch->cancel();
    $loader->handle(Mockery::mock(NhlSatModelEntityProfileBuilder::class));
    expect($batch->added)->toBeEmpty();
});

it('does not prepare or queue work for a stale profile loader', function (): void {
    ($this->activate)();
    [$loader, $batch] = (new LoadNhlSatModelProfileBatchJob($this->run->id, $this->sat->id, null, 'older'))->withFakeBatch();
    $loader->handle(Mockery::mock(NhlSatModelEntityProfileBuilder::class));
    expect($batch->added)->toBeEmpty()->and($this->run->fresh()->status)->toBe('running');
});

it('cancels partial loading and retains only bounded SQL diagnostics', function (): void {
    ($this->activate)();
    $builder = ($this->pageBuilder)(array_map(fn (int $id): string => 'skater_offense:' . $id, range(100, 199)));
    $batch = Mockery::mock(\Illuminate\Bus\Batch::class);
    $batch->shouldReceive('cancelled')->andReturn(false);
    $batch->shouldReceive('add')->once()->with(Mockery::on(fn (array $jobs): bool => count($jobs) === 100));
    $batch->shouldReceive('add')->once()->with(Mockery::on(fn (array $jobs): bool => count($jobs) === 1))
        ->andThrow(new \Illuminate\Database\QueryException('pgsql', 'insert into jobs values (?)', ['private-payload'],
            new PDOException('parameter limit exceeded')));
    $batch->shouldReceive('cancel')->once();
    $loader = Mockery::mock(LoadNhlSatModelProfileBatchJob::class, [$this->run->id, $this->sat->id, null, 'build-1'])->makePartial();
    $loader->shouldReceive('batch')->andReturn($batch);
    try {
        $loader->handle($builder);
        $this->fail('Queue failure must reach the worker.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Profile loading failed: parameter limit exceeded')
            ->and($exception->getPrevious())->toBeNull();
    }
    expect($this->run->fresh()->status)->toBe('failed');
    $this->actingAs($this->admin)->get(route('admin.nhl-sat-models.index'))->assertOk()
        ->assertSee('parameter limit exceeded')->assertDontSee('private-payload');
});

it('does not turn an incomplete snapshot batch into successful profiles', function (): void {
    ($this->activate)();
    $metrics = $this->run->fresh()->metrics;
    $this->run->update(['metrics' => [...$metrics, ...$this->readyMetrics,
        'profile_build' => ['id' => 'build-1', 'status' => 'running', 'loading_complete' => true],
        'season_snapshot_entities_completed' => 1,
    ]]);
    $finish = new ReflectionMethod(BuildNhlSatModelEntityProfilesJob::class, 'markFinishedForRun');
    $finish->invoke(null, $this->run->id, false, 'build-1');
    expect($this->run->fresh()->status)->toBe('failed')
        ->and(data_get($this->run->fresh()->metrics, 'profiles_completed_at'))->toBeNull();
    Bus::assertNothingDispatched();
});

it('requires discovery to finish even when all discovered children have finished', function (): void {
    ($this->activate)();
    $this->run->update(['metrics' => [...$this->run->fresh()->metrics, ...$this->readyMetrics,
        'profile_build' => ['id' => 'build-1', 'status' => 'running', 'loading_complete' => false],
    ]]);
    (new ReflectionMethod(BuildNhlSatModelEntityProfilesJob::class, 'markFinishedForRun'))
        ->invoke(null, $this->run->id, false, 'build-1');
    expect($this->run->fresh()->status)->toBe('failed');
    Bus::assertNothingDispatched();
});

it('keeps the batch pending until both the loader and its entities succeed', function (): void {
    ($this->activate)();
    config(['queue.default' => 'database', 'queue.connections.database.connection' => config('database.default'),
        'queue.batching.database' => config('database.default')]);
    Bus::swap($this->realBus);
    $builder = ($this->pageBuilder)();
    $builder->shouldReceive('buildEntity')->once()->andReturn(1);
    $this->mock(\App\Services\NhlSatModelGenericBucketStabilityBuilder::class)
        ->shouldReceive('build')->once()->andReturn(['total' => 1]);
    (new BuildNhlSatModelEntityProfilesJob($this->run->id, $this->sat->id, null, 'build-1'))->handle($builder);
    $batchId = DB::table('job_batches')->sole()->id;
    (new LoadNhlSatModelProfileBatchJob($this->run->id, $this->sat->id, null, 'build-1'))->withBatchId($batchId)->handle($builder);
    (new BuildNhlSatModelEntityProfileForEntityJob($this->run->id, $this->sat->id, null, 'skater_offense', 'skater_offense:101', null, 'build-1'))
        ->withBatchId($batchId)->handle($builder);
    $batch = Bus::findBatch($batchId);
    Bus::fake();
    $batch->recordSuccessfulJob('child');
    expect($batch->fresh()->pendingJobs)->toBe(1);
    Bus::assertNothingDispatched();
    $batch->recordSuccessfulJob('loader');
    expect(data_get($this->run->fresh()->metrics, 'profile_build.status'))->toBe('complete')
        ->and(data_get($this->run->fresh()->metrics, 'prediction_build.stage'))->toBe('rates');
    Bus::assertDispatchedTimes(BuildNhlSatModelEntityRateProjectionsJob::class, 1);
});

it('commits profile rows and counts once when an entity is redelivered', function (): void {
    ($this->activate)();
    $builder = Mockery::mock(NhlSatModelEntityProfileBuilder::class);
    $builder->shouldReceive('buildEntity')->once()->andReturnUsing(function (): int {
        DB::table('nhl_sat_model_entity_profile_buckets')->insert($this->profileRow);
        return 1;
    });
    $job = new BuildNhlSatModelEntityProfileForEntityJob($this->run->id, $this->sat->id, null, 'skater_offense', 'skater_offense:101', null, 'build-1');
    $job->handle($builder);
    $job->handle($builder);
    expect(DB::table('nhl_sat_model_entity_profile_buckets')->count())->toBe(1)
        ->and(data_get($this->run->fresh()->metrics, 'profile_entities_completed'))->toBe(1);
});

it('commits a bounded offensive-skater profile page with one progress update', function (): void {
    ($this->activate)();
    $builder = Mockery::mock(NhlSatModelEntityProfileBuilder::class);
    $builder->shouldReceive('buildEntity')->twice()->andReturn(1);

    (new BuildNhlSatModelEntityProfileForEntityJob(
        $this->run->id,
        $this->sat->id,
        null,
        'skater_offense',
        ['skater_offense:101', 'skater_offense:102'],
        null,
        'build-1'
    ))->handle($builder);

    expect(data_get($this->run->fresh()->metrics, 'profile_entities_completed'))->toBe(2);
});

it('rolls back an entity write when its builder fails before progress is committed', function (): void {
    ($this->activate)();
    $builder = Mockery::mock(NhlSatModelEntityProfileBuilder::class);
    $builder->shouldReceive('buildEntity')->once()->andReturnUsing(function (): int {
        DB::table('nhl_sat_model_entity_profile_buckets')->insert($this->profileRow);
        throw new RuntimeException('entity SQL failed');
    });
    $job = new BuildNhlSatModelEntityProfileForEntityJob($this->run->id, $this->sat->id, null, 'skater_offense', 'skater_offense:101', null, 'build-1');
    expect(fn () => $job->handle($builder))->toThrow(RuntimeException::class, 'entity SQL failed');
    expect(DB::table('nhl_sat_model_entity_profile_buckets')->count())->toBe(0)
        ->and(data_get($this->run->fresh()->metrics, 'profile_entities_completed', 0))->toBe(0);
});

it('ignores entity jobs from superseded builds', function (): void {
    ($this->activate)();
    $builder = Mockery::mock(NhlSatModelEntityProfileBuilder::class);
    (new BuildNhlSatModelEntityProfileForEntityJob($this->run->id, $this->sat->id, null, 'skater_offense', 'skater_offense:101', null, 'older'))->handle($builder);
    expect(data_get($this->run->fresh()->metrics, 'profile_entities_completed', 0))->toBe(0);
});

it('does not allow legacy unidentified jobs to overwrite a current profile build', function (): void {
    ($this->activate)();
    $builder = Mockery::mock(NhlSatModelEntityProfileBuilder::class);
    $job = new BuildNhlSatModelEntityProfilesJob($this->run->id, $this->sat->id);
    $job->handle($builder);
    $job->failed(new RuntimeException('old failure'));
    (new BuildNhlSatModelEntityProfileForEntityJob($this->run->id, $this->sat->id, null, 'skater_offense', 'skater_offense:101'))->handle($builder);
    expect($this->run->fresh()->status)->toBe('running');
    Bus::assertBatchCount(0);
});

it('starts standalone profiles with a build identity and visible progress', function (): void {
    $response = $this->actingAs($this->admin)->postJson(route('admin.nhl-sat-models.profiles.build', $this->run))->assertOk();
    $id = data_get($this->run->fresh()->metrics, 'profile_build.id');
    expect($id)->not->toBeNull()->and($response->json('row_html'))->toContain('Profiles', 'Discovering entities', '0/0');
    Bus::assertDispatched(BuildNhlSatModelEntityProfilesJob::class, fn ($job): bool => $job->predictionBuildId === $id);
    Bus::assertNotDispatched(BuildNhlSatModelEntityRateProjectionsJob::class);
});

it('queues goalie SAT profiles independently from offensive skater profiles', function (): void {
    $response = $this->actingAs($this->admin)->postJson(route('admin.nhl-sat-models.profiles.build', $this->run), [
        'profile_type' => 'goalie_faced',
    ])->assertOk();

    expect($response->json('message'))->toBe('Goalie profiles queued.')
        ->and($response->json('row_html'))->toContain('Profiles', 'Build Goalies');
    Bus::assertDispatched(BuildNhlSatModelEntityProfilesJob::class, fn ($job): bool => $job->predictionBuildId !== null
        && $job->profileTypes === ['goalie_faced']);
});

it('clears only the selected SAT profile type before rebuilding it', function (): void {
    DB::table('nhl_sat_model_entity_profile_buckets')->insert([
        $this->profileRow,
        [...$this->profileRow, 'profile_type' => 'goalie_faced', 'entity_key' => 'goalie_faced:201'],
    ]);

    app(NhlSatModelEntityProfileBuilder::class)->clearProfileOutputs($this->run, ['goalie_faced']);

    $this->assertDatabaseHas('nhl_sat_model_entity_profile_buckets', [
        'model_run_id' => $this->run->id, 'profile_type' => 'skater_offense', 'entity_key' => 'skater_offense:101',
    ]);
    $this->assertDatabaseMissing('nhl_sat_model_entity_profile_buckets', [
        'model_run_id' => $this->run->id, 'profile_type' => 'goalie_faced', 'entity_key' => 'goalie_faced:201',
    ]);
});

it('finishes standalone profiles without automatically starting rates', function (): void {
    $this->run->update(['status' => 'running', 'metrics' => [
        ...$this->readyMetrics, 'profile_build' => ['id' => 'profiles-1', 'status' => 'running'],
    ]]);
    NhlModelRun::finishPredictionStage($this->run->id, 'profiles-1', 'profiles', false);
    expect($this->run->fresh()->status)->toBe('complete')->and($this->run->fresh()->profilesReadyForRates())->toBeTrue();
    Bus::assertNothingDispatched();
});

it('preserves the first failure when a completion callback follows it', function (): void {
    ($this->activate)();
    $job = new BuildNhlSatModelEntityProfilesJob($this->run->id, $this->sat->id, null, 'build-1');
    $job->failed(new RuntimeException('first failure'));
    $job->failed(new RuntimeException('second failure'));
    NhlModelRun::finishPredictionStage($this->run->id, 'build-1', 'profiles', false);
    expect($this->run->fresh()->status)->toBe('failed')
        ->and(data_get($this->run->fresh()->metrics, 'prediction_build.error'))->toBe('first failure')
        ->and(data_get($this->run->fresh()->metrics, 'profiles_completed_at'))->toBeNull();
});

it('bounds long profile failure messages', function (): void {
    ($this->activate)();
    (new BuildNhlSatModelEntityProfilesJob($this->run->id, $this->sat->id, null, 'build-1'))->failed(new RuntimeException(str_repeat('x', 5000)));
    expect(data_get($this->run->fresh()->metrics, 'prediction_build.error'))->toHaveLength(1000);
});

it('rejects rates when rows exist but profile snapshots are unfinished', function (): void {
    DB::table('nhl_sat_model_entity_profile_buckets')->insert($this->profileRow);
    $this->run->update(['status' => 'failed', 'metrics' => [...$this->readyMetrics, 'season_snapshot_entities_completed' => 1]]);
    $this->actingAs($this->admin)->postJson(route('admin.nhl-sat-models.rate-projections.build', $this->run))->assertUnprocessable();
    Bus::assertNothingDispatched();
});

it('rejects rates from legacy completion timestamps without a successful build certificate', function (): void {
    DB::table('nhl_sat_model_entity_profile_buckets')->insert($this->profileRow);
    $this->run->update(['metrics' => [...$this->readyMetrics, 'profile_build' => null]]);
    $this->actingAs($this->admin)->postJson(route('admin.nhl-sat-models.rate-projections.build', $this->run))->assertUnprocessable();
    Bus::assertNothingDispatched();
});

it('accepts rates after verified profile and snapshot completion', function (): void {
    DB::table('nhl_sat_model_entity_profile_buckets')->insert($this->profileRow);
    $this->run->update(['metrics' => $this->readyMetrics]);
    $this->actingAs($this->admin)->postJson(route('admin.nhl-sat-models.rate-projections.build', $this->run))->assertOk();
    Bus::assertDispatched(BuildNhlSatModelEntityRateProjectionsJob::class,
        fn ($job): bool => $job->profileTypes === ['skater_offense']);
    Bus::assertNotDispatched(BuildNhlSatModelEntityToiProjectionsJob::class);
});

it('rejects goalie /60 because goalie skill remains a profile-only metric', function (): void {
    DB::table('nhl_sat_model_entity_profile_buckets')->insert([
        ...$this->profileRow,
        'profile_type' => 'goalie_faced',
        'entity_key' => 'goalie_faced:201',
    ]);
    $this->run->update(['metrics' => $this->readyMetrics]);

    $response = $this->actingAs($this->admin)->postJson(
        route('admin.nhl-sat-models.rate-projections.build', $this->run),
        ['profile_type' => 'goalie_faced']
    )->assertUnprocessable();

    Bus::assertNothingDispatched();
});

it('compares goalie training GSAx per 100 xGA directly to test-season profiles', function (): void {
    DB::table('nhl_sat_model_entity_profile_buckets')->insert([
        ...$this->profileRow,
        'profile_type' => 'goalie_faced',
        'entity_key' => 'goalie_faced:201',
        'matched_bucket_key' => 'L01|goalie_faced',
        'source_sat' => 20,
        'source_sog' => 12,
        'source_goals' => 2,
        'expected_goals' => 3,
        'source_toi_seconds' => 3600,
        'source_gsax' => 1,
        'source_gsax_per_60' => 1,
        'source_gsax_per_100_xga' => 33.3333,
    ]);
    DB::table('nhl_sat_model_entity_test_profile_buckets')->insert([
        ...$this->profileRow,
        'profile_type' => 'goalie_faced',
        'entity_key' => 'goalie_faced:201',
        'matched_bucket_key' => 'L01|goalie_faced',
        'test_season_id' => $this->run->target_season_id,
        'source_sat' => 18,
        'source_sog' => 11,
        'source_goals' => 3,
        'expected_goals' => 2.5,
        'source_toi_seconds' => 3600,
        'source_gsax' => -0.5,
        'source_gsax_per_60' => -0.5,
    ]);

    $builder = app(NhlSatModelEntityRateComparisonBuilder::class);
    expect($builder->prepareBuild($this->run, ['goalie_faced']))->toBe([[
        'profile_type' => 'goalie_faced', 'entity_key' => 'goalie_faced:201',
    ]]);
    $builder->buildEntity($this->run, 'goalie_faced', 'goalie_faced:201');

    $this->assertDatabaseHas('nhl_sat_model_entity_rate_comparison_aggregates', [
        'model_run_id' => $this->run->id,
        'profile_type' => 'goalie_faced',
        'entity_key' => 'goalie_faced:201',
        'train_gsax_per_60' => 1,
        'test_gsax_per_60' => -0.5,
        'gsax_drift' => -1.5,
        'train_gsax_xga' => 3,
        'test_gsax_xga' => 2.5,
        'train_gsax_per_100_xga' => 33.3333,
        'test_gsax_per_100_xga' => -20,
        'gsax_per_100_xga_drift' => -53.3333,
    ]);
});

it('leaves goalie GSAx per 100 xGA null when a profile has no expected goals', function (): void {
    DB::table('nhl_sat_model_entity_profile_buckets')->insert([
        ...$this->profileRow,
        'profile_type' => 'goalie_faced',
        'entity_key' => 'goalie_faced:202',
        'matched_bucket_key' => 'L01|goalie_faced',
        'expected_goals' => 0,
        'source_gsax' => 0,
    ]);
    DB::table('nhl_sat_model_entity_test_profile_buckets')->insert([
        ...$this->profileRow,
        'profile_type' => 'goalie_faced',
        'entity_key' => 'goalie_faced:202',
        'matched_bucket_key' => 'L01|goalie_faced',
        'test_season_id' => $this->run->target_season_id,
        'expected_goals' => 0,
        'source_gsax' => 0,
    ]);

    app(NhlSatModelEntityRateComparisonBuilder::class)->buildEntity($this->run, 'goalie_faced', 'goalie_faced:202');

    $this->assertDatabaseHas('nhl_sat_model_entity_rate_comparison_aggregates', [
        'model_run_id' => $this->run->id,
        'profile_type' => 'goalie_faced',
        'entity_key' => 'goalie_faced:202',
        'train_gsax_per_100_xga' => null,
        'test_gsax_per_100_xga' => null,
    ]);
});

it('compares goalie EV and PP profile metrics without mixing their records', function (): void {
    DB::table('nhl_sat_model_entity_profile_buckets')->insert([
        [
            ...$this->profileRow,
            'profile_type' => 'goalie_faced',
            'strength' => 'ev',
            'entity_key' => 'goalie_faced:203',
            'matched_bucket_key' => 'L01|goalie_faced',
            'source_sog' => 20,
            'source_goals' => 2,
            'expected_goals' => 3,
            'source_gsax' => 1,
            'source_gsax_per_100_xga' => 33.3333,
        ],
        [
            ...$this->profileRow,
            'profile_type' => 'goalie_faced',
            'strength' => 'pp',
            'entity_key' => 'goalie_faced:203',
            'matched_bucket_key' => 'L01|goalie_faced',
            'source_sog' => 10,
            'source_goals' => 2,
            'expected_goals' => 2.5,
            'source_gsax' => 0.5,
            'source_gsax_per_100_xga' => 20,
        ],
    ]);
    DB::table('nhl_sat_model_entity_test_profile_buckets')->insert([
        [
            ...$this->profileRow,
            'profile_type' => 'goalie_faced',
            'strength' => 'ev',
            'entity_key' => 'goalie_faced:203',
            'matched_bucket_key' => 'L01|goalie_faced',
            'test_season_id' => $this->run->target_season_id,
            'source_sog' => 20,
            'source_goals' => 1,
            'expected_goals' => 2,
            'source_gsax' => 1,
            'source_gsax_per_100_xga' => 50,
        ],
        [
            ...$this->profileRow,
            'profile_type' => 'goalie_faced',
            'strength' => 'pp',
            'entity_key' => 'goalie_faced:203',
            'matched_bucket_key' => 'L01|goalie_faced',
            'test_season_id' => $this->run->target_season_id,
            'source_sog' => 10,
            'source_goals' => 3,
            'expected_goals' => 2.5,
            'source_gsax' => -0.5,
            'source_gsax_per_100_xga' => -20,
        ],
    ]);

    app(NhlSatModelEntityRateComparisonBuilder::class)->buildEntity($this->run, 'goalie_faced', 'goalie_faced:203');

    $this->assertDatabaseHas('nhl_sat_model_entity_rate_comparison_aggregates', [
        'model_run_id' => $this->run->id,
        'entity_key' => 'goalie_faced:203',
        'strength' => 'ev',
        'train_save_percentage' => 90,
        'test_save_percentage' => 95,
        'save_percentage_drift' => 5,
    ]);
    $this->assertDatabaseHas('nhl_sat_model_entity_rate_comparison_aggregates', [
        'model_run_id' => $this->run->id,
        'entity_key' => 'goalie_faced:203',
        'strength' => 'pp',
        'train_save_percentage' => 80,
        'test_save_percentage' => 70,
        'save_percentage_drift' => -10,
    ]);
});

it('completes Compare /60 when the final successful entity records progress', function (): void {
    $this->run->update([
        'status' => 'running',
        'metrics' => [
            'rate_comparison_entities_queued' => 2,
            'rate_comparison_entities_completed' => 0,
        ],
    ]);
    $builder = Mockery::mock(NhlSatModelEntityRateComparisonBuilder::class);
    $builder->shouldReceive('buildEntity')->twice()->andReturn(0);

    (new BuildNhlSatModelEntityRateComparisonForEntityJob(
        $this->run->id,
        'skater_offense',
        'skater_offense:101'
    ))->handle($builder);

    expect($this->run->fresh()->status)->toBe('running')
        ->and(data_get($this->run->fresh()->metrics, 'rate_comparison_entities_completed'))->toBe(1);

    (new BuildNhlSatModelEntityRateComparisonForEntityJob(
        $this->run->id,
        'skater_offense',
        'skater_offense:102'
    ))->handle($builder);

    $run = $this->run->fresh();
    expect($run->status)->toBe('complete')->and($run->completed_at)->not->toBeNull()
        ->and(data_get($run->metrics, 'rate_comparison_entities_completed'))->toBe(2)
        ->and(data_get($run->metrics, 'rate_comparison_rows.total'))->toBe(0)
        ->and(data_get($run->metrics, 'rate_comparison_aggregate_rows.total'))->toBe(0);
    Event::assertDispatched(NhlSatModelUpdated::class, fn (NhlSatModelUpdated $event): bool =>
        $event->modelId === $this->run->id && $event->reason === 'rate-comparisons-completed');
});

it('does not let a late comparison batch callback overwrite completed state', function (): void {
    $this->run->update([
        'status' => 'running',
        'metrics' => [
            'rate_comparison_entities_queued' => 1,
            'rate_comparison_entities_completed' => 1,
        ],
    ]);

    BuildNhlSatModelEntityRateComparisonsJob::markCompleteWhenAllEntitiesProcessed($this->run->id);
    $completedAt = $this->run->fresh()->completed_at;
    $job = new BuildNhlSatModelEntityRateComparisonsJob($this->run->id);
    $finish = new ReflectionMethod($job, 'markFinishedForRun');
    $finish->invoke(null, $this->run->id, false);

    expect($this->run->fresh()->status)->toBe('complete')
        ->and($this->run->fresh()->completed_at?->toIso8601String())->toBe($completedAt?->toIso8601String());
    Event::assertDispatchedTimes(NhlSatModelUpdated::class, 1);
});

it('does not use a dispatch-time unique lock for Compare /60', function (): void {
    expect(new BuildNhlSatModelEntityRateComparisonsJob($this->run->id))
        ->not->toBeInstanceOf(\Illuminate\Contracts\Queue\ShouldBeUnique::class);
});

it('requires new profiles when evaluation was rerun after their build', function (): void {
    DB::table('nhl_sat_model_entity_profile_buckets')->insert($this->profileRow);
    $this->run->update(['metrics' => [...$this->readyMetrics, 'eval_sat_completed_at' => '2026-09-29T11:30:00+00:00']]);
    $this->actingAs($this->admin)->postJson(route('admin.nhl-sat-models.rate-projections.build', $this->run))->assertUnprocessable();
    Bus::assertNothingDispatched();
});

it('blocks guests from standalone profile builds', function (): void {
    $this->postJson(route('admin.nhl-sat-models.profiles.build', $this->run))->assertUnauthorized();
    Bus::assertNothingDispatched();
});

it('blocks ordinary users from standalone profile builds', function (): void {
    $this->actingAs(User::factory()->create())->postJson(route('admin.nhl-sat-models.profiles.build', $this->run))->assertForbidden();
    Bus::assertNothingDispatched();
});

it('blocks guests from rate builds', function (): void {
    $this->postJson(route('admin.nhl-sat-models.rate-projections.build', $this->run))->assertUnauthorized();
    Bus::assertNothingDispatched();
});

it('blocks ordinary users from rate builds', function (): void {
    $this->actingAs(User::factory()->create())->postJson(route('admin.nhl-sat-models.rate-projections.build', $this->run))->assertForbidden();
    Bus::assertNothingDispatched();
});

it('keeps queue retry reservations above the maximum supported job timeout', function (): void {
    $queue = require base_path('config/queue.php');
    $horizon = require base_path('config/horizon.php');
    foreach (['database', 'redis', 'beanstalkd'] as $connection) {
        expect($queue['connections'][$connection]['retry_after'])->toBeGreaterThanOrEqual(3900);
    }
    expect($horizon['defaults']['supervisor-default']['timeout'])->toBeGreaterThan(3600)
        ->toBeLessThan($queue['connections']['redis']['retry_after']);
    foreach (['supervisor-default' => 9, 'supervisor-fantrax' => 1, 'supervisor-lineups' => 1] as $supervisor => $workers) {
        expect($horizon['defaults'][$supervisor]['minProcesses'])->toBe($workers)
            ->and($horizon['defaults'][$supervisor]['maxProcesses'])->toBe($workers);
    }
});

it('ignores old rate and TOI failures while a standalone profile build owns the model', function (): void {
    $this->run->update(['status' => 'running', 'metrics' => [
        'profile_build' => ['id' => 'profiles-new', 'status' => 'running'],
    ]]);
    (new BuildNhlSatModelEntityRateProjectionsJob($this->run->id))->failed(new RuntimeException('old rates'));
    (new BuildNhlSatModelEntityToiProjectionsJob($this->run->id))->failed(new RuntimeException('old TOI'));
    expect($this->run->fresh()->status)->toBe('running')
        ->and(data_get($this->run->fresh()->metrics, 'error'))->toBeNull();
});

it('does not let an older standalone profile callback complete a replacement build', function (): void {
    $this->run->update(['status' => 'running', 'metrics' => [
        'profile_build' => ['id' => 'profiles-new', 'status' => 'running'],
    ]]);
    NhlModelRun::finishPredictionStage($this->run->id, 'profiles-old', 'profiles', false);
    expect($this->run->fresh()->status)->toBe('running')
        ->and(data_get($this->run->fresh()->metrics, 'profiles_completed_at'))->toBeNull();
    Bus::assertNothingDispatched();
});

it('counts a zero-row snapshot only once and separately from its training entity', function (): void {
    ($this->activate)();
    $builder = Mockery::mock(NhlSatModelEntityProfileBuilder::class);
    $builder->shouldReceive('buildEntity')->once()->andReturn(0);
    $builder->shouldReceive('buildSeasonSnapshotEntity')->once()->andReturn(0);
    $training = new BuildNhlSatModelEntityProfileForEntityJob($this->run->id, $this->sat->id, null, 'skater_offense', 'skater_offense:101', null, 'build-1');
    $snapshot = new BuildNhlSatModelEntityProfileForEntityJob($this->run->id, $this->sat->id, null, 'skater_offense', 'skater_offense:101', '20242025', 'build-1');
    $training->handle($builder);
    $snapshot->handle($builder);
    $snapshot->handle($builder);
    expect(data_get($this->run->fresh()->metrics, 'profile_entities_completed'))->toBe(1)
        ->and(data_get($this->run->fresh()->metrics, 'season_snapshot_entities_completed'))->toBe(1);
});

it('tracks distinct entities in compact page slots without double counting redelivery', function (): void {
    ($this->activate)();
    $builder = Mockery::mock(NhlSatModelEntityProfileBuilder::class);
    $builder->shouldReceive('buildEntity')->twice()->andReturn(0);
    $first = new BuildNhlSatModelEntityProfileForEntityJob($this->run->id, $this->sat->id, null,
        'skater_offense', 'skater_offense:101', null, 'build-1', 'page-1', 0);
    $second = new BuildNhlSatModelEntityProfileForEntityJob($this->run->id, $this->sat->id, null,
        'skater_offense', 'skater_offense:102', null, 'build-1', 'page-1', 1);
    $first->handle($builder);
    $second->handle($builder);
    $first->handle($builder);
    expect(data_get($this->run->fresh()->metrics, 'profile_entities_completed'))->toBe(2)
        ->and(data_get($this->run->fresh()->metrics, 'profile_build.completed_pages.page-1'))
        ->toBe('11' . str_repeat('0', 98));
});

it('discovers only offensive skaters across training and required season snapshots', function (): void {
    $partitions = app(NhlSatModelEntityProfileBuilder::class)->profilePartitions($this->run);
    expect(array_column($partitions, 'profile_type'))->toBe(array_fill(0, 4, 'skater_offense'))
        ->and(array_column($partitions, 'season_id'))->toBe([null, '20232024', '20242025', '20252026']);
    $this->run->target_season_id = null;
    expect(app(NhlSatModelEntityProfileBuilder::class)->profilePartitions($this->run))->toHaveCount(3);
});

it('rejects obsolete non-offensive jobs before building their profiles', function (): void {
    ($this->activate)();
    $builder = Mockery::mock(NhlSatModelEntityProfileBuilder::class);
    $job = new BuildNhlSatModelEntityProfileForEntityJob($this->run->id, $this->sat->id, null,
        'staff_defense', 'staff:defense:head_coach:38', '20252026', 'build-1');
    expect(fn () => $job->handle($builder))->toThrow(RuntimeException::class, 'Obsolete non-offensive profile job');
    expect(data_get($this->run->fresh()->metrics, 'season_snapshot_entities_completed', 0))->toBe(0);
});

it('runs entity discovery outside the model progress transaction', function (): void {
    ($this->activate)();
    $level = DB::transactionLevel();
    $builder = Mockery::mock(NhlSatModelEntityProfileBuilder::class);
    $builder->shouldReceive('profilePartitions')->once()->andReturn([
        ['profile_type' => 'skater_offense', 'season_id' => null],
    ]);
    $builder->shouldReceive('clearProfileOutputs')->once();
    $builder->shouldReceive('profileEntityPage')->once()->andReturnUsing(function () use ($level): array {
        expect(DB::transactionLevel())->toBe($level);
        return ['skater_offense:101'];
    });
    [$loader, $batch] = (new LoadNhlSatModelProfileBatchJob($this->run->id, $this->sat->id, null, 'build-1'))->withFakeBatch();
    $loader->handle($builder);
    expect(collect($batch->added)->flatten(1))->toHaveCount(1);
});

it('rechecks build ownership after unlocked discovery before clearing or submitting work', function (): void {
    ($this->activate)();
    $builder = Mockery::mock(NhlSatModelEntityProfileBuilder::class);
    $builder->shouldReceive('profilePartitions')->once()->andReturn([
        ['profile_type' => 'skater_offense', 'season_id' => null],
    ]);
    $builder->shouldNotReceive('clearProfileOutputs');
    $builder->shouldReceive('profileEntityPage')->once()->andReturnUsing(function (): array {
        $this->run->update(['metrics' => ['profile_build' => ['id' => 'replacement', 'status' => 'running']]]);
        return ['skater_offense:101'];
    });
    [$loader, $batch] = (new LoadNhlSatModelProfileBatchJob($this->run->id, $this->sat->id, null, 'build-1'))->withFakeBatch();
    $loader->handle($builder);
    expect($batch->added)->toBeEmpty()
        ->and(data_get($this->run->fresh()->metrics, 'profile_build.id'))->toBe('replacement');
});

it('uses a PostgreSQL progress lock compatible with concurrent profile foreign-key checks', function (): void {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL row-lock contract.');
    }
    ($this->activate)();
    $locks = [];
    DB::listen(function (\Illuminate\Database\Events\QueryExecuted $query) use (&$locks): void {
        if (str_contains($query->sql, 'nhl_model_runs') && str_contains(strtolower($query->sql), ' for ')) {
            $locks[] = strtolower($query->sql);
        }
    });
    $builder = Mockery::mock(NhlSatModelEntityProfileBuilder::class);
    $builder->shouldReceive('buildEntity')->once()->andReturnUsing(function (): int {
        DB::table('nhl_sat_model_entity_profile_buckets')->insert($this->profileRow);
        return 1;
    });
    (new BuildNhlSatModelEntityProfileForEntityJob($this->run->id, $this->sat->id, null,
        'skater_offense', 'skater_offense:101', null, 'build-1'))->handle($builder);
    expect($locks)->toHaveCount(1)->and($locks[0])->toContain('for no key update')
        ->and(data_get($this->run->fresh()->metrics, 'profile_entities_completed'))->toBe(1);
});

it('uses EV goalie skill only in EV and keeps PP and PK goalie conversion at league average', function (): void {
    DB::table('nhl_sat_model_entity_profile_buckets')->insert([
        [
            ...$this->profileRow,
            'profile_type' => 'goalie_faced', 'entity_id' => 301, 'entity_key' => 'goalie_faced:301',
            'matched_bucket_key' => 'L01|goalie_faced', 'strength' => 'ev',
            'source_sat' => 100, 'source_sog' => 60, 'source_goals' => 0, 'expected_goals' => 10,
        ],
        [
            ...$this->profileRow,
            'profile_type' => 'goalie_faced', 'entity_id' => 302, 'entity_key' => 'goalie_faced:302',
            'matched_bucket_key' => 'L01|goalie_faced', 'strength' => 'ev',
            'source_sat' => 100, 'source_sog' => 60, 'source_goals' => 10, 'expected_goals' => 10,
        ],
    ]);

    $environment = collect([
        (object) [
            'matched_bucket_key' => 'EV|L01|goalie_faced',
            'bucket_dimensions' => ['strength_group' => 'EV'],
            'baseline_xsat' => 8.0, 'baseline_xsog' => 4.0, 'baseline_xgf' => 2.0,
        ],
        (object) [
            'matched_bucket_key' => 'PP|L01|goalie_faced',
            'bucket_dimensions' => ['strength_group' => 'PP'],
            'baseline_xsat' => 4.0, 'baseline_xsog' => 2.0, 'baseline_xgf' => 1.0,
        ],
        (object) [
            'matched_bucket_key' => 'PK|L01|goalie_faced',
            'bucket_dimensions' => ['strength_group' => 'PK'],
            'baseline_xsat' => 4.0, 'baseline_xsog' => 2.0, 'baseline_xgf' => 1.0,
        ],
    ]);

    $buckets = app(NhlSatModelPredictionService::class)->goalieBuckets($this->run->id, 301, $environment);

    expect($buckets->get('EV|L01|goalie_faced')->goalie_skill_source)->toBe('ev_gsax_skill')
        ->and($buckets->get('EV|L01|goalie_faced')->projected_ga)->toBe(1.9)
        ->and($buckets->get('PP|L01|goalie_faced')->goalie_skill_source)->toBe('league_average_strength')
        ->and($buckets->get('PP|L01|goalie_faced')->projected_ga)->toBe(1.0)
        ->and($buckets->get('PK|L01|goalie_faced')->goalie_skill_source)->toBe('league_average_strength')
        ->and($buckets->get('PK|L01|goalie_faced')->projected_ga)->toBe(1.0);
});
