<?php

declare(strict_types=1);

use App\Models\NhlExpectedGoalsModel;
use App\Models\NhlModelRun;
use App\Services\NhlCustomGamePredictionEngine;
use App\Services\NhlCustomGamePredictionInputs;
use App\Services\NhlSatModelEntityProfileBuilder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-26 12:00:00 UTC'));
    Http::preventStrayRequests();
    Bus::fake();
    config(['nhl-custom-prediction' => require base_path('config/nhl-custom-prediction.php')]);
    $this->run = NhlModelRun::query()->create(['run_key' => 'profile-conversion', 'name' => 'Profile conversion',
        'model_family' => 'sat', 'model_version' => 'fixture', 'workflow_stage' => 'training',
        'status' => 'complete', 'train_season_ids' => ['20232024', '20242025'], 'target_season_id' => '20252026']);
    $this->evaluations = [];
    foreach (['sat' => 'shot_on_goal', 'sog' => 'goal'] as $mode => $target) {
        $evaluation = NhlExpectedGoalsModel::query()->create(['model_run_id' => $this->run->id,
            'name' => $mode, 'version' => 'fixture__run_'.$this->run->id,
            'prediction_target' => $target, 'trained_at' => now()->subHour(),
            'feature_config' => ['sample_mode' => $mode, 'workflow_action' => 'eval_'.$mode,
                'probability_smoothing' => ['policy' => 'fixture-strength', 'bucket_signature' => $mode.'-v1']]]);
        $evaluation->buckets()->create(['strength' => 'ev', 'bucket_key' => 'B', 'fallback_level' => 1,
            'bucket_dimensions' => [], 'attempts' => 100, 'goals' => 20,
            'raw_goal_rate' => 0.2, 'smoothed_goal_probability' => 0.2]);
        $this->evaluations[$mode] = $evaluation;
    }
    $this->sources = NhlSatModelEntityProfileBuilder::probabilitySources($this->evaluations['sat'], $this->evaluations['sog']);
    $this->run->update(['metrics' => ['profile_freshness' => ['skater_offense' => [
        'outdated' => false, 'build_id' => 'committed-build', 'profiled_at' => now()->toIso8601String(),
        'probability_sources' => $this->sources]]]]);
    $this->profile = function (string $strength, int $player, array $overrides = []): int {
        return DB::table('nhl_sat_model_entity_profile_buckets')->insertGetId([
            'model_run_id' => $this->run->id, 'sat_expected_goals_model_id' => $this->evaluations['sat']->id,
            'sog_expected_goals_model_id' => $this->evaluations['sog']->id,
            'source_season_ids' => json_encode(['20232024', '20242025']), 'game_type' => 2,
            'profile_type' => 'skater_offense', 'strength' => $strength,
            'entity_key' => 'player:'.$player, 'entity_id' => $player,
            'matched_bucket_key' => 'B', 'fallback_level' => 1, 'bucket_dimensions' => '{}',
            'source_sat' => 10, 'source_sog' => 2, 'source_goals' => 0,
            'expected_sog' => 2, 'expected_goals' => 0.2, 'goal_probability' => 0.99,
            'metadata' => json_encode(['probability_sources' => $this->sources]), 'profiled_at' => now(),
            ...$overrides,
        ]);
    };
    foreach (['ev' => 0.2, 'pp' => 0.3, 'pk' => 0.4] as $strength => $rate) {
        ($this->profile)($strength, 101, ['expected_goals' => 2 * ($rate - 0.1)]);
        ($this->profile)($strength, 102, ['source_sat' => 90, 'source_sog' => 18,
            'expected_sog' => 63, 'expected_goals' => 18 * $rate]);
    }
    $this->reader = new NhlCustomGamePredictionInputs();
    (new ReflectionProperty($this->reader, 'filter'))->setValue($this->reader,
        ['keys' => ['B'], 'sat_model_id' => $this->evaluations['sat']->id]);
    $this->load = function (): array {
        $references = (new ReflectionMethod($this->reader, 'profileConversionReferences'))
            ->invoke($this->reader, $this->run->fresh(), ['20232024', '20242025']);
        (new ReflectionProperty($this->reader, 'conversionReferences'))->setValue($this->reader, $references);
        return $references;
    };
    $this->raw = ['buckets' => ['B' => ['sat' => 100, 'sog' => 50, 'goals' => 5]]];
    $this->convert = fn (?array $personal, string $lane = 'EV', string $denominator = 'sog', string $key = 'B') =>
        (new ReflectionMethod($this->reader, 'conversion'))->invoke($this->reader,
            $personal, $this->raw, $key, $denominator === 'sat' ? 'sog' : 'goals', $denominator, $lane);
});

afterEach(function (): void {
    $this->travelBack();
});

it('pools expected SOG using total attempts rather than averaging player percentages', function (): void {
    ($this->load)();
    expect(($this->convert)(null, 'EV', 'sat'))->toBe(0.65);
});

it('uses conditional expected goals over actual SOG rather than the all-attempt probability field', function (): void {
    ($this->load)();
    expect(($this->convert)(null))->toEqualWithDelta(0.19, 0.000001);
});

it('keeps PP conversions distinct from EV references', function (): void {
    ($this->load)();
    expect(($this->convert)(null, 'PP'))->toEqualWithDelta(0.29, 0.000001);
});

it('keeps PK conversions distinct from PP references', function (): void {
    ($this->load)();
    expect(($this->convert)(null, 'PK'))->toEqualWithDelta(0.39, 0.000001);
});

it('excludes combined all-strength rows instead of counting them again', function (): void {
    ($this->profile)('all', 103, ['expected_goals' => 2]);
    expect(($this->load)()['buckets']['EV']['B']['source_sat'])->toBe(100.0);
});

it('excludes goalie-faced profiles from the offensive reference', function (): void {
    ($this->profile)('ev', 104, ['profile_type' => 'goalie_faced', 'expected_goals' => 2]);
    expect(($this->load)()['buckets']['EV']['B']['source_sat'])->toBe(100.0);
});

it('excludes buckets outside the frozen retained mapping', function (): void {
    ($this->profile)('ev', 105, ['matched_bucket_key' => 'OUTSIDE']);
    expect(array_keys(($this->load)()['buckets']['EV']))->toBe(['B']);
});

it('rejects Test-season provenance in the Train reference without blocking raw conversion', function (): void {
    ($this->profile)('ev', 106, ['source_season_ids' => '["20252026"]']);
    expect(($this->load)()['provenance']['reason'])->toBe('profile_row_provenance_or_counts_mismatch');
    expect(($this->convert)(null))->toBe(0.1);
});

it('falls back when the per-type freshness certificate is absent', function (): void {
    $this->run->update(['metrics' => []]);
    expect(($this->load)()['provenance']['reason'])->toBe('missing_or_stale_skater_profiles');
    expect(($this->convert)(null))->toBe(0.1);
});

it('falls back when offensive profiles are explicitly outdated', function (): void {
    $metrics = $this->run->metrics;
    $metrics['profile_freshness']['skater_offense']['outdated'] = true;
    $this->run->update(['metrics' => $metrics]);
    expect(($this->load)()['provenance']['source'])->toBe('raw_train_fallback');
});

it('rejects an evaluation updated in place after profile publication', function (): void {
    $this->evaluations['sat']->update(['trained_at' => now()]);
    expect(($this->load)()['provenance']['reason'])->toBe('evaluation_revision_mismatch');
});

it('rejects changed smoothing signatures even when model IDs and timestamps match', function (): void {
    $config = $this->evaluations['sog']->feature_config;
    $config['probability_smoothing']['bucket_signature'] = 'new-buckets';
    $this->evaluations['sog']->update(['feature_config' => $config]);
    expect(($this->load)()['provenance']['reason'])->toBe('evaluation_revision_mismatch');
});

it('rejects stale profile row provenance beneath a current certificate', function (): void {
    DB::table('nhl_sat_model_entity_profile_buckets')->where('model_run_id', $this->run->id)->where('strength', 'ev')->where('entity_id', 101)->update(['metadata' => '{}']);
    expect(($this->load)()['provenance']['reason'])->toBe('profile_row_provenance_or_counts_mismatch');
});

it('does not adopt a partially rebuilt offensive profile set', function (): void {
    $metrics = $this->run->metrics;
    $metrics['profile_build'] = ['id' => 'next-build', 'status' => 'running', 'profile_types' => ['skater_offense']];
    $this->run->update(['metrics' => $metrics]);
    expect(($this->load)()['provenance']['reason'])->toBe('skater_profile_build_not_complete');
});

it('does not disable completed skater evidence while goalies are being rebuilt', function (): void {
    $metrics = $this->run->metrics;
    $metrics['profile_build'] = ['id' => 'goalie-build', 'status' => 'running', 'profile_types' => ['goalie_faced']];
    $this->run->update(['metrics' => $metrics]);
    expect(($this->load)()['provenance']['source'])->toBe('smoothed_train_profiles');
});

it('preserves supported personal goal rates ahead of profile references', function (): void {
    ($this->load)();
    expect(($this->convert)(['buckets' => ['B' => ['sog' => 4, 'goals' => 1]]]))->toBe(0.25);
});

it('preserves genuine personal zero conversions', function (): void {
    ($this->load)();
    expect(($this->convert)(['buckets' => ['B' => ['sog' => 4, 'goals' => 0]]]))->toBe(0.0);
});

it('preserves personal recent SOG conversion instead of replacing it with smoothed league counts', function (): void {
    ($this->load)();
    expect(($this->convert)(['buckets' => ['B' => ['sat' => 4, 'sog' => 3]]], 'PP', 'sat'))->toBe(0.75);
});

it('changes only the league part of a supported low-volume EV blend', function (): void {
    ($this->load)();
    $personal = ['buckets' => ['B' => ['sat' => 10, 'sog' => 4, 'goals' => 1]]];
    $team = ['buckets' => ['B' => ['sat' => 100, 'sog' => 20, 'goals' => 2]]];
    $result = (new ReflectionMethod($this->reader, 'evGoalConversion'))->invoke($this->reader, $personal, $team, $this->raw, 'B');
    expect($result['probability'])->toEqualWithDelta(0.47 * 0.25 + 0.11 * 0.1 + 0.42 * 0.19, 0.000001)
        ->and($result['evidence']['weights'])->toBe(['player' => 0.47, 'team' => 0.11, 'league' => 0.42])
        ->and($result['evidence']['league_source'])->toBe('smoothed_train_league_bucket');
});

it('keeps the existing twelve-SAT personal EV threshold', function (): void {
    ($this->load)();
    $personal = ['buckets' => ['B' => ['sat' => 12, 'sog' => 4, 'goals' => 1]]];
    $result = (new ReflectionMethod($this->reader, 'evGoalConversion'))->invoke($this->reader, $personal, [], $this->raw, 'B');
    expect($result['probability'])->toBe(0.25)->and($result['source'])->toBe('train_player_bucket');
});

it('uses raw matching strength when a profile strength is missing', function (): void {
    DB::table('nhl_sat_model_entity_profile_buckets')->where('strength', 'pk')->delete();
    ($this->load)();
    expect(($this->convert)(null, 'PK'))->toBe(0.1);
});

it('uses the same-strength pooled goal reference when the bucket has no SOG denominator', function (): void {
    ($this->load)();
    expect(($this->convert)(null, 'PK', 'sog', 'MISSING'))->toEqualWithDelta(0.39, 0.000001);
});

it('does not invent a SOG conversion for an unsupported SAT bucket', function (): void {
    ($this->load)();
    expect(fn () => ($this->convert)(null, 'PK', 'sat', 'MISSING'))->toThrow(DomainException::class);
});

it('retains raw fallback when an otherwise valid profile pool has no requested denominator', function (): void {
    DB::table('nhl_sat_model_entity_profile_buckets')->where('strength', 'pk')
        ->update(['source_sog' => 0, 'expected_goals' => 0]);
    ($this->load)();
    expect(($this->convert)(null, 'PK'))->toBe(0.1);
});

it('archives the same frozen profile reference across checkpoint restoration', function (): void {
    $loaded = ($this->load)();
    $reader = new NhlCustomGamePredictionInputs();
    $reader->restoreCheckpointState($this->reader->checkpointState());
    expect($reader->checkpointState()['conversionReferences'])->toBe($loaded);
});

it('changes prepared-input identity when the offensive source revision changes', function (): void {
    $before = NhlCustomGamePredictionInputs::conversionProfileIdentity($this->run);
    $this->evaluations['sat']->update(['trained_at' => now()]);
    expect(NhlCustomGamePredictionInputs::conversionProfileIdentity($this->run))->not->toBe($before);
});

it('keeps profile identity stable across unrelated goalie progress changes', function (): void {
    $before = NhlCustomGamePredictionInputs::conversionProfileIdentity($this->run);
    $metrics = $this->run->metrics;
    $metrics['profile_build'] = ['id' => 'goalies', 'status' => 'running', 'profile_types' => ['goalie_faced']];
    $this->run->update(['metrics' => $metrics]);
    expect(NhlCustomGamePredictionInputs::conversionProfileIdentity($this->run->fresh()))->toBe($before);
});

it('versions the integration while retaining neutral goalies and the approved PP total allocation', function (): void {
    $definition = (new NhlCustomGamePredictionEngine())->definition();
    expect($definition['algorithm_version'])->toBe('custom_ev_pp_pk_v6')
        ->and($definition['goalie_multiplier'])->toBe(1.0)
        ->and($definition['pp_allocation'])->toBe('conditional_split_scaled_to_75_offense_25_defense_total');
    Bus::assertNothingDispatched();
});
