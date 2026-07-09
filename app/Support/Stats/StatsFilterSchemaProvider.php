<?php

declare(strict_types=1);

namespace App\Support\Stats;

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Builds stats filter schema metadata for the frontend.
 */
final class StatsFilterSchemaProvider
{
    /**
     * @param array<int,array<string,mixed>> $columns
     * @return array<int,array<string,mixed>>
     */
    public function schemaForBase($base, array $columns, array $context = []): array
    {
        return Cache::remember(
            $this->cacheKeyForBase($base, $columns, $context),
            now()->addMinutes(5),
            fn (): array => $this->buildSchemaForBase($base, $columns),
        );
    }

    /**
     * @param array<int,array<string,mixed>> $columns
     * @return array<int,array<string,mixed>>
     */
    private function buildSchemaForBase($base, array $columns): array
    {
        $table = $base->getModel()->getTable();
        $schema = [
            ['key' => 'age', 'label' => 'Age', 'type' => 'int', 'bounds' => $this->ageBoundsForBase($base)],
            ['key' => 'team', 'label' => 'Team', 'type' => 'enum', 'options' => $this->teamOptionsForBase($base)],
            ['key' => 'pos', 'label' => 'Position', 'type' => 'enum', 'options' => $this->positionOptionsForBase($base)],
        ];

        if ($table === 'stats') {
            $schema[] = [
                'key' => 'league',
                'label' => 'League',
                'type' => 'enum',
                'options' => $this->leagueOptionsForBase($base),
            ];
        }

        foreach ($columns as $column) {
            $key = $column['key'] ?? null;
            if (! $key || in_array($key, ['name', 'age', 'team', 'contract_value', 'gp'], true)) {
                continue;
            }

            $schema[] = [
                'key' => $key,
                'label' => $column['label'] ?? Str::title(str_replace('_', ' ', $key)),
                'type' => 'number',
                'bounds' => $this->bounds($base, $key),
                'step' => 1,
            ];
        }

        if (Schema::hasColumn($table, 'gp')) {
            $schema[] = [
                'key' => 'gp',
                'label' => 'GP',
                'type' => 'number',
                'bounds' => $this->bounds($base, 'gp'),
                'step' => 1,
            ];
        }

        return $schema;
    }

    /**
     * @param array<int,array<string,mixed>> $columns
     */
    private function cacheKeyForBase($base, array $columns, array $context = []): string
    {
        $query = $base->toBase();

        return 'stats_filter_schema:' . hash('sha256', json_encode([
            'context' => $context,
            'table' => $base->getModel()->getTable(),
            'sql' => $query->toSql(),
            'bindings' => $query->getBindings(),
            'columns' => collect($columns)
                ->map(fn (array $column): array => [
                    'key' => (string) ($column['key'] ?? ''),
                    'label' => (string) ($column['label'] ?? ''),
                ])
                ->values()
                ->all(),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<int,array<string,mixed>> $schema
     * @return array<string,bool>
     */
    public function physicalNumericKeys(array $schema): array
    {
        $virtualOrDerived = ['age', 'contract_value_num', 'contract_last_year_num'];

        return collect($schema)
            ->filter(function (array $definition) use ($virtualOrDerived): bool {
                return strtolower((string) ($definition['type'] ?? '')) === 'number'
                    && ! empty($definition['key'])
                    && ! in_array($definition['key'], $virtualOrDerived, true);
            })
            ->pluck('key')
            ->mapWithKeys(fn (mixed $key): array => [(string) $key => true])
            ->all();
    }

    /**
     * @return array<int,string>
     */
    public function leagueOptionsForBase($base): array
    {
        $table = $base->getModel()->getTable();
        if ($table !== 'stats') {
            return [];
        }

        return (clone $base)
            ->reorder()
            ->select('league_abbrev')
            ->whereNotNull('league_abbrev')
            ->distinct()
            ->pluck('league_abbrev')
            ->filter()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return array<int,string>
     */
    private function teamOptionsForBase($base): array
    {
        $table = $base->getModel()->getTable();

        if ($table === 'stats') {
            return (clone $base)
                ->reorder()
                ->select('nhl_team_abbrev')
                ->whereNotNull('nhl_team_abbrev')
                ->distinct()
                ->pluck('nhl_team_abbrev')
                ->filter()
                ->values()
                ->all();
        }

        return (clone $base)
            ->reorder()
            ->select('pf.team_abbrev')
            ->whereNotNull('pf.team_abbrev')
            ->distinct()
            ->pluck('pf.team_abbrev')
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<int,string>
     */
    private function positionOptionsForBase($base): array
    {
        $table = $base->getModel()->getTable();

        if ($table === 'stats') {
            return (clone $base)
                ->reorder()
                ->join('players as ppos', 'ppos.id', '=', 'stats.player_id')
                ->select('ppos.position')
                ->whereNotNull('ppos.position')
                ->distinct()
                ->pluck('ppos.position')
                ->filter()
                ->values()
                ->all();
        }

        return (clone $base)
            ->reorder()
            ->select('pf.position')
            ->whereNotNull('pf.position')
            ->distinct()
            ->pluck('pf.position')
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array{min:int,max:int}
     */
    private function bounds($base, string $key): array
    {
        $column = $this->mapFilterColumn($base, $key);
        if (! $column) {
            return ['min' => 0, 'max' => 0];
        }

        $query = (clone $base)->reorder();

        try {
            $minValue = (clone $query)->min($column);
            $maxValue = (clone $query)->max($column);
        } catch (QueryException $exception) {
            if (
                $exception->getCode() === '42S22'
                || str_contains($exception->getMessage(), 'Unknown column')
                || str_contains($exception->getMessage(), '42703')
            ) {
                return ['min' => 0, 'max' => 0];
            }

            throw $exception;
        }

        $min = (float) ($minValue ?? 0);
        $max = (float) ($maxValue ?? 0);
        if ($min > $max) {
            [$min, $max] = [$max, $min];
        }

        return ['min' => (int) floor($min), 'max' => (int) ceil($max)];
    }

    /**
     * @return array{min:int,max:int}
     */
    private function ageBoundsForBase($base): array
    {
        $query = (clone $base)
            ->reorder()
            ->whereNotNull('pf.dob');

        $earliestDob = (clone $query)->min('pf.dob');
        $latestDob = (clone $query)->max('pf.dob');

        if (! $earliestDob && ! $latestDob) {
            return ['min' => 16, 'max' => 45];
        }

        $minAge = $latestDob ? Carbon::parse($latestDob)->age : null;
        $maxAge = $earliestDob ? Carbon::parse($earliestDob)->age : null;

        if ($minAge === null && $maxAge !== null) {
            $minAge = $maxAge;
        }
        if ($maxAge === null && $minAge !== null) {
            $maxAge = $minAge;
        }
        if ($minAge === null || $maxAge === null) {
            return ['min' => 16, 'max' => 45];
        }
        if ($minAge > $maxAge) {
            [$minAge, $maxAge] = [$maxAge, $minAge];
        }

        return ['min' => (int) $minAge, 'max' => (int) $maxAge];
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
