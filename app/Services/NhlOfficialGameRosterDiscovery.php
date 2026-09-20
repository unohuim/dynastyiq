<?php

declare(strict_types=1);

namespace App\Services;

use App\Traits\HasAPITrait;
use Throwable;

/** Reads an official game roster from the NHL gamecenter boxscore when it is available. */
class NhlOfficialGameRosterDiscovery
{
    use HasAPITrait;

    /** @return array<string,mixed>|null */
    public function discover(object $game, string $teamAbbrev): ?array
    {
        try {
            $response = $this->getAPIData('nhl', 'boxscore', ['gameId' => $game->nhl_game_id]);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        $side = mb_strtoupper((string) data_get($response, 'awayTeam.abbrev')) === $teamAbbrev
            ? 'awayTeam'
            : (mb_strtoupper((string) data_get($response, 'homeTeam.abbrev')) === $teamAbbrev
                ? 'homeTeam'
                : null);
        if ($side === null) {
            return null;
        }

        $forwards = array_values(array_filter((array) data_get(
            $response,
            "playerByGameStats.{$side}.forwards",
            []
        )));
        $defense = array_values(array_filter((array) data_get(
            $response,
            "playerByGameStats.{$side}.defense",
            []
        )));
        if (count($forwards) < 12 || count($defense) < 6) {
            return null;
        }

        $players = [];
        foreach (array_slice($forwards, 0, 12) as $index => $player) {
            $players[] = $this->player(
                $player,
                'forward',
                'F' . ((int) floor($index / 3) + 1),
                ($index % 3) + 1
            );
        }
        foreach (array_slice($defense, 0, 6) as $index => $player) {
            $players[] = $this->player(
                $player,
                'defense',
                'D' . ((int) floor($index / 2) + 1),
                ($index % 2) + 1
            );
        }

        $goalies = collect(data_get($response, "playerByGameStats.{$side}.goalies", []));
        $starter = $goalies->first(fn (mixed $goalie): bool => is_array($goalie) && ($goalie['starter'] ?? false) === true);
        if (is_array($starter)) {
            $players[] = $this->player($starter, 'goalie', 'G', 1);
            $backup = $goalies->first(fn (mixed $goalie): bool => is_array($goalie)
                && (int) ($goalie['playerId'] ?? 0) !== (int) ($starter['playerId'] ?? 0));
            if (is_array($backup)) {
                $players[] = $this->player($backup, 'goalie', 'G', 2);
            }
        }

        if (collect($players)->take(18)->pluck('nhl_player_id')->filter()->unique()->count() !== 18) {
            return null;
        }

        $rosterKey = hash('sha256', collect($players)->pluck('nhl_player_id')->implode(','));
        $url = rtrim((string) config('apiurls.nhl.base'), '/')
            . '/gamecenter/' . $game->nhl_game_id . '/boxscore'
            . '#' . mb_strtolower($teamAbbrev) . '-' . $rosterKey;

        return [
            'platform' => 'nhl',
            'source_name' => 'NHL Gamecenter',
            'source_handle' => 'nhl-api',
            'source_url' => 'https://www.nhl.com',
            'followers' => null,
            'following' => null,
            'post_count' => null,
            'post_url' => $url,
            'post_text' => "Official NHL game roster for {$teamAbbrev} in game {$game->nhl_game_id}",
            'published_at' => null,
            'engagement' => ['likes' => null, 'replies' => null, 'reposts' => null, 'views' => null],
            'players' => $players,
            'official' => true,
            'raw_boxscore_team' => data_get($response, "playerByGameStats.{$side}"),
        ];
    }

    /** @param array<string,mixed> $player @return array<string,mixed> */
    private function player(array $player, string $role, string $lineKey, int $slotIndex): array
    {
        $nhlPlayerId = isset($player['playerId']) ? (int) $player['playerId'] : null;
        $name = trim((string) data_get($player, 'name.default', ''));

        return [
            'name' => $name !== '' ? $name : 'NHL Player ' . $nhlPlayerId,
            'nhl_player_id' => $nhlPlayerId,
            'lineup_role' => $role,
            'line_key' => $lineKey,
            'slot_index' => $slotIndex,
            'power_play_unit' => null,
            'penalty_kill_unit' => null,
        ];
    }
}
