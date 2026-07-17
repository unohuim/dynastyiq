<?php

declare(strict_types=1);

namespace App\Support\Stats;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Applies DB-backed stats filters to an active stats query.
 */
final class StatsQueryFilterApplier
{
    /**
     * @param array<string,mixed> $perspectiveFilters
     */
    public function prepareBase($base, ?Request $request, array $perspectiveFilters = []): string
    {
        $table = $base->getModel()->getTable();

        if ($table === 'stats') {
            $base->leftJoin('players as pf', 'pf.id', '=', "{$table}.player_id");
        } else {
            $base->leftJoin('players as pf', 'pf.nhl_id', '=', "{$table}.nhl_player_id");
        }

        $this->applyAvailabilityToBase($base, $request, $request?->user());
        $this->applyPerspectiveFiltersToBase($base, $perspectiveFilters);

        return $table;
    }

    /**
     * @param array<string,bool> $physicalNumeric
     * @return array{filters:array<string,mixed>,pos:array<int,string>,pos_type:array<int,string>}
     */
    public function applyRequestFilters(
        $base,
        string $table,
        StatsFilterSet $filterSet,
        ?Request $request,
        array $physicalNumeric
    ): array {
        $applied = [
            'filters' => [],
            ...$filterSet->positionEcho(),
        ];

        if ($applied['pos_type'] !== []) {
            $base->whereIn('pf.pos_type', $applied['pos_type']);
            if (in_array('G', array_map('strtoupper', $applied['pos_type']), true)) {
                $base->where('pf.position', 'G');
            }
        }

        if ($applied['pos'] !== []) {
            $positions = $this->expandPositionFilterValues($applied['pos']);
            if (array_diff($positions, ['G'])) {
                $positions = array_values(array_diff($positions, ['G']));
            }
            if ($positions !== []) {
                $base->whereIn('pf.position', $positions);
            }
        }

        if ($filterSet->teams !== []) {
            if ($table === 'stats') {
                $base->whereIn("{$table}.nhl_team_abbrev", $filterSet->teams);
            } else {
                $base->whereIn('pf.team_abbrev', $filterSet->teams);
            }
            $applied['filters']['team'] = $filterSet->teams;
        }

        if ($table === 'stats' && $filterSet->leagues !== []) {
            $base->whereIn("{$table}.league_abbrev", $filterSet->leagues);
            $applied['filters']['league'] = $filterSet->leagues;
        }

        if ($filterSet->draftYears !== []) {
            $base->whereIn('pf.draft_year', $filterSet->draftYears);
            $applied['filters']['entry_draft_year'] = $filterSet->draftYears;
        } elseif ($filterSet->draftYearRange['min'] !== null || $filterSet->draftYearRange['max'] !== null) {
            $min = $filterSet->draftYearRange['min'];
            $max = $filterSet->draftYearRange['max'];

            if ($min !== null && $max !== null) {
                if ($min > $max) {
                    [$min, $max] = [$max, $min];
                }

                $base->whereBetween('pf.draft_year', [$min, $max]);
            } elseif ($min !== null) {
                $base->where('pf.draft_year', '>=', $min);
            } elseif ($max !== null) {
                $base->where('pf.draft_year', '<=', $max);
            }

            $applied['filters']['entry_draft_year'] = [
                'min' => $min,
                'max' => $max,
            ];
        }

        $ageRange = $filterSet->numericRanges['age'] ?? null;
        if ($ageRange && ($ageRange['min'] !== null || $ageRange['max'] !== null)) {
            $today = Carbon::today();
            $youngestDob = $ageRange['min'] !== null
                ? $today->copy()->subYears((int) $ageRange['min'])->toDateString()
                : null;
            $oldestDob = $ageRange['max'] !== null
                ? $today->copy()->subYears((int) $ageRange['max'] + 1)->addDay()->toDateString()
                : null;

            if ($oldestDob && $youngestDob) {
                $base->whereBetween('pf.dob', [$oldestDob, $youngestDob]);
            } elseif ($oldestDob) {
                $base->where('pf.dob', '>=', $oldestDob);
            } elseif ($youngestDob) {
                $base->where('pf.dob', '<=', $youngestDob);
            }

            $applied['filters']['age'] = [
                'min' => $ageRange['min'] !== null ? (int) $ageRange['min'] : null,
                'max' => $ageRange['max'] !== null ? (int) $ageRange['max'] : null,
            ];
        }

        foreach ($filterSet->numericRanges as $baseKey => $range) {
            if ($baseKey === 'age' || ! isset($physicalNumeric[$baseKey])) {
                continue;
            }

            $column = $this->mapFilterColumn($base, $baseKey);
            if (! $column) {
                continue;
            }

            $pair = $applied['filters'][$baseKey] ?? ['min' => null, 'max' => null];
            foreach (['min', 'max'] as $bound) {
                $value = $range[$bound] ?? null;
                if ($value === null) {
                    continue;
                }

                $pair[$bound] = $value;
                $base->where($column, $bound === 'min' ? '>=' : '<=', $value);
            }

            $applied['filters'][$baseKey] = $pair;
        }

        return $applied;
    }

    private function applyAvailabilityToBase($base, ?Request $request, $user): void
    {
        $value = (int) ($request?->input('availability', 0) ?? 0);
        if ($value === 0) {
            return;
        }

        if ($value === -1) {
            $userId = $user?->id ?? Auth::id();
            if (! $userId) {
                return;
            }

            $leagueIds = DB::table('league_user_teams')
                ->where('user_id', $userId)
                ->pluck('platform_league_id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            if ($leagueIds === []) {
                return;
            }

            $base->whereExists(function ($query) use ($leagueIds): void {
                $query->selectRaw('1')
                    ->from('platform_leagues as l')
                    ->whereIn('l.id', $leagueIds)
                    ->whereNotExists(function ($subquery): void {
                        $subquery->from('platform_roster_memberships as prm')
                            ->join('platform_teams as pt', 'pt.id', '=', 'prm.platform_team_id')
                            ->whereNull('prm.ends_at')
                            ->whereColumn('pt.platform_league_id', 'l.id')
                            ->whereColumn('prm.player_id', 'pf.id');
                    });
            });

            return;
        }

        $base->whereNotExists(function ($query) use ($value): void {
            $query->from('platform_roster_memberships as prm')
                ->join('platform_teams as pt', 'pt.id', '=', 'prm.platform_team_id')
                ->whereNull('prm.ends_at')
                ->where('pt.platform_league_id', $value)
                ->whereColumn('prm.player_id', 'pf.id')
                ->selectRaw('1');
        });
    }

    /**
     * @param array<string,mixed> $filters
     */
    private function applyPerspectiveFiltersToBase($base, array $filters): void
    {
        $table = $base->getModel()->getTable();

        if ($table === 'stats' && ! $this->isProspectPerspectiveFilterSet($filters)) {
            $filter = $filters['is_prospect'] ?? null;
            if (is_array($filter) && array_key_exists('value', $filter)) {
                $base->where('stats.is_prospect', (bool) $filter['value']);
            }
        }

        foreach (['pos', 'pos_type'] as $key) {
            $filter = $filters[$key] ?? null;
            if (! is_array($filter)) {
                continue;
            }

            $value = $filter['value'] ?? null;
            $operator = strtoupper((string) ($filter['operator'] ?? '='));
            $values = is_array($value) ? array_values($value) : [$value];
            $values = array_values(array_filter(
                array_map(fn (mixed $item): string => strtoupper(trim((string) $item)), $values),
                fn (string $item): bool => $item !== '',
            ));

            if ($values === []) {
                continue;
            }

            $column = $key === 'pos' ? 'pf.position' : 'pf.pos_type';
            if (in_array($operator, ['!=', '<>'], true)) {
                $base->whereNotIn($column, $values);
            } else {
                $base->whereIn($column, $values);
            }
        }
    }

    /**
     * @param array<string,mixed> $filters
     */
    private function isProspectPerspectiveFilterSet(array $filters): bool
    {
        return isset($filters['is_prospect'], $filters['league_abbrev'])
            && is_array($filters['is_prospect'])
            && is_array($filters['league_abbrev'])
            && ($filters['is_prospect']['value'] ?? null) === true
            && ($filters['league_abbrev']['operator'] ?? null) === '!='
            && ($filters['league_abbrev']['value'] ?? null) === 'NHL';
    }

    /**
     * @param array<int,string> $positions
     * @return array<int,string>
     */
    private function expandPositionFilterValues(array $positions): array
    {
        return collect($positions)
            ->flatMap(function (string $position): array {
                return match (strtoupper(trim($position))) {
                    'LW' => ['LW', 'L'],
                    'RW' => ['RW', 'R'],
                    default => [strtoupper(trim($position))],
                };
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function mapFilterColumn($base, string $key): ?string
    {
        $table = $base->getModel()->getTable();
        $aliases = [
            'nhl_season_stats' => [
                'g_per_gp' => 'g_pg',
                'a_per_gp' => 'a_pg',
                'pts_per_gp' => 'pts_pg',
                'b_per_gp' => 'b_pg',
                'h_per_gp' => 'h_pg',
                'th_per_gp' => 'th_pg',
                'g_per_60' => 'g_p60',
                'a_per_60' => 'a_p60',
                'pts_per_60' => 'pts_p60',
                'sog_per_60' => 'sog_p60',
                'sat_per_60' => 'sat_p60',
                'hits_per_60' => 'hits_p60',
                'blocks_per_60' => 'blocks_p60',
            ],
        ];

        if (isset($aliases[$table][$key])) {
            return $table . '.' . $aliases[$table][$key];
        }

        $variants = [
            'toi' => [
                'nhl_season_stats' => ['toi', 'toi_seconds', 'toi_minutes'],
                'nhl_game_summaries' => ['toi_seconds', 'toi', 'toi_minutes'],
                'stats' => ['toi'],
            ],
        ];

        if (isset($variants[$key][$table])) {
            foreach ($variants[$key][$table] as $candidate) {
                if (Schema::hasColumn($table, $candidate)) {
                    return $table . '.' . $candidate;
                }
            }

            return null;
        }

        return Schema::hasColumn($table, $key) ? $table . '.' . $key : null;
    }
}
