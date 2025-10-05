<?php

namespace App\Services;

use App\Models\NhlBoxscore;
use App\Models\NhlGameSummary;
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


            $this->processShutout($nhlGameId, $stats[$teamSide]['goalies']);


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



    private function processShutout(int|string $gameId, array $goalies): void
    {
        if (empty($goalies)) {
            return;
        }

        $hasStarterFlag = array_reduce($goalies, static function ($carry, $g) {
            return $carry || array_key_exists('starter', $g);
        }, false);

        // If no starter flag present: detect a solo goalie with TOI > 0 and GA = 0
        $soloGoalieId = null;
        if (!$hasStarterFlag) {
            $activeWithToi = array_values(array_filter($goalies, function ($g) {
                $toi = $g['toi'] ?? '00:00';
                return $this->toiToSeconds($toi) > 0;
            }));

            if (count($activeWithToi) === 1 && (int)($activeWithToi[0]['goalsAgainst'] ?? 0) === 0) {
                $soloGoalieId = $activeWithToi[0]['playerId'] ?? null;
            }
        }

        foreach ($goalies as $g) {
            $so = 0;
            $gkId = $g['playerId'] ?? null;

            if ($gkId === null) {
                continue;
            }

            $ga = (int)($g['goalsAgainst'] ?? 0);
            $isStarterTrue = (bool)($g['starter'] ?? false);

            if ($hasStarterFlag) {
                if ($ga === 0 && $isStarterTrue && $this->isShutout($goalies)) {
                    $so = 1;
                }
            } else {
                // No starter flag: only award SO if this is the lone goalie with TOI > 0 and GA = 0
                if ($soloGoalieId !== null && $gkId === $soloGoalieId && $this->isShutout($goalies)) {
                    $so = 1;
                }
            }

            NhlGameSummary::where('nhl_game_id', (int)$gameId)
                ->where('nhl_player_id', $gkId)
                ->update(['so' => $so]);
        }
    }

    private function isShutout(array $goalies): bool
    {
        // Team-level check:
        // 1) No goalie who actually played (TOI > 0) allowed any goals.
        // 2) If no 'starter' flags exist, ensure only ONE goalie has TOI > 0 (no split shutout credit).

        $hasStarterFlag = array_reduce($goalies, static function ($carry, $g) {
            return $carry || array_key_exists('starter', $g);
        }, false);

        $activeGoalies = 0;

        foreach ($goalies as $g) {
            $toi = $g['toi'] ?? '00:00';
            $played = $this->toiToSeconds($toi) > 0;
            $ga = (int)($g['goalsAgainst'] ?? 0);

            if ($played) {
                $activeGoalies++;
                if ($ga > 0) {
                    return false;
                }
            }

            if ($hasStarterFlag) {
                // If starter exists and he allowed a goal, no shutout.
                if ((bool)($g['starter'] ?? false) && $ga > 0) {
                    return false;
                }
                // If a non-starter logged TOI, that's fine as long as GA == 0 (e.g., relief with no goals).
            }
        }

        if (!$hasStarterFlag) {
            // Without starter flags, require exactly one goalie to have TOI > 0 (no split shutout credit).
            if ($activeGoalies !== 1) {
                return false;
            }
        }

        return true;
    }

    private function toiToSeconds(?string $toi): int
    {
        if (empty($toi) || strpos($toi, ':') === false) {
            return 0;
        }

        [$m, $s] = array_map('intval', explode(':', $toi, 2));
        return ($m * 60) + $s;
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
