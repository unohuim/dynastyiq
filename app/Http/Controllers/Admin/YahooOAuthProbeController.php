<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\YahooFantasyConnection;
use App\Services\YahooFantasyClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;
use SimpleXMLElement;

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

        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => config('services.yahoo.client_id'),
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ]);

        return redirect()->away(rtrim((string) config('yahoo.oauth.authorize'), '?').'?'.$query);
    }

    /**
     * Exchange Yahoo's authorization code and return sanitized Fantasy API proof data.
     */
    public function callback(Request $request, YahooFantasyClient $client): JsonResponse|RedirectResponse
    {
        $expectedState = (string) $request->session()->pull('yahoo_oauth_state', '');
        $redirectUri = (string) $request->session()->pull('yahoo_oauth_redirect_uri', $this->redirectUri($request));
        $state = $request->string('state')->value();

        if ($expectedState === '' || ! hash_equals($expectedState, $state)) {
            abort(403, 'Invalid Yahoo authorization state.');
        }

        $code = $request->string('code')->value();
        if ($code === '') {
            abort(422, 'Yahoo authorization code is required.');
        }

        $token = $client->exchangeCode($code, $redirectUri);
        $accessToken = (string) ($token['access_token'] ?? '');

        if ($accessToken === '') {
            throw new RuntimeException('Yahoo token response did not include an access token.');
        }

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

        $gameXml = $client->fantasyXml($accessToken, 'game/'.config('yahoo.fantasy.game_code', 'nhl'));
        $playersXml = $client->fantasyXml(
            $accessToken,
            'game/'.config('yahoo.fantasy.game_code', 'nhl').'/players;start=0;count=5',
        );
        $game = $this->gamePayload($gameXml);

        $connection->forceFill([
            'display_name' => $request->user()->email,
            'last_used_at' => now(),
            'meta' => array_filter([
                'game' => $game,
            ]),
        ])->save();

        $payload = [
            'ok' => true,
            'connection' => [
                'id' => $connection->id,
                'status' => $connection->status,
                'token_expires_at' => $connection->token_expires_at?->toIso8601String(),
            ],
            'game' => $game,
            'players' => $this->playersPayload($playersXml),
        ];

        if ($request->routeIs('integrations.yahoo.callback')) {
            return redirect($this->connectedReturnUrl($request))
                ->with('success', 'Yahoo connected');
        }

        return response()->json($payload);
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
