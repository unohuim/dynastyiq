<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcasts updated league logo display data after a team-logo sync.
 */
final class TeamLogosSynced implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public int $userId,
        public int $platformLeagueId,
        public string $platform,
        public ?string $logoUrl,
    ) {
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("user.{$this->userId}");
    }

    public function broadcastAs(): string
    {
        return 'league.logos.synced';
    }

    /**
     * @return array<string,mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'platform_league_id' => $this->platformLeagueId,
            'platform' => $this->platform,
            'logo_url' => $this->logoUrl,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
