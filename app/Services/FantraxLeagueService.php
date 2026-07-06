<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PlatformLeague;
use App\Models\PlatformTeam;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use App\Events\FantraxLeagueCreated;
use App\Events\FantraxLeagueUpdated;

class FantraxLeagueService
{
    /**
     * Upsert Fantrax leagues, teams, and user↔team assignments.
     *
     * Expected $leagues item shape (Fantrax):
     * [
     *   'leagueId'   => string,
     *   'leagueName' => string|null,
     *   'leagueLogoUrl' => string|null,
     *   'teamId'     => string,
     *   'teamName'   => string|null,
     *   'teamShortName' => string|null,
     *   'teamLogoUrl' => string|null,
     *   'sport'      => string|null,  // ignored; we canonicalize to "hockey"
     * ]
     */
    public function upsertLeaguesForUser(User $user, array $leagues): void
    {
        $now = now();

        foreach ($this->normalizeLeaguePayloads($leagues) as $payload) {
            $platformLeague = null;
            $team   = null;

            // 1) League + Team inside a small transaction
            DB::transaction(function () use ($payload, $now, &$platformLeague, &$team): void {
                // League (unique: platform + platform_league_id)
                $leagueValues = [
                    'name' => $payload['leagueName'] ?? 'Unnamed League',
                    'sport' => 'hockey', // canonical fallback
                    'synced_at' => $now,
                ];
                $leagueLogoUrl = $this->logoUrl($payload, 'league');

                if ($leagueLogoUrl !== null) {
                    $leagueValues['logo_url'] = $leagueLogoUrl;
                }

                $platformLeague = PlatformLeague::updateOrCreate(
                    [
                        'platform'           => 'fantrax',
                        'platform_league_id' => (string) ($payload['leagueId'] ?? ''),
                    ],
                    $leagueValues
                );


                // Team (unique: league_id + platform_team_id)
                $teamValues = [
                    'name' => $payload['teamName'] ?? 'Unnamed Team',
                    'short_name' => $payload['teamShortName'] ?? null,
                    'synced_at' => $now,
                ];
                $teamLogoUrl = $this->logoUrl($payload, 'team');
                $providerTeamId = (string) ($payload['teamId'] ?? '');

                if ($teamLogoUrl !== null) {
                    $teamValues['logo_url'] = $teamLogoUrl;
                }

                $team = PlatformTeam::updateOrCreate(
                    [
                        'platform_league_id'        => $platformLeague->id,
                        'platform_team_id' => $providerTeamId,
                    ],
                    $teamValues
                );
            });



            // Fire only after the transaction above has committed
            if ($platformLeague?->wasRecentlyCreated) {
                DB::afterCommit(function () use ($platformLeague): void {
                    event(new FantraxLeagueCreated($platformLeague->id));
                });

            }
            // elseif ($platformLeague?->wasChanged()) {
            //     DB::afterCommit(function () use ($platformLeague): void {
            //         event(new FantraxLeagueUpdated($platformLeague->id));
            //     });

            // }


            // 2) User ↔ Team assignment outside txn (so failures don't roll back league/team)
            if ($platformLeague && $team) {
                $keys = [
                    'user_id'   => $user->id,
                    'platform_league_id' => $platformLeague->id,
                ];

                $pivot = [
                    'team_id'    => $team->id,
                    'is_active'  => true,
                    'extras'     => json_encode(['provider' => 'fantrax']),
                    'synced_at'  => $now,
                    'updated_at' => $now,
                ];

                $updated = DB::table('league_user_teams')->where($keys)->update($pivot);

                if ($updated === 0) {
                    $nextSortOrder = (int) DB::table('league_user_teams')
                        ->where('user_id', $user->id)
                        ->max('sort_order') + 1;

                    DB::table('league_user_teams')->insert(
                        $keys + $pivot + ['sort_order' => $nextSortOrder, 'created_at' => $now]
                    );
                }
            }
        }

    }

    /**
     * Normalize Fantrax flat and nested user-league payloads into league/team rows.
     *
     * @param array<int,array<string,mixed>> $leagues
     * @return array<int,array<string,mixed>>
     */
    private function normalizeLeaguePayloads(array $leagues): array
    {
        $normalized = [];

        foreach ($leagues as $item) {
            $leagueTeams = $item['leaguesTeams'] ?? null;

            if (is_array($leagueTeams)) {
                foreach ($leagueTeams as $team) {
                    if (! is_array($team)) {
                        continue;
                    }

                    $normalized[] = [
                        'leagueId' => $team['leagueId'] ?? null,
                        'leagueName' => $team['league'] ?? null,
                        'leagueLogoUrl' => $item['leagueLogoUrl'] ?? $item['leagueLogo'] ?? null,
                        'teamId' => $team['teamId'] ?? null,
                        'teamName' => $team['team'] ?? null,
                        'teamShortName' => $team['teamShortName'] ?? $team['shortName'] ?? null,
                        'teamLogoUrl' => $team['teamLogo'] ?? $team['teamLogoUrl'] ?? $team['logoUrl128'] ?? $team['logoUrl'] ?? null,
                        'sport' => $item['sport'] ?? $item['sportId'] ?? null,
                    ];
                }

                continue;
            }

            $normalized[] = $item;
        }

        return $normalized;
    }

    /**
     * Find a Fantrax logo URL from known league or team payload keys.
     *
     * @param array<string,mixed> $payload
     */
    private function logoUrl(array $payload, string $scope): ?string
    {
        $keys = $scope === 'league'
            ? ['leagueLogoUrl', 'leagueLogoURL', 'leagueLogo', 'logoUrl', 'logoURL', 'logo_url', 'avatarUrl', 'avatar_url', 'imageUrl', 'image_url', 'iconUrl', 'icon_url']
            : ['teamLogoUrl', 'teamLogoURL', 'teamLogo', 'logoUrl', 'logoURL', 'logo_url', 'avatarUrl', 'avatar_url', 'imageUrl', 'image_url', 'iconUrl', 'icon_url'];

        foreach ($keys as $key) {
            $value = data_get($payload, $key);

            if (is_string($value) && filled($value)) {
                return $value;
            }
        }

        return null;
    }
}
