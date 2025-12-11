<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Player;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class AdminPlayersController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = max(1, (int) $request->integer('per_page', 25));
        $filter  = trim((string) $request->query('filter', ''));

        $players = Player::query()
            ->select(['id', 'full_name', 'position', 'team_abbrev', 'dob'])
            ->when(
                $filter !== '',
                fn ($query) => $query->where(
                    'full_name',
                    'like',
                    '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filter) . '%'
                )
            )
            ->orderBy('full_name')
            ->paginate($perPage)
            ->through(function (Player $player) {
                $player->append('age');

                return [
                    'id'          => $player->id,
                    'full_name'   => $player->full_name,
                    'position'    => $player->position,
                    'team_abbrev' => $player->team_abbrev,
                    'age'         => $player->age,
                ];
            });

        return response()->json([
            'data' => $players->items(),
            'meta' => [
                'current_page' => $players->currentPage(),
                'last_page'    => $players->lastPage(),
                'per_page'     => $players->perPage(),
                'total'        => $players->total(),
            ],
        ]);
    }
}
