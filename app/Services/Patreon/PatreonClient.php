<?php

declare(strict_types=1);

namespace App\Services\Patreon;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class PatreonClient
{
    public function exchangeCode(string $code, string $redirectUri): array
    {
        $tokenUrl = config('patreon.oauth.token', 'https://www.patreon.com/api/oauth2/token');

        $response = Http::asForm()
            ->acceptJson()
            ->post($tokenUrl, [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'client_id' => config('services.patreon.client_id'),
                'client_secret' => config('services.patreon.client_secret'),
                'redirect_uri' => $redirectUri,
            ])
            ->throw()
            ->json();

        $response['access_token'] = (string) ($response['access_token'] ?? '');
        $response['refresh_token'] = (string) ($response['refresh_token'] ?? '');
        $response['expires_in'] = (int) ($response['expires_in'] ?? 0);
        $response['scope'] = Str::of($response['scope'] ?? '')->trim()->value();

        return $response;
    }

    public function refreshToken(string $refreshToken): array
    {
        $tokenUrl = config('patreon.oauth.token', 'https://www.patreon.com/api/oauth2/token');

        $response = Http::asForm()
            ->acceptJson()
            ->post($tokenUrl, [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
                'client_id' => config('services.patreon.client_id'),
                'client_secret' => config('services.patreon.client_secret'),
            ])
            ->throw()
            ->json();

        $response['access_token'] = (string) ($response['access_token'] ?? '');
        $response['refresh_token'] = (string) ($response['refresh_token'] ?? '');
        $response['expires_in'] = (int) ($response['expires_in'] ?? 0);
        $response['scope'] = Str::of($response['scope'] ?? '')->trim()->value();

        return $response;
    }

    /**
     * Identity MUST be called with no includes / fields.
     */
    public function getIdentity(string $accessToken): array
    {
        $baseUrl = rtrim(config('patreon.base_url', 'https://www.patreon.com/api/oauth2/v2'), '/');

        return Http::withToken($accessToken)
            ->acceptJson()
            ->get($baseUrl . '/identity')
            ->throw()
            ->json();
    }

    /**
     * Fetch all campaigns belonging to the creator.
     * Only allowed include is "creator".
     */
    public function getCampaigns(string $accessToken): array
    {
        $baseUrl = rtrim(config('patreon.base_url', 'https://www.patreon.com/api/oauth2/v2'), '/');
    
        return Http::withToken($accessToken)
            ->acceptJson()
            ->get($baseUrl . '/campaigns', [
                'include' => 'creator',
                'fields[campaign]' => 'creation_name,patron_count,image_url,image_small_url',
                'fields[creator]' => 'full_name,image_url',
                'page[count]' => 10,
            ])
            ->throw()
            ->json();
    }




    /**
     * Fetch campaign details with tiers.
     * Allowed include = tiers
     */
    public function getCampaign(string $accessToken, string $campaignId): array
    {
        $baseUrl = rtrim(config('patreon.base_url', 'https://www.patreon.com/api/oauth2/v2'), '/');
    
        return Http::withToken($accessToken)
            ->acceptJson()
            ->get("{$baseUrl}/campaigns/{$campaignId}", [
                'include' => 'tiers,creator',
                'fields[campaign]' => 'creation_name,patron_count,image_url,image_small_url',
                'fields[tier]' => 'title,amount_cents',
                'fields[creator]' => 'full_name,image_url',
                'page[count]' => 10,
            ])
            ->throw()
            ->json();
    }



    /**
     * Fetch ALL campaign members (paginated).
     * Allowed include = currently_entitled_tiers
     * Allowed fields:
     *  - fields[member] = full_name,email,patron_status,currently_entitled_amount_cents,pledge_relationship_start,lifetime_support_cents
     *  - fields[tier] = title,amount_cents
     */
    public function getCampaignMembers(string $accessToken, string $campaignId): array
    {
        $baseUrl = rtrim(config('patreon.base_url', 'https://www.patreon.com/api/oauth2/v2'), '/');

        $url = "{$baseUrl}/campaigns/{$campaignId}/members";
        $members = [];
        $included = [];

        $params = [
            'include' => 'currently_entitled_tiers',
            'page[count]' => 50,
            'fields[member]' => 'full_name,email,patron_status,currently_entitled_amount_cents,pledge_relationship_start,lifetime_support_cents',
            'fields[tier]' => 'title,amount_cents',
        ];

        do {
            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->get($url, $params)
                ->throw()
                ->json();

            $members = array_merge($members, $response['data'] ?? []);
            $included = array_merge($included, $response['included'] ?? []);

            $url = $response['links']['next'] ?? null;

            // After first call, remove params and follow pagination URLs directly
            $params = [];
        } while ($url);

        return [
            'data' => $members,
            'included' => $included,
        ];
    }
}
