<?php

namespace App\Http\Controllers;

use App\Models\PlayerRanking;
use App\Models\RankingProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Models\Player;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;

class PlayerRankingController extends Controller
{
    public function index()
    {
        $perspectives = "perspectives"; // Placeholder

        return view('player.rankings', [
            'perspectives' => $perspectives,
        ]);
    }

    public function upload(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt',
            'ranking_type' => 'required|string', // Not used here except maybe UI
        ]);

        $csv = $request->file('file');
        $path = $csv->getRealPath();
        $rows = array_map('str_getcsv', file($path));

        if (!empty($rows) && str_contains(strtolower(implode(',', $rows[0])), 'player')) {
            array_shift($rows);
        }

        // Grab the first ranking_profile of the user (no search by name)
        $rankingProfile = RankingProfile::where('author_id', Auth::id())->first();

        if (!$rankingProfile) {
            return back()->withErrors(['ranking_profile' => 'No ranking profile found for user.']);
        }

        foreach ($rows as $row) {
            if (!isset($row[0]) || trim($row[0]) === '') {
                continue;
            }

            $name   = trim($row[0]);
            $score  = $row[1] ?? null;

            $player = $this->findOrCreatePlayerByName($name);

            if (!$player) {
                continue;
            }

            PlayerRanking::updateOrCreate(
                [
                    'ranking_profile_id' => $rankingProfile->id,
                    'player_id' => $player->id,
                ],
                [
                    'score' => $score ?? '',
                    'visibility' => $rankingProfile->visibility,
                    'sport' => $rankingProfile->sport,
                    'description' => null,
                    'settings' => null,
                ]
            );
        }

        session()->flash('success', 'Rankings uploaded successfully.');

        return Redirect::route('player.rankings.index');
    }

    public function storeManual(Request $request)
    {
        $request->validate([
            'player_id' => 'required|exists:players,id',
            'rank_1' => 'nullable|string',
        ]);

        $rankingProfile = RankingProfile::where('author_id', Auth::id())->first();

        if (!$rankingProfile) {
            return back()->withErrors(['ranking_profile' => 'No ranking profile found for user.']);
        }

        PlayerRanking::updateOrCreate(
            [
                'ranking_profile_id' => $rankingProfile->id,
                'player_id' => $request->player_id,
            ],
            [
                'score' => $request->rank_1 ?? '',
                'visibility' => $rankingProfile->visibility,
                'sport' => $rankingProfile->sport,
                'description' => null,
                'settings' => null,
            ]
        );

        return back()->with('success', 'Ranking updated.');
    }

    public function findOrCreatePlayerByName($input)
    {
        $input = trim($input);

        $parts = preg_split('/\s+/', $input, 2);
        $first = $parts[0] ?? '';
        $last  = $parts[1] ?? '';

        $normalizedInput = Str::slug(strtolower($first . ' ' . $last));

        $player = Player::all()->first(function ($player) use ($normalizedInput) {
            $existing = Str::slug(strtolower($player->first_name . ' ' . $player->last_name));
            return $existing === $normalizedInput;
        });

        return $player;
    }

    // Empty resource methods below (unchanged)
    public function create() {}
    public function store(Request $request) {}
    public function show(PlayerRanking $playerRanking) {}
    public function edit(PlayerRanking $playerRanking) {}
    public function update(Request $request, PlayerRanking $playerRanking) {}
    public function destroy(PlayerRanking $playerRanking) {}
}
