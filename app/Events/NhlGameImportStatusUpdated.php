<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcasts that admin NHL game import status should be refreshed.
 */
class NhlGameImportStatusUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public string $reason,
        public ?int $runId = null,
        public ?int $gameId = null,
        public ?string $stage = null,
    ) {
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('admin.imports');
    }

    public function broadcastAs(): string
    {
        return 'admin.nhl-game-imports.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'reason' => $this->reason,
            'run_id' => $this->runId,
            'game_id' => $this->gameId,
            'stage' => $this->stage,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
