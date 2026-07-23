<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PlatformLeague;
use Carbon\CarbonImmutable;

/**
 * Normalizes Fantrax transaction history RPC payloads into provider-neutral rows.
 */
final class FantraxTransactionHistoryParser
{
    /**
     * @return array{transactions:array<int,array<string,mixed>>, meta:array<string,mixed>}
     */
    public function parse(PlatformLeague $league, string $view, array $payload): array
    {
        $rows = $this->rows($payload);
        $groups = [];

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $txSetId = trim((string) ($row['txSetId'] ?? ''));
            $groupKey = $txSetId !== '' ? $txSetId : 'row-' . $index . '-' . md5(json_encode($row) ?: '');
            $groups[$groupKey][] = $row;
        }

        $transactions = [];

        foreach ($groups as $providerTransactionId => $groupRows) {
            $transactions[] = $this->transaction($league, $view, $providerTransactionId, $groupRows);
        }

        return [
            'transactions' => $transactions,
            'meta' => [
                'view' => $view,
                'row_count' => count($rows),
                'transaction_count' => count($transactions),
                'paginated_result_set' => data_get($payload, 'response.json.responses.0.data.paginatedResultSet'),
            ],
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     *
     * @return array<string,mixed>
     */
    private function transaction(PlatformLeague $league, string $view, string $providerTransactionId, array $rows): array
    {
        $firstRow = $rows[0] ?? [];
        $firstCells = $this->cellsByKey($firstRow);
        $date = $this->cellContent($firstCells, 'date');
        $period = $this->cellContent($firstCells, 'week') ?: $this->cellContent($firstCells, 'weekOrPeriod');
        $entries = [];

        foreach ($rows as $index => $row) {
            $entries[] = $this->entry($view, $row, $index, $firstCells);
        }

        return [
            'platform_league_id' => (int) $league->id,
            'platform' => (string) $league->platform,
            'provider_transaction_id' => $providerTransactionId,
            'source_key' => implode(':', [
                (string) $league->platform,
                (string) $league->platform_league_id,
                $view,
                $providerTransactionId,
            ]),
            'source_view' => $view,
            'transaction_type' => $this->transactionType($view),
            'occurred_at' => $this->parseOccurredAt($date),
            'period' => $period !== '' ? $period : null,
            'executed' => array_key_exists('executed', $firstRow) ? (bool) $firstRow['executed'] : null,
            'deleted' => collect($rows)->contains(static fn (array $row): bool => (bool) ($row['deleted'] ?? false)),
            'status' => (string) ($firstRow['resultCode'] ?? data_get($firstRow, 'result.content', '')),
            'summary' => $this->summary($view, $entries),
            'raw_payload' => [
                'view' => $view,
                'rows' => $rows,
            ],
            'entries' => $entries,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function entry(string $view, array $row, int $entryIndex, array $groupCells): array
    {
        $cells = array_merge($groupCells, $this->cellsByKey($row));
        $providerPlayerId = trim((string) data_get($row, 'scorer.scorerId', ''));
        $playerName = trim((string) data_get($row, 'scorer.name', ''));
        $draftPick = $this->draftPickPayload($row);
        $assetType = $draftPick !== [] ? 'draft_pick' : ($playerName !== '' || $providerPlayerId !== '' ? 'player' : 'unknown');
        $rawName = $playerName !== '' ? $playerName : ($draftPick['label'] ?? null);

        return [
            'entry_index' => $entryIndex,
            'asset_type' => $assetType,
            'action' => $this->entryAction($view, (string) ($row['transactionCode'] ?? '')),
            'from_provider_team_id' => $this->cellTeamId($cells, 'from'),
            'from_team_name' => $this->cellContent($cells, 'from') ?: null,
            'to_provider_team_id' => $this->cellTeamId($cells, 'to'),
            'to_team_name' => $this->cellContent($cells, 'to') ?: null,
            'provider_team_id' => $this->cellTeamId($cells, 'team'),
            'team_name' => $this->cellContent($cells, 'team') ?: null,
            'provider_player_id' => $providerPlayerId !== '' ? $providerPlayerId : null,
            'raw_name' => $rawName,
            'from_slot' => $view === 'LINEUP_CHANGE' ? ($this->cellContent($cells, 'from') ?: null) : null,
            'to_slot' => $view === 'LINEUP_CHANGE' ? ($this->cellContent($cells, 'to') ?: null) : null,
            'draft_year' => $draftPick['year'] ?? null,
            'draft_round' => $draftPick['round'] ?? null,
            'draft_pick' => $draftPick['pick'] ?? null,
            'draft_original_team_name' => $draftPick['original_team_name'] ?? null,
            'draft_original_team_provider_id' => null,
            'raw_payload' => $row,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function rows(array $payload): array
    {
        $rows = data_get($payload, 'response.json.responses.0.data.table.rows', []);

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function cellsByKey(array $row): array
    {
        $cells = [];

        foreach (($row['cells'] ?? []) as $cell) {
            if (is_array($cell) && isset($cell['key'])) {
                $cells[(string) $cell['key']] = $cell;
            }
        }

        return $cells;
    }

    /**
     * @param array<string,array<string,mixed>> $cells
     */
    private function cellContent(array $cells, string $key): string
    {
        return trim((string) ($cells[$key]['content'] ?? ''));
    }

    /**
     * @param array<string,array<string,mixed>> $cells
     */
    private function cellTeamId(array $cells, string $key): ?string
    {
        $value = trim((string) ($cells[$key]['teamId'] ?? ''));

        return $value !== '' ? $value : null;
    }

    private function transactionType(string $view): string
    {
        return match ($view) {
            'TRADE' => 'trade',
            'CLAIM_DROP' => 'claim_drop',
            'LINEUP_CHANGE' => 'lineup_change',
            default => 'unknown',
        };
    }

    private function entryAction(string $view, string $transactionCode): string
    {
        return match ($view) {
            'TRADE' => 'trade',
            'LINEUP_CHANGE' => 'lineup_move',
            'CLAIM_DROP' => match ($transactionCode) {
                'CLAIM' => 'claim',
                'DROP' => 'drop',
                default => 'unknown',
            },
            default => 'unknown',
        };
    }

    private function parseOccurredAt(string $date): ?CarbonImmutable
    {
        if ($date === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($date, 'America/Toronto');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function draftPickPayload(array $row): array
    {
        $parts = $row['draftPickDisplayParts'] ?? null;

        if (! is_array($parts)) {
            return [];
        }

        $roundInfo = trim(strip_tags((string) ($parts['roundInfo'] ?? '')));
        $yearText = trim(strip_tags((string) ($parts['year'] ?? '')));
        $label = trim($roundInfo . ' ' . $yearText);

        preg_match('/\b(\d{4})\b/', $yearText, $yearMatch);
        preg_match('/Round\s+(\d+)/i', $roundInfo, $roundMatch);
        preg_match('/Pick\s+(\d+)/i', $roundInfo, $pickMatch);
        preg_match('/\(([^)]+)\)/', $roundInfo, $teamMatch);

        return [
            'label' => $label !== '' ? $label : null,
            'year' => isset($yearMatch[1]) ? (int) $yearMatch[1] : null,
            'round' => isset($roundMatch[1]) ? (int) $roundMatch[1] : null,
            'pick' => isset($pickMatch[1]) ? (int) $pickMatch[1] : null,
            'original_team_name' => isset($teamMatch[1]) ? trim($teamMatch[1]) : null,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $entries
     */
    private function summary(string $view, array $entries): string
    {
        $names = collect($entries)
            ->pluck('raw_name')
            ->filter()
            ->take(6)
            ->implode(', ');

        $prefix = match ($view) {
            'TRADE' => 'Trade',
            'CLAIM_DROP' => 'Claim/drop',
            'LINEUP_CHANGE' => 'Lineup change',
            default => 'Transaction',
        };

        return $names !== '' ? $prefix . ': ' . $names : $prefix;
    }
}
