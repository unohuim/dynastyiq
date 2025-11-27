<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\ProviderAccount;
use App\Services\Patreon\PatreonSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PatreonWebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function fakePatreonSyncResponses(): void
    {
        Http::fake([
            'https://www.patreon.com/api/oauth2/v2/identity*' => Http::response([
                'data' => [
                    'id' => 'user-1',
                    'attributes' => [
                        'full_name' => 'Tester',
                        'email' => 'member@example.com',
                        'image_url' => 'https://example.test/avatar.png',
                    ],
                ],
            ], 200),
            'https://www.patreon.com/api/oauth2/v2/campaigns*' => function ($request) {
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

                if (str_contains($url, '/campaigns/123')) {
                    return Http::response([
                        'data' => [
                            'id' => '123',
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
                            'id' => '123',
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

    public function test_guard_rejects_invalid_signature(): void
    {
        config(['services.patreon.webhook_secret' => 'secret-key']);

        $this->mock(PatreonSyncService::class, function ($mock): void {
            $mock->shouldNotReceive('handleWebhook');
        });

        $payload = ['data' => ['id' => 'abc']];

        $response = $this->postJson(route('patreon.webhook'), $payload, [
            'X-Patreon-Signature' => 'incorrect',
        ]);

        $response->assertStatus(401);
    }

    public function test_guard_accepts_valid_signature_and_triggers_sync(): void
    {
        config(['services.patreon.webhook_secret' => 'secret-key']);

        $organization = Organization::create([
            'name' => 'Org',
            'slug' => Str::slug(Str::uuid()->toString()),
        ]);

        $account = ProviderAccount::create([
            'organization_id' => $organization->id,
            'provider' => 'patreon',
            'external_id' => 'abc',
        ]);

        $payload = [
            'data' => [
                'relationships' => [
                    'campaign' => [
                        'data' => ['id' => 'abc'],
                    ],
                ],
            ],
        ];

        $signature = hash_hmac('md5', json_encode($payload), 'secret-key');

        $this->mock(PatreonSyncService::class, function ($mock) use ($account, $payload): void {
            $mock
                ->shouldReceive('handleWebhook')
                ->once()
                ->withArgs(function ($passedAccount, $passedPayload) use ($account, $payload): bool {
                    return $passedAccount->is($account) && $passedPayload === $payload;
                });
        });

        $this
            ->postJson(route('patreon.webhook'), $payload, ['X-Patreon-Signature' => $signature])
            ->assertNoContent();
    }

    public function test_guard_requires_signature_when_secret_configured(): void
    {
        config(['services.patreon.webhook_secret' => 'secret-key']);

        $this
            ->postJson(route('patreon.webhook'), ['data' => []])
            ->assertStatus(401);
    }

    public function test_returns_no_content_when_no_matching_account(): void
    {
        Log::spy();

        config(['services.patreon.webhook_secret' => 'secret-key']);

        $this->mock(PatreonSyncService::class, function ($mock): void {
            $mock->shouldNotReceive('handleWebhook');
        });

        $payload = [
            'data' => [
                'relationships' => [
                    'campaign' => [
                        'data' => ['id' => '999'],
                    ],
                ],
            ],
        ];

        $signature = hash_hmac('md5', json_encode($payload), 'secret-key');

        $response = $this->postJson(
            route('patreon.webhook'),
            $payload,
            ['X-Patreon-Signature' => $signature]
        );

        $response->assertNoContent();

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context): bool =>
                str_contains($message, 'Patreon webhook received without matching provider account')
                && ($context['campaign_id'] ?? null) === '999'
            );
    }

    public function test_handle_webhook_updates_account_and_records_event(): void
    {
        $this->fakePatreonSyncResponses();

        $organization = Organization::create([
            'name' => 'Org',
            'slug' => Str::slug(Str::uuid()->toString()),
        ]);

        $account = ProviderAccount::create([
            'organization_id' => $organization->id,
            'provider' => 'patreon',
            'display_name' => 'Test Patreon',
            'status' => 'connected',
            'external_id' => '123',
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addHour(),
        ]);

        $payload = [
            'data' => [
                [
                    'type' => 'member',
                    'id' => 'member-1',
                    'attributes' => [
                        'email' => 'member@example.com',
                        'full_name' => 'Member One',
                        'currently_entitled_amount_cents' => 500,
                        'currency' => 'USD',
                        'patron_status' => 'active',
                        'pledge_relationship_start' => '2024-01-01T00:00:00Z',
                    ],
                    'relationships' => [
                        'currently_entitled_tiers' => [
                            'data' => [
                                ['id' => 'tier-1'],
                            ],
                        ],
                    ],
                ],
            ],
            'included' => [
                [
                    'type' => 'tier',
                    'id' => 'tier-1',
                    'attributes' => [
                        'title' => 'Gold',
                        'amount_cents' => 500,
                        'currency' => 'USD',
                        'published' => true,
                    ],
                ],
            ],
        ];

        Carbon::setTestNow($now = Carbon::parse('2024-05-01 12:00:00'));

        app(PatreonSyncService::class)->handleWebhook($account, $payload);

        $account->refresh();

        $this->assertTrue($account->last_webhook_at?->equalTo($now));

        $this->assertDatabaseHas('membership_tiers', [
            'provider_account_id' => $account->id,
            'external_id' => 'tier-1',
            'name' => 'Gold',
        ]);

        $this->assertDatabaseHas('memberships', [
            'provider_account_id' => $account->id,
            'provider_member_id' => 'member-1',
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('membership_events', [
            'provider_account_id' => $account->id,
            'event_type' => 'patreon.webhook',
        ]);
    }
}
