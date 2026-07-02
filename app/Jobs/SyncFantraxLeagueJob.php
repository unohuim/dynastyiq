<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Events\LeagueSyncStatusUpdated;
use App\Models\PlatformLeague;
use App\Services\SyncFantraxLeague;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

final class SyncFantraxLeagueJob implements ShouldQueue
{
    use Queueable;
    use InteractsWithQueue;
    use SerializesModels;

    public function __construct(
        public int $platformLeagueId,
        public ?int $userId = null,
    ) {
        $this->afterCommit = true;
    }

    /**
     * Prevent concurrent syncs of the same league.
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('fantrax-sync:' . $this->platformLeagueId))
                ->expireAfter(300),
        ];
    }

    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function tags(): array
    {
        return ['fantrax', 'league:' . $this->platformLeagueId];
    }

    public function handle(SyncFantraxLeague $service): void
    {
        $this->broadcastStatus('processing');

        try {
            $service->sync($this->platformLeagueId);
            $this->broadcastStatus('completed');
        } catch (\Throwable $throwable) {
            $this->broadcastStatus('failed');

            throw $throwable;
        }
    }

    private function broadcastStatus(string $status): void
    {
        if ($this->userId === null) {
            return;
        }

        $league = PlatformLeague::query()
            ->select('id', 'platform')
            ->find($this->platformLeagueId);

        if (! $league instanceof PlatformLeague) {
            return;
        }

        LeagueSyncStatusUpdated::dispatch(
            $this->userId,
            $league->id,
            (string) $league->platform,
            $status,
        );
    }
}
