<?php

declare(strict_types=1);

namespace App\Services;

use Closure;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/** Run the isolated custom engine chronologically, optionally recording an append-only experiment. */
class NhlCustomGamePredictionReplay
{
    /** Inject only the new custom calculator and source reader, never legacy prediction services. */
    public function __construct(
        private readonly NhlCustomGamePredictionEngine $engine,
        private readonly NhlCustomGamePredictionInputs $inputs,
    ) {
    }

    /** Read-only by default; saving is an explicit operator choice using existing custom tables. */
    public function run(int $modelId, ?string $from = null, ?string $to = null, bool $save = false, bool $compareSchedule = false, ?Closure $progress = null): array
    {
        $this->inputs->prepare($modelId);
        if ($save) {
            foreach (['nhl_custom_game_prediction_runs', 'nhl_custom_game_predictions',
                'nhl_custom_game_prediction_teams', 'nhl_custom_game_prediction_player_inputs'] as $table) {
                if (! Schema::hasTable($table)) {
                    throw new DomainException('Apply the existing custom prediction migration before using --save.');
                }
            }
        }
        $variants = $compareSchedule ? ['without_schedule' => false, 'with_schedule' => true] : ['with_schedule' => true];
        $runs = $comparisons = $results = $exclusions = [];
        $count = 0;
        try {
            foreach ($this->inputs->games($from, $to) as $event) {
                $count++;
                if ($save && $runs === []) {
                    foreach ($variants as $name => $enabled) {
                        $runs[$name] = $this->startRun($enabled, $from, $to);
                    }
                }
                $game = $event['game'];
                $error = $event['error'];
                if ($game->home_team_score === null || $game->away_team_score === null
                    || $game->home_team_score === $game->away_team_score) {
                    $error ??= 'The final official winner is unavailable.';
                }
                $predictions = [];
                if ($error === null) {
                    try {
                        foreach ($variants as $name => $enabled) {
                            $predictions[$name] = $this->engine->predict($event['input'], $enabled);
                        }
                    } catch (DomainException $exception) {
                        $error = $exception->getMessage();
                    }
                }
                if ($error !== null) {
                    $exclusions[$game->nhl_game_id] = $error;
                    foreach ($runs as $id) {
                        $this->exclude($id, $game, $error);
                    }
                    $progress?->__invoke($count, (int) $game->nhl_game_id, false);
                    continue;
                }
                foreach ($predictions as $name => $prediction) {
                    $actualWinner = $game->home_team_score > $game->away_team_score ? 'home' : 'away';
                    $winner = $prediction['recommendation'] === 'OT' ? null
                        : ($prediction['recommendation'] === $prediction['teams']['home']['abbrev'] ? 'home' : 'away');
                    $actual = [];
                    foreach (['home' => $game->home_team_id, 'away' => $game->away_team_id] as $venue => $team) {
                        $actual[$venue] = 0;
                        foreach (['EV', 'PP'] as $lane) {
                            $actual[$venue] += $event['actual'][$team][$lane]['goals'] ?? 0;
                        }
                        $comparisons[$name]['team'][] = [$prediction['teams'][$venue]['goals'], $actual[$venue]];
                    }
                    $comparisons[$name]['game'][] = [$prediction['total_goals'], array_sum($actual)];
                    $results[$name][] = ['game_id' => (int) $game->nhl_game_id, 'date' => $game->game_date,
                        'away' => $prediction['teams']['away']['abbrev'], 'home' => $prediction['teams']['home']['abbrev'],
                        'away_prediction' => $prediction['teams']['away']['goals'],
                        'home_prediction' => $prediction['teams']['home']['goals'],
                        'away_actual_retained' => $actual['away'], 'home_actual_retained' => $actual['home'],
                        'away_actual_final' => (int) $game->away_team_score, 'home_actual_final' => (int) $game->home_team_score,
                        'away_win_probability' => $prediction['away_win_probability'],
                        'home_win_probability' => $prediction['home_win_probability'],
                        'pick' => $prediction['recommendation'], 'actual_winner' => $actualWinner,
                        'winner_correct' => $winner === null ? null : $winner === $actualWinner,
                        'away_schedule_applied' => $prediction['teams']['away']['schedule_adjustment_applied'],
                        'home_schedule_applied' => $prediction['teams']['home']['schedule_adjustment_applied'],
                        'probability_schedule_delta' => $prediction['home_probability_schedule_delta']];
                    if ($save) {
                        $this->store($runs[$name], $event, $prediction, $actualWinner, $winner);
                    }
                }
                $progress?->__invoke($count, (int) $game->nhl_game_id, true);
            }
            foreach ($runs as $name => $id) {
                DB::table('nhl_custom_game_prediction_runs')->where('id', $id)->update([
                    'status' => 'complete', 'total_games' => $count, 'completed_games' => count($results[$name] ?? []),
                    'excluded_games' => count($exclusions), 'updated_at' => now(),
                ]);
            }
        } catch (Throwable $exception) {
            foreach ($runs as $id) {
                DB::table('nhl_custom_game_prediction_runs')->where('id', $id)->update([
                    'status' => 'failed', 'last_error' => mb_substr($exception->getMessage(), 0, 4000), 'updated_at' => now(),
                ]);
            }
            throw $exception;
        }
        $summary = [];
        foreach ($variants as $name => $enabled) {
            $rows = $results[$name] ?? [];
            $picked = count(array_filter($rows, fn (array $row): bool => $row['winner_correct'] !== null));
            $wins = count(array_filter($rows, fn (array $row): bool => $row['winner_correct'] === true));
            $summary[$name] = ['run_id' => $runs[$name] ?? null, 'predicted_games' => count($rows),
                'wins' => $wins, 'losses' => $picked - $wins, 'unpicked_ties' => count($rows) - $picked,
                'win_pct' => $picked > 0 ? 100 * $wins / $picked : null,
                'game_coverage_pct' => $count > 0 ? 100 * count($rows) / $count : null,
                'team_goals' => $this->engine->accuracy($comparisons[$name]['team'] ?? []),
                'game_goals' => $this->engine->accuracy($comparisons[$name]['game'] ?? [])];
        }

        return ['model' => $this->inputs->metadata(), 'definition' => $this->engine->definition(),
            'selected_games' => $count, 'excluded_games' => count($exclusions), 'exclusions' => $exclusions,
            'summary' => $summary, 'games' => $results,
            'scope' => 'Historical lineups; retained regulation EV+PP goal errors; official final winners including OT/SO; no qualification cutoff.'];
    }

    /** Create a new run rather than changing a preceding experiment's configuration or record. */
    private function startRun(bool $schedule, ?string $from, ?string $to): int
    {
        $model = $this->inputs->metadata();

        return DB::table('nhl_custom_game_prediction_runs')->insertGetId([
            'name' => $model['model_name'].' custom '.($schedule ? 'with schedule' : 'without schedule'),
            'algorithm_version' => config('nhl-custom-prediction.algorithm_version'),
            'season_id' => $model['test_season'], 'model_run_id' => $model['model_id'], 'status' => 'running',
            'definition' => json_encode(['calculation' => $this->engine->definition(), 'source' => $model,
                'schedule_enabled' => $schedule, 'from' => $from, 'to' => $to], JSON_THROW_ON_ERROR),
            'total_games' => $model['selected_games'], 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** A missing input is an explicit excluded record, never a synthetic prediction. */
    private function exclude(int $runId, object $game, string $reason): void
    {
        DB::table('nhl_custom_game_predictions')->insert(['run_id' => $runId, 'nhl_game_id' => $game->nhl_game_id,
            'status' => 'excluded', 'source_cutoff_at' => $game->start_time_utc, 'last_error' => $reason,
            'created_at' => now(), 'updated_at' => now()]);
        DB::table('nhl_custom_game_prediction_runs')->where('id', $runId)->increment('excluded_games');
    }

    /** Atomically freeze both teams and their player inputs before attaching the completed-game comparison. */
    private function store(int $runId, array $event, array $prediction, string $actualWinner, ?string $winner): void
    {
        DB::transaction(function () use ($runId, $event, $prediction, $actualWinner, $winner): void {
            $game = $event['game'];
            $input = $event['input'];
            $time = now();
            $id = DB::table('nhl_custom_game_predictions')->insertGetId([
                'run_id' => $runId, 'nhl_game_id' => $game->nhl_game_id, 'status' => 'complete',
                'source_cutoff_at' => $game->start_time_utc,
                'home_team_context_id' => $input['teams']['home']['context_id'],
                'away_team_context_id' => $input['teams']['away']['context_id'],
                'home_goals_prediction' => $prediction['teams']['home']['goals'],
                'away_goals_prediction' => $prediction['teams']['away']['goals'],
                'total_goals_prediction' => $prediction['total_goals'],
                'home_win_probability' => $prediction['home_win_probability'],
                'away_win_probability' => $prediction['away_win_probability'],
                'confidence_score' => $prediction['confidence_score'],
                'confidence_inputs' => json_encode($prediction['confidence_inputs'], JSON_THROW_ON_ERROR),
                'recommendation' => $prediction['recommendation'],
                'home_actual_goals' => $game->home_team_score, 'away_actual_goals' => $game->away_team_score,
                'actual_winner' => $actualWinner, 'winner_correct' => $winner === null ? null : $winner === $actualWinner,
                'predicted_at' => $time, 'compared_at' => $time, 'created_at' => $time, 'updated_at' => $time,
            ]);
            $teamIds = [];
            foreach (['away', 'home'] as $venue) {
                $other = $venue === 'away' ? 'home' : 'away';
                $team = $prediction['teams'][$venue];
                $actual = $event['actual'][$team['id']] ?? [];
                $ownInputs = $input['teams'][$venue];
                unset($ownInputs['players']);
                $teamIds[$venue] = DB::table('nhl_custom_game_prediction_teams')->insertGetId([
                    'prediction_id' => $id, 'nhl_team_id' => $team['id'], 'opponent_team_id' => $prediction['teams'][$other]['id'],
                    'venue' => $venue, 'lineup_source' => $team['lineup_source'],
                    'starting_goalie_nhl_player_id' => $team['starting_goalie_id'], 'pregame_context_id' => $team['context_id'],
                    'inputs' => json_encode(['own' => $ownInputs, 'opponent_context_id' => $input['teams'][$other]['context_id'],
                        'opponent_team_metrics' => $input['teams'][$other]['team_metrics'], 'pp_allocation' => $team['pp_allocation'],
                        'league_pk_probability' => $input['league_pk_probability'], 'league_usage_seconds' => $input['league_usage_seconds'],
                        'actual_goal_basis' => ['actual_goals' => 'official_final', 'actual_ev_goals' => 'retained_regulation',
                            'actual_pp_goals' => 'retained_regulation', 'accuracy_target' => 'actual_ev_goals_plus_actual_pp_goals']], JSON_THROW_ON_ERROR),
                    'ev_goals_prediction' => $team['strengths']['EV']['goals'], 'pp_goals_prediction' => $team['strengths']['PP']['goals'],
                    'pk_goals_prediction' => null, 'goals_prediction' => $team['goals'],
                    'sat_prediction' => $team['sat'], 'sog_prediction' => $team['sog'],
                    'shooting_pct_prediction' => $team['sog'] > 0 ? 100 * $team['goals'] / $team['sog'] : null,
                    'pp_opportunities_prediction' => $team['pp_opportunities'],
                    'pp_success_pct_prediction' => $team['pp_opportunities'] > 0 ? 100 * $team['strengths']['PP']['goals'] / $team['pp_opportunities'] : null,
                    'ev_toi_seconds_prediction' => $team['time']['EV'], 'pp_toi_seconds_prediction' => $team['time']['PP'],
                    'pk_toi_seconds_prediction' => $team['time']['PK'],
                    'actual_goals' => $venue === 'home' ? $game->home_team_score : $game->away_team_score,
                    'actual_ev_goals' => $actual['EV']['goals'] ?? 0, 'actual_pp_goals' => $actual['PP']['goals'] ?? 0,
                    'actual_sat' => ($actual['EV']['sat'] ?? 0) + ($actual['PP']['sat'] ?? 0),
                    'actual_sog' => ($actual['EV']['sog'] ?? 0) + ($actual['PP']['sog'] ?? 0),
                    'actual_pp_opportunities' => $venue === 'home' ? $game->home_pp_opportunities : $game->away_pp_opportunities,
                    'created_at' => $time, 'updated_at' => $time,
                ]);
            }
            foreach (['away', 'home'] as $venue) {
                $other = $venue === 'away' ? 'home' : 'away';
                foreach ($prediction['teams'][$venue]['players'] as $playerId => $player) {
                    $ev = $player['strengths']['EV'];
                    $pp = $player['strengths']['PP'];
                    DB::table('nhl_custom_game_prediction_player_inputs')->insert([
                        'team_prediction_id' => $teamIds[$venue], 'opponent_team_prediction_id' => $teamIds[$other],
                        'nhl_player_id' => $playerId, 'player_role' => 'skater', 'player_name' => $player['name'],
                        'pregame_context_id' => $player['context_id'],
                        'inputs' => json_encode($input['teams'][$venue]['players'][$playerId], JSON_THROW_ON_ERROR),
                        'prediction' => json_encode($player, JSON_THROW_ON_ERROR),
                        'actual' => json_encode($event['player_actual'][$playerId] ?? [], JSON_THROW_ON_ERROR),
                        'sat_prediction' => $ev['sat'] + $pp['sat'], 'toi_seconds_prediction' => $ev['seconds'] + $pp['seconds'],
                        'created_at' => $time, 'updated_at' => $time,
                    ]);
                }
            }
            DB::table('nhl_custom_game_prediction_runs')->where('id', $runId)->increment('completed_games');
        });
    }
}
