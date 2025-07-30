<?php

namespace App\Livewire;

use Livewire\Component;
use App\Models\Stat;

class PlayerStatsTable extends Component
{
    public bool $isProspect;
    public string $season;
    public string $sortField = 'PTS';
    public string $sortDirection = 'desc';
    public string $playerName = '';
    public array $expanded = [];

    public function mount(bool $isProspect, string $defaultSeason)
    {
        $this->isProspect = $isProspect;
        $this->season = $defaultSeason;
    }

    public function render()
    {
        // 1) Build base query
        $query = Stat::with('player')
            ->where('season_id', $this->season)
            ->where('is_prospect', $this->isProspect)
            ->where('game_type_id', 2);

        if (! $this->isProspect) {
            $query->where('league_abbrev', 'NHL');
        }

        if (strlen(trim($this->playerName)) >= 2) {
            $query->where('player_name', 'like', "%{$this->playerName}%");
        }

        // 2) Fetch and group
        $flat = $query->get();
        $groups = $flat->groupBy('player_id');

        // 3) Normalize into unified structure
        $stats = collect();
        foreach ($groups as $playerStats) {
            if ($playerStats->count() === 1) {
                $stat = $playerStats->first();
                $stat->isMulti = false;
                $stat->age = $stat->player->age();
                $stat->pos_type = $stat->player->pos_type; // For single stats
                $stats->push($stat);
            } else {
                $first = $playerStats->first();
                $entry = new \stdClass();

                $entry->isMulti = true;
                $entry->player = $first->player;
                $entry->player_id = $first->player->id;
                $entry->player_name = $first->player_name;
                $entry->season_id = $first->season_id;
                $entry->league_abbrev = $playerStats->sortByDesc('GP')->first()->league_abbrev;
                $entry->nhl_team_abbrev = $playerStats->sortByDesc('GP')->first()->nhl_team_abbrev;
                $entry->pos_type = $first->player->pos_type;
                $entry->age = $first->player->age();
                


                // Stats
                $entry->G = $playerStats->sum('G');
                $entry->A = $playerStats->sum('A');
                $entry->PTS = $playerStats->sum('PTS');
                $entry->GP = $playerStats->sum('GP');
                $entry->SOG = $playerStats->sum('SOG');

                $entry->avgPTSpGP = $entry->GP
                    ? number_format($entry->PTS / $entry->GP, 2, '.', '')
                    : '0.00';

                $entry->shooting_percentage = $entry->SOG > 0
                    ? $entry->G / $entry->SOG
                    : 0;

                $entry->stats = $playerStats;

                $stats->push($entry);
            }
        }

        // 4) Sort in PHP (for Livewire fallback / completeness)
        $callback = fn($item) => $this->sortField === 'age'
            ? ($item->age ?? $item->player->age())
            : data_get($item, $this->sortField, 0);

        $stats = $this->sortDirection === 'desc'
            ? $stats->sortByDesc($callback)
            : $stats->sortBy($callback);


    	foreach ($stats as $stat) {
		    if (isset($stat->player)) {
		        $stat->player->age = $stat->player->age(); // Add flat age value
		    }
		}


        return view('livewire.player-stats-table', [
		    'statsJson' => $stats->values(), // ✅ pass raw array/collection
		]);

    }
}
