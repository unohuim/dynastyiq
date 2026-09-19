<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\NhlAvailabilityPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class NhlAvailabilityController extends Controller
{
    public function injuries(NhlAvailabilityPayload $payload): View
    {
        return view('injuries.index', $payload->injuries());
    }

    public function injuriesPayload(Request $request, NhlAvailabilityPayload $payload): JsonResponse
    {
        return response()->json($payload->injuries($request->string('team_abbrev')->toString() ?: null));
    }

    public function goalies(Request $request, NhlAvailabilityPayload $payload): View
    {
        $input = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $date = isset($input['date']) ? Carbon::createFromFormat('Y-m-d', $input['date']) : today();
        return view('starting-goalies.index', $payload->goalies($date));
    }

    public function goaliesPayload(Request $request, NhlAvailabilityPayload $payload): JsonResponse
    {
        $input = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $date = isset($input['date']) ? Carbon::createFromFormat('Y-m-d', $input['date']) : today();
        return response()->json($payload->goalies($date));
    }
}
