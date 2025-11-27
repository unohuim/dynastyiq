<?php

declare(strict_types=1);

namespace Tests\Unit\Patreon;

use App\Models\MemberProfile;
use App\Models\MembershipTier;
use App\Models\Organization;
use App\Models\ProviderAccount;
use App\Services\Patreon\MembershipSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MembershipSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_logs_events_when_membership_changes(): void
    {
        $organization = Organization::create([
            'name' => 'Org',
            'slug' => Str::slug('Org-' . Str::random(6)),
            'short_name' => 'org',
        ]);
        $account = ProviderAccount::create([
            'organization_id' => $organization->id,
            'provider' => 'patreon',
        ]);
        $profile = MemberProfile::create([
            'organization_id' => $organization->id,
            'display_name' => 'Member',
        ]);
        $tier = MembershipTier::create([
            'organization_id' => $organization->id,
            'provider_account_id' => $account->id,
            'provider' => 'patreon',
            'external_id' => 'tier-1',
            'name' => 'Gold',
        ]);

        $service = new MembershipSyncService();

        $membership = $service->sync(
            $account,
            $profile,
            'member-1',
            $tier,
            500,
            'USD',
            'active',
            '2024-01-01T00:00:00Z',
            null,
            []
        );

        $service->sync(
            $account,
            $profile,
            'member-1',
            $tier,
            700,
            'USD',
            'former_member',
            '2024-01-01T00:00:00Z',
            '2024-02-01T00:00:00Z',
            []
        );

        $this->assertDatabaseHas('membership_events', [
            'membership_id' => $membership->id,
            'event_type' => 'pledge.changed',
        ]);
        $this->assertDatabaseHas('membership_events', [
            'membership_id' => $membership->id,
            'event_type' => 'status.changed',
        ]);
    }

    public function test_skips_logging_when_no_changes(): void
    {
        $organization = Organization::create([
            'name' => 'Org',
            'slug' => Str::slug('Org-' . Str::random(6)),
            'short_name' => 'org',
        ]);
        $account = ProviderAccount::create([
            'organization_id' => $organization->id,
            'provider' => 'patreon',
        ]);
        $profile = MemberProfile::create([
            'organization_id' => $organization->id,
            'display_name' => 'Member',
        ]);
        $tier = MembershipTier::create([
            'organization_id' => $organization->id,
            'provider_account_id' => $account->id,
            'provider' => 'patreon',
            'external_id' => 'tier-1',
            'name' => 'Gold',
        ]);

        $service = new MembershipSyncService();

        $service->sync(
            $account,
            $profile,
            'member-1',
            $tier,
            500,
            'USD',
            'active',
            '2024-01-01T00:00:00Z',
            null,
            []
        );

        $initialEvents = \App\Models\MembershipEvent::count();

        $service->sync(
            $account,
            $profile,
            'member-1',
            $tier,
            500,
            'USD',
            'active',
            '2024-01-01T00:00:00Z',
            null,
            []
        );

        $this->assertSame($initialEvents, \App\Models\MembershipEvent::count());
    }
}
