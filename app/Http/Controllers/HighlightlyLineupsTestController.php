<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\NhlGame;
use App\Services\CapWagesLineups;
use App\Services\HighlightlyNhlClient;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use UnexpectedValueException;

/** Display date-scoped games and on-demand lineups from the selected provider. */
class HighlightlyLineupsTestController extends Controller
{
    /** Render the application shell without making a blocking provider request. */
    public function index(Request $request): View
    {
        $input = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'source' => ['sometimes', 'required', 'in:highlightly,capwages'],
        ]);

        return view('lineups-test', ['payload' => [
            'date' => $input['date'] ?? '2026-09-29',
            'source' => $input['source'] ?? 'highlightly',
            'gamesUrl' => route('lineups-test.games'),
            'lineupsUrl' => route('lineups-test.lineups', ['matchId' => '__MATCH_ID__']),
        ]]);
    }

    /** Retrieve every page of NHL games for the selected Toronto calendar date. */
    public function games(Request $request, HighlightlyNhlClient $client): JsonResponse
    {
        $input = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'source' => ['sometimes', 'required', 'in:highlightly,capwages'],
        ]);

        if (($input['source'] ?? 'highlightly') === 'capwages') {
            $games = NhlGame::query()->whereDate('game_date', $input['date'])
                ->orderBy('start_time_utc')->orderBy('nhl_game_id')->get()
                ->map(static function (NhlGame $game): array {
                    $teams = [];

                    foreach (['away', 'home'] as $side) {
                        $teams[$side.'Team'] = [
                            'abbreviation' => $game->{$side.'_team_abbrev'},
                            'displayName' => trim(($game->{$side.'_team_place_name'} ?? '').' '.
                                ($game->{$side.'_team_common_name'} ?? '')) ?: $game->{$side.'_team_abbrev'},
                        ];
                    }

                    return array_merge($teams, [
                        'id' => (int) $game->nhl_game_id,
                        'date' => $game->start_time_utc?->toIso8601String(),
                        'state' => ['description' => $game->game_state],
                    ]);
                });

            return response()->json(['date' => $input['date'], 'games' => $games]);
        }

        try {
            $games = [];
            $offset = 0;

            do {
                $payload = $client->matches([
                    'league' => 'NHL',
                    'date' => $input['date'],
                    'timezone' => 'America/Toronto',
                    'limit' => 100,
                    'offset' => $offset,
                ])->json();

                if (! is_array($payload['data'] ?? null)
                    || ! is_numeric($payload['pagination']['totalCount'] ?? null)) {
                    throw new UnexpectedValueException('Missing Highlightly game pagination.');
                }

                foreach ($payload['data'] as $game) {
                    if (! is_array($game) || ! is_numeric($game['id'] ?? null) || $game['id'] <= 0) {
                        throw new UnexpectedValueException('Missing Highlightly game identity.');
                    }

                    $games[(int) $game['id']] = $game;
                }

                $offset += 100;
                $total = (int) $payload['pagination']['totalCount'];

                // Bound a single browser request if the provider reports unexpected pagination.
                if ($total > 1000) {
                    throw new UnexpectedValueException('Unexpected Highlightly daily game count.');
                }
            } while ($offset < $total);

            return response()->json(['date' => $input['date'], 'games' => array_values($games)]);
        } catch (ConnectionException | RequestException | LogicException | UnexpectedValueException $exception) {
            return response()->json(['message' => 'Unable to load Highlightly games. Please try again.'], 503);
        }
    }

    /** Retrieve both sides only when a game is selected, without persisting evidence. */
    public function lineups(
        Request $request,
        int $matchId,
        HighlightlyNhlClient $client,
        CapWagesLineups $capWages
    ): JsonResponse {
        $input = $request->validate(['source' => ['sometimes', 'required', 'in:highlightly,capwages']]);
        abort_if($matchId <= 0, 422, 'Select a valid game.');

        if (($input['source'] ?? 'highlightly') === 'capwages') {
            return response()->json($capWages->forGame(NhlGame::query()->findOrFail($matchId)));
        }

        try {
            $payload = $client->lineups($matchId)->json();

            foreach (['away', 'home'] as $side) {
                if (isset($payload[$side]) && (! is_array($payload[$side])
                    || ! is_array($payload[$side]['lineup'] ?? null))) {
                    throw new UnexpectedValueException('Invalid Highlightly team lineup.');
                }

                foreach ($payload[$side]['lineup'] ?? [] as $player) {
                    if (! is_array($player)) {
                        throw new UnexpectedValueException('Invalid Highlightly player.');
                    }
                }
            }

            return response()->json([
                'matchId' => $matchId,
                'away' => $payload['away'] ?? null,
                'home' => $payload['home'] ?? null,
            ]);
        } catch (ConnectionException | RequestException | LogicException | UnexpectedValueException $exception) {
            return response()->json(['message' => 'Unable to load Highlightly lineups. Please try again.'], 503);
        }
    }
}
