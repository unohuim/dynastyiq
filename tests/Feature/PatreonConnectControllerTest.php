<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\ProviderAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class PatreonConnectControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_callback_with_invalid_state_redirects_with_error(): void
    {
        $user = User::factory()->create();

        $this
            ->actingAs($user)
            ->get(route('patreon.callback', ['state' => 'invalid', 'code' => 'abc']))
            ->assertRedirect(route('communities.index'))
            ->assertSessionHasErrors('patreon');
    }

    public function test_callback_preserves_existing_webhook_secret(): void
    {
        $user = User::factory()->create();

        $organization = Organization::create([
            'name' => 'Test Org',
            'short_name' => 'test-org',
            'slug' => 'test-org-' . Str::random(6),
            'settings' => ['commissioner_tools' => true],
        ]);

        $user->organizations()->sync([$organization->id]);

        $account = ProviderAccount::create([
            'organization_id' => $organization->id,
            'provider' => 'patreon',
            'webhook_secret' => 'keep-me',
            'status' => 'connected',
        ]);

        Http::fake([
            'https://www.patreon.com/api/oauth2/token' => Http::response([
                'access_token' => 'token',
                'refresh_token' => 'refresh',
                'expires_in' => 3600,
                'scope' => 'identity campaigns',
            ], 200),
            'https://www.patreon.com/api/oauth2/v2/identity*' => Http::response([
                'data' => [
                    'attributes' => ['full_name' => 'Tester'],
                    'relationships' => ['campaign' => ['data' => ['id' => '123']]],
                ],
            ], 200),
            'https://www.patreon.com/api/oauth2/v2/campaigns/123*' => Http::response([
                'data' => [
                    'id' => '123',
                    'type' => 'campaign',
                    'attributes' => [
                        'name' => 'Tester Campaign',
                        'avatar_photo_url' => 'https://example.test/avatar.png',
                    ],
                ],
            ], 200),
        ]);

        $state = encrypt([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'ts' => now()->timestamp,
        ]);

        $this
            ->actingAs($user)
            ->get(route('patreon.callback', ['state' => $state, 'code' => 'abc']))
            ->assertRedirect(route('communities.index'));

        $account->refresh();

        $this->assertSame('keep-me', $account->webhook_secret);
    }

    public function test_callback_requires_existing_organization(): void
    {
        $user = User::factory()->create();

        $state = encrypt([
            'organization_id' => 9999,
            'user_id' => $user->id,
            'ts' => now()->timestamp,
        ]);

        $this
            ->actingAs($user)
            ->get(route('patreon.callback', ['state' => $state, 'code' => 'abc']))
            ->assertRedirect(route('communities.index'))
            ->assertSessionHasErrors('patreon');
    }

    public function test_callback_rejects_unrelated_user(): void
    {
        $user = User::factory()->create();
        $organization = Organization::create([
            'name' => 'Other Org',
            'short_name' => 'other-org',
            'slug' => 'other-org-' . Str::random(6),
            'settings' => ['commissioner_tools' => true],
        ]);

        $state = encrypt([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'ts' => now()->timestamp,
        ]);

        $this
            ->actingAs($user)
            ->get(route('patreon.callback', ['state' => $state, 'code' => 'abc']))
            ->assertRedirect(route('communities.index'))
            ->assertSessionHasErrors('patreon');
    }

    public function test_callback_handles_oauth_error_response(): void
    {
        $user = User::factory()->create();
        $organization = Organization::create([
            'name' => 'Other Org',
            'short_name' => 'other-org',
            'slug' => 'other-org-' . Str::random(6),
            'settings' => ['commissioner_tools' => true],
        ]);

        $user->organizations()->sync([$organization->id]);

        $this
            ->actingAs($user)
            ->get(route('patreon.callback', [
                'state' => encrypt([
                    'organization_id' => $organization->id,
                    'user_id' => $user->id,
                    'ts' => now()->timestamp,
                ]),
                'error' => 'access_denied',
                'error_description' => 'Access denied by user.',
            ]))
            ->assertRedirect(route('communities.index'))
            ->assertSessionHasErrors('patreon');
    }
}
