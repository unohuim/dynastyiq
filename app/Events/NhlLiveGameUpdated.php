<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/** A shared NHL poll produced new public game facts. */
final class NhlLiveGameUpdated implements ShouldBroadcastNow
{
    use Dispatchable;

    /** Carry only compact public facts; full public detail is available from the snapshot endpoint. */
    public function __construct(public array $snapshot)
    {
    }

    /** All public game surfaces subscribe without requiring an account. */
    public function broadcastOn(): Channel
    {
        return new Channel('nhl.games');
    }

    /** Keep the external listener contract stable. */
    public function broadcastAs(): string
    {
        return 'nhl.game.updated';
    }

    /** @return array<string,mixed> */
    public function broadcastWith(): array
    {
        return $this->snapshot;
    }
}
