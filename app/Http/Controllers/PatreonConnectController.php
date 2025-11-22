<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\ProviderAccount;
use App\Services\Patreon\PatreonClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
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
        $redirectUri = $this->redirectUri();
        $scopes = implode(' ', config('patreon.scopes', [
            'identity',
            'identity[email]',
            'campaigns',
            'campaigns.members',
        ]));

        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'scope' => $scopes,
            'state' => $state,
        ]);

        return redirect()->away($authorizeUrl . '?' . $query);
    }

    public function callback(Request $request, PatreonClient $patreon): RedirectResponse
    {
        try {
            $state = decrypt($request->string('state')->value());
        } catch (Throwable) {
            return redirect()->route('communities.index')->withErrors([
                'patreon' => 'Invalid authorization response.',
            ])->with('error', 'Unable to connect to Patreon.');
        }

        if (($state['user_id'] ?? null) !== Auth::id() || empty($state['organization_id'])) {
            return redirect()->route('communities.index')->withErrors([
                'patreon' => 'Invalid authorization response.',
            ])->with('error', 'Unable to connect to Patreon.');
        }

        $organization = Organization::find($state['organization_id']);
        if (!$organization) {
            return redirect()->route('communities.index')->withErrors([
                'patreon' => 'Organization not found.',
            ])->with('error', 'Unable to connect to Patreon.');
        }

        $this->assertUserCanManage($organization);

        $existingAccount = ProviderAccount::where('organization_id', $organization->id)
            ->where('provider', 'patreon')
            ->first();

        $code = $request->string('code')->value();
        if (!$code) {
            return $this->errorRedirect('Missing authorization code.');
        }

        try {
            $tokenResponse = $patreon->exchangeCode($code, $this->redirectUri());

            $identity = $patreon->getIdentity($tokenResponse['access_token']);

            $campaignId = data_get($identity, 'data.relationships.campaign.data.id');
            $campaign = collect($identity['included'] ?? [])->firstWhere('type', 'campaign');

            if (!$campaign && !$campaignId) {
                $campaignResponse = $patreon->getCampaigns($tokenResponse['access_token']);
                $campaign = $campaignResponse['data'][0] ?? null;
            }

            if (!$campaignId && $campaign) {
                $campaignId = data_get($campaign, 'id');
            }

            $userMeta = array_filter([
                'id' => data_get($identity, 'data.id'),
                'full_name' => data_get($identity, 'data.attributes.full_name'),
                'email' => data_get($identity, 'data.attributes.email'),
                'vanity' => data_get($identity, 'data.attributes.vanity'),
                'image_url' => data_get($identity, 'data.attributes.image_url'),
            ]);

            $campaignMeta = array_filter([
                'id' => $campaignId,
                'name' => data_get($campaign, 'attributes.creation_name')
                    ?? data_get($campaign, 'attributes.name'),
                'image_url' => data_get($campaign, 'attributes.avatar_photo_url')
                    ?? data_get($campaign, 'attributes.image_small_url')
                    ?? data_get($campaign, 'attributes.image_url'),
            ]);

            $displayName = $campaignMeta['name']
                ?? $userMeta['full_name']
                ?? $userMeta['vanity']
                ?? 'Creator page';

            ProviderAccount::updateOrCreate(
                [
                    'organization_id' => $organization->id,
                    'provider' => 'patreon',
                ],
                [
                    'status' => 'connected',
                    'external_id' => $campaignId,
                    'display_name' => $displayName,
                    'access_token' => $tokenResponse['access_token'] ?? null,
                    'refresh_token' => $tokenResponse['refresh_token'] ?? null,
                    'token_expires_at' => now()->addSeconds((int) ($tokenResponse['expires_in'] ?? 3600)),
                    'scopes' => !empty($tokenResponse['scope'])
                        ? explode(' ', $tokenResponse['scope'])
                        : config('patreon.scopes'),
                    'connected_at' => now(),
                    'last_sync_error' => null,
                    'webhook_secret' => $this->getWebhookSecret($organization, $existingAccount),
                    'meta' => array_filter([
                        'account_id' => $userMeta['id'] ?? null,
                        'user' => $userMeta,
                        'campaign' => $campaignMeta,
                        'tokens' => [
                            'access_token' => $tokenResponse['access_token'] ?? null,
                            'refresh_token' => $tokenResponse['refresh_token'] ?? null,
                        ],
                    ]),
                ]
            );
        } catch (Throwable $e) {
            Log::warning('Patreon callback failed', [
                'organization_id' => $organization->id,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return $this->errorRedirect('Unable to connect to Patreon: ' . $e->getMessage());
        }

        return redirect()
            ->route('communities.index')
            ->with('success', 'Patreon connected')
            ->with('active_organization_id', $organization->id);
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

    protected function userCanManage(Organization $organization): bool
    {
        $user = Auth::user();

        if (!$user) {
            return false;
        }

        return $user->organizations()
            ->where('organizations.id', $organization->id)
            ->exists();
    }

    protected function errorRedirect(string $message): RedirectResponse
    {
        return redirect()->route('communities.index')
            ->withErrors(['patreon' => $message])
            ->with('error', 'Unable to connect to Patreon.');
    }

    protected function getWebhookSecret(Organization $organization, ?ProviderAccount $existingAccount = null): string
    {
        return config('services.patreon.webhook_secret')
            ?? $existingAccount?->webhook_secret
            ?? Str::random(32);
    }

    protected function redirectUri(): string
    {
        return config('services.patreon.redirect')
            ?? route('patreon.callback');
    }
}
