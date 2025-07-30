<?php

namespace App\Livewire;

use Livewire\Component;
use App\Models\Player;
use App\Models\PlayerRanking;
use Illuminate\Support\Facades\Auth;

class PlayerRankingsTable extends Component
{
    public $players;
    public $rankingType = 'FHL 2025'; // default type, optional
    public $editing = [];
    public $editingValues= [];
    public $savedPlayerId = null;
    public $saved = [];


    protected $listeners = [
	  'reinitializeRow' => '$refresh',
	];


    public function mount()
    {
        $this->loadPlayers();
    }

    public function updatedRankingType()
    {
        $this->loadPlayers();
    }



    public function loadPlayers()
	{
		// $this->players = Player::with(['currentRanking' => function ($query) {
		//     $query->where('user_id', auth()->id());
		// }])->get()->sortByDesc(function ($player) {
		//     return $player->currentRanking->rank_1 ?? 0;
		// })->values();

		// $this->players = Player::with(['currentRanking', 'rankingsForUser'])->get();
		$this->players = Player::with(['currentRanking', 'rankingsForUser'])->get()
        ->sortByDesc(fn ($player) =>
            optional($player->currentRanking)->rank_1 ?? 0
        )->values(); // reset keys

	}


 //    public function loadPlayers()
	// {
	//     $players = Player::with(['rankings' => function ($query) {
	//         $query->where('user_id', auth()->id())
	//               ->orderByDesc('created_at');
	//     }])->get();

	//     foreach ($players as $player) {
	//         $rankings = $player->rankings;

	//         $player->current_ranking = $rankings->first();
	//         $player->previous_rankings = $rankings->slice(1)->take(2)->values();
	//     }

	//     $this->players = $players
	//         ->sortByDesc(function ($player) {
	//             return isset($player->current_ranking)
	//                 ? (float) $player->current_ranking->rank_1
	//                 : 0;
	//         })
	//         ->values();
	// }


	public function edit($playerId)
    {
        $this->editing[$playerId] = true;

        $player = $this->players->firstWhere('id', $playerId);
        if ($player && $player->current_ranking) {
            $this->editingValues[$playerId] = $player->current_ranking->rank_1;
        }
    }

    public function save($playerId, $newValue = null)
	{
	    $player = Player::findOrFail($playerId);

	    // Fetch the latest ranking for this user/player
	    $ranking = PlayerRanking::where('user_id', auth()->id())
	        ->where('player_id', $player->id)
	        ->latest('created_at')
	        ->first();

	    // Use the passed-in value or fallback to what's in editingValues
	    $value = $newValue ?? ($this->editingValues[$playerId] ?? null);

	    if ($ranking && $value !== null) {
	        $ranking->rank_1 = $value;
	        $ranking->save();

	        // Set saved ID for checkmark icon to appear
	        $this->savedPlayerId = $playerId;
	    }

	    // ✅ Ensure $saved is an array before assigning
	    if (!is_array($this->saved)) {
	        $this->saved = [];
	    }

	    // Update Livewire state
	    $this->editing[$playerId] = false;
	    $this->saved[$playerId] = true;

	    // Remove the input value since it was saved
    	unset($this->editingValues[$playerId]);

	    // Reload list to show updated data
	    $this->loadPlayers();
	}



    public function render()
    {
        return view('livewire.player-rankings-table');
    }

}
