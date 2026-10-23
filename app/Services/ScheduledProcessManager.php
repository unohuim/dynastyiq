<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\NhlDiscoveryJob;
use App\Models\NhlGameImportRun;
use App\Models\NhlModelRun;
use App\Models\ScheduledProcess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Decide which allowlisted processes are due; never perform their heavy work. */
class ScheduledProcessManager
{
    public const NHL_DISCOVERY = 'nhl-game-discovery';
    public const NHL_PREGAME_CONTEXT_PREFIX = 'nhl-pregame-context-model-';

    /** Resolve the persisted process key for one model's isolated schedule. */
    public static function pregameContextKey(int $modelRunId): string
    {
        return self::NHL_PREGAME_CONTEXT_PREFIX . $modelRunId;
    }

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
                        ->whereIn('status', ['queued', 'running', 'pausing', 'paused', 'cancelling'])->exists()) {
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
                        'payload' => ['scheduled_process' => self::NHL_DISCOVERY, 'days_back' => $days, 'auto_process' => true],
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

            $this->dispatchDuePregameContextUpdates();
        } finally {
            $lock->release();
        }
    }

    /** Dispatch due model context refreshes through their existing bounded run pipeline. */
    private function dispatchDuePregameContextUpdates(): void
    {
        $processIds = ScheduledProcess::query()
            ->where('key', 'like', self::NHL_PREGAME_CONTEXT_PREFIX . '%')
            ->where('enabled', true)
            ->orderBy('next_due_at')
            ->orderBy('id')
            ->pluck('id');

        foreach ($processIds as $processId) {
            try {
                $this->dispatchPregameContextUpdate((int) $processId);
            } catch (Throwable $exception) {
                $process = ScheduledProcess::query()->find($processId);
                if ($process !== null) {
                    $now = CarbonImmutable::now('UTC');
                    $process->update([
                        'last_error' => mb_substr($exception->getMessage(), 0, 1000),
                        'next_due_at' => $this->nextDue($process, $now),
                    ]);
                }
                report($exception);
            }
        }
    }

    /** Queue one scheduled model refresh and advance its due time only after admission. */
    private function dispatchPregameContextUpdate(int $processId): void
    {
        DB::transaction(function () use ($processId): void {
            $process = ScheduledProcess::query()->lockForUpdate()->find($processId);
            $now = CarbonImmutable::now('UTC');
            if ($process === null || ! $process->enabled || ! $this->due($process, $now)
                || ! (bool) data_get($process->settings, 'include_update_pregame', true)) {
                return;
            }

            $modelId = $this->pregameModelId($process->key);
            $model = $modelId === null ? null : NhlModelRun::query()
                ->where('model_family', NhlModelRun::FAMILY_SAT)
                ->where('workflow_stage', NhlModelRun::STAGE_TRAINING)
                ->find($modelId);
            if ($model === null) {
                $process->update([
                    'enabled' => false,
                    'last_error' => 'The scheduled SAT model no longer exists.',
                ]);

                return;
            }

            $build = app(NhlPregameContextOrchestrator::class)->queueModelBackfill(
                $model,
                null,
                ['trigger' => 'auto_sync', 'scheduled_process_key' => $process->key, 'only_missing' => true],
                false,
            );
            if ($build === null) {
                return;
            }

            $process->update([
                'last_dispatched_at' => $now,
                'next_due_at' => $this->nextDue($process, $now),
                'last_error' => null,
            ]);
        });
    }

    /** Parse only numeric model keys from this process's dedicated allowlist. */
    private function pregameModelId(string $key): ?int
    {
        $suffix = substr($key, strlen(self::NHL_PREGAME_CONTEXT_PREFIX));

        return ctype_digit($suffix) ? (int) $suffix : null;
    }

    /** Initial schedules wait for today's configured Toronto start time. */
    public function due(ScheduledProcess $process, CarbonImmutable $now): bool
    {
        return $process->next_due_at
            ? $now->greaterThanOrEqualTo($process->next_due_at)
            : $now->greaterThanOrEqualTo($now->setTimezone($process->timezone)->setTimeFromTimeString($process->start_time));
    }

    /** Day-based frequencies retain local wall-clock time across DST. */
    public function nextDue(ScheduledProcess $process, CarbonImmutable $now): CarbonImmutable
    {
        if ($process->frequency_hours >= 24 && $process->frequency_hours % 24 === 0) {
            return $now->setTimezone($process->timezone)
                ->addDays(intdiv($process->frequency_hours, 24))
                ->setTimeFromTimeString($process->start_time)
                ->utc();
        }

        return $now->addHours($process->frequency_hours)->utc();
    }
}
