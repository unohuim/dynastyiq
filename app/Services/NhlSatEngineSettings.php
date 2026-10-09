<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Validates independent weights and constructs bounded discovery candidates. */
class NhlSatEngineSettings
{
    /** @return array<string, int|float> */
    public function defaults(): array
    {
        return ['offense' => 88, 'defense' => 2, 'confidence_min' => 67, 'confidence_max' => 70, 'gap' => 0];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function validate(array $input): array
    {
        return Validator::make($input, [
            'offense' => 'required|numeric|between:0,200',
            'defense' => 'required|numeric|between:0,200',
            'confidence_min' => 'required|integer|between:0,100',
            'confidence_max' => 'required|integer|between:0,100|gte:confidence_min',
            'gap' => 'required|numeric|between:0,' . (($input['gap_unit'] ?? 'goals') === 'percent' ? '100' : '10'),
            'gap_unit' => 'sometimes|in:goals,percent',
            'gap_operator' => 'sometimes|in:>,<',
        ])->validate();
    }

    /** @param array<string, mixed> $settings */
    public function qualifies(int $confidence, float $gap, array $settings): bool
    {
        return $confidence >= $settings['confidence_min'] && $confidence <= $settings['confidence_max']
            && $this->gapQualifies($gap, $settings);
    }

    /** Compare in the settings' stored units; absent metadata preserves legacy engines. */
    public function gapQualifies(float $gap, array $settings): bool
    {
        return ($settings['gap_operator'] ?? '>') === '<'
            ? $gap < (float) $settings['gap'] : $gap > (float) $settings['gap'];
    }

    /** Normalize score separation without changing the predicted winner or score. */
    public function scoreGap(float $away, float $home, string $unit = 'goals'): float
    {
        if ($unit !== 'percent') {
            return abs($home - $away);
        }

        return $away + $home > 0 ? round(100 * abs($home - $away) / ($away + $home), 6) : 0.0;
    }

    /** Validate independent optional discovery bounds, preserving explicit zero values. */
    public function discoveryConstraints(array $input): array
    {
        $input = array_map(fn ($value) => $value === '' ? null : $value, $input);

        return Validator::make($input, [
            'offense' => 'nullable|integer|between:0,200',
            'defense' => 'nullable|integer|between:0,200',
            'confidence_min' => 'nullable|integer|between:0,100',
            'confidence_max' => ['nullable', 'integer', 'between:0,100',
                ...(isset($input['confidence_min']) ? ['gte:confidence_min'] : [])],
            'gap' => 'nullable|integer|between:0,100',
            'gap_operator' => 'sometimes|in:>,<',
        ])->validate();
    }

    /** Fixed starting pair; only stage three varies unspecified weights. */
    public function constrainedCandidates(array $constraints, int $weightStage = -1, array $centers = [], array $evaluated = []): array
    {
        $rows = $weightStage < 0
            ? [['offense' => $constraints['offense'] ?? 100, 'defense' => $constraints['defense'] ?? 50]]
            : $this->automaticCandidates($weightStage, $centers);
        $seen = array_fill_keys(array_map(fn ($row) => $row['offense'] . ':' . $row['defense'], $evaluated), true);
        $result = [];
        foreach ($rows as $row) {
            $row['offense'] = $constraints['offense'] ?? $row['offense'];
            $row['defense'] = $constraints['defense'] ?? $row['defense'];
            $key = $row['offense'] . ':' . $row['defense'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = [...$row, 'confidence_min' => $constraints['confidence_min'] ?? 0,
                'confidence_max' => $constraints['confidence_max'] ?? 100,
                'gap' => $constraints['gap'] ?? 0, 'gap_unit' => 'percent',
                'gap_operator' => isset($constraints['gap']) ? ($constraints['gap_operator'] ?? '>') : '>'];
        }

        return $result;
    }

    /** Server-owned broad search, followed by two bounded refinements of distinct promising splits.
     * @param list<array<string, mixed>> $centers
     * @param list<array<string, mixed>> $evaluated
     * @return list<array<string, int>>
     */
    public function automaticCandidates(int $stage = 0, array $centers = [], array $evaluated = []): array
    {
        if ($stage < 0 || $stage > 2) {
            return [];
        }
        $step = [25, 5, 1][$stage];
        $radius = [200, 25, 5][$stage];
        $centers = $stage === 0 ? [['offense' => 0, 'defense' => 0]] : array_slice($centers, 0, 3);
        $seen = [];
        foreach ($evaluated as $row) {
            $seen[(int) $row['offense'] . ':' . (int) $row['defense']] = true;
        }
        $rows = [];
        foreach ($centers as $center) {
            for ($offense = max(0, (int) $center['offense'] - $radius); $offense <= min(200, (int) $center['offense'] + $radius); $offense += $step) {
                for ($defense = max(0, (int) $center['defense'] - $radius); $defense <= min(200, (int) $center['defense'] + $radius); $defense += $step) {
                    $key = $offense . ':' . $defense;
                    if (! isset($seen[$key])) {
                        $rows[] = ['offense' => $offense, 'defense' => $defense, 'gap' => 0,
                            'confidence_min' => 0, 'confidence_max' => 100];
                        $seen[$key] = true;
                    }
                }
            }
        }

        return $rows;
    }

    /** Generate inclusive decimal ranges without floating point stepping drift.
     * @param array<string, mixed> $range @return list<float>
     */
    public function values(array $range, float $maximum, float $precision = 1): array
    {
        $range = Validator::make($range, [
            'min' => ['required', 'numeric', 'min:0', 'max:' . $maximum],
            'max' => ['required', 'numeric', 'gte:min', 'max:' . $maximum],
            'step' => ['required', 'numeric', 'min:' . $precision, 'max:' . max(1, $maximum)],
        ])->validate();
        $scale = (int) round(1 / $precision);
        foreach ($range as $value) {
            if (abs((float) $value * $scale - round((float) $value * $scale)) > 0.000001) {
                throw ValidationException::withMessages(['search' => 'Use whole percentages and score-gap increments of 0.1.']);
            }
        }
        $values = [];
        for ($i = (int) round($range['min'] * $scale); $i <= (int) round($range['max'] * $scale); $i += (int) round($range['step'] * $scale)) {
            $values[] = $i / $scale;
        }

        return $values;
    }

    /** Build bounded weight/gap work units; confidence is discovered from saved predictions.
     * @param array<string, array<string, mixed>> $search @return list<array<string, mixed>>
     */
    public function candidates(array $search): array
    {
        $axes = [];
        foreach (['offense' => 200, 'defense' => 200, 'gap' => 10] as $key => $max) {
            $axes[$key] = $this->values($search[$key] ?? [], $max, $key === 'gap' ? 0.1 : 1);
        }
        if (count($axes['offense']) * count($axes['defense']) > 100
            || array_product(array_map('count', $axes)) > 10000) {
            throw ValidationException::withMessages(['search' => 'Narrow the search to at most 100 weight pairs and 10,000 weight/gap combinations. Confidence ranges are searched automatically.']);
        }
        $rows = [[]];
        foreach ($axes as $key => $values) {
            $next = [];
            foreach ($rows as $row) {
                foreach ($values as $value) {
                    $next[] = [...$row, $key => $value];
                }
            }
            $rows = $next;
        }
        return array_map(fn (array $row): array => [...$row, 'confidence_min' => 0, 'confidence_max' => 100], $rows);
    }

    /** Evaluate nonempty confidence intervals of at most three score values and keep the win/coverage frontier.
     * Empty confidence levels cannot change a selection, so equivalent bounds are tightened
     * to observed scores. A discarded interval has no better win rate or coverage than a retained one.
     * @param list<array{confidence: int, picks: int, wins: int}> $groups
     * @return list<array{confidence_min: int, confidence_max: int, picks: int, wins: int}>
     */
    public function confidenceFrontier(array $groups): array
    {
        usort($groups, fn (array $a, array $b): int => $a['confidence'] <=> $b['confidence']);
        $bestByCount = [];
        $length = count($groups);
        for ($low = 0; $low < $length; $low++) {
            $picks = $wins = 0;
            for ($high = $low; $high < $length && $groups[$high]['confidence'] - $groups[$low]['confidence'] <= 2; $high++) {
                $picks += (int) $groups[$high]['picks'];
                $wins += (int) $groups[$high]['wins'];
                if ($picks < 1) {
                    continue;
                }
                $candidate = ['confidence_min' => (int) $groups[$low]['confidence'],
                    'confidence_max' => (int) $groups[$high]['confidence'], 'picks' => $picks, 'wins' => $wins];
                $previous = $bestByCount[$picks] ?? null;
                if ($previous === null || $wins > $previous['wins']
                    || ($wins === $previous['wins'] && $candidate['confidence_max'] - $candidate['confidence_min']
                        < $previous['confidence_max'] - $previous['confidence_min'])) {
                    $bestByCount[$picks] = $candidate;
                }
            }
        }
        krsort($bestByCount);
        $frontier = [];
        $best = null;
        foreach ($bestByCount as $candidate) {
            if ($best === null || $candidate['wins'] * $best['picks'] > $best['wins'] * $candidate['picks']) {
                $frontier[] = $candidate;
                $best = $candidate;
            }
        }

        return $frontier;
    }
}
