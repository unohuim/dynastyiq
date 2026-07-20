<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Events\NhlGameImportStatusUpdated;
use App\Services\NhlGameImportRebuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Reseeds canonical NHL import progress rows after game-scoped rebuild cleanup.
 */
class SeedNhlGameImportProgressJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 900;

    /**
     * @param array{season_id:string,game_date:string,game_type:int|null} $context
     */
    public function __construct(
        public int $gameId,
        public ?int $runId,
        public array $context
    ) {
        $this->afterCommit = true;
    }

    public function uniqueId(): string
    {
        return (string) $this->gameId;
    }

    /**
     * @return array<int,string>
     */
    public function tags(): array
    {
        return [
            'nhl-game-import-rebuild-seed',
            "game-id:{$this->gameId}",
            'run-id:' . ($this->runId ?? 'none'),
        ];
    }

    public function handle(NhlGameImportRebuilder $rebuilder): void
    {
        broadcast(new NhlGameImportStatusUpdated('rebuild-seed-started', $this->runId, $this->gameId));

        $rebuilder->seedProgressRows($this->gameId, $this->context, $this->runId);

        DispatchNhlGameImportPipelineJob::dispatch($this->gameId, $this->runId);
        broadcast(new NhlGameImportStatusUpdated('rebuild-seed-completed', $this->runId, $this->gameId));
    }

    public function failed(Throwable $exception): void
    {
        Cache::lock($this->activeLockKey())->forceRelease();
    }

    private function activeLockKey(): string
    {
        return 'nhl-game-import-rebuild-active:' . $this->gameId;
    }
}
