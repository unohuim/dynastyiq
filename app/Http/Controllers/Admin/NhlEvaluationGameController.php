<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ForecastNhlEvaluationPlayerJob;
use App\Jobs\PredictNhlEvaluationDayJob;
use App\Models\NhlEvaluationGameForecast;
use App\Models\NhlGame;
use App\Models\NhlNextGameEvaluation;
use App\Services\NhlEvaluationGameForecaster;
use App\Services\NhlNextGameEvaluationService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use RuntimeException;

/** Evaluation-only game experiments; all routes inherit the existing super-admin protection. */
class NhlEvaluationGameController extends Controller
{
    /** List one game day and inspect one saved forecast without starting work. */
    public function index(Request $request, NhlNextGameEvaluation $evaluation)
    {
        abort_unless($evaluation->version === NhlNextGameEvaluation::OUTLOOK_VERSION, 404);
        $filters = $request->validate(['date' => ['nullable', 'date_format:Y-m-d'],
            'strength' => ['nullable', Rule::in(NhlNextGameEvaluationService::STRENGTHS)],
            'period' => ['nullable', Rule::in(['q1', 'q2', 'q3', 'q4'])],
            'forecast' => ['nullable', 'integer', 'min:1']]);
        $filters['date'] ??= DB::table('nhl_games')->where('season_id', $evaluation->season_id)
            ->where('game_type', 2)->min('game_date') ?? now()->toDateString();
        $filters['strength'] ??= 'ev';
        $filters['period'] ??= 'q1';
        $games = NhlGame::query()->where('season_id', $evaluation->season_id)->where('game_type', 2)
            ->whereDate('game_date', $filters['date'])->orderBy('start_time_utc')->limit(20)
            ->get(['nhl_game_id', 'game_date', 'away_team_id', 'home_team_id', 'away_team_abbrev', 'home_team_abbrev', 'game_state']);
        $works = NhlEvaluationGameForecast::query()->where('evaluation_id', $evaluation->id)
            ->whereIn('nhl_game_id', $games->pluck('nhl_game_id'))->where('strength', $filters['strength'])
            ->where('period', $filters['period'])->get(['id', 'nhl_game_id', 'strength', 'period', 'status',
                'phase', 'cursor', 'total', 'last_error', 'forecasted_at', 'updated_at']);
        $selected = isset($filters['forecast']) ? $works->firstWhere('id', (int) $filters['forecast']) : $works->first();
        $players = fn () => $this->displayPlayers($selected);

        return Inertia::render('Admin/Evaluations/Games', [
            'evaluation' => $evaluation->only('id', 'name', 'season_id', 'status'), 'filters' => $filters,
            'games' => $games, 'work' => $works->map(fn ($work) => $work->only('id', 'nhl_game_id', 'status')),
            'selected' => $selected ? $this->row($selected) : null, 'players' => $players,
            'leagueSummaries' => fn () => $this->leagueSummaries($works),
            'dayWork' => $this->dayWork($evaluation),
            'league' => fn () => DB::table('nhl_evaluation_player_outlooks')->where('evaluation_id', $evaluation->id)
                ->where('strength', $filters['strength'])->where('period', $filters['period'])->where('revision', 1)
                ->avg('sat_per_60'),
            'active' => DB::table('nhl_evaluation_game_forecasts as f')
                ->join('nhl_games as g', 'g.nhl_game_id', '=', 'f.nhl_game_id')
                ->where('f.evaluation_id', $evaluation->id)->whereIn('f.status', NhlEvaluationGameForecast::ACTIVE)
                ->first(['f.id', 'f.nhl_game_id', 'f.strength', 'f.period', 'g.game_date']),
        ]);
    }

    /** Start a chronological multi-day simulation through the requested inclusive date. */
    public function simulate(Request $request, NhlNextGameEvaluation $evaluation)
    {
        $request->merge(['mode' => 'simulation']);

        return $this->predictDay($request, $evaluation);
    }

    /** Save bounded work; the queue admits games sequentially using the single-game path. */
    public function predictDay(Request $request, NhlNextGameEvaluation $evaluation)
    {
        $this->requireAsync();
        abort_unless($evaluation->version === NhlNextGameEvaluation::OUTLOOK_VERSION, 404);
        $input = $request->validate(['date' => ['required', 'date_format:Y-m-d'],
            'strength' => ['required', Rule::in(NhlNextGameEvaluationService::STRENGTHS)],
            'period' => ['required', Rule::in(['q1', 'q2', 'q3', 'q4'])],
            'mode' => ['sometimes', Rule::in(['day', 'simulation'])]]);
        $input['mode'] ??= 'day';
        DB::transaction(function () use ($evaluation, $input): void {
            $evaluation = NhlNextGameEvaluation::query()->lockForUpdate()->findOrFail($evaluation->id);
            abort_unless($evaluation->status === 'ready', 422, 'Wait until this evaluation is Ready.');
            $existing = $this->dayWork($evaluation);
            if (($existing['status'] ?? null) === 'running') {
                abort_unless($existing['date'] === $input['date'] && $existing['strength'] === $input['strength']
                    && $existing['period'] === $input['period'] && ($existing['mode'] ?? 'day') === $input['mode'], 409, 'Finish the active day or simulation first.');
                if (! $existing['can_resume']) {
                    return;
                }
            }
            $simulation = $input['mode'] === 'simulation';
            $query = NhlGame::query()->where('season_id', $evaluation->season_id)->where('game_type', 2)
                ->whereDate('game_date', $simulation ? '<=' : '=', $input['date'])->whereIn('game_state', ['OFF', 'FINAL']);
            if ($simulation) {
                $query->whereNotExists(function ($query) use ($evaluation, $input): void {
                    $query->selectRaw('1')->from('nhl_evaluation_game_forecasts as forecasts')
                        ->whereColumn('forecasts.nhl_game_id', 'nhl_games.nhl_game_id')
                        ->where('forecasts.evaluation_id', $evaluation->id)->where('forecasts.strength', $input['strength'])
                        ->where('forecasts.period', $input['period'])->where('forecasts.status', 'compared')
                        ->where('forecasts.lineup->0->evidence_version', NhlEvaluationGameForecaster::CONTEXT_VERSION);
                });
            }
            $limit = $simulation ? 2000 : 20;
            $games = $query->orderBy('start_time_utc')->orderBy('nhl_game_id')->limit($limit + 1)->get(['nhl_game_id', 'game_date']);
            $ids = $games->pluck('nhl_game_id')->all();
            abort_unless(count($ids) <= $limit && ($simulation || count($ids) > 0), 422, 'Selection exceeds the safety limit or contains no eligible games.');
            $day = [...$input, 'game_ids' => $ids, 'cursor' => 0, 'status' => 'running',
                'start_date' => $games->first()?->getRawOriginal('game_date'),
                'token' => (string) Str::uuid(), 'updated_at' => now()->toISOString(), 'last_error' => null];
            if ($ids === []) {
                $day['status'] = 'completed';
            }
            $evaluation->update(['inputs' => [...$evaluation->inputs, 'predict_day' => $day]]);
            if ($ids !== []) {
                PredictNhlEvaluationDayJob::dispatch($evaluation->id, $day['token']);
            }
        });

        return response()->json(['date' => $input['date']], 202);
    }

    /** Publish only day progress; queued IDs and fencing tokens remain server-owned. */
    private function dayWork(NhlNextGameEvaluation $evaluation): ?array
    {
        $day = $evaluation->inputs['predict_day'] ?? null;
        if (! $day) {
            return null;
        }

        return [...collect($day)->only(['date', 'start_date', 'mode', 'strength', 'period', 'cursor', 'status', 'last_error'])->all(),
            'total' => count($day['game_ids']),
            'can_resume' => $day['status'] === 'failed'
                || \Carbon\CarbonImmutable::parse($day['updated_at'])->lt(now()->subMinutes(3))];
    }

    /** Select only fields consumed by the game tables, never publish saved provenance or raw inputs. */
    private function displayPlayer(object $row, bool $compared): array
    {
        $inputs = json_decode($row->inputs, true, 512, JSON_THROW_ON_ERROR);
        $context = $inputs['context'] ?? [];
        $current = ($context['version'] ?? null) === NhlEvaluationGameForecaster::CONTEXT_VERSION;
        $goalie = ($context['role'] ?? null) === 'goalie';
        $windows = [];
        foreach (['train', 'venue_train', 'last_5', 'last_10', 'last_20', 'last_season', 'venue_last_season', 'opponent_last_10', 'current_quarter'] as $key) {
            if ($goalie && in_array($key, ['venue_train', 'venue_last_season', 'current_quarter'], true)) {
                continue;
            }
            $window = $current ? ($context['windows'][$key] ?? null) : null;
            if (is_array($window)) {
                $windows[$key] = Arr::only($window, $goalie
                    ? ['games', 'sv_pct', 'gsax_per_100_xga']
                    : ['games', 'sat_per_60', 'toi_seconds', 'individual_missing_games', 'usable_games']);
            }
        }
        $comparison = $compared && $row->comparison ? json_decode($row->comparison, true, 512, JSON_THROW_ON_ERROR) : null;
        if (! $current || ($comparison['evidence_version'] ?? null) !== NhlEvaluationGameForecaster::CONTEXT_VERSION) {
            $comparison = null;
        }
        if ($goalie) {
            return ['nhl_player_id' => $row->nhl_player_id, 'nhl_team_id' => $row->nhl_team_id,
                'player_name' => $row->player_name,
                'inputs' => ['context' => [...Arr::only($context, ['version', 'role', 'position']), 'windows' => $windows]]];
        }

        return [
            'nhl_player_id' => $row->nhl_player_id,
            'nhl_team_id' => $row->nhl_team_id,
            'player_name' => $row->player_name,
            'sat_per_60' => $row->sat_per_60,
            'toi_per_game_seconds' => $row->toi_per_game_seconds,
            'sat_direction' => $row->sat_direction,
            'inputs' => [
                ...Arr::only($inputs, ['quarter_sat_per_60', 'quarter_toi_seconds', 'toi_source']),
                'previous' => Arr::only($current ? ($context['previous'] ?? []) : [], ['sat_per_60', 'toi_seconds']),
                'context' => [...Arr::only($context, ['version', 'role', 'position', 'team_records']), 'windows' => $windows],
            ],
            'comparison' => $comparison === null ? null : Arr::only($comparison, ['sat_per_60', 'toi_seconds']),
        ];
    }

    /** Load at most one selected game's players; team records need only one copy per skater lineup. */
    private function displayPlayers(?NhlEvaluationGameForecast $work): array
    {
        if (! $work || ! in_array($work->status, ['ready', 'comparing', 'compared'], true)) {
            return [];
        }
        $recordsSent = [];

        return DB::table('nhl_evaluation_game_player_forecasts')->where('forecast_id', $work->id)
            ->select('nhl_player_id', 'nhl_team_id', 'player_name', 'sat_per_60', 'toi_per_game_seconds', 'sat_direction', 'inputs', 'comparison')
            ->orderBy('nhl_team_id')->orderBy('player_name')->limit(64)->get()
            ->map(function ($row) use ($work, &$recordsSent): array {
                $player = $this->displayPlayer($row, $work->status === 'compared');
                $team = $player['nhl_team_id'];
                if (($player['inputs']['context']['role'] ?? null) === 'goalie' || isset($recordsSent[$team])) {
                    unset($player['inputs']['context']['team_records']);
                } elseif (isset($player['inputs']['context']['team_records'])) {
                    $recordsSent[$team] = true;
                }

                return $player;
            })->all();
    }

    /** Resolve the same per-player display calculation used by the selected-game tables. */
    private function displayMetric(array $player, string $column, string $metric): ?float
    {
        if ($metric === 'sat_per_game') {
            $rate = $this->displayMetric($player, $column, 'sat_per_60');
            $minutes = $this->displayMetric($player, $column, 'toi');

            return $rate === null || $minutes === null ? null : $rate * $minutes / 60;
        }
        if ($column === 'predicted' || $column === 'measured') {
            $source = $column === 'predicted' ? $player : ($player['comparison'] ?? []);
            $key = $metric === 'toi' ? ($column === 'predicted' ? 'toi_per_game_seconds' : 'toi_seconds') : 'sat_per_60';
            $value = $source[$key] ?? null;

            return $value === null ? null : (float) $value / ($metric === 'toi' ? 60 : 1);
        }
        if ($column === 'previous') {
            $value = $player['inputs']['previous'][$metric === 'toi' ? 'toi_seconds' : 'sat_per_60'] ?? null;

            return $value === null ? null : (float) $value / ($metric === 'toi' ? 60 : 1);
        }
        if ($column === 'quarter') {
            $season = $this->displayMetric($player, 'last_season', $metric);
            $recent = $this->displayMetric($player, 'last_20', $metric);

            return $season === null || $recent === null ? null : ($season + $recent) / 2;
        }
        $window = $player['inputs']['context']['windows'][$column === 'current' ? 'current_quarter' : $column] ?? [];
        if ($metric === 'toi') {
            return ($window['games'] ?? 0) > 0 && isset($window['toi_seconds']) && (($window['usable_games'] ?? 0) > 0 || ($window['individual_missing_games'] ?? 0) <= 0)
                ? (float) $window['toi_seconds'] / $window['games'] / 60 : null;
        }

        return isset($window['sat_per_60']) ? (float) $window['sat_per_60'] : null;
    }

    /** Compact one lineup while preserving marked imputation and measured-player error shares. */
    private function lineupSummary(array $players, string $metric, array $columns): array
    {
        $values = $estimated = [];
        foreach ($columns as $column) {
            $available = array_values(array_filter(array_map(
                fn ($player) => $this->displayMetric($player, $column, $metric), $players
            ), fn ($value) => $value !== null));
            $values[$column] = $available === [] ? null : array_sum($available) / count($available) * count($players);
            $estimated[$column] = $available === [] ? 0 : count($players) - count($available);
        }
        $errors = $quarterErrors = [];
        foreach ($players as $player) {
            $predicted = $this->displayMetric($player, 'predicted', $metric);
            $actual = $this->displayMetric($player, 'measured', $metric);
            if ($predicted !== null && $predicted != 0 && $actual !== null) {
                $errors[] = abs(($actual - $predicted) / $predicted * 100);
            }
            $quarter = $this->displayMetric($player, 'quarter', $metric);
            if ($quarter !== null && $quarter != 0 && $actual !== null) {
                $quarterErrors[] = abs(($actual - $quarter) / $quarter * 100);
            }
        }

        return ['values' => $values, 'estimated' => $estimated, 'measuredPlayers' => count($errors),
            'quarterMeasuredPlayers' => count($quarterErrors),
            'quarterUnder10' => count(array_filter($quarterErrors, fn ($error) => $error < 10)),
            'quarterUnder20' => count(array_filter($quarterErrors, fn ($error) => $error < 20)),
            'under10' => count(array_filter($errors, fn ($error) => $error < 10)),
            'under20' => count(array_filter($errors, fn ($error) => $error < 20))];
    }

    /** Read bounded pages, release each lineup, and publish only the three league summary rows. */
    private function leagueSummaries(\Illuminate\Support\Collection $works): array
    {
        $published = $works->filter(fn ($work) => in_array($work->status, ['ready', 'compared'], true))->keyBy('id');
        $metrics = ['sat_per_60' => 'SAT/60', 'toi' => 'TOI min/GP', 'sat_per_game' => 'SAT/GP'];
        $windows = ['train', 'venue_train', 'last_5', 'last_10', 'last_20', 'last_season', 'venue_last_season', 'opponent_last_10'];
        $columns = [...$windows, 'previous', 'quarter', 'current', 'predicted', 'measured'];
        $teams = array_fill_keys(array_keys($metrics), []);
        $lineup = [];
        $group = null;
        $flush = function () use (&$lineup, &$teams, $metrics, $columns): void {
            if ($lineup === []) {
                return;
            }
            foreach ($metrics as $key => $label) {
                $teams[$key][] = $this->lineupSummary($lineup, $key, $columns);
            }
            $lineup = [];
        };
        if ($published->isNotEmpty()) {
            $rows = DB::table('nhl_evaluation_game_player_forecasts')->whereIn('forecast_id', $published->keys())
                ->select('id', 'forecast_id', 'nhl_player_id', 'nhl_team_id', 'player_name', 'sat_per_60', 'toi_per_game_seconds', 'sat_direction', 'inputs', 'comparison')
                ->orderBy('forecast_id')->orderBy('nhl_team_id')->orderBy('id')->lazy(64);
            foreach ($rows as $row) {
                $next = $row->forecast_id.':'.$row->nhl_team_id;
                if ($group !== $next) {
                    $flush();
                    $group = $next;
                }
                $player = $this->displayPlayer($row, $published[$row->forecast_id]->status === 'compared');
                if (($player['inputs']['context']['role'] ?? null) !== 'goalie') {
                    unset($player['inputs']['context']['team_records']);
                    $lineup[] = $player;
                }
            }
            $flush();
        }
        $result = [];
        foreach ($metrics as $key => $label) {
            $values = $coverage = $estimated = [];
            foreach ($columns as $column) {
                $available = array_filter($teams[$key], fn ($team) => $team['values'][$column] !== null);
                $coverage[$column] = count($available);
                $estimated[$column] = count(array_filter($available, fn ($team) => $team['estimated'][$column] > 0));
                $values[$column] = $available === [] ? null : array_sum(array_map(fn ($team) => $team['values'][$column], $available)) / count($available);
            }
            $paired = array_filter($teams[$key], fn ($team) => $team['values']['predicted'] !== null && $team['values']['measured'] !== null);
            $coverage['error'] = count($paired);
            $estimated['error'] = count(array_filter($paired, fn ($team) => $team['estimated']['predicted'] || $team['estimated']['measured']));
            $sameCoverage = count($paired) === $coverage['predicted'] && count($paired) === $coverage['measured'];
            $error = $sameCoverage && $values['predicted'] !== null && $values['predicted'] != 0 && $values['measured'] !== null
                ? ($values['measured'] - $values['predicted']) / $values['predicted'] * 100 : null;
            $measured = array_sum(array_column($teams[$key], 'measuredPlayers'));
            $quarterPaired = array_filter($teams[$key], fn ($team) => $team['values']['quarter'] !== null && $team['values']['measured'] !== null);
            $coverage['quarterError'] = count($quarterPaired);
            $estimated['quarterError'] = count(array_filter($quarterPaired, fn ($team) => $team['estimated']['quarter'] || $team['estimated']['measured']));
            $quarterSameCoverage = count($quarterPaired) === $coverage['quarter'] && count($quarterPaired) === $coverage['measured'];
            $quarterError = $quarterSameCoverage && $values['quarter'] !== null && $values['quarter'] != 0 && $values['measured'] !== null
                ? ($values['measured'] - $values['quarter']) / $values['quarter'] * 100 : null;
            $quarterMeasured = array_sum(array_column($teams[$key], 'quarterMeasuredPlayers'));
            $result[] = ['key' => $key, 'label' => $label, 'history' => Arr::only($values, $windows),
                ...Arr::only($values, ['previous', 'quarter', 'current', 'predicted', 'measured']),
                'coverage' => $coverage, 'estimated' => $estimated, 'teams' => count($teams[$key]), 'error' => $error,
                'measuredPlayers' => $measured,
                'quarterError' => $quarterError, 'quarterMeasuredPlayers' => $quarterMeasured,
                'quarterUnder10' => $quarterMeasured ? array_sum(array_column($teams[$key], 'quarterUnder10')) / $quarterMeasured * 100 : null,
                'quarterUnder20' => $quarterMeasured ? array_sum(array_column($teams[$key], 'quarterUnder20')) / $quarterMeasured * 100 : null,
                'under10' => $measured ? array_sum(array_column($teams[$key], 'under10')) / $measured * 100 : null,
                'under20' => $measured ? array_sum(array_column($teams[$key], 'under20')) / $measured * 100 : null];
        }

        return $result;
    }

    /** Serialize admission per evaluation and enqueue one player, never every game/player. */
    public function store(Request $request, NhlNextGameEvaluation $evaluation, NhlGame $game, NhlEvaluationGameForecaster $service)
    {
        $this->requireAsync();
        $input = $request->validate(['strength' => ['required', Rule::in(NhlNextGameEvaluationService::STRENGTHS)],
            'period' => ['required', Rule::in(['q1', 'q2', 'q3', 'q4'])]]);
        try {
            $work = DB::transaction(function () use ($evaluation, $game, $input, $service) {
                $evaluation = NhlNextGameEvaluation::query()->lockForUpdate()->findOrFail($evaluation->id);
                abort_if(data_get($evaluation->inputs, 'predict_day.status') === 'running', 409, 'Predict Day is already processing this evaluation.');

                return $service->admit($evaluation, $game, $input['strength'], $input['period']);
            });
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['forecast' => $exception->getMessage()]);
        }

        return response()->json(['forecast_id' => $work->id], 202);
    }

    /** Compare saved forecasts explicitly, or resume a failed/stalled checkpoint without replacing inputs. */
    public function update(Request $request, NhlNextGameEvaluation $evaluation, NhlEvaluationGameForecast $forecast, NhlEvaluationGameForecaster $service)
    {
        $this->requireAsync();
        abort_unless((int) $forecast->evaluation_id === (int) $evaluation->id, 404);
        abort_unless($evaluation->version === NhlNextGameEvaluation::OUTLOOK_VERSION, 404);
        $action = $request->validate(['action' => ['required', Rule::in(['compare', 'resume', 'context'])]])['action'];
        DB::transaction(function () use ($evaluation, $forecast, $action, $service): void {
            NhlNextGameEvaluation::query()->lockForUpdate()->findOrFail($evaluation->id);
            $work = NhlEvaluationGameForecast::query()->lockForUpdate()->findOrFail($forecast->id);
            // Multiple open tabs may request the same automatic context upgrade.
            if ($action === 'context' && $work->phase === 'context'
                && in_array($work->status, NhlEvaluationGameForecast::ACTIVE, true)) {
                return;
            }
            $this->requireIdle($evaluation->id, $work->id);
            if ($action === 'context') {
                abort_unless(in_array($work->status, ['ready', 'compared'], true), 409, 'Finish the current work before adding context.');
                $saved = DB::table('nhl_evaluation_game_player_forecasts')->where('forecast_id', $work->id)->pluck('inputs');
                if ($work->hasCurrentEvidence() && $saved->isNotEmpty()
                    && $saved->every(fn ($inputs) => data_get(json_decode($inputs, true), 'context.version') === NhlEvaluationGameForecaster::CONTEXT_VERSION)) {
                    return;
                }
                try {
                    $service->prepareContext($work);
                } catch (RuntimeException $exception) {
                    throw ValidationException::withMessages(['context' => $exception->getMessage()]);
                }
            } elseif ($action === 'compare') {
                abort_unless($work->status === 'ready' && $work->forecasted_at, 409, 'Finish the saved forecast before comparing.');
                abort_unless(NhlGame::query()->whereKey($work->nhl_game_id)->whereIn('game_state', ['OFF', 'FINAL'])->exists(), 409, 'The game must be final.');
                $work->phase = 'compare';
                $work->cursor = 0;
            } else {
                abort_unless($work->canResume(), 409, 'Only failed or stalled work can be resumed.');
            }
            $work->fill(['status' => $work->phase === 'compare' ? 'comparing' : 'queued',
                'work_token' => (string) Str::uuid(), 'last_error' => null])->save();
            ForecastNhlEvaluationPlayerJob::dispatch($work->id, $work->work_token);
        });

        return response()->json(['forecast_id' => $forecast->id], 202);
    }

    /** Do not publish frozen inputs in status summaries. */
    private function row(NhlEvaluationGameForecast $work): array
    {
        return [...$work->only('id', 'nhl_game_id', 'strength', 'period', 'status', 'phase', 'cursor', 'last_error'),
            'total' => $work->total, 'can_resume' => $work->canResume(),
            'forecasted_at' => $work->forecasted_at?->toISOString()];
    }

    /** One active chain per evaluation prevents accidental day-wide queue bursts. */
    private function requireIdle(int $evaluationId, ?int $except = null): void
    {
        abort_if(NhlEvaluationGameForecast::query()->where('evaluation_id', $evaluationId)
            ->when($except, fn ($q) => $q->where('id', '<>', $except))
            ->whereIn('status', NhlEvaluationGameForecast::ACTIVE)->exists(), 409, 'Finish or resume the current game first.');
    }

    /** Web requests must never execute the forecast chain synchronously. */
    private function requireAsync(): void
    {
        abort_unless(in_array(config('queue.connections.' . config('queue.default') . '.driver'),
            ['database', 'redis', 'sqs', 'beanstalkd'], true), 422, 'An asynchronous queue connection is required.');
    }
}
