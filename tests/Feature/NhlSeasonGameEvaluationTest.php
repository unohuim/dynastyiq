<?php

declare(strict_types=1);

use App\Jobs\RunNhlSeasonGameEvaluationJob;
use App\Models\NhlGame;
use App\Models\NhlModelRun;
use App\Models\NhlPregameContextRun;
use App\Models\Player;
use App\Models\Role;
use App\Models\User;
use App\Services\NhlCustomGameForecast;
use App\Services\NhlCustomGamePredictionInputs;
use App\Services\NhlSeasonGameEvaluation;
use App\Services\NhlPregameContextBuilder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-19 12:00:00 UTC'));
    config(['cache.default' => 'array', 'queue.default' => 'redis', 'nhl-custom-prediction' => require base_path('config/nhl-custom-prediction.php')]);
    Cache::flush();
    Bus::fake();
    Http::preventStrayRequests();
    $this->mock(\App\Services\PlatformState::class)->shouldReceive('seeded')->andReturn(true);
    $this->admin = User::factory()->create();
    $role = Role::query()->firstOrCreate(['slug' => 'super-admin'], ['name' => 'Super Admin', 'level' => 99]);
    $this->admin->roles()->attach($role->id, ['organization_id' => null]);
    $this->model = NhlModelRun::query()->create(['name' => 'Sep2026', 'run_key' => 'season-evaluation', 'model_family' => 'sat',
        'workflow_stage' => 'training', 'model_version' => 'fixture', 'status' => 'complete',
        'train_season_ids' => ['20222023', '20232024', '20242025'], 'target_season_id' => '20252026']);
    // Bucket-fitting prerequisites are explicit fixtures; readiness still checks real persisted game/context coverage.
    $reader = Mockery::mock(NhlCustomGamePredictionInputs::class)->makePartial();
    $reader->shouldReceive('prepare')->andReturn(['model_id' => $this->model->id]);
    app()->instance(NhlCustomGamePredictionInputs::class, $reader);
    $this->service = app(NhlSeasonGameEvaluation::class);
    $this->base = '/admin/nhl-sat-models/'.$this->model->id.'/season-games';
    $this->gameId = 2025020001;
    foreach ([101 => 'F', 102 => 'G', 201 => 'D', 202 => 'G'] as $id => $position) {
        Player::query()->create(['nhl_id' => $id, 'full_name' => 'Player '.$id, 'position' => $position, 'pos_type' => $position === 'G' ? 'G' : 'S', 'is_goalie' => $position === 'G']);
    }
    $unit = DB::table('nhl_units')->insertGetId(['unit_type' => 'F']);
    foreach (['20222023', '20232024', '20242025', '20252026'] as $season) {
        $id = (int) (substr($season, 0, 4).'020001');
        $date = substr($season, 0, 4).'-11-01';
        NhlGame::query()->create(['nhl_game_id' => $id, 'season_id' => $season, 'game_type' => 2, 'game_date' => $date,
            'game_dow' => 'Saturday', 'game_month' => 'November', 'start_time_utc' => $date.' 23:00:00', 'game_state' => 'FINAL',
            'away_team_id' => 1, 'home_team_id' => 2, 'away_team_abbrev' => 'BOS', 'home_team_abbrev' => 'TOR',
            'away_team_score' => 2, 'home_team_score' => 3]);
        $play = DB::table('play_by_plays')->insertGetId(['nhl_game_id' => $id]);
        DB::table('nhl_shot_attempts_facts')->insert(['nhl_game_id' => $id, 'play_by_play_id' => $play, 'attempt_result' => 'saved']);
        DB::table('nhl_unit_shifts')->insert(['nhl_game_id' => $id, 'unit_id' => $unit, 'period' => 1,
            'start_time' => '00:00', 'end_time' => '20:00', 'start_game_seconds' => 0, 'end_game_seconds' => 1200]);
        foreach ([101 => 1, 102 => 1, 201 => 2, 202 => 2] as $player => $team) {
            DB::table('nhl_game_summaries')->insert(['nhl_game_id' => $id, 'nhl_player_id' => $player,
                'nhl_team_id' => $team, 'toi' => 1200, 'goalie_started' => in_array($player, [102, 202], true)]);
            DB::table('nhl_boxscores')->insert(['nhl_game_id' => $id, 'nhl_player_id' => $player, 'nhl_team_id' => $team]);
            DB::table('nhl_player_game_strength_summaries')->insert(['nhl_game_id' => $id, 'nhl_player_id' => $player,
                'player_id' => Player::query()->where('nhl_id', $player)->value('id'), 'team_id' => $team, 'strength' => 'EV', 'toi' => 1200]);
        }
    }
    $contextRun = NhlPregameContextRun::query()->create(['model_run_id' => $this->model->id, 'action' => 'backfill',
        'status' => 'completed', 'season_ids' => ['20252026']]);
    DB::table('nhl_pregame_context_run_games')->insert(['run_id' => $contextRun->id, 'nhl_game_id' => $this->gameId,
        'game_date' => '2025-11-01', 'sequence' => 1, 'state' => 'complete', 'comparisons_completed_at' => now()]);
    foreach ([1 => [101, 102], 2 => [201, 202]] as $team => $ids) {
        $common = ['model_run_id' => $this->model->id, 'run_id' => $contextRun->id, 'nhl_game_id' => $this->gameId,
            'nhl_team_id' => $team, 'opponent_team_id' => $team === 1 ? 2 : 1, 'game_date' => '2025-11-01',
            'venue' => $team === 1 ? 'away' : 'home', 'source_cutoff_at' => '2025-11-01 12:00:00',
            'context_version' => NhlPregameContextBuilder::VERSION, 'metrics' => '{}', 'created_at' => now(), 'updated_at' => now()];
        $parent = DB::table('nhl_team_game_pregame_contexts')->insertGetId([...$common, 'schedule_metrics' => '{}', 'opponent_metrics' => '{}']);
        foreach ($ids as $player) {
            DB::table('nhl_player_game_pregame_contexts')->insert([...$common, 'nhl_player_id' => $player,
                'team_context_id' => $parent, 'participant_source' => 'completed_game_summary']);
        }
    }
    $this->savedRun = function (array $extra = []): int {
        return DB::table('nhl_custom_game_prediction_runs')->insertGetId([
            'name' => 'Saved baseline', 'model_run_id' => $this->model->id, 'season_id' => '20252026',
            'algorithm_version' => 'custom_ev_pp_pk_v5', 'status' => 'complete', 'total_games' => 1,
            'definition' => json_encode(['entrypoint' => 'season_games', 'identity' => $this->service->identity($this->model), 'game_ids' => [$this->gameId]]),
            'created_at' => now()->subHour(), 'updated_at' => now()->subHour(), ...$extra,
        ]);
    };
    $this->savedGame = function (int $runId, array $extra = []): int {
        return DB::table('nhl_custom_game_predictions')->insertGetId(['run_id' => $runId, 'nhl_game_id' => $this->gameId,
            'status' => 'complete', 'source_cutoff_at' => '2025-11-01 23:00:00', 'home_goals_prediction' => 3.0,
            'away_goals_prediction' => 2.0, 'total_goals_prediction' => 5.0, 'home_actual_goals' => 3,
            'away_actual_goals' => 2, 'home_win_probability' => .6, 'away_win_probability' => .4,
            'recommendation' => 'TOR', 'actual_winner' => 'home', 'winner_correct' => true,
            'created_at' => now(), 'updated_at' => now(), ...$extra]);
    };
});

afterEach(function (): void { $this->travelBack(); });

it('denies guests all season evaluation endpoints', function (string $method, string $suffix): void {
    $this->json($method, $this->base.$suffix)->assertUnauthorized();
})->with([['GET', ''], ['POST', '/runs'], ['POST', '/runs/1/resume'], ['GET', '/runs/1/games/1']]);

it('denies non-admins all season evaluation endpoints', function (string $method, string $suffix): void {
    $this->actingAs(User::factory()->create())->json($method, $this->base.$suffix)->assertForbidden();
})->with([['GET', ''], ['POST', '/runs'], ['POST', '/runs/1/resume'], ['GET', '/runs/1/games/1']]);

it('admits a run freezes its definition and reads it through the new page', function (): void {
    $response = $this->actingAs($this->admin)->postJson($this->base.'/runs')->assertAccepted();
    $id = $response->json('run_id');
    $row = DB::table('nhl_custom_game_prediction_runs')->find($id);
    expect($row->status)->toBe('queued')->and(json_decode($row->definition, true)['game_ids'])->toBe([$this->gameId]);
    $this->get($this->base.'?run='.$id)->assertInertia(fn (Assert $page) => $page->component('Admin/Evaluations/SeasonGames')
        ->where('selected.id', $id)->where('selected.total_games', 1)->where('readiness.ready', true));
});

it('passes readiness without requiring standalone rate or TOI projections', function (): void {
    expect($this->service->readiness($this->model, true)['ready'])->toBeTrue();
    expect(DB::table('nhl_sat_model_entity_rate_projection_buckets')->count())->toBe(0);
});

it('blocks a draft model with an actionable reason', function (): void {
    $this->model->update(['status' => 'draft']);
    expect($this->service->readiness($this->model, true)['ready'])->toBeFalse();
    $this->actingAs($this->admin)->postJson($this->base.'/runs')->assertUnprocessable()->assertJsonValidationErrors('run');
});

it('blocks a model without an explicit Test season', function (): void {
    $this->model->update(['target_season_id' => null]);
    expect($this->service->readiness($this->model, true)['reasons'])->toContain('Finish this SAT model and select its Test season.');
});

it('surfaces the readers bucket or coefficient prerequisite error', function (): void {
    $reader = Mockery::mock(NhlCustomGamePredictionInputs::class);
    $reader->shouldReceive('prepare')->andThrow(new DomainException('No reliable Train buckets.'));
    app()->instance(NhlCustomGamePredictionInputs::class, $reader);
    expect($this->service->readiness($this->model, true)['reasons'])->toContain('No reliable Train buckets.');
});

it('reports missing imported source coverage by game', function (string $table): void {
    DB::table($table)->where('nhl_game_id', $this->gameId)->delete();
    $ready = $this->service->readiness($this->model, true);
    expect($ready['ready'])->toBeFalse()->and($ready['missing_games'][0]['game_id'])->toBe($this->gameId)
        ->and($ready['missing_games'][0]['missing'])->toContain($table);
})->with(['nhl_shot_attempts_facts', 'nhl_player_game_strength_summaries', 'nhl_unit_shifts', 'nhl_boxscores']);

it('blocks unfinished games and unknown start cutoffs', function (array $changes): void {
    NhlGame::query()->findOrFail($this->gameId)->update($changes);
    expect($this->service->readiness($this->model, true)['ready'])->toBeFalse();
})->with([[['game_state' => 'FUT']], [['start_time_utc' => null]]]);

it('requires committed context comparisons', function (): void {
    DB::table('nhl_pregame_context_run_games')->update(['comparisons_completed_at' => null]);
    expect($this->service->readiness($this->model, true)['ready'])->toBeFalse();
});

it('requires player context to share its teams cutoff and parent', function (array $changes): void {
    DB::table('nhl_player_game_pregame_contexts')->where('nhl_player_id', 101)->update($changes);
    expect($this->service->readiness($this->model, true)['ready'])->toBeFalse();
})->with([[['source_cutoff_at' => '2025-11-01 10:00:00']], [['opponent_team_id' => 99]]]);

it('requires starting goalie context even when skater contexts exist', function (): void {
    DB::table('nhl_player_game_pregame_contexts')->where('nhl_player_id', 102)->delete();
    expect($this->service->readiness($this->model, true)['missing_games'][0]['missing'])->toContain('away linked player/starter context');
});

it('blocks a second active run while retaining prior history', function (): void {
    ($this->savedRun)(['status' => 'running']);
    $this->actingAs($this->admin)->postJson($this->base.'/runs')->assertUnprocessable();
    expect(DB::table('nhl_custom_game_prediction_runs')->count())->toBe(1);
});

it('creates a separate run after a completed baseline', function (): void {
    $old = ($this->savedRun)();
    $response = $this->actingAs($this->admin)->postJson($this->base.'/runs')->assertAccepted();
    expect($response->json('run_id'))->not->toBe($old);
    $this->assertDatabaseHas('nhl_custom_game_prediction_runs', ['id' => $old, 'status' => 'complete']);
});

it('rejects synchronous execution rather than calculating in HTTP', function (): void {
    config(['queue.default' => 'sync']);
    $this->actingAs($this->admin)->postJson($this->base.'/runs')->assertUnprocessable();
    expect(DB::table('nhl_custom_game_prediction_runs')->count())->toBe(0);
});

it('prevents selecting another models run', function (): void {
    $id = ($this->savedRun)(['model_run_id' => null]);
    $this->actingAs($this->admin)->get($this->base.'?run='.$id)->assertNotFound();
});

it('prevents comparison or resume across model ownership', function (): void {
    $id = ($this->savedRun)();
    $other = ($this->savedRun)(['model_run_id' => null]);
    $this->actingAs($this->admin)->get($this->base.'?run='.$id.'&compare='.$other)->assertNotFound();
    $this->postJson($this->base.'/runs/'.$other.'/resume')->assertNotFound();
});

it('resumes failed runs without replacing committed games', function (): void {
    $id = ($this->savedRun)(['status' => 'failed']);
    $prediction = ($this->savedGame)($id);
    $this->actingAs($this->admin)->postJson($this->base.'/runs/'.$id.'/resume')->assertAccepted();
    $this->assertDatabaseHas('nhl_custom_game_predictions', ['id' => $prediction, 'winner_correct' => true]);
    $this->assertDatabaseHas('nhl_custom_game_prediction_runs', ['id' => $id, 'status' => 'queued']);
});

it('refuses resuming a run under changed algorithm settings', function (): void {
    $id = ($this->savedRun)(['status' => 'failed']);
    config(['nhl-custom-prediction.train_rate_weight' => .9]);
    $this->actingAs($this->admin)->postJson($this->base.'/runs/'.$id.'/resume')->assertUnprocessable();
});

it('refuses resuming completed or recently active runs', function (string $status): void {
    $id = ($this->savedRun)(['status' => $status, 'updated_at' => now()]);
    $this->actingAs($this->admin)->postJson($this->base.'/runs/'.$id.'/resume')->assertUnprocessable();
})->with(['complete', 'running', 'queued']);

it('allows abandoned runs to resume after the worker timeout and lock grace', function (): void {
    $id = ($this->savedRun)(['status' => 'running', 'updated_at' => now()->subMinutes(6)]);
    $this->actingAs($this->admin)->postJson($this->base.'/runs/'.$id.'/resume')->assertAccepted();
});

it('calculates win percentage from saved picks and keeps no-picks separate', function (): void {
    $id = ($this->savedRun)();
    ($this->savedGame)($id);
    $summary = $this->service->summary($id);
    expect($summary['wins'])->toBe(1)->and($summary['win_pct'])->toEqual(100);
    DB::table('nhl_custom_game_predictions')->where('run_id', $id)->update(['winner_correct' => null, 'recommendation' => 'OT']);
    expect($this->service->summary($id)['win_pct'])->toBeNull()->and($this->service->summary($id)['unpicked'])->toBe(1);
});

it('compares only matching picked outcomes and identifies corrected losses', function (): void {
    $a = ($this->savedRun)();
    $b = ($this->savedRun)();
    ($this->savedGame)($a, ['winner_correct' => false]);
    ($this->savedGame)($b);
    $comparison = $this->service->compare($a, $b);
    expect($comparison['matched'])->toBe(1)->and($comparison['before_win_pct'])->toEqual(0)
        ->and($comparison['after_win_pct'])->toEqual(100)->and($comparison['changes'][0]['after'])->toBeTrue();
});

it('does not compare games whose saved actual outcomes differ', function (): void {
    $a = ($this->savedRun)();
    $b = ($this->savedRun)();
    ($this->savedGame)($a);
    ($this->savedGame)($b, ['home_actual_goals' => 4]);
    expect($this->service->compare($a, $b)['matched'])->toBe(0);
});

it('serves frozen per-game results without updating current prediction revisions', function (): void {
    $id = ($this->savedRun)();
    ($this->savedGame)($id);
    NhlGame::query()->findOrFail($this->gameId)->update(['home_team_score' => 9]);
    $this->actingAs($this->admin)->get($this->base.'?run='.$id)->assertInertia(fn (Assert $page) => $page
        ->where('games.data.0.home_actual_goals', 3)->where('summary.wins', 1));
    expect(DB::table('nhl_game_prediction_revisions')->count())->toBe(0);
});

it('rejects details for a prediction outside the selected run', function (): void {
    $a = ($this->savedRun)();
    $b = ($this->savedRun)();
    $prediction = ($this->savedGame)($a);
    $this->actingAs($this->admin)->getJson($this->base.'/runs/'.$b.'/games/'.$prediction)->assertNotFound();
});

it('returns an empty history without dispatching prediction work', function (): void {
    $this->actingAs($this->admin)->get($this->base)->assertInertia(fn (Assert $page) => $page
        ->has('runs', 0)->where('selected', null));
    Bus::assertNotDispatched(RunNhlSeasonGameEvaluationJob::class);
    Http::assertNothingSent();
});

it('routes season execution to projections with a fixed deadline', function (): void {
    $job = new RunNhlSeasonGameEvaluationJob(42);
    expect($job->queue)->toBe('projections')->and($job->timeout)->toBe(120)
        ->and($job->retryUntil()->getTimestamp())->toBe(now()->addDay()->timestamp);
});

it('ignores obsolete queue deliveries after a new resume generation', function (): void {
    Cache::put('nhl:season-evaluation:42:generation', 'new');
    $service = Mockery::mock(NhlSeasonGameEvaluation::class);
    $service->shouldNotReceive('advance');
    $service->shouldNotReceive('fail');
    $job = new RunNhlSeasonGameEvaluationJob(42, 'old');
    $job->handle($service);
    app()->instance(NhlSeasonGameEvaluation::class, $service);
    $job->failed(new RuntimeException('Obsolete failure'));
});

it('reuses bounded typed checkpoints for isolated season readers', function (): void {
    $reader = new NhlCustomGamePredictionInputs();
    $forecast = new NhlCustomGameForecast();
    $forecast->writeSeasonCheckpoint(99, ['phase' => 'season', 'fingerprint' => 'fixture', 'reader' => $reader]);
    $saved = $forecast->readSeasonCheckpoint(99);
    expect($saved['reader'])->toBeInstanceOf(NhlCustomGamePredictionInputs::class)->and($saved['fingerprint'])->toBe('fixture');
});

it('keeps same-day target outcomes out of one anothers historical inputs', function (string $secondTime): void {
    $games = [];
    foreach ([1 => '2025-11-01 23:00:00', 2 => '2025-11-01 '.$secondTime, 3 => '2025-11-02 23:00:00'] as $id => $time) {
        $games[] = (object) ['nhl_game_id' => $id, 'season_id' => '20252026', 'start_time_utc' => $time,
            'home_team_id' => 2, 'away_team_id' => 1, 'home_pp_opportunities' => 0, 'away_pp_opportunities' => 0,
            'home_pp_goals' => 0, 'away_pp_goals' => 0];
    }
    $reader = new class extends NhlCustomGamePredictionInputs
    {
        /** The source gap is deliberate: temporal advancement must also be correct for explicitly excluded targets. */
        protected function records(array $games): array
        {
            return [$games[0]->nhl_game_id => ['valid' => false, 'source' => false, 'unmatched_shooter' => false,
                'players' => [101 => ['team' => 1, 'seconds' => [], 'counts' => [], 'unclassified' => false]],
                'counts' => [], 'actual' => [], 'reg_clocks' => [], 'clocks' => []]];
        }

        /** Avoid unrelated context reads for the deliberate invalid-source fixture. */
        protected function contexts(array $games): array
        {
            return ['teams' => [], 'players' => []];
        }
    };
    $reader->restoreCheckpointState(['filter' => ['keys' => ['B']],
        'metadata' => ['model_id' => 1, 'train_seasons' => ['20242025'], 'test_season' => '20252026'],
        'forecastWork' => ['phase' => 'replay', 'cursor' => 0, 'defense' => new \App\Services\NhlCustomGameDefense(),
            'replay_games' => $games, 'pending_history' => [], 'test_ids' => [1 => true, 2 => true, 3 => true]]]);
    $seen = [];
    $save = function (array $event) use ($reader, &$seen): void {
        $seen[] = count($reader->checkpointState()['recent'][101] ?? []);
        expect($event['input'])->toBeNull()->and($event['error'])->toContain('Target source facts');
    };
    $reader->advanceSeasonReplay($save);
    $reader->advanceSeasonReplay($save);
    $result = $reader->advanceSeasonReplay($save);
    expect($seen)->toBe([0, 0, 2])->and($result['complete'])->toBeTrue();
})->with(['23:00:00', '23:30:00']);

it('does not duplicate an excluded game when retrying the same committed checkpoint', function (): void {
    $id = ($this->savedRun)(['status' => 'running']);
    $event = ['game' => (object) ['nhl_game_id' => $this->gameId, 'start_time_utc' => '2025-11-01 23:00:00'], 'error' => 'Missing prior context'];
    $replay = app(\App\Services\NhlCustomGamePredictionReplay::class);
    $replay->saveSeasonEvent($id, $event);
    $replay->saveSeasonEvent($id, $event);
    expect(DB::table('nhl_custom_game_predictions')->where('run_id', $id)->count())->toBe(1)
        ->and((int) DB::table('nhl_custom_game_prediction_runs')->where('id', $id)->value('excluded_games'))->toBe(1);
});

it('leaves a saved successful prediction untouched on checkpoint retry', function (): void {
    $id = ($this->savedRun)(['status' => 'running']);
    $prediction = ($this->savedGame)($id);
    app(\App\Services\NhlCustomGamePredictionReplay::class)->saveSeasonEvent($id,
        ['game' => (object) ['nhl_game_id' => $this->gameId], 'error' => 'Later source change']);
    $this->assertDatabaseHas('nhl_custom_game_predictions', ['id' => $prediction, 'status' => 'complete', 'winner_correct' => true]);
});

it('publishes team player and goalie snapshots and reads them without replaying the engine', function (): void {
    $run = ($this->savedRun)(['status' => 'running']);
    $input = ['game_id' => $this->gameId, 'source_cutoff_at' => '2025-11-01 23:00:00', 'retained_bucket_keys' => ['B'],
        'league_usage_seconds' => ['EV' => 600.0, 'PP' => 60.0, 'PK' => 60.0], 'league_pk_probability' => .8, 'teams' => []];
    $goalies = [];
    foreach (['away' => [1, 101, 102, 'BOS'], 'home' => [2, 201, 202, 'TOR']] as $side => [$team, $player, $goalie, $abbrev]) {
        $parent = DB::table('nhl_team_game_pregame_contexts')->where('nhl_team_id', $team)->value('id');
        $child = DB::table('nhl_player_game_pregame_contexts')->where('nhl_player_id', $player)->value('id');
        $input['teams'][$side] = ['id' => $team, 'abbrev' => $abbrev, 'context_id' => $parent, 'team_metrics' => [],
            'starting_goalie_id' => $goalie, 'lineup_source' => 'historical_participants', 'train_opportunity_share' => .5,
            'pk_opportunities_l10' => 3.0, 'pp_offense_l20' => .8, 'pp_allowed_l5' => .6, 'pk_probability' => .8,
            'schedule' => ['back_to_back' => false, 'games_including_target_last_7_days' => 3],
            'players' => [$player => ['name' => 'Player '.$player, 'role' => 'F', 'context_id' => $child,
                'usage' => ['EV' => 600.0, 'PP' => 60.0, 'PK' => 60.0], 'complete' => ['EV' => true, 'PP' => true, 'PK' => true],
                'history' => [], 'buckets' => ['EV' => ['B' => ['sat_per_60' => 12.0, 'defense_allowance' => 1.0,
                    'defense_reference' => 1.0, 'sog_probability' => .5, 'goal_probability' => .1]],
                    'PP' => ['B' => ['sat_per_60' => 24.0, 'defense_allowance' => 1.0, 'defense_reference' => 1.0,
                        'sog_probability' => .5, 'goal_probability' => .1]]]]]];
        $goalies[$side] = ['id' => $goalie, 'name' => 'Goalie '.$goalie,
            'context_id' => DB::table('nhl_player_game_pregame_contexts')->where('nhl_player_id', $goalie)->value('id'),
            'context' => ['windows' => ['last_10' => ['games' => 10, 'on_ice_save_pct' => 91.0]]],
            'actual' => ['saves' => 18, 'shots_against' => 20, 'goals_against' => 2]];
    }
    $event = ['game' => DB::table('nhl_games')->where('nhl_game_id', $this->gameId)->first(), 'error' => null, 'input' => $input,
        'actual' => [1 => ['EV' => ['sat' => 10, 'sog' => 5, 'goals' => 2]], 2 => ['EV' => ['sat' => 12, 'sog' => 6, 'goals' => 3]]],
        'player_actual' => [101 => ['EV' => ['B' => ['sat' => 10, 'sog' => 5, 'goals' => 2]]]],
        'participants' => [101 => ['seconds' => ['EV' => 900, 'PP' => 100]], 201 => ['seconds' => ['EV' => 800, 'PP' => 100]]],
        'actual_clocks' => [1 => ['EV' => 3000, 'PP' => 300, 'PK' => 300], 2 => ['EV' => 3000, 'PP' => 300, 'PK' => 300]],
        'goalies' => $goalies];
    app(\App\Services\NhlCustomGamePredictionReplay::class)->saveSeasonEvent($run, $event);
    $id = DB::table('nhl_custom_game_predictions')->where('run_id', $run)->value('id');
    $this->assertDatabaseHas('nhl_custom_game_prediction_teams', ['prediction_id' => $id, 'actual_ev_toi_seconds' => 3000]);
    $this->actingAs($this->admin)->getJson($this->base.'/runs/'.$run.'/games/'.$id)->assertOk()
        ->assertJsonPath('teams.away.players.0.lanes.EV.actual.sat', 10)
        ->assertJsonPath('teams.away.players.0.lanes.PP.actual.goals', 0)
        ->assertJsonPath('teams.away.players.0.lanes.EV.actual.seconds', 900)
        ->assertJsonPath('teams.away.players.1.goalie.actual.saves', 18)
        ->assertJsonPath('teams.away.players.1.goalie.prediction.goalie_adjustment', 'neutral');
});

it('keeps the season menu disabled until readiness succeeds while preserving access to results', function (bool $ready): void {
    if (! $ready) {
        $this->model->update(['status' => 'draft']);
    }
    $this->service->readiness($this->model, true);
    $html = view('admin.nhl-sat-models._model-row', ['run' => $this->model, 'pregameBuild' => null])->render();
    expect($html)->toContain('Season Game Results')
        ->and($html)->toContain($ready ? '>Run Season Games</a>' : '>Run Season Games</span>');
})->with([true, false]);

it('makes older saved runs reachable through paginated history', function (): void {
    foreach (range(1, 21) as $index) {
        ($this->savedRun)();
    }
    $this->actingAs($this->admin)->get($this->base.'?history_page=2')->assertInertia(fn (Assert $page) => $page
        ->has('runs', 1)->has('runLinks')->where('selected.id', 1));
});

it('keeps pooled goal error distinct from individual team and game threshold accuracy', function (): void {
    $run = ($this->savedRun)();
    $prediction = ($this->savedGame)($run);
    foreach ([1 => [10, 9], 2 => [0, 1]] as $team => [$estimate, $actual]) {
        DB::table('nhl_custom_game_prediction_teams')->insert(['prediction_id' => $prediction,
            'nhl_team_id' => $team, 'opponent_team_id' => $team === 1 ? 2 : 1, 'venue' => $team === 1 ? 'away' : 'home',
            'goals_prediction' => $estimate, 'actual_ev_goals' => $actual, 'actual_pp_goals' => 0,
            'created_at' => now(), 'updated_at' => now()]);
    }
    $summary = $this->service->summary($run);
    expect($summary['team_goals']['err_pct'])->toEqual(0)
        ->and($summary['team_goals']['p10'])->toEqual(0)->and($summary['team_goals']['p20'])->toEqual(50)
        ->and($summary['game_goals']['p20'])->toEqual(100);
});

it('keeps legacy command runs inspectable without blocking the new administrative runner', function (): void {
    ($this->savedRun)(['status' => 'running', 'definition' => json_encode(['schedule_enabled' => true])]);
    $this->actingAs($this->admin)->postJson($this->base.'/runs')->assertAccepted();
    expect(DB::table('nhl_custom_game_prediction_runs')->count())->toBe(2);
});

it('dispatches a run-owned generation through the projections job after admission', function (): void {
    DB::partialMock()->shouldReceive('afterCommit')->andReturnUsing(fn (Closure $callback) => $callback());
    $id = $this->actingAs($this->admin)->postJson($this->base.'/runs')->assertAccepted()->json('run_id');
    Bus::assertDispatched(RunNhlSeasonGameEvaluationJob::class, fn ($job): bool => $job->runId === $id
        && $job->queue === 'projections' && $job->generation === Cache::get('nhl:season-evaluation:'.$id.':generation'));
});

it('can discard only a broken transient checkpoint during explicit resume', function (): void {
    $id = ($this->savedRun)(['status' => 'failed', 'last_error' => 'Prediction checkpoint is corrupt.']);
    ($this->savedGame)($id);
    Cache::put('nhl:season-evaluation:'.$id.':work', ['broken' => true]);
    Cache::put('nhl:season-evaluation:99999:work', ['other' => true]);
    $this->actingAs($this->admin)->postJson($this->base.'/runs/'.$id.'/resume')->assertAccepted();
    expect(Cache::get('nhl:season-evaluation:'.$id.':work'))->toBeNull()
        ->and(Cache::get('nhl:season-evaluation:99999:work'))->toBe(['other' => true]);
    expect(DB::table('nhl_custom_game_predictions')->where('run_id', $id)->count())->toBe(1);
});

it('includes retained PK goals in error comparisons for PK-enabled runs', function (): void {
    $run = ($this->savedRun)();
    $prediction = ($this->savedGame)($run);
    foreach ([1, 2] as $team) {
        DB::table('nhl_custom_game_prediction_teams')->insert([
            'prediction_id' => $prediction, 'nhl_team_id' => $team, 'opponent_team_id' => $team === 1 ? 2 : 1,
            'venue' => $team === 1 ? 'away' : 'home', 'goals_prediction' => 3,
            'pk_goals_prediction' => 1, 'actual_ev_goals' => 2, 'actual_pp_goals' => 0, 'actual_pk_goals' => 1,
        ]);
    }
    $summary = $this->service->summary($run);
    expect($summary['team_goals']['p20'])->toEqual(100)->and($summary['team_goals']['err_pct'])->toEqual(0)
        ->and($summary['game_goals']['actual_sum'])->toEqual(6);
});

it('keeps missing PK actuals unknown for PK-enabled comparisons', function (): void {
    $run = ($this->savedRun)();
    $prediction = ($this->savedGame)($run);
    DB::table('nhl_custom_game_prediction_teams')->insert([
        'prediction_id' => $prediction, 'nhl_team_id' => 1, 'opponent_team_id' => 2, 'venue' => 'away',
        'goals_prediction' => 3, 'pk_goals_prediction' => 1, 'actual_ev_goals' => 2, 'actual_pp_goals' => 0,
    ]);
    expect($this->service->summary($run)['team_goals']['count'])->toBe(0);
});

it('publishes historical preparation counts through the admitted runs automatic read path', function (): void {
    DB::partialMock()->shouldReceive('afterCommit')->andReturnUsing(fn (Closure $callback) => $callback());
    $id = $this->actingAs($this->admin)->postJson($this->base.'/runs')->assertAccepted()->json('run_id');
    $this->assertDatabaseHas('nhl_custom_game_prediction_runs', ['id' => $id, 'status' => 'queued']);
    $reader = new NhlCustomGamePredictionInputs();
    $reader->restoreCheckpointState(['forecastWork' => ['phase' => 'replay', 'cursor' => 2,
        'replay_games' => [(object) ['nhl_game_id' => 1], (object) ['nhl_game_id' => 2],
            (object) ['nhl_game_id' => 3], (object) ['nhl_game_id' => $this->gameId]],
        'test_ids' => [$this->gameId => true], 'defense' => new \App\Services\NhlCustomGameDefense()]]);
    $identity = $this->service->identity($this->model);
    app(NhlCustomGameForecast::class)->writeSeasonCheckpoint($id, ['phase' => 'season', 'fingerprint' => 'fixture', 'reader' => $reader]);
    $this->get($this->base.'?run='.$id)->assertInertia(fn (Assert $page) => $page
        ->where('selected.checkpoint.stage', 'history')->where('selected.checkpoint.processed', 2)
        ->where('selected.checkpoint.total', 3)->where('selected.checkpoint.unit', 'games')
        ->where('selected.last_activity_at', now()->toIso8601String())->where('selected.elapsed_seconds', 0));
    expect($this->service->identity($this->model))->toBe($identity);
    Bus::assertDispatchedTimes(RunNhlSeasonGameEvaluationJob::class, 1);
});

it('reports Train collection counts without counting them as Test predictions', function (): void {
    $id = ($this->savedRun)(['status' => 'running', 'completed_games' => 0]);
    $reader = new NhlCustomGamePredictionInputs();
    $reader->restoreCheckpointState(['forecastWork' => ['phase' => 'collect', 'cursor' => 1,
        'training' => [(object) ['nhl_game_id' => 1], (object) ['nhl_game_id' => 2]]]]);
    app(NhlCustomGameForecast::class)->writeSeasonCheckpoint($id, ['phase' => 'season', 'fingerprint' => 'fixture', 'reader' => $reader]);
    $this->actingAs($this->admin)->get($this->base.'?run='.$id)->assertInertia(fn (Assert $page) => $page
        ->where('selected.checkpoint.stage', 'collect')->where('selected.checkpoint.processed', 1)
        ->where('selected.checkpoint.total', 2)->where('summary.predicted', 0));
});

it('publishes defensive fitting bucket counts instead of labeling them as games', function (): void {
    $id = ($this->savedRun)(['status' => 'running']);
    $defense = new \App\Services\NhlCustomGameDefense();
    $defense->restoreCheckpointState(['fitCursor' => 1, 'fitKeys' => [['key' => 'A'], ['key' => 'B']]]);
    $reader = new NhlCustomGamePredictionInputs();
    $reader->restoreCheckpointState(['forecastWork' => ['phase' => 'fit', 'cursor' => 0, 'defense' => $defense]]);
    app(NhlCustomGameForecast::class)->writeSeasonCheckpoint($id, ['phase' => 'season', 'fingerprint' => 'fixture', 'reader' => $reader]);
    $this->actingAs($this->admin)->get($this->base.'?run='.$id)->assertInertia(fn (Assert $page) => $page
        ->where('selected.checkpoint.stage', 'fit')->where('selected.checkpoint.processed', 1)
        ->where('selected.checkpoint.total', 2)->where('selected.checkpoint.unit', 'buckets'));
});

it('separates Test prediction progress from already consumed Train games', function (string $phase, int $cursor, int $processed): void {
    $id = ($this->savedRun)(['status' => 'running']);
    $reader = new NhlCustomGamePredictionInputs();
    $reader->restoreCheckpointState(['forecastWork' => ['phase' => $phase, 'cursor' => $cursor,
        'replay_games' => [(object) ['nhl_game_id' => 1], (object) ['nhl_game_id' => 2],
            (object) ['nhl_game_id' => $this->gameId]], 'test_ids' => [$this->gameId => true]]]);
    app(NhlCustomGameForecast::class)->writeSeasonCheckpoint($id, ['phase' => 'season', 'fingerprint' => 'fixture', 'reader' => $reader]);
    $this->actingAs($this->admin)->get($this->base.'?run='.$id)->assertInertia(fn (Assert $page) => $page
        ->where('selected.checkpoint.stage', $phase === 'complete' ? 'complete' : 'predictions')
        ->where('selected.checkpoint.processed', $processed)->where('selected.checkpoint.total', 1));
})->with([['replay', 2, 0], ['complete', 3, 1]]);

it('updates elapsed time and checkpoint activity without creating predictions on a read', function (): void {
    $id = ($this->savedRun)(['status' => 'running']);
    $this->travel(90)->seconds();
    $reader = new NhlCustomGamePredictionInputs();
    app(NhlCustomGameForecast::class)->writeSeasonCheckpoint($id, ['phase' => 'season', 'fingerprint' => 'fixture', 'reader' => $reader]);
    $this->actingAs($this->admin)->get($this->base.'?run='.$id)->assertInertia(fn (Assert $page) => $page
        ->where('selected.last_activity_at', now()->toIso8601String())->where('selected.elapsed_seconds', 3690));
    $this->assertDatabaseCount('nhl_custom_game_predictions', 0);
    Bus::assertNothingDispatched();
});

it('retains terminal elapsed duration and handles older runs without checkpoint progress', function (): void {
    $id = ($this->savedRun)(['created_at' => now()->subMinutes(10), 'updated_at' => now()->subMinutes(5)]);
    $this->actingAs($this->admin)->get($this->base.'?run='.$id)->assertInertia(fn (Assert $page) => $page
        ->where('selected.checkpoint', null)->where('selected.elapsed_seconds', 300)
        ->where('selected.last_activity_at', now()->subMinutes(5)->toIso8601String()));
    $this->travel(2)->minutes();
    $this->get($this->base.'?run='.$id)->assertInertia(fn (Assert $page) => $page->where('selected.elapsed_seconds', 300));
});

it('splits incorrect picks into regulation and overtime losses without changing win percentage', function (array $ending, int $regulation, int $overtime): void {
    $id = ($this->savedRun)();
    ($this->savedGame)($id, ['winner_correct' => false, 'recommendation' => 'BOS']);
    NhlGame::query()->findOrFail($this->gameId)->update($ending);
    $summary = $this->service->summary($id);
    expect($summary['wins'])->toBe(0)->and($summary['losses'])->toBe(1)
        ->and($summary['regulation_losses'])->toBe($regulation)
        ->and($summary['overtime_losses'])->toBe($overtime)->and($summary['win_pct'])->toEqual(0);
})->with([
    'regulation' => [['game_outcome' => ['lastPeriodType' => 'REG'], 'period_type' => 'REG'], 1, 0],
    'overtime' => [['game_outcome' => ['lastPeriodType' => 'OT'], 'period_type' => 'REG'], 0, 1],
    'shootout' => [['game_outcome' => ['lastPeriodType' => 'SO'], 'period_type' => 'REG'], 0, 1],
    'secondary outcome' => [['game_outcome' => ['periodType' => 'OT'], 'period_type' => 'REG'], 0, 1],
    'period fallback' => [['game_outcome' => null, 'period_type' => 'SO'], 0, 1],
    'unknown ending' => [['game_outcome' => null, 'period_type' => null], 1, 0],
    'authoritative regulation' => [['game_outcome' => ['lastPeriodType' => 'REG'], 'period_type' => 'OT'], 1, 0],
    'normalized ending' => [['game_outcome' => ['lastPeriodType' => ' ot '], 'period_type' => 'REG'], 0, 1],
]);

it('counts correct overtime and shootout picks as wins rather than overtime losses', function (string $ending): void {
    $id = ($this->savedRun)();
    ($this->savedGame)($id);
    NhlGame::query()->findOrFail($this->gameId)->update(['game_outcome' => ['lastPeriodType' => $ending]]);
    $summary = $this->service->summary($id);
    expect($summary['wins'])->toBe(1)->and($summary['regulation_losses'])->toBe(0)
        ->and($summary['overtime_losses'])->toBe(0)->and($summary['win_pct'])->toEqual(100);
})->with(['OT', 'SO']);

it('keeps exclusions and no-picks outside the W L OTL record', function (string $status): void {
    $id = ($this->savedRun)();
    ($this->savedGame)($id, ['status' => $status, 'winner_correct' => null, 'recommendation' => 'OT']);
    NhlGame::query()->findOrFail($this->gameId)->update(['game_outcome' => ['lastPeriodType' => 'OT']]);
    $summary = $this->service->summary($id);
    expect($summary['wins'])->toBe(0)->and($summary['losses'])->toBe(0)
        ->and($summary['regulation_losses'])->toBe(0)->and($summary['overtime_losses'])->toBe(0)
        ->and($summary['win_pct'])->toBeNull();
})->with(['complete', 'excluded']);

it('shows the split record through the admitted runs page while preserving its saved picks and denominator', function (): void {
    $id = $this->actingAs($this->admin)->postJson($this->base.'/runs')->assertAccepted()->json('run_id');
    $winningGame = NhlGame::query()->findOrFail($this->gameId);
    $winningGame->update(['game_outcome' => ['lastPeriodType' => 'REG']]);
    ($this->savedGame)($id);
    foreach ([2025020002 => 'REG', 2025020003 => 'OT', 2025020004 => 'SO'] as $gameId => $ending) {
        $game = $winningGame->replicate();
        $game->nhl_game_id = $gameId;
        $game->game_outcome = ['lastPeriodType' => $ending];
        $game->save();
        ($this->savedGame)($id, ['nhl_game_id' => $gameId, 'winner_correct' => false, 'recommendation' => 'BOS']);
    }
    $this->assertDatabaseCount('nhl_custom_game_predictions', 4);
    $before = DB::table('nhl_custom_game_predictions')->where('run_id', $id)->orderBy('id')->get()->toJson();
    $this->get($this->base.'?run='.$id)->assertInertia(fn (Assert $page) => $page
        ->where('summary.wins', 1)->where('summary.losses', 3)
        ->where('summary.regulation_losses', 1)->where('summary.overtime_losses', 2)->where('summary.win_pct', 25));
    expect(DB::table('nhl_custom_game_predictions')->where('run_id', $id)->orderBy('id')->get()->toJson())->toBe($before);
});

it('shows each history runs own W L OTL split without changing their win percentages', function (): void {
    $id = $this->actingAs($this->admin)->postJson($this->base.'/runs')->assertAccepted()->json('run_id');
    DB::table('nhl_custom_game_prediction_runs')->where('id', $id)->update(['status' => 'complete']);
    $second = ($this->savedRun)();
    NhlGame::query()->findOrFail($this->gameId)->update(['game_outcome' => ['lastPeriodType' => 'OT']]);
    ($this->savedGame)($id, ['winner_correct' => false, 'recommendation' => 'BOS']);
    ($this->savedGame)($second);
    $this->assertDatabaseCount('nhl_custom_game_predictions', 2);
    $this->get($this->base.'?run='.$id)->assertInertia(fn (Assert $page) => $page
        ->where('runs.0.id', $second)->where('runs.0.wins', 1)->where('runs.0.regulation_losses', 0)
        ->where('runs.0.overtime_losses', 0)->where('runs.0.win_pct', 100)
        ->where('runs.1.id', $id)->where('runs.1.wins', 0)->where('runs.1.losses', 1)
        ->where('runs.1.regulation_losses', 0)->where('runs.1.overtime_losses', 1)->where('runs.1.win_pct', 0)
        ->where('summary.overtime_losses', 1)->where('summary.win_pct', 0));
});

it('keeps unknown and regulation endings in history losses using the shared record rule', function (array $ending): void {
    $id = ($this->savedRun)();
    NhlGame::query()->findOrFail($this->gameId)->update($ending);
    ($this->savedGame)($id, ['winner_correct' => false]);
    $this->actingAs($this->admin)->get($this->base.'?run='.$id)->assertInertia(fn (Assert $page) => $page
        ->where('runs.0.regulation_losses', 1)->where('runs.0.overtime_losses', 0)
        ->where('runs.0.losses', 1)->where('runs.0.win_pct', 0)->where('summary.regulation_losses', 1));
})->with([[['game_outcome' => ['lastPeriodType' => 'REG'], 'period_type' => 'OT']],
    [['game_outcome' => null, 'period_type' => null]]]);

it('keeps empty history records at zero while retaining an unavailable win percentage', function (): void {
    $id = ($this->savedRun)();
    $this->actingAs($this->admin)->get($this->base.'?run='.$id)->assertInertia(fn (Assert $page) => $page
        ->where('runs.0.wins', 0)->where('runs.0.regulation_losses', 0)
        ->where('runs.0.overtime_losses', 0)->where('runs.0.win_pct', null));
});
