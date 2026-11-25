<?php

declare(strict_types=1);

use App\Jobs\BuildNhlPregameContextDayBatchJob;
use App\Jobs\CalculateNhlPregameLeagueBatchJob;
use App\Models\NhlModelRun;
use App\Models\NhlPregameContextRun;
use App\Models\NhlPregameContextRunDay;
use App\Services\NhlCustomGameForecast;
use App\Services\NhlPregameContextBuilder;
use App\Services\NhlPregameContextOrchestrator;
use App\Services\NhlPregameLeagueResults;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-25 12:00:00 UTC'));
    config(['cache.default' => 'array', 'nhl-pregame.league_reference_hours' => 24]);
    Cache::flush();
    Bus::fake();
    Http::preventStrayRequests();
    $this->model = NhlModelRun::create(['run_key' => 'participant-repair', 'name' => 'sat_v2', 'model_family' => 'sat',
        'workflow_stage' => 'training', 'model_version' => 'fixture', 'status' => 'complete',
        'train_season_ids' => ['20252026'], 'target_season_id' => '20262027']);
    DB::table('nhl_games')->insert(['nhl_game_id' => 2026020390, 'season_id' => '20262027', 'game_type' => 2,
        'game_state' => 'FUT', 'game_date' => '2026-11-25', 'start_time_utc' => '2026-11-26 00:00:00',
        'game_dow' => 'Wednesday', 'game_month' => 'November', 'away_team_id' => 1, 'home_team_id' => 2,
        'away_team_abbrev' => 'BUF', 'home_team_abbrev' => 'NYI']);
    foreach ([101 => 1, 102 => 1, 201 => 2] as $id => $team) {
        DB::table('players')->insert(['nhl_id' => $id, 'full_name' => 'Fixture '.$id, 'position' => 'C', 'pos_type' => 'F',
            'team_abbrev' => $team === 1 ? 'BUF' : 'NYI']);
    }
    $players = [101 => ['id' => 101, 'team' => 1, 'name' => 'Fixture 101', 'role' => 'F'],
        201 => ['id' => 201, 'team' => 2, 'name' => 'Fixture 201', 'role' => 'F']];
    $this->lineup = ['players' => $players, 'starters' => [], 'lineup_sources' => [1 => 'projected_roster', 2 => 'projected_roster']];
    $this->evidence = ['fingerprint' => 'participant-specific', 'history_fingerprint' => 'unchanged-history',
        'history_identity' => ['model' => [$this->model->id, 'fixture', ['20252026']],
            'version' => NhlPregameContextBuilder::VERSION, 'results' => ['games' => 'stable-results'], 'game' => ['original']],
        'cutoff_at' => '2026-11-25 11:00:00', 'team_ids' => [1, 2], 'players' => $players, 'starters' => [],
        'participants' => $this->lineup];
    $this->source = NhlPregameContextRun::create(['action' => 'backfill', 'status' => 'running',
        'model_run_id' => $this->model->id, 'season_ids' => ['20262027'], 'options' => [
            'pipeline_version' => NhlPregameContextOrchestrator::CHECKPOINT_PIPELINE,
            'train_season_ids' => ['20252026'], 'prediction_evidence' => [2026020390 => $this->evidence]]]);
    $this->sourceDay = NhlPregameContextRunDay::create(['run_id' => $this->source->id, 'game_date' => '2026-11-25',
        'league_state' => 'pending', 'context_state' => 'complete']);
    DB::table('nhl_pregame_context_run_games')->insert(['run_id' => $this->source->id, 'nhl_game_id' => 2026020390,
        'game_date' => '2026-11-25', 'sequence' => 1, 'state' => 'complete', 'comparisons_completed_at' => now()]);
    $this->teams = [];
    foreach ([1, 2] as $team) {
        $this->teams[$team] = DB::table('nhl_team_game_pregame_contexts')->insertGetId([
            'run_id' => $this->source->id, 'model_run_id' => $this->model->id, 'nhl_game_id' => 2026020390,
            'nhl_team_id' => $team, 'opponent_team_id' => $team === 1 ? 2 : 1, 'game_date' => '2026-11-25',
            'source_cutoff_at' => '2026-11-25 11:00:00', 'venue' => $team === 1 ? 'away' : 'home',
            'context_version' => NhlPregameContextBuilder::VERSION, 'schedule_metrics' => json_encode([
                'prediction_evidence' => ['history_fingerprint' => 'unchanged-history']]),
            'opponent_metrics' => '{}', 'metrics' => '{}', 'ranks' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    $this->context = function (int $runId, int $id, int $team = 1): int {
        return DB::table('nhl_player_game_pregame_contexts')->insertGetId([
            'run_id' => $runId, 'model_run_id' => $this->model->id, 'team_context_id' => $this->teams[$team],
            'nhl_game_id' => 2026020390, 'nhl_player_id' => $id, 'nhl_team_id' => $team,
            'opponent_team_id' => $team === 1 ? 2 : 1, 'game_date' => '2026-11-25', 'source_cutoff_at' => '2026-11-25 11:00:00',
            'venue' => $team === 1 ? 'away' : 'home', 'context_version' => NhlPregameContextBuilder::VERSION,
            'metrics' => '{}', 'ranks' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);
    };
    ($this->context)($this->source->id, 101);
    ($this->context)($this->source->id, 201, 2);
    $builder = Mockery::mock(NhlPregameContextBuilder::class);
    $builder->shouldReceive('contextGame')->andReturnUsing(function ($run, $game) {
        $copy = clone $game;
        $copy->start_time_utc = data_get($run->options, 'prediction_evidence.'.$game->nhl_game_id.'.cutoff_at', $game->start_time_utc);

        return $copy;
    });
    $builder->shouldReceive('leagueEntities')->andReturnUsing(fn ($run, $game, $type) => collect(array_map(
        fn ($id) => (object) ['entity_id' => $id], $type === 'team' ? [1, 2] : [101, 102, 201]
    )));
    $builder->shouldReceive('leagueResultRows')->andReturnUsing(function ($run, $game, $type): Generator {
        foreach ($type === 'team' ? [1 => 20, 2 => 30] : [101 => 10, 102 => 20, 201 => 20] as $id => $value) {
            yield ['entity_id' => $id, 'metric_key' => 'sat_per_60', 'window_key' => 'last_20', 'strength' => 'EV', 'value' => $value];
        }
    });
    $this->service = new NhlPregameLeagueResults($builder);
    for ($step = 0; $step < 8; $step++) {
        if ($this->service->buildNextAverages($this->source, $this->sourceDay->fresh())) {
            break;
        }
    }
    $this->sourceDay->update(['league_state' => 'complete']);
    $this->source->update(['status' => 'completed']);
    $this->service->releaseDayPeers($this->sourceDay);
    $this->playerAverage = DB::table('nhl_pregame_context_league_averages')->where('run_id', $this->source->id)
        ->where('entity_type', 'player')->first();
    $this->repair = function (array $changes = []): array {
        $evidence = $this->evidence;
        $evidence['players'][102] = ['id' => 102, 'team' => 1, 'name' => 'Fixture 102', 'role' => 'F'];
        $evidence['participants']['players'] = $evidence['players'];
        $evidence = array_replace_recursive($evidence, $changes);
        $run = NhlPregameContextRun::create(['action' => 'backfill', 'status' => 'running', 'model_run_id' => $this->model->id,
            'season_ids' => ['20262027'], 'options' => ['trigger' => 'prediction',
                'pipeline_version' => NhlPregameContextOrchestrator::CHECKPOINT_PIPELINE,
                'train_season_ids' => ['20252026'], 'prediction_evidence' => [2026020390 => $evidence],
                'prediction_participants' => [2026020390 => $evidence['participants']]]]);
        $day = NhlPregameContextRunDay::create(['run_id' => $run->id, 'game_date' => '2026-11-25', 'game_count' => 1]);
        DB::table('nhl_pregame_context_run_games')->insert(['run_id' => $run->id, 'nhl_game_id' => 2026020390,
            'game_date' => '2026-11-25', 'sequence' => 1, 'state' => 'pending']);

        return [$run, $day, $evidence];
    };
    $this->metric = function (int $contextId, ?float $value, string $measure = 'value'): void {
        DB::table('nhl_player_game_pregame_context_metrics')->insert(['player_context_id' => $contextId,
            'metric_key' => 'sat_per_60', 'window_key' => 'last_20', 'strength' => 'EV', 'measure_key' => $measure,
            'value' => $value, 'created_at' => now(), 'updated_at' => now()]);
    };
    $this->compare = function (?float $value = 20, int $id = 102, string $measure = 'value'): object {
        [$run, $day] = ($this->repair)();
        $this->service->reuseDayReferences($run, $day);
        $context = ($this->context)($run->id, $id);
        ($this->metric)($context, $value, $measure);
        $this->service->attachGameComparisons($run, $day, 2026020390);

        return DB::table('nhl_player_game_pregame_context_metrics')->where('player_context_id', $context)->first();
    };
});

afterEach(function (): void {
    Http::assertNothingSent();
    $this->travelBack();
});

it('reports exactly the missing skater while keeping both team contexts ready', function (): void {
    [, , $evidence] = ($this->repair)();
    expect(app(NhlCustomGameForecast::class)->contextCoverage($this->model->id, 2026020390, $evidence))
        ->toBe(['ready' => false, 'teams_ready' => true, 'missing_players' => [['nhl_player_id' => 102, 'nhl_team_id' => 1]]]);
});

it('deduplicates a missing starter also listed as a selected participant', function (): void {
    [, , $evidence] = ($this->repair)(['starters' => [1 => 102]]);
    expect(app(NhlCustomGameForecast::class)->contextCoverage($this->model->id, 2026020390, $evidence)['missing_players'])
        ->toHaveCount(1);
});

it('retains the ready result when every selected participant is already committed', function (): void {
    expect(app(NhlCustomGameForecast::class)->contextCoverage($this->model->id, 2026020390, $this->evidence))
        ->toBe(['ready' => true, 'teams_ready' => true, 'missing_players' => []]);
});

it('requires a normal team refresh when historical evidence changed', function (): void {
    $evidence = $this->evidence;
    $evidence['history_fingerprint'] = 'corrected-results';
    expect(app(NhlCustomGameForecast::class)->contextCoverage($this->model->id, 2026020390, $evidence)['teams_ready'])->toBeFalse();
});

it('retains reusable ranking data after transient SQL peers have been discarded', function (): void {
    expect(DB::table('nhl_pregame_context_league_peers')->count())->toBe(0);
    [$run, $day] = ($this->repair)();
    expect($this->service->reuseDayReferences($run, $day))->toBeTrue();
    expect($day->fresh()->league_state)->toBe('complete');
});

it('does not rewrite or duplicate the saved league averages during a participant repair', function (): void {
    $before = DB::table('nhl_pregame_context_league_averages')->orderBy('id')->get()->toJson();
    [$run, $day] = ($this->repair)();
    $this->service->reuseDayReferences($run, $day);
    expect(DB::table('nhl_pregame_context_league_averages')->orderBy('id')->get()->toJson())->toBe($before);
});

it('reuses references when only participant or game identity metadata differs', function (): void {
    [$run, $day] = ($this->repair)(['fingerprint' => 'another-lineup', 'history_identity' => ['game' => ['different-game-orientation']]]);
    expect($this->service->reuseDayReferences($run, $day))->toBeTrue();
});

it('refuses reuse after a change to source result values', function (): void {
    [$run, $day] = ($this->repair)(['history_identity' => ['results' => ['games' => 'corrected-results']]]);
    expect($this->service->reuseDayReferences($run, $day))->toBeFalse();
    expect($day->fresh()->league_state)->toBe('pending');
});

it('refuses reuse across a different historical cutoff', function (): void {
    [$run, $day] = ($this->repair)(['cutoff_at' => '2026-11-25 11:01:00']);
    expect($this->service->reuseDayReferences($run, $day))->toBeFalse();
});

it('refuses reuse across changed Train seasons', function (): void {
    [$run, $day] = ($this->repair)();
    $options = $run->options;
    $options['train_season_ids'] = ['20242025'];
    $run->update(['options' => $options]);
    expect($this->service->reuseDayReferences($run, $day))->toBeFalse();
});

it('does not reuse another models league population', function (): void {
    [$run, $day] = ($this->repair)();
    $other = NhlModelRun::create(['run_key' => 'other-repair-model', 'name' => 'Other', 'model_family' => 'sat',
        'workflow_stage' => 'training', 'model_version' => 'fixture', 'status' => 'complete', 'train_season_ids' => ['20252026']]);
    $run->update(['model_run_id' => $other->id]);
    expect($this->service->reuseDayReferences($run, $day))->toBeFalse();
});

it('does not reuse an unfinished source run', function (): void {
    $this->source->update(['status' => 'running']);
    [$run, $day] = ($this->repair)();
    expect($this->service->reuseDayReferences($run, $day))->toBeFalse();
});

it('does not alter explicit nonprediction historical rebuilds', function (): void {
    [$run, $day] = ($this->repair)();
    $options = $run->options;
    unset($options['trigger']);
    $run->update(['options' => $options]);
    expect($this->service->reuseDayReferences($run, $day))->toBeFalse();
});

it('expires reusable references instead of treating an old date as current forever', function (): void {
    $this->travel(25)->hours();
    [$run, $day] = ($this->repair)();
    expect($this->service->reuseDayReferences($run, $day))->toBeFalse();
});

it('declines reuse after rank reference eviction', function (): void {
    Cache::forget('nhl:pregame:comparison-peers:v1:'.$this->playerAverage->id);
    [$run, $day] = ($this->repair)();
    expect($this->service->reuseDayReferences($run, $day))->toBeFalse();
    expect($day->fresh()->league_state)->toBe('pending');
});

it('attaches the existing mean population and competition rank to the missing player', function (): void {
    $metric = ($this->compare)();
    expect((int) $metric->league_average_id)->toBe((int) $this->playerAverage->id);
    expect((float) $metric->league_average)->toBe(16.666667);
    expect((int) $metric->league_rank)->toBe(1);
    expect((int) $metric->rank_population)->toBe(3);
    expect((float) $metric->signed_difference)->toBe(3.333333);
});

it('preserves a genuine zero while comparing it with the existing population', function (): void {
    $metric = ($this->compare)(0);
    expect((float) $metric->value)->toBe(0.0);
    expect((int) $metric->league_rank)->toBe(3);
    expect((float) $metric->percentage_difference)->toBe(-100.0);
});

it('excludes the players own peer when a contextual value differs from the league peer', function (): void {
    $metric = ($this->compare)(15);
    expect((int) $metric->league_rank)->toBe(2);
});

it('leaves unavailable metric values and their comparisons unknown', function (): void {
    $metric = ($this->compare)(null);
    expect($metric->value)->toBeNull();
    expect($metric->league_average_id)->toBeNull();
    expect($metric->league_rank)->toBeNull();
});

it('leaves comparisons unknown for an entity outside the original league population', function (): void {
    $metric = ($this->compare)(20, 999);
    expect($metric->league_average_id)->toBeNull();
    expect($metric->league_rank)->toBeNull();
});

it('does not attach league comparisons to nonvalue measure rows', function (): void {
    $metric = ($this->compare)(20, 102, 'games');
    expect($metric->league_average_id)->toBeNull();
    expect($metric->league_rank)->toBeNull();
});

it('leaves all existing team and player snapshots untouched when attaching an addition', function (): void {
    $teams = DB::table('nhl_team_game_pregame_contexts')->orderBy('id')->get()->toJson();
    $players = DB::table('nhl_player_game_pregame_contexts')->orderBy('id')->get()->toJson();
    ($this->compare)();
    expect(DB::table('nhl_team_game_pregame_contexts')->orderBy('id')->get()->toJson())->toBe($teams);
    expect(DB::table('nhl_player_game_pregame_contexts')->where('run_id', $this->source->id)->orderBy('id')->get()->toJson())->toBe($players);
});

it('rejects corrupted cached peer values without publishing guessed comparisons', function (): void {
    [$run, $day] = ($this->repair)();
    $this->service->reuseDayReferences($run, $day);
    $context = ($this->context)($run->id, 102);
    ($this->metric)($context, 20);
    $key = 'nhl:pregame:comparison-peers:v1:'.$this->playerAverage->id;
    $entry = Cache::get($key);
    $entry['peers'][102][0] = '999';
    Cache::put($key, $entry, 3600);
    expect(fn () => $this->service->attachGameComparisons($run, $day, 2026020390))->toThrow(RuntimeException::class);
    $this->assertDatabaseHas('nhl_player_game_pregame_context_metrics', ['player_context_id' => $context, 'league_rank' => null]);
});

it('does not queue another dependency when all requested context is ready', function (): void {
    expect(app(NhlPregameContextOrchestrator::class)->queuePredictionContexts(
        $this->model, [2026020390 => $this->evidence], [2026020390 => $this->lineup]
    ))->toBeNull();
    $this->assertDatabaseCount('nhl_pregame_context_runs', 1);
});

it('queues only the missing player repair and dispatches context work without a league job', function (): void {
    $evidence = $this->evidence;
    $evidence['players'][102] = ['id' => 102, 'team' => 1, 'name' => 'Fixture 102', 'role' => 'F'];
    $evidence['participants']['players'] = $evidence['players'];
    $orchestrator = app(NhlPregameContextOrchestrator::class);
    $run = $orchestrator->queuePredictionContexts($this->model, [2026020390 => $evidence], [2026020390 => $evidence['participants']]);
    expect(data_get($run->options, 'prediction_context_gaps.2026020390.missing_players'))
        ->toBe([['nhl_player_id' => 102, 'nhl_team_id' => 1]]);
    $orchestrator->prepare($run);
    $orchestrator->load($run->id);
    expect($run->days()->first()->league_state)->toBe('complete');
    Bus::assertDispatched(BuildNhlPregameContextDayBatchJob::class);
    Bus::assertNotDispatched(CalculateNhlPregameLeagueBatchJob::class);
});

it('builds the missing participant against retained parents and restores prediction readiness after comparisons', function (): void {
    [$run, $day, $evidence] = ($this->repair)();
    $this->service->reuseDayReferences($run, $day);
    $teams = DB::table('nhl_team_game_pregame_contexts')->orderBy('id')->get()->toJson();
    $players = DB::table('nhl_player_game_pregame_contexts')->orderBy('id')->get()->toJson();
    $forecast = app(NhlCustomGameForecast::class);
    expect($forecast->contextReady($this->model->id, 2026020390, $evidence))->toBeFalse();

    $result = (new NhlPregameContextBuilder())->buildDay($run, [2026020390]);
    expect($result['blocked_games'])->toBe(0);
    $this->assertDatabaseHas('nhl_player_game_pregame_contexts', [
        'run_id' => $run->id, 'nhl_player_id' => 102, 'team_context_id' => $this->teams[1],
        'source_cutoff_at' => '2026-11-25 11:00:00',
    ]);
    expect($forecast->contextReady($this->model->id, 2026020390, $evidence))->toBeFalse();
    $this->service->attachGameComparisons($run, $day, 2026020390);
    DB::table('nhl_pregame_context_run_games')->where('run_id', $run->id)->update([
        'state' => 'complete', 'comparisons_completed_at' => now(),
    ]);

    expect($forecast->contextReady($this->model->id, 2026020390, $evidence))->toBeTrue();
    expect(DB::table('nhl_team_game_pregame_contexts')->orderBy('id')->get()->toJson())->toBe($teams);
    expect(DB::table('nhl_player_game_pregame_contexts')->where('run_id', $this->source->id)->orderBy('id')->get()->toJson())->toBe($players);
    $this->assertDatabaseCount('nhl_pregame_context_league_averages', 2);
});

it('prepares only the missing population when one retained reference was evicted', function (): void {
    Cache::forget('nhl:pregame:comparison-peers:v1:'.$this->playerAverage->id);
    [$run, $day] = ($this->repair)();
    expect($this->service->reuseDayReferences($run, $day))->toBeFalse();
    expect($day->fresh()->league_state)->toBe('pending');
    expect(data_get($run->options, 'prediction_league_references.2026-11-25 11:00:00.team.run_id'))
        ->toBe($this->source->id);
    expect($this->service->buildNextAverages($run, $day->fresh()))->toBeFalse();
    expect($day->fresh()->league_cursor)->toBe(1);
    expect(DB::table('nhl_pregame_context_league_averages')->where('run_id', $run->id)->exists())->toBeFalse();
});

it('requires ordinary reference preparation for predeployment runs without retained peer data', function (): void {
    Cache::flush();
    [$run, $day] = ($this->repair)();
    expect($this->service->reuseDayReferences($run, $day))->toBeFalse();
    expect($day->fresh()->league_state)->toBe('pending');
    expect(data_get($run->options, 'prediction_league_references', []))->toBe([]);
});

it('does not reuse a population with different appearance eligibility seasons', function (): void {
    [$run, $day] = ($this->repair)();
    $run->update(['season_ids' => ['20252026', '20262027']]);
    expect($this->service->reuseDayReferences($run, $day))->toBeFalse();
});

it('does not reuse matchup oriented populations after an upcoming schedule change', function (): void {
    DB::table('nhl_games')->where('nhl_game_id', 2026020390)->update(['start_time_utc' => '2026-11-26 01:00:00']);
    [$run, $day] = ($this->repair)();
    expect($this->service->reuseDayReferences($run, $day))->toBeFalse();
});
