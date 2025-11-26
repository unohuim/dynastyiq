<?php

declare(strict_types=1);

namespace App\Services\Patreon;

use App\Traits\HasAPITrait;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PatreonClient
{
    use HasAPITrait;

    protected ?string $lastUrl = null;

    public function getLastPreparedUrl(): ?string
    {
        return $this->lastUrl;
    }

    public function exchangeCode(string $code, string $redirectUri): array
    {
        $url = $this->getApiUrl('patreon', 'token');

        $this->lastUrl = $url;

        $response = Http::asForm()
            ->acceptJson()
            ->post($url, [
                'grant_type'    => 'authorization_code',
                'code'          => $code,
                'client_id'     => config('apiurls.patreon.client_id'),
                'client_secret'=> config('apiurls.patreon.client_secret'),
                'redirect_uri' => $redirectUri,
            ])
            ->throw()
            ->json();

        return $this->normalizeTokenResponse($response);
    }

    public function refreshToken(string $refreshToken): array
    {
        $url = $this->getApiUrl('patreon', 'token');

        $this->lastUrl = $url;

        $response = Http::asForm()
            ->acceptJson()
            ->post($url, [
                'grant_type'     => 'refresh_token',
                'refresh_token' => $refreshToken,
                'client_id'     => config('apiurls.patreon.client_id'),
                'client_secret'=> config('apiurls.patreon.client_secret'),
            ])
            ->throw()
            ->json();

        return $this->normalizeTokenResponse($response);
    }

    public function getIdentity(string $accessToken): array
    {
        $this->lastUrl = $this->getApiUrl('patreon', 'identity');

        return Http::withToken($accessToken)
            ->acceptJson()
            ->get($this->lastUrl)
            ->throw()
            ->json();
    }

    public function getCampaigns(string $accessToken): array
    {
        $this->lastUrl = $this->getApiUrl('patreon', 'campaigns', [], [
            'include' => 'creator',
            'page[count]' => 10,
        ]);

        return Http::withToken($accessToken)
            ->acceptJson()
            ->get($this->lastUrl)
            ->throw()
            ->json();
    }

    public function getCampaign(string $accessToken, string $campaignId): array
    {
        Log::info('starting getCampaign');

        $this->lastUrl = $this->getApiUrl(
            'patreon',
            'campaign',
            ['campaignId' => $campaignId],
            ['fields[campaign]' => 'created_at,creation_name,summary']
        );

        return Http::withToken($accessToken)
            ->acceptJson()
            ->get($this->lastUrl)
            ->throw()
            ->json();
    }

    public function getCampaignTiers(string $accessToken, string $campaignId): array
    {
        $this->lastUrl = $this->getApiUrl(
            'patreon',
            'campaign_tiers',
            ['campaignId' => $campaignId],
            [
                'fields[tier]' => 'title,amount_cents,description',
                'page[count]'  => 50,
            ]
        );

        return Http::withToken($accessToken)
            ->acceptJson()
            ->get($this->lastUrl)
            ->throw()
            ->json();
    }

    public function getCampaignMembers(string $accessToken, string $campaignId): array
    {
        $url = $this->getApiUrl(
            'patreon',
            'campaign_members',
            ['campaignId' => $campaignId],
            [
                'include' => 'currently_entitled_tiers',
                'page[count]' => 50,
                'fields[member]' =>
                    'full_name,email,patron_status,currently_entitled_amount_cents,' .
                    'pledge_relationship_start,lifetime_support_cents',
                'fields[tier]' => 'title,amount_cents',
            ]
        );

        $this->lastUrl = $url;

        $members = [];
        $included = [];

        do {
            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->get($url)
                ->throw()
                ->json();

            $members  = array_merge($members, $response['data'] ?? []);
            $included = array_merge($included, $response['included'] ?? []);

            $url = $response['links']['next'] ?? null;
        } while ($url);

        return [
            'data'     => $members,
            'included' => $included,
        ];
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
