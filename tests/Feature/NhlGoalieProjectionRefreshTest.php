<?php

declare(strict_types=1);

use App\Events\NhlGameImportStatusUpdated;
use App\Events\NhlSeasonStatsSynced;
use App\Jobs\BuildNhlGoalieProjectionForGoalieJob;
use App\Jobs\BuildNhlGoalieWorkloadProjectionForGoalieJob;
use App\Jobs\RefreshNhlGoalieProjectionsJob;
use App\Listeners\QueueNhlGoalieProjectionRefresh;
use App\Models\NhlGameImportRun;
use App\Models\Player;
use App\Models\Role;
use App\Models\User;
use App\Services\NhlGamePredictionPayload;
use App\Services\NhlGoalieProjectionBuilder;
use App\Services\NhlGoalieProjectionRefresh;
use App\Services\NhlGoalieWorkloadProjectionBuilder;
use App\Services\NhlStartingGoalieSelector;
use Illuminate\Bus\PendingBatch;
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
    config(['cache.default' => 'array']);
    Cache::flush();
    Bus::fake();
    Event::fake([NhlGameImportStatusUpdated::class]);
    Http::preventStrayRequests();
    $this->refresh = app(NhlGoalieProjectionRefresh::class);
    $this->context = ['source' => '20252026', 'target' => '20262027', 'toi' => 'existing-toi',
        'model' => null, 'version' => 'sync-goalie-20262027-test', 'owner' => 'test-owner',
        'goalies' => [8482447], 'run_id' => null];
    $this->projection = function (string $version = 'legacy', int $id = 8482447, string $status = 'draft'): int {
        return DB::table('nhl_goalie_season_projections')->insertGetId([
            'projection_version' => $version, 'source_season_id' => '20252026', 'target_season_id' => '20262027',
            'goalie_player_id' => $id, 'target_team_abbrev' => 'VAN', 'projected_games' => 10,
            'projected_gsax' => 2, 'goalie_workload_projection_version' => 'legacy-workload',
            'toi_projection_version' => 'existing-toi', 'status' => $status, 'projected_at' => now(),
            'metadata' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);
    };
    $this->workload = function (string $version): void {
        DB::table('nhl_goalie_workload_projections')->insert([
            'projection_version' => $version, 'source_season_id' => '20252026', 'target_season_id' => '20262027',
            'goalie_player_id' => 8482447, 'target_team_abbrev' => 'VAN', 'projected_games' => 10, 'status' => 'draft',
        ]);
    };
    $this->bucket = function (int $projectionId, string $version): void {
        DB::table('nhl_goalie_projection_chance_buckets')->insert([
            'goalie_season_projection_id' => $projectionId, 'projection_version' => $version,
            'source_season_id' => '20252026', 'target_season_id' => '20262027', 'goalie_player_id' => 8482447,
            'matched_bucket_key' => 'fixture', 'fallback_level' => 1, 'bucket_dimensions' => '{}', 'projection_strength' => 'ev',
        ]);
    };
    $this->run = fn (): NhlGameImportRun => NhlGameImportRun::create([
        'action' => NhlGameImportRun::ACTION_SEASON_SYNC, 'mode' => NhlGameImportRun::MODE_SEASON,
        'status' => NhlGameImportRun::STATUS_RUNNING, 'date_count' => 1, 'queued_jobs' => 1,
        'start_date' => '2026-09-01', 'end_date' => '2027-08-31',
        'payload' => ['season' => '20262027', 'aggregation_completed_at' => now()->toIso8601String(),
            'stats_cache' => ['status' => 'ready']],
    ]);
    $this->lease = fn (): bool => Cache::lock('nhl:goalie-refresh:20262027', 21600, 'test-owner')->get();
});

afterEach(function (): void {
    Http::assertNothingSent();
    $this->travelBack();
});

it('registers the existing committed season event with the goalie refresh listener', function (): void {
    Event::assertListening(NhlSeasonStatsSynced::class, QueueNhlGoalieProjectionRefresh::class);
    expect(new QueueNhlGoalieProjectionRefresh())->toBeInstanceOf(\Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit::class);
});

it('queues a refresh after a sync without requiring an admin run', function (): void {
    ($this->projection)();
    (new QueueNhlGoalieProjectionRefresh())->handle(new NhlSeasonStatsSynced('20262027', 2));
    Bus::assertDispatched(RefreshNhlGoalieProjectionsJob::class, fn ($job): bool =>
        $job->queue === 'projections' && $job->afterCommit && $job->context['run_id'] === null);
});

it('preserves the existing source season and TOI configuration', function (): void {
    ($this->projection)();
    $this->refresh->queueSeason('20262027');
    Bus::assertDispatched(RefreshNhlGoalieProjectionsJob::class, fn ($job): bool =>
        $job->context['source'] === '20252026' && $job->context['toi'] === 'existing-toi');
});

it('preserves an existing SAT model selection', function (): void {
    $id = ($this->projection)();
    DB::table('nhl_goalie_season_projections')->where('id', $id)->update(['metadata' => '{"sat_model_run_id":2}']);
    $this->refresh->queueSeason('20262027');
    Bus::assertDispatched(RefreshNhlGoalieProjectionsJob::class, fn ($job): bool => $job->context['model'] === 2);
});

it('does not create projection configurations for unrelated seasons', function (): void {
    ($this->projection)();
    $run = ($this->run)();
    $this->refresh->queueSeason('20242025', $run->id);
    Bus::assertNotDispatched(RefreshNhlGoalieProjectionsJob::class);
    expect(data_get($run->refresh()->payload, 'goalie_refresh.status'))->toBe('skipped');
});

it('marks goalie preparation queued before the cache publisher can complete the sync', function (): void {
    ($this->projection)();
    $run = ($this->run)();
    $this->refresh->queueSeason('20262027', $run->id);
    expect($run->refresh()->status)->toBe('running')
        ->and(data_get($run->payload, 'goalie_refresh.status'))->toBe('queued')
        ->and(data_get($run->payload, 'stats_cache.status'))->toBe('ready');
});

it('does not dispatch the same admin sync twice', function (): void {
    ($this->projection)();
    $run = ($this->run)();
    $this->refresh->queueSeason('20262027', $run->id);
    $this->refresh->queueSeason('20262027', $run->id);
    Bus::assertDispatchedTimes(RefreshNhlGoalieProjectionsJob::class, 1);
});

it('retains existing manual draft selection while hiding automatic drafts', function (): void {
    ($this->projection)();
    ($this->projection)($this->context['version']);
    expect(NhlGoalieProjectionRefresh::usable(DB::table('nhl_goalie_season_projections as projections'))
        ->pluck('projection_version')->all())->toBe(['legacy']);
});

it('makes published automatic versions selectable', function (): void {
    ($this->projection)($this->context['version'], 8482447, 'published');
    expect(NhlGoalieProjectionRefresh::usable(DB::table('nhl_goalie_season_projections as projections'))->count())->toBe(1);
});

it('does not select archived automatic versions', function (): void {
    ($this->projection)($this->context['version'], 8482447, 'archived');
    expect(NhlGoalieProjectionRefresh::usable(DB::table('nhl_goalie_season_projections as projections'))->count())->toBe(0);
});

it('defers a concurrent season refresh without creating workload jobs', function (): void {
    Cache::lock('nhl:goalie-refresh:20262027', 21600, 'other-owner')->get();
    expect($this->refresh->start($this->context))->toBeFalse();
    Bus::assertNothingBatched();
});

it('does not rebuild a version whose workload batch already exists', function (): void {
    DB::table('job_batches')->insert(['id' => 'existing-workload', 'name' => 'Goalie workload refresh '.$this->context['version'],
        'total_jobs' => 1, 'pending_jobs' => 0, 'failed_jobs' => 0, 'failed_job_ids' => '[]', 'options' => '', 'created_at' => now()->timestamp]);
    expect($this->refresh->start($this->context))->toBeTrue();
    Bus::assertNothingBatched();
});

it('does not rebuild a version whose performance batch already exists', function (): void {
    DB::table('job_batches')->insert(['id' => 'existing-performance', 'name' => 'Goalie performance refresh '.$this->context['version'],
        'total_jobs' => 1, 'pending_jobs' => 0, 'failed_jobs' => 0, 'failed_job_ids' => '[]', 'options' => '', 'created_at' => now()->timestamp]);
    $this->refresh->performance($this->context);
    Bus::assertNothingBatched();
});

it('queues only workload children before their batch succeeds', function (): void {
    $this->mock(NhlGoalieWorkloadProjectionBuilder::class)->shouldReceive('prepareBuild')->once()
        ->with('20252026', '20262027', $this->context['version'])->andReturn(['goalie_player_ids' => [8482447]]);
    expect($this->refresh->start($this->context))->toBeTrue();
    Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->options['queue'] === 'projections'
        && $batch->jobs->count() === 1 && $batch->jobs->first() instanceof BuildNhlGoalieWorkloadProjectionForGoalieJob);
    Bus::assertNotDispatched(RefreshNhlGoalieProjectionsJob::class);
});

it('dispatches performance continuation only from a successful workload completion', function (bool $cancelled): void {
    $this->mock(NhlGoalieWorkloadProjectionBuilder::class)->shouldReceive('prepareBuild')->once()
        ->andReturn(['goalie_player_ids' => [8482447]]);
    $this->refresh->start($this->context);
    $pending = null;
    Bus::assertBatched(function (PendingBatch $batch) use (&$pending): bool {
        $pending = $batch;

        return true;
    });
    $batch = Mockery::mock(\Illuminate\Bus\Batch::class);
    $batch->failedJobs = 0;
    $batch->shouldReceive('cancelled')->once()->andReturn($cancelled);
    ($pending->options['then'][0])($batch);
    if ($cancelled) {
        Bus::assertNotDispatched(RefreshNhlGoalieProjectionsJob::class);
    } else {
        Bus::assertDispatched(RefreshNhlGoalieProjectionsJob::class, fn ($job): bool =>
            $job->stage === 'performance' && $job->context['version'] === $this->context['version']);
    }
})->with([false, true]);

it('releases the season lease when workload preparation fails', function (): void {
    $this->mock(NhlGoalieWorkloadProjectionBuilder::class)->shouldReceive('prepareBuild')->once()
        ->andThrow(new RuntimeException('Missing prerequisite'));
    expect(fn () => $this->refresh->start($this->context))->toThrow(RuntimeException::class, 'Missing prerequisite');
    expect(Cache::lock('nhl:goalie-refresh:20262027', 21600)->get())->toBeTrue();
});

it('does not release another refresh lease when failing stale work', function (): void {
    Cache::lock('nhl:goalie-refresh:20262027', 21600, 'other-owner')->get();
    $this->refresh->fail($this->context, 'Old work failed');
    expect(Cache::lock('nhl:goalie-refresh:20262027')->get())->toBeFalse();
});

it('rejects expired leases before performance preparation', function (): void {
    expect(fn () => $this->refresh->performance($this->context))->toThrow(RuntimeException::class, 'lease expired');
    Bus::assertNothingBatched();
});

it('rejects incomplete workload rows before performance preparation', function (): void {
    ($this->lease)();
    expect(fn () => $this->refresh->performance($this->context))->toThrow(RuntimeException::class, 'incomplete workload');
    Bus::assertNothingBatched();
});

it('queues performance jobs using the new workload version and unchanged settings', function (): void {
    ($this->lease)();
    ($this->workload)($this->context['version']);
    $this->mock(NhlGoalieProjectionBuilder::class)->shouldReceive('prepareBuild')->once()
        ->with('20252026', '20262027', $this->context['version'], 'existing-toi', $this->context['version'], null)
        ->andReturn(['goalie_player_ids' => [8482447]]);
    $this->refresh->performance($this->context);
    Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->jobs->count() === 1
        && $batch->jobs->first() instanceof BuildNhlGoalieProjectionForGoalieJob
        && $batch->jobs->first()->goalieWorkloadProjectionVersion === $this->context['version']
        && $batch->jobs->first()->queue === 'projections');
});

it('surfaces missing performance prerequisites without publishing or retaining the lease', function (): void {
    $run = ($this->run)();
    ($this->lease)();
    ($this->workload)($this->context['version']);
    $this->mock(NhlGoalieProjectionBuilder::class)->shouldReceive('prepareBuild')->once()
        ->andThrow(new RuntimeException('Build xG and xSOG models first'));
    $job = new RefreshNhlGoalieProjectionsJob([...$this->context, 'run_id' => $run->id], 'performance');
    $job->handle($this->refresh);
    expect($run->refresh()->status)->toBe('failed')->and($run->last_error)->toContain('xG and xSOG');
    expect(Cache::lock('nhl:goalie-refresh:20262027')->get())->toBeTrue();
    Bus::assertNothingBatched();
});

it('refuses to publish missing goalie rows and retains the previous version', function (): void {
    ($this->projection)();
    ($this->lease)();
    ($this->workload)($this->context['version']);
    expect(fn () => $this->refresh->publish($this->context))->toThrow(RuntimeException::class, 'incomplete performance');
    expect(NhlGoalieProjectionRefresh::usable(DB::table('nhl_goalie_season_projections as projections'))
        ->value('projection_version'))->toBe('legacy');
});

it('refuses to publish a goalie row without its compatible buckets', function (): void {
    ($this->lease)();
    ($this->workload)($this->context['version']);
    ($this->projection)($this->context['version']);
    expect(fn () => $this->refresh->publish($this->context))->toThrow(RuntimeException::class, 'incomplete performance');
    $this->assertDatabaseHas('nhl_goalie_season_projections', ['projection_version' => $this->context['version'], 'status' => 'draft']);
});

it('refuses to publish unusable goalie rates despite a completed child job', function (array $values): void {
    ($this->lease)();
    ($this->workload)($this->context['version']);
    $id = ($this->projection)($this->context['version']);
    ($this->bucket)($id, $this->context['version']);
    DB::table('nhl_goalie_season_projections')->where('id', $id)->update($values);
    expect(fn () => $this->refresh->publish($this->context))->toThrow(RuntimeException::class, 'incomplete performance');
})->with([['values' => ['projected_games' => 0]], ['values' => ['projected_gsax' => null]]]);

it('publishes both stages together and releases the lease after complete coverage', function (): void {
    ($this->lease)();
    ($this->workload)($this->context['version']);
    $id = ($this->projection)($this->context['version']);
    ($this->bucket)($id, $this->context['version']);
    $this->refresh->publish($this->context);
    foreach (['nhl_goalie_workload_projections', 'nhl_goalie_season_projections'] as $table) {
        $this->assertDatabaseHas($table, ['projection_version' => $this->context['version'], 'status' => 'published']);
    }
    expect(Cache::lock('nhl:goalie-refresh:20262027')->get())->toBeTrue();
});

it('makes both prediction and starter selectors ignore unfinished automatic versions', function (string $strength): void {
    $old = ($this->projection)();
    ($this->bucket)($old, 'legacy');
    $new = ($this->projection)($this->context['version']);
    ($this->bucket)($new, $this->context['version']);
    DB::table('nhl_goalie_projection_chance_buckets')->where('goalie_season_projection_id', $new)
        ->update(['projection_strength' => $strength]);
    foreach ([NhlGamePredictionPayload::class, NhlStartingGoalieSelector::class] as $service) {
        $method = new ReflectionMethod($service, 'latestGoalieProjectionVersion');
        expect($method->invoke(app($service), '20262027'))->toBe('legacy');
    }
    ($this->lease)();
    ($this->workload)($this->context['version']);
    $this->refresh->publish($this->context);
    foreach ([NhlGamePredictionPayload::class, NhlStartingGoalieSelector::class] as $service) {
        $method = new ReflectionMethod($service, 'latestGoalieProjectionVersion');
        expect($method->invoke(app($service), '20262027'))->toBe($this->context['version']);
    }
})->with(['ev', 'all']);

it('holds season sync completion until both independent publishers are ready', function (string $cache, string $goalies, string $expected): void {
    expect(NhlGoalieProjectionRefresh::runStatus(['aggregation_completed_at' => '2026-11-13',
        'stats_cache' => ['status' => $cache], 'goalie_refresh' => ['status' => $goalies]]))->toBe($expected);
})->with([
    ['ready', 'queued', 'running'], ['ready', 'building', 'running'], ['building', 'ready', 'running'],
    ['ready', 'ready', 'completed'], ['ready', 'skipped', 'completed'],
    ['failed', 'ready', 'failed'], ['ready', 'failed', 'failed'],
]);

it('does not complete a sync before aggregation commits', function (): void {
    expect(NhlGoalieProjectionRefresh::runStatus(['stats_cache' => ['status' => 'ready'],
        'goalie_refresh' => ['status' => 'ready']]))->toBe('running');
});

it('keeps cache publication from completing a pending or failed goalie refresh', function (string $status, string $expected): void {
    $run = ($this->run)();
    $run->update(['payload' => [...$run->payload, 'goalie_refresh' => ['status' => $status]]]);
    $batch = Mockery::mock(\Illuminate\Bus\Batch::class);
    $batch->id = 'cache-refresh';
    \App\Listeners\QueueNhlStatsDashboardRefresh::recordBatch($run->id, $batch, 'ready');
    expect($run->refresh()->status)->toBe($expected)
        ->and(data_get($run->payload, 'goalie_refresh.status'))->toBe($status);
})->with([['queued', 'running'], ['failed', 'failed']]);

it('keeps aggregation completion from bypassing pending goalie preparation', function (): void {
    $run = ($this->run)();
    $run->update(['payload' => [...$run->payload, 'goalie_refresh' => ['status' => 'queued']]]);
    $job = new \App\Jobs\SeasonSumJob('20262027', $run->id);
    (new ReflectionMethod($job, 'markCompleted'))->invoke($job, $run, 2);
    expect($run->refresh()->status)->toBe('running')->and($run->payload['rows_upserted'])->toBe(2);
});

it('preserves other sync payload fields when surfacing a goalie failure', function (): void {
    $run = ($this->run)();
    $this->refresh->fail([...$this->context, 'run_id' => $run->id], 'Goalie profile missing');
    expect($run->refresh()->status)->toBe('failed')->and($run->last_error)->toBe('Goalie profile missing')
        ->and(data_get($run->payload, 'stats_cache.status'))->toBe('ready')
        ->and(data_get($run->payload, 'goalie_refresh.status'))->toBe('failed');
});

it('retains the original failure when batch cancellation is reported afterwards', function (): void {
    $run = ($this->run)();
    $context = [...$this->context, 'run_id' => $run->id];
    $this->refresh->fail($context, 'Goalie profile missing');
    $this->refresh->fail($context, 'Batch cancelled');
    expect($run->refresh()->last_error)->toBe('Goalie profile missing');
});

it('rebuilds workload team assignment from the canonical player instead of old projections', function (): void {
    $old = ($this->projection)();
    DB::table('nhl_goalie_season_projections')->where('id', $old)->update(['target_team_abbrev' => 'OTT']);
    DB::table('nhl_teams')->insert(['nhl_id' => 23, 'abbrev' => 'VAN']);
    Player::create(['nhl_id' => 8482447, 'full_name' => 'Leevi Merilainen', 'first_name' => 'Leevi',
        'last_name' => 'Merilainen', 'position' => 'G', 'is_goalie' => true, 'team_abbrev' => 'VAN', 'nhl_team_id' => 23]);
    app(NhlGoalieWorkloadProjectionBuilder::class)->buildGoalie('20252026', '20262027', $this->context['version'], 8482447);
    $this->assertDatabaseHas('nhl_goalie_workload_projections', [
        'goalie_player_id' => 8482447, 'projection_version' => $this->context['version'], 'target_team_abbrev' => 'VAN',
    ]);
    $this->assertDatabaseHas('nhl_goalie_season_projections', ['id' => $old, 'target_team_abbrev' => 'OTT']);
});

it('exposes a post-sync goalie preparation failure through the authorized admin status read', function (): void {
    $user = User::factory()->create();
    $role = Role::create(['name' => 'Super Admin', 'slug' => 'super-admin', 'level' => 99]);
    $user->roles()->attach($role->id, ['organization_id' => null]);
    ($this->projection)();
    $id = $this->actingAs($user)->postJson(route('admin.nhl-game-imports.season-sync'), ['season' => '20262027'])
        ->assertAccepted()->json('run.id');
    (new QueueNhlGoalieProjectionRefresh())->handle(new NhlSeasonStatsSynced('20262027', 1, $id));
    $this->refresh->fail([...$this->context, 'run_id' => $id], 'Required goalie profile missing');
    $this->assertDatabaseHas('nhl_game_import_runs', ['id' => $id, 'status' => 'failed', 'last_error' => 'Required goalie profile missing']);
    $this->getJson(route('admin.nhl-game-imports.status'))->assertOk()
        ->assertJsonPath('runs.0.status', 'failed')->assertJsonPath('runs.0.payload.goalie_refresh.status', 'failed')
        ->assertJsonPath('runs.0.last_error', 'Required goalie profile missing');
});

it('retains guest and ordinary-user restrictions on goalie-related sync progress', function (bool $signedIn): void {
    if ($signedIn) {
        $this->actingAs(User::factory()->create());
    }
    $this->getJson(route('admin.nhl-game-imports.status'))->assertStatus($signedIn ? 403 : 401);
    $this->postJson(route('admin.nhl-game-imports.season-sync'), ['season' => '20262027'])->assertStatus($signedIn ? 403 : 401);
    Bus::assertNotDispatched(RefreshNhlGoalieProjectionsJob::class);
})->with([false, true]);
