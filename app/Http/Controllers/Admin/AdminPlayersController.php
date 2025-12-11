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
        $filter  = trim((string) $request->query('filter', ''));

        $query = Player::query();

        if ($filter !== '') {
            $query->where(function ($q) use ($filter): void {
                $like = '%' . $filter . '%';

                $q->where('full_name', 'LIKE', $like)
                    ->orWhere('first_name', 'LIKE', $like)
                    ->orWhere('last_name', 'LIKE', $like);
            });
        }

        // Ensure stable, predictable ordering
        $query->orderBy('full_name');

        $players = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data'         => $players->getCollection()
                ->map(static function (Player $player): array {
                    return [
                        'id'          => $player->id,
                        'full_name'   => $player->full_name,
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
