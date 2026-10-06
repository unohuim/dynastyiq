<?php

declare(strict_types=1);

use App\Models\NhlModelRun;
use App\Services\NhlHistoricalPredictionService;
use App\Services\NhlSatModelPredictionService;
use App\Services\NhlSatModelEntityRateProjectionBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->travelTo(\Illuminate\Support\Carbon::parse('2026-09-28 12:00:00 UTC'));
    $this->run = NhlModelRun::query()->create([
        'run_key' => 'prediction-pair', 'name' => 'Prediction pair', 'model_family' => 'sat',
        'workflow_stage' => 'training', 'model_version' => 'test', 'status' => 'complete',
        'train_season_ids' => ['20232024', '20242025'], 'target_season_id' => '20252026',
        'metrics' => ['rate_projections_completed_at' => '2026-09-27T12:00:00Z',
            'rate_projection_entities_queued' => 1, 'rate_projection_entities_completed' => 1],
    ]);
    $this->goalModel = DB::table('nhl_expected_goals_models')->insertGetId([
        'model_run_id' => $this->run->id, 'name' => 'Goals', 'version' => 'test', 'prediction_target' => 'goal',
    ]);
    $this->identity = ['model_run_id' => $this->run->id, 'source_season_ids' => '["20232024","20242025"]',
        'profile_type' => 'skater_offense', 'entity_key' => 'skater_offense:101', 'entity_id' => 101];
    $this->rate = function (array $values = []): void {
        DB::table('nhl_sat_model_entity_rate_projection_buckets')->insert([
            ...$this->identity, 'matched_bucket_key' => 'A', 'bucket_dimensions' => '{"distance_group":"d_005_010"}',
            'projected_xsat_per_60' => 12, 'sat_probability' => 0.5, 'goal_probability' => 0.1, ...$values,
        ]);
    };
    $this->toi = function (array $values = []): void {
        DB::table('nhl_sat_model_entity_toi_projections')->insert([
            ...$this->identity, 'projected_toi_per_game_seconds' => 900, ...$values,
        ]);
    };
    $this->profile = function (array $values = []): void {
        DB::table('nhl_sat_model_entity_profile_buckets')->insert([
            ...$this->identity, 'sat_expected_goals_model_id' => $this->goalModel,
            'matched_bucket_key' => 'A', 'fallback_level' => 1, 'bucket_dimensions' => '{}',
            'source_sat' => 100, 'source_sog' => 50, 'source_goals' => 2,
            'source_toi_seconds' => 3600, 'expected_sog' => 50, 'expected_goals' => 4,
            'confidence_score' => 0.5, ...$values,
        ]);
    };
    $this->service = app(NhlSatModelPredictionService::class);
    $this->player = ['nhl_player_id' => 101, 'player_name' => 'Skater', 'game_projected_toi_seconds' => 600,
        'projected_sat' => 4, 'projected_sog' => 2, 'projected_goals' => 0.2, 'projection_source' => 'nhl_projection'];
});

afterEach(function (): void {
    $this->travelBack();
});

it('multiplies model bucket rates by same-run TOI in seconds', function (): void {
    ($this->rate)();
    ($this->toi)();
    $input = $this->service->inputs($this->run->id)->get(101);
    expect($input['buckets']->sum('baseline_xsat'))->toBe(3.0)
        ->and($input['buckets']->sum('baseline_xsog'))->toBe(1.5)
        ->and($input['buckets']->sum('baseline_xgf'))->toEqualWithDelta(0.15, 0.000001);
});

it('uses projected bucket conversion ahead of personal historical conversion', function (): void {
    ($this->rate)(['projected_xsog_per_60' => 8, 'projected_xg_per_60' => 1.2]);
    ($this->toi)();
    ($this->profile)(['source_sog' => 80, 'source_goals' => 8]);
    $input = $this->service->inputs($this->run->id)->get(101);
    $bucket = $input['buckets']->first();
    expect($input['toi_seconds'])->toBe(900.0)
        ->and($bucket->baseline_xsat)->toBe(3.0)
        ->and($bucket->baseline_xsog)->toEqualWithDelta(2.0, 0.000001)
        ->and($bucket->baseline_xgf)->toEqualWithDelta(0.3, 0.000001)
        ->and($bucket->on_target_source)->toBe('projected_rate')
        ->and($bucket->finishing_source)->toBe('projected_rate');
});

it('falls back as a pair when model TOI is absent', function (): void {
    ($this->rate)();
    expect($this->service->inputs($this->run->id))->toBeEmpty();
    $result = $this->service->offense($this->run->id, [$this->player]);
    expect($result['players'][0]['bucket_projection_source'])->toBe('historical_fallback')
        ->and($result['players'][0]['game_projected_toi_seconds'])->toBe(600)
        ->and($result['buckets']->sum('baseline_xsat'))->toBe(4.0);
});

it('does not take model TOI when model rates are absent', function (): void {
    ($this->toi)();
    $result = $this->service->offense($this->run->id, [$this->player]);
    expect($result['players'][0]['game_projected_toi_seconds'])->toBe(600)
        ->and($result['players'][0]['model_projected_toi_per_game_seconds'])->toBeNull();
});

it('rejects zero model TOI without converting missing opportunity to zero production', function (): void {
    ($this->rate)();
    ($this->toi)(['projected_toi_per_game_seconds' => 0]);
    expect($this->service->inputs($this->run->id))->toBeEmpty();
});

it('keeps valid zero shot rates as genuine model predictions', function (): void {
    ($this->rate)(['projected_xsat_per_60' => 0]);
    ($this->toi)();
    $result = $this->service->offense($this->run->id, [$this->player]);
    expect($result['players'][0]['bucket_projection_source'])->toBe('sat_model')
        ->and($result['players'][0]['projected_sat'])->toBe(0.0);
});

it('rejects an entire player when one projected bucket rate is missing', function (): void {
    ($this->rate)();
    ($this->rate)(['matched_bucket_key' => 'B', 'projected_xsat_per_60' => null]);
    ($this->toi)();
    expect($this->service->inputs($this->run->id))->toBeEmpty();
});

it('uses personal conversion when unused bucket probabilities are invalid', function (string $field, float $value): void {
    ($this->rate)(['projected_xsog_per_60' => 8, 'projected_xg_per_60' => 1.2, $field => $value]);
    ($this->toi)();
    ($this->profile)(['source_sog' => 80, 'source_goals' => 8]);
    $input = $this->service->inputs($this->run->id)->get(101);
    expect($input['sog_rate'])->toEqualWithDelta(9.6, 0.000001)
        ->and($input['goal_rate'])->toEqualWithDelta(0.96, 0.000001);
})->with([['sat_probability', -0.1], ['sat_probability', 1.1], ['goal_probability', -0.1], ['goal_probability', 1.1]]);

it('does not combine TOI belonging to another model run', function (): void {
    ($this->rate)();
    $other = $this->run->replicate();
    $other->run_key = 'other-run';
    $other->save();
    ($this->toi)(['model_run_id' => $other->id]);
    expect($this->service->inputs($this->run->id))->toBeEmpty();
});

it('excludes playoff or defensive opportunity from the offensive model pair', function (array $values): void {
    ($this->rate)();
    ($this->toi)($values);
    expect($this->service->inputs($this->run->id))->toBeEmpty();
})->with([['game_type' => 3], ['profile_type' => 'skater_defense']]);

it('does not use partial TOI rebuild outputs', function (): void {
    ($this->rate)();
    ($this->toi)();
    $this->run->update(['metrics' => [...$this->run->metrics, 'toi_projections_started_at' => '2026-09-28T10:00:00Z']]);
    expect($this->service->inputs($this->run->id))->toBeEmpty();
});

it('selects finished models independently of their evaluation season', function (): void {
    ($this->rate)();
    expect($this->service->latestModelId())->toBe($this->run->id);
});

it('does not let an incomplete newer run displace a completed rate run', function (): void {
    ($this->rate)();
    $newer = $this->run->replicate();
    $newer->run_key = 'newer-incomplete';
    $newer->metrics = [];
    $newer->save();
    expect($this->service->latestModelId())->toBe($this->run->id);
});

it('requires the selected runs goal probability model', function (): void {
    ($this->rate)();
    DB::table('nhl_expected_goals_models')->where('id', $this->goalModel)->delete();
    expect($this->service->latestModelId())->toBeNull();
});

it('preserves legacy model bucket definitions and the Other tail', function (): void {
    ($this->rate)();
    ($this->rate)(['matched_bucket_key' => 'Other', 'is_other_bucket' => true, 'projected_xsat_per_60' => 4]);
    ($this->toi)();
    $buckets = $this->service->offense($this->run->id, [$this->player])['buckets'];
    expect($buckets->pluck('matched_bucket_key')->all())->toBe(['A', 'Other'])
        ->and($buckets->sum('baseline_xsat'))->toBe(4.0)
        ->and($buckets->first()->distance_group)->toBe('d_005_010');
});

it('sums model and historical players without replacing roster identities', function (): void {
    ($this->rate)();
    ($this->toi)();
    ($this->profile)(['entity_id' => 102, 'entity_key' => 'skater_offense:102']);
    $result = $this->service->offense($this->run->id, [$this->player, [...$this->player, 'nhl_player_id' => 102]]);
    expect(array_column($result['players'], 'nhl_player_id'))->toBe([101, 102])
        ->and(array_column($result['players'], 'bucket_projection_source'))->toBe(['sat_model', 'historical_fallback'])
        ->and($result['buckets']->sum('baseline_xsat'))->toEqualWithDelta(3 + 100 / 6, 0.000001);
});

it('preserves unresolved players and their existing peer estimates', function (): void {
    $result = $this->service->offense($this->run->id, [[...$this->player, 'nhl_player_id' => null, 'projection_source' => 'line_peer_average']]);
    expect($result['players'][0]['nhl_player_id'])->toBeNull()
        ->and($result['buckets']->first()->matched_bucket_key)->toBe('Other')
        ->and($result['players'][0]['projected_goals'])->toBe(0.2);
});

it('scales historical counts using historical exposure and projected opportunity', function (): void {
    ($this->profile)();
    $history = app(NhlHistoricalPredictionService::class);
    $rows = $history->project($history->profiles($this->run->id, 'skater_offense', [101])->get(101), 1800, $this->run->id);
    expect($rows->sum('baseline_xsat'))->toBe(50.0)->and($rows->sum('baseline_xgf'))->toBe(1.0);
});

it('does not read held-out snapshots as historical projection input', function (): void {
    ($this->profile)();
    $row = (array) DB::table('nhl_sat_model_entity_profile_buckets')->first();
    unset($row['id'], $row['flags']);
    DB::table('nhl_sat_model_entity_test_profile_buckets')->insert([
        ...$row, 'test_season_id' => '20252026', 'source_sat' => 999,
    ]);
    $rows = app(NhlHistoricalPredictionService::class)->profiles($this->run->id, 'skater_offense', [101]);
    expect((int) $rows->get(101)->first()->source_sat)->toBe(100);
});

it('uses goalies own same-bucket performance to reduce expected goals', function (): void {
    ($this->profile)(['profile_type' => 'goalie_faced', 'entity_key' => 'goalie_faced:900', 'entity_id' => 900]);
    $environment = collect([app(NhlHistoricalPredictionService::class)->bucket((object) [
        'matched_bucket_key' => 'A', 'bucket_dimensions' => [],
    ], 10, 5, 0.4, 'sat_model', $this->run->id)]);
    $bucket = $this->service->goalieBuckets($this->run->id, 900, $environment)->get('A');
    expect($bucket->projected_ga)->toEqualWithDelta(0.3, 0.000001)
        ->and($bucket->projected_gsax)->toEqualWithDelta(0.1, 0.000001);
});

it('uses neutral goalie response when no historical evidence exists', function (): void {
    $environment = collect([app(NhlHistoricalPredictionService::class)->bucket((object) [
        'matched_bucket_key' => 'A', 'bucket_dimensions' => [],
    ], 10, 5, 0.4, 'sat_model', $this->run->id)]);
    $bucket = $this->service->goalieBuckets($this->run->id, 999, $environment)->get('A');
    expect($bucket->projected_ga)->toBe(0.4)->and($bucket->goalie_skill_source)->toBe('neutral_missing_bucket');
});

it('does not apply the goalie confidence shrink twice', function (): void {
    ($this->profile)([
        'profile_type' => 'goalie_faced',
        'entity_key' => 'goalie_faced:900',
        'entity_id' => 900,
        'confidence_score' => 0.99,
    ]);
    $environment = collect([app(NhlHistoricalPredictionService::class)->bucket((object) [
        'matched_bucket_key' => 'A', 'bucket_dimensions' => [],
    ], 10, 5, 0.4, 'sat_model', $this->run->id)]);
    $bucket = $this->service->goalieBuckets($this->run->id, 900, $environment)->get('A');
    expect($bucket->source_confidence_score)->toBe(0.99)->and($bucket->confidence_score)->toBe(1.0);
});

it('feeds model buckets into matchup goals and counts full-game volume once', function (): void {
    ($this->rate)(['projected_xsog_per_60' => 8, 'projected_xg_per_60' => 1.2]);
    ($this->toi)();
    ($this->profile)(['profile_type' => 'goalie_faced', 'entity_key' => 'goalie_faced:900', 'entity_id' => 900]);
    DB::table('nhl_goalie_season_projections')->insert([
        'projection_version' => 'goalies', 'source_season_id' => '20242025', 'target_season_id' => '20262027',
        'goalie_player_id' => 900, 'target_team_abbrev' => 'BBB', 'projected_games' => 50,
        'projected_ev_xga' => 100, 'projected_ev_ga' => 100, 'projected_pk_xga' => 100,
        'projected_pk_ga' => 100, 'projected_pk_sata' => 500, 'projected_pk_soga' => 250,
    ]);
    $result = app(\App\Services\NhlProjectedTeamMatchupSimulator::class)->simulateWithRosters(
        '20242025', '20262027', 'old-skater', 'old-toi', 'goalies', 'AAA', 'BBB', null, 900,
        [101], [102], $this->run->id, [$this->player], [[...$this->player, 'nhl_player_id' => 102]]
    );
    $side = $result['sides'][0];
    expect($side['summary']['baseline_xsat_per_game'])->toBe(3.0)
        ->and($side['summary']['baseline_xsog_per_game'])->toBe(1.5)
        ->and($side['summary']['baseline_xgf_per_game'])->toBe(0.15)
        ->and($side['summary']['adjusted_xsat_per_game'])->toBe(2.64)
        ->and($side['summary']['total_goalie_adjusted_xgf_per_game'])->toBe(0.1056)
        ->and($side['summary']['pk_xgf_per_game'])->toBe(0.0)
        ->and($side['roster'][0]['projected_sat'])->toBe(3.0)
        ->and($side['goalie_reasons']->first()['projection_strength'])->toBe('all');
});

it('retains the legacy historical 85 to 15 shape blend while preserving totals', function (): void {
    $simulator = app(\App\Services\NhlProjectedTeamMatchupSimulator::class);
    $method = new \ReflectionMethod($simulator, 'adjustedGoalieEnvironmentRows');
    $offense = collect([[
        'matched_bucket_key' => 'offense-only',
        'baseline_xsat' => 60.0, 'baseline_xsog' => 30.0, 'baseline_xgf' => 3.0,
        'adjusted_xsat' => 60.0, 'adjusted_xsog' => 30.0, 'adjusted_xgf' => 3.0,
    ]]);
    $defense = collect([(object) [
        'matched_bucket_key' => 'defense-only',
        'baseline_xsat' => 40.0, 'baseline_xsog' => 20.0, 'baseline_xgf' => 2.0,
    ]]);
    $rows = $method->invoke($simulator, $offense, $defense, [
        'baseline_xsat' => 60.0, 'baseline_xsog' => 30.0, 'baseline_xgf' => 3.0,
    ])->keyBy('matched_bucket_key');

    expect($rows['offense-only']['adjusted_xsat'])->toBe(51.0)
        ->and($rows['defense-only']['adjusted_xsat'])->toBe(9.0)
        ->and($rows['offense-only']['adjusted_xsog'])->toBe(25.5)
        ->and($rows['defense-only']['adjusted_xsog'])->toBe(4.5)
        ->and($rows['offense-only']['adjusted_xgf'])->toBe(2.55)
        ->and($rows['defense-only']['adjusted_xgf'])->toBe(0.45)
        ->and($rows->sum('adjusted_xsat'))->toBe(60.0)
        ->and($rows->sum('adjusted_xsog'))->toBe(30.0)
        ->and($rows->sum('adjusted_xgf'))->toBe(3.0);
});

it('changes matchup goalie contribution when model attempt volume changes', function (): void {
    ($this->rate)();
    ($this->toi)();
    ($this->profile)(['profile_type' => 'goalie_faced', 'entity_key' => 'goalie_faced:900', 'entity_id' => 900]);
    $before = $this->service->offense($this->run->id, [$this->player]);
    $first = $this->service->goalieBuckets($this->run->id, 900, $before['buckets'])->sum('projected_gsax');
    DB::table('nhl_sat_model_entity_rate_projection_buckets')->where('model_run_id', $this->run->id)
        ->update(['projected_xsat_per_60' => 24]);
    $after = $this->service->offense($this->run->id, [$this->player]);
    $second = $this->service->goalieBuckets($this->run->id, 900, $after['buckets'])->sum('projected_gsax');
    expect($second)->toEqualWithDelta($first * 2, 0.000001);
});

it('exposes paired model buckets through the real prediction endpoint', function (): void {
    config(['cache.default' => 'array']);
    ($this->rate)(['projected_xsog_per_60' => 8, 'projected_xg_per_60' => 1.2]);
    ($this->toi)();
    DB::table('nhl_teams')->insert([['nhl_id' => 1, 'abbrev' => 'AAA'], ['nhl_id' => 2, 'abbrev' => 'BBB']]);
    \App\Models\NhlGame::query()->create([
        'nhl_game_id' => 2026020990, 'season_id' => '20262027', 'game_type' => 2,
        'game_date' => '2026-10-10', 'game_state' => 'FUT', 'game_dow' => 'Saturday', 'game_month' => 'October',
        'away_team_abbrev' => 'AAA', 'home_team_abbrev' => 'BBB', 'start_time_utc' => '2026-10-10 23:00:00',
    ]);
    foreach ([101 => 'AAA', 102 => 'BBB'] as $id => $team) {
        \App\Models\Player::query()->create(['nhl_id' => $id, 'full_name' => 'Skater ' . $id, 'team_abbrev' => $team, 'position' => 'C', 'pos_type' => 'F']);
        DB::table('nhl_player_toi_projections')->insert([
            'player_id' => $id, 'source_season_id' => '20242025', 'target_season_id' => '20262027',
            'projection_version' => 'toi', 'target_team_abbrev' => $team, 'position' => 'C',
            'projected_toi_per_game_seconds' => 600,
        ]);
    }
    foreach ([901 => 'AAA', 900 => 'BBB'] as $id => $team) {
        \App\Models\Player::query()->create(['nhl_id' => $id, 'full_name' => 'Goalie ' . $id, 'team_abbrev' => $team, 'position' => 'G', 'pos_type' => 'G', 'is_goalie' => true]);
        DB::table('nhl_goalie_season_projections')->insert([
            'goalie_player_id' => $id, 'projection_version' => 'goalies', 'source_season_id' => '20242025',
            'target_season_id' => '20262027', 'target_team_abbrev' => $team,
            'projected_games' => 50, 'projected_toi_seconds' => 180000, 'confidence_score' => 0.5,
        ]);
    }
    $token = 'paired-model-prediction-test';
    \App\Models\ApiClient::query()->create(['name' => 'Prediction test', 'slug' => 'paired-prediction',
        'token_hash' => \App\Models\ApiClient::hashToken($token), 'scopes' => ['nhl-stats:read']]);
    $response = $this->withToken($token)->getJson('/api/nhl-game-predictions?' . http_build_query([
        'nhl_game_id' => 2026020990, 'source_season_id' => '20242025', 'target_season_id' => '20262027',
        'projection_version' => 'skater', 'toi_projection_version' => 'toi', 'goalie_projection_version' => 'goalies',
        'away_goalie_id' => 901, 'home_goalie_id' => 900,
    ]))->assertOk()->assertJsonPath('inputs.sat_model_run_id', $this->run->id)
        ->assertJsonPath('teams.away.roster.0.nhl_player_id', 101)
        ->assertJsonPath('teams.away.roster.0.bucket_projection_source', 'sat_model');
    expect((float) $response->json('teams.away.summary.baseline_xsat_per_game'))->toBe(3.0)
        ->and((float) $response->json('teams.away.summary.baseline_xsog_per_game'))->toBe(1.5)
        ->and($response->json('teams.away.roster.0.on_target_sources'))->toBe(['bucket_average'])
        ->and($response->json('teams.away.roster.0.finishing_sources'))->toBe(['bucket_average'])
        ->and((float) $response->json('teams.away.summary.adjusted_xsat_per_game'))->toBe(2.64)
        ->and((float) $response->json('teams.away.summary.adjusted_xsog_per_game'))->toBe(1.32)
        ->and((float) $response->json('teams.away.summary.total_goalie_adjusted_xgf_per_game'))->toBe(0.132);
    $this->assertDatabaseCount('nhl_sat_model_entity_rate_projection_buckets', 1);
    $this->assertDatabaseCount('nhl_lineup_observations', 0);
    $this->assertDatabaseHas('nhl_sat_model_entity_rate_projection_buckets', [
        'model_run_id' => $this->run->id, 'entity_id' => 101,
        'projected_xsog_per_60' => 8, 'projected_xg_per_60' => 1.2,
    ]);
    Http::assertNothingSent();
});

it('persists compatible goalie buckets and reconciles season totals on rebuild', function (): void {
    ($this->profile)(['profile_type' => 'skater_defense', 'entity_key' => 'skater_defense:101']);
    ($this->profile)(['profile_type' => 'goalie_faced', 'entity_key' => 'goalie_faced:900', 'entity_id' => 900]);
    \App\Models\Player::query()->create(['nhl_id' => 101, 'full_name' => 'Skater', 'team_abbrev' => 'AAA', 'position' => 'C']);
    DB::table('nhl_player_toi_projections')->insert([
        'player_id' => 101, 'source_season_id' => '20242025', 'target_season_id' => '20262027',
        'projection_version' => 'toi', 'target_team_abbrev' => 'AAA', 'position' => 'C',
        'projected_toi_per_game_seconds' => 900,
    ]);
    DB::table('nhl_goalie_workload_projections')->insert([
        'goalie_player_id' => 900, 'source_season_id' => '20242025', 'target_season_id' => '20262027',
        'projection_version' => 'workload', 'target_team_abbrev' => 'AAA', 'position' => 'G',
        'projected_toi_seconds' => 36000, 'projected_toi_hours' => 10, 'projected_games' => 10,
    ]);
    $builder = app(\App\Services\NhlGoalieProjectionBuilder::class);
    foreach ([1, 2] as $attempt) {
        $builder->buildModelGoalie('20242025', '20262027', 'workload', 'toi', 'model-goalie', 900, $this->run->id);
    }
    $this->assertDatabaseCount('nhl_goalie_season_projections', 1);
    $this->assertDatabaseCount('nhl_goalie_projection_chance_buckets', 1);
    $season = DB::table('nhl_goalie_season_projections')->first();
    $bucket = DB::table('nhl_goalie_projection_chance_buckets')->first();
    expect((float) $season->projected_xga)->toBe(2.0)
        ->and((float) $season->projected_ga)->toBe(1.5)
        ->and((float) $season->projected_gsax)->toBe(0.5)
        ->and((float) $bucket->projected_xga)->toBe((float) $season->projected_xga)
        ->and($bucket->projection_strength)->toBe('all')
        ->and(json_decode($season->metadata, true)['sat_model_run_id'])->toBe($this->run->id);
});

it('uses personal bucket conversion when projected conversion is unavailable', function (): void {
    ($this->rate)();
    ($this->toi)();
    ($this->profile)(['source_sat' => 100, 'source_sog' => 80, 'source_goals' => 8]);
    $bucket = $this->service->inputs($this->run->id)->get(101)['buckets']->first();
    expect($bucket->baseline_xsat)->toBe(3.0)
        ->and($bucket->baseline_xsog)->toEqualWithDelta(2.4, 0.000001)
        ->and($bucket->baseline_xgf)->toEqualWithDelta(0.24, 0.000001)
        ->and($bucket->finishing_source)->toBe('historical_fallback');
});

it('falls back to bucket averages when the player has no bucket history', function (): void {
    ($this->rate)();
    ($this->toi)();
    ($this->profile)(['entity_id' => 102, 'entity_key' => 'skater_offense:102', 'source_sog' => 60, 'source_goals' => 6]);
    $bucket = $this->service->inputs($this->run->id)->get(101)['buckets']->first();
    expect($bucket->baseline_xsog)->toEqualWithDelta(1.8, 0.000001)
        ->and($bucket->baseline_xgf)->toEqualWithDelta(0.18, 0.000001)
        ->and($bucket->on_target_source)->toBe('bucket_average');
});

it('preserves observed zero finishing instead of substituting an average', function (): void {
    ($this->rate)();
    ($this->toi)();
    ($this->profile)(['source_goals' => 0]);
    expect($this->service->inputs($this->run->id)->get(101)['buckets']->first()->baseline_xgf)->toBe(0.0);
});

it('changes attempt volume before applying offensive conversion', function (): void {
    $history = app(NhlHistoricalPredictionService::class);
    $shape = (object) ['matched_bucket_key' => 'A', 'bucket_dimensions' => []];
    $offense = collect([$history->bucket($shape, 60, 30, 3, 'sat_model', $this->run->id)]);
    $defense = collect([$history->bucket($shape, 40, 35, 10, 'historical_fallback', $this->run->id)]);
    $row = $this->service->environment($this->run->id, $offense, $defense)->first();
    expect($row['adjusted_xsat'])->toEqualWithDelta(53.6, 0.000001)
        ->and($row['adjusted_xsog'])->toEqualWithDelta(26.8, 0.000001)
        ->and($row['adjusted_xgf'])->toEqualWithDelta(2.68, 0.000001)
        ->and($row['environment_offense_weight'])->toBe(0.88)
        ->and($row['environment_defense_weight'])->toBe(0.02);
});

it('keeps the offensive weight when no defensive evidence exists', function (): void {
    $bucket = app(NhlHistoricalPredictionService::class)->bucket((object) [
        'matched_bucket_key' => 'A', 'bucket_dimensions' => [],
    ], 60, 30, 3, 'sat_model', $this->run->id);
    $row = $this->service->environment($this->run->id, collect([$bucket]), collect())->first();
    expect($row['adjusted_xsat'])->toEqualWithDelta(52.8, 0.000001)
        ->and($row['adjusted_xgf'])->toEqualWithDelta(2.64, 0.000001)
        ->and($row['environment_offense_weight'])->toBe(0.88)
        ->and($row['environment_defense_weight'])->toBe(0.0);
});

it('adds only exact defensive matches while retaining every offensive bucket', function (): void {
    $history = app(NhlHistoricalPredictionService::class);
    $bucket = fn (string $key, float $sat): object => $history->bucket(
        (object) ['matched_bucket_key' => $key, 'bucket_dimensions' => ['shot_type_group' => 'unknown']],
        $sat, $sat / 2, $sat / 20, 'sat_model', $this->run->id
    );
    $offense = collect([$bucket('A', 60), $bucket('Other', 10)]);
    // Identical displayed dimensions do not make a different key an exact match.
    $defense = collect([$bucket('A', 40), $bucket('defense-only', 1000)]);
    $rows = $this->service->environment($this->run->id, $offense, $defense)->keyBy('matched_bucket_key');

    expect($rows->keys()->sort()->values()->all())->toBe(['A', 'Other'])
        ->and($rows['A']['adjusted_xsat'])->toEqualWithDelta(53.6, 0.000001)
        ->and($rows['Other']['adjusted_xsat'])->toEqualWithDelta(8.8, 0.000001)
        ->and($rows['Other']['shot_type_group'])->toBe('unknown')
        ->and($rows['Other']['environment_defense_weight'])->toBe(0.0)
        ->and($rows->sum('adjusted_xsat'))->toEqualWithDelta(62.4, 0.000001)
        ->and($rows->sum('offense_share'))->toEqualWithDelta(1.0, 0.000001);
});

it('does not produce attempts from defense when the offensive bucket set is empty', function (): void {
    $bucket = app(NhlHistoricalPredictionService::class)->bucket((object) [
        'matched_bucket_key' => 'A', 'bucket_dimensions' => [],
    ], 60, 30, 3, 'historical_fallback', $this->run->id);

    expect($this->service->environment($this->run->id, collect(), collect([$bucket])))->toBeEmpty();
});

it('keeps defensive weight independent of an explicit offensive weight', function (): void {
    $bucket = app(NhlHistoricalPredictionService::class)->bucket((object) [
        'matched_bucket_key' => 'A', 'bucket_dimensions' => [],
    ], 60, 30, 3, 'sat_model', $this->run->id);
    $row = $this->service->environment($this->run->id, collect([$bucket]), collect([$bucket]), 1.05)->first();

    expect($row['adjusted_xsat'])->toEqualWithDelta(64.2, 0.000001)
        ->and($row['environment_offense_weight'])->toBe(1.05)
        ->and($row['environment_defense_weight'])->toBe(0.02);
});

it('does not borrow goalie skill from a different bucket', function (): void {
    ($this->profile)(['profile_type' => 'goalie_faced', 'entity_key' => 'goalie_faced:900', 'entity_id' => 900]);
    $bucket = app(NhlHistoricalPredictionService::class)->bucket((object) [
        'matched_bucket_key' => 'B', 'bucket_dimensions' => [],
    ], 10, 5, 0.4, 'sat_model', $this->run->id);
    $result = $this->service->goalieBuckets($this->run->id, 900, collect([$bucket]))->get('B');
    expect($result->projected_ga)->toBe(0.4)->and($result->goalie_skill_source)->toBe('neutral_missing_bucket');
});

it('uses pooled volume when both model and personal history are absent', function (): void {
    ($this->profile)(['entity_id' => 102, 'entity_key' => 'skater_offense:102']);
    $result = $this->service->offense($this->run->id, [$this->player]);
    expect($result['players'][0]['bucket_projection_source'])->toBe('bucket_average')
        ->and($result['buckets']->sum('baseline_xsat'))->toEqualWithDelta(100 / 6, 0.000001);
});

it('excludes preseason profiles from static bucket conversion averages', function (): void {
    ($this->rate)();
    ($this->toi)();
    ($this->profile)(['entity_id' => 102, 'entity_key' => 'skater_offense:102', 'game_type' => 1, 'source_sog' => 100, 'source_goals' => 100]);
    $bucket = $this->service->inputs($this->run->id)->get(101)['buckets']->first();
    expect($bucket->baseline_xsog)->toBe(1.5)
        ->and($bucket->baseline_xgf)->toEqualWithDelta(0.15, 0.000001);
});

it('does not confuse model bucket confidence with a large personal goalie sample', function (): void {
    ($this->profile)(['profile_type' => 'goalie_faced', 'entity_key' => 'goalie_faced:900', 'entity_id' => 900,
        'source_sat' => 3, 'source_sog' => 2, 'source_goals' => 0, 'expected_goals' => 0.2, 'confidence_score' => 1]);
    $environment = collect([app(NhlHistoricalPredictionService::class)->bucket((object) [
        'matched_bucket_key' => 'A', 'bucket_dimensions' => [],
    ], 10, 5, 0.4, 'sat_model', $this->run->id)]);
    expect($this->service->goalieBuckets($this->run->id, 900, $environment)->get('A')->source_confidence_score)->toBe(0.1);
});


it('preserves projected zero conversion despite positive historical and pooled evidence', function (): void {
    ($this->rate)(['projected_xsog_per_60' => 0, 'projected_xg_per_60' => 0]);
    ($this->toi)();
    ($this->profile)(['source_sog' => 0, 'source_goals' => 0]);
    ($this->profile)(['entity_id' => 102, 'entity_key' => 'skater_offense:102']);
    $bucket = $this->service->inputs($this->run->id)->get(101)['buckets']->first();
    expect($bucket->baseline_xsat)->toBe(3.0)
        ->and($bucket->baseline_xsog)->toBe(0.0)
        ->and($bucket->baseline_xgf)->toBe(0.0)
        ->and($bucket->on_target_source)->toBe('projected_rate')
        ->and($bucket->finishing_source)->toBe('projected_rate');
});

it('uses projected bucket conversion ahead of pooled history', function (): void {
    ($this->rate)(['projected_xsog_per_60' => 8, 'projected_xg_per_60' => 1.2]);
    ($this->toi)();
    ($this->profile)(['entity_id' => 102, 'entity_key' => 'skater_offense:102', 'source_sog' => 60, 'source_goals' => 6]);
    $bucket = $this->service->inputs($this->run->id)->get(101)['buckets']->first();
    expect($bucket->baseline_xsog)->toEqualWithDelta(2.0, 0.000001)
        ->and($bucket->baseline_xgf)->toEqualWithDelta(0.3, 0.000001)
        ->and($bucket->on_target_source)->toBe('projected_rate')
        ->and($bucket->finishing_source)->toBe('projected_rate');
});

it('uses the matching personal Other bucket without changing its projected attempts', function (): void {
    ($this->rate)(['matched_bucket_key' => 'L99|other=low_volume', 'is_other_bucket' => true,
        'projected_xsat_per_60' => 12, 'projected_xsog_per_60' => 8, 'projected_xg_per_60' => 1.2]);
    ($this->toi)();
    ($this->profile)(['matched_bucket_key' => 'L99|other=low_volume', 'source_sog' => 40, 'source_goals' => 4]);
    $result = $this->service->offense($this->run->id, [$this->player]);
    expect($result['players'][0]['game_projected_toi_seconds'])->toBe(900)
        ->and($result['players'][0]['projected_sat'])->toBe(3.0)
        ->and($result['players'][0]['projected_sog'])->toBe(1.2)
        ->and($result['players'][0]['projected_goals'])->toBe(0.12);
});

it('does not turn a zero offensive bucket into average conversion when defense adds attempts', function (): void {
    ($this->rate)(['projected_xsat_per_60' => 0, 'projected_xsog_per_60' => 0, 'projected_xg_per_60' => 0]);
    ($this->toi)();
    ($this->profile)(['entity_id' => 102, 'entity_key' => 'skater_offense:102']);
    $offense = $this->service->offense($this->run->id, [$this->player])['buckets'];
    $defense = collect([app(NhlHistoricalPredictionService::class)->bucket(
        (object) ['matched_bucket_key' => 'A', 'bucket_dimensions' => []],
        20, 10, 1, 'historical_fallback', $this->run->id
    )]);
    $row = $this->service->environment($this->run->id, $offense, $defense)->first();
    expect($row['adjusted_xsat'])->toEqualWithDelta(0.4, 0.000001)
        ->and($row['adjusted_xsog'])->toBe(0.0)
        ->and($row['adjusted_xgf'])->toBe(0.0);
});

it('rejects a negative attempt projection instead of silently converting it to zero', function (): void {
    ($this->rate)(['projected_xsat_per_60' => -1, 'projected_xsog_per_60' => 8, 'projected_xg_per_60' => 1.2]);
    ($this->toi)();
    expect($this->service->inputs($this->run->id))->toBeEmpty();
});


it('retains only reliable buckets and rescales them to the entity target', function (): void {
    foreach (['A' => [96, 0.99], 'B' => [3, 0.98], 'C' => [1, 0.50]] as $key => [$sat, $confidence]) {
        ($this->profile)([
            'matched_bucket_key' => $key, 'bucket_dimensions' => json_encode(['shot_type_group' => $key]),
            'source_sat' => $sat, 'source_sog' => $sat, 'source_goals' => 0,
            'source_profile_share' => $sat / 100, 'source_xsat_per_60' => $sat / 10,
            'source_xsog_per_60' => $sat / 10, 'source_xg_per_60' => 0,
            'expected_sog' => $sat, 'expected_goals' => 0, 'confidence_score' => $confidence,
        ]);
    }
    $builder = app(NhlSatModelEntityRateProjectionBuilder::class);
    expect($builder->buildEntity($this->run, 'skater_offense', 'skater_offense:101'))->toBe(3);
    $rows = DB::table('nhl_sat_model_entity_rate_projection_buckets')
        ->where('model_run_id', $this->run->id)->where('entity_id', 101)
        ->orderBy('matched_bucket_key')->get();
    expect($rows->pluck('matched_bucket_key')->all())->toBe(['A', 'B'])
        ->and((int) $rows->sum('source_sat'))->toBe(99)
        ->and($rows->where('is_other_bucket', true))->toBeEmpty();
    foreach ($rows as $row) {
        expect(json_decode($row->bucket_dimensions, true))->toBe(['shot_type_group' => $row->matched_bucket_key])
            ->and((float) $row->projected_xsat_per_60)->toBeGreaterThan(0);
        $metadata = json_decode($row->metadata, true);
        expect($metadata['minimum_source_sat'])->toBe(0)
            ->and((float) $metadata['minimum_bucket_confidence'])->toBe(0.97)
            ->and((float) $metadata['profile_input_share_coverage'])->toBe(1.0);
    }
    ($this->toi)();
    $input = $this->service->inputs($this->run->id)->get(101);
    expect($input['buckets']->pluck('matched_bucket_key')->sort()->values()->all())->toBe(['A', 'B'])
        ->and($input['buckets']->sum('baseline_xsat'))
        ->toEqualWithDelta((float) $rows->sum('projected_xsat_per_60') / 4, 0.000001);
    Http::assertNothingSent();
});

it('matches sparse training snapshots by exact key without consuming held-out buckets', function (): void {
    ($this->profile)(['source_sat' => 1, 'source_profile_share' => 1, 'confidence_score' => 0.99,
        'source_xsat_per_60' => 2, 'source_xsog_per_60' => 1, 'source_xg_per_60' => 0.1]);
    foreach (['20232024' => 1, '20242025' => 3, '20252026' => 999] as $season => $rate) {
        DB::table('nhl_sat_model_entity_test_profile_buckets')->insert([
            ...$this->identity, 'test_season_id' => (string) $season,
            'sat_expected_goals_model_id' => $this->goalModel,
            'matched_bucket_key' => 'A', 'fallback_level' => 1, 'bucket_dimensions' => '{}',
            'source_sat' => 1, 'source_sog' => 1, 'source_goals' => 0,
            'source_xsat_per_60' => $rate, 'source_xsog_per_60' => $rate, 'source_xg_per_60' => 0,
        ]);
    }
    app(NhlSatModelEntityRateProjectionBuilder::class)
        ->buildEntity($this->run, 'skater_offense', 'skater_offense:101');
    $row = DB::table('nhl_sat_model_entity_rate_projection_buckets')
        ->where('model_run_id', $this->run->id)->where('entity_id', 101)->sole();
    $metadata = json_decode($row->metadata, true);
    expect($row->matched_bucket_key)->toBe('A')
        ->and((float) $metadata['season_one_xsat_per_60'])->toBe(1.0)
        ->and((float) $metadata['season_two_xsat_per_60'])->toBe(3.0)
        ->and($metadata['latest_active_bucket_count'])->toBe(1)
        ->and((int) $row->source_sat)->toBe(1);
});

it('removes obsolete Other on entity rebuild without touching another player', function (): void {
    ($this->profile)(['source_sat' => 1, 'source_profile_share' => 1, 'confidence_score' => 0.99,
        'source_xsat_per_60' => 2, 'source_xsog_per_60' => 1, 'source_xg_per_60' => 0.1]);
    ($this->rate)(['matched_bucket_key' => 'L99|other=low_volume', 'is_other_bucket' => true]);
    ($this->rate)(['entity_key' => 'skater_offense:102', 'entity_id' => 102,
        'matched_bucket_key' => 'L99|other=low_volume', 'is_other_bucket' => true]);
    $builder = app(NhlSatModelEntityRateProjectionBuilder::class);
    foreach ([1, 2] as $attempt) {
        expect($builder->buildEntity($this->run, 'skater_offense', 'skater_offense:101'))->toBe(1);
    }
    $this->assertDatabaseMissing('nhl_sat_model_entity_rate_projection_buckets', [
        'model_run_id' => $this->run->id, 'entity_id' => 101, 'matched_bucket_key' => 'L99|other=low_volume',
    ]);
    $this->assertDatabaseHas('nhl_sat_model_entity_rate_projection_buckets', [
        'model_run_id' => $this->run->id, 'entity_id' => 102, 'matched_bucket_key' => 'L99|other=low_volume',
    ]);
    $this->assertDatabaseHas('nhl_sat_model_entity_profile_buckets', [
        'model_run_id' => $this->run->id, 'entity_id' => 101, 'matched_bucket_key' => 'A', 'source_sat' => 1,
    ]);
});

it('passes only resolved lineup NHL identities to model inputs', function (): void {
    $this->mock(NhlSatModelPredictionService::class)->shouldReceive('inputs')->once()
        ->with($this->run->id, [101, 102])->andReturn(collect());
    $players = [
        ['nhl_player_id' => 101, 'player_id' => 7001, 'line_key' => 'F1'],
        ['nhl_player_id' => null, 'player_id' => 7002, 'line_key' => 'F1'],
        ['player_id' => 102, 'line_key' => 'F2'],
        ['nhl_player_id' => 101, 'player_id' => 7001, 'line_key' => 'F3'],
    ];
    $players = array_map(fn (array $player): array => ['projection_source' => 'historical_fallback', ...$player], $players);
    $result = app(\App\Services\NhlGameLineupProjectionBuilder::class)
        ->applySatModel($players, $this->run->id, '20252026', 2);
    expect($result)->toHaveCount(4)->and($result[1]['nhl_player_id'])->toBeNull();
});

it('does not load model inputs for a lineup without resolved NHL players', function (): void {
    $this->mock(NhlSatModelPredictionService::class)->shouldNotReceive('inputs');
    $builder = app(\App\Services\NhlGameLineupProjectionBuilder::class);
    expect($builder->applySatModel([], $this->run->id, '20252026', 2))->toBe([]);
    $result = $builder->applySatModel([['nhl_player_id' => null, 'player_id' => 101, 'line_key' => 'F4',
        'projection_source' => 'historical_fallback']],
        $this->run->id, '20252026', 2);
    expect($result)->toHaveCount(1)->and($result[0]['nhl_player_id'])->toBeNull()
        ->and($result[0]['game_projected_toi_seconds'])->toBe(510);
});

it('preserves lineup predictions while restricting rate and TOI reads to that lineup', function (): void {
    ($this->rate)();
    ($this->toi)();
    ($this->profile)();
    ($this->rate)(['entity_id' => 202, 'entity_key' => 'skater_offense:202', 'projected_xsat_per_60' => 1000]);
    ($this->toi)(['entity_id' => 202, 'entity_key' => 'skater_offense:202']);
    $queries = [];
    DB::listen(function (\Illuminate\Database\Events\QueryExecuted $query) use (&$queries): void {
        if (str_contains($query->sql, 'nhl_sat_model_entity_rate_projection_buckets')
            || str_contains($query->sql, 'nhl_sat_model_entity_toi_projections')) {
            $queries[] = $query;
        }
    });
    $result = app(\App\Services\NhlGameLineupProjectionBuilder::class)
        ->applySatModel([$this->player], $this->run->id, '20252026', 2);
    expect($result)->toHaveCount(1)->and($result[0]['projected_sat'])->toBe(3.0)
        ->and($result[0]['projected_sog'])->toBe(1.5)->and($result[0]['projected_goals'])->toBe(0.06)
        ->and($result[0]['game_projected_toi_seconds'])->toBe(900)
        ->and($queries)->toHaveCount(2);
    foreach ($queries as $query) {
        expect(strtolower($query->sql))->toContain('in (?)')
            ->and($query->bindings)->toContain(101)->not->toContain(202);
    }
});

it('short circuits explicit empty model input selections without historical loading', function (): void {
    $history = Mockery::mock(NhlHistoricalPredictionService::class);
    $history->shouldNotReceive('profiles');
    $history->shouldNotReceive('averages');
    expect((new NhlSatModelPredictionService($history))->inputs($this->run->id, []))->toBeEmpty();
});

it('does not compute pooled history when selected players have no projected buckets', function (): void {
    $history = Mockery::mock(NhlHistoricalPredictionService::class);
    $history->shouldNotReceive('profiles');
    $history->shouldNotReceive('averages');
    expect((new NhlSatModelPredictionService($history))->inputs($this->run->id, [101]))->toBeEmpty();
});

it('matches historical pooling with entity-level snapshot replacement and full exposure', function (string $type): void {
    $add = function (int $id, string $bucket, int $sat, int $sog, int $goals, int $seconds) use ($type): void {
        ($this->profile)(['profile_type' => $type, 'entity_id' => $id, 'entity_key' => $type . ':' . $id,
            'matched_bucket_key' => $bucket, 'source_sat' => $sat, 'source_sog' => $sog,
            'source_goals' => $goals, 'source_toi_seconds' => $seconds,
            'expected_sog' => $sog / 2, 'expected_goals' => $goals / 2]);
    };
    $add(101, 'A', 100, 50, 2, 3600);
    $add(101, 'obsolete', 40, 20, 1, 3600);
    $add(102, 'A', 60, 30, 2, 1800);
    $add(103, 'C', 20, 10, 1, 900);
    $snapshot = function (int $id, string $bucket, string $season, int $sat, int $sog, int $goals): void {
        $row = (array) DB::table('nhl_sat_model_entity_profile_buckets')->where('entity_id', 101)
            ->where('matched_bucket_key', 'A')->first();
        unset($row['id']);
        DB::table('nhl_sat_model_entity_test_profile_buckets')->insert([
            ...$row, 'entity_id' => $id, 'entity_key' => $row['profile_type'] . ':' . $id,
            'test_season_id' => $season, 'matched_bucket_key' => $bucket,
            'source_sat' => $sat, 'source_sog' => $sog, 'source_goals' => $goals,
            'source_toi_seconds' => 600, 'expected_sog' => $sog / 2, 'expected_goals' => $goals / 2,
        ]);
    };
    $snapshot(101, 'A', '20242025', 10, 4, 1);
    $snapshot(101, 'Z', '20242025', 5, 0, 0);
    $snapshot(104, 'excluded_snapshot_only', '20242025', 999, 999, 999);
    $snapshot(102, 'excluded_held_out', '20252026', 999, 999, 999);
    $snapshot(102, 'excluded_older', '20232024', 999, 999, 999);
    $history = app(NhlHistoricalPredictionService::class);
    $profiles = $history->profiles($this->run->id, $type, [101, 102, 103]);
    $oldExposure = (float) $profiles->sum(fn ($rows): float => (float) $rows->max('source_toi_seconds'));
    $expected = $profiles->flatten(1)->groupBy('matched_bucket_key');
    $actual = $history->averages($this->run->id, $type)->keyBy('matched_bucket_key');
    expect($actual->keys()->all())->toBe(['A', 'C', 'Z'])->and($oldExposure)->toBe(3300.0);
    foreach ($actual as $key => $row) {
        foreach (['source_sat', 'source_sog', 'source_goals', 'expected_sog', 'expected_goals'] as $field) {
            expect($row->{$field})->toEqualWithDelta((float) $expected[$key]->sum($field), 0.000001);
        }
        expect($row->source_toi_seconds)->toBe($oldExposure)->and($row->profile_type)->toBe($type)
            ->and($row->projection_source)->toBe('bucket_average');
    }
    $projected = $history->project($actual->values(), 600, $this->run->id);
    expect($projected->sum('baseline_xsat'))->toEqualWithDelta(95 * 600 / 3300, 0.000001)
        ->and($projected->sum('baseline_xsog'))->toEqualWithDelta(($type === 'skater_defense' ? 22 : 44) * 600 / 3300, 0.000001)
        ->and($projected->sum('baseline_xgf'))->toEqualWithDelta(($type === 'skater_defense' ? 2 : 4) * 600 / 3300, 0.000001);
})->with(['skater_offense', 'skater_defense']);

it('pools exposure once per entity using its maximum without averaging player percentages', function (): void {
    ($this->profile)(['source_sat' => 100, 'source_sog' => 80, 'source_toi_seconds' => 3600]);
    ($this->profile)(['matched_bucket_key' => 'B', 'source_sat' => 50, 'source_sog' => 0, 'source_toi_seconds' => 7200]);
    ($this->profile)(['entity_id' => 102, 'entity_key' => 'skater_offense:102',
        'source_sat' => 300, 'source_sog' => 60, 'source_toi_seconds' => 3600]);
    $history = app(NhlHistoricalPredictionService::class);
    $rows = $history->averages($this->run->id, 'skater_offense')->keyBy('matched_bucket_key');
    expect($rows['A']->source_sat)->toBe(400.0)->and($rows['A']->source_sog)->toBe(140.0)
        ->and($rows['A']->source_toi_seconds)->toBe(10800.0)
        ->and($rows['B']->source_toi_seconds)->toBe(10800.0)
        ->and($history->conversion(null, $rows['A'])['on_target'])->toBe(0.35)
        ->and($history->conversion(null, $rows['B'])['on_target'])->toBe(0.0);
});

it('does not call the raw profile loader when computing pooled historical averages', function (): void {
    ($this->profile)();
    $history = Mockery::mock(NhlHistoricalPredictionService::class)->makePartial();
    $history->shouldNotReceive('profiles');
    $queries = [];
    DB::listen(function (\Illuminate\Database\Events\QueryExecuted $query) use (&$queries): void {
        if (str_contains($query->sql, 'nhl_sat_model_entity_profile_buckets')) {
            $queries[] = strtolower($query->sql);
        }
    });
    expect($history->averages($this->run->id, 'skater_offense'))->toHaveCount(1)
        ->and($queries)->toHaveCount(2);
    foreach ($queries as $sql) {
        expect($sql)->toContain('sum(')->not->toContain('select *');
    }
    $history->averages($this->run->id, 'skater_offense');
    expect($queries)->toHaveCount(2);
});

it('keeps empty historical pools empty', function (): void {
    expect(app(NhlHistoricalPredictionService::class)->averages($this->run->id, 'skater_offense'))->toBeEmpty();
});

it('isolates SQL historical pools by model profile type game type and non-null identity', function (): void {
    ($this->profile)();
    ($this->profile)(['entity_id' => 102, 'entity_key' => 'skater_offense:102', 'game_type' => 1]);
    ($this->profile)(['entity_id' => 103, 'entity_key' => 'skater_defense:103', 'profile_type' => 'skater_defense']);
    ($this->profile)(['entity_id' => null, 'entity_key' => 'skater_offense:unresolved']);
    $other = $this->run->replicate();
    $other->run_key = 'other-average-model';
    $other->save();
    ($this->profile)(['model_run_id' => $other->id, 'source_sat' => 900]);
    $history = app(NhlHistoricalPredictionService::class);
    expect($history->averages($this->run->id, 'skater_offense')->sole()->source_sat)->toBe(100.0)
        ->and($history->averages($this->run->id, 'skater_defense')->sole()->source_sat)->toBe(100.0)
        ->and($history->averages($other->id, 'skater_offense')->sole()->source_sat)->toBe(900.0);
});
