<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Player;
use Illuminate\Http\Request;

class AdminPlayersController extends Controller
{
    public function index(Request $request)
    {
        $perPage = 25;
        $page = $request->query('page');
        $filter = $request->query('filter');

        $players = Player::query()
            ->when($filter, function ($query) use ($filter) {
                $query->where('full_name', 'LIKE', "%{$filter}%")
                    ->orWhere('first_name', 'LIKE', "%{$filter}%")
                    ->orWhere('last_name', 'LIKE', "%{$filter}%");
            })
            ->paginate($perPage, ['*'], 'page', $page);

        $data = $players->getCollection()
            ->map(fn ($player) => [
                'id' => $player->id,
                'full_name' => $player->full_name,
                'position' => $player->position,
                'team_abbrev' => $player->team_abbrev,
            ])
            ->values();

        return response()->json([
            'data' => $data,
            'current_page' => $players->currentPage(),
            'per_page' => $players->perPage(),
            'total' => $players->total(),
        ]);
    }
}
