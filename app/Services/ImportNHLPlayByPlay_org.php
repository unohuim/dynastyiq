<?php

namespace App\Services;

use App\Models\PlayByPlay;
use App\Traits\HasAPITrait;

class ImportNHLPlayByPlay
{
    use HasAPITrait;

    /**
     * Convert MM:SS time string to total seconds.
     */
    protected function timeStringToSeconds(?string $time): ?int
    {
        if (!$time) return null;
        [$minutes, $seconds] = explode(':', $time) + [1 => '0'];
        return ((int)$minutes * 60) + (int)$seconds;
    }

    /**
     * Determine strength (PP/PK/EV) from situation code and event owner team.
     */
    protected function determineStrength(?string $situationCode, ?int $eventOwnerTeamId, ?int $homeTeamId, ?int $awayTeamId): string
    {
        if (!$situationCode || !$eventOwnerTeamId || !$homeTeamId || !$awayTeamId) {
            return 'EV';
        }

        if (strlen($situationCode) !== 4) {
            return 'EV';
        }

        // Determine if eventOwnerTeamId is home or away
        $isHomeTeam = $eventOwnerTeamId === $homeTeamId;

        
        $awayPlayers = (int)$situationCode[0] + (int)$situationCode[1];
        $homePlayers = (int)$situationCode[2] + (int)$situationCode[3];

        $teamPlayers = $isHomeTeam ? $homePlayers : $awayPlayers;
        $oppPlayers = $isHomeTeam ? $awayPlayers : $homePlayers;

        if ($teamPlayers > $oppPlayers) {
            return 'PP';
        }
        if ($teamPlayers < $oppPlayers) {
            return 'PK';
        }
        return 'EV';
    }


    /**
     * Import play-by-play data for given NHL game ID.
     */
    public function import($gameId): void
    {
        $response = $this->getAPIData('nhl', 'pbp', ['gameId' => $gameId]);

        if (empty($response['plays'])) {
            return;
        }

        $periodLengthSeconds = 1200; // 20 minutes per regulation period

        foreach ($response['plays'] as $event) {
            $details = $event['details'] ?? [];

            $timeInPeriod = $event['timeInPeriod'] ?? null;
            $timeRemaining = $event['timeRemaining'] ?? null;
            $period = $event['periodDescriptor']['number'] ?? 1;

            $secondsInPeriod = $this->timeStringToSeconds($timeInPeriod);
            $secondsRemaining = $this->timeStringToSeconds($timeRemaining);

            $secondsInGame = $secondsInPeriod !== null ? $secondsInPeriod + ($period - 1) * $periodLengthSeconds : null;

            $data = [
                // Game-level
                'nhl_game_id' => $gameId,
                'season_id' => $response['season'] ?? null,
                'game_date' => $response['gameDate'] ?? null,
                'game_type' => $response['gameType'] ?? null,
                'away_team_id' => $response['awayTeam']['id'] ?? null,
                'home_team_id' => $response['homeTeam']['id'] ?? null,
                'away_team_abbrev' => $response['awayTeam']['abbrev'] ?? null,
                'home_team_abbrev' => $response['homeTeam']['abbrev'] ?? null,
                'away_score' => $response['awayTeam']['score'] ?? 0,
                'home_score' => $response['homeTeam']['score'] ?? 0,
                'away_sog' => $response['awayTeam']['sog'] ?? 0,
                'home_sog' => $response['homeTeam']['sog'] ?? 0,

                // Event-level
                'nhl_event_id' => $event['eventId'] ?? null,
                'period' => $period,
                'period_type' => $event['periodDescriptor']['periodType'] ?? null,
                'time_in_period' => $timeInPeriod,
                'time_remaining' => $timeRemaining,
                'seconds_in_period' => $secondsInPeriod,
                'seconds_remaining' => $secondsRemaining,
                'seconds_in_game' => $secondsInGame,

                'situation_code' => isset($event['situationCode']) ? (string)$event['situationCode'] : null,
                'type_code' => $event['typeCode'] ?? null,
                'type_desc_key' => $event['typeDescKey'] ?? null,
                'desc_key' => $details['descKey'] ?? null,
                'sort_order' => $event['sortOrder'] ?? null,
                'event_owner_team_id' => $details['eventOwnerTeamId'] ?? null,
                'home_team_defending_side' => $event['homeTeamDefendingSide'] ?? null,

                // Coordinates & zones
                'x_coord' => $details['xCoord'] ?? null,
                'y_coord' => $details['yCoord'] ?? null,
                'zone_code' => $details['zoneCode'] ?? null,

                'scoring_player_id' => $details['scoringPlayerId'] ?? null,
                'scoring_player_total' => $details['scoringPlayerTotal'] ?? 0,
                'assist1_player_id' => $details['assist1PlayerId'] ?? null,
                'assist1_player_total' => $details['assist1PlayerTotal'] ?? 0,
                'assist2_player_id' => $details['assist2PlayerId'] ?? null,
                'assist2_player_total' => $details['assist2PlayerTotal'] ?? 0,

                // Players involved
                'committed_by_player_id' => $details['committedByPlayerId'] ?? null,
                'drawn_by_player_id' => $details['drawnByPlayerId'] ?? null,
                'fo_winning_player_id' => $details['winningPlayerId'] ?? null,
                'fo_losing_player_id' => $details['losingPlayerId'] ?? null,
                'shooting_player_id' => $details['shootingPlayerId'] ?? null,
                'goalie_in_net_player_id' => $details['goalieInNetId'] ?? null,
                'blocking_player_id' => $details['blockingPlayerId'] ?? null,
                'hitting_player_id' => $details['hittingPlayerId'] ?? null,
                'hittee_player_id' => $details['hitteePlayerId'] ?? null,
                'player_id' => $details['playerId'] ?? null,
            

                // Penalty / event specifics
                'duration' => $details['duration'] ?? null,
                'penalty_type_code' => $details['typeCode'] ?? null,
                'strength' => null, // assigned below
                'reason' => $details['reason'] ?? null,
                'secondary_reason' => $details['secondaryReason'] ?? null,
                'shot_type' => $details['shotType'] ?? null,

                // Highlights
                'highlight_clip_sharing_url' => $details['highlightClipSharingUrl'] ?? null,
                'highlight_clip_id' => $details['highlightClip'] ?? null,
            ];

            // Assign strength PP, PK, or EV
            $data['strength'] = $this->determineStrength(
                $data['situation_code'],
                $data['event_owner_team_id'],
                $data['home_team_id'],
                $data['away_team_id'],
            );

            // Clean metadata for unassigned fields
            $excludedKeys = [
                'eventOwnerTeamId', 'winningPlayerId', 'losingPlayerId', 'shootingPlayerId', 'goalieInNetId',
                'blockingPlayerId', 'hittingPlayerId', 'hitteePlayerId', 'playerId', 'scoringPlayerId',
                'scoringPlayerTotal', 'assist1PlayerId', 'assist1PlayerTotal', 'assist2PlayerId', 'assist2PlayerTotal',
                'duration', 'typeCode', 'reason', 'secondaryReason', 'shotType',
                'highlightClipSharingUrl', 'highlightClip', 'xCoord', 'yCoord', 'zoneCode', 'homeZoneCode', 'awayZoneCode',
                'descKey',
            ];

            $metadata = $event;
            unset(
                $metadata['eventId'], $metadata['periodDescriptor'], $metadata['timeInPeriod'], $metadata['timeRemaining'],
                $metadata['situationCode'], $metadata['homeTeamDefendingSide'], $metadata['typeCode'], $metadata['typeDescKey'],
                $metadata['sortOrder'], $metadata['details']
            );

            if (!empty($details)) {
                foreach ($excludedKeys as $key) {
                    unset($details[$key]);
                }
                if (!empty($details)) {
                    $metadata['details_extra'] = $details;
                }
            }

            $data['metadata'] = !empty($metadata) ? json_encode($metadata) : null;

            PlayByPlay::updateOrCreate(
                [
                    'nhl_game_id' => $gameId,
                    'nhl_event_id' => $data['nhl_event_id'],
                ],
                $data
            );
        }
    }
}
