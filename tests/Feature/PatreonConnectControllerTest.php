<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\ProviderAccount;
use App\Models\User;
use App\Services\Patreon\PatreonSyncService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class PatreonConnectControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function fakePatreonSyncResponses(
        string $campaignId = '123',
        string $identityName = 'Tester'
    ): void {
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
                    'type' => 'user',
                    'attributes' => [
                        'full_name' => $identityName,
                        'email' => 'test@example.com',
                        'image_url' => 'https://example.test/avatar.png',
                    ],
                ],
            ], 200),
            'https://www.patreon.com/api/oauth2/v2/campaigns*' => function ($request) use ($campaignId) {
                $url = $request->url();

                if (str_contains($url, '/members')) {
                    return Http::response([
                        'data' => [],
                        'included' => [],
                        'links' => ['next' => null],
                    ], 200);
                }

                if (str_contains($url, 'include=tiers')) {
                    return Http::response([
                        'included' => [
                            [
                                'type' => 'tier',
                                'id' => 'tier-1',
                                'attributes' => [
                                    'title' => 'Gold',
                                    'amount_cents' => 500,
                                    'currency' => 'USD',
                                ],
                            ],
                        ],
                    ], 200);
                }

                if (str_contains($url, "/campaigns/{$campaignId}")) {
                    return Http::response([
                        'data' => [
                            'id' => $campaignId,
                            'type' => 'campaign',
                            'attributes' => [
                                'currency' => 'USD',
                                'creation_name' => 'Tester Campaign',
                                'summary' => 'Tester Campaign',
                            ],
                            'relationships' => [
                                'creator' => [
                                    'data' => ['id' => 'user-1'],
                                ],
                            ],
                        ],
                    ], 200);
                }

                return Http::response([
                    'data' => [
                        [
                            'id' => $campaignId,
                            'type' => 'campaign',
                            'attributes' => ['currency' => 'USD'],
                            'relationships' => [
                                'creator' => [
                                    'data' => ['id' => 'user-1'],
                                ],
                            ],
                        ],
                    ],
                ], 200);
            },
        ]);
    }

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
        config()->set('services.patreon.webhook_secret', 'keep-me');

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

        $this->fakePatreonSyncResponses();

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
            'https://www.patreon.com/api/oauth2/v2/campaigns*' => Http::response([
                'data' => [[
                    'id' => '123',
                    'type' => 'campaign',
                    'attributes' => ['currency' => 'USD'],
                    'relationships' => [
                        'creator' => [
                            'data' => ['id' => 'user-1'],
                        ],
                    ],
                ]],
            ], 200),
            'https://www.patreon.com/api/oauth2/v2/campaigns/123*' => Http::response([
                'data' => [
                    'id' => '123',
                    'type' => 'campaign',
                    'attributes' => [
                        'currency' => 'USD',
                        'creation_name' => 'Tester Campaign',
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

        $this->fakePatreonSyncResponses();

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
        $this->assertSame('Tester', $account->display_name);
        $this->assertSame('token', $account->access_token);
        $this->assertSame('refresh', $account->refresh_token);
        $this->assertSame(['identity', 'campaigns', 'memberships'], $account->scopes);
        $this->assertTrue($account->token_expires_at->equalTo(Carbon::parse('2024-01-01 02:00:00')));
        $this->assertSame('test@example.com', data_get($account->meta, 'identity.data.attributes.email'));
        $this->assertSame('Tester Campaign', data_get($account->meta, 'campaign.data.attributes.summary'));

        Carbon::setTestNow();
    }

    public function test_disconnect_deletes_account_and_returns_json(): void
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
            'display_name' => 'To Remove',
        ]);

        $this
            ->actingAs($user)
            ->deleteJson(route('patreon.disconnect', $organization))
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertModelMissing($account);
    }

    public function test_manual_sync_endpoint_returns_payload_for_authorized_user(): void
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
            'status' => 'connected',
        ]);

        $this->mock(PatreonSyncService::class, function ($mock): void {
            $mock
                ->shouldReceive('syncProviderAccount')
                ->once()
                ->andReturn(['members_synced' => 2, 'tiers_synced' => 1]);
        });

        $response = $this
            ->actingAs($user)
            ->postJson(route('patreon.sync', $organization));

        $response
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'account_id' => $account->id,
                'members_synced' => 2,
                'tiers_synced' => 1,
            ]);
    }

    public function test_callback_missing_code_returns_error_without_creating_account(): void
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

        $this->assertDatabaseCount('provider_accounts', 0);
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

        $this->fakePatreonSyncResponses();

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
            'display_name' => 'Tester',
            'status' => 'connected',
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('communities.index'));

        $response->assertOk();
        $response->assertSee('Patreon');
        $response->assertSee('Community Members');
        $response->assertSee('Tester');

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

        $this->fakePatreonSyncResponses('999');

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

        $this->assertSame('Tester', $account->display_name);
        $this->assertSame('Tester Campaign', data_get($account->meta, 'campaign.data.attributes.summary'));
        $this->assertSame('999', data_get($account->meta, 'campaign.data.id'));

        Carbon::setTestNow();
    }
}
