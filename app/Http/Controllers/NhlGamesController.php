<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\AdminImportSchedules;
use App\Services\NhlAnticipatedLineupPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/** Serves public NHL game index and detail pages with anticipated-lineup context. */
class NhlGamesController extends Controller
{
    /** Display all scheduled NHL games and team lineup statuses for a date. */
    public function index(
        Request $request,
        NhlAnticipatedLineupPayload $payload,
        AdminImportSchedules $schedules
    ): Response
    {
        $input = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $date = isset($input['date'])
            ? Carbon::createFromFormat('Y-m-d', $input['date'])
            : Carbon::now('UTC')->startOfDay();

        $canManage = $this->canManageGameSync($request);

        return Inertia::render('Games/Index', [
            'initialPayload' => $payload->page($date),
            'payloadUrl' => route('games.payload'),
            'canManageGameSync' => $canManage,
            'gameSyncSchedule' => $canManage ? $schedules->payload(AdminImportSchedules::GAME_BOXSCORES) : null,
            'gameSyncScheduleUrl' => $canManage
                ? route('admin.imports.schedule.update', ['key' => AdminImportSchedules::GAME_BOXSCORES])
                : null,
        ]);
    }

    /** Return scheduled games and current lineup statuses for asynchronous date navigation. */
    public function payload(Request $request, NhlAnticipatedLineupPayload $payload): JsonResponse
    {
        $input = $request->validate(['date' => ['required', 'date_format:Y-m-d']]);
        $date = Carbon::createFromFormat('Y-m-d', $input['date']);

        return response()->json($payload->page($date));
    }

    /** Display the latest current lineup projection for both teams in a game. */
    public function show(int $nhlGameId, NhlAnticipatedLineupPayload $payload): Response
    {
        return Inertia::render('Games/Show', $payload->gamePage($nhlGameId));
    }

    private function canManageGameSync(Request $request): bool
    {
        $user = $request->user();

        return $user !== null && (
            $user->roles()->where('slug', 'super-admin')->exists()
            || $user->roles()->where('level', '>=', 99)->exists()
        );
    }
}
