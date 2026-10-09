<?php

declare(strict_types=1);

use App\Events\GamesDiscovered;
use App\Jobs\NhlDiscoverDayJob;
use App\Jobs\NhlDiscoveryJob;
use App\Listeners\StartDiscoveredGameProcessing;
use App\Models\NhlGameImportRun;
use App\Models\Role;
use App\Models\ScheduledProcess;
use App\Models\User;
use App\Services\DiscoveredGameProcessing;
use App\Services\NhlDiscoverGames;
use App\Services\NhlImportOrchestrator;
use App\Services\ScheduledProcessManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-10 08:00:00 UTC'));
    Http::preventStrayRequests();
    Bus::fake();
    config(['cache.default' => 'array']);
    $this->mock(\App\Services\PlatformState::class)->shouldReceive('seeded')->andReturn(true);
    $this->admin = User::factory()->create();
    $role = Role::query()->create(['name' => 'Super Admin', 'slug' => 'super-admin', 'level' => 99]);
    $this->admin->roles()->attach($role->id, ['organization_id' => null]);
    $this->url = '/admin/scheduled-processes/nhl-game-discovery';
    $this->process = ScheduledProcess::query()->where('key', 'nhl-game-discovery')->firstOrFail();
    $this->manager = app(ScheduledProcessManager::class);
    $this->run = fn (array $attributes = []) => NhlGameImportRun::query()->create([
        'action' => 'discover', 'mode' => 'range', 'status' => 'completed',
        'start_date' => '2026-10-09', 'end_date' => '2026-10-09', 'date_count' => 1,
        'payload' => ['discovery_completed_dates' => ['2026-10-09']], ...$attributes,
    ]);
});

afterEach(function (): void {
    Http::assertNothingSent();
    $this->travelBack();
});

it('denies guest access to settings', function (string $verb): void {
    $this->json($verb, $this->url, ['enabled' => false])->assertUnauthorized();
})->with(['GET', 'PUT']);

it('denies non-admin settings access', function (string $verb): void {
    $this->actingAs(User::factory()->create())->json($verb, $this->url, ['enabled' => false])->assertForbidden();
})->with(['GET', 'PUT']);

it('provides the seeded Toronto defaults', function (): void {
    $this->actingAs($this->admin)->getJson($this->url)->assertOk()
        ->assertJsonPath('enabled', true)->assertJsonPath('start_time', '03:50')
        ->assertJsonPath('timezone', 'America/Toronto')->assertJsonPath('frequency_hours', 24)
        ->assertJsonPath('settings.days_back', 3);
});

it('persists the recycle toggle without dispatching work', function (): void {
    $this->actingAs($this->admin)->putJson($this->url, ['enabled' => false])->assertOk();
    $this->assertDatabaseHas('scheduled_processes', ['key' => 'nhl-game-discovery', 'enabled' => false]);
    $this->getJson($this->url)->assertJsonPath('enabled', false)->assertJsonPath('frequency_hours', 24);
    Bus::assertNothingDispatched();
});

it('persists and reads edited timing and scope', function (): void {
    $this->actingAs($this->admin)->putJson($this->url, ['start_time' => '05:20', 'frequency_hours' => 12, 'days_back' => 5])->assertOk();
    $this->assertDatabaseHas('scheduled_processes', ['start_time' => '05:20', 'frequency_hours' => 12]);
    $this->getJson($this->url)->assertJsonPath('settings.days_back', 5)->assertJsonPath('enabled', true);
    Bus::assertNothingDispatched();
});

it('rejects invalid schedule inputs', function (array $input, string $field): void {
    $this->actingAs($this->admin)->putJson($this->url, $input)->assertUnprocessable()->assertJsonValidationErrors($field);
    expect($this->process->fresh()->frequency_hours)->toBe(24);
})->with([
    [['start_time' => '26:00'], 'start_time'], [['frequency_hours' => 0], 'frequency_hours'],
    [['frequency_hours' => 1.5], 'frequency_hours'], [['frequency_hours' => 169], 'frequency_hours'],
    [['days_back' => 0], 'days_back'], [['days_back' => 32], 'days_back'], [['enabled' => 'yes'], 'enabled'],
]);

it('does not accept arbitrary process keys or command execution', function (): void {
    $this->actingAs($this->admin)->putJson('/admin/scheduled-processes/arbitrary', ['command' => 'test'])->assertNotFound();
    Bus::assertNothingDispatched();
});

it('does not dispatch disabled schedules', function (): void {
    $this->process->update(['enabled' => false]);
    $this->manager->tick();
    Bus::assertNothingDispatched();
});

it('waits for the Toronto start time', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-10 07:49:00 UTC'));
    $this->manager->tick();
    Bus::assertNothingDispatched();
});

it('dispatches yesterday and the prior two dates without today', function (): void {
    $this->manager->tick();
    Bus::assertDispatched(NhlDiscoveryJob::class, fn ($job) => $job->start->toDateString() === '2026-10-09'
        && $job->end->toDateString() === '2026-10-07' && $job->afterCommit === true);
    $this->assertDatabaseHas('nhl_game_import_runs', ['date_count' => 3, 'start_date' => '2026-10-09', 'end_date' => '2026-10-07']);
    expect($this->process->fresh()->last_dispatched_at)->not->toBeNull();
});

it('does not double-dispatch repeated scheduler ticks', function (): void {
    $this->manager->tick();
    $this->manager->tick();
    Bus::assertDispatchedTimes(NhlDiscoveryJob::class, 1);
});

it('respects the cross-tick dispatch lock', function (): void {
    $lock = Cache::lock('scheduled-processes:dispatch', 60);
    $lock->get();
    try {
        $this->manager->tick();
        Bus::assertNothingDispatched();
    } finally {
        $lock->release();
    }
});

it('does not overlap an active automatic discovery run', function (): void {
    ($this->run)(['status' => 'running', 'payload' => ['scheduled_process' => 'nhl-game-discovery']]);
    $this->manager->tick();
    Bus::assertNothingDispatched();
});

it('keeps the daily wall-clock time across daylight saving', function (): void {
    $next = $this->manager->nextDue($this->process, CarbonImmutable::parse('2026-10-31 07:50:00 UTC'));
    expect($next->toDateTimeString())->toBe('2026-11-01 08:50:00');
});

it('uses actual dispatch time for non-daily intervals', function (): void {
    $this->process->frequency_hours = 6;
    expect($this->manager->nextDue($this->process, CarbonImmutable::parse('2026-10-10 08:00:00 UTC'))->hour)->toBe(14);
});

it('registers the after-commit discovery event and queued listener', function (): void {
    Event::fake();
    expect(new GamesDiscovered(1))->toBeInstanceOf(\Illuminate\Contracts\Events\ShouldDispatchAfterCommit::class);
    Event::assertListening(GamesDiscovered::class, StartDiscoveredGameProcessing::class);
    expect(new StartDiscoveredGameProcessing())->toBeInstanceOf(\Illuminate\Contracts\Queue\ShouldQueueAfterCommit::class);
});

it('emits readiness only on the final distinct discovery date', function (): void {
    $run = ($this->run)(['status' => 'running', 'date_count' => 2,
        'payload' => ['discovery_completed_dates' => ['2026-10-08']]]);
    // Intercept dispatch itself; ShouldDispatchAfterCommit supplies transaction timing.
    Event::shouldReceive('dispatch')->with(Mockery::on(fn ($event) => $event instanceof GamesDiscovered
        && $event->runId === $run->id))->once();
    Event::shouldReceive('dispatch')->with(Mockery::on(fn ($event) => $event instanceof \App\Events\NhlGameImportStatusUpdated))->zeroOrMoreTimes();
    Event::shouldReceive('dispatch')->withArgs(fn ($event, ...$args) => is_string($event))->zeroOrMoreTimes();
    Event::shouldReceive('until')->andReturnNull();
    $service = Mockery::mock(NhlDiscoverGames::class);
    $service->shouldReceive('discoverDay')->twice()->with('2026-10-09', $run->id);
    $job = new NhlDiscoverDayJob('2026-10-09', $run->id);
    $job->handle($service);
    $job->handle($service);
    expect($run->fresh()->payload['discovery_completed_dates'])->toBe(['2026-10-08', '2026-10-09']);
});

it('starts only committed discovered runs through the existing orchestrator', function (): void {
    $run = ($this->run)();
    DB::table('nhl_import_progress')->insert(['run_id' => $run->id, 'game_id' => '2026020001',
        'game_date' => '2026-10-09', 'season_id' => '20262027', 'import_type' => 'pbp', 'status' => 'scheduled']);
    $this->mock(NhlImportOrchestrator::class)->shouldReceive('fillActiveGameSlotsForRun')->once()->with($run->id)->andReturn(1);
    (new StartDiscoveredGameProcessing())->handle(new GamesDiscovered($run->id));
    expect($run->fresh()->status)->toBe('running')->and($run->fresh()->payload['processing_started_at'])->not->toBeNull();
});

it('ignores repeated processing events after terminal processing', function (): void {
    $run = ($this->run)(['payload' => ['discovery_completed_dates' => ['2026-10-09'], 'processing_started_at' => '2026-10-10T07:00:00Z']]);
    $this->mock(NhlImportOrchestrator::class)->shouldNotReceive('fillActiveGameSlotsForRun');
    app(DiscoveredGameProcessing::class)->start($run->id);
    expect($run->fresh()->status)->toBe('completed');
});

it('does not start processing before all discovery dates finish', function (): void {
    $run = ($this->run)(['date_count' => 3]);
    $this->mock(NhlImportOrchestrator::class)->shouldNotReceive('fillActiveGameSlotsForRun');
    app(DiscoveredGameProcessing::class)->start($run->id);
});

it('does not start empty or failed discoveries', function (string $status): void {
    $run = ($this->run)(['status' => $status]);
    $this->mock(NhlImportOrchestrator::class)->shouldNotReceive('fillActiveGameSlotsForRun');
    app(DiscoveredGameProcessing::class)->start($run->id);
})->with(['completed', 'failed']);

it('marks failed discovery parents so subsequent schedules are not stranded', function (): void {
    $run = ($this->run)(['status' => 'running']);
    (new NhlDiscoverDayJob('2026-10-09', $run->id))->failed(new \RuntimeException('test'));
    expect($run->fresh()->status)->toBe('failed');
});

it('removes direct discovery and minute-by-minute processing from Laravel scheduling', function (): void {
    $commands = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->pluck('command')->implode('\n');
    expect($commands)->toContain('processes:dispatch-due')->not->toContain('nhl:process')->not->toContain('nhl:discover');
});
