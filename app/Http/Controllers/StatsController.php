<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Perspective;
use App\Models\Stat;
use App\Models\NhlSeasonStat;
use App\Models\NhlGameSummary;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Illuminate\Support\Facades\DB;


class StatsController extends BaseController
{
    public function index(): View
    {
        $user = Auth::user();

        $connectedLeagues = $this->connectedLeaguesForUser($user);

        $perspModels = Perspective::forUser($user)->orderBy('id')->get();

        $perspectives = $perspModels->map(fn ($p) => [
            'id'          => $p->id,
            'slug'        => $p->slug ?? $p->name,
            'name'        => $p->name,
            'is_slicable' => (bool)($p->is_slicable ?? true),
        ])->values();

        $first = $perspModels->first();
        $selectedPerspectiveId = $first?->id;
        $selectedSlug          = $first?->slug ?? $first?->name ?? null;

        if ($selectedPerspectiveId) {
            [$payload] = $this->buildAndFormatPlayersPayload(
                $user,
                $selectedPerspectiveId,
                null,
                'total',
                2,
                null
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
                    'filterSchema'       => [],
                    'appliedFilters'     => [],
                    'pos'                => [],
                    'pos_type'           => [],
                ],
            ];
        }

        return view('stats-view', [
            'payload'               => $payload,
            'perspectives'          => $perspectives,
            'selectedPerspectiveId' => $selectedPerspectiveId,
            'selectedSlug'          => $selectedSlug,
            'defaultSeason'         => $payload['meta']['season'] ?? null,
            'connectedLeagues'      => $connectedLeagues,
        ]);
    }


    private function connectedLeaguesForUser($user): array
    {
        if (!$user) return [];

        // Adjust table/column names if yours differ
        $rows = DB::table('platform_leagues as pl')
            ->join('league_user_teams as lut', 'lut.platform_league_id', '=', 'pl.id')
            ->where('lut.user_id', $user->id)
            // Optional: uncomment if you have an "active" flag/soft-deletes on the pivot
            // ->where('lut.is_active', true)
            // ->whereNull('lut.deleted_at')
            ->select('pl.id', 'pl.name')
            ->distinct()
            ->orderBy('pl.name')
            ->get();

        return $rows->map(fn ($r) => ['id' => (int) $r->id, 'name' => (string) $r->name])
                    ->values()
                    ->all();
    }



    // helper (place once in the controller)
    private function formatTimeOnIce(int $seconds): string
    {
        $m = intdiv($seconds, 60);
        $s = $seconds % 60;
        return sprintf('%d:%02d', $m, $s);
    }



    /** ───────────────────────────── API (AJAX) ───────────────────────────── */
    public function payload(Request $request)
    {
        \Log::info('pre validate request: ', ['request' => $request]);
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
            'availability'  => 'nullable|integer',
        ]);

        \Log::info('post validate request: ', ['request' => $request]);

        $user = $request->user();

        $connectedLeagues = $this->connectedLeaguesForUser($user);

        // Resolve perspective (prefer slug)
        if ($request->filled('perspectiveId')) {
            $perspective = Perspective::forUser($user)
                ->whereKey($request->integer('perspectiveId'))
                ->firstOrFail();
        } else {
            $slug = (string) $request->query('perspective', '');
            $perspective = Perspective::forUser($user)
                ->where('slug', $slug)
                ->orWhere('name', $slug)
                ->firstOrFail();
        }

        \Log::info('api request: ', ['request' => $request]);
        $season         = $request->input('season_id', $request->input('season'));
        $sliceParam     = $request->input('slice', 'total');
        $gameType       = (int) $request->input('game_type', 2);
        $period         = (string) $request->input('period', 'season');
        $canSlice       = (bool)($perspective->is_slicable ?? true);
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

            $payload['connectedLeagues'] = $connectedLeagues;

            return response()->json($payload);
        }

        // Ranges / partial periods
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

        $payload['connectedLeagues'] = $connectedLeagues;

        \Log::info('updated payload: ', ['payload' => $payload]);

        return response()->json($payload);
    }



    /**
     * Apply "availability" from a single integer param:
     *   0 => no constraint
     *   -1 => "available in any of the user's leagues"
     *   N (>1) => "available in internal league id N (platform_leagues.id)"
     *
     * Current = platform_roster_memberships.ends_at IS NULL.
     * player match uses local players.id (pf.id).
     */
    private function applyAvailabilityToBase($base, ?Request $request, $user): void
    {
        $val = (int) ($request?->input('availability', 0) ?? 0);
        if ($val === 0) return;


        if ($val === -1) {
            // robust user id (works for web/ajax/api)
            \Log::info('verifying user');
            $uid = $user?->id ?? Auth::id();
            if (!$uid) return;

            \Log::info('user authed');

            // get internal platform_leagues.id values from the pivot
            $leagueIds = DB::table('league_user_teams')
                ->where('user_id', $uid)
                ->pluck('platform_league_id')
                ->map(fn ($i) => (int) $i)
                ->all();

            \Log::info('Availability ANY: fetched league ids', ['uid' => $uid, 'leagueIdsCount' => count($leagueIds)]);

            if (empty($leagueIds)) return;

            $base->whereExists(function ($q) use ($leagueIds) {
                $q->selectRaw('1')
                ->from('platform_leagues as l')
                ->whereIn('l.id', $leagueIds)
                ->whereNotExists(function ($q2) {
                    $q2->from('platform_roster_memberships as prm')
                        ->join('platform_teams as pt', 'pt.id', '=', 'prm.platform_team_id')
                        ->whereNull('prm.ends_at')
                        ->whereColumn('pt.platform_league_id', 'l.id')  // this league
                        ->whereColumn('prm.player_id', 'pf.id');        // this player
                });
            });

            \Log::info('Availability ANY applied');
            return;
        }

        // specific league stays the same
        $leagueId = $val;
        $base->whereNotExists(function ($q) use ($leagueId) {
            $q->from('platform_roster_memberships as prm')
            ->join('platform_teams as pt', 'pt.id', '=', 'prm.platform_team_id')
            ->whereNull('prm.ends_at')
            ->where('pt.platform_league_id', $leagueId)
            ->whereColumn('prm.player_id', 'pf.id')
            ->selectRaw('1');
        });
        \Log::info('Availability SPECIFIC applied', ['leagueId' => $leagueId]);
    }









    /** ─────────────── Build + format PLAYERS payload (SEASON) ─────────────── */
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

            // avoid SELECT * + aggregates trouble in bounds()
            $base->select($base->getModel()->getTable() . '.*');

            [$schema, $applied] = $this->buildSchemaAndApplyFilters($base, $columns, $request);

            $stats = $base->get();

            $availableSeasons = NhlSeasonStat::query()
                ->select('season_id')->distinct()->pluck('season_id')->sortDesc()->values()->all();

            $rows = $this->assembleRowsFromCollection($stats, $columns, $slice, $canSlice, 'season');
        }

        // Post filters + virtual schema
        [$rows, $appliedExtra, $virtualSchema] = $this->applyPostFilters($request, $rows);

        // Sort
        $sortKey = $sort['sortKey'] ?? 'pts';
        $sortDir = strtolower($sort['sortDirection'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $sorted  = $rows->sortBy([[$sortKey, $sortDir]])->values();

        // Headings + schema merge
        $headings = $this->mergeHeadings($identityCols, $columns);

        $seen = [];
        $mergedSchema = [];
        foreach (array_merge($schema ?? [], $virtualSchema ?? []) as $def) {
            $k = $def['key'] ?? null;
            if (!$k || isset($seen[$k])) continue;
            $seen[$k] = true;
            $mergedSchema[] = $def;
        }

        // Merge applied echo
        $applied['filters'] = array_merge($applied['filters'] ?? [], $appliedExtra['filters'] ?? []);
        $avail = (int) ($request?->input('availability', 0) ?? 0);
        $leagueIdMeta = $avail > 0 ? $avail : null;

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
                'availability'       => $avail,
                'league_id'          => $leagueIdMeta,
            ],
        ];

        return [$formatted, $availableSeasons, $season];
    }

    /** ───────────── Build + format PLAYERS payload (RANGE/PARTIAL) ───────────── */
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

        [$schema, $applied] = $this->buildSchemaAndApplyFilters($base, $columns, $request);

        $results = $base->get();
        $rows    = $this->assembleRowsFromCollection($results, $columns, $slice, $canSlice, 'range');

        // Post filters + virtual schema
        [$rows, $appliedExtra, $virtualSchema] = $this->applyPostFilters($request, $rows);

        // Sort
        $sortKey = $sort['sortKey'] ?? 'pts';
        $sortDir = strtolower($sort['sortDirection'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $sorted  = $rows->sortBy([[$sortKey, $sortDir]])->values();

        // Headings + schema merge
        $headings = $this->mergeHeadings($identityCols, $columns);

        $seen = [];
        $mergedSchema = [];
        foreach (array_merge($schema ?? [], $virtualSchema ?? []) as $def) {
            $k = $def['key'] ?? null;
            if (!$k || isset($seen[$k])) continue;
            $seen[$k] = true;
            $mergedSchema[] = $def;
        }

        $applied['filters'] = array_merge($applied['filters'] ?? [], $appliedExtra['filters'] ?? []);
        $avail = (int) ($request?->input('availability', 0) ?? 0);
        $leagueIdMeta = $avail > 0 ? $avail : null;


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
                'availability' => $avail,
                'league_id'    => $leagueIdMeta,
            ],
        ];

        return [$formatted, [], null];
    }

    /** ───────────────────────── Row assembly (grouped) ───────────────────────── */
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
            $entry    = $playerStats->count() === 1 ? $playerStats->first() : $playerStats->sortByDesc('gp')->first();
            $player   = $entry->player;
            $isSeason = ($mode === 'season');

            $contract        = $player?->contracts()->exists() ? $player->contracts()->first() : null;
            $contractSeason  = $contract?->seasons->last();
            $contractLastLbl = $contractSeason?->label ?? '';
            $contractAavRaw  = is_numeric($contractSeason?->aav) ? (float) $contractSeason->aav : 0.0;

            $contractAavM    = $contractAavRaw > 0 ? $contractAavRaw / 1_000_000 : 0.0;
            $contractAav     = $contractAavRaw > 0 ? '$' . number_format($contractAavM, 1) . 'm' : '$0.0m';

            $lastYearNum     = $this->parseContractLastYear($contractLastLbl);

            // GP & TOI (normalize to INT seconds first)
            if ($isSeason) {
                $gpSum = (int) ($entry->gp ?? 0);

                $toiSec = 0;
                if (isset($entry->toi_seconds) && is_numeric($entry->toi_seconds)) {
                    $toiSec = (int) $entry->toi_seconds;
                } elseif (isset($entry->toi) && is_numeric($entry->toi)) {
                    $toiSec = (int) $entry->toi; // seconds in your schema
                } elseif (isset($entry->toi_minutes) && is_numeric($entry->toi_minutes)) {
                    $v = (float) $entry->toi_minutes;
                    $toiSec = (int) (($v > 4000) ? $v : $v * 60.0);
                }
            } else {
                $gpSum = ($mode === 'range')
                    ? (int) $playerStats->pluck('nhl_game_id')->unique()->count()
                    : (int) $playerStats->sum('gp');

                $toiSec = 0;
                if (($sum = (int) $playerStats->sum('toi_seconds')) > 0) {
                    $toiSec = $sum;
                } elseif (($sum = (float) $playerStats->sum('toi_minutes')) > 0) {
                    $toiSec = (int) (($sum > 4000) ? $sum : $sum * 60.0);
                } elseif (($sum = (float) $playerStats->sum('toi')) > 0) {
                    $toiSec = (int) (($sum > 4000) ? $sum : $sum * 60.0);
                }
            }

            $toiPerGameSec = ($gpSum > 0) ? (int) floor($toiSec / $gpSum) : 0;

            $row = [
                'name'                   => $player?->full_name ?? trim(($player->first_name ?? '') . ' ' . ($player->last_name ?? '')),
                'age'                    => $this->playerAge($player),
                'team'                   => $player?->team_abbrev ?? $entry->team_abbrev ?? ($entry->nhl_team_abbrev ?? null),
                'pos_type'               => $player?->pos_type,
                'contract_value'         => $contractAav,
                'contract_value_num'     => round($contractAavM, 1),
                'contract_last_year'     => $contractLastLbl,
                'contract_last_year_num' => $lastYearNum,
                'gp'                     => max(0, $gpSum),

                // TOI immediately after GP
                'toi_seconds'            => $toiPerGameSec,
                'toi'                    => $this->formatTimeOnIce($toiPerGameSec),
            ];

            foreach ($columns as $col) {
                $key = $col['key'] ?? null;
                if (!$key || $key === 'gp' || $key === 'toi' || $key === 'toi_seconds') {
                    continue;
                }

                // total for this key
                $total = $isSeason
                    ? (float) ($entry->{$key} ?? 0)
                    : (float) $playerStats->sum($key);

                if ($canSlice && $slice !== 'total') {
                    if ($slice === 'pgp') {
                        $row[$key] = $gpSum > 0 ? round($total / $gpSum, 2) : 0.0;
                    } elseif ($slice === 'p60') {
                        // use INT seconds, convert to hours
                        $row[$key] = $toiSec > 0 ? round($total / ($toiSec / 3600), 2) : 0.0;
                    }
                } else {
                    $row[$key] = fmod($total, 1.0) === 0.0 ? (int) $total : $total;
                }
            }

            $rows->push($row);
        }

        return $rows;
    }

    /** ───────────────────── Post-assembly filters + schema ───────────────────── */
    private function applyPostFilters(?Request $request, Collection $rows): array
    {
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

        $virtualSchema = [];
        if ($bounds['gp']['max'] > 0) {
            $virtualSchema[] = ['key' => 'gp', 'label' => 'GP', 'type' => 'number', 'bounds' => $bounds['gp'], 'step' => 1];
        }
        if ($bounds['contract_value_num']['max'] > 0) {
            $virtualSchema[] = [
                'key' => 'contract_value_num', 'label' => 'AAV', 'type' => 'number',
                'bounds' => [
                    'min' => (float) floor($bounds['contract_value_num']['min']),
                    'max' => (float) ceil($bounds['contract_value_num']['max']),
                ],
                'step' => 0.1
            ];
        }
        if ($bounds['contract_last_year_num']['max'] > 0) {
            $virtualSchema[] = ['key' => 'contract_last_year_num', 'label' => 'Term End', 'type' => 'number', 'bounds' => $bounds['contract_last_year_num'], 'step' => 1];
        }

        if (!$request) {
            return [$rows, ['filters' => []], $virtualSchema];
        }

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



    /** ───────────────────────────── Small helpers ───────────────────────────── */
    private function parseContractLastYear(?string $label): ?int
    {
        if (!$label) return null;
        $label = trim($label);

        if (preg_match('/\b(20\d{2})\b/', $label, $m)) {
            $year = (int) $m[1];

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
        if (Str::contains($name, 'prospect') || Str::contains($slug, 'prospect')) return true;

        $leagueFilter = $filters['league_abbrev'] ?? null;
        if ($leagueFilter) {
            $op  = $leagueFilter['operator'] ?? '=';
            $val = $leagueFilter['value'] ?? null;
            return ($op === '!=' && $val === 'NHL') || ($val && strtoupper($val) !== 'NHL');
        }
        return false;
    }



    /**
     * Build the filter schema from the perspective columns + identity fields,
     * then apply filters from the query string onto $base.
     *
     * Returns: [$schema, $applied]
     * - $schema: array of filter definitions for the UI (with numeric bounds)
     * - $applied: echo of what was applied (for the UI to hydrate)
     */
    private function buildSchemaAndApplyFilters($base, array $columns, ?Request $request): array
    {
        $table = $base->getModel()->getTable();

        // Join players as `pf` so we can filter by team/pos/age everywhere.
        if ($table === 'stats') {
            $base->leftJoin('players as pf', 'pf.id', '=', "{$table}.player_id");
        } else { // nhl_season_stats / nhl_game_summaries
            $base->leftJoin('players as pf', 'pf.nhl_id', '=', "{$table}.nhl_player_id");
        }

        $this->applyAvailabilityToBase($base, $request, $request?->user());


        // Base schema (always available in the UI)
        $schema = [
            ['key' => 'age',  'label' => 'Age',      'type' => 'int',  'bounds' => $this->ageBoundsForBase($base)],
            ['key' => 'team', 'label' => 'Team',     'type' => 'enum', 'options' => $this->teamOptionsForBase($base)],
            ['key' => 'pos',  'label' => 'Position', 'type' => 'enum', 'options' => $this->positionOptionsForBase($base)],
        ];

        // Perspective numeric columns -> add with bounds
        foreach ($columns as $col) {
            $key = $col['key'] ?? null;
            if (!$key || in_array($key, ['name','age','team','contract_value','gp'], true)) {
                continue;
            }

            $b = $this->bounds($base, $key);
            $schema[] = [
                'key'    => $key,
                'label'  => $col['label'] ?? \Illuminate\Support\Str::title(str_replace('_',' ', $key)),
                'type'   => 'number',
                'bounds' => $b,
                'step'   => 1,
            ];
        }

        // Expose GP slider only for tables that physically have it.
        if (\Illuminate\Support\Facades\Schema::hasColumn($table, 'gp')) {
            $schema[] = [
                'key'    => 'gp',
                'label'  => 'GP',
                'type'   => 'number',
                'bounds' => $this->bounds($base, 'gp'),
                'step'   => 1,
            ];
        }

        // Derived/virtual numeric keys that must NOT be pushed to SQL in the generic pass.
        $virtualOrDerived = ['age', 'contract_value_num', 'contract_last_year_num'];

        // Whitelist of physical numeric keys we can safely filter on.
        $physicalNumeric = collect($schema)
            ->filter(function ($s) use ($virtualOrDerived) {
                return strtolower($s['type'] ?? '') === 'number'
                    && !empty($s['key'])
                    && !in_array($s['key'], $virtualOrDerived, true);
            })
            ->pluck('key')
            ->flip(); // O(1) lookup

        // Echo back what was applied (for UI hydration)
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

        if (!empty($applied['pos'])) {
            $posU = array_map('strtoupper', $applied['pos']);
            if (array_diff($posU, ['G'])) {
                $posU = array_values(array_diff($posU, ['G']));
            }
            if (!empty($posU)) {
                $base->whereIn('pf.position', $posU);
            }
        }

        // Teams
        $teams = array_values(array_filter((array)($request?->query('team', []) ?? []), 'strlen'));
        if (!empty($teams)) {
            if ($table === 'stats') {
                $base->whereIn("{$table}.nhl_team_abbrev", $teams);
            } else {
                $base->whereIn('pf.team_abbrev', $teams);
            }
            $applied['filters']['team'] = $teams;
        }

        // Age min/max -> pf.dob range (DB-agnostic)
        $ageMin = $request?->query('age_min');
        $ageMax = $request?->query('age_max');
        if ($ageMin !== null || $ageMax !== null) {
            $today       = \Illuminate\Support\Carbon::today();
            $youngestDob = $ageMin !== null ? $today->copy()->subYears((int)$ageMin)->toDateString() : null;              // newest DOB
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

        // Dynamic numeric filters via *_min / *_max for physical columns only.
        foreach (($request?->query() ?? []) as $k => $v) {
            if (!is_scalar($v)) continue;
            if (!preg_match('/^(.*)_(min|max)$/', $k, $m)) continue;

            $baseKey = $m[1];
            $bound   = $m[2];

            if ($baseKey === 'age') continue;                 // handled above via DOB
            if (!isset($physicalNumeric[$baseKey])) continue; // only real numeric columns

            $col = $this->mapFilterColumn($base, $baseKey);
            if (!$col) continue;

            $val  = is_numeric($v) ? (float)$v : null;
            $pair = $applied['filters'][$baseKey] ?? ['min' => null, 'max' => null];

            if ($bound === 'min') {
                $pair['min'] = $val;
                if ($val !== null) $base->where($col, '>=', $val);
            } else {
                $pair['max'] = $val;
                if ($val !== null) $base->where($col, '<=', $val);
            }

            $applied['filters'][$baseKey] = $pair;
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
     * MySQL/Postgres safe. Handles TOI name differences by trying
     * multiple candidate columns and swallowing "unknown column" errors.
     */
    private function bounds($base, string $key): array
    {
        $col = $this->mapFilterColumn($base, $key);
        if (!$col) return ['min' => 0, 'max' => 0];

        $qb = $base->cloneWithout(['orders', 'columns']);

        try {
            $minVal = (clone $qb)->min($col);
            $maxVal = (clone $qb)->max($col);
        } catch (\Illuminate\Database\QueryException $e) {
            // MySQL 42S22 / Postgres 42703 => unknown column / invalid identifier
            \Log::error('bounds() failed', [
                'table'    => $table,
                'key'      => $key,
                'col'      => $col,
                'sql'      => (clone $qb)->toSql(),
                'bindings' => (clone $qb)->getBindings(),
                'code'     => $e->getCode(),
                'message'  => $e->getMessage(),
            ]);

            if ($e->getCode() === '42S22' || str_contains($e->getMessage(), 'Unknown column') ||
                str_contains($e->getMessage(), '42703')) {
                return ['min' => 0, 'max' => 0];
            }
            throw $e;
        }

        $min = (float) ($minVal ?? 0);
        $max = (float) ($maxVal ?? 0);
        if ($min > $max) { [$min, $max] = [$max, $min]; }

        return ['min' => (int) floor($min), 'max' => (int) ceil($max)];
    }




    /** │ Age bounds: portable */
    private function ageBoundsForBase($base): array
    {
        $table = $base->getModel()->getTable();

        $qb = $base->cloneWithout(['orders', 'columns', 'joins'])
            ->join('players as page', function ($j) use ($table) {
                if (in_array($table, ['nhl_season_stats', 'nhl_game_summaries'], true)) {
                    $j->on('page.nhl_id', '=', "{$table}.nhl_player_id");
                } else {
                    $j->on('page.id', '=', "{$table}.player_id");
                }
            })
            ->whereNotNull('page.dob');

        $earliestDob = $qb->clone()->min('page.dob');
        $latestDob   = $qb->clone()->max('page.dob');

        if (!$earliestDob && !$latestDob) return ['min' => 16, 'max' => 45];

        $minAge = $latestDob ? Carbon::parse($latestDob)->age : null;
        $maxAge = $earliestDob ? Carbon::parse($earliestDob)->age : null;

        if ($minAge === null && $maxAge !== null) $minAge = $maxAge;
        if ($maxAge === null && $minAge !== null) $maxAge = $minAge;
        if ($minAge === null || $maxAge === null) return ['min' => 16, 'max' => 45];
        if ($minAge > $maxAge) [$minAge, $maxAge] = [$maxAge, $minAge];

        return ['min' => (int)$minAge, 'max' => (int)$maxAge];
    }

    /** ───────────── Column mapping that tolerates MySQL/PG view drift ───────────── */
    private function mapFirstExisting(string $table, array $candidates): ?string
    {
        foreach ($candidates as $c) {
            try {
                if (Schema::hasColumn($table, $c)) {
                    return "{$table}.{$c}";
                }
            } catch (\Throwable $e) {
                // On some drivers/views hasColumn can be finicky; a try/catch keeps us safe
            }
        }
        return null;
    }



    private function mapFilterColumn($base, string $key): ?string
    {
        $table = $base->getModel()->getTable();

        // direct aliases for rate fields that actually exist in your schema
        $aliases = [
            'nhl_season_stats' => [
                'g_per_gp' => 'g_pg',  'a_per_gp' => 'a_pg',  'pts_per_gp' => 'pts_pg',
                'b_per_gp' => 'b_pg',  'h_per_gp' => 'h_pg',  'th_per_gp'  => 'th_pg',
                'g_per_60' => 'g_p60', 'a_per_60' => 'a_p60', 'pts_per_60' => 'pts_p60',
                'sog_per_60' => 'sog_p60', 'sat_per_60' => 'sat_p60',
                'hits_per_60' => 'hits_p60', 'blocks_per_60' => 'blocks_p60',
            ],
        ];

        if (isset($aliases[$table][$key])) {
            return $table . '.' . $aliases[$table][$key];
        }

        // keys that vary by install; pick the first column that actually exists
        $variants = [
            'toi' => [
                // your MySQL migration has "toi" (seconds). Some installs might use
                // toi_seconds or toi_minutes, so try in a safe order.
                'nhl_season_stats'   => ['toi', 'toi_seconds', 'toi_minutes'],
                'nhl_game_summaries' => ['toi_seconds', 'toi', 'toi_minutes'],
                'stats'              => ['toi'],
            ],
        ];

        if (isset($variants[$key][$table])) {
            foreach ($variants[$key][$table] as $cand) {
                if (\Illuminate\Support\Facades\Schema::hasColumn($table, $cand)) {
                    return $table . '.' . $cand;
                }
            }
            // no physical column → skip building a filter for this key
            return null;
        }

        // generic fallback only if the column exists
        return \Illuminate\Support\Facades\Schema::hasColumn($table, $key)
            ? $table . '.' . $key
            : null;
    }




    private function playerAge($player): ?int
    {
        if (!$player) return null;
        if (method_exists($player, 'age')) return $player->age();
        if (!empty($player->dob)) return Carbon::parse($player->dob)->age;
        return null;
    }


    private function getStatValue(object $st, string $key, string $slice)
    {
        $total = fn() => (float) ($st->{$key} ?? 0);

        if ($slice === 'pgp') {
            $mapped = $this->rateMaps()['pg'][$key] ?? null;
            if ($mapped && isset($st->{$mapped}) && is_numeric($st->{$mapped})) {
                return round((float) $st->{$mapped}, 1);
            }
            $gp = (int) ($st->gp ?? 0);
            return $gp > 0 ? round($total() / $gp, 1) : 0.0;
        }

        if ($slice === 'p60') {
            $mapped = $this->rateMaps()['p60'][$key] ?? null;
            if ($mapped && isset($st->{$mapped}) && is_numeric($st->{$mapped})) {
                return round((float) $st->{$mapped}, 1);
            }

            // TOI minutes regardless of the backing column name
            $toiMin = 0.0;
            if (isset($st->toi_seconds) && is_numeric($st->toi_seconds)) {
                $toiMin = (float)$st->toi_seconds / 60.0;
            } elseif (isset($st->toi) && is_numeric($st->toi)) {
                // your schema: seconds
                $toiMin = (float)$st->toi / 60.0;
            } elseif (isset($st->toi_minutes) && is_numeric($st->toi_minutes)) {
                $toiMin = (float)$st->toi_minutes; // already minutes
            }

            return $toiMin > 0 ? round($total() / ($toiMin / 60.0), 1) : 0.0;
        }

        return $st->{$key} ?? 0;
    }




    private function deriveFromTotals(string $key, float|int $total, string $slice, int $gpSum, float $toiSec)
    {
        if ($slice === 'pgp') return $gpSum > 0 ? round($total / $gpSum, 3) : 0;
        if ($slice === 'p60') return $toiSec > 0 ? round($total / ($toiSec / 3600), 3) : 0;
        return $total;
    }

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
