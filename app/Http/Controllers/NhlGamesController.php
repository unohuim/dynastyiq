<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\AdminImportSchedules;
use App\Services\NhlAnticipatedLineupPayload;
use App\Services\NhlAnticipatedLineupImporter;
use App\Services\NhlLineupImageOcr;
use App\Models\NhlGame;
use App\Models\ImportRun;
use App\Jobs\ImportNhlAnticipatedLineupTeamJob;
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

    /** List canonical team goalies for the super-admin starter picker. */
    public function goalieOptions(Request $request, int $nhlGameId, \App\Services\NhlStartingGoalieSelector $selector): JsonResponse
    {
        abort_unless($this->canManageGameSync($request), 403);
        $input = $request->validate(['team_abbrev' => ['required', 'string', 'max:10']]);
        $team = mb_strtoupper(trim($input['team_abbrev']));
        $game = NhlGame::query()->findOrFail($nhlGameId);
        abort_unless(in_array($team, [$game->home_team_abbrev, $game->away_team_abbrev], true), 422, 'Team is not playing in this game.');

        return response()->json(['goalies' => $selector->teamGoalies($team)->map(fn ($player): array => [
            'player_id' => $player->id, 'nhl_player_id' => $player->nhl_id === null ? null : (int) $player->nhl_id, 'name' => $player->full_name ?: 'Player #' . $player->id,
            'avatar_url' => $player->head_shot_url, 'league' => $player->current_league_abbrev,
        ])]);
    }

    /** Append a game-specific manual starter decision without changing lineup history. */
    public function storeGoalie(Request $request, int $nhlGameId, \App\Services\NhlStartingGoalieSelector $selector): JsonResponse
    {
        abort_unless($this->canManageGameSync($request), 403);
        $input = $request->validate([
            'team_abbrev' => ['required', 'string', 'max:10'], 'player_id' => ['required', 'integer', 'min:1'],
        ]);
        $team = mb_strtoupper(trim($input['team_abbrev']));

        return DB::transaction(function () use ($request, $nhlGameId, $selector, $input, $team): JsonResponse {
            $game = NhlGame::query()->where('nhl_game_id', $nhlGameId)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($team, [$game->home_team_abbrev, $game->away_team_abbrev], true), 422, 'Team is not playing in this game.');
            $player = $selector->teamGoalies($team)->firstWhere('id', (int) $input['player_id']);
            abort_if($player === null, 422, 'Select a goalie belonging to this team.');
            $isHome = $team === $game->home_team_abbrev;
            \App\Models\NhlStartingGoalieObservation::query()->create([
                'nhl_game_id' => $nhlGameId, 'game_date' => $game->game_date,
                'team_abbrev' => $team, 'opponent_abbrev' => $isHome ? $game->away_team_abbrev : $game->home_team_abbrev,
                'is_home' => $isHome, 'player_id' => $player->id, 'nhl_player_id' => $player->nhl_id,
                'player_name' => $player->full_name ?: 'Player #' . $player->id, 'provider' => 'manual', 'status' => 'expected',
                'provider_published_at' => now(), 'fetched_at' => now(),
                'source_url' => route('games.show', $nhlGameId),
                'raw_evidence' => ['manual_starter_override' => true, 'submitted_by_user_id' => $request->user()->id],
            ]);

            return response()->json([
                'nhl_game_id' => $nhlGameId, 'team_abbrev' => $team,
                'starting_goalie' => $selector->select($nhlGameId, $team),
            ]);
        });
    }

    /** Queue one missing game/team lineup using the existing importer and run ledger. */
    public function refreshLineup(Request $request, int $nhlGameId, NhlAnticipatedLineupPayload $payload): JsonResponse
    {
        abort_unless($this->canManageGameSync($request), 403);
        $input = $request->validate(['team_abbrev' => ['required', 'string', 'max:10']]);
        $team = mb_strtoupper(trim($input['team_abbrev']));

        [$run, $created, $teamId] = DB::transaction(function () use ($nhlGameId, $team, $payload): array {
            $game = NhlGame::query()->where('nhl_game_id', $nhlGameId)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($team, [$game->home_team_abbrev, $game->away_team_abbrev], true), 422, 'Team is not playing in this game.');
            $today = Carbon::now('America/Toronto')->startOfDay();
            abort_unless(in_array(Carbon::parse($game->game_date)->toDateString(), [
                $today->toDateString(), $today->copy()->addDay()->toDateString(),
            ], true) && filled($game->start_time_utc), 422, 'Lineup imports support games scheduled today or tomorrow.');
            abort_if($payload->forGameTeam($nhlGameId, $team, false) !== null, 409, 'This team already has a reported lineup.');
            $teamId = DB::table('nhl_teams')->where('abbrev', $team)->value('nhl_id');
            abort_if($teamId === null, 422, 'The NHL team identity is missing.');
            $run = ImportRun::query()->where('source', 'nhl-anticipated-lineups')->where('status', 'working')
                ->where('options->nhl_game_id', $nhlGameId)->where('options->team_abbrev', $team)
                ->where('options->targeted_refresh', true)->latest('id')->first();
            if ($run !== null) {
                return [$run, false, (int) $teamId];
            }
            $run = ImportRun::query()->create([
                'source' => 'nhl-anticipated-lineups', 'status' => 'working',
                'ran_at' => now(), 'started_at' => now(), 'total_records' => 1,
                'progress_label' => 'Team lineup search',
                'options' => ['targeted_refresh' => true, 'nhl_game_id' => $nhlGameId, 'team_abbrev' => $team],
            ]);

            return [$run, true, (int) $teamId];
        });

        if ($created) {
            try {
                ImportNhlAnticipatedLineupTeamJob::dispatch($nhlGameId, $team, $teamId, $run->id);
            } catch (\Throwable $exception) {
                $run->markFailed($exception);
                throw $exception;
            }
        }

        return response()->json([
            'status_url' => route('games.lineup.refresh-status', ['nhlGameId' => $nhlGameId, 'run' => $run->id]),
        ], 202);
    }

    /** Read a targeted attempt's terminal state and the latest verified team lineup. */
    public function refreshLineupStatus(Request $request, int $nhlGameId, ImportRun $run, NhlAnticipatedLineupPayload $payload): JsonResponse
    {
        abort_unless($this->canManageGameSync($request), 403);
        abort_unless($run->source === 'nhl-anticipated-lineups'
            && ($run->options['targeted_refresh'] ?? false)
            && (int) ($run->options['nhl_game_id'] ?? 0) === $nhlGameId, 404);

        return response()->json([
            'status' => $run->status,
            'review' => $run->meta['lineup_review'] ?? ['activity' => 'Queued for lineup search…', 'posts' => []],
            'lineup' => $run->status === 'working' ? null
                : $payload->forGameTeam($nhlGameId, (string) $run->options['team_abbrev'], false),
            'message' => $run->status === 'failed' ? 'The lineup search failed. See the import run in Admin for details.' : null,
        ]);
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
        $ocr = $request->hasFile('image') ? app(NhlLineupImageOcr::class)->extractUpload($request->file('image')) : null;
        if ($ocr !== null) {
            $ocr['reviewed'] = $request->boolean('image_reviewed');
        }

        return DB::transaction(function () use ($request, $nhlGameId, $input, $importer, $payload, $ocr): JsonResponse {
            $game = NhlGame::query()->where('nhl_game_id', $nhlGameId)->lockForUpdate()->firstOrFail();
            $team = mb_strtoupper($input['team_abbrev']);
            abort_unless(in_array($team, [$game->home_team_abbrev, $game->away_team_abbrev], true), 422, 'Team is not playing in this game.');
            $teamId = DB::table('nhl_teams')->where('abbrev', $team)->value('nhl_id');
            abort_if($teamId === null, 422, 'The NHL team identity is missing.');
            $importer->importManual($game, $team, (int) $teamId, trim($input['text'] ?? ''), (int) $request->user()->id, $ocr);

            return response()->json(['lineup' => $payload->forGameTeam($nhlGameId, $team, false)]);
        });
    }
}
