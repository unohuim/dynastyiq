<?php

declare(strict_types=1);

use App\Models\NhlPregameContextRun;
use App\Models\NhlPregameContextRunGame;
use App\Models\NhlExpectedGoalsModel;
use App\Models\NhlModelRun;
use App\Jobs\BuildNhlPregameContextGameJob;
use App\Jobs\CalculateNhlPregameContextLeagueMetricsJob;
use App\Services\NhlPregameContextOrchestrator;
use App\Services\NhlPregameContextBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-10-09 12:00:00 UTC'));
    Bus::fake();
    Http::preventStrayRequests();
    $this->player = DB::table('players')->insertGetId([
        'nhl_id' => 101, 'first_name' => 'Test', 'last_name' => 'Skater',
        'full_name' => 'Test Skater', 'position' => 'C', 'pos_type' => 'F',
    ]);
    $this->run = NhlPregameContextRun::create([
        'action' => 'backfill', 'status' => 'running', 'season_ids' => ['20252026'],
    ]);
    $this->game = function (int $id, string $date, string $season = '20252026', int $team = 10): void {
        DB::table('nhl_games')->insert([
            'nhl_game_id' => $id, 'season_id' => $season, 'game_type' => 2, 'game_state' => 'OFF',
            'game_date' => $date, 'start_time_utc' => $date . ' 19:00:00',
            'game_dow' => 'Fri', 'game_month' => 'Oct', 'home_team_id' => 10, 'away_team_id' => 20,
        ]);
        DB::table('nhl_game_summaries')->insert([
            'nhl_game_id' => $id, 'nhl_player_id' => 101, 'nhl_team_id' => $team,
            'toi' => 1200, 'sat' => 9, 'sog' => 5, 'g' => 2,
        ]);
    };
    $this->exposure = function (int $id, string $strength, int $toi): void {
        DB::table('nhl_player_game_strength_summaries')->insert([
            'nhl_game_id' => $id, 'player_id' => $this->player, 'nhl_player_id' => 101,
            'team_id' => 10, 'strength' => $strength, 'toi' => $toi,
            'satf' => 40, 'sata' => 30, 'sf' => 20, 'sa' => 10, 'gf' => 2, 'ga' => 1,
            'individual_pts' => 1,
        ]);
    };
    $this->fact = function (int $id, string $strength, bool $sog = false, bool $goal = false, array $extra = []): void {
        $playId = DB::table('play_by_plays')->insertGetId([
            'nhl_game_id' => $id, 'period' => 1, 'seconds_in_game' => 120, 'type_desc_key' => 'shot-on-goal',
        ]);
        DB::table('nhl_shot_attempts_facts')->insert([
            'play_by_play_id' => $playId, 'nhl_game_id' => $id, 'shooter_player_id' => 101,
            'attempt_result' => 'shot', 'is_shot_attempt' => true,
            'is_shot_on_goal' => $sog, 'is_goal' => $goal,
            'strength' => $strength, 'strength_bucket' => strtolower($strength), 'period_type' => 'REG',
            ...$extra,
        ]);
    };
    $this->build = function (): array {
        app(NhlPregameContextBuilder::class)->build($this->run, 2025020010);

        return json_decode(DB::table('nhl_player_game_pregame_contexts')
            ->where('nhl_game_id', 2025020010)->where('nhl_player_id', 101)->value('metrics'), true);
    };
    $this->reliableFilter = function (): array {
        $run = NhlModelRun::create(['run_key' => 'reliable-context', 'name' => 'Train', 'model_family' => 'sat',
            'workflow_stage' => 'training', 'model_version' => 'test', 'status' => 'complete',
            'train_season_ids' => ['20242025'], 'target_season_id' => '20252026']);
        $model = NhlExpectedGoalsModel::create(['model_run_id' => $run->id, 'name' => 'SAT',
            'version' => 'reliable-context', 'prediction_target' => 'shot_on_goal',
            'feature_config' => ['training_season_ids' => ['20242025'],
                'fallback_levels' => [1 => ['shot_type_group'], 99 => ['baseline']]]]);
        foreach (['wrist' => 1, 'slap' => 0.5, 'backhand' => 0.4999, 'unknown' => 0] as $type => $confidence) {
            DB::table('nhl_expected_goals_model_buckets')->insert([
                'expected_goals_model_id' => $model->id, 'bucket_key' => 'L01|shot_type_group='.$type,
                'fallback_level' => 1, 'bucket_dimensions' => '{}', 'attempts' => 100, 'goals' => 10,
                'raw_goal_rate' => 0.1, 'smoothed_goal_probability' => 0.1, 'confidence_score' => $confidence,
            ]);
        }
        DB::table('nhl_sat_model_entity_profile_buckets')->insert([
            'model_run_id' => $run->id, 'sat_expected_goals_model_id' => $model->id,
            'source_season_ids' => '["20242025"]', 'profile_type' => 'skater_offense',
            'entity_key' => 'skater_offense:101', 'matched_bucket_key' => 'L01|shot_type_group=wrist',
            'fallback_level' => 1, 'bucket_dimensions' => '{}',
        ]);

        return app(NhlPregameContextBuilder::class)->evaluationBucketFilter($run->id, ['20242025']);
    };
    $this->evaluationMetrics = fn (array $filter): array => app(NhlPregameContextBuilder::class)->evaluationPlayerMetrics(
        101, 10, 20, 'home', Carbon::parse('2025-10-20 19:00:00 UTC'), '20252026', ['20242025'], $filter
    );
    $this->teamContext = fn (int $gameId, int $teamId, string $date = '2025-10-20', string $cutoff = '18:00:00'): int => DB::table('nhl_team_game_pregame_contexts')->insertGetId([
        'run_id' => $this->run->id, 'model_run_id' => null, 'nhl_game_id' => $gameId,
        'nhl_team_id' => $teamId, 'opponent_team_id' => $teamId + 100, 'game_date' => $date,
        'source_cutoff_at' => $date . ' ' . $cutoff, 'venue' => 'home',
        'context_version' => NhlPregameContextBuilder::VERSION, 'schedule_metrics' => '{}',
        'opponent_metrics' => '{}', 'metrics' => '{}', 'ranks' => '{}',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->playerContext = fn (int $gameId, int $playerId, string $date = '2025-10-20'): int => DB::table('nhl_player_game_pregame_contexts')->insertGetId([
        'run_id' => $this->run->id, 'model_run_id' => null, 'team_context_id' => null,
        'nhl_game_id' => $gameId, 'nhl_player_id' => $playerId, 'nhl_team_id' => 10,
        'opponent_team_id' => 20, 'game_date' => $date, 'source_cutoff_at' => $date . ' 18:00:00',
        'venue' => 'home', 'participant_source' => 'completed_game_summary',
        'context_version' => NhlPregameContextBuilder::VERSION, 'metrics' => '{}', 'ranks' => '{}',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->contextMetric = fn (string $table, string $foreignKey, int $contextId, string $metric, float $value) => DB::table($table)->insert([
        $foreignKey => $contextId, 'metric_key' => $metric, 'window_key' => 'l20', 'strength' => 'ALL',
        'measure_key' => 'value', 'value' => $value, 'created_at' => now(), 'updated_at' => now(),
    ]);
    ($this->game)(2025020010, '2025-10-20');
    ($this->game)(2025020001, '2025-10-10');
    ($this->exposure)(2025020001, 'EV', 600);
    ($this->exposure)(2025020001, 'PP', 120);
    ($this->exposure)(2025020001, 'PK', 60);
});

it('persists individual EV PP PK counts and rates separately from on ice totals', function (): void {
    ($this->fact)(2025020001, 'EV', true, true);
    ($this->fact)(2025020001, 'EV');
    ($this->fact)(2025020001, 'PP', true);
    ($this->fact)(2025020001, 'PK');
    $metrics = ($this->build)();
    expect($metrics['strength']['EV']['last_5'])->toMatchArray([
        'sat' => 2, 'sog' => 1, 'goals' => 1, 'sat_per_60' => 12,
        'sog_per_60' => 6, 'goals_per_60' => 6, 'shooting_pct' => 100,
        'corsi_for' => 40, 'pdo' => 100,
    ])->and($metrics['strength']['PP']['last_5']['sat_per_60'])->toEqual(30)
        ->and($metrics['strength']['PK']['last_5']['sat_per_60'])->toEqual(60)
        ->and($metrics['strength']['PK']['last_5']['shooting_pct'])->toBeNull()
        ->and($metrics['all']['last_5']['sat'])->toBe(9);
});

it('keeps missing facts unavailable rather than claiming zero attempts', function (): void {
    $row = ($this->build)()['strength']['EV']['last_5'];
    expect($row['sat'])->toBeNull()->and($row['sat_per_60'])->toBeNull()
        ->and($row['individual_missing_games'])->toBe(1);
});

it('keeps missing strength exposure unavailable', function (): void {
    DB::table('nhl_player_game_strength_summaries')->delete();
    ($this->fact)(2025020001, 'EV', true);
    expect(($this->build)()['strength']['EV']['last_5']['sat_per_60'])->toBeNull();
});

it('preserves genuine zero shot games and zero exposure rates', function (): void {
    ($this->fact)(2025020001, 'EV', true, false, ['shooter_player_id' => 999]);
    DB::table('nhl_player_game_strength_summaries')->where('strength', 'PK')->delete();
    $metrics = ($this->build)();
    expect($metrics['strength']['EV']['last_5']['sat_per_60'])->toEqual(0)
        ->and($metrics['strength']['PK']['last_5']['sat'])->toBe(0)
        ->and($metrics['strength']['PK']['last_5']['sat_per_60'])->toBeNull();
});

it('uses prior seasons and excludes target future and non regular games', function (): void {
    ($this->fact)(2025020001, 'EV');
    foreach ([[2024020001, '2025-04-10', '20242025'], [2025020011, '2025-10-21', '20252026'], [2025010001, '2025-10-09', '20252026']] as [$id, $date, $season]) {
        ($this->game)($id, $date, $season);
        ($this->exposure)($id, 'EV', 600);
        ($this->fact)($id, 'EV');
    }
    DB::table('nhl_games')->where('nhl_game_id', 2025010001)->update(['game_type' => 1]);
    ($this->fact)(2025020010, 'EV');
    $metrics = ($this->build)()['strength']['EV'];
    expect($metrics['last_5']['sat'])->toBe(2)
        ->and($metrics['last_5']['games'])->toBe(2)
        ->and($metrics['season_to_date']['sat'])->toBe(1);
});

it('selects the last five appearances before choosing strength so no exposure games remain', function (): void {
    ($this->fact)(2025020001, 'PP', true);
    foreach (range(2, 6) as $index) {
        $id = 2025020000 + $index;
        ($this->game)($id, '2025-10-' . (10 + $index));
        ($this->exposure)($id, 'EV', 600);
        ($this->fact)($id, 'EV', true);
    }
    $row = ($this->build)()['strength']['PP'];
    expect($row['last_5']['games'])->toBe(5)->and($row['last_5']['sat'])->toBe(0)
        ->and($row['last_5']['sat_per_60'])->toBeNull()
        ->and($row['last_10']['sat'])->toBe(1);
});

it('preserves raw strength for empty net buckets and excludes shootouts', function (): void {
    ($this->fact)(2025020001, 'EV', true, true, ['strength_bucket' => 'en']);
    ($this->fact)(2025020001, 'EV', true, true, ['period_type' => 'SO']);
    expect(($this->build)()['strength']['EV']['last_5']['goals'])->toBe(1);
});

it('marks unclassified player attempts unavailable instead of dropping them silently', function (): void {
    ($this->fact)(2025020001, 'unknown');
    expect(($this->build)()['strength']['EV']['last_5']['sat'])->toBeNull();
});

it('upserts rebuilt snapshots without creating a second version', function (): void {
    ($this->fact)(2025020001, 'EV');
    ($this->build)();
    ($this->fact)(2025020001, 'EV', true);
    $metrics = ($this->build)();
    expect(DB::table('nhl_player_game_pregame_contexts')->where('nhl_game_id', 2025020010)->count())->toBe(1)
        ->and($metrics['strength']['EV']['last_5']['sat'])->toBe(2)
        ->and($metrics['individual_strength_version'])->toBe(1);
});

it('uses pooled strength ice time and historical venue and opponent identity', function (): void {
    ($this->fact)(2025020001, 'EV', true);
    ($this->game)(2025020002, '2025-10-11', '20252026', 20);
    ($this->exposure)(2025020002, 'EV', 1200);
    ($this->fact)(2025020002, 'EV', true);
    $windows = ($this->build)()['strength']['EV'];
    expect($windows['last_5']['sat_per_60'])->toEqual(4)
        ->and($windows['venue_season_to_date']['sat'])->toBe(1)
        ->and($windows['opponent_last_10']['sat'])->toBe(1);
});

it('uses only complete Train games for counts exposure and games without relaxing recent windows', function (): void {
    ($this->game)(2024020001, '2025-04-01', '20242025');
    ($this->exposure)(2024020001, 'EV', 600);
    ($this->fact)(2024020001, 'EV', true, true);
    ($this->game)(2024020002, '2025-04-02', '20242025');
    ($this->exposure)(2024020002, 'EV', 1800);
    // No shot facts for the second game: its ice time must also be excluded.
    $metrics = app(NhlPregameContextBuilder::class)->evaluationPlayerMetrics(
        101, 10, 20, 'home', Carbon::parse('2025-10-20 19:00:00 UTC'), '20252026', ['20242025']
    )['strength']['EV'];

    foreach (['train', 'venue_train'] as $window) {
        expect($metrics[$window])->toMatchArray([
            'games' => 1, 'usable_games' => 1, 'individual_missing_games' => 1,
            'toi_seconds' => 600, 'sat' => 1, 'sog' => 1, 'goals' => 1,
            'sat_per_60' => 6, 'sog_per_60' => 6, 'goals_per_60' => 6,
        ]);
    }
    expect($metrics['last_5']['sat_per_60'])->toBeNull();
});

it('leaves Train unavailable when no complete games exist', function (): void {
    ($this->game)(2024020001, '2025-04-01', '20242025');
    ($this->exposure)(2024020001, 'EV', 600);
    $metrics = app(NhlPregameContextBuilder::class)->evaluationPlayerMetrics(
        101, 10, 20, 'home', Carbon::parse('2025-10-20 19:00:00 UTC'), '20252026', ['20242025']
    )['strength']['EV']['train'];

    expect($metrics)->toMatchArray([
        'games' => 0, 'usable_games' => 0, 'individual_missing_games' => 1,
        'toi_seconds' => 0, 'sat' => null, 'sat_per_60' => null,
    ]);
});

it('restricts LSeason H/A to prior-season appearances matching the target venue', function (): void {
    ($this->game)(2024020001, '2025-04-01', '20242025', 10);
    ($this->exposure)(2024020001, 'EV', 600);
    ($this->fact)(2024020001, 'EV');
    ($this->game)(2024020002, '2025-04-02', '20242025', 20);
    ($this->exposure)(2024020002, 'EV', 1200);
    ($this->fact)(2024020002, 'EV');
    ($this->fact)(2025020001, 'EV');
    foreach (['home' => 6, 'away' => 3] as $venue => $expected) {
        $metrics = app(NhlPregameContextBuilder::class)->evaluationPlayerMetrics(
            101, 10, 20, $venue, Carbon::parse('2025-10-20 19:00:00 UTC'), '20252026', ['20242025']
        );
        expect($metrics['strength']['EV']['venue_last_season']['games'])->toBe(1)
            ->and($metrics['strength']['EV']['venue_last_season']['sat_per_60'])->toEqual($expected)
            ->and($metrics['all']['venue_last_season']['games'])->toBe(1);
    }
});

it('uses the same inclusive confidence cutoff for Train recent quarter and all-strength evaluation windows', function (): void {
    $filter = ($this->reliableFilter)();
    expect($filter['keys'])->toBe(['L01|shot_type_group=slap', 'L01|shot_type_group=wrist']);
    ($this->game)(2024020001, '2025-04-01', '20242025');
    ($this->exposure)(2024020001, 'EV', 600);
    foreach ([2024020001, 2025020001] as $id) {
        foreach (['wrist', 'slap', 'backhand', 'unknown', 'unmapped'] as $type) {
            ($this->fact)($id, 'EV', true, true, ['shot_type_bucket' => $type]);
        }
        ($this->fact)($id, 'EV', true, true, ['shot_type_bucket' => 'wrist', 'is_empty_net' => true]);
        ($this->fact)($id, 'EV', true, true, ['shot_type_bucket' => 'wrist', 'period_type' => 'SO']);
    }
    // Neither target outcomes nor later games can alter pregame windows.
    ($this->fact)(2025020010, 'EV', true, true, ['shot_type_bucket' => 'wrist']);
    $metrics = ($this->evaluationMetrics)($filter);
    foreach (['train', 'venue_train', 'last_season', 'venue_last_season', 'last_5', 'last_10', 'last_20', 'opponent_last_10', 'q1_to_date'] as $key) {
        expect($metrics['strength']['EV'][$key]['sat_per_60'])->toEqual(12);
    }
    expect($metrics['strength']['EV']['last_5']['toi_seconds'])->toBe(1200)
        ->and($metrics['strength']['EV']['q1_to_date']['games'])->toBe(1)
        ->and($metrics['strength']['EV']['train']['sog'])->toBe(2)
        ->and($metrics['strength']['EV']['train']['goals'])->toBe(2)
        ->and($metrics['all']['last_5']['sat_per_60'])->toEqual(6);
});

it('stores team league mean deltas rank and population for a target day', function (): void {
    $first = ($this->teamContext)(2025020101, 10);
    $second = ($this->teamContext)(2025020102, 11);
    ($this->contextMetric)('nhl_team_game_pregame_context_metrics', 'team_context_id', $first, 'sat_for_per_game', 40);
    ($this->contextMetric)('nhl_team_game_pregame_context_metrics', 'team_context_id', $second, 'sat_for_per_game', 60);

    app(NhlPregameContextBuilder::class)->buildLeagueBenchmarksForDate(null, '2025-10-20');
    $row = DB::table('nhl_team_game_pregame_context_metrics')->where('team_context_id', $first)->first();

    expect((float) $row->league_average)->toBe(50.0)
        ->and((float) $row->signed_difference)->toBe(-10.0)
        ->and((float) $row->absolute_difference)->toBe(10.0)
        ->and((float) $row->percentage_difference)->toBe(-20.0)
        ->and($row->league_rank)->toBe(2)
        ->and($row->rank_population)->toBe(2);
});

it('ranks player metrics independently from team metrics', function (): void {
    $first = ($this->playerContext)(2025020101, 101);
    $second = ($this->playerContext)(2025020102, 102);
    ($this->contextMetric)('nhl_player_game_pregame_context_metrics', 'player_context_id', $first, 'sat_per_60', 12);
    ($this->contextMetric)('nhl_player_game_pregame_context_metrics', 'player_context_id', $second, 'sat_per_60', 18);

    app(NhlPregameContextBuilder::class)->buildLeagueBenchmarksForDate(null, '2025-10-20');
    $row = DB::table('nhl_player_game_pregame_context_metrics')->where('player_context_id', $first)->first();

    expect((float) $row->league_average)->toBe(15.0)
        ->and($row->league_rank)->toBe(2)
        ->and($row->rank_population)->toBe(2);
});

it('limits each league comparison to the matching metric window strength and game day', function (): void {
    $first = ($this->teamContext)(2025020101, 10);
    $second = ($this->teamContext)(2025020102, 11);
    $otherDate = ($this->teamContext)(2025020103, 12, '2025-10-21');
    ($this->contextMetric)('nhl_team_game_pregame_context_metrics', 'team_context_id', $first, 'sat_for_per_game', 10);
    ($this->contextMetric)('nhl_team_game_pregame_context_metrics', 'team_context_id', $second, 'sat_for_per_game', 30);
    ($this->contextMetric)('nhl_team_game_pregame_context_metrics', 'team_context_id', $second, 'sog_per_sat_pct', 50);
    ($this->contextMetric)('nhl_team_game_pregame_context_metrics', 'team_context_id', $otherDate, 'sat_for_per_game', 100);

    app(NhlPregameContextBuilder::class)->buildLeagueBenchmarksForDate(null, '2025-10-20');
    $firstRow = DB::table('nhl_team_game_pregame_context_metrics')->where('team_context_id', $first)->first();
    $nextDateRow = DB::table('nhl_team_game_pregame_context_metrics')->where('team_context_id', $otherDate)->first();

    expect((float) $firstRow->league_average)->toBe(20.0)
        ->and($firstRow->rank_population)->toBe(2)
        ->and($nextDateRow->league_average)->toBeNull();
});

it('assigns tied ranks and leaves percent difference null for a zero league average', function (): void {
    $first = ($this->teamContext)(2025020101, 10);
    $second = ($this->teamContext)(2025020102, 11);
    ($this->contextMetric)('nhl_team_game_pregame_context_metrics', 'team_context_id', $first, 'wins', 0);
    ($this->contextMetric)('nhl_team_game_pregame_context_metrics', 'team_context_id', $second, 'wins', 0);

    app(NhlPregameContextBuilder::class)->buildLeagueBenchmarksForDate(null, '2025-10-20');
    $rows = DB::table('nhl_team_game_pregame_context_metrics')->whereIn('team_context_id', [$first, $second])->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->every(fn (object $row): bool => (float) $row->league_average === 0.0
            && $row->league_rank === 1 && $row->rank_population === 2 && $row->percentage_difference === null))->toBeTrue();
});

it('uses only peer snapshots available by each target start time', function (): void {
    $early = ($this->teamContext)(2025020101, 10, '2025-10-20', '18:00:00');
    $late = ($this->teamContext)(2025020102, 11, '2025-10-20', '20:00:00');
    ($this->contextMetric)('nhl_team_game_pregame_context_metrics', 'team_context_id', $early, 'sat_for_per_game', 10);
    ($this->contextMetric)('nhl_team_game_pregame_context_metrics', 'team_context_id', $late, 'sat_for_per_game', 90);

    app(NhlPregameContextBuilder::class)->buildLeagueBenchmarksForDate(null, '2025-10-20');
    $earlyRow = DB::table('nhl_team_game_pregame_context_metrics')->where('team_context_id', $early)->first();
    $lateRow = DB::table('nhl_team_game_pregame_context_metrics')->where('team_context_id', $late)->first();

    expect((float) $earlyRow->league_average)->toBe(10.0)
        ->and($earlyRow->rank_population)->toBe(1)
        ->and((float) $lateRow->league_average)->toBe(50.0)
        ->and($lateRow->rank_population)->toBe(2);
});

it('finishes one day league comparisons before dispatching the following day', function (): void {
    $first = NhlPregameContextRunGame::create([
        'run_id' => $this->run->id, 'nhl_game_id' => 2025020101, 'game_date' => '2025-10-20',
        'sequence' => 1, 'state' => NhlPregameContextRunGame::STATE_PENDING,
    ]);
    NhlPregameContextRunGame::create([
        'run_id' => $this->run->id, 'nhl_game_id' => 2025020102, 'game_date' => '2025-10-21',
        'sequence' => 2, 'state' => NhlPregameContextRunGame::STATE_PENDING,
    ]);

    $orchestrator = app(NhlPregameContextOrchestrator::class);
    $orchestrator->load($this->run->id);
    Bus::assertDispatched(BuildNhlPregameContextGameJob::class, fn ($job): bool => $job->gameId === $first->nhl_game_id);

    $first->update(['state' => NhlPregameContextRunGame::STATE_COMPLETE]);
    $orchestrator->load($this->run->id);
    Bus::assertDispatched(CalculateNhlPregameContextLeagueMetricsJob::class, fn ($job): bool =>
        $job->runId === $this->run->id && $job->gameDate === '2025-10-20'
    );
    expect(data_get($this->run->fresh()->options, 'league_benchmarks.2025-10-20'))->toBe('processing');

    $orchestrator->markLeagueBenchmarksComplete($this->run->id, '2025-10-20');
    $orchestrator->load($this->run->id);
    Bus::assertDispatched(BuildNhlPregameContextGameJob::class, fn ($job): bool => $job->gameId === 2025020102);
});

it('retains genuine zero appearances and missing-data checks after bucket exclusion without changing shared context', function (): void {
    $filter = ($this->reliableFilter)();
    ($this->fact)(2025020001, 'EV', true, false, ['shot_type_bucket' => 'backhand']);
    $row = ($this->evaluationMetrics)($filter)['strength']['EV']['last_5'];
    expect($row['sat_per_60'])->toEqual(0)->and($row['games'])->toBe(1)->and($row['toi_seconds'])->toBe(600)
        ->and(($this->build)()['strength']['EV']['last_5']['sat_per_60'])->toEqual(6);
    ($this->fact)(2025020001, 'unknown', false, false, ['shot_type_bucket' => 'backhand']);
    expect(($this->evaluationMetrics)($filter)['strength']['EV']['last_5']['sat_per_60'])->toBeNull();
    DB::table('nhl_shot_attempts_facts')->delete();
    expect(($this->evaluationMetrics)($filter)['strength']['EV']['last_5']['sat_per_60'])->toBeNull();
});

it('keeps the frozen bucket set strength-specific and rejects a different Train source', function (): void {
    $filter = ($this->reliableFilter)();
    ($this->fact)(2025020001, 'EV', true, false, ['shot_type_bucket' => 'wrist']);
    ($this->fact)(2025020001, 'PP', true, false, ['shot_type_bucket' => 'slap']);
    ($this->fact)(2025020001, 'PK', true, false, ['shot_type_bucket' => 'backhand']);
    DB::table('nhl_expected_goals_model_buckets')->where('expected_goals_model_id', $filter['sat_model_id'])->update(['confidence_score' => 0]);
    $metrics = ($this->evaluationMetrics)($filter)['strength'];
    expect($metrics['EV']['last_5']['sat_per_60'])->toEqual(6)
        ->and($metrics['PP']['last_5']['sat_per_60'])->toEqual(30)
        ->and($metrics['PK']['last_5']['sat_per_60'])->toEqual(0);
    $model = NhlExpectedGoalsModel::findOrFail($filter['sat_model_id']);
    expect(fn () => app(NhlPregameContextBuilder::class)->evaluationBucketFilter($model->model_run_id, ['20252026']))
        ->toThrow(\RuntimeException::class, 'matching its frozen Train seasons');
});

it('builds league averages from prior results before any context exists', function (): void {
    ($this->fact)(2025020001, 'EV', true, true);
    $day = \App\Models\NhlPregameContextRunDay::create(['run_id' => $this->run->id, 'game_date' => '2025-10-20']);
    NhlPregameContextRunGame::create(['run_id' => $this->run->id, 'nhl_game_id' => 2025020010,
        'game_date' => '2025-10-20', 'sequence' => 1, 'state' => 'pending']);
    app(\App\Services\NhlPregameLeagueResults::class)->buildAverages($this->run, $day);
    $average = DB::table('nhl_pregame_context_league_averages')->where('run_day_id', $day->id)
        ->where('entity_type', 'player')->where('window_key', 'l5')->where('strength', 'EV')
        ->where('metric_key', 'sat_per_60')->first();
    expect((float) $average->league_average)->toBe(6.0)->and($average->rank_population)->toBe(1)
        ->and(DB::table('nhl_player_game_pregame_contexts')->count())->toBe(0)
        ->and(DB::table('nhl_team_game_pregame_contexts')->count())->toBe(0);
});

it('excludes target outcomes and target-only participants from league peers', function (): void {
    ($this->fact)(2025020001, 'EV', true);
    ($this->fact)(2025020010, 'EV', true, true);
    DB::table('nhl_game_summaries')->insert(['nhl_game_id' => 2025020010, 'nhl_player_id' => 999,
        'nhl_team_id' => 10, 'toi' => 600, 'sat' => 100, 'sog' => 90, 'g' => 80]);
    $game = DB::table('nhl_games')->where('nhl_game_id', 2025020010)->first();
    $rows = collect(app(NhlPregameContextBuilder::class)->leagueResultRows($this->run, $game, 'player'));
    expect($rows->pluck('entity_id')->unique()->values()->all())->toBe([101]);
    $rate = $rows->first(fn (array $row): bool => $row['metric_key'] === 'sat_per_60'
        && $row['window_key'] === 'l5' && $row['strength'] === 'EV');
    expect($rate['value'])->toBe(6.0);
});

it('preserves summed exposure and shooting denominators in result-based league metrics', function (): void {
    ($this->fact)(2025020001, 'EV', true, true);
    ($this->game)(2025020002, '2025-10-15');
    ($this->exposure)(2025020002, 'EV', 1200);
    foreach (range(1, 3) as $index) {
        ($this->fact)(2025020002, 'EV', true);
    }
    $game = DB::table('nhl_games')->where('nhl_game_id', 2025020010)->first();
    $rows = collect(app(NhlPregameContextBuilder::class)->leagueResultRows($this->run, $game, 'player'))
        ->where('window_key', 'l5')->where('strength', 'EV')->keyBy('metric_key');
    expect($rows['sat_per_60']['value'])->toBe(8.0)->and($rows['shooting_pct']['value'])->toBe(25.0);
});

it('retains model Train and cross-season recent windows independently', function (): void {
    $this->run->update(['season_ids' => ['20242025', '20252026'], 'options' => ['train_season_ids' => ['20242025']]]);
    ($this->game)(2024020001, '2025-04-10', '20242025');
    ($this->exposure)(2024020001, 'EV', 600);
    ($this->fact)(2024020001, 'EV', true);
    ($this->fact)(2025020001, 'EV', true);
    $game = DB::table('nhl_games')->where('nhl_game_id', 2025020010)->first();
    $rows = collect(app(NhlPregameContextBuilder::class)->leagueResultRows($this->run, $game, 'player'))
        ->where('metric_key', 'games')->where('strength', 'EV')->keyBy('window_key');
    expect($rows['train']['value'])->toBe(1.0)->and($rows['lseason']['value'])->toBe(1.0)
        ->and($rows['l5']['value'])->toBe(2.0)->and($rows['season_to_date']['value'])->toBe(1.0);
});

it('does not manufacture a league rate from missing shot facts', function (): void {
    $game = DB::table('nhl_games')->where('nhl_game_id', 2025020010)->first();
    $rows = collect(app(NhlPregameContextBuilder::class)->leagueResultRows($this->run, $game, 'player'));
    expect($rows->contains(fn (array $row): bool => $row['metric_key'] === 'sat_per_60'
        && $row['strength'] === 'EV' && $row['window_key'] === 'l5'))->toBeFalse();
});

it('attaches competition ranks with ties and deltas without replacing context values', function (): void {
    $day = \App\Models\NhlPregameContextRunDay::create(['run_id' => $this->run->id, 'game_date' => '2025-10-20']);
    NhlPregameContextRunGame::create(['run_id' => $this->run->id, 'nhl_game_id' => 2025020010,
        'game_date' => '2025-10-20', 'sequence' => 1, 'state' => 'pending']);
    $builder = \Mockery::mock(NhlPregameContextBuilder::class);
    $builder->shouldReceive('leagueResultRows')->andReturnUsing(function ($run, $game, string $type): \Generator {
        if ($type === 'player') {
            foreach ([101 => 10.0, 102 => 20.0, 103 => 20.0] as $id => $value) {
                yield ['entity_id' => $id, 'metric_key' => 'sat_per_60', 'window_key' => 'l20', 'strength' => 'ALL', 'value' => $value];
            }
        }
    });
    $service = new \App\Services\NhlPregameLeagueResults($builder);
    $service->buildAverages($this->run, $day);
    $contexts = [];
    foreach ([101 => 10.0, 102 => 20.0, 103 => 20.0] as $id => $value) {
        $contextId = ($this->playerContext)(2025020010, $id);
        DB::table('nhl_player_game_pregame_contexts')->where('id', $contextId)->update(['source_cutoff_at' => '2025-10-20 19:00:00']);
        ($this->contextMetric)('nhl_player_game_pregame_context_metrics', 'player_context_id', $contextId, 'sat_per_60', $value);
        $contexts[] = $contextId;
    }
    $service->attachComparisons($this->run, $day);
    $metrics = DB::table('nhl_player_game_pregame_context_metrics')->whereIn('player_context_id', $contexts)->orderBy('player_context_id')->get();
    expect($metrics->pluck('league_rank')->map(fn ($rank): int => (int) $rank)->all())->toBe([3, 1, 1])
        ->and($metrics->pluck('value')->map(fn ($value): float => (float) $value)->all())->toBe([10.0, 20.0, 20.0])
        ->and((float) $metrics[0]->league_average)->toBe(16.666667)
        ->and((float) $metrics[0]->signed_difference)->toBe(-6.666667)
        ->and($metrics[0]->league_average_id)->not->toBeNull();
    $averageId = $metrics[0]->league_average_id;
    $service->buildAverages($this->run, $day);
    expect(DB::table('nhl_pregame_context_league_averages')->count())->toBe(1)
        ->and(DB::table('nhl_pregame_context_league_averages')->value('id'))->toBe($averageId);
});

it('honors cancellation before writing result-based league baselines', function (): void {
    $day = \App\Models\NhlPregameContextRunDay::create(['run_id' => $this->run->id, 'game_date' => '2025-10-20']);
    NhlPregameContextRunGame::create(['run_id' => $this->run->id, 'nhl_game_id' => 2025020010,
        'game_date' => '2025-10-20', 'sequence' => 1, 'state' => 'pending']);
    expect(fn () => app(\App\Services\NhlPregameLeagueResults::class)->buildAverages($this->run, $day, fn (): bool => true))
        ->toThrow(\App\Exceptions\NhlPregameContextRunStopped::class);
    expect(DB::table('nhl_pregame_context_league_averages')->count())->toBe(0);
});

it('keeps percentage differences unavailable when the league average is zero', function (): void {
    $day = \App\Models\NhlPregameContextRunDay::create(['run_id' => $this->run->id, 'game_date' => '2025-10-20']);
    NhlPregameContextRunGame::create(['run_id' => $this->run->id, 'nhl_game_id' => 2025020010,
        'game_date' => '2025-10-20', 'sequence' => 1, 'state' => 'pending']);
    $context = ($this->playerContext)(2025020010, 101);
    DB::table('nhl_player_game_pregame_contexts')->where('id', $context)->update(['source_cutoff_at' => '2025-10-20 19:00:00']);
    ($this->contextMetric)('nhl_player_game_pregame_context_metrics', 'player_context_id', $context, 'goals', 0);
    $builder = \Mockery::mock(NhlPregameContextBuilder::class);
    $builder->shouldReceive('leagueResultRows')->andReturnUsing(function ($run, $game, string $type): \Generator {
        if ($type === 'player') {
            yield ['entity_id' => 101, 'metric_key' => 'goals', 'window_key' => 'l20', 'strength' => 'ALL', 'value' => 0.0];
        }
    });
    $service = new \App\Services\NhlPregameLeagueResults($builder);
    $service->buildAverages($this->run, $day);
    $service->attachComparisons($this->run, $day);
    $metric = DB::table('nhl_player_game_pregame_context_metrics')->where('player_context_id', $context)->first();
    expect((float) $metric->league_average)->toBe(0.0)->and((float) $metric->signed_difference)->toBe(0.0)
        ->and($metric->percentage_difference)->toBeNull()->and((int) $metric->league_rank)->toBe(1);
});

it('saves reusable peers and checkpoints one league population per batch', function (): void {
    $day = \App\Models\NhlPregameContextRunDay::create(['run_id' => $this->run->id, 'game_date' => '2025-10-20']);
    NhlPregameContextRunGame::create(['run_id' => $this->run->id, 'nhl_game_id' => 2025020010,
        'game_date' => '2025-10-20', 'sequence' => 1, 'state' => 'pending']);
    $builder = Mockery::mock(NhlPregameContextBuilder::class);
    $builder->shouldReceive('leagueResultRows')->twice()->andReturnUsing(function ($run, $game, string $type): \Generator {
        if ($type === 'player') {
            foreach ([101 => 10.0, 102 => 20.0, 103 => 20.0] as $id => $value) {
                yield ['entity_id' => $id, 'metric_key' => 'sat_per_60', 'window_key' => 'l20', 'strength' => 'ALL', 'value' => $value];
            }
        }
    });
    $service = new \App\Services\NhlPregameLeagueResults($builder);
    expect($service->buildNextAverages($this->run, $day))->toBeFalse()
        ->and($day->fresh()->league_cursor)->toBe(1);
    expect($service->buildNextAverages($this->run, $day->fresh()))->toBeTrue()
        ->and($day->fresh()->league_cursor)->toBe(2)
        ->and(DB::table('nhl_pregame_context_league_peers')->count())->toBe(3);
    $contexts = [];
    foreach ([101 => 10.0, 102 => 20.0, 103 => 20.0] as $id => $value) {
        $context = ($this->playerContext)(2025020010, $id);
        DB::table('nhl_player_game_pregame_contexts')->where('id', $context)->update(['source_cutoff_at' => '2025-10-20 19:00:00']);
        ($this->contextMetric)('nhl_player_game_pregame_context_metrics', 'player_context_id', $context, 'sat_per_60', $value);
        $contexts[] = $context;
    }
    $service->attachGameComparisons($this->run, $day->fresh(), 2025020010);
    $metrics = DB::table('nhl_player_game_pregame_context_metrics')->whereIn('player_context_id', $contexts)->orderBy('player_context_id')->get();
    expect($metrics->pluck('league_rank')->map(fn ($value): int => (int) $value)->all())->toBe([3, 1, 1])
        ->and((float) $metrics[0]->league_average)->toBe(16.666667)
        ->and($metrics->pluck('value')->map(fn ($value): float => (float) $value)->all())->toBe([10.0, 20.0, 20.0]);
    $service->releaseDayPeers($day);
    expect(DB::table('nhl_pregame_context_league_peers')->count())->toBe(0)
        ->and(DB::table('nhl_pregame_context_league_averages')->count())->toBe(1)
        ->and(DB::table('nhl_player_game_pregame_context_metrics')->whereIn('player_context_id', $contexts)->whereNotNull('league_rank')->count())->toBe(3);
});

it('rolls back a cancelled population without advancing its league checkpoint', function (): void {
    $day = \App\Models\NhlPregameContextRunDay::create(['run_id' => $this->run->id, 'game_date' => '2025-10-20']);
    NhlPregameContextRunGame::create(['run_id' => $this->run->id, 'nhl_game_id' => 2025020010,
        'game_date' => '2025-10-20', 'sequence' => 1, 'state' => 'pending']);
    $builder = Mockery::mock(NhlPregameContextBuilder::class);
    $builder->shouldReceive('leagueResultRows')->once()->andReturnUsing(function (): \Generator {
        yield ['entity_id' => 10, 'metric_key' => 'sat_per_60', 'window_key' => 'l20', 'strength' => 'ALL', 'value' => 10.0];
    });
    $service = new \App\Services\NhlPregameLeagueResults($builder);
    expect(fn () => $service->buildNextAverages($this->run, $day, fn (): bool => true))
        ->toThrow(\App\Exceptions\NhlPregameContextRunStopped::class);
    expect($day->fresh()->league_cursor)->toBe(0)
        ->and(DB::table('nhl_pregame_context_league_peers')->count())->toBe(0)
        ->and(DB::table('nhl_pregame_context_league_averages')->count())->toBe(0);
});

it('replaces replayed peer rows without changing average identities', function (): void {
    $day = \App\Models\NhlPregameContextRunDay::create(['run_id' => $this->run->id, 'game_date' => '2025-10-20']);
    NhlPregameContextRunGame::create(['run_id' => $this->run->id, 'nhl_game_id' => 2025020010,
        'game_date' => '2025-10-20', 'sequence' => 1, 'state' => 'pending']);
    $builder = Mockery::mock(NhlPregameContextBuilder::class);
    $builder->shouldReceive('leagueResultRows')->twice()->andReturnUsing(function (): \Generator {
        yield ['entity_id' => 10, 'metric_key' => 'sat_per_60', 'window_key' => 'l20', 'strength' => 'ALL', 'value' => 10.0];
    });
    $service = new \App\Services\NhlPregameLeagueResults($builder);
    $service->buildNextAverages($this->run, $day);
    $average = DB::table('nhl_pregame_context_league_averages')->value('id');
    $day->update(['league_cursor' => 0]);
    $service->buildNextAverages($this->run, $day->fresh());
    expect(DB::table('nhl_pregame_context_league_averages')->value('id'))->toBe($average)
        ->and(DB::table('nhl_pregame_context_league_peers')->count())->toBe(1);
});

it('keeps zero-average percentage deltas unavailable using persisted peers', function (): void {
    $day = \App\Models\NhlPregameContextRunDay::create(['run_id' => $this->run->id, 'game_date' => '2025-10-20']);
    $average = DB::table('nhl_pregame_context_league_averages')->insertGetId([
        'run_id' => $this->run->id, 'run_day_id' => $day->id, 'game_date' => '2025-10-20',
        'source_cutoff_at' => '2025-10-20 19:00:00', 'entity_type' => 'player',
        'metric_key' => 'sat_per_60', 'window_key' => 'l20', 'strength' => 'ALL', 'league_average' => 0, 'rank_population' => 1,
    ]);
    DB::table('nhl_pregame_context_league_peers')->insert(['league_average_id' => $average, 'entity_id' => 101, 'value' => 0, 'league_rank' => 1]);
    $context = ($this->playerContext)(2025020010, 101);
    DB::table('nhl_player_game_pregame_contexts')->where('id', $context)->update(['source_cutoff_at' => '2025-10-20 19:00:00']);
    ($this->contextMetric)('nhl_player_game_pregame_context_metrics', 'player_context_id', $context, 'sat_per_60', 0);
    $builder = Mockery::mock(NhlPregameContextBuilder::class);
    $builder->shouldNotReceive('leagueResultRows');
    (new \App\Services\NhlPregameLeagueResults($builder))->attachGameComparisons($this->run, $day, 2025020010);
    $metric = DB::table('nhl_player_game_pregame_context_metrics')->where('player_context_id', $context)->first();
    expect($metric->percentage_difference)->toBeNull()->and((int) $metric->league_rank)->toBe(1);
});

it('excludes the target peer when a context value differs from its saved peer value', function (): void {
    $day = \App\Models\NhlPregameContextRunDay::create(['run_id' => $this->run->id, 'game_date' => '2025-10-20']);
    $average = DB::table('nhl_pregame_context_league_averages')->insertGetId([
        'run_id' => $this->run->id, 'run_day_id' => $day->id, 'game_date' => '2025-10-20',
        'source_cutoff_at' => '2025-10-20 19:00:00', 'entity_type' => 'player',
        'metric_key' => 'sat_per_60', 'window_key' => 'l20', 'strength' => 'ALL', 'league_average' => 20, 'rank_population' => 3,
    ]);
    foreach ([101 => 30, 102 => 20, 103 => 10] as $id => $value) {
        DB::table('nhl_pregame_context_league_peers')->insert(['league_average_id' => $average,
            'entity_id' => $id, 'value' => $value, 'league_rank' => (40 - $value) / 10]);
    }
    $context = ($this->playerContext)(2025020010, 101);
    DB::table('nhl_player_game_pregame_contexts')->where('id', $context)->update(['source_cutoff_at' => '2025-10-20 19:00:00']);
    ($this->contextMetric)('nhl_player_game_pregame_context_metrics', 'player_context_id', $context, 'sat_per_60', 15);
    DB::table('nhl_player_game_pregame_context_metrics')->where('player_context_id', $context)
        ->update(['sample_games' => 8, 'exposure_seconds' => 900]);
    $builder = Mockery::mock(NhlPregameContextBuilder::class);
    $builder->shouldNotReceive('leagueResultRows');
    (new \App\Services\NhlPregameLeagueResults($builder))->attachGameComparisons($this->run, $day, 2025020010);
    $metric = DB::table('nhl_player_game_pregame_context_metrics')->where('player_context_id', $context)->first();
    expect((int) $metric->league_rank)->toBe(2)->and((float) $metric->value)->toBe(15.0)
        ->and((int) $metric->sample_games)->toBe(8)->and((float) $metric->exposure_seconds)->toBe(900.0)
        ->and((float) $metric->percentage_difference)->toBe(-25.0);
});

it('stores each teams rest and opponents rest with normalized pregame metrics', function (): void {
    ($this->game)(2025020009, '2025-10-19');
    DB::table('nhl_games')->where('nhl_game_id', 2025020009)->update(['home_team_id' => 20, 'away_team_id' => 30]);
    app(NhlPregameContextBuilder::class)->build($this->run, 2025020010);
    $contexts = DB::table('nhl_team_game_pregame_contexts')->where('nhl_game_id', 2025020010)->get()->keyBy('nhl_team_id');
    $home = json_decode($contexts[10]->schedule_metrics, true, 512, JSON_THROW_ON_ERROR);
    $away = json_decode($contexts[20]->schedule_metrics, true, 512, JSON_THROW_ON_ERROR);
    expect($home['days_rest'])->toBe(9)->and($home['opponent_days_rest'])->toBe(0)
        ->and($away['days_rest'])->toBe(0)->and($away['opponent_days_rest'])->toBe(9);
    $opponentRest = DB::table('nhl_team_game_pregame_context_metrics')->where('team_context_id', $contexts[10]->id)
        ->where('window_key', 'pregame')->where('metric_key', 'opponent_days_rest')->where('measure_key', 'value')->value('value');
    expect((float) $opponentRest)->toBe(0.0);
    $playerTeamContext = DB::table('nhl_player_game_pregame_contexts')->where('nhl_game_id', 2025020010)
        ->where('nhl_team_id', 10)->value('team_context_id');
    expect((int) $playerTeamContext)->toBe((int) $contexts[10]->id);
});

it('leaves opponent rest unavailable without a prior completed game', function (): void {
    DB::table('nhl_games')->where('nhl_game_id', 2025020001)->update(['away_team_id' => 30]);
    ($this->game)(2025020011, '2025-10-19');
    DB::table('nhl_games')->where('nhl_game_id', 2025020011)->update(['home_team_id' => 20, 'away_team_id' => 30, 'game_state' => 'FUT']);
    ($this->game)(2025020012, '2025-10-21');
    DB::table('nhl_games')->where('nhl_game_id', 2025020012)->update(['home_team_id' => 20, 'away_team_id' => 30]);
    app(NhlPregameContextBuilder::class)->build($this->run, 2025020010);
    $schedule = json_decode(DB::table('nhl_team_game_pregame_contexts')->where('nhl_game_id', 2025020010)
        ->where('nhl_team_id', 10)->value('schedule_metrics'), true, 512, JSON_THROW_ON_ERROR);
    expect($schedule['opponent_days_rest'])->toBeNull()->and($schedule['days_rest'])->toBe(9);
});
