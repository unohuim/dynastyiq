<?php

namespace App\Listeners;

use App\Events\DiscordMemberConnected;
use Illuminate\Support\Facades\Cache;

class MarkDiscordConnected
{
    public function handle(DiscordMemberConnected $event): void
    {
        // durable flag for your app to read

        \Log::info('discord.connected', ['user_id'=>$event->userId, 'guild_id'=>$event->guildId]);
        Cache::put("discord_connected:{$event->userId}", $event->guildId, now()->addMinutes(5));


    }
}
