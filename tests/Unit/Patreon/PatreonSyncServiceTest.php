<?php

declare(strict_types=1);

namespace Tests\Unit\Patreon;

use App\Models\MemberProfile;
use App\Models\Organization;
use App\Models\ProviderAccount;
use App\Services\Patreon\MembershipSyncService;
use App\Services\Patreon\PatreonClient;
use App\Services\Patreon\PatreonSyncService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class PatreonSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function makeService(?PatreonClient $client = null, ?MembershipSyncService $membershipSync = null): PatreonSyncService
    {
        $client ??= Mockery::mock(PatreonClient::class);
        $membershipSync ??= Mockery::mock(MembershipSyncService::class);

        return new class($client, $membershipSync) extends PatreonSyncService {
            public function refreshAccountTokenPublic(ProviderAccount $account, bool $force = false): ProviderAccount
            {
                return $this->refreshAccountToken($account, $force);
            }

            public function callPatreonPublic(ProviderAccount $account, callable $callback): array
            {
                return $this->callPatreon($account, $callback);
            }

            public function displayNameFromMetadataPublic(array $identity, array $campaign): string
            {
                return $this->displayNameFromMetadata($identity, $campaign);
            }

            public function mapStatusPublic(?string $status): string
            {
                return $this->mapStatus($status);
            }

            public function resolveMemberProfilePublic(
                ProviderAccount $account,
                string $providerMemberId,
                ?string $email,
                ?string $displayName,
                ?string $avatar
            ): MemberProfile {
                return $this->resolveMemberProfile($account, $providerMemberId, $email, $displayName, $avatar);
            }

            public function syncMembersPublic(
                ProviderAccount $account,
                array $members,
                array $included,
                array $tierMap,
                string $campaignCurrency
            ): int {
                return $this->syncMembers($account, $members, $included, $tierMap, $campaignCurrency);
            }
        };
    }

    public function test_refresh_account_token_skips_when_not_expired(): void
    {
        Carbon::setTestNow('2024-01-01 00:00:00');

        $account = ProviderAccount::create([
            'organization_id' => Organization::create([
                'name' => 'Org',
                'slug' => Str::slug('Org-' . Str::random(6)),
                'short_name' => 'org',
            ])->id,
            'provider' => 'patreon',
            'access_token' => 'old-token',
            'refresh_token' => 'refresh-token',
            'token_expires_at' => Carbon::now()->addMinutes(10),
        ]);

        $client = Mockery::mock(PatreonClient::class);
        $client->shouldNotReceive('refreshToken');

        $service = $this->makeService($client);
        $result = $service->refreshAccountTokenPublic($account);

        $this->assertSame('old-token', $result->access_token);

        Carbon::setTestNow();
    }

    public function test_refresh_account_token_updates_when_near_expiry(): void
    {
        Carbon::setTestNow('2024-01-01 00:00:00');

        $account = ProviderAccount::create([
            'organization_id' => Organization::create([
                'name' => 'Org',
                'slug' => Str::slug('Org-' . Str::random(6)),
                'short_name' => 'org',
            ])->id,
            'provider' => 'patreon',
            'access_token' => 'old-token',
            'refresh_token' => 'refresh-token',
            'token_expires_at' => Carbon::now()->addMinutes(4),
        ]);

        $client = Mockery::mock(PatreonClient::class);
        $client->shouldReceive('refreshToken')
            ->once()
            ->with('refresh-token')
            ->andReturn([
                'access_token' => 'new-token',
                'refresh_token' => 'new-refresh',
                'expires_in' => 3600,
            ]);

        $service = $this->makeService($client);
        $result = $service->refreshAccountTokenPublic($account);

        $this->assertSame('new-token', $result->access_token);
        $this->assertSame('new-refresh', $result->refresh_token);
        $this->assertTrue($result->token_expires_at->equalTo(Carbon::parse('2024-01-01 01:00:00')));

        Carbon::setTestNow();
    }

    public function test_refresh_account_token_ignores_when_missing_refresh_token(): void
    {
        Carbon::setTestNow('2024-01-01 00:00:00');

        $account = ProviderAccount::create([
            'organization_id' => Organization::create([
                'name' => 'Org',
                'slug' => Str::slug('Org-' . Str::random(6)),
                'short_name' => 'org',
            ])->id,
            'provider' => 'patreon',
            'access_token' => 'old-token',
            'refresh_token' => null,
            'token_expires_at' => Carbon::now()->subMinute(),
        ]);

        $client = Mockery::mock(PatreonClient::class);
        $client->shouldNotReceive('refreshToken');

        $service = $this->makeService($client);
        $result = $service->refreshAccountTokenPublic($account);

        $this->assertSame('old-token', $result->access_token);

        Carbon::setTestNow();
    }

    public function test_call_patreon_retries_after_unauthorized_and_refreshes(): void
    {
        Carbon::setTestNow('2024-01-01 00:00:00');

        $account = ProviderAccount::create([
            'organization_id' => Organization::create([
                'name' => 'Org',
                'slug' => Str::slug('Org-' . Str::random(6)),
                'short_name' => 'org',
            ])->id,
            'provider' => 'patreon',
            'access_token' => 'old-token',
            'refresh_token' => 'refresh-token',
        ]);

        Http::fakeSequence()
            ->push([], 401)
            ->push(['ok' => true], 200);

        $client = Mockery::mock(PatreonClient::class);
        $client->shouldReceive('refreshToken')
            ->once()
            ->with('refresh-token')
            ->andReturn([
                'access_token' => 'new-token',
                'refresh_token' => 'new-refresh',
                'expires_in' => 60,
            ]);

        $service = $this->makeService($client);

        [$payload, $updatedAccount] = $service->callPatreonPublic($account, function (string $token): array {
            return Http::withToken($token)
                ->acceptJson()
                ->get('https://example.test/data')
                ->throw()
                ->json();
        });

        $this->assertSame(['ok' => true], $payload);
        $this->assertSame('new-token', $updatedAccount->access_token);

        Carbon::setTestNow();
    }

    public function test_display_name_resolution_prefers_identity_then_campaign(): void
    {
        $service = $this->makeService();

        $identity = ['data' => ['attributes' => ['full_name' => 'Identity Name']]];
        $campaign = ['data' => ['attributes' => ['creation_name' => 'Campaign Name']]];

        $this->assertSame('Identity Name', $service->displayNameFromMetadataPublic($identity, $campaign));

        $identity = ['data' => ['attributes' => ['full_name' => '']]];
        $this->assertSame('Campaign Name', $service->displayNameFromMetadataPublic($identity, $campaign));

        $identity = ['data' => ['attributes' => []]];
        $campaign = ['data' => ['attributes' => []]];
        $this->assertSame('Patreon Campaign', $service->displayNameFromMetadataPublic($identity, $campaign));
    }

    public function test_map_status_maps_known_values_and_defaults_active(): void
    {
        $service = $this->makeService();

        $this->assertSame('declined', $service->mapStatusPublic('declined_patron'));
        $this->assertSame('former_member', $service->mapStatusPublic('former_patron'));
        $this->assertSame('deleted', $service->mapStatusPublic('deleted'));
        $this->assertSame('active', $service->mapStatusPublic('unknown'));
    }

    public function test_resolve_member_profile_prefers_existing_external_id_and_email_linkage(): void
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

        $existing = MemberProfile::create([
            'organization_id' => $organization->id,
            'email' => 'member@example.com',
            'display_name' => 'Existing Member',
            'external_ids' => ['patreon' => 'member-1'],
        ]);

        $service = $this->makeService();
        $resolved = $service->resolveMemberProfilePublic($account, 'member-1', 'member@example.com', 'New Name', null);

        $this->assertTrue($existing->is($resolved));
        $this->assertSame('Existing Member', $resolved->display_name);
        $this->assertSame('member-1', $resolved->getExternalId('patreon'));
    }

    public function test_resolve_member_profile_attaches_external_id_from_email_and_sets_avatar(): void
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
            'email' => 'member@example.com',
            'display_name' => 'Keep Name',
        ]);

        $service = $this->makeService();
        $resolved = $service->resolveMemberProfilePublic(
            $account,
            'member-2',
            'member@example.com',
            'New Name',
            'https://example.test/avatar.png'
        );

        $this->assertTrue($profile->is($resolved));
        $this->assertSame('Keep Name', $resolved->display_name);
        $this->assertSame('https://example.test/avatar.png', $resolved->avatar_url);
        $this->assertSame('member-2', $resolved->getExternalId('patreon'));
    }

    public function test_sync_members_skips_when_no_tiers_available(): void
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

        $membershipSync = Mockery::mock(MembershipSyncService::class);
        $membershipSync->shouldNotReceive('sync');

        $service = $this->makeService(client: Mockery::mock(PatreonClient::class), membershipSync: $membershipSync);

        $count = $service->syncMembersPublic(
            $account,
            [
                [
                    'type' => 'member',
                    'id' => 'member-1',
                    'attributes' => ['full_name' => 'Member'],
                    'relationships' => [],
                ],
            ],
            [],
            [],
            'USD'
        );

        $this->assertSame(0, $count);
        $this->assertDatabaseCount('memberships', 0);
    }

    public function test_sync_members_skips_when_attributes_missing(): void
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

        $membershipSync = Mockery::mock(MembershipSyncService::class);
        $membershipSync->shouldNotReceive('sync');

        $service = $this->makeService(client: Mockery::mock(PatreonClient::class), membershipSync: $membershipSync);

        $count = $service->syncMembersPublic(
            $account,
            [
                [
                    'type' => 'member',
                    'id' => 'member-1',
                    'attributes' => [],
                    'relationships' => [
                        'currently_entitled_tiers' => ['data' => [['id' => 'tier-1']]],
                    ],
                ],
            ],
            [],
            ['tier-1' => null],
            'USD'
        );

        $this->assertSame(0, $count);
        $this->assertDatabaseCount('memberships', 0);
    }
}
