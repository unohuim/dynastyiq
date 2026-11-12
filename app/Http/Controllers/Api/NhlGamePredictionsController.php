<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NhlGame;
use App\Services\NhlGamePredictionHistory;
use App\Services\NhlGamePredictionPayload;
use App\Services\NhlGamePredictionResponseCache;
use App\Services\NhlPublicGamePage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Returns projected NHL game predictions for partner ingestion.
 */
class NhlGamePredictionsController extends Controller
{
    /** Return completed predictions with their frozen Matchup; evidence-only responses retain descriptive form. */
    public function __invoke(
        Request $request,
        NhlGamePredictionPayload $payload,
        NhlGamePredictionResponseCache $cache,
        NhlPublicGamePage $pages,
        NhlGamePredictionHistory $history,
    ): JsonResponse {
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

        $game = NhlGame::query()->findOrFail((int) $input['nhl_game_id']);
        $prediction = $history->latestSaved((int) $input['nhl_game_id']);
        // Return saved publication before consulting cache age, changed inputs or refresh requests.
        if ($prediction === null) {
            if ($game->game_date->toDateString() < now('America/Toronto')->toDateString()) {
                $prediction = [
                    'prediction_available' => false,
                    'pick_qualified' => false,
                    'reason' => 'historical_prediction_not_found',
                    'game' => ['nhl_game_id' => (int) $input['nhl_game_id']],
                    'prediction' => null,
                    'market_probabilities' => [],
                    'reasons' => [],
                ];
            } else {
                $prediction = $cache->respond((int) $input['nhl_game_id'], $input,
                    fn (): array => $payload->build((int) $input['nhl_game_id'], $input), $forceRefresh);
                if (! ($prediction['prediction_available'] ?? false)) {
                    $prediction['matchup'] = $pages->matchup($game);
                }
            }
        }

        // Presentation-only copy: cached revisions and admin diagnostics keep their internal evidence.
        if (is_array($prediction['prediction'] ?? null)) {
            $publicConfidence = $prediction['prediction']['public_confidence_score'] ?? null;
            $prediction['prediction']['confidence_score'] = $publicConfidence;
            unset($prediction['prediction']['confidence_inputs']);
            foreach (array_keys($prediction['market_probabilities'] ?? []) as $index) {
                $prediction['market_probabilities'][$index]['confidence_score'] = $publicConfidence;
            }
        }
        foreach (['away', 'home'] as $side) {
            unset($prediction['teams'][$side]['input_confidence_score']);
        }
        unset($prediction['_internal_confidence'], $prediction['_confidence_components']);

        return response()->json($prediction, 200, [], JSON_PRESERVE_ZERO_FRACTION);
    }
}
