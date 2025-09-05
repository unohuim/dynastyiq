
<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

final class BotFantraxLinked implements ShouldBroadcast
{
    public function __construct(public string $discordUserId) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('diq-bot');
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
