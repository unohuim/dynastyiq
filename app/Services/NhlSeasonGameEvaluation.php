<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\RunNhlSeasonGameEvaluationJob;
use App\Models\NhlGame;
use App\Models\NhlModelRun;
use DomainException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Administrative admission and saved-result inspection for the existing chronological engine replay. */
class NhlSeasonGameEvaluation
{
    /** Readiness checks inspect stored source coverage, never fit a model or calculate predictions.
     * @return array{ready:bool,reasons:array,total_games:int}
     */
    public function readiness(NhlModelRun $model, bool $refresh = false): array
    {
        $key = 'nhl:season-readiness:'.$model->id.':'.$model->updated_at?->timestamp;
        if ($refresh) {
            Cache::forget($key);
        }
        return Cache::remember($key, 60, function () use ($model): array {
            $reasons = [];
            if ($model->model_family !== 'sat' || $model->status !== 'complete' || ! $model->target_season_id) {
                $reasons[] = 'Finish this SAT model and select its Test season.';
            }
            if ($reasons === []) {
                try {
                    app(NhlCustomGamePredictionInputs::class)->prepare((int) $model->id);
                } catch (DomainException|\RuntimeException $exception) {
                    $reasons[] = $exception->getMessage();
                }
            }
            if ($reasons !== []) {
                return ['ready' => false, 'reasons' => $reasons, 'missing_games' => [],
                    'total_games' => $model->target_season_id === null ? 0 : DB::table('nhl_games')->where('season_id', $model->target_season_id)->where('game_type', 2)->count()];
            }
            $games = DB::table('nhl_games')->where('season_id', $model->target_season_id)->where('game_type', 2)->get([
                'nhl_game_id', 'game_state', 'game_date', 'start_time_utc', 'home_team_id', 'away_team_id', 'home_team_score', 'away_team_score',
            ]);
            if ($games->isEmpty()) {
                $reasons[] = 'Import the Test season schedule and completed games.';
            }
            $missing = [];
            foreach ($games->chunk(50) as $page) {
                $ids = $page->pluck('nhl_game_id');
                $coverage = [];
                foreach (['nhl_shot_attempts_facts', 'nhl_game_summaries', 'nhl_player_game_strength_summaries', 'nhl_unit_shifts', 'nhl_boxscores'] as $table) {
                    $coverage[$table] = DB::table($table)->whereIn('nhl_game_id', $ids)->distinct()->pluck('nhl_game_id')->flip();
                }
                $participants = DB::table('nhl_game_summaries as s')->leftJoin('players as p', 'p.nhl_id', '=', 's.nhl_player_id')
                    ->whereIn('s.nhl_game_id', $ids)->where('s.toi', '>', 0)
                    ->get(['s.nhl_game_id', 's.nhl_team_id', 's.nhl_player_id', 's.goalie_started', 'p.nhl_id', 'p.is_goalie', 'p.position', 'p.pos_type'])
                    ->groupBy('nhl_game_id');
                $contexts = $this->contextRows((int) $model->id, $ids->all());
                foreach ($page as $game) {
                    $problems = [];
                    if (! in_array($game->game_state, ['OFF', 'FINAL'], true) || ! $game->start_time_utc
                        || $game->home_team_score === null || $game->away_team_score === null || $game->home_team_score === $game->away_team_score) {
                        $problems[] = 'completed game, start cutoff or final result';
                    }
                    foreach ($coverage as $table => $found) {
                        if (! $found->has($game->nhl_game_id)) {
                            $problems[] = $table;
                        }
                    }
                    $rows = $participants->get($game->nhl_game_id, collect());
                    foreach (['away', 'home'] as $side) {
                        $team = (int) $game->{$side.'_team_id'};
                        $opponent = (int) $game->{($side === 'away' ? 'home' : 'away').'_team_id'};
                        $parent = $contexts['teams'][$game->nhl_game_id][$team] ?? null;
                        if ($parent === null || (int) $parent->opponent_team_id !== $opponent || $parent->source_cutoff_at > $game->start_time_utc) {
                            $problems[] = $side.' committed team context';
                            continue;
                        }
                        $own = $rows->where('nhl_team_id', $team);
                        $skaters = $own->filter(fn ($row): bool => ! $row->is_goalie && $row->position !== 'G' && $row->pos_type !== 'G');
                        $starters = $own->where('goalie_started', true);
                        if ($skaters->isEmpty() || $starters->count() !== 1) {
                            $problems[] = $side.' historical lineup/starting goalie';
                        }
                        foreach ($skaters->concat($starters)->unique('nhl_player_id') as $player) {
                            $context = $contexts['players'][$game->nhl_game_id][$player->nhl_player_id] ?? null;
                            if ($player->nhl_id === null || $context === null || (int) $context->nhl_team_id !== $team
                                || (int) $context->opponent_team_id !== $opponent || (int) $context->team_context_id !== (int) $parent->id
                                || $context->source_cutoff_at !== $parent->source_cutoff_at) {
                                $problems[] = $side.' linked player/starter context';
                                break;
                            }
                        }
                    }
                    if ($problems !== []) {
                        $missing[] = ['game_id' => (int) $game->nhl_game_id, 'date' => $game->game_date, 'missing' => array_values(array_unique($problems))];
                    }
                }
            }
            foreach ((array) $model->train_season_ids as $season) {
                $history = DB::table('nhl_games')->where('season_id', $season)->where('game_type', 2)->whereIn('game_state', ['OFF', 'FINAL']);
                foreach (['nhl_shot_attempts_facts', 'nhl_game_summaries', 'nhl_unit_shifts'] as $table) {
                    if (! DB::table($table)->whereIn('nhl_game_id', (clone $history)->select('nhl_game_id'))->exists()) {
                        $reasons[] = 'Missing '.$table.' in Train '.$season.'.';
                    }
                }
            }
            if ($missing !== []) {
                $reasons[] = count($missing).' Test games have missing sources or committed context.';
            }
            return ['ready' => $reasons === [], 'reasons' => $reasons, 'total_games' => $games->count(), 'missing_games' => $missing];
        });
    }

    /** Freeze only committed same-model/version context; matching parents are checked separately.
     * @param list<int> $gameIds
     * @return array<string,array>
     */
    public function contextRows(int $modelId, array $gameIds): array
    {
        $result = ['teams' => [], 'players' => []];
        foreach (['teams' => 'team', 'players' => 'player'] as $key => $entity) {
            $rows = DB::table('nhl_'.$entity.'_game_pregame_contexts as c')->where('c.model_run_id', $modelId)
                ->where('c.context_version', NhlPregameContextBuilder::VERSION)->whereIn('c.nhl_game_id', $gameIds)
                ->whereExists(fn ($q) => $q->selectRaw('1')->from('nhl_pregame_context_run_games as w')
                    ->whereColumn('w.run_id', 'c.run_id')->whereColumn('w.nhl_game_id', 'c.nhl_game_id')
                    ->where('w.state', 'complete')->whereNotNull('w.comparisons_completed_at'))
                ->get($entity === 'team'
                    ? ['c.id', 'c.nhl_game_id', 'c.nhl_team_id', 'c.opponent_team_id', 'c.source_cutoff_at']
                    : ['c.id', 'c.nhl_game_id', 'c.nhl_player_id', 'c.nhl_team_id', 'c.opponent_team_id', 'c.team_context_id', 'c.source_cutoff_at']);
            foreach ($rows as $row) {
                $result[$key][$row->nhl_game_id][$row->{$entity === 'team' ? 'nhl_team_id' : 'nhl_player_id'}] = $row;
            }
        }
        return $result;
    }

    /** Code/settings identity prevents a resumed run from quietly mixing engine versions. */
    public function identity(NhlModelRun $model): string
    {
        return hash('sha256', json_encode([app(NhlCustomGamePredictionEngine::class)->definition(),
            NhlCustomGamePredictionInputs::conversionProfileIdentity($model),
            $model->train_season_ids, $model->target_season_id, $model->status, $model->model_version,
            $model->updated_at?->format('Y-m-d H:i:s.u'),
            DB::table('nhl_pregame_context_run_games as w')->join('nhl_pregame_context_runs as r', 'r.id', '=', 'w.run_id')
                ->where('r.model_run_id', $model->id)->where('w.state', 'complete')
                ->selectRaw('COUNT(*) AS games, MAX(w.comparisons_completed_at) AS committed_at')->first(),
            array_map('hash_file', array_fill(0, 3, 'sha256'), [__DIR__.'/NhlCustomGamePredictionEngine.php',
                __DIR__.'/NhlCustomGamePredictionInputs.php', __DIR__.'/NhlCustomGameDefense.php'])], JSON_THROW_ON_ERROR));
    }

    /** Admit one append-only season run under the model lock; execution is always asynchronous. */
    public function start(NhlModelRun $model, ?int $userId): int
    {
        if (config('queue.default') === 'sync') {
            throw ValidationException::withMessages(['run' => 'Configure an asynchronous projections queue first.']);
        }
        $ready = $this->readiness($model, true);
        if (! $ready['ready']) {
            throw ValidationException::withMessages(['run' => implode(' ', $ready['reasons'])]);
        }
        return DB::transaction(function () use ($model, $userId, $ready): int {
            $locked = NhlModelRun::query()->whereKey($model->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'complete' || $locked->train_season_ids !== $model->train_season_ids
                || $locked->target_season_id !== $model->target_season_id) {
                throw ValidationException::withMessages(['run' => 'The model changed during readiness checks. Check again.']);
            }
            $model = $locked;
            if (DB::table('nhl_custom_game_prediction_runs')->where('model_run_id', $model->id)->where('definition->entrypoint', 'season_games')->whereIn('status', ['queued', 'running'])->exists()) {
                throw ValidationException::withMessages(['run' => 'Finish or resume this model’s active season run first.']);
            }
            $games = DB::table('nhl_games')->where('season_id', $model->target_season_id)->where('game_type', 2)
                ->orderBy('start_time_utc')->orderBy('nhl_game_id')->pluck('nhl_game_id')->map(fn ($id): int => (int) $id)->all();
            $id = DB::table('nhl_custom_game_prediction_runs')->insertGetId([
                'name' => $model->name.' · Test season', 'algorithm_version' => config('nhl-custom-prediction.algorithm_version'),
                'season_id' => $model->target_season_id, 'model_run_id' => $model->id, 'status' => 'queued',
                'definition' => json_encode(['calculation' => app(NhlCustomGamePredictionEngine::class)->definition(),
                    'source' => ['model_id' => $model->id, 'model_name' => $model->name, 'train_seasons' => $model->train_season_ids,
                        'test_season' => $model->target_season_id], 'game_ids' => $games, 'identity' => $this->identity($model),
                    'schedule_enabled' => true, 'entrypoint' => 'season_games'], JSON_THROW_ON_ERROR),
                'total_games' => $ready['total_games'], 'created_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::afterCommit(fn () => $this->dispatch($id));
            return $id;
        });
    }

    /** Dispatch only this run; broker failures leave an actionable failed record. */
    private function dispatch(int $id): void
    {
        try {
            $generation = (string) \Illuminate\Support\Str::uuid();
            Cache::put('nhl:season-evaluation:'.$id.':generation', $generation, now()->addDays(2));
            RunNhlSeasonGameEvaluationJob::dispatch($id, $generation);
        } catch (Throwable $exception) {
            $this->fail($id, $exception->getMessage());
            throw $exception;
        }
    }

    /** Resume failed or abandoned work, retaining every committed prediction and checkpoint. */
    public function resume(NhlModelRun $model, int $id): void
    {
        DB::transaction(function () use ($model, $id): void {
            NhlModelRun::query()->whereKey($model->id)->lockForUpdate()->firstOrFail();
            $run = DB::table('nhl_custom_game_prediction_runs')->where('model_run_id', $model->id)->where('id', $id)->lockForUpdate()->firstOrFail();
            $definition = json_decode($run->definition, true, 512, JSON_THROW_ON_ERROR);
            if (($definition['entrypoint'] ?? null) !== 'season_games' || ($definition['identity'] ?? null) !== $this->identity($model)) {
                throw ValidationException::withMessages(['run' => 'The model or algorithm changed. Start a new season run.']);
            }
            if ($run->status !== 'failed' && (! in_array($run->status, ['queued', 'running'], true) || \Carbon\CarbonImmutable::parse($run->updated_at)->greaterThan(now()->subMinutes(5)))) {
                throw ValidationException::withMessages(['run' => 'This run is complete or still has active work.']);
            }
            if (DB::table('nhl_custom_game_prediction_runs')->where('model_run_id', $model->id)->where('id', '<>', $id)->where('definition->entrypoint', 'season_games')->whereIn('status', ['queued', 'running'])->exists()) {
                throw ValidationException::withMessages(['run' => 'Another season run is active for this model.']);
            }
            if (str_contains(mb_strtolower((string) $run->last_error), 'checkpoint')) {
                // An explicit Resume may discard only a broken transient manifest; saved game rows remain authoritative.
                Cache::forget('nhl:season-evaluation:'.$id.':work');
            }
            DB::table('nhl_custom_game_prediction_runs')->where('id', $id)->update(['status' => 'queued', 'last_error' => null, 'updated_at' => now()]);
            DB::afterCommit(fn () => $this->dispatch($id));
        });
    }

    /** Advance a bounded phase under a run-owned lock; duplicate deliveries share one cursor. */
    public function advance(int $id): bool
    {
        $lock = Cache::lock('nhl:season-evaluation:'.$id.':lock', 180);
        if (! $lock->get()) {
            return false;
        }
        try {
            $run = DB::table('nhl_custom_game_prediction_runs')->where('id', $id)->firstOrFail();
            if (! in_array($run->status, ['queued', 'running'], true)) {
                return true;
            }
            $model = NhlModelRun::query()->findOrFail($run->model_run_id);
            $definition = json_decode($run->definition, true, 512, JSON_THROW_ON_ERROR);
            if (($definition['identity'] ?? null) !== $this->identity($model)) {
                throw new DomainException('Model or algorithm changed during this run. Start a new run to keep results comparable.');
            }
            $checkpoints = app(NhlCustomGameForecast::class);
            $work = $checkpoints->readSeasonCheckpoint($id);
            if ($work === null) {
                $reader = app(NhlCustomGamePredictionInputs::class);
                $reader->beginSeasonReplay((int) $model->id, $definition['game_ids']);
                $work = ['phase' => 'season', 'fingerprint' => $definition['identity'], 'reader' => $reader];
            }
            DB::table('nhl_custom_game_prediction_runs')->where('id', $id)->update(['status' => 'running', 'updated_at' => now()]);
            $started = microtime(true);
            for ($steps = 0; $steps < 25; $steps++) {
                $state = $work['reader']->advanceSeasonReplay(function (array $event) use ($id, $model): void {
                    if ($event['error'] === null) {
                        $this->validateEventContext((int) $model->id, $event);
                        $event['goalies'] = $this->goalieDetails((int) $model->id, $event);
                    }
                    app(NhlCustomGamePredictionReplay::class)->saveSeasonEvent($id, $event);
                });
                if ($state['complete'] || microtime(true) - $started >= 8) {
                    break;
                }
            }
            $checkpoints->writeSeasonCheckpoint($id, $work);
            DB::table('nhl_custom_game_prediction_runs')->where('id', $id)->update([
                'status' => $state['complete'] ? 'complete' : 'running', 'updated_at' => now(),
            ]);
            return $state['complete'];
        } finally {
            $lock->release();
        }
    }

    /** Preserve the error without modifying committed games. */
    public function fail(int $id, string $message): void
    {
        DB::table('nhl_custom_game_prediction_runs')->where('id', $id)->whereIn('status', ['queued', 'running'])
            ->update(['status' => 'failed', 'last_error' => mb_substr($message, 0, 4000), 'updated_at' => now()]);
    }

    /** Validate the committed parents used by this event before saving its immutable inputs. */
    private function validateEventContext(int $modelId, array $event): void
    {
        $game = $event['game'];
        $contexts = $this->contextRows($modelId, [(int) $game->nhl_game_id]);
        foreach (['away', 'home'] as $side) {
            $input = $event['input']['teams'][$side];
            $parent = $contexts['teams'][$game->nhl_game_id][$input['id']] ?? null;
            $opponent = $event['input']['teams'][$side === 'away' ? 'home' : 'away']['id'];
            if ($parent === null || (int) $parent->id !== (int) $input['context_id']
                || (int) $parent->opponent_team_id !== (int) $opponent || $parent->source_cutoff_at > $game->start_time_utc
                || $parent->source_cutoff_at !== ($input['context_provenance']['source_cutoff_at'] ?? null)) {
                throw new DomainException('Committed team context changed or is incomplete. Refresh readiness before resuming.');
            }
            foreach ($input['players'] as $id => $player) {
                $child = $contexts['players'][$game->nhl_game_id][$id] ?? null;
                if ($child === null || (int) $child->id !== (int) $player['context_id']
                    || (int) $child->nhl_team_id !== (int) $input['id'] || (int) $child->opponent_team_id !== (int) $opponent
                    || (int) $child->team_context_id !== (int) $parent->id || $child->source_cutoff_at !== $parent->source_cutoff_at) {
                    throw new DomainException('Committed player context changed or is incomplete. Refresh readiness before resuming.');
                }
            }
        }
    }

    /** Snapshot selected starters' descriptive pregame windows and actual boxscore separately from the model input.
     * @param array<string,mixed> $event
     * @return array<string,array>
     */
    private function goalieDetails(int $modelId, array $event): array
    {
        $result = [];
        $contexts = $this->contextRows($modelId, [(int) $event['game']->nhl_game_id]);
        foreach (['away', 'home'] as $side) {
            $id = $event['input']['teams'][$side]['starting_goalie_id'];
            $context = $contexts['players'][$event['game']->nhl_game_id][$id] ?? null;
            $team = $event['input']['teams'][$side];
            if ($id === null || $context === null || (int) $context->nhl_team_id !== (int) $team['id']
                || (int) $context->team_context_id !== (int) $team['context_id']
                || $context->source_cutoff_at !== ($team['context_provenance']['source_cutoff_at'] ?? null)) {
                throw new DomainException('Selected starter context is missing or mismatched. Refresh readiness before resuming.');
            }
            $box = DB::table('nhl_boxscores')->where('nhl_game_id', $event['game']->nhl_game_id)->where('nhl_player_id', $id)
                ->first(['saves', 'shots_against', 'goals_against', 'toi_seconds']);
            $metrics = json_decode(DB::table('nhl_player_game_pregame_contexts')->where('id', $context->id)
                ->value('metrics->all') ?? '{}', true, 512, JSON_THROW_ON_ERROR) ?? [];
            $result[$side] = ['id' => (int) $id, 'name' => DB::table('players')->where('nhl_id', $id)->value('full_name') ?? (string) $id,
                'context_id' => (int) $context->id, 'context' => ['source_cutoff_at' => $context->source_cutoff_at,
                    'windows' => Arr::only($metrics, ['train', 'last_game', 'last_5', 'last_10', 'last_20', 'last_season'])],
                'actual' => $box === null ? null : ['saves' => $box->saves, 'shots_against' => $box->shots_against,
                    'goals_against' => $box->goals_against, 'toi_seconds' => $box->toi_seconds, 'basis' => 'official_full_appearance']];
        }
        return $result;
    }

    /** Keep accuracy on frozen picks; official ending metadata only splits displayed losses.
     * @return array<string,mixed>
     */
    public function summary(int $id): array
    {
        $rows = DB::table('nhl_custom_game_predictions')->where('run_id', $id)->get(['id', 'nhl_game_id', 'status', 'winner_correct']);
        $wins = $rows->where('winner_correct', true)->count();
        $losses = $rows->where('winner_correct', false)->whereNotNull('winner_correct')->count();
        $overtimeLosses = $this->overtimeLossCounts([$id])[$id] ?? 0;
        $teams = DB::table('nhl_custom_game_prediction_teams')->whereIn('prediction_id', $rows->where('status', 'complete')->pluck('id'))->get([
            'prediction_id', 'goals_prediction', 'actual_ev_goals', 'actual_pp_goals', 'actual_pk_goals', 'pk_goals_prediction']);
        $known = static fn ($row): bool => $row->actual_ev_goals !== null && $row->actual_pp_goals !== null
            && ($row->pk_goals_prediction === null || $row->actual_pk_goals !== null);
        $actualGoals = static fn ($row): float => $row->actual_ev_goals + $row->actual_pp_goals
            + ($row->pk_goals_prediction === null ? 0 : $row->actual_pk_goals);
        $pairs = $teams->filter($known)
            ->map(fn ($row): array => [(float) $row->goals_prediction, $actualGoals($row)]);
        $games = $teams->groupBy('prediction_id')->filter(fn ($group): bool => $group->count() === 2
            && $group->every($known))
            ->map(fn ($group): array => [(float) $group->sum('goals_prediction'), $group->sum($actualGoals)]);
        return ['wins' => $wins, 'losses' => $losses,
            'regulation_losses' => $losses - $overtimeLosses, 'overtime_losses' => $overtimeLosses, 'win_pct' => $wins + $losses > 0 ? 100 * $wins / ($wins + $losses) : null,
            'predicted' => $rows->where('status', 'complete')->count(), 'unpicked' => $rows->where('status', 'complete')->whereNull('winner_correct')->count(),
            'excluded' => $rows->where('status', 'excluded')->count(),
            'team_goals' => app(NhlCustomGamePredictionEngine::class)->accuracy($pairs->values()->all()),
            'game_goals' => app(NhlCustomGamePredictionEngine::class)->accuracy($games->values()->all())];
    }

    /**
     * Batch the shared display-only overtime loss split for the selected run and paginated history.
     *
     * @param array<int, int> $runIds
     * @return array<int, int>
     */
    public function overtimeLossCounts(array $runIds): array
    {
        $picks = DB::table('nhl_custom_game_predictions')->whereIn('run_id', $runIds)
            ->where('winner_correct', false)->get(['run_id', 'nhl_game_id']);
        $overtimeGames = NhlGame::query()->whereIn('nhl_game_id', $picks->pluck('nhl_game_id')->unique())
            ->get(['nhl_game_id', 'game_outcome', 'period_type'])->filter(function (NhlGame $game): bool {
                foreach ([data_get($game->game_outcome, 'lastPeriodType'),
                    data_get($game->game_outcome, 'periodType'), $game->period_type] as $value) {
                    $period = strtoupper(trim((string) $value));
                    if (in_array($period, ['REG', 'OT', 'SO'], true)) {
                        return in_array($period, ['OT', 'SO'], true);
                    }
                }
                return false;
            })->pluck('nhl_game_id')->flip();

        return $picks->filter(fn (object $pick): bool => $overtimeGames->has($pick->nhl_game_id))
            ->countBy('run_id')->all();
    }

    /** Compare only identical games/outcomes; keep unmatched games visible as a separate count.
     * @return array<string,mixed>
     */
    public function compare(int $first, int $second): array
    {
        $a = DB::table('nhl_custom_game_predictions')->where('run_id', $first)->where('status', 'complete')->get(['nhl_game_id', 'winner_correct', 'home_actual_goals', 'away_actual_goals'])->keyBy('nhl_game_id');
        $b = DB::table('nhl_custom_game_predictions')->where('run_id', $second)->where('status', 'complete')->get(['nhl_game_id', 'winner_correct', 'home_actual_goals', 'away_actual_goals'])->keyBy('nhl_game_id');
        $changes = [];
        $winsA = $winsB = $matched = 0;
        foreach ($a as $id => $row) {
            $other = $b->get($id);
            if ($other === null || $row->winner_correct === null || $other->winner_correct === null
                || $row->home_actual_goals !== $other->home_actual_goals || $row->away_actual_goals !== $other->away_actual_goals) {
                continue;
            }
            $matched++;
            $winsA += (int) $row->winner_correct;
            $winsB += (int) $other->winner_correct;
            if ((bool) $row->winner_correct !== (bool) $other->winner_correct) {
                $changes[] = ['game_id' => (int) $id, 'before' => (bool) $row->winner_correct, 'after' => (bool) $other->winner_correct];
            }
        }
        $schedule = DB::table('nhl_games')->whereIn('nhl_game_id', array_column($changes, 'game_id'))
            ->get(['nhl_game_id', 'game_date', 'away_team_abbrev', 'home_team_abbrev'])->keyBy('nhl_game_id');
        foreach ($changes as &$change) {
            $game = $schedule->get($change['game_id']);
            $change += ['date' => $game->game_date ?? null, 'away' => $game->away_team_abbrev ?? null, 'home' => $game->home_team_abbrev ?? null];
        }
        unset($change);
        return ['matched' => $matched, 'unmatched' => count(array_unique([...$a->keys()->all(), ...$b->keys()->all()])) - $matched,
            'before_win_pct' => $matched > 0 ? 100 * $winsA / $matched : null,
            'after_win_pct' => $matched > 0 ? 100 * $winsB / $matched : null, 'changes' => $changes];
    }
}
