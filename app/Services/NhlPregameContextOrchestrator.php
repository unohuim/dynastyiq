<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\BuildNhlPregameContextGameJob;
use App\Jobs\CompareNhlPregameContextRunDayJob;
use App\Jobs\BuildNhlPregameContextDayJob;
use App\Jobs\BuildNhlPregameContextDayBatchJob;
use App\Jobs\CalculateNhlPregameLeagueBatchJob;
use App\Jobs\CalculateNhlPregameContextRunDayLeagueAveragesJob;
use App\Jobs\CalculateNhlPregameContextLeagueMetricsJob;
use App\Jobs\LoadNhlPregameContextRunJob;
use App\Jobs\PrepareNhlPregameContextRunJob;
use App\Models\NhlModelRun;
use App\Models\NhlPregameContextRun;
use App\Models\NhlPregameContextRunGame;
use App\Models\NhlPregameContextRunDay;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Validation\ValidationException;

/** Coordinates durable, date-ordered pregame-context work without queue flooding. */
class NhlPregameContextOrchestrator
{
    public const RESULTS_PIPELINE = 'results_league_v2';

    public const CHECKPOINT_PIPELINE = 'results_league_v3';

    public const THREE_STAGE_PIPELINE = 'results_league_v1';

    private const DISPATCH_PAGE_SIZE = 12;

    /** Wake active checkpointed builds independently of their queue continuation chain. */
    public function recoverActiveRuns(): void
    {
        NhlPregameContextRun::query()
            ->whereIn('status', [NhlPregameContextRun::STATUS_QUEUED, NhlPregameContextRun::STATUS_RUNNING, NhlPregameContextRun::STATUS_PAUSING])
            ->where('options->pipeline_version', self::CHECKPOINT_PIPELINE)
            ->select(['id', 'status'])->chunkById(100, function (Collection $runs): void {
                foreach ($runs as $run) {
                    if ($run->status === NhlPregameContextRun::STATUS_QUEUED) {
                        PrepareNhlPregameContextRunJob::dispatch($run->id);
                    } else {
                        LoadNhlPregameContextRunJob::dispatch($run->id);
                    }
                }
            });
    }

    /** Keep a bounded wakeup even when a loader loses a lock or its queue delivery is interrupted. */
    private function scheduleRecovery(int $runId): void
    {
        if (! NhlPregameContextRun::query()->whereKey($runId)->whereIn('status', ['running', 'pausing'])->exists()) {
            return;
        }
        $key = 'pregame-recovery-watchdog:'.$runId;
        if (! Cache::add($key, true, 30)) {
            return;
        }
        try {
            LoadNhlPregameContextRunJob::dispatch($runId)->delay(now()->addSeconds(30));
        } catch (\Throwable $exception) {
            Cache::forget($key);

            throw $exception;
        }
    }

    /** Serialize a new build and preserve its run history. */
    public function queueBackfill(
        array $seasons,
        ?int $modelId = null,
        ?int $createdBy = null,
        array $runOptions = [],
        bool $rejectActive = true,
    ): ?NhlPregameContextRun {
        $lock = Cache::lock('nhl-pregame-context-admission', 30);
        if (! $lock->get()) {
            if ($rejectActive) {
                throw ValidationException::withMessages(['season_ids' => 'Another context build is being queued. Please retry.']);
            }

            return null;
        }

        try {
            return DB::transaction(function () use ($seasons, $modelId, $createdBy, $runOptions, $rejectActive): ?NhlPregameContextRun {
                $active = NhlPregameContextRun::query()
                    ->whereIn('status', [
                        NhlPregameContextRun::STATUS_QUEUED,
                        NhlPregameContextRun::STATUS_RUNNING,
                        NhlPregameContextRun::STATUS_PAUSING,
                        NhlPregameContextRun::STATUS_PAUSED,
                    ])
                    ->where('model_run_id', $modelId)
                    ->where(function ($query) use ($seasons): void {
                        foreach ($seasons as $season) {
                            $query->orWhereJsonContains('season_ids', $season);
                        }
                    })->exists();
                if ($active) {
                    if ($rejectActive) {
                        throw ValidationException::withMessages(['season_ids' => 'A context build is already active for one of these seasons.']);
                    }

                    return null;
                }

                $build = NhlPregameContextRun::query()->create([
                    'model_run_id' => $modelId,
                    'action' => NhlPregameContextRun::ACTION_BACKFILL,
                    'status' => NhlPregameContextRun::STATUS_QUEUED,
                    'season_ids' => $seasons,
                    'created_by' => $createdBy,
                    'options' => array_merge([
                        'context_version' => NhlPregameContextBuilder::VERSION,
                    ], $modelId === null ? [] : ['model_run_id' => $modelId], $runOptions),
                ]);
                PrepareNhlPregameContextRunJob::dispatch($build->id);

                return $build;
            });
        } finally {
            $lock->release();
        }
    }

    /** Queue the same Train-and-Test context build used by the model's manual action. */
    public function queueModelBackfill(
        NhlModelRun $model,
        ?int $createdBy = null,
        array $runOptions = [],
        bool $rejectActive = true,
    ): ?NhlPregameContextRun {
        $seasons = $model->pregameContextSeasonIds();
        if ($seasons === []) {
            if ($rejectActive) {
                throw ValidationException::withMessages(['season_ids' => 'This model has no seasons to build.']);
            }

            return null;
        }

        return $this->queueBackfill($seasons, $model->id, $createdBy, array_merge($runOptions, [
            'train_season_ids' => array_map('strval', $model->train_season_ids ?? []),
            'pipeline_version' => self::CHECKPOINT_PIPELINE,
        ]), $rejectActive);
    }

    /** Stop claiming pages, then pause once the already-claimed work is terminal. */
    public function pauseModelBackfill(NhlModelRun $model): NhlPregameContextRun
    {
        $run = $this->latestModelRun($model);
        $status = DB::transaction(function () use ($run): string {
            $fresh = NhlPregameContextRun::query()->lock(DB::getDriverName() === 'pgsql' ? 'for no key update' : true)->findOrFail($run->id);
            if ($fresh->status === NhlPregameContextRun::STATUS_QUEUED) {
                $fresh->update(['status' => NhlPregameContextRun::STATUS_PAUSED]);
            } elseif ($fresh->status === NhlPregameContextRun::STATUS_RUNNING) {
                $fresh->update(['status' => NhlPregameContextRun::STATUS_PAUSING]);
            } elseif (! in_array($fresh->status, [
                NhlPregameContextRun::STATUS_PAUSING,
                NhlPregameContextRun::STATUS_PAUSED,
            ], true)) {
                throw ValidationException::withMessages(['pregame' => 'This Pregame build cannot be paused in its current state.']);
            }

            return (string) $fresh->fresh()->status;
        });

        if ($status === NhlPregameContextRun::STATUS_PAUSING) {
            LoadNhlPregameContextRunJob::dispatch($run->id);
        }

        return $run->fresh();
    }

    /** Resume a paused run from its durable game work rows. */
    public function resumeModelBackfill(NhlModelRun $model): NhlPregameContextRun
    {
        $run = $this->latestModelRun($model);
        $nextStatus = DB::transaction(function () use ($run): string {
            $fresh = NhlPregameContextRun::query()->lock(DB::getDriverName() === 'pgsql' ? 'for no key update' : true)->findOrFail($run->id);
            if (! in_array($fresh->status, [NhlPregameContextRun::STATUS_PAUSED, NhlPregameContextRun::STATUS_PAUSING], true)
                && ! ($fresh->status === NhlPregameContextRun::STATUS_FAILED
                    && in_array(data_get($fresh->options, 'pipeline_version'), [self::RESULTS_PIPELINE, self::CHECKPOINT_PIPELINE], true))) {
                throw ValidationException::withMessages(['pregame' => 'Only a paused or pausing Pregame build can be resumed.']);
            }

            $this->recoverExpiredDayClaims($fresh);

            if ($fresh->status === NhlPregameContextRun::STATUS_FAILED) {
                $busy = $fresh->days()->where(fn ($query) => $query->where('context_state', 'processing')
                    ->orWhere('league_state', 'processing'))->exists();
                if ($busy) {
                    throw ValidationException::withMessages(['pregame' => 'Wait until claimed batches finish before retrying.']);
                }
                $fresh->games()->whereIn('state', ['failed', 'processing'])->update(['state' => 'ready', 'last_error' => null]);
                $fresh->days()->where('context_state', 'failed')->update(['context_state' => 'pending', 'last_error' => null, 'failed_games' => 0]);
                $fresh->days()->where('league_state', 'failed')->update(['league_state' => 'pending', 'last_error' => null]);
                $fresh->update(['last_error' => null]);
            }
            if ($fresh->status !== NhlPregameContextRun::STATUS_PAUSING) {
                $this->upgradePausedRun($fresh);
            }

            $nextStatus = (int) $fresh->total_games > 0
                ? NhlPregameContextRun::STATUS_RUNNING
                : NhlPregameContextRun::STATUS_QUEUED;
            $fresh->update(['status' => $nextStatus]);
            $this->refreshRunCounts($fresh);

            return $nextStatus;
        });

        if ($nextStatus === NhlPregameContextRun::STATUS_QUEUED) {
            PrepareNhlPregameContextRunJob::dispatch($run->id);
        } else {
            try {
                Cache::store('redis')->forget(NhlPregameContextRun::stopSignalKey($run->id));
            } catch (\Throwable) {
                // A paused run has no stop signal unless an external cache failure occurred.
            }
            LoadNhlPregameContextRunJob::dispatch($run->id);
        }

        return $run->fresh();
    }

    /** Stop one run, mark unfinished game rows, and fence its queued and active jobs. */
    public function stopModelBackfill(NhlModelRun $model): NhlPregameContextRun
    {
        $run = $this->latestModelRun($model);
        $activeStatuses = [
            NhlPregameContextRun::STATUS_QUEUED,
            NhlPregameContextRun::STATUS_RUNNING,
            NhlPregameContextRun::STATUS_PAUSING,
            NhlPregameContextRun::STATUS_PAUSED,
        ];
        if (! in_array($run->status, $activeStatuses, true)) {
            throw ValidationException::withMessages(['pregame' => 'This Pregame build is not active.']);
        }

        try {
            Cache::store('redis')->put(NhlPregameContextRun::stopSignalKey($run->id), true, now()->addDays(7));
        } catch (\Throwable) {
            // The durable run status still fences queued jobs if Redis is unavailable.
        }

        try {
            DB::transaction(function () use ($run, $activeStatuses): void {
                $fresh = NhlPregameContextRun::query()->lock(DB::getDriverName() === 'pgsql' ? 'for no key update' : true)->findOrFail($run->id);
                if (! in_array($fresh->status, $activeStatuses, true)) {
                    throw ValidationException::withMessages(['pregame' => 'This Pregame build is no longer active.']);
                }

                $fresh->update(['status' => NhlPregameContextRun::STATUS_STOPPED]);
                NhlPregameContextRunGame::query()->where('run_id', $fresh->id)
                    ->whereNotIn('state', [
                        NhlPregameContextRunGame::STATE_COMPLETE,
                        NhlPregameContextRunGame::STATE_BLOCKED,
                        NhlPregameContextRunGame::STATE_FAILED,
                        NhlPregameContextRunGame::STATE_STOPPED,
                    ])
                    ->update(['state' => NhlPregameContextRunGame::STATE_STOPPED, 'updated_at' => now()]);
                NhlPregameContextRunDay::query()->where('run_id', $fresh->id)
                    ->where(function ($query): void {
                        $query->whereIn('context_state', [NhlPregameContextRunDay::STATE_PENDING, NhlPregameContextRunDay::STATE_PROCESSING])
                            ->orWhereIn('league_state', [NhlPregameContextRunDay::STATE_PENDING, NhlPregameContextRunDay::STATE_PROCESSING]);
                    })
                    ->update([
                        'context_state' => DB::raw("CASE WHEN context_state IN ('pending', 'processing') THEN 'stopped' ELSE context_state END"),
                        'league_state' => DB::raw("CASE WHEN league_state IN ('pending', 'processing') THEN 'stopped' ELSE league_state END"),
                        'updated_at' => now(),
                    ]);
                if (in_array(data_get($fresh->options, 'pipeline_version'), [self::THREE_STAGE_PIPELINE, self::RESULTS_PIPELINE, self::CHECKPOINT_PIPELINE], true)) {
                    NhlPregameContextRunDay::query()->where('run_id', $fresh->id)
                        ->whereIn('comparison_state', [NhlPregameContextRunDay::STATE_PENDING, NhlPregameContextRunDay::STATE_PROCESSING])
                        ->update(['comparison_state' => NhlPregameContextRunDay::STATE_STOPPED, 'updated_at' => now()]);
                }
                $this->refreshRunCounts($fresh);
            });
        } catch (\Throwable $exception) {
            try {
                Cache::store('redis')->forget(NhlPregameContextRun::stopSignalKey($run->id));
            } catch (\Throwable) {
                // Preserve the original database transition error.
            }

            throw $exception;
        }

        return $run->fresh();
    }

    /** Upgrade only a fully paused batched run; retain every completed context and game row. */
    private function upgradePausedRun(NhlPregameContextRun $run): void
    {
        $previousPipeline = data_get($run->options, 'pipeline_version');
        if (! in_array($previousPipeline, ['game_day_batch_v1', self::THREE_STAGE_PIPELINE, self::RESULTS_PIPELINE], true)) {
            return;
        }
        $busy = NhlPregameContextRunDay::query()->where('run_id', $run->id)
            ->where(fn ($query) => $query->where('context_state', NhlPregameContextRunDay::STATE_PROCESSING)
                ->orWhere('league_state', NhlPregameContextRunDay::STATE_PROCESSING)
                ->orWhere('comparison_state', NhlPregameContextRunDay::STATE_PROCESSING))->exists();
        if ($busy) {
            throw ValidationException::withMessages(['pregame' => 'Wait until claimed Pregame work finishes before resuming.']);
        }
        $options = $run->options ?? [];
        $options['pipeline_version'] = self::CHECKPOINT_PIPELINE;
        $run->update(['options' => $options]);
        // Completed dates have already consumed their peers; never reset their averages or comparisons.
        NhlPregameContextRunDay::query()->where('run_id', $run->id)
            ->where(function ($query) use ($previousPipeline): void {
                $query->where('context_state', '<>', 'complete')->orWhere('league_state', '<>', 'complete');
                if ($previousPipeline === self::THREE_STAGE_PIPELINE) {
                    $query->orWhere('comparison_state', '<>', 'complete');
                }
            })
            ->update([
                'context_state' => NhlPregameContextRunDay::STATE_PENDING,
                'context_started_at' => null, 'context_completed_at' => null,
                'league_cursor' => 0, 'league_entity_cursor' => 0,
                'league_state' => NhlPregameContextRunDay::STATE_PENDING,
                'league_started_at' => null, 'league_completed_at' => null,
                'comparison_state' => NhlPregameContextRunDay::STATE_PENDING,
                'comparison_started_at' => null, 'comparison_completed_at' => null,
                'updated_at' => now(),
            ]);
    }

    /** Find the latest context run owned by the selected SAT model. */
    private function latestModelRun(NhlModelRun $model): NhlPregameContextRun
    {
        $run = NhlPregameContextRun::latestForModel($model->id);
        if ($run === null || (int) $run->model_run_id !== (int) $model->id) {
            throw ValidationException::withMessages(['pregame' => 'This model has no Pregame build to control.']);
        }

        return $run;
    }

    /** Materialize durable game work rows for a queued context run. */
    public function prepare(NhlPregameContextRun $run): void
    {
        if ($run->status !== NhlPregameContextRun::STATUS_QUEUED) {
            return;
        }

        $query = DB::table('nhl_games')
            ->where('game_type', 2)
            ->whereIn('game_state', ['OFF', 'FINAL'])
            ->whereIn('season_id', $run->season_ids)
            ->when($run->start_date !== null, fn ($query) => $query->whereDate('game_date', '>=', $run->start_date))
            ->when($run->end_date !== null, fn ($query) => $query->whereDate('game_date', '<=', $run->end_date));

        if ((bool) data_get($run->options, 'only_missing', false)) {
            $contextVersion = (string) data_get($run->options, 'context_version', NhlPregameContextBuilder::VERSION);
            $modelRunId = $run->model_run_id;
            $query->where(function ($missing) use ($contextVersion, $modelRunId): void {
                $missing->where(function ($teamMissing) use ($contextVersion, $modelRunId): void {
                    foreach (['home', 'away'] as $index => $side) {
                        $method = $index === 0 ? 'whereNotExists' : 'orWhereNotExists';
                        $teamMissing->{$method}(function ($teamContext) use ($contextVersion, $side, $modelRunId): void {
                            $teamContext->selectRaw('1')
                                ->from('nhl_team_game_pregame_contexts as team_context')
                                ->whereColumn('team_context.nhl_game_id', 'nhl_games.nhl_game_id')
                                ->whereColumn('team_context.nhl_team_id', 'nhl_games.' . $side . '_team_id')
                                ->where('team_context.context_version', $contextVersion)
                                ->where('team_context.model_run_id', $modelRunId);
                        });
                    }
                });
                $missing->orWhereExists(function ($metrics) use ($contextVersion, $modelRunId): void {
                    $metrics->selectRaw('1')
                        ->from('nhl_team_game_pregame_context_metrics as team_metric')
                        ->join('nhl_team_game_pregame_contexts as team_context', 'team_context.id', '=', 'team_metric.team_context_id')
                        ->whereColumn('team_context.nhl_game_id', 'nhl_games.nhl_game_id')
                        ->where('team_context.context_version', $contextVersion)
                        ->where('team_context.model_run_id', $modelRunId)
                        ->where('team_metric.measure_key', 'value')
                        ->whereNotNull('team_metric.value')
                        ->whereNull('team_metric.league_average');
                });
                $missing->orWhereNotExists(function ($summary): void {
                    $summary->selectRaw('1')
                        ->from('nhl_game_summaries as game_summary')
                        ->whereColumn('game_summary.nhl_game_id', 'nhl_games.nhl_game_id')
                        ->where(function ($teams): void {
                            $teams->whereColumn('game_summary.nhl_team_id', 'nhl_games.home_team_id')
                                ->orWhereColumn('game_summary.nhl_team_id', 'nhl_games.away_team_id');
                        });
                });
                $missing->orWhereExists(function ($summary) use ($contextVersion, $modelRunId): void {
                    $summary->selectRaw('1')
                        ->from('nhl_game_summaries as game_summary')
                        ->whereColumn('game_summary.nhl_game_id', 'nhl_games.nhl_game_id')
                        ->where(function ($teams): void {
                            $teams->whereColumn('game_summary.nhl_team_id', 'nhl_games.home_team_id')
                                ->orWhereColumn('game_summary.nhl_team_id', 'nhl_games.away_team_id');
                        })
                        ->whereNotExists(function ($playerContext) use ($contextVersion, $modelRunId): void {
                            $playerContext->selectRaw('1')
                                ->from('nhl_player_game_pregame_contexts as player_context')
                                ->whereColumn('player_context.nhl_game_id', 'game_summary.nhl_game_id')
                                ->whereColumn('player_context.nhl_player_id', 'game_summary.nhl_player_id')
                                ->whereColumn('player_context.nhl_team_id', 'game_summary.nhl_team_id')
                                ->where('player_context.context_version', $contextVersion)
                                ->where('player_context.model_run_id', $modelRunId);
                        });
                });
                $missing->orWhereExists(function ($metrics) use ($contextVersion, $modelRunId): void {
                    $metrics->selectRaw('1')
                        ->from('nhl_player_game_pregame_context_metrics as player_metric')
                        ->join('nhl_player_game_pregame_contexts as player_context', 'player_context.id', '=', 'player_metric.player_context_id')
                        ->whereColumn('player_context.nhl_game_id', 'nhl_games.nhl_game_id')
                        ->where('player_context.context_version', $contextVersion)
                        ->where('player_context.model_run_id', $modelRunId)
                        ->where('player_metric.measure_key', 'value')
                        ->whereNotNull('player_metric.value')
                        ->whereNull('player_metric.league_average');
                });
            });
        }

        $games = $query->orderBy('game_date')
            ->orderBy('start_time_utc')
            ->orderBy('nhl_game_id')
            ->get(['nhl_game_id', 'game_date']);

        DB::transaction(function () use ($run, $games): void {
            $fresh = NhlPregameContextRun::query()->lock(DB::getDriverName() === 'pgsql' ? 'for no key update' : true)->findOrFail($run->id);
            if ($fresh->status !== NhlPregameContextRun::STATUS_QUEUED) {
                return;
            }

            $now = now();
            $rows = $games->values()->map(fn (object $game, int $index): array => [
                'run_id' => $fresh->id,
                'nhl_game_id' => $game->nhl_game_id,
                'game_date' => $game->game_date,
                'sequence' => $index + 1,
                'state' => NhlPregameContextRunGame::STATE_PENDING,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('nhl_pregame_context_run_games')->upsert(
                    $chunk,
                    ['run_id', 'nhl_game_id'],
                    ['game_date', 'sequence', 'updated_at']
                );
            }

            if (in_array(data_get($fresh->options, 'pipeline_version'), ['game_day_batch_v1', self::THREE_STAGE_PIPELINE, self::RESULTS_PIPELINE, self::CHECKPOINT_PIPELINE], true)) {
                $dayRows = collect($rows)->groupBy('game_date')->map(fn (Collection $dateRows, string $date): array => [
                    'run_id' => $fresh->id,
                    'game_date' => $date,
                    'context_state' => NhlPregameContextRunDay::STATE_PENDING,
                    'league_state' => NhlPregameContextRunDay::STATE_PENDING,
                    'game_count' => $dateRows->count(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->values()->all();
                foreach (array_chunk($dayRows, 500) as $chunk) {
                    DB::table('nhl_pregame_context_run_days')->upsert(
                        $chunk,
                        ['run_id', 'game_date'],
                        ['game_count', 'updated_at']
                    );
                }
            }

            $fresh->update([
                'status' => NhlPregameContextRun::STATUS_RUNNING,
                'total_games' => count($rows),
                'last_error' => null,
            ]);
        });

        LoadNhlPregameContextRunJob::dispatch($run->id);
    }

    /** Claim and dispatch one bounded page from the earliest unfinished date. */
    public function load(int $runId): void
    {
        $run = NhlPregameContextRun::query()->find($runId);
        if (in_array(data_get($run?->options, 'pipeline_version'), ['game_day_batch_v1', self::THREE_STAGE_PIPELINE, self::RESULTS_PIPELINE, self::CHECKPOINT_PIPELINE], true)) {
            $this->loadDayBatch($runId);

            return;
        }

        $lock = Cache::lock("nhl-pregame-context-run-loader:{$runId}", 30);
        if (! $lock->get()) {
            return;
        }

        try {
            $work = DB::transaction(function () use ($runId): array {
                $run = NhlPregameContextRun::query()->lock(DB::getDriverName() === 'pgsql' ? 'for no key update' : true)->find($runId);
                if ($run === null || ! in_array($run->status, [
                    NhlPregameContextRun::STATUS_RUNNING,
                    NhlPregameContextRun::STATUS_PAUSING,
                ], true)) {
                    return ['game_ids' => [], 'finalize_date' => null];
                }

                if ($run->status === NhlPregameContextRun::STATUS_PAUSING) {
                    $hasProcessingGames = NhlPregameContextRunGame::query()->where('run_id', $runId)
                        ->where('state', NhlPregameContextRunGame::STATE_PROCESSING)->exists();
                    $hasProcessingBenchmarks = collect(data_get($run->options, 'league_benchmarks', []))
                        ->contains(fn (mixed $status): bool => $status === 'processing');

                    if (! $hasProcessingGames && ! $hasProcessingBenchmarks) {
                        $this->refreshRunCounts($run);
                        $run->update(['status' => NhlPregameContextRun::STATUS_PAUSED]);
                    }

                    return ['game_ids' => [], 'finalize_date' => null];
                }

                $dates = NhlPregameContextRunGame::query()->where('run_id', $runId)
                    ->distinct()->orderBy('game_date')->pluck('game_date');
                $date = null;
                foreach ($dates as $candidateDate) {
                    if (data_get($run->options, 'league_benchmarks.'.$candidateDate) !== 'complete') {
                        $date = (string) $candidateDate;
                        break;
                    }
                }

                if ($date === null) {
                    $this->refreshRunCounts($run);
                    $run->update(['status' => NhlPregameContextRun::STATUS_COMPLETED, 'current_game_date' => null]);

                    return ['game_ids' => [], 'finalize_date' => null];
                }

                $dateWork = NhlPregameContextRunGame::query()->where('run_id', $runId)->whereDate('game_date', $date);
                $hasPending = (clone $dateWork)->whereIn('state', [
                    NhlPregameContextRunGame::STATE_PENDING,
                    NhlPregameContextRunGame::STATE_READY,
                ])->exists();
                $hasProcessing = (clone $dateWork)->where('state', NhlPregameContextRunGame::STATE_PROCESSING)->exists();

                if (! $hasPending && ! $hasProcessing) {
                    if (data_get($run->options, 'league_benchmarks.'.$date) === 'processing') {
                        return ['game_ids' => [], 'finalize_date' => null];
                    }

                    $options = $run->options ?? [];
                    data_set($options, 'league_benchmarks.'.$date, 'processing');
                    $run->update(['options' => $options, 'current_game_date' => $date]);

                    return ['game_ids' => [], 'finalize_date' => $date];
                }

                $run->update(['current_game_date' => $date]);
                NhlPregameContextRunGame::query()
                    ->where('run_id', $runId)
                    ->whereDate('game_date', $date)
                    ->where('state', NhlPregameContextRunGame::STATE_PENDING)
                    ->update(['state' => NhlPregameContextRunGame::STATE_READY, 'updated_at' => now()]);

                $work = NhlPregameContextRunGame::query()
                    ->where('run_id', $runId)
                    ->whereDate('game_date', $date)
                    ->where('state', NhlPregameContextRunGame::STATE_READY)
                    ->orderBy('sequence')
                    ->lockForUpdate()
                    ->limit(self::DISPATCH_PAGE_SIZE)
                    ->get();

                if ($work->isEmpty()) {
                    $this->refreshRunCounts($run);

                    return ['game_ids' => [], 'finalize_date' => null];
                }

                $ids = $work->pluck('nhl_game_id')->map(fn (mixed $id): int => (int) $id)->all();
                NhlPregameContextRunGame::query()
                    ->where('run_id', $runId)
                    ->whereIn('nhl_game_id', $ids)
                    ->update([
                        'state' => NhlPregameContextRunGame::STATE_PROCESSING,
                        'attempts' => DB::raw('attempts + 1'),
                        'started_at' => now(),
                        'updated_at' => now(),
                    ]);
                $this->refreshRunCounts($run);

                return ['game_ids' => $ids, 'finalize_date' => null];
            });

            foreach ($work['game_ids'] as $gameId) {
                BuildNhlPregameContextGameJob::dispatch($runId, $gameId);
            }
            if ($work['finalize_date'] !== null) {
                CalculateNhlPregameContextLeagueMetricsJob::dispatch($runId, $work['finalize_date']);
            }
        } finally {
            $lock->release();
        }
    }

    /** Claim bounded context-day and independent league-average work for new model runs. */
    private function loadDayBatch(int $runId): void
    {
        $lock = Cache::lock("nhl-pregame-context-run-loader:{$runId}", 30);
        if (! $lock->get()) {
            $this->scheduleRecovery($runId);

            return;
        }

        try {
            $claimed = DB::transaction(function () use ($runId): array {
                $run = NhlPregameContextRun::query()->lock(DB::getDriverName() === 'pgsql' ? 'for no key update' : true)->find($runId);
                if ($run === null || ! in_array($run->status, [NhlPregameContextRun::STATUS_RUNNING, NhlPregameContextRun::STATUS_PAUSING], true)) {
                    return ['context' => [], 'league' => [], 'comparison' => []];
                }

                $this->recoverExpiredDayClaims($run);

                $checkpointed = data_get($run->options, 'pipeline_version') === self::CHECKPOINT_PIPELINE;
                $leagueFirst = $checkpointed || data_get($run->options, 'pipeline_version') === self::RESULTS_PIPELINE;
                $independent = $leagueFirst || data_get($run->options, 'pipeline_version') === self::THREE_STAGE_PIPELINE;
                $activeComparisons = $independent && ! $leagueFirst ? NhlPregameContextRunDay::query()->where('run_id', $runId)
                    ->where('comparison_state', NhlPregameContextRunDay::STATE_PROCESSING)->count() : 0;
                $activeContextCount = NhlPregameContextRunDay::query()->where('run_id', $runId)
                    ->where('context_state', NhlPregameContextRunDay::STATE_PROCESSING)->count();
                $activeLeagueCount = NhlPregameContextRunDay::query()->where('run_id', $runId)
                    ->where('league_state', NhlPregameContextRunDay::STATE_PROCESSING)->count();

                if ($run->status === NhlPregameContextRun::STATUS_PAUSING) {
                    if ($activeContextCount === 0 && $activeLeagueCount === 0 && $activeComparisons === 0) {
                        $this->refreshRunCounts($run);
                        $run->update(['status' => NhlPregameContextRun::STATUS_PAUSED]);
                    }

                    return ['context' => [], 'league' => [], 'comparison' => []];
                }

                // A worker with stale code can seed game rows without creating day rows.
                // An empty day query must never mean that the selected games are finished.
                $missingDays = NhlPregameContextRunGame::query()->where('run_id', $runId)
                    ->whereNotExists(function ($day): void {
                        $day->selectRaw('1')->from('nhl_pregame_context_run_days as run_day')
                            ->whereColumn('run_day.run_id', 'nhl_pregame_context_run_games.run_id')
                            ->whereColumn('run_day.game_date', 'nhl_pregame_context_run_games.game_date');
                    })->exists();
                $noDaysForSelectedGames = (int) $run->total_games > 0
                    && ! NhlPregameContextRunDay::query()->where('run_id', $runId)->exists();
                if ($missingDays || $noDaysForSelectedGames) {
                    $this->refreshRunCounts($run);
                    $run->update([
                        'status' => NhlPregameContextRun::STATUS_FAILED,
                        'last_error' => 'Pregame game-day work is missing. Reload all projections workers, then start Build Pregame again.',
                    ]);

                    return ['context' => [], 'league' => [], 'comparison' => []];
                }

                $contextDays = collect();
                $availableSlots = max(0, ($independent ? max(1, (int) config('nhl-pregame.context_concurrency', 2)) : 2) - $activeContextCount);
                if ($availableSlots > 0) {
                    $contextDays = NhlPregameContextRunDay::query()->where('run_id', $runId)
                        ->where('context_state', NhlPregameContextRunDay::STATE_PENDING)
                        ->when($leagueFirst, fn ($query) => $query->where('league_state', NhlPregameContextRunDay::STATE_COMPLETE))
                        ->orderBy('game_date')->limit($availableSlots)->lockForUpdate()->get();
                    foreach ($contextDays as $day) {
                        $day->update([
                            'context_state' => NhlPregameContextRunDay::STATE_PROCESSING,
                            'attempts' => DB::raw('attempts + 1'),
                            'context_started_at' => $checkpointed ? ($day->context_started_at ?? now()) : now(),
                            ...($checkpointed ? $this->dayLease('context') : []),
                        ]);
                        if ($checkpointed) {
                            continue;
                        }
                        NhlPregameContextRunGame::query()->where('run_id', $runId)->whereDate('game_date', $day->game_date)
                            ->whereIn('state', [NhlPregameContextRunGame::STATE_PENDING, NhlPregameContextRunGame::STATE_READY])
                            ->update([
                                'state' => NhlPregameContextRunGame::STATE_PROCESSING,
                                'attempts' => DB::raw('attempts + 1'),
                                'started_at' => now(),
                                'updated_at' => now(),
                            ]);
                    }
                }

                $leagueDays = [];
                $availableLeagueSlots = max(0, ($independent ? max(1, (int) config('nhl-pregame.league_concurrency', 4)) : 2) - $activeLeagueCount);
                // Bound transient peer storage while allowing configured worker concurrency.
                $lookahead = max((int) config('nhl-pregame.league_concurrency', 4), (int) config('nhl-pregame.league_lookahead_days', 8));
                $preparedDays = $checkpointed ? NhlPregameContextRunDay::query()->where('run_id', $runId)
                    ->where('context_state', '<>', 'complete')->where(fn ($query) => $query->whereIn('league_state', ['complete', 'processing'])
                        ->orWhere('league_cursor', '>', 0)->orWhere('league_entity_cursor', '>', 0))->count() : 0;
                while ($availableLeagueSlots > 0) {
                    $leagueDay = NhlPregameContextRunDay::query()->where('run_id', $runId)
                        ->when(! $independent, fn ($query) => $query->where('context_state', NhlPregameContextRunDay::STATE_COMPLETE))
                        ->where('league_state', NhlPregameContextRunDay::STATE_PENDING)
                        ->when($checkpointed && $preparedDays >= $lookahead, fn ($query) => $query->where(fn ($partial) => $partial->where('league_cursor', '>', 0)->orWhere('league_entity_cursor', '>', 0)))
                        ->when(! $independent, fn ($query) => $query->whereNotExists(function ($priorDay) use ($runId): void {
                            $priorDay->selectRaw('1')->from('nhl_pregame_context_run_days as prior_day')
                                ->whereColumn('prior_day.run_id', 'nhl_pregame_context_run_days.run_id')
                                ->whereColumn('prior_day.game_date', '<', 'nhl_pregame_context_run_days.game_date')
                                ->where('prior_day.run_id', $runId)
                                ->where('prior_day.context_state', '<>', NhlPregameContextRunDay::STATE_COMPLETE);
                        }))
                        ->orderBy('game_date')->lockForUpdate()->first();
                    if ($leagueDay !== null) {
                        $leagueDay->update([
                            'league_state' => NhlPregameContextRunDay::STATE_PROCESSING,
                            'league_started_at' => $checkpointed ? ($leagueDay->league_started_at ?? now()) : now(),
                            ...($checkpointed ? $this->dayLease('league') : []),
                        ]);
                        if ($checkpointed && (int) $leagueDay->league_cursor === 0) {
                            $preparedDays++;
                        }
                        $leagueDays[] = (int) $leagueDay->id;
                        $availableLeagueSlots--;
                    } else {
                        break;
                    }
                }

                $comparisonDays = [];
                $comparisonSlots = $independent && ! $leagueFirst ? max(0, max(1, (int) config('nhl-pregame.comparison_concurrency', 2)) - $activeComparisons) : 0;
                if ($comparisonSlots > 0) {
                    $ready = NhlPregameContextRunDay::query()->where('run_id', $runId)
                        ->where('context_state', NhlPregameContextRunDay::STATE_COMPLETE)
                        ->where('league_state', NhlPregameContextRunDay::STATE_COMPLETE)
                        ->where('comparison_state', NhlPregameContextRunDay::STATE_PENDING)
                        ->orderBy('game_date')->limit($comparisonSlots)->lockForUpdate()->get();
                    foreach ($ready as $day) {
                        $day->update(['comparison_state' => NhlPregameContextRunDay::STATE_PROCESSING, 'comparison_started_at' => now()]);
                        $comparisonDays[] = (int) $day->id;
                    }
                }

                $unfinished = NhlPregameContextRunDay::query()->where('run_id', $runId)
                    ->where(function ($query) use ($independent, $leagueFirst): void {
                        $query->whereIn('context_state', [NhlPregameContextRunDay::STATE_PENDING, NhlPregameContextRunDay::STATE_PROCESSING])
                            ->orWhereIn('league_state', [NhlPregameContextRunDay::STATE_PENDING, NhlPregameContextRunDay::STATE_PROCESSING]);
                        if ($independent && ! $leagueFirst) {
                            $query->orWhereIn('comparison_state', [NhlPregameContextRunDay::STATE_PENDING, NhlPregameContextRunDay::STATE_PROCESSING]);
                        }
                    })->exists();
                if (! $unfinished) {
                    $this->refreshRunCounts($run);
                    $unfinishedGames = NhlPregameContextRunGame::query()->where('run_id', $runId)
                        ->whereNotIn('state', [NhlPregameContextRunGame::STATE_COMPLETE, NhlPregameContextRunGame::STATE_BLOCKED])
                        ->exists();
                    $accountedGames = (int) $run->completed_games + (int) $run->blocked_games;
                    if ($unfinishedGames || $accountedGames !== (int) $run->total_games) {
                        $run->update([
                            'status' => NhlPregameContextRun::STATUS_FAILED,
                            'last_error' => 'Pregame day progress does not match the selected games. Reload all projections workers, then start Build Pregame again.',
                        ]);
                    } else {
                        $run->update(['status' => NhlPregameContextRun::STATUS_COMPLETED, 'current_game_date' => null]);
                    }
                }
                if ($independent && $unfinished) {
                    $this->refreshRunCounts($run);
                }

                return [
                    'context' => $contextDays->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
                    'league' => $leagueDays,
                    'comparison' => $comparisonDays,
                    'claims' => $checkpointed ? NhlPregameContextRunDay::query()
                        ->whereIn('id', array_merge($contextDays->pluck('id')->all(), $leagueDays))
                        ->get(['id', 'league_claim_token', 'context_claim_token'])->keyBy('id')->all() : [],
                ];
            });

            $checkpointed = data_get(NhlPregameContextRun::query()->find($runId)?->options, 'pipeline_version') === self::CHECKPOINT_PIPELINE;
            foreach ($claimed['league'] as $dayId) {
                if ($checkpointed) {
                    CalculateNhlPregameLeagueBatchJob::dispatch($runId, $dayId, $claimed['claims'][$dayId]->league_claim_token);
                } else {
                    CalculateNhlPregameContextRunDayLeagueAveragesJob::dispatch($runId, $dayId);
                }
            }
            foreach ($claimed['context'] as $dayId) {
                if ($checkpointed) {
                    BuildNhlPregameContextDayBatchJob::dispatch($runId, $dayId, $claimed['claims'][$dayId]->context_claim_token);
                } else {
                    BuildNhlPregameContextDayJob::dispatch($runId, $dayId);
                }
            }
            foreach ($claimed['comparison'] as $dayId) {
                CompareNhlPregameContextRunDayJob::dispatch($runId, $dayId);
            }
        } finally {
            $lock->release();
        }
        $this->scheduleRecovery($runId);
    }

    /** Check one immutable delivery token; an expired or replaced delivery cannot commit. */
    public function ownsDayClaim(int $runId, int $dayId, string $stage, ?string $token): bool
    {
        if (! in_array($stage, ['league', 'context'], true)) {
            return false;
        }

        return NhlPregameContextRunDay::query()->whereKey($dayId)->where('run_id', $runId)
            ->where($stage.'_state', 'processing')->where($stage.'_claim_token', $token)
            ->when($token !== null, fn ($query) => $query->where($stage.'_claim_expires_at', '>', now()))->exists();
    }

    /** Give an unchanged, still-owned delivery its full execution lease after queue waiting. */
    public function startDayClaim(int $runId, int $dayId, string $stage, ?string $token): bool
    {
        if (! in_array($stage, ['league', 'context'], true)) {
            return false;
        }

        return DB::transaction(function () use ($runId, $dayId, $stage, $token): bool {
            $run = NhlPregameContextRun::query()->whereKey($runId)
                ->lock(DB::getDriverName() === 'pgsql' ? 'for no key update' : true)->first();
            if ($run === null || data_get($run->options, 'pipeline_version') !== self::CHECKPOINT_PIPELINE
                || ! in_array($run->status, ['running', 'pausing'], true)
                || ! $this->ownsDayClaim($runId, $dayId, $stage, $token)) {
                return false;
            }
            NhlPregameContextRunDay::query()->whereKey($dayId)->where('run_id', $runId)
                ->where($stage.'_claim_token', $token)->update([
                    $stage.'_claim_expires_at' => $this->dayLease($stage)[$stage.'_claim_expires_at'],
                    'updated_at' => now(),
                ]);

            return true;
        });
    }

    /** Retry only a genuine timeout, fencing its old delivery and retaining all committed work. */
    public function retryTimedOutDay(int $runId, int $dayId, string $stage, ?string $token, \Throwable $exception): bool
    {
        if (! $exception instanceof TimeoutExceededException || ! in_array($stage, ['league', 'context'], true)) {
            return false;
        }
        $retry = DB::transaction(function () use ($runId, $dayId, $stage, $token, $exception): bool {
            $run = NhlPregameContextRun::query()->whereKey($runId)
                ->lock(DB::getDriverName() === 'pgsql' ? 'for no key update' : true)->first();
            if ($run === null || data_get($run->options, 'pipeline_version') !== self::CHECKPOINT_PIPELINE
                || ! in_array($run->status, ['running', 'pausing'], true)
                || ! $this->ownsDayClaim($runId, $dayId, $stage, $token)) {
                return false;
            }
            $day = NhlPregameContextRunDay::query()->whereKey($dayId)->firstOrFail();
            $game = $stage === 'context' ? $run->games()->whereDate('game_date', $day->game_date)
                ->where('state', 'processing')->orderBy('sequence')->first() : null;
            $checkpoint = $stage === 'league'
                ? implode(':', [$day->league_cursor, $day->league_entity_cursor, $day->league_seconds])
                : ($game === null ? 'cleanup' : $game->nhl_game_id.':'.($game->snapshot_completed_at === null ? 'snapshot' : 'comparisons'));
            $key = $stage.':'.$dayId;
            $options = $run->options ?? [];
            $previous = $options['timeout_retries'][$key] ?? [];
            $attempts = ($previous['checkpoint'] ?? null) === $checkpoint ? (int) ($previous['attempts'] ?? 0) : 0;
            if ($attempts >= max(0, (int) config('nhl-pregame.timeout_retries', 3))) {
                return false;
            }
            $options['timeout_retries'][$key] = ['checkpoint' => $checkpoint, 'attempts' => $attempts + 1];
            $run->update(['options' => $options]);
            $day->update([$stage.'_state' => 'pending', $stage.'_claim_token' => (string) Str::uuid(),
                $stage.'_claim_expires_at' => null,
                'last_error' => mb_strimwidth('Automatic timeout retry '.($attempts + 1).': '.$exception->getMessage(), 0, 2000)]);

            return true;
        });
        if ($retry) {
            LoadNhlPregameContextRunJob::dispatch($runId)
                ->delay(now()->addSeconds(max(1, (int) config('nhl-pregame.timeout_retry_delay_seconds', 10))));
        }

        return $retry;
    }

    /** Clear a recovered checkpoint's consecutive-timeout budget under the caller's parent run lock. */
    public function clearDayTimeoutRetries(NhlPregameContextRun $run, int $dayId, string $stage): void
    {
        $options = $run->options ?? [];
        $key = $stage.':'.$dayId;
        if (! isset($options['timeout_retries'][$key])) {
            return;
        }
        unset($options['timeout_retries'][$key]);
        if ($options['timeout_retries'] === []) {
            unset($options['timeout_retries']);
        }
        $run->update(['options' => $options]);
    }

    /** Create a delivery fence with enough time for queue wait, bounded execution, and cleanup. */
    private function dayLease(string $stage): array
    {
        $timeout = $stage === 'league' ? (new CalculateNhlPregameLeagueBatchJob(0, 0))->timeout
            : (new BuildNhlPregameContextDayBatchJob(0, 0))->timeout;

        return [$stage.'_claim_token' => (string) Str::uuid(),
            $stage.'_claim_expires_at' => now()->addSeconds($timeout + max(60, (int) config('nhl-pregame.claim_grace_seconds', 120)))];
    }

    /** Reclaim abandoned checkpoint work under the parent fence without discarding committed pages. */
    private function recoverExpiredDayClaims(NhlPregameContextRun $run): void
    {
        if (data_get($run->options, 'pipeline_version') !== self::CHECKPOINT_PIPELINE) {
            return;
        }
        foreach (['league', 'context'] as $stage) {
            NhlPregameContextRunDay::query()->where('run_id', $run->id)->where($stage.'_state', 'processing')
                ->where(function ($query) use ($stage): void {
                    $query->where($stage.'_claim_expires_at', '<=', now())
                        ->orWhere(fn ($legacy) => $legacy->whereNull($stage.'_claim_token')
                            ->where('updated_at', '<=', now()->subSeconds(1800 + max(60, (int) config('nhl-pregame.claim_grace_seconds', 120)))));
                })->update([$stage.'_state' => 'pending', $stage.'_claim_token' => (string) Str::uuid(),
                    $stage.'_claim_expires_at' => null, 'updated_at' => now()]);
        }
    }

    /** Mark an independently calculated day benchmark as complete. */
    public function markRunDayLeagueComplete(int $runId, int $runDayId): void
    {
        NhlPregameContextRunDay::query()->where('run_id', $runId)->whereKey($runDayId)
            ->where('league_state', NhlPregameContextRunDay::STATE_PROCESSING)
            ->update([
                'league_state' => NhlPregameContextRunDay::STATE_COMPLETE,
                'league_completed_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /** Mark the final comparison stage complete after its bounded writes succeed. */
    public function markRunDayComparisonsComplete(int $runId, int $runDayId): void
    {
        NhlPregameContextRunDay::query()->where('run_id', $runId)->whereKey($runDayId)
            ->where('comparison_state', NhlPregameContextRunDay::STATE_PROCESSING)->update([
                'comparison_state' => NhlPregameContextRunDay::STATE_COMPLETE,
                'comparison_completed_at' => now(), 'updated_at' => now(),
            ]);
    }

    /** Mark day-level league comparisons complete before advancing the run. */
    public function markLeagueBenchmarksComplete(int $runId, string $gameDate): void
    {
        DB::transaction(function () use ($runId, $gameDate): void {
            $run = NhlPregameContextRun::query()->lock(DB::getDriverName() === 'pgsql' ? 'for no key update' : true)->find($runId);
            if ($run === null || ! in_array($run->status, [
                NhlPregameContextRun::STATUS_RUNNING,
                NhlPregameContextRun::STATUS_PAUSING,
            ], true)) {
                return;
            }

            $options = $run->options ?? [];
            data_set($options, 'league_benchmarks.'.$gameDate, 'complete');
            $run->update(['options' => $options]);
        });
    }

    /** Update cached counters from durable work rows. */
    public function refreshRunCounts(NhlPregameContextRun $run): void
    {
        $counts = NhlPregameContextRunGame::query()->where('run_id', $run->id)
            ->selectRaw('SUM(CASE WHEN state = ? THEN 1 ELSE 0 END) as ready_games', [NhlPregameContextRunGame::STATE_READY])
            ->selectRaw('SUM(CASE WHEN state = ? THEN 1 ELSE 0 END) as processing_games', [NhlPregameContextRunGame::STATE_PROCESSING])
            ->selectRaw('SUM(CASE WHEN state = ? THEN 1 ELSE 0 END) as completed_games', [NhlPregameContextRunGame::STATE_COMPLETE])
            ->selectRaw('SUM(CASE WHEN state = ? THEN 1 ELSE 0 END) as blocked_games', [NhlPregameContextRunGame::STATE_BLOCKED])
            ->selectRaw('SUM(CASE WHEN state = ? THEN 1 ELSE 0 END) as failed_games', [NhlPregameContextRunGame::STATE_FAILED])
            ->first();

        $run->update([
            'ready_games' => (int) ($counts->ready_games ?? 0),
            'processing_games' => (int) ($counts->processing_games ?? 0),
            'completed_games' => (int) ($counts->completed_games ?? 0),
            'blocked_games' => (int) ($counts->blocked_games ?? 0),
            'failed_games' => (int) ($counts->failed_games ?? 0),
        ]);
    }
}
