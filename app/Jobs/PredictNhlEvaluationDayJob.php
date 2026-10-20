<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\NhlEvaluationGameForecast;
use App\Models\NhlGame;
use App\Models\NhlNextGameEvaluation;
use App\Services\NhlEvaluationGameForecaster;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Durable, bounded day coordinator; only one game player-chain runs at a time. */
class PredictNhlEvaluationDayJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 45;
    public bool $failOnTimeout = true;
    public array $backoff = [15, 45];

    /** Queue identifiers only; evaluation inputs own the frozen day selection. */
    public function __construct(public int $evaluationId, public string $token)
    {
        $this->onQueue('projections');
        $this->afterCommit = true;
    }

    /** Admit or observe one game, then yield rather than occupying a worker while it runs. */
    public function handle(NhlEvaluationGameForecaster $service): void
    {
        DB::transaction(function () use ($service): void {
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement("SET LOCAL statement_timeout = '10s'");
                DB::statement("SET LOCAL lock_timeout = '2s'");
            }
            $evaluation = NhlNextGameEvaluation::query()->lockForUpdate()->find($this->evaluationId);
            $day = $evaluation?->inputs['predict_day'] ?? null;
            if (! $day || $day['token'] !== $this->token || $day['status'] !== 'running') {
                return;
            }
            $delay = 0;
            $button = ($day['mode'] ?? 'day') === 'simulation' ? 'Simulate' : 'Predict Day';
            try {
                $active = NhlEvaluationGameForecast::query()->where('evaluation_id', $evaluation->id)
                    ->whereIn('status', NhlEvaluationGameForecast::ACTIVE)->first();
                if ($active) {
                    if ($active->canResume()) {
                        throw new RuntimeException('Game #'.$active->nhl_game_id.' has stalled. Resume that game, then press '.$button.' again.');
                    }
                    $delay = 30;
                } elseif (isset($day['game_ids'][$day['cursor']])) {
                    $work = $service->admit($evaluation, NhlGame::findOrFail($day['game_ids'][$day['cursor']]), $day['strength'], $day['period']);
                    if ($work->status === 'failed') {
                        throw new RuntimeException('Game #'.$work->nhl_game_id.' failed. Resume that game, then press '.$button.' again.');
                    }
                    if ($work->status === 'compared') {
                        $day['cursor']++;
                    } elseif ($work->status === 'ready') {
                        throw new RuntimeException('Game #'.$work->nhl_game_id.' has no saved prediction timestamp. Inspect this game before continuing.');
                    } else {
                        $delay = 30;
                    }
                }
                if ($day['cursor'] >= count($day['game_ids'])) {
                    $day['status'] = 'completed';
                }
            } catch (RuntimeException $exception) {
                $day['status'] = 'failed';
                $day['last_error'] = mb_substr($exception->getMessage(), 0, 1800);
            }
            $day['updated_at'] = now()->toISOString();
            $day['token'] = (string) Str::uuid();
            $evaluation->update(['inputs' => [...$evaluation->inputs, 'predict_day' => $day]]);
            if ($day['status'] === 'running') {
                self::dispatch($evaluation->id, $day['token'])
                    ->onConnection($this->job?->getConnectionName() ?? $this->connection)->delay($delay);
            }
        });
    }

    /** Ignore stale deliveries; expose queue failures without replacing saved forecasts. */
    public function failed(Throwable $exception): void
    {
        DB::transaction(function () use ($exception): void {
            $evaluation = NhlNextGameEvaluation::query()->lockForUpdate()->find($this->evaluationId);
            $day = $evaluation?->inputs['predict_day'] ?? null;
            if (! $day || $day['token'] !== $this->token || $day['status'] !== 'running') {
                return;
            }
            $day['status'] = 'failed';
            $day['last_error'] = mb_substr($exception->getMessage(), 0, 1800);
            $day['updated_at'] = now()->toISOString();
            $evaluation->update(['inputs' => [...$evaluation->inputs, 'predict_day' => $day]]);
        });
    }
}
