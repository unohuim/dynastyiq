<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NhlExpectedGoalsModel;
use App\Models\NhlModelRun;
use App\Models\NhlNextGameEvaluation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Freezes training evidence and evaluates chronological, individual shot-bucket rates. */
class NhlNextGameEvaluationService
{
    public const METHODS = [
        'baseline' => 'Static / historical baseline',
        'last_season' => 'Last season',
        'recent_5' => 'Previous 5 games',
        'recent_10' => 'Previous 10 games',
        'recent_20' => 'Previous 20 games',
        'blend_5' => '50% baseline + 50% previous 5',
        'blend_10' => '50% baseline + 50% previous 10',
        'blend_20' => '50% baseline + 50% previous 20',
    ];

    public const STRENGTHS = ['ev', 'pp', 'pk', 'all'];

    /** Validate the temporal boundary before creating or preparing a run. */
    public function validateModel(NhlModelRun $model, string $season): void
    {
        $training = array_map('strval', $model->train_season_ids ?? []);
        if ($model->model_family !== 'sat' || $model->workflow_stage !== 'training'
            || $training === [] || max($training) >= $season
            || (string) $model->target_season_id !== $season) {
            throw new RuntimeException('Choose the model’s test season, strictly after every training season.');
        }
        if ($model->status === 'running' || ! $model->profilesReadyForRates()) {
            throw new RuntimeException('Finish building this model’s profiles before starting an evaluation.');
        }
    }

    private const PROFILE_ROW_LIMIT = 5000;
    private const GAME_PAGE_SIZE = 100;

    /** Advance one small checkpoint. The caller commits output and cursor together. */
    public function advance(NhlNextGameEvaluation $evaluation): void
    {
        $stage = data_get($evaluation->inputs, '_work.stage', 'initialize');

        match ($stage) {
            'initialize' => $this->initialize($evaluation),
            'baselines' => $this->prepareBaseline($evaluation),
            'games' => $this->prepareGames($evaluation),
            'evaluate' => $this->evaluateNextPlayer($evaluation),
            default => throw new RuntimeException('Unknown evaluation checkpoint.'),
        };
    }

    /** Read bounded metadata only; no season-wide profile hydration. */
    private function initialize(NhlNextGameEvaluation $evaluation): void
    {
        $model = NhlModelRun::query()->lockForUpdate()->findOrFail($evaluation->model_run_id);
        $this->validateModel($model, $evaluation->season_id);
        $training = array_map('strval', $model->train_season_ids);
        $profiles = $this->profileQuery($model->id);
        $satIds = (clone $profiles)->distinct()->limit(2)->pluck('sat_expected_goals_model_id');
        if ($satIds->count() !== 1 || $satIds->first() === null) {
            throw new RuntimeException('Complete, single-SAT-model offensive profiles are required.');
        }
        $satModel = NhlExpectedGoalsModel::query()->findOrFail($satIds->first());
        $keys = $satModel->buckets()->limit(self::PROFILE_ROW_LIMIT + 1)->pluck('bucket_key');
        if ($keys->count() > self::PROFILE_ROW_LIMIT) {
            throw new RuntimeException('SAT bucket mapping exceeds the evaluation safety limit.');
        }
        $inputs = $evaluation->inputs ?? [];
        $inputs = array_replace($inputs, [
            'training_seasons' => $training,
            'last_season' => max($training),
            'feature_config' => $satModel->feature_config,
            'sat_model_id' => $satModel->id,
            'bucket_keys' => $keys->all(),
            'source_signature' => $this->sourceSignature($model->id, max($training)),
            'model_updated_at' => (string) $model->updated_at,
            'freeze_started_at' => now()->toIso8601String(),
            'participant_source' => 'completed_game_summary',
            'blend_weight' => 0.5,
        ]);
        $inputs['_work'] = array_replace($inputs['_work'] ?? [], [
            'stage' => 'baselines',
            'baseline_cursor' => 0,
            'baseline_players_done' => 0,
            'baseline_players_total' => (clone $profiles)->distinct()->count('entity_id'),
            'game_cursor' => 0,
            'games_loaded' => 0,
        ]);
        $evaluation->update(['status' => 'preparing', 'inputs' => $inputs]);
    }

    /** Select only the columns needed to freeze offensive baselines. */
    private function profileQuery(int $modelId): \Illuminate\Database\Query\Builder
    {
        return DB::table('nhl_sat_model_entity_profile_buckets')
            ->where('model_run_id', $modelId)->where('profile_type', 'skater_offense')
            ->whereIn('strength', self::STRENGTHS)->whereNotNull('entity_id');
    }

    /** Detect source rebuilds during a multi-job freeze without loading source rows. */
    private function sourceSignature(int $modelId, string $lastSeason): array
    {
        $signature = [];
        foreach ([
            'profiles' => 'nhl_sat_model_entity_profile_buckets',
            'snapshots' => 'nhl_sat_model_entity_test_profile_buckets',
            'projections' => 'nhl_sat_model_entity_rate_projection_buckets',
        ] as $name => $table) {
            $query = DB::table($table)->where('model_run_id', $modelId)->where('profile_type', 'skater_offense');
            if ($name === 'snapshots') {
                $query->where('test_season_id', $lastSeason);
            }
            $signature[$name] = (array) $query
                ->selectRaw('COUNT(*) as rows, MAX(id) as last_id, MAX(updated_at) as updated')->first();
        }

        return $signature;
    }

    /** Freeze exactly one player's strengths; reject changing training evidence. */
    private function prepareBaseline(NhlNextGameEvaluation $evaluation): void
    {
        $inputs = $evaluation->inputs;
        $model = NhlModelRun::query()->lockForUpdate()->findOrFail($evaluation->model_run_id);
        $this->validateModel($model, $evaluation->season_id);
        if ((string) $model->updated_at !== $inputs['model_updated_at']) {
            throw new RuntimeException('The source model changed during preparation. Start a new evaluation.');
        }
        $playerId = $this->profileQuery($model->id)
            ->where('entity_id', '>', $inputs['_work']['baseline_cursor'])
            ->orderBy('entity_id')->value('entity_id');
        if ($playerId === null) {
            if ($this->sourceSignature($model->id, $inputs['last_season']) !== $inputs['source_signature']) {
                throw new RuntimeException('Profiles or projections changed during preparation. Start a new evaluation.');
            }
            $inputs['baseline_frozen_at'] = now()->toIso8601String();
            $inputs['_work']['stage'] = 'games';
            $evaluation->update(['inputs' => $inputs]);

            return;
        }
        $profiles = $this->profileQuery($model->id)->where('entity_id', $playerId)
            ->limit(self::PROFILE_ROW_LIMIT + 1)
            ->get(['entity_id', 'strength', 'matched_bucket_key', 'source_xsat_per_60',
                'source_season_ids', 'sat_expected_goals_model_id']);
        $latest = DB::table('nhl_sat_model_entity_test_profile_buckets')
            ->where('model_run_id', $model->id)->where('profile_type', 'skater_offense')
            ->where('entity_id', $playerId)->where('test_season_id', $inputs['last_season'])
            ->where('sat_expected_goals_model_id', $inputs['sat_model_id'])
            ->whereIn('strength', self::STRENGTHS)->limit(self::PROFILE_ROW_LIMIT + 1)
            ->get(['strength', 'matched_bucket_key', 'source_xsat_per_60', 'source_season_ids']);
        $projections = DB::table('nhl_sat_model_entity_rate_projection_buckets')
            ->where('model_run_id', $model->id)->where('profile_type', 'skater_offense')
            ->where('entity_id', $playerId)->limit(self::PROFILE_ROW_LIMIT + 1)
            ->get(['matched_bucket_key', 'is_other_bucket', 'projected_xsat_per_60', 'source_season_ids']);
        foreach ([$profiles, $latest, $projections] as $rows) {
            if ($rows->count() > self::PROFILE_ROW_LIMIT) {
                throw new RuntimeException('One player exceeds the baseline row safety limit.');
            }
            foreach ($rows as $row) {
                $sources = json_decode($row->source_season_ids, true, 512, JSON_THROW_ON_ERROR);
                if (! is_array($sources) || $sources === [] || array_diff($sources, $inputs['training_seasons']) !== []) {
                    throw new RuntimeException('Baseline includes evidence outside the training seasons.');
                }
            }
        }
        if ($profiles->contains(fn (object $row): bool => (int) $row->sat_expected_goals_model_id !== (int) $inputs['sat_model_id'])) {
            throw new RuntimeException('Profile SAT model changed during preparation.');
        }
        $latest = $latest->groupBy('strength');
        $rows = [];
        foreach ($profiles->groupBy('strength') as $strength => $buckets) {
            $historical = $this->profileRates($buckets);
            $static = $strength === 'all' && ! $projections->contains('is_other_bucket', true)
                ? $projections->whereNotNull('projected_xsat_per_60')
                    ->pluck('projected_xsat_per_60', 'matched_bucket_key')->map(fn ($rate) => (float) $rate)->all()
                : [];
            $rows[] = [
                'evaluation_id' => $evaluation->id, 'nhl_player_id' => $playerId, 'strength' => $strength,
                'rates' => json_encode([
                    'baseline' => $static !== [] ? $static : $historical,
                    'last_season' => $this->profileRates($latest->get($strength, collect())),
                    'source' => $static !== [] ? 'static_projection' : 'training_history',
                ], JSON_THROW_ON_ERROR),
            ];
        }
        DB::table('nhl_next_game_evaluation_baselines')->upsert(
            $rows, ['evaluation_id', 'nhl_player_id', 'strength'], ['rates']
        );
        $inputs['_work']['baseline_cursor'] = (int) $playerId;
        $inputs['_work']['baseline_players_done']++;
        $evaluation->update(['inputs' => $inputs]);
    }

    /** Materialize at most one page of game IDs, not a season-sized job fan-out. */
    private function prepareGames(NhlNextGameEvaluation $evaluation): void
    {
        $inputs = $evaluation->inputs;
        $games = DB::table('nhl_games')->where('season_id', $evaluation->season_id)
            ->where('game_type', 2)->whereIn('game_state', ['OFF', 'FINAL'])
            ->whereNotNull('start_time_utc')
            ->where('nhl_game_id', '>', $inputs['_work']['game_cursor'])
            ->orderBy('nhl_game_id')->limit(self::GAME_PAGE_SIZE)->get(['nhl_game_id', 'start_time_utc']);
        if ($games->isEmpty()) {
            if ((int) $evaluation->total_games === 0) {
                throw new RuntimeException('No completed regular-season games are available.');
            }
            $inputs['_work']['stage'] = 'evaluate';
            $evaluation->update(['status' => 'running', 'inputs' => $inputs]);

            return;
        }
        DB::table('nhl_next_game_evaluation_games')->upsert($games->map(fn (object $game): array => [
            'evaluation_id' => $evaluation->id, 'nhl_game_id' => $game->nhl_game_id,
            'starts_at' => $game->start_time_utc, 'state' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ])->all(), ['evaluation_id', 'nhl_game_id'], ['starts_at']);
        $inputs['_work']['game_cursor'] = (int) $games->last()->nhl_game_id;
        $inputs['_work']['games_loaded'] += $games->count();
        $evaluation->update(['inputs' => $inputs, 'total_games' => $inputs['_work']['games_loaded']]);
    }

    /** Share participant selection between paging and the player evaluator. */
    private function participants(int $gameId): \Illuminate\Database\Query\Builder
    {
        return DB::table('nhl_game_summaries as summaries')
            ->join('players', 'players.nhl_id', '=', 'summaries.nhl_player_id')
            ->where('summaries.nhl_game_id', $gameId)->where('summaries.toi', '>', 0)
            ->where(fn ($query) => $query->whereNull('players.is_goalie')->orWhere('players.is_goalie', false))
            ->where(fn ($query) => $query->whereNull('players.position')->orWhere('players.position', '<>', 'G'));
    }

    /** Evaluate one skater, then commit its checkpoint before handing back the worker. */
    private function evaluateNextPlayer(NhlNextGameEvaluation $evaluation): void
    {
        $work = DB::table('nhl_next_game_evaluation_games')->where('evaluation_id', $evaluation->id)
            ->where('state', 'pending')->orderBy('starts_at')->orderBy('nhl_game_id')->lockForUpdate()->first();
        if ($work === null) {
            $evaluation->update(['status' => 'completed']);

            return;
        }
        $inputs = $evaluation->inputs;
        if (($inputs['_work']['current_game'] ?? null) !== (int) $work->nhl_game_id) {
            $inputs['_work']['current_game'] = (int) $work->nhl_game_id;
            $inputs['_work']['player_cursor'] = 0;
            $inputs['_work']['players_done'] = 0;
        }
        $playerId = $this->participants((int) $work->nhl_game_id)
            ->where('summaries.nhl_player_id', '>', $inputs['_work']['player_cursor'])
            ->orderBy('summaries.nhl_player_id')->value('summaries.nhl_player_id');
        $error = null;
        if ($playerId !== null) {
            try {
                $this->evaluatePlayer($evaluation, (int) $work->nhl_game_id, (int) $playerId);
                $inputs['_work']['player_cursor'] = (int) $playerId;
                $inputs['_work']['players_done']++;
                $evaluation->update(['inputs' => $inputs]);

                return;
            } catch (\DomainException $exception) {
                $error = $exception->getMessage();
            }
        } elseif ($inputs['_work']['players_done'] === 0) {
            $error = 'No skater participants with positive ice time are available.';
        }
        if ($error !== null) {
            // Only this run's tentative, unpublished game output is removed.
            DB::table('nhl_next_game_evaluation_results')->where('evaluation_id', $evaluation->id)
                ->where('nhl_game_id', $work->nhl_game_id)->delete();
        }
        DB::table('nhl_next_game_evaluation_games')->where('id', $work->id)->update([
            'state' => $error === null ? 'completed' : 'excluded',
            'error' => $error, 'updated_at' => now(),
        ]);
        $counter = $error === null ? 'completed_games' : 'excluded_games';
        $evaluation->{$counter}++;
        unset($inputs['_work']['current_game'], $inputs['_work']['player_cursor'], $inputs['_work']['players_done']);
        $evaluation->fill(['inputs' => $inputs]);
        if ($evaluation->completed_games + $evaluation->excluded_games >= $evaluation->total_games) {
            $evaluation->status = 'completed';
        }
        $evaluation->save();
    }

    /**
     * Forecast one observed participant, including zero-attempt buckets with positive TOI.
     * Target TOI is used only for scoring rate error, never as an input to a forecast.
     */
    public function evaluatePlayer(NhlNextGameEvaluation $evaluation, int $gameId, int $playerId): void
    {
        $game = DB::table('nhl_games')->where('nhl_game_id', $gameId)->first();
        if ($game === null || $game->start_time_utc === null) {
            throw new \DomainException('Game start time is unavailable.');
        }
        $participants = $this->participants($gameId)
            ->where('summaries.nhl_player_id', $playerId)->limit(1)
            ->get(['summaries.nhl_player_id', 'summaries.nhl_team_id', 'summaries.toi', 'players.full_name']);
        if ($participants->isEmpty() || ! DB::table('nhl_shot_attempts_facts')->where('nhl_game_id', $gameId)->exists()) {
            throw new \DomainException('Player summaries or shot facts are missing.');
        }
        $playerIds = $participants->pluck('nhl_player_id')->all();
        // Select prior appearances before reading their buckets; zero-attempt games stay in the window.
        $ranked = DB::table('nhl_game_summaries as summaries')
            ->join('nhl_games as games', 'games.nhl_game_id', '=', 'summaries.nhl_game_id')
            ->whereIn('summaries.nhl_player_id', $playerIds)->where('summaries.toi', '>', 0)
            ->where('games.game_type', 2)->whereIn('games.game_state', ['OFF', 'FINAL'])
            ->where('games.start_time_utc', '<', $game->start_time_utc)
            ->whereIn('games.season_id', [$evaluation->inputs['last_season'], $evaluation->season_id])
            ->select(['summaries.nhl_player_id', 'summaries.nhl_game_id', 'summaries.toi'])
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY summaries.nhl_player_id ORDER BY games.start_time_utc DESC, games.nhl_game_id DESC) as recent_position');
        $history = DB::query()->fromSub($ranked, 'prior')->where('recent_position', '<=', 20)->get();
        $gameIds = $history->pluck('nhl_game_id')->push($gameId)->unique()->values()->all();
        $available = DB::table('nhl_shot_attempts_facts')->whereIn('nhl_game_id', $gameIds)->distinct()->pluck('nhl_game_id')->all();
        if (array_diff($gameIds, $available) !== []) {
            throw new \DomainException('Shot facts are missing for a prior appearance; it cannot be treated as zero attempts.');
        }
        $exposure = DB::table('nhl_player_game_strength_summaries')->whereIn('nhl_game_id', $gameIds)
            ->whereIn('nhl_player_id', $playerIds)->get()->keyBy(fn (object $row): string => $row->nhl_player_id . ':' . $row->nhl_game_id . ':' . strtolower($row->strength));
        $exposureGames = $exposure->mapWithKeys(fn (object $row): array => [$row->nhl_player_id . ':' . $row->nhl_game_id => true]);
        $model = new NhlExpectedGoalsModel(['feature_config' => $evaluation->inputs['feature_config']]);
        $expression = app(NhlSatModelEntityProfileBuilder::class)->modelBucketKeySql($model, 'facts');
        $facts = DB::table('nhl_shot_attempts_facts as facts')->whereIn('nhl_game_id', $gameIds)
            ->whereIn('shooter_player_id', $playerIds)->where('is_shot_attempt', true)
            ->whereRaw("COALESCE(period_type, '') <> 'SO' AND COALESCE(is_empty_net, false) = false")
            ->select(['nhl_game_id', 'shooter_player_id'])
            ->selectRaw("LOWER(COALESCE(NULLIF(strength_bucket, ''), strength)) as strength")
            ->selectRaw("{$expression} as bucket_key, COUNT(*) as sat")
            ->groupByRaw("nhl_game_id, shooter_player_id, LOWER(COALESCE(NULLIF(strength_bucket, ''), strength)), {$expression}")
            ->limit(self::PROFILE_ROW_LIMIT + 1)->get();
        if ($facts->count() > self::PROFILE_ROW_LIMIT) {
            throw new RuntimeException('Player history exceeds the shot-bucket row safety limit.');
        }
        $known = array_fill_keys($evaluation->inputs['bucket_keys'], true);
        $counts = [];
        foreach ($facts as $fact) {
            $key = isset($known[$fact->bucket_key]) ? $fact->bucket_key : 'L99|baseline=league';
            if (! isset($known[$key])) {
                throw new \DomainException('The frozen SAT model has no fallback for an unmatched shot bucket.');
            }
            foreach (array_unique([$fact->strength, 'all']) as $strength) {
                $id = $fact->shooter_player_id . ':' . $fact->nhl_game_id . ':' . $strength;
                $counts[$id][$key] = ($counts[$id][$key] ?? 0) + (int) $fact->sat;
            }
        }
        $baselines = DB::table('nhl_next_game_evaluation_baselines')->where('evaluation_id', $evaluation->id)
            ->whereIn('nhl_player_id', $playerIds)->get()->keyBy(fn (object $row): string => $row->nhl_player_id . ':' . $row->strength);
        $history = $history->groupBy('nhl_player_id');
        $rows = [];
        foreach ($participants as $player) {
            $prior = $history->get($player->nhl_player_id, collect())->sortBy('recent_position')->values();
            foreach (self::STRENGTHS as $strength) {
                $targetExposure = $exposure->get($player->nhl_player_id . ':' . $gameId . ':' . $strength);
                if ($strength !== 'all' && ! $exposureGames->has($player->nhl_player_id . ':' . $gameId)) {
                    throw new \DomainException('Strength TOI is missing for a participant. Rebuild strength summaries first.');
                }
                // The strength summarizer writes only strengths with observed shifts.
                $toi = $strength === 'all' ? (int) $player->toi : (int) ($targetExposure->toi ?? 0);
                if ($toi <= 0 && ! empty($counts[$player->nhl_player_id . ':' . $gameId . ':' . $strength])) {
                    throw new \DomainException('Shot attempts have no matching strength ice time.');
                }
                if ($toi <= 0) {
                    continue;
                }
                $baselineRow = $baselines->get($player->nhl_player_id . ':' . $strength);
                $baseline = $baselineRow === null ? [] : json_decode($baselineRow->rates, true, 512, JSON_THROW_ON_ERROR);
                $windows = [];
                foreach ([5, 10, 20] as $size) {
                    $seconds = 0;
                    $shots = [];
                    foreach ($prior->take($size) as $appearance) {
                        $id = $player->nhl_player_id . ':' . $appearance->nhl_game_id . ':' . $strength;
                        $strengthExposure = $exposure->get($id);
                        if ($strength !== 'all' && ! $exposureGames->has($player->nhl_player_id . ':' . $appearance->nhl_game_id)) {
                            throw new \DomainException('Strength TOI is missing in the previous-game window.');
                        }
                        $appearanceSeconds = $strength === 'all' ? (int) $appearance->toi : (int) ($strengthExposure->toi ?? 0);
                        if ($appearanceSeconds <= 0 && ! empty($counts[$id])) {
                            throw new \DomainException('Historical shots have no matching strength ice time.');
                        }
                        $seconds += $appearanceSeconds;
                        foreach ($counts[$id] ?? [] as $bucket => $sat) {
                            $shots[$bucket] = ($shots[$bucket] ?? 0) + $sat;
                        }
                    }
                    $windows[$size] = ['games' => min($size, $prior->count()), 'toi_seconds' => $seconds,
                        'rates' => $seconds > 0 ? array_map(fn (int $sat): float => $sat * 3600 / $seconds, $shots) : null];
                }
                $actual = $counts[$player->nhl_player_id . ':' . $gameId . ':' . $strength] ?? [];
                $comparison = $this->compare($baseline, $windows, $actual, $toi);
                $rows[] = [
                    'evaluation_id' => $evaluation->id, 'nhl_game_id' => $gameId,
                    'nhl_player_id' => $player->nhl_player_id, 'nhl_team_id' => $player->nhl_team_id,
                    'player_name' => $player->full_name ?: (string) $player->nhl_player_id,
                    'strength' => $strength, 'toi_seconds' => $toi, 'prior_games' => $prior->count(),
                    'baseline_source' => empty($baseline['baseline']) ? 'unavailable' : $baseline['source'],
                    'buckets' => json_encode($comparison['buckets'], JSON_THROW_ON_ERROR),
                    'metrics' => json_encode($comparison['metrics'], JSON_THROW_ON_ERROR),
                ];
            }
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('nhl_next_game_evaluation_results')->upsert($chunk,
                ['evaluation_id', 'nhl_game_id', 'nhl_player_id', 'strength'],
                ['nhl_team_id', 'player_name', 'toi_seconds', 'prior_games', 'baseline_source', 'buckets', 'metrics']);
        }
    }

    /**
     * Score methods on the same bucket/game cohort; absolute errors never cancel.
     *
     * @param array<string,mixed> $baseline
     * @param array<int,array<string,mixed>> $windows
     * @param array<string,int> $actual
     * @return array{buckets:array,metrics:array}
     */
    public function compare(array $baseline, array $windows, array $actual, int $toi): array
    {
        $base = $baseline['baseline'] ?? [];
        if ($base === [] || $toi <= 0) {
            return ['buckets' => ['actual' => $actual, 'windows' => $windows], 'metrics' => []];
        }
        $rates = ['baseline' => $base, 'last_season' => ($baseline['last_season'] ?? []) ?: $base];
        $fallbacks = ['baseline' => false, 'last_season' => empty($baseline['last_season'])];
        foreach ([5, 10, 20] as $size) {
            $recent = $windows[$size]['rates'] ?? null;
            $rates['recent_' . $size] = $recent ?? $base;
            $keys = array_unique([...array_keys($base), ...array_keys($recent ?? [])]);
            $rates['blend_' . $size] = [];
            foreach ($keys as $key) {
                $rates['blend_' . $size][$key] = $recent === null ? ($base[$key] ?? 0)
                    : 0.5 * ($base[$key] ?? 0) + 0.5 * ($recent[$key] ?? 0);
            }
            $fallbacks['recent_' . $size] = $fallbacks['blend_' . $size] = $recent === null;
        }
        $metrics = [];
        foreach ($rates as $method => $prediction) {
            $absoluteError = 0.0;
            foreach (array_unique([...array_keys($prediction), ...array_keys($actual)]) as $key) {
                $absoluteError += abs(($prediction[$key] ?? 0) * $toi / 3600 - ($actual[$key] ?? 0));
            }
            $metrics[$method] = ['actual_sat' => array_sum($actual), 'predicted_sat' => array_sum($prediction) * $toi / 3600,
                'absolute_error' => $absoluteError, 'fallback' => $fallbacks[$method]];
        }

        return ['buckets' => ['actual' => $actual, 'rates' => $rates, 'windows' => $windows], 'metrics' => $metrics];
    }

    /** Historical rates stay unavailable when their strength exposure is missing. */
    private function profileRates(Collection $rows): array
    {
        return $rows->whereNotNull('source_xsat_per_60')->pluck('source_xsat_per_60', 'matched_bucket_key')
            ->map(fn ($value): float => (float) $value)->all();
    }
}
