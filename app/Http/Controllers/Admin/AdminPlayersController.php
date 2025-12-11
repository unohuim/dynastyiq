<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Player;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminPlayersController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = 25;
        $page    = max((int) $request->query('page', 1), 1);

        $query = Player::query();

        if ($filter = trim($request->input('filter', ''))) {
            $clean = mb_strtolower($filter);
            $query->whereRaw("LOWER(full_name) LIKE ?", ["%{$clean}%"]);
        }

        $players = $query
            ->orderBy('full_name')
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data'         => $players->getCollection()
                ->map(static function (Player $player): array {
                    return [
                        'id'          => $player->id,
                        'full_name'   => $player->full_name,
                        'age'         => $player->age,
                        'position'    => $player->position,
                        'team_abbrev' => $player->team_abbrev,
                    ];
                })
                ->values(),
            'current_page' => $players->currentPage(),
            'per_page'     => $players->perPage(),
            'total'        => $players->total(),
        ]);
    }
}
