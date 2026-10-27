<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\GamesDiscovered;
use App\Events\NhlGameImportStatusUpdated;
use App\Exceptions\NhlGameImportRunCancelled;
use App\Jobs\BuildNhlFaceoffFactsJob;
use App\Jobs\BuildNhlShotAttemptFactsJob;
use App\Jobs\NhlDiscoveryJob;
use App\Jobs\RefreshNhlGameContextJob;
use App\Models\NhlGameImportRun;
use Closure;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Durable controls and commit fencing shared by discovery and game import jobs. */
class NhlGameImportRunControl
{
    public const CONTROLLED = ['pausing', 'paused', 'cancelling', 'cancelled'];

    /** Resolve the persisted generation, retaining compatibility with existing queued jobs. */
    public function generationFor(int $runId): string
    {
        return $this->generation(NhlGameImportRun::query()->find($runId));
    }

    /** Stable identity for a discovery date, stage, or scoped game's work. */
    public function workKey(object $job): string
    {
        return $this->workType(get_class($job)).':'.($job->date ?? $job->gameId ?? $job->nhlGameId ?? 'coordinator');
    }

    /** Allow only actively running or queued runs to acquire additional work. */
    public function canAdvance(?int $runId): bool
    {
        if ($runId === null) {
            return true;
        }
        $run = NhlGameImportRun::query()->find($runId);
        return $run !== null && in_array($run->status, ['queued', 'running'], true)
            && ! $this->signalled($runId, $this->generation($run));
    }

    /** Atomically admit work before any provider call or database transformation. */
    public function claim(int $runId, string $generation, string $key, int $timeout): ?string
    {
        return $this->metadataTransaction(function () use ($runId, $generation, $key, $timeout): ?string {
            $run = NhlGameImportRun::query()->lock('for no key update')->find($runId);
            if ($run === null || $this->generation($run) !== $generation
                || ! in_array($run->status, ['queued', 'running'], true) || $this->signalled($runId, $generation)) {
                return null;
            }
            $payload = $run->payload ?? [];
            if (isset($payload['run_control']['completed'][$key]) && ! str_ends_with($key, ':coordinator')) {
                return null;
            }
            $token = (string) Str::uuid();
            $payload['run_control']['active'][$token] = ['key' => $key, 'expires' => now()->timestamp + $timeout + 120];
            $run->update(['payload' => $payload]);
            return $token;
        });
    }

    /** Fence the final commit, retaining successful work as a Resume checkpoint. */
    public function finish(int $runId, string $generation, string $key, string $token, bool $successful): void
    {
        $run = NhlGameImportRun::query()->lock('for no key update')->find($runId);
        if ($run === null || $this->generation($run) !== $generation || in_array($run->status, ['cancelling', 'cancelled'], true)
            || $this->signalled($runId, $generation)) {
            throw new NhlGameImportRunCancelled();
        }
        $payload = $run->payload ?? [];
        unset($payload['run_control']['active'][$token]);
        // Stages and discovery dates already have durable progress rows; only scoped work needs extra keys.
        if ($successful && in_array(strtok($key, ':'), ['shots', 'faceoffs', 'refs_staff', 'on_ice'], true)) {
            $payload['run_control']['completed'][$key] = true;
        }
        $run->update(['payload' => $payload, 'status' => $this->settledStatus($run, $payload)]);
    }

    /** Release a claim after errors or cancellation without reopening a terminal run. */
    public function release(int $runId, string $generation, string $token): void
    {
        $this->metadataTransaction(function () use ($runId, $generation, $token): void {
            $run = NhlGameImportRun::query()->lock('for no key update')->find($runId);
            if ($run === null || $this->generation($run) !== $generation) {
                return;
            }
            $payload = $run->payload ?? [];
            if (! isset($payload['run_control']['active'][$token])) {
                return;
            }
            unset($payload['run_control']['active'][$token]);
            $run->update(['payload' => $payload, 'status' => $this->settledStatus($run, $payload)]);
        });
    }

    /** Pause admission while previously admitted work drains. */
    public function pause(int $runId): NhlGameImportRun
    {
        $run = $this->metadataTransaction(function () use ($runId): NhlGameImportRun {
            $run = $this->controllable($runId);
            if (! in_array($run->status, ['queued', 'running'], true)) {
                throw ValidationException::withMessages(['run' => 'Only queued or running runs can be paused.']);
            }
            $payload = $run->payload ?? [];
            $payload['run_control']['active'] = $this->active($payload);
            $run->update(['status' => $payload['run_control']['active'] === [] ? 'paused' : 'pausing', 'payload' => $payload]);
            return $run;
        });
        broadcast(new NhlGameImportStatusUpdated('run-paused', $runId));
        return $run;
    }

    /** Fence active writes and make queued work harmless, preserving completed results. */
    public function cancel(int $runId): NhlGameImportRun
    {
        $existing = NhlGameImportRun::query()->findOrFail($runId);
        if (! in_array($existing->action, ['discover', 'process'], true)
            || ! in_array($existing->status, ['queued', 'running', 'pausing', 'paused', 'cancelling', 'cancelled'], true)) {
            throw ValidationException::withMessages(['run' => 'Only active or paused runs can be cancelled.']);
        }
        try {
            Cache::put($this->signalKey($runId, $this->generation($existing)), true, 86400);
        } catch (Throwable) {
            // The durable cancelled state remains the commit fence when cache is unavailable.
        }
        $run = $this->metadataTransaction(function () use ($runId): NhlGameImportRun {
            $run = $this->controllable($runId);
            if ($run->status === 'cancelled') {
                return $run;
            }
            if (! in_array($run->status, ['queued', 'running', 'pausing', 'paused', 'cancelling'], true)) {
                throw ValidationException::withMessages(['run' => 'Only active or paused runs can be cancelled.']);
            }
            $payload = $run->payload ?? [];
            $payload['run_control']['active'] = [];
            unset($payload['run_control']['resume_pending']);
            $run->update(['status' => 'cancelling', 'payload' => $payload]);
            return $run;
        });
        // Never hold the parent metadata lock while waiting for a worker's progress rows.
        $this->metadataTransaction(function () use ($run): void {
            $this->progressForGeneration($run, 'cancelling')->whereIn('status', ['scheduled', 'running'])
                ->update(['status' => 'skipped', 'last_error' => 'Cancelled by operator.', 'updated_at' => now()]);
        });
        $run = $this->metadataTransaction(function () use ($run, $runId): NhlGameImportRun {
            $current = $this->controllable($runId);
            if ($current->status === 'cancelling' && $this->generation($current) === $this->generation($run)) {
                $current->update(['status' => 'cancelled']);
            }
            return $current;
        });
        foreach ($this->batchKeys() as $key) {
            if ($id = data_get($run->payload, $key)) {
                Bus::findBatch($id)?->cancel();
            }
        }
        broadcast(new NhlGameImportStatusUpdated('run-cancelled', $runId));
        return $run;
    }

    /** Resume only unfinished work; obsolete queued jobs retain their old generation. */
    public function resume(int $runId): NhlGameImportRun
    {
        $resumedBatch = false;
        $run = $this->metadataTransaction(function () use ($runId): NhlGameImportRun {
            $run = $this->controllable($runId);
            $payload = $run->payload ?? [];
            if (! in_array($run->status, ['pausing', 'paused'], true) || $this->active($payload) !== []
                || ! empty($payload['run_control']['resume_pending'])) {
                throw ValidationException::withMessages(['run' => 'Wait until claimed work finishes before resuming a paused run.']);
            }
            $payload['run_control']['active'] = [];
            $payload['run_control']['generation'] = (string) Str::uuid();
            $payload['run_control']['resume_pending'] = true;
            $run->update(['status' => 'paused', 'payload' => $payload]);
            return $run;
        });
        $generation = $this->generation($run);
        try {
            $this->metadataTransaction(function () use ($run): void {
                $this->progressForGeneration($run, 'paused')->where('status', 'running')
                    ->update(['status' => 'scheduled', 'updated_at' => now()]);
            });
            $run = $this->metadataTransaction(function () use ($runId, $generation, &$resumedBatch): NhlGameImportRun {
                $run = $this->controllable($runId);
                if ($run->status !== 'paused' || $this->generation($run) !== $generation
                    || empty($run->payload['run_control']['resume_pending']) || $this->signalled($runId, $generation)) {
                    throw ValidationException::withMessages(['run' => 'This run changed while Resume was being prepared.']);
                }
                $payload = $run->payload ?? [];
                unset($payload['run_control']['resume_pending']);
                $run->update(['status' => 'running', 'payload' => $payload]);
                // Only batch metadata and queue admission remain under this short parent lock.
                $resumedBatch = $this->resumeBatch($run);
                return $run;
            }, false);
        } catch (Throwable $exception) {
            $this->metadataTransaction(function () use ($runId, $generation): void {
                $current = $this->controllable($runId);
                if ($this->generation($current) === $generation && $current->status === 'paused') {
                    $payload = $current->payload ?? [];
                    unset($payload['run_control']['resume_pending']);
                    $current->update(['payload' => $payload]);
                }
            });
            throw $exception;
        }
        $payload = $run->payload ?? [];
        if ($run->action === 'discover' && empty($payload['processing_started_at'])) {
            if (count($payload['discovery_completed_dates'] ?? []) >= $run->date_count) {
                if ($this->saveWorkerRun($run, ['status' => 'completed'])) {
                    GamesDiscovered::dispatch($runId);
                }
            } else {
                NhlDiscoveryJob::dispatch($run->start_date, $run->end_date, $runId);
            }
        } elseif ($resumedBatch) {
            // The original batch retains its callbacks and queue.
        } else {
            app(NhlImportOrchestrator::class)->fillActiveGameSlotsForRun($runId);
        }
        broadcast(new NhlGameImportStatusUpdated('run-resumed', $runId));
        return $run->fresh();
    }

    /** Save callback progress without overwriting Pause, Cancel, or a newer run generation. */
    public function saveWorkerRun(NhlGameImportRun $snapshot, array $updates): bool
    {
        return $this->metadataTransaction(function () use ($snapshot, $updates): bool {
            $run = NhlGameImportRun::query()->lock('for no key update')->find($snapshot->id);
            if ($run === null || in_array($run->status, ['cancelling', 'cancelled'], true)
                || $this->generation($run) !== $this->generation($snapshot)) {
                return false;
            }
            if (isset($updates['payload'])) {
                $updates['payload']['run_control'] = $run->payload['run_control'] ?? [];
            }
            if (in_array($run->status, ['pausing', 'paused'], true)) {
                $updates['status'] = $run->status;
            }
            $run->forceFill($updates)->save();
            return true;
        });
    }

    /** Ignore callbacks from replaced batches or batches with resumed work still pending. */
    public function acceptsBatchCallback(?NhlGameImportRun $run, string $key, string $batchId, bool $completed = false): bool
    {
        if ($run === null || in_array($run->status, ['cancelling', 'cancelled'], true)) {
            return false;
        }
        $current = data_get($run->payload, $key);
        if ($completed && in_array($run->status, ['pausing', 'paused'], true) && ! $this->batchWorkComplete($run, $key)) {
            return false;
        }
        return ($current === null || $current === $batchId)
            && (! $completed || (Bus::findBatch($batchId)?->pendingJobs ?? 0) === 0);
    }

    /** Start an explicit rerun with a new generation and no stale control checkpoints. */
    public function freshPayload(array $payload): array
    {
        foreach (array_keys($payload) as $key) {
            if (str_starts_with($key, 'post_process_enrichment_')) {
                unset($payload[$key]);
            }
        }
        unset($payload['completed_at'], $payload['overlap_excluded_games']);
        $payload['run_control'] = ['generation' => (string) Str::uuid()];
        return $payload;
    }

    /** Resolve Pausing to Paused when no live claims remain, including after a worker timeout. */
    public function settledStatus(NhlGameImportRun $run, ?array $payload = null): string
    {
        return $run->status === 'pausing' && $this->active($payload ?? $run->payload ?? []) === [] ? 'paused' : $run->status;
    }

    /** Requeue remaining scoped or post-processing jobs into their original batch. */
    private function resumeBatch(NhlGameImportRun $run): bool
    {
        $payload = $run->payload ?? [];
        $scope = $payload['process_scope'] ?? null;
        $jobs = [];
        $definitions = [
            'shots' => ['shot_fact_game_ids', 'shot_fact_batch_id', BuildNhlShotAttemptFactsJob::class],
            'faceoffs' => ['faceoff_fact_game_ids', 'faceoff_fact_batch_id', BuildNhlFaceoffFactsJob::class],
            'refs_staff' => ['refs_staff_game_ids', 'refs_staff_batch_id', RefreshNhlGameContextJob::class],
            'on_ice' => ['on_ice_game_ids', 'on_ice_batch_id', \App\Jobs\RebuildNhlOnIceStatsJob::class],
        ];
        if (isset($definitions[$scope])) {
            [$gameKey, $batchKey, $class] = $definitions[$scope];
            foreach ($payload[$gameKey] ?? [] as $gameId) {
                $jobs[] = new $class((int) $gameId, $run->id);
            }
        } elseif (! empty($payload['post_process_enrichment_batch_id']) && empty($payload['post_process_enrichment_completed_at'])) {
            $batchKey = 'post_process_enrichment_batch_id';
            foreach (DB::table('nhl_import_progress')->where('run_id', $run->id)->distinct()->pluck('game_id') as $gameId) {
                foreach (array_intersect_key($definitions, array_flip(['shots', 'faceoffs', 'refs_staff'])) as [, , $class]) {
                    $jobs[] = new $class((int) $gameId, $run->id);
                }
            }
        } else {
            return false;
        }
        $jobs = array_values(array_filter($jobs, fn ($job): bool => ! isset($payload['run_control']['completed'][$this->workKey($job)])));
        if ($jobs === []) {
            if ($scope === 'on_ice') {
                app(NhlGameImportRebuilder::class)->finishOnIceRun($run->id, $payload['on_ice_batch_id'] ?? '');
                return true;
            }
            $run->update(['status' => 'completed']);
            return true;
        }
        $batch = Bus::findBatch($payload[$batchKey] ?? '');
        if ($batch === null || $batch->cancelled() || $batch->failedJobs > 0) {
            $run->update(['status' => 'paused']);
            throw ValidationException::withMessages(['run' => 'This batch is unavailable or has failed. Cancel this run and rerun it.']);
        }
        $batch->add($jobs);
        return true;
    }

    /** Load only the supported discovery and game-processing run types under lock. */
    private function controllable(int $runId): NhlGameImportRun
    {
        $run = NhlGameImportRun::query()->lock('for no key update')->findOrFail($runId);
        if (! in_array($run->action, ['discover', 'process'], true)) {
            throw ValidationException::withMessages(['run' => 'Controls apply to discovery and game import runs.']);
        }
        return $run;
    }

    /** Drop abandoned claims once their job timeout and lock grace have elapsed. */
    private function active(array $payload): array
    {
        return array_filter($payload['run_control']['active'] ?? [], fn (array $claim): bool => $claim['expires'] > now()->timestamp);
    }

    /** Read the generation without treating ordinary progress updates as a new run. */
    private function generation(?NhlGameImportRun $run): string
    {
        return (string) data_get($run?->payload, 'run_control.generation', 'initial');
    }

    /** A fast signal allows an active transaction to roll back before Cancel waits for its row lock. */
    private function signalled(int $runId, string $generation): bool
    {
        try {
            return (bool) Cache::get($this->signalKey($runId, $generation), false);
        } catch (Throwable) {
            return false;
        }
    }

    /** Scope cancellation to one incarnation of one run. */
    private function signalKey(int $runId, string $generation): string
    {
        return 'nhl-import-cancel:'.$runId.':'.$generation;
    }

    /** Known child batches belonging to supported processing scopes. */
    private function batchKeys(): array
    {
        return ['shot_fact_batch_id', 'faceoff_fact_batch_id', 'refs_staff_batch_id', 'on_ice_batch_id', 'post_process_enrichment_batch_id'];
    }

    /** Dropped paused jobs do not count as completed source work when a batch drains. */
    private function batchWorkComplete(NhlGameImportRun $run, string $batchKey): bool
    {
        $payload = $run->payload ?? [];
        $definitions = [
            'shot_fact_batch_id' => ['shot_fact_game_ids', BuildNhlShotAttemptFactsJob::class],
            'faceoff_fact_batch_id' => ['faceoff_fact_game_ids', BuildNhlFaceoffFactsJob::class],
            'refs_staff_batch_id' => ['refs_staff_game_ids', RefreshNhlGameContextJob::class],
            'on_ice_batch_id' => ['on_ice_game_ids', \App\Jobs\RebuildNhlOnIceStatsJob::class],
        ];
        if ($batchKey === 'post_process_enrichment_batch_id') {
            $ids = DB::table('nhl_import_progress')->where('run_id', $run->id)->distinct()->pluck('game_id')->all();
            foreach (array_diff_key($definitions, ['on_ice_batch_id' => true]) as [, $class]) {
                foreach ($ids as $gameId) {
                    if (! isset($payload['run_control']['completed'][$this->workType($class).':'.$gameId])) {
                        return false;
                    }
                }
            }
            return true;
        }
        if (! isset($definitions[$batchKey])) {
            return false;
        }
        [$gameKey, $class] = $definitions[$batchKey];
        foreach ($payload[$gameKey] ?? [] as $gameId) {
            if (! isset($payload['run_control']['completed'][$this->workType($class).':'.$gameId])) {
                return false;
            }
        }
        return true;
    }

    /** Keep durable per-game checkpoint keys compact for season-sized imports. */
    private function workType(string $class): string
    {
        return match ($class) {
            BuildNhlShotAttemptFactsJob::class => 'shots',
            BuildNhlFaceoffFactsJob::class => 'faceoffs',
            RefreshNhlGameContextJob::class => 'refs_staff',
            \App\Jobs\RebuildNhlOnIceStatsJob::class => 'on_ice',
            default => $class,
        };
    }

    /** Filter cleanup by lifecycle and generation without locking the parent row. */
    private function progressForGeneration(NhlGameImportRun $run, string $status): \Illuminate\Database\Query\Builder
    {
        $generation = $this->generation($run);
        return DB::table('nhl_import_progress')->where('run_id', $run->id)
            ->whereExists(function ($query) use ($run, $status, $generation): void {
                $query->selectRaw('1')->from('nhl_game_import_runs as controlled')
                    ->whereColumn('controlled.id', 'nhl_import_progress.run_id')
                    ->where('controlled.id', $run->id)->where('controlled.status', $status)
                    ->whereRaw("COALESCE(controlled.payload->'run_control'->>'generation', 'initial') = ?", [$generation]);
            });
    }

    /** Retry short bookkeeping only; an enclosing worker/provider transaction is never replayed here. */
    private function metadataTransaction(Closure $callback, bool $retry = true): mixed
    {
        return DB::transaction($callback, $retry && DB::transactionLevel() === 0 ? 3 : 1);
    }
}
