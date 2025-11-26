<?php

declare(strict_types=1);

namespace App\Traits;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Http\Client\RequestException;

/**
 * Trait HasAPITrait
 *
 * Provides helper methods for fetching data from external APIs based
 * on service definitions in config/apiurls.php, handling authentication
 * placement (header or query) automatically.
 *
 * @package App\Traits
 */
trait HasAPITrait
{

    /**
 * Perform a GET request against a configured API endpoint
 * using a runtime OAuth Bearer token.
 */
public function getAPIDataWithToken(
    string $service,
    string $endpointKey,
    string $accessToken,
    array $replacements = [],
    array $query = []
    ): array {
        $url = $this->getApiUrl(
            $service,
            $endpointKey,
            $replacements,
            $query
        );
    
        return Http::withToken($accessToken)
            ->acceptJson()
            ->get($url)
            ->throw()
            ->json();
    }
    

    /**
     * Fetch data from a full URL (for shifts API).
     *
     * @param string $url
     * @return array
     */
    public function getAPIDataFullUrl(string $url): array
    {
        $client = new \GuzzleHttp\Client();

        $response = $client->get($url);

        return json_decode($response->getBody()->getContents(), true);
    }


    
    /**
     * Send a GET request to a configured API service.
     *
     * @param string $service      The service key as defined in config/apiurls.php
     * @param string $endpointKey  The endpoint key under that service
     * @param array  $replacements Placeholder replacements for the endpoint path
     * @param array  $query        Additional query parameters
     * @param bool   $decodeJson   If true, decode JSON to array; return raw body otherwise
     * @return array|string        Response data
     * @throws RequestException    When HTTP client or server error occurs
     */
    public function getAPIData(
        string $service,
        string $endpointKey,
        array $replacements = [],
        array $query = [],
        bool $decodeJson = true
    ): array|string {
        // Build the base URL and path
        $config = config("apiurls.{$service}", []);
        $path   = rtrim($config['base'] ?? '', '/') . '/' . ltrim(
            str_replace(
                array_map(fn($k) => "{{$k}}", array_keys($replacements)),
                array_values($replacements),
                $config['endpoints'][$endpointKey] ?? ''
            ),
            '/'
        );

        // Handle auth configuration
        $auth = $config['auth'] ?? ['in' => 'none'];

        if (isset($config['key']) && ! empty($config['key'])) {
            switch ($auth['in']) {
                case 'header':
                    $headerValue = $auth['type']
                        ? sprintf('%s %s', $auth['type'], $config['key'])
                        : $config['key'];
                    $headers = [$auth['name'] => $headerValue];
                    break;

                case 'query':
                    $query[$auth['name']] = $config['key'];
                    $headers = [];
                    break;

                default:
                    $headers = [];
            }
        } else {
            $headers = [];
        }

        // Append query string to URL if present
        if (! empty($query)) {
            $path .= (Str::contains($path, '?') ? '&' : '?') . http_build_query($query);
        }

        // Prepare the HTTP client
        $request = Http::timeout(30)
                       ->acceptJson();

        if (! empty($headers)) {
            $request = $request->withHeaders($headers);
        }

        // Execute and handle errors
        $response = $request->get($path);
        $response->throw();

        return $decodeJson ? $response->json() : $response->body();
    }


    /**
     * Build the full URL that would be used for an API request,
     * including path, replacements, and query parameters (including auth in query).
     *
     * @param string      $service       The service key as defined in config/apiurls.php
     * @param string      $endpointKey   The endpoint key under that service
     * @param array       $replacements  Placeholder replacements for the endpoint path
     * @param array       $query         Additional query parameters
     * @return string                     Fully assembled URL
     */
    public function getApiUrl(
        string $service,
        string $endpointKey,
        array $replacements = [],
        array $query = []
    ): string {
        // Base and endpoint from config
        $config  = config("apiurls.{$service}", []);
        $base    = rtrim($config['base'] ?? '', '/');
        $endpoint = $config['endpoints'][$endpointKey] ?? '';

        // Replace placeholders
        $path = str_replace(
            array_map(fn($k) => "{{$k}}", array_keys($replacements)),
            array_values($replacements),
            $endpoint
        );

        $url = $base . '/' . ltrim($path, '/');

        // Handle auth in query
        $auth = $config['auth'] ?? ['in' => 'none'];
        if (isset($config['key']) && ! empty($config['key']) && $auth['in'] === 'query') {
            $query[$auth['name']] = $config['key'];
        }

        // Append manual query parameters
        if (! empty($query)) {
            $url .= (Str::contains($url, '?') ? '&' : '?') . http_build_query($query);
        }

        return $url;
    }
}
