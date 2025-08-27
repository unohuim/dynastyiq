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
