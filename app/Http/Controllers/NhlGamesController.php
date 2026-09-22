<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\AdminImportSchedules;
use App\Services\NhlAnticipatedLineupPayload;
use App\Services\NhlAnticipatedLineupImporter;
use App\Services\NhlLineupImageOcr;
use App\Models\NhlGame;
use Illuminate\Support\Facades\DB;
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

    /** Extract an editable manual draft without writing lineup observations. */
    public function previewLineup(Request $request, int $nhlGameId, NhlLineupImageOcr $ocr, NhlAnticipatedLineupPayload $payload): JsonResponse
    {
        abort_unless($this->canManageGameSync($request), 403);
        $input = $request->validate([
            'team_abbrev' => ['required', 'string', 'max:10'],
            'image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png', 'max:10240'],
        ]);
        $game = NhlGame::query()->where('nhl_game_id', $nhlGameId)->firstOrFail();
        $team = mb_strtoupper($input['team_abbrev']);
        abort_unless(in_array($team, [$game->home_team_abbrev, $game->away_team_abbrev], true), 422, 'Team is not playing in this game.');
        abort_if($payload->forGameTeam($nhlGameId, $team, false) !== null, 409, 'This team already has a reported lineup.');
        $evidence = $ocr->extractUpload($request->file('image'));
        if (($evidence['status'] ?? 'error') === 'error') {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'image' => $evidence['reason'] ?? 'Unable to read the image.',
            ]);
        }
        $text = $ocr->reviewText($evidence);
        if (trim($text) === '') {
            throw \Illuminate\Validation\ValidationException::withMessages(['image' => 'No text was found. You can enter the lineup manually.']);
        }

        return response()->json(['text' => $text]);
    }

    /** Process super-admin pasted evidence for a participating team without contacting X. */
    public function storeLineup(
        Request $request,
        int $nhlGameId,
        NhlAnticipatedLineupImporter $importer,
        NhlAnticipatedLineupPayload $payload
    ): JsonResponse {
        abort_unless($this->canManageGameSync($request), 403);
        $input = $request->validate([
            'team_abbrev' => ['required', 'string', 'max:10'],
            'text' => ['nullable', 'required_without:image', 'required_if:image_reviewed,1', 'string', 'max:20000'],
            'image' => ['nullable', 'required_without:text', 'file', 'image', 'mimes:jpg,jpeg,png', 'max:10240'],
            'image_reviewed' => ['sometimes', 'boolean'],
        ]);

        // Reject invalid targets before OCR, and keep CPU work outside the database lock.
        $game = NhlGame::query()->where('nhl_game_id', $nhlGameId)->firstOrFail();
        $team = mb_strtoupper($input['team_abbrev']);
        abort_unless(in_array($team, [$game->home_team_abbrev, $game->away_team_abbrev], true), 422, 'Team is not playing in this game.');
        abort_if($payload->forGameTeam($nhlGameId, $team, false) !== null, 409, 'This team already has a reported lineup.');
        $ocr = $request->hasFile('image') ? app(NhlLineupImageOcr::class)->extractUpload($request->file('image')) : null;
        if ($ocr !== null) {
            $ocr['reviewed'] = $request->boolean('image_reviewed');
        }

        return DB::transaction(function () use ($request, $nhlGameId, $input, $importer, $payload, $ocr): JsonResponse {
            $game = NhlGame::query()->where('nhl_game_id', $nhlGameId)->lockForUpdate()->firstOrFail();
            $team = mb_strtoupper($input['team_abbrev']);
            abort_unless(in_array($team, [$game->home_team_abbrev, $game->away_team_abbrev], true), 422, 'Team is not playing in this game.');
            abort_if($payload->forGameTeam($nhlGameId, $team, false) !== null, 409, 'This team already has a reported lineup.');
            $teamId = DB::table('nhl_teams')->where('abbrev', $team)->value('nhl_id');
            abort_if($teamId === null, 422, 'The NHL team identity is missing.');
            $importer->importManual($game, $team, (int) $teamId, trim($input['text'] ?? ''), (int) $request->user()->id, $ocr);

            return response()->json(['lineup' => $payload->forGameTeam($nhlGameId, $team, false)]);
        });
    }
}
