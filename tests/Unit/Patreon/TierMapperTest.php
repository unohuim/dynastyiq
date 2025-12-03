<?php

declare(strict_types=1);

namespace Tests\Unit\Patreon;

use App\Models\MembershipTier;
use App\Models\Organization;
use App\Models\ProviderAccount;
use App\Services\Patreon\TierMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class TierMapperTest extends TestCase
{
    use RefreshDatabase;

    public function test_throws_when_title_missing(): void
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

        $mapper = new TierMapper($account, 'USD');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Patreon tier missing required title.');

        $mapper->map([
            ['type' => 'tier', 'id' => 'tier-1', 'attributes' => ['title' => '']],
        ]);
    }

    public function test_preserves_diq_owned_tier_metadata_when_mapping(): void
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

        $existing = MembershipTier::create([
            'organization_id' => $organization->id,
            'provider_account_id' => $account->id,
            'provider' => 'patreon',
            'external_id' => 'tier-1',
            'name' => 'Gold',
            'description' => 'Existing description',
            'amount_cents' => 700,
            'currency' => 'USD',
        ]);

        $mapper = new TierMapper($account, 'USD');

        $result = $mapper->map([
            [
                'type' => 'tier',
                'id' => 'tier-1',
                'attributes' => [
                    'title' => 'Gold',
                    'description' => 'New description',
                    'amount_cents' => 500,
                    'currency' => 'USD',
                ],
            ],
        ]);

        $mappedTier = $result['tier-1'];

        $this->assertTrue($mappedTier->is($existing));
        $this->assertSame($account->id, $mappedTier->provider_account_id);
        $this->assertSame('Gold', $mappedTier->name);
        $this->assertSame('New description', $mappedTier->description);
        $this->assertSame(500, $mappedTier->amount_cents);
        $this->assertSame('USD', $mappedTier->currency);
        $this->assertSame('tier-1', $mappedTier->external_id);
    }

    public function test_reuses_existing_free_tier_and_sets_provider_data(): void
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

        $existingFree = MembershipTier::create([
            'organization_id' => $organization->id,
            'name' => 'Free Tier',
            'amount_cents' => null,
            'currency' => 'USD',
        ]);

        $mapper = new TierMapper($account, 'USD');

        $mapped = $mapper->map([
            [
                'type' => 'tier',
                'id' => 'free-1',
                'attributes' => [
                    'title' => 'Patreon Free',
                    'amount_cents' => 0,
                    'currency' => 'USD',
                ],
            ],
        ]);

        $tier = $mapped['free-1'];

        $this->assertTrue($tier->is($existingFree));
        $this->assertSame($account->id, $tier->provider_account_id);
        $this->assertSame('patreon', $tier->provider);
        $this->assertSame('free-1', $tier->external_id);
        $this->assertSame(0, $tier->amount_cents);
        $this->assertSame('USD', $tier->currency);
        $this->assertSame('Patreon Free', $tier->name);
    }

    public function test_matches_diq_owned_tier_by_name_without_changing_paid_amount(): void
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

        $diqTier = MembershipTier::create([
            'organization_id' => $organization->id,
            'name' => 'Gold',
            'description' => 'Local description',
            'amount_cents' => 750,
            'currency' => 'USD',
        ]);

        $mapper = new TierMapper($account, 'USD');

        $mapped = $mapper->map([
            [
                'type' => 'tier',
                'id' => 'gold-provider',
                'attributes' => [
                    'title' => 'Gold',
                    'description' => 'Provider description',
                    'amount_cents' => 750,
                    'currency' => 'USD',
                ],
            ],
        ]);

        $tier = $mapped['gold-provider'];

        $this->assertTrue($tier->is($diqTier));
        $this->assertSame(750, $tier->amount_cents);
        $this->assertSame('Gold', $tier->name);
        $this->assertSame('Local description', $tier->description);
        $this->assertSame($account->id, $tier->provider_account_id);
        $this->assertSame('patreon', $tier->provider);
        $this->assertSame('gold-provider', $tier->external_id);
        $this->assertNull($tier->metadata);
    }
}
