<?php

declare(strict_types=1);

namespace App\Support\Stats;

use App\Models\NhlGameSummary;
use App\Models\NhlSeasonStat;
use App\Models\Player;
use App\Models\Perspective;
use App\Models\Stat;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Cache;

/**
 * Coordinates extracted stats payload services behind the controller boundary.
 */
final class StatsPayloadBuilder
{
    public function __construct(
        private readonly StatsQueryFilterApplier $queryFilterApplier,
        private readonly StatsFilterSchemaProvider $schemaProvider,
        private readonly StatsDerivedFilterApplier $derivedFilterApplier,
        private readonly StatsPayloadAssembler $payloadAssembler,
    ) {
    }

    /** Identify a public report revision without sharing private or user-specific payloads. */
    public function preparedKey(Perspective $perspective, string $season, int $gameType): string
    {
        return 'nhl:stats:table:v1:'.$season.':'.$gameType.':'.$perspective->id.':'.hash('sha256',
            json_encode([$perspective->slug, $perspective->settings, $perspective->is_slicable], JSON_THROW_ON_ERROR));
    }

    /** Build every supported slice off-request, publishing one atomic report bundle. */
    public function refreshPreparedSeasonPayload(Perspective $perspective, string $season, int $gameType): void
    {
        if ($perspective->visibility !== 'public_guest') {
            throw new \InvalidArgumentException('Only guest-visible reports can enter the public cache.');
        }
        // Legacy seeded rows can contain a JSON string even after Eloquent's array cast.
        $settings = $perspective->settings;
        if (is_string($settings)) {
            $settings = json_decode($settings, true);
        }
        $settings = is_array($settings) ? $settings : [];
        $slices = [];
        foreach (['total', 'pgp', 'p60'] as $slice) {
            [$payload] = $this->buildSeasonPayload(new SeasonStatsPayloadRequest(
                null, $perspective, $settings, (bool) $perspective->is_slicable,
                $season, $slice, $gameType, null, StatsFilterSet::fromRequest(null),
            ), freshSchema: true);
            $payload['data'] = collect($payload['data'])->values()->all();
            $payload['meta']['cache_status'] = 'ready';
            $payload['meta']['prepared_at'] = now()->toIso8601String();
            $slices[$slice] = $payload;
        }
        if (! Cache::forever($this->preparedKey($perspective, $season, $gameType), $slices)) {
            throw new \RuntimeException('Public stats report publication failed.');
        }
    }

    /**
     * Reuse or build a public report snapshot, then apply selections to its calculated rows.
     *
     * @return array{0:array<string,mixed>,1:array<int,mixed>,2:string,3:array<string,float>}
     */
    public function readPreparedSeasonPayload(SeasonStatsPayloadRequest $input): array
    {
        if (($input->perspective->visibility ?? null) !== 'public_guest') {
            throw new \InvalidArgumentException('Only guest-visible reports can read public snapshots.');
        }
        $season = (string) ($input->seasonFilter ?: app(\App\Services\NhlStatsDashboardPayload::class)->latestPreparedSeason());
        $gameType = $this->isProspectsPerspective($input->perspective) ? 2 : (int) ($input->gameType ?? 2);
        $bundle = Cache::get($this->preparedKey($input->perspective, $season, $gameType));
        $slice = $input->canSlice ? $input->slice : 'total';
        $payload = $bundle[$slice] ?? null;
        if (! is_array($payload)) {
            $key = $this->preparedKey($input->perspective, $season, $gameType);
            $bundle = Cache::lock($key.':build', 240)->block(180, function () use ($key, $input, $season, $gameType, $slice): array {
                $existing = Cache::get($key);
                if (is_array($existing) && isset($existing[$slice])) {
                    return $existing;
                }
                $this->refreshPreparedSeasonPayload($input->perspective, $season, $gameType);
                return Cache::get($key);
            });
            $payload = $bundle[$slice];
        }
        $filters = $input->filterSet;
        $totalRows = collect($bundle['total']['data']);
        $identity = static fn (array $row): string => ($row['player_id'] ?? $row['nhl_player_id'] ?? '').'|'.($row['league'] ?? '');
        $totals = $totalRows->keyBy($identity);
        $positions = collect($filters->positions)->flatMap(static fn (string $pos): array => match (strtoupper($pos)) {
            'LW' => ['L', 'LW'], 'RW' => ['R', 'RW'], default => [strtoupper($pos)],
        })->all();
        if (array_diff($positions, ['G']) !== []) {
            $positions = array_values(array_diff($positions, ['G']));
        }
        $numericKeys = array_column(array_filter($payload['meta']['filterSchema'] ?? [],
            static fn (array $field): bool => in_array($field['type'] ?? '', ['int', 'float', 'number'], true)), 'key');
        $numericKeys = array_unique([...$numericKeys, 'gp', 'age', 'contract_value_num', 'contract_last_year_num']);
        $payload['data'] = collect($payload['data'])->filter(function (array $row) use ($filters, $totals, $identity, $positions, $numericKeys): bool {
            if ($positions !== [] && ! in_array(strtoupper((string) ($row['pos'] ?? '')), $positions, true)) {
                return false;
            }
            if ($filters->positionTypes !== [] && (! in_array($row['pos_type'] ?? '', $filters->positionTypes, true)
                || (in_array('G', $filters->positionTypes, true) && ($row['pos'] ?? '') !== 'G'))) {
                return false;
            }
            foreach (['team' => $filters->teams, 'league' => $filters->leagues, 'drafted_year' => $filters->draftYears] as $key => $values) {
                if ($values !== [] && ! in_array($row[$key] ?? null, $values, false)) {
                    return false;
                }
            }
            if ($filters->draftYears === []) {
                $min = $filters->draftYearRange['min'];
                $max = $filters->draftYearRange['max'];
                if ($min !== null && $max !== null && $min > $max) {
                    [$min, $max] = [$max, $min];
                }
                if (($min !== null || $max !== null) && (($row['drafted_year'] ?? null) === null
                    || ($min !== null && $row['drafted_year'] < $min) || ($max !== null && $row['drafted_year'] > $max))) {
                    return false;
                }
            }
            $total = $totals->get($identity($row), $row);
            foreach ($filters->numericRanges as $key => $range) {
                if (! in_array($key, $numericKeys, true)) {
                    continue;
                }
                $value = $key === 'toi' ? ($total['toi_total_seconds'] ?? null) : ($total[$key] ?? null);
                if (($range['min'] !== null || $range['max'] !== null) && ($value === null
                    || ($range['min'] !== null && $value < $range['min']) || ($range['max'] !== null && $value > $range['max']))) {
                    return false;
                }
            }
            return true;
        })->values()->all();
        $payload['meta']['pos'] = $filters->positions;
        $payload['meta']['pos_type'] = $filters->positionTypes;
        $payload['meta']['appliedFilters'] = array_filter([
            'team' => $filters->teams, 'league' => $filters->leagues,
            'entry_draft_year' => $filters->draftYears ?: array_filter($filters->draftYearRange, static fn ($value): bool => $value !== null),
            ...$filters->numericRanges,
        ], static fn ($value): bool => $value !== []);

        return [$payload, $payload['meta']['availableSeasons'] ?? [], $season, []];
    }

    /**
     * Build and format a season players payload from perspective settings.
     *
     * @param bool $freshSchema Recalculate filter bounds during background snapshot publication.
     *
     * @return array{0:array<string,mixed>,1:array<int,mixed>,2:string|null,3:array<string,float>}
     */
    public function buildSeasonPayload(SeasonStatsPayloadRequest $payloadRequest, bool $freshSchema = false): array
    {
        $buildProfileLast = hrtime(true);
        $buildTimings = [];
        $buildMark = static function (string $key) use (&$buildProfileLast, &$buildTimings): void {
            $now = hrtime(true);
            $buildTimings[$key] = round(($now - $buildProfileLast) / 1_000_000, 2);
            $buildProfileLast = $now;
        };

        $settings = $payloadRequest->settings;
        $filters = $settings['filters'] ?? [];
        $columns = $settings['columns'] ?? [];
        $sort = $settings['sort'] ?? ['sortKey' => 'pts', 'sortDirection' => 'desc'];

        $isProspects = $this->isProspectsPerspective($payloadRequest->perspective);
        $isDraftCentralContext = (bool) ($payloadRequest->request?->boolean('draft_context') ?? false);
        $lockedSeason = $filters['season_id']['value'] ?? null;
        $season = $lockedSeason ?: $payloadRequest->seasonFilter;

        $identityCols = $this->identityColumns($isProspects);
        $availableSeasons = [];
        $availableLeagues = [];
        $availableGameTypes = $isProspects ? [2] : [1, 2, 3];
        $effectiveGameType = 2;
        $buildMark('setup_ms');

        if ($isProspects) {
            $base = Stat::query()
                ->with(['player.contracts.seasons'])
                ->regularSeason()
                ->where('league_abbrev', '!=', 'NHL')
                ->where('stats.is_prospect', true);

            $base->select($base->getModel()->getTable() . '.*');
            $buildMark('base_query_ms');

            [$schema, $applied] = $this->buildSchemaAndApplyFilters(
                $base,
                $columns,
                $payloadRequest->request,
                $filters,
                $payloadRequest->filterSet,
                $this->schemaCacheContext($payloadRequest, 'season', $season, $effectiveGameType, $columns),
                $freshSchema,
            );
            $buildMark('schema_filters_ms');

            $availableLeagues = $this->schemaProvider->leagueOptionsForBase($base);
            $buildMark('league_options_ms');

            $availableSeasons = (clone $base)
                ->reorder()
                ->select('stats.season_id')
                ->distinct()
                ->pluck('stats.season_id')
                ->map(static fn (mixed $seasonId): string => (string) $seasonId)
                ->sortDesc()
                ->values()
                ->all();
            $buildMark('available_seasons_ms');

            if (! $season && $isDraftCentralContext) {
                $lastCompletedSeason = (string) ((int) now()->year - 1) . (string) now()->year;
                $season = in_array($lastCompletedSeason, $availableSeasons, true)
                    ? $lastCompletedSeason
                    : ($availableSeasons[0] ?? null);
            } elseif (! $season) {
                $season = $availableSeasons[0] ?? null;
            }

            if ($season) {
                $base->where('season_id', $season);
            }

            $stats = $base->get();
            $buildMark('stats_query_ms');

            $rows = $this->assembleRowsFromCollection(
                $stats,
                $columns,
                $payloadRequest->slice,
                $payloadRequest->canSlice,
                'prospects',
                ['draft_context' => $isDraftCentralContext],
            );
            $buildMark('assemble_rows_ms');

            $effectiveGameType = 2;
        } else {
            if (! $season) {
                $season = (string) NhlSeasonStat::query()->max('season_id');
            }
            $buildMark('season_resolve_ms');

            $effectiveGameType = (int) ($payloadRequest->gameType ?? 2);
            if (isset($filters['game_type']['value'])) {
                $effectiveGameType = (int) $filters['game_type']['value'];
            }

            $base = NhlSeasonStat::query()
                ->with(['player.contracts.seasons'])
                ->where('season_id', $season)
                ->where('game_type', $effectiveGameType);

            $base->select($base->getModel()->getTable() . '.*');
            $buildMark('base_query_ms');

            [$schema, $applied] = $this->buildSchemaAndApplyFilters(
                $base,
                $columns,
                $payloadRequest->request,
                $filters,
                $payloadRequest->filterSet,
                $this->schemaCacheContext($payloadRequest, 'season', $season, $effectiveGameType, $columns),
                $freshSchema,
            );
            $buildMark('schema_filters_ms');

            $stats = $base->get();
            $buildMark('stats_query_ms');

            $availableSeasons = NhlSeasonStat::query()
                ->select('season_id')
                ->distinct()
                ->pluck('season_id')
                ->sortDesc()
                ->values()
                ->all();
            $buildMark('available_seasons_ms');

            $rows = $this->assembleRowsFromCollection(
                $stats,
                $columns,
                $payloadRequest->slice,
                $payloadRequest->canSlice,
                'season',
                [
                    'season_id' => $season,
                    'game_type' => $effectiveGameType,
                ],
            );
            $buildMark('assemble_rows_ms');

            $rows = $this->appendOnIceRows($rows, $columns, [
                'season_id' => $season,
                'game_type' => $effectiveGameType,
            ]);
            $buildMark('append_on_ice_ms');
        }

        [$rows, $appliedExtra, $virtualSchema] = $this->applyPostFilters($payloadRequest->request, $rows);
        $buildMark('post_filters_ms');

        $sortKey = $sort['sortKey'] ?? 'pts';
        $sortDir = strtolower($sort['sortDirection'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $sorted = $rows->sortBy([[$sortKey, $sortDir]])->values();
        $buildMark('sort_ms');

        $headings = $this->mergeHeadings($identityCols, $columns);
        $mergedSchema = $this->mergeSchema($schema ?? [], $virtualSchema ?? []);
        $buildMark('headings_schema_ms');

        $applied['filters'] = array_merge($applied['filters'] ?? [], $appliedExtra['filters'] ?? []);
        $availability = (int) ($payloadRequest->request?->input('availability', 0) ?? 0);

        $formatted = [
            'headings' => $headings,
            'data' => $sorted,
            'settings' => [
                'sortable' => collect($headings)->pluck('key')->values()->all(),
                'defaultSort' => $sortKey,
                'defaultSortDirection' => $sortDir,
                'resource' => 'players',
                'slice' => $payloadRequest->canSlice ? $payloadRequest->slice : 'total',
                'columnGroups' => $settings['columnGroups'] ?? null,
                'columnGroupSort' => $settings['columnGroupSort'] ?? null,
                'activeColumnGroup' => $settings['activeColumnGroup'] ?? null,
                'fantasyScoring' => $settings['fantasyScoring'] ?? null,
            ],
            'meta' => [
                'availableSeasons' => $availableSeasons,
                'availableLeagues' => $availableLeagues,
                'availableGameTypes' => $availableGameTypes,
                'season' => $season,
                'game_type' => $effectiveGameType,
                'canSlice' => $payloadRequest->canSlice,
                'filterSchema' => $mergedSchema,
                'appliedFilters' => $applied['filters'] ?? [],
                'pos' => $applied['pos'] ?? [],
                'pos_type' => $applied['pos_type'] ?? [],
                'availability' => $availability,
                'league_id' => $availability > 0 ? $availability : null,
                'positionButtons' => $this->positionButtons($settings),
                'supportsDateRange' => ! $isProspects,
                'fantasyScoring' => $settings['fantasyScoring'] ?? null,
            ],
        ];
        $buildMark('format_ms');

        return [$formatted, $availableSeasons, $season, $buildTimings];
    }

    /**
     * Build and format a date-range players payload from perspective settings.
     *
     * @return array{0:array<string,mixed>,1:array<int,mixed>,2:null,3:array<string,float>}
     */
    public function buildRangePayload(RangeStatsPayloadRequest $payloadRequest): array
    {
        $buildProfileLast = hrtime(true);
        $buildTimings = [];
        $buildMark = static function (string $key) use (&$buildProfileLast, &$buildTimings): void {
            $now = hrtime(true);
            $buildTimings[$key] = round(($now - $buildProfileLast) / 1_000_000, 2);
            $buildProfileLast = $now;
        };

        $settings = $payloadRequest->settings;
        $columns = $settings['columns'] ?? [];
        $sort = $settings['sort'] ?? ['sortKey' => 'pts', 'sortDirection' => 'desc'];
        $identityCols = $this->identityColumns(false);
        $buildMark('setup_ms');

        $base = NhlGameSummary::query()
            ->join('nhl_games as g', 'g.nhl_game_id', '=', 'nhl_game_summaries.nhl_game_id');

        if ($payloadRequest->from) {
            $base->whereDate('g.game_date', '>=', $payloadRequest->from->toDateString());
        }
        if ($payloadRequest->to) {
            $base->whereDate('g.game_date', '<=', $payloadRequest->to->toDateString());
        }
        if (in_array((string) $payloadRequest->gameType, ['1', '2', '3'], true)) {
            $base->where('g.game_type', (int) $payloadRequest->gameType);
        }
        $buildMark('base_query_ms');

        [$schema, $applied] = $this->buildSchemaAndApplyFilters(
            $base,
            $columns,
            $payloadRequest->request,
            $settings['filters'] ?? [],
            $payloadRequest->filterSet,
            [
                'period' => 'range',
                'slice' => $payloadRequest->slice,
                'game_type' => $payloadRequest->gameType,
                'date_from' => $payloadRequest->from?->toDateString(),
                'date_to' => $payloadRequest->to?->toDateString(),
                'columns' => $this->columnKeys($columns),
            ],
        );
        $buildMark('schema_filters_ms');

        $results = $this->aggregateRangeStatsByPlayer($base);
        $buildMark('stats_query_ms');

        $rangeFilters = [
            'game_type' => $payloadRequest->gameType,
            'date_from' => $payloadRequest->from?->toDateString(),
            'date_to' => $payloadRequest->to?->toDateString(),
        ];
        $rows = $this->assembleRowsFromCollection(
            $results,
            $columns,
            $payloadRequest->slice,
            $payloadRequest->canSlice,
            'range',
            $rangeFilters,
        );
        $buildMark('assemble_rows_ms');

        $rows = $this->appendOnIceRows($rows, $columns, $rangeFilters);
        $buildMark('append_on_ice_ms');

        [$rows, $appliedExtra, $virtualSchema] = $this->applyPostFilters($payloadRequest->request, $rows);
        $buildMark('post_filters_ms');

        $sortKey = $sort['sortKey'] ?? 'pts';
        $sortDir = strtolower($sort['sortDirection'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $sorted = $rows->sortBy([[$sortKey, $sortDir]])->values();
        $buildMark('sort_ms');

        $headings = $this->mergeHeadings($identityCols, $columns);
        $mergedSchema = $this->mergeSchema($schema ?? [], $virtualSchema ?? []);
        $buildMark('headings_schema_ms');

        $applied['filters'] = array_merge($applied['filters'] ?? [], $appliedExtra['filters'] ?? []);
        $availability = (int) ($payloadRequest->request->input('availability', 0) ?? 0);

        $formatted = [
            'headings' => $headings,
            'data' => $sorted,
            'settings' => [
                'sortable' => collect($headings)->pluck('key')->values()->all(),
                'defaultSort' => $sortKey,
                'defaultSortDirection' => $sortDir,
                'resource' => 'players',
                'slice' => $payloadRequest->canSlice ? $payloadRequest->slice : 'total',
                'columnGroups' => $settings['columnGroups'] ?? null,
                'columnGroupSort' => $settings['columnGroupSort'] ?? null,
                'activeColumnGroup' => $settings['activeColumnGroup'] ?? null,
                'fantasyScoring' => $settings['fantasyScoring'] ?? null,
            ],
            'meta' => [
                'availableSeasons' => [],
                'availableGameTypes' => [1, 2, 3],
                'season' => null,
                'game_type' => (int) ($payloadRequest->gameType ?? 2),
                'canSlice' => $payloadRequest->canSlice,
                'filterSchema' => $mergedSchema,
                'appliedFilters' => $applied['filters'] ?? [],
                'pos' => $applied['pos'] ?? [],
                'pos_type' => $applied['pos_type'] ?? [],
                'availability' => $availability,
                'league_id' => $availability > 0 ? $availability : null,
                'positionButtons' => $this->positionButtons($settings),
                'supportsDateRange' => true,
                'fantasyScoring' => $settings['fantasyScoring'] ?? null,
            ],
        ];
        $buildMark('format_ms');

        return [$formatted, [], null, $buildTimings];
    }

    /**
     * Collapse matching game-summary rows before hydrating player metadata.
     *
     * @return Collection<int,object>
     */
    private function aggregateRangeStatsByPlayer($base): Collection
    {
        $aggregate = clone $base;
        $selects = [
            'MIN(nhl_game_summaries.id) as id',
            'nhl_game_summaries.nhl_player_id',
            'MAX(nhl_game_summaries.nhl_team_id) as nhl_team_id',
            'COUNT(DISTINCT nhl_game_summaries.nhl_game_id) as gp',
            'SUM(COALESCE(nhl_game_summaries.toi, 0)) as toi_seconds',
        ];

        foreach ($this->rangeAggregateColumns() as $column) {
            if ($column === 'toi') {
                $selects[] = 'SUM(COALESCE(nhl_game_summaries.toi, 0)) as toi';
                continue;
            }

            $selects[] = sprintf(
                'SUM(COALESCE(nhl_game_summaries.%1$s, 0)) as %1$s',
                $column,
            );
        }

        $rows = $aggregate
            ->selectRaw(implode(', ', $selects))
            ->groupBy('nhl_game_summaries.nhl_player_id')
            ->get();

        if ($rows->isEmpty()) {
            return $rows;
        }

        $players = Player::query()
            ->with(['contracts.seasons'])
            ->whereIn('nhl_id', $rows->pluck('nhl_player_id')->filter()->values()->all())
            ->get()
            ->keyBy('nhl_id');

        return $rows
            ->map(function (object $row) use ($players): object {
                $row->player = $players->get((int) $row->nhl_player_id);

                return $row;
            })
            ->filter(static fn (object $row): bool => $row->player !== null)
            ->values();
    }

    /**
     * @return array<int,string>
     */
    private function rangeAggregateColumns(): array
    {
        $excludedColumns = [
            'id',
            'nhl_game_id',
            'nhl_player_id',
            'nhl_team_id',
            'created_at',
            'updated_at',
            'goalie_decision',
            'goalie_started',
            'quality_start',
            'really_bad_start',
        ];

        return collect(Schema::getColumnListing('nhl_game_summaries'))
            ->reject(static fn (string $column): bool => in_array($column, $excludedColumns, true))
            ->values()
            ->all();
    }

    /**
     * @param array<int,array<string,mixed>> $columns
     * @param array<string,mixed> $perspectiveFilters
     * @return array{0:array<int,array<string,mixed>>,1:array<string,mixed>}
     */
    public function buildSchemaAndApplyFilters(
        $base,
        array $columns,
        ?Request $request,
        array $perspectiveFilters = [],
        ?StatsFilterSet $filterSet = null,
        array $schemaCacheContext = [],
        bool $freshSchema = false,
    ): array {
        $filterSet ??= StatsFilterSet::fromRequest($request);
        $table = $this->queryFilterApplier->prepareBase($base, $request, $perspectiveFilters);
        $schema = $this->schemaProvider->schemaForBase($base, $columns, $schemaCacheContext, $freshSchema);
        $applied = $this->queryFilterApplier->applyRequestFilters(
            $base,
            $table,
            $filterSet,
            $request,
            $this->schemaProvider->physicalNumericKeys($schema),
        );

        return [$schema, $applied];
    }

    /**
     * @param array<int,array<string,mixed>> $columns
     * @return array<string,mixed>
     */
    private function schemaCacheContext(
        SeasonStatsPayloadRequest $payloadRequest,
        string $period,
        ?string $season,
        int $gameType,
        array $columns,
    ): array {
        return [
            'perspective' => (string) ($payloadRequest->perspective->slug ?? $payloadRequest->perspective->name ?? ''),
            'season' => $season,
            'game_type' => $gameType,
            'period' => $period,
            'slice' => $payloadRequest->slice,
            'draft_context' => $payloadRequest->request?->boolean('draft_context') ?? false,
            'entry_draft_year' => $payloadRequest->request?->input('entry_draft_year'),
            'availability' => $payloadRequest->request?->input('availability', 0),
            'columns' => $this->columnKeys($columns),
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $columns
     * @return array<int,string>
     */
    private function columnKeys(array $columns): array
    {
        return collect($columns)
            ->pluck('key')
            ->map(static fn (mixed $key): string => (string) $key)
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param Collection<int,object> $collection
     * @param array<int,array<string,mixed>> $columns
     * @param array<string,mixed> $filters
     * @return Collection<int,array<string,mixed>>
     */
    public function assembleRowsFromCollection(
        Collection $collection,
        array $columns,
        string $slice,
        bool $canSlice,
        string $mode,
        array $filters = [],
    ): Collection {
        return $this->payloadAssembler->assembleRowsFromCollection(
            $collection,
            $columns,
            $slice,
            $canSlice,
            $mode,
            $filters,
        );
    }

    /**
     * @param Collection<int,array<string,mixed>> $rows
     * @param array<int,array<string,mixed>> $columns
     * @param array<string,mixed> $filters
     * @return Collection<int,array<string,mixed>>
     */
    public function appendOnIceRows(Collection $rows, array $columns, array $filters): Collection
    {
        return $this->payloadAssembler->appendOnIceRows($rows, $columns, $filters);
    }

    /**
     * @param Collection<int,array<string,mixed>> $rows
     * @return array{0:Collection<int,array<string,mixed>>,1:array<string,mixed>,2:array<int,array<string,mixed>>}
     */
    public function applyPostFilters(?Request $request, Collection $rows): array
    {
        return $this->derivedFilterApplier->apply($request, $rows);
    }

    /**
     * @return array<int,array{key:string,label:string}>
     */
    private function identityColumns(bool $includeLeague): array
    {
        $columns = [
            ['key' => 'name', 'label' => 'Player'],
            ['key' => 'age', 'label' => 'Age'],
            ['key' => 'team', 'label' => 'Team'],
            ['key' => 'pos_type', 'label' => 'Type'],
            ['key' => 'contract_value_num', 'label' => 'Cap'],
            ['key' => 'contract_last_year', 'label' => 'Term End'],
            ['key' => 'gp', 'label' => 'GP'],
        ];

        if ($includeLeague) {
            array_splice($columns, 3, 0, [[
                'key' => 'league',
                'label' => 'League',
            ]]);
        }

        return $columns;
    }

    private function isProspectsPerspective(object $perspective): bool
    {
        $slug = strtolower((string) ($perspective->slug ?? ''));

        return in_array($slug, ['prospects', 'prospects-goalies'], true);
    }

    /**
     * @param array<string,mixed> $settings
     * @return array<int,string>
     */
    private function positionButtons(array $settings): array
    {
        $buttons = $settings['ui']['positionButtons'] ?? null;

        if (! is_array($buttons)) {
            return ['LW', 'C', 'RW', 'F', 'D', 'G'];
        }

        return collect($buttons)
            ->map(fn (mixed $button): string => strtoupper(trim((string) $button)))
            ->filter(fn (string $button): bool => in_array($button, ['F', 'C', 'LW', 'RW', 'D', 'G'], true))
            ->values()
            ->all();
    }

    /**
     * @param array<int,array<string,mixed>> $identity
     * @param array<int,array<string,mixed>> $columns
     * @return array<int,array{key:string,label:string}>
     */
    private function mergeHeadings(array $identity, array $columns): array
    {
        $seen = [];
        $headings = [];

        foreach (array_merge($identity, $columns) as $column) {
            $key = $column['key'] ?? null;
            if (! $key || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $headings[] = ['key' => $key, 'label' => $column['label'] ?? strtoupper((string) $key)];
        }

        return $headings;
    }

    /**
     * @param array<int,array<string,mixed>> $schema
     * @param array<int,array<string,mixed>> $virtualSchema
     * @return array<int,array<string,mixed>>
     */
    private function mergeSchema(array $schema, array $virtualSchema): array
    {
        $seen = [];
        $merged = [];

        foreach (array_merge($schema, $virtualSchema) as $definition) {
            $key = $definition['key'] ?? null;
            if (! $key || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $merged[] = $definition;
        }

        return $merged;
    }
}
