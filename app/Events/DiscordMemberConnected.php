<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class DiscordMemberConnected implements ShouldBroadcastNow
{
    public function __construct(public int $userId, public string $guildId) {}

    public function broadcastOn(): array { return [new PrivateChannel("user.{$this->userId}")]; }
    public function broadcastAs(): string { return 'discord.connected'; }
    public function broadcastWith(): array { return ['guild_id' => $this->guildId]; }
}
