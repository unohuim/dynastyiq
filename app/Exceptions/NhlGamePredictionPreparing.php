<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** A queued dependency is still preparing a game; no new prediction exists yet. */
class NhlGamePredictionPreparing extends HttpException
{
    /** Identify the requested game without claiming a prediction has been calculated. */
    public function __construct(public readonly int $gameId)
    {
        parent::__construct(202, 'Pregame context and prediction inputs are being prepared.', null, ['Retry-After' => '15']);
    }

    /** Keep pending work explicit for API, admin previews and other JSON consumers. */
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'preparing', 'prediction_available' => false,
            'nhl_game_id' => $this->gameId, 'message' => $this->getMessage(), 'retry_after' => 15,
        ], 202, ['Retry-After' => '15']);
    }
}
