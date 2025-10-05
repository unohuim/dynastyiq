<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class FantraxTeamCreated implements ShouldBroadcast
{
    use Dispatchable;
    use SerializesModels;

    public bool $afterCommit = true;

    public function __construct(
        public int $platformLeagueId,
        public string $platformTeamId
    ) {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('fantrax.teams')];
    }

    public function broadcastAs(): string
    {
        return 'FantraxTeamCreated';
    }

    public function broadcastWith(): array
    {
        return [
            'platform_league_id' => $this->platformLeagueId,
            'platform_team_id' => $this->platformTeamId,
        ];
    }
}
