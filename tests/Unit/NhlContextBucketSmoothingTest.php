<?php

declare(strict_types=1);

use App\Services\NhlExpectedGoalsBackfiller;

beforeEach(function (): void {
    $this->row = static fn (int $attempts, int $successes, array $dimensions, int $level = 1): array => [
        'attempts' => $attempts, 'successes' => $successes, 'dimensions' => $dimensions, 'level' => $level,
    ];
    $this->stats = [
        'near' => ($this->row)(20, 10, ['shot_type_group' => 'wrist', 'distance_group' => 'near']),
        'far' => ($this->row)(80, 30, ['shot_type_group' => 'wrist', 'distance_group' => 'far']),
        'other' => ($this->row)(100, 90, ['shot_type_group' => 'slap', 'distance_group' => 'near']),
        'baseline' => ($this->row)(200, 130, ['baseline' => 'league'], 99),
    ];
    $this->smooth = static function (array $stats, int $prior = 100, float $baseline = 0.65, ?string $factor = 'shot_type_group'): array {
        $service = new NhlExpectedGoalsBackfiller();

        return (new ReflectionMethod($service, 'trainedBuckets'))->invoke($service, $stats, $prior, $baseline, $factor);
    };
});

it('borrows the matching shot type rate instead of the whole strength rate', function (): void {
    expect(($this->smooth)($this->stats)['near']['smoothed_probability'])->toEqualWithDelta(50 / 120, 0.000001);
});

it('does not borrow another shot type outcome distribution', function (): void {
    $this->stats['other']['successes'] = 0;
    expect(($this->smooth)($this->stats)['near']['smoothed_probability'])->toEqualWithDelta(50 / 120, 0.000001);
});

it('uses distance parents for SOG to goal training', function (): void {
    $rows = ($this->smooth)($this->stats, factor: 'distance_group');
    expect($rows['near']['smoothed_probability'])->toEqualWithDelta((10 + 100 * (100 / 120)) / 120, 0.000001);
});

it('does not borrow other distances when smoothing goal conversion', function (): void {
    $this->stats['far']['successes'] = 80;
    expect(($this->smooth)($this->stats, factor: 'distance_group')['near']['smoothed_probability'])
        ->toEqualWithDelta((10 + 100 * (100 / 120)) / 120, 0.000001);
});

it('weights parent evidence by actual attempts rather than averaging child rates', function (): void {
    expect(($this->smooth)($this->stats)['far']['smoothed_probability'])->toEqualWithDelta(70 / 180, 0.000001);
});

it('does not count overlapping fallback rows in the parent sample', function (): void {
    $this->stats['fallback'] = ($this->row)(10000, 10000, ['shot_type_group' => 'wrist'], 2);
    expect(($this->smooth)($this->stats)['near']['smoothed_probability'])->toEqualWithDelta(50 / 120, 0.000001);
});

it('does not count the combined baseline again as parent evidence', function (): void {
    $this->stats['baseline']['successes'] = 0;
    expect(($this->smooth)($this->stats)['near']['smoothed_probability'])->toEqualWithDelta(50 / 120, 0.000001);
});

it('keeps the raw baseline free of smoothing', function (): void {
    expect(($this->smooth)($this->stats)['baseline']['smoothed_probability'])->toBe(0.65);
});

it('preserves the configured confidence calculation', function (): void {
    expect(($this->smooth)($this->stats)['near']['confidence_score'])->toEqualWithDelta(20 / 120, 0.000001);
});

it('preserves the configured borrowed evidence share', function (): void {
    expect(($this->smooth)($this->stats)['near']['shrinkage_weight'])->toEqualWithDelta(100 / 120, 0.000001);
});

it('keeps the baseline confidence at one and shrinkage at zero', function (): void {
    $row = ($this->smooth)($this->stats)['baseline'];
    expect($row['confidence_score'])->toBe(1.0)->and($row['shrinkage_weight'])->toBe(0.0);
});

it('preserves actual counts and raw rates', function (): void {
    $row = ($this->smooth)($this->stats)['near'];
    expect($row['attempts'])->toBe(20)->and($row['successes'])->toBe(10)->and($row['raw_success_rate'])->toBe(0.5);
});

it('uses raw probabilities when smoothing is explicitly disabled', function (): void {
    expect(($this->smooth)($this->stats, prior: 0)['near']['smoothed_probability'])->toBe(0.5);
});

it('retains legacy global smoothing without a contextual policy', function (): void {
    expect(($this->smooth)($this->stats, factor: null)['near']['smoothed_probability'])->toBe(0.625);
});

it('keeps unknown shot type evidence separate from named shot types', function (): void {
    $this->stats['unknown'] = ($this->row)(133, 1, ['shot_type_group' => 'unknown', 'distance_group' => 'near']);
    expect(($this->smooth)($this->stats)['unknown']['smoothed_probability'])->toEqualWithDelta(1 / 133, 0.000001);
});

it('uses the first selected dimension when the preferred parent is not selected', function (): void {
    $stats = [
        'a' => ($this->row)(10, 1, ['angle_group' => 'low', 'distance_group' => 'near']),
        'b' => ($this->row)(30, 15, ['angle_group' => 'low', 'distance_group' => 'far']),
    ];
    expect(($this->smooth)($stats)['a']['smoothed_probability'])->toEqualWithDelta(41 / 110, 0.000001);
});

it('keeps a single context group at its observed rate without inventing sibling evidence', function (): void {
    expect(($this->smooth)(['one' => $this->stats['near']])['one']['smoothed_probability'])->toBe(0.5);
});

it('falls back to the same strength baseline when no parent evidence exists', function (): void {
    $stats = ['broad' => ($this->row)(20, 10, ['shot_type_group' => 'wrist'], 2)];
    expect(($this->smooth)($stats)['broad']['smoothed_probability'])->toBe(0.625);
});

it('keeps measured zeros when a matching parent has no successes', function (): void {
    $this->stats['near']['successes'] = 0;
    $this->stats['far']['successes'] = 0;
    expect(($this->smooth)($this->stats)['near']['smoothed_probability'])->toBe(0.0);
});

it('isolates independent strength samples even when the shape keys match', function (): void {
    $pk = ['a' => ($this->row)(100, 1, ['shot_type_group' => 'wrist'])];
    $pp = ['a' => ($this->row)(100, 80, ['shot_type_group' => 'wrist'])];
    expect(($this->smooth)($pk)['a']['smoothed_probability'])->toBe(0.01)
        ->and(($this->smooth)($pp)['a']['smoothed_probability'])->toBe(0.8);
});

it('keeps probabilities within zero and one at both outcome extremes', function (): void {
    $stats = [
        'zero' => ($this->row)(10, 0, ['shot_type_group' => 'unknown']),
        'one' => ($this->row)(10, 10, ['shot_type_group' => 'wrist']),
    ];
    $rows = ($this->smooth)($stats);
    expect($rows['zero']['smoothed_probability'])->toBe(0.0)->and($rows['one']['smoothed_probability'])->toBe(1.0);
});
