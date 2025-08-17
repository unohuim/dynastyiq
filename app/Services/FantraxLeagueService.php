<?php

namespace App\Services;

use App\Models\User;
use App\Models\FantraxLeague;
use App\Models\FantraxTeam;
use App\Models\FantraxLeagueUserTeam;
use Illuminate\Support\Facades\DB;

class FantraxLeagueService
{
    /**
     * Upsert leagues and user ↔ league pivots for a given user.
     *
     * @param \App\Models\User $user
     * @param array $leagues   Raw leagues array from Fantrax API
     * @return void
     */
    public function upsertLeaguesForUser(User $user, array $leagues): void
    {
        DB::transaction(function () use ($user, $leagues) {
            foreach ($leagues as $league) {
                // 1) Upsert league
                $fantraxLeague = FantraxLeague::updateOrCreate(
                    ['fantrax_league_id' => $league['leagueId']],
                    [
                        'league_name' => $league['leagueName'] ?? null,
                        'draft_type'  => $league['draftType'] ?? null,
                    ]
                );

                // 2) Upsert team for this league (creates our own internal team id)
                $team = FantraxTeam::updateOrCreate(
                    [
                        'fantrax_league_id' => $fantraxLeague->fantrax_league_id,
                        'fantrax_team_id'   => $league['teamId'],
                    ],
                    [
                        'name' => $league['teamName'] ?? null,
                    ]
                );

                // 3) Upsert pivot with our internal team id (no nulls)
                FantraxLeagueUserTeam::updateOrCreate(
                    [
                        'user_id'           => $user->id,
                        'fantrax_league_id' => $fantraxLeague->fantrax_league_id,
                        'fantrax_team_id'   => $team->fantrax_team_id,
                    ],
                    [
                        'is_active' => true,
                    ]
                );
            }
        });
    }

}
