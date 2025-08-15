<?php

namespace App\Services;

use App\Models\NhlBoxscore;
use App\Traits\HasAPITrait;

class ImportNhlBoxscore
{
    use HasAPITrait;

    /**
     * Import boxscore data for a given NHL game.
     *
     * @param int|string $nhlGameId
     * @return int
     */
    public function import($nhlGameId): int
    {
        $response = $this->getAPIData('nhl', 'boxscore', ['gameId' => $nhlGameId]);

        if (empty($response['playerByGameStats'])) {
            return 0;
        }


        $stats = $response['playerByGameStats'];

        $playerCount = 0;

        foreach (['awayTeam', 'homeTeam'] as $teamSide) {
            if (empty($stats[$teamSide])) {
                continue;
            }

            $teamId = $response[$teamSide]['id'] ?? null;
            

            foreach (['forwards', 'defense', 'goalies'] as $posGroup) {
                if (empty($stats[$teamSide][$posGroup])) {
                    continue;
                }

                foreach ($stats[$teamSide][$posGroup] as $playerData) {
                    // Extract player info & stats
                    $playerId = $playerData['playerId'] ?? null;
                    if (!$playerId) {
                        continue;
                    }

                    // Parse saves/shots strings for goalies only
                    $evSaves = $evShotsAgainst = $ppSaves = $ppShotsAgainst = $pkSaves = $pkShotsAgainst = 0;
                    if ($posGroup === 'goalies') {
                        [$evSaves, $evShotsAgainst] = $this->parseSaveShots($playerData['evenStrengthShotsAgainst'] ?? '0/0');
                        [$ppSaves, $ppShotsAgainst] = $this->parseSaveShots($playerData['powerPlayShotsAgainst'] ?? '0/0');
                        [$pkSaves, $pkShotsAgainst] = $this->parseSaveShots($playerData['shorthandedShotsAgainst'] ?? '0/0');
                    }

                    // Calculate toi_seconds (assumption: parseElapsedSeconds helper exists and accepts toi string)
                    $toiSeconds = null;
                    if (!empty($playerData['toi'])) {
                        [$min, $sec] = explode(':', $playerData['toi']);
                        $toiSeconds = ((int)$min * 60) + (int)$sec;
                    }

                    NhlBoxscore::updateOrCreate(
                        [
                            'nhl_game_id' => $nhlGameId,
                            'nhl_player_id' => $playerId,
                            'nhl_team_id' => $teamId,
                        ],
                        [
                            'sweater_number' => $playerData['sweaterNumber'] ?? 0,
                            'goals' => $playerData['goals'] ?? 0,
                            'assists' => $playerData['assists'] ?? 0,
                            'points' => $playerData['points'] ?? 0,
                            'plus_minus' => $playerData['plusMinus'] ?? 0,
                            'penalty_minutes' => $playerData['pim'] ?? 0,
                            'toi' => $playerData['toi'] ?? null,
                            'toi_seconds' => $toiSeconds,
                            'sog' => $playerData['sog'] ?? 0,
                            'hits' => $playerData['hits'] ?? 0,
                            'blocks' => $playerData['blockedShots'] ?? 0,
                            'faceoffs_won' => $playerData['faceoffWins'] ?? $playerData['faceoffsWon'] ?? 0,
                            'faceoffs_lost' => $playerData['faceoffLosses'] ?? $playerData['faceoffsLost'] ?? 0,
                            // 'faceoff_win_percentage' => (isset($playerData['faceoffWinningPctg']) ? $playerData['faceoffWinningPctg'] / 100 : 0),
                            'faceoff_win_percentage' => $playerData['faceoffWinningPctg'] ??  0,
                            'power_play_goals' => $playerData['powerPlayGoals'] ?? 0,
                            'power_play_assists' => $playerData['powerPlayAssists'] ?? 0,
                            'short_handed_goals' => $playerData['shortHandedGoals'] ?? 0,
                            'short_handed_assists' => $playerData['shortHandedAssists'] ?? 0,
                            'giveaways' => $playerData['giveaways'] ?? 0,
                            'takeaways' => $playerData['takeaways'] ?? 0,
                            'goals_against' => $playerData['goalsAgainst'] ?? 0,
                            'saves' => $playerData['saves'] ?? 0,
                            'shots_against' => $playerData['shotsAgainst'] ?? 0,
                            'ev_saves' => $evSaves,
                            'ev_shots_against' => $evShotsAgainst,
                            'pp_saves' => $ppSaves,
                            'pp_shots_against' => $ppShotsAgainst,
                            'pk_saves' => $pkSaves,
                            'pk_shots_against' => $pkShotsAgainst,
                            'position' => $playerData['position'] ?? null,
                            'player_name' => $playerData['name']['default'] ?? null,
                        ]
                    );
                }
            }

            $playerCount++;
        }

        return $playerCount;
    }

    /**
     * Parse "saves/shots" string format to int array.
     *
     * @param string $stat
     * @return array<int,int>
     */
    private function parseSaveShots(string $stat): array
    {
        $parts = explode('/', $stat);
        return [
            isset($parts[0]) ? (int)$parts[0] : 0,
            isset($parts[1]) ? (int)$parts[1] : 0,
        ];
    }
}
