<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\NhlGamePredictionPayload;
use App\Services\NhlGamePredictionResponseCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Returns projected NHL game predictions for partner ingestion.
 */
class NhlGamePredictionsController extends Controller
{
    public function __invoke(Request $request, NhlGamePredictionPayload $payload, NhlGamePredictionResponseCache $cache): JsonResponse
    {
        $input = $request->validate([
            'nhl_game_id' => ['required', 'integer'],
            'force_refresh' => ['sometimes', 'boolean'],
            'source_season_id' => ['nullable', 'digits:8'],
            'target_season_id' => ['nullable', 'digits:8'],
            'projection_version' => ['nullable', 'string', 'max:80'],
            'toi_projection_version' => ['nullable', 'string', 'max:80'],
            'goalie_projection_version' => ['nullable', 'string', 'max:80'],
            'away_goalie_id' => ['nullable', 'integer'],
            'home_goalie_id' => ['nullable', 'integer'],
            'markets' => ['nullable', 'array'],
            'markets.*' => ['string', 'in:moneyline,puckline,total'],
            'puckline' => ['nullable', 'numeric', 'not_in:0'],
            'puckline_spreads' => ['nullable', 'array'],
            'puckline_spreads.*' => ['numeric', 'not_in:0'],
            'total' => ['nullable', 'numeric', 'gt:0'],
            'total_lines' => ['nullable', 'array'],
            'total_lines.*' => ['numeric', 'gt:0'],
        ]);

        $forceRefresh = (bool) ($input['force_refresh'] ?? false);
        unset($input['force_refresh']);

        return response()->json($cache->respond((int) $input['nhl_game_id'], $input,
            fn (): array => $payload->build((int) $input['nhl_game_id'], $input), $forceRefresh), 200, [], JSON_PRESERVE_ZERO_FRACTION);
    }
}
