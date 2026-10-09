<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\NhlDiscoveryJob;
use App\Models\NhlGameImportRun;
use App\Models\ScheduledProcess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Decide which allowlisted processes are due; never perform their heavy work. */
class ScheduledProcessManager
{
    public const NHL_DISCOVERY = 'nhl-game-discovery';

    /** Dispatch at most one due occurrence per process, without catching up bursts. */
    public function tick(): void
    {
        $lock = Cache::lock('scheduled-processes:dispatch', 60);
        if (! $lock->get()) {
            return;
        }
        try {
            $run = null;
            try {
                DB::transaction(function () use (&$run): void {
                    $process = ScheduledProcess::query()->where('key', self::NHL_DISCOVERY)->lockForUpdate()->first();
                    $now = CarbonImmutable::now('UTC');
                    if (! $process || ! $process->enabled || ! $this->due($process, $now)) {
                        return;
                    }
                    // Do not start another automatic run while its predecessor is active.
                    if (NhlGameImportRun::query()->where('payload->scheduled_process', self::NHL_DISCOVERY)
                        ->whereIn('status', ['queued', 'running'])->exists()) {
                        return;
                    }
                    $today = $now->setTimezone($process->timezone)->startOfDay();
                    $days = (int) $process->settings['days_back'];
                    $start = $today->subDay()->toDateString();
                    $end = $today->subDays($days)->toDateString();
                    $run = NhlGameImportRun::query()->create([
                        'action' => 'discover', 'mode' => 'range', 'status' => 'queued',
                        'start_date' => $start, 'end_date' => $end, 'date_count' => $days,
                        'queued_jobs' => $days, 'created_by' => null,
                        'payload' => ['scheduled_process' => self::NHL_DISCOVERY, 'days_back' => $days],
                    ]);
                    $process->update(['last_dispatched_at' => $now, 'next_due_at' => $this->nextDue($process, $now),
                        'last_error' => null]);
                    NhlDiscoveryJob::dispatch($start, $end, $run->id)->afterCommit();
                });
            } catch (Throwable $exception) {
                // Surface publish failures rather than reporting a successful dispatch.
                if ($run?->exists) {
                    $run->update(['status' => 'failed']);
                }
                ScheduledProcess::query()->where('key', self::NHL_DISCOVERY)
                    ->update(['last_error' => mb_substr($exception->getMessage(), 0, 1000)]);
                report($exception);
            }
        } finally {
            $lock->release();
        }
    }

    /** Initial schedules wait for today's configured Toronto start time. */
    public function due(ScheduledProcess $process, CarbonImmutable $now): bool
    {
        return $process->next_due_at
            ? $now->greaterThanOrEqualTo($process->next_due_at)
            : $now->greaterThanOrEqualTo($now->setTimezone($process->timezone)->setTimeFromTimeString($process->start_time));
    }

    /** Daily schedules retain wall-clock time across DST; other intervals use hours. */
    public function nextDue(ScheduledProcess $process, CarbonImmutable $now): CarbonImmutable
    {
        return $process->frequency_hours === 24
            ? $now->setTimezone($process->timezone)->addDay()->setTimeFromTimeString($process->start_time)->utc()
            : $now->addHours($process->frequency_hours)->utc();
    }
}
