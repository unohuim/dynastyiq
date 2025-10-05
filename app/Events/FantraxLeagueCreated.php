<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class FantraxLeagueCreated implements ShouldBroadcast
{
    use Dispatchable;
    use SerializesModels;

    public bool $afterCommit = true;

    public function __construct(
        public int $platformLeagueId
    ) {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('fantrax.leagues')];
    }

    public function broadcastAs(): string
    {
        return 'FantraxLeagueCreated';
    }

    public function broadcastWith(): array
    {
        return [
            'platform_league_id' => $this->platformLeagueId,
        ];
    }
}
