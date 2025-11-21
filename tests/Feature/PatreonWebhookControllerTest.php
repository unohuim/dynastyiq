<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\ProviderAccount;
use App\Services\Patreon\PatreonSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PatreonWebhookControllerTest extends TestCase
{
    use RefreshDatabase;

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

        $response = $this->postJson(route('patreon.webhook'), $payload);

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
        $organization = Organization::create([
            'name' => 'Org',
            'slug' => Str::slug(Str::uuid()->toString()),
        ]);

        $account = ProviderAccount::create([
            'organization_id' => $organization->id,
            'provider' => 'patreon',
            'display_name' => 'Test Patreon',
            'status' => 'connected',
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
