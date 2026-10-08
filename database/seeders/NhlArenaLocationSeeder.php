<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Seeds reviewed NHL home-venue coordinates needed for pregame travel context. */
class NhlArenaLocationSeeder extends Seeder
{
    public function run(): void
    {
        $teamIds = DB::table('nhl_teams')->pluck('nhl_id', 'abbrev');
        $venues = [
            ['ANA', 'Honda Center', 33.8078, -117.8765], ['BOS', 'TD Garden', 42.3662, -71.0621],
            ['BUF', 'KeyBank Center', 42.8750, -78.8764], ['CGY', 'Scotiabank Saddledome', 51.0374, -114.0519],
            ['CAR', 'PNC Arena', 35.8034, -78.7218], ['CHI', 'United Center', 41.8807, -87.6742],
            ['COL', 'Ball Arena', 39.7487, -105.0077], ['CBJ', 'Nationwide Arena', 39.9690, -83.0062],
            ['DAL', 'American Airlines Center', 32.7905, -96.8103], ['DET', 'Little Caesars Arena', 42.3410, -83.0550],
            ['EDM', 'Rogers Place', 53.5469, -113.4970], ['FLA', 'Amerant Bank Arena', 26.1584, -80.3256],
            ['LAK', 'Crypto.com Arena', 34.0430, -118.2673], ['MIN', 'Xcel Energy Center', 44.9448, -93.1011],
            ['MTL', 'Bell Centre', 45.4961, -73.5694], ['NSH', 'Bridgestone Arena', 36.1592, -86.7786],
            ['NJD', 'Prudential Center', 40.7336, -74.1711], ['NYI', 'UBS Arena', 40.7227, -73.5906],
            ['NYR', 'Madison Square Garden', 40.7505, -73.9934], ['OTT', 'Canadian Tire Centre', 45.2969, -75.9272],
            ['PHI', 'Wells Fargo Center', 39.9012, -75.1720], ['PIT', 'PPG Paints Arena', 40.4394, -79.9892],
            ['SJS', 'SAP Center at San Jose', 37.3328, -121.9011], ['SEA', 'Climate Pledge Arena', 47.6221, -122.3540],
            ['STL', 'Enterprise Center', 38.6268, -90.2026], ['TBL', 'Amalie Arena', 27.9427, -82.4518],
            ['TOR', 'Scotiabank Arena', 43.6435, -79.3791], ['VAN', 'Rogers Arena', 49.2778, -123.1089],
            ['VGK', 'T-Mobile Arena', 36.1029, -115.1785], ['WSH', 'Capital One Arena', 38.8981, -77.0209],
            ['WPG', 'Canada Life Centre', 49.8928, -97.1436], ['ARI', 'Mullett Arena', 33.4255, -111.9325, '2022-10-01', '2024-06-30'],
            ['UTA', 'Delta Center', 40.7683, -111.9011, '2024-07-01', null],
            // Utah's 2024–25 games retain ID 59, absent from the current team lookup.
            ['UTA', 'Delta Center', 40.7683, -111.9011, '2024-07-01', '2025-06-30', 59],
        ];

        $now = now();
        foreach ($venues as $venue) {
            [$abbrev, $name, $latitude, $longitude] = $venue;
            $from = $venue[4] ?? null;
            $to = $venue[5] ?? null;
            $teamId = $venue[6] ?? $teamIds->get($abbrev);
            if ($teamId === null) {
                continue;
            }

            DB::table('nhl_arena_locations')->updateOrInsert([
                'nhl_team_id' => $teamId,
                'venue_name' => $name,
                'effective_from' => $from ?? null,
            ], [
                'latitude' => $latitude,
                'longitude' => $longitude,
                'effective_to' => $to ?? null,
                'updated_at' => $now,
                'created_at' => $now,
            ]);
        }
    }
}
