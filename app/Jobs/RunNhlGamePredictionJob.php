<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\NhlGamePredictionPreparing;
use App\Services\NhlCustomGameForecast;
use App\Services\NhlGamePredictionHistory;
use App\Services\NhlGamePredictionPayload;
use App\Services\NhlGamePredictionResponseCache;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Advance and publish one game's calculation without joining a date-wide preparation dependency. */
final class RunNhlGamePredictionJob implements ShouldQueue
{
    use Batchable;
    use Queueable;

    public int $tries = 0;
    public int $maxExceptions = 3;
    public ?string $deadline = null;
    public int $timeout = 120;
    public bool $failOnTimeout = true;
    public int $generation = 1;
    public array $backoff = [10, 30, 60];

    /** Persist a generation in each delivery so recovery makes abandoned deliveries harmless. */
    public function __construct(public readonly int $itemId, int $generation = 1)
    {
        $this->generation = $generation;
        $this->onQueue('projections');
        $this->afterCommit = true;
        $this->deadline = now()->addDay()->toIso8601String();
    }

    /** Finish one bounded checkpoint; ready games proceed directly to calculation and publication. */
    public function handle(NhlGamePredictionPayload $builder, NhlGamePredictionResponseCache $cache, NhlGamePredictionHistory $history): void
    {
        $token = (string) Str::uuid();
        $item = $history->claim($this->itemId, $this->generation, $token);
        if ($item === null) {
            $current = DB::table('nhl_game_prediction_run_games')->find($this->itemId);
            if ($current !== null && (int) $current->generation === $this->generation
                && in_array($current->status, ['queued', 'running'], true)) {
                $this->release(15);
            }
            return;
        }
        $saved = DB::table('nhl_game_prediction_revisions')->where('run_id', $item->run_id)
            ->where('nhl_game_id', $item->nhl_game_id)->first();
        if ($saved !== null) {
            $history->finish($this->itemId, $saved->prediction_available ? 'completed' : 'unavailable', (int) $saved->id, generation: $this->generation, claimToken: $token);
            return;
        }
        $input = $item->request_inputs === null ? ['nhl_game_id' => (int) $item->nhl_game_id]
            : json_decode($item->request_inputs, true, 512, JSON_THROW_ON_ERROR);
        try {
            $forecast = app(NhlCustomGameForecast::class);
            $game = DB::table('nhl_games')->where('nhl_game_id', $item->nhl_game_id)->first();
            $model = $forecast->model(isset($input['sat_model_run_id']) ? (int) $input['sat_model_run_id'] : null, $game->season_id);
            $history->progress($this->itemId, $token, $item->phase === 'queued' ? 'preparing' : $item->phase, (int) $item->progress_current);
            if (! $forecast->advancePreparation((int) $item->nhl_game_id, (int) $model->id, (bool) ($input['use_stored_boxscore'] ?? false))) {
                $step = $forecast->preparationProgress((int) $item->nhl_game_id, (int) $model->id, (bool) ($input['use_stored_boxscore'] ?? false));
                $history->progress($this->itemId, $token, $step['phase'], $step['cursor'], $step['total'] ?? null, true);
                $this->release($forecast->preparationDelay());
                return;
            }
            $history->progress($this->itemId, $token, 'calculating');
            $payload = $cache->respond((int) $item->nhl_game_id, $input,
                fn (): array => $builder->build((int) $item->nhl_game_id, $input), true, (int) $item->run_id,
                $this->itemId, $this->generation, $token);
            if (! $history->owns($this->itemId, $this->generation, $token)) {
                return;
            }
            $history->finish($this->itemId, ($payload['prediction_available'] ?? false) ? 'completed' : 'unavailable',
                (int) $payload['meta']['prediction_revision_id'], generation: $this->generation, claimToken: $token);
        } catch (NhlGamePredictionPreparing $pending) {
            // Matchup facts can prepare independently; no new prediction job or slate is admitted here.
            $history->progress($this->itemId, $token, $pending->phase, $pending->progressCurrent ?? 0, $pending->progressTotal, true);
            $this->release($pending->continuationSeconds);
        } catch (ValidationException $error) {
            if (! $history->owns($this->itemId, $this->generation, $token)) {
                return;
            }
            $history->finish($this->itemId, 'failed', error: $error->getMessage(), generation: $this->generation, claimToken: $token);
        } catch (Throwable $error) {
            $history->progress($this->itemId, $token, 'retrying', release: true);
            DB::table('nhl_game_prediction_run_games')->where('id', $this->itemId)->where('generation', $this->generation)
                ->whereIn('status', ['queued', 'running'])->update(['last_error' => mb_substr($error->getMessage(), 0, 2000), 'failures' => DB::raw('failures + 1')]);
            throw $error;
        }
    }

    /** Keep a finite recovery lifetime independently of the number of successful checkpoint deliveries. */
    public function retryUntil(): \DateTimeInterface
    {
        return new \DateTimeImmutable($this->deadline ?? now()->addDay()->toIso8601String());
    }

    /** A terminal delivery releases lineup protection and preserves an already committed revision. */
    public function failed(?Throwable $error): void
    {
        $item = DB::table('nhl_game_prediction_run_games')->find($this->itemId);
        if ($item === null || (int) $item->generation !== $this->generation) {
            return;
        }
        $saved = DB::table('nhl_game_prediction_revisions')->where('run_id', $item->run_id)
            ->where('nhl_game_id', $item->nhl_game_id)->first();
        app(NhlGamePredictionHistory::class)->finish($this->itemId,
            $saved === null ? 'failed' : ($saved->prediction_available ? 'completed' : 'unavailable'),
            $saved === null ? null : (int) $saved->id,
            $saved === null ? mb_substr($error?->getMessage() ?? 'Prediction job failed.', 0, 2000) : null,
            generation: $this->generation);
    }
}
