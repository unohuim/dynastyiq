<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PlayersAvailable implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public string $source, public int $count = 0)
    {
    }

    public function broadcastOn(): Channel
    {
        return new PrivateChannel('admin.imports');
    }

    public function broadcastAs(): string
    {
        return 'players.available';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'timestamp' => now()->toIso8601String(),
            'players_count' => $this->count,
            'source' => $this->source,
        ];
    }
}
