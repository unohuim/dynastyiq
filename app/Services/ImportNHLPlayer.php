<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Player;
use App\Models\Stat;
use App\Observers\PlayerNhlIdentityObserver;
use App\Traits\HasAPITrait;
use Illuminate\Support\Facades\Log;

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
        $this->syncProspectFlags($player);

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
        foreach ($this->uniqueSeasonTotals($player, $seasonTotals) as $row) {
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
            $goalieToiMin = parseToiMinutes($row['timeOnIce'] ?? $row['avgToi'] ?? null);

            if ($goalsAgainstAverage === null && $goalsAgainst !== null && $goalieToiMin > 0) {
                $goalsAgainstAverage = round($goalsAgainst / ($goalieToiMin / 60), 3);
            }

            $key = $this->statIdentityKey($player, $row);

            $stat = Stat::updateOrCreate($key, [
                // IDs and player info
                'is_prospect'         => $player->is_prospect,
                'nhl_team_id'         => $player->nhl_team_id,
                'nhl_team_abbrev'     => $player->team_abbrev,
                'player_name'         => $player->full_name,
                'league_abbrev'       => $key['league_abbrev'],
                'team_name'           => $key['team_name'],

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

            if ($player->is_prospect) {
                $player->current_league_abbrev = $stat->league_abbrev;
                $player->save();
            }
        }
    }

    private function syncProspectFlags(Player $player): void
    {
        $nhlPlayerId = (int) ($player->nhl_id ?? 0);
        if ($nhlPlayerId <= 0) {
            return;
        }

        $isProspect = app(ProspectEligibilityService::class)->isProspect($nhlPlayerId);

        $player->is_prospect = $isProspect;
        if ($isProspect) {
            $currentLeague = $this->currentEvaluationNonNhlLeague($player);
            if ($currentLeague !== null) {
                $player->current_league_abbrev = $currentLeague;
            }
        }
        PlayerNhlIdentityObserver::withoutLandingRefresh(fn () => $player->save());

        Stat::query()
            ->where('player_id', $player->id)
            ->update(['is_prospect' => $isProspect]);
    }

    private function currentEvaluationNonNhlLeague(Player $player): ?string
    {
        $seasons = app(ProspectEligibilityService::class)->evaluationSeasonIds(now());

        return Stat::query()
            ->where('player_id', $player->id)
            ->where('league_abbrev', '<>', 'NHL')
            ->whereIn('season_id', $seasons)
            ->where('gp', '>', 0)
            ->orderByDesc('season_id')
            ->orderByDesc('gp')
            ->value('league_abbrev');
    }

    /**
     * Return one authoritative source row for each NHL season total identity.
     *
     * @param Player $player
     * @param array<int,array<string,mixed>> $seasonTotals
     * @return array<int,array<string,mixed>>
     */
    private function uniqueSeasonTotals(Player $player, array $seasonTotals): array
    {
        $uniqueRows = [];

        foreach ($seasonTotals as $row) {
            $identity = $this->statIdentityKey($player, $row);
            $hash = $this->statIdentityHash($identity);

            if (
                isset($uniqueRows[$hash])
                && $this->seasonTotalFingerprint($uniqueRows[$hash]) !== $this->seasonTotalFingerprint($row)
            ) {
                Log::warning('Duplicate NHL landing season total identity had conflicting values; keeping last row.', [
                    'player_id' => $player->id,
                    'nhl_id' => $player->nhl_id,
                    'identity' => $identity,
                ]);
            }

            $uniqueRows[$hash] = $row;
        }

        return array_values($uniqueRows);
    }

    /**
     * Build the persisted stat identity used for NHL landing season totals.
     *
     * @param Player $player
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function statIdentityKey(Player $player, array $row): array
    {
        return [
            'player_id' => $player->id,
            'season_id' => (string)($row['season'] ?? ''),
            'league_abbrev' => (string)($row['leagueAbbrev'] ?? ''),
            'team_name' => (string)($row['teamName']['default'] ?? 'Unknown'),
            'game_type_id' => array_key_exists('gameTypeId', $row) ? (int)$row['gameTypeId'] : null,
            'sequence' => array_key_exists('sequence', $row) ? (int)$row['sequence'] : null,
        ];
    }

    /**
     * Convert a stat identity to a deterministic string key.
     *
     * @param array<string,mixed> $identity
     * @return string
     */
    private function statIdentityHash(array $identity): string
    {
        return implode('|', array_map(
            static fn ($value): string => $value === null ? '__NULL__' : (string)$value,
            $identity,
        ));
    }

    /**
     * Build a stable fingerprint for comparing duplicate source rows.
     *
     * @param array<string,mixed> $row
     * @return string
     */
    private function seasonTotalFingerprint(array $row): string
    {
        return json_encode($this->sortArrayRecursively($row)) ?: '';
    }

    /**
     * Sort nested arrays so equivalent NHL source rows compare consistently.
     *
     * @param array<string|int,mixed> $value
     * @return array<string|int,mixed>
     */
    private function sortArrayRecursively(array $value): array
    {
        foreach ($value as $key => $nestedValue) {
            if (is_array($nestedValue)) {
                $value[$key] = $this->sortArrayRecursively($nestedValue);
            }
        }

        ksort($value);

        return $value;
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
