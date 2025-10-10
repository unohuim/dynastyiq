<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Events\DiscordMemberConnected;
use App\Models\Organization;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;


class DiscordWebhookController extends Controller
{


// app/Http/Controllers/Api/DiscordWebhookController.php

    /**
     * Return Discord IDs that belong to users with Fantrax linkage.
     * Request: { "discord_user_ids": ["123", "456", ...] }
     * Response: { "connected_ids": ["123", ...] }
     */
    public function isFantrax(Request $request)
    {
        $ids = collect((array) $request->input('discord_user_ids', []))
            ->filter()->map(strval(...))->unique()->values();

        if ($ids->isEmpty()) {
            return response()->json(['connected_ids' => []]);
        }

        // Map discord_id -> user_id via SocialAccount (provider='discord')
        $map = \App\Models\SocialAccount::query()
            ->where('provider', 'discord')
            ->whereIn('provider_user_id', $ids)
            ->get(['provider_user_id', 'user_id'])
            ->pluck('user_id', 'provider_user_id'); // [discord_id => user_id]

        if ($map->isEmpty()) {
            return response()->json(['connected_ids' => []]);
        }

        // Users with any Fantrax linkage (exists in league_user_teams)
        $linkedUserIds = \DB::table('league_user_teams')
            ->whereIn('user_id', $map->values()->unique())
            ->distinct()
            ->pluck('user_id');

        // Discord IDs whose mapped user_id is linked
        $connectedDiscordIds = \App\Models\SocialAccount::query()
            ->where('provider', 'discord')
            ->whereIn('provider_user_id', $ids)            // incoming Discord IDs (strings)
            ->whereIn('user_id', $linkedUserIds)           // users present in league_user_teams
            ->pluck('provider_user_id')
            ->map(fn ($v) => (string) $v)                  // <<< force strings to avoid JS precision loss
            ->values();

        return response()->json(['connected_ids' => $connectedDiscordIds]);

    }




    /**
     * GET /api/discord/users/{discord_id}
     * Returns the leagues you share with the target Discord user and the target's team in each.
     * Optional query: viewer_discord_id=<discord_id> (defaults to target → returns all target leagues)
     */
    public function getUserTeams(Request $request, string $discordId): \Illuminate\Http\JsonResponse
    {
        // target (right–clicked) user
        $targetSocial = SocialAccount::query()
            ->where('provider', 'discord')
            ->where('provider_user_id', (string) $discordId)
            ->first();

        $targetUserId = $targetSocial?->user_id;

        // viewer (invoker) user — optional; if absent, treat as self
        $viewerDiscordId = (string) $request->query('viewer_discord_id', '');
        $viewerUserId = $targetUserId;
        if ($viewerDiscordId !== '') {
            $viewerSocial = SocialAccount::query()
                ->where('provider', 'discord')
                ->where('provider_user_id', $viewerDiscordId)
                ->first();
            $viewerUserId = $viewerSocial?->user_id ?? null;
        }

        if (!$targetUserId || !$viewerUserId) {
            return response()->json([
                'target_discord_user_id' => (string) $discordId,
                'target_user_id'         => $targetUserId,
                'viewer_user_id'         => $viewerUserId,
                'shared_count'           => 0,
                'teams'                  => [],
            ]);
        }

        // leagues (platform_leagues) the viewer is in
        $viewerPlatformLeagueIds = DB::table('league_user_teams')
            ->where('user_id', $viewerUserId)
            ->pluck('platform_league_id');

        // target's teams limited to mutual platform leagues
        $rows = DB::table('league_user_teams as lut')
            ->join('platform_leagues as pl', 'pl.id', '=', 'lut.platform_league_id')
            ->join('platform_teams as pt', 'pt.id', '=', 'lut.team_id')
            ->where('lut.user_id', $targetUserId)
            ->whereIn('lut.platform_league_id', $viewerPlatformLeagueIds)
            ->select([
                'pl.id as platform_league_id',
                'pl.name as league_name',
                'pt.platform_team_id as team_id',
                'pt.name as team_name',
            ])
            ->orderBy('pl.name')
            ->get();

        \Log::info('user teams: ', ['rows'=>$rows]);


        return response()->json([
            'target_discord_user_id' => (string) $discordId,
            'target_user_id'         => $targetUserId,
            'viewer_user_id'         => $viewerUserId,
            'shared_count'           => $rows->count(),
            'teams'                  => $rows,
        ]);
    }



    /**
     * GET /api/discord/users/{discord_id}
     *
     * Return the email (if any) linked to a Discord user.
     *
     * @param  string  $discordId
     * @return \Illuminate\Http\JsonResponse
     */
    public function getUserEmail(string $discordId): \Illuminate\Http\JsonResponse
    {
        $social = SocialAccount::query()
            ->with(['user:id,email'])
            ->where('provider', 'discord')
            ->where('provider_user_id', (string) $discordId)
            ->first();

        $email = $social?->user?->email ?? $social?->email ?? null;

        return response()->json([
            'discord_user_id' => (string) $discordId,
            'diq_user_id'     => $social?->user_id ?? null,
            'email'           => $email,
        ]);
    }




    public function memberJoined(Request $request)
    {
        // Optional: simple shared-secret header
        if (config('services.discord.webhook_secret')) {
            abort_unless(
                hash_equals($request->header('X-Webhook-Secret') ?? '', config('services.discord.webhook_secret')),
                401
            );
        }

        $guildId = (string) $request->input('guild_id');
        $discordUserId = (string) $request->input('discord_user_id');
        abort_unless($guildId && $discordUserId, 422);

        // NEW: single source of truth for the handle
        $handle = trim((string) $request->input('username')) ?: "discord_{$discordUserId}";


        // Only act for the DynastyIQ guild
        $diqGuildId = (string) config('services.diq.guild_id');
        if ($diqGuildId !== '' && $guildId !== $diqGuildId) {
            return response()->noContent();
        }

        $social = SocialAccount::where('provider','discord')
            ->where('provider_user_id',$discordUserId)
            ->first();


        if (! $social) {
            DB::transaction(function () use ($request, $discordUserId, $handle, &$social) {
                $org = Organization::create([
                    'name'       => "{$handle}'s Organization",
                    'short_name' => null,
                ]);

                $user = User::create([
                    'name'              => $handle, // <— handle
                    'email'             => "discord_{$discordUserId}@placeholder.local",
                    'password'          => bcrypt(Str::random(40)),
                    'tenant_id'         => $org->id,
                    'email_verified_at' => null,
                ]);

                $social = SocialAccount::create([
                    'user_id'          => $user->id,
                    'provider'         => 'discord',
                    'provider_user_id' => $discordUserId,
                    'email'            => null,
                    'nickname'         => $handle,              // <— handle (was $request->input('username'))
                    'name'             => $handle,              // optional mirror
                    'avatar'           => $request->input('avatar'),
                ]);
            });
        }


        if ($social && empty($social->nickname)) {
            $social->update(['nickname' => $handle]);
        }




        if ($social && $social->user_id) {
            event(new DiscordMemberConnected($social->user_id, $guildId)); // UI flips to "Connected"
        }

        return response()->noContent();
    }
}
