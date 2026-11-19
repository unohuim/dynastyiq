<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\NhlSeasonGameEvaluation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** Continue a bounded, isolated historical replay without occupying one worker for a season. */
class RunNhlSeasonGameEvaluationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 0;
    public int $maxExceptions = 3;
    public int $timeout = 120;
    public int $backoff = 15;
    public string $deadline;

    /** Only the run identity travels through the queue; saved results survive missing checkpoints. */
    public function __construct(public int $runId, public ?string $generation = null)
    {
        $this->onQueue('projections');
        $this->afterCommit = true;
        $this->deadline = now()->addDay()->toIso8601String();
    }

    /** Release between bounded steps, retaining a fixed retry deadline. */
    public function handle(NhlSeasonGameEvaluation $evaluations): void
    {
        if ($this->generation !== null && Cache::get('nhl:season-evaluation:'.$this->runId.':generation') !== $this->generation) {
            return;
        }
        if (! $evaluations->advance($this->runId)) {
            $this->release(1);
        }
    }

    /** Queue restarts cannot extend the job's recovery window indefinitely. */
    public function retryUntil(): \DateTimeInterface
    {
        return new \DateTimeImmutable($this->deadline);
    }

    /** Expose a terminal error while preserving all successfully saved games. */
    public function failed(?Throwable $exception): void
    {
        if ($this->generation !== null && Cache::get('nhl:season-evaluation:'.$this->runId.':generation') !== $this->generation) {
            return;
        }
        app(NhlSeasonGameEvaluation::class)->fail($this->runId, $exception?->getMessage() ?? 'Season evaluation timed out. Resume this run.');
    }
}
