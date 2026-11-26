<?php

declare(strict_types=1);

use App\Events\NhlSatModelUpdated;
use App\Models\NhlExpectedGoalsModel;
use App\Models\NhlModelRun;
use App\Models\Role;
use App\Models\User;
use App\Services\NhlExpectedGoalsBackfiller;
use App\Services\NhlShotAttemptAnalysisBuckets;
use App\Services\NhlShotAttemptModelScorer;
use App\Services\NhlSatModelEntityProfileBuilder;
use App\Services\NhlPregameContextBuilder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-25 12:00:00 UTC'));
    Http::preventStrayRequests();
    Bus::fake();
    Event::fake([NhlSatModelUpdated::class]);
    $this->mock(\App\Services\PlatformState::class)->shouldReceive('seeded')->andReturn(true);
    $this->run = NhlModelRun::query()->create([
        'run_key' => 'strength-probability-training', 'name' => 'Strength training',
        'model_family' => 'sat', 'workflow_stage' => 'training', 'model_version' => 'strength-test',
        'status' => 'complete', 'train_season_ids' => ['20242025'], 'target_season_id' => '20252026',
    ]);
    foreach (['20242025' => 2024020001, '20252026' => 2025020001] as $season => $game) {
        DB::table('nhl_games')->insert([
            'nhl_game_id' => $game, 'season_id' => (string) $season, 'game_type' => 2,
            'game_date' => $game === 2024020001 ? '2025-01-01' : '2026-01-01',
            'game_dow' => 'Wed', 'game_month' => 'Jan', 'home_team_id' => 10, 'home_team_abbrev' => 'TOR',
            'away_team_id' => 8, 'away_team_abbrev' => 'MTL',
        ]);
    }
    $this->fact = function (string $strength, string $result, array $overrides = []): int {
        $game = (int) ($overrides['nhl_game_id'] ?? 2024020001);
        $play = DB::table('play_by_plays')->insertGetId([
            'nhl_game_id' => $game, 'period' => 1, 'seconds_in_game' => 120, 'type_desc_key' => $result,
        ]);

        return DB::table('nhl_shot_attempts_facts')->insertGetId([
            'play_by_play_id' => $play, 'nhl_game_id' => $game,
            'season_id' => $game === 2024020001 ? '20242025' : '20252026',
            'game_date' => '2025-01-01', 'event_type' => $result, 'attempt_result' => $result,
            'is_shot_attempt' => true, 'is_unblocked_attempt' => $result !== 'blocked-shot',
            'is_shot_on_goal' => in_array($result, ['goal', 'shot-on-goal'], true),
            'is_goal' => $result === 'goal', 'team_id' => 10, 'opponent_team_id' => 8,
            'shooter_player_id' => 101, 'goalie_player_id' => 201, 'shot_type_bucket' => 'wrist',
            'shot_distance' => 12, 'abs_shot_angle' => 5, 'period' => 1, 'period_type' => 'REG',
            'is_empty_net' => false, 'is_rush' => false, 'is_rebound' => false,
            'strength' => strtoupper($strength), 'strength_bucket' => strtolower($strength), ...$overrides,
        ]);
    };
    // Identical shot shape; only the strength and actual outcome distributions differ.
    foreach (['ev' => ['goal', 'shot-on-goal', 'shot-on-goal', 'missed-shot'],
        'pp' => ['goal', 'shot-on-goal', 'missed-shot', 'blocked-shot'],
        'pk' => ['goal', 'missed-shot', 'missed-shot', 'blocked-shot']] as $strength => $results) {
        foreach ($results as $result) {
            ($this->fact)($strength, $result);
        }
    }
    $this->train = function (string $target = 'shot_on_goal', int $prior = 0, bool $dryRun = false): NhlExpectedGoalsModel {
        app(NhlExpectedGoalsBackfiller::class)->trainBucketsForRun(
            $this->run, smoothingPriorAttempts: $prior, dryRun: $dryRun, predictionTarget: $target
        );

        return NhlExpectedGoalsModel::query()->where('model_run_id', $this->run->id)
            ->where('prediction_target', $target)->sole();
    };
    $this->baseline = fn (NhlExpectedGoalsModel $model, string $strength) => $model->buckets()
        ->where('strength', $strength)->where('fallback_level', 99)->sole();
    $this->admin = function (): User {
        $user = User::factory()->create();
        $role = Role::query()->firstOrCreate(['slug' => 'super-admin'], ['name' => 'Super Admin', 'level' => 99]);
        $user->roles()->attach($role->id, ['organization_id' => null]);

        return $user;
    };
    $this->lookupModel = function (): NhlExpectedGoalsModel {
        return NhlExpectedGoalsModel::query()->create([
            'name' => 'Lookup fixture', 'version' => 'v1', 'prediction_target' => 'goal',
            'feature_config' => ['fallback_levels' => [1 => ['shot_type_group'], 99 => ['baseline']]],
        ]);
    };
    $this->probabilityRow = function (NhlExpectedGoalsModel $model, string $strength, float $probability, bool $baseline = false): void {
        DB::table('nhl_expected_goals_model_buckets')->insert([
            'expected_goals_model_id' => $model->id, 'strength' => $strength,
            'bucket_key' => $baseline ? 'L99|baseline=league' : 'L01|shot_type_group=wrist',
            'fallback_level' => $baseline ? 99 : 1,
            'bucket_dimensions' => json_encode($baseline ? ['baseline' => 'league'] : ['shot_type_group' => 'wrist']),
            'attempts' => 100, 'goals' => (int) ($probability * 100),
            'raw_goal_rate' => $probability, 'smoothed_goal_probability' => $probability,
        ]);
    };
    $this->lookup = function (NhlExpectedGoalsModel $model, string $strength): ?object {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Production probability lookups use PostgreSQL LATERAL joins.');
        }
        $id = ($this->fact)($strength, 'shot-on-goal');
        $join = app(NhlShotAttemptAnalysisBuckets::class)->probabilityBucketJoinSql(
            $model->id, "'L01|shot_type_group=wrist'", 'selected_bucket', 'facts'
        );

        return DB::selectOne("SELECT selected_bucket.* FROM nhl_shot_attempts_facts facts {$join} WHERE facts.id = ?", [$id]);
    };
});

afterEach(function (): void {
    Http::assertNothingSent();
    $this->travelBack();
});

it('builds combined and three strength SAT baselines', function (): void {
    $model = ($this->train)();
    expect($model->buckets()->where('fallback_level', 99)->orderBy('strength')->pluck('strength')->all())
        ->toBe(['all', 'ev', 'pk', 'pp']);
});

it('uses all eligible attempts in the combined SAT baseline', function (): void {
    $row = ($this->baseline)(($this->train)(), 'all');
    expect($row->attempts)->toBe(12)->and($row->goals)->toBe(6);
});

it('calculates EV SOG probability from EV attempts only', function (): void {
    $row = ($this->baseline)(($this->train)(), 'ev');
    expect($row->attempts)->toBe(4)->and((float) $row->smoothed_goal_probability)->toBe(0.75);
});

it('calculates PP SOG probability from PP attempts only', function (): void {
    $row = ($this->baseline)(($this->train)(), 'pp');
    expect($row->attempts)->toBe(4)->and((float) $row->smoothed_goal_probability)->toBe(0.5);
});

it('calculates PK SOG probability from PK attempts only', function (): void {
    $row = ($this->baseline)(($this->train)(), 'pk');
    expect($row->attempts)->toBe(4)->and((float) $row->smoothed_goal_probability)->toBe(0.25);
});

it('keeps the same shot shape keys across strengths', function (): void {
    $model = ($this->train)();
    $rows = $model->buckets()->where('fallback_level', 1)->get();
    expect($rows->pluck('bucket_key')->unique())->toHaveCount(1)
        ->and($rows->pluck('strength')->unique())->toHaveCount(4);
});

it('builds separate SOG to goal baselines', function (): void {
    $model = ($this->train)('goal');
    expect(($this->baseline)($model, 'all')->attempts)->toBe(6)
        ->and(($this->baseline)($model, 'all')->goals)->toBe(3);
});

it('uses only EV shots on goal in the EV goal probability', function (): void {
    $row = ($this->baseline)(($this->train)('goal'), 'ev');
    expect($row->attempts)->toBe(3)->and((float) $row->smoothed_goal_probability)->toEqualWithDelta(1 / 3, 0.000001);
});

it('uses only PP shots on goal in the PP goal probability', function (): void {
    $row = ($this->baseline)(($this->train)('goal'), 'pp');
    expect($row->attempts)->toBe(2)->and((float) $row->smoothed_goal_probability)->toBe(0.5);
});

it('uses only PK shots on goal in the PK goal probability', function (): void {
    $row = ($this->baseline)(($this->train)('goal'), 'pk');
    expect($row->attempts)->toBe(1)->and((float) $row->smoothed_goal_probability)->toBe(1.0);
});

it('retains blocked attempts in SAT training', function (): void {
    ($this->fact)('pk', 'blocked-shot');
    expect(($this->baseline)(($this->train)(), 'pk')->attempts)->toBe(5);
});

it('excludes blocked attempts from SOG goal training', function (): void {
    ($this->fact)('pk', 'blocked-shot');
    expect(($this->baseline)(($this->train)('goal'), 'pk')->attempts)->toBe(1);
});

it('retains unknown shot types at their actual strength', function (): void {
    ($this->fact)('pk', 'goal', ['shot_type_bucket' => null]);
    expect(($this->baseline)(($this->train)('goal'), 'pk')->attempts)->toBe(2);
});

it('keeps Test outcomes out of every strength training sample', function (): void {
    ($this->fact)('pk', 'goal', ['nhl_game_id' => 2025020001]);
    expect(($this->baseline)(($this->train)('goal'), 'pk')->attempts)->toBe(1);
});

it('excludes empty net attempts from strength probabilities', function (): void {
    ($this->fact)('pk', 'goal', ['is_empty_net' => true]);
    expect(($this->baseline)(($this->train)(), 'pk')->attempts)->toBe(4);
});

it('excludes shootouts from strength probabilities', function (): void {
    ($this->fact)('pk', 'goal', ['period_type' => 'SO']);
    expect(($this->baseline)(($this->train)(), 'pk')->attempts)->toBe(4);
});

it('keeps unknown strengths combined without assigning them to PK', function (): void {
    ($this->fact)('unknown', 'goal');
    $model = ($this->train)();
    expect(($this->baseline)($model, 'all')->attempts)->toBe(13)
        ->and(($this->baseline)($model, 'pk')->attempts)->toBe(4);
});

it('accepts lowercase canonical strength values', function (): void {
    ($this->fact)('pk', 'goal', ['strength' => 'pk']);
    expect(($this->baseline)(($this->train)(), 'pk')->attempts)->toBe(5);
});

it('uses the valid strength bucket when primary strength is missing', function (): void {
    ($this->fact)('pk', 'goal', ['strength' => null]);
    expect(($this->baseline)(($this->train)(), 'pk')->attempts)->toBe(5);
});

it('smooths small PK buckets toward their same-strength shot type parent', function (): void {
    ($this->fact)('pk', 'missed-shot', ['shot_distance' => 65]);
    $model = ($this->train)('shot_on_goal', 100);
    $rows = $model->buckets()->where('strength', 'pk')->where('fallback_level', 1)->get();
    $row = $rows->firstWhere('attempts', 1);
    expect($row)->not->toBeNull()->and((float) $row->smoothed_goal_probability)
        ->toEqualWithDelta((0 + 0.2 * 100) / 101, 0.000001);
});

it('does not fabricate a baseline for a strength with no eligible sample', function (): void {
    DB::table('nhl_shot_attempts_facts')->where('strength', 'PK')->delete();
    $model = ($this->train)();
    expect($model->buckets()->where('strength', 'pk')->exists())->toBeFalse();
});

it('replaces prior buckets without duplicating rows when reevaluated', function (): void {
    $first = ($this->train)();
    $count = $first->buckets()->count();
    $again = ($this->train)();
    expect($again->id)->toBe($first->id)->and($again->buckets()->count())->toBe($count);
});

it('preserves the other target when SAT is reevaluated', function (): void {
    $sog = ($this->train)('goal');
    $before = $sog->buckets()->orderBy('id')->get()->toArray();
    ($this->train)();
    expect($sog->buckets()->orderBy('id')->get()->toArray())->toBe($before);
});

it('records strength training provenance', function (): void {
    $model = ($this->train)();
    expect(data_get($model->feature_config, 'strength_probabilities'))->toBe('strength_bucket_v1')
        ->and(data_get($model->feature_config, 'training_season_ids'))->toBe(['20242025'])
        ->and(data_get($model->metrics, 'strength_bucket_count'))->toBeGreaterThan(0);
});

it('does not write per attempt scores during evaluation', function (): void {
    ($this->train)();
    $this->assertDatabaseCount('nhl_shot_attempt_predictions', 0);
    $this->assertDatabaseCount('nhl_shot_attempt_model_scores', 0);
});

it('does not write model rows during a dry run', function (): void {
    app(NhlExpectedGoalsBackfiller::class)->trainBucketsForRun($this->run, dryRun: true, predictionTarget: 'shot_on_goal');
    $this->assertDatabaseCount('nhl_expected_goals_models', 0);
    $this->assertDatabaseCount('nhl_expected_goals_model_buckets', 0);
});

it('prefers an exact PK bucket over the combined bucket', function (): void {
    $model = ($this->lookupModel)();
    ($this->probabilityRow)($model, 'all', 0.1);
    ($this->probabilityRow)($model, 'pk', 0.3);
    expect((float) ($this->lookup)($model, 'pk')->smoothed_goal_probability)->toBe(0.3);
});

it('prefers a PK baseline over a combined exact bucket when the PK shape is absent', function (): void {
    $model = ($this->lookupModel)();
    ($this->probabilityRow)($model, 'all', 0.1);
    ($this->probabilityRow)($model, 'pk', 0.2, true);
    expect((float) ($this->lookup)($model, 'pk')->smoothed_goal_probability)->toBe(0.2);
});

it('falls back to a combined exact bucket for a legacy model', function (): void {
    $model = ($this->lookupModel)();
    ($this->probabilityRow)($model, 'all', 0.1);
    expect((float) ($this->lookup)($model, 'pk')->smoothed_goal_probability)->toBe(0.1);
});

it('falls back to the combined baseline when no shape is available', function (): void {
    $model = ($this->lookupModel)();
    ($this->probabilityRow)($model, 'all', 0.12, true);
    expect((float) ($this->lookup)($model, 'pk')->smoothed_goal_probability)->toBe(0.12);
});

it('does not borrow a PP probability for a PK attempt', function (): void {
    $model = ($this->lookupModel)();
    ($this->probabilityRow)($model, 'pp', 0.4);
    expect(($this->lookup)($model, 'pk')->id)->toBeNull();
});

it('keeps a measured zero probability instead of falling back', function (): void {
    $model = ($this->lookupModel)();
    ($this->probabilityRow)($model, 'all', 0.3);
    ($this->probabilityRow)($model, 'pk', 0.0);
    expect((float) ($this->lookup)($model, 'pk')->smoothed_goal_probability)->toBe(0.0);
});

it('scores every attempt once using its own strength', function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Production scorer requires PostgreSQL.');
    }
    $model = ($this->train)();
    app(NhlShotAttemptModelScorer::class)->scoreGame($model, 2024020001, '20242025', 2, 0.1);
    $rows = DB::table('nhl_shot_attempt_model_scores as scores')
        ->join('nhl_shot_attempts_facts as f', 'f.id', '=', 'scores.shot_attempt_fact_id')
        ->where('scores.expected_goals_model_id', $model->id)
        ->get(['f.strength', 'scores.probability', 'scores.matched_bucket_payload']);
    expect($rows)->toHaveCount(12);
    foreach (['EV' => 0.75, 'PP' => 0.5, 'PK' => 0.25] as $strength => $probability) {
        expect($rows->where('strength', $strength))->toHaveCount(4);
        foreach ($rows->where('strength', $strength) as $row) {
            expect((float) $row->probability)->toBe($probability)
                ->and(json_decode($row->matched_bucket_payload, true)['strength'])->toBe(strtolower($strength));
        }
    }
});

it('uses the same strength rules when backfilling historical attempts', function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Historical scoring requires PostgreSQL.');
    }
    $result = app(NhlExpectedGoalsBackfiller::class)->backfill(
        '20242025', version: 'strength-backfill', smoothingPriorAttempts: 0, predictionTarget: 'shot_on_goal'
    );
    $rows = DB::table('nhl_shot_attempt_predictions as predictions')
        ->join('nhl_shot_attempts_facts as f', 'f.id', '=', 'predictions.shot_attempt_fact_id')
        ->where('predictions.expected_goals_model_id', $result['model_id'])->get(['f.strength', 'predictions.xg']);
    expect($rows)->toHaveCount(12);
    foreach (['EV' => 0.75, 'PP' => 0.5, 'PK' => 0.25] as $strength => $probability) {
        foreach ($rows->where('strength', $strength) as $row) {
            expect((float) $row->xg)->toBe($probability);
        }
    }
});

it('builds profiles without multiplying attempts across probability strengths', function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Production profile builder requires PostgreSQL.');
    }
    $playerId = DB::table('players')->insertGetId([
        'nhl_id' => 101, 'first_name' => 'Test', 'last_name' => 'Skater', 'full_name' => 'Test Skater',
        'position' => 'C', 'pos_type' => 'F',
    ]);
    DB::table('nhl_game_summaries')->insert([
        'nhl_game_id' => 2024020001, 'nhl_player_id' => 101, 'nhl_team_id' => 10, 'toi' => 1200,
    ]);
    foreach (['EV' => 900, 'PP' => 200, 'PK' => 100] as $strength => $toi) {
        DB::table('nhl_player_game_strength_summaries')->insert([
            'nhl_game_id' => 2024020001, 'player_id' => $playerId, 'nhl_player_id' => 101, 'team_id' => 10,
            'strength' => $strength, 'toi' => $toi,
        ]);
    }
    $sat = ($this->train)();
    $sog = ($this->train)('goal');
    app(NhlSatModelEntityProfileBuilder::class)->buildEntity($this->run, $sat, $sog, 'skater_offense', 'skater_offense:101');
    $rows = DB::table('nhl_sat_model_entity_profile_buckets')->where('model_run_id', $this->run->id)
        ->where('profile_type', 'skater_offense')->where('entity_key', 'skater_offense:101')->get();
    expect((int) $rows->where('strength', 'all')->sum('source_sat'))->toBe(12)
        ->and((float) $rows->where('strength', 'all')->sum('expected_sog'))->toBe(6.0)
        ->and((float) $rows->where('strength', 'all')->sum('expected_goals'))->toEqualWithDelta(3.0, 0.0001);
    foreach (['ev', 'pp', 'pk'] as $strength) {
        expect((int) $rows->where('strength', $strength)->sum('source_sat'))->toBe(4);
    }
    foreach ($rows as $row) {
        $sources = json_decode($row->metadata, true)['probability_sources'];
        expect($sources['sat']['model_id'])->toBe($sat->id)
            ->and($sources['sog']['model_id'])->toBe($sog->id)
            ->and($sources['sat']['smoothing_policy'])->toBe(NhlExpectedGoalsBackfiller::SMOOTHING_POLICY);
    }
    $filter = app(NhlPregameContextBuilder::class)->evaluationBucketFilter($this->run->id, ['20242025']);
    expect($filter['keys'])->toHaveCount(1)->and($filter['keys'])->toBe(array_values(array_unique($filter['keys'])));
});

it('blocks guests from evaluating probability buckets', function (): void {
    $this->post(route('admin.nhl-sat-models.train', $this->run), ['evaluation' => 'sat'])->assertRedirect(route('login'));
});

it('blocks unprivileged users from evaluating probability buckets', function (): void {
    $this->actingAs(User::factory()->create())->postJson(route('admin.nhl-sat-models.train', $this->run), ['evaluation' => 'sat'])
        ->assertForbidden();
});

it('allows an admin to evaluate SAT then read strength rows', function (): void {
    $this->actingAs(($this->admin)())->postJson(route('admin.nhl-sat-models.train', $this->run), ['evaluation' => 'sat'])
        ->assertOk();
    $model = NhlExpectedGoalsModel::query()->where('model_run_id', $this->run->id)->where('prediction_target', 'shot_on_goal')->sole();
    $this->assertDatabaseHas('nhl_expected_goals_model_buckets', ['expected_goals_model_id' => $model->id, 'strength' => 'pk']);
    $this->get(route('admin.nhl-sat-models.buckets', ['run' => $this->run, 'target' => 'shot_on_goal']))
        ->assertOk()->assertSee('All strengths')->assertSee('>pk<', false);
});

it('allows an admin to evaluate SOG then read strength rows', function (): void {
    $this->actingAs(($this->admin)())->postJson(route('admin.nhl-sat-models.train', $this->run), ['evaluation' => 'sog'])
        ->assertOk();
    $model = NhlExpectedGoalsModel::query()->where('model_run_id', $this->run->id)->where('prediction_target', 'goal')->sole();
    $this->assertDatabaseHas('nhl_expected_goals_model_buckets', ['expected_goals_model_id' => $model->id, 'strength' => 'pk']);
    $this->get(route('admin.nhl-sat-models.buckets', ['run' => $this->run, 'target' => 'goal']))
        ->assertOk()->assertSee('All strengths')->assertSee('>pk<', false);
});

it('blocks guests from reading admin probability buckets', function (): void {
    $this->get(route('admin.nhl-sat-models.buckets', $this->run))->assertRedirect(route('login'));
});

it('blocks unprivileged users from reading admin probability buckets', function (): void {
    $this->actingAs(User::factory()->create())->get(route('admin.nhl-sat-models.buckets', $this->run))->assertForbidden();
});

it('stores contextual smoothing provenance and a bucket revision signature', function (): void {
    $model = ($this->train)('shot_on_goal', 100);
    expect(data_get($model->feature_config, 'probability_smoothing.policy'))->toBe(NhlExpectedGoalsBackfiller::SMOOTHING_POLICY)
        ->and(data_get($model->feature_config, 'probability_smoothing.parent_factor'))->toBe('shot_type_group')
        ->and(data_get($model->feature_config, 'probability_smoothing.bucket_signature'))->toHaveLength(64);
});

it('stores distance as the goal conversion smoothing parent', function (): void {
    $model = ($this->train)('goal', 100);
    expect(data_get($model->feature_config, 'probability_smoothing.parent_factor'))->toBe('distance_group');
});

it('changes the evaluation signature when evidence changes within the same clock second', function (): void {
    $first = ($this->train)();
    ($this->fact)('pk', 'goal');
    $second = ($this->train)();
    expect($first->id)->toBe($second->id)
        ->and(data_get($first->feature_config, 'probability_smoothing.bucket_signature'))
        ->not->toBe(data_get($second->feature_config, 'probability_smoothing.bucket_signature'));
});

it('marks existing profiles outdated after evaluation and exposes that through HTTP', function (): void {
    $sat = ($this->train)();
    foreach (['skater_offense', 'goalie_faced'] as $type) {
        DB::table('nhl_sat_model_entity_profile_buckets')->insert([
            'model_run_id' => $this->run->id, 'sat_expected_goals_model_id' => $sat->id,
            'source_season_ids' => '["20242025"]', 'profile_type' => $type,
            'entity_key' => $type . ':101', 'matched_bucket_key' => 'L01|wrist',
            'fallback_level' => 1, 'bucket_dimensions' => '{}',
        ]);
    }
    $this->actingAs(($this->admin)())->postJson(route('admin.nhl-sat-models.train', $this->run), ['evaluation' => 'sat'])->assertOk();
    expect(data_get($this->run->fresh()->metrics, 'profile_freshness.skater_offense.outdated'))->toBeTrue()
        ->and(data_get($this->run->fresh()->metrics, 'profile_freshness.goalie_faced.outdated'))->toBeTrue();
    $this->get(route('admin.nhl-sat-models.index'))->assertOk()->assertSee('Profiles outdated')->assertSee('Skaters, Goalies');
    expect(DB::table('nhl_sat_model_entity_profile_buckets')->where('model_run_id', $this->run->id)->count())->toBe(2);
});

it('does not label an unbuilt profile type outdated', function (): void {
    ($this->train)();
    expect(data_get($this->run->fresh()->metrics, 'profile_freshness'))->toBeNull();
});

it('does not invalidate profiles during a dry run evaluation', function (): void {
    ($this->train)();
    $this->run->update(['metrics' => ['profile_freshness' => ['skater_offense' => ['outdated' => false]]]]);
    ($this->train)('shot_on_goal', 100, true);
    expect(data_get($this->run->fresh()->metrics, 'profile_freshness.skater_offense.outdated'))->toBeFalse();
});

it('keeps the bucket signature stable for identical evidence and smoothing settings', function (): void {
    $first = ($this->train)('shot_on_goal', 100);
    $second = ($this->train)('shot_on_goal', 100);
    expect(data_get($first->feature_config, 'probability_smoothing.bucket_signature'))
        ->toBe(data_get($second->feature_config, 'probability_smoothing.bucket_signature'));
});
