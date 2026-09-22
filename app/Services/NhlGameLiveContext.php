<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NhlGame;
use App\Traits\HasAPITrait;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** Supplies short-lived NHL gamecenter state for today's unprocessed games. */
class NhlGameLiveContext
{
    use HasAPITrait;

    /** Create the provider context reader with canonical goalie selection. */
    public function __construct(private readonly NhlStartingGoalieSelector $goalies)
    {
    }

    /** @return array<string,mixed>|null */
    public function forGame(NhlGame $game): ?array
    {
        try {
            $response = Cache::remember(
                'nhl:gamecenter:boxscore:' . $game->nhl_game_id,
                60,
                fn (): array|string => $this->getAPIData('nhl', 'boxscore', ['gameId' => $game->nhl_game_id])
            );
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        if (! is_array($response)) {
            return null;
        }

        $state = mb_strtoupper((string) ($response['gameState'] ?? ''));
        $showScore = $state !== '' && ! in_array($state, ['FUT', 'PRE'], true);
        if ($showScore) {
            $game->forceFill([
                'game_state' => $state,
                'away_team_score' => data_get($response, 'awayTeam.score', $game->away_team_score),
                'home_team_score' => data_get($response, 'homeTeam.score', $game->home_team_score),
                'away_team_sog' => data_get($response, 'awayTeam.sog', $game->away_team_sog),
                'home_team_sog' => data_get($response, 'homeTeam.sog', $game->home_team_sog),
            ])->save();
        } elseif ($state !== '' && $game->game_state !== $state) {
            $game->forceFill(['game_state' => $state])->save();
        }

        return [
            'state' => $state,
            'label' => match ($state) {
                'FUT' => 'Pregame',
                'PRE' => 'Starting soon',
                'FINAL' => 'Final',
                default => $state === '' ? null : 'Live',
            },
            'show_score' => $showScore,
            'away_score' => $showScore ? data_get($response, 'awayTeam.score') : null,
            'home_score' => $showScore ? data_get($response, 'homeTeam.score') : null,
            'away_goalie' => $this->goalie($response, 'awayTeam', $game, (string) $game->away_team_abbrev),
            'home_goalie' => $this->goalie($response, 'homeTeam', $game, (string) $game->home_team_abbrev),
        ];
    }

    /** @param array<string,mixed> $response @return array<string,mixed>|null */
    private function goalie(array $response, string $side, NhlGame $game, string $teamAbbrev): ?array
    {
        $rows = collect(data_get($response, "playerByGameStats.{$side}.goalies", []));
        $starter = $rows->first(fn (mixed $goalie): bool => is_array($goalie) && ($goalie['starter'] ?? false) === true);
        if (is_array($starter) && isset($starter['playerId'])) {
            $selected = $this->goalies->select(
                (int) $game->nhl_game_id,
                $teamAbbrev,
                providedGoalieId: (int) $starter['playerId']
            );

            return [
                ...($selected ?? []),
                'nhl_player_id' => (int) $starter['playerId'],
                'name' => $selected['name'] ?? data_get($starter, 'name.default') ?? (string) $starter['playerId'],
                'status' => 'confirmed',
                'selection_source' => 'nhl_boxscore',
                'goals_against' => isset($starter['goalsAgainst']) ? (int) $starter['goalsAgainst'] : null,
                'saves' => isset($starter['saves']) ? (int) $starter['saves'] : null,
            ];
        }

        $selected = $this->goalies->select((int) $game->nhl_game_id, $teamAbbrev);
        if ($selected === null) {
            return null;
        }
        $row = $rows->first(fn (mixed $goalie): bool => is_array($goalie)
            && (int) ($goalie['playerId'] ?? 0) === (int) $selected['nhl_player_id']);

        return [
            ...$selected,
            'goals_against' => isset($row['goalsAgainst']) ? (int) $row['goalsAgainst'] : null,
            'saves' => isset($row['saves']) ? (int) $row['saves'] : null,
        ];
    }
}
