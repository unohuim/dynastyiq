<?php

declare(strict_types=1);

namespace App\Services\Patreon;

use App\Traits\HasAPITrait;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PatreonClient
{
    use HasAPITrait;

    protected ?string $lastUrl = null;

    public function getLastPreparedUrl(): ?string
    {
        return $this->lastUrl;
    }

    /**
     * OAUTH ONLY — exchange code for tokens.
     * NO campaign calls here.
     */
    public function exchangeCode(string $code, string $redirectUri): array
    {
        $tokenUrl = config('patreon.oauth.token', 'https://www.patreon.com/api/oauth2/token');
        $this->lastUrl = $tokenUrl;

        $response = Http::asForm()
            ->acceptJson()
            ->post($tokenUrl, [
                'grant_type'     => 'authorization_code',
                'code'           => $code,
                'client_id'      => config('services.patreon.client_id'),
                'client_secret' => config('services.patreon.client_secret'),
                'redirect_uri'  => $redirectUri,
            ])
            ->throw()
            ->json();

        return $this->normalizeTokenResponse($response);
    }

    /**
     * OAUTH SAFE — identity only.
     */
    public function getIdentity(string $accessToken): array
    {
        $baseUrl = rtrim(config('patreon.base_url'), '/');
        $this->lastUrl = "{$baseUrl}/identity";

        return Http::withToken($accessToken)
            ->acceptJson()
            ->get($this->lastUrl)
            ->throw()
            ->json();
    }

    /**
     * DEFERRED SYNC
     */
    public function getCampaigns(string $accessToken): array
    {
        $baseUrl = rtrim(config('patreon.base_url'), '/');
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

    /**
     * DEFERRED SYNC
     */
    public function getCampaign(string $accessToken, string $campaignId): array
    {
        Log::info('starting getCampaign');

        $baseUrl = rtrim(config('patreon.base_url'), '/');
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

    /**
     * DEFERRED SYNC
     */
    public function getCampaignTiers(string $accessToken, string $campaignId): array
    {
        $baseUrl = rtrim(config('patreon.base_url'), '/');
        $url = "{$baseUrl}/campaigns/{$campaignId}/tiers";
        $this->lastUrl = $url;

        return Http::withToken($accessToken)
            ->acceptJson()
            ->get($url, [
                'fields[tier]' => 'title,amount_cents,description',
                'page[count]' => 50,
            ])
            ->throw()
            ->json();
    }

    /**
     * DEFERRED SYNC
     */
    public function getCampaignMembers(string $accessToken, string $campaignId): array
    {
        $baseUrl = rtrim(config('patreon.base_url'), '/');
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

    /**
     * TOKEN REFRESH — untouched
     */
    public function refreshToken(string $refreshToken): array
    {
        $tokenUrl = config('patreon.oauth.token', 'https://www.patreon.com/api/oauth2/token');
        $this->lastUrl = $tokenUrl;

        $response = Http::asForm()
            ->acceptJson()
            ->post($tokenUrl, [
                'grant_type'     => 'refresh_token',
                'refresh_token' => $refreshToken,
                'client_id'     => config('services.patreon.client_id'),
                'client_secret'=> config('services.patreon.client_secret'),
            ])
            ->throw()
            ->json();

        return $this->normalizeTokenResponse($response);
    }

    protected function normalizeTokenResponse(array $response): array
    {
        return [
            'access_token'  => (string) ($response['access_token'] ?? ''),
            'refresh_token' => (string) ($response['refresh_token'] ?? ''),
            'expires_in'    => (int) ($response['expires_in'] ?? 0),
            'scope'         => Str::of($response['scope'] ?? '')->trim()->value(),
        ];
    }
}
