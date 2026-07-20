<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Performs the heavy game-scoped rebuild setup before the canonical NHL import jobs run.
 */
class RebuildNhlGameImportJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @var int
     */
    public int $tries = 3;

    /**
     * @var int
     */
    public int $timeout = 60;

    /**
     * @var int
     */
    public int $uniqueFor = 900;

    public function __construct(
        public int $gameId,
        public ?int $runId = null
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
            'nhl-game-import-rebuild',
            "game-id:{$this->gameId}",
            'run-id:' . ($this->runId ?? 'none'),
        ];
    }

    public function handle(): void
    {
        PreflightNhlGameImportRebuildJob::dispatch($this->gameId, $this->runId);
    }
}
