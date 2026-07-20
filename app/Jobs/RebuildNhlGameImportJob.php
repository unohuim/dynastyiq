<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\NhlGameImportRebuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

/**
 * Performs the heavy game-scoped rebuild setup before the canonical NHL import jobs run.
 */
class RebuildNhlGameImportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @var int
     */
    public int $tries = 1;

    /**
     * @var int
     */
    public int $timeout = 600;

    public function __construct(
        public int $gameId,
        public ?int $runId = null
    ) {
        $this->afterCommit = true;
    }

    /**
     * Prevent concurrent rebuild setup for the same NHL game.
     *
     * @return array<int,WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('nhl-game-import-rebuild:' . $this->gameId))
                ->expireAfter(900),
        ];
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

    public function handle(NhlGameImportRebuilder $rebuilder): void
    {
        $rebuilder->rebuild($this->gameId, $this->runId);
    }
}
