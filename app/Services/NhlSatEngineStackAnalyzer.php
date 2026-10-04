<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NhlSatEngineRun;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Builds bounded, analysis-only ordered candidate stacks from persisted run evidence. */
final class NhlSatEngineStackAnalyzer
{
    private const MAX_FRONTIER_STATES = 100;

    /**
     * @param Collection<int, object> $rows Already-filtered, completed candidate rows.
     * @return list<array<string, mixed>>
     */
    public function analyze(NhlSatEngineRun $run, Collection $rows): array
    {
        $allCandidates = $this->candidates($rows, false);
        $allCandidates = array_values(array_filter($allCandidates, fn (array $candidate): bool => $candidate['win_pct'] !== null));
        $allCandidates = $this->candidatePool($allCandidates);
        if ($allCandidates === []) {
            return [];
        }
        $candidateResults = $this->candidateResults($run, $allCandidates);
        $this->assertEquivalentSettings($allCandidates, $candidateResults);
        $stacks = [];
        foreach ($allCandidates as $foundation) {
            $stack = $this->state([$foundation], $candidateResults, $run->game_count);
            while (($supplement = $this->bestSupplement($stack, $allCandidates, $candidateResults)) !== null) {
                $stack = $this->state([...$stack['candidates'], $supplement], $candidateResults, $run->game_count);
            }
            $stacks[] = $stack;
        }

        return array_slice($this->frontier($stacks), 0, 25);
    }

    /** Analyze one user-ordered foundation and supplementary candidate selection. */
    public function manual(NhlSatEngineRun $run, Collection $rows): ?array
    {
        $candidates = $this->candidates($rows, false);

        return $candidates === [] ? null : $this->state($candidates, $this->candidateResults($run, $candidates), $run->game_count);
    }

    /** Return the highest-win completed candidate, independent of its coverage. */
    public function foundation(NhlSatEngineRun $run, Collection $rows): ?array
    {
        return $this->foundationFrom($run, $this->candidates($rows, false));
    }

    /** @return list<array<string,mixed>> */
    private function candidates(Collection $rows, bool $automatic): array
    {
        $rows = $rows->filter(fn (object $row): bool => $row->metrics !== null && (! $automatic || $row->win_pct !== null));

        return $rows->map(function (object $row): array {
            $settings = json_decode($row->settings, true, 512, JSON_THROW_ON_ERROR);
            $metrics = is_array($row->metrics) ? $row->metrics : json_decode($row->metrics, true, 512, JSON_THROW_ON_ERROR);

            return ['id' => (int) $row->id, 'split_index' => (int) $row->split_index, 'settings' => $settings,
                'win_pct' => $row->win_pct === null ? null : (float) $row->win_pct,
                'coverage_pct' => (float) $row->coverage_pct,
                'wins' => (int) ($metrics['wins'] ?? 0), 'losses' => (int) ($metrics['losses'] ?? 0)];
        })->values()->all();
    }

    /** @param list<array<string,mixed>> $candidates @return array<string,mixed>|null */
    private function foundationFrom(NhlSatEngineRun $run, array $candidates): ?array
    {
        $foundations = collect($candidates)->filter(fn (array $candidate): bool => $candidate['win_pct'] !== null)->values()->all();
        usort($foundations, function (array $left, array $right): int {
            $winComparison = $right['win_pct'] <=> $left['win_pct'];
            if ($winComparison !== 0) {
                return $winComparison;
            }
            $offenseComparison = ((float) $right['settings']['offense']) <=> ((float) $left['settings']['offense']);
            if ($offenseComparison !== 0) {
                return $offenseComparison;
            }
            return $left['id'] <=> $right['id'];
        });

        return $foundations[0] ?? null;
    }

    /** @param list<array<string,mixed>> $candidates @return array<int, array{eligible: array<int,bool>, picks: array<int,bool>}> */
    private function candidateResults(NhlSatEngineRun $run, array $candidates): array
    {
        $results = DB::table('nhl_sat_engine_results')->where('run_id', $run->id)
            ->whereIn('split_index', array_values(array_unique(array_column($candidates, 'split_index'))))
            ->where('status', 'complete')->get(['split_index', 'nhl_game_id', 'game', 'confidence', 'gap', 'correct'])
            ->groupBy('split_index');
        $candidateResults = [];
        foreach ($candidates as $candidate) {
            $eligible = [];
            $picks = [];
            foreach ($results->get($candidate['split_index'], []) as $result) {
                if (! $this->matchesTeamVenue($result->game, $candidate['settings'])) {
                    continue;
                }
                $gameId = (int) $result->nhl_game_id;
                $eligible[$gameId] = true;
                if ($result->correct !== null && (int) $result->confidence >= $candidate['settings']['confidence_min']
                    && (int) $result->confidence <= $candidate['settings']['confidence_max'] && (float) $result->gap > (float) $candidate['settings']['gap']) {
                    $picks[$gameId] = (bool) $result->correct;
                }
            }
            $candidateResults[$candidate['id']] = ['eligible' => $eligible, 'picks' => $picks];
        }

        return $candidateResults;
    }

    /** @param array<string,mixed> $candidate */
    private function settingsKey(array $candidate): string
    {
        return implode(':', [
            (string) $candidate['settings']['offense'],
            (string) $candidate['settings']['defense'],
            (string) ($candidate['settings']['team_abbrev'] ?? ''),
            (string) ($candidate['settings']['venue'] ?? ''),
            (string) $candidate['settings']['confidence_min'],
            (string) $candidate['settings']['confidence_max'],
            (string) $candidate['settings']['gap'],
        ]);
    }

    /**
     * Equivalent qualification settings must qualify exactly the same historical games.
     *
     * @param list<array<string,mixed>> $candidates
     * @param array<int, array{eligible: array<int,bool>, picks: array<int,bool>}> $candidateResults
     */
    private function assertEquivalentSettings(array $candidates, array $candidateResults): void
    {
        $seen = [];
        foreach ($candidates as $candidate) {
            $key = $this->settingsKey($candidate);
            if (! isset($seen[$key])) {
                $seen[$key] = $candidate['id'];

                continue;
            }
            $firstId = $seen[$key];
            if ($candidateResults[$firstId]['picks'] !== $candidateResults[$candidate['id']]['picks']) {
                throw new \LogicException('Equivalent SAT engine settings produced different qualified games.');
            }
        }
    }

    /** Retain only outcomes that no other outcome beats in both win percentage and coverage. */
    private function frontier(array $states): array
    {
        $byOutcome = [];
        foreach ($states as $state) {
            $key = $state['pick_signature'];
            if (! isset($byOutcome[$key]) || count($state['ids']) < count($byOutcome[$key]['ids'])) {
                $byOutcome[$key] = $state;
            }
        }
        $states = array_values($byOutcome);
        $frontier = array_values(array_filter($states, function (array $state) use ($states): bool {
            foreach ($states as $other) {
                if ($other === $state) {
                    continue;
                }
                if ($other['win_pct'] >= $state['win_pct'] && $other['coverage_pct'] >= $state['coverage_pct']
                    && ($other['win_pct'] > $state['win_pct'] || $other['coverage_pct'] > $state['coverage_pct'])) {
                    return false;
                }
            }
            return true;
        }));
        usort($frontier, fn (array $left, array $right): int => [
            $right['win_pct'], $right['coverage_pct'], -count($right['ids']), $left['ids'],
        ] <=> [
            $left['win_pct'], $left['coverage_pct'], -count($left['ids']), $right['ids'],
        ]);

        return array_slice($frontier, 0, self::MAX_FRONTIER_STATES);
    }

    /** Keep every individual candidate that no other candidate beats on win and coverage. */
    private function candidatePool(array $candidates): array
    {
        usort($candidates, fn (array $left, array $right): int => [$right['win_pct'], $right['coverage_pct'], $left['id']]
            <=> [$left['win_pct'], $left['coverage_pct'], $right['id']]);
        $pool = [];
        $bestCoverage = -1.0;
        foreach ($candidates as $candidate) {
            if ($candidate['coverage_pct'] <= $bestCoverage) {
                continue;
            }
            $pool[] = $candidate;
            $bestCoverage = $candidate['coverage_pct'];
        }

        return $pool;
    }

    /** Return whether a persisted game belongs to the candidate's optional team-venue scope. */
    private function matchesTeamVenue(string $game, array $settings): bool
    {
        if (! isset($settings['team_abbrev'], $settings['venue'])) {
            return true;
        }
        $venue = $settings['venue'];
        if (! in_array($venue, ['away', 'home'], true)) {
            return false;
        }
        $snapshot = json_decode($game, true, 512, JSON_THROW_ON_ERROR);

        return ($snapshot[$venue] ?? null) === $settings['team_abbrev'];
    }

    /**
     * Return the candidate's quality and volume after earlier stack members take precedence.
     *
     * @param array<string,mixed> $state
     * @param array<string,mixed> $candidate
     * @param array<int, array{eligible: array<int,bool>, picks: array<int,bool>}> $candidateResults
     * @return array{marginal_win_pct:float,marginal_picks:int}
     */
    private function marginal(array $state, array $candidate, array $candidateResults): array
    {
        $existingPicks = $this->picks($state['candidates'], $candidateResults);
        $newPicks = array_diff_key($candidateResults[$candidate['id']]['picks'], $existingPicks);
        $pickCount = count($newPicks);

        return [
            'marginal_win_pct' => $pickCount === 0 ? 0.0 : round(count(array_filter($newPicks)) / $pickCount * 100, 4),
            'marginal_picks' => $pickCount,
            'marginal_wins' => count(array_filter($newPicks)),
        ];
    }

    /** Return the highest-quality positive contributor to the stack's uncovered games. */
    private function bestSupplement(array $state, array $candidates, array $candidateResults): ?array
    {
        $best = null;
        $bestMarginal = null;
        foreach ($candidates as $candidate) {
            if (in_array($candidate['id'], $state['ids'], true)
                || in_array($this->settingsKey($candidate), $state['settings_keys'], true)) {
                continue;
            }
            $marginal = $this->marginal($state, $candidate, $candidateResults);
            if ($marginal['marginal_wins'] <= $marginal['marginal_picks'] - $marginal['marginal_wins']) {
                continue;
            }
            if ($best === null || [
                $marginal['marginal_win_pct'], $candidate['win_pct'], $marginal['marginal_picks'],
                (float) $candidate['settings']['offense'], (float) $candidate['settings']['defense'], -$candidate['id'],
            ] > [
                $bestMarginal['marginal_win_pct'], $best['win_pct'], $bestMarginal['marginal_picks'],
                (float) $best['settings']['offense'], (float) $best['settings']['defense'], -$best['id'],
            ]) {
                $best = $candidate;
                $bestMarginal = $marginal;
            }
        }

        return $best;
    }

    /** @param list<array<string, mixed>> $candidates @param array<int, array{eligible: array<int, bool>, picks: array<int, bool>}> $candidateResults @return array<string, mixed> */
    private function state(array $candidates, array $candidateResults, int $selected): array
    {
        $eligible = [];
        $picks = [];
        $stackCandidates = [];
        $foundationPicks = [];
        foreach ($candidates as $candidate) {
            foreach ($candidateResults[$candidate['id']]['eligible'] as $gameId => $_) {
                $eligible[$gameId] = true;
            }
            $newPicks = array_diff_key($candidateResults[$candidate['id']]['picks'], $picks);
            $stackCandidates[] = [...$candidate,
                'stack_picks' => count($newPicks),
                'stack_wins' => count(array_filter($newPicks)),
                'stack_losses' => count($newPicks) - count(array_filter($newPicks))];
            foreach ($candidateResults[$candidate['id']]['picks'] as $gameId => $correct) {
                $picks[$gameId] ??= $correct;
            }
            if ($foundationPicks === []) {
                $foundationPicks = $newPicks;
            }
            $cumulativePicks = count($picks);
            $cumulativeWins = count(array_filter($picks));
            $stackCandidates[array_key_last($stackCandidates)]['stack_win_pct'] = $cumulativePicks === 0 ? null : round($cumulativeWins / $cumulativePicks * 100, 4);
            $stackCandidates[array_key_last($stackCandidates)]['stack_coverage_pct'] = count($eligible) === 0 ? 0.0 : round($cumulativePicks / count($eligible) * 100, 4);
        }
        $wins = count(array_filter($picks));
        $pickCount = count($picks);
        $eligibleCount = count($eligible);
        $foundationWins = count(array_filter($foundationPicks));
        $foundationPickCount = count($foundationPicks);
        $foundationEligibleCount = count($candidateResults[$candidates[0]['id']]['eligible']);
        ksort($picks);
        $pickSignature = implode('|', array_map(
            fn (int $gameId, bool $correct): string => $gameId . ':' . (int) $correct,
            array_keys($picks),
            array_values($picks)
        ));

        return ['ids' => array_column($candidates, 'id'), 'candidates' => $stackCandidates,
            'settings_keys' => array_map(fn (array $candidate): string => $this->settingsKey($candidate), $candidates), 'wins' => $wins,
            'losses' => $pickCount - $wins, 'picks' => $pickCount, 'eligible' => $eligibleCount,
            'foundation_wins' => $foundationWins,
            'foundation_losses' => $foundationPickCount - $foundationWins,
            'foundation_win_pct' => $foundationPickCount === 0 ? null : round($foundationWins / $foundationPickCount * 100, 4),
            'foundation_coverage_pct' => $foundationEligibleCount === 0 ? 0.0 : round($foundationPickCount / $foundationEligibleCount * 100, 4),
            'unique_added_picks' => $pickCount - $foundationPickCount,
            'unique_added_wins' => $wins - $foundationWins,
            'unique_added_losses' => ($pickCount - $wins) - ($foundationPickCount - $foundationWins),
            'pick_signature' => $pickSignature,
            'excluded' => max(0, $selected - $eligibleCount),
            'win_pct' => $pickCount === 0 ? null : round($wins / $pickCount * 100, 4),
            'coverage_pct' => $eligibleCount === 0 ? 0.0 : round($pickCount / $eligibleCount * 100, 4)];
    }

    /** @param list<array<string, mixed>> $candidates @param array<int, array{eligible: array<int,bool>, picks: array<int,bool>}> $candidateResults @return array<int,bool> */
    private function picks(array $candidates, array $candidateResults): array
    {
        $picks = [];
        foreach ($candidates as $candidate) {
            foreach ($candidateResults[$candidate['id']]['picks'] as $gameId => $correct) {
                $picks[$gameId] ??= $correct;
            }
        }

        return $picks;
    }

}
