<?php

namespace App\Livewire;

use Livewire\Component;
use App\Models\Player;
use App\Models\PlayerRanking;
use App\Models\RankingProfile;
use Illuminate\Support\Facades\Auth;

class PlayerRankingsTable extends Component
{
    public $players;
    public $rankingType = 'FHL 2025'; // default type, optional but not used to find profile here
    public $editing = [];
    public $editingValues= [];
    public $savedPlayerId = null;
    public $saved = [];

    protected $listeners = [
        'reinitializeRow' => '$refresh',
    ];

    public ?RankingProfile $rankingProfile = null;

    public function mount()
    {
        // Grab first ranking profile for current user (no lookup by name)
        $this->rankingProfile = RankingProfile::where('author_id', Auth::id())->first();

        $this->loadPlayers();
    }


    public function loadPlayers()
    {
        if (!$this->rankingProfile) {
            $this->players = collect();
            return;
        }

        $profileId = $this->rankingProfile->id;

        // Eager load currentRanking and rankingsForUser filtered by ranking_profile_id
        $this->players = Player::with([
            'currentRanking' => function ($q) use ($profileId) {
                $q->where('ranking_profile_id', $profileId);
            },
            'rankingsForUser' => function ($q) use ($profileId) {
                $q->where('ranking_profile_id', $profileId);
            }
        ])
        ->get()
        ->sortByDesc(fn($player) => optional($player->currentRanking)->score ?? 0)
        ->values();
    }

    public function edit($playerId)
    {
        $this->editing[$playerId] = true;

        $player = $this->players->firstWhere('id', $playerId);
        if ($player && $player->currentRanking) {
            $this->editingValues[$playerId] = $player->currentRanking->score;
        }
    }

    public function save($playerId, $newValue = null)
    {
        if (!$this->rankingProfile) {   
            return;
        }
        

        $player = Player::findOrFail($playerId);

        $ranking = PlayerRanking::where('ranking_profile_id', $this->rankingProfile->id)
            ->where('player_id', $player->id)
            ->first();

        $value = $newValue ?? ($this->editingValues[$playerId] ?? null);

        if ($ranking && $value !== null) {
            $ranking->score = $value;
            $ranking->save();

            $this->savedPlayerId = $playerId;
        }

        $this->editing[$playerId] = false;
        $this->saved[$playerId] = true;
        unset($this->editingValues[$playerId]);

        $this->loadPlayers();        
    }

    public function render()
    {
        return view('livewire.player-rankings-table');
    }
}
