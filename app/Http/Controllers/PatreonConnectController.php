<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\ProviderAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class PatreonConnectController extends Controller
{
    public function redirect(Organization $organization): RedirectResponse
    {
        $this->assertUserCanManage($organization);

        $state = encrypt([
            'organization_id' => $organization->id,
            'user_id' => Auth::id(),
            'ts' => now()->timestamp,
        ]);

        $authorizeUrl = config('patreon.oauth.authorize', 'https://www.patreon.com/oauth2/authorize');
        $clientId = config('services.patreon.client_id');
        $redirectUri = config('services.patreon.redirect');
        $scopes = implode(' ', config('patreon.scopes', ['identity', 'campaigns', 'memberships']));

        $query = http_build_query([
            'response_type' => 'code',
            'client_id'     => $clientId,
            'redirect_uri'  => $redirectUri,
            'scope'         => $scopes,
            'state'         => $state,
        ]);

        return redirect()->away($authorizeUrl . '?' . $query);
    }

    public function callback(Request $request): RedirectResponse
    {
        $state = decrypt($request->string('state')->value());
        $organization = Organization::findOrFail($state['organization_id'] ?? 0);
        $this->assertUserCanManage($organization);

        $existingAccount = ProviderAccount::where('organization_id', $organization->id)
            ->where('provider', 'patreon')
            ->first();

        $code = $request->string('code')->value();
        if (!$code) {
            return redirect()->route('communities.index')->withErrors([
                'patreon' => 'Missing authorization code.',
            ]);
        }

        try {
            $tokenResponse = Http::asForm()->post(
                config('patreon.oauth.token', 'https://www.patreon.com/api/oauth2/token'),
                [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'client_id' => config('services.patreon.client_id'),
                    'client_secret' => config('services.patreon.client_secret'),
                    'redirect_uri' => config('services.patreon.redirect'),
                ]
            )->throw()->json();

            $identity = Http::withToken($tokenResponse['access_token'] ?? '')
                ->acceptJson()
                ->get(config('patreon.base_url') . '/identity', ['include' => 'campaign'])
                ->throw()
                ->json();
        } catch (Throwable $e) {
            return redirect()->route('communities.index')->withErrors([
                'patreon' => 'Unable to connect to Patreon: ' . $e->getMessage(),
            ])->with('error', 'Unable to connect to Patreon.');
        }

        $account = ProviderAccount::updateOrCreate(
            [
                'organization_id' => $organization->id,
                'provider' => 'patreon',
            ],
            [
                'status'         => 'connected',
                'external_id'    => data_get($identity ?? [], 'data.relationships.campaign.data.id'),
                'display_name'   => data_get($identity ?? [], 'data.attributes.full_name'),
                'access_token'   => $tokenResponse['access_token'] ?? null,
                'refresh_token'  => $tokenResponse['refresh_token'] ?? null,
                'token_expires_at' => now()->addSeconds((int) ($tokenResponse['expires_in'] ?? 3600)),
                'scopes'         => isset($tokenResponse['scope'])
                    ? explode(' ', $tokenResponse['scope'])
                    : config('patreon.scopes'),
                'connected_at'   => now(),
                'last_sync_error'=> null,
                'webhook_secret' => $this->getWebhookSecret($organization, $existingAccount),
            ]
        );

        return redirect()->route('communities.index')->with('success', 'Patreon connected');
    }

    public function disconnect(Organization $organization): RedirectResponse|JsonResponse
    {
        $this->assertUserCanManage($organization);

        $account = ProviderAccount::where('organization_id', $organization->id)
            ->where('provider', 'patreon')
            ->first();

        if ($account) {
            $account->delete();
        }

        if (request()->wantsJson()) {
            return response()->json(['ok' => true]);
        }

        return redirect()->route('communities.index')->with('success', 'Patreon disconnected');
    }

    protected function assertUserCanManage(Organization $organization): void
    {
        $user = Auth::user();
        if (!$user) {
            abort(403);
        }

        $hasOrg = $user->organizations()->where('organizations.id', $organization->id)->exists();
        if (!$hasOrg) {
            abort(403);
        }
    }

    protected function getWebhookSecret(Organization $organization, ?ProviderAccount $existingAccount = null): string
    {
        return config('services.patreon.webhook_secret')
            ?? $existingAccount?->webhook_secret
            ?? Str::random(32);
    }
}
