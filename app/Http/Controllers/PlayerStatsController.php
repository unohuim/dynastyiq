<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Perspective;
use App\Models\Stat;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Illuminate\Routing\Controller as BaseController;

class PlayerStatsController extends BaseController
{
    /**
     * Show the page with initial payload.
     *
     * @return View
     */
    public function index(): View
    {
        $user = Auth::user();
        $perspectives = Perspective::forUser($user)
            ->get()       // ← get the Collection
            ->toArray();  // ← convert to array

        // fetch all of this user’s own rankings + any 'public_guest' ones
        // $availableRankings = PlayerRanking::forUser($user)
        //     ->get()       // ← retrieve a Collection of models
        //     ->toArray();  // ← convert to plain PHP arrays




        $availableRankings = ['FHL', 'Dobber'];
        $selectedPerspectiveId = $perspectives[0]['id'] ?? null;
        $season = null;
        $availableSeasons = [];

        // Build initial payload
        if ($selectedPerspectiveId) {
            [$payload, $availableSeasons, $season] = $this->buildAndFormatPayload(
                $user,
                $selectedPerspectiveId,
                null
            );
        } else {
            $payload = ['headings' => [], 'data' => [], 'settings' => []];
        }

        return view('player-stats-view', [
            'payload'               => $payload,
            'perspectives'          => $perspectives,
            'availableRankings'     => $availableRankings,
            'selectedPerspectiveId' => $selectedPerspectiveId,
            'availableSeasons'      => $availableSeasons,
            'season'                => $season,
        ]);
    }

    /**
     * Return JSON payload for AJAX/Alpine calls.
     *
     * Expects query params: ?perspectiveId=123&season=2025
     *
     * @param  Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function payload(Request $request)
    {
        $request->validate([
            'perspectiveId' => 'sometimes|nullable|integer|exists:perspectives,id',
            'season'        => 'nullable|string',
        ]);

        $user          = $request->user();
        $perspectiveId = (int)$request->input('perspectiveId');
           
        $season        = $request->input('season');

        [$payload] = $this->buildAndFormatPayload(
            $user,
            $perspectiveId,
            $season
        );


        return response()->json($payload);
    }

    /**
     * Build raw payload, then format it for the frontend.
     *
     * @param  \Illuminate\Contracts\Auth\Authenticatable  $user
     * @param  int                                         $perspectiveId
     * @param  string|null                                 $seasonFilter
     * @return array{0: array<string,mixed>,1: array<int,string>,2: string|null}
     */
    private function buildAndFormatPayload($user, ?int $perspectiveId, ?string $seasonFilter): array
    {
        // 1) Fetch perspective settings (or use defaults when no perspectiveId)
        if (is_null($perspectiveId)) {
            $settings = [
                'filters' => [],
                'columns' => [],
                'sort'    => ['key' => null, 'direction' => 'desc'],
            ];
        } else {
            $perspective = Perspective::findOrFail($perspectiveId);
            $settings    = json_decode($perspective->settings, true) ?? [];
        }

        $filters = $settings['filters'] ?? [];
        $columns = $settings['columns'] ?? [];
        $sort    = $settings['sort']    ?? ['sortKey' => 'pts', 'sortDirection' => 'desc'];

        

        // 2) Build the stats query
        $query = Stat::with([
                'player.contracts.seasons',
                // 'player.currentRanking'   
            ])
            ->regularSeason();

        
        //league fitlers
        if (! empty($filters['league_abbrev']['value'])) {
            $op  = $filters['league_abbrev']['operator'] ?? '=';
            $val = $filters['league_abbrev']['value'];
            $query->where('league_abbrev', $op, $val);
        }

        // season logic
        $lockedSeason = $filters['season_id']['value'] ?? null;
        if ($lockedSeason) {
            $query->where('season_id', $lockedSeason);
            $season = $lockedSeason;
        } elseif (!empty($seasonFilter)) {
            $query->where('season_id', $seasonFilter);
            $season = $seasonFilter;
        } else {
            $season = null;
        }

        $stats = $query->get();


        // collect available seasons
        if (! $lockedSeason) {
            $availableSeasons = $stats
                ->pluck('season_id')
                ->unique()
                ->sortDesc()
                ->values()
                ->mapWithKeys(fn($id) => [$id => (string) $id])
                ->toArray();

            if (is_null($season)) {
                $season = (string) array_key_first($availableSeasons);
            }
        } else {
            $availableSeasons = [$season => (string) $season];
        }

        // 3) Group by player and pick the best entry
        $groups = $stats->groupBy('player_id');
        $rows   = collect();

        foreach ($groups as $playerStats) {
            $entry = $playerStats->count() === 1
                ? $playerStats->first()
                : $playerStats->sortByDesc('GP')->first();

            $player         = $entry->player;
            $contract       = $player->contracts->first();
            $contractSeason = $contract?->seasons->last();
            $contractLength = $contract?->seasons->count();


            $row = [
                'name'           => $player->full_name
                    ?? trim($player->first_name . ' ' . $player->last_name),
                'age'            => $player->age(),                
                'team'           => $entry->nhl_team_abbrev,
                'pos'            => $player->position,
                'pos_type'       => $player->pos_type,
                'contract_value' => is_numeric($contractSeason?->aav)
                    ? $contractSeason->aav
                    : 0,
                'contract_length'=> is_numeric($contractLength)
                    ? $contractLength
                    : 0,
                'contract_last_year'
                                 => $contractSeason?->label ?? '',
                'head_shot_url'  => $player->head_shot_url,
                'stats'          => [],
            ];

            foreach ($columns as $col) {
                $key = $col['key'] ?? null;
                if (! $key || in_array($key, ['name','age', 'team','contract_value'], true)) {
                    continue;
                }

                $value = match($key) {
                    'avgPTSpGP' => $playerStats->sum('gp') > 0
                        ? round($playerStats->sum('pts') / $playerStats->sum('gp'), 2)
                        : 0,
                    'shooting_percentage' => $playerStats->sum('sog') > 0
                        ? round($playerStats->sum('g') / $playerStats->sum('sog'), 3)
                        : 0,
                    default => $playerStats->sum($key) ?? 0,
                };

                $row['stats'][$key] = $value;
            }

            $rows->push($row);
        }

        // 4) Assemble formatted payload
        $formatted = [
            'headings' => array_merge(
                [
                    ['key' => 'name',           'label' => 'Player'],
                    ['key' => 'age',            'label' => 'Age'],        
                    ['key' => 'team',           'label' => 'Team'],
                    ['key' => 'contract_value', 'label' => 'Contract'],
                ],
                collect($columns)
                    ->reject(fn($col) => in_array($col['key'], ['name','age','team','contract_value'], true))
                    ->map(fn($col) => ['label' => $col['label'], 'key' => $col['key']])
                    ->values()
                    ->toArray()
            ),
            'data'     => $rows->values(),
            'settings' => [
                'sortable'             => array_merge(
                    ['name','age','team','contract_value'],
                    collect($columns)->pluck('key')->toArray()
                ),
                'filterable'           => ['pos_type','team','league'],
                'defaultSort'          => $sort['sortKey'] ?? null,
                'defaultSortDirection' => $sort['sortDirection'] ?? 'desc',
            ],
        ];

        return [$formatted, $availableSeasons, $season];
    }
}
