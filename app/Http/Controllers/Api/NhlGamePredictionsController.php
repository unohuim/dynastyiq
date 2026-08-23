<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\NhlGamePredictionPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Returns projected NHL game predictions for partner ingestion.
 */
class NhlGamePredictionsController extends Controller
{
    public function __invoke(Request $request, NhlGamePredictionPayload $payload): JsonResponse
    {
        $input = $request->validate([
            'nhl_game_id' => ['required', 'integer'],
            'source_season_id' => ['nullable', 'digits:8'],
            'target_season_id' => ['nullable', 'digits:8'],
            'projection_version' => ['nullable', 'string', 'max:80'],
            'toi_projection_version' => ['nullable', 'string', 'max:80'],
            'goalie_projection_version' => ['nullable', 'string', 'max:80'],
            'away_goalie_id' => ['nullable', 'integer'],
            'home_goalie_id' => ['nullable', 'integer'],
        ]);

        return response()->json($payload->build(
            (int) $input['nhl_game_id'],
            $input
        ), 200, [], JSON_PRESERVE_ZERO_FRACTION);
    }
}
