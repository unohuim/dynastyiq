<?php

declare(strict_types=1);

use App\Jobs\BuildNhlShotAttemptFactsJob;
use App\Jobs\Middleware\ControlNhlGameImportRun;
use App\Jobs\NhlDiscoveryJob;
use App\Models\NhlGameImportRun;
use App\Models\Role;
use App\Models\User;
use App\Services\NhlGameImportRunControl;
use Illuminate\Bus\Batch;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-10-23 12:00:00 UTC'));
    config(['cache.default' => 'array']);
    Cache::flush();
    Bus::fake();
    Event::fake();
    Http::preventStrayRequests();
    $this->mock(\App\Services\PlatformState::class)->shouldReceive('seeded')->andReturn(true);
    $this->admin = User::factory()->create();
    $role = Role::query()->firstOrCreate(['slug' => 'super-admin'], ['name' => 'Super Admin', 'level' => 99]);
    $this->admin->roles()->attach($role->id, ['organization_id' => null]);
    $this->control = app(NhlGameImportRunControl::class);
    $this->run = NhlGameImportRun::query()->create([
        'action' => 'discover', 'mode' => 'range', 'status' => 'running',
        'start_date' => '2026-10-22', 'end_date' => '2026-10-21', 'date_count' => 2,
        'payload' => ['auto_process' => false, 'discovery_completed_dates' => ['2026-10-21']],
    ]);
    $this->url = fn (string $action): string => '/admin/nhl-game-imports/'.$this->run->id.'/'.$action;
    $this->progress = function (string $status = 'scheduled', int $gameId = 2026020001): int {
        return DB::table('nhl_import_progress')->insertGetId([
            'run_id' => $this->run->id, 'game_id' => $gameId, 'season_id' => '20262027',
            'game_date' => '2026-10-22', 'import_type' => 'pbp', 'status' => $status, 'items_count' => 1,
        ]);
    };
    $this->work = fn () => new BuildNhlShotAttemptFactsJob(2026020001, $this->run->id);
});

afterEach(function (): void {
    $this->travelBack();
});

it('denies guests access to all run controls', function (string $action): void {
    $this->postJson(($this->url)($action))->assertUnauthorized();
})->with(['pause', 'resume', 'cancel']);

it('denies non-admin users access to all run controls', function (string $action): void {
    $this->actingAs(User::factory()->create())->postJson(($this->url)($action))->assertForbidden();
})->with(['pause', 'resume', 'cancel']);

it('allows an admin to pause and reads the paused status through the existing status endpoint', function (): void {
    ($this->progress)();
    $this->actingAs($this->admin)->postJson(($this->url)('pause'))->assertOk()->assertJsonPath('run.status', 'paused');
    $this->assertDatabaseHas('nhl_game_import_runs', ['id' => $this->run->id, 'status' => 'paused']);
    $this->getJson(route('admin.nhl-game-imports.status'))->assertOk()->assertJsonPath('runs.0.status', 'paused');
});

it('shows pausing while a previously claimed job is still active', function (): void {
    $token = $this->control->claim($this->run->id, 'initial', 'fixture', 180);
    expect($token)->not->toBeNull();
    $this->actingAs($this->admin)->postJson(($this->url)('pause'))->assertOk()->assertJsonPath('run.status', 'pausing');
});

it('refuses new work once a pause has been requested', function (): void {
    $this->control->pause($this->run->id);
    expect($this->control->claim($this->run->id, 'initial', 'next', 180))->toBeNull();
});

it('finishes claimed work during Pause and transitions to Paused', function (): void {
    $progress = ($this->progress)('running');
    (new ControlNhlGameImportRun())->handle(($this->work)(), function () use ($progress): void {
        $this->control->pause($this->run->id);
        DB::table('nhl_import_progress')->where('id', $progress)->update(['items_count' => 7]);
    });
    expect((int) DB::table('nhl_import_progress')->where('id', $progress)->value('items_count'))->toBe(7)
        ->and($this->run->fresh()->status)->toBe('paused');
});

it('drops paused queued work without consuming its source checkpoint', function (): void {
    $this->control->pause($this->run->id);
    $called = false;
    (new ControlNhlGameImportRun())->handle(($this->work)(), function () use (&$called): void { $called = true; });
    expect($called)->toBeFalse()->and(data_get($this->run->fresh()->payload, 'run_control.completed', []))->toBe([]);
});

it('allows an admin to resume unfinished discovery and dispatches a new coordinator', function (): void {
    $this->control->pause($this->run->id);
    $this->actingAs($this->admin)->postJson(($this->url)('resume'))->assertOk()->assertJsonPath('run.status', 'running');
    Bus::assertDispatched(NhlDiscoveryJob::class, fn ($job) => $job->runId === $this->run->id && $job->runGeneration !== 'initial');
});

it('retains completed discovery dates when resuming', function (): void {
    $this->control->pause($this->run->id);
    $this->control->resume($this->run->id);
    expect($this->run->fresh()->payload['discovery_completed_dates'])->toBe(['2026-10-21']);
});

it('does not resume while claimed work is still live', function (): void {
    $this->control->claim($this->run->id, 'initial', 'fixture', 180);
    $this->control->pause($this->run->id);
    $this->actingAs($this->admin)->postJson(($this->url)('resume'))->assertUnprocessable();
    Bus::assertNothingDispatched();
});

it('recovers an expired claim when resuming after a worker timeout', function (): void {
    $this->control->claim($this->run->id, 'initial', 'fixture', 30);
    $this->control->pause($this->run->id);
    $this->travel(151)->seconds();
    $this->control->resume($this->run->id);
    expect($this->run->fresh()->status)->toBe('running');
});

it('allows an admin to cancel pending work and reads the durable status', function (): void {
    ($this->progress)();
    $this->actingAs($this->admin)->postJson(($this->url)('cancel'))->assertOk()->assertJsonPath('run.status', 'cancelled');
    $this->assertDatabaseHas('nhl_import_progress', ['run_id' => $this->run->id, 'status' => 'skipped']);
    $this->getJson(route('admin.nhl-game-imports.status'))->assertOk()->assertJsonPath('runs.0.status', 'cancelled');
});

it('keeps completed progress intact when cancelling', function (): void {
    $completed = ($this->progress)('completed');
    ($this->progress)('running', 2026020002);
    $this->control->cancel($this->run->id);
    expect(DB::table('nhl_import_progress')->where('id', $completed)->value('status'))->toBe('completed');
});

it('does not change another run when cancelling', function (): void {
    $other = NhlGameImportRun::query()->create(['action' => 'discover', 'mode' => 'date', 'status' => 'running', 'date_count' => 1]);
    $this->control->cancel($this->run->id);
    expect($other->fresh()->status)->toBe('running');
});

it('prevents cancelled jobs from performing provider or database work', function (): void {
    $job = ($this->work)();
    $this->control->cancel($this->run->id);
    $called = false;
    (new ControlNhlGameImportRun())->handle($job, function () use (&$called): void { $called = true; });
    expect($called)->toBeFalse();
});

it('rolls back active database writes when the cancellation signal arrives', function (): void {
    $progress = ($this->progress)('running');
    (new ControlNhlGameImportRun())->handle(($this->work)(), function () use ($progress): void {
        DB::table('nhl_import_progress')->where('id', $progress)->update(['items_count' => 99]);
        Cache::put('nhl-import-cancel:'.$this->run->id.':initial', true, 60);
    });
    expect((int) DB::table('nhl_import_progress')->where('id', $progress)->value('items_count'))->toBe(1);
});

it('keeps stale queued jobs fenced out after a cancelled run is explicitly restarted', function (): void {
    $oldJob = ($this->work)();
    $this->control->cancel($this->run->id);
    $this->run->refresh()->update(['status' => 'running', 'payload' => $this->control->freshPayload($this->run->payload ?? [])]);
    $called = false;
    (new ControlNhlGameImportRun())->handle($oldJob, function () use (&$called): void { $called = true; });
    expect($called)->toBeFalse();
});

it('prevents a late completion callback from overwriting cancellation', function (): void {
    $snapshot = $this->run->fresh();
    $this->control->cancel($this->run->id);
    expect($this->control->saveWorkerRun($snapshot, ['status' => 'completed', 'payload' => []]))->toBeFalse()
        ->and($this->run->fresh()->status)->toBe('cancelled');
});

it('preserves Pause when a completion callback records progress', function (): void {
    $snapshot = $this->run->fresh();
    $this->control->pause($this->run->id);
    $this->control->saveWorkerRun($snapshot, ['status' => 'completed', 'payload' => ['checkpoint' => true]]);
    expect($this->run->fresh()->status)->toBe('paused')->and($this->run->fresh()->payload['checkpoint'])->toBeTrue();
});

it('ignores completion callbacks from an obsolete batch', function (): void {
    $this->run->update(['payload' => ['shot_fact_batch_id' => 'current']]);
    expect($this->control->acceptsBatchCallback($this->run, 'shot_fact_batch_id', 'old', true))->toBeFalse();
});

it('does not mark skipped paused batch work as completed', function (): void {
    $this->run->update(['status' => 'paused', 'payload' => ['shot_fact_batch_id' => 'fixture', 'shot_fact_game_ids' => [2026020001]]]);
    expect($this->control->acceptsBatchCallback($this->run, 'shot_fact_batch_id', 'fixture', true))->toBeFalse();
});

it('resumes a scoped batch with only its unfinished games', function (): void {
    $completedKey = $this->control->workKey(($this->work)());
    $this->run->update(['action' => 'process', 'status' => 'paused', 'payload' => [
        'process_scope' => 'shots', 'shot_fact_batch_id' => 'fixture', 'shot_fact_game_ids' => [2026020001, 2026020002],
        'run_control' => ['completed' => [$completedKey => true]],
    ]]);
    $batch = Mockery::mock(Batch::class);
    $batch->failedJobs = 0;
    $batch->shouldReceive('cancelled')->andReturnFalse();
    $batch->shouldReceive('add')->once()->with(Mockery::on(fn ($jobs) => count($jobs) === 1 && $jobs[0]->nhlGameId === 2026020002))->andReturnSelf();
    Bus::shouldReceive('findBatch')->with('fixture')->andReturn($batch);
    $this->control->resume($this->run->id);
    expect($this->run->fresh()->status)->toBe('running');
});

it('keeps a paused run paused when its batch is unavailable', function (): void {
    $this->run->update(['action' => 'process', 'status' => 'paused', 'payload' => [
        'process_scope' => 'shots', 'shot_fact_batch_id' => 'missing', 'shot_fact_game_ids' => [2026020001],
    ]]);
    $this->actingAs($this->admin)->postJson(($this->url)('resume'))->assertUnprocessable();
    expect($this->run->fresh()->status)->toBe('paused');
});

it('rejects controls for unrelated orchestration types', function (): void {
    $this->run->update(['action' => 'season-sync']);
    $this->actingAs($this->admin)->postJson(($this->url)('pause'))->assertUnprocessable();
});

it('rejects Pause and Cancel for a completed run', function (): void {
    $this->run->update(['status' => 'completed']);
    $this->actingAs($this->admin)->postJson(($this->url)('pause'))->assertUnprocessable();
    $this->postJson(($this->url)('cancel'))->assertUnprocessable();
});

it('returns not found for an unknown run', function (): void {
    $this->actingAs($this->admin)->postJson('/admin/nhl-game-imports/999999/pause')->assertNotFound();
});
