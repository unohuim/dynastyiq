<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/** Invalidate the super-admin activity read without exposing private prediction inputs. */
final class NhlGamePredictionStatusUpdated implements ShouldBroadcastNow
{
    use Dispatchable;

    /** Capture only the identifiers needed to invalidate the authorized admin read. */
    public function __construct(public int $runId, public ?int $gameId = null)
    {
    }

    /** Restrict prediction activity to the existing super-admin authorization boundary. */
    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('admin.predictions');
    }

    /** Keep the event separate from import progress broadcasts. */
    public function broadcastAs(): string
    {
        return 'admin.nhl-predictions.updated';
    }

    /** @return array{run_id:int,game_id:?int} */
    public function broadcastWith(): array
    {
        return ['run_id' => $this->runId, 'game_id' => $this->gameId];
    }
}
