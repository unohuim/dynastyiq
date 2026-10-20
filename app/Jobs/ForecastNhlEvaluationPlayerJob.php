<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\NhlEvaluationGameForecast;
use App\Services\NhlEvaluationGameForecaster;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** One player checkpoint per job on projections, with fenced resumable handoff. */
class ForecastNhlEvaluationPlayerJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 45;
    public bool $failOnTimeout = true;
    public array $backoff = [15, 45];

    /** Queue only identifiers; the durable record owns the frozen lineup and inputs. */
    public function __construct(public int $forecastId, public string $workToken)
    {
        $this->onQueue('projections');
        $this->afterCommit = true;
    }

    /** Atomically commit one player and rejoin the queue tail without fan-out. */
    public function handle(NhlEvaluationGameForecaster $service): void
    {
        $next = null;
        try {
            $next = DB::transaction(function () use ($service): ?string {
                if (DB::connection()->getDriverName() === 'pgsql') {
                    DB::statement("SET LOCAL statement_timeout = '10s'");
                    DB::statement("SET LOCAL lock_timeout = '2s'");
                }
                $work = NhlEvaluationGameForecast::query()->lockForUpdate()->find($this->forecastId);
                if (! $work || $work->work_token !== $this->workToken || ! in_array($work->status, NhlEvaluationGameForecast::ACTIVE, true)) {
                    return null;
                }
                $work->update(['status' => $work->phase === 'compare' ? 'comparing' : 'running', 'last_error' => null]);
                $service->advance($work);
                if (! in_array($work->status, NhlEvaluationGameForecast::ACTIVE, true)) {
                    return null;
                }
                $token = (string) Str::uuid();
                $work->update(['work_token' => $token]);

                return $token;
            });
            if ($next !== null) {
                self::dispatch($this->forecastId, $next)->onConnection($this->job?->getConnectionName() ?? $this->connection);
            }
        } catch (Throwable $exception) {
            if ($next !== null) {
                $this->recordFailure($exception, $next);
            }
            throw $exception;
        }
    }

    /** A late failure must not fail a resumed or already-completed checkpoint. */
    public function failed(Throwable $exception): void
    {
        $this->recordFailure($exception, $this->workToken);
    }

    /** Expose failed work without discarding committed player forecasts. */
    private function recordFailure(Throwable $exception, string $token): void
    {
        NhlEvaluationGameForecast::query()->whereKey($this->forecastId)->where('work_token', $token)
            ->whereIn('status', NhlEvaluationGameForecast::ACTIVE)
            ->update(['status' => 'failed', 'last_error' => mb_substr($exception->getMessage(), 0, 1800)]);
    }
}
