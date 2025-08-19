<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\League;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class FantraxLeagueService
{
    /**
     * Upsert Fantrax leagues, teams, and user↔team assignments.
     *
     * Expected $leagues item shape (Fantrax):
     * [
     *   'leagueId'   => string,
     *   'leagueName' => string|null,
     *   'teamId'     => string,
     *   'teamName'   => string|null,
     *   'teamShortName' => string|null,
     *   'sport'      => string|null,  // ignored; we canonicalize to "hockey"
     * ]
     */
    public function upsertLeaguesForUser(User $user, array $leagues): void
    {
        $now = now();

        foreach ($leagues as $payload) {
            $league = null;
            $team   = null;

            // 1) League + Team inside a small transaction
            DB::transaction(function () use ($payload, $now, &$league, &$team): void {
                // League (unique: platform + platform_league_id)
                $league = League::updateOrCreate(
                    [
                        'platform'           => 'fantrax',
                        'platform_league_id' => (string) ($payload['leagueId'] ?? ''),
                    ],
                    [
                        'name'      => $payload['leagueName'] ?? 'Unnamed League',
                        'sport'     => 'hockey', // canonical fallback
                        'synced_at' => $now,
                    ]
                );

                // Team (unique: league_id + platform_team_id)
                $team = Team::updateOrCreate(
                    [
                        'league_id'        => $league->id,
                        'platform_team_id' => (string) ($payload['teamId'] ?? ''),
                    ],
                    [
                        'name'       => $payload['teamName'] ?? 'Unnamed Team',
                        'short_name' => $payload['teamShortName'] ?? null,
                        'synced_at'  => $now,
                    ]
                );
            });

            // 2) User ↔ Team assignment outside txn (so failures don't roll back league/team)
            if ($league && $team) {
                $keys = [
                    'user_id'   => $user->id,
                    'league_id' => $league->id,
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
                    DB::table('league_user_teams')->insert($keys + $pivot + ['created_at' => $now]);
                }
            }
        }
    }
}
