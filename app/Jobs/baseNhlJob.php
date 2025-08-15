<?php

declare(strict_types=1);

namespace App\Jobs;

use Throwable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Services\NhlImportOrchestrator;

/**
 * Abstract base for NHL pipeline jobs (pbp, summary, shifts, boxscore, unit-shifts, connect-events).
 */
abstract class BaseNhlJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var int */
    public int $gameId;

    /** @var int */
    public int $tries = 5;

    /** @var array<int,int> */
    public array $backoff = [60, 120, 300, 600];

    /** @var int */
    public int $timeout = 600;

    public function __construct(int $gameId)
    {
        $this->gameId = $gameId;
    }

    /**
     * Return stage key: 'pbp', 'summary', 'shifts', 'boxscore', 'unit-shifts', or 'connect-events'.
     */
    abstract protected function stageName(): string;

    /**
     * Execute work for this stage and return processed item count.
     */
    abstract protected function perform(int $gameId): int;

    /**
     * Template handle: guard → perform → report.
     */
    public function handle(NhlImportOrchestrator $orchestrator): void
    {
        if (! $orchestrator->onRunning($this->gameId, $this->stageName())) {
            return;
        }

        try {
            $count = $this->perform($this->gameId);

            $orchestrator->onSuccess($this->gameId, $this->stageName(), [
                'items_count' => $count,
            ]);

            return;
        } catch (Throwable $e) {
            app(NhlImportOrchestrator::class)->onFailure(
                $this->gameId,
                $this->stageName(),
                $e->getMessage(),
                $e->getCode()
            );

            $this->fail($e);
        }
    }

    /**
     * Queue/Horizon tags.
     *
     * @return array<int,string>
     */
    public function tags(): array
    {
        return [
            'nhl-import' . $this->stageName(),
            "game-id:{$this->gameId}",
        ];
    }
}
