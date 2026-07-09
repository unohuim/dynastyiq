<?php

declare(strict_types=1);

namespace App\Support\Stats;

use App\Models\PlatformLeague;
use Illuminate\Support\Facades\DB;

/**
 * Filters league stats payload rows to players known by the league platform.
 */
final class LeagueStatsPlayerUniverseFilter
{
    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function filter(array $payload, PlatformLeague $league): array
    {
        $platform = (string) ($league->platform ?? '');

        if (! in_array($platform, ['fantrax', 'yahoo'], true)) {
            return $payload;
        }

        $universe = $this->playerUniverse($league);

        if ($universe['player_ids'] === [] && $universe['nhl_ids'] === []) {
            $payload['data'] = [];

            return $payload;
        }

        $payload['data'] = collect($payload['data'] ?? [])
            ->filter(static function (mixed $row) use ($universe): bool {
                if (! is_array($row)) {
                    return false;
                }

                $playerId = (string) ($row['player_id'] ?? $row['id'] ?? '');
                $nhlId = (string) ($row['nhl_player_id'] ?? '');

                return ($playerId !== '' && isset($universe['player_ids'][$playerId]))
                    || ($nhlId !== '' && isset($universe['nhl_ids'][$nhlId]));
            })
            ->values()
            ->all();

        return $payload;
    }

    /**
     * Return canonical player ids observed in the provider plus current league roster evidence.
     *
     * @return array{player_ids:array<string,bool>,nhl_ids:array<string,bool>}
     */
    public function playerUniverse(PlatformLeague $league): array
    {
        $platform = (string) ($league->platform ?? '');

        $providerPlayerIds = match ($platform) {
            'fantrax' => DB::table('fantrax_players')
                ->whereNotNull('player_id')
                ->pluck('player_id'),
            'yahoo' => DB::table('yahoo_players')
                ->whereNotNull('player_id')
                ->pluck('player_id'),
            default => collect(),
        };

        $rosterPlayerIds = DB::table('platform_roster_memberships as prm')
            ->join('platform_teams as pt', 'pt.id', '=', 'prm.platform_team_id')
            ->where('pt.platform_league_id', $league->id)
            ->where('prm.platform', $platform)
            ->whereNull('prm.ends_at')
            ->pluck('prm.player_id');

        $playerIds = $providerPlayerIds
            ->merge($rosterPlayerIds)
            ->filter()
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        if ($playerIds->isEmpty()) {
            return [
                'player_ids' => [],
                'nhl_ids' => [],
            ];
        }

        $nhlIds = DB::table('players')
            ->whereIn('id', $playerIds)
            ->whereNotNull('nhl_id')
            ->pluck('nhl_id')
            ->filter()
            ->map(static fn (mixed $id): string => (string) $id)
            ->unique()
            ->values();

        return [
            'player_ids' => $playerIds
                ->mapWithKeys(static fn (int $id): array => [(string) $id => true])
                ->all(),
            'nhl_ids' => $nhlIds
                ->mapWithKeys(static fn (string $id): array => [$id => true])
                ->all(),
        ];
    }
}
