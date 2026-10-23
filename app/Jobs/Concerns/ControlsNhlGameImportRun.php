<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

use App\Services\NhlGameImportRunControl;

/** Stamp queued work so old jobs cannot revive a cancelled and restarted run. */
trait ControlsNhlGameImportRun
{
    public string $runGeneration = 'initial';

    /** Capture the generation when a run-owned job is created. */
    protected function captureRunGeneration(): void
    {
        if ($this->runId !== null) {
            $this->runGeneration = app(NhlGameImportRunControl::class)->generationFor($this->runId);
        }
    }

    /** Suppress obsolete queue failure callbacks after cancellation or an explicit rerun. */
    protected function canReportRunFailure(): bool
    {
        if ($this->runId === null) {
            return true;
        }
        $run = \App\Models\NhlGameImportRun::query()->find($this->runId);
        return $run !== null && ! in_array($run->status, ['cancelling', 'cancelled'], true)
            && app(NhlGameImportRunControl::class)->generationFor($this->runId) === $this->runGeneration;
    }
}
