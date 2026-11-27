<?php

declare(strict_types=1);

use App\Jobs\CoordinateNhlPregameWorkstreamsJob;
use App\Jobs\WorkNhlPregameWorkstreamTaskJob;
use App\Models\NhlModelRun;
use App\Models\NhlPregameContextRun;
use App\Models\Player;
use App\Models\Role;
use App\Models\User;
use App\Services\NhlPregameContextBuilder;
use App\Services\NhlPregameContextOrchestrator;
use App\Services\NhlPregameLeagueResults;
use App\Services\NhlPregameWorkstreams;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-26 16:00:00 UTC'));
    $this->withoutVite();
    Bus::fake();
    Http::preventStrayRequests();
    config(['cache.default' => 'array', 'cache.stores.redis.driver' => 'array', 'nhl-pregame.workspace_model_name' => 'Sep2026']);
    Cache::flush();
    $this->mock(\App\Services\PlatformState::class)->shouldReceive('seeded')->andReturn(true);
    $this->mock(\App\Services\NhlSeasonGameEvaluation::class)->shouldReceive('readiness')->andReturn(['ready' => false, 'reasons' => []]);
    $this->mock(\App\Services\NhlPregameWorkerActivity::class)->shouldReceive('snapshot')->andReturn(['available' => true, 'workers' => []]);
    $this->admin = User::factory()->create(['name' => 'Research Admin', 'email' => 'research-admin@example.test']);
    $role = Role::firstOrCreate(['slug' => 'super-admin'], ['name' => 'Super Admin', 'level' => 99]);
    $this->admin->roles()->attach($role->id, ['organization_id' => null]);
    $this->model = NhlModelRun::create(['run_key' => 'goals-pilot', 'name' => 'Sep2026', 'model_family' => 'sat',
        'workflow_stage' => 'training', 'model_version' => 'sat_v1', 'status' => 'complete',
        'train_season_ids' => ['20222023'], 'target_season_id' => '20252026']);
    $this->service = app(NhlPregameWorkstreams::class);
    $this->limits = array_fill_keys(array_keys(NhlPregameWorkstreams::AREAS), 1);
    $this->game = function (int $id = 2022020001, string $date = '2022-10-10'): void {
        DB::table('nhl_games')->insert(['nhl_game_id' => $id, 'season_id' => '20222023', 'game_type' => 2,
            'game_date' => $date, 'game_dow' => 'Mon', 'game_month' => 'Oct', 'game_state' => 'FINAL',
            'start_time_utc' => $date.' 23:00:00', 'home_team_id' => 1, 'away_team_id' => 2]);
        foreach ([101 => [1, false], 102 => [2, false], 103 => [1, true], 104 => [2, true]] as $player => [$team, $goalie]) {
            Player::withoutEvents(fn () => Player::firstOrCreate(['nhl_id' => $player], ['first_name' => 'Player',
                'last_name' => (string) $player, 'is_goalie' => $goalie, 'nhl_team_id' => $team]));
            DB::table('nhl_game_summaries')->insert(['nhl_game_id' => $id, 'nhl_player_id' => $player, 'nhl_team_id' => $team, 'toi' => 900]);
        }
    };
    $this->start = function (): NhlPregameContextRun {
        $response = $this->actingAs($this->admin)->postJson('/admin/nhl-sat-models/pregame/repair')->assertAccepted();
        $run = NhlPregameContextRun::findOrFail($response->json('progress.id'));
        app(NhlPregameContextOrchestrator::class)->prepare($run);
        return $run->fresh();
    };
    $this->task = fn (string $area) => DB::table('nhl_pregame_workstream_tasks as t')
        ->join('nhl_pregame_workstream_goals as g', 'g.id', '=', 't.goal_id')->where('g.area', $area)->orderByDesc('t.id')->first('t.*');
    $this->read = fn () => $this->actingAs($this->admin)->withHeaders(['X-Inertia' => 'true',
        'X-Inertia-Partial-Component' => 'Admin/SatModels/Index', 'X-Inertia-Partial-Data' => 'pregame'])->get('/admin/nhl-sat-models');
});

afterEach(function (): void {
    Http::assertNothingSent();
    $this->travelBack();
});

it('blocks unauthenticated area allocations', function (): void {
    $this->putJson('/admin/nhl-sat-models/pregame/goals', ['areas' => $this->limits])->assertUnauthorized();
});

it('blocks ordinary members from area allocation', function (): void {
    $user = User::factory()->create(['email' => 'member@example.test']);
    $this->actingAs($user)->putJson('/admin/nhl-sat-models/pregame/goals', ['areas' => $this->limits])->assertForbidden();
});

it('persists six area allocations and exposes them through authorized HTTP', function (): void {
    $this->limits['players'] = 4;
    $this->limits['goalies'] = 0;
    $this->actingAs($this->admin)->putJson('/admin/nhl-sat-models/pregame/goals', ['areas' => $this->limits])->assertOk();
    $this->assertDatabaseHas('nhl_pregame_workstream_goals', ['model_run_id' => $this->model->id, 'area' => 'players', 'worker_limit' => 4]);
    ($this->read)()->assertOk()->assertJsonCount(6, 'props.pregame.workstream_goals.areas')
        ->assertJsonPath('props.pregame.workstream_goals.areas.0.worker_limit', 4)
        ->assertJsonPath('props.pregame.workstream_goals.areas.2.worker_limit', 0);
    Bus::assertDispatched(CoordinateNhlPregameWorkstreamsJob::class);
});

it('requires all area limits', function (): void {
    $this->actingAs($this->admin)->putJson('/admin/nhl-sat-models/pregame/goals', ['areas' => ['players' => 2]])->assertUnprocessable();
    expect(DB::table('nhl_pregame_workstream_goals')->count())->toBe(0);
});

it('rejects negative area limits', function (): void {
    $this->limits['players'] = -1;
    $this->actingAs($this->admin)->putJson('/admin/nhl-sat-models/pregame/goals', ['areas' => $this->limits])->assertUnprocessable();
});

it('rejects fractional area limits', function (): void {
    $this->limits['players'] = 1.5;
    $this->actingAs($this->admin)->putJson('/admin/nhl-sat-models/pregame/goals', ['areas' => $this->limits])->assertUnprocessable();
});

it('caps the combined worker assignment at twenty five', function (): void {
    $this->limits['players'] = 25;
    $this->actingAs($this->admin)->putJson('/admin/nhl-sat-models/pregame/goals', ['areas' => $this->limits])->assertUnprocessable();
});

it('never substitutes a production model for an absent historical pilot', function (): void {
    $this->model->update(['name' => 'sat_v2']);
    $this->actingAs($this->admin)->putJson('/admin/nhl-sat-models/pregame/goals', ['areas' => $this->limits])->assertStatus(409);
    expect(DB::table('nhl_pregame_workstream_goals')->count())->toBe(0);
});

it('does not enrol or dispatch workers when merely reading the workspace', function (): void {
    ($this->read)()->assertOk()->assertJsonPath('props.pregame.workstream_goals.areas.0.state', 'unassessed');
    expect(DB::table('nhl_pregame_workstream_goals')->count())->toBe(0);
    Bus::assertNothingDispatched();
});

it('seeds independently owned game and date tasks without altering source summaries', function (): void {
    ($this->game)();
    $before = DB::table('nhl_game_summaries')->orderBy('id')->get()->toJson();
    ($this->start)();
    expect(DB::table('nhl_pregame_workstream_tasks')->count())->toBe(7)
        ->and(DB::table('nhl_game_summaries')->orderBy('id')->get()->toJson())->toBe($before);
});

it('makes task seeding replay safe', function (): void {
    ($this->game)();
    $run = ($this->start)();
    $this->service->seed($run);
    expect(DB::table('nhl_pregame_workstream_tasks')->count())->toBe(7);
});

it('claims league and team work before dependent player tasks', function (): void {
    ($this->game)();
    ($this->start)();
    $this->service->coordinate($this->model->id);
    expect(($this->task)('teams')->state)->toBe('claimed')->and(($this->task)('league')->state)->toBe('claimed')
        ->and(($this->task)('players')->state)->toBe('pending')->and(($this->task)('goalies')->state)->toBe('pending');
});

it('honours zero allocation without admitting a task', function (): void {
    ($this->game)();
    ($this->start)();
    $this->service->allocate($this->model, array_fill_keys(array_keys($this->limits), 0));
    $this->service->coordinate($this->model->id);
    expect(DB::table('nhl_pregame_workstream_tasks')->where('state', 'claimed')->count())->toBe(0);
});

it('changes live allocations without revoking already claimed work', function (): void {
    ($this->game)();
    ($this->start)();
    $this->service->coordinate($this->model->id);
    $task = ($this->task)('teams');
    $this->limits['teams'] = 0;
    $this->service->allocate($this->model, $this->limits);
    $this->service->execute($task->id, $task->claim_token);
    expect(($this->task)('teams')->state)->toBe('complete');
});

it('does not exceed a live area limit across repeated coordination', function (): void {
    ($this->game)();
    ($this->game)(2022020002, '2022-10-11');
    ($this->start)();
    $this->service->coordinate($this->model->id);
    $this->service->coordinate($this->model->id);
    $goal = DB::table('nhl_pregame_workstream_goals')->where('area', 'teams')->value('id');
    expect(DB::table('nhl_pregame_workstream_tasks')->where('goal_id', $goal)->where('state', 'claimed')->count())->toBe(1);
});

it('permits several workers in the same area when sufficient independent tasks exist', function (): void {
    ($this->game)();
    ($this->game)(2022020002, '2022-10-11');
    ($this->start)();
    $this->limits['teams'] = 2;
    $this->service->allocate($this->model, $this->limits);
    $this->service->coordinate($this->model->id);
    $goal = DB::table('nhl_pregame_workstream_goals')->where('area', 'teams')->value('id');
    expect(DB::table('nhl_pregame_workstream_tasks')->where('goal_id', $goal)->where('state', 'claimed')->count())->toBe(2);
});

it('releases player goalie and travel tasks after their own team task commits', function (): void {
    ($this->game)();
    ($this->start)();
    $this->service->coordinate($this->model->id);
    $task = ($this->task)('teams');
    $this->service->execute($task->id, $task->claim_token);
    $this->service->coordinate($this->model->id);
    expect(($this->task)('players')->state)->toBe('claimed')->and(($this->task)('goalies')->state)->toBe('claimed')
        ->and(($this->task)('travel')->state)->toBe('claimed');
});

it('separates goalie participants from skater participants', function (): void {
    ($this->game)();
    $run = ($this->start)();
    $builder = app(NhlPregameContextBuilder::class);
    $builder->buildAreaPage($run, 2022020001, 'teams');
    $builder->buildAreaPage($run, 2022020001, 'goalies');
    expect(DB::table('nhl_player_game_pregame_contexts')->orderBy('nhl_player_id')->pluck('nhl_player_id')->all())->toBe([103, 104]);
    $builder->buildAreaPage($run, 2022020001, 'players');
    expect(DB::table('nhl_player_game_pregame_contexts')->count())->toBe(4);
});

it('keeps team metrics intact when travel writes its owned fields', function (): void {
    ($this->game)();
    $run = ($this->start)();
    $builder = app(NhlPregameContextBuilder::class);
    $builder->buildAreaPage($run, 2022020001, 'teams');
    $before = DB::table('nhl_team_game_pregame_contexts')->orderBy('id')->pluck('metrics')->all();
    $metricCount = DB::table('nhl_team_game_pregame_context_metrics')->count();
    $builder->buildAreaPage($run, 2022020001, 'travel');
    expect(DB::table('nhl_team_game_pregame_contexts')->orderBy('id')->pluck('metrics')->all())->toBe($before)
        ->and(DB::table('nhl_team_game_pregame_context_metrics')->count())->toBeGreaterThan($metricCount);
});

it('preserves travel fields when team values are refreshed', function (): void {
    ($this->game)();
    $run = ($this->start)();
    $builder = app(NhlPregameContextBuilder::class);
    $builder->buildAreaPage($run, 2022020001, 'teams');
    $builder->buildAreaPage($run, 2022020001, 'travel');
    $before = DB::table('nhl_team_game_pregame_contexts')->orderBy('id')->pluck('schedule_metrics')->all();
    $builder->buildAreaPage($run, 2022020001, 'teams');
    expect(DB::table('nhl_team_game_pregame_contexts')->orderBy('id')->pluck('schedule_metrics')->all())->toBe($before);
});

it('ignores stale and replaced task tokens', function (): void {
    ($this->game)();
    ($this->start)();
    $this->service->coordinate($this->model->id);
    $this->service->execute(($this->task)('teams')->id, 'stale-token');
    expect(DB::table('nhl_team_game_pregame_contexts')->count())->toBe(0);
});

it('recovers an expired delivery from its existing task checkpoint', function (): void {
    ($this->game)();
    ($this->start)();
    $this->service->coordinate($this->model->id);
    $old = ($this->task)('teams');
    DB::table('nhl_pregame_workstream_tasks')->where('id', $old->id)->update(['lease_expires_at' => now()->subSecond()]);
    $this->service->coordinate($this->model->id);
    expect(($this->task)('teams')->claim_token)->not->toBe($old->claim_token)
        ->and(($this->task)('teams')->cursor)->toBe($old->cursor);
});

it('does not restart a stopped goal when new source data arrives', function (): void {
    ($this->game)();
    $run = ($this->start)();
    app(NhlPregameContextOrchestrator::class)->stopModelBackfill($this->model);
    $this->service->sourcesChanged('20222023', '2022-10-10 23:00:00');
    $this->service->coordinate($this->model->id);
    expect(NhlPregameContextRun::count())->toBe(1)->and($run->fresh()->status)->toBe('stopped');
});

it('revokes every unfinished task token on Stop and keeps completed context', function (): void {
    ($this->game)();
    ($this->start)();
    $this->service->coordinate($this->model->id);
    $task = ($this->task)('teams');
    $this->service->execute($task->id, $task->claim_token);
    app(NhlPregameContextOrchestrator::class)->stopModelBackfill($this->model);
    expect(DB::table('nhl_pregame_workstream_tasks')->whereIn('state', ['claimed', 'working', 'pending'])->count())->toBe(0)
        ->and(DB::table('nhl_team_game_pregame_contexts')->count())->toBe(2);
});

it('pauses after claimed deliveries drain instead of waiting for pending tasks', function (): void {
    ($this->game)();
    ($this->start)();
    app(NhlPregameContextOrchestrator::class)->pauseModelBackfill($this->model);
    $this->service->coordinate($this->model->id);
    expect(NhlPregameContextRun::latestForModel($this->model->id)->status)->toBe('paused');
});

it('does not reset a legacy active run or its checkpoints', function (): void {
    $this->service->enrol($this->model);
    $run = app(NhlPregameContextOrchestrator::class)->queueModelBackfill($this->model);
    $before = $run->getRawOriginal();
    $this->service->coordinate($this->model->id);
    expect($run->fresh()->getRawOriginal())->toBe($before)->and(DB::table('nhl_pregame_workstream_tasks')->count())->toBe(0);
});

it('invalidates only enrolled historical goals for a relevant source season', function (): void {
    $this->service->enrol($this->model);
    $this->service->sourcesChanged('20262027');
    expect(DB::table('nhl_pregame_workstream_goals')->whereNotNull('dirty_from')->count())->toBe(0);
    $this->service->sourcesChanged('20222023', '2022-10-10 23:00:00');
    expect(DB::table('nhl_pregame_workstream_goals')->whereNotNull('dirty_from')->count())->toBe(6);
});

it('rejects arbitrary area repair names', function (): void {
    $this->actingAs($this->admin)->postJson('/admin/nhl-sat-models/pregame/repair', ['area' => 'production'])->assertUnprocessable();
});

it('reports actual worker identity separately from queued task counts', function (): void {
    ($this->game)();
    $run = ($this->start)();
    $this->service->coordinate($this->model->id);
    $task = ($this->task)('teams');
    DB::table('nhl_pregame_workstream_tasks')->where('id', $task->id)->update(['state' => 'working',
        'server' => 'queue-fixture', 'worker_pid' => 42, 'started_at' => now(), 'lease_expires_at' => now()->addMinute()]);
    ($this->read)()->assertOk()->assertJsonPath('props.pregame.workstream_goals.areas.1.workers.0.pid', 42)
        ->assertJsonPath('props.pregame.workstream_goals.areas.1.workers.0.run_id', $run->id)
        ->assertJsonPath('props.pregame.workstream_goals.areas.1.workers.0.state', 'running');
});

it('can finish a small goal run and expose the resulting area completion through HTTP', function (): void {
    ($this->game)();
    $run = ($this->start)();
    $results = $this->mock(NhlPregameLeagueResults::class);
    $results->shouldReceive('buildNextAverages')->andReturn(true);
    $results->shouldReceive('attachGameComparisonsPage')->andReturn(true);
    $results->shouldReceive('releaseDayPeerPage')->andReturn(false);
    for ($step = 0; $step < 30 && $run->fresh()->status !== 'completed'; $step++) {
        $this->service->coordinate($this->model->id);
        foreach (DB::table('nhl_pregame_workstream_tasks')->where('run_id', $run->id)->where('state', 'claimed')->get() as $task) {
            $this->service->execute($task->id, $task->claim_token);
        }
    }
    expect($run->fresh()->status)->toBe('completed')->and(DB::table('nhl_player_game_pregame_contexts')->count())->toBe(4);
    $this->service->coordinate($this->model->id);
    ($this->read)()->assertJsonPath('props.pregame.workstream_goals.areas.0.state', 'complete')
        ->assertJsonPath('props.pregame.workstream_goals.areas.0.remaining', 0);
    Bus::assertDispatched(WorkNhlPregameWorkstreamTaskJob::class);
});

it('keeps rankings waiting until both references and all component tasks are complete', function (): void {
    ($this->game)();
    ($this->start)();
    $this->service->coordinate($this->model->id);
    expect(DB::table('nhl_pregame_workstream_tasks as t')->join('nhl_pregame_workstream_goals as g', 'g.id', '=', 't.goal_id')
        ->where('g.area', 'rankings')->where('t.state', 'claimed')->count())->toBe(0);
});

it('does not count queued deliveries as executing workers', function (): void {
    ($this->game)();
    ($this->start)();
    $this->service->coordinate($this->model->id);
    $area = collect($this->service->payload($this->model)['areas'])->firstWhere('key', 'teams');
    expect($area['workers'])->toBe([])->and($area['counts']['claimed'])->toBe(1);
});

it('does not declare source-free seasons complete merely because the selection is empty', function (): void {
    ($this->start)();
    $this->service->coordinate($this->model->id);
    $area = $this->service->payload($this->model)['areas'][0];
    expect($area['state'])->toBe('unassessed')->and($area['last_error'])->toContain('No completed schedule games');
});

it('does not report completion when task records disappear', function (): void {
    ($this->game)();
    $run = ($this->start)();
    DB::table('nhl_pregame_workstream_tasks')->where('id', ($this->task)('players')->id)->delete();
    $this->service->coordinate($this->model->id);
    expect($run->fresh()->status)->toBe('failed')->and($run->fresh()->last_error)->toContain('inventory');
});

it('retains the earliest dirty cutoff across several committed source changes', function (): void {
    $this->service->enrol($this->model);
    $this->service->sourcesChanged('20222023', '2022-11-10 23:00:00');
    $this->service->sourcesChanged('20222023', '2022-10-10 23:00:00');
    $this->service->sourcesChanged('20222023', '2022-12-10 23:00:00');
    expect(DB::table('nhl_pregame_workstream_goals')->where('area', 'players')->value('dirty_from'))->toBe('2022-10-10 23:00:00');
});

it('keeps missing goalie participants visible as a source blocker', function (): void {
    ($this->game)();
    DB::table('nhl_game_summaries')->whereIn('nhl_player_id', [103, 104])->delete();
    ($this->start)();
    $this->service->coordinate($this->model->id);
    $team = ($this->task)('teams');
    $this->service->execute($team->id, $team->claim_token);
    $this->service->coordinate($this->model->id);
    $goalies = ($this->task)('goalies');
    $this->service->execute($goalies->id, $goalies->claim_token);
    expect(($this->task)('goalies')->state)->toBe('blocked')
        ->and(($this->task)('goalies')->last_error)->toContain('goalies participants are missing');
});

it('bounds repeated expired-lease recovery instead of retrying forever', function (): void {
    ($this->game)();
    ($this->start)();
    $this->service->coordinate($this->model->id);
    $task = ($this->task)('teams');
    DB::table('nhl_pregame_workstream_tasks')->where('id', $task->id)->update(['attempts' => 3, 'lease_expires_at' => now()->subSecond()]);
    $this->service->coordinate($this->model->id);
    expect(($this->task)('teams')->state)->toBe('failed')->and(($this->task)('teams')->last_error)->toContain('expired repeatedly');
});

it('preserves canonical team and player metrics when splitting the legacy snapshot into areas', function (): void {
    ($this->game)(2022020000, '2022-10-09');
    DB::table('nhl_games')->where('nhl_game_id', 2022020000)->update(['home_team_score' => 3, 'away_team_score' => 2,
        'home_team_sog' => 28, 'away_team_sog' => 25]);
    foreach ([101 => 1, 102 => 2, 103 => 1, 104 => 2] as $player => $team) {
        DB::table('nhl_player_game_strength_summaries')->insert(['nhl_game_id' => 2022020000,
            'player_id' => Player::where('nhl_id', $player)->value('id'), 'nhl_player_id' => $player,
            'team_id' => $team, 'strength' => 'EV', 'toi' => 900, 'satf' => 15, 'sata' => 12,
            'sf' => 8, 'sa' => 6, 'gf' => 2, 'ga' => 1, 'individual_pts' => 1]);
    }
    foreach ([101 => 1, 102 => 2] as $player => $team) {
        $play = DB::table('play_by_plays')->insertGetId(['nhl_game_id' => 2022020000,
            'period' => 1, 'seconds_in_game' => 120, 'type_desc_key' => 'goal']);
        DB::table('nhl_shot_attempts_facts')->insert(['play_by_play_id' => $play, 'nhl_game_id' => 2022020000,
            'team_id' => $team, 'shooter_player_id' => $player, 'attempt_result' => 'goal',
            'is_shot_attempt' => true, 'is_shot_on_goal' => true, 'is_goal' => true,
            'strength' => 'EV', 'strength_bucket' => 'ev', 'period_type' => 'REG']);
    }
    ($this->game)();
    $run = ($this->start)();
    $builder = app(NhlPregameContextBuilder::class);
    foreach (['teams', 'travel', 'players', 'goalies'] as $area) {
        $builder->buildAreaPage($run, 2022020001, $area);
    }
    $baseline = $this->model->replicate();
    $baseline->run_key = 'canonical-comparison';
    $baseline->name = 'Canonical baseline';
    $baseline->save();
    $legacy = NhlPregameContextRun::create(['model_run_id' => $baseline->id, 'action' => 'backfill', 'status' => 'running',
        'season_ids' => ['20222023'], 'options' => ['train_season_ids' => ['20222023']]]);
    $builder->build($legacy, 2022020001);
    foreach (['team' => 'nhl_team_id', 'player' => 'nhl_player_id'] as $entity => $key) {
        $columns = $entity === 'team' ? [$key, 'metrics', 'opponent_metrics', 'schedule_metrics'] : [$key, 'metrics'];
        $current = DB::table('nhl_'.$entity.'_game_pregame_contexts')->where('model_run_id', $this->model->id)->orderBy($key)->get($columns);
        $original = DB::table('nhl_'.$entity.'_game_pregame_contexts')->where('model_run_id', $baseline->id)->orderBy($key)->get($columns);
        expect($current->toJson())->toBe($original->toJson());
    }
});

it('resumes a paused workstream run without changing completed task checkpoints', function (): void {
    ($this->game)();
    $run = ($this->start)();
    $this->service->coordinate($this->model->id);
    $team = ($this->task)('teams');
    $this->service->execute($team->id, $team->claim_token);
    app(NhlPregameContextOrchestrator::class)->pauseModelBackfill($this->model);
    DB::table('nhl_pregame_workstream_tasks')->where('state', 'claimed')
        ->update(['state' => 'pending', 'claim_token' => null, 'lease_expires_at' => null]);
    $this->service->coordinate($this->model->id);
    $before = (array) ($this->task)('teams');
    app(NhlPregameContextOrchestrator::class)->resumeModelBackfill($this->model);
    expect((array) ($this->task)('teams'))->toBe($before)->and($run->fresh()->status)->toBe('running');
});

it('requires team context before writing player records', function (): void {
    ($this->game)();
    $run = ($this->start)();
    expect(fn () => app(NhlPregameContextBuilder::class)->buildAreaPage($run, 2022020001, 'players'))
        ->toThrow(\App\Exceptions\NhlPregameContextDataUnavailable::class, 'same-game team context');
    expect(DB::table('nhl_player_game_pregame_contexts')->count())->toBe(0);
});

it('resumes the blocked cleanup belonging to a failed game without discarding its cursor', function (): void {
    ($this->game)();
    $run = ($this->start)();
    $team = ($this->task)('teams');
    DB::table('nhl_pregame_workstream_tasks')->where('id', $team->id)->update(['state' => 'failed']);
    DB::table('nhl_pregame_workstream_tasks')->where('run_id', $run->id)->where('kind', 'cleanup')
        ->update(['state' => 'blocked', 'cursor' => 12]);
    DB::table('nhl_pregame_context_run_games')->where('run_id', $run->id)->update(['state' => 'failed']);
    $run->update(['status' => 'failed']);
    $this->service->resume($run);
    $cleanup = DB::table('nhl_pregame_workstream_tasks')->where('run_id', $run->id)->where('kind', 'cleanup')->first();
    expect($cleanup->state)->toBe('pending')->and((int) $cleanup->cursor)->toBe(12)
        ->and(($this->task)('teams')->state)->toBe('pending')->and($run->fresh()->status)->toBe('running');
});
