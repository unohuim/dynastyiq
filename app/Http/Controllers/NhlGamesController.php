<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\NhlAnticipatedLineupPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/** Serves public NHL game index and detail pages with anticipated-lineup context. */
class NhlGamesController extends Controller
{
    /** Display all scheduled NHL games and team lineup statuses for a date. */
    public function index(Request $request, NhlAnticipatedLineupPayload $payload): View
    {
        $input = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $date = isset($input['date'])
            ? Carbon::createFromFormat('Y-m-d', $input['date'])
            : Carbon::now('UTC')->startOfDay();

        return view('games.index', $payload->page($date));
    }

    /** Return scheduled games and current lineup statuses for asynchronous date navigation. */
    public function payload(Request $request, NhlAnticipatedLineupPayload $payload): JsonResponse
    {
        $input = $request->validate(['date' => ['required', 'date_format:Y-m-d']]);
        $date = Carbon::createFromFormat('Y-m-d', $input['date']);

        return response()->json($payload->page($date));
    }

    /** Display the latest current lineup projection for both teams in a game. */
    public function show(int $nhlGameId, NhlAnticipatedLineupPayload $payload): View
    {
        return view('games.show', $payload->gamePage($nhlGameId));
    }
}
