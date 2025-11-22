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

    public function getIdentity(string $accessToken): array
    {
        $baseUrl = rtrim(config('patreon.base_url', 'https://www.patreon.com/api/oauth2/v2'), '/');

        return Http::withToken($accessToken)
            ->acceptJson()
            ->get($baseUrl . '/identity', [
                'include' => 'campaign',
                'fields[user]' => 'full_name,vanity,email,image_url',
                'fields[campaign]' => 'name,creation_name,avatar_photo_url,image_small_url,image_url',
            ])
            ->throw()
            ->json();
    }

    public function getCampaigns(string $accessToken): array
    {
        $baseUrl = rtrim(config('patreon.base_url', 'https://www.patreon.com/api/oauth2/v2'), '/');

        return Http::withToken($accessToken)
            ->acceptJson()
            ->get($baseUrl . '/campaigns', [
                'include' => 'creator',
                'fields[campaign]' => 'name,creation_name,avatar_photo_url,image_small_url,image_url',
                'page[count]' => 1,
            ])
            ->throw()
            ->json();
    }
}
