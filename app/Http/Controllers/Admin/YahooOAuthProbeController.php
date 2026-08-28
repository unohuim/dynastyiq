<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\YahooFantasyConnection;
use App\Services\YahooFantasyClient;
use App\Services\YahooFantasyLeagueService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;
use SimpleXMLElement;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Connects Yahoo OAuth and verifies Fantasy Sports API access.
 */
class YahooOAuthProbeController extends Controller
{
    /**
     * Redirect the current user to Yahoo for Fantasy Sports authorization.
     */
    public function redirect(Request $request): RedirectResponse
    {
        $state = Str::random(40);
        $redirectUri = $this->redirectUri($request);
        $request->session()->put('yahoo_oauth_state', $state);
        $request->session()->put('yahoo_oauth_redirect_uri', $redirectUri);

        if ($request->routeIs('integrations.yahoo.redirect')) {
            $request->session()->put('yahoo_oauth_return_url', $this->returnUrl($request));
        }

        $queryParams = [
            'response_type' => 'code',
            'client_id' => config('services.yahoo.client_id'),
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ];
        $scopes = $this->oauthScopes();

        if ($scopes !== '') {
            $queryParams['scope'] = $scopes;
        }

        $query = http_build_query($queryParams, '', '&', PHP_QUERY_RFC3986);

        return redirect()->away(rtrim((string) config('yahoo.oauth.authorize'), '?').'?'.$query);
    }

    /**
     * Exchange Yahoo's authorization code and return sanitized Fantasy API proof data.
     */
    public function callback(
        Request $request,
        YahooFantasyClient $client,
        YahooFantasyLeagueService $leagueService,
    ): JsonResponse|RedirectResponse
    {
        $expectedState = (string) $request->session()->get('yahoo_oauth_state', '');
        $redirectUri = (string) $request->session()->get('yahoo_oauth_redirect_uri', $this->redirectUri($request));
        $state = $request->string('state')->value();

        if ($expectedState === '' || ! hash_equals($expectedState, $state)) {
            abort(403, 'Invalid Yahoo authorization state.');
        }

        if ($request->filled('error')) {
            return $this->failedAuthorizationResponse(
                $request,
                $this->yahooAuthorizationErrorMessage($request)
            );
        }

        $code = $request->string('code')->value();
        if ($code === '') {
            return $this->failedAuthorizationResponse($request, 'Yahoo authorization code is required.');
        }

        $token = $client->exchangeCode($code, $redirectUri);
        $accessToken = (string) ($token['access_token'] ?? '');

        if ($accessToken === '') {
            throw new RuntimeException('Yahoo token response did not include an access token.');
        }

        $request->session()->forget([
            'yahoo_oauth_state',
            'yahoo_oauth_redirect_uri',
        ]);

        $connection = YahooFantasyConnection::updateOrCreate(
            ['user_id' => $request->user()->id],
            [
                'status' => 'connected',
                'access_token' => $accessToken,
                'refresh_token' => $token['refresh_token'] ?? null,
                'token_expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 3600)),
                'scopes' => $client->scopesFromTokenResponse($token, []),
                'connected_at' => now(),
                'last_error' => null,
            ],
        );

        $request->session()->put('yahoo_oauth_probe_token', [
            'access_token' => $accessToken,
            'refresh_token' => $token['refresh_token'] ?? null,
            'expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 3600))->toIso8601String(),
        ]);

        $gamePath = 'game/'.config('yahoo.fantasy.game_code', 'nhl');
        $playersPath = $gamePath.'/players;start=0;count=5';
        $probePath = $gamePath;

        try {
            $gameXml = $client->fantasyXml($accessToken, $probePath);
            $probePath = $playersPath;
            $playersXml = $client->fantasyXml($accessToken, $probePath);
        } catch (Throwable $throwable) {
            $message = $this->fantasyProbeFailureMessage($probePath, $throwable);

            $connection->forceFill([
                'last_error' => $message,
                'meta' => array_merge($connection->meta ?? [], [
                    'oauth_probe' => array_filter([
                        'status' => 'failed',
                        'path' => $probePath,
                        'http_status' => $this->yahooResponseStatus($throwable),
                        'description' => $this->yahooResponseDescription($throwable),
                        'failed_at' => now()->toIso8601String(),
                    ]),
                ]),
            ])->save();

            if ($request->routeIs('integrations.yahoo.callback')) {
                return redirect($this->connectedReturnUrl($request))
                    ->with('error', $message);
            }

            return response()->json([
                'ok' => false,
                'message' => $message,
                'connection' => [
                    'id' => $connection->id,
                    'status' => $connection->status,
                    'token_expires_at' => $connection->token_expires_at?->toIso8601String(),
                ],
            ], $this->yahooResponseStatus($throwable) ?? Response::HTTP_BAD_GATEWAY);
        }

        $game = $this->gamePayload($gameXml);

        $connection->forceFill([
            'display_name' => $request->user()->email,
            'last_used_at' => now(),
            'meta' => array_filter([
                'game' => $game,
            ]),
        ])->save();

        $leagueSync = $this->syncLeagues($connection->refresh(), $leagueService);

        $payload = [
            'ok' => true,
            'connection' => [
                'id' => $connection->id,
                'status' => $connection->status,
                'token_expires_at' => $connection->token_expires_at?->toIso8601String(),
            ],
            'game' => $game,
            'league_sync' => $leagueSync,
            'players' => $this->playersPayload($playersXml),
        ];

        if ($request->routeIs('integrations.yahoo.callback')) {
            return redirect($this->connectedReturnUrl($request))
                ->with('success', 'Yahoo connected');
        }

        return response()->json($payload);
    }

    /**
     * Sync Yahoo leagues without invalidating an otherwise successful OAuth grant.
     *
     * @return array<string,mixed>
     */
    private function syncLeagues(YahooFantasyConnection $connection, YahooFantasyLeagueService $leagueService): array
    {
        try {
            $summary = $leagueService->syncForConnection($connection);

            $connection->forceFill([
                'last_error' => null,
                'meta' => array_merge($connection->meta ?? [], [
                    'league_sync' => $summary,
                ]),
            ])->save();

            return $summary;
        } catch (Throwable $throwable) {
            $connection->forceFill([
                'last_error' => $throwable->getMessage(),
                'meta' => array_merge($connection->meta ?? [], [
                    'league_sync' => [
                        'error' => $throwable->getMessage(),
                    ],
                ]),
            ])->save();

            return [
                'error' => 'Yahoo connected, but league sync failed.',
            ];
        }
    }

    /**
     * Return the Yahoo redirect URI used for OAuth callback validation.
     */
    private function redirectUri(Request $request): string
    {
        if ($request->routeIs('integrations.yahoo.redirect', 'integrations.yahoo.callback')) {
            return route('integrations.yahoo.callback');
        }

        return (string) (config('services.yahoo.redirect') ?: route('admin.yahoo.oauth.callback'));
    }

    /**
     * Return the configured Yahoo OAuth scopes for Fantasy Sports access.
     */
    private function oauthScopes(): string
    {
        return collect(explode(' ', (string) config('yahoo.oauth.scopes', '')))
            ->map(static fn (string $scope): string => trim($scope))
            ->filter(static fn (string $scope): bool => $scope !== '')
            ->unique()
            ->values()
            ->implode(' ');
    }

    /**
     * Return a route-appropriate failed OAuth response without exposing raw provider markup.
     */
    private function failedAuthorizationResponse(Request $request, string $message): JsonResponse|RedirectResponse
    {
        if ($request->routeIs('integrations.yahoo.callback')) {
            return redirect($this->connectedReturnUrl($request))
                ->with('error', $message);
        }

        return response()->json([
            'ok' => false,
            'message' => $message,
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * Build a sanitized Yahoo OAuth error message from callback query parameters.
     */
    private function yahooAuthorizationErrorMessage(Request $request): string
    {
        $error = $request->string('error')->trim()->toString();
        $description = $request->string('error_description')->trim()->toString();

        if ($description !== '') {
            return 'Yahoo authorization failed: '.$description;
        }

        return $error !== ''
            ? 'Yahoo authorization failed: '.$error
            : 'Yahoo authorization failed.';
    }

    /**
     * Return a clear probe failure message without exposing token data.
     */
    private function fantasyProbeFailureMessage(string $path, Throwable $throwable): string
    {
        $message = 'Yahoo connected, but Fantasy API probe failed on '.$path.'.';
        $description = $this->yahooResponseDescription($throwable);

        return $description === null
            ? $message
            : $message.' Yahoo response: '.$description;
    }

    /**
     * Return the HTTP status from a Yahoo response exception when available.
     */
    private function yahooResponseStatus(Throwable $throwable): ?int
    {
        return $throwable instanceof RequestException
            ? $throwable->response->status()
            : null;
    }

    /**
     * Extract a sanitized Yahoo response description when available.
     */
    private function yahooResponseDescription(Throwable $throwable): ?string
    {
        if (! $throwable instanceof RequestException) {
            return null;
        }

        $body = trim($throwable->response->body());
        if ($body === '') {
            return null;
        }

        $useInternalErrors = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        libxml_clear_errors();
        libxml_use_internal_errors($useInternalErrors);

        $description = $xml instanceof SimpleXMLElement
            ? trim((string) ($xml->description ?? ''))
            : '';

        if ($description === '') {
            $description = trim((string) preg_replace('/\s+/', ' ', strip_tags($body)));
        }

        return $description === ''
            ? null
            : Str::limit($description, 240, '');
    }

    /**
     * Return a same-application URL for the user integration callback.
     */
    private function returnUrl(Request $request): string
    {
        $fallback = route('dashboard', absolute: false);
        $requested = $request->string('return_to')->toString();
        $path = $this->localPath($requested, $request) ?? $fallback;
        $url = url($path);

        if ($request->string('drawer')->toString() === 'account') {
            $url = $this->withQuery($url, ['drawer' => 'account']);
        }

        return $url;
    }

    /**
     * Return the post-connect URL with UI state flags.
     */
    private function connectedReturnUrl(Request $request): string
    {
        $url = (string) $request->session()->pull('yahoo_oauth_return_url', route('dashboard'));

        return $this->withQuery($url, [
            'drawer' => 'account',
            'yahoo_connected' => '1',
        ]);
    }

    /**
     * Convert a same-origin or root-relative URL into a local path.
     */
    private function localPath(string $url, Request $request): ?string
    {
        $url = trim($url);

        if ($url === '') {
            return null;
        }

        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return $url;
        }

        $parts = parse_url($url);
        if (! is_array($parts)) {
            return null;
        }

        $host = $parts['host'] ?? null;
        if ($host !== $request->getHost()) {
            return null;
        }

        $scheme = $parts['scheme'] ?? $request->getScheme();
        if ($scheme !== $request->getScheme()) {
            return null;
        }

        $path = $parts['path'] ?? '/';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#'.$parts['fragment'] : '';

        return $path.$query.$fragment;
    }

    /**
     * Merge query parameters into a URL.
     *
     * @param array<string, string> $parameters
     */
    private function withQuery(string $url, array $parameters): string
    {
        $parts = parse_url($url);
        if (! is_array($parts)) {
            return $url;
        }

        parse_str($parts['query'] ?? '', $query);
        $query = array_merge($query, $parameters);

        $scheme = isset($parts['scheme']) ? $parts['scheme'].'://' : '';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = $parts['path'] ?? '';
        $fragment = isset($parts['fragment']) ? '#'.$parts['fragment'] : '';

        return $scheme.$host.$port.$path.'?'.http_build_query($query).$fragment;
    }

    /**
     * Extract safe game metadata from Yahoo XML.
     *
     * @return array<string,string|null>
     */
    private function gamePayload(SimpleXMLElement $xml): array
    {
        return [
            'game_key' => $this->firstText($xml, 'game_key'),
            'game_id' => $this->firstText($xml, 'game_id'),
            'code' => $this->firstText($xml, 'code'),
            'name' => $this->firstText($xml, 'name'),
            'season' => $this->firstText($xml, 'season'),
        ];
    }

    /**
     * Extract safe first-page player diagnostics from Yahoo XML.
     *
     * @return array<int,array<string,mixed>>
     */
    private function playersPayload(SimpleXMLElement $xml): array
    {
        $players = $xml->xpath('//*[local-name()="player"]') ?: [];

        return collect($players)
            ->take(5)
            ->map(fn (SimpleXMLElement $player): array => [
                'player_key' => $this->firstText($player, 'player_key'),
                'player_id' => $this->firstText($player, 'player_id'),
                'full_name' => $this->firstText($player, 'full'),
                'first_name' => $this->firstText($player, 'first'),
                'last_name' => $this->firstText($player, 'last'),
                'editorial_team_abbr' => $this->firstText($player, 'editorial_team_abbr'),
                'display_position' => $this->firstText($player, 'display_position'),
                'primary_position' => $this->firstText($player, 'primary_position'),
                'eligible_positions' => $this->allText($player, 'position'),
            ])
            ->values()
            ->all();
    }

    /**
     * Return the first descendant text value matching a local XML element name.
     */
    private function firstText(SimpleXMLElement $xml, string $localName): ?string
    {
        $nodes = $xml->xpath('.//*[local-name()="'.$localName.'"]') ?: [];
        $value = trim((string) ($nodes[0] ?? ''));

        return $value === '' ? null : $value;
    }

    /**
     * Return all descendant text values matching a local XML element name.
     *
     * @return array<int,string>
     */
    private function allText(SimpleXMLElement $xml, string $localName): array
    {
        $nodes = $xml->xpath('.//*[local-name()="'.$localName.'"]') ?: [];

        return collect($nodes)
            ->map(static fn (SimpleXMLElement $node): string => trim((string) $node))
            ->filter(static fn (string $value): bool => $value !== '')
            ->values()
            ->all();
    }
}
