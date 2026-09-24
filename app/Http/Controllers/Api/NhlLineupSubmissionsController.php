<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiClient;
use App\Models\NhlCurrentLineup;
use App\Models\NhlGame;
use App\Services\NhlAnticipatedLineupImporter;
use App\Services\NhlAnticipatedLineupPayload;
use App\Services\NhlLineupImageOcr;
use App\Services\NhlStartingGoalieSelector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Accept explicitly authorized partner lineup evidence through the manual validator. */
class NhlLineupSubmissionsController extends Controller
{
    /** Validate uploads before OCR, then atomically validate and promote the submitted roster. */
    public function __invoke(
        Request $request,
        NhlAnticipatedLineupImporter $importer,
        NhlAnticipatedLineupPayload $payload,
        NhlLineupImageOcr $ocr,
        NhlStartingGoalieSelector $goalies
    ): JsonResponse {
        $client = $request->attributes->get('api_client');
        abort_unless($client instanceof ApiClient && $client->hasScope('nhl-lineups:write'), 403);
        $image = null;
        $text = '';
        try {
            $input = $request->validate([
                'nhl_game_id' => ['required', 'integer'],
                'team_abbrev' => ['required', 'string', 'max:10'],
                'text' => ['nullable', 'required_without:image', 'required_if:image_reviewed,1', 'string', 'max:20000'],
                'image' => ['nullable', 'required_without:text', 'file', 'image', 'mimes:jpg,jpeg,png', 'max:10240'],
                'image_reviewed' => ['sometimes', 'boolean'],
            ]);
            $gameId = (int) $input['nhl_game_id'];
            $team = mb_strtoupper(trim($input['team_abbrev']));
            $text = trim($input['text'] ?? '');
            $game = NhlGame::query()->findOrFail($gameId);
            $this->validateTeam($game, $team);
            // Reuse the existing bounded uploader; never fetch arbitrary image URLs.
            $image = $request->hasFile('image') ? $ocr->extractUpload($request->file('image')) : null;
            if ($image !== null) {
                $image['reviewed'] = $request->boolean('image_reviewed');
            }

            return DB::transaction(function () use ($gameId, $team, $text, $client, $image, $importer, $payload, $goalies): JsonResponse {
                $game = NhlGame::query()->where('nhl_game_id', $gameId)->lockForUpdate()->firstOrFail();
                $teamId = $this->validateTeam($game, $team);
                $importer->importManual($game, $team, $teamId, $text, null, $image, $client);
                $current = NhlCurrentLineup::query()->where('nhl_game_id', $gameId)->where('team_abbrev', $team)->firstOrFail();

                return response()->json([
                    'success' => true, 'message' => 'Lineup accepted as the current manual override.',
                    'submission_id' => $current->nhl_lineup_observation_id,
                    'nhl_game_id' => $gameId, 'team_abbrev' => $team,
                    'lineup' => $payload->forGameTeam($gameId, $team, false),
                    'starting_goalie' => $goalies->select($gameId, $team),
                ], 201);
            });
        } catch (ValidationException $exception) {
            return response()->json([
                'success' => false, 'message' => $exception->getMessage(), 'errors' => $exception->errors(),
                'interpreted_text' => $image !== null ? $ocr->reviewText($image) : $text,
            ], 422);
        }
    }

    /** Resolve and validate the exact game/team before accepting evidence. */
    private function validateTeam(NhlGame $game, string $team): int
    {
        $teamId = DB::table('nhl_teams')->where('abbrev', $team)->value('nhl_id');
        if (! in_array($team, [$game->home_team_abbrev, $game->away_team_abbrev], true) || $teamId === null) {
            throw ValidationException::withMessages(['team_abbrev' => 'Select a known NHL team participating in this game.']);
        }

        return (int) $teamId;
    }
}
