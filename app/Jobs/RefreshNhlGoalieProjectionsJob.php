<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\NhlGoalieProjectionRefresh;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;
use Throwable;

/** Prepare a model-owned goalie rating snapshot after season sync. */
class RefreshNhlGoalieProjectionsJob implements ShouldQueue
{
    use Queueable;
    use InteractsWithQueue;

    public int $tries = 0;
    public int $timeout = 120;
    public int $deadline;

    /** @param array<string,mixed> $context */
    public function __construct(public array $context, public string $stage = 'ratings')
    {
        $this->onQueue('projections');
        $this->afterCommit = true;
        $this->deadline = now()->addDay()->timestamp;
    }

    /** Permit lock-contention releases without an unbounded retry window. */
    public function retryUntil(): \DateTimeInterface
    {
        return Carbon::createFromTimestampUTC($this->deadline);
    }

    /** Serialize redelivered coordinators as well as independent same-season refreshes. */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('nhl:goalie-refresh-stage:'.$this->context['version'].':'.$this->stage))
            ->releaseAfter(30)->expireAfter($this->timeout + 60)];
    }

    /** Start only when no other complete pipeline owns this target season. */
    public function handle(NhlGoalieProjectionRefresh $refresh): void
    {
        try {
            // Older serialized workload/performance coordinators use this same safe entrypoint.
            if (! $refresh->start($this->context)) {
                $this->release(60);
            }
        } catch (Throwable $exception) {
            $refresh->fail($this->context, $exception->getMessage());
            $this->fail($exception);
        }
    }

    /** Surface timeouts and exhausted retries in the originating season-sync record. */
    public function failed(Throwable $exception): void
    {
        app(NhlGoalieProjectionRefresh::class)->fail($this->context, $exception->getMessage());
    }

    /** @return array<int,string> */
    public function tags(): array
    {
        return ['nhl-goalie-refresh', 'season:'.$this->context['target'], 'stage:'.$this->stage];
    }
}
