<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\NhlPregameContextDataUnavailable;
use App\Exceptions\NhlPregameContextRunStopped;
use App\Jobs\CoordinateNhlPregameWorkstreamsJob;
use App\Jobs\WorkNhlPregameWorkstreamTaskJob;
use App\Models\NhlModelRun;
use App\Models\NhlPregameContextRun;
use App\Models\NhlPregameContextRunDay;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Durable, pilot-only goals and independent task admission over existing statistical storage. */
class NhlPregameWorkstreams
{
    public const AREAS = ['players' => 'Players', 'teams' => 'Teams', 'goalies' => 'Goalies',
        'league' => 'League averages', 'rankings' => 'Rankings', 'travel' => 'Travel'];
    private const GOALS = 'nhl_pregame_workstream_goals';
    private const TASKS = 'nhl_pregame_workstream_tasks';

    /** A rolling deployment can still inspect legacy work before the human runs migrations. */
    public function available(): bool
    {
        return Schema::hasTable(self::GOALS) && Schema::hasTable(self::TASKS);
    }

    /** Enrol explicit maintenance goals only for the unique approved historical pilot. */
    public function enrol(NhlModelRun $model): void
    {
        abort_unless($this->available(), 409, 'Run the Pregame workstream migrations before using area controls.');
        $pilot = app(NhlSatPregameWorkspace::class)->pilot();
        abort_unless($pilot !== null && (int) $pilot->id === (int) $model->id, 409, 'Sep2026 pilot source is unavailable.');
        foreach (self::AREAS as $area => $name) {
            DB::table(self::GOALS)->insertOrIgnore(['model_run_id' => $model->id, 'area' => $area,
                'worker_limit' => 1, 'context_version' => NhlPregameContextBuilder::VERSION,
                'scope_hash' => $this->scopeHash($model),
                'status' => 'assessing', 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /** Start missing-context maintenance without touching valid games or active legacy runs. */
    public function repair(NhlModelRun $model, ?int $userId = null): NhlPregameContextRun
    {
        $this->enrol($model);
        $goals = DB::table(self::GOALS)->where('model_run_id', $model->id)->get();
        $dirty = $goals->whereNotNull('dirty_from')->min('dirty_from');
        $run = app(NhlPregameContextOrchestrator::class)->queueModelRepair($model, $userId, [
            'workstream_pipeline' => true, 'source_dirty_from' => $dirty,
            'goal_generations' => $goals->pluck('generation', 'area')->all(),
        ]);
        DB::table(self::GOALS)->where('model_run_id', $model->id)->update(['run_id' => $run->id,
            'status' => 'queued', 'last_error' => null, 'updated_at' => now()]);

        return $run;
    }

    /** Live limits change future admissions; claimed work finishes its bounded page. */
    public function allocate(NhlModelRun $model, array $limits): void
    {
        if (array_diff(array_keys(self::AREAS), array_keys($limits)) !== [] || array_diff(array_keys($limits), array_keys(self::AREAS)) !== []
            || collect($limits)->contains(fn ($value): bool => ! is_int($value) || $value < 0 || $value > 25) || array_sum($limits) > 25) {
            throw ValidationException::withMessages(['areas' => 'Set all six area limits from 0 to 25, with at most 25 total workers.']);
        }
        $this->enrol($model);
        DB::transaction(function () use ($model, $limits): void {
            DB::table(self::GOALS)->where('model_run_id', $model->id)->orderBy('id')->lockForUpdate()->get(['id']);
            foreach (self::AREAS as $area => $name) {
                DB::table(self::GOALS)->where('model_run_id', $model->id)->where('area', $area)
                    ->update(['worker_limit' => $limits[$area], 'updated_at' => now()]);
            }
        });
        CoordinateNhlPregameWorkstreamsJob::dispatch((int) $model->id);
    }

    /** Materialize small independently owned tasks after existing source preflight. */
    public function seed(NhlPregameContextRun $run): void
    {
        if (! in_array($run->status, ['running', 'completed'], true) || data_get($run->options, 'workstream_seeded')
            || (int) NhlPregameContextRun::latestForModel((int) $run->model_run_id)?->id !== (int) $run->id) {
            return;
        }
        $model = NhlModelRun::findOrFail($run->model_run_id);
        $this->enrol($model);
        $goals = DB::table(self::GOALS)->where('model_run_id', $model->id)->pluck('id', 'area');
        $days = DB::table('nhl_pregame_context_run_days')->where('run_id', $run->id)->pluck('id', 'game_date');
        $games = DB::table('nhl_pregame_context_run_games')->where('run_id', $run->id)->orderBy('id');
        $games->chunkById(100, function ($rows) use ($run, $goals, $days): void {
            $tasks = [];
            foreach ($rows as $game) {
                foreach (['teams', 'players', 'goalies', 'travel', 'rankings'] as $area) {
                    $tasks[] = ['goal_id' => $goals[$area], 'run_id' => $run->id, 'run_day_id' => $days[$game->game_date],
                        'nhl_game_id' => $game->nhl_game_id, 'unit_key' => 'game:'.$game->nhl_game_id, 'kind' => 'game',
                        'state' => $game->state === 'blocked' ? 'blocked' : 'pending', 'last_error' => $game->last_error,
                        'created_at' => now(), 'updated_at' => now()];
                }
            }
            DB::table(self::TASKS)->insertOrIgnore($tasks);
        });
        foreach ($days as $date => $dayId) {
            $ready = DB::table('nhl_pregame_context_run_games')->where('run_id', $run->id)->where('game_date', $date)->where('state', '!=', 'blocked')->exists();
            foreach (['league' => 'date', 'rankings' => 'cleanup'] as $area => $kind) {
                DB::table(self::TASKS)->insertOrIgnore(['goal_id' => $goals[$area], 'run_id' => $run->id, 'run_day_id' => $dayId,
                    'unit_key' => $kind.':'.$date, 'kind' => $kind,
                    'state' => $ready ? 'pending' : ($kind === 'cleanup' ? 'complete' : 'blocked'),
                    'last_error' => $ready ? null : 'All selected games on this date need source data.',
                    'created_at' => now(), 'updated_at' => now()]);
            }
        }
        DB::table(self::GOALS)->where('model_run_id', $model->id)->update(['run_id' => $run->id, 'updated_at' => now()]);
        $run->update(['options' => array_merge($run->options ?? [], ['workstream_seeded' => true])]);
        CoordinateNhlPregameWorkstreamsJob::dispatch((int) $model->id);
    }

    /** Reconcile enrolled goals periodically, including worker loss and newly committed sources. */
    public function tick(): void
    {
        if (! $this->available()) {
            return;
        }
        foreach (DB::table(self::GOALS)->distinct()->pluck('model_run_id') as $id) {
            CoordinateNhlPregameWorkstreamsJob::dispatch((int) $id);
        }
    }

    /** Invalidate dependent historical cutoffs after committed source changes; never dispatch per player. */
    public function sourcesChanged(string $season, ?string $from = null): void
    {
        if (! $this->available()) {
            return;
        }
        $pilot = app(NhlSatPregameWorkspace::class)->pilot();
        if ($pilot === null || ! in_array($season, $pilot->pregameContextSeasonIds(), true)) {
            return;
        }
        $from ??= substr($season, 0, 4).'-09-01 00:00:00';
        DB::update('UPDATE '.self::GOALS.' SET dirty_from = CASE WHEN dirty_from IS NULL OR dirty_from > ? THEN ? ELSE dirty_from END,
            generation = generation + 1, status = ?, updated_at = ? WHERE model_run_id = ?', [$from, $from, 'outdated', now(), $pilot->id]);
        if (DB::table(self::GOALS)->where('model_run_id', $pilot->id)->exists()) {
            CoordinateNhlPregameWorkstreamsJob::dispatch((int) $pilot->id);
        }
    }

    /** Claim only ready tasks within each live area limit, preserving legacy processing ownership. */
    public function coordinate(int $modelId): void
    {
        if (! $this->available()) {
            return;
        }
        $pilot = app(NhlSatPregameWorkspace::class)->pilot();
        if ($pilot === null || (int) $pilot->id !== $modelId) {
            return;
        }
        $lock = Cache::lock('pregame-goal-coordinate:'.$modelId, 120);
        if (! $lock->get()) {
            return;
        }
        try {
            $definition = DB::table(self::GOALS)->where('model_run_id', $modelId)->first();
            if ($definition !== null && ($definition->scope_hash !== $this->scopeHash($pilot)
                || $definition->context_version !== NhlPregameContextBuilder::VERSION)) {
                $seasons = $pilot->pregameContextSeasonIds();
                if ($seasons !== []) {
                    $this->sourcesChanged($seasons[0]);
                }
                DB::table(self::GOALS)->where('model_run_id', $modelId)->update([
                    'scope_hash' => $this->scopeHash($pilot), 'context_version' => NhlPregameContextBuilder::VERSION]);
            }
            $run = NhlPregameContextRun::latestForModel($modelId);
            if ($run === null || ! data_get($run->options, 'workstream_pipeline')) {
                if ($run !== null && in_array($run->status, ['queued', 'running', 'pausing', 'paused'], true)) {
                    return;
                }
                if ($run?->status !== 'stopped' && DB::table(self::GOALS)->where('model_run_id', $modelId)
                    ->where(fn ($goal) => $goal->whereNotNull('dirty_from')->orWhereNull('run_id'))->exists()) {
                    $this->repair($pilot);
                }
                return;
            }
            if ($run->status === 'queued') {
                return;
            }
            if ($run->status === 'stopped') {
                $this->stop($run);
                $this->refreshGoals($run);
                return;
            }
            if (! data_get($run->options, 'workstream_seeded')) {
                $this->seed($run);
            }
            $expected = 5 * (int) $run->total_games + 2 * $run->days()->count();
            if ($this->tasks($run)->count() !== $expected) {
                $run->update(['status' => 'failed', 'last_error' => 'Workstream task inventory is incomplete. Start a fresh missing-context repair.',
                    'options' => array_merge($run->options ?? [], ['workstream_inventory_failed' => true])]);
                $this->refreshGoals($run->fresh());
                return;
            }
            $this->recover($run);
            if ($run->status === 'pausing' && ! $this->tasks($run)->whereIn('state', ['claimed', 'working'])->exists()) {
                $run->update(['status' => 'paused']);
            }
            if (in_array($run->status, ['running', 'pausing'], true)) {
                $this->blockDependents($run);
                if ($run->status === 'running') {
                    foreach (DB::table(self::GOALS)->where('model_run_id', $modelId)->orderBy('id')->get() as $goal) {
                        $deliveries = DB::transaction(function () use ($goal, $run): array {
                            if (! NhlPregameContextRun::whereKey($run->id)->where('status', 'running')->exists()) {
                                return [];
                            }
                            $current = DB::table(self::GOALS)->where('id', $goal->id)->lockForUpdate()->first();
                            $active = DB::table(self::TASKS)->where('goal_id', $goal->id)->where('run_id', $run->id)
                                ->whereIn('state', ['claimed', 'working'])->count();
                            $slots = max(0, (int) $current->worker_limit - $active);
                            $deliveries = [];
                            foreach ($this->ready($run, $goal)->orderBy('t.id')->limit($slots)->lockForUpdate()->get('t.*') as $task) {
                                $token = (string) Str::uuid();
                                DB::table(self::TASKS)->where('id', $task->id)->update(['state' => 'claimed', 'claim_token' => $token,
                                    'lease_expires_at' => now()->addMinutes(30), 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);
                                $deliveries[] = [$task->id, $token];
                            }
                            return $deliveries;
                        });
                        foreach ($deliveries as [$id, $token]) {
                            WorkNhlPregameWorkstreamTaskJob::dispatch((int) $id, $token);
                        }
                    }
                }
                $this->finishRun($run);
            }
            $this->refreshGoals($run->fresh());
            $changed = DB::table(self::GOALS)->where('model_run_id', $modelId)->whereNotNull('dirty_from')->where('worker_limit', '>', 0)->get()
                ->contains(fn ($goal): bool => (int) $goal->generation !== (int) data_get($run->options, 'goal_generations.'.$goal->area, -1));
            if (in_array($run->fresh()->status, ['completed', 'failed'], true) && $changed) {
                $this->repair($pilot);
            }
        } finally {
            $lock->release();
        }
    }

    /** Read dependency-ready work without counting claimed jobs as executing workers. */
    private function ready(NhlPregameContextRun $run, object $goal): Builder
    {
        $query = DB::table(self::TASKS.' as t')->where('t.run_id', $run->id)->where('t.goal_id', $goal->id)->where('t.state', 'pending');
        if (in_array($goal->area, ['players', 'goalies', 'travel'], true)) {
            $query->whereExists(fn ($dep) => $dep->selectRaw('1')->from(self::TASKS.' as d')->join(self::GOALS.' as g', 'g.id', '=', 'd.goal_id')
                ->whereColumn('d.run_id', 't.run_id')->whereColumn('d.nhl_game_id', 't.nhl_game_id')->where('g.area', 'teams')->where('d.state', 'complete'));
        }
        if ($goal->area === 'rankings') {
            $query->where(function ($kinds): void {
                $kinds->where(function ($games): void {
                    $games->where('t.kind', 'game')->whereNotExists(fn ($dep) => $dep->selectRaw('1')->from(self::TASKS.' as d')
                        ->join(self::GOALS.' as g', 'g.id', '=', 'd.goal_id')->whereColumn('d.run_id', 't.run_id')
                        ->whereColumn('d.nhl_game_id', 't.nhl_game_id')->whereIn('g.area', ['teams', 'players', 'goalies', 'travel'])->where('d.state', '!=', 'complete'))
                        ->whereExists(fn ($dep) => $dep->selectRaw('1')->from(self::TASKS.' as d')->join(self::GOALS.' as g', 'g.id', '=', 'd.goal_id')
                            ->whereColumn('d.run_id', 't.run_id')->whereColumn('d.run_day_id', 't.run_day_id')->where('g.area', 'league')->where('d.state', 'complete'));
                })->orWhere(function ($cleanup): void {
                    $cleanup->where('t.kind', 'cleanup')->whereNotExists(fn ($dep) => $dep->selectRaw('1')->from(self::TASKS.' as d')
                        ->whereColumn('d.run_id', 't.run_id')->whereColumn('d.run_day_id', 't.run_day_id')
                        ->whereColumn('d.goal_id', 't.goal_id')->where('d.kind', 'game')->where('d.state', '!=', 'complete'));
                });
            });
        }
        return $query;
    }

    /** Execute one canonical page with rollback if Stop or a replacement lease wins. */
    public function execute(int $taskId, string $token): void
    {
        $task = DB::table(self::TASKS)->where('id', $taskId)->where('claim_token', $token)->where('state', 'claimed')->first();
        if ($task === null || $task->lease_expires_at < now()->toDateTimeString()) {
            return;
        }
        $run = NhlPregameContextRun::findOrFail($task->run_id);
        if (! in_array($run->status, ['running', 'pausing'], true)) {
            DB::table(self::TASKS)->where('id', $taskId)->where('claim_token', $token)->where('state', 'claimed')
                ->update(['state' => $run->status === 'stopped' ? 'stopped' : 'pending', 'claim_token' => null,
                    'lease_expires_at' => null, 'updated_at' => now()]);
            CoordinateNhlPregameWorkstreamsJob::dispatch((int) $run->model_run_id);
            return;
        }
        $started = DB::table(self::TASKS)->where('id', $taskId)->where('claim_token', $token)->where('state', 'claimed')
            ->where('lease_expires_at', '>', now())->update(['state' => 'working',
            'server' => gethostname() ?: 'unknown', 'worker_pid' => getmypid(), 'started_at' => now(),
            'lease_expires_at' => now()->addSeconds(420), 'updated_at' => now()]);
        if ($started !== 1) {
            return;
        }
        $goal = DB::table(self::GOALS)->where('id', $task->goal_id)->first();
        try {
            DB::transaction(function () use ($task, $taskId, $token, $run, $goal): void {
                $check = fn (): bool => ! $this->owns($taskId, $token, $run->id);
                $day = NhlPregameContextRunDay::findOrFail($task->run_day_id);
                $results = app(NhlPregameLeagueResults::class);
                $page = ['complete' => false, 'processed' => 0, 'cursor' => $task->cursor, 'total' => null];
                if ($goal->area === 'league') {
                    $page['complete'] = $results->buildNextAverages($run, $day, $check);
                } elseif ($task->kind === 'cleanup') {
                    $page['complete'] = ! $results->releaseDayPeerPage($run, $day, $check);
                } elseif ($goal->area === 'rankings') {
                    $page['complete'] = $results->attachGameComparisonsPage($run, $day, (int) $task->nhl_game_id, $check);
                } else {
                    $page = app(NhlPregameContextBuilder::class)->buildAreaPage($run, (int) $task->nhl_game_id,
                        $goal->area, (int) $task->cursor, $check);
                }
                $owner = NhlPregameContextRun::query()->whereKey($run->id)->lock(DB::getDriverName() === 'pgsql' ? 'for no key update' : true)->firstOrFail();
                if (! in_array($owner->status, ['running', 'pausing'], true) || ! $this->owns($taskId, $token, $run->id)) {
                    throw new NhlPregameContextRunStopped('Workstream task lost its execution authority.');
                }
                DB::table(self::TASKS)->where('id', $taskId)->where('claim_token', $token)->update([
                    'state' => $page['complete'] ? 'complete' : 'pending', 'cursor' => $page['cursor'],
                    'completed_units' => (int) $task->completed_units + $page['processed'], 'total_units' => $page['total'],
                    'claim_token' => null, 'lease_expires_at' => null, 'attempts' => 0, 'last_error' => null,
                    'completed_at' => $page['complete'] ? now() : null, 'updated_at' => now(),
                ]);
                if ($page['complete'] && $goal->area === 'league') {
                    $day->update(['league_state' => 'complete', 'league_completed_at' => now()]);
                } elseif ($page['complete'] && $task->kind === 'cleanup') {
                    $day->update(['context_state' => 'complete', 'context_completed_at' => now()]);
                } elseif ($page['complete'] && $goal->area === 'rankings') {
                    DB::table('nhl_pregame_context_run_games')->where('run_id', $run->id)->where('nhl_game_id', $task->nhl_game_id)
                        ->update(['state' => 'complete', 'snapshot_completed_at' => now(), 'comparisons_completed_at' => now(),
                            'completed_at' => now(), 'last_error' => null, 'updated_at' => now()]);
                }
            });
        } catch (NhlPregameContextDataUnavailable $error) {
            $this->failTask($taskId, $token, $error->getMessage(), 'blocked');
        } catch (NhlPregameContextRunStopped) {
            DB::table(self::TASKS)->where('id', $taskId)->where('claim_token', $token)->update([
                'state' => $run->fresh()->status === 'stopped' ? 'stopped' : 'pending', 'claim_token' => null,
                'lease_expires_at' => null, 'updated_at' => now()]);
        } finally {
            CoordinateNhlPregameWorkstreamsJob::dispatch((int) $run->model_run_id);
        }
    }

    /** Fence every commit by durable task lease and the owning run's lifecycle. */
    private function owns(int $id, string $token, int $runId): bool
    {
        try {
            if (Cache::store('redis')->get(NhlPregameContextRun::stopSignalKey($runId))) {
                return false;
            }
        } catch (Throwable) {
            // Durable status and task leases remain authoritative if cache is unavailable.
        }
        return DB::table(self::TASKS.' as task')->join('nhl_pregame_context_runs as run', 'run.id', '=', 'task.run_id')
            ->where('task.id', $id)->where('task.claim_token', $token)->where('task.state', 'working')
            ->where('task.lease_expires_at', '>', now())->where('run.id', $runId)->whereIn('run.status', ['running', 'pausing'])
            ->whereNotExists(fn ($bad) => $bad->selectRaw('1')->from('nhl_pregame_context_run_games as game')
                ->whereColumn('game.run_id', 'task.run_id')->whereColumn('game.nhl_game_id', 'task.nhl_game_id')
                ->whereIn('game.state', ['failed', 'blocked', 'stopped']))->exists();
    }

    /** Resume only unfinished task checkpoints; a new repair handles newly imported source blockers. */
    public function resume(NhlPregameContextRun $run): NhlPregameContextRun
    {
        if ($this->tasks($run)->count() !== 5 * (int) $run->total_games + 2 * $run->days()->count()) {
            throw ValidationException::withMessages(['pregame' => 'Task inventory is incomplete. Start a fresh missing-context repair.']);
        }
        if (! in_array($run->status, ['paused', 'pausing', 'failed'], true)) {
            throw ValidationException::withMessages(['pregame' => 'This workstream run cannot be resumed.']);
        }
        DB::transaction(function () use ($run): void {
            $run = NhlPregameContextRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            $failed = DB::table('nhl_pregame_context_run_games')->where('run_id', $run->id)->where('state', 'failed')->pluck('nhl_game_id');
            $failedDays = $this->tasks($run)->where('state', 'failed')->pluck('run_day_id');
            $this->tasks($run)->where(function ($tasks) use ($failed, $failedDays): void {
                $tasks->where('state', 'failed')->orWhere(function ($blocked) use ($failed, $failedDays): void {
                    $blocked->where('state', 'blocked')->where(fn ($dependants) => $dependants->whereIn('nhl_game_id', $failed)
                        ->orWhere(fn ($cleanup) => $cleanup->where('kind', 'cleanup')->whereIn('run_day_id', $failedDays)));
                });
            })->update(['state' => 'pending', 'attempts' => 0, 'claim_token' => null, 'lease_expires_at' => null, 'last_error' => null, 'updated_at' => now()]);
            DB::table('nhl_pregame_context_run_games')->where('run_id', $run->id)->whereIn('nhl_game_id', $failed)
                ->update(['state' => 'pending', 'last_error' => null, 'updated_at' => now()]);
            $run->update(['status' => 'running', 'last_error' => null]);
        });
        CoordinateNhlPregameWorkstreamsJob::dispatch((int) $run->model_run_id);
        return $run->fresh();
    }

    /** Revoke all unfinished task authority immediately after the parent Stop has committed. */
    public function requestStop(NhlPregameContextRun $run): NhlPregameContextRun
    {
        try {
            Cache::store('redis')->put(NhlPregameContextRun::stopSignalKey($run->id), true, now()->addDays(7));
        } catch (Throwable) {
            // The committed parent status is sufficient to fence writes.
        }
        $changed = NhlPregameContextRun::whereKey($run->id)->whereIn('status', ['queued', 'running', 'pausing', 'paused'])
            ->update(['status' => 'stopped', 'current_game_date' => null, 'updated_at' => now()]);
        if ($changed !== 1) {
            throw ValidationException::withMessages(['pregame' => 'This workstream run is no longer active.']);
        }
        // Parent fencing commits before child cleanup, avoiding a parent/child lock-order cycle.
        $this->stop($run);
        return $run->fresh();
    }

    /** Idempotently clear unfinished task/game/day activity while preserving committed snapshots. */
    public function stop(NhlPregameContextRun $run): void
    {
        if (! NhlPregameContextRun::whereKey($run->id)->where('status', 'stopped')->exists()) {
            return;
        }
        $this->tasks($run)->whereIn('state', ['pending', 'claimed', 'working'])->update(['state' => 'stopped',
            'claim_token' => null, 'lease_expires_at' => null, 'updated_at' => now()]);
        DB::table('nhl_pregame_context_run_games')->where('run_id', $run->id)->whereNotIn('state', ['complete', 'blocked'])
            ->update(['state' => 'stopped', 'updated_at' => now()]);
        foreach (['context_state', 'league_state'] as $stage) {
            DB::table('nhl_pregame_context_run_days')->where('run_id', $run->id)->whereIn($stage, ['pending', 'processing'])
                ->update([$stage => 'stopped', 'updated_at' => now()]);
        }
        app(NhlPregameContextOrchestrator::class)->refreshRunCounts($run->fresh());
        DB::table(self::GOALS)->where('model_run_id', $run->model_run_id)->where('run_id', $run->id)
            ->update(['status' => 'stopped', 'updated_at' => now()]);
    }

    /** Recover interrupted deliveries with a bounded retry budget; keep explicit errors visible. */
    public function failed(int $id, string $token, Throwable $error): void
    {
        $task = DB::table(self::TASKS)->where('id', $id)->where('claim_token', $token)->first();
        if ($task === null) {
            return;
        }
        if ($error instanceof \Illuminate\Queue\TimeoutExceededException && $task->attempts < 3) {
            DB::table(self::TASKS)->where('id', $id)->where('claim_token', $token)->update([
                'state' => 'pending', 'claim_token' => null, 'lease_expires_at' => null, 'last_error' => $error->getMessage(), 'updated_at' => now()]);
        } else {
            $this->failTask($id, $token, $error->getMessage(), 'failed');
        }
        $run = NhlPregameContextRun::find($task->run_id);
        if ($run !== null) {
            CoordinateNhlPregameWorkstreamsJob::dispatch((int) $run->model_run_id);
        }
    }

    /** Fail one task/game/date without blocking unrelated games or erasing completed context. */
    private function failTask(int $id, string $token, string $error, string $state): void
    {
        DB::transaction(function () use ($id, $token, $error, $state): void {
            $task = DB::table(self::TASKS)->where('id', $id)->where('claim_token', $token)->lockForUpdate()->first();
            if ($task === null) {
                return;
            }
            DB::table(self::TASKS)->where('id', $id)->update(['state' => $state, 'claim_token' => null,
                'lease_expires_at' => null, 'last_error' => Str::limit($error, 1800), 'updated_at' => now()]);
            $games = DB::table('nhl_pregame_context_run_games')->where('run_id', $task->run_id)
                ->whereNotIn('state', ['complete', 'blocked']);
            if ($task->nhl_game_id !== null) {
                $games->where('nhl_game_id', $task->nhl_game_id);
            } else {
                $date = DB::table('nhl_pregame_context_run_days')->where('id', $task->run_day_id)->value('game_date');
                $games->where('game_date', $date);
            }
            $games->update(['state' => $state, 'last_error' => Str::limit($error, 1800), 'updated_at' => now()]);
        });
    }

    /** Recover leases only after expiration, including queued deliveries lost during deployments. */
    private function recover(NhlPregameContextRun $run): void
    {
        $expired = $this->tasks($run)->whereIn('state', ['claimed', 'working'])->where('lease_expires_at', '<', now())->limit(100)->get();
        foreach ($expired as $task) {
            if ($task->attempts >= 3) {
                $this->failTask((int) $task->id, $task->claim_token, 'Worker lease expired repeatedly; inspect this task before retrying.', 'failed');
            } else {
                DB::table(self::TASKS)->where('id', $task->id)->where('claim_token', $task->claim_token)->where('lease_expires_at', '<', now())
                    ->update(['state' => 'pending', 'claim_token' => null, 'lease_expires_at' => null,
                        'last_error' => 'Worker lease expired; resuming its committed checkpoint.', 'updated_at' => now()]);
            }
        }
    }

    /** Propagate explicit source/task failures only to dependent unfinished work. */
    private function blockDependents(NhlPregameContextRun $run): void
    {
        $this->tasks($run)->where('state', 'pending')->whereNotNull('nhl_game_id')
            ->whereIn('nhl_game_id', DB::table('nhl_pregame_context_run_games')->where('run_id', $run->id)
                ->whereIn('state', ['blocked', 'failed', 'stopped'])->select('nhl_game_id'))
            ->update(['state' => 'blocked', 'last_error' => 'A source or prerequisite task needs repair for this game.', 'updated_at' => now()]);
        $days = $this->tasks($run)->where('kind', 'game')->whereIn('state', ['blocked', 'failed', 'stopped'])->distinct()->pluck('run_day_id');
        $this->tasks($run)->where('kind', 'cleanup')->where('state', 'pending')->whereIn('run_day_id', $days)
            ->update(['state' => 'blocked', 'last_error' => 'Ranking references are retained until the blocked game tasks are repaired.', 'updated_at' => now()]);
    }

    /** Retry only unfinished affected tasks and their dependants, preserving completed pages and other games. */
    public function repairArea(NhlModelRun $model, string $area, ?int $userId = null): NhlPregameContextRun
    {
        $this->enrol($model);
        $run = NhlPregameContextRun::latestForModel((int) $model->id);
        if ($run === null || ! data_get($run->options, 'workstream_pipeline') || $run->status === 'stopped') {
            return $this->repair($model, $userId);
        }
        $goal = DB::table(self::GOALS)->where('model_run_id', $model->id)->where('area', $area)->first();
        $affected = $this->tasks($run)->where('goal_id', $goal->id)->whereIn('state', ['blocked', 'failed'])->get();
        if ($affected->isEmpty()) {
            if (in_array($run->status, ['running', 'queued', 'pausing', 'paused'], true)) {
                throw ValidationException::withMessages(['area' => 'This area already has pending work. Adjust its allocation or resume the active run.']);
            }
            return $this->repair($model, $userId);
        }
        $sourceBlocked = DB::table('nhl_pregame_context_run_games')->where('run_id', $run->id)
            ->whereIn('game_date', DB::table('nhl_pregame_context_run_days')->whereIn('id', $affected->pluck('run_day_id'))->select('game_date'))
            ->whereJsonLength('readiness->source_blockers', '>', 0)->exists();
        if ($sourceBlocked) {
            if (in_array($run->status, ['running', 'queued', 'pausing', 'paused'], true)) {
                throw ValidationException::withMessages(['area' => 'Source-blocked games need a fresh source assessment after the current maintenance run finishes.']);
            }
            return $this->repair($model, $userId);
        }
        DB::transaction(function () use ($run, $affected): void {
            $owner = NhlPregameContextRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            if (in_array($owner->status, ['stopped', 'pausing', 'paused'], true)) {
                throw ValidationException::withMessages(['area' => 'Resume maintenance before repairing an individual area.']);
            }
            $days = $affected->pluck('run_day_id')->unique();
            $games = $affected->whereNotNull('nhl_game_id')->pluck('nhl_game_id');
            if ($affected->contains('kind', 'date')) {
                $games = DB::table('nhl_pregame_context_run_games')->where('run_id', $run->id)
                    ->whereIn('game_date', DB::table('nhl_pregame_context_run_days')->whereIn('id', $days)->select('game_date'))
                    ->whereIn('state', ['failed', 'blocked'])->pluck('nhl_game_id');
            }
            $this->tasks($run)->whereIn('state', ['blocked', 'failed'])->where(function ($tasks) use ($days, $games): void {
                $tasks->whereIn('nhl_game_id', $games)->orWhere(fn ($date) => $date->whereNull('nhl_game_id')->whereIn('run_day_id', $days));
            })->update(['state' => 'pending', 'claim_token' => null, 'lease_expires_at' => null, 'attempts' => 0,
                'last_error' => null, 'updated_at' => now()]);
            DB::table('nhl_pregame_context_run_games')->where('run_id', $run->id)->whereIn('nhl_game_id', $games)
                ->whereIn('state', ['failed', 'blocked'])->update(['state' => 'pending', 'last_error' => null, 'updated_at' => now()]);
            $owner->update(['status' => 'running', 'last_error' => null]);
        });
        CoordinateNhlPregameWorkstreamsJob::dispatch((int) $model->id);
        return $run->fresh();
    }

    /** Complete only when every selected task has reached a terminal outcome. */
    private function finishRun(NhlPregameContextRun $run): void
    {
        app(NhlPregameContextOrchestrator::class)->refreshRunCounts($run);
        if ($this->tasks($run)->whereIn('state', ['pending', 'claimed', 'working'])->exists()) {
            return;
        }
        $failed = $this->tasks($run)->where('state', 'failed')->exists();
        $accounted = (int) $run->completed_games + (int) $run->blocked_games + (int) $run->failed_games;
        if ($accounted !== (int) $run->total_games) {
            $run->update(['status' => 'failed', 'last_error' => 'Workstream outcomes do not account for every selected game. Investigate task completion before retrying.']);
            return;
        }
        $run->update(['status' => $failed ? 'failed' : 'completed', 'current_game_date' => null,
            'last_error' => $failed ? 'A workstream task failed. View issues for the specific task and repair action.' : null]);
    }

    /** Publish goal status from actual tasks, limits and source generations rather than decorated run bars. */
    private function refreshGoals(NhlPregameContextRun $run): void
    {
        $hasGames = DB::table('nhl_games')->whereIn('season_id', $run->season_ids)->where('game_type', 2)
            ->whereIn('game_state', ['OFF', 'FINAL'])->exists();
        foreach (DB::table(self::GOALS)->where('model_run_id', $run->model_run_id)->get() as $goal) {
            $counts = $this->tasks($run)->where('goal_id', $goal->id)->selectRaw('state, COUNT(*) AS qty')->groupBy('state')->pluck('qty', 'state');
            $remaining = (int) $counts->get('pending', 0) + (int) $counts->get('claimed', 0);
            $status = $counts->get('working', 0) > 0 ? 'working'
                : ($counts->get('failed', 0) > 0 || $counts->get('blocked', 0) > 0 ? 'blocked'
                    : ($remaining > 0 ? ((int) $goal->worker_limit === 0 || $run->status === 'paused' ? 'paused'
                        : ($this->ready($run, $goal)->exists() || $counts->get('claimed', 0) > 0 ? 'queued' : 'waiting')) : 'complete'));
            if ($run->status === 'stopped') {
                $this->tasks($run)->where('goal_id', $goal->id)->whereIn('state', ['pending', 'claimed', 'working'])
                    ->update(['state' => 'stopped', 'claim_token' => null, 'lease_expires_at' => null, 'updated_at' => now()]);
                $status = 'stopped';
            }
            $values = ['status' => $status, 'last_error' => null, 'checked_at' => now(), 'updated_at' => now()];
            if ($run->status === 'failed' && $remaining > 0) {
                $values['status'] = 'blocked';
                $values['last_error'] = $run->last_error;
            }
            if (! $hasGames && $counts->sum() === 0) {
                $values['status'] = 'unassessed';
                $values['last_error'] = 'No completed schedule games are available in these seasons. Import the schedule and results before assessing context.';
            }
            if ($run->status === 'completed' && (int) $goal->generation === (int) data_get($run->options, 'goal_generations.'.$goal->area, -1)) {
                $values['dirty_from'] = null;
            }
            DB::table(self::GOALS)->where('id', $goal->id)->where('generation', $goal->generation)->update($values);
        }
    }

    /** Keep all task reads explicitly attached to an existing parent run. */
    private function tasks(NhlPregameContextRun $run): Builder
    {
        return DB::table(self::TASKS)->where('run_id', $run->id);
    }

    /** Freeze model-defined Train/Test ownership independently of operational allocation changes. */
    private function scopeHash(NhlModelRun $model): string
    {
        $training = array_values(array_unique(array_map('strval', $model->train_season_ids ?? [])));
        sort($training);
        return hash('sha256', json_encode([$model->pregameContextSeasonIds(),
            $training, $model->target_season_id,
            NhlPregameContextBuilder::VERSION], JSON_THROW_ON_ERROR));
    }

    /** Read health and active workers for the six independently managed areas without creating work. */
    public function payload(NhlModelRun $model): array
    {
        if (! $this->available()) {
            return ['available' => false, 'areas' => []];
        }
        $goals = DB::table(self::GOALS)->where('model_run_id', $model->id)->get()->keyBy('area');
        $latest = NhlPregameContextRun::latestForModel((int) $model->id);
        $areas = [];
        foreach (self::AREAS as $area => $name) {
            $goal = $goals->get($area);
            $counts = $goal === null ? collect() : DB::table(self::TASKS)->where('goal_id', $goal->id)->where('run_id', $goal->run_id)
                ->selectRaw('state, COUNT(*) AS qty')->groupBy('state')->pluck('qty', 'state');
            $workers = $goal === null ? collect() : DB::table(self::TASKS.' as task')
                ->join('nhl_pregame_context_run_days as day', 'day.id', '=', 'task.run_day_id')
                ->where('task.goal_id', $goal->id)->where('task.run_id', $goal->run_id)
                ->where('task.state', 'working')->where('task.lease_expires_at', '>', now())->orderBy('task.id')->limit(25)->get([
                    'task.*', 'day.game_date', 'day.league_cursor', 'day.league_entity_cursor',
                ])->map(function ($task) use ($area, $name): array {
                    $progress = null;
                    if ($task->total_units > 0) {
                        $progress = ['percent' => min(100, round(100 * $task->completed_units / $task->total_units, 1)),
                            'label' => $task->completed_units.' / '.$task->total_units.' entities processed'];
                    } elseif ($area === 'league') {
                        $progress = ['percent' => null, 'label' => $task->league_cursor.' league populations completed · '
                            .((int) $task->league_entity_cursor === PHP_INT_MAX ? 'finalizing metric groups' : 'reading peer histories')];
                    }
                    return ['job_id' => 'workstream-task-'.$task->id, 'run_id' => (int) $task->run_id,
                        'task_id' => (int) $task->id, 'task' => $task->kind === 'cleanup' ? 'Releasing completed ranking references' : 'Updating '.strtolower($name),
                        'game_id' => $task->nhl_game_id, 'game_date' => $task->game_date, 'pool' => $area,
                        'server' => $task->server, 'pid' => $task->worker_pid, 'attempt' => (int) $task->attempts,
                        'queue' => 'projections', 'state' => 'running',
                        'started_at' => \Carbon\CarbonImmutable::parse($task->started_at, 'UTC')->toIso8601String(), 'work_progress' => $progress];
                });
            $remaining = (int) $counts->get('pending', 0) + (int) $counts->get('claimed', 0) + (int) $counts->get('working', 0);
            $expired = $workers->isEmpty() && (int) $counts->get('working', 0) > 0;
            $state = $goal?->status ?? 'unassessed';
            if ($workers->isNotEmpty()) {
                $state = 'working';
            } elseif ($latest?->status === 'stopped' && $goal !== null) {
                $state = 'stopped';
            } elseif ($goal !== null && ($latest?->status === 'paused' || ((int) $goal->worker_limit === 0 && $remaining > 0))) {
                $state = 'paused';
            } elseif ($expired || $counts->get('failed', 0) > 0 || $counts->get('blocked', 0) > 0) {
                $state = 'blocked';
            } elseif ($goal?->dirty_from) {
                $state = 'outdated';
            } elseif ($state === 'working') {
                $state = $remaining > 0 ? 'queued' : 'complete';
            }
            $blockers = $goal === null ? collect() : DB::table(self::TASKS.' as task')
                ->join('nhl_pregame_context_run_days as day', 'day.id', '=', 'task.run_day_id')
                ->where('task.goal_id', $goal->id)->where('task.run_id', $goal->run_id)
                ->whereIn('task.state', ['blocked', 'failed'])->orderBy('task.id')->limit(51)
                ->get(['task.id', 'task.nhl_game_id', 'task.last_error', 'day.game_date']);
            $areas[] = ['key' => $area, 'name' => $name, 'goal' => 'Keep '.strtolower($name).' context complete for this model’s Train and Test seasons.',
                'worker_limit' => (int) ($goal->worker_limit ?? 1), 'enrolled' => $goal !== null,
                'state' => $state, 'outdated' => $goal?->dirty_from !== null,
                'last_error' => $expired ? 'An execution lease expired. The coordinator will recover its committed checkpoint.' : $goal?->last_error,
                'counts' => $counts->all(), 'total' => (int) $counts->sum(), 'completed' => (int) $counts->get('complete', 0),
                'remaining' => $remaining,
                'workers' => $workers->all(), 'checked_at' => $goal?->checked_at,
                'blockers' => $blockers->take(50)->all(), 'blockers_truncated' => $blockers->count() > 50];
        }
        return ['available' => true, 'areas' => $areas, 'legacy_active' => $latest !== null
            && ! data_get($latest->options, 'workstream_pipeline') && in_array($latest->status, ['queued', 'running', 'pausing', 'paused'], true)];
    }
}
