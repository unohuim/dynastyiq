<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Player;
use App\Models\Stat;
use App\Observers\PlayerNhlIdentityObserver;
use App\Traits\HasAPITrait;

/**
 * Class ImportNHLPlayer
 *
 * Imports NHL player metadata and season stats from the NHL API.
 */
class ImportNHLPlayer
{
    use HasAPITrait;

    public function __construct(
        private readonly PlayerIdentityResolver $identityResolver,
        private readonly NhlTeamReference $teams,
    ) {
    }

    /**
     * Import a player from the NHL API and persist their data.
     *
     * @param string $playerId   NHL.com player ID
     * @param bool   $isProspect Whether this player is a prospect
     */
    public function import(string $playerId, bool $isProspect = false): Player
    {
        $data = $this->getAPIData('nhl', 'player_landing', [
            'playerId' => $playerId,
        ]);

        return $this->persistLandingPayload($data, null, $isProspect);
    }

    /**
     * Import NHL landing data and force it onto a known canonical player when safe.
     *
     * @param Player $player
     * @param string $playerId
     * @param bool $isProspect
     */
    public function importForPlayer(Player $player, string $playerId, bool $isProspect = false): Player
    {
        $data = $this->getAPIData('nhl', 'player_landing', [
            'playerId' => $playerId,
        ]);

        return $this->persistLandingPayload($data, $player, $isProspect);
    }

    /**
     * @param array<string,mixed> $data
     * @param Player|null $preferredPlayer
     * @param bool $isProspect
     */
    private function persistLandingPayload(array $data, ?Player $preferredPlayer, bool $isProspect): Player
    {
        $identity = $this->identityResolver->upsertNhlIdentity($data);
        $this->teams->upsertFromPlayerPayload($data);

        $player = $identity->player ?? $preferredPlayer ?? Player::firstOrNew([
            'nhl_id' => $data['playerId'],
        ]);

        $teamAbbrev = $data['currentTeamAbbrev'] ?? null;

        $player->nhl_id                = $data['playerId'];
        $player->nhl_team_id           = $data['currentTeamId'] ?? $this->teams->idForAbbrev($teamAbbrev);
        $player->team_abbrev           = $teamAbbrev;
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

        PlayerNhlIdentityObserver::withoutLandingRefresh(fn () => $player->save());

        $this->identityResolver->linkIdentityToPlayer($identity, $player);

        $this->importStats($player, $data['seasonTotals'] ?? []);

        return $player;
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
            $shotsAgainst = $this->nullableInt($row, ['shotsAgainst', 'shots_against']);
            $goalsAgainst = $this->nullableInt($row, ['goalsAgainst', 'goals_against']);
            $saves = $this->nullableInt($row, ['saves']);

            if ($saves === null && $shotsAgainst !== null && $goalsAgainst !== null) {
                $saves = max(0, $shotsAgainst - $goalsAgainst);
            }

            $savePercentage = $this->nullableFloat($row, ['savePctg', 'savePct', 'sv_pct']);

            if ($savePercentage === null && $shotsAgainst !== null && $shotsAgainst > 0 && $saves !== null) {
                $savePercentage = round($saves / $shotsAgainst, 3);
            }

            $goalsAgainstAverage = $this->nullableFloat($row, ['gaa']);

            if ($goalsAgainstAverage === null && $goalsAgainst !== null && $toiMin > 0) {
                $goalsAgainstAverage = round($goalsAgainst / ($toiMin / 60), 3);
            }

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
                'gaa'                 => $goalsAgainstAverage,
                'sv_pct'              => $savePercentage,
                'saves'               => $saves,
                'shots_against'       => $shotsAgainst,
                'goals_against'       => $goalsAgainst,
            ]);

            $stat->save();

            if ($player->is_prospect) {
                $player->current_league_abbrev = $stat->league_abbrev;
                $player->save();
            }
        }
    }

    /**
     * Return the first numeric integer value from a source row.
     *
     * @param array<string,mixed> $row
     * @param array<int,string> $keys
     * @return int|null
     */
    private function nullableInt(array $row, array $keys): ?int
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && is_numeric($row[$key])) {
                return (int) $row[$key];
            }
        }

        return null;
    }

    /**
     * Return the first numeric float value from a source row.
     *
     * @param array<string,mixed> $row
     * @param array<int,string> $keys
     * @return float|null
     */
    private function nullableFloat(array $row, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && is_numeric($row[$key])) {
                return (float) $row[$key];
            }
        }

        return null;
    }
}
