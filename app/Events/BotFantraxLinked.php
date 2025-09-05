
<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

final class BotFantraxLinked implements ShouldBroadcastNow
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
