<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

final class BotFantraxLinked implements ShouldBroadcastNow
{
    public function __construct(public string $discordUserId) {}

    public function broadcastOn(): PrivateChannel
    {
        // Match what the bot subscribes to
        return new PrivateChannel('private-diq-bot');
    }

    public function broadcastAs(): string
    {
        return 'fantrax-linked';
    }

    public function broadcastWith(): array
    {
        return ['discord_user_id' => $this->discordUserId];
    }
}
