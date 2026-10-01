<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NhlModelRun;
use App\Models\NhlSatEngine;
use App\Models\NhlSatEngineRun;
use App\Services\NhlSatEngineEvaluator;
use App\Services\NhlSatEngineSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Admin-only saved engines and explicitly started discovery runs. */
class NhlSatEngineController extends Controller
{
    /** Paginated CRUD index, using the shared Inertia application shell. */
    public function index(): Response
    {
        return Inertia::render('Admin/SatEngines/Index', [
            'engines' => NhlSatEngine::query()->latest('id')->paginate(25),
            'models' => $this->models(), 'defaults' => app(NhlSatEngineSettings::class)->defaults(),
        ]);
    }

    /** Save an engine definition without starting any work. */
    public function store(Request $request, NhlSatEngineSettings $settings): RedirectResponse
    {
        $engine = (new NhlSatEngine())->saveDefinition($this->definition($request, $settings));

        return to_route('admin.nhl-sat-engines.show', $engine);
    }

    /** Display a saved engine and its prior runs. */
    public function show(NhlSatEngine $engine): Response
    {
        return $this->workspace($engine);
    }

    /** Discovery does not require an engine to exist first. */
    public function discover(): Response
    {
        return $this->workspace();
    }

    /** Change future builds; previous runs retain their frozen settings. */
    public function update(Request $request, NhlSatEngine $engine, NhlSatEngineSettings $settings): RedirectResponse
    {
        $engine->saveDefinition($this->definition($request, $settings));

        return back();
    }

    /** Retain completed evaluation evidence when deleting an engine. */
    public function destroy(NhlSatEngine $engine): RedirectResponse
    {
        if (NhlSatEngineRun::query()->where('engine_id', $engine->id)->whereIn('status', ['queued', 'running', 'ranking'])->exists()) {
            throw ValidationException::withMessages(['engine' => 'Cancel active runs before deleting this engine.']);
        }
        $engine->deleteDefinition();

        return to_route('admin.nhl-sat-engines.index');
    }

    /** Immediately select the engine used by ordinary game predictions. */
    public function makeDefault(NhlSatEngine $engine): RedirectResponse
    {
        $engine->makeDefault();

        return back();
    }

    /** Start only the build or discovery explicitly requested by the user. */
    public function start(Request $request, NhlSatEngineEvaluator $evaluator): RedirectResponse
    {
        $input = $request->validate([
            'kind' => ['required', Rule::in(['build', 'discovery'])],
            'engine_id' => 'nullable|required_if:kind,build|integer|exists:nhl_sat_engines,id',
            'model_run_id' => ['required', 'integer', Rule::exists('nhl_model_runs', 'id')->where('model_family', 'sat')],
            'desired_win_pct' => 'required|numeric|between:0,100',
            'min_coverage_pct' => 'required|numeric|gt:0|max:100',
            'scope' => 'required|array',
            'scope.mode' => ['required', Rule::in(['season', 'games', 'days', 'selected'])],
            'scope.selection' => ['sometimes', Rule::in(['first', 'last', 'random'])],
            'scope.start_date' => 'nullable|date_format:Y-m-d',
            'scope.end_date' => array_filter(['nullable', 'date_format:Y-m-d',
                $request->filled('scope.start_date') ? 'after_or_equal:scope.start_date' : null]),
            'scope.count' => 'required_if:scope.mode,games,days|nullable|integer|between:1,3000',
            'scope.teams' => 'present|array|max:40',
            'scope.teams.*' => 'required|string|regex:/^[A-Z]{2,3}$/|distinct',
            'scope.game_ids' => 'required_if:scope.mode,selected|array|max:3000',
            'scope.game_ids.*' => 'integer|distinct',
        ]);
        $engine = isset($input['engine_id']) ? NhlSatEngine::query()->findOrFail($input['engine_id']) : null;
        if ($engine && (int) $engine->test_model_run_id !== (int) $input['model_run_id']) {
            throw ValidationException::withMessages(['model_run_id' => 'Save the engine model change before starting its run.']);
        }
        $run = $evaluator->start($input, $engine);

        return to_route('admin.nhl-sat-engines.runs.show', $run);
    }

    /** Inspect ranked candidates and one candidate's paginated game results. */
    public function run(Request $request, NhlSatEngineRun $run): Response
    {
        $input = $request->validate(['candidate' => 'nullable|integer']);
        $candidates = DB::table('nhl_sat_engine_candidates')->where('run_id', $run->id)
            ->orderByDesc('meets_targets')->orderByRaw('CASE WHEN win_pct IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('win_pct')->orderByDesc('coverage_pct')->orderBy('id')->paginate(25)->withQueryString();
        $candidate = DB::table('nhl_sat_engine_candidates')->where('run_id', $run->id)
            ->where('id', $input['candidate'] ?? $candidates->items()[0]->id ?? 0)->first();
        abort_if(isset($input['candidate']) && ! $candidate, 404);
        $decode = function ($row, array $keys) {
            foreach ($keys as $key) {
                $row->{$key} = $row->{$key} === null ? null : json_decode($row->{$key}, true, 512, JSON_THROW_ON_ERROR);
            }

            return $row;
        };
        $candidates->through(fn ($row) => $decode($row, ['settings', 'metrics']));
        // Surface strong below-coverage alternatives independently of the main ranking.
        $nearMisses = DB::table('nhl_sat_engine_candidates')->where('run_id', $run->id)
            ->whereNotNull('metrics')->where('win_pct', '>=', $run->definition['desired_win_pct'])
            ->where('coverage_pct', '<', $run->definition['min_coverage_pct'])
            ->orderByDesc('coverage_pct')->orderByDesc('win_pct')->orderBy('id')
            ->paginate(10, ['*'], 'near_page')->withQueryString()
            ->through(fn ($row) => $decode($row, ['settings', 'metrics']));
        $games = DB::table('nhl_sat_engine_results')->where('run_id', $run->id)
            ->where('split_index', $candidate?->split_index ?? 0)->orderBy('nhl_game_id')
            ->paginate(25, ['*'], 'games_page')->withQueryString()
            ->through(fn ($row) => $decode($row, ['game', 'prediction']));

        return Inertia::render('Admin/SatEngines/Run', [
            'run' => $run, 'candidates' => $candidates, 'nearMisses' => $nearMisses, 'games' => $games,
            'candidate' => $candidate ? $decode($candidate, ['settings', 'metrics']) : null,
            'engines' => NhlSatEngine::query()->orderBy('name')->get(['id', 'name', 'model_run_id', 'test_model_run_id']),
            'models' => $this->models(),
        ]);
    }

    /** Cancel cooperatively; in-flight results cannot update terminal runs. */
    public function cancel(NhlSatEngineRun $run): RedirectResponse
    {
        NhlSatEngineRun::query()->whereKey($run->id)->whereIn('status', ['queued', 'running', 'ranking'])
            ->update(['status' => 'cancelled', 'completed_at' => now()]);

        return back();
    }

    /** Copy a reviewed candidate into a new or existing engine definition. */
    public function apply(Request $request, NhlSatEngineRun $run, int $candidate): RedirectResponse
    {
        abort_unless(in_array($run->status, ['queued', 'running', 'ranking', 'complete'], true), 409,
            'Failed or cancelled evaluations cannot supply engine settings.');
        $row = DB::table('nhl_sat_engine_candidates')->where('run_id', $run->id)->where('id', $candidate)->first();
        abort_unless($row, 404);
        abort_if($row->metrics === null, 409, 'This candidate has not finished evaluation yet.');
        $input = $request->validate([
            'engine_id' => 'nullable|integer|exists:nhl_sat_engines,id',
            'name' => 'required_without:engine_id|nullable|string|max:160',
            'test_model_run_id' => 'nullable|integer|exists:nhl_model_runs,id',
            'model_run_id' => 'nullable|integer|exists:nhl_model_runs,id',
        ]);
        $engine = isset($input['engine_id']) ? NhlSatEngine::query()->findOrFail($input['engine_id']) : new NhlSatEngine();
        $attributes = ['settings' => json_decode($row->settings, true, 512, JSON_THROW_ON_ERROR)];
        if (! $engine->exists) {
            $attributes['name'] = $input['name'];
            $attributes['test_model_run_id'] = $input['test_model_run_id'] ?? $run->model_run_id;
            $attributes['model_run_id'] = $input['model_run_id'] ?? $run->model_run_id;
        }
        $engine->saveDefinition($attributes);

        return to_route('admin.nhl-sat-engines.show', $engine);
    }

    /** @return array<string, mixed> */
    private function definition(Request $request, NhlSatEngineSettings $settings): array
    {
        $input = $request->validate([
            'name' => 'required|string|max:160', 'notes' => 'nullable|string|max:2000',
            'test_model_run_id' => ['required', 'integer', Rule::exists('nhl_model_runs', 'id')->where('model_family', 'sat')],
            'model_run_id' => ['required', 'integer', Rule::exists('nhl_model_runs', 'id')->where('model_family', 'sat')],
            'settings' => 'required|array',
        ]);
        $input['settings'] = $settings->validate($input['settings']);

        return $input;
    }

    /** @return \Illuminate\Database\Eloquent\Collection */
    private function models(): \Illuminate\Database\Eloquent\Collection
    {
        return NhlModelRun::query()->where('model_family', 'sat')->latest('id')
            ->get(['id', 'name', 'status', 'target_season_id', 'train_season_ids']);
    }

    /** Compose discovery and engine detail props. */
    private function workspace(?NhlSatEngine $engine = null): Response
    {
        return Inertia::render('Admin/SatEngines/Workspace', [
            'engine' => $engine, 'models' => $this->models(),
            'defaults' => app(NhlSatEngineSettings::class)->defaults(),
            'teams' => DB::table('nhl_games')->whereNotNull('home_team_abbrev')->distinct()->orderBy('home_team_abbrev')->pluck('home_team_abbrev'),
            'runs' => NhlSatEngineRun::query()->when($engine, fn ($q) => $q->where('engine_id', $engine->id))
                ->latest('id')->paginate(20),
        ]);
    }
}
