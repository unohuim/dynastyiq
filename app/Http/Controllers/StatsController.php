<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Perspective;
use App\Models\Stat;              // Prospects (non-NHL, season totals)
use App\Models\NhlSeasonStat;     // NHL season view (supports game_type)
use App\Models\NhlGameSummary;    // Date ranges & partial periods
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class StatsController extends BaseController
{
    /**
     * Show the page with initial payload (players / season / total).
     */
    public function index(): View
    {
        $user = Auth::user();

        // Visible perspectives (id, slug, name, is_slicable)
        $perspModels = Perspective::forUser($user)->orderBy('id')->get();

        $perspectives = $perspModels->map(fn ($p) => [
            'id'          => $p->id,
            'slug'        => $p->slug ?? $p->name, // legacy fallback if any row lacks slug
            'name'        => $p->name,
            'is_slicable' => (bool)($p->is_slicable ?? true),
        ])->values();

        // Defaults based on first visible perspective
        $first = $perspModels->first();
        $selectedPerspectiveId = $first?->id;
        $selectedSlug          = $first?->slug ?? $first?->name ?? null;

        if ($selectedPerspectiveId) {
            // Initial payload: slice 'total', game_type Regular (2)
            [$payload] = $this->buildAndFormatPlayersPayload(
                $user,
                $selectedPerspectiveId,
                null,        // season (choose latest if not locked)
                'total',     // slice
                2,           // game_type: Regular Season
                null         // request (n/a on first paint)
            );
        } else {
            $payload = [
                'headings' => [],
                'data'     => collect(),
                'settings' => [
                    'sortable'             => [],
                    'defaultSort'          => null,
                    'defaultSortDirection' => 'desc',
                    'resource'             => 'players',
                    'slice'                => 'total',
                ],
                'meta' => [
                    'availableSeasons'   => [],
                    'availableGameTypes' => [2],
                    'season'             => null,
                    'game_type'          => 2,
                    'canSlice'           => true,
                    // drawer meta (empty on first paint)
                    'filterSchema'       => [],
                    'appliedFilters'     => [],
                    'pos'                => [],
                    'pos_type'           => [],
                ],
            ];
        }

        return view('stats-view', [
            'payload'       => $payload,
            'perspectives'  => $perspectives,   // [{id,slug,name,is_slicable}]
            'selectedPerspectiveId' => $selectedPerspectiveId,
            'selectedSlug'  => $selectedSlug,
            'defaultSeason' => $payload['meta']['season'] ?? null,
        ]);
    }

    /**
     * Return JSON payload for AJAX/Alpine calls.
     *
     * Accepts (players focused):
     * - perspectiveId: int   (optional; slug is preferred)
     * - perspective: slug (or legacy name)
     * - season / season_id: string
     * - slice: total|pgp|p60   (applied only when is_slicable=true)
     * - game_type: 1|2|3       (ignored for Prospects)
     * - period: season|range|lastWeek|thisWeek|past30days
     * - from,to (YYYY-MM-DD) for period=range
     * - pos[]=L|C|R|D|G, pos_type[]=F|D|G
     * - dynamic numeric filters: e.g., g_min/g_max, pts_min/pts_max, etc.
     */
    public function payload(Request $request)
    {
        $request->validate([
            'perspectiveId' => 'nullable|integer|exists:perspectives,id',
            'perspective'   => 'nullable|string',
            'season'        => 'nullable|string',
            'season_id'     => 'nullable|string',
            'slice'         => 'nullable|in:total,pgp,p60',
            'resource'      => 'nullable|in:players,units',
            'period'        => 'nullable|in:season,range,lastWeek,thisWeek,past30days',
            'game_type'     => 'nullable|in:1,2,3',
            'from'          => 'nullable|date',
            'to'            => 'nullable|date',
        ]);

        $user = $request->user();

        // Resolve perspective under visibility scope (prefer slug)
        if ($request->filled('perspectiveId')) {
            $perspective = Perspective::forUser($user)
                ->whereKey($request->integer('perspectiveId'))
                ->firstOrFail();
        } else {
            $slug = (string) $request->query('perspective', '');
            $perspective = Perspective::forUser($user)
                ->where('slug', $slug)
                ->orWhere('name', $slug) // legacy fallback
                ->firstOrFail();
        }

        $season       = $request->input('season_id', $request->input('season'));
        $sliceParam   = $request->input('slice', 'total');
        $gameType     = (int) $request->input('game_type', 2);
        $period       = (string) $request->input('period', 'season');
        $canSlice     = (bool)($perspective->is_slicable ?? true);
        $effectiveSlice = $canSlice ? $sliceParam : 'total';

        if ($period === 'season') {
            [$payload] = $this->buildAndFormatPlayersPayload(
                $user,
                $perspective->id,
                $season,
                $effectiveSlice,
                $gameType,
                $request
            );

            return response()->json($payload);
        }

        // Range-like (range, lastWeek, thisWeek, past30days)
        [$fromDate, $toDate] = $this->resolveDates($period, $request->query('from'), $request->query('to'));

        [$payload] = $this->buildAndFormatPlayersPayloadRange(
            $user,
            $perspective->id,
            $effectiveSlice,
            $gameType,
            $fromDate,
            $toDate,
            $request
        );

        return response()->json($payload);
    }

    /**
     * Build + format the PLAYERS payload (SEASON mode).
     *
     * @return array{0: array<string,mixed>,1: array<int,string>,2: string|null}
     */
    private function buildAndFormatPlayersPayload(
        $user,
        int $perspectiveId,
        ?string $seasonFilter,
        string $slice = 'total',
        ?int $gameType = 2,
        ?Request $request = null
        ): array {
            $perspective = Perspective::findOrFail($perspectiveId);
            $settings    = is_array($perspective->settings) ? $perspective->settings : (json_decode($perspective->settings ?? '[]', true) ?: []);
            $canSlice    = (bool)($perspective->is_slicable ?? true);

            $filters = $settings['filters'] ?? [];
            $columns = $settings['columns'] ?? [];
            $sort    = $settings['sort']    ?? ['sortKey' => 'pts', 'sortDirection' => 'desc'];

            $isProspects   = $this->isProspectsPerspective($perspective, $filters);
            $lockedSeason  = $filters['season_id']['value'] ?? null;
            $season        = $lockedSeason ?: $seasonFilter;

            $identityCols = [
                ['key' => 'name',               'label' => 'Player'],
                ['key' => 'age',                'label' => 'Age'],
                ['key' => 'team',               'label' => 'Team'],
                ['key' => 'pos_type',           'label' => 'Type'],
                ['key' => 'contract_value_num', 'label' => 'AAV'],
                ['key' => 'contract_last_year', 'label' => 'Term End'],
                ['key' => 'gp',                 'label' => 'GP'],
            ];

            $rows = collect();
            $availableSeasons   = [];
            $availableGameTypes = $isProspects ? [2] : [1, 2, 3];
            $effectiveGameType  = 2;

            if ($isProspects) {
                $base = Stat::query()
                    ->with(['player.contracts.seasons'])
                    ->regularSeason()
                    ->where('league_abbrev', '!=', 'NHL');

                if ($season) $base->where('season_id', $season);

                [$schema, $applied] = $this->buildSchemaAndApplyFilters($base, $columns, $request);

                $stats = $base->get();
                $availableSeasons = $stats->pluck('season_id')->unique()->sortDesc()->values()->all();
                if (!$season) $season = $availableSeasons[0] ?? null;

                $rows = $this->assembleRowsFromCollection($stats, $columns, $slice, $canSlice, 'prospects');
                $effectiveGameType = 2;
            } else {
                if (!$season) $season = (string) NhlSeasonStat::query()->max('season_id');

                $effectiveGameType = (int)($gameType ?? 2);
                if (isset($filters['game_type']['value'])) $effectiveGameType = (int)$filters['game_type']['value'];

                $base = NhlSeasonStat::query()
                    ->with(['player.contracts.seasons'])
                    ->where('season_id', $season)
                    ->where('game_type', $effectiveGameType);

                $base->select($base->getModel()->getTable() . '.*');

                [$schema, $applied] = $this->buildSchemaAndApplyFilters($base, $columns, $request);

                $stats = $base->get();

                $availableSeasons = NhlSeasonStat::query()
                    ->select('season_id')->distinct()->pluck('season_id')->sortDesc()->values()->all();

                $rows = $this->assembleRowsFromCollection($stats, $columns, $slice, $canSlice, 'season');
            }

            // Apply post-assembly filters (gp, contract AAV, contract last year) + collect virtual schema.
            [$rows, $appliedExtra, $virtualSchema] = $this->applyPostFilters($request, $rows);

            // Sort
            $sortKey = $sort['sortKey'] ?? 'pts';
            $sortDir = strtolower($sort['sortDirection'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
            $sorted  = $rows->sortBy([[$sortKey, $sortDir]])->values();

            // Headings
            $headings = $this->mergeHeadings($identityCols, $columns);

            // Merge schema (avoid duplicates by key)
            $seen = [];
            $mergedSchema = [];
            foreach (array_merge($schema ?? [], $virtualSchema ?? []) as $def) {
                $k = $def['key'] ?? null;
                if (!$k || isset($seen[$k])) continue;
                $seen[$k] = true;
                $mergedSchema[] = $def;
            }

            // Merge applied filters echo
            $applied['filters'] = array_merge($applied['filters'] ?? [], $appliedExtra['filters'] ?? []);

            $formatted = [
                'headings' => $headings,
                'data'     => $sorted,
                'settings' => [
                    'sortable'             => collect($headings)->pluck('key')->values()->all(),
                    'defaultSort'          => $sortKey,
                    'defaultSortDirection' => $sortDir,
                    'resource'             => 'players',
                    'slice'                => $canSlice ? $slice : 'total',
                ],
                'meta' => [
                    'availableSeasons'   => $availableSeasons,
                    'availableGameTypes' => $availableGameTypes,
                    'season'             => $season,
                    'game_type'          => $effectiveGameType,
                    'canSlice'           => $canSlice,
                    'filterSchema'       => $mergedSchema,
                    'appliedFilters'     => $applied['filters'] ?? [],
                    'pos'                => $applied['pos'] ?? [],
                    'pos_type'           => $applied['pos_type'] ?? [],
                ],
            ];

        return [$formatted, $availableSeasons, $season];
    }





    /**
     * Build + format the PLAYERS payload (RANGE / partial periods).
     */
    private function buildAndFormatPlayersPayloadRange(
        $user,
        int $perspectiveId,
        string $slice,
        ?int $gameType,
        ?Carbon $from,
        ?Carbon $to,
        Request $request
    ): array {
        $perspective = Perspective::findOrFail($perspectiveId);
        $settings    = is_array($perspective->settings) ? $perspective->settings : (json_decode($perspective->settings ?? '[]', true) ?: []);
        $canSlice    = (bool)($perspective->is_slicable ?? true);

        $columns = $settings['columns'] ?? [];
        $sort    = $settings['sort']    ?? ['sortKey' => 'pts', 'sortDirection' => 'desc'];

        $identityCols = [
            ['key' => 'name',               'label' => 'Player'],
            ['key' => 'age',                'label' => 'Age'],
            ['key' => 'team',               'label' => 'Team'],
            ['key' => 'pos_type',           'label' => 'Type'],
            ['key' => 'contract_value_num', 'label' => 'AAV'],
            ['key' => 'contract_last_year', 'label' => 'Term End'],
            ['key' => 'gp',                 'label' => 'GP'],
        ];

        // CHANGE: plain join + WHEREs (portable across MySQL/PG)
        $base = NhlGameSummary::query()
            ->with(['player.contracts.seasons'])
            ->join('nhl_games as g', 'g.nhl_game_id', '=', 'nhl_game_summaries.nhl_game_id')
            ->select('nhl_game_summaries.*');

        if ($from) {
            $base->whereDate('g.game_date', '>=', $from->toDateString());
        }
        if ($to) {
            $base->whereDate('g.game_date', '<=', $to->toDateString());
        }
        if (in_array((string)$gameType, ['1','2','3'], true)) {
            $base->where('g.game_type', (int)$gameType);
        }

        $base->select('nhl_game_summaries.*');

        [$schema, $applied] = $this->buildSchemaAndApplyFilters($base, $columns, $request);

        $results = $base->get();
        $rows    = $this->assembleRowsFromCollection($results, $columns, $slice, $canSlice, 'range');

        // Apply post-assembly filters (gp, contract AAV, last year) + collect virtual schema.
        [$rows, $appliedExtra, $virtualSchema] = $this->applyPostFilters($request, $rows);

        // Sort
        $sortKey = $sort['sortKey'] ?? 'pts';
        $sortDir = strtolower($sort['sortDirection'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $sorted  = $rows->sortBy([[$sortKey, $sortDir]])->values();

        // Headings
        $headings = $this->mergeHeadings($identityCols, $columns);

        // Merge schema with virtual fields (dedupe by key)
        $seen = [];
        $mergedSchema = [];
        foreach (array_merge($schema ?? [], $virtualSchema ?? []) as $def) {
            $k = $def['key'] ?? null;
            if (!$k || isset($seen[$k])) continue;
            $seen[$k] = true;
            $mergedSchema[] = $def;
        }

        // Merge applied filters echo
        $applied['filters'] = array_merge($applied['filters'] ?? [], $appliedExtra['filters'] ?? []);

        $formatted = [
            'headings' => $headings,
            'data'     => $sorted,
            'settings' => [
                'sortable'             => collect($headings)->pluck('key')->values()->all(),
                'defaultSort'          => $sortKey,
                'defaultSortDirection' => $sortDir,
                'resource'             => 'players',
                'slice'                => $canSlice ? $slice : 'total',
            ],
            'meta' => [
                'availableSeasons'   => [],
                'availableGameTypes' => [1,2,3],
                'season'             => null,
                'game_type'          => (int) ($gameType ?? 2),
                'canSlice'           => $canSlice,
                'filterSchema'       => $mergedSchema,
                'appliedFilters'     => $applied['filters'] ?? [],
                'pos'                => $applied['pos'] ?? [],
                'pos_type'           => $applied['pos_type'] ?? [],
            ],
        ];

        return [$formatted, [], null];
    }





    /**
     * Assemble rows grouped by player, summing totals.
     */
    private function assembleRowsFromCollection(
        Collection $collection,
        array $columns,
        string $slice,
        bool $canSlice,
        string $mode
    ): Collection {
        $rows = collect();

        $grouped = $collection->groupBy(fn ($row) => $row->player_id ?? $row->nhl_player_id);

        foreach ($grouped as $playerStats) {
            $entry  = $playerStats->count() === 1 ? $playerStats->first() : $playerStats->sortByDesc('gp')->first();
            $player = $entry->player;
            $isSeason = ($mode === 'season');

            $contract        = $player?->contracts()->exists() ? $player->contracts()->first() : null;
            $contractSeason  = $contract?->seasons->last();
            $contractLastLbl = $contractSeason?->label ?? '';
            $contractAavRaw  = is_numeric($contractSeason?->aav) ? (float) $contractSeason->aav : 0.0;
            $contractAavM    = $contractAavRaw > 0 ? $contractAavRaw / 1_000_000 : 0.0;
            $contractAav     = $contractAavRaw > 0 ? '$' . number_format($contractAavM, 3) . 'm' : '$0.000m';
            $lastYearNum     = $this->parseContractLastYear($contractLastLbl);

            // GP and TOI (normalize to seconds -> minutes)
            if ($isSeason) {
                $gpSum = (int) ($entry->gp ?? 0);

                $toiSec = 0.0;
                if (isset($entry->toi_minutes) && is_numeric($entry->toi_minutes)) {
                    // On nhl_season_stats this is SECONDS
                    $toiSec = (float) $entry->toi_minutes;
                } elseif (isset($entry->toi) && is_numeric($entry->toi)) {
                    $v = (float) $entry->toi;
                    $toiSec = $v > 4000 ? $v : $v * 60.0;
                }
            } else {
                $gpSum = ($mode === 'range')
                    ? $playerStats->pluck('nhl_game_id')->unique()->count()
                    : (int) $playerStats->sum('gp');

                $toiSec = 0.0;
                if ($playerStats->sum('toi_seconds') > 0) {
                    $toiSec = (float) $playerStats->sum('toi_seconds');
                } elseif ($playerStats->sum('toi_minutes') > 0) {
                    $v = (float) $playerStats->sum('toi_minutes');
                    $toiSec = $v > 4000 ? $v : $v * 60.0;
                } elseif ($playerStats->sum('toi') > 0) {
                    $v = (float) $playerStats->sum('toi');
                    $toiSec = $v > 4000 ? $v : $v * 60.0;
                }
            }
            $toiMin = $toiSec / 60.0;

            $row = [
                'name'                   => $player?->full_name ?? trim(($player->first_name ?? '') . ' ' . ($player->last_name ?? '')),
                'age'                    => $this->playerAge($player),
                'team'                   => $player?->team_abbrev ?? $entry->team_abbrev ?? ($entry->nhl_team_abbrev ?? null),
                'pos_type'               => $player?->pos_type,
                'contract_value'         => $contractAav,
                'contract_value_num'     => round($contractAavM, 3),
                'contract_last_year'     => $contractLastLbl,
                'contract_last_year_num' => $lastYearNum,
                'gp'                     => max(0, $gpSum),
            ];

            foreach ($columns as $col) {
                $key = $col['key'] ?? null;
                if (!$key || $key === 'gp') {
                    continue;
                }

                if ($isSeason) {
                    // Use mapped season rate fields when slicing
                    $val = $this->getStatValue($entry, $key, $canSlice ? $slice : 'total');
                    $row[$key] = is_numeric($val) ? (float) $val : 0.0;
                } else {
                    $total = (float) $playerStats->sum($key);
                    if ($canSlice && $slice !== 'total') {
                        if ($slice === 'pgp') {
                            $row[$key] = $gpSum > 0 ? round($total / $gpSum, 2) : 0.0;
                        } elseif ($slice === 'p60') {
                            $row[$key] = $toiMin > 0 ? round($total / ($toiMin / 60.0), 2) : 0.0;
                        }
                    } else {
                        $row[$key] = fmod($total, 1.0) === 0.0 ? (int) $total : $total;
                    }
                }
            }

            $rows->push($row);
        }

        return $rows;
    }





    private function applyPostFilters(?Request $request, Collection $rows): array
    {
        // Compute bounds from the assembled rows (for virtual fields / range mode).
        $bounds = [
            'gp' => [
                'min' => (int) ($rows->min('gp') ?? 0),
                'max' => (int) ($rows->max('gp') ?? 0),
            ],
            'contract_value_num' => [
                'min' => (float) ($rows->min('contract_value_num') ?? 0.0),
                'max' => (float) ($rows->max('contract_value_num') ?? 0.0),
            ],
            'contract_last_year_num' => [
                'min' => (int) ($rows->min('contract_last_year_num') ?? 0),
                'max' => (int) ($rows->max('contract_last_year_num') ?? 0),
            ],
        ];

        // Build schema entries for the UI (only if bounds look sane).
        $virtualSchema = [];
        if ($bounds['gp']['max'] > 0) {
            $virtualSchema[] = ['key' => 'gp', 'label' => 'GP', 'type' => 'number', 'bounds' => $bounds['gp'], 'step' => 1];
        }
        if ($bounds['contract_value_num']['max'] > 0) {
            $virtualSchema[] = ['key' => 'contract_value_num', 'label' => 'AAV', 'type' => 'number', 'bounds' => [
                'min' => (float) floor($bounds['contract_value_num']['min']),
                'max' => (float) ceil($bounds['contract_value_num']['max']),
            ], 'step' => 0.1];
        }
        if ($bounds['contract_last_year_num']['max'] > 0) {
            $virtualSchema[] = ['key' => 'contract_last_year_num', 'label' => 'Term End', 'type' => 'number', 'bounds' => $bounds['contract_last_year_num'], 'step' => 1];
        }

        if (!$request) {
            return [$rows, ['filters' => []], $virtualSchema];
        }

        // Apply filters coming from query string to the in-memory rows.
        $gpMin  = $request->query('gp_min');  $gpMax  = $request->query('gp_max');
        $cvMin  = $request->query('contract_value_num_min');  $cvMax  = $request->query('contract_value_num_max');
        $lyMin  = $request->query('contract_last_year_num_min');  $lyMax  = $request->query('contract_last_year_num_max');

        $filtered = $rows->filter(function ($r) use ($gpMin, $gpMax, $cvMin, $cvMax, $lyMin, $lyMax) {
            if ($gpMin !== null && $r['gp'] < (int)$gpMin) return false;
            if ($gpMax !== null && $r['gp'] > (int)$gpMax) return false;

            if ($cvMin !== null && (float)$r['contract_value_num'] < (float)$cvMin) return false;
            if ($cvMax !== null && (float)$r['contract_value_num'] > (float)$cvMax) return false;

            if ($lyMin !== null && (int)$r['contract_last_year_num'] < (int)$lyMin) return false;
            if ($lyMax !== null && (int)$r['contract_last_year_num'] > (int)$lyMax) return false;

            return true;
        })->values();

        $applied = ['filters' => []];
        if ($gpMin !== null || $gpMax !== null) {
            $applied['filters']['gp'] = ['min' => $gpMin !== null ? (int)$gpMin : null, 'max' => $gpMax !== null ? (int)$gpMax : null];
        }
        if ($cvMin !== null || $cvMax !== null) {
            $applied['filters']['contract_value_num'] = ['min' => $cvMin !== null ? (float)$cvMin : null, 'max' => $cvMax !== null ? (float)$cvMax : null];
        }
        if ($lyMin !== null || $lyMax !== null) {
            $applied['filters']['contract_last_year_num'] = ['min' => $lyMin !== null ? (int)$lyMin : null, 'max' => $lyMax !== null ? (int)$lyMax : null];
        }

        return [$filtered, $applied, $virtualSchema];
    }



    private function parseContractLastYear(?string $label): ?int
    {
        if (!$label) return null;

        // Examples: "2027-28", "2028", "2024-25 (UFA)"
        $label = trim($label);

        // Full year first
        if (preg_match('/\b(20\d{2})\b/', $label, $m)) {
            $year = (int) $m[1];

            // If it looks like "2027-28", snap to the second year (2028).
            if (preg_match('/20(\d{2})\D+(\d{2})\b/', $label, $mm)) {
                $first = (int) ('20' . $mm[1]);
                $end2  = (int) $mm[2];
                $second = $first >= 2000 ? ($first - 2000) : 0;
                $second = $second <= 99 ? (int) ('20' . str_pad((string)$end2, 2, '0', STR_PAD_LEFT)) : $year;
                return $second;
            }

            return $year;
        }

        return null;
    }





    private function resolveDates(string $period, $from, $to): array
    {
        $today = Carbon::today();

        return match ($period) {
            'lastWeek'   => [$today->copy()->subWeek()->startOfWeek(), $today->copy()->subWeek()->endOfWeek()],
            'thisWeek'   => [$today->copy()->startOfWeek(), $today->copy()->endOfWeek()],
            'past30days' => [$today->copy()->subDays(30), $today],
            'range'      => [$from ? Carbon::parse($from) : null, $to ? Carbon::parse($to) : null],
            default      => [null, null],
        };
    }

    private function isProspectsPerspective(Perspective $p, array $filters): bool
    {
        $name = Str::lower($p->name ?? '');
        $slug = Str::lower($p->slug ?? '');
        if (Str::contains($name, 'prospect') || Str::contains($slug, 'prospect')) {
            return true;
        }

        $leagueFilter = $filters['league_abbrev'] ?? null;
        if ($leagueFilter) {
            $op  = $leagueFilter['operator'] ?? '=';
            $val = $leagueFilter['value'] ?? null;
            return ($op === '!=' && $val === 'NHL') || ($val && strtoupper($val) !== 'NHL');
        }

        return false;
    }

    /**
     * Build filter schema from perspective columns + identity fields,
     * then apply filters from query string onto $base.
     *
     * @return array{0: array<int,mixed>, 1: array{filters:array,pos:array,pos_type:array}}
     */
    private function buildSchemaAndApplyFilters($base, array $columns, ?Request $request): array
    {
        $table = $base->getModel()->getTable();

        $joinedPlayers = false;
        $needPlayerJoin = $table !== 'stats';

        if ($needPlayerJoin) {
            $base->leftJoin('players as pf', 'pf.nhl_id', '=', $table . '.nhl_player_id');
            $joinedPlayers = true;
        } else {
            $base->leftJoin('players as pf', 'pf.id', '=', $table . '.player_id');
            $joinedPlayers = true;
        }

        $schema = [
            ['key' => 'age',  'label' => 'Age',  'type' => 'int',  'bounds' => $this->ageBoundsForBase($base)],
            ['key' => 'team', 'label' => 'Team', 'type' => 'enum', 'options' => $this->teamOptionsForBase($base)],
            ['key' => 'pos',  'label' => 'Position', 'type' => 'enum', 'options' => $this->positionOptionsForBase($base)],
        ];

        foreach ($columns as $col) {
            $key = $col['key'] ?? null;
            if (!$key || in_array($key, ['name','age','team','contract_value','gp'], true)) continue;

            $bounds = $this->bounds($base, $key);
            if ($bounds) {
                $schema[] = [
                    'key'    => $key,
                    'label'  => $col['label'] ?? \Illuminate\Support\Str::title(str_replace('_',' ', $key)),
                    'type'   => 'number',
                    'bounds' => $bounds,
                    'step'   => 1,
                ];
            }
        }

        // If the active table has a physical GP column, offer GP filter too.
        if (\Illuminate\Support\Facades\Schema::hasColumn($table, 'gp')) {
            $has = collect($schema)->firstWhere('key', 'gp');
            if (!$has) {
                $schema[] = [
                    'key'    => 'gp',
                    'label'  => 'GP',
                    'type'   => 'number',
                    'bounds' => $this->bounds($base, 'gp'),
                    'step'   => 1,
                ];
            }
        }

        $applied = [
            'filters'  => [],
            'pos'      => array_values(array_filter((array)($request?->query('pos', []) ?? []), 'strlen')),
            'pos_type' => array_values(array_filter((array)($request?->query('pos_type', []) ?? []), 'strlen')),
        ];

        // pos_type / pos filters (on pf.*)
        if (!empty($applied['pos_type'])) {
            $base->whereIn('pf.pos_type', $applied['pos_type']);
            if (in_array('G', array_map('strtoupper', $applied['pos_type']), true)) {
                $base->where('pf.position', 'G');
            }
        }

        $pos = $applied['pos'];
        if (!empty($pos)) {
            $posU = array_map('strtoupper', $pos);
            if (array_diff($posU, ['G'])) $posU = array_values(array_diff($posU, ['G']));
            $base->whereIn('pf.position', $posU);
        }

        // Teams
        $teams = array_values(array_filter((array)($request?->query('team', []) ?? []), 'strlen'));
        if (!empty($teams)) {
            if ($table === 'stats') $base->whereIn('nhl_team_abbrev', $teams);
            else $base->whereIn('pf.team_abbrev', $teams);
            $applied['filters']['team'] = $teams;
        }

        // Age min/max
        $ageMin = $request?->query('age_min');
        $ageMax = $request?->query('age_max');

        if ($ageMin !== null || $ageMax !== null) {
            $today = \Illuminate\Support\Carbon::today();

            // Ages -> DOB range (inclusive)
            // Example: age 19–24  =>  DOB between (today-24y-1d)+1d and (today-19y)
            $youngestDob = $ageMin !== null ? $today->copy()->subYears((int)$ageMin)->toDateString() : null; // newest DOB
            $oldestDob   = $ageMax !== null ? $today->copy()->subYears((int)$ageMax + 1)->addDay()->toDateString() : null; // oldest DOB

            if ($oldestDob && $youngestDob) {
                $base->whereBetween('pf.dob', [$oldestDob, $youngestDob]);
            } elseif ($oldestDob) {
                $base->where('pf.dob', '>=', $oldestDob);
            } elseif ($youngestDob) {
                $base->where('pf.dob', '<=', $youngestDob);
            }

            $applied['filters']['age'] = [
                'min' => $ageMin !== null ? (int)$ageMin : null,
                'max' => $ageMax !== null ? (int)$ageMax : null,
            ];
        }



        // Dynamic numeric filters via *_min / *_max for physical columns.
        $all = $request?->query() ?? [];
        foreach ($all as $k => $v) {
            if (!is_scalar($v)) continue;
            if (str_ends_with($k, '_min') || str_ends_with($k, '_max')) {
                $baseKey = substr($k, 0, -4);
                $col = $this->mapFilterColumn($base, $baseKey);
                if (!$col) continue;

                $pair = $applied['filters'][$baseKey] ?? ['min' => null, 'max' => null];
                if (str_ends_with($k, '_min')) {
                    $pair['min'] = is_numeric($v) ? (float)$v : null;
                    if ($pair['min'] !== null) $base->where($col, '>=', $pair['min']);
                } else {
                    $pair['max'] = is_numeric($v) ? (float)$v : null;
                    if ($pair['max'] !== null) $base->where($col, '<=', $pair['max']);
                }
                $applied['filters'][$baseKey] = $pair;
            }
        }

        return [$schema, $applied];
    }





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
                ->filter()->values()->all();
        }

        return (clone $base)
            ->reorder()
            ->select('pf.team_abbrev')
            ->whereNotNull('pf.team_abbrev')
            ->distinct()
            ->pluck('pf.team_abbrev')
            ->filter()->values()->all();
    }

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
                ->filter()->values()->all();
        }

        return (clone $base)
            ->reorder()
            ->select('pf.position')
            ->whereNotNull('pf.position')
            ->distinct()
            ->pluck('pf.position')
            ->filter()->values()->all();
    }

    /**
     * Return min/max for a numeric column on the active base query.
     * Vendor-neutral (MySQL/Postgres) and GROUP BY safe.
     */
    private function bounds($base, string $key): array
    {
        $col = $this->mapFilterColumn($base, $key);
        if (!$col) {
            return ['min' => 0, 'max' => 0];
        }

        // Remove ORDER BY / SELECT list / JOINs so aggregates are clean & portable
        $qb = $base->cloneWithout(['orders', 'columns', 'joins']);

        // Use Builder's aggregate helpers instead of SELECT RAW
        $minVal = $qb->clone()->min($col);
        $maxVal = $qb->clone()->max($col);

        $min = (float) ($minVal ?? 0);
        $max = (float) ($maxVal ?? 0);
        if ($min > $max) { [$min, $max] = [$max, $min]; }

        return ['min' => (int) floor($min), 'max' => (int) ceil($max)];
    }




    /**
     * Compute age bounds for the active dataset without DB-specific functions.
     * Works on MySQL (incl. ONLY_FULL_GROUP_BY) and Postgres.
     */
    private function ageBoundsForBase($base): array
    {
        $table = $base->getModel()->getTable();

        // Start clean, then join players as `page` so we measure the same population
        $qb = $base->cloneWithout(['orders', 'columns', 'joins'])
            ->join('players as page', function ($j) use ($table) {
                if (in_array($table, ['nhl_season_stats', 'nhl_game_summaries'], true)) {
                    $j->on('page.nhl_id', '=', "{$table}.nhl_player_id");
                } else { // stats/prospects
                    $j->on('page.id', '=', "{$table}.player_id");
                }
            })
            ->whereNotNull('page.dob');

        // DB-agnostic aggregates (no SELECT * + aggregate mix, no vendor funcs)
        $earliestDob = $qb->clone()->min('page.dob'); // oldest player (min date)
        $latestDob   = $qb->clone()->max('page.dob'); // youngest player (max date)

        if (!$earliestDob && !$latestDob) {
            return ['min' => 16, 'max' => 45];
        }

        // Convert to ages in PHP for portability
        $minAge = $latestDob
            ? \Illuminate\Support\Carbon::parse($latestDob)->age
            : null; // youngest => minimum age

        $maxAge = $earliestDob
            ? \Illuminate\Support\Carbon::parse($earliestDob)->age
            : null; // oldest => maximum age

        // Fallbacks and ordering
        if ($minAge === null && $maxAge !== null) $minAge = $maxAge;
        if ($maxAge === null && $minAge !== null) $maxAge = $minAge;
        if ($minAge === null || $maxAge === null) return ['min' => 16, 'max' => 45];
        if ($minAge > $maxAge) [$minAge, $maxAge] = [$maxAge, $minAge];

        return ['min' => (int) $minAge, 'max' => (int) $maxAge];
    }




    private function mapFilterColumn($base, string $key): ?string
    {
        $table = $base->getModel()->getTable();

        $map = [
            'nhl_season_stats' => [
                'g_per_gp'   => 'g_pg',
                'a_per_gp'   => 'a_pg',
                'pts_per_gp' => 'pts_pg',
                'b_per_gp'   => 'b_pg',
                'h_per_gp'   => 'h_pg',
                'th_per_gp'  => 'th_pg',

                'g_per_60'      => 'g_p60',
                'a_per_60'      => 'a_p60',
                'pts_per_60'    => 'pts_p60',
                'sog_per_60'    => 'sog_p60',
                'sat_per_60'    => 'sat_p60',
                'hits_per_60'   => 'hits_p60',
                'blocks_per_60' => 'blocks_p60',
            ],
            'stats' => [],
            'nhl_game_summaries' => [], // no rate columns on that table
        ];

        $col = $map[$table][$key] ?? $key;

        return Schema::hasColumn($table, $col) ? $table . '.' . $col : null;
    }

    private function playerAge($player): ?int
    {
        if (!$player) return null;
        if (method_exists($player, 'age')) return $player->age();
        if (!empty($player->dob)) return Carbon::parse($player->dob)->age;
        return null;
    }

    /**
     * Return the correct value for a stat key based on the requested slice.
     * - For pgp/p60: round to 1 decimal (UI spec).
     * - If a mapped rate field exists (e.g., pts_pg, pts_p60), use it but still round.
     * - Otherwise compute on the fly from totals.
     * - Totals are returned raw (no rounding) so integer columns stay integers.
     */
    private function getStatValue(object $st, string $key, string $slice)
    {
        $total = fn() => (float) ($st->{$key} ?? 0);

        if ($slice === 'pgp') {
            $mapped = $this->rateMaps()['pg'][$key] ?? null;

            if ($mapped && isset($st->{$mapped}) && is_numeric($st->{$mapped})) {
                return round((float) $st->{$mapped}, 1);
            }

            $gp = (int) ($st->gp ?? 0);
            if ($gp > 0) {
                return round($total() / $gp, 1);
            }

            return 0.0;
        }

        if ($slice === 'p60') {
            $mapped = $this->rateMaps()['p60'][$key] ?? null;

            if ($mapped && isset($st->{$mapped}) && is_numeric($st->{$mapped})) {
                return round((float) $st->{$mapped}, 1);
            }

            $toiMin = null;
            if (isset($st->toi_minutes) && is_numeric($st->toi_minutes)) {
                $toiMin = (float) $st->toi_minutes;
            } elseif (isset($st->toi) && is_numeric($st->toi)) {
                $toi = (float) $st->toi;
                $toiMin = $toi > 4000 ? $toi / 60.0 : $toi;
            } else {
                $toiMin = 0.0;
            }

            if ($toiMin > 0) {
                return round($total() / ($toiMin / 60.0), 1);
            }

            return 0.0;
        }

        return $st->{$key} ?? 0;
    }

    /**
     * Compute derived from totals for prospects (Stat aggregates).
     */
    private function deriveFromTotals(string $key, float|int $total, string $slice, int $gpSum, float $toiSec)
    {
        if ($slice === 'pgp') {
            return $gpSum > 0 ? round($total / $gpSum, 3) : 0;
        }
        if ($slice === 'p60') {
            // seconds → hours: divide by 3600
            return $toiSec > 0 ? round($total / ($toiSec / 3600), 3) : 0;
            // or: round(($total * 3600) / $toiSec, 3)
        }

        return $total;
    }

    /**
     * Mapping of total keys -> rate field names on NhlSeasonStat.
     */
    private function rateMaps(): array
    {
        return [
            'pg'  => [
                'g'   => 'g_pg',
                'a'   => 'a_pg',
                'pts' => 'pts_pg',
                'pim' => 'pim_pg',
                'sog' => 'sog_pg',
                'ppp' => 'ppp_pg',
            ],
            'p60' => [
                'g'   => 'g_p60',
                'a'   => 'a_p60',
                'pts' => 'pts_p60',
                'sog' => 'sog_p60',
                'sat' => 'sat_p60',
                'h'   => 'hits_p60',
                'b'   => 'blocks_p60',
                'pim' => 'pim_p60',
                'ppp' => 'ppp_p60',
            ],
        ];
    }

    /**
     * Merge identity headings with perspective columns, dedup by key.
     * @param array<int,array{key:string,label:string}> $identity
     * @param array<int,array{key:string,label:string}> $columns
     * @return array<int,array{key:string,label:string}>
     */
    private function mergeHeadings(array $identity, array $columns): array
    {
        $seen = [];
        $out  = [];

        foreach (array_merge($identity, $columns) as $col) {
            $key = $col['key'] ?? null;
            if (!$key || isset($seen[$key])) continue;
            $seen[$key] = true;
            $out[] = ['key' => $key, 'label' => $col['label'] ?? strtoupper($key)];
        }

        return $out;
    }
}
