<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\PlatformLeague;
use App\Services\SyncFantraxDraftState;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

final class SyncFantraxDraftStateJob implements ShouldQueue
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
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('fantrax-draft-sync:' . $this->platformLeagueId))
                ->expireAfter(300),
        ];
    }

    public function backoff(): array
    {
        return [10, 60, 300];
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
