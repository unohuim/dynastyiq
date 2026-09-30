<?php

declare(strict_types=1);

use App\Models\NhlExpectedGoalsModel;
use App\Models\NhlModelRun;
use App\Models\Role;
use App\Models\User;
use App\Services\NhlExpectedGoalsBackfiller;
use App\Services\NhlSatModelEntityProfileBuilder;
use App\Services\NhlSatModelEntityRateComparisonBuilder;
use App\Services\NhlSatModelEntityRateProjectionBuilder;
use App\Services\NhlShotAttemptModelScorer;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->travelTo(\Illuminate\Support\Carbon::parse('2026-09-30 12:00:00 UTC'));
    Http::preventStrayRequests();
    Bus::fake();
    Event::fake([\App\Events\NhlSatModelUpdated::class]);
    $this->mock(\App\Services\PlatformState::class)->shouldReceive('seeded')->andReturn(true);
    $this->run = NhlModelRun::query()->create([
        'run_key' => 'unknown-shot-types', 'name' => 'Unknown shot types', 'model_family' => 'sat',
        'workflow_stage' => 'training', 'model_version' => 'unknown_test', 'status' => 'complete',
        'train_season_ids' => ['20242025'], 'target_season_id' => '20252026',
    ]);
    DB::table('players')->insert([
        'nhl_id' => 101, 'first_name' => 'Unknown', 'last_name' => 'Shooter', 'full_name' => 'Unknown Shooter',
        'position' => 'C', 'pos_type' => 'F', 'dob' => '1998-01-01',
    ]);
    foreach (['20242025' => 2024020001, '20252026' => 2025020001] as $season => $game) {
        DB::table('nhl_games')->insert([
            'nhl_game_id' => $game, 'season_id' => (string) $season, 'game_type' => 2,
            'game_date' => $season === 20242025 ? '2025-01-01' : '2026-01-01',
            'game_dow' => 'Wed', 'game_month' => 'Jan', 'home_team_id' => 10, 'home_team_abbrev' => 'TOR',
            'away_team_id' => 20, 'away_team_abbrev' => 'MTL',
        ]);
        DB::table('nhl_game_summaries')->insert([
            'nhl_game_id' => $game, 'nhl_player_id' => 101, 'nhl_team_id' => 10, 'toi' => 1200,
        ]);
    }
    $this->fact = function (array $values = []): int {
        $gameId = (int) ($values['nhl_game_id'] ?? 2024020001);
        $playId = DB::table('play_by_plays')->insertGetId([
            'nhl_game_id' => $gameId, 'period' => 1, 'seconds_in_game' => 120,
            'type_desc_key' => $values['event_type'] ?? 'blocked-shot',
        ]);

        return DB::table('nhl_shot_attempts_facts')->insertGetId([
            'play_by_play_id' => $playId, 'nhl_game_id' => $gameId,
            'season_id' => $gameId === 2024020001 ? '20242025' : '20252026',
            'game_date' => '2025-01-01', 'event_type' => 'blocked-shot', 'attempt_result' => 'blocked_shot',
            'is_shot_attempt' => true, 'is_unblocked_attempt' => false, 'is_shot_on_goal' => false,
            'is_goal' => false, 'team_id' => 10, 'opponent_team_id' => 20, 'shooter_player_id' => 101,
            'goalie_player_id' => 201, 'shot_type_bucket' => 'unknown', 'shot_distance' => 22,
            'abs_shot_angle' => 10, 'period' => 1, 'period_type' => 'REG', 'is_empty_net' => false,
            'is_rush' => false, 'is_rebound' => false, 'strength_bucket' => 'ev', ...$values,
        ]);
    };
    $this->goal = ['event_type' => 'goal', 'attempt_result' => 'goal', 'is_unblocked_attempt' => true,
        'is_shot_on_goal' => true, 'is_goal' => true];
    $this->model = NhlExpectedGoalsModel::query()->create([
        'model_run_id' => $this->run->id, 'name' => 'SAT fixture',
        'version' => 'unknown_test__run_' . $this->run->id, 'prediction_target' => 'shot_on_goal',
        'feature_config' => ['fallback_levels' => [1 => ['shot_type_group'], 99 => ['baseline']]],
    ]);
    foreach (['unknown' => 0.1, 'wrist' => 0.7] as $type => $probability) {
        DB::table('nhl_expected_goals_model_buckets')->insert([
            'expected_goals_model_id' => $this->model->id, 'bucket_key' => 'L01|shot_type_group=' . $type,
            'fallback_level' => 1, 'bucket_dimensions' => json_encode(['shot_type_group' => $type]),
            'attempts' => 10, 'goals' => (int) round($probability * 10), 'raw_goal_rate' => $probability,
            'smoothed_goal_probability' => $probability,
        ]);
    }
    $this->profiles = function (): void {
        app(NhlSatModelEntityProfileBuilder::class)->buildEntity(
            $this->run, $this->model, null, 'skater_offense', 'skater_offense:101'
        );
    };
    $this->profileRows = fn () => DB::table('nhl_sat_model_entity_profile_buckets')
        ->where('model_run_id', $this->run->id)->where('profile_type', 'skater_offense');
    $this->evaluator = app(NhlExpectedGoalsBackfiller::class);
    $this->admin = function (): User {
        $user = User::factory()->create();
        $role = Role::query()->firstOrCreate(['slug' => 'super-admin'], ['name' => 'Super Admin', 'level' => 99]);
        $user->roles()->attach($role->id, ['organization_id' => null]);

        return $user;
    };
});

afterEach(function (): void {
    Http::assertNothingSent();
    $this->travelBack();
});

it('includes blocked unknown attempts in SAT eligibility', function (): void {
    ($this->fact)();
    expect($this->evaluator->trainingEligibilityCounts(['20242025']))
        ->toMatchArray(['total' => 1, 'eligible' => 1, 'excluded' => 0]);
});

it('includes null and blank shot types in SAT eligibility', function (): void {
    ($this->fact)(['shot_type_bucket' => null]);
    ($this->fact)(['shot_type_bucket' => '']);
    expect($this->evaluator->trainingEligibilityCounts(['20242025'])['eligible'])->toBe(2);
});

it('continues including known shot types beside unknown types', function (): void {
    ($this->fact)();
    ($this->fact)(['shot_type_bucket' => 'wrist']);
    expect($this->evaluator->trainingEligibilityCounts(['20242025'])['eligible'])->toBe(2);
});

it('continues excluding empty net attempts regardless of shot type', function (): void {
    ($this->fact)(['is_empty_net' => true]);
    expect($this->evaluator->trainingEligibilityCounts(['20242025']))
        ->toMatchArray(['total' => 1, 'eligible' => 0, 'excluded' => 1]);
});

it('continues excluding shootouts regardless of shot type', function (): void {
    ($this->fact)(['period_type' => 'SO']);
    expect($this->evaluator->trainingEligibilityCounts(['20242025'])['eligible'])->toBe(0);
});

it('does not include held out unknown attempts in training counts', function (): void {
    ($this->fact)(['nhl_game_id' => 2025020001]);
    expect($this->evaluator->trainingEligibilityCounts(['20242025'])['total'])->toBe(0);
});

it('includes unknown goals in SOG evaluation without admitting blocked attempts', function (): void {
    ($this->fact)();
    ($this->fact)($this->goal);
    expect($this->evaluator->sogTrainingEligibilityCounts(['20242025']))
        ->toMatchArray(['total' => 1, 'eligible' => 1, 'excluded' => 0]);
});

it('includes null and blank SOG types but preserves the separate other exclusion', function (): void {
    foreach ([null, '', 'other'] as $type) {
        ($this->fact)([...$this->goal, 'shot_type_bucket' => $type]);
    }
    expect($this->evaluator->sogTrainingEligibilityCounts(['20242025']))
        ->toMatchArray(['total' => 3, 'eligible' => 2, 'excluded' => 1]);
});

it('trains SAT with unknown outcomes and records the actual inclusion policy', function (): void {
    ($this->fact)();
    ($this->fact)($this->goal);
    $result = $this->evaluator->trainBucketsForRun($this->run, predictionTarget: 'shot_on_goal');
    expect($result['training_attempts'])->toBe(2)->and($result['training_successes'])->toBe(1);
    $model = NhlExpectedGoalsModel::query()->where('name', NhlExpectedGoalsBackfiller::MODEL_NAME)->sole();
    expect(data_get($model->training_filters, 'shot_type_bucket'))->toBe('include_unknown')
        ->and(data_get($model->feature_config, 'excluded_training_values.shot_type_bucket'))->toBe([]);
    $this->assertDatabaseCount('nhl_shot_attempt_predictions', 0);
});

it('trains SOG using unknown goals as goals rather than guessed blocks', function (): void {
    ($this->fact)();
    ($this->fact)($this->goal);
    $result = $this->evaluator->trainBucketsForRun($this->run, predictionTarget: 'goal');
    expect($result['training_attempts'])->toBe(1)->and($result['training_successes'])->toBe(1);
    $model = NhlExpectedGoalsModel::query()->where('name', NhlExpectedGoalsBackfiller::MODEL_NAME)->sole();
    expect(data_get($model->training_filters, 'shot_type_bucket'))->toBe('exclude_other');
});

it('keeps held out goals out of trained SAT probabilities', function (): void {
    ($this->fact)();
    ($this->fact)([...$this->goal, 'nhl_game_id' => 2025020001]);
    $result = $this->evaluator->trainBucketsForRun($this->run, predictionTarget: 'shot_on_goal');
    expect($result['training_attempts'])->toBe(1)->and($result['training_successes'])->toBe(0);
});

it('backfills unknown attempts through the existing probability buckets', function (): void {
    $factId = ($this->fact)();
    ($this->fact)([...$this->goal, 'shot_type_bucket' => 'wrist']);
    $this->evaluator->backfill('20242025', version: 'unknown_backfill', minimumBucketAttempts: 0, predictionTarget: 'shot_on_goal');
    $row = DB::table('nhl_shot_attempt_predictions')->where('shot_attempt_fact_id', $factId)->sole();
    expect((bool) $row->is_scored)->toBeTrue()->and($row->exclusion_reason)->toBeNull()
        ->and($row->matched_bucket_key)->toContain('shot_type_group=unknown');
});

it('scores unknown attempts without assigning them to a known shot type', function (): void {
    $factId = ($this->fact)();
    app(NhlShotAttemptModelScorer::class)->scoreSeason($this->model, '20242025', 2, 0.1);
    $row = DB::table('nhl_shot_attempt_model_scores')->where('shot_attempt_fact_id', $factId)->sole();
    expect((bool) $row->is_scored)->toBeTrue()->and($row->exclusion_reason)->toBeNull()
        ->and($row->matched_bucket_key)->toBe('L01|shot_type_group=unknown');
});

it('retains empty net and shootout exclusions in model scoring', function (): void {
    $emptyId = ($this->fact)(['is_empty_net' => true]);
    $shootoutId = ($this->fact)(['period_type' => 'SO']);
    app(NhlShotAttemptModelScorer::class)->scoreSeason($this->model, '20242025', 2, 0.1);
    $this->assertDatabaseHas('nhl_shot_attempt_model_scores', ['shot_attempt_fact_id' => $emptyId, 'exclusion_reason' => 'empty_net']);
    $this->assertDatabaseHas('nhl_shot_attempt_model_scores', ['shot_attempt_fact_id' => $shootoutId, 'exclusion_reason' => 'shootout']);
});

it('replaces legacy unknown exclusions without duplicating scored attempts', function (): void {
    $factId = ($this->fact)();
    $scorer = app(NhlShotAttemptModelScorer::class);
    $scorer->scoreSeason($this->model, '20242025', 2, 0.1);
    DB::table('nhl_shot_attempt_model_scores')->where('shot_attempt_fact_id', $factId)
        ->update(['is_scored' => false, 'exclusion_reason' => 'unknown_shot_type']);
    $scorer->scoreSeason($this->model, '20242025', 2, 0.1);
    $this->assertDatabaseCount('nhl_shot_attempt_model_scores', 1);
    $this->assertDatabaseHas('nhl_shot_attempt_model_scores', ['shot_attempt_fact_id' => $factId, 'is_scored' => true, 'exclusion_reason' => null]);
});

it('invalidates cached unknown exclusions when refreshing summary scores', function (): void {
    $factId = ($this->fact)();
    $scorer = app(NhlShotAttemptModelScorer::class);
    $scorer->scoreSeason($this->model, '20242025', 2, 0.1);
    DB::table('nhl_shot_attempt_model_scores')->where('shot_attempt_fact_id', $factId)
        ->update(['is_scored' => false, 'exclusion_reason' => 'unknown_shot_type']);
    $scorer->refreshGameSummaryHighDangerSat($this->model, '20242025', 2, 0.1);
    $this->assertDatabaseHas('nhl_shot_attempt_model_scores', [
        'shot_attempt_fact_id' => $factId, 'is_scored' => true, 'exclusion_reason' => null,
    ]);
});

it('refreshes cached probabilities after the same model is retrained', function (): void {
    $factId = ($this->fact)();
    $scorer = app(NhlShotAttemptModelScorer::class);
    $scorer->scoreSeason($this->model, '20242025', 2, 0.1);
    DB::table('nhl_shot_attempt_model_scores')->where('shot_attempt_fact_id', $factId)
        ->update(['scored_at' => '2026-09-29 12:00:00']);
    $this->model->update(['trained_at' => now()]);
    DB::table('nhl_expected_goals_model_buckets')->where('expected_goals_model_id', $this->model->id)
        ->where('bucket_key', 'L01|shot_type_group=unknown')->update(['smoothed_goal_probability' => 0.2]);
    $scorer->refreshGameSummaryHighDangerSat($this->model, '20242025', 2, 0.1);
    $row = DB::table('nhl_shot_attempt_model_scores')->where('shot_attempt_fact_id', $factId)->sole();
    expect((float) $row->probability)->toBe(0.2);
});

it('discovers entities whose only attempts have unknown shot types', function (): void {
    ($this->fact)();
    $entities = app(NhlSatModelEntityProfileBuilder::class)->prepareBuild($this->run);
    expect(collect($entities)->where('profile_type', 'skater_offense')->pluck('entity_key')->all())
        ->toContain('skater_offense:101');
});

it('preserves actual SAT SOG and goals within the unknown profile bucket', function (): void {
    ($this->fact)();
    ($this->fact)($this->goal);
    ($this->profiles)();
    $row = ($this->profileRows)()->sole();
    expect($row->matched_bucket_key)->toBe('L01|shot_type_group=unknown')
        ->and((int) $row->source_sat)->toBe(2)->and((int) $row->source_sog)->toBe(1)
        ->and((int) $row->source_goals)->toBe(1)->and((float) $row->source_xsat_per_60)->toBe(6.0);
});

it('normalizes missing types into one profile bucket without losing attempts', function (): void {
    foreach (['unknown', null, ''] as $type) {
        ($this->fact)(['shot_type_bucket' => $type]);
    }
    ($this->profiles)();
    expect(($this->profileRows)()->count())->toBe(1)
        ->and((int) ($this->profileRows)()->sum('source_sat'))->toBe(3)
        ->and((int) ($this->profileRows)()->sum('source_sog'))->toBe(0);
});

it('keeps unknown and known shot type profiles distinct', function (): void {
    ($this->fact)();
    ($this->fact)(['shot_type_bucket' => 'wrist']);
    ($this->profiles)();
    expect(($this->profileRows)()->count())->toBe(2)
        ->and((float) ($this->profileRows)()->sum('source_profile_share'))->toBe(1.0);
});

it('builds unknown held out snapshots separately from training profiles', function (): void {
    ($this->fact)();
    ($this->fact)([...$this->goal, 'nhl_game_id' => 2025020001]);
    ($this->profiles)();
    app(NhlSatModelEntityProfileBuilder::class)->buildSeasonSnapshotEntity(
        $this->run, $this->model, null, 'skater_offense', 'skater_offense:101', '20252026'
    );
    expect((int) ($this->profileRows)()->sum('source_goals'))->toBe(0);
    $this->assertDatabaseHas('nhl_sat_model_entity_test_profile_buckets', [
        'model_run_id' => $this->run->id, 'test_season_id' => '20252026',
        'matched_bucket_key' => 'L01|shot_type_group=unknown', 'source_sat' => 1, 'source_goals' => 1,
    ]);
});

it('carries unknown bucket identity into rate projections', function (): void {
    ($this->fact)();
    ($this->profiles)();
    $builder = app(NhlSatModelEntityProfileBuilder::class);
    $builder->buildSeasonSnapshotEntity($this->run, $this->model, null, 'skater_offense', 'skater_offense:101', '20242025');
    app(NhlSatModelEntityRateProjectionBuilder::class)->buildEntity($this->run, 'skater_offense', 'skater_offense:101');
    $this->assertDatabaseHas('nhl_sat_model_entity_rate_projection_buckets', [
        'model_run_id' => $this->run->id, 'entity_key' => 'skater_offense:101',
        'matched_bucket_key' => 'L01|shot_type_group=unknown', 'source_sat' => 1, 'is_other_bucket' => false,
    ]);
});

it('counts unknown only games in held out comparisons', function (): void {
    ($this->fact)();
    ($this->fact)(['nhl_game_id' => 2025020001]);
    ($this->profiles)();
    foreach (['20242025', '20252026'] as $season) {
        app(NhlSatModelEntityProfileBuilder::class)->buildSeasonSnapshotEntity(
            $this->run, $this->model, null, 'skater_offense', 'skater_offense:101', $season
        );
    }
    app(NhlSatModelEntityRateProjectionBuilder::class)->buildEntity($this->run, 'skater_offense', 'skater_offense:101');
    app(NhlSatModelEntityRateComparisonBuilder::class)->buildEntity($this->run, 'skater_offense', 'skater_offense:101');
    $this->assertDatabaseHas('nhl_sat_model_entity_rate_comparison_aggregates', [
        'model_run_id' => $this->run->id, 'entity_key' => 'skater_offense:101', 'train_games' => 1, 'test_games' => 1,
    ]);
});

it('exposes unknown profiles after an authorized build request and persisted build', function (): void {
    ($this->fact)();
    $this->actingAs(($this->admin)())->postJson(route('admin.nhl-sat-models.profiles.build', $this->run))->assertOk();
    Bus::assertDispatched(\App\Jobs\BuildNhlSatModelEntityProfilesJob::class);
    ($this->profiles)();
    $this->assertDatabaseHas('nhl_sat_model_entity_profile_buckets', [
        'model_run_id' => $this->run->id, 'matched_bucket_key' => 'L01|shot_type_group=unknown', 'source_sat' => 1,
    ]);
    $this->get(route('admin.nhl-sat-models.profiles', $this->run))->assertOk()->assertSee('unknown');
});

it('blocks guests from profile build and inspection routes', function (): void {
    $this->postJson(route('admin.nhl-sat-models.profiles.build', $this->run))->assertUnauthorized();
    $this->getJson(route('admin.nhl-sat-models.profiles', $this->run))->assertUnauthorized();
    $this->getJson(route('admin.nhl-sat-models.profiles.training-drift', $this->run))->assertUnauthorized();
    $this->getJson(route('admin.nhl-shot-attempts.index', ['tab' => 'predictive']))->assertUnauthorized();
    Bus::assertNothingDispatched();
});

it('blocks ordinary users from profile build and inspection routes', function (): void {
    $this->actingAs(User::factory()->create());
    $this->postJson(route('admin.nhl-sat-models.profiles.build', $this->run))->assertForbidden();
    $this->getJson(route('admin.nhl-sat-models.profiles', $this->run))->assertForbidden();
    $this->getJson(route('admin.nhl-sat-models.profiles.training-drift', $this->run))->assertForbidden();
    $this->getJson(route('admin.nhl-shot-attempts.index', ['tab' => 'predictive']))->assertForbidden();
    Bus::assertNothingDispatched();
});

it('includes unknown only games in Training Drift per game rates', function (): void {
    ($this->fact)();
    $this->actingAs(($this->admin)())->postJson(route('admin.nhl-sat-models.profiles.build', $this->run))->assertOk();
    ($this->profiles)();
    app(NhlSatModelEntityProfileBuilder::class)->buildSeasonSnapshotEntity(
        $this->run, $this->model, null, 'skater_offense', 'skater_offense:101', '20242025'
    );
    $this->get(route('admin.nhl-sat-models.profiles.training-drift', $this->run))->assertOk()
        ->assertViewHas('drifts', fn ($rows): bool => (float) $rows->first()->train_sat === 1.0
            && (float) $rows->first()->latest_sat === 1.0);
});

it('shows unknown attempts and their true outcomes in predictive analysis', function (): void {
    ($this->fact)();
    ($this->fact)($this->goal);
    $this->actingAs(($this->admin)())->get(route('admin.nhl-shot-attempts.index', [
        'tab' => 'predictive', 'season_id' => '20242025', 'min_attempts' => 1,
    ]))->assertOk()->assertViewHas('predictiveRows', fn ($rows): bool => $rows->count() === 1
        && (int) $rows->first()->attempts === 2
        && (int) $rows->first()->shots_on_goal === 1
        && (int) $rows->first()->goals === 1);
});
