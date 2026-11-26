<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NhlModelRun;
use App\Services\NhlSeasonGameEvaluation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Super-admin inspection of isolated season runs; public/API prediction records stay separate. */
class NhlSeasonGameEvaluationController extends Controller
{
    /** Read saved run history, progress and one paginated game list without calculating forecasts. */
    public function index(Request $request, NhlModelRun $model, NhlSeasonGameEvaluation $evaluations): Response
    {
        abort_unless($model->model_family === 'sat', 404);
        $input = $request->validate(['run' => ['nullable', 'integer', 'min:1'], 'compare' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'], 'history_page' => ['nullable', 'integer', 'min:1'], 'refresh_readiness' => ['sometimes', 'boolean']]);
        $runPager = DB::table('nhl_custom_game_prediction_runs')->where('model_run_id', $model->id)
            ->orderByDesc('id')->paginate(20, ['*'], 'history_page')->withQueryString();
        $runs = collect($runPager->items());
        $selected = isset($input['run']) ? $this->run($model, (int) $input['run']) : $runs->first();
        $counts = DB::table('nhl_custom_game_predictions')->whereIn('run_id', $runs->pluck('id'))
            ->selectRaw('run_id, SUM(CASE WHEN winner_correct = true THEN 1 ELSE 0 END) AS wins, SUM(CASE WHEN winner_correct = false THEN 1 ELSE 0 END) AS losses')
            ->groupBy('run_id')->get()->keyBy('run_id');
        $history = $runs->map(function ($run) use ($counts): array {
            $count = $counts->get($run->id);
            $wins = (int) ($count->wins ?? 0);
            $losses = (int) ($count->losses ?? 0);
            return [...(array) $run, 'definition' => \Illuminate\Support\Arr::except(json_decode($run->definition, true), ['game_ids']), 'wins' => $wins, 'losses' => $losses,
                'win_pct' => $wins + $losses > 0 ? 100 * $wins / ($wins + $losses) : null];
        });
        if ($selected !== null) {
            $selected->definition = \Illuminate\Support\Arr::except(json_decode($selected->definition, true, 512, JSON_THROW_ON_ERROR), ['game_ids']);
            $selected->requires_new_run = ($selected->definition['entrypoint'] ?? null) === 'season_games'
                && ($selected->definition['identity'] ?? null) !== $evaluations->identity($model);
            $selected->can_resume = ! $selected->requires_new_run && ($selected->definition['entrypoint'] ?? null) === 'season_games'
                && ($selected->status === 'failed' || (in_array($selected->status, ['queued', 'running'], true)
                    && \Carbon\CarbonImmutable::parse($selected->updated_at)->lessThan(now()->subMinutes(5))));
            $manifest = Cache::get('nhl:season-evaluation:'.$selected->id.':work');
            $selected->checkpoint = $manifest['checkpoint'] ?? null;
            $activity = \Carbon\CarbonImmutable::parse($selected->updated_at, 'UTC');
            if (! empty($manifest['diagnostics']['saved_at'])) {
                $activity = $activity->max(\Carbon\CarbonImmutable::parse($manifest['diagnostics']['saved_at']));
            }
            $selected->last_activity_at = $activity->toIso8601String();
            $end = in_array($selected->status, ['queued', 'running'], true)
                ? now('UTC') : \Carbon\CarbonImmutable::parse($selected->updated_at, 'UTC');
            $selected->elapsed_seconds = max(0, $end->getTimestamp()
                - \Carbon\CarbonImmutable::parse($selected->created_at, 'UTC')->getTimestamp());
        }
        $compare = null;
        if ($selected !== null && isset($input['compare'])) {
            $other = $this->run($model, (int) $input['compare']);
            abort_unless($other->season_id === $selected->season_id, 422, 'Compare runs for the same Test season.');
            $compare = ['baseline_id' => (int) $other->id, ...$evaluations->compare((int) $other->id, (int) $selected->id)];
        }
        $games = DB::table('nhl_custom_game_predictions as p')->join('nhl_games as g', 'g.nhl_game_id', '=', 'p.nhl_game_id')
            ->where('p.run_id', $selected->id ?? 0)->orderBy('g.start_time_utc')->orderBy('p.nhl_game_id')
            ->select(['p.id', 'p.nhl_game_id', 'p.status', 'p.source_cutoff_at', 'p.home_goals_prediction', 'p.away_goals_prediction',
                'p.total_goals_prediction', 'p.home_win_probability', 'p.away_win_probability', 'p.recommendation', 'p.home_actual_goals',
                'p.away_actual_goals', 'p.winner_correct', 'p.last_error', 'g.game_date', 'g.home_team_abbrev', 'g.away_team_abbrev'])
            ->paginate(50)->withQueryString();
        return Inertia::render('Admin/Evaluations/SeasonGames', [
            'model' => $model->only('id', 'name', 'train_season_ids', 'target_season_id'), 'runs' => $history,
            'selected' => $selected, 'games' => $games, 'comparison' => $compare, 'runLinks' => $runPager->linkCollection()->all(),
            'activeRunId' => DB::table('nhl_custom_game_prediction_runs')->where('model_run_id', $model->id)
                ->where('definition->entrypoint', 'season_games')->whereIn('status', ['queued', 'running'])->orderByDesc('id')->value('id'),
            'summary' => $selected === null ? null : $evaluations->summary((int) $selected->id),
            'readiness' => fn () => $evaluations->readiness($model, $request->boolean('refresh_readiness')),
        ]);
    }

    /** Every accepted click creates a new immutable experiment definition. */
    public function store(Request $request, NhlModelRun $model, NhlSeasonGameEvaluation $evaluations): JsonResponse
    {
        abort_unless($model->model_family === 'sat', 404);
        $id = $evaluations->start($model, $request->user()?->id);
        return response()->json(['run_id' => $id], 202);
    }

    /** Continue the same experiment, never replace its successful games. */
    public function resume(NhlModelRun $model, int $seasonRun, NhlSeasonGameEvaluation $evaluations): JsonResponse
    {
        $this->run($model, $seasonRun);
        $evaluations->resume($model, $seasonRun);
        return response()->json(['run_id' => $seasonRun], 202);
    }

    /** Load one game's compact frozen detail only when expanded. */
    public function game(NhlModelRun $model, int $seasonRun, int $prediction): JsonResponse
    {
        $this->run($model, $seasonRun);
        DB::table('nhl_custom_game_predictions')->where('run_id', $seasonRun)->where('id', $prediction)->firstOrFail();
        $teams = DB::table('nhl_custom_game_prediction_teams')->where('prediction_id', $prediction)->get();
        $players = DB::table('nhl_custom_game_prediction_player_inputs')->whereIn('team_prediction_id', $teams->pluck('id'))->orderBy('id')->get();
        $output = [];
        foreach ($teams as $team) {
            $input = json_decode($team->inputs ?? '{}', true, 512, JSON_THROW_ON_ERROR);
            $output[$team->venue] = ['team_id' => $team->nhl_team_id, 'team_abbrev' => $input['own']['abbrev'] ?? null,
                'starting_goalie_id' => $team->starting_goalie_nhl_player_id, 'pregame' => $input['own']['team_metrics'] ?? [],
                'schedule' => $input['own']['schedule'] ?? [],
                'predicted' => ['goals' => $team->goals_prediction, 'ev_goals' => $team->ev_goals_prediction, 'pp_goals' => $team->pp_goals_prediction,
                    'sat' => $team->sat_prediction, 'sog' => $team->sog_prediction, 'pp_opportunities' => $team->pp_opportunities_prediction,
                    'ev_toi' => $team->ev_toi_seconds_prediction, 'pp_toi' => $team->pp_toi_seconds_prediction, 'pk_toi' => $team->pk_toi_seconds_prediction],
                'actual' => ['final_goals' => $team->actual_goals, 'ev_goals' => $team->actual_ev_goals, 'pp_goals' => $team->actual_pp_goals,
                    'sat' => $team->actual_sat, 'sog' => $team->actual_sog, 'pp_opportunities' => $team->actual_pp_opportunities,
                    'ev_toi' => $team->actual_ev_toi_seconds, 'pp_toi' => $team->actual_pp_toi_seconds, 'pk_toi' => $team->actual_pk_toi_seconds],
                'players' => $players->where('team_prediction_id', $team->id)->map(function ($player): array {
                    $prediction = json_decode($player->prediction ?? '{}', true, 512, JSON_THROW_ON_ERROR) ?? [];
                    $actual = json_decode($player->actual ?? '{}', true, 512, JSON_THROW_ON_ERROR) ?? [];
                    $inputs = json_decode($player->inputs, true, 512, JSON_THROW_ON_ERROR);
                    $lanes = [];
                    foreach (['EV', 'PP'] as $lane) {
                        $bucket = $actual[$lane] ?? [];
                        $known = ($actual['_measured'] ?? false) || array_key_exists($lane, $actual);
                        $lanes[$lane] = ['prediction' => $prediction['strengths'][$lane] ?? null,
                            'actual' => ['sat' => $known ? array_sum(array_column($bucket, 'sat')) : null,
                                'sog' => $known ? array_sum(array_column($bucket, 'sog')) : null,
                                'goals' => $known ? array_sum(array_column($bucket, 'goals')) : null,
                                'seconds' => $actual['_toi_seconds'][$lane] ?? null]];
                    }
                    return ['nhl_player_id' => $player->nhl_player_id, 'name' => $player->player_name, 'role' => $player->player_role,
                        'lanes' => $lanes, 'history' => $inputs['history'] ?? [],
                        'goalie' => $player->player_role === 'goalie' ? ['pregame' => $inputs, 'prediction' => $prediction, 'actual' => $actual] : null];
                })->values()->all()];
        }
        return response()->json(['prediction_id' => $prediction, 'teams' => $output]);
    }

    /** Enforce model ownership for history, comparison, resume and details. */
    private function run(NhlModelRun $model, int $id): object
    {
        return DB::table('nhl_custom_game_prediction_runs')->where('model_run_id', $model->id)->where('id', $id)->firstOrFail();
    }
}
