<?php

declare(strict_types=1);

/**
 * Audit the production prerequisites for a reproducible custom-engine replay.
 * Execute with PHP from the application root. All database work is read-only.
 * This does not substitute the legacy engine for the unfinished custom engine.
 */
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$applicationRoot = getcwd();
require $applicationRoot.'/vendor/autoload.php';
$app = require $applicationRoot.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$options = getopt('', ['model:', 'baseline-definition:']);
$modelId = filter_var($options['model'] ?? null, FILTER_VALIDATE_INT);
if (! $modelId) {
    fwrite(STDERR, "Usage: php scripts/replay-custom-pregame.php --model=1 [--baseline-definition=/path/to/definition.json]\n");
    exit(2);
}

/** Read a definition as evidence; never execute arbitrary supplied engine code. */
function baselineDefinition(array $options): array
{
    if (! isset($options['baseline-definition'])) {
        return [];
    }
    $contents = file_get_contents((string) $options['baseline-definition']);
    if ($contents === false) {
        throw new RuntimeException('Unable to read baseline definition.');
    }
    return json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
}

$definition = baselineDefinition($options);
$required = [
    'algorithm_version',
    'offensive_bucket_windows_and_weights',
    'team_strength_time_formula',
    'player_strength_share_formula',
    'player_bucket_sog_conversion',
    'player_bucket_goal_conversion',
    'ev_season_gap_formula_and_coefficients',
    'pdo_finishing_formula_and_coefficients',
    'goalie_reconstruction_formula_and_coefficients',
    'win_probability_formula_and_overtime_treatment',
    'missing_history_policy',
    'lineup_and_starter_selection',
    'train_selection_protocol',
];
$missing = array_values(array_filter($required, static fn (string $key): bool => ! isset($definition[$key]) || $definition[$key] === ''));

DB::beginTransaction();
try {
    DB::statement('SET TRANSACTION READ ONLY');
    DB::statement("SET LOCAL statement_timeout = '30s'");
    $model = DB::table('nhl_model_runs')->where('id', $modelId)->first();
    if ($model === null || $model->model_family !== 'sat' || $model->target_season_id === null) {
        throw new RuntimeException('A SAT model with an explicit Test season is required.');
    }
    $run = DB::table('nhl_pregame_context_runs')->where('model_run_id', $modelId)
        ->where('status', 'completed')->orderByDesc('id')->first();
    if ($run === null) {
        throw new RuntimeException('The model has no completed pregame-context build.');
    }
    $contexts = DB::table('nhl_team_game_pregame_contexts as c')
        ->join('nhl_games as g', 'g.nhl_game_id', '=', 'c.nhl_game_id')
        ->where('c.run_id', $run->id)->where('g.season_id', $model->target_season_id);
    $teamRows = (clone $contexts)->count();
    $games = (clone $contexts)->distinct()->count('c.nhl_game_id');
    $travel = (clone $contexts)->whereNotNull(DB::raw("c.schedule_metrics->>'travel_km_since_last_game'"))->count();
    $savedRuns = DB::table('nhl_custom_game_prediction_runs')->where('model_run_id', $modelId)
        ->where('season_id', $model->target_season_id)->get(['id', 'algorithm_version', 'status', 'completed_games']);
    $savedPredictions = DB::table('nhl_custom_game_predictions')->whereIn('run_id', $savedRuns->pluck('id'))->count();
    $report = [
        'status' => 'blocked_before_prediction_replay',
        'model' => ['id' => $model->id, 'name' => $model->name, 'train_seasons' => json_decode($model->train_season_ids, true), 'test_season' => $model->target_season_id],
        'context_run' => $run->id,
        'test_games_with_team_context' => $games,
        'test_team_context_rows' => $teamRows,
        'team_context_rows_with_travel_km' => $travel,
        'saved_custom_runs' => $savedRuns,
        'saved_custom_predictions' => $savedPredictions,
        'baseline_definition_sha256' => $definition === [] ? null : hash('sha256', json_encode($definition, JSON_THROW_ON_ERROR)),
        'missing_baseline_definition_fields' => $missing,
        'replay_implementation' => 'The executable EV/PP runner is nhl:custom-predict. This script only audits the supplied historical baseline definition; it does not execute or establish replay equivalence.',
        'required_comparisons' => [
            'team_season_quarter_assignment', 'restored_missing_history_and_travel',
            'recent_strength_specific_toi_allocation', 'retained_bucket_sat_window_comparison',
            'venue_specific_fatigue', 'possession_and_form_matchup',
            'pdo_finishing_ablation', 'agreed_team_pp_allocation',
        ],
        'result_contract' => [
            'match_same_games_and_lineups' => true,
            'select_coefficients_from_train_only' => true,
            'reproduce_per_game_baseline_before_comparing' => true,
            'report_wins_games_win_pct_and_percentage_point_change' => true,
            'unmeasured_improvements' => null,
        ],
    ];
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    DB::rollBack();
}

// Fail closed: this audit cannot claim to have rerun a prediction algorithm.
exit(3);
