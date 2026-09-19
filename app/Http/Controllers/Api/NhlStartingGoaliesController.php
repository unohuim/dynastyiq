<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\NhlAvailabilityPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class NhlStartingGoaliesController extends Controller
{
    public function __invoke(Request $request, NhlAvailabilityPayload $payload): JsonResponse
    {
        $input = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'nhl_game_id' => ['nullable', 'integer', 'exists:nhl_games,nhl_game_id'],
        ]);
        if (isset($input['date'], $input['nhl_game_id'])) {
            throw ValidationException::withMessages(['date' => 'Date and nhl_game_id are mutually exclusive.']);
        }
        $date = isset($input['date']) ? Carbon::createFromFormat('Y-m-d', $input['date']) : today();
        if (isset($input['nhl_game_id'])) {
            $gameDate = \Illuminate\Support\Facades\DB::table('nhl_games')->where('nhl_game_id', $input['nhl_game_id'])->value('game_date');
            $date = Carbon::parse($gameDate);
        }
        return response()->json($payload->goalies($date, isset($input['nhl_game_id']) ? (int) $input['nhl_game_id'] : null));
    }
}
