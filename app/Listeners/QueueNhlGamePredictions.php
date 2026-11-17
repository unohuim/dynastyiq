<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\NhlPregameContextDayCompleted;
use App\Services\ScheduledProcessManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/** Admit event-driven forecasts separately from the context builder's committed work. */
final class QueueNhlGamePredictions implements ShouldQueue
{
    use InteractsWithQueue;

    public string $queue = 'projections';
    public int $tries = 0;
    public int $maxExceptions = 3;
    public int $timeout = 60;
    public int $backoff = 60;

    /** Disabled or time-based settings do not add context-triggered queue work. */
    public function shouldQueue(NhlPregameContextDayCompleted $event): bool
    {
        $process = app(ScheduledProcessManager::class)->predictionSettings();

        return $process->enabled && ($process->settings['trigger_mode'] ?? 'time') === 'event'
            && $event->gameDate === now('America/Toronto')->toDateString();
    }

    /** Deliveries expire once their game date has passed in Toronto. */
    public function retryUntil(): \DateTimeInterface
    {
        return \Carbon\CarbonImmutable::now('America/Toronto')->endOfDay();
    }

    /** Retry a busy slate without changing its model inputs or running in the builder. */
    public function handle(NhlPregameContextDayCompleted $event): void
    {
        if (! app(ScheduledProcessManager::class)->predictCompletedContextDay($event)) {
            $this->release(60);
        }
    }

    /** Preserve operational failure evidence without failing an already committed context build. */
    public function failed(NhlPregameContextDayCompleted $event, Throwable $error): void
    {
        \App\Models\ScheduledProcess::query()->where('key', ScheduledProcessManager::NHL_PREDICTIONS)
            ->update(['last_error' => mb_substr($error->getMessage(), 0, 1000)]);
    }
}
