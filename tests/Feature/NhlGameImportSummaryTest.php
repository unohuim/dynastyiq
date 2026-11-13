<?php

declare(strict_types=1);

use App\Events\NhlGameImportStatusUpdated;
use App\Listeners\RefreshNhlImportProgressSummary;
use App\Models\NhlGameImportRun;
use App\Models\Role;
use App\Models\User;
use App\Repositories\NhlImportProgressRepo;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-13 12:00:00 UTC'));
    Bus::fake();
    Event::fake();
    Http::preventStrayRequests();
    $this->mock(\App\Services\PlatformState::class)->shouldReceive('seeded')->andReturn(true);
    $this->admin = User::factory()->create();
    $role = Role::firstOrCreate(['slug' => 'super-admin'], ['name' => 'Super Admin', 'level' => 99]);
    $this->admin->roles()->attach($role->id, ['organization_id' => null]);
    $this->run = NhlGameImportRun::create(['action' => 'discover', 'mode' => 'date', 'status' => 'running',
        'start_date' => '2026-11-12', 'end_date' => '2026-11-12', 'date_count' => 1,
        'payload' => ['processing_started_at' => now()->toIso8601String()]]);
    $this->repo = app(NhlImportProgressRepo::class);
    $this->source = function (string $status = 'scheduled', string $type = 'pbp', int $game = 2026020299): void {
        DB::table('nhl_import_progress')->insert(['run_id' => $this->run->id, 'game_id' => $game,
            'season_id' => '20262027', 'game_date' => '2026-11-12', 'import_type' => $type,
            'status' => $status, 'items_count' => 0, 'created_at' => now(), 'updated_at' => now()]);
    };
    $this->statusUrl = route('admin.nhl-game-imports.status');
    $this->detailsUrl = route('admin.nhl-game-imports.games', $this->run);
    // Exercise the post-commit listener directly; the feature-test transaction itself never commits.
    $this->publish = fn () => app(RefreshNhlImportProgressSummary::class)
        ->handle(new NhlGameImportStatusUpdated('stage-completed', $this->run->id));
});

afterEach(function (): void {
    $this->travelBack();
});

it('denies guests the saved import status endpoint', function (): void {
    $this->getJson($this->statusUrl)->assertUnauthorized();
});

it('denies ordinary users the saved import status endpoint', function (): void {
    $this->actingAs(User::factory()->create())->getJson($this->statusUrl)->assertForbidden();
});

it('denies guests the lazy game details endpoint', function (): void {
    $this->getJson($this->detailsUrl)->assertUnauthorized();
});

it('denies ordinary users the lazy game details endpoint', function (): void {
    $this->actingAs(User::factory()->create())->getJson($this->detailsUrl)->assertForbidden();
});

it('returns not found for an unknown lazy detail run', function (): void {
    $this->actingAs($this->admin)->getJson('/admin/nhl-game-imports/999999/games')->assertNotFound();
});

it('initializes an older visible run once and returns its saved progress without games', function (): void {
    ($this->source)();
    $this->actingAs($this->admin)->getJson($this->statusUrl)->assertOk()
        ->assertJsonPath('runs.0.progress.scheduled_stage_rows', 1)->assertJsonPath('runs.0.games', [])
        ->assertJsonPath('runs.0.games_url', $this->detailsUrl);
    expect(data_get($this->run->fresh()->payload, 'progress_summary.counts.scheduled'))->toBe(1);
    Bus::assertNothingDispatched();
});

it('allows a super admin to read owned game diagnostics separately', function (): void {
    ($this->source)('error');
    $this->actingAs($this->admin)->getJson($this->detailsUrl)->assertOk()
        ->assertJsonPath('run_id', $this->run->id)->assertJsonPath('games.0.game_id', 2026020299)
        ->assertJsonPath('games.0.failed_stage_rows', 1);
});

it('keeps lazy game diagnostics isolated to their owning discovery run', function (): void {
    $other = NhlGameImportRun::create(['action' => 'discover', 'status' => 'failed', 'mode' => 'date']);
    ($this->source)('running');
    DB::table('nhl_import_progress')->insert(['run_id' => $other->id, 'game_id' => 2026020299,
        'season_id' => '20262027', 'game_date' => '2026-11-12', 'import_type' => 'boxscore', 'status' => 'error']);
    $this->actingAs($this->admin)->getJson($this->detailsUrl)->assertOk()
        ->assertJsonPath('games.0.total_stage_rows', 1)->assertJsonPath('games.0.failed_stage_rows', 0);
});

it('reads prepared status without per-run source counting or loading details', function (): void {
    ($this->source)('running');
    $this->repo->refreshRunSummary($this->run->id);
    $queries = [];
    DB::listen(function ($query) use (&$queries): void { $queries[] = $query->sql; });
    $this->actingAs($this->admin)->getJson($this->statusUrl)->assertOk()
        ->assertJsonPath('runs.0.progress.running_stage_rows', 1)->assertJsonPath('runs.0.games', []);
    $progress = array_values(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'nhl_import_progress')));
    // The one global scheduled-date count remains; no run-level aggregation or game listing.
    expect($progress)->toHaveCount(1)->and(strtolower($progress[0]))->toContain('distinct')
        ->and(strtolower($progress[0]))->not->toContain('group by');
});

it('publishes a completed source mutation into saved status and subsequent HTTP reads', function (): void {
    ($this->source)('running');
    $this->repo->refreshRunSummary($this->run->id);
    $this->repo->markCompleted(2026020299, 'pbp', 12, $this->run->id);
    ($this->publish)();
    $this->assertDatabaseHas('nhl_import_progress', ['run_id' => $this->run->id, 'status' => 'completed', 'items_count' => 12]);
    expect(data_get($this->run->fresh()->payload, 'progress_summary.counts.completed'))->toBe(1);
    $this->actingAs($this->admin)->getJson($this->statusUrl)->assertOk()
        ->assertJsonPath('runs.0.progress.completed_stage_rows', 1)->assertJsonPath('runs.0.progress.percentage', 100);
});

it('does not double count repeated source notifications', function (): void {
    ($this->source)('completed');
    ($this->publish)();
    ($this->publish)();
    expect(data_get($this->run->fresh()->payload, 'progress_summary.counts.completed'))->toBe(1);
});

it('stores latest failure diagnostics alongside source counts', function (): void {
    ($this->source)();
    $this->repo->markError(2026020299, 'pbp', 'Provider unavailable', runId: $this->run->id,
        sentryEventId: 'trace-fixture', failureCategory: 'provider', retryable: true);
    ($this->publish)();
    $this->actingAs($this->admin)->getJson($this->statusUrl)->assertOk()
        ->assertJsonPath('runs.0.progress.last_error', 'Provider unavailable')
        ->assertJsonPath('runs.0.progress.sentry_event_id', 'trace-fixture')
        ->assertJsonPath('runs.0.progress.retryable', true);
});

it('clears saved failure diagnostics after a source reschedule', function (): void {
    ($this->source)('error');
    $this->repo->rescheduleExistingRowsForRun($this->run->id, '2026-11-12', '2026-11-12');
    ($this->publish)();
    expect(data_get($this->run->fresh()->payload, 'progress_summary.latest_error'))->toBeNull()
        ->and(data_get($this->run->fresh()->payload, 'progress_summary.counts.scheduled'))->toBe(1);
});

it('retains skipped stage counts in the saved projection', function (): void {
    ($this->source)();
    $this->repo->markSkipped(2026020299, ['pbp'], 'Source incomplete', $this->run->id);
    ($this->publish)();
    $this->actingAs($this->admin)->getJson($this->statusUrl)->assertOk()
        ->assertJsonPath('runs.0.progress.skipped_stage_rows', 1)->assertJsonPath('runs.0.facts.skipped_game_count', 1);
});

it('does not borrow date-range stages for an empty discovery snapshot', function (): void {
    DB::table('nhl_import_progress')->insert(['game_id' => 2026020299, 'season_id' => '20262027',
        'game_date' => '2026-11-12', 'import_type' => 'pbp', 'status' => 'error']);
    $summary = $this->repo->refreshRunSummary($this->run->id);
    expect($summary['counts'])->toBe([])->and($summary['owned_stage_rows'])->toBe(0);
});

it('preserves current cancellation and checkpoint metadata while publishing counts', function (): void {
    $payload = $this->run->payload;
    $payload['run_control'] = ['generation' => 'retained', 'completed' => ['shots:2026020299' => true]];
    $this->run->update(['status' => 'paused', 'payload' => $payload]);
    ($this->source)('completed');
    $this->repo->refreshRunSummary($this->run->id);
    expect($this->run->fresh()->status)->toBe('paused')
        ->and($this->run->fresh()->payload['run_control'])->toBe($payload['run_control']);
});

it('does not change the run lifecycle timestamp when publishing a summary', function (): void {
    $before = $this->run->updated_at->toDateTimeString();
    $this->travel(5)->minutes();
    $this->repo->refreshRunSummary($this->run->id);
    expect($this->run->fresh()->updated_at->toDateTimeString())->toBe($before);
});

it('refreshes totals when additional source stages are seeded', function (): void {
    ($this->source)();
    $this->repo->refreshRunSummary($this->run->id);
    ($this->source)('completed', 'boxscore');
    ($this->publish)();
    $summary = $this->run->fresh()->payload['progress_summary'];
    expect($summary['owned_stage_rows'])->toBe(2)->and($summary['facts']['discovered_game_count'])->toBe(1);
});

it('retains explicit legacy process date-range compatibility', function (): void {
    $this->run->update(['action' => 'process']);
    DB::table('nhl_import_progress')->insert(['game_id' => 2026020299, 'season_id' => '20262027',
        'game_date' => '2026-11-12', 'import_type' => 'pbp', 'status' => 'completed']);
    $summary = $this->repo->refreshRunSummary($this->run->id);
    expect($summary['counts']['completed'])->toBe(1)->and($summary['owned_stage_rows'])->toBe(0);
});

it('rolls back a summary publication with its surrounding transaction', function (): void {
    DB::beginTransaction();
    try {
        ($this->source)();
        $this->repo->refreshRunSummary($this->run->id);
    } finally {
        DB::rollBack();
    }
    expect(data_get($this->run->fresh()->payload, 'progress_summary'))->toBeNull();
});

it('rejects malformed optional inline detail requests', function (): void {
    $this->actingAs($this->admin)->getJson($this->statusUrl.'?include_games=unexpected')->assertUnprocessable();
});

it('retains explicit inline game details for existing admin consumers', function (): void {
    ($this->source)('running');
    $this->actingAs($this->admin)->getJson($this->statusUrl.'?include_games=1')->assertOk()
        ->assertJsonPath('runs.0.games.0.game_id', 2026020299);
});

it('saves per-game category counts without requiring expanded rows', function (): void {
    ($this->source)('running');
    ($this->source)('skipped', 'pbp', 2026020300);
    ($this->source)('error', 'pbp', 2026020301);
    $summary = $this->repo->refreshRunSummary($this->run->id);
    expect($summary['facts']['active_game_count'])->toBe(1)
        ->and($summary['facts']['skipped_game_count'])->toBe(1)
        ->and($summary['facts']['failed_game_count'])->toBe(1);
});

it('keeps source publications scoped when another run shares the same game', function (): void {
    ($this->source)('completed');
    $other = NhlGameImportRun::create(['action' => 'discover', 'mode' => 'date', 'status' => 'running']);
    DB::table('nhl_import_progress')->insert(['run_id' => $other->id, 'game_id' => 2026020299,
        'season_id' => '20262027', 'game_date' => '2026-11-12', 'import_type' => 'pbp', 'status' => 'running']);
    ($this->publish)();
    expect(data_get($this->run->fresh()->payload, 'progress_summary.counts.completed'))->toBe(1)
        ->and(data_get($other->fresh()->payload, 'progress_summary'))->toBeNull();
});

it('preserves saved progress across an HTTP pause database update and subsequent status read', function (): void {
    ($this->source)('scheduled');
    $this->repo->refreshRunSummary($this->run->id);
    $this->actingAs($this->admin)->postJson(route('admin.nhl-game-imports.pause', $this->run))->assertOk();
    $this->assertDatabaseHas('nhl_game_import_runs', ['id' => $this->run->id, 'status' => 'paused']);
    $this->assertDatabaseHas('nhl_import_progress', ['run_id' => $this->run->id, 'status' => 'scheduled']);
    $this->getJson($this->statusUrl)->assertOk()->assertJsonPath('runs.0.status', 'paused')
        ->assertJsonPath('runs.0.progress.scheduled_stage_rows', 1)->assertJsonPath('runs.0.games', []);
});

it('retains validation based failed rerun counts and clears them after correction', function (): void {
    \App\Models\NhlGame::create(['nhl_game_id' => 2026020299, 'season_id' => '20262027',
        'game_type' => 2, 'game_date' => '2026-11-12', 'game_dow' => 'Thursday', 'game_month' => 'November',
        'start_time_utc' => '2026-11-12 23:00:00', 'game_state' => 'OFF', 'away_team_id' => 1, 'home_team_id' => 2]);
    DB::table('nhl_game_validations')->insert(['nhl_game_id' => 2026020299, 'validation_type' => 'summary_boxscore', 'status' => 'failed']);
    ($this->source)('completed');
    $summary = $this->repo->refreshRunSummary($this->run->id);
    expect($summary['facts']['failed_rerun_game_count'])->toBe(1);
    DB::table('nhl_game_validations')->where('nhl_game_id', 2026020299)->update(['status' => 'approved']);
    ($this->publish)();
    $this->actingAs($this->admin)->getJson($this->statusUrl)->assertOk()
        ->assertJsonPath('runs.0.facts.failed_rerun_game_count', 0);
});

it('publishes a shared notification projection only once within the same dispatch', function (): void {
    $this->mock(NhlImportProgressRepo::class)->shouldReceive('refreshRunSummary')->once()
        ->with($this->run->id)->andReturn([]);
    $event = new NhlGameImportStatusUpdated('stage-completed', $this->run->id);
    $listener = app(RefreshNhlImportProgressSummary::class);
    $listener->handle($event);
    $listener->handle($event);
    expect($event->progressSummaryPrepared)->toBeTrue();
});

it('keeps projection bookkeeping out of the existing broadcast payload', function (): void {
    $event = new NhlGameImportStatusUpdated('stage-completed', $this->run->id, 2026020299, 'pbp');
    $event->progressSummaryPrepared = true;
    expect(array_keys($event->broadcastWith()))->toBe(['reason', 'run_id', 'game_id', 'stage', 'timestamp'])
        ->and($event->broadcastWith()['run_id'])->toBe($this->run->id);
});
