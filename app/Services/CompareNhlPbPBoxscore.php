<?php

namespace App\Services;

use App\Models\NhlBoxscore;
use App\Models\NhlGameSummary;

class CompareNhlPbPBoxscore
{
    /**
     * Compare nhl_boxscores stats against nhl_game_summaries stats for given game_id.
     * Only compares stats present in nhl_boxscores.
     *
     * Assumptions / mappings:
     * - goals => g
     * - assists => a
     * - points => pts
     * - plus_minus => plus_minus
     * - penalty_minutes => pim
     * - toi_seconds => toi (seconds)
     * - sog => sog
     * - hits => h
     * - blocks => b
     * - faceoffs_won => fow
     * - faceoffs_lost => fol
     * - faceoff_win_percentage => fow_percentage
     * - power_play_goals => ppg
     * - power_play_assists => ppa
     * - short_handed_goals => sha
     * - short_handed_assists => pka (assumed as short handed assists, no exact mapping)
     * - giveaways => gv
     * - takeaways => tk
     * - goals_against => ga
     * - saves => sv
     * - shots_against => sa
     * - ev_saves => evsv
     * - ev_shots_against => evsa
     * - pp_saves => ppsv
     * - pp_shots_against => ppsa
     * - pk_saves => pksv
     * - pk_shots_against => pksa
     *
     * Notes:
     * - short_handed_assists mapped to pka (penalty kill assists) as closest guess.
     * - toi in boxscore is string, but we compare toi_seconds (int) with toi in summaries (int seconds).
     *
     * @param int $game_id
     * @return array Comparison results keyed by nhl_player_id, each value is array of mismatched stats with [boxscore, summary]
     */
    public function compare(int $game_id): array
    {
        $results = [];

        $boxscores = NhlBoxscore::where('nhl_game_id', $game_id)->get();

        foreach ($boxscores as $box) {
            $summary = NhlGameSummary::where('nhl_game_id', $game_id)
                ->where('nhl_player_id', $box->nhl_player_id)
                ->first();

            if (!$summary) {
                $results[$box->nhl_player_id] = ['error' => 'No summary record found'];
                continue;
            }

            $mismatches = [];

            $map = [
                'goals' => 'g',
                'assists' => 'a',
                'points' => 'pts',
                //'plus_minus' => 'plus_minus',
                'penalty_minutes' => 'pim',
                'toi_seconds' => 'toi',
                'sog' => 'sog',
                'hits' => 'h',
                'blocks' => 'b',                
                'faceoff_win_percentage' => 'fow_percentage',
                'power_play_goals' => 'ppg',                                
                'giveaways' => 'gv',
                'takeaways' => 'tk',
                'goals_against' => 'ga',
                'saves' => 'sv',
                'shots_against' => 'sa',
                'ev_saves' => 'evsv',
                'ev_shots_against' => 'evsa',
                'pp_saves' => 'ppsv',
                'pp_shots_against' => 'ppsa',
                'pk_saves' => 'pksv',
                'pk_shots_against' => 'pksa',
            ];

            foreach ($map as $boxField => $summaryField) {
                $boxVal = $box->$boxField;
                $sumVal = $summary->$summaryField;

                // Handle nullable toi_seconds/toi
                if ($boxField === 'toi_seconds') {
                    if ($boxVal === null && $sumVal === null) {
                        continue;
                    }
                    if ((int)$boxVal !== (int)$sumVal) {
                        $mismatches[$boxField] = ['boxscore' => $boxVal, 'summary' => $sumVal];
                    }
                    continue;
                }

                // Handle float faceoff_win_percentage comparison with tolerance
                if ($boxField === 'faceoff_win_percentage') {
                    if (abs($boxVal - $sumVal) > 0.1) { // tolerate minor decimal difference
                        $mismatches[$boxField] = ['boxscore' => $boxVal, 'summary' => $sumVal];
                    }
                    continue;
                }

                // Default integer equality check
                if ($boxVal !== $sumVal) {
                    $mismatches[$boxField] = ['boxscore' => $boxVal, 'summary' => $sumVal];
                }
            }

            if (!empty($mismatches)) {
                $results[$box->nhl_player_id] = $mismatches;
            }
        }

        return $results;
    }
}
