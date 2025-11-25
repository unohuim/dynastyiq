<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\ProviderAccount;
use App\Services\Patreon\PatreonClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
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

    public function callback(
        Request $request,
        PatreonClient $patreon
    ): RedirectResponse
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

        try {
            $code = $request->string('code')->value();
            if (!$code) {
                throw new \RuntimeException('Missing authorization code.');
            }

            $tokenResponse = $patreon->exchangeCode($code, $this->redirectUri());
            $accessToken = $tokenResponse['access_token'] ?? '';
            $identity = $patreon->getIdentity($accessToken);
            $creatorId = (string) data_get($identity, 'data.id');
            $identityCampaignId = (string) data_get($identity, 'data.relationships.campaign.data.id');
            $baseExternalId = $identityCampaignId ?: $creatorId;
            $tokenExpiresAt = now()->addSeconds((int) ($tokenResponse['expires_in'] ?? 3600));

            $baseMeta = array_filter([
                'account_id' => $creatorId ?: null,
                'tokens' => [
                    'access_token' => $accessToken,
                    'refresh_token' => $tokenResponse['refresh_token'] ?? null,
                    'expires_in' => $tokenResponse['expires_in'] ?? null,
                ],
            ], fn ($value) => $value !== null);

            $account = ProviderAccount::updateOrCreate(
                [
                    'organization_id' => $organization->id,
                    'provider' => 'patreon',
                ],
                [
                    'status' => 'connected',
                    'external_id' => $baseExternalId,
                    'display_name' => data_get($identity, 'data.attributes.full_name')
                        ?? data_get($identity, 'data.attributes.vanity')
                        ?? 'Creator page',
                    'access_token' => $accessToken,
                    'refresh_token' => $tokenResponse['refresh_token'] ?? null,
                    'token_expires_at' => $tokenExpiresAt,
                    'scopes' => !empty($tokenResponse['scope'])
                        ? explode(' ', $tokenResponse['scope'])
                        : config('patreon.scopes'),
                    'connected_at' => now(),
                    'last_sync_error' => null,
                    'webhook_secret' => $this->getWebhookSecret($organization, $existingAccount),
                    'meta' => $baseMeta,
                ]
            );

            $account->refresh();

            $campaigns = $patreon->getCampaigns($accessToken);
            $campaign = collect($campaigns['data'] ?? [])->first(function (array $item) use ($creatorId) {
                return $creatorId && data_get($item, 'relationships.creator.data.id') === (string) $creatorId;
            });
            $campaign ??= data_get($campaigns, 'data.0');

            $campaignId = $campaign ? (string) data_get($campaign, 'id') : $baseExternalId;
            $campaignAttributes = (array) data_get($campaign, 'attributes', []);
            $campaignName = $campaignAttributes['creation_name']
                ?? $campaignAttributes['name']
                ?? data_get($identity, 'data.attributes.full_name')
                ?? data_get($identity, 'data.attributes.vanity')
                ?? 'Creator page';

            $campaignMeta = array_filter([
                'id' => $campaignId,
                'name' => $campaignName,
                'avatar_photo_url' => $campaignAttributes['avatar_photo_url'] ?? null,
                'image_small_url' => $campaignAttributes['image_small_url'] ?? null,
                'image_url' => $campaignAttributes['image_url'] ?? null,
            ], fn ($value) => $value !== null);

            $campaignTiers = array_values(array_filter($campaigns['included'] ?? [], fn ($item) => data_get($item, 'type') === 'tier'));

            $membersResponse = $patreon->getCampaignMembers($accessToken, $campaignId);
            $members = array_values(array_filter($membersResponse['data'] ?? [], fn ($item) => data_get($item, 'type') === 'member'));
            $memberTiers = array_values(array_filter($membersResponse['included'] ?? [], fn ($item) => data_get($item, 'type') === 'tier'));
            $tiers = $this->mergeUniqueById($campaignTiers, $memberTiers);

            $account->forceFill([
                'status' => 'connected',
                'external_id' => $campaignId,
                'display_name' => $campaignName,
                'access_token' => $accessToken,
                'refresh_token' => $tokenResponse['refresh_token'] ?? null,
                'token_expires_at' => $tokenExpiresAt,
                'last_synced_at' => now(),
                'last_sync_error' => null,
                'meta' => array_filter([
                    'account_id' => $creatorId ?: null,
                    'campaign' => $campaignMeta,
                    'members' => $members,
                    'tiers' => $tiers,
                    'tokens' => [
                        'access_token' => $accessToken,
                        'refresh_token' => $tokenResponse['refresh_token'] ?? null,
                        'expires_in' => $tokenResponse['expires_in'] ?? null,
                    ],
                ], fn ($value) => $value !== null),
            ])->save();
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

    protected function mergeUniqueById(array $existing, array $items): array
    {
        $map = collect($existing)
            ->keyBy(fn ($item) => data_get($item, 'id'))
            ->all();

        foreach ($items as $item) {
            if (!Arr::has($item, 'id')) {
                continue;
            }

            $map[(string) data_get($item, 'id')] = $item;
        }

        return array_values($map);
    }
}
