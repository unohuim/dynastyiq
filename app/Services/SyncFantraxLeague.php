<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\FantraxTeamCreated;
use App\Models\PlatformLeague;
use App\Models\PlatformTeam;
use App\Traits\HasAPITrait;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class SyncFantraxLeague
{
    use HasAPITrait;

    public function sync(int $platformLeagueId): void
    {
        //Log::info('Attempting to find platform league: ', ['leagueId'=>$platformLeagueId]);

        $league = PlatformLeague::query()->find($platformLeagueId);

        if ($league === null || $league->platform !== 'fantrax') {
            return;
        }

        //Log::info('Found platform league', ['league'=>$league]);


        //team rosters
        try {
            $respTeamRosters = $this->getAPIData('fantrax', 'team_rosters', [
                'leagueId' => (string) $league->platform_league_id,
            ]);
        } catch (RequestException) {
            return;
        }

        $teamRosters = $respTeamRosters['rosters'] ?? [];
        if (empty($teamRosters)) {
            return;
        }

        $now = now();
        $rows = [];

        foreach ($teamRosters as $teamId => $team) {
            $rows[] = [
                'platform_league_id' => $league->id,
                'platform_team_id' => (string) ($teamId ?? ''),
                'name' => (string) ($team['teamName'] ?? 'Unnamed Team'),
                'short_name' => $team['shortName'] ?? null,
                'synced_at' => $now,
                'updated_at' => $now,
            ];
        }

        $teamIdMap = []; // [fantrax_team_key => platform_teams.id]
        $created = [];

        DB::transaction(static function () use ($rows, &$created, &$teamIdMap): void {
            foreach ($rows as $row) {
                $platformTeam = PlatformTeam::query()->updateOrCreate(
                    [
                        'platform_league_id' => $row['platform_league_id'],
                        'platform_team_id'   => $row['platform_team_id'],
                    ],
                    [
                        'name'       => $row['name'] ?? null,
                        'short_name' => $row['short_name'] ?? null,
                        'synced_at'  => now(),
                    ]
                );

                $teamIdMap[$row['platform_team_id']] = (int) $platformTeam->id;

                // if ($platformTeam->wasRecentlyCreated) {
                //     $created[] = [$platformTeam->platform_league_id, (string) $platformTeam->id];
                // }
            }
        });

        // DB::afterCommit(static function () use ($created): void {
        //     foreach ($created as [$leagueId, $teamId]) {
        //         event(new FantraxTeamCreated($leagueId, $teamId));
        //     }
        // });

        /**
         * New: sync pivot of Fantrax players on Platform Teams using platform_player_ids.
         * - Resolve Fantrax player IDs -> canonical player_id via platform_player_ids.
         * - Open memberships for players currently on a team.
         * - Close memberships for players no longer on that team.
         *
         * Assumes a history-aware table `platform_roster_memberships` with:
         *  - platform_team_id (fk), player_id (fk), platform (enum), platform_player_id (string, nullable),
         *  - starts_at (ts), ends_at (ts nullable), timestamps.
         */

        // Collect all Fantrax player ids from roster snapshot
        $allFantraxIds = [];
        foreach ($teamRosters as $team) {
            $items = $team['rosterItems'] ?? [];
            foreach ($items as $it) {
                if (isset($it['id'])) {
                    $allFantraxIds[] = (string) $it['id'];
                }
            }
        }
        $allFantraxIds = array_values(array_unique($allFantraxIds));

        if (empty($allFantraxIds)) {
            return;
        }

        // Map fantrax id -> player_id via fantrax_players
        $fantraxToPlayerId = DB::table('fantrax_players')
            ->whereIn('fantrax_id', $allFantraxIds)
            ->whereNotNull('player_id')
            ->pluck('player_id', 'fantrax_id')
            ->toArray();

        // Invert for quick lookup player_id -> fantrax id
        $playerIdToFantrax = [];
        foreach ($fantraxToPlayerId as $fxId => $pid) {
            $playerIdToFantrax[(int) $pid] = (string) $fxId;
        }

        // Populate/refresh platform_player_ids from fantrax_players for this snapshot
        $ppiUpserts = [];
        foreach ($fantraxToPlayerId as $fxId => $pid) {
            $ppiUpserts[] = [
                'player_id'          => (int) $pid,
                'platform'           => 'fantrax',
                'platform_player_id' => (string) $fxId,
                'created_at'         => $now,
                'updated_at'         => $now,
            ];
        }


        // 1) Dedupe by (platform, player_id)
        $dedup = [];
        foreach ($ppiUpserts as $row) {
            $k = $row['platform'] . ':' . $row['player_id'];
            if (isset($dedup[$k]) && $dedup[$k]['platform_player_id'] !== $row['platform_player_id']) {
                // Optional: log when multiple Fantrax IDs map to one player_id
                Log::warning('[FX Sync] Multiple Fantrax IDs for same player_id; keeping latest', [
                    'player_id' => $row['player_id'],
                    'old_fx'    => $dedup[$k]['platform_player_id'],
                    'new_fx'    => $row['platform_player_id'],
                ]);
            }
            $dedup[$k] = $row; // keep the last seen (or change policy to "first")
        }
        $ppiUpserts = array_values($dedup);


        // 2) Upsert using the unique pair that exists in DB: (platform, player_id)
        if (!empty($ppiUpserts)) {
            DB::table('platform_player_ids')->upsert(
                $ppiUpserts,
                ['platform', 'player_id'],                     // <-- matches uq_platform_player_link
                ['platform_player_id', 'updated_at']           // update FX id if it changed
            );
        }


        DB::transaction(static function () use ($teamRosters, $teamIdMap, $fantraxToPlayerId, $playerIdToFantrax, $now): void {
            foreach ($teamRosters as $fantraxTeamKey => $team) {
                if (! isset($teamIdMap[$fantraxTeamKey])) {
                    continue;
                }

                $platformTeamId = $teamIdMap[$fantraxTeamKey];

                $desiredFantrax = [];
                foreach (($team['rosterItems'] ?? []) as $it) {
                    if (isset($it['id'])) {
                        $desiredFantrax[] = (string) $it['id'];
                    }
                }
                $desiredFantrax = array_values(array_unique($desiredFantrax));

                // Translate to canonical player_ids, skip unresolved
                $desiredPlayerIds = [];
                foreach ($desiredFantrax as $fxId) {
                    if (isset($fantraxToPlayerId[$fxId])) {
                        $desiredPlayerIds[] = (int) $fantraxToPlayerId[$fxId];
                    }
                }
                $desiredPlayerIds = array_values(array_unique($desiredPlayerIds));

                // Current open memberships
                $currentPlayerIds = DB::table('platform_roster_memberships')
                    ->where('platform_team_id', $platformTeamId)
                    ->whereNull('ends_at')
                    ->pluck('player_id')
                    ->map(static fn ($v) => (int) $v)
                    ->all();

                $toAdd = array_values(array_diff($desiredPlayerIds, $currentPlayerIds));
                $toClose = array_values(array_diff($currentPlayerIds, $desiredPlayerIds));

                if (! empty($toAdd)) {
                    $insert = [];
                    foreach ($toAdd as $pid) {
                        $insert[] = [
                            'platform_team_id'    => $platformTeamId,
                            'player_id'           => $pid,
                            'platform'            => 'fantrax',
                            'platform_player_id'  => $playerIdToFantrax[$pid] ?? null,
                            'starts_at'           => $now,
                            'created_at'          => $now,
                            'updated_at'          => $now,
                        ];
                    }
                    DB::table('platform_roster_memberships')->insert($insert);
                }

                if (! empty($toClose)) {
                    DB::table('platform_roster_memberships')
                        ->where('platform_team_id', $platformTeamId)
                        ->whereNull('ends_at')
                        ->whereIn('player_id', $toClose)
                        ->update(['ends_at' => $now, 'updated_at' => $now]);
                }
            }
        });
    }
}
