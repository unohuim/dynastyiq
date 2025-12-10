<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ImportStreamEvent implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public string $source,
        public string $message,
        public string $status = 'output',
        public ?string $batchId = null,
    ) {
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('admin.imports');
    }

    public function broadcastAs(): string
    {
        return 'admin.import.output';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'source' => $this->source,
            'message' => $this->message,
            'status' => $this->status,
            'batch_id' => $this->batchId,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
