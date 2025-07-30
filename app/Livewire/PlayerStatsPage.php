<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Perspective;
use App\Models\Stat;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\Attributes\On;

/**
 * @psalm-type PerspectiveSettings = array{
 *     columns: list<array{key: string, label: string, type?: string}>,
 *     sort: array{key: string, direction: string},
 *     filters: array<string, array{value: mixed, locked?: bool}>
 * }
 */
class PlayerStatsPage extends Component
{
    public bool $isProspect;
    public ?string $season = null;
    public array $availableSeasons = [];
    public array $availablePerspectives = [];
    public ?int $selectedPerspectiveId = null;
    public array $jsonPayload = [];

    /**
     * Mounts the component.
     *
     * @param bool $isProspect
     */
    public function mount(bool $isProspect): void
    {
        $this->isProspect = $isProspect;
        $this->availablePerspectives = Perspective::forUser(Auth::user(), $isProspect)->values()->toArray();
        $this->selectedPerspectiveId = $this->availablePerspectives[0]['id'] ?? null;

        if ($this->selectedPerspectiveId) {
            $this->jsonPayload = $this->formatPayload($this->buildPayload());
        }
    }

    /**
     * Triggered when the selected season changes.
     */
    public function updatedSeason(): void
    {
        $this->jsonPayload = $this->buildPayload();
        
    }


    public function generatePayload(int $perspectiveId, ?string $seasonId = null): array
    {
        $this->selectedPerspectiveId = $perspectiveId;
        $this->season = $seasonId;

        return $this->formatPayload($this->buildPayload());
    }



    /**
     * Triggered when the selected perspective changes.
     */
    public function updatedSelectedPerspectiveId(): void
    {
        $this->jsonPayload = $this->formatPayload($this->buildPayload());
        // $this->dispatch('playerStatsUpdated', json: $this->jsonPayload)->toBrowser();
        $this->dispatch('playerStatsUpdated', json: $this->jsonPayload);

    }


    /**
     * Builds the frontend payload.
     *
     * @return array<string, mixed>
     */
    public function buildPayload(): array
    {
        $perspective = Perspective::findOrFail($this->selectedPerspectiveId);

        /** @var PerspectiveSettings $settings */
        $settings = $perspective->settings;
        $filters = $settings['filters'] ?? [];
        $columns = $settings['columns'] ?? [];
        $sort = $settings['sort'] ?? ['key' => null, 'direction' => 'desc'];

        $query = Stat::with(['player.contracts.seasons'])
            ->regularSeason()
            ->whereHas('player', function ($q): void {
                $q->where('is_prospect', $this->isProspect);
            });

        if (!empty($filters['league_abbrev']['value'])) {
            $query->where('league_abbrev', $filters['league_abbrev']['value']);
        }

        $lockedSeason = $filters['season_id']['value'] ?? null;

        if ($lockedSeason) {
            $query->where('season_id', $lockedSeason);
            $this->season = $lockedSeason;
        } elseif (!empty($this->season)) {
            $query->where('season_id', $this->season);
        }

        $stats = $query->get();

        if (!$lockedSeason) {
            $this->availableSeasons = $stats->pluck('season_id')
                ->unique()
                ->sortDesc()
                ->values()
                ->mapWithKeys(fn ($id) => [$id => $id])
                ->toArray();

            if (empty($this->season)) {
                $this->season = (string) array_key_first($this->availableSeasons);
            }
        } else {
            $this->availableSeasons = [$this->season => $this->season];
        }

        $groups = $stats->groupBy('player_id');
        $rows = collect();

        foreach ($groups as $playerStats) {
            $entry = $playerStats->count() === 1
                ? $playerStats->first()
                : $playerStats->sortByDesc('GP')->first();

            $player = $entry->player;

            $contract = $player->contracts->first();
            $contractSeason = $contract?->seasons->first();
            $contractLength = $contract?->seasons->count();
            
            $row = [
                'name' => $player->full_name ?? trim($player->first_name . ' ' . $player->last_name),
                'age' => $player->age(),
                'team' => $entry->nhl_team_abbrev,
                'pos' => $player->position,
                'pos_type' => $player->pos_type,
                'contract_value' => is_numeric($contractSeason?->aav) ? $contractSeason->aav : 0,
                'contract_length' => is_numeric($contractLength) ? $contractLength : 0,
                'stats' => [],
            ];

            foreach ($columns as $col) {
                $key = $col['key'] ?? null;

                if (!$key || in_array($key, ['name', 'age', 'team', 'contract_value'], true)) {
                    continue;
                }

                $value = match ($key) {
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



        return [
            'headings' => array_merge(
                [
                    ['key' => 'name', 'label' => 'Player'],
                    ['key' => 'age', 'label' => 'Age'],
                    ['key' => 'team', 'label' => 'Team'],
                    ['key' => 'contract_value', 'label' => 'Contract'],
                ],
                collect($columns)
                    ->reject(fn ($col) => in_array($col['key'], ['name', 'age', 'team', 'contract_value'], true))
                    ->map(fn ($col) => ['label' => $col['label'], 'key' => $col['key']])
                    ->values()
                    ->toArray()
            ),
            'data' => $rows->values(),
            'settings' => [
                'sortable' => array_merge(
                    ['name', 'age', 'team', 'contract_value'],
                    collect($columns)->pluck('key')->toArray()
                ),
                'filterable' => ['pos_type', 'team', 'league'],
                'defaultSort' => $sort['key'] ?? null,
                'defaultSortDirection' => $sort['direction'] ?? 'desc',
            ],
        ];
    }



    /**
     * Renders the component.
     *
     * @return View
     */
    public function render(): View
    {

        return view('livewire.player-stats-page', [
            'payload' => $this->formatPayload($this->buildPayload()),
            'perspectives' => $this->availablePerspectives,
            'selectedPerspectiveId' => $this->selectedPerspectiveId,
            'availableSeasons' => $this->availableSeasons,
            'season' => $this->season,
        ])->layout('layouts.app');

    }




    private function formatPayload(array $raw): array
    {
        return [
            'headings' => array_merge(
                [
                    ['key' => 'name', 'label' => 'Player'],
                    ['key' => 'age', 'label' => 'Age'],
                    ['key' => 'team', 'label' => 'Team'],
                    ['key' => 'contract_value', 'label' => 'Contract'],
                ],
                collect($raw['headings'] ?? [])->reject(fn ($col) =>
                    in_array($col['key'], ['name', 'age', 'team', 'contract_value'], true)
                )->map(fn ($col) =>
                    ['label' => $col['label'], 'key' => $col['key']]
                )->values()->toArray()
            ),
            'data' => $raw['data'] ?? [],
            'settings' => [
                'sortable' => array_merge(
                    ['name', 'age', 'team', 'contract_value'],
                    collect($raw['headings'] ?? [])->pluck('key')->toArray()
                ),
                'filterable' => ['pos_type', 'team', 'league'],
                'defaultSort' => $raw['settings']['defaultSort'] ?? null,
                'defaultSortDirection' => $raw['settings']['defaultSortDirection'] ?? 'desc',
            ],
        ];
    }


}
