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
}
