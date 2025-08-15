<?php

declare(strict_types=1);

namespace App\Classes;

use App\Models\Player;
use App\Models\Stat;
use App\Traits\HasAPITrait;

/**
 * Class ImportNHLPlayer
 *
 * Imports NHL player metadata and season stats from the NHL API.
 *
 * @package App\Classes
 */
class ImportNHLPlayer
{
    use HasAPITrait;

    /**
     * Import a player from the NHL API and persist their data.
     *
     * @param string $playerId   NHL.com player ID
     * @param bool   $isProspect Whether this player is a prospect
     * @return void
     */
    public function import(string $playerId, bool $isProspect = false): void
    {
        $data = $this->getAPIData('nhl', 'player_landing', [
            'playerId' => $playerId,
        ]);

        $player = Player::firstOrNew([
            'nhl_id' => $data['playerId'],
        ]);

        $player->nhl_team_id          = $data['currentTeamId'] ?? null;
        $player->team_abbrev          = $data['currentTeamAbbrev'] ?? null;
        $player->first_name           = $data['firstName']['default'] ?? '';
        $player->last_name            = $data['lastName']['default'] ?? '';
        $player->full_name            = trim($player->first_name . ' ' . $player->last_name);
        $player->dob                  = $data['birthDate'] ?? null;
        $player->country_code         = $data['birthCountry'] ?? null;
        $player->position             = $data['position'] ?? null;
        $player->pos_type             = in_array($player->position, ['L', 'R', 'C']) ? 'F' : $player->position;
        $player->current_league_abbrev = 'NHL';
        $player->is_prospect          = $isProspect;
        $player->head_shot_url        = $data['headshot'] ?? null;
        $player->hero_image_url       = $data['heroImage'] ?? null;

        $player->save();

        $this->importStats($player, $data['seasonTotals'] ?? []);
    }



    /**
     * Check if a player already exists by NHL player ID.
     *
     * @param int|string $nhlPlayerId
     * @return bool
     */
    public function playerExists(int|string $nhlPlayerId): bool
    {
        return Player::where('nhl_id', (string)$nhlPlayerId)->exists();
    }

    

    /**
     * Import and persist all stat lines for a player.
     *
     * @param Player                $player
     * @param array<array<string,mixed>> $seasonTotals
     * @return void
     */
    private function importStats(Player $player, array $seasonTotals): void
    {
        foreach ($seasonTotals as $data) {
            $gp     = (int)($data['gamesPlayed'] ?? 0);
            $toiRaw = $data['avgToi'] ?? $data['timeOnIce'] ?? null;
            $toiMin = parseToiMinutes($toiRaw);

            $key = [
                'player_id'     => $player->id,
                'team_name'     => $data['teamName']['default'] ?? 'Unknown',
                'season_id'     => $data['season'],
                'game_type_id'  => $data['gameTypeId'],
                'sequence'      => $data['sequence'],
            ];

            $stat = Stat::firstOrNew($key);

            $stat->fill([
                // IDs and player info
                'is_prospect'        => $player->is_prospect,
                'nhl_team_id'        => $player->nhl_team_id,
                'nhl_team_abbrev'    => $player->team_abbrev,
                'player_name'        => $player->full_name,
                'league_abbrev'      => $data['leagueAbbrev'] ?? null,
                'team_name'          => $data['teamName']['default'] ?? null,

                // Raw stats
                'gp'                 => $gp,
                'g'                  => $data['goals'] ?? 0,
                'a'                  => $data['assists'] ?? 0,
                'pts'                => $data['points'] ?? 0,
                'gwg'                => $data['gameWinningGoals'] ?? null,
                'ppg'                => $data['powerPlayGoals'] ?? null,
                'ppp'                => $data['powerPlayPoints'] ?? null,
                'shg'                => $data['shorthandedGoals'] ?? null,
                'ot_goals'           => $data['otGoals'] ?? null,
                'pim'                => $data['pim'] ?? null,
                'plus_minus'         => $data['plusMinus'] ?? null,
                'sog'                => $data['shots'] ?? null,
                'shooting_percentage'=> $data['shootingPctg'] ?? null,

                // TOI
                'avg_toi'            => $data['avgToi'] ?? null,
                'total_toi'          => $data['timeOnIce'] ?? null,
                'toi_minutes'        => $toiMin,

                // Derived per GP
                'g_per_gp'           => $gp > 0 ? round(($data['goals'] ?? 0) / $gp, 3) : 0,
                'a_per_gp'           => $gp > 0 ? round(($data['assists'] ?? 0) / $gp, 3) : 0,
                'pts_per_gp'         => $gp > 0 ? round(($data['points'] ?? 0) / $gp, 3) : 0,
                'sog_per_gp'         => $gp > 0 ? round(($data['shots'] ?? 0) / $gp, 2) : 0,

                // Derived per 60
                'g_per_60'           => $toiMin > 0 ? round(($data['goals'] ?? 0) / $toiMin * 60, 2) : 0,
                'a_per_60'           => $toiMin > 0 ? round(($data['assists'] ?? 0) / $toiMin * 60, 2) : 0,
                'pts_per_60'         => $toiMin > 0 ? round(($data['points'] ?? 0) / $toiMin * 60, 2) : 0,
                'sog_per_60'         => $toiMin > 0 ? round(($data['shots'] ?? 0) / $toiMin * 60, 2) : 0,

                // Goalie stats
                'wins'               => $data['wins'] ?? null,
                'losses'             => $data['losses'] ?? null,
                'ot_losses'          => $data['otLosses'] ?? null,
                'shutouts'           => $data['shutouts'] ?? null,
                'gaa'                => $data['gaa'] ?? null,
                'sv_pct'             => $data['savePctg'] ?? null,
                'saves'              => $data['saves'] ?? null,
                'shots_against'      => $data['shotsAgainst'] ?? null,
                'goals_against'      => $data['goalsAgainst'] ?? null,
            ]);

            $stat->save();

            // If this is a prospect, update their league affiliation
            if ($player->is_prospect) {
                $player->current_league_abbrev = $stat->league_abbrev;
                $player->save();
            }
        }
    }
}
