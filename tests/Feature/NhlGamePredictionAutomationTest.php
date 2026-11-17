<?php

declare(strict_types=1);

use App\Events\NhlPregameContextDayCompleted;
use App\Jobs\RunNhlGamePredictionJob;
use App\Listeners\QueueNhlGamePredictions;
use App\Models\NhlGame;
use App\Models\NhlModelRun;
use App\Models\NhlPregameContextRun;
use App\Models\NhlPregameContextRunDay;
use App\Models\NhlPregameContextRunGame;
use App\Models\Role;
use App\Models\ScheduledProcess;
use App\Models\User;
use App\Services\NhlGamePredictionHistory;
use App\Services\NhlPregameContextBuilder;
use App\Services\NhlPregameContextOrchestrator;
use App\Services\ScheduledProcessManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 11:00:00 UTC'));
    config(['cache.default' => 'array']);
    Cache::flush();
    Bus::fake();
    Http::preventStrayRequests();
    $this->mock(\App\Services\PlatformState::class)->shouldReceive('seeded')->andReturn(true);
    ScheduledProcess::query()->update(['enabled' => false]);
    $this->admin = User::factory()->create();
    $role = Role::create(['name' => 'Super Admin', 'slug' => 'super-admin', 'level' => 99, 'scope' => 'global', 'is_active' => true]);
    $this->admin->roles()->attach($role->id, ['organization_id' => null]);
    $this->url = '/admin/game-predictions/automation';
    $this->manager = app(ScheduledProcessManager::class);
    $this->model = NhlModelRun::create(['run_key' => 'prediction-automation', 'name' => 'sat_v2',
        'model_family' => 'sat', 'workflow_stage' => 'training', 'model_version' => 'automation-v1',
        'usage' => 'production', 'status' => 'complete', 'train_season_ids' => ['20252026'],
        'target_season_id' => '20262027', 'metrics' => []]);
    $this->game = function (int $id = 2026020001, string $date = '2026-11-17', string $state = 'FUT'): NhlGame {
        return NhlGame::create(['nhl_game_id' => $id, 'season_id' => '20262027', 'game_type' => 2,
            'game_date' => $date, 'game_dow' => 'Tuesday', 'game_month' => 'November', 'game_state' => $state,
            'home_team_id' => 10, 'away_team_id' => 6, 'home_team_abbrev' => 'TOR', 'away_team_abbrev' => 'BOS']);
    };
    ($this->game)();
    $this->settings = function (string $mode = 'time', bool $enabled = true): ScheduledProcess {
        return ScheduledProcess::query()->updateOrCreate(['key' => ScheduledProcessManager::NHL_PREDICTIONS], [
            'enabled' => $enabled, 'start_time' => '06:00', 'timezone' => 'America/Toronto', 'frequency_hours' => 24,
            'settings' => ['trigger_mode' => $mode], 'next_due_at' => null,
        ]);
    };
    $this->context = function (string $pipeline = NhlPregameContextOrchestrator::CHECKPOINT_PIPELINE): NhlPregameContextRun {
        $run = NhlPregameContextRun::create(['model_run_id' => $this->model->id, 'action' => 'backfill',
            'status' => 'completed', 'season_ids' => ['20262027'], 'total_games' => 1, 'completed_games' => 1,
            'options' => ['pipeline_version' => $pipeline, 'league_benchmarks' => ['2026-11-17' => 'complete']]]);
        NhlPregameContextRunGame::create(['run_id' => $run->id, 'nhl_game_id' => 2026020001,
            'game_date' => '2026-11-17', 'sequence' => 1, 'state' => 'complete', 'comparisons_completed_at' => now()]);
        NhlPregameContextRunDay::create(['run_id' => $run->id, 'game_date' => '2026-11-17', 'game_count' => 1,
            'context_state' => 'complete', 'league_state' => 'complete', 'comparison_state' => 'complete']);
        foreach ([10 => 'home', 6 => 'away'] as $team => $venue) {
            $context = DB::table('nhl_team_game_pregame_contexts')->insertGetId([
                'model_run_id' => $this->model->id, 'run_id' => $run->id, 'nhl_game_id' => 2026020001,
                'nhl_team_id' => $team, 'opponent_team_id' => $team === 10 ? 6 : 10, 'game_date' => '2026-11-17',
                'source_cutoff_at' => '2026-11-17 11:00:00', 'venue' => $venue,
                'context_version' => NhlPregameContextBuilder::VERSION, 'schedule_metrics' => '{}', 'opponent_metrics' => '{}', 'metrics' => '{}',
            ]);
            DB::table('nhl_player_game_pregame_contexts')->insert([
                'model_run_id' => $this->model->id, 'run_id' => $run->id, 'team_context_id' => $context,
                'nhl_game_id' => 2026020001, 'nhl_player_id' => 100 + $team, 'nhl_team_id' => $team,
                'opponent_team_id' => $team === 10 ? 6 : 10, 'game_date' => '2026-11-17',
                'source_cutoff_at' => '2026-11-17 11:00:00', 'venue' => $venue, 'participant_source' => 'anticipated',
                'context_version' => NhlPregameContextBuilder::VERSION, 'metrics' => '{}',
            ]);
        }
        return $run;
    };
    $this->event = fn ($run): NhlPregameContextDayCompleted => new NhlPregameContextDayCompleted($run->id, $this->model->id, '2026-11-17');
});

afterEach(function (): void {
    Http::assertNothingSent();
    $this->travelBack();
});

it('denies guests each automation endpoint', function (string $verb): void {
    $this->json($verb, $this->url, [])->assertUnauthorized();
})->with(['GET', 'PUT']);

it('denies ordinary members each automation endpoint', function (string $verb): void {
    $this->actingAs(User::factory()->create())->json($verb, $this->url, [])->assertForbidden();
})->with(['GET', 'PUT']);

it('reads disabled six AM daily defaults without creating a schedule', function (): void {
    $this->actingAs($this->admin)->getJson($this->url)->assertOk()->assertJsonPath('enabled', false)
        ->assertJsonPath('start_time', '06:00')->assertJsonPath('frequency_hours', 24)
        ->assertJsonPath('timezone', 'America/Toronto')->assertJsonPath('settings.trigger_mode', 'time');
    expect(ScheduledProcess::where('key', ScheduledProcessManager::NHL_PREDICTIONS)->exists())->toBeFalse();
    Bus::assertNothingBatched();
});

it('saves timing through HTTP and reads the same persisted settings without starting work', function (): void {
    $this->actingAs($this->admin)->putJson($this->url, ['enabled' => true, 'trigger_mode' => 'time',
        'start_time' => '07:15', 'frequency_hours' => 3])->assertOk();
    $this->assertDatabaseHas('scheduled_processes', ['key' => ScheduledProcessManager::NHL_PREDICTIONS, 'enabled' => true,
        'start_time' => '07:15', 'frequency_hours' => 3]);
    $this->getJson($this->url)->assertJsonPath('settings.trigger_mode', 'time')->assertJsonPath('start_time', '07:15')
        ->assertJsonPath('frequency_hours', 3);
    Bus::assertNothingBatched();
    $this->assertDatabaseCount('nhl_game_prediction_runs', 0);
});

it('saves event mode without requiring a time or interval', function (): void {
    $this->actingAs($this->admin)->putJson($this->url, ['enabled' => true, 'trigger_mode' => 'event'])->assertOk();
    $this->getJson($this->url)->assertJsonPath('settings.trigger_mode', 'event')->assertJsonPath('start_time', '06:00');
    Bus::assertNothingBatched();
});

it('rejects invalid or combined trigger settings', function (array $input, string $field): void {
    $this->actingAs($this->admin)->putJson($this->url, array_replace(['enabled' => true, 'trigger_mode' => 'time',
        'start_time' => '06:00', 'frequency_hours' => 24], $input))->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    [['trigger_mode' => 'both'], 'trigger_mode'], [['trigger_mode' => ['time', 'event']], 'trigger_mode'],
    [['start_time' => '24:01'], 'start_time'], [['frequency_hours' => 0], 'frequency_hours'],
    [['frequency_hours' => 169], 'frequency_hours'], [['frequency_hours' => 1.5], 'frequency_hours'], [['enabled' => 'yes'], 'enabled'],
]);

it('does not permit client timezone or command overrides', function (): void {
    $this->actingAs($this->admin)->putJson($this->url, ['enabled' => false, 'trigger_mode' => 'event',
        'timezone' => 'UTC', 'command' => 'anything'])->assertOk();
    $this->getJson($this->url)->assertJsonPath('timezone', 'America/Toronto')->assertJsonMissing(['command' => 'anything']);
});

it('keeps one settings row when changing modes and preserves event deduplication evidence', function (): void {
    $process = ($this->settings)('event');
    $process->update(['settings' => ['trigger_mode' => 'event', 'last_context_run_id' => 42, 'last_context_date' => '2026-11-17']]);
    $this->actingAs($this->admin)->putJson($this->url, ['enabled' => true, 'trigger_mode' => 'time',
        'start_time' => '06:00', 'frequency_hours' => 24])->assertOk();
    $this->getJson($this->url)->assertJsonPath('settings.last_context_run_id', 42)->assertJsonPath('settings.trigger_mode', 'time');
    expect(ScheduledProcess::where('key', ScheduledProcessManager::NHL_PREDICTIONS)->count())->toBe(1);
});

it('does not dispatch disabled prediction automation', function (): void {
    ($this->settings)('time', false);
    $this->manager->tick();
    Bus::assertNothingBatched();
});

it('waits until six AM Eastern on the initial daily schedule', function (): void {
    ($this->settings)();
    $this->travelTo(CarbonImmutable::parse('2026-11-17 10:59:00 UTC'));
    $this->manager->tick();
    Bus::assertNothingBatched();
    $this->assertDatabaseCount('nhl_game_prediction_runs', 0);
});

it('queues the current engine on projections and exposes its automatic run through HTTP', function (): void {
    ($this->settings)();
    $this->manager->tick();
    $run = DB::table('nhl_game_prediction_runs')->first();
    expect($run->created_by)->toBeNull()->and(json_decode($run->requested_stack, true)['model_run_id'])->toBe($this->model->id);
    Bus::assertBatched(fn ($batch): bool => $batch->options['queue'] === 'projections'
        && $batch->jobs->count() === 1 && $batch->jobs->first() instanceof RunNhlGamePredictionJob);
    $this->actingAs($this->admin)->getJson('/admin/game-predictions?start_date=2026-11-17')
        ->assertOk()->assertJsonPath('run.id', $run->id)->assertJsonPath('games.0.work.status', 'queued');
});

it('does not duplicate repeated timer ticks or burst missed intervals', function (): void {
    $process = ($this->settings)();
    $process->update(['next_due_at' => now()->subDays(3)]);
    $this->manager->tick();
    $this->manager->tick();
    $this->assertDatabaseCount('nhl_game_prediction_runs', 1);
    Bus::assertBatchCount(1);
    expect($process->fresh()->next_due_at->format('Y-m-d H:i'))->toBe('2026-11-18 11:00');
});

it('does not start time-based runs while event mode is selected', function (): void {
    ($this->settings)('event');
    $this->manager->tick();
    Bus::assertNothingBatched();
});

it('skips past future live and final games during automatic admission', function (): void {
    ($this->settings)();
    ($this->game)(2026020002, '2026-11-16');
    ($this->game)(2026020003, '2026-11-18');
    ($this->game)(2026020004, '2026-11-17', 'LIVE');
    ($this->game)(2026020005, '2026-11-17', 'OFF');
    $this->manager->tick();
    expect(DB::table('nhl_game_prediction_run_games')->pluck('nhl_game_id')->all())->toBe([2026020001]);
});

it('suppresses automatic overlap with a manual run without consuming the due time', function (): void {
    $process = ($this->settings)();
    app(NhlGamePredictionHistory::class)->queueRun('2026-11-17', '2026-11-17', $this->admin->id);
    $this->manager->tick();
    $this->assertDatabaseCount('nhl_game_prediction_runs', 1);
    expect($process->fresh()->next_due_at)->toBeNull();
});

it('suppresses automatic overlap with an active API prediction lease', function (): void {
    ($this->settings)();
    NhlGame::predictionActivity(2026020001, 'fixture', true);
    $this->manager->tick();
    $this->assertDatabaseCount('nhl_game_prediction_runs', 0);
    NhlGame::predictionActivity(2026020001, 'fixture', false);
    $this->manager->tick();
    $this->assertDatabaseCount('nhl_game_prediction_runs', 1);
});

it('keeps daily wall time across daylight-saving changes', function (): void {
    $process = ($this->settings)();
    $next = $this->manager->nextDue($process, CarbonImmutable::parse('2026-10-31 10:00:00 UTC'));
    expect($next->format('Y-m-d H:i'))->toBe('2026-11-01 11:00');
});

it('uses the configured subdaily interval after actual dispatch', function (): void {
    $process = ($this->settings)();
    $process->update(['frequency_hours' => 3]);
    $this->manager->tick();
    expect($process->fresh()->next_due_at->format('H:i'))->toBe('14:00');
});

it('resets due timing when saving a different mode or start time', function (): void {
    $process = ($this->settings)();
    $process->update(['next_due_at' => now()->addDays(2)]);
    $this->actingAs($this->admin)->putJson($this->url, ['enabled' => true, 'trigger_mode' => 'time',
        'start_time' => '08:00', 'frequency_hours' => 24])->assertOk();
    expect($process->fresh()->next_due_at)->toBeNull();
});

it('admits one event run for complete current-model context and deduplicates repeated notifications', function (): void {
    ($this->settings)('event');
    $run = ($this->context)();
    $this->manager->predictCompletedContextDay(($this->event)($run));
    $this->manager->predictCompletedContextDay(($this->event)($run));
    $this->assertDatabaseCount('nhl_game_prediction_runs', 1);
    expect($this->manager->predictionSettings()->settings['last_context_run_id'])->toBe($run->id);
    Bus::assertBatchCount(1);
});

it('ignores context notifications for another model or another game date', function (): void {
    ($this->settings)('event');
    $run = ($this->context)();
    $this->manager->predictCompletedContextDay(new NhlPregameContextDayCompleted($run->id, $this->model->id + 1, '2026-11-17'));
    $this->manager->predictCompletedContextDay(new NhlPregameContextDayCompleted($run->id, $this->model->id, '2026-11-16'));
    $this->manager->predictCompletedContextDay(new NhlPregameContextDayCompleted($run->id, $this->model->id, '2026-11-18'));
    Bus::assertNothingBatched();
});

it('ignores completion notifications in time mode or when automation is disabled', function (): void {
    $run = ($this->context)();
    ($this->settings)();
    $this->manager->predictCompletedContextDay(($this->event)($run));
    ($this->settings)('event', false);
    $this->manager->predictCompletedContextDay(($this->event)($run));
    Bus::assertNothingBatched();
});

it('does not predict before every current slate game has context', function (): void {
    ($this->settings)('event');
    $run = ($this->context)();
    ($this->game)(2026020002);
    $this->manager->predictCompletedContextDay(($this->event)($run));
    Bus::assertNothingBatched();
});

it('requires both teams and player snapshots in the current context version', function (string $missing): void {
    ($this->settings)('event');
    $run = ($this->context)();
    if ($missing === 'players') {
        DB::table('nhl_player_game_pregame_contexts')->delete();
    } else {
        DB::table('nhl_team_game_pregame_contexts')->where('nhl_team_id', 6)->update(['context_version' => 'old']);
    }
    $this->manager->predictCompletedContextDay(($this->event)($run));
    Bus::assertNothingBatched();
})->with(['players', 'team']);

it('rejects incomplete blocked or failed context day states', function (string $stage, string $state): void {
    ($this->settings)('event');
    $run = ($this->context)();
    if ($stage === 'game') {
        $run->games()->update(['state' => $state]);
    } else {
        $run->days()->update([$stage.'_state' => $state]);
    }
    $this->manager->predictCompletedContextDay(($this->event)($run));
    Bus::assertNothingBatched();
})->with([['context', 'pending'], ['league', 'processing'], ['game', 'blocked'], ['game', 'failed']]);

it('requires separate comparisons for the older three-stage pipeline', function (): void {
    ($this->settings)('event');
    $run = ($this->context)(NhlPregameContextOrchestrator::THREE_STAGE_PIPELINE);
    $run->days()->update(['comparison_state' => 'pending']);
    $this->manager->predictCompletedContextDay(($this->event)($run));
    Bus::assertNothingBatched();
});

it('retries busy event work without marking its context event consumed', function (): void {
    ($this->settings)('event');
    $run = ($this->context)();
    NhlGame::predictionActivity(2026020001, 'fixture', true);
    expect($this->manager->predictCompletedContextDay(($this->event)($run)))->toBeFalse();
    expect($this->manager->predictionSettings()->settings)->not->toHaveKey('last_context_run_id');
    NhlGame::predictionActivity(2026020001, 'fixture', false);
    expect($this->manager->predictCompletedContextDay(($this->event)($run)))->toBeTrue();
    $this->assertDatabaseCount('nhl_game_prediction_runs', 1);
});

it('keeps a queued listener harmless after the setting changes', function (): void {
    ($this->settings)('event');
    $run = ($this->context)();
    $listener = new QueueNhlGamePredictions();
    expect($listener->shouldQueue(($this->event)($run)))->toBeTrue()->and($listener->queue)->toBe('projections');
    ($this->settings)('time');
    $listener->handle(($this->event)($run));
    Bus::assertNothingBatched();
});

it('publishes a current-day completion event from the ordinary loader after readiness', function (): void {
    $run = ($this->context)();
    Event::fake([NhlPregameContextDayCompleted::class]);
    app(NhlPregameContextOrchestrator::class)->load($run->id);
    Event::assertDispatched(NhlPregameContextDayCompleted::class,
        fn ($event): bool => $event->runId === $run->id && $event->gameDate === '2026-11-17' && $event->modelRunId === $this->model->id);
});

it('records queue publication failures as failed run items instead of leaving queued work stuck', function (): void {
    ($this->settings)();
    Bus::shouldReceive('batch')->once()->andThrow(new \RuntimeException('Queue unavailable'));
    $this->manager->tick();
    expect(DB::table('nhl_game_prediction_run_games')->first()->status)->toBe('failed');
    expect(DB::table('nhl_game_prediction_runs')->first()->status)->toBe('completed_with_errors');
});

it('preserves explicit manual prediction admission for historical and final games', function (): void {
    ($this->game)(2026020002, '2026-11-16', 'OFF');
    $this->actingAs($this->admin)->postJson('/admin/game-predictions/runs', ['start_date' => '2026-11-16'])->assertAccepted();
    $this->assertDatabaseHas('nhl_game_prediction_run_games', ['nhl_game_id' => 2026020002, 'status' => 'queued']);
});

it('does not enqueue a completion listener when automation is disabled or time-based', function (): void {
    $event = new NhlPregameContextDayCompleted(1, $this->model->id, '2026-11-17');
    $listener = new QueueNhlGamePredictions();
    expect($listener->shouldQueue($event))->toBeFalse();
    ($this->settings)();
    expect($listener->shouldQueue($event))->toBeFalse();
    Event::fake();
    Event::assertListening(NhlPregameContextDayCompleted::class, QueueNhlGamePredictions::class);
});

it('releases a busy event listener and stops retrying when its date expires', function (): void {
    ($this->settings)('event');
    $run = ($this->context)();
    NhlGame::predictionActivity(2026020001, 'fixture', true);
    $job = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
    $job->shouldReceive('release')->once()->with(60);
    $listener = new QueueNhlGamePredictions();
    $listener->setJob($job);
    $listener->handle(($this->event)($run));
    $this->travelTo(CarbonImmutable::parse('2026-11-18 11:00:00 UTC'));
    $listener->handle(($this->event)($run));
    Bus::assertNothingBatched();
});

it('skips nights with no upcoming games without creating an empty prediction run', function (): void {
    $process = ($this->settings)();
    NhlGame::query()->update(['game_state' => 'OFF']);
    $this->manager->tick();
    $this->assertDatabaseCount('nhl_game_prediction_runs', 0);
    expect($process->fresh()->next_due_at)->not->toBeNull();
});

it('does not publish context readiness while selected games remain excluded', function (): void {
    $run = ($this->context)();
    $run->games()->update(['state' => 'blocked']);
    Event::fake([NhlPregameContextDayCompleted::class]);
    app(NhlPregameContextOrchestrator::class)->load($run->id);
    Event::assertNotDispatched(NhlPregameContextDayCompleted::class);
});

it('retains explicit manual ownership and the configured algorithm definition', function (): void {
    $response = $this->actingAs($this->admin)->postJson('/admin/game-predictions/runs', ['start_date' => '2026-11-17'])->assertAccepted();
    $run = DB::table('nhl_game_prediction_runs')->find($response->json('run_id'));
    $definition = json_decode($run->requested_stack, true);
    expect((int) $run->created_by)->toBe($this->admin->id)->and($definition['model_run_id'])->toBe($this->model->id)
        ->and($definition)->not->toHaveKey('automation');
});
