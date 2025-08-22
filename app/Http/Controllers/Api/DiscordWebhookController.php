<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Events\DiscordMemberConnected;
use App\Models\SocialAccount;
use Illuminate\Http\Request;

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

        $social = SocialAccount::where('provider','discord')
            ->where('provider_user_id',$discordUserId)
            ->first();

        if ($social && $social->user_id) {
            event(new DiscordMemberConnected($social->user_id, $guildId)); // UI flips to "Connected"
        }

        return response()->noContent();
    }
}
