<?php

declare(strict_types=1);

use App\Models\ApiClient;
use App\Models\Role;
use App\Models\User;
use App\Models\WebhookSubscription;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-25 16:00:00 UTC'));
    $this->withoutVite();
    Bus::fake();
    Http::preventStrayRequests();
    $this->mock(\App\Services\PlatformState::class)->shouldReceive('seeded')->andReturn(true);
    $this->admin = User::factory()->create();
    $role = Role::create(['name' => 'Super Admin', 'slug' => 'super-admin', 'level' => 99]);
    $this->admin->roles()->attach($role->id, ['organization_id' => null]);
    $this->client = ApiClient::create(['name' => 'Gner8', 'slug' => 'gner8-fixture', 'token_prefix' => 'fixture',
        'token_hash' => ApiClient::hashToken('fixture-token'), 'scopes' => ['nhl-stats:read']]);
    $this->body = ['name' => 'Gner8 predictions', 'api_client_id' => $this->client->id,
        'receiving_url' => 'https://gner8.example/webhooks/diq', 'events' => ['prediction.available'], 'is_enabled' => false];
    $this->subscription = fn (array $extra = []) => WebhookSubscription::create([
        ...$this->body, 'signing_secret' => 'whsec_fixture_secret', ...$extra,
    ]);
});

afterEach(function (): void {
    Http::assertNothingSent();
    Bus::assertNothingDispatched();
    $this->travelBack();
});

it('blocks guests on every inventory endpoint', function (string $method, string $path): void {
    $this->json($method, $path, $this->body)->assertUnauthorized();
})->with([
    ['GET', '/admin/webhooks'], ['POST', '/admin/webhooks'], ['PUT', '/admin/webhooks/1'],
    ['PATCH', '/admin/webhooks/1/status'], ['GET', '/admin/webhooks/1/secret'], ['DELETE', '/admin/webhooks/1'],
]);

it('blocks ordinary members on every inventory endpoint', function (string $method, string $path): void {
    $this->actingAs(User::factory()->create())->json($method, $path, $this->body)->assertForbidden();
})->with([
    ['GET', '/admin/webhooks'], ['POST', '/admin/webhooks'], ['PUT', '/admin/webhooks/1'],
    ['PATCH', '/admin/webhooks/1/status'], ['GET', '/admin/webhooks/1/secret'], ['DELETE', '/admin/webhooks/1'],
]);

it('serves the Vue inventory with empty data to super admins', function (): void {
    $this->actingAs($this->admin)->get('/admin/webhooks')->assertOk()->assertInertia(fn (Assert $page) =>
        $page->component('Admin/Webhooks')->has('subscriptions', 0)->has('apiClients', 1)->has('eventOptions', 1));
});

it('creates inventory and exposes the same persisted configuration through an Inertia read', function (): void {
    $response = $this->actingAs($this->admin)->postJson('/admin/webhooks', $this->body)->assertCreated()
        ->assertJsonPath('subscription.events', ['prediction.available'])->assertJsonPath('subscription.is_enabled', false);
    $id = $response->json('subscription.id');
    $this->assertDatabaseHas('webhook_subscriptions', ['id' => $id, 'receiving_url' => $this->body['receiving_url']]);
    $this->get('/admin/webhooks')->assertInertia(fn (Assert $page) => $page->component('Admin/Webhooks')
        ->where('subscriptions.0.id', $id)->where('subscriptions.0.name', $this->body['name'])
        ->where('subscriptions.0.client_name', 'Gner8')->where('subscriptions.0.events', ['prediction.available']));
});

it('encrypts generated signing secrets at rest', function (): void {
    $id = $this->actingAs($this->admin)->postJson('/admin/webhooks', $this->body)->assertCreated()->json('subscription.id');
    $subscription = WebhookSubscription::findOrFail($id);
    expect($subscription->signing_secret)->toStartWith('whsec_');
    expect(DB::table('webhook_subscriptions')->where('id', $id)->value('signing_secret'))->not->toBe($subscription->signing_secret);
});

it('omits secrets and client access tokens from all initial inventory props', function (): void {
    ($this->subscription)();
    $this->actingAs($this->admin)->get('/admin/webhooks')->assertInertia(fn (Assert $page) => $page
        ->missing('subscriptions.0.signing_secret')->missing('apiClients.0.token_hash')->missing('apiClients.0.token_prefix'))
        ->assertDontSee('whsec_fixture_secret');
});

it('never returns a signing secret in a create response', function (): void {
    $this->actingAs($this->admin)->postJson('/admin/webhooks', $this->body)->assertCreated()->assertJsonMissingPath('subscription.signing_secret');
});

it('reveals a persisted secret only through the protected no-store endpoint', function (): void {
    $subscription = ($this->subscription)();
    $this->actingAs($this->admin)->getJson('/admin/webhooks/'.$subscription->id.'/secret')->assertOk()
        ->assertJsonPath('secret', 'whsec_fixture_secret')->assertHeader('Cache-Control', 'no-store, private');
});

it('updates configuration and retains its existing secret', function (): void {
    $subscription = ($this->subscription)();
    $this->actingAs($this->admin)->putJson('/admin/webhooks/'.$subscription->id,
        [...$this->body, 'name' => 'Renamed', 'receiving_url' => 'https://new.example/events', 'is_enabled' => true])
        ->assertOk()->assertJsonPath('subscription.name', 'Renamed')->assertJsonPath('subscription.is_enabled', true);
    expect($subscription->fresh()->signing_secret)->toBe('whsec_fixture_secret');
    $this->get('/admin/webhooks')->assertInertia(fn (Assert $page) => $page
        ->where('subscriptions.0.name', 'Renamed')->where('subscriptions.0.receiving_url', 'https://new.example/events'));
});

it('allows a super admin to reassign a subscription to another client', function (): void {
    $subscription = ($this->subscription)();
    $other = ApiClient::create(['name' => 'Other', 'slug' => 'other', 'token_prefix' => 'other',
        'token_hash' => ApiClient::hashToken('other'), 'scopes' => []]);
    $this->actingAs($this->admin)->putJson('/admin/webhooks/'.$subscription->id, [...$this->body, 'api_client_id' => $other->id])
        ->assertOk()->assertJsonPath('subscription.client_name', 'Other');
});

it('enables configuration without changing its name endpoint events or secret', function (): void {
    $subscription = ($this->subscription)();
    $this->actingAs($this->admin)->patchJson('/admin/webhooks/'.$subscription->id.'/status', ['is_enabled' => true, 'name' => 'Ignored'])
        ->assertOk()->assertJsonPath('subscription.is_enabled', true)->assertJsonPath('subscription.name', $this->body['name']);
    expect($subscription->fresh()->signing_secret)->toBe('whsec_fixture_secret');
    $this->get('/admin/webhooks')->assertInertia(fn (Assert $page) => $page->where('subscriptions.0.is_enabled', true));
});

it('disables an enabled subscription', function (): void {
    $subscription = ($this->subscription)(['is_enabled' => true]);
    $this->actingAs($this->admin)->patchJson('/admin/webhooks/'.$subscription->id.'/status', ['is_enabled' => false])
        ->assertOk()->assertJsonPath('subscription.is_enabled', false);
});

it('deletes a subscription and removes it from the inventory', function (): void {
    $subscription = ($this->subscription)();
    $this->actingAs($this->admin)->deleteJson('/admin/webhooks/'.$subscription->id)->assertOk()->assertJsonPath('deleted', true);
    $this->assertDatabaseMissing('webhook_subscriptions', ['id' => $subscription->id]);
    $this->get('/admin/webhooks')->assertInertia(fn (Assert $page) => $page->has('subscriptions', 0));
});

it('returns not found for missing inventory identities', function (string $method, string $suffix): void {
    $this->actingAs($this->admin)->json($method, '/admin/webhooks/999999'.$suffix, $this->body)->assertNotFound();
})->with([['PUT', ''], ['PATCH', '/status'], ['GET', '/secret'], ['DELETE', '']]);

it('requires a nonempty subscription name', function (): void {
    $this->actingAs($this->admin)->postJson('/admin/webhooks', [...$this->body, 'name' => ' '])->assertUnprocessable()->assertJsonValidationErrors('name');
});

it('limits subscription names to the persisted column width', function (): void {
    $this->actingAs($this->admin)->postJson('/admin/webhooks', [...$this->body, 'name' => str_repeat('x', 121)])
        ->assertUnprocessable()->assertJsonValidationErrors('name');
});

it('requires an existing client identity', function (): void {
    $this->actingAs($this->admin)->postJson('/admin/webhooks', [...$this->body, 'api_client_id' => 999999])
        ->assertUnprocessable()->assertJsonValidationErrors('api_client_id');
});

it('accepts HTTPS endpoints without contacting their host', function (): void {
    $this->actingAs($this->admin)->postJson('/admin/webhooks', $this->body)->assertCreated();
});

it('rejects unencrypted and unsupported URL schemes', function (string $url): void {
    $this->actingAs($this->admin)->postJson('/admin/webhooks', [...$this->body, 'receiving_url' => $url])
        ->assertUnprocessable()->assertJsonValidationErrors('receiving_url');
})->with(['http://partner.example/events', 'ftp://partner.example/events', 'javascript:alert(1)', 'not-a-url']);

it('rejects embedded URL credentials and fragments', function (string $url): void {
    $this->actingAs($this->admin)->postJson('/admin/webhooks', [...$this->body, 'receiving_url' => $url])
        ->assertUnprocessable()->assertJsonValidationErrors('receiving_url');
})->with(['https://user:pass@partner.example/events', 'https://partner.example/events#token']);

it('rejects malformed URL values without casting an array to text', function (): void {
    $this->actingAs($this->admin)->postJson('/admin/webhooks', [...$this->body, 'receiving_url' => ['https://partner.example']])
        ->assertUnprocessable()->assertJsonValidationErrors('receiving_url');
});

it('requires at least one event', function (): void {
    $this->actingAs($this->admin)->postJson('/admin/webhooks', [...$this->body, 'events' => []])
        ->assertUnprocessable()->assertJsonValidationErrors('events');
});

it('rejects unknown event names', function (): void {
    $this->actingAs($this->admin)->postJson('/admin/webhooks', [...$this->body, 'events' => ['unapproved.event']])
        ->assertUnprocessable()->assertJsonValidationErrors('events.0');
});

it('rejects duplicate events', function (): void {
    $this->actingAs($this->admin)->postJson('/admin/webhooks', [...$this->body, 'events' => ['prediction.available', 'prediction.available']])
        ->assertUnprocessable()->assertJsonValidationErrors('events.0');
});

it('requires a boolean for the independent status endpoint', function (): void {
    $subscription = ($this->subscription)();
    $this->actingAs($this->admin)->patchJson('/admin/webhooks/'.$subscription->id.'/status', ['is_enabled' => 'invalid'])
        ->assertUnprocessable()->assertJsonValidationErrors('is_enabled');
    expect($subscription->fresh()->is_enabled)->toBeFalse();
});

it('prevents caller supplied secrets from replacing the generated signing identity', function (): void {
    $id = $this->actingAs($this->admin)->postJson('/admin/webhooks', [...$this->body, 'signing_secret' => 'caller-controlled'])
        ->assertCreated()->json('subscription.id');
    expect(WebhookSubscription::findOrFail($id)->signing_secret)->not->toBe('caller-controlled');
});

it('preserves configuration on validation failure', function (): void {
    $subscription = ($this->subscription)();
    $before = $subscription->getRawOriginal();
    $this->actingAs($this->admin)->putJson('/admin/webhooks/'.$subscription->id, [...$this->body, 'events' => []])->assertUnprocessable();
    expect($subscription->fresh()->getRawOriginal())->toBe($before);
});

it('lists revoked client status without hiding its subscription inventory', function (): void {
    $this->client->update(['revoked_at' => now()]);
    ($this->subscription)();
    $this->actingAs($this->admin)->get('/admin/webhooks')->assertInertia(fn (Assert $page) => $page
        ->where('subscriptions.0.client_revoked', true)->where('apiClients.0.revoked', true));
});

it('places Webhooks immediately after Admin Faceoffs in the super admin shell', function (): void {
    $this->actingAs($this->admin)->get('/admin/webhooks')->assertInertia(fn (Assert $page) => $page
        ->where('shell.admin', function ($links): bool {
            $labels = collect($links)->pluck('label')->all();
            return array_search('Webhooks', $labels, true) === array_search('Admin Faceoffs', $labels, true) + 1;
        }));
});

it('does not manufacture delivery history or delivery controls in its page contract', function (): void {
    $this->actingAs($this->admin)->get('/admin/webhooks')->assertInertia(fn (Assert $page) => $page
        ->missing('deliveries')->missing('deliveryUrl')->missing('retryUrl'));
});
