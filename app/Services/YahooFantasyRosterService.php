<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PlatformTeam;
use App\Models\YahooFantasyConnection;
use App\Models\YahooPlayer;
use App\Support\FantasyProvider;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use SimpleXMLElement;

/**
 * Syncs Yahoo Fantasy roster players into provider-owned storage and roster memberships.
 */
class YahooFantasyRosterService
{
    public function __construct(
        private readonly YahooFantasyClient $client,
        private readonly PlayerIdentityResolver $resolver,
    ) {
    }

    /**
     * Sync one Yahoo platform team's current roster.
     *
     * @return array{
     *     platform_team_id:int,
     *     platform_team_key:string|null,
     *     league_key:string|null,
     *     status:string,
     *     skip_reason:string|null,
     *     players_count:int,
     *     skipped_count:int,
     *     resolved_count:int,
     *     unresolved_count:int,
     *     membership_inserted_count:int,
     *     membership_updated_count:int,
     *     stale_closed_count:int,
     *     synced_at:string
     * }
     */
    public function syncTeam(int $platformTeamId): array
    {
        $team = PlatformTeam::query()
            ->with('league')
            ->find($platformTeamId);

        if (! $team || $team->league?->platform !== FantasyProvider::YAHOO) {
            return $this->emptySummary($platformTeamId);
        }

        $connection = $this->connectionForTeam($team);
        $xml = $this->client->fantasyXmlForConnection(
            $connection,
            "team/{$team->platform_team_id}/roster/players",
        );
        $now = now();
        $players = $this->playerPayloads($team->league->platform_league_id, $xml);
        $desiredPlayerIds = [];
        $skippedCount = 0;
        $resolvedCount = 0;
        $unresolvedCount = 0;
        $membershipInsertedCount = 0;
        $membershipUpdatedCount = 0;

        foreach ($players as $payload) {
            if ($payload['player_key'] === null || $payload['yahoo_player_id'] === null) {
                $skippedCount++;
                continue;
            }

            $yahooPlayer = YahooPlayer::updateOrCreate(
                ['player_key' => $payload['player_key']],
                [
                    'game_key' => $payload['game_key'],
                    'yahoo_player_id' => $payload['yahoo_player_id'],
                    'full_name' => $payload['full_name'],
                    'first_name' => $payload['first_name'],
                    'last_name' => $payload['last_name'],
                    'editorial_team_abbr' => $payload['editorial_team_abbr'],
                    'display_position' => $payload['display_position'],
                    'eligible_positions' => $payload['eligible_positions'],
                    'raw_payload' => $payload['raw_payload'],
                    'imported_at' => $now,
                ],
            );
            $identity = $this->resolver->resolveNonAuthorityIdentity(
                $this->resolver->upsertYahooIdentity($yahooPlayer),
            );

            $yahooPlayer->update([
                'player_external_identity_id' => $identity->id,
                'player_id' => $identity->player_id,
            ]);

            if ($identity->player_id === null) {
                $unresolvedCount++;
                continue;
            }

            $playerId = (int) $identity->player_id;
            $desiredPlayerIds[] = $playerId;
            $resolvedCount++;
            $membershipResult = $this->upsertRosterMembership($team->id, $playerId, $payload, $now);

            if ($membershipResult === 'inserted') {
                $membershipInsertedCount++;
            }

            if ($membershipResult === 'updated') {
                $membershipUpdatedCount++;
            }
        }

        $staleClosedCount = $this->closeStaleMemberships($team->id, array_unique($desiredPlayerIds), $now);

        $summary = [
            'platform_team_id' => $team->id,
            'platform_team_key' => $team->platform_team_id,
            'league_key' => $team->league->platform_league_id,
            'status' => 'completed',
            'skip_reason' => null,
            'players_count' => count($players),
            'skipped_count' => $skippedCount,
            'resolved_count' => $resolvedCount,
            'unresolved_count' => $unresolvedCount,
            'membership_inserted_count' => $membershipInsertedCount,
            'membership_updated_count' => $membershipUpdatedCount,
            'stale_closed_count' => $staleClosedCount,
            'synced_at' => $now->toIso8601String(),
        ];

        $this->rememberSyncSummary($team, $summary);

        return $summary;
    }

    /**
     * Resolve the Yahoo OAuth connection that owns a platform team.
     */
    private function connectionForTeam(PlatformTeam $team): YahooFantasyConnection
    {
        $userId = DB::table('league_user_teams')
            ->where('platform_league_id', $team->platform_league_id)
            ->where('is_active', true)
            ->orderByDesc('updated_at')
            ->value('user_id');
        $connection = $userId
            ? YahooFantasyConnection::query()
                ->where('user_id', $userId)
                ->where('status', 'connected')
                ->first()
            : null;

        if (! $connection) {
            throw new RuntimeException('Yahoo roster sync requires a connected Yahoo owner.');
        }

        return $connection;
    }

    /**
     * Upsert an open roster membership for a resolved Yahoo player.
     *
     * @param array<string,mixed> $payload
     */
    private function upsertRosterMembership(
        int $platformTeamId,
        int $playerId,
        array $payload,
        CarbonInterface $now,
    ): string {
        $openMembership = DB::table('platform_roster_memberships')
            ->where('platform_team_id', $platformTeamId)
            ->where('player_id', $playerId)
            ->where('platform', FantasyProvider::YAHOO)
            ->whereNull('ends_at')
            ->first();

        $values = [
            'platform_player_id' => $payload['player_key'],
            'slot' => $payload['selected_position'],
            'status' => $this->membershipStatus($payload['selected_position']),
            'eligibility' => json_encode($payload['eligible_positions']),
            'updated_at' => $now,
        ];

        if ($openMembership) {
            DB::table('platform_roster_memberships')
                ->where('id', $openMembership->id)
                ->update($values);

            return 'updated';
        }

        DB::table('platform_roster_memberships')->insert($values + [
            'platform_team_id' => $platformTeamId,
            'player_id' => $playerId,
            'platform' => FantasyProvider::YAHOO,
            'starts_at' => $now,
            'created_at' => $now,
        ]);

        return 'inserted';
    }

    /**
     * Close open Yahoo roster memberships absent from the latest resolved roster.
     *
     * @param array<int,int> $desiredPlayerIds
     */
    private function closeStaleMemberships(int $platformTeamId, array $desiredPlayerIds, CarbonInterface $now): int
    {
        $query = DB::table('platform_roster_memberships')
            ->where('platform_team_id', $platformTeamId)
            ->where('platform', FantasyProvider::YAHOO)
            ->whereNull('ends_at');

        if ($desiredPlayerIds !== []) {
            $query->whereNotIn('player_id', $desiredPlayerIds);
        }

        return $query->update([
            'ends_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Persist the latest roster sync summary on the provider team for diagnostics.
     *
     * @param array<string,mixed> $summary
     */
    private function rememberSyncSummary(PlatformTeam $team, array $summary): void
    {
        $extras = $team->extras ?? [];
        $extras['yahoo_roster_sync'] = $summary;

        $team->forceFill(['extras' => $extras])->save();
    }

    /**
     * Return a diagnostic summary when the requested team cannot be synced.
     *
     * @return array{
     *     platform_team_id:int,
     *     platform_team_key:null,
     *     league_key:null,
     *     status:string,
     *     skip_reason:string,
     *     players_count:int,
     *     skipped_count:int,
     *     resolved_count:int,
     *     unresolved_count:int,
     *     membership_inserted_count:int,
     *     membership_updated_count:int,
     *     stale_closed_count:int,
     *     synced_at:string
     * }
     */
    private function emptySummary(int $platformTeamId): array
    {
        return [
            'platform_team_id' => $platformTeamId,
            'platform_team_key' => null,
            'league_key' => null,
            'status' => 'skipped',
            'skip_reason' => 'missing_or_non_yahoo_team',
            'players_count' => 0,
            'skipped_count' => 0,
            'resolved_count' => 0,
            'unresolved_count' => 0,
            'membership_inserted_count' => 0,
            'membership_updated_count' => 0,
            'stale_closed_count' => 0,
            'synced_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Extract Yahoo player payloads from roster XML.
     *
     * @return array<int,array<string,mixed>>
     */
    private function playerPayloads(string $leagueKey, SimpleXMLElement $xml): array
    {
        $gameKey = $this->gameKeyFromLeagueKey($leagueKey);
        $players = $xml->xpath('//*[local-name()="player"]') ?: [];

        return collect($players)
            ->map(fn (SimpleXMLElement $player): array => [
                'game_key' => $gameKey,
                'player_key' => $this->firstText($player, 'player_key'),
                'yahoo_player_id' => $this->firstText($player, 'player_id'),
                'full_name' => $this->firstText($player, 'full'),
                'first_name' => $this->firstText($player, 'first'),
                'last_name' => $this->firstText($player, 'last'),
                'editorial_team_abbr' => $this->firstText($player, 'editorial_team_abbr'),
                'display_position' => $this->firstText($player, 'display_position'),
                'eligible_positions' => $this->eligiblePositions($player),
                'selected_position' => $this->selectedPosition($player),
                'raw_payload' => $this->xmlToArray($player),
            ])
            ->filter(static fn (array $player): bool => $player['player_key'] !== null)
            ->values()
            ->all();
    }

    /**
     * Return a first descendant text value by local XML name.
     */
    private function firstText(SimpleXMLElement $xml, string $localName): ?string
    {
        $nodes = $xml->xpath('.//*[local-name()="'.$localName.'"]') ?: [];
        $value = trim((string) ($nodes[0] ?? ''));

        return $value === '' ? null : $value;
    }

    /**
     * Return Yahoo eligible positions for one roster player.
     *
     * @return array<int,string>
     */
    private function eligiblePositions(SimpleXMLElement $player): array
    {
        $nodes = $player->xpath('.//*[local-name()="eligible_positions"]/*[local-name()="position"]') ?: [];

        return collect($nodes)
            ->map(static fn (SimpleXMLElement $node): string => trim((string) $node))
            ->filter(static fn (string $value): bool => $value !== '')
            ->values()
            ->all();
    }

    /**
     * Return the selected roster slot for one Yahoo roster player.
     */
    private function selectedPosition(SimpleXMLElement $player): ?string
    {
        $nodes = $player->xpath('.//*[local-name()="selected_position"]/*[local-name()="position"]') ?: [];
        $value = trim((string) ($nodes[0] ?? ''));

        return $value === '' ? null : $value;
    }

    /**
     * Convert a Yahoo selected position into the roster membership status enum.
     */
    private function membershipStatus(?string $selectedPosition): ?string
    {
        return match ($selectedPosition) {
            'BN' => 'bench',
            'IR', 'IR+' => 'ir',
            'NA' => 'na',
            default => $selectedPosition === null ? null : 'active',
        };
    }

    /**
     * Derive a game key from a Yahoo league key.
     */
    private function gameKeyFromLeagueKey(string $leagueKey): string
    {
        return explode('.', $leagueKey)[0] ?? $leagueKey;
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
