<?php

declare(strict_types=1);

namespace App\Support\Stats;

use App\Models\Perspective;
use App\Models\PlatformLeague;

/**
 * Builds synthetic league stats perspectives and provider scoring settings.
 */
final class LeagueStatsPerspectiveFactory
{
    /**
     * Return the synthetic Yahoo scoring perspective slug when supported.
     */
    public function leagueScoringPerspectiveSlug(PlatformLeague $league): ?string
    {
        return (string) ($league->platform ?? '') === 'yahoo'
            ? 'yahoo-league-' . $league->id
            : null;
    }

    /**
     * Return the synthetic Fantrax league perspective slug when supported.
     */
    public function fantraxLeaguePerspectiveSlug(PlatformLeague $league): ?string
    {
        return (string) ($league->platform ?? '') === 'fantrax'
            ? 'fantrax-league-' . $league->id
            : null;
    }

    /**
     * Return the default stats perspective slug for the league page.
     */
    public function defaultPerspectiveSlug($user, PlatformLeague $league, callable $defaultSavedPerspective): string
    {
        $leaguePerspectiveSlug = $this->leagueScoringPerspectiveSlug($league);
        $fantraxPerspectiveSlug = $this->fantraxLeaguePerspectiveSlug($league);

        if ($leaguePerspectiveSlug !== null && $this->leagueScoringPerspectiveSettings($league) !== null) {
            return $leaguePerspectiveSlug;
        }

        if ($fantraxPerspectiveSlug !== null) {
            return $fantraxPerspectiveSlug;
        }

        $perspective = $defaultSavedPerspective($user);

        return (string) ($perspective->slug ?? $perspective->name);
    }

    /**
     * Return the synthetic league perspectives followed by normal user perspectives.
     *
     * @return array<int,array{id:int|string|null,slug:string,name:string,is_slicable:bool}>
     */
    public function perspectives($user, PlatformLeague $league): array
    {
        $leaguePerspectiveSlug = $this->leagueScoringPerspectiveSlug($league);
        $fantraxPerspectiveSlug = $this->fantraxLeaguePerspectiveSlug($league);
        $leaguePerspective = $leaguePerspectiveSlug !== null && $this->leagueScoringPerspectiveSettings($league) !== null
            ? [[
                'id' => $leaguePerspectiveSlug,
                'slug' => $leaguePerspectiveSlug,
                'name' => $league->name . ' Scoring',
                'is_slicable' => true,
            ]]
            : [];
        $fantraxPerspective = $fantraxPerspectiveSlug !== null
            ? [[
                'id' => $fantraxPerspectiveSlug,
                'slug' => $fantraxPerspectiveSlug,
                'name' => $league->name . ' Fantrax',
                'is_slicable' => true,
            ]]
            : [];

        $perspectives = Perspective::forUser($user)
            ->orderBy('id')
            ->get()
            ->map(static fn (Perspective $perspective): array => [
                'id' => $perspective->id,
                'slug' => $perspective->slug ?? $perspective->name,
                'name' => $perspective->name,
                'is_slicable' => (bool) ($perspective->is_slicable ?? true),
            ])
            ->values()
            ->all();

        return [...$leaguePerspective, ...$fantraxPerspective, ...$perspectives];
    }

    /**
     * Build ephemeral perspective settings from a fully mapped league scoring configuration.
     *
     * @return array<string,mixed>|null
     */
    public function leagueScoringPerspectiveSettings(PlatformLeague $league): ?array
    {
        $categories = data_get($league, 'scoring_settings.categories', []);

        if (! is_array($categories) || $categories === []) {
            return null;
        }

        $scoringOrderByStatId = $this->leagueScoringOrderByStatId($league);
        $categoryRows = collect(array_is_list($categories) ? $categories : array_values($categories))
            ->filter(static fn (mixed $category): bool => is_array($category))
            ->map(static function (array $category) use ($scoringOrderByStatId): array {
                $category['display_order'] = (int) (
                    $scoringOrderByStatId[(string) ($category['id'] ?? '')]
                    ?? $category['scoring_order']
                    ?? PHP_INT_MAX
                );

                return $category;
            })
            ->sortBy('display_order')
            ->values();

        if ($categoryRows->isEmpty()) {
            return null;
        }

        if ($categoryRows->contains(static fn (array $category): bool => trim((string) ($category['stat_key'] ?? '')) === '')) {
            return null;
        }

        $columns = $categoryRows
            ->filter(static fn (mixed $category): bool => is_array($category))
            ->map(static function (array $category): ?array {
                $statKey = trim((string) ($category['stat_key'] ?? ''));

                if ($statKey === '') {
                    return null;
                }

                $label = trim((string) (
                    $category['short']
                    ?? $category['label']
                    ?? $category['name']
                    ?? strtoupper($statKey)
                ));

                return [
                    'key' => $statKey,
                    'label' => $label !== '' ? $label : strtoupper($statKey),
                    'type' => in_array($statKey, ['sv_pct', 'gaa'], true) ? 'float' : 'int',
                ];
            })
            ->filter()
            ->unique(static fn (array $column): string => $column['key'])
            ->values()
            ->all();

        if ($columns === []) {
            return null;
        }

        $goalieKeys = $this->goalieScoringStatKeys();
        $goalieColumns = collect($columns)
            ->filter(static fn (array $column): bool => in_array($column['key'], $goalieKeys, true))
            ->values()
            ->all();
        $skaterColumns = collect($columns)
            ->reject(static fn (array $column): bool => in_array($column['key'], $goalieKeys, true))
            ->values()
            ->all();

        $sortKey = collect(['pts', 'g', 'a', 'wins', 'sv', 'sog'])
            ->first(static fn (string $key): bool => collect($columns)->contains('key', $key))
            ?? $columns[0]['key'];
        $skaterSortKey = collect(['pts', 'g', 'a', 'sog'])
            ->first(static fn (string $key): bool => collect($skaterColumns)->contains('key', $key))
            ?? ($skaterColumns[0]['key'] ?? $sortKey);
        $goalieSortKey = collect(['wins', 'sv', 'sv_pct', 'gaa'])
            ->first(static fn (string $key): bool => collect($goalieColumns)->contains('key', $key))
            ?? ($goalieColumns[0]['key'] ?? $sortKey);

        return [
            'columns' => $columns,
            'sort' => [
                'sortKey' => $skaterSortKey,
                'sortDirection' => 'desc',
            ],
            'columnGroups' => [
                'skater' => $skaterColumns,
                'goalie' => $goalieColumns,
            ],
            'columnGroupSort' => [
                'skater' => [
                    'sortKey' => $skaterSortKey,
                    'sortDirection' => 'desc',
                ],
                'goalie' => [
                    'sortKey' => $goalieSortKey,
                    'sortDirection' => 'desc',
                ],
            ],
            'activeColumnGroup' => 'skater',
            'filters' => [],
            'ui' => [
                'positionButtons' => ['F', 'C', 'LW', 'RW', 'D', 'G'],
            ],
        ];
    }

    /**
     * Replace active Fantrax stat columns with goalie categories for a goalie-mode payload.
     *
     * @param array<string,mixed> $settings
     * @return array<string,mixed>
     */
    public function withFantraxGoalieSettings(array $settings, PlatformLeague $league): array
    {
        $goalieColumns = $this->fantraxGoalieScoringColumns($league);

        if ($goalieColumns === []) {
            $goalieColumns = $this->defaultGoalieScoringColumns();
        }

        $settings['columns'] = $goalieColumns;
        $settings['sort'] = [
            'sortKey' => $this->goalieSortKey($goalieColumns),
            'sortDirection' => 'desc',
        ];
        $settings['filters'] = is_array($settings['filters'] ?? null) ? $settings['filters'] : [];
        $settings['filters']['pos_type'] = [
            'operator' => '=',
            'value' => ['G'],
        ];
        $settings['ui'] = is_array($settings['ui'] ?? null) ? $settings['ui'] : [];
        $settings['ui']['positionButtons'] = ['F', 'C', 'LW', 'RW', 'D', 'G'];

        return $settings;
    }

    /**
     * Add auto-mapped Fantrax goalie categories as a column group without requiring full category mapping.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function withFantraxGoalieColumnGroup(array $payload, PlatformLeague $league): array
    {
        $goalieColumns = $this->fantraxGoalieScoringColumns($league);

        if ($goalieColumns === []) {
            return $payload;
        }

        $payload['settings'] = is_array($payload['settings'] ?? null) ? $payload['settings'] : [];
        $payload['settings']['columnGroups'] = is_array($payload['settings']['columnGroups'] ?? null)
            ? $payload['settings']['columnGroups']
            : [];
        $payload['settings']['columnGroups']['skater'] = $this->skaterColumnsFromPayload($payload);
        $payload['settings']['columnGroups']['goalie'] = $goalieColumns;
        $payload['settings']['columnGroupSort'] = is_array($payload['settings']['columnGroupSort'] ?? null)
            ? $payload['settings']['columnGroupSort']
            : [];
        $payload['settings']['columnGroupSort']['goalie'] = [
            'sortKey' => $this->goalieSortKey($goalieColumns),
            'sortDirection' => 'desc',
        ];
        $payload['settings']['activeColumnGroup'] ??= 'skater';

        return $payload;
    }

    /**
     * @return array<int,array{key:string,label:string,type:string}>
     */
    public function fantraxGoalieScoringColumns(PlatformLeague $league): array
    {
        $goalieKeys = $this->goalieScoringStatKeys();
        $seen = [];

        return collect(data_get($league, 'scoring_settings.categories', []))
            ->filter(static fn (mixed $category): bool => is_array($category))
            ->map(function (array $category) use ($goalieKeys, &$seen): ?array {
                $manualStatKey = trim((string) ($category['stat_key'] ?? ''));
                $autoStatKey = trim((string) ($category['auto_stat_key'] ?? ''));
                $statKey = $manualStatKey !== '' ? $manualStatKey : $autoStatKey;

                if ($statKey === '' || ! in_array($statKey, $goalieKeys, true) || isset($seen[$statKey])) {
                    return null;
                }

                $seen[$statKey] = true;
                $label = trim((string) (
                    $category['short']
                    ?? $category['label']
                    ?? $category['name']
                    ?? strtoupper($statKey)
                ));

                return [
                    'key' => $statKey,
                    'label' => $label !== '' ? $label : strtoupper($statKey),
                    'type' => in_array($statKey, ['sv_pct', 'gaa'], true) ? 'float' : 'int',
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<int,array{key:string,label:string,type:string}>
     */
    public function defaultGoalieScoringColumns(): array
    {
        return [
            ['key' => 'wins', 'label' => 'W', 'type' => 'int'],
            ['key' => 'sv', 'label' => 'SV', 'type' => 'int'],
            ['key' => 'sv_pct', 'label' => 'SV%', 'type' => 'float'],
            ['key' => 'gaa', 'label' => 'GAA', 'type' => 'float'],
            ['key' => 'so', 'label' => 'SO', 'type' => 'int'],
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $columns
     */
    public function goalieSortKey(array $columns): string
    {
        return collect(['wins', 'sv', 'sv_pct', 'gaa'])
            ->first(static fn (string $key): bool => collect($columns)->contains('key', $key))
            ?? (string) ($columns[0]['key'] ?? 'gp');
    }

    /**
     * @return array<int,string>
     */
    private function goalieScoringStatKeys(): array
    {
        return [
            'wins',
            'losses',
            'ot_losses',
            'starts',
            'relief_appearances',
            'quality_starts',
            'really_bad_starts',
            'quality_start_percentage',
            'sv',
            'saves',
            'sa',
            'shots_against',
            'ga',
            'goals_against',
            'gaa',
            'sv_pct',
            'ev_sv_pct',
            'pp_sv_pct',
            'pk_sv_pct',
            'so',
            'shutouts',
            'shosv',
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<int,array<string,mixed>>
     */
    private function skaterColumnsFromPayload(array $payload): array
    {
        $identityKeys = [
            'name',
            'player',
            'team',
            'league',
            'pos',
            'pos_type',
            'age',
            'contract_value',
            'contract_value_num',
            'contract_last_year',
            'contract_last_year_num',
            'avatar_url',
            'head_shot_url',
            'id',
            'nhl_player_id',
            'gp',
        ];

        return collect($payload['headings'] ?? [])
            ->filter(static fn (mixed $heading): bool => is_array($heading))
            ->reject(static fn (array $heading): bool => in_array((string) ($heading['key'] ?? ''), $identityKeys, true))
            ->values()
            ->all();
    }

    /**
     * @return array<string,int>
     */
    private function leagueScoringOrderByStatId(PlatformLeague $league): array
    {
        $stats = data_get($league, 'scoring_settings.raw_payload.stat_modifiers.stats.stat', []);

        if (! is_array($stats)) {
            return [];
        }

        if (isset($stats['stat_id'])) {
            $stats = [$stats];
        }

        $rows = array_values($stats);
        $count = count($rows);

        return collect($rows)
            ->filter(static fn (mixed $stat): bool => is_array($stat))
            ->mapWithKeys(static function (array $stat, int $index) use ($count): array {
                $statId = trim((string) ($stat['stat_id'] ?? ''));

                return $statId !== '' ? [$statId => $count - $index] : [];
            })
            ->all();
    }
}
