<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\League;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LeaguesController extends Controller
{
    public function store(Request $request, Organization $organization, ?League $league = null): JsonResponse
    {
        $data = $request->validate([
            'name' => [$league ? 'sometimes' : 'required', 'string', 'min:2', 'max:120'],
            'sport' => ['nullable', 'string', 'max:50'],
            'platform' => ['nullable', 'required_with:platform_league_id', Rule::in(['fantrax', 'yahoo', 'espn'])],
            'platform_league_id' => ['nullable', 'required_with:platform', 'string', 'max:255'],
            'discord_server_id' => [
                'nullable',
                Rule::exists('discord_servers', 'id')->where('organization_id', $organization->id),
            ],
        ]);

        // 1) Use existing League from route or create a new one
        $league = $league ?? League::create([
            'name'  => $data['name'],
            'sport' => $data['sport'] ?? 'hockey',
        ]);

        // 2) If platform values provided, link PlatformLeague to this League
        if (!empty($data['platform']) && !empty($data['platform_league_id'])) {
            $pl = \App\Models\PlatformLeague::firstOrCreate(
                [
                    'platform'           => $data['platform'],
                    'platform_league_id' => (string) $data['platform_league_id'],
                ],
                [
                    'name'      => $data['name'] ?? $league->name,
                    'sport'     => $data['sport'] ?? 'hockey',
                    'synced_at' => now(),
                ]
            );

            $league->name = $pl->name;
            $league->save();

            if ($existing = $pl->league()->first()) {
                if ((int) $existing->id !== (int) $league->id) {
                    return response()->json([
                        'ok' => false,
                        'message' => 'Selected platform league is already linked to another league.',
                    ], 422);
                }
            } else {
                $league->platformLeagues()->syncWithoutDetaching([$pl->id]);
            }
        }

        // 3) Attach/refresh League ↔ Organization pivot
        $pivot = [
            'discord_server_id' => $data['discord_server_id'] ?? null,
            'linked_at' => now(),
        ];

        $organization->leagues()->whereKey($league->id)->exists()
            ? $organization->leagues()->updateExistingPivot($league->id, $pivot)
            : $organization->leagues()->attach($league->id, $pivot);

        return response()->json([
            'ok' => true,
            'league' => [
                'id' => $league->id,
                'name' => $league->name,
            ],
        ]);
    }


}
