<?php

declare(strict_types=1);

namespace App\Services\Patreon;

use App\Traits\HasAPITrait;
use Illuminate\Support\Facades\Http;
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
     * OAuth: exchange authorization code for tokens
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
     * OAuth-safe identity (used ONLY for creator ↔ user linkage)
     */
    public function getIdentity(string $accessToken): array
    {
        $this->lastUrl = $this->getApiUrl('patreon', 'identity');

        return $this->getAPIDataWithToken(
            'patreon',
            'identity',
            $accessToken
        );
    }

    /**
     * Campaign list WITH creator relationship + fields
     */
    public function getCampaigns(string $accessToken): array
    {
        $this->lastUrl = $this->getApiUrl(
            'patreon',
            'campaigns',
            [],
            [
                'include' => 'creator',
                'fields[user]' => 'full_name,image_url,vanity',
                'page[count]' => 10,
            ]
        );

        return $this->getAPIDataWithToken(
            'patreon',
            'campaigns',
            $accessToken,
            [],
            [
                'include' => 'creator',
                'fields[user]' => 'full_name,image_url,vanity',
                'page[count]' => 10,
            ]
        );
    }

    /**
     * Single campaign WITH creator relationship + fields
     */
    public function getCampaign(string $accessToken, string $campaignId): array
    {
        $this->lastUrl = $this->getApiUrl(
            'patreon',
            'campaign',
            ['campaignId' => $campaignId],
            [
                'include' => 'creator',
                'fields[campaign]' => 'created_at,creation_name,summary',
                'fields[user]' => 'full_name,image_url,vanity',
            ]
        );

        return $this->getAPIDataWithToken(
            'patreon',
            'campaign',
            $accessToken,
            ['campaignId' => $campaignId],
            [
                'include' => 'creator',
                'fields[campaign]' => 'created_at,creation_name,summary',
                'fields[user]' => 'full_name,image_url,vanity',
            ]
        );
    }

    /**
     * Campaign tiers (via campaign include)
     */
    public function getCampaignTiers(string $accessToken, string $campaignId): array
    {
        $this->lastUrl = $this->getApiUrl(
            'patreon',
            'campaign',
            ['campaignId' => $campaignId],
            [
                'include' => 'tiers',
                'fields[tier]' => 'title,amount_cents,description',
            ]
        );

        $response = $this->getAPIDataWithToken(
            'patreon',
            'campaign',
            $accessToken,
            ['campaignId' => $campaignId],
            [
                'include' => 'tiers',
                'fields[tier]' => 'title,amount_cents,description',
            ]
        );

        return [
            'data' => collect($response['included'] ?? [])
                ->where('type', 'tier')
                ->values()
                ->all(),
        ];
    }

    /**
     * Campaign members
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
     * Refresh OAuth token
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
