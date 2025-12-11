<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Player;
use Illuminate\Http\JsonResponse;

class AdminPlayersController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'players' => Player::orderBy('full_name')->get([
                'id',
                'full_name',
                'position',
                'team',
                'dob',
            ])->append('age'),
        ]);
    }
}
