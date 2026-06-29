<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\YahooFantasyConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use SimpleXMLElement;
use Throwable;

/**
 * Small authenticated client boundary for Yahoo Fantasy Sports API calls.
 */
class YahooFantasyClient
{
    /**
     * Exchange an OAuth authorization code for Yahoo tokens.
     *
     * @return array<string,mixed>
     */
    public function exchangeCode(string $code, string $redirectUri): array
    {
        return $this->tokenRequest()
            ->asForm()
            ->post((string) config('yahoo.oauth.token'), [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $redirectUri,
            ])
            ->throw()
            ->json();
    }

    /**
     * Fetch XML from the configured Yahoo Fantasy API path.
     */
    public function fantasyXml(string $accessToken, string $path): SimpleXMLElement
    {
        $url = rtrim((string) config('yahoo.base_url'), '/').'/'.ltrim($path, '/');
        $body = Http::withToken($accessToken)
            ->accept('application/xml')
            ->get($url)
            ->throw()
            ->body();

        $xml = simplexml_load_string($body);
        if (! $xml instanceof SimpleXMLElement) {
            throw new RuntimeException('Yahoo Fantasy API returned invalid XML.');
        }

        return $xml;
    }

    /**
     * Fetch XML using a persisted Yahoo connection, refreshing when needed.
     */
    public function fantasyXmlForConnection(YahooFantasyConnection $connection, string $path): SimpleXMLElement
    {
        try {
            return $this->fantasyXml($this->accessTokenFor($connection), $path);
        } catch (RequestException $exception) {
            if ($exception->response->status() !== 401 || ! $connection->refresh_token) {
                throw $exception;
            }

            $connection = $this->refreshConnection($connection);

            return $this->fantasyXml((string) $connection->access_token, $path);
        }
    }

    /**
     * Return a usable access token, refreshing expired tokens first.
     */
    public function accessTokenFor(YahooFantasyConnection $connection): string
    {
        if ($connection->token_expires_at && $connection->token_expires_at->lte(now()->addMinutes(5))) {
            if (! $connection->refresh_token) {
                throw new RuntimeException('Yahoo connection token is expired and cannot be refreshed.');
            }

            $connection = $this->refreshConnection($connection);
        }

        $accessToken = (string) $connection->access_token;
        if ($accessToken === '') {
            throw new RuntimeException('Yahoo connection is missing an access token.');
        }

        $connection->forceFill([
            'last_used_at' => now(),
            'last_error' => null,
        ])->save();

        return $accessToken;
    }

    /**
     * Refresh and persist a Yahoo access token.
     */
    public function refreshConnection(YahooFantasyConnection $connection): YahooFantasyConnection
    {
        $refreshToken = (string) $connection->refresh_token;
        if ($refreshToken === '') {
            throw new RuntimeException('Yahoo connection is missing a refresh token.');
        }

        try {
            $tokens = $this->tokenRequest()
                ->asForm()
                ->post((string) config('yahoo.oauth.token'), [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refreshToken,
                ])
                ->throw()
                ->json();
        } catch (Throwable $throwable) {
            $connection->forceFill([
                'status' => 'offline',
                'last_error' => $throwable->getMessage(),
            ])->save();

            throw $throwable;
        }

        $connection->forceFill([
            'access_token' => (string) ($tokens['access_token'] ?? $connection->access_token),
            'refresh_token' => (string) ($tokens['refresh_token'] ?? $connection->refresh_token),
            'token_expires_at' => now()->addSeconds((int) ($tokens['expires_in'] ?? 3600)),
            'scopes' => $this->scopesFromTokenResponse($tokens, $connection->scopes ?? []),
            'status' => 'connected',
            'last_error' => null,
        ])->save();

        return $connection->refresh();
    }

    /**
     * Normalize OAuth scope response values.
     *
     * @param array<string,mixed> $tokens
     * @param array<int,string> $fallback
     * @return array<int,string>
     */
    public function scopesFromTokenResponse(array $tokens, array $fallback = []): array
    {
        $scope = trim((string) ($tokens['scope'] ?? ''));

        if ($scope === '') {
            return $fallback;
        }

        return collect(explode(' ', $scope))
            ->map(static fn (string $value): string => trim($value))
            ->filter(static fn (string $value): bool => $value !== '')
            ->values()
            ->all();
    }

    /**
     * Build a Yahoo token request configured with client credentials.
     */
    private function tokenRequest(): PendingRequest
    {
        return Http::withBasicAuth(
            (string) config('services.yahoo.client_id'),
            (string) config('services.yahoo.client_secret'),
        )->acceptJson();
    }
}
