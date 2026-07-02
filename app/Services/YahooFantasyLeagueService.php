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
                [
                    'name' => $leaguePayload['name'] ?? 'Unnamed Yahoo League',
                    'sport' => 'hockey',
                    'synced_at' => $now,
                ],
            );
            $syncedLeagueIds[] = $platformLeague->id;
            $this->syncRosterSlots($connection, $platformLeague, $leaguePayload['league_key'], $now);

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
                    [
                        'name' => $teamPayload['name'] ?? 'Unnamed Yahoo Team',
                        'short_name' => $teamPayload['short_name'],
                        'extras' => $teamPayload['raw_payload'],
                        'synced_at' => $now,
                    ],
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
     * Sync provider-neutral roster slot settings for a Yahoo league.
     */
    private function syncRosterSlots(
        YahooFantasyConnection $connection,
        PlatformLeague $platformLeague,
        string $leagueKey,
        CarbonInterface $now,
    ): void {
        $slots = $this->rosterSlotPayloads(
            $this->client->fantasyXmlForConnection($connection, "league/{$leagueKey}/settings"),
        );

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
            DB::table('league_user_teams')->insert($keys + $values + ['created_at' => $now]);
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
