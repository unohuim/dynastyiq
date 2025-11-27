<?php

declare(strict_types=1);

namespace Tests\Unit\Patreon;

use App\Models\Organization;
use App\Models\ProviderAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProviderAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_patreon_identity_composes_display_fields(): void
    {
        $organizationId = \App\Models\Organization::create([
            'name' => 'Org',
            'slug' => Str::slug('Org-' . Str::random(6)),
            'short_name' => 'org',
        ])->id;

        $account = ProviderAccount::create([
            'organization_id' => $organizationId,
            'provider' => 'patreon',
            'display_name' => 'Display',
            'meta' => [
                'identity' => [
                    'id' => 'identity-1',
                    'full_name' => 'Identity Name',
                    'email' => 'user@example.com',
                    'vanity' => 'creator',
                    'image_url' => 'https://example.test/avatar.png',
                ],
                'campaign' => [
                    'id' => 'campaign-1',
                    'summary' => 'Campaign Summary',
                ],
            ],
        ]);

        $identity = $account->patreonIdentity();

        $this->assertSame('@creator', $identity['display']['handle']);
        $this->assertSame('Campaign Summary', $identity['display']['campaign']);
        $this->assertSame('https://example.test/avatar.png', $identity['avatar']);
        $this->assertSame('Campaign Summary', $identity['display']['name']);
    }
}
