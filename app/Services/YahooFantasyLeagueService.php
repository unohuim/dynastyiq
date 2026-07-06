<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\SyncYahooTeamRosterJob;
use App\Models\PlatformLeague;
use App\Models\PlatformLeagueRosterSlot;
use App\Models\PlatformTeam;
use App\Models\YahooFantasyConnection;
use App\Support\FantasyProvider;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use SimpleXMLElement;

/**
 * Syncs a connected Yahoo user's hockey leagues into platform-neutral tables.
 */
class YahooFantasyLeagueService
{
    /**
     * Yahoo hockey category names normalized to DynastyIQ nhl_season_stats keys.
     *
     * @var array<string,string>
     */
    private const HOCKEY_STAT_KEYS_BY_LABEL = [
        'assists' => 'a',
        'blocks' => 'b',
        'blocked shots' => 'b',
        'faceoffs won' => 'fow',
        'goals' => 'g',
        'goals against' => 'ga',
        'goals against average' => 'gaa',
        'hits' => 'h',
        'losses' => 'losses',
        'overtime losses' => 'ot_losses',
        'penalty minutes' => 'pim',
        'plus minus' => 'plus_minus',
        'plus/minus' => 'plus_minus',
        'points' => 'pts',
        'power play assists' => 'ppa',
        'power play goals' => 'ppg',
        'power play points' => 'ppp',
        'save percentage' => 'sv_pct',
        'saves' => 'sv',
        'shorthanded assists' => 'pka',
        'shorthanded goals' => 'pkg',
        'shorthanded points' => 'pkp',
        'shootout saves' => 'shosv',
        'shots against' => 'sa',
        'shots on goal' => 'sog',
        'shutouts' => 'so',
        'wins' => 'wins',
    ];

    public function __construct(
        private readonly YahooFantasyClient $client,
    ) {
    }

    /**
     * Sync Yahoo Fantasy hockey leagues and owned team assignments for a connection.
     *
     * @return array{leagues_count:int,teams_count:int,owned_teams_count:int}
     */
    public function syncForConnection(YahooFantasyConnection $connection, ?int $broadcastUserId = null): array
    {
        $user = $connection->user;

        if (! $user) {
            throw new RuntimeException('Yahoo Fantasy connection is missing an owning user.');
        }

        $gameCode = (string) config('yahoo.fantasy.game_code', 'nhl');
        $now = now();
        $leaguePayloads = $this->leaguePayloads(
            $this->client->fantasyXmlForConnection($connection, "users;use_login=1/games;game_keys={$gameCode}/leagues"),
        );
        $ownedTeamPayloads = $this->teamPayloads(
            $this->client->fantasyXmlForConnection($connection, "users;use_login=1/games;game_keys={$gameCode}/teams"),
        );
        $ownedTeamKeys = collect($ownedTeamPayloads)
            ->pluck('team_key')
            ->filter()
            ->values()
            ->all();
        $ownedTeamsByLeague = collect($ownedTeamPayloads)
            ->filter(static fn (array $team): bool => $team['team_key'] !== null && $team['league_key'] !== null)
            ->groupBy('league_key');

        $syncedLeagueIds = [];
        $activeAssignmentLeagueIds = [];
        $syncedTeamCount = 0;
        $ownedTeamCount = 0;

        foreach ($leaguePayloads as $leaguePayload) {
            if ($leaguePayload['league_key'] === null) {
                continue;
            }

            $platformLeague = PlatformLeague::updateOrCreate(
                [
                    'platform' => FantasyProvider::YAHOO,
                    'platform_league_id' => $leaguePayload['league_key'],
                ],
                array_filter([
                    'name' => $leaguePayload['name'] ?? 'Unnamed Yahoo League',
                    'sport' => 'hockey',
                    'logo_url' => $leaguePayload['logo_url'],
                    'synced_at' => $now,
                ], static fn (mixed $value): bool => $value !== null),
            );
            $syncedLeagueIds[] = $platformLeague->id;
            $this->syncLeagueSettings($connection, $platformLeague, $leaguePayload['league_key'], $now);

            $leagueTeams = $this->teamsForLeague($connection, $leaguePayload['league_key']);
            if ($leagueTeams === []) {
                $leagueTeams = $ownedTeamsByLeague->get($leaguePayload['league_key'], collect())->values()->all();
            }

            foreach ($leagueTeams as $teamPayload) {
                if ($teamPayload['team_key'] === null) {
                    continue;
                }

                $platformTeam = PlatformTeam::updateOrCreate(
                    [
                        'platform_league_id' => $platformLeague->id,
                        'platform_team_id' => $teamPayload['team_key'],
                    ],
                    array_filter([
                        'name' => $teamPayload['name'] ?? 'Unnamed Yahoo Team',
                        'short_name' => $teamPayload['short_name'],
                        'logo_url' => $teamPayload['logo_url'],
                        'extras' => $teamPayload['raw_payload'],
                        'synced_at' => $now,
                    ], static fn (mixed $value): bool => $value !== null),
                );
                $syncedTeamCount++;
                SyncYahooTeamRosterJob::dispatch($platformTeam->id, $broadcastUserId);

                if (in_array($teamPayload['team_key'], $ownedTeamKeys, true)) {
                    $this->upsertUserTeamAssignment($user->id, $platformLeague->id, $platformTeam->id, $now);
                    $activeAssignmentLeagueIds[] = $platformLeague->id;
                    $ownedTeamCount++;
                }
            }
        }

        $this->deactivateStaleAssignments($user->id, array_unique($activeAssignmentLeagueIds), $now);

        return [
            'leagues_count' => count($syncedLeagueIds),
            'teams_count' => $syncedTeamCount,
            'owned_teams_count' => $ownedTeamCount,
        ];
    }

    /**
     * Sync provider-neutral league settings for a Yahoo league.
     */
    private function syncLeagueSettings(
        YahooFantasyConnection $connection,
        PlatformLeague $platformLeague,
        string $leagueKey,
        CarbonInterface $now,
    ): void {
        $settingsXml = $this->client->fantasyXmlForConnection($connection, "league/{$leagueKey}/settings");

        $manualMappings = $this->manualScoringMappings($platformLeague);
        $categories = $this->applyManualScoringMappings(
            $this->scoringCategoryPayloads($settingsXml),
            $manualMappings,
        );

        $platformLeague->forceFill([
            'settings' => $this->leagueSettingsPayload($settingsXml),
            'scoring_settings' => [
                'categories' => $categories,
                'manual_mappings' => $manualMappings,
                'raw_payload' => $this->settingsRawPayload($settingsXml),
            ],
            'updated_at' => $now,
        ])->save();

        $this->syncRosterSlots($platformLeague, $this->rosterSlotPayloads($settingsXml), $now);
    }

    /**
     * Sync provider-neutral roster slot settings for a Yahoo league.
     *
     * @param array<int,array{slot:string,slot_type:string,position_type:?string,count:int,sort_order:int,raw_payload:array<string,mixed>}> $slots
     */
    private function syncRosterSlots(PlatformLeague $platformLeague, array $slots, CarbonInterface $now): void
    {
        if ($slots === []) {
            return;
        }

        $seenSlots = [];

        foreach ($slots as $slot) {
            $seenSlots[] = $slot['slot'];

            PlatformLeagueRosterSlot::query()->updateOrCreate(
                [
                    'platform_league_id' => $platformLeague->id,
                    'slot' => $slot['slot'],
                ],
                [
                    'slot_type' => $slot['slot_type'],
                    'position_type' => $slot['position_type'],
                    'count' => $slot['count'],
                    'sort_order' => $slot['sort_order'],
                    'raw_payload' => $slot['raw_payload'],
                    'updated_at' => $now,
                ],
            );
        }

        PlatformLeagueRosterSlot::query()
            ->where('platform_league_id', $platformLeague->id)
            ->whereNotIn('slot', $seenSlots)
            ->delete();
    }

    /**
     * Fetch and normalize all teams for a Yahoo league.
     *
     * @return array<int,array<string,mixed>>
     */
    private function teamsForLeague(YahooFantasyConnection $connection, string $leagueKey): array
    {
        $xml = $this->client->fantasyXmlForConnection($connection, "league/{$leagueKey}/teams");

        return $this->teamPayloads($xml);
    }

    /**
     * Sync Yahoo team and league logos for one platform league.
     *
     * @return array{ran:bool,skipped_reason:string|null,candidate_count:int,updated_team_count:int,updated_league_count:int}
     */
    public function syncLogosForLeague(YahooFantasyConnection $connection, PlatformLeague $platformLeague): array
    {
        $summary = [
            'ran' => false,
            'skipped_reason' => null,
            'candidate_count' => 0,
            'updated_team_count' => 0,
            'updated_league_count' => 0,
        ];

        if ($platformLeague->platform !== FantasyProvider::YAHOO) {
            $summary['skipped_reason'] = 'not_yahoo_league';

            return $summary;
        }

        $leagueKey = (string) $platformLeague->platform_league_id;
        if ($leagueKey === '') {
            $summary['skipped_reason'] = 'missing_league_key';

            return $summary;
        }

        $leagueXml = $this->client->fantasyXmlForConnection($connection, "league/{$leagueKey}");
        $leagueLogoUrl = $this->logoUrl($this->firstTextByPath(
            $leagueXml,
            '//*[local-name()="league"]/*[local-name()="logo_url"]',
        ));

        if ($leagueLogoUrl !== null && $platformLeague->logo_url !== $leagueLogoUrl) {
            $platformLeague->forceFill(['logo_url' => $leagueLogoUrl])->save();
            $summary['updated_league_count']++;
        }

        foreach ($this->teamsForLeague($connection, $leagueKey) as $teamPayload) {
            $logoUrl = $this->logoUrl($teamPayload['logo_url'] ?? null);
            $teamKey = $teamPayload['team_key'] ?? null;

            if (! is_string($teamKey) || $teamKey === '' || $logoUrl === null) {
                continue;
            }

            $summary['candidate_count']++;

            $team = PlatformTeam::query()
                ->where('platform_league_id', $platformLeague->id)
                ->where('platform_team_id', $teamKey)
                ->first(['id', 'logo_url']);

            if ($team === null || $team->logo_url === $logoUrl) {
                continue;
            }

            $team->forceFill(['logo_url' => $logoUrl])->save();
            $summary['updated_team_count']++;
        }

        $summary['ran'] = true;

        return $summary;
    }

    /**
     * Upsert the connected user's owned team assignment for a platform league.
     */
    private function upsertUserTeamAssignment(
        int $userId,
        int $platformLeagueId,
        int $platformTeamId,
        CarbonInterface $now,
    ): void {
        $keys = [
            'user_id' => $userId,
            'platform_league_id' => $platformLeagueId,
        ];
        $values = [
            'team_id' => $platformTeamId,
            'is_active' => true,
            'extras' => json_encode(['provider' => FantasyProvider::YAHOO]),
            'synced_at' => $now,
            'updated_at' => $now,
        ];

        $updated = DB::table('league_user_teams')->where($keys)->update($values);

        if ($updated === 0) {
            $nextSortOrder = (int) DB::table('league_user_teams')
                ->where('user_id', $userId)
                ->max('sort_order') + 1;

            DB::table('league_user_teams')->insert(
                $keys + $values + ['sort_order' => $nextSortOrder, 'created_at' => $now]
            );
        }
    }

    /**
     * Deactivate Yahoo league assignments that no longer appear in the user's Yahoo account.
     *
     * @param array<int,int> $activeLeagueIds
     */
    private function deactivateStaleAssignments(int $userId, array $activeLeagueIds, CarbonInterface $now): void
    {
        $query = DB::table('league_user_teams')
            ->where('user_id', $userId)
            ->whereIn(
                'platform_league_id',
                DB::table('platform_leagues')
                    ->select('id')
                    ->where('platform', FantasyProvider::YAHOO),
            );

        if ($activeLeagueIds !== []) {
            $query->whereNotIn('platform_league_id', $activeLeagueIds);
        }

        $query->update([
            'is_active' => false,
            'updated_at' => $now,
        ]);
    }

    /**
     * Extract normalized Yahoo league payloads.
     *
     * @return array<int,array<string,mixed>>
     */
    private function leaguePayloads(SimpleXMLElement $xml): array
    {
        $leagues = $xml->xpath('//*[local-name()="league"]') ?: [];

        return collect($leagues)
            ->map(fn (SimpleXMLElement $league): array => [
                'league_key' => $this->childText($league, 'league_key'),
                'league_id' => $this->childText($league, 'league_id'),
                'name' => $this->childText($league, 'name'),
                'url' => $this->childText($league, 'url'),
                'logo_url' => $this->logoUrl($this->childText($league, 'logo_url')),
                'season' => $this->childText($league, 'season'),
                'raw_payload' => $this->xmlToArray($league),
            ])
            ->filter(static fn (array $league): bool => $league['league_key'] !== null)
            ->values()
            ->all();
    }

    /**
     * Extract normalized Yahoo team payloads.
     *
     * @return array<int,array<string,mixed>>
     */
    private function teamPayloads(SimpleXMLElement $xml): array
    {
        $teams = $xml->xpath('//*[local-name()="team"]') ?: [];

        return collect($teams)
            ->map(function (SimpleXMLElement $team): array {
                $teamKey = $this->childText($team, 'team_key');

                return [
                    'team_key' => $teamKey,
                    'team_id' => $this->childText($team, 'team_id'),
                    'league_key' => $teamKey ? $this->leagueKeyFromTeamKey($teamKey) : null,
                    'name' => $this->childText($team, 'name'),
                    'short_name' => $this->childText($team, 'short_name'),
                    'url' => $this->childText($team, 'url'),
                    'logo_url' => $this->teamLogoUrl($team),
                    'raw_payload' => $this->xmlToArray($team),
                ];
            })
            ->filter(static fn (array $team): bool => $team['team_key'] !== null)
            ->values()
            ->all();
    }

    /**
     * Extract normalized Yahoo roster slot settings.
     *
     * @return array<int,array{slot:string,slot_type:string,position_type:?string,count:int,sort_order:int,raw_payload:array<string,mixed>}>
     */
    private function rosterSlotPayloads(SimpleXMLElement $xml): array
    {
        $nodes = $xml->xpath('//*[local-name()="roster_positions"]/*[local-name()="roster_position"]') ?: [];

        return collect($nodes)
            ->map(function (SimpleXMLElement $slot, int $index): ?array {
                $position = $this->childText($slot, 'position');

                if ($position === null) {
                    return null;
                }

                return [
                    'slot' => $position,
                    'slot_type' => $this->slotType($position),
                    'position_type' => $this->positionType($position, $this->childText($slot, 'position_type')),
                    'count' => max(0, (int) ($this->childText($slot, 'count') ?? 0)),
                    'sort_order' => $index + 1,
                    'raw_payload' => $this->xmlToArray($slot),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Extract normalized Yahoo league settings metadata.
     *
     * @return array<string,mixed>
     */
    private function leagueSettingsPayload(SimpleXMLElement $xml): array
    {
        $settings = $this->settingsNode($xml);

        if (! $settings instanceof SimpleXMLElement) {
            return [];
        }

        return [
            'scoring_type' => $this->childText($settings, 'scoring_type'),
            'draft_type' => $this->childText($settings, 'draft_type'),
            'uses_fractional_points' => $this->booleanText($this->childText($settings, 'uses_fractional_points')),
            'uses_negative_points' => $this->booleanText($this->childText($settings, 'uses_negative_points')),
            'player_pool' => $this->childText($settings, 'player_pool'),
            'raw_payload' => $this->xmlToArray($settings),
        ];
    }

    /**
     * Extract normalized Yahoo scoring categories with league modifiers.
     *
     * @return array<int,array<string,mixed>>
     */
    private function scoringCategoryPayloads(SimpleXMLElement $xml): array
    {
        $modifiers = $this->statModifiersById($xml);
        $nodes = $xml->xpath(
            '//*[local-name()="settings"]/*[local-name()="stat_categories"]/*[local-name()="stats"]/*[local-name()="stat"]'
        ) ?: [];

        return collect($nodes)
            ->map(function (SimpleXMLElement $stat, int $index) use ($modifiers): ?array {
                $statId = $this->childText($stat, 'stat_id');

                if ($statId === null) {
                    return null;
                }

                $displayName = $this->childText($stat, 'display_name');
                $name = $this->childText($stat, 'name');
                $autoStatKey = $this->autoStatKey($displayName, $name);

                return [
                    'id' => $statId,
                    'label' => $displayName ?? $name ?? $statId,
                    'name' => $name,
                    'short' => $displayName,
                    'enabled' => $this->booleanText($this->childText($stat, 'enabled')) ?? false,
                    'position_type' => $this->childText($stat, 'position_type'),
                    'position_types' => $this->statPositionTypes($stat),
                    'sort_order' => (int) ($this->childText($stat, 'sort_order') ?? $index + 1),
                    'scoring_order' => $modifiers[$statId]['display_order'] ?? $index + 1,
                    'value' => $modifiers[$statId]['value'] ?? null,
                    'auto_stat_key' => $autoStatKey,
                    'stat_key' => $autoStatKey,
                    'is_mapped' => $autoStatKey !== null,
                    'mapping_source' => $autoStatKey !== null ? 'auto' : null,
                    'raw_payload' => $this->xmlToArray($stat),
                ];
            })
            ->filter()
            ->filter(static fn (array $stat): bool => (bool) $stat['enabled'])
            ->sortBy([
                ['scoring_order', 'asc'],
                ['label', 'asc'],
            ])
            ->values()
            ->all();
    }

    /**
     * Resolve a Yahoo scoring category to a DynastyIQ stat key.
     */
    private function autoStatKey(?string $displayName, ?string $name): ?string
    {
        foreach ([$displayName, $name] as $label) {
            $normalized = $this->normalizedStatLabel($label);

            if ($normalized !== '' && isset(self::HOCKEY_STAT_KEYS_BY_LABEL[$normalized])) {
                return self::HOCKEY_STAT_KEYS_BY_LABEL[$normalized];
            }
        }

        return null;
    }

    /**
     * Return manual scoring mappings already stored on the platform league.
     *
     * @return array<string,string>
     */
    private function manualScoringMappings(PlatformLeague $platformLeague): array
    {
        $mappings = data_get($platformLeague->scoring_settings ?? [], 'manual_mappings', []);

        if (! is_array($mappings)) {
            return [];
        }

        return collect($mappings)
            ->mapWithKeys(static fn (mixed $value, mixed $key): array => [(string) $key => (string) $value])
            ->filter(static fn (string $value): bool => $value !== '')
            ->all();
    }

    /**
     * Apply user-selected scoring mappings over auto mappings.
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
                $statId = (string) ($category['id'] ?? '');
                $manualStatKey = $manualMappings[$statId] ?? null;
                $statKey = $manualStatKey ?: ($category['auto_stat_key'] ?? null);

                $category['stat_key'] = $statKey;
                $category['is_mapped'] = $statKey !== null && $statKey !== '';
                $category['mapping_source'] = $manualStatKey ? 'manual' : ($category['mapping_source'] ?? null);

                return $category;
            })
            ->values()
            ->all();
    }

    /**
     * Normalize Yahoo scoring labels for deterministic stat-key matching.
     */
    private function normalizedStatLabel(?string $label): string
    {
        $label = strtolower(trim((string) $label));
        $label = str_replace(['+', '-'], [' plus ', ' '], $label);
        $label = preg_replace('/[^a-z0-9\/]+/', ' ', $label);

        return trim((string) preg_replace('/\s+/', ' ', (string) $label));
    }

    /**
     * Return Yahoo stat modifier values keyed by stat id.
     *
     * @return array<string,array{value:int|float|string,display_order:int}>
     */
    private function statModifiersById(SimpleXMLElement $xml): array
    {
        $nodes = $xml->xpath(
            '//*[local-name()="settings"]/*[local-name()="stat_modifiers"]/*[local-name()="stats"]/*[local-name()="stat"]'
        ) ?: [];

        $count = count($nodes);

        return collect($nodes)
            ->mapWithKeys(function (SimpleXMLElement $stat, int $index) use ($count): array {
                $statId = $this->childText($stat, 'stat_id');
                $value = $this->childText($stat, 'value');

                if ($statId === null || $value === null) {
                    return [];
                }

                return [
                    $statId => [
                        'value' => $this->numericText($value),
                        'display_order' => $count - $index,
                    ],
                ];
            })
            ->all();
    }

    /**
     * Return position types that Yahoo associates to one stat.
     *
     * @return array<int,string>
     */
    private function statPositionTypes(SimpleXMLElement $stat): array
    {
        $nodes = $stat->xpath(
            './*[local-name()="stat_position_types"]/*[local-name()="stat_position_type"]/*[local-name()="position_type"]'
        ) ?: [];

        return collect($nodes)
            ->map(static fn (SimpleXMLElement $node): string => trim((string) $node))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Return the league settings node from a Yahoo settings response.
     */
    private function settingsNode(SimpleXMLElement $xml): ?SimpleXMLElement
    {
        $nodes = $xml->xpath('//*[local-name()="settings"]') ?: [];
        $settings = $nodes[0] ?? null;

        return $settings instanceof SimpleXMLElement ? $settings : null;
    }

    /**
     * Return the raw settings payload for diagnostics.
     *
     * @return array<string,mixed>
     */
    private function settingsRawPayload(SimpleXMLElement $xml): array
    {
        $settings = $this->settingsNode($xml);

        return $settings instanceof SimpleXMLElement ? $this->xmlToArray($settings) : [];
    }

    /**
     * Normalize a provider roster slot into a display group.
     */
    private function slotType(string $slot): string
    {
        return match ($slot) {
            'BN' => 'bench',
            'IR', 'IR+' => 'injured',
            'NA' => 'minor',
            'Util', 'UTIL' => 'utility',
            default => str_contains($slot, '/') ? 'utility' : 'starter',
        };
    }

    /**
     * Normalize a provider roster slot into a hockey position type.
     */
    private function positionType(string $slot, ?string $positionType): ?string
    {
        if (in_array($positionType, ['F', 'D', 'G'], true)) {
            return $positionType;
        }

        return match ($slot) {
            'G' => 'G',
            'D' => 'D',
            'C', 'LW', 'RW', 'W', 'F', 'Util', 'UTIL' => 'F',
            default => str_contains($slot, '/') ? 'F' : null,
        };
    }

    /**
     * Return a direct child text value by local XML name.
     */
    private function childText(SimpleXMLElement $xml, string $localName): ?string
    {
        foreach ($xml->children() as $child) {
            if ($child->getName() === $localName) {
                $value = trim((string) $child);

                return $value === '' ? null : $value;
            }
        }

        return null;
    }

    private function teamLogoUrl(SimpleXMLElement $team): ?string
    {
        return $this->logoUrl($this->firstTextByPath(
            $team,
            './*[local-name()="team_logos"]/*[local-name()="team_logo"]/*[local-name()="url"]',
        ));
    }

    private function firstTextByPath(SimpleXMLElement $xml, string $path): ?string
    {
        $nodes = $xml->xpath($path) ?: [];
        $node = $nodes[0] ?? null;

        if (! $node instanceof SimpleXMLElement) {
            return null;
        }

        $value = trim((string) $node);

        return $value === '' ? null : $value;
    }

    private function logoUrl(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $url = trim($value);

        return str_starts_with($url, 'https://') ? $url : null;
    }

    /**
     * Convert Yahoo boolean text when present.
     */
    private function booleanText(?string $value): ?bool
    {
        if ($value === null) {
            return null;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes'], true);
    }

    /**
     * Convert Yahoo numeric text while preserving non-numeric values.
     */
    private function numericText(string $value): int|float|string
    {
        if (! is_numeric($value)) {
            return $value;
        }

        $number = (float) $value;

        return floor($number) === $number ? (int) $number : $number;
    }

    /**
     * Derive a Yahoo league key from a Yahoo team key.
     */
    private function leagueKeyFromTeamKey(string $teamKey): ?string
    {
        $leagueKey = preg_replace('/\.t\.[^.]+$/', '', $teamKey);

        return is_string($leagueKey) && $leagueKey !== $teamKey ? $leagueKey : null;
    }

    /**
     * Convert Yahoo XML into an auditable provider payload array.
     *
     * @return array<string,mixed>
     */
    private function xmlToArray(SimpleXMLElement $xml): array
    {
        $encoded = json_encode($xml, JSON_THROW_ON_ERROR);
        $decoded = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }
}
