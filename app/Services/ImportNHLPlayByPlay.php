<?php

namespace App\Services;

use App\Models\PlayByPlay;
use App\Models\NhlGame;
use App\Traits\HasAPITrait;

class ImportNHLPlayByPlay
{
    use HasAPITrait;

    protected function timeStringToSeconds(?string $time): ?int
    {
        if (!$time) return null;
        [$minutes, $seconds] = explode(':', $time) + [1 => '0'];
        return ((int)$minutes * 60) + (int)$seconds;
    }

    protected function determineStrength(?string $situationCode, ?int $eventOwnerTeamId, ?int $homeTeamId, ?int $awayTeamId): string
    {
        if (!$situationCode || !$eventOwnerTeamId || !$homeTeamId || !$awayTeamId) {
            return 'EV';
        }

        if (strlen($situationCode) !== 4) {
            return 'EV';
        }

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

    public function import($gameId): int
    {
        try {
            \Log::warning("Trying Pbp import for game {$gameId}");

            $response = $this->getAPIData('nhl', 'pbp', ['gameId' => $gameId]);

            if (empty($response['plays'])) {
                return 0;
            }

            $periodLengthSeconds = 1200; // 20 minutes per regulation period


            $gameDate = $response['gameDate'] ?? null;
            $gameDow = $gameDate ? \Carbon\Carbon::parse($gameDate)->format('l') : 'Unknown';
            $gameMonth = $gameDate ? \Carbon\Carbon::parse($gameDate)->format('F') : 'Unknown';
            $homeTeamId = $response['homeTeam']['id'] ?? null;
            $awayTeamId = $response['awayTeam']['id'] ?? null;


            // Upsert NHL game details once
            NhlGame::updateOrCreate(
                ['nhl_game_id' => $gameId],
                [
                    'season_id' => $response['season'] ?? null,
                    'game_type' => $response['gameType'] ?? null,
                    'game_date' => $response['gameDate'] ?? null,
                    'game_dow'  => $gameDow,
                    'game_month'=> $gameMonth,
                    'home_team_id' => $response['homeTeam']['id'] ?? null,
                    'away_team_id' => $response['awayTeam']['id'] ?? null,
                    'home_team_common_name' => $response['homeTeam']['commonName']['default'] ?? null,
                    'away_team_common_name' => $response['awayTeam']['commonName']['default'] ?? null,
                    'home_team_abbrev' => $response['homeTeam']['abbrev'] ?? null,
                    'away_team_abbrev' => $response['awayTeam']['abbrev'] ?? null,
                    'home_team_score' => $response['homeTeam']['score'] ?? 0,
                    'away_team_score' => $response['awayTeam']['score'] ?? 0,
                    'home_team_sog' => $response['homeTeam']['sog'] ?? 0,
                    'away_team_sog' => $response['awayTeam']['sog'] ?? 0,
                    'home_team_logo' => $response['homeTeam']['logo'] ?? null,
                    'home_team_dark_logo' => $response['homeTeam']['darkLogo'] ?? null,
                    'home_team_place_name' => $response['homeTeam']['placeName']['default'] ?? null,
                    'away_team_logo' => $response['awayTeam']['logo'] ?? null,
                    'away_team_dark_logo' => $response['awayTeam']['darkLogo'] ?? null,
                    'away_team_place_name' => $response['awayTeam']['placeName']['default'] ?? null,
                    'limited_scoring' => $response['limitedScoring'] ?? false,
                    'venue' => $response['venue']['default'] ?? null,
                    'venue_location' => $response['venueLocation']['default'] ?? null,
                    'start_time_utc' => $response['startTimeUTC'] ?? null,
                    'eastern_utc_offset' => $response['easternUTCOffset'] ?? null,
                    'venue_utc_offset' => $response['venueUTCOffset'] ?? null,
                    'shootout_in_use' => $response['shootoutInUse'] ?? false,
                    'ot_in_use' => $response['otInUse'] ?? false,
                    'game_state' => $response['gameState'] ?? null,
                    'game_schedule_state' => $response['gameScheduleState'] ?? null,
                    'current_period' => $response['periodDescriptor']['number'] ?? null,
                    'period_type' => $response['periodDescriptor']['periodType'] ?? null,
                    'max_regulation_periods' => $response['periodDescriptor']['maxRegulationPeriods'] ?? null,
                    'clock_time_remaining' => $response['clock']['timeRemaining'] ?? null,
                    'clock_seconds_remaining' => $response['clock']['secondsRemaining'] ?? null,
                    'clock_running' => $response['clock']['running'] ?? false,
                    'clock_in_intermission' => $response['clock']['inIntermission'] ?? false,
                    'clock_display_period' => $response['clock']['displayPeriod'] ?? null,
                    'clock_max_periods' => $response['clock']['maxPeriods'] ?? null,
                    'tv_broadcasts' => $response['tvBroadcasts'] ?? null,
                    'game_outcome' => $response['gameOutcome'] ?? null,
                ]
            );

            $playCount = 0;
            foreach ($response['plays'] as $event) {
                $details = $event['details'] ?? [];

                $timeInPeriod = $event['timeInPeriod'] ?? null;
                $timeRemaining = $event['timeRemaining'] ?? null;
                $period = $event['periodDescriptor']['number'] ?? 1;

                $secondsInPeriod = $this->timeStringToSeconds($timeInPeriod);
                $secondsRemaining = $this->timeStringToSeconds($timeRemaining);

                $secondsInGame = $secondsInPeriod !== null ? $secondsInPeriod + ($period - 1) * $periodLengthSeconds : null;

                $data = [
                    'nhl_game_id' => $gameId,
                    'nhl_event_id' => $event['eventId'] ?? null,
                    
                    'away_score' => $details['awayScore'] ?? 0,
                    'home_score' => $details['homeScore'] ?? 0,

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

                    'x_coord' => $details['xCoord'] ?? null,
                    'y_coord' => $details['yCoord'] ?? null,
                    'zone_code' => $details['zoneCode'] ?? null,

                    'scoring_player_id' => $details['scoringPlayerId'] ?? null,
                    'scoring_player_total' => $details['scoringPlayerTotal'] ?? 0,
                    'assist1_player_id' => $details['assist1PlayerId'] ?? null,
                    'assist1_player_total' => $details['assist1PlayerTotal'] ?? 0,
                    'assist2_player_id' => $details['assist2PlayerId'] ?? null,
                    'assist2_player_total' => $details['assist2PlayerTotal'] ?? 0,

                    'committed_by_player_id' => $details['committedByPlayerId'] ?? null,
                    'drawn_by_player_id' => $details['drawnByPlayerId'] ?? null,
                    'fo_winning_player_id' => $details['winningPlayerId'] ?? null,
                    'fo_losing_player_id' => $details['losingPlayerId'] ?? null,
                    'shooting_player_id' => $details['shootingPlayerId'] ?? null,
                    'goalie_in_net_player_id' => $details['goalieInNetId'] ?? null,
                    'blocking_player_id' => $details['blockingPlayerId'] ?? null,
                    'hitting_player_id' => $details['hittingPlayerId'] ?? null,
                    'hittee_player_id' => $details['hitteePlayerId'] ?? null,
                    'nhl_player_id' => $details['playerId'] ?? null,

                    'duration' => $details['duration'] ?? null,
                    'penalty_type_code' => $details['typeCode'] ?? null,
                    'strength' => null, // will be assigned below
                    'reason' => $details['reason'] ?? null,
                    'secondary_reason' => $details['secondaryReason'] ?? null,
                    'shot_type' => $details['shotType'] ?? null,

                    'highlight_clip_sharing_url' => $details['highlightClipSharingUrl'] ?? null,
                    'highlight_clip_id' => $details['highlightClip'] ?? null,
                ];

                $data['strength'] = $this->determineStrength(
                    $data['situation_code'],
                    $data['event_owner_team_id'],
                    $homeTeamId,
                    $awayTeamId,
                );

                PlayByPlay::updateOrCreate(
                    [
                        'nhl_game_id' => $gameId,
                        'nhl_event_id' => $data['nhl_event_id'],
                    ],
                    $data
                );

                $playCount++;
            }

        } catch (\Throwable $e) {

            \Log::error("Pbp failed for game {$gameId}: {$e->getMessage()}");
            throw $e;
        }

        \Log::warning("Completed Pbp import for game {$gameId}");
        return $playCount;
    }
}
