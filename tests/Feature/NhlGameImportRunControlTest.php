<?php

declare(strict_types=1);

use App\Jobs\BuildNhlShotAttemptFactsJob;
use App\Jobs\Middleware\ControlNhlGameImportRun;
use App\Jobs\NhlDiscoveryJob;
use App\Jobs\NhlOrchestratorJob;
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
    expect($this->run->fresh()->status)->toBe('paused')
        ->and(data_get($this->run->fresh()->payload, 'run_control.resume_pending', false))->toBeFalse();
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

it('allows run bookkeeping while two discovery writers retain parent foreign key locks', function (): void {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('This regression exercises PostgreSQL foreign-key row locks.');
    }

    // Independent sessions are essential: savepoints on one connection cannot reproduce this conflict.
    $connectionConfig = config('database.connections.'.config('database.default'));
    config(['database.connections.import_lock_writer_a' => $connectionConfig, 'database.connections.import_lock_writer_b' => $connectionConfig]);
    $writerA = DB::connection('import_lock_writer_a');
    $writerB = DB::connection('import_lock_writer_b');
    $runId = null;
    $baseline = DB::transactionLevel();
    try {
        // Commit this isolated fixture so both writer sessions can reference it.
        $runId = $writerA->table('nhl_game_import_runs')->insertGetId([
            'action' => 'discover', 'mode' => 'range', 'status' => 'running', 'date_count' => 2,
            'start_date' => '2026-10-22', 'end_date' => '2026-10-21',
            'payload' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([$writerA, $writerB] as $index => $writer) {
            $writer->beginTransaction();
            $writer->table('nhl_import_progress')->insert([
                'run_id' => $runId, 'game_id' => 2026020001 + $index, 'season_id' => '20262027',
                'game_date' => '2026-10-22', 'import_type' => 'pbp', 'status' => 'scheduled',
            ]);
        }

        DB::beginTransaction();
        // A bounded safety timeout turns the previous incompatible FOR UPDATE into a deterministic failure.
        DB::statement("SET LOCAL lock_timeout = '1000ms'");
        $token = $this->control->claim($runId, 'initial', 'concurrent-discovery', 180);
        expect($token)->not->toBeNull();
        $this->control->finish($runId, 'initial', 'concurrent-discovery', $token, true);
        expect(data_get(NhlGameImportRun::findOrFail($runId)->payload, 'run_control.active'))->toBe([]);
    } finally {
        while (DB::transactionLevel() > $baseline) {
            DB::rollBack();
        }
        foreach ([$writerA, $writerB] as $writer) {
            if ($writer->transactionLevel() > 0) {
                $writer->rollBack();
            }
        }
        if ($runId !== null) {
            $writerA->table('nhl_game_import_runs')->where('id', $runId)->delete();
        }
        DB::purge('import_lock_writer_a');
        DB::purge('import_lock_writer_b');
    }
});

it('can retry cancellation after progress cleanup was interrupted', function (): void {
    ($this->progress)('running');
    $this->run->update(['status' => 'cancelling']);
    $this->actingAs($this->admin)->postJson(($this->url)('cancel'))->assertOk()->assertJsonPath('run.status', 'cancelled');
    $this->assertDatabaseHas('nhl_import_progress', ['run_id' => $this->run->id, 'status' => 'skipped']);
    $this->getJson(route('admin.nhl-game-imports.status'))->assertOk()->assertJsonPath('runs.0.status', 'cancelled');
});

it('handles repeated cancellation without resetting completed results', function (): void {
    $completed = ($this->progress)('completed');
    $this->control->cancel($this->run->id);
    $this->control->cancel($this->run->id);
    expect($this->run->fresh()->status)->toBe('cancelled')
        ->and(DB::table('nhl_import_progress')->where('id', $completed)->value('status'))->toBe('completed');
});

it('blocks admission and late completion while cancellation cleanup is pending', function (): void {
    $snapshot = $this->run->fresh();
    $this->run->update(['status' => 'cancelling']);
    expect($this->control->canAdvance($this->run->id))->toBeFalse()
        ->and($this->control->claim($this->run->id, 'initial', 'fixture', 180))->toBeNull()
        ->and($this->control->saveWorkerRun($snapshot, ['status' => 'completed']))->toBeFalse()
        ->and($this->control->acceptsBatchCallback($this->run->fresh(), 'shot_fact_batch_id', 'fixture'))->toBeFalse();
});

it('rejects Resume and deletion while cancellation cleanup is pending', function (): void {
    $this->run->update(['status' => 'cancelling']);
    $this->actingAs($this->admin)->postJson(($this->url)('resume'))->assertUnprocessable();
    $this->deleteJson('/admin/nhl-game-imports/'.$this->run->id)->assertUnprocessable();
    expect($this->run->fresh()->status)->toBe('cancelling');
    Bus::assertNothingDispatched();
});

it('rejects a second Resume while progress reset is pending', function (): void {
    $this->run->update(['status' => 'paused', 'payload' => ['run_control' => ['generation' => 'resume-fixture', 'resume_pending' => true]]]);
    $this->actingAs($this->admin)->postJson(($this->url)('resume'))->assertUnprocessable();
    expect($this->control->generationFor($this->run->id))->toBe('resume-fixture');
    Bus::assertNothingDispatched();
});

it('keeps Cancel authoritative when it arrives during Resume progress cleanup', function (): void {
    ($this->progress)('running');
    $this->run->update(['status' => 'paused']);
    $interrupted = false;
    DB::listen(function (\Illuminate\Database\Events\QueryExecuted $query) use (&$interrupted): void {
        if ($interrupted || ! str_starts_with($query->sql, 'update "nhl_import_progress"') || ! in_array('scheduled', $query->bindings, true)) {
            return;
        }
        $interrupted = true;
        $current = $this->run->fresh();
        expect($current->status)->toBe('paused')->and(data_get($current->payload, 'run_control.resume_pending'))->toBeTrue();
        $this->control->cancel($this->run->id);
    });
    $this->actingAs($this->admin)->postJson(($this->url)('resume'))->assertUnprocessable();
    expect($interrupted)->toBeTrue()->and($this->run->fresh()->status)->toBe('cancelled');
    Bus::assertNothingDispatched();
});

it('coordinates stages without retaining a whole-job transaction', function (): void {
    $baseline = DB::transactionLevel();
    $calls = 0;
    $job = new NhlOrchestratorJob('2026-10-22', $this->run->id);
    (new ControlNhlGameImportRun())->handle($job, function () use ($baseline, &$calls): void {
        $calls++;
        expect(DB::transactionLevel())->toBe($baseline);
    });
    expect($calls)->toBe(1)->and(data_get($this->run->fresh()->payload, 'run_control.active'))->toBe([]);
});

it('retains atomic database writes for actual transformation jobs', function (): void {
    $baseline = DB::transactionLevel();
    $progress = ($this->progress)('running');
    (new ControlNhlGameImportRun())->handle(($this->work)(), function () use ($baseline, $progress): void {
        expect(DB::transactionLevel())->toBe($baseline + 1);
        DB::table('nhl_import_progress')->where('id', $progress)->update(['items_count' => 9]);
    });
    expect((int) DB::table('nhl_import_progress')->where('id', $progress)->value('items_count'))->toBe(9);
});

it('does not replay provider work when its transaction fails', function (): void {
    $calls = 0;
    $progress = ($this->progress)('running');
    expect(function () use (&$calls, $progress): void {
        (new ControlNhlGameImportRun())->handle(($this->work)(), function () use (&$calls, $progress): void {
            $calls++;
            DB::table('nhl_import_progress')->where('id', $progress)->update(['items_count' => 99]);
            throw new RuntimeException('Provider transformation failed.');
        });
    })->toThrow(RuntimeException::class, 'Provider transformation failed.');
    expect($calls)->toBe(1)
        ->and((int) DB::table('nhl_import_progress')->where('id', $progress)->value('items_count'))->toBe(1);
});


describe('scoped on-ice rebuilds', function (): void {
    beforeEach(function (): void {
        $this->run->update(['status' => 'completed']);
        $this->onIceUrl = '/admin/nhl-game-imports/process-on-ice';
        $this->seedOnIceGame = function (int $id = 2026020001, string $date = '2026-10-22', string $season = '20262027'): void {
            DB::table('nhl_games')->insert(['nhl_game_id' => $id, 'season_id' => $season, 'game_type' => 2,
                'game_date' => $date, 'game_state' => 'OFF', 'home_team_score' => 1, 'away_team_score' => 0]);
            DB::table('play_by_plays')->insert(['nhl_game_id' => $id]);
            DB::table('nhl_boxscores')->insert(['nhl_game_id' => $id, 'nhl_player_id' => 1, 'nhl_team_id' => 1]);
            DB::table('nhl_shifts')->insert(['nhl_game_id' => $id, 'nhl_player_id' => 1, 'shift_number' => 1,
                'period' => 1, 'start_time' => '00:00', 'end_time' => '01:00', 'shift_start_seconds' => 0,
                'shift_end_seconds' => 60, 'team_abbrev' => 'TOR', 'team_name' => 'Toronto', 'first_name' => 'Test', 'last_name' => 'Player']);
        };
        $this->onIceRun = function (string $status = 'running', array $completed = []): void {
            $this->run->update(['action' => 'process', 'status' => $status, 'payload' => [
                'process_scope' => 'on_ice', 'on_ice_game_ids' => [2026020001], 'on_ice_batch_id' => 'on-ice-fixture',
                'run_control' => ['completed' => $completed],
            ]]);
        };
    });

    it('denies guests the on-ice processing endpoint', function (): void {
        $this->postJson($this->onIceUrl, ['run_id' => $this->run->id])->assertUnauthorized();
        Bus::assertNothingBatched();
    });

    it('denies non-admins the on-ice processing endpoint', function (): void {
        $this->actingAs(User::factory()->create())->postJson($this->onIceUrl, ['run_id' => $this->run->id])->assertForbidden();
        Bus::assertNothingBatched();
    });

    it('queues only derived on-ice jobs and preserves the original discovery', function (): void {
        ($this->seedOnIceGame)();
        $response = $this->actingAs($this->admin)->postJson($this->onIceUrl, ['run_id' => $this->run->id]);
        $response->assertAccepted()->assertJsonPath('run.payload.process_scope', 'on_ice')
            ->assertJsonPath('run.progress.completed_stage_rows', 0)->assertJsonPath('run.progress.total_stage_rows', 1);
        expect($response->json('run.id'))->not->toBe($this->run->id)
            ->and($this->run->fresh()->status)->toBe('completed');
        Bus::assertBatched(fn ($batch): bool => $batch->jobs->count() === 1
            && $batch->jobs->first() instanceof \App\Jobs\RebuildNhlOnIceStatsJob
            && $batch->jobs->first()->queue === 'default');
        Bus::assertNotDispatched(\App\Jobs\ImportPbpNhlJob::class);
        Bus::assertNotDispatched(\App\Jobs\BuildNhlShotAttemptFactsJob::class);
    });

    it('rejects an invalid source run without dispatching work', function (): void {
        $this->actingAs($this->admin)->postJson($this->onIceUrl, ['run_id' => 99999999])->assertUnprocessable();
        Bus::assertNothingBatched();
    });

    it('rejects a selection without stored source coverage', function (): void {
        $this->actingAs($this->admin)->postJson($this->onIceUrl, ['run_id' => $this->run->id])->assertUnprocessable();
        Bus::assertNothingBatched();
    });

    it('rejects a paused source discovery', function (): void {
        $this->run->update(['status' => 'paused']);
        $this->actingAs($this->admin)->postJson($this->onIceUrl, ['run_id' => $this->run->id])->assertUnprocessable();
    });

    it('limits queued games to the selected discovery range', function (): void {
        ($this->seedOnIceGame)();
        ($this->seedOnIceGame)(2026020002, '2026-10-23');
        $this->actingAs($this->admin)->postJson($this->onIceUrl, ['run_id' => $this->run->id])->assertAccepted()
            ->assertJsonPath('run.payload.on_ice_game_ids', [2026020001]);
    });

    it('does not admit a duplicate active on-ice selection', function (): void {
        ($this->seedOnIceGame)();
        $this->actingAs($this->admin)->postJson($this->onIceUrl, ['run_id' => $this->run->id])->assertAccepted();
        $this->postJson($this->onIceUrl, ['run_id' => $this->run->id])->assertUnprocessable();
    });

    it('rejects games currently running through the canonical import pipeline', function (): void {
        ($this->seedOnIceGame)();
        ($this->progress)('running');
        $this->actingAs($this->admin)->postJson($this->onIceUrl, ['run_id' => $this->run->id])->assertUnprocessable();
    });

    it('checkpoints successful on-ice games through the existing commit fence', function (): void {
        ($this->onIceRun)();
        $job = new \App\Jobs\RebuildNhlOnIceStatsJob(2026020001, $this->run->id);
        (new ControlNhlGameImportRun())->handle($job, function (): void {});
        expect(data_get($this->run->fresh()->payload, 'run_control.completed.on_ice:2026020001'))->toBeTrue();
    });

    it('resumes only unfinished on-ice games in the original batch', function (): void {
        ($this->onIceRun)('paused', ['on_ice:2026020001' => true]);
        $payload = $this->run->payload;
        $payload['on_ice_game_ids'][] = 2026020002;
        $this->run->update(['payload' => $payload]);
        $batch = Mockery::mock(Batch::class);
        $batch->failedJobs = 0;
        $batch->shouldReceive('cancelled')->andReturnFalse();
        $batch->shouldReceive('add')->once()->with(Mockery::on(fn ($jobs): bool => count($jobs) === 1
            && $jobs[0] instanceof \App\Jobs\RebuildNhlOnIceStatsJob && $jobs[0]->nhlGameId === 2026020002))->andReturnSelf();
        Bus::shouldReceive('findBatch')->with('on-ice-fixture')->andReturn($batch);
        $this->control->resume($this->run->id);
        expect($this->run->fresh()->status)->toBe('running');
    });

    it('does not queue season sync while an on-ice game remains unfinished', function (): void {
        ($this->seedOnIceGame)();
        ($this->onIceRun)();
        app(\App\Services\NhlGameImportRebuilder::class)->finishOnIceRun($this->run->id, 'on-ice-fixture');
        Bus::assertNotDispatched(\App\Jobs\SeasonSumJob::class);
        expect($this->run->fresh()->status)->toBe('running');
    });

    it('queues one tracked Season Sync after all games complete and rejects duplicate completion', function (): void {
        ($this->seedOnIceGame)();
        ($this->onIceRun)('running', ['on_ice:2026020001' => true]);
        $rebuilder = app(\App\Services\NhlGameImportRebuilder::class);
        $rebuilder->finishOnIceRun($this->run->id, 'on-ice-fixture');
        $rebuilder->finishOnIceRun($this->run->id, 'on-ice-fixture');
        expect($this->run->fresh()->status)->toBe('completed')
            ->and($this->run->fresh()->payload['on_ice_season_sync_run_ids'])->toHaveCount(1);
        Bus::assertDispatchedTimes(\App\Jobs\SeasonSumJob::class, 1);
        $this->assertDatabaseHas('nhl_game_import_runs', ['action' => 'season-sync', 'status' => 'queued']);
    });

    it('keeps fully rebuilt paused work paused until Resume queues the season sync', function (): void {
        ($this->seedOnIceGame)();
        ($this->onIceRun)('paused', ['on_ice:2026020001' => true]);
        app(\App\Services\NhlGameImportRebuilder::class)->finishOnIceRun($this->run->id, 'on-ice-fixture');
        Bus::assertNotDispatched(\App\Jobs\SeasonSumJob::class);
        $this->control->resume($this->run->id);
        expect($this->run->fresh()->status)->toBe('completed');
        Bus::assertDispatched(\App\Jobs\SeasonSumJob::class);
    });

    it('prevents cancelled on-ice work from queueing a season sync', function (): void {
        ($this->onIceRun)('cancelled', ['on_ice:2026020001' => true]);
        app(\App\Services\NhlGameImportRebuilder::class)->finishOnIceRun($this->run->id, 'on-ice-fixture');
        Bus::assertNotDispatched(\App\Jobs\SeasonSumJob::class);
    });

    it('fences cancelled on-ice jobs before the transformation begins', function (): void {
        ($this->onIceRun)('cancelled');
        $called = false;
        (new ControlNhlGameImportRun())->handle(new \App\Jobs\RebuildNhlOnIceStatsJob(2026020001, $this->run->id),
            function () use (&$called): void { $called = true; });
        expect($called)->toBeFalse();
    });

    it('reports on-ice progress from this run checkpoints rather than stale summary rows', function (): void {
        ($this->onIceRun)();
        $response = $this->actingAs($this->admin)->getJson(route('admin.nhl-game-imports.status'))->assertOk();
        $run = collect($response->json('runs'))->firstWhere('id', $this->run->id);
        expect($run['progress']['completed_stage_rows'])->toBe(0)->and($run['facts'])->toBe([]);
    });
});
