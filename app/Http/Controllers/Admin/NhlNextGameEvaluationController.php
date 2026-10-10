<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\EvaluateNhlNextGameJob;
use App\Models\NhlModelRun;
use App\Models\NhlNextGameEvaluation;
use App\Services\NhlEvaluationOutlookBuilder;
use App\Services\NhlNextGameEvaluationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use RuntimeException;

/** Admin evaluation containers and preserved historical experiments, isolated from live predictions. */
class NhlNextGameEvaluationController extends Controller
{
    /** List named evaluations without calculating or loading game comparisons. */
    public function index(Request $request, NhlEvaluationOutlookBuilder $builder)
    {
        if ($request->filled('evaluation')) {
            $request->validate(['evaluation' => ['required', 'integer', 'exists:nhl_next_game_evaluations,id']]);
            $selected = NhlNextGameEvaluation::findOrFail($request->integer('evaluation'));
            if ($selected->version !== NhlNextGameEvaluation::OUTLOOK_VERSION) {
                return $this->legacyIndex($request);
            }
        }
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['building', 'ready', 'failed', 'completed'])],
            'sort' => ['nullable', Rule::in(['name', 'model', 'season_id', 'status', 'progress', 'updated_at'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $query = NhlNextGameEvaluation::query()->leftJoin('nhl_model_runs as model', 'model.id', '=', 'nhl_next_game_evaluations.model_run_id')
            ->select('nhl_next_game_evaluations.*', 'model.name as model_name')
            ->when($filters['q'] ?? null, fn ($query, $q) => $query->where(fn ($where) =>
                $where->where('nhl_next_game_evaluations.name', 'ilike', '%' . $q . '%')->orWhere('model.name', 'ilike', '%' . $q . '%')))
            ->when($filters['status'] ?? null, fn ($query, $status) => $status === 'building'
                ? $query->whereIn('nhl_next_game_evaluations.status', NhlNextGameEvaluation::ACTIVE_STATUSES)
                : $query->where('nhl_next_game_evaluations.status', $status));
        $direction = $filters['direction'] ?? 'desc';
        $sort = $filters['sort'] ?? 'updated_at';
        if ($sort === 'progress') {
            $query->orderByRaw("CASE WHEN version = 'next_game_outlook_v1' THEN
                COALESCE((inputs->'_work'->>'players_completed')::numeric, 0) / NULLIF((inputs->'_work'->>'players_total')::numeric, 0)
                ELSE (completed_games + excluded_games)::numeric / NULLIF(total_games, 0) END {$direction} NULLS LAST");
        } else {
            $query->orderBy($sort === 'model' ? 'model.name' : 'nhl_next_game_evaluations.' . $sort, $direction);
        }
        $evaluations = $query->orderByDesc('nhl_next_game_evaluations.id')->paginate(20)->withQueryString()
            ->through(fn ($evaluation) => $this->row($evaluation));
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['evaluations' => $evaluations]);
        }

        return Inertia::render('Admin/Evaluations/Index', [
            'evaluations' => $evaluations, 'filters' => $filters,
            'models' => fn () => $this->models($builder),
        ]);
    }

    /** Expose prerequisites as read-only choice metadata; POST repeats validation authoritatively. */
    private function models(NhlEvaluationOutlookBuilder $builder): array
    {
        return NhlModelRun::query()->where('model_family', 'sat')->where('workflow_stage', 'training')
            ->orderByDesc('id')->get()->map(function ($model) use ($builder): array {
                $reason = null;
                try {
                    $builder->validateModel($model);
                } catch (RuntimeException $exception) {
                    $reason = $exception->getMessage();
                }

                return ['id' => $model->id, 'name' => $model->name ?? 'Model #' . $model->id,
                    'training_seasons' => $model->train_season_ids ?? [], 'season_id' => $model->projectionSeasonId(),
                    'available' => $reason === null, 'reason' => $reason];
            })->all();
    }

    /** Safe index representation; never expose frozen player payloads in polling responses. */
    private function row(NhlNextGameEvaluation $evaluation): array
    {
        $outlook = $evaluation->version === NhlNextGameEvaluation::OUTLOOK_VERSION;

        return ['id' => $evaluation->id, 'name' => $evaluation->name ?? 'Evaluation #' . $evaluation->id,
            'model_name' => $evaluation->model_name ?? 'Model #' . $evaluation->model_run_id,
            'season_id' => $evaluation->season_id, 'status' => $evaluation->status,
            'completed' => $outlook ? data_get($evaluation->inputs, '_work.players_completed', 0) : $evaluation->completed_games + $evaluation->excluded_games,
            'total' => $outlook ? data_get($evaluation->inputs, '_work.players_total', 0) : $evaluation->total_games,
            'unit' => $outlook ? 'players' : 'games', 'updated_at' => $evaluation->updated_at?->toISOString(),
            'active' => in_array($evaluation->status, NhlNextGameEvaluation::ACTIVE_STATUSES, true),
            'can_resume' => $evaluation->canResume(), 'error' => $evaluation->last_error,
            'legacy_url' => $outlook ? null : route('admin.nhl-sat-models.next-game', ['evaluation' => $evaluation->id])];
    }

    /** Render progress and like-for-like error summaries for the selected cohort. */
    private function legacyIndex(Request $request)
    {
        $filters = $request->validate([
            'evaluation' => ['nullable', 'integer', 'exists:nhl_next_game_evaluations,id'],
            'strength' => ['nullable', Rule::in(NhlNextGameEvaluationService::STRENGTHS)],
            'team' => ['nullable', 'integer'], 'q' => ['nullable', 'string', 'max:100'],
            'method' => ['nullable', Rule::in(array_keys(NhlNextGameEvaluationService::METHODS))],
            'result' => ['nullable', 'integer'], 'page' => ['nullable', 'integer', 'min:1'],
            'sort' => ['nullable', Rule::in(['player_name', 'games', 'actual_sat', 'error_pct'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'progress' => ['nullable', 'boolean'],
        ]);
        if ($request->boolean('progress')) {
            $evaluation = NhlNextGameEvaluation::query()
                ->when(isset($filters['evaluation']), fn ($query) => $query->whereKey($filters['evaluation']))
                ->latest('id')->first();
            return response()->json([
                'progress_html' => view('admin.nhl-sat-models.next-game-progress', compact('evaluation'))->render(),
                'active' => $evaluation && in_array($evaluation->status, NhlNextGameEvaluation::ACTIVE_STATUSES, true),
                'status' => $evaluation?->status,
                'revision' => $evaluation ? $evaluation->completed_games . ':' . $evaluation->excluded_games : '0:0',
            ]);
        }
        $runs = NhlNextGameEvaluation::query()->where('version', NhlNextGameEvaluation::VERSION)->latest('id')->limit(30)->get(['id', 'season_id']);
        $evaluation = isset($filters['evaluation'])
            ? NhlNextGameEvaluation::findOrFail($filters['evaluation'])
            : NhlNextGameEvaluation::query()->latest('id')->first();
        $strength = $filters['strength'] ?? 'ev';
        $method = $filters['method'] ?? 'baseline';
        $summary = collect();
        $players = null;
        $detail = null;
        $missing = 0;
        $excluded = collect();
        if ($evaluation) {
            $base = DB::table('nhl_next_game_evaluation_results as r')->where('evaluation_id', $evaluation->id)
                ->whereExists(fn ($query) => $query->selectRaw('1')->from('nhl_next_game_evaluation_games as work')
                    ->whereColumn('work.evaluation_id', 'r.evaluation_id')->whereColumn('work.nhl_game_id', 'r.nhl_game_id')
                    ->where('work.state', 'completed'))
                ->where('strength', $strength)
                ->when($filters['team'] ?? null, fn ($query, $team) => $query->where('nhl_team_id', $team))
                ->when($filters['q'] ?? null, fn ($query, $name) => $query->where('player_name', 'ilike', '%' . $name . '%'));
            $missing = (clone $base)->where('baseline_source', 'unavailable')->count();
            $byPlayer = (clone $base)->where('baseline_source', '<>', 'unavailable')
                ->crossJoin(DB::raw("LATERAL jsonb_each(CASE WHEN jsonb_typeof(r.metrics::jsonb) = 'object' THEN r.metrics::jsonb ELSE '{}'::jsonb END) AS methods(method, value)"))
                ->selectRaw("nhl_player_id, MAX(player_name) as player_name, methods.method, COUNT(*) as games,
                    SUM((value->>'actual_sat')::numeric) as actual_sat,
                    SUM((value->>'absolute_error')::numeric) as absolute_error,
                    SUM(CASE WHEN (value->>'fallback')::boolean THEN 1 ELSE 0 END) as fallbacks")
                ->groupBy('nhl_player_id', 'methods.method');
            $summary = DB::query()->fromSub(clone $byPlayer, 'players')->selectRaw("method,
                100.0 * SUM(absolute_error) / NULLIF(SUM(actual_sat), 0) as error_pct,
                COUNT(*) FILTER (WHERE actual_sat > 0) as measured_players,
                100.0 * COUNT(*) FILTER (WHERE actual_sat > 0 AND absolute_error / actual_sat < 0.10)
                    / NULLIF(COUNT(*) FILTER (WHERE actual_sat > 0), 0) as under_10,
                100.0 * COUNT(*) FILTER (WHERE actual_sat > 0 AND absolute_error / actual_sat < 0.20)
                    / NULLIF(COUNT(*) FILTER (WHERE actual_sat > 0), 0) as under_20,
                SUM(fallbacks) as fallbacks, SUM(games) as appearances")
                ->groupBy('method')->orderBy('error_pct')->get();
            $players = DB::query()->fromSub($byPlayer, 'players')->where('method', $method)
                ->selectRaw('*, 100.0 * absolute_error / NULLIF(actual_sat, 0) as error_pct')
                ->orderByRaw(($filters['sort'] ?? 'error_pct') . ' ' . ($filters['direction'] ?? 'asc') . ' NULLS LAST')
                ->orderBy('nhl_player_id')->paginate(30)->withQueryString()->appends(['evaluation' => $evaluation->id]);
            $excluded = DB::table('nhl_next_game_evaluation_games')->where('evaluation_id', $evaluation->id)
                ->where('state', 'excluded')->selectRaw('error, COUNT(*) as games')->groupBy('error')->get();
            if (isset($filters['result'])) {
                $detail = (clone $base)->where('r.id', $filters['result'])->first();
                abort_unless($detail, 404);
            }
        }
        $games = $evaluation ? DB::table('nhl_next_game_evaluation_results as r')->where('evaluation_id', $evaluation->id)
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('nhl_next_game_evaluation_games as work')
                ->whereColumn('work.evaluation_id', 'r.evaluation_id')->whereColumn('work.nhl_game_id', 'r.nhl_game_id')
                ->where('work.state', 'completed'))
            ->where('strength', $strength)
            ->when($filters['team'] ?? null, fn ($query, $team) => $query->where('nhl_team_id', $team))
            ->when($filters['q'] ?? null, fn ($query, $name) => $query->where('player_name', 'ilike', '%' . $name . '%'))
            ->orderByDesc('nhl_game_id')->orderBy('nhl_player_id')->limit(30)->get() : collect();
        $data = compact('runs', 'evaluation', 'strength', 'method', 'summary', 'players', 'detail', 'missing', 'excluded', 'games', 'filters');
        $data['methods'] = NhlNextGameEvaluationService::METHODS;
        if ($request->expectsJson()) {
            return response()->json([
                'html' => view('admin.nhl-sat-models.next-game-results', $data)->render(),
                'active' => $evaluation && in_array($evaluation->status, NhlNextGameEvaluation::ACTIVE_STATUSES, true),
            ]);
        }
        $data['models'] = NhlModelRun::query()->where('model_family', 'sat')->where('workflow_stage', 'training')
            ->whereNotNull('target_season_id')->orderByDesc('id')->get();

        return view('admin.nhl-sat-models.next-game', $data);
    }

    /** Create one durable run and dispatch only its coordinator. */
    public function store(Request $request, NhlEvaluationOutlookBuilder $service)
    {
        $this->requireAsyncQueue();
        $input = $request->validate(['name' => ['required', 'string', 'max:160'],
            'model_run_id' => ['required', 'integer', 'exists:nhl_model_runs,id']]);
        $evaluation = DB::transaction(function () use ($input, $request, $service) {
            $model = NhlModelRun::query()->lockForUpdate()->findOrFail($input['model_run_id']);
            try {
                $service->validateModel($model);
            } catch (RuntimeException $exception) {
                throw ValidationException::withMessages(['model_run_id' => $exception->getMessage()]);
            }
            $token = (string) Str::uuid();
            $evaluation = NhlNextGameEvaluation::create([
                'model_run_id' => $model->id, 'created_by' => $request->user()->id,
                'name' => $input['name'], 'season_id' => $model->projectionSeasonId(), 'status' => 'building',
                'version' => NhlNextGameEvaluation::OUTLOOK_VERSION,
                'inputs' => ['training_seasons' => array_map('strval', $model->train_season_ids),
                    'model_updated_at' => $model->updated_at->toISOString(),
                    '_work' => ['stage' => 'initialize', 'token' => $token]],
            ]);
            EvaluateNhlNextGameJob::dispatch($evaluation->id, $token);

            return $evaluation;
        });

        $url = route('admin.nhl-sat-models.next-game');

        return $request->expectsJson()
            ? response()->json(['url' => $url, 'id' => $evaluation->id], 202)
            : redirect()->to($url, 303);
    }

    /** Rename without changing the frozen model or initial estimates. */
    public function update(Request $request, NhlNextGameEvaluation $evaluation)
    {
        $input = $request->validate(['name' => ['required', 'string', 'max:160']]);
        $evaluation->update($input);

        return response()->json(['message' => 'Evaluation renamed.']);
    }

    /** Delete only inactive evaluation-owned data; never remove model outputs. */
    public function destroy(NhlNextGameEvaluation $evaluation)
    {
        DB::transaction(function () use ($evaluation): void {
            $evaluation = NhlNextGameEvaluation::query()->lockForUpdate()->findOrFail($evaluation->id);
            abort_if(in_array($evaluation->status, NhlNextGameEvaluation::ACTIVE_STATUSES, true), 409,
                'This evaluation is still building. Wait until it finishes before deleting it.');
            $evaluation->delete();
        });

        return response()->json(['message' => 'Evaluation deleted.']);
    }

    /** Resume failed or abandoned work without clearing successful games. */
    public function resume(Request $request, NhlNextGameEvaluation $evaluation)
    {
        $this->requireAsyncQueue();
        DB::transaction(function () use ($evaluation): void {
            $evaluation = NhlNextGameEvaluation::query()->lockForUpdate()->findOrFail($evaluation->id);
            abort_unless($evaluation->canResume(), 409, 'Only failed or stalled evaluations can be resumed.');
            $inputs = $evaluation->inputs ?? [];
            $inputs['_work']['stage'] ??= isset($inputs['baseline_frozen_at']) ? 'evaluate' : 'initialize';
            $inputs['_work']['token'] = (string) Str::uuid();
            unset($inputs['_work']['started_at']);
            $evaluation->update([
                'status' => 'queued', 'last_error' => null, 'inputs' => $inputs,
            ]);
            EvaluateNhlNextGameJob::dispatch($evaluation->id, $inputs['_work']['token']);
        });

        $url = route('admin.nhl-sat-models.next-game', ['evaluation' => $evaluation->id]);

        return $request->expectsJson()
            ? response()->json(['url' => $url], 202)
            : redirect()->to($url, 303);
    }

    /** A synchronous driver would recurse through the entire run in the HTTP request. */
    private function requireAsyncQueue(): void
    {
        $connection = config('queue.default');
        $driver = config('queue.connections.' . $connection . '.driver');
        if (! in_array($driver, ['database', 'redis', 'sqs', 'beanstalkd'], true)) {
            throw ValidationException::withMessages([
                'evaluation' => 'Next-game evaluations require an asynchronous queue connection.',
            ]);
        }
    }
}
