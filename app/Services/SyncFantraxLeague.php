<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\FantraxTeamCreated;
use App\Models\PlatformLeague;
use App\Models\PlatformTeam;
use App\Traits\HasAPITrait;
use Carbon\CarbonInterface;
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


        $leagueInfo = [];

        try {
            $respLeagueInfo = $this->getAPIData('fantrax', 'league_info', [
                'leagueId' => (string) $league->platform_league_id,
            ]);
            $leagueInfo = is_array($respLeagueInfo) ? $respLeagueInfo : [];
        } catch (RequestException) {
            $leagueInfo = [];
        }

        $leaguePlayerEligibilityByFantraxId = self::leaguePlayerEligibilityByFantraxId($leagueInfo);

        //team rosters
        try {
            $respTeamRosters = $this->getAPIData('fantrax', 'team_rosters', [
                'leagueId' => (string) $league->platform_league_id,
            ]);
        } catch (RequestException) {
            return;
        }

        $now = now();
        $teamRosters = is_array($respTeamRosters) ? ($respTeamRosters['rosters'] ?? []) : [];
        self::rememberLeagueRosterDiagnostics($league, $now, [
            'stage' => 'response_received',
            'response_keys' => is_array($respTeamRosters) ? array_keys($respTeamRosters) : [],
            'has_rosters_key' => is_array($respTeamRosters) && array_key_exists('rosters', $respTeamRosters),
            'roster_count' => is_array($teamRosters) ? count($teamRosters) : 0,
            'roster_team_keys' => is_array($teamRosters) ? array_map('strval', array_keys($teamRosters)) : [],
            'league_info_keys' => array_keys($leagueInfo),
            'league_player_info_count' => count($leaguePlayerEligibilityByFantraxId),
        ]);

        if (empty($teamRosters)) {
            return;
        }

        $rows = [];

        foreach ($teamRosters as $teamId => $team) {
            if (! is_array($team)) {
                continue;
            }

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

                $teamIdMap[(string) $row['platform_team_id']] = (int) $platformTeam->id;

                // if ($platformTeam->wasRecentlyCreated) {
                //     $created[] = [$platformTeam->platform_league_id, (string) $platformTeam->id];
                // }
            }
        });

        $rosterTeamKeys = array_map('strval', array_keys($teamRosters));
        $dbTeamKeys = array_map('strval', array_keys($teamIdMap));
        self::rememberLeagueRosterDiagnostics($league, $now, [
            'stage' => 'team_key_match',
            'roster_team_keys' => $rosterTeamKeys,
            'db_team_keys' => $dbTeamKeys,
            'unmatched_roster_team_keys' => array_values(array_diff($rosterTeamKeys, $dbTeamKeys)),
            'unmatched_db_team_keys' => array_values(array_diff($dbTeamKeys, $rosterTeamKeys)),
        ]);

        // DB::afterCommit(static function () use ($created): void {
        //     foreach ($created as [$leagueId, $teamId]) {
        //         event(new FantraxTeamCreated($leagueId, $teamId));
        //     }
        // });

        foreach ($teamRosters as $fantraxTeamKey => $team) {
            if (! is_array($team)) {
                continue;
            }

            $platformTeamId = $teamIdMap[(string) $fantraxTeamKey] ?? null;

            if ($platformTeamId === null) {
                continue;
            }

            $rosterItems = self::rosterItems($team);
            self::rememberRosterDiagnostics($platformTeamId, $team, $now, [
                'stage' => 'seen_roster_payload',
                'roster_team_key' => (string) $fantraxTeamKey,
                'team_payload_keys' => array_keys($team),
                'roster_item_count' => count($rosterItems),
                'status_counts' => self::statusCounts($rosterItems),
            ]);
        }

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
            if (! is_array($team)) {
                continue;
            }

            foreach (self::rosterItems($team) as $it) {
                if (! is_array($it)) {
                    continue;
                }

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


        DB::transaction(static function () use (
            $teamRosters,
            $teamIdMap,
            $fantraxToPlayerId,
            $playerIdToFantrax,
            $leaguePlayerEligibilityByFantraxId,
            $now
        ): void {
            foreach ($teamRosters as $fantraxTeamKey => $team) {
                if (! is_array($team)) {
                    continue;
                }

                $fantraxTeamKey = (string) $fantraxTeamKey;

                if (! isset($teamIdMap[$fantraxTeamKey])) {
                    continue;
                }

                $platformTeamId = $teamIdMap[$fantraxTeamKey];

                $desiredFantrax = [];
                $rosterMetadataByFantraxId = [];
                $statusCounts = [];
                $metadataCounts = [
                    'with_slot' => 0,
                    'with_status' => 0,
                    'with_eligibility' => 0,
                    'minor_slot' => 0,
                    'reserve_slot' => 0,
                ];

                foreach (self::rosterItems($team) as $it) {
                    if (! is_array($it)) {
                        continue;
                    }

                    $rawStatus = strtoupper(trim((string) ($it['status'] ?? $it['rosterStatus'] ?? 'UNKNOWN')));
                    $statusKey = $rawStatus === '' ? 'UNKNOWN' : $rawStatus;
                    $statusCounts[$statusKey] = ($statusCounts[$statusKey] ?? 0) + 1;

                    if (isset($it['id'])) {
                        $fantraxId = (string) $it['id'];
                        $desiredFantrax[] = $fantraxId;
                        $metadata = self::rosterItemMetadata(
                            $it,
                            $leaguePlayerEligibilityByFantraxId[$fantraxId] ?? [],
                        );
                        $rosterMetadataByFantraxId[$fantraxId] = $metadata;

                        if (($metadata['slot'] ?? null) !== null) {
                            $metadataCounts['with_slot']++;
                        }

                        if (($metadata['status'] ?? null) !== null) {
                            $metadataCounts['with_status']++;
                        }

                        if (($metadata['eligibility'] ?? null) !== null) {
                            $metadataCounts['with_eligibility']++;
                        }

                        if (($metadata['slot'] ?? null) === 'MIN') {
                            $metadataCounts['minor_slot']++;
                        }

                        if (($metadata['slot'] ?? null) === 'RES') {
                            $metadataCounts['reserve_slot']++;
                        }
                    }
                }
                $desiredFantrax = array_values(array_unique($desiredFantrax));
                $desiredFantraxWithLeagueEligibilityCount = count(array_intersect(
                    $desiredFantrax,
                    array_keys($leaguePlayerEligibilityByFantraxId),
                ));

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
                $membershipMetadataUpdatedByPlatformPlayerIdCount = 0;
                $membershipMetadataUpdatedByPlayerIdCount = 0;

                foreach ($rosterMetadataByFantraxId as $fantraxId => $metadata) {
                    if ($metadata === []) {
                        continue;
                    }

                    $metadata['updated_at'] = $now;
                    $membershipMetadataUpdatedByPlatformPlayerIdCount += DB::table('platform_roster_memberships')
                        ->where('platform_team_id', $platformTeamId)
                        ->where('platform', 'fantrax')
                        ->where('platform_player_id', $fantraxId)
                        ->whereNull('ends_at')
                        ->update($metadata);
                }

                foreach ($desiredPlayerIds as $pid) {
                    $fantraxId = $playerIdToFantrax[$pid] ?? null;
                    $metadata = $fantraxId ? ($rosterMetadataByFantraxId[$fantraxId] ?? []) : [];

                    if ($metadata === []) {
                        continue;
                    }

                    $metadata['updated_at'] = $now;
                    $metadata['platform_player_id'] = $fantraxId;
                    $updated = DB::table('platform_roster_memberships')
                        ->where('platform_team_id', $platformTeamId)
                        ->where('platform', 'fantrax')
                        ->where('player_id', $pid)
                        ->whereNull('ends_at')
                        ->update($metadata);

                    $membershipMetadataUpdatedByPlayerIdCount += $updated;
                }

                if (! empty($toAdd)) {
                    $insert = [];

                    foreach ($toAdd as $pid) {
                        $fantraxId = $playerIdToFantrax[$pid] ?? null;
                        $insert[] = [
                            'platform_team_id'    => $platformTeamId,
                            'player_id'           => $pid,
                            'platform'            => 'fantrax',
                            'platform_player_id'  => $fantraxId,
                            'starts_at'           => $now,
                            'created_at'          => $now,
                            'updated_at'          => $now,
                        ] + ($fantraxId ? ($rosterMetadataByFantraxId[$fantraxId] ?? []) : []);
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

                self::rememberRosterDiagnostics($platformTeamId, $team, $now, [
                    'status_counts' => $statusCounts,
                    'metadata_counts' => $metadataCounts,
                    'desired_fantrax_count' => count($desiredFantrax),
                    'resolved_player_count' => count($desiredPlayerIds),
                    'membership_metadata_updated_by_platform_player_id_count' => $membershipMetadataUpdatedByPlatformPlayerIdCount,
                    'membership_metadata_updated_by_player_id_count' => $membershipMetadataUpdatedByPlayerIdCount,
                    'membership_metadata_updated_count' => $membershipMetadataUpdatedByPlatformPlayerIdCount + $membershipMetadataUpdatedByPlayerIdCount,
                    'membership_inserted_count' => count($toAdd),
                    'membership_closed_count' => count($toClose),
                    'league_player_info_count' => count($leaguePlayerEligibilityByFantraxId),
                    'rostered_players_with_league_eligibility_count' => $desiredFantraxWithLeagueEligibilityCount,
                    'rostered_players_missing_league_eligibility_count' => count($desiredFantrax) - $desiredFantraxWithLeagueEligibilityCount,
                ]);
            }
        });
    }

    /**
     * Extract optional Fantrax roster metadata from a roster item payload.
     *
     * @param array<string,mixed> $item
     * @param array<int,string> $leagueEligibility
     *
     * @return array<string,mixed>
     */
    private static function rosterItemMetadata(array $item, array $leagueEligibility = []): array
    {
        $evidence = self::rosterEvidence($item);
        $payloadStatus = self::firstString($item, ['status', 'rosterStatus']);
        $payloadSlot = self::slotFromStatus($payloadStatus) ?? self::firstString($item, [
            'slot',
            'rosterSlot',
            'lineupSlot',
            'fantasyPosition',
        ]);
        $slot = in_array($evidence['slot'], ['MIN', 'IR', 'BEN', 'RES'], true)
            ? $evidence['slot']
            : ($payloadSlot ?? $evidence['slot']);
        $slot = self::normalizeRosterSlot($slot);
        $status = self::membershipStatus($slot, $payloadStatus);
        $eligibility = $leagueEligibility !== [] ? $leagueEligibility : self::eligibility($item, $slot);

        return array_filter([
            'slot' => $slot,
            'status' => $status,
            'eligibility' => $eligibility === [] ? null : json_encode($eligibility),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * Build a league-specific Fantrax player eligibility map from getLeagueInfo.
     *
     * @param array<string,mixed> $leagueInfo
     *
     * @return array<string,array<int,string>>
     */
    private static function leaguePlayerEligibilityByFantraxId(array $leagueInfo): array
    {
        $playerInfo = $leagueInfo['playerInfo']
            ?? $leagueInfo['player_info']
            ?? data_get($leagueInfo, 'league.playerInfo')
            ?? [];

        if (! is_array($playerInfo)) {
            return [];
        }

        $eligibility = [];

        foreach ($playerInfo as $key => $info) {
            if (! is_array($info)) {
                continue;
            }

            $fantraxId = (string) ($info['id'] ?? $info['playerId'] ?? $info['player_id'] ?? $key);
            $positions = self::normalizeEligiblePositions(
                $info['eligiblePos']
                    ?? $info['eligiblePositions']
                    ?? $info['eligibility']
                    ?? null,
            );

            if ($fantraxId === '' || $positions === []) {
                continue;
            }

            $eligibility[$fantraxId] = $positions;
        }

        return $eligibility;
    }

    /**
     * Return the first non-empty string value from a payload.
     *
     * @param array<string,mixed> $item
     * @param array<int,string> $keys
     */
    private static function firstString(array $item, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $item[$key] ?? null;

            if (is_array($value)) {
                $value = $value['code'] ?? $value['shortName'] ?? $value['name'] ?? null;
            }

            $value = trim((string) $value);

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * Normalize optional Fantrax eligible position values.
     *
     * @param array<string,mixed> $item
     *
     * @return array<int,string>
     */
    private static function eligibility(array $item, ?string $fallback): array
    {
        $raw = $item['eligiblePositions']
            ?? $item['eligibility']
            ?? $item['positions']
            ?? $item['positionEligibility']
            ?? $item['position']
            ?? $item['pos']
            ?? null;

        $fallback = in_array($fallback, ['C', 'LW', 'RW', 'F', 'D', 'SKT', 'G'], true) ? $fallback : null;

        return self::normalizeEligiblePositions($raw ?? $fallback);
    }

    /**
     * Normalize provider eligibility values into stable Fantrax position codes.
     *
     * @param mixed $raw
     *
     * @return array<int,string>
     */
    private static function normalizeEligiblePositions(mixed $raw): array
    {
        $values = is_array($raw) ? $raw : preg_split('/[,\\/]/', (string) $raw);

        if (! is_array($values)) {
            return [];
        }

        return collect($values)
            ->flatten()
            ->map(static function (mixed $value): string {
                if (is_array($value)) {
                    $value = $value['code'] ?? $value['shortName'] ?? $value['name'] ?? '';
                }

                $value = strtoupper(trim((string) $value));

                return match ($value) {
                    'L' => 'LW',
                    'R' => 'RW',
                    'SKT', 'SKATER' => 'SKT',
                    default => $value,
                };
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Convert provider slot/status hints into platform membership status.
     */
    private static function membershipStatus(?string $slot, ?string $status): ?string
    {
        $value = strtoupper(trim((string) ($status ?: $slot)));

        return match ($value) {
            'ACTIVE' => 'active',
            'BN', 'BEN', 'BENCH', 'RES', 'RESERVE' => 'bench',
            'IR', 'IR+', 'INJURED', 'INJURED_RESERVE' => 'ir',
            'MIN', 'MINORS', 'MINOR' => 'na',
            'TAXI' => 'taxi',
            default => $value === '' ? null : 'active',
        };
    }

    /**
     * Convert Fantrax roster item status into a display slot when status is authoritative.
     */
    private static function slotFromStatus(?string $status): ?string
    {
        $status = strtoupper(trim((string) $status));

        return match ($status) {
            'ACTIVE' => null,
            'RESERVE' => 'RES',
            'MINORS', 'MINOR', 'MIN' => 'MIN',
            'IR', 'IR+', 'INJURED', 'INJURED_RESERVE' => 'IR',
            'BN', 'BEN', 'BENCH' => 'BEN',
            'TAXI' => 'TAXI',
            default => null,
        };
    }

    /**
     * Persist a compact provider evidence sample for roster debugging.
     *
     * @param array<string,mixed> $team
     */
    private static function rememberRosterDiagnostics(
        int $platformTeamId,
        array $team,
        CarbonInterface $now,
        array $summary = [],
    ): void {
        $items = collect(self::rosterItems($team))
            ->filter(static fn (mixed $item): bool => is_array($item))
            ->take(5)
            ->map(static fn (array $item): array => self::rosterEvidence($item))
            ->values()
            ->all();

        $platformTeam = PlatformTeam::query()->find($platformTeamId);

        if (! $platformTeam) {
            return;
        }

        $extras = $platformTeam->extras ?? [];
        $extras['fantrax_roster_sync'] = $summary + [
            'synced_at' => $now->toIso8601String(),
            'sample_items' => $items,
        ];
        $platformTeam->forceFill(['extras' => $extras])->save();
    }

    /**
     * Persist league-level roster diagnostics on all existing teams for the league.
     *
     * Platform leagues do not currently have an extras column, so this makes
     * early sync evidence visible even when provider roster keys do not match.
     *
     * @param array<string,mixed> $summary
     */
    private static function rememberLeagueRosterDiagnostics(
        PlatformLeague $league,
        CarbonInterface $now,
        array $summary,
    ): void {
        PlatformTeam::query()
            ->where('platform_league_id', $league->id)
            ->get()
            ->each(static function (PlatformTeam $platformTeam) use ($now, $summary): void {
                $extras = $platformTeam->extras ?? [];
                $extras['fantrax_roster_sync'] = $summary + [
                    'synced_at' => $now->toIso8601String(),
                ];

                $platformTeam->forceFill(['extras' => $extras])->save();
            });
    }

    /**
     * Return roster items from known Fantrax team payload shapes.
     *
     * @param array<string,mixed> $team
     *
     * @return array<int,mixed>
     */
    private static function rosterItems(array $team): array
    {
        $items = $team['rosterItems']
            ?? $team['roster_items']
            ?? $team['players']
            ?? [];

        return is_array($items) ? array_values($items) : [];
    }

    /**
     * Count provider statuses across roster items.
     *
     * @param array<int,mixed> $rosterItems
     *
     * @return array<string,int>
     */
    private static function statusCounts(array $rosterItems): array
    {
        $counts = [];

        foreach ($rosterItems as $item) {
            if (! is_array($item)) {
                continue;
            }

            $rawStatus = strtoupper(trim((string) ($item['status'] ?? $item['rosterStatus'] ?? 'UNKNOWN')));
            $statusKey = $rawStatus === '' ? 'UNKNOWN' : $rawStatus;
            $counts[$statusKey] = ($counts[$statusKey] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Extract slot/status evidence from known and nested Fantrax roster item fields.
     *
     * @param array<string,mixed> $item
     *
     * @return array{keys:array<int,string>,slot:?string,status:?string,raw_values:array<string,mixed>}
     */
    private static function rosterEvidence(array $item): array
    {
        $rawValues = [];

        foreach ($item as $key => $value) {
            $keyLower = strtolower((string) $key);

            if (
                str_contains($keyLower, 'slot')
                || str_contains($keyLower, 'status')
                || str_contains($keyLower, 'position')
                || str_contains($keyLower, 'lineup')
                || str_contains($keyLower, 'roster')
            ) {
                $rawValues[(string) $key] = self::compactValue($value);
            }
        }

        $flatValues = self::flattenScalarValues($rawValues);
        $prioritySlot = collect($flatValues)
            ->map(static fn (mixed $value): string => self::normalizeRosterSlot((string) $value) ?? '')
            ->first(static fn (string $value): bool => in_array($value, [
                'MIN',
                'IR',
                'BEN',
                'RES',
            ], true));

        $slot = $prioritySlot ?: collect($flatValues)
            ->map(static fn (mixed $value): string => self::normalizeRosterSlot((string) $value) ?? '')
            ->first(static fn (string $value): bool => in_array($value, [
                'C',
                'LW',
                'RW',
                'F',
                'D',
                'SKT',
                'G',
                'RES',
                'BEN',
                'IR',
                'MIN',
            ], true));

        $status = collect($flatValues)
            ->map(static fn (mixed $value): string => strtoupper(trim((string) $value)))
            ->first(static fn (string $value): bool => in_array($value, [
                'ACTIVE',
                'BENCH',
                'BEN',
                'BN',
                'IR',
                'INJURED',
                'MIN',
                'MINOR',
                'MINORS',
                'RES',
                'RESERVE',
            ], true));

        return [
            'keys' => array_keys($item),
            'slot' => $slot ?: null,
            'status' => $status ?: null,
            'raw_values' => $rawValues,
        ];
    }

    /**
     * Normalize Fantrax roster slot names into app display slots.
     */
    private static function normalizeRosterSlot(?string $slot): ?string
    {
        $slot = strtoupper(trim((string) $slot));

        return match ($slot) {
            '' => null,
            'L' => 'LW',
            'R' => 'RW',
            'BN', 'BENCH' => 'BEN',
            'RESERVE' => 'RES',
            'MINOR', 'MINORS', 'MINORS_ROSTER', 'MINORSROSTER' => 'MIN',
            default => $slot,
        };
    }

    /**
     * Reduce nested provider values to compact scalar evidence.
     */
    private static function compactValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        return collect($value)
            ->map(static fn (mixed $nested): mixed => self::compactValue($nested))
            ->take(8)
            ->all();
    }

    /**
     * Flatten scalar evidence values.
     *
     * @param array<string,mixed> $values
     *
     * @return array<int,mixed>
     */
    private static function flattenScalarValues(array $values): array
    {
        return collect($values)
            ->flatten()
            ->filter(static fn (mixed $value): bool => is_scalar($value) && trim((string) $value) !== '')
            ->values()
            ->all();
    }
}
