<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PlatformLeague;
use App\Support\FantraxLogoBrowserProfile;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Fetches raw Fantrax transaction history through the authenticated browser RPC endpoint.
 */
final class FantraxTransactionHistoryBrowserRpc
{
    public function __construct(
        private readonly FantraxLogoBrowserProfile $profile,
    ) {
    }

    /**
     * Fetch raw transaction history JSON for a Fantrax platform league.
     *
     * @return array<string,mixed>
     */
    public function fetch(PlatformLeague $league, string $view = 'CLAIM_DROP'): array
    {
        if ($league->platform !== 'fantrax') {
            throw new RuntimeException('Transactions are only available for Fantrax leagues.');
        }

        if (! in_array($view, ['CLAIM_DROP', 'TRADE', 'LINEUP_CHANGE'], true)) {
            throw new RuntimeException('Unsupported Fantrax transaction view.');
        }

        $profilePath = $this->profile->path();

        if ($profilePath === null || ! is_dir($profilePath)) {
            throw new RuntimeException('Fantrax browser profile is not configured or ready.');
        }

        $command = [
            $this->nodePath(),
            base_path('scripts/fetch-fantrax-transaction-history-rpc.mjs'),
            '--league-id',
            (string) $league->platform_league_id,
            '--profile',
            $profilePath,
            '--view',
            $view,
        ];

        if ($this->browserHeadless()) {
            $command[] = '--headless';
        }

        $process = new Process($command, base_path());
        $process->setTimeout(60);
        $process->run();

        $payload = json_decode(trim($process->getOutput()), true);

        if (! is_array($payload)) {
            throw new RuntimeException(trim($process->getErrorOutput()) ?: 'Fantrax transaction browser RPC returned invalid JSON.');
        }

        if (! $process->isSuccessful()) {
            throw new RuntimeException((string) ($payload['message'] ?? 'Fantrax transaction browser RPC failed.'));
        }

        return $payload;
    }

    private function nodePath(): string
    {
        $configuredPath = trim((string) config('apiurls.fantrax.node_path', ''));

        if ($configuredPath !== '' && is_file($configuredPath) && is_executable($configuredPath)) {
            return $configuredPath;
        }

        foreach ([
            '/opt/homebrew/bin/node',
            '/usr/local/bin/node',
            '/usr/bin/node',
        ] as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return 'node';
    }

    private function browserHeadless(): bool
    {
        return filter_var(config('apiurls.fantrax.browser_headless', false), FILTER_VALIDATE_BOOL);
    }
}
