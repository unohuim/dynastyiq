<?php

declare(strict_types=1);

use App\Events\NhlSatModelUpdated;
use App\Jobs\BuildNhlSatModelEntityProfilesJob;
use App\Jobs\BuildNhlSatModelEntityRateProjectionsJob;
use App\Jobs\BuildNhlSatModelEntityToiProjectionsJob;
use App\Jobs\LoadNhlSatModelProfileBatchJob;
use App\Models\NhlExpectedGoalsModel;
use App\Models\NhlModelRun;
use App\Models\Role;
use App\Models\User;
use App\Services\NhlSatModelEntityProfileBuilder;
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
    $builder->shouldReceive('prepareBuild')->andReturn([]);
    $builder->shouldReceive('prepareSeasonSnapshotBuilds')->andReturn([]);
    [$loader, $batch] = (new LoadNhlSatModelProfileBatchJob($this->run->id, $this->sat->id, null, 'build-1'))->withFakeBatch();
    try {
        $loader->handle($builder);
        $this->fail('Empty combined builds must fail.');
    } catch (\RuntimeException $exception) {
        expect($exception->getMessage())->toContain('No profile entities or season snapshots are available.');
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

it('queues more than the old parameter limit in bounded database inserts', function (): void {
    ($this->activate)();
    config([
        'queue.default' => 'database',
        'queue.connections.database.connection' => config('database.default'),
        'queue.batching.database' => config('database.default'),
    ]);
    Bus::swap($this->realBus);
    $insertBindings = [];
    DB::listen(function (\Illuminate\Database\Events\QueryExecuted $query) use (&$insertBindings): void {
        if (preg_match('/^insert into ["`]?jobs["`]?\s/i', $query->sql) === 1) {
            $insertBindings[] = count($query->bindings);
        }
    });
    $entities = array_map(fn (int $id): array => [
        'profile_type' => 'skater_offense', 'entity_key' => 'skater_offense:' . $id,
    ], range(1, 11001));
    $builder = Mockery::mock(NhlSatModelEntityProfileBuilder::class);
    $builder->shouldReceive('prepareBuild')->once()->andReturn($entities);
    $builder->shouldReceive('prepareSeasonSnapshotBuilds')->once()->andReturn([]);
    (new BuildNhlSatModelEntityProfilesJob($this->run->id, $this->sat->id, null, 'build-1'))->handle($builder);
    $batchId = DB::table('job_batches')->sole()->id;
    $loader = (new LoadNhlSatModelProfileBatchJob($this->run->id, $this->sat->id, null, 'build-1'))->withBatchId($batchId);
    $loader->handle($builder);

    expect(max($insertBindings))->toBeLessThanOrEqual(600)
        ->and(count($insertBindings))->toBe(112)
        ->and(DB::table('jobs')->count())->toBe(11002);
    $batch = Bus::findBatch($batchId);
    expect($batch->totalJobs)->toBe(11002)->and($batch->pendingJobs)->toBe(11002)
        ->and($batch->finished())->toBeFalse()
        ->and(data_get($this->run->fresh()->metrics, 'profile_entities_queued'))->toBe(11001)
        ->and(data_get($this->run->fresh()->metrics, 'prediction_build.stage'))->toBe('profiles');
});

it('preserves training and snapshot identities across chunk boundaries', function (): void {
    ($this->activate)();
    $entities = array_map(fn (int $id): array => [
        'profile_type' => 'skater_offense', 'entity_key' => 'skater_offense:' . $id,
    ], range(1, 99));
    $snapshots = [
        ['profile_type' => 'goalie_faced', 'entity_key' => 'goalie_faced:200', 'season_id' => '20232024'],
        ['profile_type' => 'team_defense', 'entity_key' => 'team_defense:300', 'season_id' => '20252026'],
    ];
    $builder = Mockery::mock(NhlSatModelEntityProfileBuilder::class);
    $builder->shouldReceive('prepareBuild')->once()->andReturn($entities);
    $builder->shouldReceive('prepareSeasonSnapshotBuilds')->once()->andReturn($snapshots);
    [$loader, $batch] = (new LoadNhlSatModelProfileBatchJob($this->run->id, $this->sat->id, $this->sat->id, 'build-1'))->withFakeBatch();
    $loader->handle($builder);
    $jobs = collect($batch->added)->flatten(1);
    expect($jobs)->toHaveCount(101);
    foreach ($jobs as $job) {
        expect($job->modelRunId)->toBe($this->run->id)->and($job->satModelId)->toBe($this->sat->id)
            ->and($job->sogModelId)->toBe($this->sat->id);
    }
    expect($jobs[0]->entityKey)->toBe('skater_offense:1')->and($jobs[0]->snapshotSeasonId)->toBeNull()
        ->and($jobs[99]->profileType)->toBe('goalie_faced')->and($jobs[99]->snapshotSeasonId)->toBe('20232024')
        ->and($jobs[100]->entityKey)->toBe('team_defense:300')->and($jobs[100]->snapshotSeasonId)->toBe('20252026')
        ->and(data_get($this->run->fresh()->metrics, 'profile_entities_queued'))->toBe(99)
        ->and(data_get($this->run->fresh()->metrics, 'season_snapshot_entities_queued'))->toBe(2);
});

it('does not prepare or queue work for a cancelled profile loader', function (): void {
    ($this->activate)();
    $builder = Mockery::mock(NhlSatModelEntityProfileBuilder::class);
    $builder->shouldNotReceive('prepareBuild');
    [$loader, $batch] = (new LoadNhlSatModelProfileBatchJob($this->run->id, $this->sat->id, null, 'build-1'))->withFakeBatch();
    $batch->cancel();
    $loader->handle($builder);
    expect($batch->added)->toBeEmpty();
});

it('does not prepare or queue work for a stale profile loader', function (): void {
    ($this->activate)();
    $builder = Mockery::mock(NhlSatModelEntityProfileBuilder::class);
    $builder->shouldNotReceive('prepareBuild');
    [$loader, $batch] = (new LoadNhlSatModelProfileBatchJob($this->run->id, $this->sat->id, null, 'old-build'))->withFakeBatch();
    $loader->handle($builder);
    expect($batch->added)->toBeEmpty()->and($this->run->fresh()->status)->toBe('running');
});

it('stops adding chunks when a profile batch is cancelled during loading', function (): void {
    ($this->activate)();
    $builder = Mockery::mock(NhlSatModelEntityProfileBuilder::class);
    $builder->shouldReceive('prepareBuild')->andReturn(array_fill(0, 201, [
        'profile_type' => 'skater_offense', 'entity_key' => 'skater_offense:101',
    ]));
    $builder->shouldReceive('prepareSeasonSnapshotBuilds')->andReturn([]);
    $batch = Mockery::mock(\Illuminate\Bus\Batch::class);
    $batch->shouldReceive('cancelled')->andReturn(false, false, true);
    $batch->shouldReceive('add')->once()->with(Mockery::on(fn (array $jobs): bool => count($jobs) === 100));
    $loader = Mockery::mock(LoadNhlSatModelProfileBatchJob::class, [$this->run->id, $this->sat->id, null, 'build-1'])->makePartial();
    $loader->shouldReceive('batch')->andReturn($batch);
    $loader->handle($builder);
    Bus::assertNotDispatched(BuildNhlSatModelEntityRateProjectionsJob::class);
});

it('cancels a partially loaded batch and records a short SQL error through HTTP', function (): void {
    $this->actingAs($this->admin)->postJson($this->url)->assertOk();
    $buildId = data_get($this->run->fresh()->metrics, 'prediction_build.id');
    $builder = Mockery::mock(NhlSatModelEntityProfileBuilder::class);
    $builder->shouldReceive('prepareBuild')->andReturn(array_fill(0, 101, [
        'profile_type' => 'skater_offense', 'entity_key' => 'skater_offense:101',
    ]));
    $builder->shouldReceive('prepareSeasonSnapshotBuilds')->andReturn([]);
    $batch = Mockery::mock(\Illuminate\Bus\Batch::class);
    $batch->shouldReceive('cancelled')->andReturn(false);
    $batch->shouldReceive('add')->once()->with(Mockery::on(fn (array $jobs): bool => count($jobs) === 100));
    $batch->shouldReceive('add')->once()->with(Mockery::on(fn (array $jobs): bool => count($jobs) === 1))
        ->andThrow(new \Illuminate\Database\QueryException('pgsql', 'insert into jobs values (?)', [str_repeat('private-payload', 1000)],
            new \PDOException('SQLSTATE[HY000]: parameter limit exceeded')));
    $batch->shouldReceive('cancel')->once();
    $loader = Mockery::mock(LoadNhlSatModelProfileBatchJob::class, [$this->run->id, $this->sat->id, null, $buildId])->makePartial();
    $loader->shouldReceive('batch')->andReturn($batch);
    try {
        $loader->handle($builder);
        $this->fail('The insert failure must reach the worker.');
    } catch (\RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Profile loading failed (queued 100/101): SQLSTATE[HY000]: parameter limit exceeded')
            ->and($exception->getPrevious())->toBeNull();
    }
    expect($this->run->fresh()->status)->toBe('failed');
    $this->get(route('admin.nhl-sat-models.index'))->assertOk()
        ->assertSee('parameter limit exceeded')->assertDontSee('private-payload')->assertDontSee('insert into jobs');
    Bus::assertNotDispatched(BuildNhlSatModelEntityRateProjectionsJob::class);
});

it('bounds ordinary profile failure messages as well as database exceptions', function (): void {
    ($this->activate)();
    (new BuildNhlSatModelEntityProfilesJob($this->run->id, $this->sat->id, null, 'build-1'))
        ->failed(new \RuntimeException(str_repeat('x', 5000)));
    expect(data_get($this->run->fresh()->metrics, 'prediction_build.error'))->toHaveLength(1000);
});

it('keeps the profile batch pending until the loader and all children succeed', function (): void {
    ($this->activate)();
    config([
        'queue.default' => 'database',
        'queue.connections.database.connection' => config('database.default'),
        'queue.batching.database' => config('database.default'),
    ]);
    Bus::swap($this->realBus);
    $builder = Mockery::mock(NhlSatModelEntityProfileBuilder::class);
    $builder->shouldReceive('prepareBuild')->andReturn([
        ['profile_type' => 'skater_offense', 'entity_key' => 'skater_offense:101'],
        ['profile_type' => 'skater_offense', 'entity_key' => 'skater_offense:102'],
    ]);
    $builder->shouldReceive('prepareSeasonSnapshotBuilds')->andReturn([]);
    $this->mock(\App\Services\NhlSatModelGenericBucketStabilityBuilder::class)
        ->shouldReceive('build')->once()->andReturn(['total' => 0]);
    (new BuildNhlSatModelEntityProfilesJob($this->run->id, $this->sat->id, null, 'build-1'))->handle($builder);
    $batchId = DB::table('job_batches')->sole()->id;
    (new LoadNhlSatModelProfileBatchJob($this->run->id, $this->sat->id, null, 'build-1'))
        ->withBatchId($batchId)->handle($builder);
    $batch = Bus::findBatch($batchId);
    Bus::fake();
    $batch->recordSuccessfulJob('child-101');
    $batch->recordSuccessfulJob('child-102');
    expect($batch->fresh()->pendingJobs)->toBe(1)->and($batch->fresh()->finished())->toBeFalse();
    Bus::assertNothingDispatched();
    $batch->recordSuccessfulJob('loader');
    expect($batch->fresh()->finished())->toBeTrue()
        ->and(data_get($this->run->fresh()->metrics, 'prediction_build.stage'))->toBe('rates');
    Bus::assertDispatchedTimes(BuildNhlSatModelEntityRateProjectionsJob::class, 1);
});

it('keeps standalone profiles on the same bounded loading path', function (): void {
    $this->run->update(['status' => 'running']);
    $builder = Mockery::mock(NhlSatModelEntityProfileBuilder::class);
    $builder->shouldReceive('prepareBuild')->andReturn([
        ['profile_type' => 'skater_offense', 'entity_key' => 'skater_offense:101'],
    ]);
    $builder->shouldReceive('prepareSeasonSnapshotBuilds')->andReturn([]);
    (new BuildNhlSatModelEntityProfilesJob($this->run->id, $this->sat->id))->handle($builder);
    Bus::assertBatched(fn (\Illuminate\Bus\PendingBatch $batch) =>
        $batch->jobs->count() === 1 && $batch->jobs->first()->predictionBuildId === null);
    [$loader, $batch] = (new LoadNhlSatModelProfileBatchJob($this->run->id, $this->sat->id))->withFakeBatch();
    $loader->handle($builder);
    expect(collect($batch->added)->flatten(1))->toHaveCount(1)
        ->and(data_get($this->run->fresh()->metrics, 'prediction_build'))->toBeNull();
    Bus::assertNotDispatched(BuildNhlSatModelEntityRateProjectionsJob::class);
});

it('preserves loader error details when the worker and batch report the same failure again', function (): void {
    ($this->activate)();
    $builder = Mockery::mock(NhlSatModelEntityProfileBuilder::class);
    $builder->shouldReceive('prepareBuild')->andThrow(new \RuntimeException('Entity discovery failed'));
    (new BuildNhlSatModelEntityProfilesJob($this->run->id, $this->sat->id, null, 'build-1'))->handle($builder);
    [$loader, $batch] = (new LoadNhlSatModelProfileBatchJob($this->run->id, $this->sat->id, null, 'build-1'))->withFakeBatch();
    try {
        $loader->handle($builder);
        $this->fail('Discovery failure must reach the worker.');
    } catch (\RuntimeException $exception) {
        $loader->failed($exception);
    }
    Bus::assertBatched(function (\Illuminate\Bus\PendingBatch $pending) use ($batch): bool {
        $batch->failedJobs = 1;
        ($pending->options['finally'][0])($batch);

        return true;
    });
    expect($this->run->fresh()->status)->toBe('failed')
        ->and(data_get($this->run->fresh()->metrics, 'prediction_build.error'))
        ->toBe('Profile loading failed (queued 0/0): Entity discovery failed');
    Bus::assertNotDispatched(BuildNhlSatModelEntityRateProjectionsJob::class);
});
