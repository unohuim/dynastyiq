<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\DiscordServer;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class DiscordServerCallbackController extends Controller
{
    public function __invoke(Request $request)
    {
        $code  = $request->get('code');
        $error = $request->get('error');

        if ($error || !$code) {
            return redirect()->route('dashboard')
                ->with('error', 'Discord server authorization failed.');
        }

        // 1) Exchange the code for a token
        $tokenResp = Http::asForm()->post('https://discord.com/api/oauth2/token', [
            'client_id'     => config('services.discord.client_id'),
            'client_secret' => config('services.discord.client_secret'),
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => route('discord-server.callback'),
        ]);

        if (! $tokenResp->ok()) {
            return redirect()->route('dashboard')
                ->with('error', 'Failed to exchange code with Discord.');
        }

        $oauth = $tokenResp->json();

        // 2) Read install context from querystring
        $guildId     = (string) $request->get('guild_id');     // present on bot OAuth
        $permissions = (string) $request->get('permissions');  // permissions integer as string
        $guildName   = (string) $request->get('guild_name', ''); // sometimes not sent

        if (empty($guildId)) {
            return redirect()->route('dashboard')
                ->with('error', 'Missing guild information from Discord.');
        }

        // 3) (Optional) Identify who installed it (Discord user id)
        $installedBy = null;
        if (!empty($oauth['access_token'])) {
            $me = Http::withToken($oauth['access_token'])->get('https://discord.com/api/oauth2/@me');
            if ($me->ok()) {
                $installedBy = data_get($me->json(), 'user.id');
            }
        }

        // 4) Resolve current organization (requires authenticated user)
        $user = Auth::user();
        $org  = $user?->organization ?? (isset($user->tenant_id) ? Organization::find($user->tenant_id) : null);

        if (!$org) {
            return redirect()->route('dashboard')
                ->with('error', 'No organization context found for this install.');
        }

        // 5) Upsert DiscordServer and link via pivot discord_organizations
        DB::transaction(function () use ($org, $guildId, $guildName, $installedBy, $permissions, $oauth) {
            // Upsert into discord_servers (one row per guild)
            $server = DiscordServer::updateOrCreate(
                ['discord_guild_id' => $guildId],
                [
                    'organization_id'              => $org->id, // ensures ownership; if moving guilds, unique will enforce one-org-per-server
                    'discord_guild_name'           => $guildName ?: ('Discord Guild ' . $guildId),
                    'installed_by_discord_user_id' => $installedBy,
                    'access_token'                 => $oauth['access_token']   ?? null,
                    'refresh_token'                => $oauth['refresh_token']  ?? null,
                    'token_expires_at'             => isset($oauth['expires_in']) ? now()->addSeconds((int) $oauth['expires_in']) : null,
                    'granted_permissions'          => $permissions,
                ]
            );

            // Ensure pivot link exists (one org↔many servers; one server→one org)
            DB::table('discord_organizations')->updateOrInsert(
                ['discord_server_id' => $server->id],
                [
                    'organization_id' => $org->id,
                    'linked_at'       => now(),
                    'meta'            => null,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ]
            );
        });

        return redirect()->route('dashboard')
            ->with('success', 'DynastyIQ bot connected to your server!');
    }
}
