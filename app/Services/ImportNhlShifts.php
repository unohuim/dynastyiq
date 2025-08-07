<?php

namespace App\Services;

use App\Models\NhlShift;
use App\Traits\HasAPITrait;
use App\Models\NhlGameSummary;
use App\Models\Player;
use App\Classes\ImportNHLPlayer;
use App\Models\NhlGame;


class ImportNhlShifts
{
    use HasAPITrait;

    /**
     * Import raw shift data from NHL API and store into nhl_shifts table,
     * including calculated fields for shift start/end seconds and duration seconds.
     *
     * @param string $nhlGameId
     * @return void
     */
    public function import(string $nhlGameId): void
    {
        // Fetch shifts from the special base URL
        $response = $this->getAPIDataFullUrl(
            "https://api.nhle.com/stats/rest/en/shiftcharts?cayenneExp=gameId={$nhlGameId}"
        );

        if (empty($response['data'])) {
            return;
        }

        $shiftsData = $response['data'];

        foreach ($shiftsData as $shift) {
            $playerId = $shift['playerId'] ?? null;

            if (!$playerId) {
                continue;
            }

            // Calculate elapsed seconds from period and string times
            $shiftStartSeconds = parseElapsedSeconds($shift['startTime'] ?? null, $shift['period'] ?? 1);
            $shiftEndSeconds = parseElapsedSeconds($shift['endTime'] ?? null, $shift['period'] ?? 1);
            $durationSeconds = parseElapsedSeconds($shift['duration'] ?? null);


            NhlShift::updateOrCreate(
                [
                    'nhl_game_id' => $nhlGameId,
                    'nhl_player_id' => $playerId,
                    'shift_start_seconds' => $shiftStartSeconds,
                    'shift_number' => $shift['shiftNumber'] ?? 0,
                ],
                [
                    'start_time' => $shift['startTime'] ?? null,
                    'end_time' => $shift['endTime'] ?? null,
                    'duration' => $shift['duration'] ?? null,
                    'period' => $shift['period'] ?? 1,
                    'shift_end_seconds' => $shiftEndSeconds,
                    'shift_duration_seconds' => $durationSeconds ?? 0,
                    'pos_type' => null, // to be updated later from player data
                    'position' => null, // to be updated later from player data
                    'team_abbrev' => $shift['teamAbbrev'] ?? null,
                    'team_name' => $shift['teamName'] ?? null,
                    'first_name' => $shift['firstName'] ?? null,
                    'last_name' => $shift['lastName'] ?? null,
                    'detail_code' => $shift['detailCode'] ?? null,
                    'event_description' => $shift['eventDescription'] ?? null,
                    'event_details' => $shift['eventDetails'] ?? null,
                    'event_number' => $shift['eventNumber'] ?? null,
                    'type_code' => $shift['typeCode'] ?? null,
                    'hex_value' => $shift['hexValue'] ?? null,
                    'unit_id' => null, // to be assigned later
                ]
            );            
        }



        // Sum TOI by player for this game
        $toiSums = NhlShift::where('nhl_game_id', $nhlGameId)
            ->selectRaw('nhl_player_id, team_abbrev, SUM(shift_duration_seconds) as total_toi')
            ->groupBy('nhl_player_id', 'team_abbrev')
            ->get();

            
        $nhlGame = NhlGame::find($nhlGameId);

        foreach ($toiSums as $toi) {
            $player = Player::where('nhl_id', $toi->nhl_player_id)->first();

            if (!isset($player)) {
                $playerImport = new ImportNHLPlayer;
                $playerImport->import($toi->nhl_player_id);
                $player = Player::where('nhl_id', $toi->nhl_player_id)->first();
            }

            $teamId = $nhlGame->getTeamIdByAbbrev($toi->team_abbrev);

            NhlGameSummary::updateOrCreate(
                [
                    'nhl_game_id' => $nhlGameId,
                    'nhl_player_id' => $toi->nhl_player_id,
                ],
                [
                    'toi' => $toi->total_toi,
                    'nhl_team_id' => $teamId,
                ]
            );
        }

    }

}
