<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Perspective;
use App\Models\Stat;              // Prospects (non-NHL, season totals)
use App\Models\NhlSeasonStat;     // NHL season view (supports game_type)
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Auth;
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
                2            // game_type: Regular Season
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
     * - perspectiveId: int
     * - perspective: slug (or legacy name)
     * - season / season_id: string
     * - slice: total|pgp|p60   (applied only when is_slicable=true)
     * - game_type: 1|2|3       (ignored for Prospects)
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
            'period'        => 'nullable|in:season,range',
            'game_type'     => 'nullable|in:1,2,3',
        ]);

        $user = $request->user();

        // Resolve perspective under visibility scope
        if ($request->filled('perspectiveId')) {
            $perspective = Perspective::forUser($user)
                ->whereKey($request->integer('perspectiveId'))
                ->firstOrFail();
        } else {
            $slug = $request->string('perspective')->toString();
            $perspective = Perspective::forUser($user)
                ->where('slug', $slug)
                ->orWhere('name', $slug) // legacy fallback
                ->firstOrFail();
        }

        $season       = $request->input('season_id', $request->input('season'));
        $sliceParam   = $request->input('slice', 'total');
        $gameType     = (int) $request->input('game_type', 2);
        $canSlice     = (bool)($perspective->is_slicable ?? true);
        $effectiveSlice = $canSlice ? $sliceParam : 'total';

        [$payload] = $this->buildAndFormatPlayersPayload(
            $user,
            $perspective->id,
            $season,
            $effectiveSlice,
            $gameType
        );

        return response()->json($payload);
    }

    /**
     * Build + format the PLAYERS payload.
     *
     * @param  mixed        $user
     * @param  int          $perspectiveId
     * @param  string|null  $seasonFilter
     * @param  string       $slice       'total'|'pgp'|'p60' (applied only if is_slicable=true)
     * @param  int|null     $gameType    1=pre,2=reg,3=playoffs (ignored for Prospects)
     * @return array{0: array<string,mixed>,1: array<int,string>,2: string|null}
     */
    private function buildAndFormatPlayersPayload($user, int $perspectiveId, ?string $seasonFilter, string $slice = 'total', ?int $gameType = 2): array
    {
        // Perspective + settings
        $perspective = Perspective::findOrFail($perspectiveId);
        $settings    = is_array($perspective->settings) ? $perspective->settings : (json_decode($perspective->settings ?? '[]', true) ?: []);
        $canSlice    = (bool)($perspective->is_slicable ?? true);

        $filters = $settings['filters'] ?? [];
        $columns = $settings['columns'] ?? [];
        $sort    = $settings['sort']    ?? ['sortKey' => 'pts', 'sortDirection' => 'desc'];

        // Is this a prospects view? (non-NHL league)
        $leagueFilter = $filters['league_abbrev'] ?? null;
        $isProspects  = false;
        if ($leagueFilter) {
            $op  = $leagueFilter['operator'] ?? '=';
            $val = $leagueFilter['value'] ?? null;
            $isProspects = ($op === '!=' && $val === 'NHL') || ($val && strtoupper($val) !== 'NHL');
        }

        // Season resolve (respect locked)
        $lockedSeason = $filters['season_id']['value'] ?? null;
        $season       = $lockedSeason ?: $seasonFilter;

        // Identity headings (always first)
        $identityCols = [
            ['key' => 'name',               'label' => 'Player'],
            ['key' => 'age',                'label' => 'Age'],
            ['key' => 'team',               'label' => 'Team'],
            ['key' => 'pos_type',           'label' => 'Type'],
            ['key' => 'contract_value',     'label' => 'Contract'],
            ['key' => 'contract_last_year', 'label' => 'Last Yr'],
            // GP must always appear here (pre-columns) and remain a TOTAL (never sliced)
            ['key' => 'gp',                 'label' => 'GP'],
        ];

        $rows                = collect();
        $availableSeasons    = [];
        $availableGameTypes  = $isProspects ? [2] : [1, 2, 3];
        $effectiveGameType   = 2;

        if ($isProspects) {
            // Prospects: ignore game_type; apply slice only if canSlice
            $query = Stat::with(['player.contracts.seasons'])
                ->regularSeason()
                ->where('league_abbrev', '!=', 'NHL');

            if ($season) {
                $query->where('season_id', $season);
            }

            $stats = $query->get();

            $availableSeasons = $stats->pluck('season_id')->unique()->sortDesc()->values()->all();
            if (!$season) {
                $season = $availableSeasons[0] ?? null;
            }

            $groups = $stats->groupBy('player_id');

            foreach ($groups as $playerStats) {
                $entry  = $playerStats->first();
                $player = $entry->player;

                // Precompute totals for slice math
                $totals = [];
                foreach ($columns as $c) {
                    $k = $c['key'] ?? null;
                    if ($k) $totals[$k] = $playerStats->sum($k) ?? 0;
                }
                // GP is always total for prospects
                $gpSum  = max(0, (int)$playerStats->sum('gp'));
                // If Stat stores total TOI in minutes, adapt as needed; if not, Per60 falls back to totals.
                $toiMin = (float)($playerStats->sum('toi_minutes') ?? 0);
                $toiSec = $toiMin > 0 ? ($toiMin * 60.0) : 0.0;

                // Contract
                $contract        = $player?->contracts()->exists() ? $player->contracts()->first() : null;
                $contractSeason  = $contract?->seasons->last();
                $contractLastLbl = $contractSeason?->label ?? '';
                $contractAav = is_numeric($contractSeason?->aav)
                    ? '$' . number_format($contractSeason->aav / 1_000_000, 3) . 'm'
                    : '$0.0m';

                $row = [
                    'name'               => $player?->full_name ?? trim(($player?->first_name ?? '') . ' ' . ($player?->last_name ?? '')),
                    'age'                => $player?->age() ?? 0,
                    'team'               => $entry->nhl_team_abbrev,
                    'pos_type'           => $player?->pos_type,
                    'contract_value'     => $contractAav,
                    'contract_last_year' => $contractLastLbl,
                    'gp'                 => $gpSum, // <- ALWAYS total, never sliced
                ];

                foreach ($columns as $col) {
                    $key = $col['key'] ?? null;
                    if (!$key || $key === 'gp') continue; // never override identity GP

                    if ($canSlice && $slice !== 'total') {
                        $row[$key] = $this->deriveFromTotals($key, $totals[$key] ?? 0, $slice, $gpSum, $toiSec);
                    } else {
                        $row[$key] = $totals[$key] ?? 0;
                    }
                }

                $rows->push($row);
            }

            $effectiveGameType = 2; // fixed for prospects
        } else {
            // NHL: apply slice only if canSlice
            if (!$season) {
                $season = (string) NhlSeasonStat::query()->max('season_id');
            }

            // Determine effective game_type: request -> locked -> default(2)
            $effectiveGameType = (int)($gameType ?? 2);
            if (isset($filters['game_type']['value'])) {
                $effectiveGameType = (int)$filters['game_type']['value'];
            }

            $stats = NhlSeasonStat::query()
                ->with(['player.contracts.seasons'])
                ->where('season_id', $season)
                ->where('game_type', $effectiveGameType)
                ->get();

            $availableSeasons = NhlSeasonStat::query()
                ->select('season_id')->distinct()->pluck('season_id')->sortDesc()->values()->all();

            foreach ($stats as $st) {
                $p = $st->player;

                // Contract
                $contract        = $p?->contracts()->exists() ? $p->contracts()->first() : null;
                $contractSeason  = $contract?->seasons->last();
                $contractLastLbl = $contractSeason?->label ?? '';
                $contractAav = is_numeric($contractSeason?->aav)
                    ? '$' . str_pad(number_format($contractSeason->aav / 1_000_000, 3, '.', ''), 6, ' ', STR_PAD_LEFT)
                    : '$ 0.000';

                $row = [
                    'name'               => $p?->full_name ?? trim(($p->first_name ?? '') . ' ' . ($p->last_name ?? '')),
                    'age'                => $p?->age() ?? 0,
                    'team'               => $p?->team_abbrev,
                    'pos_type'           => $p?->pos_type,
                    'contract_value'     => $contractAav,
                    'contract_last_year' => $contractLastLbl,
                    'gp'                 => (int)($st->gp ?? 0), // <- ALWAYS total, never sliced
                ];

                foreach ($columns as $col) {
                    $key = $col['key'] ?? null;
                    if (!$key || $key === 'gp') continue; // never override identity GP

                    if ($canSlice && $slice !== 'total') {
                        $row[$key] = $this->getStatValue($st, $key, $slice);
                    } else {
                        $row[$key] = $st->{$key} ?? 0;
                    }
                }

                $rows->push($row);
            }
        }

        // Sort
        $sortKey = $sort['sortKey'] ?? 'pts';
        $sortDir = strtolower($sort['sortDirection'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $sorted  = $rows->sortBy([[$sortKey, $sortDir]])->values();

        // Headings (identity first; dedup ensures identity GP wins)
        $headings = $this->mergeHeadings($identityCols, $columns);

        // Final payload
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
            ],
        ];

        return [$formatted, $availableSeasons, $season];
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
        // helper to safely pull a total
        $total = fn() => (float) ($st->{$key} ?? 0);

        if ($slice === 'pgp') {
            $mapped = $this->rateMaps()['pg'][$key] ?? null;

            if ($mapped && isset($st->{$mapped}) && is_numeric($st->{$mapped})) {
                return round((float) $st->{$mapped}, 1); // <- round mapped field
            }

            // compute P/GP from totals when no mapped field
            $gp = (int) ($st->gp ?? 0);
            if ($gp > 0) {
                return round($total() / $gp, 1);
            }

            return 0.0;
        }

        if ($slice === 'p60') {
            $mapped = $this->rateMaps()['p60'][$key] ?? null;

            if ($mapped && isset($st->{$mapped}) && is_numeric($st->{$mapped})) {
                return round((float) $st->{$mapped}, 1); // <- round mapped field
            }

            // compute per 60 from totals when no mapped field
            // normalize TOI to minutes: prefer explicit minutes field if present
            $toiMin = null;
            if (isset($st->toi_minutes) && is_numeric($st->toi_minutes)) {
                $toiMin = (float) $st->toi_minutes;
            } elseif (isset($st->toi) && is_numeric($st->toi)) {
                // Heuristic: if very large, treat as seconds; else minutes
                $toi = (float) $st->toi;
                $toiMin = $toi > 4000 ? $toi / 60.0 : $toi;
            } else {
                $toiMin = 0.0;
            }

            if ($toiMin > 0) {
                // value per 60 = totals / (TOI in minutes / 60)
                return round($total() / ($toiMin / 60.0), 1);
            }

            return 0.0;
        }

        // totals (leave raw so integer cols remain integers)
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
            return $toiSec > 0 ? round($total / ($toiSec / 60), 3) : 0;
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
