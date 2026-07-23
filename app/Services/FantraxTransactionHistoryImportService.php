<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PlatformLeague;

/**
 * Fetches, parses, and persists Fantrax transaction history views.
 */
final class FantraxTransactionHistoryImportService
{
    public const VIEWS = ['TRADE', 'CLAIM_DROP', 'LINEUP_CHANGE'];

    public function __construct(
        private readonly FantraxTransactionHistoryBrowserRpc $browserRpc,
        private readonly FantraxTransactionHistoryParser $parser,
        private readonly PlatformTransactionPersistenceService $persistence,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function import(PlatformLeague $league): array
    {
        $summary = [
            'views' => [],
            'transactions_created' => 0,
            'transactions_updated' => 0,
            'entries_created' => 0,
            'entries_updated' => 0,
            'entries_deleted' => 0,
            'unresolved_players' => 0,
            'unresolved_teams' => 0,
            'created_transaction_ids' => [],
        ];

        foreach (self::VIEWS as $view) {
            $payload = $this->browserRpc->fetch($league, $view);
            $parsed = $this->parser->parse($league, $view, $payload);
            $persisted = $this->persistence->persist($league, $parsed['transactions']);

            foreach ([
                'transactions_created',
                'transactions_updated',
                'entries_created',
                'entries_updated',
                'entries_deleted',
                'unresolved_players',
                'unresolved_teams',
            ] as $key) {
                $summary[$key] += (int) ($persisted[$key] ?? 0);
            }
            $summary['created_transaction_ids'] = array_values(array_unique([
                ...$summary['created_transaction_ids'],
                ...array_map('intval', $persisted['created_transaction_ids'] ?? []),
            ]));

            $summary['views'][$view] = [
                ...$parsed['meta'],
                ...$persisted,
                'payload' => $payload,
            ];
        }

        return $summary;
    }
}
