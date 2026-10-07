<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\EvaluateNhlNextGameJob;
use App\Models\NhlModelRun;
use App\Models\NhlNextGameEvaluation;
use App\Services\NhlNextGameEvaluationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** Admin-only historical experiments, isolated from production predictions. */
class NhlNextGameEvaluationController extends Controller
{
    /** Render progress and like-for-like error summaries for the selected cohort. */
    public function index(Request $request)
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
        $runs = NhlNextGameEvaluation::query()->latest('id')->limit(30)->get(['id', 'season_id']);
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
    public function store(Request $request, NhlNextGameEvaluationService $service)
    {
        $this->requireAsyncQueue();
        $input = $request->validate(['model_run_id' => ['required', 'integer', 'exists:nhl_model_runs,id']]);
        $evaluation = DB::transaction(function () use ($input, $request, $service) {
            $model = NhlModelRun::query()->lockForUpdate()->findOrFail($input['model_run_id']);
            try {
                $service->validateModel($model, (string) $model->target_season_id);
            } catch (RuntimeException $exception) {
                throw ValidationException::withMessages(['model_run_id' => $exception->getMessage()]);
            }
            $existing = NhlNextGameEvaluation::query()->where('model_run_id', $model->id)
                ->whereIn('status', NhlNextGameEvaluation::ACTIVE_STATUSES)->first();
            if ($existing) {
                return $existing;
            }
            $token = (string) Str::uuid();
            $evaluation = NhlNextGameEvaluation::create([
                'model_run_id' => $model->id, 'created_by' => $request->user()->id,
                'season_id' => $model->target_season_id, 'status' => 'queued',
                'version' => NhlNextGameEvaluation::VERSION,
                'inputs' => ['_work' => ['stage' => 'initialize', 'token' => $token]],
            ]);
            EvaluateNhlNextGameJob::dispatch($evaluation->id, $token);

            return $evaluation;
        });

        $url = route('admin.nhl-sat-models.next-game', ['evaluation' => $evaluation->id]);

        return $request->expectsJson()
            ? response()->json(['url' => $url], 202)
            : redirect()->to($url, 303);
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
