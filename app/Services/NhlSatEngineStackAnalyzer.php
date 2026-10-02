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

    private const BEAM_WIDTH = 3;

    /**
     * @param Collection<int, object> $rows Already-filtered, completed candidate rows.
     * @return list<array<string, mixed>>
     */
    public function analyze(NhlSatEngineRun $run, Collection $rows): array
    {
        $candidates = $rows->filter(fn (object $row): bool => $row->metrics !== null && $row->win_pct !== null)
            ->sortBy([['win_pct', 'desc'], ['coverage_pct', 'desc'], ['id', 'asc']])->take(self::MAX_CANDIDATES)
            ->map(function (object $row): array {
                $row->settings = json_decode($row->settings, true, 512, JSON_THROW_ON_ERROR);

                return ['id' => (int) $row->id, 'split_index' => (int) $row->split_index, 'settings' => $row->settings,
                    'win_pct' => (float) $row->win_pct, 'coverage_pct' => (float) $row->coverage_pct];
            })->values()->all();
        if (count($candidates) < 2) {
            return [];
        }

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

        $stacks = [];
        foreach ($candidates as $foundation) {
            $states = [$this->state([$foundation], $candidateResults, $run->game_count)];
            for ($depth = 2; $depth <= self::MAX_DEPTH; $depth++) {
                $next = [];
                foreach ($states as $state) {
                    foreach ($candidates as $candidate) {
                        if (in_array($candidate['id'], $state['ids'], true)) {
                            continue;
                        }
                        $next[] = $this->state([...$state['candidates'], $candidate], $candidateResults, $run->game_count);
                    }
                }
                if ($next === []) {
                    break;
                }
                usort($next, fn (array $left, array $right): int => $this->compare($left, $right));
                $states = array_slice($next, 0, self::BEAM_WIDTH);
                foreach ($states as $state) {
                    if ($state['coverage_pct'] > $foundation['coverage_pct'] && $state['win_pct'] >= $foundation['win_pct']) {
                        $stacks[] = $state;
                    }
                }
            }
        }

        usort($stacks, fn (array $left, array $right): int => $this->compare($left, $right));

        return array_values(array_slice($stacks, 0, 25));
    }

    /** @param list<array<string, mixed>> $candidates @param array<int, array{eligible: array<int, bool>, picks: array<int, bool>}> $candidateResults @return array<string, mixed> */
    private function state(array $candidates, array $candidateResults, int $selected): array
    {
        $eligible = [];
        $picks = [];
        foreach ($candidates as $candidate) {
            foreach ($candidateResults[$candidate['id']]['eligible'] as $gameId => $_) {
                $eligible[$gameId] = true;
            }
            foreach ($candidateResults[$candidate['id']]['picks'] as $gameId => $correct) {
                $picks[$gameId] ??= $correct;
            }
        }
        $wins = count(array_filter($picks));
        $pickCount = count($picks);
        $eligibleCount = count($eligible);

        return ['ids' => array_column($candidates, 'id'), 'candidates' => $candidates, 'wins' => $wins,
            'losses' => $pickCount - $wins, 'picks' => $pickCount, 'eligible' => $eligibleCount,
            'excluded' => max(0, $selected - $eligibleCount),
            'win_pct' => $pickCount === 0 ? null : round($wins / $pickCount * 100, 4),
            'coverage_pct' => $eligibleCount === 0 ? 0.0 : round($pickCount / $eligibleCount * 100, 4)];
    }

    /** @param array<string, mixed> $left @param array<string, mixed> $right */
    private function compare(array $left, array $right): int
    {
        return [$right['win_pct'] ?? -1, $right['coverage_pct'], count($left['ids']), $left['ids']]
            <=> [$left['win_pct'] ?? -1, $left['coverage_pct'], count($right['ids']), $right['ids']];
    }
}
