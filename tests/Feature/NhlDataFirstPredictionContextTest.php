<?php

declare(strict_types=1);

use App\Jobs\UpdateNhlPredictionPregameContextJob;
use App\Models\ApiClient;
use App\Models\NhlGame;
use App\Models\NhlModelRun;
use App\Models\NhlPregameContextRun;
use App\Services\NhlCustomGameForecast;
use App\Services\NhlCustomGamePredictionInputs;
use App\Services\NhlGamePredictionHistory;
use App\Services\NhlGamePredictionPayload;
use App\Services\NhlPregameContextOrchestrator;
use App\Services\NhlPublicGamePage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-25 15:00:00 UTC'));
    config(['cache.default' => 'array']);
    Cache::flush();
    Bus::fake();
    Http::preventStrayRequests();
    $this->mock(\App\Services\PlatformState::class)->shouldReceive('seeded')->andReturn(true);
    $this->model = NhlModelRun::create(['run_key' => 'data-first', 'name' => 'sat_v2', 'model_family' => 'sat',
        'workflow_stage' => 'training', 'status' => 'complete', 'train_season_ids' => ['20252026']]);
    $this->game = NhlGame::create(['nhl_game_id' => 2026020399, 'season_id' => '20262027', 'game_type' => 2,
        'game_date' => '2026-11-25', 'game_dow' => 'Wednesday', 'game_month' => 'November', 'game_state' => 'FUT',
        'start_time_utc' => '2026-11-26 00:00:00', 'away_team_id' => 28, 'home_team_id' => 24,
        'away_team_abbrev' => 'SJS', 'home_team_abbrev' => 'ANA']);
    $this->payload = ['prediction_available' => true, 'game' => ['nhl_game_id' => 2026020399],
        'inputs' => ['sat_model_run_id' => $this->model->id,
            'context' => ['source_date' => '2026-11-24', 'refresh_needed' => true]],
        'prediction' => ['winner' => ['side' => 'away', 'team_abbrev' => 'SJS'],
            'predicted_score' => ['away' => 3.0, 'home' => 2.0], 'away_win_probability' => 0.6,
            'home_win_probability' => 0.4, 'confidence_score' => 55], 'market_probabilities' => [], 'teams' => []];
    $page = $this->mock(NhlPublicGamePage::class);
    $page->shouldReceive('capturePlayerAnalysis')->andReturn([]);
    $page->shouldReceive('playerPregamePayload')->andReturn([]);
    $page->shouldReceive('refreshAfterCommit')->andReturnNull();
    $this->databaseManager = DB::getFacadeRoot();
    $this->callbacks = [];
    DB::partialMock()->shouldReceive('afterCommit')->andReturnUsing(function (callable $callback): void {
        $this->callbacks[] = $callback;
    });
    $this->commitCallbacks = function (): void {
        $callbacks = $this->callbacks;
        $this->callbacks = [];
        foreach ($callbacks as $callback) {
            $callback();
        }
    };
    $this->save = fn (?array $payload = null): array => app(NhlGamePredictionHistory::class)->record(
        2026020399, ['nhl_game_id' => 2026020399], 'data-first', [], $payload ?? $this->payload
    );
    $this->saved = ($this->save)();
    $this->job = new UpdateNhlPredictionPregameContextJob(2026020399, $this->model->id, $this->saved['meta']['prediction_revision_id']);
    $this->record = ['players' => [1 => ['id' => 1, 'team' => 28, 'name' => 'Fixture', 'role' => 'F']], 'starters' => []];
    $this->evidence = ['fingerprint' => 'current-source', 'players' => $this->record['players']];
    $this->reader = $this->mock(NhlCustomGamePredictionInputs::class);
    $this->reader->shouldReceive('anticipatedParticipants')->byDefault()->andReturn($this->record);
    $this->forecast = Mockery::mock(NhlCustomGameForecast::class);
    $this->forecast->shouldReceive('contextEvidence')->byDefault()->andReturn($this->evidence);
    $this->forecast->shouldReceive('contextReady')->byDefault()->andReturnFalse();
    $this->contexts = Mockery::mock(NhlPregameContextOrchestrator::class);
    $this->contexts->shouldReceive('queuePredictionContexts')->byDefault()->andReturn(new NhlPregameContextRun(['id' => 500]));
});

afterEach(function (): void {
    Http::assertNothingSent();
    $this->travelBack();
});

it('saves the successful prediction before scheduling its missing context build', function (): void {
    $this->assertDatabaseHas('nhl_game_prediction_revisions', ['id' => $this->job->revisionId, 'prediction_available' => true]);
    Bus::assertNotDispatched(UpdateNhlPredictionPregameContextJob::class);
    ($this->commitCallbacks)();
    Bus::assertDispatched(UpdateNhlPredictionPregameContextJob::class, fn ($job): bool => $job->revisionId === $this->job->revisionId);
});

it('uses projections for the post-publication context job', function (): void {
    expect($this->job->queue)->toBe('projections')->and($this->job->afterCommit)->toBeTrue();
});

it('deduplicates repair requests across revisions for the same game and model', function (): void {
    $second = ($this->save)();
    ($this->commitCallbacks)();
    Bus::assertDispatchedTimes(UpdateNhlPredictionPregameContextJob::class, 1);
    expect($second['meta']['prediction_revision_id'])->not->toBe($this->job->revisionId);
});

it('keeps different games and models in distinct repair identities', function (): void {
    expect((new UpdateNhlPredictionPregameContextJob(2, $this->model->id, 1))->uniqueId())->not->toBe($this->job->uniqueId());
    expect((new UpdateNhlPredictionPregameContextJob(2026020399, $this->model->id + 1, 1))->uniqueId())->not->toBe($this->job->uniqueId());
});

it('does not treat an old personal context date as an uncovered completed game', function (): void {
    expect($this->saved['prediction']['days_away'])->toBe(0);
    $this->assertDatabaseHas('nhl_game_prediction_revisions', ['id' => $this->job->revisionId, 'days_away' => 0]);
});

it('keeps fresh same-day context at zero days away', function (): void {
    expect(app(NhlGamePredictionPayload::class)->predictionDaysAway('2026-11-25', '2026-11-25', 2026020399, $this->model->id))->toBe(0);
});

it('keeps a future prediction at zero when no completed games are uncovered', function (): void {
    expect(app(NhlGamePredictionPayload::class)->predictionDaysAway('2026-11-28', '2026-11-23', 2026020399, $this->model->id))->toBe(0);
});

it('does not add a negative context-age bonus', function (): void {
    expect(app(NhlGamePredictionPayload::class)->predictionDaysAway('2026-11-26', '2026-11-27', 2026020399, $this->model->id))->toBe(0);
});

it('does not invent negative lag from a historical calendar date', function (): void {
    expect(app(NhlGamePredictionPayload::class)->predictionDaysAway('2026-11-24', '2026-11-20', 2026020399, $this->model->id))->toBe(0);
});

it('leaves lag unknown when no game and model identities were supplied', function (): void {
    expect(app(NhlGamePredictionPayload::class)->predictionDaysAway('2026-11-27'))->toBeNull();
});

it('uses adjusted days away in public confidence without changing win probability', function (): void {
    expect($this->saved['prediction']['public_confidence_score'])->toBe(61.0)
        ->and($this->saved['prediction']['away_win_probability'])->toBe(0.6);
});

it('does not enqueue a context build when exact context was used', function (): void {
    $this->callbacks = [];
    $payload = $this->payload;
    $payload['inputs']['context']['refresh_needed'] = false;
    ($this->save)($payload);
    ($this->commitCallbacks)();
    Bus::assertNotDispatched(UpdateNhlPredictionPregameContextJob::class);
});

it('does not enqueue a context build for an unavailable prediction', function (): void {
    $this->callbacks = [];
    ($this->save)([...$this->payload, 'prediction_available' => false]);
    ($this->commitCallbacks)();
    Bus::assertNotDispatched(UpdateNhlPredictionPregameContextJob::class);
});

it('delays a future target context build until that games Toronto day', function (): void {
    $this->callbacks = [];
    $this->game->update(['game_date' => '2026-11-27']);
    ($this->save)();
    ($this->commitCallbacks)();
    Bus::assertDispatched(UpdateNhlPredictionPregameContextJob::class, fn ($job): bool =>
        $job->delay->equalTo(CarbonImmutable::parse('2026-11-27 00:00:00 America/Toronto')));
});

it('checks current context data rather than the status of an old failed run', function (): void {
    NhlPregameContextRun::create(['model_run_id' => $this->model->id, 'action' => 'backfill', 'status' => 'failed', 'season_ids' => ['20262027']]);
    $this->forecast->shouldReceive('contextReady')->once()->andReturnTrue();
    $this->contexts->shouldNotReceive('queuePredictionContexts');
    $this->job->handle($this->forecast, $this->contexts);
});

it('queues only the requested game through the existing context builder', function (): void {
    $this->contexts->shouldReceive('queuePredictionContexts')->once()->withArgs(fn ($model, $evidence, $records): bool =>
        $model->id === $this->model->id && $evidence === [2026020399 => $this->evidence] && $records === [2026020399 => $this->record])
        ->andReturn(new NhlPregameContextRun(['id' => 500]));
    $this->job->handle($this->forecast, $this->contexts);
});

it('waits for an active model build without starting duplicate work', function (): void {
    NhlPregameContextRun::create(['model_run_id' => $this->model->id, 'action' => 'backfill', 'status' => 'running', 'season_ids' => ['20262027']]);
    $queue = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
    $queue->shouldReceive('release')->once()->with(60);
    $this->job->setJob($queue);
    $this->reader->shouldNotReceive('anticipatedParticipants');
    $this->job->handle($this->forecast, $this->contexts);
});

it('preserves paused model builds instead of resuming them', function (): void {
    $run = NhlPregameContextRun::create(['model_run_id' => $this->model->id, 'action' => 'backfill', 'status' => 'paused', 'season_ids' => ['20262027']]);
    $queue = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
    $queue->shouldReceive('release')->once()->with(60);
    $this->job->setJob($queue);
    $this->job->handle($this->forecast, $this->contexts);
    expect($run->fresh()->status)->toBe('paused');
});

it('retries an admission race without changing the published forecast', function (): void {
    $queue = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
    $queue->shouldReceive('release')->once()->with(30);
    $this->job->setJob($queue);
    $this->contexts->shouldReceive('queuePredictionContexts')->once()->andReturnNull();
    $this->job->handle($this->forecast, $this->contexts);
    $this->assertDatabaseHas('nhl_game_prediction_revisions', ['id' => $this->job->revisionId, 'prediction_available' => true]);
});

it('does not build context without a successful matching published revision', function (): void {
    $job = new UpdateNhlPredictionPregameContextJob(2026020399, $this->model->id, 999999);
    $this->reader->shouldNotReceive('anticipatedParticipants');
    $job->handle($this->forecast, $this->contexts);
});

it('uses actual participants for a game that finished before context repair executes', function (): void {
    $this->game->update(['game_state' => 'OFF']);
    $this->reader->shouldReceive('anticipatedParticipants')->once()->withArgs(fn ($game, $actual): bool =>
        $game->nhl_game_id === 2026020399 && $actual === true)->andReturn($this->record);
    $this->job->handle($this->forecast, $this->contexts);
});

it('preserves the committed prediction if later context repair fails', function (): void {
    $this->contexts->shouldReceive('queuePredictionContexts')->once()->andThrow(new \RuntimeException('Context unavailable'));
    expect(fn () => $this->job->handle($this->forecast, $this->contexts))->toThrow(\RuntimeException::class, 'Context unavailable');
    $this->assertDatabaseHas('nhl_game_prediction_revisions', ['id' => $this->job->revisionId, 'days_away' => 0]);
});

it('retains the originally saved age when the revision is read on a later day', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-26 15:00:00 UTC'));
    expect(app(NhlGamePredictionHistory::class)->latestSaved(2026020399)['prediction']['days_away'])->toBe(0);
});

it('serves the persisted adjusted horizon and public confidence through the saved API read', function (): void {
    ApiClient::create(['name' => 'Age reader', 'slug' => 'age-reader', 'token_hash' => ApiClient::hashToken('age-token'), 'scopes' => ['nhl-stats:read']]);
    $this->withToken('age-token')->getJson('/api/nhl-game-predictions?nhl_game_id=2026020399')->assertOk()
        ->assertJsonPath('prediction.days_away', 0)->assertJsonPath('prediction.confidence_score', fn ($score): bool => (float) $score === 61.0);
});


it('requires authentication to read a saved forecast with its adjusted context age', function (): void {
    $this->getJson('/api/nhl-game-predictions?nhl_game_id=2026020399')->assertUnauthorized();
});

it('requires stats read scope to read the adjusted prediction horizon', function (): void {
    ApiClient::create(['name' => 'Wrong scope', 'slug' => 'wrong-age-scope', 'token_hash' => ApiClient::hashToken('wrong-age-token'),
        'scopes' => ['nhl-lineups:write']]);
    $this->withToken('wrong-age-token')->getJson('/api/nhl-game-predictions?nhl_game_id=2026020399')->assertForbidden();
});


it('does not dispatch repair when the prediction publication transaction rolls back', function (): void {
    $this->callbacks = [];
    DB::swap($this->databaseManager);
    DB::beginTransaction();
    ($this->save)();
    DB::rollBack();
    Bus::assertNotDispatched(UpdateNhlPredictionPregameContextJob::class);
    $this->assertDatabaseCount('nhl_game_prediction_revisions', 1);
});

describe('completed game context lag', function (): void {
    beforeEach(function (): void {
        $this->complete = function (int $id, string $date, int $team = 28): void {
            NhlGame::create(['nhl_game_id' => $id, 'season_id' => '20262027', 'game_type' => 2,
                'game_date' => $date, 'game_dow' => 'Monday', 'game_month' => 'November', 'game_state' => 'OFF',
                'start_time_utc' => $date.' 00:00:00', 'away_team_id' => $team, 'home_team_id' => 99,
                'away_team_abbrev' => 'SJS', 'home_team_abbrev' => 'AAA']);
        };
        $this->marker = function (int $team, string $cutoff, ?int $model = null): int {
            return DB::table('nhl_team_game_pregame_contexts')->insertGetId([
                'model_run_id' => $model ?? $this->model->id, 'nhl_game_id' => 2026020399,
                'nhl_team_id' => $team, 'opponent_team_id' => $team === 28 ? 24 : 28,
                'game_date' => '2026-11-25', 'source_cutoff_at' => $cutoff, 'venue' => 'home',
                'context_version' => \App\Services\NhlPregameContextBuilder::VERSION, 'metrics' => '{}',
            ]);
        };
        $this->lag = fn () => app(NhlGamePredictionPayload::class)->predictionDaysAway(
            '2026-11-25', '2026-10-10', 2026020399, $this->model->id);
    });

    it('counts uncovered completed games rather than elapsed calendar days', function (): void {
        ($this->complete)(2026020201, '2026-11-20');
        ($this->complete)(2026020202, '2026-11-23');
        ($this->marker)(28, '2026-11-19 12:00:00');
        expect(($this->lag)())->toBe(2);
    });

    it('uses the largest team gap rather than adding both teams gaps together', function (): void {
        ($this->complete)(2026020201, '2026-11-20');
        ($this->complete)(2026020202, '2026-11-23');
        ($this->complete)(2026020203, '2026-11-23', 24);
        expect(($this->lag)())->toBe(2);
    });

    it('returns zero when context follows the most recent completed game', function (): void {
        ($this->complete)(2026020201, '2026-11-23');
        ($this->marker)(28, '2026-11-24 12:00:00');
        expect(($this->lag)())->toBe(0);
    });

    it('ignores a returning players old row when a teammate has current context', function (): void {
        ($this->complete)(2026020201, '2026-11-23');
        $parent = ($this->marker)(28, '2026-10-10 12:00:00');
        foreach ([101 => '2026-10-10 12:00:00', 102 => '2026-11-24 12:00:00'] as $id => $cutoff) {
            DB::table('nhl_player_game_pregame_contexts')->insert([
                'model_run_id' => $this->model->id, 'team_context_id' => $parent, 'nhl_game_id' => 2026020399,
                'nhl_player_id' => $id, 'nhl_team_id' => 28, 'opponent_team_id' => 24,
                'game_date' => '2026-11-25', 'source_cutoff_at' => $cutoff, 'venue' => 'away',
                'context_version' => \App\Services\NhlPregameContextBuilder::VERSION, 'metrics' => '{}',
            ]);
        }
        expect(($this->lag)())->toBe(0);
    });

    it('does not use another models fresh context as coverage', function (): void {
        ($this->complete)(2026020201, '2026-11-23');
        $other = $this->model->replicate(); $other->run_key = 'other-lag-model'; $other->save();
        ($this->marker)(28, '2026-11-24 12:00:00', $other->id);
        expect(($this->lag)())->toBe(1);
    });

    it('does not let a context rebuilt today for an older cutoff pretend to cover recent results', function (): void {
        ($this->complete)(2026020201, '2026-11-23');
        $id = ($this->marker)(28, '2026-11-20 12:00:00');
        DB::table('nhl_team_game_pregame_contexts')->where('id', $id)->update(['created_at' => now(), 'updated_at' => now()]);
        expect(($this->lag)())->toBe(1);
    });

    it('does not use context from after the prediction cutoff', function (): void {
        ($this->complete)(2026020201, '2026-11-23');
        ($this->marker)(28, '2026-11-26 12:00:00');
        expect(($this->lag)())->toBe(1);
    });

    it('keeps pregame for the completed game itself from claiming that games outcome', function (): void {
        ($this->complete)(2026020201, '2026-11-23');
        ($this->marker)(28, '2026-11-23 00:00:00');
        expect(($this->lag)())->toBe(1);
    });

    it('excludes upcoming games and results later than the frozen cutoff', function (): void {
        ($this->complete)(2026020201, '2026-11-26');
        expect(($this->lag)())->toBe(0);
    });

    it('archives corrected lag and public confidence through the existing saved prediction path', function (): void {
        ($this->complete)(2026020201, '2026-11-23');
        ($this->marker)(28, '2026-11-24 12:00:00');
        $saved = ($this->save)();
        expect($saved['prediction']['days_away'])->toBe(0)->and($saved['prediction']['public_confidence_score'])->toBe(61.0);
        $this->assertDatabaseHas('nhl_game_prediction_revisions', ['id' => $saved['meta']['prediction_revision_id'], 'days_away' => 0]);
        ApiClient::create(['name' => 'Lag read', 'slug' => 'lag-read', 'token_hash' => ApiClient::hashToken('lag-read-token'), 'scopes' => ['nhl-stats:read']]);
        $this->withToken('lag-read-token')->getJson('/api/nhl-game-predictions?nhl_game_id=2026020399')
            ->assertOk()->assertJsonPath('meta.prediction_revision_id', $saved['meta']['prediction_revision_id'])
            ->assertJsonPath('prediction.days_away', 0);
    });
});
