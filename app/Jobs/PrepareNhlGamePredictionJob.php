<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\NhlCustomGameForecast;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Advance one bounded preparation checkpoint, sharing work across a model's date. */
class PrepareNhlGamePredictionJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 0;
    public int $maxExceptions = 3;
    public int $timeout = 120;
    public int $backoff = 30;
    public string $deadline;

    /** Capture identities only; prepared source state stays in the bounded shared cache. */
    public function __construct(
        public int $gameId,
        public int $modelId,
        public bool $storedBoxscore = false,
        public array $rosterOverrides = [],
    ) {
        $this->onQueue('projections');
        $this->afterCommit = true;
        $this->deadline = now()->addDay()->toIso8601String();
    }

    /** Pending dependencies release without consuming an exception budget. */
    public function handle(NhlCustomGameForecast $forecast): void
    {
        if (! $forecast->advancePreparation($this->gameId, $this->modelId, $this->storedBoxscore, $this->rosterOverrides)) {
            $this->release($forecast->preparationDelay());
        }
    }

    /** Bound waiting and worker-recovery lifetime independently from delivery attempts. */
    public function retryUntil(): \DateTimeInterface
    {
        return new \DateTimeImmutable($this->deadline);
    }

    /** Surface terminal errors instead of leaving API consumers preparing forever. */
    public function failed(?Throwable $error): void
    {
        app(NhlCustomGameForecast::class)->preparationFailed(
            $this->gameId, $this->modelId, $this->storedBoxscore, $this->rosterOverrides,
            $error?->getMessage() ?? 'Prediction preparation failed.',
        );
    }
}
