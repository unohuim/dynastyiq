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

    public function getIdentity(string $accessToken): array
    {
        $baseUrl = rtrim(config('patreon.base_url', 'https://www.patreon.com/api/oauth2/v2'), '/');

        return Http::withToken($accessToken)
            ->acceptJson()
            ->get($baseUrl . '/identity')
            ->throw()
            ->json();
    }

    public function getCampaigns(string $accessToken): array
    {
        $baseUrl = rtrim(config('patreon.base_url', 'https://www.patreon.com/api/oauth2/v2'), '/');

        return Http::withToken($accessToken)
            ->acceptJson()
            ->get($baseUrl . '/campaigns', [
                'include' => 'tiers',
            ])
            ->throw()
            ->json();
    }

    public function getCampaign(string $accessToken, string $campaignId): array
    {
        $baseUrl = rtrim(config('patreon.base_url', 'https://www.patreon.com/api/oauth2/v2'), '/');

        return Http::withToken($accessToken)
            ->acceptJson()
            ->get("{$baseUrl}/campaigns/{$campaignId}", [
                'include' => 'tiers',
                'fields[campaign]' => 'name,creation_name,avatar_photo_url,image_small_url,image_url',
            ])
            ->throw()
            ->json();
    }

    public function getCampaignMembers(string $accessToken, string $campaignId): array
    {
        $baseUrl = rtrim(config('patreon.base_url', 'https://www.patreon.com/api/oauth2/v2'), '/');

        $url = $baseUrl . "/campaigns/{$campaignId}/members";
        $members = [];
        $included = [];
        $params = [
            'include' => 'currently_entitled_tiers',
            'fields[member]' => 'full_name,email,patron_status,currently_entitled_amount_cents,pledge_relationship_start,currently_entitled_tiers',
            'fields[tier]' => 'title,amount_cents',
        ];

        do {
            $response = (array) Http::withToken($accessToken)
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

    public function getCreatorCampaigns(string $accessToken, array $query = []): array
    {
        $baseUrl = rtrim(config('patreon.base_url', 'https://www.patreon.com/api/oauth2/v2'), '/');

        $url = $baseUrl . '/campaigns';
        $campaigns = [];
        $included = [];
        $params = $query;

        do {
            $request = Http::withToken($accessToken)->acceptJson();

            if ($params) {
                $response = (array) $request->get($url, $params)->throw()->json();
            } else {
                $response = (array) $request->get($url)->throw()->json();
            }

            $campaigns = array_merge($campaigns, $response['data'] ?? []);
            $included = array_merge($included, $response['included'] ?? []);

            $url = $response['links']['next'] ?? null;
            $params = [];
        } while ($url);

        return [
            'data' => $campaigns,
            'included' => $included,
        ];
    }
}
