<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\NhlAnticipatedLineupPayload;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/** Serves public NHL anticipated-lineup index and game detail pages. */
class NhlLineupsController extends Controller
{
    /** Display all scheduled NHL games and team lineup statuses for a date. */
    public function index(Request $request, NhlAnticipatedLineupPayload $payload): View
    {
        $input = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $date = isset($input['date'])
            ? Carbon::createFromFormat('Y-m-d', $input['date'])
            : Carbon::now('America/Toronto')->startOfDay();

        return view('lineups.index', $payload->page($date));
    }

    /** Display the latest current lineup projection for both teams in a game. */
    public function show(int $nhlGameId, NhlAnticipatedLineupPayload $payload): View
    {
        return view('lineups.show', $payload->gamePage($nhlGameId));
    }
}
