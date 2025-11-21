<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ProviderAccount;
use App\Services\Patreon\PatreonSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class PatreonWebhookController extends Controller
{
    public function handle(Request $request, PatreonSyncService $service): Response
    {
        $this->guardWebhook($request);

        $payload = $request->json()->all();
        $campaignId = data_get($payload, 'data.relationships.campaign.data.id')
            ?? data_get($payload, 'campaign_id');

        $account = ProviderAccount::where('provider', 'patreon')
            ->when($campaignId, fn ($q) => $q->where('external_id', (string) $campaignId))
            ->first();

        if (!$account) {
            Log::warning('Patreon webhook received without matching provider account', [
                'campaign_id' => $campaignId,
            ]);
            return response()->noContent();
        }

        $service->handleWebhook($account, $payload);

        return response()->noContent();
    }

    protected function guardWebhook(Request $request): void
    {
        $secret = config('services.patreon.webhook_secret');
        $signature = $request->header('X-Patreon-Signature');

        if (!$secret || !$signature) {
            return;
        }

        $computed = hash_hmac('md5', $request->getContent(), $secret);
        if (!hash_equals($computed, $signature)) {
            abort(401, 'Invalid signature');
        }
    }
}
