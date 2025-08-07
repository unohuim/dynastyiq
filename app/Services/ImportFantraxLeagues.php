<?php

namespace App\Services;

use App\Models\League;
use Illuminate\Support\Arr;

class ImportFantraxLeagues
{
    /**
     * Import leagues from Fantrax JSON payload into DB.
     *
     * @param  array  $payload  Fantrax leagues JSON decoded as array
     * @param  int|null $userId User ID to attach to leagues via pivot
     * @return void
     */
    public function import(array $payload, ?int $userId = null): void
    {
        if (!isset($payload['leagues']) || !is_array($payload['leagues'])) {
            return;
        }

        foreach ($payload['leagues'] as $item) {
            $league = League::updateOrCreate(
                ['platform_league_id' => $item['leagueId']],
                [
                    'platform' => 'fantrax',
                    'name' => $item['leagueName'] ?? 'Unnamed League',
                    'sport' => $item['sport'] ?? null,
                    'discord_server_id' => null,
                    'draft_settings' => null,
                    'scoring_settings' => null,
                    'roster_settings' => null,
                ]
            );

            if ($userId) {
                // Attach user to league with default pivot values if not already attached
                if (!$league->users()->where('user_id', $userId)->exists()) {
                    $league->users()->attach($userId, [
                        'is_commish' => false,
                        'is_admin' => false,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }
}
