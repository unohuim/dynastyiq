<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NhlModelRun;
use DomainException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/** Shared default game calculation and compatibility view of the EV/PP engine. */
class NhlCustomGameForecast
{
    /** Resolve the selected complete model, defaulting explicitly to SAT_V2. */
    public function model(?int $id = null, ?string $season = null): NhlModelRun
    {
        $query = NhlModelRun::query()->where('model_family', 'sat')->where('status', 'complete');
        $model = $id === null
            ? $query->whereRaw('LOWER(name) = ?', [mb_strtolower((string) config('nhl-custom-prediction.default_model_name', 'sat_v2'))])->orderByDesc('id')->first()
            : $query->find($id);
        $eligible = static fn (NhlModelRun $candidate): bool => $candidate->train_season_ids !== []
            && $candidate->train_season_ids !== null && ($season === null || max($candidate->train_season_ids) < $season);
        if ($id === null && ($model === null || ! $eligible($model))) {
            $model = NhlModelRun::query()->where('model_family', 'sat')->where('status', 'complete')
                ->orderByDesc('id')->get()->first($eligible);
        }
        if ($model === null || ! $eligible($model)) {
            throw ValidationException::withMessages(['sat_model_run_id' => 'Build the selected SAT model before predicting games.']);
        }

        return $model;
    }

    /** Calculate one game; shared date inputs avoid repeating Train replay for every request. */
    public function predict(int $gameId, ?int $modelId = null, bool $storedBoxscore = false, array $rosterOverrides = []): array
    {
        $game = DB::table('nhl_games')->where('nhl_game_id', $gameId)->first();
        abort_if($game === null, 404);
        $model = $this->model($modelId, (string) $game->season_id);
        $date = (string) $game->game_date;
        $source = DB::table('nhl_games')->where('game_type', 2)->whereIn('game_state', ['OFF', 'FINAL'])
            ->where('game_date', '<', $date)->selectRaw('COUNT(*) AS games, MAX(updated_at) AS changed')->first();
        $schedule = DB::table('nhl_games')->where('game_date', $date)->orderBy('nhl_game_id')
            ->get(['nhl_game_id', 'start_time_utc', 'home_team_id', 'away_team_id']);
        $lineups = DB::table('nhl_current_lineups')->whereIn('nhl_game_id', $schedule->pluck('nhl_game_id'))
            ->orderBy('nhl_game_id')->orderBy('team_id')->get(['nhl_game_id', 'team_id', 'structure_hash', 'updated_at']);
        $goalies = DB::table('nhl_starting_goalie_observations')->whereIn('nhl_game_id', $schedule->pluck('nhl_game_id'))
            ->orderBy('id')->get(['id', 'updated_at']);
        $identity = ['model' => [$model->id, $model->updated_at, $model->train_season_ids],
            'definition' => config('nhl-custom-prediction'), 'date' => $date, 'schedule' => $schedule,
            'source' => $source, 'lineups' => $lineups, 'goalies' => $goalies, 'stored_boxscore' => $storedBoxscore,
            'roster_overrides' => $rosterOverrides, 'override_game' => $rosterOverrides === [] ? null : $gameId];
        $fingerprint = hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
        $key = 'nhl:custom-inputs:filled-v2:'.$model->id.':'.$date.':'.(int) $storedBoxscore.':'
            .hash('sha256', json_encode([$rosterOverrides, $rosterOverrides === [] ? null : $gameId], JSON_THROW_ON_ERROR));
        $cached = Cache::get($key);
        $prepared = is_array($cached) && ($cached['fingerprint'] ?? null) === $fingerprint ? $cached['prepared'] : null;
        if ($prepared === null) {
            $lock = Cache::lock($key.':lock', 150);
            if (! $lock->get()) {
                throw new ServiceUnavailableHttpException(2, 'Prediction inputs are being prepared. Retry this request.');
            }
            try {
                $cached = Cache::get($key);
                $prepared = is_array($cached) && ($cached['fingerprint'] ?? null) === $fingerprint ? $cached['prepared'] : null;
                if ($prepared === null) {
                    $reader = app(NhlCustomGamePredictionInputs::class);
                    $participants = [];
                    $participantErrors = [];
                    foreach (DB::table('nhl_games')->where('game_date', $date)->get() as $target) {
                        try {
                            $participants[$target->nhl_game_id] = $reader->anticipatedParticipants($target, $storedBoxscore,
                                (int) $target->nhl_game_id === $gameId ? $rosterOverrides : []);
                        } catch (DomainException $error) {
                            $participantErrors[$target->nhl_game_id] = $error->getMessage();
                            $participants[$target->nhl_game_id] = ['players' => [], 'starters' => [], 'lineup_sources' => []];
                        }
                    }
                    $prepared = ['inputs' => [], 'errors' => []];
                    foreach ($reader->forecasts((int) $model->id, $date, $participants) as $event) {
                        if ($event['error'] === null) {
                            $prepared['inputs'][$event['game']->nhl_game_id] = $event['input'];
                        } else {
                            $prepared['errors'][$event['game']->nhl_game_id] = $event['error'];
                        }
                    }
                    $prepared['errors'] = array_replace($prepared['errors'], $participantErrors);
                    $prepared['metadata'] = $reader->metadata();
                    Cache::put($key, ['fingerprint' => $fingerprint, 'prepared' => $prepared], 300);
                }
            } finally {
                $lock->release();
            }
        }
        $input = $prepared['inputs'][$gameId] ?? null;
        if ($input === null) {
            throw new DomainException($prepared['errors'][$gameId] ?? 'The scheduled game has no prepared inputs.');
        }
        $prediction = app(NhlCustomGamePredictionEngine::class)->predict($input);

        return ['game' => $game, 'model' => $model, 'input' => $input,
            'metadata' => $prepared['metadata'], 'prediction' => $prediction];
    }

    /** Preserve existing team-summary and roster field names using only the new calculation. */
    public function matchup(array $forecast): array
    {
        $prediction = $forecast['prediction'];
        $sides = [];
        foreach (['away' => 'home', 'home' => 'away'] as $venue => $opponent) {
            $team = $prediction['teams'][$venue];
            $roster = [];
            $baseline = ['sat' => 0.0, 'sog' => 0.0, 'goals' => 0.0];
            foreach ($team['players'] as $id => $player) {
                $ev = $player['strengths']['EV'];
                $pp = $player['strengths']['PP'];
                $goals = $ev['goals'] + $pp['goals'];
                $raw = ['sat' => 0.0, 'sog' => 0.0, 'goals' => 0.0];
                foreach (['EV', 'PP'] as $lane) {
                    foreach ($forecast['input']['teams'][$venue]['players'][$id]['buckets'][$lane] ?? [] as $bucket) {
                        $attempts = $bucket['sat_per_60'] * $player['strengths'][$lane]['seconds'] / 3600;
                        $shots = $attempts * $bucket['sog_probability'];
                        $raw['sat'] += $attempts;
                        $raw['sog'] += $shots;
                        $raw['goals'] += $shots * $bucket['goal_probability'];
                    }
                }
                foreach ($raw as $metric => $value) {
                    $baseline[$metric] += $value;
                }
                $roster[] = ['player_id' => (int) $id, 'nhl_player_id' => (int) $id, 'player_name' => $player['name'],
                    'position' => $player['role'], 'line_key' => $player['line_key'] ?? null, 'projection_source' => 'custom_pregame',
                    'model_run_id' => $forecast['model']->id, 'bucket_model_run_id' => $forecast['model']->id,
                    'bucket_projection_source' => 'custom_pregame',
                    'toi_seconds' => $ev['seconds'] + $pp['seconds'], 'confidence_score' => null,
                    'projected_sat' => $ev['sat'] + $pp['sat'], 'projected_sog' => $ev['sog'] + $pp['sog'],
                    'projected_goals' => $goals, 'team_xgf_share' => $team['goals'] > 0 ? $goals / $team['goals'] : 0.0,
                    'baseline_xsat_per_game' => $raw['sat'], 'adjusted_xsat_per_game' => $ev['sat'] + $pp['sat'],
                    'baseline_xsog_per_game' => $raw['sog'], 'adjusted_xsog_per_game' => $ev['sog'] + $pp['sog'],
                    'baseline_xgf_per_game' => $raw['goals'], 'adjusted_xgf_per_game' => $goals,
                    'strengths' => $player['strengths']];
            }
            $summary = ['baseline_xsat_per_game' => $baseline['sat'], 'adjusted_xsat_per_game' => $team['sat'],
                'baseline_xsog_per_game' => $baseline['sog'], 'adjusted_xsog_per_game' => $team['sog'],
                'baseline_xgf_per_game' => $baseline['goals'], 'adjusted_xgf_per_game' => $team['goals'],
                'ev_xgf_per_game' => $team['strengths']['EV']['goals'], 'pp_xgf_per_game' => $team['strengths']['PP']['goals'],
                'pk_xgf_per_game' => 0.0, 'ev_goalie_adjusted_xgf_per_game' => $team['strengths']['EV']['goals'],
                'pp_goalie_adjusted_xgf_per_game' => $team['strengths']['PP']['goals'],
                'pk_goalie_adjusted_xgf_per_game' => 0.0, 'total_goalie_adjusted_xgf_per_game' => $team['goals'],
                'goalie_adjusted_xgf_per_game' => $team['goals'], 'goalie_adjustment_per_game' => 0.0,
                'pk_goalie_adjustment_per_game' => 0.0, 'total_goalie_adjustment_per_game' => 0.0,
                'xsat_delta_per_game' => $team['sat'] - $baseline['sat'], 'xsog_delta_per_game' => $team['sog'] - $baseline['sog'],
                'pp_opportunities' => $team['pp_opportunities'],
                'ev_toi_seconds' => $team['time']['EV'], 'pp_toi_seconds' => $team['time']['PP'], 'pk_toi_seconds' => $team['time']['PK']];
            $sides[] = ['offense_team' => $team['abbrev'], 'defense_team' => $prediction['teams'][$opponent]['abbrev'],
                'summary' => $summary, 'roster' => $roster, 'defense_roster' => [], 'defense_summary' => [],
                'offense_profile' => [], 'defense_profile' => [], 'goalie_environment_profile' => [],
                'goalie_reasons' => [], 'goalie_reason_summary' => [], 'goalie_bucket_coverage' => [],
                'goalie_input_confidence' => null, 'input_confidence_score' => null];
        }

        return ['is_available' => true, 'inputs' => ['algorithm_version' => config('nhl-custom-prediction.algorithm_version'),
            'sat_model_run_id' => $forecast['model']->id, 'goal_weights' => ['offense' => 100, 'defense' => 0]],
            'sides' => $sides, 'custom_prediction' => $prediction];
    }
}
