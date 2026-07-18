<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DiscordServer;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\View\View;

/**
 * Coordinate Discord bot installation callbacks with the community UI.
 */
class DiscordBotInstallController extends Controller
{
    /**
     * Redirect to Discord's configured bot install URL with app-owned callback state.
     */
    public function redirect(
        Request $request,
        Organization $organization,
        DiscordServer $discordServer
    ): RedirectResponse {
        $this->authorizeCommunityServer($request, $organization, $discordServer);

        $installUrl = (string) config('services.discord.diq_install_url');
        abort_if($installUrl === '', 404);

        $url = $this->installUrlWithCallback(
            $installUrl,
            encrypt([
                'organization_id' => $organization->id,
                'discord_server_id' => $discordServer->id,
                'user_id' => $request->user()?->id,
            ]),
            route('discord-server.bot-installed.callback')
        );

        return redirect()->away($url);
    }

    /**
     * Render a lightweight callback page that notifies the opener and closes.
     */
    public function callback(Request $request): View
    {
        $state = (string) $request->query('state', '');
        $payload = [];

        if ($state !== '') {
            try {
                $payload = (array) decrypt($state);
            } catch (\Throwable) {
                $payload = [];
            }
        }

        return view('discord.bot-installed', [
            'organizationId' => (int) ($payload['organization_id'] ?? 0),
            'discordServerId' => (int) ($payload['discord_server_id'] ?? 0),
        ]);
    }

    /**
     * Return whether the DIQ bot can currently access a connected Discord server.
     */
    public function status(
        Request $request,
        Organization $organization,
        DiscordServer $discordServer
    ): JsonResponse {
        $this->authorizeCommunityServer($request, $organization, $discordServer);

        $token = (string) config('apiurls.discord-bot.key');
        $installed = false;

        if ($token !== '') {
            try {
                $installed = Http::withHeaders([
                    'Authorization' => 'Bot ' . $token,
                ])
                    ->acceptJson()
                    ->get('https://discord.com/api/v10/guilds/' . $discordServer->discord_guild_id)
                    ->successful();
            } catch (\Throwable) {
                $installed = false;
            }
        }

        return response()->json([
            'ok' => true,
            'installed' => $installed,
            'discord_server' => [
                'id' => $discordServer->id,
                'discord_guild_id' => $discordServer->discord_guild_id,
                'discord_guild_name' => $discordServer->discord_guild_name,
            ],
        ]);
    }

    private function authorizeCommunityServer(
        Request $request,
        Organization $organization,
        DiscordServer $discordServer
    ): void {
        $user = $request->user();

        abort_unless($user, 403);
        abort_unless((int) $discordServer->organization_id === (int) $organization->id, 404);

        $roleLevel = (int) ($user->roles()
            ->wherePivot('organization_id', $organization->id)
            ->max('level') ?? 0);

        abort_unless($roleLevel >= 10, 403);
    }

    private function installUrlWithCallback(string $installUrl, string $state, string $callbackUrl): string
    {
        $parts = parse_url($installUrl);
        $query = [];

        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);
        }

        $query['state'] = $state;
        $query['redirect_uri'] = $callbackUrl;
        $queryString = http_build_query($query);

        $scheme = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = $parts['path'] ?? '';
        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

        return $scheme . $host . $port . $path . ($queryString !== '' ? '?' . $queryString : '') . $fragment;
    }
}
