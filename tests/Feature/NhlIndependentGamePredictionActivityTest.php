<?php

declare(strict_types=1);

use App\Events\NhlGamePredictionStatusUpdated;
use App\Jobs\PrepareNhlGamePredictionJob;
use App\Jobs\RefreshNhlGamePredictionJob;
use App\Jobs\RunNhlGamePredictionJob;
use App\Models\NhlGame;
use App\Models\NhlModelRun;
use App\Models\Role;
use App\Models\User;
use App\Services\NhlCustomGameForecast;
use App\Services\NhlGamePredictionHistory;
use App\Services\NhlGamePredictionPayload;
use App\Services\NhlGamePredictionResponseCache;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-25 15:00:00 UTC'));
    config(['cache.default' => 'array']);
    Cache::flush();
    Bus::fake();
    Event::fake([NhlGamePredictionStatusUpdated::class]);
    Http::preventStrayRequests();
    $this->model = NhlModelRun::create(['run_key' => 'independent-predictions', 'name' => 'sat_v2',
        'model_family' => 'sat', 'workflow_stage' => 'training', 'model_version' => 'fixture', 'status' => 'complete',
        'train_season_ids' => ['20252026'], 'target_season_id' => '20262027', 'metrics' => []]);
    foreach ([2026020394 => ['VAN', 'DET', 23, 17], 2026020395 => ['BOS', 'TOR', 6, 10]] as $id => $teams) {
        NhlGame::create(['nhl_game_id' => $id, 'season_id' => '20262027', 'game_type' => 2,
            'game_date' => '2026-11-25', 'game_state' => 'FUT', 'start_time_utc' => '2026-11-26 00:00:00',
            'game_dow' => 'Wednesday', 'game_month' => 'November', 'away_team_abbrev' => $teams[0],
            'home_team_abbrev' => $teams[1], 'away_team_id' => $teams[2], 'home_team_id' => $teams[3]]);
    }
    $this->admin = User::factory()->create();
    $role = Role::create(['name' => 'Super Admin', 'slug' => 'super-admin', 'level' => 99]);
    $this->admin->roles()->attach($role->id, ['organization_id' => null]);
    $this->history = app(NhlGamePredictionHistory::class);
    $this->admit = fn (int $game = 2026020394, string $trigger = 'api'): int => $this->history->queueRefresh($game, ['nhl_game_id' => $game], $trigger);
    $this->index = fn (array $extra = []) => $this->actingAs($this->admin)->getJson(route('admin.game-predictions.index',
        ['start_date' => '2026-11-25', ...$extra]));
    $this->forecast = ['prediction_available' => true, 'inputs' => [], 'prediction' => [
        'winner' => ['team_abbrev' => 'DET'], 'predicted_score' => ['away' => 2.1, 'home' => 3.2], 'confidence_score' => 65],
        'market_probabilities' => [['market_key' => 'moneyline', 'team_abbrev' => 'DET', 'probability' => .6]]];
});

afterEach(function (): void {
    Http::assertNothingSent();
    $this->travelBack();
});

it('persists background API admission before any forecast input is read', function (): void {
    $this->mock(NhlCustomGameForecast::class)->shouldNotReceive('model');
    $id = ($this->admit)();
    $this->assertDatabaseHas('nhl_game_prediction_run_games', ['id' => $id, 'status' => 'queued', 'phase' => 'queued']);
    ($this->index)()->assertOk()->assertJsonPath('games.0.work.trigger_source', 'api')
        ->assertJsonPath('games.0.work.status', 'queued')->assertJsonPath('games.0.revision', null);
    Bus::assertDispatched(RefreshNhlGamePredictionJob::class, fn ($job): bool => $job->itemId === $id && $job->queue === 'projections');
});

it('keeps separate work items for unrelated games on the same day', function (): void {
    $first = ($this->admit)();
    $second = ($this->admit)(2026020395);
    expect($first)->not->toBe($second);
    expect($this->history->claim($first, 1, 'first-worker'))->not->toBeNull();
    expect($this->history->claim($second, 1, 'second-worker'))->not->toBeNull();
});

it('coalesces repeated refresh requests for identical game inputs', function (): void {
    $id = ($this->admit)();
    expect(($this->admit)())->toBe($id);
    Bus::assertDispatchedTimes(RefreshNhlGamePredictionJob::class, 1);
    $this->assertDatabaseCount('nhl_game_prediction_runs', 1);
});

it('retains explicit model variants while serializing their work for one game', function (): void {
    $first = ($this->admit)();
    $second = $this->history->queueRefresh(2026020394, ['nhl_game_id' => 2026020394, 'sat_model_run_id' => 123]);
    expect($second)->not->toBe($first)->and($this->history->claim($second, 1, 'variant-worker'))->toBeNull();
    $this->history->finish($first, 'failed', error: 'Fixture terminal failure');
    expect($this->history->claim($second, 1, 'variant-worker'))->not->toBeNull();
});

it('records lineup and injury trigger sources independently of API requests', function (): void {
    app(NhlGamePredictionResponseCache::class)->queueLineupRefresh(2026020394);
    app(NhlGamePredictionResponseCache::class)->refreshAvailability(2026020395);
    ($this->index)()->assertOk()->assertJsonPath('games.0.work.trigger_source', 'lineup')
        ->assertJsonPath('games.1.work.trigger_source', 'injury');
});

it('rejects simultaneous claims for the same item', function (): void {
    $id = ($this->admit)();
    expect($this->history->claim($id, 1, 'first'))->not->toBeNull()
        ->and($this->history->claim($id, 1, 'second'))->toBeNull()
        ->and($this->history->owns($id, 1, 'first'))->toBeTrue();
});

it('rejects obsolete generations before reading or calculating anything', function (): void {
    $id = ($this->admit)();
    DB::table('nhl_game_prediction_run_games')->where('id', $id)->update(['generation' => 2]);
    $builder = Mockery::mock(NhlGamePredictionPayload::class);
    $builder->shouldNotReceive('build');
    (new RunNhlGamePredictionJob($id, 1))->handle($builder, app(NhlGamePredictionResponseCache::class), $this->history);
    expect(DB::table('nhl_game_prediction_run_games')->find($id)->attempts)->toBe(0);
});

it('records cursor and heartbeat without reopening historical state for the admin read', function (): void {
    $id = ($this->admit)();
    $this->history->claim($id, 1, 'worker');
    $this->travel(8)->seconds();
    $this->history->progress($id, 'worker', 'history', 25, 3000, true);
    ($this->index)()->assertOk()->assertJsonPath('games.0.work.phase', 'history')
        ->assertJsonPath('games.0.work.progress_current', 25)->assertJsonPath('games.0.work.progress_total', 3000)
        ->assertJsonPath('games.0.engine_time_seconds', 8)->assertJsonMissingPath('games.0.work.claim_token')
        ->assertJsonMissingPath('games.0.work.request_inputs');
    expect(DB::table('nhl_game_prediction_run_games')->find($id)->last_progress_at)->not->toBeNull();
});

it('keeps the first worker start across successful checkpoint continuations', function (): void {
    $id = ($this->admit)();
    $first = $this->history->claim($id, 1, 'first');
    $this->history->progress($id, 'first', 'history', 25, 50, true);
    $this->travel(10)->seconds();
    $second = $this->history->claim($id, 1, 'second');
    expect($second->started_at)->toBe($first->started_at)->and((int) $second->attempts)->toBe(2);
});

it('does not let a stale token replace another workers progress', function (): void {
    $id = ($this->admit)();
    $this->history->claim($id, 1, 'current');
    $this->history->progress($id, 'old', 'history', 999);
    expect(DB::table('nhl_game_prediction_run_games')->find($id)->progress_current)->toBe(0);
});

it('recovers an abandoned item with a new fenced generation', function (): void {
    $id = ($this->admit)();
    $this->history->claim($id, 1, 'dead-worker');
    $this->travel(301)->seconds();
    $this->history->recoverActiveGames();
    $item = DB::table('nhl_game_prediction_run_games')->find($id);
    expect((int) $item->generation)->toBe(2)->and((int) $item->recoveries)->toBe(1)
        ->and($item->status)->toBe('queued')->and($item->claim_token)->toBeNull();
    expect($this->history->owns($id, 1, 'dead-worker'))->toBeFalse();
    Bus::assertDispatched(RunNhlGamePredictionJob::class, fn ($job): bool => $job->itemId === $id && $job->generation === 2);
});

it('leaves healthy workers and recently queued items alone during recovery', function (): void {
    $id = ($this->admit)();
    $this->history->claim($id, 1, 'healthy');
    $this->travel(60)->seconds();
    $this->history->recoverActiveGames();
    expect((int) DB::table('nhl_game_prediction_run_games')->find($id)->generation)->toBe(1);
    Bus::assertNotDispatched(RunNhlGamePredictionJob::class);
});

it('does not repeat recovery admission for the same stale observation', function (): void {
    $id = ($this->admit)();
    $this->travel(301)->seconds();
    $this->history->recoverActiveGames();
    $this->history->recoverActiveGames();
    Bus::assertDispatchedTimes(RunNhlGamePredictionJob::class, 1);
    expect((int) DB::table('nhl_game_prediction_run_games')->find($id)->generation)->toBe(2);
});

it('finishes exhausted abandoned work and unlocks its lineup', function (): void {
    $id = ($this->admit)();
    DB::table('nhl_game_prediction_run_games')->where('id', $id)->update(['recoveries' => 3]);
    $this->travel(301)->seconds();
    $this->history->recoverActiveGames();
    expect(DB::transaction(fn () => NhlGame::lockForLineupChange(2026020394))->nhl_game_id)->toBe(2026020394);
    ($this->index)()->assertOk()->assertJsonPath('games.0.work.status', 'failed')
        ->assertJsonPath('games.0.work.lineup_lock_reason', null);
});

it('keeps other games editable while one prediction is queued', function (): void {
    ($this->admit)();
    expect(fn () => DB::transaction(fn () => NhlGame::lockForLineupChange(2026020394)))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class, 'Predictions are in progress');
    expect(DB::transaction(fn () => NhlGame::lockForLineupChange(2026020395))->nhl_game_id)->toBe(2026020395);
});

it('releases lineup protection on a terminal worker failure', function (): void {
    $id = ($this->admit)();
    (new RunNhlGamePredictionJob($id))->failed(new RuntimeException('Fixture failure'));
    expect(DB::transaction(fn () => NhlGame::lockForLineupChange(2026020394))->nhl_game_id)->toBe(2026020394);
    $this->assertDatabaseHas('nhl_game_prediction_run_games', ['id' => $id, 'status' => 'failed', 'last_error' => 'Fixture failure']);
});

it('ignores terminal callbacks from a replaced delivery', function (): void {
    $id = ($this->admit)();
    DB::table('nhl_game_prediction_run_games')->where('id', $id)->update(['generation' => 2]);
    (new RunNhlGamePredictionJob($id, 1))->failed(new RuntimeException('Old delivery failed'));
    expect(DB::table('nhl_game_prediction_run_games')->find($id)->status)->toBe('queued');
});

it('shows background activity even when the admin selected an older failed run', function (): void {
    $old = ($this->admit)();
    $run = DB::table('nhl_game_prediction_run_games')->find($old)->run_id;
    $this->history->finish($old, 'failed', error: 'Obsolete dependency 217');
    $this->travel(30)->seconds();
    $current = ($this->admit)();
    ($this->index)(['run_id' => $run])->assertOk()->assertJsonPath('games.0.work.id', $current)
        ->assertJsonPath('games.0.work.last_error', null)->assertJsonPath('games.0.engine_time_seconds', 0);
});

it('retains a saved forecast while a new background attempt is active', function (): void {
    $saved = app(NhlGamePredictionResponseCache::class)->respond(2026020394, ['nhl_game_id' => 2026020394], fn () => $this->forecast, true);
    $this->travel(30)->seconds();
    $id = ($this->admit)();
    ($this->index)()->assertOk()->assertJsonPath('games.0.revision.id', $saved['meta']['prediction_revision_id'])
        ->assertJsonPath('games.0.work.id', $id)->assertJsonPath('games.0.work.status', 'queued');
});

it('keeps API revisions attributed to API when they have tracked run IDs', function (): void {
    $result = app(NhlGamePredictionResponseCache::class)->respond(2026020394, ['nhl_game_id' => 2026020394], fn () => $this->forecast, true);
    $this->assertDatabaseHas('nhl_game_prediction_revisions', ['id' => $result['meta']['prediction_revision_id'], 'source' => 'api']);
    $this->assertDatabaseHas('nhl_game_prediction_run_games', ['nhl_game_id' => 2026020394, 'status' => 'completed']);
});

it('rejects publication from a replaced claim without archiving a prediction', function (): void {
    $id = ($this->admit)();
    $item = $this->history->claim($id, 1, 'worker');
    $cache = app(NhlGamePredictionResponseCache::class);
    expect(fn () => $cache->respond(2026020394, ['nhl_game_id' => 2026020394], function () use ($id): array {
        DB::table('nhl_game_prediction_run_games')->where('id', $id)->update(['generation' => 2, 'claim_token' => null]);
        return $this->forecast;
    }, true, (int) $item->run_id, $id, 1, 'worker'))->toThrow(DomainException::class, 'cannot publish');
    $this->assertDatabaseCount('nhl_game_prediction_revisions', 0);
});

it('commits an initial checkpoint before any lineup or context resolution', function (): void {
    $this->mock(\App\Services\NhlCustomGamePredictionInputs::class)->shouldNotReceive('anticipatedParticipants');
    $service = new NhlCustomGameForecast();
    expect($service->advancePreparation(2026020394, $this->model->id))->toBeFalse();
    $progress = $service->preparationProgress(2026020394, $this->model->id);
    expect($progress['phase'])->toBe('evidence')->and($service->preparationDelay())->toBe(0);
    expect($service->preparationProgress(2026020395, $this->model->id)['phase'])->toBe('queued');
});

it('uses distinct preparation keys and locks for games on the same date', function (): void {
    $service = new NhlCustomGameForecast();
    $method = new ReflectionMethod($service, 'preparationKey');
    $first = $method->invoke($service, DB::table('nhl_games')->where('nhl_game_id', 2026020394)->first(), $this->model, false, []);
    $second = $method->invoke($service, DB::table('nhl_games')->where('nhl_game_id', 2026020395)->first(), $this->model, false, []);
    expect($first)->not->toBe($second);
    $lock = Cache::lock($first.':lock', 180);
    $lock->get();
    try {
        expect($service->advancePreparation(2026020395, $this->model->id))->toBeFalse()
            ->and($service->preparationProgress(2026020395, $this->model->id)['phase'])->toBe('evidence');
    } finally {
        $lock->release();
    }
});

it('returns a per-game phase without deserializing checkpoint source parts', function (): void {
    $service = new NhlCustomGameForecast();
    $key = (new ReflectionMethod($service, 'preparationKey'))->invoke($service,
        DB::table('nhl_games')->where('nhl_game_id', 2026020394)->first(), $this->model, false, []);
    Cache::put($key.':work', ['checkpoint' => ['phase' => 'history', 'cursor' => 100, 'total' => 2000],
        'groups' => ['core' => [['key' => 'nonexistent-part']]]], 600);
    expect($service->preparationProgress(2026020394, $this->model->id))->toMatchArray([
        'phase' => 'history', 'cursor' => 100, 'total' => 2000, 'ready' => false]);
});

it('uses a finite timeout failure hook instead of an untracked Redis reservation wait', function (): void {
    $job = new RunNhlGamePredictionJob(1);
    expect($job->failOnTimeout)->toBeTrue()->and($job->timeout)->toBe(120)
        ->and((new RefreshNhlGamePredictionJob(2026020394, [], ''))->queue)->toBe('projections')
        ->and((new PrepareNhlGamePredictionJob(2026020394, $this->model->id))->failOnTimeout)->toBeTrue();
});

it('preserves the checkpoint when recovery fences its worker delivery', function (): void {
    $service = new NhlCustomGameForecast();
    $service->advancePreparation(2026020394, $this->model->id);
    $id = ($this->admit)();
    $this->travel(301)->seconds();
    $this->history->recoverActiveGames();
    expect($service->preparationProgress(2026020394, $this->model->id)['phase'])->toBe('evidence');
    expect((int) DB::table('nhl_game_prediction_run_games')->find($id)->generation)->toBe(2);
});

it('broadcasts activity identifiers on a dedicated private admin channel', function (): void {
    ($this->admit)();
    Event::assertDispatched(NhlGamePredictionStatusUpdated::class);
    $event = new NhlGamePredictionStatusUpdated(12, 2026020394);
    expect($event->broadcastOn()->name)->toBe('private-admin.predictions')
        ->and($event->broadcastWith())->toBe(['run_id' => 12, 'game_id' => 2026020394]);
});

it('blocks guest reads of prediction activity', function (): void {
    $this->getJson(route('admin.game-predictions.index', ['start_date' => '2026-11-25']))->assertUnauthorized();
});

it('blocks non-admin reads of prediction activity', function (): void {
    $this->actingAs(User::factory()->create())->getJson(route('admin.game-predictions.index', ['start_date' => '2026-11-25']))->assertForbidden();
});

it('blocks guest prediction admission', function (): void {
    $this->postJson(route('admin.game-predictions.runs.store'), ['start_date' => '2026-11-25'])->assertUnauthorized();
});

it('blocks non-admin prediction admission', function (): void {
    $this->actingAs(User::factory()->create())->postJson(route('admin.game-predictions.runs.store'), ['start_date' => '2026-11-25'])->assertForbidden();
});

it('admits admin work and reads its trigger and lineup protection via HTTP', function (): void {
    $run = $this->actingAs($this->admin)->postJson(route('admin.game-predictions.runs.store'), ['start_date' => '2026-11-25'])
        ->assertAccepted()->json('run_id');
    $this->assertDatabaseHas('nhl_game_prediction_runs', ['id' => $run, 'trigger_source' => 'admin', 'total_games' => 2]);
    ($this->index)()->assertOk()->assertJsonPath('games.0.work.trigger_source', 'admin')
        ->assertJsonPath('games.1.work.status', 'queued');
    expect(($this->index)()->json('games.0.work.lineup_lock_reason'))->toContain('lineup changes are protected');
});

it('yields a bounded preparation step without constructing the prediction payload', function (): void {
    $id = ($this->admit)();
    $forecast = Mockery::mock(NhlCustomGameForecast::class)->makePartial();
    $forecast->shouldReceive('advancePreparation')->once()->with(2026020394, $this->model->id, false)->andReturnFalse();
    $forecast->shouldReceive('preparationProgress')->once()->andReturn(['phase' => 'history', 'cursor' => 25, 'total' => 250]);
    $forecast->shouldReceive('preparationDelay')->once()->andReturn(0);
    app()->instance(NhlCustomGameForecast::class, $forecast);
    $builder = Mockery::mock(NhlGamePredictionPayload::class);
    $builder->shouldNotReceive('build');
    $queue = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
    $queue->shouldReceive('release')->once()->with(0);
    $job = new RunNhlGamePredictionJob($id);
    $job->setJob($queue);
    $job->handle($builder, app(NhlGamePredictionResponseCache::class), $this->history);
    ($this->index)()->assertOk()->assertJsonPath('games.0.work.phase', 'history')
        ->assertJsonPath('games.0.work.progress_current', 25)->assertJsonPath('games.0.work.status', 'queued');
    Bus::assertNotDispatched(PrepareNhlGamePredictionJob::class);
});

it('publishes one game while a different game still has unfinished work', function (): void {
    $blocked = ($this->admit)(2026020395);
    $id = ($this->admit)();
    $forecast = Mockery::mock(NhlCustomGameForecast::class)->makePartial();
    $forecast->shouldReceive('advancePreparation')->once()->andReturnTrue();
    app()->instance(NhlCustomGameForecast::class, $forecast);
    $builder = Mockery::mock(NhlGamePredictionPayload::class);
    $builder->shouldReceive('build')->once()->with(2026020394, ['nhl_game_id' => 2026020394])->andReturn($this->forecast);
    (new RunNhlGamePredictionJob($id))->handle($builder, app(NhlGamePredictionResponseCache::class), $this->history);
    $this->assertDatabaseHas('nhl_game_prediction_run_games', ['id' => $id, 'status' => 'completed']);
    $this->assertDatabaseHas('nhl_game_prediction_run_games', ['id' => $blocked, 'status' => 'queued']);
    ($this->index)()->assertOk()->assertJsonPath('games.0.revision.home_goals', 3.2)
        ->assertJsonPath('games.0.work.status', 'completed')->assertJsonPath('games.1.work.status', 'queued');
});

it('adopts a legacy refresh once without reusing its cache-only lease', function (): void {
    $cache = app(NhlGamePredictionResponseCache::class);
    NhlGame::predictionActivity(2026020394, 'refresh:'.hash('sha256', json_encode(['nhl_game_id' => 2026020394])), true);
    $legacy = new RefreshNhlGamePredictionJob(2026020394, ['nhl_game_id' => 2026020394], 'old');
    $builder = Mockery::mock(NhlGamePredictionPayload::class);
    $builder->shouldNotReceive('build');
    $legacy->handle($cache, $builder);
    $legacy->handle($cache, $builder);
    $this->assertDatabaseCount('nhl_game_prediction_run_games', 1);
    expect(Cache::get('nhl:prediction-input-writers:2026020394', []))->toBe([]);
});

it('does not resurrect an obsolete legacy refresh after a newer attempt finished', function (): void {
    $legacy = new RefreshNhlGamePredictionJob(2026020394, ['nhl_game_id' => 2026020394], 'old');
    $this->travel(60)->seconds();
    $id = ($this->admit)();
    $this->history->finish($id, 'failed', error: 'Newer terminal work');
    $legacy->handle(app(NhlGamePredictionResponseCache::class), Mockery::mock(NhlGamePredictionPayload::class));
    $this->assertDatabaseCount('nhl_game_prediction_run_games', 1);
});

it('does not let an unrelated game roster or starter change invalidate this game', function (): void {
    $forecast = new NhlCustomGameForecast();
    $game = DB::table('nhl_games')->where('nhl_game_id', 2026020394)->first();
    $method = new ReflectionMethod($forecast, 'preparationIdentity');
    $before = $method->invoke($forecast, $game, $this->model, false, []);
    DB::table('nhl_games')->where('nhl_game_id', 2026020395)->update(['home_starter_lock' => 8470001]);
    $after = $method->invoke($forecast, $game, $this->model, false, []);
    expect($after)->toBe($before);
});

it('hashes only one cold historical summary page before yielding without an exception-budget failure', function (): void {
    \App\Models\Player::create(['nhl_id' => 8800001, 'full_name' => 'Summary Fixture', 'first_name' => 'Summary',
        'last_name' => 'Fixture', 'position' => 'C', 'pos_type' => 'F', 'team_abbrev' => 'DET']);
    foreach ([2026020394, 2026020395] as $game) {
        DB::table('nhl_game_summaries')->insert(['nhl_game_id' => $game, 'nhl_player_id' => 8800001,
            'nhl_team_id' => 17, 'sat' => 4, 'sog' => 2, 'g' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }
    config(['nhl-custom-prediction.batch_games' => 1]);
    $forecast = new NhlCustomGameForecast();
    $method = new ReflectionMethod($forecast, 'summaryValueEvidence');
    try {
        $method->invoke($forecast, [2026020394, 2026020395], 2026020394, true);
        $this->fail('Expected a bounded source-evidence continuation.');
    } catch (\App\Exceptions\NhlGamePredictionPreparing $pending) {
        expect($pending->phase)->toBe('evidence')->and($pending->continuationSeconds)->toBe(0)
            ->and($pending->progressCurrent)->toBe(1)->and($pending->progressTotal)->toBe(2);
    }
    expect(Cache::get('nhl:pregame:summary-values:v1:2026020394'))->toBeArray()
        ->and(Cache::get('nhl:pregame:summary-values:v1:2026020395'))->toBeNull();
    $digest = $method->invoke($forecast, [2026020394, 2026020395], 2026020394, true);
    expect($digest)->toBeString()->toHaveLength(64)
        ->and($method->invoke($forecast, [2026020394, 2026020395], 2026020394, true))->toBe($digest);
});

it('requires recovery to fence an expired token before another delivery can claim the item', function (): void {
    $id = ($this->admit)();
    $this->history->claim($id, 1, 'old');
    $this->travel(181)->seconds();
    expect($this->history->claim($id, 1, 'replacement'))->toBeNull();
    $this->travel(120)->seconds();
    $this->history->recoverActiveGames();
    expect($this->history->claim($id, 2, 'replacement'))->not->toBeNull();
});

it('recovers a committed revision before applying the abandoned recovery limit', function (): void {
    $id = ($this->admit)();
    $item = DB::table('nhl_game_prediction_run_games')->find($id);
    $saved = $this->history->record(2026020394, ['nhl_game_id' => 2026020394], str_repeat('a', 64), [], $this->forecast, (int) $item->run_id);
    DB::table('nhl_game_prediction_run_games')->where('id', $id)->update(['recoveries' => 3]);
    $this->travel(301)->seconds();
    $this->history->recoverActiveGames();
    $this->assertDatabaseHas('nhl_game_prediction_run_games', ['id' => $id, 'status' => 'completed',
        'revision_id' => $saved['meta']['prediction_revision_id']]);
    $this->assertDatabaseCount('nhl_game_prediction_revisions', 1);
});

it('refuses terminal updates carrying an obsolete generation or ownership token', function (): void {
    $id = ($this->admit)();
    $this->history->claim($id, 1, 'current');
    $this->history->finish($id, 'failed', error: 'Wrong generation', generation: 0, claimToken: 'current');
    $this->history->finish($id, 'failed', error: 'Wrong token', generation: 1, claimToken: 'old');
    $item = DB::table('nhl_game_prediction_run_games')->find($id);
    expect($item->status)->toBe('running')->and($item->claim_token)->toBe('current')->and($item->last_error)->toBeNull();
});

it('does not rehash or dispatch duplicate work for repeated API requests while a game is pending', function (): void {
    $cache = app(NhlGamePredictionResponseCache::class);
    foreach (range(1, 3) as $request) {
        expect(fn () => $cache->respond(2026020394, ['nhl_game_id' => 2026020394],
            fn () => throw new \App\Exceptions\NhlGamePredictionPreparing(2026020394), true))
            ->toThrow(\App\Exceptions\NhlGamePredictionPreparing::class);
    }
    Bus::assertDispatchedTimes(RefreshNhlGamePredictionJob::class, 1);
    $this->assertDatabaseCount('nhl_game_prediction_run_games', 1);
    $this->assertDatabaseCount('nhl_game_prediction_revisions', 0);
});

it('uses durable input protection without leaving a second cache lease on a killed worker', function (): void {
    app(NhlGamePredictionResponseCache::class)->respond(2026020394, ['nhl_game_id' => 2026020394], function (): array {
        expect(Cache::get('nhl:prediction-input-writers:2026020394', []))->toBe([]);
        expect(fn () => DB::transaction(fn () => NhlGame::lockForLineupChange(2026020394)))
            ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        return $this->forecast;
    }, true);
    expect(DB::transaction(fn () => NhlGame::lockForLineupChange(2026020394))->nhl_game_id)->toBe(2026020394);
});

it('authorizes the private prediction channel only for super admins', function (string $actor, int $status): void {
    $pusher = Mockery::mock(\Pusher\Pusher::class);
    $pusher->shouldReceive('authorizeChannel')->andReturn('{"auth":"fixture-signature"}');
    \Illuminate\Support\Facades\Broadcast::extend('prediction-test',
        fn () => new \Illuminate\Broadcasting\Broadcasters\PusherBroadcaster($pusher));
    config(['broadcasting.default' => 'prediction-test', 'broadcasting.connections.prediction-test' => ['driver' => 'prediction-test']]);
    // Register the real callbacks against an isolated, network-free broadcaster.
    require base_path('routes/channels.php');
    if ($actor !== 'guest') {
        $this->actingAs($actor === 'admin' ? $this->admin : User::factory()->create());
    }
    $response = $this->postJson('/broadcasting/auth', ['channel_name' => 'private-admin.predictions', 'socket_id' => '1.2']);
    $response->assertStatus($status);
    if ($status === 200) {
        $response->assertJsonPath('auth', 'fixture-signature');
    }
})->with([['guest', 403], ['member', 403], ['admin', 200]]);
