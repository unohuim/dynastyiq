<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\NhlNextGameEvaluation;
use App\Services\NhlNextGameEvaluationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** Executes one checkpoint, never a season or an entire game's player history. */
class EvaluateNhlNextGameJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 45;
    public bool $failOnTimeout = true;
    public array $backoff = [15, 45];

    // A declared default also supports already-serialized legacy jobs.
    public ?string $workToken = null;

    /** Dispatch only IDs and a checkpoint token, never hydrated profiles. */
    public function __construct(public int $evaluationId, ?string $workToken = null)
    {
        $this->workToken = $workToken;
        $this->afterCommit = true;
    }

    /** Publish activity first, then atomically commit one bounded output and cursor. */
    public function handle(NhlNextGameEvaluationService $service): void
    {
        $nextToken = null;
        try {
            $claimed = DB::transaction(function (): bool {
                $this->limitQueries();
                $evaluation = NhlNextGameEvaluation::query()->lockForUpdate()->find($this->evaluationId);
                if (! $evaluation?->acceptsWork($this->workToken)) {
                    return false;
                }
                $inputs = $evaluation->inputs ?? [];
                $inputs['_work']['stage'] ??= isset($inputs['baseline_frozen_at']) ? 'evaluate' : 'initialize';
                $inputs['_work']['started_at'] = now()->toIso8601String();
                $evaluation->update([
                    'status' => $inputs['_work']['stage'] === 'evaluate' ? 'running' : 'preparing',
                    'inputs' => $inputs,
                    'last_error' => null,
                ]);

                return true;
            });
            if (! $claimed) {
                return;
            }
            $nextToken = DB::transaction(function () use ($service): ?string {
                $this->limitQueries();
                $evaluation = NhlNextGameEvaluation::query()->lockForUpdate()->find($this->evaluationId);
                if (! $evaluation?->acceptsWork($this->workToken)) {
                    return null;
                }
                $service->advance($evaluation);
                $inputs = $evaluation->inputs;
                $inputs['_work']['token'] = (string) Str::uuid();
                $inputs['_work']['checkpoints_completed'] = (int) ($inputs['_work']['checkpoints_completed'] ?? 0) + 1;
                $inputs['_work']['checkpoint_at'] = now()->toIso8601String();
                unset($inputs['_work']['started_at']);
                $evaluation->update(['inputs' => $inputs, 'last_error' => null]);

                return $evaluation->status === 'completed' ? null : $inputs['_work']['token'];
            });
            if ($nextToken !== null) {
                // Rejoin the queue tail: no fan-out, and other work gets the worker back.
                self::dispatch($this->evaluationId, $nextToken);
            }
        } catch (Throwable $exception) {
            if ($nextToken !== null) {
                // Output committed, but publishing its successor failed. Surface recovery now.
                $this->recordError($exception, true, $nextToken);
            } else {
                $this->recordError($exception, false);
            }
            throw $exception;
        }
    }

    /** Bound database waits before the worker's hard timeout; settings end at commit. */
    private function limitQueries(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL statement_timeout = '10s'");
            DB::statement("SET LOCAL lock_timeout = '2s'");
        }
    }

    /** Persist a terminal failure only if this is still the current checkpoint. */
    public function failed(Throwable $exception): void
    {
        $this->recordError($exception, true);
    }

    /** Late failures from an old delivery must not fail a resumed evaluation. */
    private function recordError(Throwable $exception, bool $terminal, ?string $token = null): void
    {
        DB::transaction(function () use ($exception, $terminal, $token): void {
            $this->limitQueries();
            $evaluation = NhlNextGameEvaluation::query()->lockForUpdate()->find($this->evaluationId);
            if (! $evaluation?->acceptsWork($token ?? $this->workToken)) {
                return;
            }
            $evaluation->update([
                'status' => $terminal ? 'failed' : $evaluation->status,
                'last_error' => ($terminal ? '' : 'Retry pending: ') . mb_substr($exception->getMessage(), 0, 1800),
            ]);
        });
    }
}
