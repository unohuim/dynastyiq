<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\PlatformLeague;
use App\Models\PlatformTeam;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Resolves the viewer-specific Fantrax player-pool scope for league reads.
 */
final class FantraxViewerScope
{
    /**
     * @return array{scope:string,division:string|null}|null
     */
    public function resolve(?PlatformLeague $league, ?User $user): ?array
    {
        if (! $league || ! $user || (string) ($league->platform ?? '') !== 'fantrax') {
            return null;
        }

        $shape = data_get($league, 'settings.league_shape', []);

        if (! is_array($shape) || ($shape['player_pool_scope'] ?? null) !== 'division') {
            return null;
        }

        $teamId = DB::table('league_user_teams')
            ->where('platform_league_id', $league->id)
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->value('team_id');
        $team = $teamId
            ? PlatformTeam::query()
                ->where('platform_league_id', $league->id)
                ->find($teamId)
            : null;

        $division = $team instanceof PlatformTeam
            ? $this->divisionFromTeam($team)
            : null;

        if ($division === null) {
            return null;
        }

        return [
            'scope' => 'division',
            'division' => $division,
        ];
    }

    public function divisionFromTeam(PlatformTeam $team): ?string
    {
        $division = trim((string) (
            data_get($team->extras, 'fantrax.division')
            ?? data_get($team->extras, 'fantrax.pool')
            ?? ''
        ));

        return $division !== '' ? $division : null;
    }

    /**
     * @param array{scope:string,division:string|null}|null $scope
     */
    public function teamMatches(PlatformTeam $team, ?array $scope): bool
    {
        if (($scope['scope'] ?? null) !== 'division') {
            return true;
        }

        return strcasecmp(
            (string) ($scope['division'] ?? ''),
            (string) ($this->divisionFromTeam($team) ?? '')
        ) === 0;
    }
}
