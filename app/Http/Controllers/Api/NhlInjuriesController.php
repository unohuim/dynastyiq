<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\NhlAvailabilityPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NhlInjuriesController extends Controller
{
    public function __invoke(Request $request, NhlAvailabilityPayload $payload): JsonResponse
    {
        $input = $request->validate([
            'team_abbrev' => ['nullable', 'string', 'max:10'],
            'nhl_player_id' => ['nullable', 'integer'],
        ]);
        return response()->json($payload->injuries($input['team_abbrev'] ?? null, isset($input['nhl_player_id']) ? (int) $input['nhl_player_id'] : null));
    }
}
