<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\PlatformLeague;
use App\Services\SyncFantraxDraftState;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

final class SyncFantraxDraftStateJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Queueable;
    use InteractsWithQueue;
    use SerializesModels;

    public function __construct(
        public int $platformLeagueId,
        public ?array $draftResults = null,
        public ?array $draftPickInfo = null,
    ) {
        $this->afterCommit = true;
        $this->onQueue('fantrax');
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('fantrax-draft-sync:' . $this->platformLeagueId))
                ->releaseAfter(30)
                ->expireAfter(660),
        ];
    }

    public function backoff(): array
    {
        return [10, 60, 300];
    }

    /** Collapse repeated polls, but never discard a distinct supplied payload. */
    public function uniqueId(): string
    {
        return $this->platformLeagueId . ':' . ($this->draftResults === null
            ? 'poll'
            : hash('sha256', serialize([$this->draftResults, $this->draftPickInfo])));
    }

    /** Share admission locks between the application and dedicated workers. */
    public function uniqueVia(): Repository
    {
        return Cache::store('database');
    }

    public function tags(): array
    {
        return ['fantrax', 'draft', 'league:' . $this->platformLeagueId];
    }

    public function handle(SyncFantraxDraftState $service): void
    {
        if ($this->draftResults !== null) {
            $league = PlatformLeague::query()->find($this->platformLeagueId);

            if ($league instanceof PlatformLeague) {
                $service->syncPayloads($league, $this->draftResults, $this->draftPickInfo ?? []);
            }

            return;
        }

        $service->sync($this->platformLeagueId);
    }
}
