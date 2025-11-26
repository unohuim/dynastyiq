<?php

declare(strict_types=1);

namespace App\Services\Patreon;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PatreonClient
{
    protected ?string $lastUrl = null;

    public function getLastPreparedUrl(): ?string
    {
        return $this->lastUrl;
    }

    /**
     * OAuth step 1: exchange authorization code for tokens.
     * MUST succeed and be persisted before any campaign calls.
     */
    public function exchangeCode(string $code, string $redirectUri): array
    {
        $tokenUrl = config('patreon.oauth.token', 'https://www.patreon.com/api/oauth2/token');
        $this->lastUrl = $tokenUrl;

        $response = Http::asForm()
            ->acceptJson()
            ->post($tokenUrl, [
                'grant_type'    => 'authorization_code',
                'code'          => $code,
                'client_id'     => config('services.patreon.client_id'),
                'client_secret'=> config('services.patreon.client_secret'),
                'redirect_uri' => $redirectUri,
            ])
            ->throw()
            ->json();

        return [
            'access_token'  => (string) ($response['access_token'] ?? ''),
            'refresh_token' => (string) ($response['refresh_token'] ?? ''),
            'expires_in'    => (int) ($response['expires_in'] ?? 0),
            'scope'         => Str::of($response['scope'] ?? '')->trim()->value(),
        ];
    }

    /**
     * OAuth step 2: fetch creator identity.
     * Safe to call during initial connection.
     */
    public function getIdentity(string $accessToken): array
    {
        $baseUrl = rtrim(config('patreon.base_url', 'https://www.patreon.com/api/oauth2/v2'), '/');
        $this->lastUrl = "{$baseUrl}/identity";

        return Http::withToken($accessToken)
            ->acceptJson()
            ->get($this->lastUrl)
            ->throw()
            ->json();
    }

    /*
     |--------------------------------------------------------------------------
     | Deferred sync methods
     |--------------------------------------------------------------------------
     | These MUST NOT be called during initial OAuth.
     */

    public function getCampaigns(string $accessToken): array
    {
        $baseUrl = rtrim(config('patreon.base_url', 'https://www.patreon.com/api/oauth2/v2'), '/');
        $this->lastUrl = "{$baseUrl}/campaigns";

        return Http::withToken($accessToken)
            ->acceptJson()
            ->get($this->lastUrl, [
                'include' => 'creator',
                'page[count]' => 10,
            ])
            ->throw()
            ->json();
    }

    public function getCampaign(string $accessToken, string $campaignId): array
    {
        Log::info('starting getCampaign');

        $baseUrl = rtrim(config('patreon.base_url', 'https://www.patreon.com/api/oauth2/v2'), '/');
        $url = "{$baseUrl}/campaigns/{$campaignId}";
        $this->lastUrl = $url;

        return Http::withToken($accessToken)
            ->acceptJson()
            ->get($url, [
                'fields[campaign]' => 'created_at,creation_name,summary',
            ])
            ->throw()
            ->json();
    }

    public function getCampaignMembers(string $accessToken, string $campaignId): array
    {
        $baseUrl = rtrim(config('patreon.base_url', 'https://www.patreon.com/api/oauth2/v2'), '/');

        $url = "{$baseUrl}/campaigns/{$campaignId}/members";
        $this->lastUrl = $url;

        $members = [];
        $included = [];
        $params = [
            'include' => 'currently_entitled_tiers',
            'page[count]' => 50,
            'fields[member]' =>
                'full_name,email,patron_status,currently_entitled_amount_cents,' .
                'pledge_relationship_start,lifetime_support_cents',
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
            $params = [];
        } while ($url);

        return [
            'data' => $members,
            'included' => $included,
        ];
    }
}
