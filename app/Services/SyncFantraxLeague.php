<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Draft;
use App\Models\PlatformLeague;
use App\Models\PlatformTeam;
use App\Models\PlayerExternalIdentity;
use App\Traits\HasAPITrait;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class SyncFantraxLeague
{
    use HasAPITrait;

    public function __construct(
        private readonly SyncFantraxDraftState $draftStateSync,
        private readonly FantraxScoringCategoryMapper $scoringCategoryMapper,
        private readonly PlatformLeagueScoringCategoryService $scoringCategoryService,
        private readonly PlatformLeaguePlayerStatService $playerStatService,
    ) {
    }

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
        $this->syncLeagueSettings($league, $leagueInfo);
        $leagueShape = $this->fantraxLeagueShape($leagueInfo);
        $leagueTeamsByProviderId = $this->fantraxTeamsByProviderId($leagueInfo);

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
        $this->syncFantraxRosterSlots($league, $leagueInfo, $now);
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

            $teamId = (string) ($teamId ?? '');

            $rows[] = [
                'platform_league_id' => $league->id,
                'platform_team_id' => $teamId,
                'name' => (string) ($team['teamName'] ?? 'Unnamed Team'),
                'short_name' => $team['shortName'] ?? null,
                'logo_url' => self::teamLogoUrl($team),
                'fantrax_team_context' => $leagueTeamsByProviderId[$teamId] ?? [],
                'synced_at' => $now,
                'updated_at' => $now,
            ];
        }

        $teamIdMap = [];

        DB::transaction(static function () use ($rows, &$teamIdMap): void {
            foreach ($rows as $row) {
                $values = [
                    'name'       => $row['name'] ?? null,
                    'short_name' => $row['short_name'] ?? null,
                    'synced_at'  => now(),
                ];

                if (! empty($row['logo_url'])) {
                    $values['logo_url'] = $row['logo_url'];
                }

                $platformTeam = PlatformTeam::query()->firstOrNew([
                    'platform_league_id' => $row['platform_league_id'],
                    'platform_team_id'   => $row['platform_team_id'],
                ]);
                $extras = is_array($platformTeam->extras) ? $platformTeam->extras : [];
                $fantraxContext = is_array($row['fantrax_team_context'] ?? null) ? $row['fantrax_team_context'] : [];

                if ($fantraxContext !== []) {
                    $values['extras'] = array_merge($extras, [
                        'fantrax' => array_merge(
                            is_array($extras['fantrax'] ?? null) ? $extras['fantrax'] : [],
                            $fantraxContext,
                        ),
                    ]);
                }

                $platformTeam->fill($values);
                $platformTeam->save();

                $teamIdMap[(string) $row['platform_team_id']] = (int) $platformTeam->id;
            }
        });
        $this->mergeFantraxRosterShapeDetections($league, $teamRosters, $leagueShape);

        $rosterTeamKeys = array_map('strval', array_keys($teamRosters));
        $dbTeamKeys = array_map('strval', array_keys($teamIdMap));
        self::rememberLeagueRosterDiagnostics($league, $now, [
            'stage' => 'team_key_match',
            'roster_team_keys' => $rosterTeamKeys,
            'db_team_keys' => $dbTeamKeys,
            'unmatched_roster_team_keys' => array_values(array_diff($rosterTeamKeys, $dbTeamKeys)),
            'unmatched_db_team_keys' => array_values(array_diff($dbTeamKeys, $rosterTeamKeys)),
        ]);

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

        $fantraxToPlayerId = self::canonicalPlayerIdsByFantraxId($allFantraxIds, $now);

        $this->playerStatService->syncFromRosterPayload(
            $league,
            $teamRosters,
            $teamIdMap,
            $fantraxToPlayerId,
            $now,
        );
        $this->syncProviderPlayerStats($league, $teamIdMap, $fantraxToPlayerId, $now);

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
                        $metadata = $fantraxId ? ($rosterMetadataByFantraxId[$fantraxId] ?? []) : [];
                        $insert[] = [
                            'platform_team_id'    => $platformTeamId,
                            'player_id'           => $pid,
                            'platform'            => 'fantrax',
                            'platform_player_id'  => $fantraxId,
                            'slot' => $metadata['slot'] ?? null,
                            'status' => $metadata['status'] ?? null,
                            'eligibility' => $metadata['eligibility'] ?? null,
                            'metadata' => $metadata['metadata'] ?? null,
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

        $this->refreshReadOnlyDraftMirror($league);
    }

    /**
     * Mirror Fantrax draft data for all league users when Fantrax exposes it.
     */
    private function refreshReadOnlyDraftMirror(PlatformLeague $league): void
    {
        try {
            $this->draftStateSync->syncIfAvailable((int) $league->id);
        } catch (Throwable $throwable) {
            Log::info('[FX Sync] Draft mirror refresh skipped', [
                'platform_league_id' => $league->id,
                'provider_league_id' => $league->platform_league_id,
                'message' => $throwable->getMessage(),
            ]);
        }
    }

    /**
     * Sync provider-earned player stats only when a verified Fantrax endpoint is configured.
     *
     * Fantrax's public API does not currently expose individual fantasy stat totals.
     * This path is intentionally dormant until a real provider payload is available.
     *
     * @param array<string,int> $teamIdMap
     * @param array<string,int> $fantraxToPlayerId
     */
    private function syncProviderPlayerStats(
        PlatformLeague $league,
        array $teamIdMap,
        array $fantraxToPlayerId,
        CarbonInterface $now,
    ): void {
        if (! array_key_exists('player_stats', config('apiurls.fantrax.endpoints', []))) {
            return;
        }

        try {
            $payload = $this->getAPIData('fantrax', 'player_stats', [
                'leagueId' => (string) $league->platform_league_id,
            ]);
        } catch (RequestException $exception) {
            Log::info('[FX Sync] Player stats endpoint skipped', [
                'platform_league_id' => $league->id,
                'provider_league_id' => $league->platform_league_id,
                'message' => $exception->getMessage(),
            ]);

            return;
        } catch (Throwable $throwable) {
            Log::info('[FX Sync] Player stats sync skipped', [
                'platform_league_id' => $league->id,
                'provider_league_id' => $league->platform_league_id,
                'message' => $throwable->getMessage(),
            ]);

            return;
        }

        if (! is_array($payload)) {
            return;
        }

        $syncedCount = $this->playerStatService->syncFromProviderPayload(
            $league,
            $payload,
            $teamIdMap,
            $fantraxToPlayerId,
            $now,
        );

        if ($syncedCount > 0) {
            Log::info('[FX Sync] Player stats synced', [
                'platform_league_id' => $league->id,
                'provider_league_id' => $league->platform_league_id,
                'synced_count' => $syncedCount,
            ]);
        }
    }

    /**
     * Persist Fantrax league settings and scoring categories in the shared league shape.
     *
     * @param array<string,mixed> $leagueInfo
     */
    private function syncLeagueSettings(PlatformLeague $league, array $leagueInfo): void
    {
        if ($leagueInfo === []) {
            return;
        }

        $leagueShape = $this->fantraxLeagueShape($leagueInfo);
        $existingSettings = array_replace(
            ['custom_cap' => false],
            is_array($league->settings) ? $league->settings : [],
        );
        $existingScoringSettings = is_array($league->scoring_settings) ? $league->scoring_settings : [];
        $manualMappings = is_array($existingScoringSettings['manual_mappings'] ?? null)
            ? $existingScoringSettings['manual_mappings']
            : [];
        $normalizedCategories = $this->scoringCategoryMapper->enrich(
            $this->fantraxScoringCategoryPayloads($leagueInfo),
        );

        if ($normalizedCategories === []) {
            $league->forceFill([
                'settings' => array_merge($existingSettings, [
                    'fantrax_league_info_keys' => array_keys($leagueInfo),
                    'league_shape' => $leagueShape,
                ]),
            ])->save();

            return;
        }

        $categories = $this->applyManualScoringMappings(
            $normalizedCategories,
            $manualMappings,
        );
        $rawScoringPayload = [
            'scoringSystem' => $leagueInfo['scoringSystem'] ?? null,
            'scoringCategorySettings' => $leagueInfo['scoringCategorySettings'] ?? null,
            'scoringCategories' => $leagueInfo['scoringCategories'] ?? null,
        ];

        $this->scoringCategoryService->sync($league, $categories, $manualMappings);

        $league->forceFill([
            'settings' => array_merge($existingSettings, [
                'fantrax_league_info_keys' => array_keys($leagueInfo),
                'league_shape' => $leagueShape,
            ]),
            'scoring_settings' => [
                'type' => $this->nullableScoringString(data_get($leagueInfo, 'scoringSystem.type')),
                'season_year' => data_get($leagueInfo, 'seasonYear'),
                'start_date' => data_get($leagueInfo, 'startDate'),
                'end_date' => data_get($leagueInfo, 'endDate'),
                'categories' => $categories,
                'manual_mappings' => $manualMappings,
                'raw_payload' => $rawScoringPayload,
            ],
        ])->save();
    }

    /**
     * Build the provider league-shape payload persisted on platform_leagues.settings.
     *
     * @param array<string,mixed> $leagueInfo
     *
     * @return array<string,mixed>
     */
    private function fantraxLeagueShape(array $leagueInfo): array
    {
        $duplicatePlayerType = strtoupper(trim((string) data_get($leagueInfo, 'poolSettings.duplicatePlayerType')));
        $teamsByProviderId = $this->fantraxTeamsByProviderId($leagueInfo);
        $divisions = collect($teamsByProviderId)
            ->pluck('division')
            ->filter(static fn (mixed $value): bool => trim((string) $value) !== '')
            ->map(static fn (mixed $value): string => (string) $value)
            ->unique()
            ->values()
            ->all();
        $teamDivisions = collect($teamsByProviderId)
            ->mapWithKeys(static function (array $team, string $teamId): array {
                $division = trim((string) ($team['division'] ?? ''));

                return $division !== '' ? [$teamId => $division] : [];
            })
            ->all();
        $playerPoolScope = match ($duplicatePlayerType) {
            'NONE' => 'league',
            'ACROSS_DIVISIONS' => 'division',
            default => 'unknown',
        };

        return [
            'duplicate_player_type' => $duplicatePlayerType !== '' ? $duplicatePlayerType : null,
            'player_pool_scope' => $playerPoolScope,
            'team_count' => count($teamsByProviderId),
            'division_count' => count($divisions),
            'divisions' => $divisions,
            'team_divisions' => $teamDivisions,
            'scoring_type' => $this->nullableScoringString(data_get($leagueInfo, 'scoringSystem.type')),
            'roster_period_count' => $this->countArray(data_get($leagueInfo, 'rosterPeriods')),
            'scoring_period_count' => $this->countArray(data_get($leagueInfo, 'scoringPeriods')),
            'custom_salary_detected' => false,
            'contract_codes_detected' => [],
            'draft_shape' => $playerPoolScope === 'division' ? 'division_scoped' : 'flat',
        ];
    }

    /**
     * Extract Fantrax team metadata from all observed getLeagueInfo team shapes.
     *
     * @param array<string,mixed> $leagueInfo
     *
     * @return array<string,array<string,mixed>>
     */
    private function fantraxTeamsByProviderId(array $leagueInfo): array
    {
        $teams = [];
        $teamInfo = $leagueInfo['teamInfo']
            ?? $leagueInfo['team_info']
            ?? data_get($leagueInfo, 'league.teamInfo')
            ?? [];

        if (is_array($teamInfo)) {
            foreach ($teamInfo as $key => $team) {
                if (! is_array($team)) {
                    continue;
                }

                $teamId = trim((string) ($team['id'] ?? $team['teamId'] ?? $team['team_id'] ?? $key));

                if ($teamId === '') {
                    continue;
                }

                $teams[$teamId] = $this->fantraxTeamContext($team);
            }
        }

        foreach ($leagueInfo as $key => $team) {
            if (! is_array($team) || ! $this->looksLikeFantraxTeam($team, (string) $key)) {
                continue;
            }

            $teamId = trim((string) ($team['id'] ?? $team['teamId'] ?? $team['team_id'] ?? $key));

            if ($teamId === '') {
                continue;
            }

            $teams[$teamId] = array_merge($teams[$teamId] ?? [], $this->fantraxTeamContext($team));
        }

        return $teams;
    }

    /**
     * @param array<string,mixed> $team
     *
     * @return array<string,mixed>
     */
    private function fantraxTeamContext(array $team): array
    {
        return array_filter([
            'division' => $this->nullableString($team['division'] ?? $team['divisionName'] ?? $team['division_name'] ?? null),
            'pool' => $this->nullableString($team['pool'] ?? $team['poolName'] ?? $team['pool_name'] ?? null),
            'raw_team_info' => $team,
        ], static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function looksLikeFantraxTeam(array $payload, string $key): bool
    {
        if (in_array($key, ['teamInfo', 'team_info'], true)) {
            return false;
        }

        return isset($payload['id'], $payload['name'])
            || isset($payload['teamId'], $payload['teamName'])
            || isset($payload['division'], $payload['name']);
    }

    /**
     * Upsert provider roster slot settings from Fantrax position constraints.
     *
     * @param array<string,mixed> $leagueInfo
     */
    private function syncFantraxRosterSlots(PlatformLeague $league, array $leagueInfo, CarbonInterface $now): void
    {
        $constraints = data_get($leagueInfo, 'rosterInfo.positionConstraints')
            ?? data_get($leagueInfo, 'league.rosterInfo.positionConstraints')
            ?? [];

        if (! is_array($constraints) || $constraints === []) {
            return;
        }

        $rows = [];

        foreach ($constraints as $slot => $constraint) {
            if (! is_array($constraint)) {
                $constraint = ['maxActive' => $constraint];
            }

            $slot = strtoupper(trim((string) $slot));

            if ($slot === '') {
                continue;
            }

            $rows[] = [
                'platform_league_id' => $league->id,
                'slot' => $slot,
                'slot_type' => $this->rosterSlotType($slot),
                'position_type' => $slot === 'G' ? 'goalie' : 'skater',
                'count' => $this->integerSetting(
                    $constraint['maxActive']
                    ?? $constraint['count']
                    ?? $constraint['max']
                    ?? null,
                ) ?? 0,
                'sort_order' => $this->fantraxRosterSlotSortOrder($slot),
                'raw_payload' => json_encode($constraint),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows === []) {
            return;
        }

        DB::table('platform_league_roster_slots')->upsert(
            $rows,
            ['platform_league_id', 'slot'],
            ['slot_type', 'position_type', 'count', 'sort_order', 'raw_payload', 'updated_at'],
        );
    }

    /**
     * Merge getTeamRosters salary/contract clues into persisted Fantrax league shape.
     *
     * @param array<string,mixed> $teamRosters
     * @param array<string,mixed> $leagueShape
     */
    private function mergeFantraxRosterShapeDetections(
        PlatformLeague $league,
        array $teamRosters,
        array $leagueShape,
    ): void {
        $contractCodes = [];
        $customSalaryDetected = false;

        foreach ($teamRosters as $team) {
            if (! is_array($team)) {
                continue;
            }

            foreach (self::rosterItems($team) as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $customSalaryDetected = $customSalaryDetected || is_numeric($item['salary'] ?? null);
                $contractCode = data_get($item, 'contract.smallId') ?? data_get($item, 'contract.name');

                if (trim((string) $contractCode) !== '') {
                    $contractCodes[] = trim((string) $contractCode);
                }
            }
        }

        $settings = is_array($league->settings) ? $league->settings : [];
        $settings['league_shape'] = array_merge($leagueShape, [
            'custom_salary_detected' => $customSalaryDetected,
            'contract_codes_detected' => array_values(array_unique($contractCodes)),
        ]);

        $league->forceFill(['settings' => $settings])->save();
    }

    /**
     * Count a provider array only when it is present as an array.
     */
    private function countArray(mixed $value): int
    {
        return is_array($value) ? count($value) : 0;
    }

    /**
     * Normalize an optional provider string to null when empty.
     */
    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    /**
     * Normalize optional provider integer settings.
     */
    private function integerSetting(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Map Fantrax roster constraint slots to the platform slot-type enum.
     */
    private function rosterSlotType(string $slot): string
    {
        return match (strtoupper(trim($slot))) {
            'BEN', 'BN', 'RES' => 'bench',
            'IR', 'IR+', 'IL' => 'injured',
            'MIN', 'MINORS', 'NA' => 'minor',
            'F', 'SKT', 'UTIL' => 'utility',
            default => 'starter',
        };
    }

    /**
     * Return DynastyIQ's hockey roster display order for provider slot settings.
     */
    private function fantraxRosterSlotSortOrder(string $slot): int
    {
        return match (strtoupper(trim($slot))) {
            'C' => 10,
            'LW' => 20,
            'RW' => 30,
            'W' => 40,
            'F' => 50,
            'D' => 60,
            'SKT' => 70,
            'UTIL' => 80,
            'G' => 90,
            'BEN', 'BN', 'RES' => 100,
            'IR', 'IR+', 'IL' => 110,
            'MIN', 'MINORS', 'NA' => 120,
            default => 900,
        };
    }

    /**
     * Normalize Fantrax scoring settings to editable category rows.
     *
     * @param array<string,mixed> $payload
     *
     * @return array<int,array<string,mixed>>
     */
    private function fantraxScoringCategoryPayloads(array $payload): array
    {
        $groups = data_get($payload, 'scoringSystem.scoringCategorySettings')
            ?? data_get($payload, 'scoringCategorySettings')
            ?? [];
        $simple = data_get($payload, 'scoringSystem.scoringCategories')
            ?? data_get($payload, 'scoringCategories')
            ?? [];
        $rows = [];
        $shortAliases = [];
        $sortOrder = 1;

        foreach ((array) $groups as $groupBlock) {
            $groupCode = $this->scoringGroupCode((string) (data_get($groupBlock, 'group.code') ?? 'UNKNOWN'));

            foreach ((array) data_get($groupBlock, 'configs', []) as $config) {
                $category = data_get($config, 'scoringCategory', []);
                $code = (string) data_get($category, 'code');

                if ($code === '') {
                    continue;
                }

                $key = $groupCode . ':' . $code;
                $points = $this->parseFantraxPoints(
                    data_get($config, 'weight')
                    ?? data_get($config, 'points')
                    ?? data_get($category, 'points')
                );
                $shortName = (string) (data_get($category, 'shortName') ?? $code);
                $autoStatKey = $this->autoStatKey($shortName, data_get($category, 'name'));

                $rows[$key] ??= [
                    'id' => $key,
                    'label' => (string) (data_get($category, 'name') ?: $shortName ?: $code),
                    'name' => (string) (data_get($category, 'name') ?? ''),
                    'short' => $shortName,
                    'value' => $points,
                    'auto_stat_key' => $autoStatKey,
                    'stat_key' => $autoStatKey,
                    'is_mapped' => $autoStatKey !== null,
                    'mapping_source' => $autoStatKey !== null ? 'auto' : null,
                    'position_values' => [],
                    'sort_order' => $sortOrder++,
                    'raw_payload' => [
                        'config' => $config,
                        'scoringCategory' => $category,
                    ],
                ];

                $alias = $this->scoringAliasKey($groupCode, $shortName);

                if ($alias !== null) {
                    $shortAliases[$alias] = $key;
                }

                $position = (string) (data_get($config, 'position.code') ?? 'DEFAULT');
                $rows[$key]['position_values'][$position] = $points;
            }
        }

        foreach ((array) $simple as $group => $categories) {
            foreach ((array) $categories as $short => $value) {
                $groupCode = $this->scoringGroupCode((string) $group);
                $shortCode = (string) $short;
                $alias = $this->scoringAliasKey($groupCode, $shortCode);
                $key = $alias !== null && isset($shortAliases[$alias])
                    ? $shortAliases[$alias]
                    : $groupCode . ':' . $shortCode;
                $autoStatKey = $this->autoStatKey($shortCode, null);

                $rows[$key] ??= [
                    'id' => $key,
                    'label' => $shortCode,
                    'name' => '',
                    'short' => $shortCode,
                    'value' => is_array($value) ? null : $this->parseFantraxPoints($value),
                    'auto_stat_key' => $autoStatKey,
                    'stat_key' => $autoStatKey,
                    'is_mapped' => $autoStatKey !== null,
                    'mapping_source' => $autoStatKey !== null ? 'auto' : null,
                    'position_values' => [],
                    'sort_order' => $sortOrder++,
                    'raw_payload' => $value,
                ];

                if (! is_array($value)) {
                    $parsedValue = $this->parseFantraxPoints($value);
                    if (! is_numeric($rows[$key]['value'] ?? null)) {
                        $rows[$key]['value'] = $parsedValue;
                    }
                }
            }
        }

        return array_values($rows);
    }

    /**
     * Build a stable alias key for matching Fantrax rich scoring rows to short-code rows.
     */
    private function scoringAliasKey(string $groupCode, string $shortName): ?string
    {
        $groupCode = trim($groupCode);
        $shortName = strtoupper(trim($shortName));

        if ($groupCode === '' || $shortName === '') {
            return null;
        }

        return $groupCode . ':' . $shortName;
    }

    /**
     * Normalize Fantrax shorthand scoring group keys to their rich group codes.
     */
    private function scoringGroupCode(string $groupCode): string
    {
        $groupCode = strtoupper(trim($groupCode));

        return match ($groupCode) {
            'SKATING' => 'HOCKEY_SKATING',
            'GOALIE' => 'HOCKEY_GOALIE',
            default => $groupCode !== '' ? $groupCode : 'UNKNOWN',
        };
    }

    /**
     * Normalize optional Fantrax scoring-system strings.
     */
    private function nullableScoringString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? strtolower($value) : null;
    }

    /**
     * Apply persisted manual stat-key mappings over auto mappings.
     *
     * @param array<int,array<string,mixed>> $categories
     * @param array<string,string> $manualMappings
     *
     * @return array<int,array<string,mixed>>
     */
    private function applyManualScoringMappings(array $categories, array $manualMappings): array
    {
        return collect($categories)
            ->map(static function (array $category) use ($manualMappings): array {
                $id = (string) ($category['id'] ?? '');
                $manualStatKey = $manualMappings[$id] ?? null;
                $statKey = $manualStatKey ?: ($category['auto_stat_key'] ?? null);

                $category['stat_key'] = $statKey;
                $category['is_mapped'] = $statKey !== null && $statKey !== '';
                $category['mapping_source'] = $manualStatKey ? 'manual' : ($category['mapping_source'] ?? null);

                if ($manualStatKey) {
                    $category['is_supported'] = true;
                    $category['support_message'] = null;
                }

                return $category;
            })
            ->values()
            ->all();
    }

    /**
     * Resolve common Fantrax category labels to DynastyIQ stat keys.
     */
    private function autoStatKey(?string $short, ?string $name): ?string
    {
        $map = [
            'a' => 'a',
            'ast' => 'a',
            'assist' => 'a',
            'assists' => 'a',
            'blk' => 'b',
            'blocks' => 'b',
            'g' => 'g',
            'goal' => 'g',
            'goals' => 'g',
            'gaa' => 'gaa',
            'gwg' => 'gwg',
            'hit' => 'h',
            'hits' => 'h',
            'pim' => 'pim',
            '+/-' => 'plus_minus',
            'plus minus' => 'plus_minus',
            'ppg' => 'ppg',
            'ppa' => 'ppa',
            'ppp' => 'ppp',
            'shg' => 'pkg',
            'short handed goals' => 'pkg',
            'short-handed goals' => 'pkg',
            'sha' => 'pka',
            'short handed assists' => 'pka',
            'short-handed assists' => 'pka',
            'shp' => 'pkp',
            'short handed points' => 'pkp',
            'short-handed points' => 'pkp',
            'pts' => 'pts',
            'points' => 'pts',
            'sog' => 'sog',
            'shots on goal' => 'sog',
            'sv' => 'sv',
            'saves' => 'sv',
            'sv%' => 'sv_pct',
            'save percentage' => 'sv_pct',
            'w' => 'wins',
            'wins' => 'wins',
            'so' => 'so',
            'shutouts' => 'so',
        ];

        foreach ([$short, $name] as $label) {
            $normalized = strtolower(trim((string) $label));
            $normalized = preg_replace('/\s+/', ' ', $normalized);

            if ($normalized !== '' && isset($map[$normalized])) {
                return $map[$normalized];
            }
        }

        return null;
    }

    /**
     * Parse Fantrax point strings such as points0.5.
     */
    private function parseFantraxPoints(mixed $value): int|float|string|null
    {
        if (is_numeric($value)) {
            $number = (float) $value;

            return floor($number) === $number ? (int) $number : $number;
        }

        if (is_string($value) && preg_match('/^points(-?\d+(?:\.\d+)?)$/i', trim($value), $matches)) {
            $number = (float) $matches[1];

            return floor($number) === $number ? (int) $number : $number;
        }

        return is_string($value) ? $value : null;
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
            'metadata' => self::rosterItemProviderMetadata($item),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * Extract provider-specific roster metadata that powers Fantrax-only UI features.
     *
     * @param array<string,mixed> $item
     */
    private static function rosterItemProviderMetadata(array $item): ?string
    {
        $metadata = [];
        $salary = $item['salary'] ?? null;

        if (is_numeric($salary)) {
            $metadata['fantrax_salary'] = (int) $salary;
        }

        if (is_array($item['contract'] ?? null)) {
            $contract = array_filter([
                'small_id' => self::firstString($item['contract'], ['smallId', 'small_id']),
                'name' => self::firstString($item['contract'], ['name']),
            ], static fn (?string $value): bool => $value !== null && $value !== '');

            if ($contract !== []) {
                $metadata['fantrax_contract'] = $contract;
            }
        }

        if ($metadata === []) {
            return null;
        }

        return json_encode($metadata) ?: null;
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

        self::collectFantraxPlayerEligibility($playerInfo, $eligibility);

        return $eligibility;
    }

    /**
     * Collect eligibility from flat and division-grouped Fantrax playerInfo payloads.
     *
     * @param array<string,mixed> $playerInfo
     * @param array<string,array<int,string>> $eligibility
     */
    private static function collectFantraxPlayerEligibility(array $playerInfo, array &$eligibility): void
    {
        foreach ($playerInfo as $key => $info) {
            if (! is_array($info)) {
                continue;
            }

            if (self::looksLikeFantraxPlayerInfo($info)) {
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

                $eligibility[$fantraxId] = array_values(array_unique(array_merge(
                    $eligibility[$fantraxId] ?? [],
                    $positions,
                )));

                continue;
            }

            self::collectFantraxPlayerEligibility($info, $eligibility);
        }
    }

    /**
     * @param array<string,mixed> $info
     */
    private static function looksLikeFantraxPlayerInfo(array $info): bool
    {
        return array_key_exists('eligiblePos', $info)
            || array_key_exists('eligiblePositions', $info)
            || array_key_exists('eligibility', $info)
            || array_key_exists('status', $info)
            || array_key_exists('playerId', $info)
            || array_key_exists('player_id', $info);
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
     * Resolve a Fantrax team logo URL from explicit payload fields.
     *
     * @param array<string,mixed> $team
     */
    private static function teamLogoUrl(array $team): ?string
    {
        foreach (['teamLogoUrl', 'teamLogoURL', 'teamLogo', 'logoUrl', 'logoURL', 'logo_url', 'avatarUrl', 'avatar_url', 'imageUrl', 'image_url', 'iconUrl', 'icon_url'] as $key) {
            $value = data_get($team, $key);

            if (is_string($value) && filled($value)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Resolve Fantrax player ids to canonical player ids.
     *
     * The staged fantrax_players table is the normal fast path, but matched
     * PlayerExternalIdentity rows are the authoritative repair path when a
     * mirror row is stale or has been nulled during duplicate cleanup.
     *
     * @param array<int,string> $fantraxIds
     * @return array<string,int>
     */
    private static function canonicalPlayerIdsByFantraxId(array $fantraxIds, CarbonInterface $now): array
    {
        $fantraxIds = array_values(array_unique(array_filter(
            array_map(static fn (string $fantraxId): string => trim($fantraxId), $fantraxIds),
            static fn (string $fantraxId): bool => $fantraxId !== '',
        )));

        if ($fantraxIds === []) {
            return [];
        }

        $fantraxToPlayerId = DB::table('fantrax_players')
            ->whereIn('fantrax_id', $fantraxIds)
            ->whereNotNull('player_id')
            ->pluck('player_id', 'fantrax_id')
            ->map(static fn (mixed $playerId): int => (int) $playerId)
            ->toArray();

        $unresolvedFantraxIds = array_values(array_diff($fantraxIds, array_keys($fantraxToPlayerId)));

        if ($unresolvedFantraxIds === []) {
            return $fantraxToPlayerId;
        }

        $identityFallbacks = PlayerExternalIdentity::query()
            ->where('provider', PlayerExternalIdentity::PROVIDER_FANTRAX)
            ->where('match_status', PlayerExternalIdentity::STATUS_MATCHED)
            ->whereIn('provider_player_id', $unresolvedFantraxIds)
            ->whereNotNull('player_id')
            ->pluck('player_id', 'provider_player_id')
            ->map(static fn (mixed $playerId): int => (int) $playerId)
            ->toArray();

        if ($identityFallbacks === []) {
            return $fantraxToPlayerId;
        }

        foreach ($identityFallbacks as $fantraxId => $playerId) {
            $fantraxToPlayerId[(string) $fantraxId] = $playerId;
        }

        self::mirrorRecoveredFantraxPlayerLinks($identityFallbacks, $now);

        return $fantraxToPlayerId;
    }

    /**
     * Repair stale Fantrax mirror rows when the linked PEI can safely identify
     * the canonical player.
     *
     * @param array<string,int> $fantraxToPlayerId
     */
    private static function mirrorRecoveredFantraxPlayerLinks(array $fantraxToPlayerId, CarbonInterface $now): void
    {
        foreach ($fantraxToPlayerId as $fantraxId => $playerId) {
            $fantraxId = (string) $fantraxId;
            $playerId = (int) $playerId;

            $existingForPlayer = DB::table('fantrax_players')
                ->where('player_id', $playerId)
                ->where('fantrax_id', '!=', $fantraxId)
                ->exists();

            if ($existingForPlayer) {
                Log::warning('[FX Sync] PEI fallback resolved rostered Fantrax player, but mirror row is claimed by another Fantrax id.', [
                    'fantrax_id' => $fantraxId,
                    'player_id' => $playerId,
                ]);

                continue;
            }

            $updated = DB::table('fantrax_players')
                ->where('fantrax_id', $fantraxId)
                ->update([
                    'player_id' => $playerId,
                    'updated_at' => $now,
                ]);

            if ($updated === 0) {
                DB::table('fantrax_players')->insert([
                    'fantrax_id' => $fantraxId,
                    'player_id' => $playerId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
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
