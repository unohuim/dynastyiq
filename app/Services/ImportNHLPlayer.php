<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\ImportStreamEvent;
use App\Models\Player;
use App\Models\Stat;
use App\Traits\HasAPITrait;

/**
 * Class ImportNHLPlayer
 *
 * Imports NHL player metadata and season stats from the NHL API.
 */
class ImportNHLPlayer
{
    use HasAPITrait;

    /**
     * Import a player from the NHL API and persist their data.
     *
     * @param string $playerId   NHL.com player ID
     * @param bool   $isProspect Whether this player is a prospect
     */
    public function import(string $playerId, bool $isProspect = false): void
    {
        $data = $this->getAPIData('nhl', 'player_landing', [
            'playerId' => $playerId,
        ]);

        $player = Player::firstOrNew([
            'nhl_id' => $data['playerId'],
        ]);

        $player->nhl_team_id           = $data['currentTeamId'] ?? null;
        $player->team_abbrev           = $data['currentTeamAbbrev'] ?? null;
        $player->first_name            = $data['firstName']['default'] ?? '';
        $player->last_name             = $data['lastName']['default'] ?? '';
        $player->full_name             = trim($player->first_name . ' ' . $player->last_name);
        $player->dob                   = $data['birthDate'] ?? null;
        $player->country_code          = $data['birthCountry'] ?? null;
        $player->position              = $data['position'] ?? null;
        $player->pos_type              = in_array($player->position, ['L', 'R', 'C'], true) ? 'F' : $player->position;
        $player->current_league_abbrev = 'NHL';
        $player->is_prospect           = $isProspect;
        $player->head_shot_url         = $data['headshot'] ?? null;
        $player->hero_image_url        = $data['heroImage'] ?? null;

        $player->save();


        ImportStreamEvent::dispatch(
            'nhl',
            "Importing {$player->full_name}, {$player->position} – {$player->teamAbbrev}",
            'started'
        );

        $this->importStats($player, $data['seasonTotals'] ?? []);
    }


    /**
     * Check if a player already exists by NHL player ID.
     *
     * @param int|string $nhlPlayerId
     * @return bool
     */
    public static function playerExists(int|string $nhlPlayerId): bool
    {
        return Player::where('nhl_id', (string)$nhlPlayerId)->exists();
    }


    

    /**
     * Import and persist all stat lines for a player.
     *
     * @param Player                     $player
     * @param array<int,array<string,mixed>> $seasonTotals
     */
    private function importStats(Player $player, array $seasonTotals): void
    {
        foreach ($seasonTotals as $row) {
            $gp     = (int)($row['gamesPlayed'] ?? 0);
            $toiRaw = $row['avgToi'] ?? $row['timeOnIce'] ?? null;
            $toiMin = parseToiMinutes($toiRaw);

            $key = [
                'player_id'    => $player->id,
                'team_name'    => $row['teamName']['default'] ?? 'Unknown',
                'season_id'    => $row['season'],
                'game_type_id' => $row['gameTypeId'],
                'sequence'     => $row['sequence'],
            ];

            $stat = Stat::firstOrNew($key);

            $stat->fill([
                // IDs and player info
                'is_prospect'         => $player->is_prospect,
                'nhl_team_id'         => $player->nhl_team_id,
                'nhl_team_abbrev'     => $player->team_abbrev,
                'player_name'         => $player->full_name,
                'league_abbrev'       => $row['leagueAbbrev'] ?? null,
                'team_name'           => $row['teamName']['default'] ?? null,

                // Raw stats
                'gp'                  => $gp,
                'g'                   => $row['goals'] ?? 0,
                'a'                   => $row['assists'] ?? 0,
                'pts'                 => $row['points'] ?? 0,
                'gwg'                 => $row['gameWinningGoals'] ?? null,
                'ppg'                 => $row['powerPlayGoals'] ?? null,
                'ppp'                 => $row['powerPlayPoints'] ?? null,
                'shg'                 => $row['shorthandedGoals'] ?? null,
                'ot_goals'            => $row['otGoals'] ?? null,
                'pim'                 => $row['pim'] ?? null,
                'plus_minus'          => $row['plusMinus'] ?? null,
                'sog'                 => $row['shots'] ?? null,
                'shooting_percentage' => $row['shootingPctg'] ?? null,

                // TOI
                'avg_toi'             => $row['avgToi'] ?? null,
                'total_toi'           => $row['timeOnIce'] ?? null,
                'toi_minutes'         => $toiMin,

                // Derived per GP
                'g_per_gp'            => $gp > 0 ? round(((int)($row['goals'] ?? 0)) / $gp, 3) : 0,
                'a_per_gp'            => $gp > 0 ? round(((int)($row['assists'] ?? 0)) / $gp, 3) : 0,
                'pts_per_gp'          => $gp > 0 ? round(((int)($row['points'] ?? 0)) / $gp, 3) : 0,
                'sog_per_gp'          => $gp > 0 ? round(((int)($row['shots'] ?? 0)) / $gp, 2) : 0,

                // Derived per 60
                'g_per_60'            => $toiMin > 0 ? round(((int)($row['goals'] ?? 0)) / $toiMin * 60, 2) : 0,
                'a_per_60'            => $toiMin > 0 ? round(((int)($row['assists'] ?? 0)) / $toiMin * 60, 2) : 0,
                'pts_per_60'          => $toiMin > 0 ? round(((int)($row['points'] ?? 0)) / $toiMin * 60, 2) : 0,
                'sog_per_60'          => $toiMin > 0 ? round(((int)($row['shots'] ?? 0)) / $toiMin * 60, 2) : 0,

                // Goalie stats
                'wins'                => $row['wins'] ?? null,
                'losses'              => $row['losses'] ?? null,
                'ot_losses'           => $row['otLosses'] ?? null,
                'shutouts'            => $row['shutouts'] ?? null,
                'gaa'                 => $row['gaa'] ?? null,
                'sv_pct'              => $row['savePctg'] ?? null,
                'saves'               => $row['saves'] ?? null,
                'shots_against'       => $row['shotsAgainst'] ?? null,
                'goals_against'       => $row['goalsAgainst'] ?? null,
            ]);

            $stat->save();

            if ($player->is_prospect) {
                $player->current_league_abbrev = $stat->league_abbrev;
                $player->save();
            }
        }
    }
}
