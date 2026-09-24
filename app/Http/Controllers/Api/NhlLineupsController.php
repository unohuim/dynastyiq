<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\NhlAnticipatedLineupPayload;
use App\Services\NhlStartingGoalieSelector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Read scheduled matchups and their current accepted lineup evidence without importing. */
class NhlLineupsController extends Controller
{
    /** Return both participating teams even when no verified lineup exists. */
    public function __invoke(
        Request $request,
        NhlAnticipatedLineupPayload $payload,
        NhlStartingGoalieSelector $goalies
    ): JsonResponse {
        $input = $request->validate(['date' => ['required', 'date_format:Y-m-d']]);

        return response()->json($payload->schedule(
            Carbon::createFromFormat('!Y-m-d', $input['date'], 'America/Toronto'),
            $goalies
        ));
    }
}
