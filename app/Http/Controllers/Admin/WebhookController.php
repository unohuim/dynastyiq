<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiClient;
use App\Models\WebhookSubscription;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Super-admin management of persisted webhook subscriptions, without delivery behavior. */
class WebhookController extends Controller
{
    /** Serve explicit inventory fields without tokens or signing secrets in page props. */
    public function index(): Response
    {
        $deliveryReady = Schema::hasTable('webhook_deliveries');
        return Inertia::render('Admin/Webhooks', [
            'subscriptions' => WebhookSubscription::with('apiClient:id,name,revoked_at')->when($deliveryReady, fn ($query) => $query->with('latestDelivery'))->orderBy('name')->orderBy('id')->get()
                ->map(fn (WebhookSubscription $webhook): array => $this->payload($webhook, $deliveryReady)),
            'apiClients' => ApiClient::orderBy('name')->get(['id', 'name', 'revoked_at'])
                ->map(fn (ApiClient $client): array => ['id' => $client->id, 'name' => $client->name, 'revoked' => $client->revoked_at !== null]),
            'eventOptions' => collect(WebhookSubscription::EVENTS)->map(fn (string $label, string $value): array => compact('value', 'label'))->values(),
            'storeUrl' => route('admin.webhooks.store'), 'deliveryReady' => $deliveryReady,
        ]);
    }

    /** Persist configuration and generate an encrypted secret without contacting the endpoint. */
    public function store(Request $request): JsonResponse
    {
        $webhook = WebhookSubscription::create($this->validated($request) + ['signing_secret' => 'whsec_'.Str::random(64)]);

        return response()->json(['subscription' => $this->payload($webhook)], 201);
    }

    /** Update configuration while retaining the existing signing identity. */
    public function update(Request $request, WebhookSubscription $webhook): JsonResponse
    {
        $webhook->update($this->validated($request));

        return response()->json(['subscription' => $this->payload($webhook->fresh())]);
    }

    /** Persist one enabled switch independently from unsaved editor fields. */
    public function status(Request $request, WebhookSubscription $webhook): JsonResponse
    {
        $webhook->update($request->validate(['is_enabled' => ['required', 'boolean']]));

        return response()->json(['subscription' => $this->payload($webhook)]);
    }

    /** Reveal a secret only through an explicit privileged request, never through cached page props. */
    public function secret(WebhookSubscription $webhook): JsonResponse
    {
        return response()->json(['secret' => $webhook->signing_secret])
            ->header('Cache-Control', 'no-store, private');
    }

    /** Delete configuration without emitting a webhook or affecting prediction history. */
    public function destroy(WebhookSubscription $webhook): JsonResponse
    {
        $webhook->delete();

        return response()->json(['deleted' => true]);
    }

    /** @return array<string,mixed> Validated inventory fields; secrets are always server-owned. */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'api_client_id' => ['required', 'integer', Rule::exists('api_clients', 'id')],
            'receiving_url' => ['bail', 'required', 'string', 'max:2048', 'url:https', function (string $attribute, mixed $value, Closure $fail): void {
                $parts = parse_url((string) $value);
                if ($parts === false || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
                    $fail('Use an HTTPS receiving URL without embedded credentials or a fragment.');
                }
            }],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['required', 'string', 'distinct', Rule::in(array_keys(WebhookSubscription::EVENTS))],
            'is_enabled' => ['required', 'boolean'],
        ]);
    }

    /** @return array<string,mixed> Explicit safe inventory contract shared by mutations and page reads. */
    private function payload(WebhookSubscription $webhook, ?bool $deliveryReady = null): array
    {
        $webhook->loadMissing('apiClient:id,name,revoked_at');
        $deliveryReady ??= Schema::hasTable('webhook_deliveries');
        $delivery = $deliveryReady ? $webhook->loadMissing('latestDelivery')->latestDelivery : null;

        return [
            'id' => $webhook->id, 'name' => $webhook->name, 'api_client_id' => $webhook->api_client_id,
            'client_name' => $webhook->apiClient?->name, 'client_revoked' => $webhook->apiClient?->revoked_at !== null,
            'receiving_url' => $webhook->receiving_url, 'events' => $webhook->events, 'is_enabled' => $webhook->is_enabled,
            'latest_delivery' => $delivery === null ? null : [
                'id' => $delivery->id, 'status' => $delivery->status, 'attempts' => $delivery->attempts,
                'http_status' => $delivery->http_status, 'last_error' => $delivery->last_error,
                'prediction_revision_id' => $delivery->prediction_revision_id,
                'last_attempt_at' => $delivery->last_attempt_at?->toIso8601String(),
                'delivered_at' => $delivery->delivered_at?->toIso8601String(),
            ],
            'created_at' => $webhook->created_at?->toIso8601String(), 'updated_at' => $webhook->updated_at?->toIso8601String(),
            'update_url' => route('admin.webhooks.update', $webhook), 'status_url' => route('admin.webhooks.status', $webhook),
            'secret_url' => route('admin.webhooks.secret', $webhook), 'delete_url' => route('admin.webhooks.destroy', $webhook),
        ];
    }
}
