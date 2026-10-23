<?php

declare(strict_types=1);

namespace App\Jobs\Middleware;

use App\Exceptions\NhlGameImportRunCancelled;
use App\Services\NhlGameImportRunControl;
use Closure;
use Illuminate\Support\Facades\DB;

/** Claim run work and fence its database commit against Cancel and stale jobs. */
class ControlNhlGameImportRun
{
    /** Allow active claims to finish during Pause, and roll back cancelled claims. */
    public function handle(object $job, Closure $next): void
    {
        if ($job->runId === null) {
            $next($job);
            return;
        }

        $control = app(NhlGameImportRunControl::class);
        $key = $control->workKey($job);
        $token = $control->claim($job->runId, $job->runGeneration, $key, (int) ($job->timeout ?? 600));
        if ($token === null) {
            return;
        }

        try {
            DB::transaction(function () use ($job, $next, $control, $key, $token): void {
                $next($job);
                $successful = ! isset($job->job) || (! $job->job->isReleased() && ! $job->job->hasFailed());
                $control->finish($job->runId, $job->runGeneration, $key, $token, $successful);
            });
        } catch (NhlGameImportRunCancelled) {
            // Cancellation is an operator action, not a queue failure.
        } finally {
            $control->release($job->runId, $job->runGeneration, $token);
        }
    }
}
