<?php

declare(strict_types=1);

use App\Jobs\DeliverPredictionWebhookJob;
use App\Models\ApiClient;
use App\Models\NhlGame;
use App\Models\NhlModelRun;
use App\Models\Role;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use App\Services\NhlGamePredictionHistory;
use App\Services\NhlPublicGamePage;
use App\Services\PredictionWebhookDelivery;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-26 16:00:00 UTC'));
    Bus::fake(); Http::preventStrayRequests(); $this->withoutVite();
    config(['queue.default' => 'redis', 'cache.default' => 'array', 'app.url' => 'https://dynastyiq.example']);
    $this->mock(\App\Services\PlatformState::class)->shouldReceive('seeded')->andReturn(true);
    $this->admin = User::factory()->create();
    $role = Role::firstOrCreate(['slug' => 'super-admin'], ['name' => 'Super Admin', 'level' => 99]);
    $this->admin->roles()->attach($role->id, ['organization_id' => null]);
    $this->client = ApiClient::create(['name' => 'Partner', 'slug' => 'delivery-partner',
        'token_hash' => ApiClient::hashToken('delivery-token'), 'scopes' => ['nhl-stats:read']]);
    $this->model = NhlModelRun::create(['run_key' => 'delivery-model', 'name' => 'sat_v2', 'model_family' => 'sat',
        'workflow_stage' => 'training', 'status' => 'complete', 'train_season_ids' => ['20252026']]);
    $this->game = NhlGame::create(['nhl_game_id' => 2026020428, 'season_id' => '20262027', 'game_type' => 2,
        'game_date' => '2026-11-29', 'game_dow' => 'Sunday', 'game_month' => 'November', 'game_state' => 'FUT',
        'start_time_utc' => '2026-11-29 23:00:00', 'away_team_id' => 30, 'home_team_id' => 16,
        'away_team_abbrev' => 'MIN', 'home_team_abbrev' => 'CHI']);
    $page = $this->mock(NhlPublicGamePage::class);
    $page->shouldReceive('capturePlayerAnalysis')->andReturn([]);
    $page->shouldReceive('playerPregamePayload')->andReturn([]);
    $page->shouldReceive('refreshAfterCommit')->andReturnNull();
    $this->service = Mockery::mock(PredictionWebhookDelivery::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $this->service->shouldReceive('addresses')->byDefault()->andReturn(['8.8.8.8']);
    app()->instance(PredictionWebhookDelivery::class, $this->service);
    $this->callbacks = [];
    DB::partialMock()->shouldReceive('afterCommit')->andReturnUsing(function (callable $callback): void { $this->callbacks[] = $callback; });
    $this->commit = function (): void { $callbacks = $this->callbacks; $this->callbacks = []; foreach ($callbacks as $callback) $callback(); };
    $this->subscription = function (array $extra = []): WebhookSubscription {
        return WebhookSubscription::create(['name' => 'Delivery fixture', 'api_client_id' => $this->client->id,
            'receiving_url' => 'https://partner.example/webhooks/diq', 'events' => ['prediction.available'],
            'is_enabled' => true, 'signing_secret' => 'whsec_delivery_fixture', ...$extra]);
    };
    $this->payload = ['prediction_available' => true, 'game' => ['nhl_game_id' => $this->game->nhl_game_id],
        'inputs' => ['sat_model_run_id' => $this->model->id, 'context' => ['refresh_needed' => false]],
        'prediction' => ['winner' => ['side' => 'away', 'team_abbrev' => 'MIN'], 'predicted_score' => ['away' => 3.1, 'home' => 2.6],
            'away_win_probability' => 0.6, 'home_win_probability' => 0.4, 'confidence_score' => 55], 'teams' => []];
    $this->publish = fn (?array $payload = null, array $request = []) => app(NhlGamePredictionHistory::class)->record(
        (int) $this->game->nhl_game_id, ['nhl_game_id' => $this->game->nhl_game_id, ...$request], 'webhook-fixture', [], $payload ?? $this->payload);
    $this->stage = function (array $extra = []): WebhookDelivery {
        ($this->subscription)($extra); ($this->publish)(); return WebhookDelivery::query()->latest('id')->firstOrFail();
    };
});

afterEach(function (): void { $this->travelBack(); });

it('stages one durable delivery for a newly committed successful canonical revision', function (): void {
    $delivery = ($this->stage)();
    $this->assertDatabaseHas('webhook_deliveries', ['id' => $delivery->id, 'status' => 'pending', 'attempts' => 0]);
    expect($delivery->payload['data']['prediction_revision_id'])->toBe($delivery->prediction_revision_id);
    Http::assertNothingSent();
});

it('dispatches delivery only through the post-commit callback on projections', function (): void {
    $delivery = ($this->stage)(); Bus::assertNothingDispatched();
    ($this->commit)();
    Bus::assertDispatched(DeliverPredictionWebhookJob::class, fn ($job): bool => $job->deliveryId === $delivery->id && $job->queue === 'projections' && $job->afterCommit);
});

it('deduplicates staging by subscription and revision', function (): void {
    $delivery = ($this->stage)();
    $this->service->stage($delivery->prediction_revision_id);
    $this->assertDatabaseCount('webhook_deliveries', 1);
});

it('does not restage or resend when a saved prediction is read', function (): void {
    ($this->stage)();
    $this->withToken('delivery-token')->getJson('/api/nhl-game-predictions?nhl_game_id='.$this->game->nhl_game_id)->assertOk();
    $this->assertDatabaseCount('webhook_deliveries', 1); Http::assertNothingSent();
});

it('stages distinct deliveries for a later prediction revision', function (): void {
    ($this->stage)(); ($this->publish)();
    $this->assertDatabaseCount('webhook_deliveries', 2);
    expect(WebhookDelivery::query()->pluck('event_id')->unique()->count())->toBe(2);
});

it('does not stage unavailable predictions', function (): void {
    ($this->subscription)(); ($this->publish)([...$this->payload, 'prediction_available' => false]);
    $this->assertDatabaseCount('webhook_deliveries', 0);
});

it('does not stage noncanonical forecast overrides', function (): void {
    ($this->subscription)(); ($this->publish)(null, ['home_goalie_id' => 123]);
    $this->assertDatabaseCount('webhook_deliveries', 0);
});

it('does not stage disabled subscribers', function (): void {
    ($this->subscription)(['is_enabled' => false]); ($this->publish)();
    $this->assertDatabaseCount('webhook_deliveries', 0);
});

it('does not stage revoked API clients', function (): void {
    $this->client->update(['revoked_at' => now()]); ($this->subscription)(); ($this->publish)();
    $this->assertDatabaseCount('webhook_deliveries', 0);
});

it('does not stage clients without NHL statistics scope', function (): void {
    $this->client->update(['scopes' => []]); ($this->subscription)(); ($this->publish)();
    $this->assertDatabaseCount('webhook_deliveries', 0);
});

it('sends signed JSON using the exact serialized body and stable delivery identity', function (): void {
    $delivery = ($this->stage)(); Http::fake(['*' => Http::response([], 204)]);
    $this->service->deliver($delivery->id);
    Http::assertSent(fn ($request): bool => $request->hasHeader('X-DIQ-Delivery-Id', $delivery->event_id)
        && $request->hasHeader('X-DIQ-Signature', 'sha256='.hash_hmac('sha256', now()->timestamp.'.'.$request->body(), 'whsec_delivery_fixture'))
        && $request['event'] === 'prediction.available' && ! isset($request['data']['confidence_inputs']));
    expect($delivery->fresh()->status)->toBe('delivered');
});

it('encrypts the destination and hides request bodies from model serialization', function (): void {
    $delivery = ($this->stage)();
    expect(DB::table('webhook_deliveries')->where('id', $delivery->id)->value('destination'))->not->toBe($delivery->destination);
    expect($delivery->toArray())->not->toHaveKeys(['destination', 'payload', 'claim_token']);
});

it('sends a Discord-native embed and asks for confirmed message creation', function (): void {
    $delivery = ($this->stage)(['receiving_url' => 'https://discord.com/api/webhooks/123/test_token?wait=false']);
    Http::fake(['*' => Http::response(['id' => '123456789'], 200)]);
    $this->service->deliver($delivery->id);
    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'wait=true')
        && $request['embeds'][0]['title'] === 'MIN @ CHI · 2026-11-29'
        && $request['allowed_mentions']['parse'] === []);
    expect($delivery->fresh()->remote_message_id)->toBe('123456789')->and($delivery->fresh()->status)->toBe('delivered');
});

it('does not treat an ordinary Discord URL as a native webhook', function (): void {
    $delivery = ($this->stage)(['receiving_url' => 'https://discord.com/other-endpoint']);
    expect($delivery->transport)->toBe('json');
});

it('requires a Discord message ID before reporting successful delivery', function (): void {
    $delivery = ($this->stage)(['receiving_url' => 'https://discord.com/api/webhooks/123/test_token']);
    Http::fake(['*' => Http::response([], 200)]); $this->service->deliver($delivery->id);
    expect($delivery->fresh()->status)->toBe('retrying')->and($delivery->fresh()->delivered_at)->toBeNull();
});

it('does not send the same delivery again after success', function (): void {
    $delivery = ($this->stage)(); Http::fake(['*' => Http::response([], 200)]);
    $this->service->deliver($delivery->id); $this->service->deliver($delivery->id);
    Http::assertSentCount(1);
});

it('retries a temporary server error without losing the saved prediction', function (): void {
    $delivery = ($this->stage)(); Http::fake(['*' => Http::response([], 503)]);
    $this->service->deliver($delivery->id);
    expect($delivery->fresh()->status)->toBe('retrying')->and($delivery->fresh()->attempts)->toBe(1);
    $this->assertDatabaseHas('nhl_game_prediction_revisions', ['id' => $delivery->prediction_revision_id, 'prediction_available' => true]);
});

it('honors Discord retry-after timing for rate limiting', function (): void {
    $delivery = ($this->stage)(); Http::fake(['*' => Http::response(['retry_after' => 72.5], 429)]);
    $this->service->deliver($delivery->id);
    expect($delivery->fresh()->next_attempt_at->timestamp)->toBe(now()->addSeconds(73)->timestamp);
});

it('records permanent rejection without scheduling another attempt', function (): void {
    $delivery = ($this->stage)(); Http::fake(['*' => Http::response([], 400)]);
    $this->service->deliver($delivery->id);
    expect($delivery->fresh()->status)->toBe('failed')->and($delivery->fresh()->http_status)->toBe(400)
        ->and($delivery->fresh()->next_attempt_at)->toBeNull();
});

it('stops retrying once the bounded delivery attempts are exhausted', function (): void {
    $delivery = ($this->stage)(); $delivery->update(['attempts' => 4]); Http::fake(['*' => Http::response([], 503)]);
    $this->service->deliver($delivery->id);
    expect($delivery->fresh()->status)->toBe('failed')->and($delivery->fresh()->attempts)->toBe(5);
});

it('does not send a retry before its scheduled time', function (): void {
    $delivery = ($this->stage)(); $delivery->update(['status' => 'retrying', 'next_attempt_at' => now()->addMinute()]);
    $this->service->deliver($delivery->id); Http::assertNothingSent();
});

it('cancels queued work if the subscription is disabled before delivery', function (): void {
    $delivery = ($this->stage)(); WebhookSubscription::query()->whereKey($delivery->webhook_subscription_id)->update(['is_enabled' => false]);
    $this->service->deliver($delivery->id); expect($delivery->fresh()->status)->toBe('cancelled'); Http::assertNothingSent();
});

it('cancels queued work if the receiving URL changes', function (): void {
    $delivery = ($this->stage)(); WebhookSubscription::query()->whereKey($delivery->webhook_subscription_id)->update(['receiving_url' => 'https://different.example/webhook']);
    $this->service->deliver($delivery->id); expect($delivery->fresh()->status)->toBe('cancelled'); Http::assertNothingSent();
});

it('cancels queued work if the API client is revoked before delivery', function (): void {
    $delivery = ($this->stage)(); $this->client->update(['revoked_at' => now()]);
    $this->service->deliver($delivery->id); expect($delivery->fresh()->status)->toBe('cancelled'); Http::assertNothingSent();
});

it('rejects private and local resolved addresses before sending', function (string $address): void {
    $delivery = ($this->stage)(); $this->service->shouldReceive('addresses')->andReturn([$address]);
    $this->service->deliver($delivery->id);
    expect($delivery->fresh()->status)->toBe('failed')->and($delivery->fresh()->last_error)->toBe('Destination is not a public HTTPS endpoint.');
    Http::assertNothingSent();
})->with(['127.0.0.1', '10.1.2.3', '169.254.169.254', '100.64.0.1', '::1', '::ffff:127.0.0.1']);

it('rejects mixed public and private DNS answers', function (): void {
    $delivery = ($this->stage)(); $this->service->shouldReceive('addresses')->andReturn(['8.8.8.8', '10.0.0.1']);
    $this->service->deliver($delivery->id); Http::assertNothingSent(); expect($delivery->fresh()->status)->toBe('failed');
});

it('does not follow a redirect from the configured destination', function (): void {
    $delivery = ($this->stage)(); Http::fake(['*' => Http::response([], 302, ['Location' => 'http://127.0.0.1/private'])]);
    $this->service->deliver($delivery->id);
    Http::assertSentCount(1); expect($delivery->fresh()->status)->toBe('failed')->and($delivery->fresh()->http_status)->toBe(302);
});

it('recovers abandoned delivery claims with the durable scheduled dispatcher', function (): void {
    $delivery = ($this->stage)(); $this->callbacks = [];
    $delivery->update(['status' => 'delivering', 'claim_token' => '00000000-0000-4000-8000-000000000001', 'claim_expires_at' => now()->subMinute()]);
    $this->service->dispatchDue();
    expect($delivery->fresh()->status)->toBe('retrying')->and($delivery->fresh()->claim_token)->toBeNull();
    Bus::assertDispatched(DeliverPredictionWebhookJob::class, fn ($job): bool => $job->deliveryId === $delivery->id);
});

it('leaves an active claim alone when another job receives the same delivery', function (): void {
    $delivery = ($this->stage)(); $delivery->update(['status' => 'delivering', 'claim_expires_at' => now()->addMinute()]);
    $this->service->deliver($delivery->id); Http::assertNothingSent();
});

it('shows actual delivery status through the existing admin inventory without request secrets', function (): void {
    $body = ['name' => 'UI delivery', 'api_client_id' => $this->client->id, 'receiving_url' => 'https://partner.example/diq',
        'events' => ['prediction.available'], 'is_enabled' => true];
    $this->actingAs($this->admin)->postJson('/admin/webhooks', $body)->assertCreated();
    ($this->publish)(); $delivery = WebhookDelivery::query()->firstOrFail();
    Http::fake(['*' => Http::response([], 200)]); $this->service->deliver($delivery->id);
    $this->assertDatabaseHas('webhook_deliveries', ['id' => $delivery->id, 'status' => 'delivered']);
    $this->get('/admin/webhooks')->assertInertia(fn (Assert $page) => $page
        ->where('subscriptions.0.latest_delivery.status', 'delivered')
        ->where('subscriptions.0.latest_delivery.attempts', 1)
        ->missing('subscriptions.0.latest_delivery.payload')->missing('subscriptions.0.latest_delivery.destination')
        ->missing('subscriptions.0.signing_secret'));
});
