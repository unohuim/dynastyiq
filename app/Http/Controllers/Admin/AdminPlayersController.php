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
        $players = Player::query()
            ->select(['id', 'full_name', 'position', 'team_abbrev', 'dob'])
            ->when($request->filter, function ($q, $term) {
                $term = strtolower($term);
                $q->whereRaw('LOWER(full_name) LIKE ?', ["%{$term}%"]);
            })
            ->orderBy('full_name')
            ->paginate(50);
    
        $players->getCollection()->transform(function ($p) {
            $p->age = $p->dob ? \Carbon\Carbon::parse($p->dob)->age : null;
            return $p;
        });
    
        return response()->json([
            'data' => $players->items(),
            'meta' => [
                'total' => $players->total(),
                'current_page' => $players->currentPage(),
                'last_page' => $players->lastPage(),
            ],
        ]);
    }
}
