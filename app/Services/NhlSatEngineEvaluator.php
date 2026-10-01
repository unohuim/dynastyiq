<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\EvaluateNhlSatEngineGameJob;
use App\Models\NhlModelRun;
use App\Models\NhlSatEngine;
use App\Models\NhlSatEngineRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Coordinates bounded historical evaluations without changing production selection. */
class NhlSatEngineEvaluator
{
    /** Share validated settings between saved engines and discovery runs. */
    public function __construct(private readonly NhlSatEngineSettings $settings)
    {
    }

    /** @param array<string, mixed> $scope @return list<int> */
    public function gameIds(NhlModelRun $model, array $scope): array
    {
        if (! $model->target_season_id || $model->model_family !== 'sat'
            || $model->status !== 'complete' || empty($model->train_season_ids)
            || max($model->train_season_ids) >= $model->target_season_id) {
            throw ValidationException::withMessages(['model_run_id' => 'Select a completed SAT model with a test season strictly after its training seasons.']);
        }
        $query = DB::table('nhl_games')->where('season_id', $model->target_season_id)
            ->where('game_type', 2)->whereIn('game_state', ['OFF', 'FINAL'])
            ->when($scope['start_date'] ?? null, fn ($q, $date) => $q->where('game_date', '>=', $date))
            ->when($scope['end_date'] ?? null, fn ($q, $date) => $q->where('game_date', '<=', $date))
            ->when($scope['teams'] ?? [], fn ($q, $teams) => $q->where(fn ($q) => $q
                ->whereIn('away_team_abbrev', $teams)->orWhereIn('home_team_abbrev', $teams)));
        if ($scope['mode'] === 'selected') {
            $query->whereIn('nhl_game_id', $scope['game_ids']);
        }
        // Only identifiers and dates enter memory, never boxscores or prediction inputs.
        $games = $query->orderBy('game_date')->orderBy('nhl_game_id')->limit(3001)
            ->get(['nhl_game_id', 'game_date']);
        if ($games->isEmpty() || $games->count() > 3000) {
            throw ValidationException::withMessages(['scope' => 'Select between 1 and 3,000 completed regular-season games.']);
        }
        if (in_array($scope['mode'], ['games', 'days'], true)) {
            $field = $scope['mode'] === 'games' ? 'nhl_game_id' : 'game_date';
            $pool = $games->pluck($field)->unique()->values()->all();
            $selection = $scope['selection'] ?? 'first';
            if (! in_array($selection, ['first', 'last', 'random'], true)) {
                throw ValidationException::withMessages(['scope.selection' => 'Choose First, Last or Random.']);
            }
            if ($selection === 'random') {
                $seed = $scope['selection_seed'] ?? '';
                if ($seed === '') {
                    throw ValidationException::withMessages(['scope.selection' => 'Random selection requires a run seed.']);
                }
                usort($pool, fn ($a, $b): int => strcmp(hash('sha256', $seed . ':' . $a), hash('sha256', $seed . ':' . $b))
                    ?: strcmp((string) $a, (string) $b));
            }
            $count = max(1, (int) $scope['count']);
            $selected = $selection === 'last' ? array_slice($pool, -$count) : array_slice($pool, 0, $count);
            $games = $games->whereIn($field, $selected);
        }
        $ids = $games->pluck('nhl_game_id')->map(fn ($id): int => (int) $id)->values()->all();
        if ($scope['mode'] === 'selected' && count($ids) !== count(array_unique($scope['game_ids']))) {
            throw ValidationException::withMessages(['scope.game_ids' => 'Every selected game must belong to this model test season and match the scope.']);
        }

        return $ids;
    }

    /** @param array<string, mixed> $input */
    public function start(array $input, ?NhlSatEngine $engine = null): NhlSatEngineRun
    {
        $input['scope']['selection'] = in_array($input['scope']['mode'], ['games', 'days'], true)
            ? ($input['scope']['selection'] ?? 'first') : 'first';
        unset($input['scope']['selection_seed']);
        if ($input['scope']['selection'] === 'random') {
            $input['scope']['selection_seed'] = (string) Str::uuid();
        }
        $model = NhlModelRun::query()->findOrFail($engine?->model_run_id ?? $input['model_run_id']);
        $games = $this->gameIds($model, $input['scope']);
        $metrics = $model->metrics ?? [];
        foreach (['rate_projection' => 'rate_projections', 'toi_projection' => 'toi_projections'] as $counter => $stage) {
            $completed = $metrics[$stage . '_completed_at'] ?? null;
            $started = $metrics[$stage . '_started_at'] ?? null;
            if (! $completed || ($started && strtotime($completed) < strtotime($started))
                || (int) ($metrics[$counter . '_entities_queued'] ?? 0) < 1
                || (int) ($metrics[$counter . '_entities_completed'] ?? 0) !== (int) $metrics[$counter . '_entities_queued']) {
                throw ValidationException::withMessages(['model_run_id' => 'Finish this model’s /60 and TOI builds before evaluating engines.']);
            }
        }
        if (! DB::table('nhl_expected_goals_models')->where('model_run_id', $model->id)->where('prediction_target', 'goal')->exists()
            || ! DB::table('nhl_sat_model_entity_rate_projection_buckets')->where('model_run_id', $model->id)->where('profile_type', 'skater_offense')->where('game_type', 2)->exists()
            || ! DB::table('nhl_sat_model_entity_toi_projections')->where('model_run_id', $model->id)->where('profile_type', 'skater_offense')->where('game_type', 2)->where('projected_toi_per_game_seconds', '>', 0)->exists()) {
            throw ValidationException::withMessages(['model_run_id' => 'This model is missing goal evaluation, offensive rates, or TOI outputs.']);
        }
        $candidates = $input['kind'] === 'build'
            ? [$this->settings->validate($engine->settings)] : $this->settings->automaticCandidates();
        $splits = [];
        foreach ($candidates as &$candidate) {
            $key = $candidate['offense'] . ':' . $candidate['defense'];
            if (! isset($splits[$key])) {
                $splits[$key] = ['index' => count($splits), 'offense' => $candidate['offense'], 'defense' => $candidate['defense']];
            }
            $candidate['split_index'] = $splits[$key]['index'];
        }
        unset($candidate);

        return DB::transaction(function () use ($input, $engine, $model, $games, $candidates, $splits): NhlSatEngineRun {
            $run = NhlSatEngineRun::query()->create([
                'engine_id' => $engine?->id, 'model_run_id' => $model->id,
                'kind' => $input['kind'], 'status' => 'queued',
                'game_count' => count($games), 'prediction_count' => count($games) * count($splits),
                'candidate_count' => count($candidates),
                'definition' => [
                    'model_updated_at' => $model->updated_at->toISOString(),
                    'model_name' => $model->name, 'test_season' => $model->target_season_id,
                    'scope' => $input['scope'], 'game_ids' => $games, 'splits' => array_values($splits),
                    'desired_win_pct' => $input['desired_win_pct'], 'min_coverage_pct' => $input['min_coverage_pct'],
                    'search' => null,
                    'automatic_search' => $input['kind'] === 'discovery' ? [
                        'strategy' => 'coarse_to_fine_v1', 'stage' => 0,
                        'stage_first_split' => 0, 'stage_split_count' => count($splits), 'lanes' => 4,
                    ] : null,
                    'confidence_search' => $input['kind'] === 'discovery' ? 'automatic' : 'configured',
                ],
            ]);
            foreach (array_chunk($candidates, 100) as $chunk) {
                DB::table('nhl_sat_engine_candidates')->insert(array_map(fn (array $row): array => [
                    'run_id' => $run->id, 'split_index' => $row['split_index'],
                    'settings' => json_encode(array_diff_key($row, ['split_index' => true]), JSON_THROW_ON_ERROR),
                    'created_at' => now(), 'updated_at' => now(),
                ], $chunk));
            }
            foreach (array_slice(array_values($splits), 0, $input['kind'] === 'discovery' ? 4 : count($splits)) as $split) {
                EvaluateNhlSatEngineGameJob::dispatch($run->id, $split['index'], 0)->afterCommit();
            }

            return $run;
        });
    }

    /** Refine three distinct promising splits under the caller's locked run transaction.
     * Each stage appends work; frozen games and existing results are never replaced.
     */
    public function advanceDiscovery(NhlSatEngineRun $run): bool
    {
        $search = $run->definition['automatic_search'] ?? null;
        if (($search['strategy'] ?? null) !== 'coarse_to_fine_v1' || $search['stage'] >= 2) {
            return false;
        }
        $centers = $indices = [];
        for ($i = 0; $i < 3; $i++) {
            $best = DB::table('nhl_sat_engine_candidates')->where('run_id', $run->id)
                ->whereNotNull('metrics')->whereNotNull('win_pct')->where('coverage_pct', '>', 0)
                ->whereNotIn('split_index', $indices)->orderByDesc('meets_targets')
                ->orderByRaw('GREATEST(0, ? - win_pct) + GREATEST(0, ? - coverage_pct) ASC',
                    [$run->definition['desired_win_pct'], $run->definition['min_coverage_pct']])
                ->orderByDesc('win_pct')->orderByDesc('coverage_pct')->orderBy('id')->first();
            if ($best === null) {
                break;
            }
            $indices[] = (int) $best->split_index;
            $centers[] = json_decode($best->settings, true, 512, JSON_THROW_ON_ERROR);
        }
        $definition = $run->definition;
        $candidates = $this->settings->automaticCandidates($search['stage'] + 1, $centers, $definition['splits']);
        if ($candidates === []) {
            return false;
        }
        $first = count($definition['splits']);
        foreach (array_chunk($candidates, 100, true) as $chunk) {
            $rows = [];
            foreach ($chunk as $offset => $settings) {
                $index = $first + $offset;
                $definition['splits'][] = ['index' => $index, 'offense' => $settings['offense'], 'defense' => $settings['defense']];
                $rows[] = ['run_id' => $run->id, 'split_index' => $index,
                    'settings' => json_encode($settings, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()];
            }
            DB::table('nhl_sat_engine_candidates')->insert($rows);
        }
        $definition['automatic_search'] = [...$search, 'stage' => $search['stage'] + 1,
            'stage_first_split' => $first, 'stage_split_count' => count($candidates)];
        $run->definition = $definition;
        $run->prediction_count += count($candidates) * $run->game_count;
        $run->candidate_count += count($candidates);
        $run->status = 'queued';
        $run->save();
        for ($lane = 0; $lane < min($search['lanes'], count($candidates)); $lane++) {
            EvaluateNhlSatEngineGameJob::dispatch($run->id, $first + $lane, 0)->afterCommit();
        }

        return true;
    }

    /** Search all distinct supported gap selections and inclusive confidence intervals.
     * Only scalar results for one split enter memory (at most 3000 games).
     * @param array<string, mixed> $settings
     * @return list<array{settings: array<string, mixed>, metrics: array<string, mixed>}>
     */
    public function discoverQualifications(int $runId, int $splitIndex, array $settings, int $selected): array
    {
        $settings = [...$settings, 'gap' => 0, 'confidence_min' => 0, 'confidence_max' => 100];
        $base = $this->metrics($runId, $splitIndex, $settings, $selected);
        $games = DB::table('nhl_sat_engine_results')->where('run_id', $runId)->where('split_index', $splitIndex)
            ->where('status', 'complete')->whereNotNull('correct')->where('gap', '>', 0)
            ->whereBetween('confidence', [0, 100])->limit(3001)->get(['confidence', 'gap', 'correct']);
        if ($games->count() > 3000) {
            throw new \RuntimeException('Automatic discovery exceeded its game bound.');
        }
        $groups = $events = [];
        foreach ($games as $game) {
            $confidence = (int) $game->confidence;
            $groups[$confidence] ??= ['confidence' => $confidence, 'picks' => 0, 'wins' => 0];
            $groups[$confidence]['picks']++;
            $groups[$confidence]['wins'] += (int) $game->correct;
            $tick = (int) round((float) $game->gap * 1000000);
            if ($tick <= 10000000) {
                $events[$tick][] = $game;
            }
        }
        // A strict > threshold changes selection only at an observed gap; zero includes every non-tie.
        $events[0] = [];
        ksort($events);
        $bestByCount = [];
        foreach ($events as $tick => $removed) {
            foreach ($removed as $game) {
                $groups[(int) $game->confidence]['picks']--;
                $groups[(int) $game->confidence]['wins'] -= (int) $game->correct;
            }
            foreach ($this->settings->confidenceFrontier(array_values(array_filter($groups, fn ($row) => $row['picks'] > 0))) as $range) {
                $previous = $bestByCount[$range['picks']] ?? null;
                if ($previous === null || $range['wins'] > $previous['metrics']['wins']) {
                    $bestByCount[$range['picks']] = [
                        'settings' => [...$settings, 'gap' => $tick / 1000000,
                            'confidence_min' => $range['confidence_min'], 'confidence_max' => $range['confidence_max']],
                        'metrics' => [...$base, 'picks' => $range['picks'], 'wins' => $range['wins'],
                            'losses' => $range['picks'] - $range['wins'], 'win_pct' => 100 * $range['wins'] / $range['picks'],
                            'coverage_pct' => 100 * $range['picks'] / (int) $base['eligible']],
                    ];
                }
            }
        }
        krsort($bestByCount);
        $frontier = [];
        $best = null;
        foreach ($bestByCount as $candidate) {
            $metrics = $candidate['metrics'];
            if ($best === null || $metrics['wins'] * $best['picks'] > $best['wins'] * $metrics['picks']) {
                $frontier[] = $candidate;
                $best = $metrics;
            }
        }

        return $frontier ?: [['settings' => $settings, 'metrics' => $base]];
    }

    /** Fail a run if its model inputs were rebuilt during evaluation. */
    public function assertModelUnchanged(NhlSatEngineRun $run): void
    {
        $model = NhlModelRun::query()->findOrFail($run->model_run_id);
        if ($model->status !== 'complete' || $model->updated_at->toISOString() !== $run->definition['model_updated_at']) {
            throw new \RuntimeException('The selected model changed during evaluation. Start a new run after its builds finish.');
        }
    }

    /** Compute one game using the normal pinned-model prediction path.
     * @return array<string, mixed>
     */
    public function predict(NhlSatEngineRun $run, int $splitIndex, int $gameId): array
    {
        $this->assertModelUnchanged($run);
        $game = DB::table('nhl_games')->where('nhl_game_id', $gameId)->firstOrFail();
        $row = [
            'run_id' => $run->id, 'split_index' => $splitIndex, 'nhl_game_id' => $gameId,
            'game' => json_encode(['date' => $game->game_date, 'away' => $game->away_team_abbrev,
                'home' => $game->home_team_abbrev, 'away_goals' => $game->away_team_score,
                'home_goals' => $game->home_team_score], JSON_THROW_ON_ERROR),
            'status' => 'excluded', 'created_at' => now(), 'updated_at' => now(),
        ];
        $facts = DB::table('nhl_shot_attempts_facts')->where('nhl_game_id', $gameId)
            ->where('is_shot_attempt', true)->where(fn ($q) => $q->whereNull('period_type')->orWhere('period_type', '!=', 'SO'))
            ->selectRaw('team_id, COUNT(DISTINCT play_by_play_id) AS sat')->groupBy('team_id')->pluck('sat', 'team_id');
        if ($game->away_team_score === null || $game->home_team_score === null
            || $game->away_team_sog === null || $game->home_team_sog === null
            || $game->away_team_score === $game->home_team_score
            || ! isset($facts[$game->away_team_id], $facts[$game->home_team_id])) {
            return [...$row, 'reason' => 'Missing final boxscore totals or shot-attempt facts.'];
        }
        try {
            $payload = app(NhlGamePredictionPayload::class)->build($gameId, [
                'sat_model_run_id' => $run->model_run_id, 'use_stored_boxscore' => true,
                'engine_weights' => $run->definition['splits'][$splitIndex],
            ]);
        } catch (ValidationException $exception) {
            return [...$row, 'reason' => mb_substr(implode(' ', $exception->validator->errors()->all()), 0, 500)];
        }
        if (! ($payload['prediction_available'] ?? false)) {
            return [...$row, 'reason' => 'Prediction unavailable with stored inputs.'];
        }
        $away = $payload['teams']['away']['summary'];
        $home = $payload['teams']['home']['summary'];
        $difference = (float) $home['total_goalie_adjusted_xgf_per_game'] - (float) $away['total_goalie_adjusted_xgf_per_game'];

        return [...$row, 'status' => 'complete',
            'confidence' => $payload['prediction']['confidence_score'], 'gap' => abs($difference),
            'correct' => $difference == 0.0 ? null : ($difference > 0) === ($game->home_team_score > $game->away_team_score),
            'pred_sat' => $away['adjusted_xsat_per_game'] + $home['adjusted_xsat_per_game'],
            'pred_sog' => $away['adjusted_xsog_per_game'] + $home['adjusted_xsog_per_game'],
            'pred_goals' => $away['total_goalie_adjusted_xgf_per_game'] + $home['total_goalie_adjusted_xgf_per_game'],
            'actual_sat' => (int) $facts[$game->away_team_id] + (int) $facts[$game->home_team_id],
            'actual_sog' => $game->away_team_sog + $game->home_team_sog,
            'actual_goals' => $game->away_team_score + $game->home_team_score,
            'prediction' => json_encode(['away' => $away, 'home' => $home, 'winner' => $difference == 0.0 ? null
                : ($difference > 0 ? $game->home_team_abbrev : $game->away_team_abbrev)], JSON_THROW_ON_ERROR),
        ];
    }

    /** Aggregate all-game totals separately from qualified-pick records.
     * @param array<string, mixed> $settings @return array<string, mixed>
     */
    public function metrics(int $runId, int $splitIndex, array $settings, int $selected): array
    {
        $query = DB::table('nhl_sat_engine_results')->where('run_id', $runId)->where('split_index', $splitIndex)->where('status', 'complete');
        $totals = (clone $query)->selectRaw('COUNT(*) AS eligible, SUM(pred_sat) AS pred_sat, SUM(pred_sog) AS pred_sog, SUM(pred_goals) AS pred_goals,
            SUM(actual_sat) AS actual_sat, SUM(actual_sog) AS actual_sog, SUM(actual_goals) AS actual_goals')->first();
        $picks = (clone $query)->whereBetween('confidence', [$settings['confidence_min'], $settings['confidence_max']])
            ->where('gap', '>', $settings['gap'])->whereNotNull('correct');
        $count = (clone $picks)->count();
        $wins = (clone $picks)->where('correct', true)->count();
        $allCount = (clone $query)->whereNotNull('correct')->count();
        $allWins = (clone $query)->where('correct', true)->count();

        return [...(array) $totals, 'selected' => $selected, 'excluded' => $selected - (int) $totals->eligible,
            'picks' => $count, 'wins' => $wins, 'losses' => $count - $wins,
            'all_wins' => $allWins, 'all_losses' => $allCount - $allWins,
            'win_pct' => $count > 0 ? 100 * $wins / $count : null,
            'coverage_pct' => $totals->eligible > 0 ? 100 * $count / $totals->eligible : 0,
        ];
    }

    /** Evaluate confidence intervals from at most 101 aggregated confidence rows.
     * @param array<string, mixed> $settings
     * @return list<array{settings: array<string, mixed>, metrics: array<string, mixed>}>
     */
    public function discoverConfidence(int $runId, int $splitIndex, array $settings, int $selected): array
    {
        $settings = [...$settings, 'confidence_min' => 0, 'confidence_max' => 100];
        $base = $this->metrics($runId, $splitIndex, $settings, $selected);
        $groups = DB::table('nhl_sat_engine_results')->where('run_id', $runId)->where('split_index', $splitIndex)
            ->where('status', 'complete')->whereNotNull('correct')->where('gap', '>', $settings['gap'])
            ->whereBetween('confidence', [0, 100])->groupBy('confidence')->orderBy('confidence')
            ->selectRaw('confidence, COUNT(*) AS picks, SUM(CASE WHEN correct THEN 1 ELSE 0 END) AS wins')
            ->get()->map(fn ($row): array => ['confidence' => (int) $row->confidence,
                'picks' => (int) $row->picks, 'wins' => (int) $row->wins])->all();
        $frontier = $this->settings->confidenceFrontier($groups);
        if ($frontier === []) {
            return [['settings' => $settings, 'metrics' => $base]];
        }

        return array_map(fn (array $range): array => [
            'settings' => [...$settings, 'confidence_min' => $range['confidence_min'], 'confidence_max' => $range['confidence_max']],
            'metrics' => [...$base, 'picks' => $range['picks'], 'wins' => $range['wins'], 'losses' => $range['picks'] - $range['wins'],
                'win_pct' => 100 * $range['wins'] / $range['picks'],
                'coverage_pct' => (int) $base['eligible'] > 0 ? 100 * $range['picks'] / (int) $base['eligible'] : 0],
        ], $frontier);
    }
}
