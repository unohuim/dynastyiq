<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FantraxPlayer;
use App\Models\Player;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class AdminPlayersController extends Controller
{
    public function index(Request $request)
    {
        $perPage = max(5, min((int) $request->integer('per_page', 25), 100));
        $source = strtolower((string) $request->input('source', 'nhl'));

        if ($source === 'fantrax') {
            $search = $request->input('filter', $request->input('search'));
            $matched = $request->boolean('nhl_matched', true);

            $players = FantraxPlayer::query()
                ->leftJoin('players', 'players.id', '=', 'fantrax_players.player_id')
                ->select([
                    'fantrax_players.id',
                    'fantrax_players.name',
                    'fantrax_players.position',
                    'fantrax_players.team',
                    'fantrax_players.player_id',
                    'players.full_name as matched_name',
                    'players.team_abbrev as matched_team',
                    'players.position as matched_position',
                ])
                ->when($matched, function ($query) {
                    $query->whereNotNull('fantrax_players.player_id');
                }, function ($query) {
                    $query->whereNull('fantrax_players.player_id')
                        ->whereNotNull('fantrax_players.team')
                        ->whereNotIn('fantrax_players.team', ['NA', 'N/A', '(N/A)']);
                })
                ->when($search, function ($query, $term) {
                    $term = strtolower($term);

                    $query->whereRaw('LOWER(fantrax_players.name) LIKE ?', ["%{$term}%"]);
                })
                ->orderBy('fantrax_players.name')
                ->paginate($perPage)
                ->through(function (FantraxPlayer $player) {
                    $match = null;

                    if ($player->matched_name) {
                        $match = $player->matched_name;

                        if ($player->matched_team) {
                            $match .= ' ' . $player->matched_team;
                        }

                        if ($player->matched_position) {
                            $match .= ', ' . $player->matched_position;
                        }
                    }

                    return [
                        'id' => $player->id,
                        'name' => $player->name,
                        'position' => $player->position,
                        'team' => $player->team,
                        'match' => $match,
                        'player_id' => $player->player_id,
                    ];
                });
        } else {
            $search     = $request->input('filter', $request->input('search'));
            $allPlayers = $request->boolean('all_players', false);

            $players = Player::query()
                ->select([
                    'id',
                    'full_name',
                    'first_name',
                    'last_name',
                    'position',
                    'team_abbrev',
                    'dob',
                ])
                ->when(!$allPlayers, function ($query) {
                    $query->whereNotNull('team_abbrev')
                        ->whereRaw("TRIM(team_abbrev) <> ''")
                        ->where('team_abbrev', '!=', '—');
                })
                ->when($search, function ($query, $term) {
                    $term = strtolower($term);

                    $query->where(function ($q) use ($term) {
                        $q->whereRaw('LOWER(full_name) LIKE ?', ["%{$term}%"])
                            ->orWhereRaw('LOWER(first_name) LIKE ?', ["%{$term}%"])
                            ->orWhereRaw('LOWER(last_name) LIKE ?', ["%{$term}%"]);
                    });
                })
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->paginate($perPage)
                ->through(function (Player $player) {
                    return [
                        'id' => $player->id,
                        'full_name' => $player->full_name,
                        'first_name' => $player->first_name,
                        'last_name' => $player->last_name,
                        'position' => $player->position,
                        'team_abbrev' => $player->team_abbrev,
                        'dob' => $player->dob ? \Carbon\Carbon::parse($player->dob)->toDateString() : null,
                        'age' => $player->age,
                    ];
                });
        }

        return response()->json([
            'data' => $players->items(),
            'meta' => [
                'total' => $players->total(),
                'current_page' => $players->currentPage(),
                'last_page' => $players->lastPage(),
                'per_page' => $players->perPage(),
            ],
            'links' => [
                'next' => $players->nextPageUrl(),
                'prev' => $players->previousPageUrl(),
            ],
        ]);
    }
}
