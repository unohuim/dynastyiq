<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\RunNhlGamePredictionJob;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Append immutable default-path calculations and expose compact admin comparison rows. */
final class NhlGamePredictionHistory
{
    /** Admit manual or automatic work through the same durable run and publication pathway.
     * @param array<string,mixed> $automation Internal trigger evidence; empty for manual runs.
     * @return array{run_id:int,total_games:int}|null Null means no automatic work or an active overlap.
     */
    public function queueRun(string $start, string $end, ?int $createdBy, array $automation = []): ?array
    {
        $model = app(NhlCustomGameForecast::class)->model();
        $definition = ['algorithm' => config('nhl-custom-prediction'), 'model_run_id' => $model->id];
        if ($automation !== []) {
            $definition['automation'] = $automation;
        }
        return DB::transaction(function () use ($start, $end, $createdBy, $automation, $definition): ?array {
            $games = DB::table('nhl_games')->whereBetween('game_date', [$start, $end])->whereIn('game_type', [1, 2, 3])
                ->when($automation !== [], fn ($query) => $query->whereIn('game_state', ['FUT', 'PRE']))
                ->orderBy('nhl_game_id')->lockForUpdate()->pluck('nhl_game_id');
            if ($games->isEmpty()) {
                if ($automation !== []) {
                    return null;
                }
                throw ValidationException::withMessages(['start_date' => 'No stored NHL games were found for these dates.']);
            }
            if ($automation !== []) {
                $active = DB::table('nhl_game_prediction_run_games')->whereIn('nhl_game_id', $games)
                    ->whereIn('status', ['queued', 'running'])->exists();
                $apiActive = $games->contains(fn ($id): bool => collect(Cache::get('nhl:prediction-input-writers:'.$id, []))
                    ->contains(fn ($expires): bool => $expires > now()->timestamp));
                if ($active || $apiActive) {
                    return null;
                }
            }
            $runId = DB::table('nhl_game_prediction_runs')->insertGetId([
                'start_date' => $start, 'end_date' => $end, 'status' => 'queued', 'total_games' => $games->count(),
                'requested_stack' => json_encode($definition, JSON_THROW_ON_ERROR), 'created_by' => $createdBy,
                'trigger_source' => $automation === [] ? 'admin' : 'automation',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('nhl_game_prediction_run_games')->insert($games->map(fn ($id): array => [
                'run_id' => $runId, 'nhl_game_id' => $id, 'status' => 'queued',
                'heartbeat_at' => now(), 'dispatched_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ])->all());
            $itemIds = DB::table('nhl_game_prediction_run_games')->where('run_id', $runId)->pluck('id');
            DB::afterCommit(function () use ($itemIds, $runId): void {
                $this->notify($runId);
                try {
                    Bus::batch($itemIds->map(fn ($id) => new RunNhlGamePredictionJob((int) $id))->all())
                        ->name('NHL game predictions #'.$runId)->onQueue('projections')->allowFailures()->dispatch();
                } catch (Throwable $error) {
                    foreach ($itemIds as $id) {
                        $this->finish((int) $id, 'failed', error: 'Unable to queue prediction: '.mb_substr($error->getMessage(), 0, 1500));
                    }
                    report($error);
                }
            });

            return ['run_id' => $runId, 'total_games' => $games->count()];
        });
    }

    /** Admit background work before expensive reads; repeated requests share the same game/variant item.
     * @param array<string,mixed> $input
     */
    public function queueRefresh(int $gameId, array $input, string $trigger = 'api', bool $dispatch = true): int
    {
        return DB::transaction(function () use ($gameId, $input, $trigger, $dispatch): int {
            $game = DB::table('nhl_games')->where('nhl_game_id', $gameId)->lockForUpdate()->first();
            abort_if($game === null, 404);
            $normalized = $input;
            $normalized['nhl_game_id'] = $gameId;
            ksort($normalized);
            $existing = DB::table('nhl_game_prediction_run_games')->where('nhl_game_id', $gameId)
                ->whereIn('status', ['queued', 'running'])->orderBy('id')->get()->first(function ($row) use ($normalized, $gameId): bool {
                    $saved = $row->request_inputs === null ? ['nhl_game_id' => $gameId] : json_decode($row->request_inputs, true, 512, JSON_THROW_ON_ERROR);
                    $saved['nhl_game_id'] = $gameId;
                    ksort($saved);
                    return $saved === $normalized;
                });
            if ($existing !== null) {
                return (int) $existing->id;
            }
            $runId = DB::table('nhl_game_prediction_runs')->insertGetId([
                'start_date' => $game->game_date, 'end_date' => $game->game_date, 'total_games' => 1,
                'status' => 'queued', 'trigger_source' => $trigger, 'requested_stack' => '{}', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $id = DB::table('nhl_game_prediction_run_games')->insertGetId([
                'run_id' => $runId, 'nhl_game_id' => $gameId, 'status' => 'queued', 'phase' => 'queued',
                'request_inputs' => json_encode($input, JSON_THROW_ON_ERROR),
                'heartbeat_at' => now(), 'dispatched_at' => $dispatch ? now() : null, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::afterCommit(function () use ($id, $gameId, $input, $runId, $dispatch, $trigger): void {
                $this->notify($runId, $gameId);
                if (! $dispatch) {
                    return;
                }
                try {
                    \App\Jobs\RefreshNhlGamePredictionJob::dispatch($gameId, $input, '', $id, 1)->onQueue('projections');
                } catch (Throwable $error) {
                    $this->finish($id, 'failed', error: 'Unable to queue prediction: '.$error->getMessage());
                    report($error);
                    throw new \Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException(15,
                        $trigger === 'lineup' ? 'The lineup was saved, but its prediction refresh could not be queued.' : 'Unable to queue prediction.');
                }
            });

            return $id;
        });
    }

    /** Fence duplicate or obsolete deliveries and serialize only workers for this game.
     * @return object|null
     */
    public function claim(int $itemId, int $generation, string $token): ?object
    {
        return DB::transaction(function () use ($itemId, $generation, $token): ?object {
            $item = DB::table('nhl_game_prediction_run_games')->find($itemId);
            if ($item === null) {
                return null;
            }
            DB::table('nhl_games')->where('nhl_game_id', $item->nhl_game_id)->lockForUpdate()->first();
            DB::table('nhl_game_prediction_runs')->where('id', $item->run_id)->lockForUpdate()->first();
            $item = DB::table('nhl_game_prediction_run_games')->where('id', $itemId)->lockForUpdate()->first();
            if ((int) $item->generation !== $generation || ! in_array($item->status, ['queued', 'running'], true)
                || $item->claim_token !== null) {
                return null;
            }
            $other = DB::table('nhl_game_prediction_run_games')->where('nhl_game_id', $item->nhl_game_id)
                ->where('id', '<', $itemId)->whereIn('status', ['queued', 'running'])->exists();
            if ($other) {
                return null;
            }
            DB::table('nhl_game_prediction_run_games')->where('id', $itemId)->update([
                'status' => 'running', 'started_at' => $item->started_at ?? now(), 'heartbeat_at' => now(),
                'claim_token' => $token, 'claim_expires_at' => now()->addSeconds(180),
                'attempts' => (int) $item->attempts + 1, 'last_error' => null, 'updated_at' => now(),
            ]);
            DB::table('nhl_game_prediction_runs')->where('id', $item->run_id)->where('status', 'queued')
                ->update(['status' => 'running', 'started_at' => now(), 'updated_at' => now()]);
            DB::afterCommit(function () use ($item): void {
                $input = $item->request_inputs === null ? ['nhl_game_id' => (int) $item->nhl_game_id]
                    : json_decode($item->request_inputs, true, 512, JSON_THROW_ON_ERROR);
                app(NhlGamePredictionResponseCache::class)->refreshFailed((int) $item->nhl_game_id, $input);
                $this->notify((int) $item->run_id, (int) $item->nhl_game_id);
            });

            return DB::table('nhl_game_prediction_run_games')->find($itemId);
        });
    }

    /** Persist a bounded step's progress and release only its own worker claim. */
    public function progress(int $itemId, string $token, string $phase, int $cursor = 0, ?int $total = null, bool $release = false): void
    {
        $query = DB::table('nhl_game_prediction_run_games')->where('id', $itemId)->where('claim_token', $token)
            ->whereIn('status', ['queued', 'running']);
        $item = (clone $query)->first();
        if ($item === null) {
            return;
        }
        $values = ['phase' => $phase, 'progress_current' => $cursor, 'progress_total' => $total,
            'heartbeat_at' => now(), 'claim_expires_at' => now()->addSeconds(180), 'updated_at' => now()];
        if ($item->phase !== $phase || (int) $item->progress_current !== $cursor) {
            $values['last_progress_at'] = now();
        }
        if ($release) {
            $values += ['status' => 'queued', 'claim_token' => null];
            $values['claim_expires_at'] = null;
        }
        if ($query->update($values)) {
            $this->notify((int) $item->run_id, (int) $item->nhl_game_id);
        }
    }

    /** Reject a worker whose claim has expired or was replaced, including publication after recovery. */
    public function owns(int $itemId, int $generation, string $token): bool
    {
        return DB::table('nhl_game_prediction_run_games')->where('id', $itemId)->where('generation', $generation)
            ->where('claim_token', $token)->where('claim_expires_at', '>', now())
            ->where('status', 'running')->exists();
    }

    /** Requeue abandoned deliveries without waiting for Redis's long shared retry_after. */
    public function recoverActiveGames(): void
    {
        DB::table('nhl_game_prediction_run_games')->whereIn('status', ['queued', 'running'])
            ->whereRaw('COALESCE(heartbeat_at, updated_at) < ?', [now()->subMinutes(5)])
            ->orderBy('id')->chunkById(100, function ($items): void {
                foreach ($items as $candidate) {
                    DB::transaction(function () use ($candidate): void {
                        DB::table('nhl_games')->where('nhl_game_id', $candidate->nhl_game_id)->lockForUpdate()->first();
                        DB::table('nhl_game_prediction_runs')->where('id', $candidate->run_id)->lockForUpdate()->first();
                        $item = DB::table('nhl_game_prediction_run_games')->where('id', $candidate->id)->lockForUpdate()->first();
                        if (! in_array($item->status, ['queued', 'running'], true)
                            || ($item->heartbeat_at ?? $item->updated_at) >= now()->subMinutes(5)->toDateTimeString()) {
                            return;
                        }
                        $saved = DB::table('nhl_game_prediction_revisions')->where('run_id', $item->run_id)
                            ->where('nhl_game_id', $item->nhl_game_id)->first();
                        if ($saved !== null) {
                            $this->finish((int) $item->id, $saved->prediction_available ? 'completed' : 'unavailable', (int) $saved->id);
                            return;
                        }
                        if ((int) $item->recoveries >= 3) {
                            $this->finish((int) $item->id, 'failed', error: 'Prediction worker stopped reporting progress after three recoveries. Retry this game.');
                            return;
                        }
                        $generation = (int) $item->generation + 1;
                        DB::table('nhl_game_prediction_run_games')->where('id', $item->id)->update([
                            'status' => 'queued', 'generation' => $generation, 'recoveries' => (int) $item->recoveries + 1,
                            'claim_token' => null, 'claim_expires_at' => null, 'heartbeat_at' => now(), 'dispatched_at' => now(), 'updated_at' => now(),
                            'last_error' => 'Recovering an interrupted worker from its last saved checkpoint.',
                        ]);
                        DB::afterCommit(function () use ($item, $generation): void {
                            try {
                                RunNhlGamePredictionJob::dispatch((int) $item->id, $generation);
                                $this->notify((int) $item->run_id, (int) $item->nhl_game_id);
                            } catch (Throwable $error) {
                                $this->finish((int) $item->id, 'failed', error: $error->getMessage());
                                report($error);
                            }
                        });
                    });
                }
            });
    }

    /** Broadcast identifiers only; failed Reverb delivery cannot fail calculation or publication. */
    private function notify(int $runId, ?int $gameId = null): void
    {
        try {
            event(new \App\Events\NhlGamePredictionStatusUpdated($runId, $gameId));
        } catch (Throwable $error) {
            report($error);
        }
    }

    /** Read the latest published prediction for one game without refreshing any dependencies.
     * @return array<string,mixed>|null
     */
    public function latestSaved(int $gameId): ?array
    {
        $revision = DB::table('nhl_game_prediction_revisions')
            ->where('nhl_game_id', $gameId)->where('prediction_available', true)
            ->orderByDesc('id')->first();

        return $revision === null ? null : $this->payload($revision);
    }

    /** Read public activity without opening checkpoints, calculating inputs, or admitting work.
     * @return array<string,mixed>
     */
    public function predictionStatus(int $gameId, ?int $revisionId = null): array
    {
        $item = DB::table('nhl_game_prediction_run_games as work')
            ->join('nhl_game_prediction_runs as parent', 'parent.id', '=', 'work.run_id')
            ->where('work.nhl_game_id', $gameId)->orderByDesc('work.id')
            ->first(['work.id', 'work.run_id', 'work.status', 'work.phase', 'work.progress_current', 'work.progress_total',
                'work.attempts', 'work.failures', 'work.recoveries', 'work.created_at', 'work.started_at',
                'work.finished_at', 'work.heartbeat_at', 'parent.trigger_source']);
        $saved = DB::table('nhl_game_prediction_revisions')->where('nhl_game_id', $gameId)
            ->where('prediction_available', true)->when($revisionId !== null, fn ($query) => $query->where('id', $revisionId))
            ->orderByDesc('id')->first(['id', 'run_id', 'predicted_at']);
        // A newer legacy publication can exist without a corresponding tracked work item.
        if ($item !== null && $saved !== null && ! in_array($item->status, ['queued', 'running'], true)
            && $item->finished_at !== null && $saved->predicted_at > $item->finished_at
            && (int) $saved->run_id !== (int) $item->run_id) {
            $item = null;
        }
        $status = $item?->status ?? ($saved === null ? 'queued' : 'completed');
        $active = in_array($status, ['queued', 'running'], true);
        $stage = $item === null ? $status : ($active ? $item->phase : $status);
        $total = $item?->progress_total === null ? null : (int) $item->progress_total;
        $processed = (int) ($item?->progress_current ?? 0);
        $progress = $active && $total !== null && $total > 0
            ? round(min(100.0, max(0.0, 100 * $processed / $total)), 2) : null;
        $iso = static fn (?string $at): ?string => $at === null ? null
            : \Illuminate\Support\Carbon::parse($at, 'UTC')->toIso8601String();
        $completedAt = $status === 'completed' ? ($item === null ? $saved?->predicted_at : $item->finished_at) : null;
        $elapsed = $item === null || (! $active && $item->finished_at === null) ? null
            : max(0, ($item->finished_at === null ? now()->timestamp
                : \Illuminate\Support\Carbon::parse($item->finished_at, 'UTC')->timestamp)
                - \Illuminate\Support\Carbon::parse($item->created_at, 'UTC')->timestamp);

        return [
            'nhl_game_id' => $gameId, 'attempt_id' => $item === null ? null : (int) $item->id,
            'run_id' => $item === null ? null : (int) $item->run_id,
            'status' => $status, 'stage' => $stage, 'trigger_source' => $item?->trigger_source,
            'processed_count' => $processed, 'total_count' => $total,
            'progress_scope' => 'stage', 'stage_progress_percent' => $progress,
            'progress_indeterminate' => $active && $progress === null,
            'deliveries' => (int) ($item?->attempts ?? 0), 'retries' => (int) ($item?->failures ?? 0),
            'recoveries' => (int) ($item?->recoveries ?? 0), 'elapsed_seconds' => $elapsed,
            'queued_at' => $iso($item?->created_at), 'started_at' => $iso($item?->started_at),
            'last_activity_at' => $iso($item?->heartbeat_at), 'finished_at' => $iso($item?->finished_at),
            'completed_at' => $iso($completedAt),
            'prediction_revision_id' => $saved === null ? null : (int) $saved->id,
            'prediction_completed_at' => $iso($saved?->predicted_at),
        ];
    }

    /** Persist once per manual run/game; independent API recalculations always append.
     * @param array<string,mixed> $input
     * @param array<string,mixed> $evidence
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function record(int $gameId, array $input, string $fingerprint, array $evidence, array $payload, ?int $runId = null): array
    {
        if (DB::transactionLevel() === 0) {
            return DB::transaction(fn () => $this->record($gameId, $input, $fingerprint, $evidence, $payload, $runId));
        }
        if (isset($payload['_custom_prediction_evidence'])) {
            $evidence['custom_prediction'] = $payload['_custom_prediction_evidence'];
            unset($payload['_custom_prediction_evidence']);
        }
        $existing = $runId === null ? null : DB::table('nhl_game_prediction_revisions')
            ->where('run_id', $runId)->where('nhl_game_id', $gameId)->first();
        if ($existing !== null) {
            return $this->payload($existing);
        }
        // Market selection changes pricing output, not the core matchup. Explicit roster/model overrides remain separate variants.
        $canonical = array_diff(array_keys($input), ['nhl_game_id', 'markets', 'puckline', 'puckline_spreads', 'total', 'total_lines']) === [];
        $at = now();
        $date = DB::table('nhl_games')->where('nhl_game_id', $gameId)->value('game_date');
        $daysAway = $date === null ? null : app(NhlGamePredictionPayload::class)->predictionDaysAway(
            (string) $date, null, $gameId,
            is_numeric(data_get($payload, 'inputs.sat_model_run_id')) ? (int) $payload['inputs']['sat_model_run_id'] : null,
            data_get($evidence, 'custom_prediction.input.source_cutoff_at')
        );
        $publicConfidence = null;
        if (is_array($payload['prediction'] ?? null)) {
            $publicConfidence = app(NhlGamePredictionPayload::class)->publicConfidenceScore($payload['prediction'], $daysAway);
            $payload['prediction']['days_away'] = $daysAway;
            $payload['prediction']['public_confidence_score'] = $publicConfidence;
        }
        if (($payload['prediction_available'] ?? false) === true) {
            $pages = app(NhlPublicGamePage::class);
            $analysis = $pages->capturePlayerAnalysis($gameId, $payload, $evidence['custom_prediction'] ?? [], $at->toIso8601String());
            $evidence['player_pregame_analysis'] = $analysis;
            $payload['player_pregame'] = $pages->playerPregamePayload($gameId, $analysis);
        }
        $id = DB::table('nhl_game_prediction_revisions')->insertGetId([
            'nhl_game_id' => $gameId, 'run_id' => $runId, 'source' => $runId === null || ! in_array(DB::table('nhl_game_prediction_runs')->where('id', $runId)->value('trigger_source'), ['admin', 'automation'], true) ? 'api' : 'admin',
            'fingerprint' => $fingerprint, 'is_canonical' => $canonical,
            'prediction_available' => ($payload['prediction_available'] ?? false) === true,
            'days_away' => $daysAway, 'public_confidence_score' => $publicConfidence,
            'request_inputs' => json_encode($input, JSON_THROW_ON_ERROR),
            'evidence' => json_encode($evidence, JSON_THROW_ON_ERROR),
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'summary' => json_encode($this->metrics($payload), JSON_THROW_ON_ERROR), 'predicted_at' => $at,
        ]);
        if ($canonical && ($payload['prediction_available'] ?? false) === true) {
            app(PredictionWebhookDelivery::class)->stage($id);
            app(NhlPublicGamePage::class)->refreshAfterCommit($gameId);
        }
        if (($payload['prediction_available'] ?? false) === true
            && data_get($payload, 'inputs.context.refresh_needed', false)
            && is_numeric(data_get($payload, 'inputs.sat_model_run_id'))) {
            $modelId = (int) $payload['inputs']['sat_model_run_id'];
            // A failed context refresh cannot undo an already-published prediction.
            DB::afterCommit(function () use ($gameId, $modelId, $id, $date): void {
                try {
                    \App\Jobs\UpdateNhlPredictionPregameContextJob::dispatch($gameId, $modelId, $id)
                        ->delay($date > now('America/Toronto')->toDateString()
                            ? CarbonImmutable::parse($date, 'America/Toronto')->startOfDay() : now());
                } catch (Throwable $error) {
                    report($error);
                }
            });
        }
        $payload['meta']['prediction_revision_id'] = $id;
        $payload['meta']['predicted_at'] = $at->toIso8601String();
        if (isset($payload['player_pregame'])) {
            $payload['player_pregame']['prediction_revision_id'] = $id;
            $payload['player_pregame']['predicted_at'] = $payload['meta']['predicted_at'];
        }

        return $payload;
    }

    /** Restore a revision without recalculating or modifying its stored inputs.
     * @return array<string,mixed>
     */
    public function payload(object $revision): array
    {
        $payload = json_decode($revision->payload, true, 512, JSON_THROW_ON_ERROR);
        if (is_array($payload['prediction'] ?? null)) {
            $payload['prediction']['days_away'] = isset($revision->days_away) ? (int) $revision->days_away : null;
            $payload['prediction']['public_confidence_score'] = isset($revision->public_confidence_score)
                ? (float) $revision->public_confidence_score : null;
        }
        $payload['meta']['prediction_revision_id'] = (int) $revision->id;
        $payload['meta']['predicted_at'] = \Illuminate\Support\Carbon::parse($revision->predicted_at)->toIso8601String();

        if (($payload['prediction_available'] ?? false) === true) {
            if (! is_array($payload['player_pregame'] ?? null)) {
                $pages = app(NhlPublicGamePage::class);
                $payload['player_pregame'] = $pages->playerPregamePayload((int) $revision->nhl_game_id,
                    $pages->savedPlayerAnalysis((int) $revision->id));
            }
            $payload['player_pregame']['prediction_revision_id'] = (int) $revision->id;
            $payload['player_pregame']['predicted_at'] = $payload['meta']['predicted_at'];
        }

        return NhlGamePredictionPayload::synchronizePredictionLineups($payload);
    }

    /** Summarize a stored calculation with separate confidence and moneyline probability.
     * @return array<string,mixed>
     */
    public function summary(object $revision): array
    {
        return [
            'id' => (int) $revision->id, 'source' => $revision->source, 'run_id' => $revision->run_id,
            'predicted_at' => \Illuminate\Support\Carbon::parse($revision->predicted_at)->toIso8601String(),
            'prediction_available' => (bool) $revision->prediction_available,
            'is_canonical' => (bool) $revision->is_canonical,
            ...json_decode($revision->summary, true, 512, JSON_THROW_ON_ERROR),
        ];
    }

    /** Normalize small read-side metrics without loading full evidence in polling responses.
     * @param array<string,mixed> $payload @return array<string,mixed>
     */
    private function metrics(array $payload): array
    {
        $winner = data_get($payload, 'prediction.winner.team_abbrev');
        $moneyline = collect($payload['market_probabilities'] ?? [])->first(fn (array $market): bool =>
            ($market['market_key'] ?? null) === 'moneyline' && ($market['team_abbrev'] ?? null) === $winner);

        return [
            'away_goals' => data_get($payload, 'prediction.predicted_score.away'),
            'home_goals' => data_get($payload, 'prediction.predicted_score.home'),
            'total_goals' => data_get($payload, 'prediction.predicted_total_goals'),
            'totals_normalization' => data_get($payload, 'prediction.totals_normalization'),
            'winner' => $winner, 'win_probability' => $moneyline['probability'] ?? null,
            'confidence' => data_get($payload, 'prediction.confidence_score'),
            'days_away' => data_get($payload, 'prediction.days_away'),
            'public_confidence_score' => data_get($payload, 'prediction.public_confidence_score'),
            'qualified' => $payload['pick_qualified'] ?? null,
            'algorithm_version' => data_get($payload, 'inputs.algorithm_version'),
            'engine_id' => data_get($payload, 'inputs.engine_id'), 'stack_id' => data_get($payload, 'inputs.stack_id'),
            'reason' => $payload['reason'] ?? null,
        ];
    }

    /** Finish one work item and derive run status while serializing concurrent completion updates. */
    public function finish(
        int $itemId,
        string $status,
        ?int $revisionId = null,
        ?string $error = null,
        ?int $generation = null,
        ?string $claimToken = null,
    ): void {
        DB::transaction(function () use ($itemId, $status, $revisionId, $error, $generation, $claimToken): void {
            $item = DB::table('nhl_game_prediction_run_games')->find($itemId);
            if ($item === null) {
                return;
            }
            DB::table('nhl_games')->where('nhl_game_id', $item->nhl_game_id)->lockForUpdate()->first();
            DB::table('nhl_game_prediction_runs')->where('id', $item->run_id)->lockForUpdate()->first();
            $item = DB::table('nhl_game_prediction_run_games')->find($itemId);
            if (($generation !== null && (int) $item->generation !== $generation)
                || ($claimToken !== null && $item->claim_token !== $claimToken)) {
                return;
            }
            if (in_array($item->status, ['completed', 'unavailable', 'failed'], true)) {
                return;
            }
            DB::table('nhl_game_prediction_run_games')->where('id', $itemId)->update([
                'status' => $status, 'revision_id' => $revisionId, 'last_error' => $error,
                'finished_at' => now(), 'updated_at' => now(), 'phase' => $status,
                'claim_token' => null, 'claim_expires_at' => null, 'heartbeat_at' => now(),
            ]);
            DB::afterCommit(fn () => $this->notify((int) $item->run_id, (int) $item->nhl_game_id));
            $counts = DB::table('nhl_game_prediction_run_games')->where('run_id', $item->run_id)
                ->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
            if ((int) $counts->get('queued', 0) + (int) $counts->get('running', 0) === 0) {
                DB::table('nhl_game_prediction_runs')->where('id', $item->run_id)->update([
                    'status' => $counts->get('failed', 0) > 0 ? 'completed_with_errors' : 'completed',
                    'finished_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
    }
}
