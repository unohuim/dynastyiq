<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\ProviderAccount;
use App\Models\User;
use Carbon\Carbon;
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

    public function test_redirect_falls_back_to_callback_route_when_config_missing(): void
    {
        config()->set('services.patreon.redirect', null);

        $user = User::factory()->create();

        $organization = Organization::create([
            'name' => 'Test Org',
            'short_name' => 'test-org',
            'slug' => 'test-org-' . Str::random(6),
            'settings' => ['commissioner_tools' => true],
        ]);

        $user->organizations()->sync([$organization->id]);

        $response = $this
            ->actingAs($user)
            ->get(route('patreon.redirect', $organization->id));

        $response->assertRedirect();
        $this->assertStringContainsString(
            urlencode(route('patreon.callback')),
            $response->headers->get('Location')
        );
    }

    public function test_callback_uses_callback_route_when_redirect_not_configured(): void
    {
        config()->set('services.patreon.redirect', null);

        $user = User::factory()->create();

        $organization = Organization::create([
            'name' => 'Test Org',
            'short_name' => 'test-org',
            'slug' => 'test-org-' . Str::random(6),
            'settings' => ['commissioner_tools' => true],
        ]);

        $user->organizations()->sync([$organization->id]);

        Http::fake([
            'https://www.patreon.com/api/oauth2/token' => function ($request) {
                $this->assertSame(route('patreon.callback'), $request['redirect_uri']);

                return Http::response([
                    'access_token' => 'token',
                    'refresh_token' => 'refresh',
                    'expires_in' => 3600,
                    'scope' => 'identity campaigns',
                ], 200);
            },
            'https://www.patreon.com/api/oauth2/v2/identity*' => Http::response([
                'data' => [
                    'attributes' => ['full_name' => 'Tester'],
                    'relationships' => ['campaign' => ['data' => ['id' => '123']]],
                ],
                'included' => [
                    [
                        'type' => 'campaign',
                        'attributes' => [
                            'name' => 'Tester Campaign',
                            'avatar_photo_url' => 'https://example.test/avatar.png',
                        ],
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

    public function test_redirect_builds_oauth_url_with_expected_parameters(): void
    {
        config()->set('patreon.oauth.authorize', 'https://oauth.example/authorize');
        config()->set('services.patreon.client_id', 'client-id');
        config()->set('services.patreon.redirect', 'https://app.example/patreon/callback');
        config()->set('patreon.scopes', ['identity', 'campaigns', 'memberships']);

        $user = User::factory()->create();

        $organization = Organization::create([
            'name' => 'Test Org',
            'short_name' => 'test-org',
            'slug' => 'test-org-' . Str::random(6),
            'settings' => ['commissioner_tools' => true],
        ]);

        $user->organizations()->sync([$organization->id]);

        $response = $this
            ->actingAs($user)
            ->get(route('patreon.redirect', $organization->id));

        $response->assertRedirect();

        $location = $response->headers->get('Location');
        $this->assertStringStartsWith('https://oauth.example/authorize?', $location);

        $parts = parse_url($location);
        parse_str($parts['query'] ?? '', $query);

        $this->assertSame('code', $query['response_type']);
        $this->assertSame('client-id', $query['client_id']);
        $this->assertSame('https://app.example/patreon/callback', $query['redirect_uri']);
        $this->assertSame('identity campaigns memberships', $query['scope']);

        $state = decrypt($query['state']);

        $this->assertSame($organization->id, $state['organization_id']);
        $this->assertSame($user->id, $state['user_id']);
        $this->assertArrayHasKey('ts', $state);
    }

    public function test_callback_rejects_state_for_different_user(): void
    {
        $actingUser = User::factory()->create();
        $stateUser = User::factory()->create();

        $organization = Organization::create([
            'name' => 'Test Org',
            'short_name' => 'test-org',
            'slug' => 'test-org-' . Str::random(6),
            'settings' => ['commissioner_tools' => true],
        ]);

        $actingUser->organizations()->sync([$organization->id]);

        $state = encrypt([
            'organization_id' => $organization->id,
            'user_id' => $stateUser->id,
            'ts' => now()->timestamp,
        ]);

        $this
            ->actingAs($actingUser)
            ->get(route('patreon.callback', ['state' => $state, 'code' => 'abc']))
            ->assertRedirect(route('communities.index'))
            ->assertSessionHasErrors('patreon');
    }

    public function test_callback_missing_code_returns_error(): void
    {
        $user = User::factory()->create();

        $organization = Organization::create([
            'name' => 'Test Org',
            'short_name' => 'test-org',
            'slug' => 'test-org-' . Str::random(6),
            'settings' => ['commissioner_tools' => true],
        ]);

        $user->organizations()->sync([$organization->id]);

        $state = encrypt([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'ts' => now()->timestamp,
        ]);

        $this
            ->actingAs($user)
            ->get(route('patreon.callback', ['state' => $state]))
            ->assertRedirect(route('communities.index'))
            ->assertSessionHasErrors('patreon');
    }

    public function test_callback_creates_connected_account_with_tokens_and_metadata(): void
    {
        Carbon::setTestNow('2024-01-01 00:00:00');

        $user = User::factory()->create();

        $organization = Organization::create([
            'name' => 'Test Org',
            'short_name' => 'test-org',
            'slug' => 'test-org-' . Str::random(6),
            'settings' => ['commissioner_tools' => true],
        ]);

        $user->organizations()->sync([$organization->id]);

        Http::fake([
            'https://www.patreon.com/api/oauth2/token' => Http::response([
                'access_token' => 'token',
                'refresh_token' => 'refresh',
                'expires_in' => 7200,
                'scope' => 'identity campaigns',
            ], 200),
            'https://www.patreon.com/api/oauth2/v2/identity*' => Http::response([
                'data' => [
                    'id' => 'user-1',
                    'attributes' => ['full_name' => 'Tester', 'email' => 'test@example.com'],
                    'relationships' => ['campaign' => ['data' => ['id' => '123']]],
                ],
                'included' => [
                    [
                        'type' => 'campaign',
                        'id' => '123',
                        'attributes' => [
                            'name' => 'Tester Campaign',
                            'avatar_photo_url' => 'https://example.test/avatar.png',
                        ],
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

        $account = ProviderAccount::where('organization_id', $organization->id)
            ->where('provider', 'patreon')
            ->firstOrFail();

        $this->assertSame('connected', $account->status);
        $this->assertSame('123', $account->external_id);
        $this->assertSame('Tester Campaign', $account->display_name);
        $this->assertSame('token', $account->access_token);
        $this->assertSame('refresh', $account->refresh_token);
        $this->assertSame(['identity', 'campaigns'], $account->scopes);
        $this->assertTrue($account->token_expires_at->equalTo(Carbon::parse('2024-01-01 02:00:00')));
        $this->assertSame('test@example.com', data_get($account->meta, 'user.email'));
        $this->assertSame('Tester Campaign', data_get($account->meta, 'campaign.name'));

        Carbon::setTestNow();
    }

    public function test_memberships_section_shows_connected_patreon_account_after_callback(): void
    {
        Carbon::setTestNow('2024-01-01 00:00:00');

        $user = User::factory()->create();

        $organization = Organization::create([
            'name' => 'Test Org',
            'short_name' => 'test-org',
            'slug' => 'test-org-' . Str::random(6),
            'settings' => [
                'commissioner_tools' => true,
                'creator_tools' => true,
            ],
        ]);

        $user->organizations()->sync([$organization->id]);

        Http::fake([
            'https://www.patreon.com/api/oauth2/token' => Http::response([
                'access_token' => 'token',
                'refresh_token' => 'refresh',
                'expires_in' => 7200,
                'scope' => 'identity campaigns memberships',
            ], 200),
            'https://www.patreon.com/api/oauth2/v2/identity*' => Http::response([
                'data' => [
                    'id' => 'user-1',
                    'attributes' => ['full_name' => 'Tester', 'email' => 'test@example.com'],
                    'relationships' => ['campaign' => ['data' => ['id' => '123']]],
                ],
                'included' => [
                    [
                        'type' => 'campaign',
                        'id' => '123',
                        'attributes' => [
                            'name' => 'Tester Campaign',
                            'avatar_photo_url' => 'https://example.test/avatar.png',
                        ],
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

        $this->assertDatabaseCount('provider_accounts', 1);
        $this->assertDatabaseHas('provider_accounts', [
            'organization_id' => $organization->id,
            'provider' => 'patreon',
            'display_name' => 'Tester Campaign',
            'status' => 'connected',
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('communities.index'));

        $response->assertOk();
        $response->assertSee('Memberships');
        $response->assertSee('Tester Campaign');

        Carbon::setTestNow();
    }

    public function test_callback_fetches_campaign_when_identity_has_no_campaign(): void
    {
        Carbon::setTestNow('2024-01-01 00:00:00');

        $user = User::factory()->create();

        $organization = Organization::create([
            'name' => 'Test Org',
            'short_name' => 'test-org',
            'slug' => 'test-org-' . Str::random(6),
            'settings' => ['commissioner_tools' => true],
        ]);

        $user->organizations()->sync([$organization->id]);

        Http::fake([
            'https://www.patreon.com/api/oauth2/token' => Http::response([
                'access_token' => 'token',
                'refresh_token' => 'refresh',
                'expires_in' => 3600,
                'scope' => 'identity campaigns memberships',
            ], 200),
            'https://www.patreon.com/api/oauth2/v2/identity*' => Http::response([
                'data' => [
                    'id' => 'user-1',
                    'attributes' => ['full_name' => 'Tester'],
                    'relationships' => ['campaign' => ['data' => null]],
                ],
                'included' => [],
            ], 200),
            'https://www.patreon.com/api/oauth2/v2/campaigns*' => Http::response([
                'data' => [[
                    'id' => '999',
                    'type' => 'campaign',
                    'attributes' => [
                        'name' => 'Fallback Campaign',
                        'avatar_photo_url' => 'https://example.test/campaign.png',
                    ],
                ]],
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

        Http::assertSent(fn ($request) => str_contains($request->url(), '/campaigns'));

        $account = ProviderAccount::where('organization_id', $organization->id)
            ->where('provider', 'patreon')
            ->firstOrFail();

        $this->assertSame('Fallback Campaign', $account->display_name);
        $this->assertSame('Fallback Campaign', data_get($account->meta, 'campaign.name'));
        $this->assertSame('999', data_get($account->meta, 'campaign.id'));

        Carbon::setTestNow();
    }
}
