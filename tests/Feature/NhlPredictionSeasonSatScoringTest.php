<?php

declare(strict_types=1);

use App\Events\SatImported;
use App\Jobs\ScoreNhlPredictionGameSatJob;
use App\Jobs\RefreshNhlStatsDashboardJob;
use App\Listeners\QueuePredictionSeasonSatScoring;
use App\Models\NhlExpectedGoalsModel;
use App\Models\NhlModelRun;
use App\Services\BuildNhlShotAttemptFacts;
use App\Services\NhlShotAttemptModelScorer;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-23 12:00:00 UTC'));
    Http::preventStrayRequests();
    config(['cache.default' => 'array']);
    Cache::flush();
    Bus::fake();
    $this->gameId = 2026020001;
    $this->season = '20262027';
    foreach ([2026020001, 2026020002] as $gameId) {
        DB::table('nhl_games')->insert([
            'nhl_game_id' => $gameId, 'season_id' => $this->season, 'game_type' => 2,
            'game_date' => '2026-10-22', 'game_state' => 'OFF',
            'home_team_id' => 10, 'away_team_id' => 20,
        ]);
    }
    $this->trainedModel = function (NhlModelRun $run, string $target, float $probability, string $version = 'v1'): NhlExpectedGoalsModel {
        $model = NhlExpectedGoalsModel::query()->create([
            'name' => 'prediction-import-'.$run->id, 'version' => $version, 'model_run_id' => $run->id,
            'prediction_target' => $target, 'training_season_id' => '20242025',
            'status' => 'draft', 'trained_at' => now(),
            'feature_config' => ['fallback_levels' => [['baseline']], 'sample_mode' => $target === 'goal' ? 'sog' : 'sat'],
        ]);
        DB::table('nhl_expected_goals_model_buckets')->insert([
            'expected_goals_model_id' => $model->id, 'bucket_key' => 'L99|baseline=league',
            'fallback_level' => 99, 'bucket_dimensions' => '{}', 'attempts' => 100, 'goals' => 10,
            'raw_goal_rate' => $probability, 'smoothed_goal_probability' => $probability,
        ]);

        return $model;
    };
    $this->createRun = function (string $key = 'primary', array $overrides = []): NhlModelRun {
        $run = NhlModelRun::query()->create(array_merge([
            'run_key' => $key, 'name' => $key, 'model_family' => 'sat', 'workflow_stage' => 'training',
            'model_version' => 'fixture', 'status' => 'complete', 'train_season_ids' => ['20232024', '20242025'],
            'target_season_id' => '20252026', 'run_config' => ['projection_season_id' => $this->season],
        ], $overrides));
        ($this->trainedModel)($run, 'shot_on_goal', 0.45);
        ($this->trainedModel)($run, 'goal', 0.12);

        return $run;
    };
    $this->insertFact = function (array $overrides = []): int {
        $gameId = $overrides['nhl_game_id'] ?? $this->gameId;
        $playId = DB::table('play_by_plays')->insertGetId([
            'nhl_game_id' => $gameId, 'type_desc_key' => 'shot-on-goal',
            'period' => 1, 'period_type' => 'REG', 'seconds_in_game' => 120,
            'shooting_player_id' => 101, 'event_owner_team_id' => 10,
        ]);

        return DB::table('nhl_shot_attempts_facts')->insertGetId(array_merge([
            'nhl_game_id' => $gameId, 'play_by_play_id' => $playId, 'season_id' => $this->season,
            'game_date' => '2026-10-22', 'shooter_player_id' => 101, 'goalie_player_id' => 201,
            'team_id' => 10, 'opponent_team_id' => 20, 'attempt_result' => 'shot_on_goal',
            'is_shot_attempt' => true, 'is_shot_on_goal' => true, 'is_goal' => false,
            'period_type' => 'REG', 'is_empty_net' => false, 'shot_type_bucket' => 'unknown',
        ], $overrides));
    };
    $this->dispatchImport = fn () => app(QueuePredictionSeasonSatScoring::class)
        ->handle(new SatImported($this->gameId, $this->season, 2));
    $this->score = fn (NhlModelRun $run) => (new ScoreNhlPredictionGameSatJob($run->id, $this->gameId, $this->season))
        ->handle(app(NhlShotAttemptModelScorer::class));
});

afterEach(function (): void {
    $this->travelBack();
});

it('queues a matching prediction season even when Test is another season', function (): void {
    $run = ($this->createRun)();
    ($this->dispatchImport)();
    Bus::assertDispatched(ScoreNhlPredictionGameSatJob::class, fn ($job) => $job->modelRunId === $run->id
        && $job->nhlGameId === $this->gameId && $job->seasonId === $this->season && $job->queue === 'projections');
});

it('does not select a model solely because its Test season matches', function (): void {
    ($this->createRun)('test-only', ['target_season_id' => $this->season, 'run_config' => ['projection_season_id' => '20272028']]);
    ($this->dispatchImport)();
    Bus::assertNotDispatched(ScoreNhlPredictionGameSatJob::class);
});

it('does not select a model solely because Train contains the imported season', function (): void {
    ($this->createRun)('train-only', ['train_season_ids' => [$this->season], 'run_config' => ['projection_season_id' => '20272028']]);
    ($this->dispatchImport)();
    Bus::assertNotDispatched(ScoreNhlPredictionGameSatJob::class);
});

it('queues each matching model separately', function (): void {
    ($this->createRun)('first');
    ($this->createRun)('second');
    ($this->dispatchImport)();
    Bus::assertDispatchedTimes(ScoreNhlPredictionGameSatJob::class, 2);
});

it('ignores archived models', function (): void {
    ($this->createRun)('archived', ['status' => 'archived']);
    ($this->dispatchImport)();
    Bus::assertNotDispatched(ScoreNhlPredictionGameSatJob::class);
});

it('ignores failed models', function (): void {
    ($this->createRun)('failed', ['status' => 'failed']);
    ($this->dispatchImport)();
    Bus::assertNotDispatched(ScoreNhlPredictionGameSatJob::class);
});

it('ignores other model families', function (): void {
    ($this->createRun)('other', ['model_family' => 'other']);
    ($this->dispatchImport)();
    Bus::assertNotDispatched(ScoreNhlPredictionGameSatJob::class);
});

it('waits for both trained probability targets', function (): void {
    $run = ($this->createRun)();
    NhlExpectedGoalsModel::query()->where('model_run_id', $run->id)->where('prediction_target', 'goal')->delete();
    ($this->dispatchImport)();
    Bus::assertNotDispatched(ScoreNhlPredictionGameSatJob::class);
});

it('does not use buckets that are currently being retrained', function (): void {
    $run = ($this->createRun)();
    NhlExpectedGoalsModel::query()->where('model_run_id', $run->id)->update(['status' => 'running']);
    ($this->dispatchImport)();
    Bus::assertNotDispatched(ScoreNhlPredictionGameSatJob::class);
});

it('ignores a different game type', function (): void {
    ($this->createRun)();
    app(QueuePredictionSeasonSatScoring::class)->handle(new SatImported($this->gameId, $this->season, 3));
    Bus::assertNotDispatched(ScoreNhlPredictionGameSatJob::class);
});

it('stores both existing probabilities for every eligible SAT including blocked attempts', function (): void {
    $run = ($this->createRun)();
    $factId = ($this->insertFact)(['attempt_result' => 'blocked_shot', 'is_shot_on_goal' => false]);
    ($this->score)($run);
    $scores = DB::table('nhl_shot_attempt_model_scores')->where('shot_attempt_fact_id', $factId)->get()->keyBy('prediction_target');
    expect($scores)->toHaveCount(2)->and((float) $scores['shot_on_goal']->probability)->toBe(0.45)
        ->and((float) $scores['goal']->probability)->toBe(0.12)
        ->and((bool) $scores['goal']->is_scored)->toBeTrue()
        ->and((bool) $scores['shot_on_goal']->is_high_danger)->toBeFalse()
        ->and((int) $scores['goal']->model_run_id)->toBe($run->id);
    Bus::assertDispatched(RefreshNhlStatsDashboardJob::class, fn ($job): bool =>
        $job->seasonId === '20262027' && $job->view === 'leaders');
    Bus::assertDispatched(RefreshNhlStatsDashboardJob::class, fn ($job): bool =>
        $job->seasonId === '20262027' && $job->view === 'goalies');
});

it('updates reimported game ratings without duplicates', function (): void {
    $run = ($this->createRun)();
    ($this->insertFact)();
    ($this->score)($run);
    DB::table('nhl_expected_goals_model_buckets')->whereIn('expected_goals_model_id',
        NhlExpectedGoalsModel::query()->where('model_run_id', $run->id)->pluck('id'))->update(['smoothed_goal_probability' => 0.2]);
    ($this->score)($run);
    expect(DB::table('nhl_shot_attempt_model_scores')->count())->toBe(2)
        ->and(DB::table('nhl_shot_attempt_model_scores')->where('probability', 0.2)->count())->toBe(2);
});

it('leaves other games ratings untouched', function (): void {
    $run = ($this->createRun)();
    ($this->insertFact)();
    ($this->insertFact)(['nhl_game_id' => 2026020002]);
    (new ScoreNhlPredictionGameSatJob($run->id, 2026020002, $this->season))->handle(app(NhlShotAttemptModelScorer::class));
    $before = DB::table('nhl_shot_attempt_model_scores')->where('nhl_game_id', 2026020002)->get()->toJson();
    ($this->score)($run);
    expect(DB::table('nhl_shot_attempt_model_scores')->where('nhl_game_id', 2026020002)->get()->toJson())->toBe($before);
});

it('keeps scores from different model runs separate', function (): void {
    $first = ($this->createRun)('first');
    $second = ($this->createRun)('second');
    ($this->insertFact)();
    ($this->score)($first);
    ($this->score)($second);
    expect(DB::table('nhl_shot_attempt_model_scores')->where('model_run_id', $first->id)->count())->toBe(2)
        ->and(DB::table('nhl_shot_attempt_model_scores')->where('model_run_id', $second->id)->count())->toBe(2);
});

it('uses the newest trained version for each target', function (): void {
    $run = ($this->createRun)();
    $newGoal = ($this->trainedModel)($run, 'goal', 0.25, 'v2');
    ($this->insertFact)();
    ($this->score)($run);
    expect((float) DB::table('nhl_shot_attempt_model_scores')->where('expected_goals_model_id', $newGoal->id)->value('probability'))->toBe(0.25);
});

it('rechecks the prediction season when the queued job executes', function (): void {
    $run = ($this->createRun)();
    ($this->insertFact)();
    $run->update(['run_config' => ['projection_season_id' => '20272028']]);
    ($this->score)($run);
    expect(DB::table('nhl_shot_attempt_model_scores')->count())->toBe(0);
});

it('does no work when its model has been removed', function (): void {
    ($this->insertFact)();
    (new ScoreNhlPredictionGameSatJob(999999, $this->gameId, $this->season))->handle(app(NhlShotAttemptModelScorer::class));
    expect(DB::table('nhl_shot_attempt_model_scores')->count())->toBe(0);
});

it('rejects an event game whose stored season no longer matches', function (): void {
    $run = ($this->createRun)();
    ($this->insertFact)();
    DB::table('nhl_games')->where('nhl_game_id', $this->gameId)->update(['season_id' => '20272028']);
    ($this->score)($run);
    expect(DB::table('nhl_shot_attempt_model_scores')->count())->toBe(0);
});

it('retains explicit exclusions for shootouts and empty nets', function (): void {
    $run = ($this->createRun)();
    ($this->insertFact)(['period_type' => 'SO']);
    ($this->insertFact)(['is_empty_net' => true]);
    ($this->score)($run);
    expect(DB::table('nhl_shot_attempt_model_scores')->where('is_scored', true)->count())->toBe(0)
        ->and(DB::table('nhl_shot_attempt_model_scores')->where('exclusion_reason', 'shootout')->count())->toBe(2)
        ->and(DB::table('nhl_shot_attempt_model_scores')->where('exclusion_reason', 'empty_net')->count())->toBe(2);
});

it('rolls back both targets if either scoring operation fails', function (): void {
    $run = ($this->createRun)();
    ($this->insertFact)();
    $scorer = new class extends NhlShotAttemptModelScorer
    {
        public function scoreGame(NhlExpectedGoalsModel $model, int $gameId, string $seasonId, int $gameType, float $highDangerThreshold): bool
        {
            if ($model->prediction_target === 'goal') {
                throw new RuntimeException('Scoring failure');
            }

            return parent::scoreGame($model, $gameId, $seasonId, $gameType, $highDangerThreshold);
        }
    };
    expect(fn () => (new ScoreNhlPredictionGameSatJob($run->id, $this->gameId, $this->season))->handle($scorer))
        ->toThrow(RuntimeException::class, 'Scoring failure');
    expect(DB::table('nhl_shot_attempt_model_scores')->count())->toBe(0);
});

it('fails visibly when a trained target disappears after dispatch', function (): void {
    $run = ($this->createRun)();
    ($this->insertFact)();
    NhlExpectedGoalsModel::query()->where('model_run_id', $run->id)->where('prediction_target', 'goal')->delete();
    expect(fn () => ($this->score)($run))->toThrow(RuntimeException::class, 'requires trained SAT and SOG buckets');
});

it('does not retrain models or change source outcomes while scoring', function (): void {
    $run = ($this->createRun)();
    $factId = ($this->insertFact)();
    $modelsBefore = NhlExpectedGoalsModel::query()->where('model_run_id', $run->id)->get()->toJson();
    $factsBefore = DB::table('nhl_shot_attempts_facts')->where('id', $factId)->first();
    ($this->score)($run);
    expect(NhlExpectedGoalsModel::query()->where('model_run_id', $run->id)->get()->toJson())->toBe($modelsBefore)
        ->and(DB::table('nhl_shot_attempts_facts')->where('id', $factId)->first())->toEqual($factsBefore);
});

it('emits the import event with game and season after saving facts', function (): void {
    Event::fake([SatImported::class]);
    ($this->insertFact)();
    app(BuildNhlShotAttemptFacts::class)->buildForGame($this->gameId);
    Event::assertDispatched(SatImported::class, fn ($event) => $event->nhlGameId === $this->gameId
        && $event->seasonId === $this->season && $event->gameType === 2
        && DB::table('nhl_shot_attempts_facts')->where('nhl_game_id', $event->nhlGameId)->exists());
});

it('does not dispatch scoring for rolled back fact imports', function (): void {
    Event::fake([SatImported::class]);
    ($this->insertFact)();
    DB::beginTransaction();
    app(BuildNhlShotAttemptFacts::class)->buildForGame($this->gameId);
    Event::assertNotDispatched(SatImported::class);
    DB::rollBack();
    Event::assertNotDispatched(SatImported::class);
});
