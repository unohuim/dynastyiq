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
    // GET /auth/discord-server/callback
    public function __invoke(Request $request)
    {
        $code  = (string) $request->query('code', '');
        $state = (string) $request->query('state', '');
        $error = (string) $request->query('error', '');

        if ($error || $code === '' || $state === '') {
            return redirect()->route('communities.index')->with('error', 'Discord authorization failed.');
        }

        // org context from encrypted state
        try {
            $payload = decrypt($state);
            $orgId   = (int) ($payload['org_id'] ?? 0);
        } catch (\Throwable $e) {
            return redirect()->route('communities.index')->with('error', 'Invalid state.');
        }
        $org = Organization::find($orgId);
        if (! $org) {
            return redirect()->route('communities.index')->with('error', 'Organization not found.');
        }

        // app-side guard: admin (level >= 10) for this org
        /** @var \App\Models\User $user */
        $user = Auth::user();
        $maxLevel = (int) $user->roles()->wherePivot('organization_id', $org->id)->max('level');
        if ($maxLevel < 10) {
            return redirect()->route('communities.index')->with('error', 'Not authorized.');
        }

        // Exchange code → token
        $token = Http::asForm()->post('https://discord.com/api/oauth2/token', [
            'client_id'     => config('services.discord.client_id'),
            'client_secret' => config('services.discord.client_secret'),
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => route('discord-server.callback'),
        ])->throw()->json('access_token');

        // Who authorized?
        $me = Http::withToken($token)->get('https://discord.com/api/oauth2/@me')->throw()->json();
        $discordUserId = (string) data_get($me, 'user.id', '');

        // Guilds user can manage
        $guilds = Http::withToken($token)->get('https://discord.com/api/users/@me/guilds')->throw()->json();


        $eligible = collect($guilds)->filter(function ($g) {
            $perm = (int) ($g['permissions'] ?? 0);
            return ($g['owner'] ?? false) || ($perm & 0x20) || ($perm & 0x8);
        })->map(fn ($g) => [
            'id'          => (string) $g['id'],
            'name'        => (string) ($g['name'] ?? 'Unknown Server'),
            'permissions' => (string) ($g['permissions'] ?? '0'),
            'icon'        => (string) ($g['icon'] ?? ''), // <-- add this
        ])->values()->all();



        if (empty($eligible)) {
            return redirect()->route('communities.index')->with('error', 'No eligible servers found.');
        }

        // stash for attach step and show picker
        session([
            'discord.connect.org_id'  => $org->id,
            'discord.connect.user_id' => $discordUserId,
            'discord.connect.guilds'  => $eligible,
        ]);

        return view('discord.choose-guild', [
            'organization' => $org,
            'guilds'       => $eligible,
        ]);
    }

    // POST /auth/discord-server/attach
    public function attach(Request $request)
    {
        $validated = $request->validate([
            'guild_ids'   => 'required|array|min:1',
            'guild_ids.*' => 'string',
        ]);

        $orgId   = (int) session('discord.connect.org_id', 0);
        $userId  = (string) session('discord.connect.user_id', '');
        $allowed = collect((array) session('discord.connect.guilds', []));

        $org = Organization::find($orgId);
        if (! $org) {
            return redirect()->route('communities.index')->with('error', 'Organization not found.');
        }

        /** @var \App\Models\User $user */
        $user = Auth::user();
        $maxLevel = (int) $user->roles()->wherePivot('organization_id', $org->id)->max('level');
        if ($maxLevel < 10) {
            return redirect()->route('communities.index')->with('error', 'Not authorized.');
        }

        // Keep only selections that were actually authorized by Discord in the prior step
        $selected = collect($validated['guild_ids'])->unique()->map(function ($id) use ($allowed) {
            return $allowed->firstWhere('id', (string) $id);
        })->filter()->values();

        if ($selected->isEmpty()) {
            return redirect()->route('communities.index')->with('error', 'Invalid selection.');
        }

        DB::transaction(function () use ($org, $selected, $userId) {
            foreach ($selected as $g) {
                DiscordServer::updateOrCreate(
                    ['discord_guild_id' => $g['id']],
                    [
                        'organization_id'              => $org->id,
                        'discord_guild_name'           => $g['name'],
                        'installed_by_discord_user_id' => $userId,
                        'granted_permissions'          => (string) ($g['permissions'] ?? '0'),
                        'meta'                         => ['icon' => $g['icon'] ?? null], // <-- save icon
                    ]
                );
            }
        });

        // clear session
        session()->forget([
            'discord.connect.org_id',
            'discord.connect.user_id',
            'discord.connect.guilds',
        ]);

        return redirect()->route('communities.index')->with('success', $selected->count().' server(s) connected.');
    }

}
