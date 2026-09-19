<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\NhlAnticipatedLineupPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Returns current anticipated NHL lineup projections to API clients. */
class NhlAnticipatedLineupsController extends Controller
{
    public function __invoke(Request $request, NhlAnticipatedLineupPayload $payload): JsonResponse
    {
        $input = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'nhl_game_id' => ['nullable', 'integer', 'exists:nhl_games,nhl_game_id'],
        ]);
        if (isset($input['date'], $input['nhl_game_id'])) {
            throw ValidationException::withMessages(['date' => 'Date and nhl_game_id are mutually exclusive.']);
        }
        $gameId = isset($input['nhl_game_id']) ? (int) $input['nhl_game_id'] : null;
        $date = isset($input['date'])
            ? Carbon::createFromFormat('Y-m-d', $input['date'])
            : Carbon::now('America/Toronto')->startOfDay();
        if ($gameId !== null) {
            $date = Carbon::parse(DB::table('nhl_games')->where('nhl_game_id', $gameId)->value('game_date'));
        }

        return response()->json($payload->build($date, $gameId));
    }
}
