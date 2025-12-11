<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Player;
use Illuminate\Http\JsonResponse;

class AdminPlayersController extends Controller
{
    public function index(): JsonResponse
    {
        $players = Player::orderBy('full_name')->get([
            'id',
            'full_name',
            'position',
            'team',
            'dob',
        ]);

        $players->each->append('age');

        return response()->json([
            'players' => $players,
        ]);
    }
}
