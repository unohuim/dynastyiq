<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\PatreonNightlySync;
use App\Models\Organization;
use App\Models\ProviderAccount;
use App\Services\Patreon\PatreonSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PatreonNightlySyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_processes_connected_accounts(): void
    {
        $organizationA = Organization::create([
            'name' => 'Org A',
            'slug' => Str::slug('OrgA-' . Str::random(6)),
            'short_name' => 'orga',
        ]);
        $organizationB = Organization::create([
            'name' => 'Org B',
            'slug' => Str::slug('OrgB-' . Str::random(6)),
            'short_name' => 'orgb',
        ]);

        $accountA = ProviderAccount::create([
            'organization_id' => $organizationA->id,
            'provider' => 'patreon',
            'status' => 'connected',
        ]);
        $accountB = ProviderAccount::create([
            'organization_id' => $organizationB->id,
            'provider' => 'patreon',
            'status' => 'connected',
        ]);

        $this->mock(PatreonSyncService::class, function ($mock) use ($accountA, $accountB): void {
            $mock->shouldReceive('syncProviderAccount')
                ->times(2)
                ->withArgs(fn ($account) => $account->is($accountA) || $account->is($accountB))
                ->andReturn(['members_synced' => 0, 'tiers_synced' => 0]);
        });

        $this->artisan('patreon:sync-nightly')
            ->assertExitCode(PatreonNightlySync::SUCCESS)
            ->expectsOutputToContain('Patreon nightly sync complete.');
    }
}
