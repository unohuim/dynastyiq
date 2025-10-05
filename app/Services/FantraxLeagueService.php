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
            $platformLeague = null;
            $team   = null;

            // 1) League + Team inside a small transaction
            DB::transaction(function () use ($payload, $now, &$platformLeague, &$team): void {
                // League (unique: platform + platform_league_id)

                $platformLeague = PlatformLeague::updateOrCreate(
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
                $team = PlatformTeam::updateOrCreate(
                    [
                        'platform_league_id'        => $platformLeague->id,
                        'platform_team_id' => (string) ($payload['teamId'] ?? ''),
                    ],
                    [
                        'name'       => $payload['teamName'] ?? 'Unnamed Team',
                        'short_name' => $payload['teamShortName'] ?? null,
                        'synced_at'  => $now,
                    ]
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
                    DB::table('league_user_teams')->insert($keys + $pivot + ['created_at' => $now]);
                }
            }
        }

    }
}
