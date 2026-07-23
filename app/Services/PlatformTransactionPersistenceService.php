<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PlatformLeague;
use App\Models\PlatformTransaction;
use App\Models\PlatformTransactionEntry;
use Illuminate\Support\Facades\DB;

/**
 * Persists normalized fantasy platform transactions idempotently.
 */
final class PlatformTransactionPersistenceService
{
    /**
     * @param array<int,array<string,mixed>> $transactions
     *
     * @return array<string,mixed>
     */
    public function persist(PlatformLeague $league, array $transactions): array
    {
        $summary = [
            'transactions_created' => 0,
            'transactions_updated' => 0,
            'entries_created' => 0,
            'entries_updated' => 0,
            'entries_deleted' => 0,
            'unresolved_players' => 0,
            'unresolved_teams' => 0,
            'created_transaction_ids' => [],
        ];

        $teamMap = $this->teamMap((int) $league->id);
        $playerMap = $this->playerMap($transactions);

        DB::transaction(function () use ($transactions, $teamMap, $playerMap, &$summary): void {
            foreach ($transactions as $transactionRow) {
                $entries = $transactionRow['entries'] ?? [];
                unset($transactionRow['entries']);

                $transaction = PlatformTransaction::query()->updateOrCreate(
                    [
                        'platform_league_id' => $transactionRow['platform_league_id'],
                        'source_key' => $transactionRow['source_key'],
                    ],
                    $transactionRow
                );

                if ($transaction->wasRecentlyCreated) {
                    $summary['transactions_created']++;
                    $summary['created_transaction_ids'][] = (int) $transaction->id;
                } else {
                    $summary['transactions_updated']++;
                }

                $seenIndexes = [];

                foreach ($entries as $entryRow) {
                    $entryIndex = (int) $entryRow['entry_index'];
                    $seenIndexes[] = $entryIndex;

                    $resolved = $this->resolveEntry($entryRow, $teamMap, $playerMap);
                    $entry = PlatformTransactionEntry::query()->updateOrCreate(
                        [
                            'platform_transaction_id' => $transaction->id,
                            'entry_index' => $entryIndex,
                        ],
                        $resolved['row']
                    );

                    $entry->wasRecentlyCreated
                        ? $summary['entries_created']++
                        : $summary['entries_updated']++;

                    $summary['unresolved_players'] += $resolved['unresolved_player'];
                    $summary['unresolved_teams'] += $resolved['unresolved_teams'];
                }

                $deleted = PlatformTransactionEntry::query()
                    ->where('platform_transaction_id', $transaction->id)
                    ->when($seenIndexes !== [], static function ($query) use ($seenIndexes): void {
                        $query->whereNotIn('entry_index', $seenIndexes);
                    })
                    ->delete();

                $summary['entries_deleted'] += (int) $deleted;
            }
        });

        return $summary;
    }

    /**
     * @return array{by_provider_id:array<string,int>,by_name:array<string,int>}
     */
    private function teamMap(int $platformLeagueId): array
    {
        $teams = DB::table('platform_teams')
            ->where('platform_league_id', $platformLeagueId)
            ->get(['id', 'platform_team_id', 'name', 'short_name']);

        $byProviderId = [];
        $byName = [];

        foreach ($teams as $team) {
            $id = (int) $team->id;
            $providerId = trim((string) $team->platform_team_id);

            if ($providerId !== '') {
                $byProviderId[$providerId] = $id;
            }

            foreach ([(string) $team->name, (string) $team->short_name] as $name) {
                $normalized = $this->normalizeTeamName($name);

                if ($normalized !== '') {
                    $byName[$normalized] = $id;
                }
            }
        }

        return [
            'by_provider_id' => $byProviderId,
            'by_name' => $byName,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $transactions
     *
     * @return array<string,array{id:int,player_id:int}>
     */
    private function playerMap(array $transactions): array
    {
        $providerPlayerIds = collect($transactions)
            ->flatMap(static fn (array $transaction): array => $transaction['entries'] ?? [])
            ->pluck('provider_player_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($providerPlayerIds === []) {
            return [];
        }

        return DB::table('platform_player_ids')
            ->where('platform', 'fantrax')
            ->whereIn('platform_player_id', $providerPlayerIds)
            ->get(['id', 'player_id', 'platform_player_id'])
            ->mapWithKeys(static fn (object $row): array => [
                (string) $row->platform_player_id => [
                    'id' => (int) $row->id,
                    'player_id' => (int) $row->player_id,
                ],
            ])
            ->all();
    }

    /**
     * @param array<string,mixed> $entryRow
     * @param array{by_provider_id:array<string,int>,by_name:array<string,int>} $teamMap
     * @param array<string,array{id:int,player_id:int}> $playerMap
     *
     * @return array{row:array<string,mixed>,unresolved_player:int,unresolved_teams:int}
     */
    private function resolveEntry(array $entryRow, array $teamMap, array $playerMap): array
    {
        $providerPlayerId = $entryRow['provider_player_id'] ?? null;
        $playerIdentity = is_string($providerPlayerId) ? ($playerMap[$providerPlayerId] ?? null) : null;

        $fromTeamId = $this->resolveTeamId($teamMap, $entryRow['from_provider_team_id'] ?? null, $entryRow['from_team_name'] ?? null);
        $toTeamId = $this->resolveTeamId($teamMap, $entryRow['to_provider_team_id'] ?? null, $entryRow['to_team_name'] ?? null);
        $teamId = $this->resolveTeamId($teamMap, $entryRow['provider_team_id'] ?? null, $entryRow['team_name'] ?? null);

        $row = [
            'entry_index' => (int) $entryRow['entry_index'],
            'asset_type' => (string) $entryRow['asset_type'],
            'action' => (string) $entryRow['action'],
            'from_platform_team_id' => $fromTeamId,
            'to_platform_team_id' => $toTeamId,
            'platform_team_id' => $teamId,
            'player_id' => $playerIdentity['player_id'] ?? null,
            'platform_player_identity_id' => $playerIdentity['id'] ?? null,
            'provider_player_id' => $providerPlayerId,
            'raw_name' => $entryRow['raw_name'] ?? null,
            'from_slot' => $entryRow['from_slot'] ?? null,
            'to_slot' => $entryRow['to_slot'] ?? null,
            'draft_year' => $entryRow['draft_year'] ?? null,
            'draft_round' => $entryRow['draft_round'] ?? null,
            'draft_pick' => $entryRow['draft_pick'] ?? null,
            'draft_original_team_name' => $entryRow['draft_original_team_name'] ?? null,
            'draft_original_team_provider_id' => $entryRow['draft_original_team_provider_id'] ?? null,
            'raw_payload' => $entryRow['raw_payload'] ?? null,
        ];

        $teamRefs = [
            [$entryRow['from_provider_team_id'] ?? null, $entryRow['from_team_name'] ?? null, $fromTeamId],
            [$entryRow['to_provider_team_id'] ?? null, $entryRow['to_team_name'] ?? null, $toTeamId],
            [$entryRow['provider_team_id'] ?? null, $entryRow['team_name'] ?? null, $teamId],
        ];

        return [
            'row' => $row,
            'unresolved_player' => $providerPlayerId !== null && $playerIdentity === null ? 1 : 0,
            'unresolved_teams' => collect($teamRefs)
                ->filter(static fn (array $teamRef): bool => (filled($teamRef[0]) || filled($teamRef[1])) && $teamRef[2] === null)
                ->map(static fn (array $teamRef): string => (string) ($teamRef[0] ?: $teamRef[1]))
                ->unique()
                ->count(),
        ];
    }

    /**
     * @param array{by_provider_id:array<string,int>,by_name:array<string,int>} $teamMap
     */
    private function resolveTeamId(array $teamMap, mixed $providerTeamId, mixed $teamName): ?int
    {
        $key = is_string($providerTeamId) ? $providerTeamId : '';

        if ($key !== '' && isset($teamMap['by_provider_id'][$key])) {
            return $teamMap['by_provider_id'][$key];
        }

        $name = $this->normalizeTeamName(is_string($teamName) ? $teamName : '');

        return $name !== '' ? ($teamMap['by_name'][$name] ?? null) : null;
    }

    private function normalizeTeamName(string $name): string
    {
        return str($name)->lower()->replaceMatches('/[^a-z0-9]+/', '')->toString();
    }
}
