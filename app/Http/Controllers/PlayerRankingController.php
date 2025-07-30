<?php

namespace App\Http\Controllers;

use App\Models\PlayerRanking;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Models\Player;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;


class PlayerRankingController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        // Optionally load perspectives if you're allowing filtering by them
        $perspectives = "perspctives";//Perspective::all();

        return view('player.rankings', [
            'perspectives' => $perspectives,
        ]);
    }



    public function upload(Request $request)
    {
        //dd($request->all(), $request->file('file'));

        $request->validate([
            'file' => 'required|file|mimes:csv,txt',
            'ranking_type' => 'required|string',
        ]);

        // dd('here');
        $csv = $request->file('file');
        $path = $csv->getRealPath(); // full temporary path
        $rows = array_map('str_getcsv', file($path));

        // Optionally skip header row
        if (!empty($rows) && str_contains(strtolower(implode(',', $rows[0])), 'player')) {
            array_shift($rows);
        }


        foreach ($rows as $index => $row) {

            if (!isset($row[0]) || trim($row[0]) === '') {
                continue;
            }

            $name   = trim($row[0]);
            $rank1  = $row[1] ?? null;
            $rank2  = $row[2] ?? null;
            $rank3  = $row[3] ?? null;

            $player = $this->findOrCreatePlayerByName($name);
            if (!$player) {
                // Optionally log or collect missing names
                continue;
            }

            PlayerRanking::create([
                'player_id' => $player->id,
                'user_id'   => Auth::id(),
                'type'      => $request->ranking_type,
                'rank_1'    => $rank1,
                'rank_2'    => $rank2,
                'rank_3'    => $rank3,
            ]);
        }

        session()->flash('success', 'Rankings uploaded successfully.');

        return Redirect::route('player.rankings.index');
    }



    public function storeManual(Request $request)
    {
        $request->validate([
            'player_id' => 'required|exists:players,id',
            'ranking_type' => 'required|string',
            'rank_1' => 'nullable|string',
            'rank_2' => 'nullable|string',
            'rank_3' => 'nullable|string',
        ]);

        Ranking::updateOrCreate(
            [
                'user_id' => auth()->id(),
                'player_id' => $request->player_id,
                'type' => $request->ranking_type,
            ],
            [
                'rank_1' => $request->rank_1,
                'rank_2' => $request->rank_2,
                'rank_3' => $request->rank_3,
            ]
        );

        return back()->with('success', 'Ranking updated.');
    }



    public function findOrCreatePlayerByName($input)
    {
        $input = trim($input);

        // Split into first and last (assumes first word = first name, rest = last name)
        $parts = preg_split('/\s+/', $input, 2);
        $first = $parts[0] ?? '';
        $last  = $parts[1] ?? '';

        // Normalize for fuzzy matching
        $normalizedInput = Str::slug(strtolower($first . ' ' . $last));

        // Search existing players
        $player = Player::all()->first(function ($player) use ($normalizedInput) {
            $existing = Str::slug(strtolower($player->first_name . ' ' . $player->last_name));
            return $existing === $normalizedInput;
        });

        // If not found, create new
        return $player ?? Player::create([
            'first_name' => $first,
            'last_name'  => $last,
            'full_name'  => $input, // if you store full name too
        ]);
    }
    

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(PlayerRanking $playerRanking)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(PlayerRanking $playerRanking)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, PlayerRanking $playerRanking)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(PlayerRanking $playerRanking)
    {
        //
    }
}
