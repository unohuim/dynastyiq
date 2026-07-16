<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PlatformLeague;
use App\Models\PlatformLeaguePlayerStat;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Persists and reads provider-earned player stats scoped to a platform league.
 */
final class PlatformLeaguePlayerStatService
{
    /**
     * Sync stat rows exposed inside a Fantrax roster payload.
     *
     * @param array<string,mixed> $teamRosters
     * @param array<string,int> $teamIdMap
     * @param array<string,int> $fantraxToPlayerId
     */
    public function syncFromRosterPayload(
        PlatformLeague $league,
        array $teamRosters,
        array $teamIdMap,
        array $fantraxToPlayerId,
        CarbonInterface $now,
    ): int {
        if (! $this->tableExists()) {
            return 0;
        }

        $rows = [];

        foreach ($teamRosters as $providerTeamId => $team) {
            if (! is_array($team)) {
                continue;
            }

            $platformTeamId = $teamIdMap[(string) $providerTeamId] ?? null;
            $items = $team['rosterItems']
                ?? $team['players']
                ?? $team['roster']
                ?? [];

            foreach ((array) $items as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $row = $this->statRow($league, $item, $fantraxToPlayerId, $now, [
                    'platform_team_id' => $platformTeamId,
                    'provider_team_id' => (string) $providerTeamId,
                    'scope' => 'season',
                ]);

                if ($row !== null) {
                    $rows[] = $row;
                }
            }
        }

        return $this->upsertRows($rows);
    }

    /**
     * Sync stat rows from a provider stats endpoint payload.
     *
     * @param array<string,mixed> $payload
     * @param array<string,int> $teamIdMap
     * @param array<string,int> $fantraxToPlayerId
     */
    public function syncFromProviderPayload(
        PlatformLeague $league,
        array $payload,
        array $teamIdMap,
        array $fantraxToPlayerId,
        CarbonInterface $now,
    ): int {
        if (! $this->tableExists()) {
            return 0;
        }

        $rows = [];

        foreach ($this->candidateRows($payload) as $candidate) {
            $providerTeamId = $this->stringValue($this->firstValue($candidate, [
                'teamId',
                'team_id',
                'platform_team_id',
                'fantasyTeamId',
                'rosterTeamId',
            ]));
            $row = $this->statRow($league, $candidate, $fantraxToPlayerId, $now, [
                'platform_team_id' => $providerTeamId !== '' ? ($teamIdMap[$providerTeamId] ?? null) : null,
                'provider_team_id' => $providerTeamId !== '' ? $providerTeamId : null,
                'scope' => $this->stringValue($this->firstValue($candidate, ['scope', 'statScope'])) ?: 'season',
            ]);

            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $this->upsertRows($rows);
    }

    /**
     * Return normalized provider stats keyed by canonical player id.
     *
     * @return array<int,array<string,mixed>>
     */
    public function statsForLeagueByPlayerId(PlatformLeague $league, ?string $season = null): array
    {
        if (! $this->tableExists()) {
            return [];
        }

        $season = $season ?: $this->currentFantasySeasonKey();
        $aliases = $this->statAliasMap($league);

        return PlatformLeaguePlayerStat::query()
            ->where('platform_league_id', $league->id)
            ->where('scope', 'season')
            ->where('season', $season)
            ->whereNotNull('player_id')
            ->get(['player_id', 'stats', 'synced_at'])
            ->sortByDesc('synced_at')
            ->groupBy(static fn (PlatformLeaguePlayerStat $row): int => (int) $row->player_id)
            ->mapWithKeys(function (Collection $rows) use ($aliases): array {
                /** @var PlatformLeaguePlayerStat|null $row */
                $row = $rows->first();

                return $row instanceof PlatformLeaguePlayerStat
                    ? [(int) $row->player_id => $this->normalizeStats((array) $row->stats, $aliases)]
                    : [];
            })
            ->all();
    }

    /**
     * Replace stat values in a league stats payload when provider-earned rows exist.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function overlayStatsPayload(array $payload, PlatformLeague $league, ?string $season = null): array
    {
        $statsByPlayerId = $this->statsForLeagueByPlayerId($league, $season);

        if ($statsByPlayerId === [] || ! is_array($payload['data'] ?? null)) {
            return $payload;
        }

        $statKeys = collect($payload['headings'] ?? [])
            ->filter(static fn (mixed $heading): bool => is_array($heading))
            ->pluck('key')
            ->map(static fn (mixed $key): string => (string) $key)
            ->reject(static fn (string $key): bool => in_array($key, [
                'id',
                'player',
                'name',
                'team',
                'league',
                'pos',
                'pos_type',
                'age',
                'avatar_url',
                'head_shot_url',
                'gp',
                'contract_value',
                'contract_value_num',
                'contract_last_year',
                'contract_last_year_num',
            ], true))
            ->values()
            ->all();

        $payload['data'] = collect($payload['data'])
            ->map(static function (mixed $row) use ($statsByPlayerId, $statKeys): mixed {
                if (! is_array($row)) {
                    return $row;
                }

                $playerId = (int) ($row['id'] ?? $row['player_id'] ?? 0);
                $providerStats = $statsByPlayerId[$playerId] ?? null;

                if ($providerStats === null) {
                    return $row;
                }

                foreach ($statKeys as $key) {
                    if (array_key_exists($key, $providerStats)) {
                        $row[$key] = $providerStats[$key];
                    }
                }

                $row['stats_source'] = 'provider';

                return $row;
            })
            ->values()
            ->all();

        $sortKey = (string) data_get($payload, 'settings.defaultSort', '');
        $sortDirection = strtolower((string) data_get($payload, 'settings.defaultSortDirection', 'desc'));

        if ($sortKey !== '') {
            $payload['data'] = collect($payload['data'])
                ->sortBy(
                    static fn (mixed $row): mixed => is_array($row) ? ($row[$sortKey] ?? null) : null,
                    SORT_REGULAR,
                    $sortDirection !== 'asc',
                )
                ->values()
                ->all();
        }

        $payload['meta'] = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
        $payload['meta']['statsSource'] = 'provider';

        return $payload;
    }

    /**
     * Return the current fantasy season key using the July 1 turnover rule.
     */
    public function currentFantasySeasonKey(): string
    {
        $now = now();
        $startYear = $now->month >= 7 ? $now->year : $now->year - 1;

        return (string) $startYear . (string) ($startYear + 1);
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,int> $fantraxToPlayerId
     * @param array<string,mixed> $context
     * @return array<string,mixed>|null
     */
    private function statRow(
        PlatformLeague $league,
        array $row,
        array $fantraxToPlayerId,
        CarbonInterface $now,
        array $context,
    ): ?array {
        $platformPlayerId = $this->stringValue($this->firstValue($row, [
            'id',
            'playerId',
            'player_id',
            'fantraxId',
            'fantraxPlayerId',
            'platform_player_id',
        ]));

        if ($platformPlayerId === '') {
            return null;
        }

        $aliases = $this->statAliasMap($league);
        $stats = $this->statsFromRow($row, $aliases);

        if ($stats === []) {
            return null;
        }

        $season = $this->stringValue($this->firstValue($row, [
            'season',
            'seasonId',
            'season_id',
            'fantasySeason',
        ])) ?: $this->currentFantasySeasonKey();
        $scope = $this->stringValue($context['scope'] ?? null) ?: 'season';
        $scoringPeriod = $this->stringValue($this->firstValue($row, [
            'scoringPeriod',
            'scoring_period',
            'period',
            'week',
        ]));

        return [
            'platform_league_id' => (int) $league->id,
            'platform_team_id' => $context['platform_team_id'] ?? null,
            'player_id' => $fantraxToPlayerId[$platformPlayerId] ?? null,
            'platform' => (string) $league->platform,
            'provider_identity_key' => implode(':', array_filter([
                (string) $league->platform,
                (string) $league->platform_league_id,
                $season,
                $scope,
                $scoringPeriod !== '' ? $scoringPeriod : null,
                $platformPlayerId,
            ])),
            'platform_player_id' => $platformPlayerId,
            'season' => $season,
            'scoring_period' => $scoringPeriod !== '' ? $scoringPeriod : null,
            'scope' => $scope,
            'stats' => $stats,
            'raw_payload' => $row,
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,string> $aliases
     * @return array<string,mixed>
     */
    private function statsFromRow(array $row, array $aliases): array
    {
        $containers = [
            $row['stats'] ?? null,
            $row['seasonStats'] ?? null,
            $row['fantasyStats'] ?? null,
            $row['scoringStats'] ?? null,
            $row['statValues'] ?? null,
            $row['statsByCategory'] ?? null,
        ];
        $stats = [];

        foreach ($containers as $container) {
            if (is_array($container)) {
                $stats = array_merge($stats, $this->normalizeStats($container, $aliases));
            }
        }

        $stats = array_merge($stats, $this->normalizeStats($row, $aliases));

        return $stats;
    }

    /**
     * @param array<string,mixed>|array<int,mixed> $stats
     * @param array<string,string> $aliases
     * @return array<string,mixed>
     */
    private function normalizeStats(array $stats, array $aliases): array
    {
        $normalized = [];

        foreach ($stats as $key => $value) {
            if (is_array($value)) {
                $statKey = $this->stringValue($this->firstValue($value, [
                    'key',
                    'code',
                    'shortName',
                    'short',
                    'name',
                    'label',
                    'category',
                    'scoringCategory',
                ]));
                $statValue = $this->firstValue($value, ['value', 'score', 'total', 'amount']);

                if ($statKey === '' || ! is_numeric($statValue)) {
                    continue;
                }

                $key = $statKey;
                $value = $statValue;
            }

            if (! is_numeric($value)) {
                continue;
            }

            $canonicalKey = $aliases[$this->normalizeKey((string) $key)] ?? null;

            if ($canonicalKey === null) {
                continue;
            }

            $numeric = (float) $value;
            $normalized[$canonicalKey] = floor($numeric) === $numeric ? (int) $numeric : $numeric;
        }

        return $normalized;
    }

    /**
     * @return array<string,string>
     */
    private function statAliasMap(PlatformLeague $league): array
    {
        $aliases = [
            'gp' => 'gp',
            'gamesplayed' => 'gp',
            'games played' => 'gp',
            'w' => 'wins',
            'wins' => 'wins',
            'sv' => 'sv',
            'saves' => 'sv',
            'sv%' => 'sv_pct',
            'save percentage' => 'sv_pct',
            'gaa' => 'gaa',
            'goals against average' => 'gaa',
            'so' => 'so',
            'shutouts' => 'so',
        ];

        foreach (app(PlatformLeagueScoringCategoryService::class)->payloadRows($league) as $category) {
            if (! is_array($category)) {
                continue;
            }

            $columnKey = trim((string) ($category['stat_key'] ?? ''))
                ?: trim((string) ($category['id'] ?? ''));

            if ($columnKey === '') {
                continue;
            }

            foreach ([
                $columnKey,
                $category['id'] ?? null,
                $category['short'] ?? null,
                $category['label'] ?? null,
                $category['name'] ?? null,
                $category['dictionary_provider_label'] ?? null,
                $category['auto_stat_key'] ?? null,
            ] as $alias) {
                $alias = $this->normalizeKey((string) $alias);

                if ($alias !== '') {
                    $aliases[$alias] = $columnKey;
                }
            }

            $id = trim((string) ($category['id'] ?? ''));

            if (str_contains($id, ':')) {
                $aliases[$this->normalizeKey(explode(':', $id, 2)[1])] = $columnKey;
            }
        }

        return $aliases;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<int,array<string,mixed>>
     */
    private function candidateRows(array $payload): array
    {
        $rows = [];
        $stack = [$payload];

        while ($stack !== []) {
            $current = array_pop($stack);

            if (! is_array($current)) {
                continue;
            }

            if ($this->looksLikePlayerStatRow($current)) {
                $rows[] = $current;
                continue;
            }

            foreach ($current as $value) {
                if (is_array($value)) {
                    $stack[] = $value;
                }
            }
        }

        return $rows;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function looksLikePlayerStatRow(array $row): bool
    {
        $playerId = $this->firstValue($row, [
            'id',
            'playerId',
            'player_id',
            'fantraxId',
            'fantraxPlayerId',
            'platform_player_id',
        ]);

        if ($this->stringValue($playerId) === '') {
            return false;
        }

        foreach (['stats', 'seasonStats', 'fantasyStats', 'scoringStats', 'statValues', 'statsByCategory'] as $key) {
            if (is_array($row[$key] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     */
    private function upsertRows(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $rows = array_map(static function (array $row): array {
            $row['stats'] = json_encode($row['stats'], JSON_THROW_ON_ERROR);
            $row['raw_payload'] = json_encode($row['raw_payload'], JSON_THROW_ON_ERROR);

            return $row;
        }, $rows);

        PlatformLeaguePlayerStat::query()->upsert(
            $rows,
            ['platform_league_id', 'provider_identity_key'],
            [
                'platform_team_id',
                'player_id',
                'platform',
                'platform_player_id',
                'season',
                'scoring_period',
                'scope',
                'stats',
                'raw_payload',
                'synced_at',
                'updated_at',
            ],
        );

        return count($rows);
    }

    /**
     * @param array<string,mixed> $row
     * @param array<int,string> $keys
     */
    private function firstValue(array $row, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row)) {
                return $row[$key];
            }
        }

        return null;
    }

    private function stringValue(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function normalizeKey(string $key): string
    {
        $key = strtolower(trim($key));
        $key = preg_replace('/[^a-z0-9_:%+\\/-]+/', ' ', $key) ?? '';
        $key = preg_replace('/\s+/', ' ', $key) ?? '';

        return trim($key);
    }

    private function tableExists(): bool
    {
        return Schema::hasTable('platform_league_player_stats');
    }
}
