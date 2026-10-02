<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NhlSatEngineRun;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Builds bounded, analysis-only ordered candidate stacks from persisted run evidence. */
final class NhlSatEngineStackAnalyzer
{
    public const MAX_DEPTH = 5;

    private const MAX_CANDIDATES = 50;

    private const BEAM_WIDTH = 10;

    /**
     * @param Collection<int, object> $rows Already-filtered, completed candidate rows.
     * @return list<array<string, mixed>>
     */
    public function analyze(NhlSatEngineRun $run, Collection $rows): array
    {
        $allCandidates = $this->candidates($rows, false);
        $foundation = $this->foundationFrom($run, $allCandidates);
        if ($foundation === null) {
            return [];
        }
        $candidateResults = $this->candidateResults($run, $allCandidates);
        $foundationState = $this->state([$foundation], $candidateResults, $run->game_count);
        $supplements = collect($allCandidates)
            ->filter(fn (array $candidate): bool => $candidate['id'] !== $foundation['id']
                && $candidate['win_pct'] !== null && $this->qualificationKey($candidate) !== $this->qualificationKey($foundation))
            ->map(function (array $candidate) use ($foundationState, $candidateResults): array {
                $marginal = $this->marginal($foundationState, $candidate, $candidateResults);

                return [...$candidate, ...$marginal];
            })
            ->filter(fn (array $candidate): bool => $candidate['marginal_picks'] > 0
                && $this->preservesPrecedence($foundationState, $candidate, $candidateResults))
            ->sortBy([['marginal_win_pct', 'desc'], ['marginal_picks', 'desc'], ['win_pct', 'desc'], ['coverage_pct', 'desc'], ['id', 'asc']])
            ->unique(fn (array $candidate): string => $this->qualificationKey($candidate))
            ->take(self::MAX_CANDIDATES - 1)
            ->map(fn (array $candidate): array => array_diff_key($candidate, ['marginal_win_pct' => true, 'marginal_picks' => true]))
            ->values()
            ->all();
        if ($supplements === []) {
            return [];
        }

        $stacks = [];
        $states = [$foundationState];
        for ($depth = 2; $depth <= self::MAX_DEPTH; $depth++) {
            $next = [];
            foreach ($states as $state) {
                foreach ($supplements as $candidate) {
                    if (in_array($candidate['id'], $state['ids'], true)
                        || in_array($this->qualificationKey($candidate), $state['qualification_keys'], true)) {
                        continue;
                    }
                    $expanded = $this->expand($state, $candidate, $candidateResults, $run->game_count);
                    if ($expanded !== null) {
                        $next[] = $expanded;
                    }
                }
            }
            if ($next === []) {
                break;
            }
            usort($next, fn (array $left, array $right): int => $this->compareExpansion($left, $right));
            $states = array_slice($next, 0, self::BEAM_WIDTH);
            foreach ($states as $state) {
                if ($state['coverage_pct'] > $foundation['coverage_pct']) {
                    $stacks[] = $state;
                }
            }
        }

        usort($stacks, fn (array $left, array $right): int => $this->compareRecommendation($left, $right));
        $seenOutcomes = [];
        $stacks = array_values(array_filter($stacks, function (array $stack) use (&$seenOutcomes): bool {
            if (isset($seenOutcomes[$stack['pick_signature']])) {
                return false;
            }
            $seenOutcomes[$stack['pick_signature']] = true;

            return true;
        }));

        $minimumPreferredDepth = collect($stacks)->contains(fn (array $stack): bool => count($stack['ids']) >= 3) ? 3 : 2;
        $preferred = array_values(array_filter($stacks,
            fn (array $stack): bool => count($stack['ids']) >= $minimumPreferredDepth));
        $secondary = array_values(array_filter($stacks,
            fn (array $stack): bool => count($stack['ids']) < $minimumPreferredDepth));
        $featured = [];
        $featuredIds = [];
        foreach ($preferred as $stack) {
            $depth = count($stack['ids']);
            if (isset($featured[$depth])) {
                continue;
            }
            $featured[$depth] = $stack;
            $featuredIds[implode(',', $stack['ids'])] = true;
        }
        $remaining = array_values(array_filter([...$preferred, ...$secondary],
            fn (array $stack): bool => ! isset($featuredIds[implode(',', $stack['ids'])])));

        return array_values(array_slice([...array_values($featured), ...$remaining], 0, 25));
    }

    /** Analyze one user-ordered foundation and supplementary candidate selection. */
    public function manual(NhlSatEngineRun $run, Collection $rows): ?array
    {
        $candidates = $this->candidates($rows, false);

        return $candidates === [] ? null : $this->state($candidates, $this->candidateResults($run, $candidates), $run->game_count);
    }

    /** Return the automatic foundation selected from target-qualified candidates. */
    public function foundation(NhlSatEngineRun $run, Collection $rows): ?array
    {
        return $this->foundationFrom($run, $this->candidates($rows, false));
    }

    /** @return list<array<string,mixed>> */
    private function candidates(Collection $rows, bool $automatic): array
    {
        $rows = $rows->filter(fn (object $row): bool => $row->metrics !== null && (! $automatic || $row->win_pct !== null));
        if ($automatic) {
            $rows = $rows->sortBy([['coverage_pct', 'desc'], ['win_pct', 'desc'], ['id', 'asc']])->take(self::MAX_CANDIDATES);
        }

        return $rows->map(function (object $row): array {
            $settings = json_decode($row->settings, true, 512, JSON_THROW_ON_ERROR);

            return ['id' => (int) $row->id, 'split_index' => (int) $row->split_index, 'settings' => $settings,
                'win_pct' => $row->win_pct === null ? null : (float) $row->win_pct,
                'coverage_pct' => (float) $row->coverage_pct];
        })->values()->all();
    }

    /** @param list<array<string,mixed>> $candidates @return array<string,mixed>|null */
    private function foundationFrom(NhlSatEngineRun $run, array $candidates): ?array
    {
        $foundations = collect($candidates)->filter(fn (array $candidate): bool => $candidate['win_pct'] !== null
            && $candidate['coverage_pct'] >= (float) $run->definition['min_coverage_pct'])->values()->all();
        usort($foundations, function (array $left, array $right): int {
            $winComparison = $right['win_pct'] <=> $left['win_pct'];
            if ($winComparison !== 0) {
                return $winComparison;
            }
            $offenseComparison = ((float) $right['settings']['offense']) <=> ((float) $left['settings']['offense']);
            if ($offenseComparison !== 0) {
                return $offenseComparison;
            }
            $coverageComparison = $right['coverage_pct'] <=> $left['coverage_pct'];

            return $coverageComparison !== 0 ? $coverageComparison : $left['id'] <=> $right['id'];
        });

        return $foundations[0] ?? null;
    }

    /** @param list<array<string,mixed>> $candidates @return array<int, array{eligible: array<int,bool>, picks: array<int,bool>}> */
    private function candidateResults(NhlSatEngineRun $run, array $candidates): array
    {
        $results = DB::table('nhl_sat_engine_results')->where('run_id', $run->id)
            ->whereIn('split_index', array_values(array_unique(array_column($candidates, 'split_index'))))
            ->where('status', 'complete')->get(['split_index', 'nhl_game_id', 'confidence', 'gap', 'correct'])
            ->groupBy('split_index');
        $candidateResults = [];
        foreach ($candidates as $candidate) {
            $eligible = [];
            $picks = [];
            foreach ($results->get($candidate['split_index'], []) as $result) {
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
    private function qualificationKey(array $candidate): string
    {
        return implode(':', [
            (string) $candidate['settings']['confidence_min'],
            (string) $candidate['settings']['confidence_max'],
            (string) $candidate['settings']['gap'],
        ]);
    }

    /**
     * Append a supplement only when it provides picks not supplied by the existing stack.
     *
     * @param array<string,mixed> $state
     * @param array<string,mixed> $candidate
     * @param array<int, array{eligible: array<int,bool>, picks: array<int,bool>}> $candidateResults
     * @return array<string,mixed>|null
     */
    private function expand(array $state, array $candidate, array $candidateResults, int $selected): ?array
    {
        if (! $this->preservesPrecedence($state, $candidate, $candidateResults)) {
            return null;
        }
        $marginal = $this->marginal($state, $candidate, $candidateResults);
        if ($marginal['marginal_picks'] === 0) {
            return null;
        }

        $expanded = $this->state([...$state['candidates'], $candidate], $candidateResults, $selected);

        return [...$expanded, ...$marginal];
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
        ];
    }

    /**
     * Reject a replacement candidate that owns every already-selected pick by itself.
     *
     * @param array<string,mixed> $state
     * @param array<string,mixed> $candidate
     * @param array<int, array{eligible: array<int,bool>, picks: array<int,bool>}> $candidateResults
     */
    private function preservesPrecedence(array $state, array $candidate, array $candidateResults): bool
    {
        return array_diff_key(
            $this->picks($state['candidates'], $candidateResults),
            $candidateResults[$candidate['id']]['picks']
        ) !== [];
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
            'qualification_keys' => array_map(fn (array $candidate): string => $this->qualificationKey($candidate), $candidates), 'wins' => $wins,
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

    /** @param array<string, mixed> $left @param array<string, mixed> $right */
    private function compareExpansion(array $left, array $right): int
    {
        return [$right['marginal_win_pct'], $right['marginal_picks'], $right['win_pct'] ?? -1, $right['coverage_pct'], count($left['ids']), $left['ids']]
            <=> [$left['marginal_win_pct'], $left['marginal_picks'], $left['win_pct'] ?? -1, $left['coverage_pct'], count($right['ids']), $right['ids']];
    }

    /** @param array<string, mixed> $left @param array<string, mixed> $right */
    private function compareRecommendation(array $left, array $right): int
    {
        return [$right['win_pct'] ?? -1, $right['coverage_pct'], count($left['ids']), $left['ids']]
            <=> [$left['win_pct'] ?? -1, $left['coverage_pct'], count($right['ids']), $right['ids']];
    }
}
