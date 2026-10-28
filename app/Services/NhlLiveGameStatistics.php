<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\ScoreNhlPredictionGameSatJob;
use App\Models\NhlExpectedGoalsModel;
use App\Models\NhlGame;
use App\Models\NhlModelRun;
use Illuminate\Support\Facades\DB;

/** Score one game's active attempts using a stable pair of existing trained models. */
final class NhlLiveGameStatistics
{
    /** Return model provenance and team expectations without dispatching season refreshes. */
    public function calculate(int $gameId, ?string $pinned): array
    {
        $game = NhlGame::query()->findOrFail($gameId);
        $ids = $pinned === null ? [] : json_decode($pinned, true, 512, JSON_THROW_ON_ERROR);
        if ($ids === []) {
            $runs = NhlModelRun::query()->where('model_family', NhlModelRun::FAMILY_SAT)
                ->whereNotIn('status', [NhlModelRun::STATUS_ARCHIVED, NhlModelRun::STATUS_FAILED])->orderByDesc('id')->get();
            foreach ($runs as $run) {
                if ($run->projectionSeasonId() !== (string) $game->season_id || (int) ($run->game_type ?? 2) !== (int) $game->game_type) {
                    continue;
                }
                $models = ScoreNhlPredictionGameSatJob::trainedModels($run);
                if ($models->has('goal') && $models->has('shot_on_goal')) {
                    $ids = $models->map(fn ($model) => $model->id)->all();
                    break;
                }
            }
        }
        if (empty($ids['goal'])) {
            return ['model_ids' => [], 'teams' => [], 'available' => false];
        }
        foreach ($ids as $id) {
            $model = NhlExpectedGoalsModel::query()->findOrFail($id);
            if (! app(NhlShotAttemptModelScorer::class)->scoreGame($model, $gameId, (string) $game->season_id, (int) $game->game_type, 0.10)) {
                throw new \RuntimeException('Live SAT scoring is unavailable.');
            }
        }
        $model = NhlExpectedGoalsModel::query()->findOrFail($ids['goal']);
        $sogOnly = ($model->feature_config['sample_mode'] ?? null) === 'sog';
        $rows = DB::table('nhl_shot_attempts_facts as f')
            ->leftJoin('nhl_shot_attempt_model_scores as s', function ($join) use ($ids): void {
                $join->on('s.shot_attempt_fact_id', '=', 'f.id')->where('s.expected_goals_model_id', $ids['goal']);
            })->where('f.nhl_game_id', $gameId)->where('f.is_empty_net', false)
            ->get(['f.team_id', 'f.goalie_player_id', 'f.strength_bucket', 'f.is_shot_on_goal', 'f.is_goal', 's.probability', 's.is_scored']);
        $teams = [];
        foreach ([(int) $game->away_team_id, (int) $game->home_team_id] as $team) {
            $attempts = $rows->where('team_id', $team)->filter(fn ($row) => ! $sogOnly || $row->is_shot_on_goal);
            $complete = $attempts->every(fn ($row) => $row->is_scored && $row->probability !== null);
            $teams[$team] = ['xgf' => $complete ? round($attempts->sum('probability'), 3) : null, 'splits' => []];
            foreach (['ev', 'pp', 'pk'] as $strength) {
                $split = $attempts->where('strength_bucket', $strength);
                $teams[$team]['splits'][$strength] = ['xgf' => $split->every(fn ($row) => $row->is_scored && $row->probability !== null)
                    ? round($split->sum('probability'), 3) : null];
            }
        }
        foreach ($teams as $team => &$values) {
            $other = $team === (int) $game->home_team_id ? (int) $game->away_team_id : (int) $game->home_team_id;
            $values['xga'] = $teams[$other]['xgf'];
            foreach (['ev' => 'ev', 'pp' => 'pk', 'pk' => 'pp'] as $strength => $opponentStrength) {
                $values['splits'][$strength]['xga'] = $teams[$other]['splits'][$opponentStrength]['xgf'];
            }
        }
        unset($values);
        $goalies = [];
        foreach ($rows->whereNotNull('goalie_player_id')->groupBy('goalie_player_id') as $id => $attempts) {
            $eligible = $attempts->filter(fn ($row) => ! $sogOnly || $row->is_shot_on_goal);
            $xga = $eligible->every(fn ($row) => $row->is_scored && $row->probability !== null) ? $eligible->sum('probability') : null;
            $goalies[$id] = ['xga' => $xga, 'gsax' => $xga === null ? null : $xga - $attempts->where('is_goal', true)->count()];
        }
        return ['model_ids' => $ids, 'teams' => $teams, 'goalies' => $goalies, 'available' => true];
    }
}
