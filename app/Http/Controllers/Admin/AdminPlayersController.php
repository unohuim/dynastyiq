<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Player;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class AdminPlayersController extends Controller
{
    public function index(Request $request)
    {
        $perPage = 25;

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
            ->when($request->filter, function ($query, $term) {
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
