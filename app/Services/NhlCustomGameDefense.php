<?php

declare(strict_types=1);

namespace App\Services;

use DomainException;

/** Train-only opponent-strength normalization for the custom defensive allowance ratio. */
class NhlCustomGameDefense
{
    private array $rates = [];

    private array $offense = [];

    private array $defense = [];

    private array $defensiveMinutes = [];

    private array $fitSeconds = [];

    private array $fitScored = [];

    private array $fitAllowed = [];

    private ?array $fitKeys = null;

    private int $fitCursor = 0;

    /** Export fit state without serializing a large enclosing prediction reader.
     * @return array<string,mixed>
     */
    public function checkpointState(): array
    {
        return array_intersect_key(get_object_vars($this), get_class_vars(self::class));
    }

    /** Restore only this class's existing fit fields; no fitting or equations run here.
     * @param array<string,mixed> $state
     */
    public function restoreCheckpointState(array $state): void
    {
        foreach (array_intersect_key($state, get_class_vars(self::class)) as $field => $value) {
            $this->{$field} = $value;
        }
    }

    /** Fit the same Train equation synchronously for explicit standalone replay callers. */
    public function fit(iterable $records): void
    {
        $this->beginFit();
        $this->collectFit($records);
        while (! $this->finishFitPage(8)) {
            // Each bucket converges independently using exactly the same equation.
        }
    }

    /** Reset the serializable Train-fit accumulator before its first bounded page. */
    public function beginFit(): void
    {
        $this->rates = $this->offense = $this->defense = $this->defensiveMinutes = [];
        $this->fitSeconds = $this->fitScored = $this->fitAllowed = [];
        $this->fitKeys = null;
        $this->fitCursor = 0;
    }

    /** Collect a bounded raw-result page without fitting or retaining its source rows. */
    public function collectFit(iterable $records): void
    {
        foreach ($records as $record) {
            if (! $record['valid']) {
                continue;
            }
            foreach (['EV', 'PP'] as $lane) {
                $minutes = ($record['clock'][$lane] ?? 0) / 60;
                if ($minutes <= 0) {
                    continue;
                }
                $team = $record['team'];
                $opponent = $record['opponent'];
                $this->fitSeconds[$lane][$team][$opponent] = ($this->fitSeconds[$lane][$team][$opponent] ?? 0) + $minutes;
                $this->defensiveMinutes[$lane][$opponent] = ($this->defensiveMinutes[$lane][$opponent] ?? 0) + $minutes;
                foreach ($record['counts'][$lane] ?? [] as $key => $values) {
                    $this->fitScored[$lane][$team][$key] = ($this->fitScored[$lane][$team][$key] ?? 0) + $values['sat'];
                    $this->fitAllowed[$lane][$opponent][$key] = ($this->fitAllowed[$lane][$opponent][$key] ?? 0) + $values['sat'];
                }
            }
        }
    }

    /** Fit at most the requested number of complete buckets and retain a retryable cursor. */
    public function finishFitPage(int $limit): bool
    {
        if ($this->fitKeys === null) {
            $this->fitKeys = [];
            foreach (['EV', 'PP'] as $lane) {
                $minutes = array_sum(array_map('array_sum', $this->fitSeconds[$lane] ?? []));
                if ($minutes <= 0) {
                    throw new DomainException('Train defensive clocks are unavailable for '.$lane.'.');
                }
                $totals = [];
                foreach ($this->fitScored[$lane] ?? [] as $buckets) {
                    foreach ($buckets as $key => $count) {
                        $totals[$key] = ($totals[$key] ?? 0) + $count;
                    }
                }
                foreach ($totals as $key => $count) {
                    $this->fitKeys[] = compact('lane', 'key', 'count', 'minutes');
                }
            }
        }
        $prior = (float) config('nhl-custom-prediction.defense.prior_minutes');
        $page = array_slice($this->fitKeys, $this->fitCursor, max(1, $limit));
        foreach ($page as $bucket) {
            ['lane' => $lane, 'key' => $key, 'count' => $count, 'minutes' => $minutes] = $bucket;
            $pairs = $this->fitSeconds[$lane] ?? [];
            $ids = array_unique([...array_keys($pairs), ...array_keys($this->fitAllowed[$lane] ?? [])]);
            $rate = $count / $minutes;
            $this->rates[$lane][$key] = $rate;
            $attack = $defend = array_fill_keys($ids, 1.0);
            for ($iteration = 0; $iteration < config('nhl-custom-prediction.defense.fit_iterations'); $iteration++) {
                $change = 0.0;
                foreach ($ids as $team) {
                    $exposure = $prior;
                    foreach ($pairs[$team] ?? [] as $opponent => $time) {
                        $exposure += $time * $defend[$opponent];
                    }
                    $value = (($this->fitScored[$lane][$team][$key] ?? 0) + $prior * $rate) / ($rate * $exposure);
                    $change = max($change, abs($value - $attack[$team]));
                    $attack[$team] = $value;
                }
                foreach ($ids as $team) {
                    $exposure = $prior;
                    foreach ($pairs as $opponent => $opponents) {
                        $exposure += ($opponents[$team] ?? 0) * $attack[$opponent];
                    }
                    $value = (($this->fitAllowed[$lane][$team][$key] ?? 0) + $prior * $rate) / ($rate * $exposure);
                    $change = max($change, abs($value - $defend[$team]));
                    $defend[$team] = $value;
                }
                if ($change < config('nhl-custom-prediction.defense.fit_tolerance')) {
                    break;
                }
            }
            foreach ($ids as $team) {
                $this->offense[$lane][$team][$key] = $attack[$team];
                $this->defense[$lane][$team][$key] = $defend[$team];
            }
        }
        $this->fitCursor += count($page);

        return $this->fitCursor >= count($this->fitKeys);
    }

    /** Return the allowance selected by the defensive history fallback chain. */
    public function allowance(int $team, string $lane, string $key, ?array $recent = null): float
    {
        return $this->resolveAllowance($team, $lane, $key, $recent)['value'];
    }

    /** Resolve matching recent, team Train, then league Train evidence with provenance. */
    public function resolveAllowance(int $team, string $lane, string $key, ?array $recent = null): array
    {
        $rate = $this->rates[$lane][$key] ?? 0.0;
        if ($rate <= 0) {
            if (($this->rates[$lane] ?? []) === []) {
                throw new DomainException('Matching Train league defensive strength evidence is unavailable.');
            }

            // With no Train attempts in this bucket there is no supported
            // defensive departure from the league reference of one.
            return ['value' => 1.0, 'source' => 'league_train'];
        }
        $fallback = ($this->defensiveMinutes[$lane][$team] ?? 0) > 0
            && isset($this->defense[$lane][$team][$key])
            ? ['value' => $this->defense[$lane][$team][$key], 'source' => 'team_train']
            : ['value' => 1.0, 'source' => 'league_train'];
        if ($recent === null || $recent === []) {
            return $fallback;
        }
        $prior = (float) config('nhl-custom-prediction.defense.prior_minutes');
        $observed = 0.0;
        $exposure = $prior;
        $minutes = 0.0;
        foreach ($recent as $record) {
            $seconds = $record['clock'][$lane === 'PP' ? 'PK' : 'EV'] ?? null;
            if (! ($record['valid'] ?? false) || ! is_numeric($seconds)
                || ! is_finite((float) $seconds) || $seconds < 0) {
                return $fallback;
            }
            $opponent = $record['opponent'];
            $count = $record['against'][$lane][$key]['sat'] ?? 0;
            if (! is_numeric($count) || ! is_finite((float) $count) || $count < 0
                || ($seconds == 0 && $count > 0)) {
                return $fallback;
            }
            $observed += $count;
            $minutes += $seconds / 60;
            $exposure += $seconds / 60
                * ($this->offense[$lane][$opponent][$key] ?? 1.0);
        }

        return $minutes > 0
            ? ['value' => ($observed + $prior * $rate) / ($rate * $exposure), 'source' => 'team_recent']
            : $fallback;
    }

    /** Expose the fitted source for immutable run provenance, never refit from Test. */
    public function snapshot(): array
    {
        return ['bucket_rates_per_minute' => $this->rates,
            'offense_strengths' => $this->offense, 'defense_allowances' => $this->defense];
    }
}
