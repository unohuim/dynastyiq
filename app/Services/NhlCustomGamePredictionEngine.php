<?php

declare(strict_types=1);

namespace App\Services;

use DomainException;

/** Pure EV/PP calculation shared by custom replay and independently supplied future inputs. */
class NhlCustomGamePredictionEngine
{
    /** Freeze the complete calculation definition into each custom run. */
    public function definition(): array
    {
        $settings = config('nhl-custom-prediction');
        if (! is_array($settings) || ! isset($settings['algorithm_version'], $settings['reference_train_seasons'])) {
            throw new DomainException('Load the new custom prediction configuration before running the engine.');
        }

        return [
            ...$settings,
            'strengths' => ['EV', 'PP'],
            'sog_sources' => ['EV' => 'last_20', 'PP' => 'last_5'],
            'goal_sources' => ['EV' => 'last_season', 'PP' => 'season_to_date'],
            'goalie_multiplier' => 1.0,
            'player_usage' => 'last_10_normalized_over_full_lineup',
            'sat_fallback' => 'frozen_retained_train_league_by_strength',
            'defense_fallback' => ['team_recent', 'team_train', 'league_train'],
            'sog_fallback' => 'personal_bucket_then_train_bucket_only',
            'goal_fallback' => 'personal_bucket_then_train_bucket_then_train_strength',
            'probability' => 'independent_poisson_with_equal_overtime_split',
            'pp_allocation' => 'conditional_split_scaled_to_75_offense_25_defense_total',
            'short_handed_goals' => false,
        ];
    }

    /** Calculate both sides without reading results, querying providers or persisting records. */
    public function predict(array $input, bool $withSchedule = true): array
    {
        $definition = $this->definition();
        $sides = $input['teams'] ?? [];
        if (array_keys($sides) !== ['away', 'home']) {
            throw new DomainException('Custom inputs require separate away and home teams.');
        }
        if (($sides['away']['id'] ?? null) === ($sides['home']['id'] ?? null)) {
            throw new DomainException('Home and away must be different teams.');
        }
        $retained = array_fill_keys($input['retained_bucket_keys'] ?? [], true);
        if ($retained === []) {
            throw new DomainException('Freeze the retained Train bucket keys before predicting.');
        }
        $shareTotal = 0.0;
        foreach ($sides as $side) {
            $shareTotal += $this->probability($side['train_opportunity_share'] ?? null);
            if (($side['players'] ?? []) === []) {
                throw new DomainException('A complete expected lineup is required.');
            }
        }
        if ($shareTotal <= 0) {
            throw new DomainException('Combined Train opportunity shares have no positive denominator.');
        }
        $form = $this->teamForm($sides, $this->probability($definition['team_l5_win_pct_weight'] ?? null));
        $opportunities = [];
        foreach (['away' => 'home', 'home' => 'away'] as $venue => $opponent) {
            $base = $definition['initial_game_pp_opportunities'] * $sides[$venue]['train_opportunity_share'] / $shareTotal;
            $allowed = $sides[$opponent]['pk_opportunities_l10'] ?? null;
            $opportunities[$venue] = $allowed === null ? $base
                : (1 - $definition['opponent_opportunity_weight']) * $base
                    + $definition['opponent_opportunity_weight'] * $this->nonnegative($allowed, 'L10 PK opportunities');
        }
        $pp = array_map(fn (float $opps): float => $opps * $definition['pp_minutes_per_opportunity'] * 60, $opportunities);
        $ev = 3600 - array_sum($pp);
        if ($ev <= 0) {
            throw new DomainException('Projected special-teams time consumes regulation time.');
        }
        // Observed coverage remains diagnostic; source/usage fallbacks complete
        // the calculation instead of admitting or rejecting games by coverage.
        $teams = [];
        foreach (['away' => 'home', 'home' => 'away'] as $venue => $opponent) {
            $side = $sides[$venue];
            $qualifies = $withSchedule && $this->fourthGameBackToBack($side['schedule'] ?? []);
            $weights = ['EV' => [], 'PP' => []];
            foreach ($side['players'] as $id => $player) {
                foreach (['EV', 'PP'] as $lane) {
                    $weights[$lane][$id] = $this->nonnegative(
                        $player['usage'][$lane] ?? $input['league_usage_seconds'][$lane] ?? null,
                        'Player usage or Train usage fallback'
                    );
                }
            }
            $team = [
                'id' => (int) $side['id'], 'abbrev' => $side['abbrev'], 'venue' => $venue,
                'context_id' => $side['context_id'] ?? null,
                'starting_goalie_id' => $side['starting_goalie_id'] ?? null,
                'lineup_source' => $side['lineup_source'],
                'pp_opportunities' => $opportunities[$venue],
                'time' => ['EV' => $ev, 'PP' => $pp[$venue], 'PK' => $pp[$opponent]],
                'schedule_adjustment_applied' => $qualifies,
                'ev_form_multiplier' => $form['multipliers'][$venue],
                'players' => [], 'strengths' => ['EV' => $this->emptyTotals(), 'PP' => $this->emptyTotals()],
            ];
            foreach ($side['players'] as $id => $player) {
                if (! in_array($player['role'] ?? null, ['F', 'D'], true)) {
                    throw new DomainException('Custom skater roles must be F or D.');
                }
                $prediction = ['id' => (int) $id, 'role' => $player['role'], 'name' => $player['name'],
                    'line_key' => $player['line_key'] ?? null,
                    'context_id' => $player['context_id'] ?? null, 'strengths' => []];
                foreach (['EV', 'PP'] as $lane) {
                    $totalWeight = array_sum($weights[$lane]);
                    if ($totalWeight <= 0) {
                        throw new DomainException('Lineup usage has no positive denominator.');
                    }
                    $share = $weights[$lane][$id] / $totalWeight;
                    $seconds = 5 * $team['time'][$lane] * $share;
                    $multiplier = $qualifies
                        ? $definition['schedule'][$player['role'] === 'D' ? 'defense_sat_multiplier' : 'forward_sat_multiplier'] : 1.0;
                    $formMultiplier = $lane === 'EV' ? $form['multipliers'][$venue] : 1.0;
                    $line = [...$this->emptyTotals(), 'share' => $share, 'seconds' => $seconds,
                        'schedule_multiplier' => $multiplier, 'form_multiplier' => $formMultiplier, 'buckets' => []];
                    foreach ($player['buckets'][$lane] ?? [] as $key => $bucket) {
                        if (! isset($retained[$key])) {
                            throw new DomainException('A forecast bucket is outside the frozen Train-confidence filter.');
                        }
                        $rate = $this->nonnegative($bucket['sat_per_60'] ?? null, 'SAT/60');
                        $allowance = $this->positive($bucket['defense_allowance'] ?? null, 'Defensive allowance');
                        $reference = $this->positive($bucket['defense_reference'] ?? null, 'Historical defensive reference');
                        $defense = ($allowance / $reference) ** $definition['defense']['coefficients'][$lane];
                        $sat = $rate * $seconds / 3600 * $defense * $multiplier * $formMultiplier;
                        $sog = $sat * $this->probability($bucket['sog_probability'] ?? null);
                        $goals = $sog * $this->probability($bucket['goal_probability'] ?? null);
                        $line['buckets'][$key] = ['sat' => $sat, 'sog' => $sog, 'goals' => $goals,
                            'defense_allowance_source' => $bucket['defense_allowance_source'] ?? null,
                            'defense_reference_sources' => $bucket['defense_reference_sources'] ?? [],
                            'defense_multiplier' => $defense, 'schedule_multiplier' => $multiplier,
                            'form_multiplier' => $formMultiplier];
                        foreach (['sat', 'sog', 'goals'] as $metric) {
                            $line[$metric] += $line['buckets'][$key][$metric];
                        }
                    }
                    $prediction['strengths'][$lane] = $line;
                    foreach (['sat', 'sog', 'goals'] as $metric) {
                        $team['strengths'][$lane][$metric] += $line[$metric];
                    }
                }
                $team['players'][$id] = $prediction;
            }
            $teams[$venue] = $team;
        }
        $this->allocatePowerPlayGoals($teams, $sides, $this->probability($input['league_pk_probability'] ?? null));
        foreach ($teams as &$team) {
            $team['goals'] = $team['strengths']['EV']['goals'] + $team['strengths']['PP']['goals'];
            $team['sat'] = $team['strengths']['EV']['sat'] + $team['strengths']['PP']['sat'];
            $team['sog'] = $team['strengths']['EV']['sog'] + $team['strengths']['PP']['sog'];
        }
        unset($team);
        $probabilities = $this->winProbabilities($teams['home']['goals'], $teams['away']['goals']);
        $probabilityDelta = $withSchedule && $this->fourthGameBackToBack($sides['away']['schedule'] ?? [])
            ? $definition['schedule']['away_fourth_b2b_home_probability_delta'] : 0.0;
        $homeProbability = max(0.0, min(1.0, $probabilities['home'] + $probabilityDelta));
        $confidence = $this->internalConfidence($input, $teams);

        return [
            'game_id' => (int) $input['game_id'], 'source_cutoff_at' => $input['source_cutoff_at'],
            'teams' => $teams, 'total_goals' => $teams['home']['goals'] + $teams['away']['goals'],
            'home_win_probability' => $homeProbability, 'away_win_probability' => 1 - $homeProbability,
            'home_probability_schedule_delta' => $probabilityDelta,
            'team_form_adjustment' => $form,
            'base_home_win_probability' => $probabilities['home'],
            'regulation_tie_probability' => $probabilities['tie'],
            'recommendation' => abs($homeProbability - 0.5) < 1.0e-12 ? 'OT'
                : $teams[$homeProbability > 0.5 ? 'home' : 'away']['abbrev'],
            'confidence_score' => $confidence['score'],
            'confidence_inputs' => ['input_completeness' => $this->completeness($sides),
                'effective_input_completeness' => ['EV' => 1.0, 'PP' => 1.0],
                'calibration' => 'unavailable', 'schedule_enabled' => $withSchedule,
                'teams' => $confidence['teams']],
        ];
    }

    /** Reconnect the unchanged internal input-quality helper after score and winner calculation.
     * @param array<string,mixed> $input
     * @param array<string,array<string,mixed>> $teams
     * @return array{score:int,teams:array<string,array<string,int|float|bool>>}
     */
    private function internalConfidence(array $input, array &$teams): array
    {
        $helper = app(NhlGamePredictionPayload::class);
        $sides = $goalies = $components = [];
        foreach (['away' => 'home', 'home' => 'away'] as $venue => $opponent) {
            $roster = [];
            foreach ($teams[$venue]['players'] as $id => $player) {
                $roster[] = ['adjusted_xgf_per_game' => $player['strengths']['EV']['goals'] + $player['strengths']['PP']['goals'],
                    'confidence_score' => $input['teams'][$venue]['players'][$id]['confidence_score'] ?? null];
                $teams[$venue]['players'][$id]['confidence_score'] = $input['teams'][$venue]['players'][$id]['confidence_score'] ?? null;
            }
            $evidence = $input['teams'][$venue]['goalie_confidence_buckets'] ?? null;
            $weights = [];
            foreach ($teams[$opponent]['players'] as $player) {
                foreach ($player['strengths']['EV']['buckets'] as $key => $bucket) {
                    $weights[] = (object) ['projected_sata' => $bucket['sat'],
                        'source_confidence_score' => $evidence[$key]['confidence_score'] ?? 0.0];
                }
            }
            $goalieScore = $evidence === null ? null : round(100 * app(NhlHistoricalPredictionService::class)
                ->confidence(collect($weights), 'projected_sata', 'source_confidence_score'));
            $sides[$venue] = ['roster' => $roster];
            $goalies[$venue] = ['confidence_score' => $goalieScore];
            $teamScore = round(100 * $helper->teamInputConfidence($sides[$venue], $goalies[$venue]), 4);
            $teams[$venue]['input_confidence_score'] = $teamScore;
            $teams[$venue]['goalie_input_confidence_score'] = $goalieScore ?? 50;
            $components[$venue] = ['input_confidence_score' => $teamScore,
                'goalie_input_confidence_score' => $goalieScore ?? 50, 'goalie_evidence_available' => $evidence !== null];
        }

        return ['score' => $helper->confidenceScore($sides['away'], $sides['home'], $goalies['away'], $goalies['home']),
            'teams' => $components];
    }

    /** Compare saved L5 team records without querying history or changing player usage.
     * @param array<string,array<string,mixed>> $sides
     * @return array<string,mixed>
     */
    private function teamForm(array $sides, float $weight): array
    {
        $rates = $samples = [];
        foreach (['home', 'away'] as $venue) {
            $row = $sides[$venue]['team_metrics']['last_5'] ?? [];
            $games = is_array($row) ? ($row['games'] ?? null) : null;
            $rate = is_array($row) ? ($row['win_pct'] ?? null) : null;
            $valid = is_numeric($games) && is_finite((float) $games) && $games > 0
                && floor((float) $games) === (float) $games
                && is_numeric($rate) && is_finite((float) $rate) && $rate >= 0 && $rate <= 100;
            $rates[$venue] = $valid ? (float) $rate : null;
            $samples[$venue] = $valid ? (int) $games : null;
        }
        $available = $rates['home'] !== null && $rates['away'] !== null;
        $delta = $available ? ($rates['home'] - $rates['away']) / 100 : 0.0;

        return ['window' => 'last_5', 'weight' => $weight, 'available' => $available,
            'win_pct' => $rates, 'sample_games' => $samples,
            'multipliers' => ['home' => 1 + $weight * $delta, 'away' => 1 - $weight * $delta]];
    }

    /** Exact-fourth B2Bs are mutually exclusive with standalone B2B and third-in-seven. */
    public function fourthGameBackToBack(array $schedule): bool
    {
        return ($schedule['back_to_back'] ?? null) === true
            && ($schedule['games_including_target_last_7_days'] ?? null) === 4;
    }

    /** Independent regulation Poisson scores with an explicitly neutral OT/SO split. */
    public function winProbabilities(float $homeGoals, float $awayGoals): array
    {
        $home = $this->poisson($homeGoals);
        $away = $this->poisson($awayGoals);
        $win = 0.0;
        $tie = 0.0;
        $awayBelow = 0.0;
        foreach ($home as $goals => $probability) {
            $win += $probability * $awayBelow;
            $tie += $probability * ($away[$goals] ?? 0);
            $awayBelow += $away[$goals] ?? 0;
        }
        $result = max(0.0, min(1.0, $win + 0.5 * $tie));

        return ['home' => $result, 'away' => 1 - $result, 'tie' => $tie];
    }

    /** Derive error from pooled totals, and strict hit rates from individual comparisons. */
    public function accuracy(array $comparisons): array
    {
        $predicted = $actual = 0.0;
        $p10 = $p20 = $count = $bothZero = 0;
        foreach ($comparisons as [$prediction, $outcome]) {
            $prediction = $this->nonnegative($prediction, 'Comparison prediction');
            $outcome = $this->nonnegative($outcome, 'Comparison actual');
            if ($prediction === 0.0 && $outcome === 0.0) {
                $bothZero++;
                continue;
            }
            $count++;
            $predicted += $prediction;
            $actual += $outcome;
            $error = $prediction > 0 ? abs($prediction - $outcome) / $prediction : INF;
            $p10 += (int) ($error < 0.10);
            $p20 += (int) ($error < 0.20);
        }

        return ['count' => $count, 'both_zero' => $bothZero,
            'prediction_sum' => $predicted, 'actual_sum' => $actual,
            'prediction' => $count > 0 ? $predicted / $count : null,
            'actual' => $count > 0 ? $actual / $count : null,
            'err_pct' => $predicted > 0 ? 100 * abs($predicted - $actual) / $predicted : null,
            'p10_count' => $p10, 'p20_count' => $p20,
            'p10' => $count > 0 ? 100 * $p10 / $count : null,
            'p20' => $count > 0 ? 100 * $p20 / $count : null];
    }

    /** Replace, rather than add to, bucket PP goals using the agreed team/game allocation. */
    private function allocatePowerPlayGoals(array &$teams, array $sides, float $leaguePk): void
    {
        $initial = [];
        $offenseTotal = $defenseTotal = 0.0;
        foreach (['away' => 'home', 'home' => 'away'] as $venue => $opponent) {
            $offense = $this->nonnegative($sides[$venue]['pp_offense_l20'] ?? null, 'Lineup L20 PP goals/game');
            $defense = $this->nonnegative($sides[$opponent]['pp_allowed_l5'] ?? null, 'Opponent L5 PP goals against/game');
            $pk = $this->probability($sides[$opponent]['pk_probability'] ?? null);
            $initial[$venue] = $pk > $leaguePk ? $defense : $offense;
            $offenseTotal += $offense;
            $defenseTotal += $defense;
        }
        $total = 0.75 * $offenseTotal + 0.25 * $defenseTotal;
        foreach (['away', 'home'] as $venue) {
            $target = array_sum($initial) > 0 ? $total * $initial[$venue] / array_sum($initial) : $total / 2;
            $team = &$teams[$venue];
            $team['pp_allocation'] = ['initial_goals' => $initial[$venue], 'game_total' => $total,
                'original_bucket_goals' => $team['strengths']['PP']['goals'], 'final_goals' => $target];
            $weightTotal = $team['strengths']['PP']['goals'];
            $useSog = $weightTotal <= 0;
            if ($useSog) {
                $weightTotal = $team['strengths']['PP']['sog'];
            }
            if ($target > 0 && $weightTotal <= 0) {
                throw new DomainException('Positive PP allocation has no forecast bucket weight.');
            }
            if ($target > $team['strengths']['PP']['sog'] + 1.0e-12) {
                throw new DomainException('The final PP allocation exceeds projected PP SOG.');
            }
            foreach ($team['players'] as &$player) {
                $player['strengths']['PP']['goals'] = 0.0;
                foreach ($player['strengths']['PP']['buckets'] as &$bucket) {
                    $weight = $bucket[$useSog ? 'sog' : 'goals'];
                    $bucket['goals'] = $weightTotal > 0 ? $target * $weight / $weightTotal : 0.0;
                    $player['strengths']['PP']['goals'] += $bucket['goals'];
                }
                unset($bucket);
            }
            unset($player);
            $team['strengths']['PP']['goals'] = $target;
            unset($team);
        }
    }

    /** Count complete inputs before league imputation, separately for each predicted strength. */
    private function completeness(array $sides): array
    {
        $counts = ['EV' => 0, 'PP' => 0];
        $total = 0;
        foreach ($sides as $side) {
            foreach ($side['players'] as $player) {
                $total++;
                foreach (array_keys($counts) as $lane) {
                    $counts[$lane] += (int) ($player['complete'][$lane] ?? false);
                }
            }
        }

        return array_map(fn (int $count): float => $total > 0 ? $count / $total : 0.0, $counts);
    }

    /** Preserve fractional estimates through every intermediate stage. */
    private function emptyTotals(): array
    {
        return ['sat' => 0.0, 'sog' => 0.0, 'goals' => 0.0];
    }

    /** Bound the calculation, retaining the negligible Poisson tail in a normalized distribution. */
    private function poisson(float $mean): array
    {
        if (! is_finite($mean) || $mean < 0 || $mean > 100) {
            throw new DomainException('Goal mean must be finite and between zero and 100.');
        }
        $limit = (int) ceil($mean + 12 * sqrt($mean + 1) + 20);
        $values = [exp(-$mean)];
        for ($goals = 1; $goals <= $limit; $goals++) {
            $values[] = $values[$goals - 1] * $mean / $goals;
        }
        $sum = array_sum($values);

        return array_map(fn (float $value): float => $value / $sum, $values);
    }

    /** Never turn unavailable evidence into a zero measurement. */
    private function nonnegative(mixed $value, string $label): float
    {
        if (! is_numeric($value) || ! is_finite((float) $value) || (float) $value < 0) {
            throw new DomainException($label.' must be an available finite nonnegative value.');
        }

        return (float) $value;
    }

    /** Validate denominators and relative allowances independently of zero-count evidence. */
    private function positive(mixed $value, string $label): float
    {
        $number = $this->nonnegative($value, $label);
        if ($number <= 0) {
            throw new DomainException($label.' must be positive.');
        }

        return $number;
    }

    /** Supported zero probabilities remain zero. */
    private function probability(mixed $value): float
    {
        $probability = $this->nonnegative($value, 'Probability');
        if ($probability > 1) {
            throw new DomainException('Probability exceeds one.');
        }

        return $probability;
    }
}
