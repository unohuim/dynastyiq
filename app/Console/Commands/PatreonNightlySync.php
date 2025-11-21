<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ProviderAccount;
use App\Services\Patreon\PatreonSyncService;
use Illuminate\Console\Command;

class PatreonNightlySync extends Command
{
    protected $signature = 'patreon:sync-nightly';

    protected $description = 'Sync Patreon memberships for all connected organizations.';

    public function handle(PatreonSyncService $service): int
    {
        $accounts = ProviderAccount::where('provider', 'patreon')
            ->where('status', 'connected')
            ->get();

        if ($accounts->isEmpty()) {
            $this->info('No connected Patreon accounts found.');
            return self::SUCCESS;
        }

        foreach ($accounts as $account) {
            $result = $service->syncProviderAccount($account);
            $this->line("Synced provider account {$account->id} ({$result['members_synced']} members, {$result['tiers_synced']} tiers)");
        }

        $this->info('Patreon nightly sync complete.');

        return self::SUCCESS;
    }
}
