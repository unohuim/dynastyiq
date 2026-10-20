<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\ForecastNhlEvaluationPlayerJob;
use App\Models\NhlEvaluationGameForecast;
use App\Models\NhlExpectedGoalsModel;
use App\Models\NhlGame;
use App\Models\NhlNextGameEvaluation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** First-pass quarter-direction and recent-usage experiment; never changes live predictions. */
class NhlEvaluationGameForecaster
{
    public const CONTEXT_VERSION = 6;

    /** Admit one game inside the caller's evaluation-row lock, preserving saved forecasts. */
    public function admit(NhlNextGameEvaluation $evaluation, NhlGame $game, string $strength, string $period): NhlEvaluationGameForecast
    {
        $existing = NhlEvaluationGameForecast::query()->where('evaluation_id', $evaluation->id)
            ->where('nhl_game_id', $game->nhl_game_id)->where('strength', $strength)->where('period', $period)->first();
        if ($existing) {
            if (in_array($existing->status, ['ready', 'compared'], true) && $existing->forecasted_at
                && ($existing->status === 'ready' || ! $existing->hasCurrentEvidence())) {
                if (NhlEvaluationGameForecast::query()->where('evaluation_id', $evaluation->id)
                    ->whereIn('status', NhlEvaluationGameForecast::ACTIVE)->exists()) {
                    throw new RuntimeException('Finish or resume the current game first.');
                }
                if (! $existing->hasCurrentEvidence()) {
                    $this->prepareContext($existing);
                    $existing->status = 'queued';
                } else {
                    $this->prepareComparison($existing);
                }
                $existing->fill(['work_token' => (string) Str::uuid(), 'last_error' => null])->save();
                ForecastNhlEvaluationPlayerJob::dispatch($existing->id, $existing->work_token);
            }

            return $existing;
        }
        if (NhlEvaluationGameForecast::query()->where('evaluation_id', $evaluation->id)
            ->whereIn('status', NhlEvaluationGameForecast::ACTIVE)->exists()) {
            throw new RuntimeException('Finish or resume the current game first.');
        }
        $lineup = $this->lineup($evaluation, $game);
        $work = NhlEvaluationGameForecast::create(['evaluation_id' => $evaluation->id,
            'nhl_game_id' => $game->nhl_game_id, 'strength' => $strength, 'period' => $period,
            'status' => 'queued', 'phase' => 'forecast', 'work_token' => (string) Str::uuid(),
            'lineup' => $lineup, 'total' => count($lineup), 'source_cutoff_at' => $game->start_time_utc]);
        ForecastNhlEvaluationPlayerJob::dispatch($work->id, $work->work_token);

        return $work;
    }

    /** Freeze bounded historical lineup identities and already-built pregame evidence. */
    public function lineup(NhlNextGameEvaluation $evaluation, NhlGame $game): array
    {
        if ($evaluation->version !== NhlNextGameEvaluation::OUTLOOK_VERSION || $evaluation->status !== 'ready') {
            throw new RuntimeException('Wait until this evaluation is Ready.');
        }
        if ((string) $game->season_id !== (string) $evaluation->season_id || (int) $game->game_type !== 2
            || ! in_array($game->game_state, ['OFF', 'FINAL'], true) || ! $game->start_time_utc) {
            throw new RuntimeException('This first pass requires a completed regular-season game in the evaluation season.');
        }
        $players = DB::table('nhl_game_summaries as s')->join('players as p', 'p.nhl_id', '=', 's.nhl_player_id')
            ->where('s.nhl_game_id', $game->nhl_game_id)->where('s.toi', '>', 0)
            ->whereIn('s.nhl_team_id', [$game->away_team_id, $game->home_team_id])
            ->orderBy('s.nhl_player_id')->limit(65)->get(['s.nhl_player_id', 's.nhl_team_id', 'p.full_name', 'p.position', 'p.is_goalie']);
        if ($players->isEmpty() || $players->count() > 64 || $players->pluck('nhl_player_id')->unique()->count() !== $players->count()) {
            throw new RuntimeException('A unique lineup of at most 64 players is required.');
        }
        $contexts = DB::table('nhl_player_game_pregame_contexts')->where('nhl_game_id', $game->nhl_game_id)
            ->where('context_version', NhlPregameContextBuilder::VERSION)
            ->whereIn('nhl_player_id', $players->pluck('nhl_player_id'))->limit(65)->get()
            ->keyBy(fn ($row) => $row->nhl_player_id . ':' . $row->nhl_team_id);

        return $players->map(function ($player) use ($contexts, $game): array {
            $context = $contexts->get($player->nhl_player_id . ':' . $player->nhl_team_id);
            $metrics = $context ? json_decode($context->metrics, true, 512, JSON_THROW_ON_ERROR) : [];
            if (! $context || (int) ($metrics['individual_strength_version'] ?? 0) !== 1
                || ! $game->start_time_utc->equalTo($context->source_cutoff_at)) {
                throw new RuntimeException('Build current strength-separated pregame context for every player in this game first.');
            }

            return ['player_id' => (int) $player->nhl_player_id, 'team_id' => (int) $player->nhl_team_id,
                'name' => $player->full_name, 'context_id' => $context->id,
                'position' => $player->is_goalie || $player->position === 'G' ? 'G' : $player->position,
                'context_updated_at' => $context->updated_at, 'metrics' => $metrics,
                'venue' => $context->venue, 'participant_source' => $context->participant_source];
        })->all();
    }

    /** Commit exactly one player's forecast or comparison inside the caller's locked transaction. */
    public function advance(NhlEvaluationGameForecast $work): void
    {
        $player = $work->lineup[$work->cursor] ?? null;
        if ($player === null) {
            if ($work->phase === 'forecast') {
                $work->update(['forecasted_at' => now()]);
                $this->prepareComparison($work);

                return;
            }
            if ($work->phase === 'context' && $work->forecasted_at) {
                $this->prepareComparison($work);

                return;
            }
            $lineup = $work->lineup;
            if ($work->phase === 'compare') {
                $lineup[0]['evidence_version'] = self::CONTEXT_VERSION;
            }
            $work->update(['lineup' => $lineup, ...($work->phase === 'context'
                ? ['status' => $work->lineup[0]['context_return_status'] ?? 'ready']
                : ['status' => 'compared', 'compared_at' => now()])]);

            return;
        }
        if ($work->phase === 'context') {
            $this->saveContext($work, $player);
        } elseif ($work->phase === 'compare') {
            if (($player['position'] ?? null) === 'G') {
                $work->update(['cursor' => $work->cursor + 1]);

                return;
            }
            $this->saveContext($work, $player);
            $this->compare($work, $player);
        } else {
            if (($player['position'] ?? null) !== 'G') {
                $this->forecast($work, $player);
            }
            $this->saveContext($work, $player);
        }
        $work->update(['cursor' => $work->cursor + 1]);
    }

    /** Persist one bounded Train-only bucket map for every column and checkpoint in this game. */
    private function bucketFilter(NhlEvaluationGameForecast $work): array
    {
        $filter = $work->lineup[0]['bucket_filter'] ?? null;
        if (($filter['version'] ?? null) === NhlPregameContextBuilder::EVALUATION_BUCKET_VERSION) {
            return $filter;
        }
        $evaluation = NhlNextGameEvaluation::findOrFail($work->evaluation_id);
        $filter = app(NhlPregameContextBuilder::class)->evaluationBucketFilter(
            $evaluation->model_run_id, $evaluation->inputs['training_seasons'] ?? []
        );
        $lineup = $work->lineup;
        $lineup[0]['bucket_filter'] = $filter;
        $work->update(['lineup' => $lineup]);

        return $filter;
    }

    /** Start actuals only after every prediction is saved; keep the same bounded player chain. */
    private function prepareComparison(NhlEvaluationGameForecast $work): void
    {
        if (! $work->forecasted_at || ! NhlGame::query()->whereKey($work->nhl_game_id)
            ->whereIn('game_state', ['OFF', 'FINAL'])->exists()) {
            throw new RuntimeException('Saved predictions and a final game are required before comparing actuals.');
        }
        $work->update(['phase' => 'compare', 'status' => 'comparing', 'cursor' => 0, 'last_error' => null]);
    }

    /** Enrich older saved games without replacing their lineup skaters or forecasts. */
    public function prepareContext(NhlEvaluationGameForecast $work): void
    {
        $evaluation = NhlNextGameEvaluation::findOrFail($work->evaluation_id);
        $fresh = $this->lineup($evaluation, NhlGame::findOrFail($work->nhl_game_id));
        $saved = collect($work->lineup)->keyBy('player_id');
        if (collect($fresh)->contains(fn ($p) => $p['position'] !== 'G' && ! $saved->has($p['player_id']))) {
            throw new RuntimeException('The skater lineup changed; context cannot replace the saved forecast lineup.');
        }
        $lineup = collect($fresh)->map(function ($player) use ($saved) {
            $old = $saved->get($player['player_id']);
            if ($old && (int) $old['team_id'] !== (int) $player['team_id']) {
                throw new RuntimeException('A saved player changed teams in the source lineup. Existing forecasts have not been changed.');
            }

            return $old ? [...$old, 'position' => $old['position'] ?? $player['position']] : $player;
        })->all();
        if ($saved->keys()->diff(collect($lineup)->pluck('player_id'))->isNotEmpty()) {
            throw new RuntimeException('The saved lineup no longer matches the game. Existing forecasts have not been changed.');
        }
        $lineup[0]['context_return_status'] = $work->status;
        $work->fill(['lineup' => $lineup, 'phase' => 'context', 'cursor' => 0, 'total' => count($lineup)])->save();
    }

    /** Add display evidence only; never replace a committed forecast or its original inputs. */
    private function saveContext(NhlEvaluationGameForecast $work, array $player): void
    {
        $row = DB::table('nhl_evaluation_game_player_forecasts')->where('forecast_id', $work->id)
            ->where('nhl_player_id', $player['player_id'])->first();
        $inputs = $row ? json_decode($row->inputs, true, 512, JSON_THROW_ON_ERROR) : [];
        if (($inputs['context']['version'] ?? null) === self::CONTEXT_VERSION) {
            return;
        }
        $evaluation = NhlNextGameEvaluation::findOrFail($work->evaluation_id);
        $training = array_map('strval', $evaluation->inputs['training_seasons'] ?? []);
        $game = NhlGame::findOrFail($work->nhl_game_id);
        $opponent = (int) $player['team_id'] === (int) $game->home_team_id ? $game->away_team_id : $game->home_team_id;
        $goalie = ($player['position'] ?? null) === 'G';
        if ($goalie) {
            $windows = $this->goalieWindows($work, $player, $evaluation, (int) $opponent, $training);
        } else {
            $filter = $this->bucketFilter($work);
            if (($inputs['bucket_filter_version'] ?? null) === NhlPregameContextBuilder::EVALUATION_BUCKET_VERSION) {
                $windows = $inputs['windows'];
            } else {
                $metrics = app(NhlPregameContextBuilder::class)->evaluationPlayerMetrics(
                    $player['player_id'], $player['team_id'], (int) $opponent, $player['venue'],
                    $work->source_cutoff_at, $evaluation->season_id, $training, $filter
                );
                $windows = $work->strength === 'all' ? $metrics['all'] : $metrics['strength'][strtoupper($work->strength)];
            }
            $windows['current_quarter'] = $windows[$work->period.'_to_date'];
        }
        $lineup = $work->lineup;
        if (! isset($lineup[0]['team_records'])) {
            $lineup[0]['team_records'] = $this->teamRecords($game, $work);
            $work->update(['lineup' => $lineup]);
        }
        $position = match (strtoupper((string) ($player['position'] ?? ''))) {
            'L', 'LW' => 'LW', 'R', 'RW' => 'RW', 'C' => 'C', 'D' => 'D', 'G' => 'G', default => 'Unknown',
        };
        $previous = ! $goalie && ! empty($inputs['previous_game_id'])
            ? $this->performance((int) $inputs['previous_game_id'], $player['player_id'], $work->strength, $filter) : null;
        $inputs['context'] = ['version' => self::CONTEXT_VERSION, 'position' => $position, 'role' => $goalie ? 'goalie' : 'skater',
            'previous' => $previous,
            'training_seasons' => $training, 'windows' => $windows,
            'team_records' => $lineup[0]['team_records'][(string) $player['team_id']] ?? null,
            'cutoff_at' => $work->source_cutoff_at->toISOString(), 'captured_at' => now()->toISOString()];
        if ($row) {
            DB::table('nhl_evaluation_game_player_forecasts')->where('id', $row->id)
                ->update(['inputs' => json_encode($inputs, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
        } else {
            DB::table('nhl_evaluation_game_player_forecasts')->insert([
                'forecast_id' => $work->id, 'nhl_player_id' => $player['player_id'], 'nhl_team_id' => $player['team_id'],
                'player_name' => $player['name'], 'inputs' => json_encode($inputs, JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /** Team records are historical results, never independent team performance forecasts. */
    private function teamRecords(NhlGame $game, NhlEvaluationGameForecast $work): array
    {
        $result = [];
        foreach ([$game->away_team_id, $game->home_team_id] as $team) {
            $opponent = (int) $team === (int) $game->home_team_id ? $game->away_team_id : $game->home_team_id;
            $base = DB::table('nhl_games')->where('game_type', 2)->whereIn('game_state', ['OFF', 'FINAL'])
                ->where('start_time_utc', '<', $work->source_cutoff_at)
                ->whereNotNull('home_team_score')->whereNotNull('away_team_score')
                ->where(fn ($q) => $q->where('home_team_id', $team)->orWhere('away_team_id', $team))
                ->orderByDesc('start_time_utc')->orderByDesc('nhl_game_id');
            $recent = (clone $base)->limit(20)->get();
            $groups = ['last_5' => $recent->take(5), 'last_10' => $recent->take(10), 'last_20' => $recent,
                'season' => (clone $base)->where('season_id', $game->season_id)->limit(100)->get(),
                'opponent_last_10' => (clone $base)->where(fn ($q) => $q->where('home_team_id', $opponent)->orWhere('away_team_id', $opponent))->limit(10)->get()];
            foreach ($groups as $key => $games) {
                $wins = $games->filter(fn ($g) => (int) $g->home_team_id === (int) $team
                    ? $g->home_team_score > $g->away_team_score : $g->away_team_score > $g->home_team_score)->count();
                $result[(string) $team][$key] = ['games' => $games->count(), 'wins' => $wins,
                    'losses' => $games->count() - $wins, 'win_pct' => $games->count() ? round(100 * $wins / $games->count(), 2) : null];
            }
        }

        return $result;
    }

    /** Pool goalie shot evidence at the selected shooting-team strength, using this model's goal probabilities. */
    private function goalieWindows(NhlEvaluationGameForecast $work, array $player, NhlNextGameEvaluation $evaluation, int $opponent, array $training): array
    {
        $history = DB::table('nhl_game_summaries as s')->join('nhl_games as g', 'g.nhl_game_id', '=', 's.nhl_game_id')
            ->where('s.nhl_player_id', $player['player_id'])->where('s.toi', '>', 0)
            ->where('g.game_type', 2)->whereIn('g.game_state', ['OFF', 'FINAL'])
            ->where('g.start_time_utc', '<', $work->source_cutoff_at)->orderByDesc('g.start_time_utc')->orderByDesc('g.nhl_game_id')
            ->limit(2001)->get(['s.nhl_game_id', 's.nhl_team_id', 'g.home_team_id', 'g.away_team_id', 'g.season_id']);
        if ($history->count() > 2000) {
            throw new RuntimeException('Goalie history exceeds the bounded evaluation limit.');
        }
        $ids = DB::table('nhl_sat_model_entity_profile_buckets')->where('model_run_id', $evaluation->model_run_id)
            ->where('profile_type', 'goalie_faced')->whereNotNull('sog_expected_goals_model_id')->distinct()
            ->limit(2)->pluck('sog_expected_goals_model_id');
        $model = $ids->count() === 1 ? NhlExpectedGoalsModel::find($ids->first()) : null;
        $sources = array_map('strval', data_get($model?->feature_config, 'training_season_ids', []));
        if (! $sources || array_diff($sources, $training)) {
            $model = null;
        }
        $facts = DB::table('nhl_shot_attempts_facts as f')->whereIn('f.nhl_game_id', $history->pluck('nhl_game_id'))
            ->where('f.goalie_player_id', $player['player_id'])->where('f.is_shot_attempt', true)
            ->where(fn ($q) => $q->whereNull('f.period_type')->orWhere('f.period_type', '<>', 'SO'))
            ->where(fn ($q) => $q->whereNull('f.is_empty_net')->orWhere('f.is_empty_net', false));
        $strength = "CASE WHEN UPPER(f.strength) IN ('EV','PP','PK') THEN UPPER(f.strength) ELSE UPPER(f.strength_bucket) END";
        $selected = $work->strength === 'all' ? 'true' : "{$strength} = '" . strtoupper($work->strength) . "'";
        $probability = 'NULL';
        if ($model) {
            $key = app(NhlSatModelEntityProfileBuilder::class)->modelBucketKeySql($model, 'f');
            $facts->leftJoin('nhl_expected_goals_model_buckets as b', fn ($join) => $join
                ->where('b.expected_goals_model_id', $model->id)->whereRaw("b.bucket_key = {$key}"))
                ->leftJoin('nhl_expected_goals_model_buckets as fallback', fn ($join) => $join
                    ->where('fallback.expected_goals_model_id', $model->id)->where('fallback.bucket_key', 'L99|baseline=league'));
            $probability = 'COALESCE(b.smoothed_goal_probability, fallback.smoothed_goal_probability)';
        }
        $counts = $facts->select('f.nhl_game_id')
            ->selectRaw("SUM(CASE WHEN f.is_shot_on_goal AND ({$selected}) THEN 1 ELSE 0 END) as sog, SUM(CASE WHEN f.is_goal AND ({$selected}) THEN 1 ELSE 0 END) as goals")
            ->selectRaw("SUM(CASE WHEN f.is_shot_on_goal AND ({$selected}) THEN {$probability} ELSE 0 END) as xga, SUM(CASE WHEN f.is_shot_on_goal AND ({$selected}) AND {$probability} IS NULL THEN 1 ELSE 0 END) as missing_xga")
            ->selectRaw("SUM(CASE WHEN {$strength} IN ('EV','PP','PK') THEN 0 ELSE 1 END) as unclassified")
            ->groupBy('f.nhl_game_id')->get()->keyBy('nhl_game_id');
        $groups = ['train' => $history->whereIn('season_id', $training),
            'last_season' => $history->where('season_id', (string) ((int) $evaluation->season_id - 10001)),
            'last_5' => $history->take(5), 'last_10' => $history->take(10), 'last_20' => $history->take(20),
            'opponent_last_10' => $history->filter(fn ($g) => ((int) $g->nhl_team_id === (int) $g->home_team_id
                ? (int) $g->away_team_id : (int) $g->home_team_id) === $opponent)->take(10)];
        $result = [];
        foreach ($groups as $key => $games) {
            $rows = $games->map(fn ($g) => $counts->get($g->nhl_game_id));
            $complete = $games->isNotEmpty() && $rows->filter()->count() === $games->count()
                && ($work->strength === 'all' || $rows->filter()->sum('unclassified') === 0);
            $sog = $rows->filter()->sum('sog');
            $goals = $rows->filter()->sum('goals');
            $xga = $rows->filter()->sum('xga');
            $result[$key] = ['games' => $games->count(), 'sog' => $complete ? $sog : null,
                'sv_pct' => $complete && $sog > 0 ? round(100 * ($sog - $goals) / $sog, 4) : null,
                'gsax_per_100_xga' => $complete && $model && $rows->filter()->sum('missing_xga') === 0 && $xga > 0
                    ? round(100 * ($xga - $goals) / $xga, 4) : null,
                'sog_model_id' => $model?->id];
        }

        return $result;
    }

    /** Freeze same-strength inputs; previous-game performance supplies direction, not a boost. */
    private function forecast(NhlEvaluationGameForecast $work, array $player): void
    {
        // A resumed legacy chain may already have this player's immutable prediction.
        if (DB::table('nhl_evaluation_game_player_forecasts')->where('forecast_id', $work->id)
            ->where('nhl_player_id', $player['player_id'])->whereNotNull('inputs->method')->exists()) {
            return;
        }
        $filter = $this->bucketFilter($work);
        $evaluation = NhlNextGameEvaluation::findOrFail($work->evaluation_id);
        $game = NhlGame::findOrFail($work->nhl_game_id);
        $opponent = (int) $player['team_id'] === (int) $game->home_team_id ? $game->away_team_id : $game->home_team_id;
        $metrics = app(NhlPregameContextBuilder::class)->evaluationPlayerMetrics(
            $player['player_id'], $player['team_id'], (int) $opponent, $player['venue'],
            $work->source_cutoff_at, $evaluation->season_id, $evaluation->inputs['training_seasons'], $filter
        );
        $outlook = DB::table('nhl_evaluation_player_outlooks')->where('evaluation_id', $work->evaluation_id)
            ->where('nhl_player_id', $player['player_id'])->where('strength', $work->strength)
            ->where('period', $work->period)->where('revision', 1)->first();
        $windows = $work->strength === 'all' ? $metrics['all'] : $metrics['strength'][strtoupper($work->strength)];
        $l20 = $windows['last_20'] ?? [];
        $useRecent = (int) ($l20['games'] ?? 0) > 0 && isset($l20['toi_seconds'])
            && ($work->strength === 'all' || (isset($l20['individual_missing_games']) && (int) $l20['individual_missing_games'] === 0));
        $toi = $useRecent ? (float) $l20['toi_seconds'] / (int) $l20['games'] : $outlook?->toi_per_game_seconds;
        $prior = DB::table('nhl_game_summaries as s')->join('nhl_games as g', 'g.nhl_game_id', '=', 's.nhl_game_id')
            ->where('s.nhl_player_id', $player['player_id'])->where('s.toi', '>', 0)
            ->where('g.game_type', 2)->whereIn('g.game_state', ['OFF', 'FINAL'])
            ->where('g.start_time_utc', '<', $work->source_cutoff_at)->orderByDesc('g.start_time_utc')
            ->orderByDesc('g.nhl_game_id')->value('g.nhl_game_id');
        $previous = $prior ? $this->performance((int) $prior, $player['player_id'], $work->strength, $filter) : null;
        $sat = $outlook?->sat_per_60;
        DB::table('nhl_evaluation_game_player_forecasts')->insert([
            'forecast_id' => $work->id, 'nhl_player_id' => $player['player_id'], 'nhl_team_id' => $player['team_id'],
            'player_name' => $player['name'], 'sat_per_60' => $sat, 'toi_per_game_seconds' => $toi,
            'sat_direction' => $this->direction($sat, $previous['sat_per_60'] ?? null),
            'inputs' => json_encode(['method' => 'quarter_direction_l20_toi_v1', 'outlook_id' => $outlook?->id,
                'bucket_filter_version' => NhlPregameContextBuilder::EVALUATION_BUCKET_VERSION,
                'period' => $work->period, 'strength' => $work->strength, 'context_id' => $player['context_id'],
                'context_updated_at' => $player['context_updated_at'], 'source_cutoff_at' => $work->source_cutoff_at->toISOString(),
                'lineup_source' => $player['participant_source'], 'venue' => $player['venue'],
                'windows' => $windows, 'previous_game_id' => $prior, 'previous' => $previous,
                'quarter_sat_per_60' => $sat, 'quarter_toi_seconds' => $outlook?->toi_per_game_seconds,
                'toi_source' => $useRecent ? 'last_20' : ($toi !== null ? 'model_projection' : 'unavailable')], JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Read one player's measured game rates; absent source evidence never becomes zero SAT. */
    private function performance(int $gameId, int $playerId, string $strength, array $bucketFilter): array
    {
        $summary = DB::table('nhl_game_summaries')->where('nhl_game_id', $gameId)->where('nhl_player_id', $playerId)->first();
        $exposure = DB::table('nhl_player_game_strength_summaries')->where('nhl_game_id', $gameId)
            ->where('nhl_player_id', $playerId)->get(['strength', 'toi']);
        $toi = $strength === 'all' ? $summary?->toi
            : ($exposure->isEmpty() ? null : ($exposure->firstWhere('strength', strtoupper($strength))?->toi ?? 0));
        $facts = DB::table('nhl_shot_attempts_facts')->where('nhl_game_id', $gameId)->where('is_shot_attempt', true)
            ->where(fn ($q) => $q->whereNull('period_type')->orWhere('period_type', '<>', 'SO'));
        $available = (clone $facts)->exists();
        $counts = app(NhlPregameContextBuilder::class)->individualAttemptCounts($playerId, [$gameId], $bucketFilter)
            ->get($gameId, collect());
        if ($strength !== 'all' && $counts->contains(fn ($row) => ! in_array($row->strength, ['EV', 'PP', 'PK'], true))) {
            $available = false;
        }
        $sat = $strength === 'all' ? $counts->sum('sat') : ($counts->firstWhere('strength', strtoupper($strength))?->sat ?? 0);

        return ['sat_per_60' => $available && $toi > 0 ? round($sat * 3600 / $toi, 4) : null,
            'toi_seconds' => $toi === null ? null : (float) $toi];
    }

    /** Refresh measured evidence only; original prediction values and inputs stay immutable. */
    private function compare(NhlEvaluationGameForecast $work, array $player): void
    {
        $forecast = DB::table('nhl_evaluation_game_player_forecasts')->where('forecast_id', $work->id)
            ->where('nhl_player_id', $player['player_id'])->first();
        if (! $work->forecasted_at || ! $forecast) {
            throw new RuntimeException('Save all forecasts before comparing actual results.');
        }
        $inputs = json_decode($forecast->inputs, true, 512, JSON_THROW_ON_ERROR);
        $saved = $forecast->comparison ? json_decode($forecast->comparison, true, 512, JSON_THROW_ON_ERROR) : [];
        if (($saved['evidence_version'] ?? null) === self::CONTEXT_VERSION) {
            return;
        }
        $actual = $this->performance($work->nhl_game_id, $player['player_id'], $work->strength, $this->bucketFilter($work));
        $previous = $inputs['context']['previous']['sat_per_60'] ?? null;
        $direction = $this->direction($actual['sat_per_60'], $previous);
        $predictedDirection = $this->direction($forecast->sat_per_60, $previous);
        $comparison = [...$actual, 'evidence_version' => self::CONTEXT_VERSION,
            'sat_error_pct' => $this->error($forecast->sat_per_60, $actual['sat_per_60']),
            'toi_error_pct' => $this->error($forecast->toi_per_game_seconds, $actual['toi_seconds']),
            'direction_correct' => $direction !== null && $predictedDirection !== null ? $direction === $predictedDirection : null];
        DB::table('nhl_evaluation_game_player_forecasts')->where('id', $forecast->id)
            ->update(['comparison' => json_encode($comparison, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
    }

    /** Higher/lower is relative to the player's previous same-strength game rate. */
    private function direction(mixed $value, mixed $previous): ?string
    {
        return $value === null || $previous === null ? null
            : ((float) $value > (float) $previous ? 'higher' : ((float) $value < (float) $previous ? 'lower' : 'unchanged'));
    }

    /** Signed change uses the original forecast as denominator; zero forecasts have no percentage. */
    private function error(mixed $forecast, mixed $actual): ?float
    {
        return $forecast === null || $actual === null || (float) $forecast === 0.0
            ? null : round(100 * ((float) $actual - (float) $forecast) / (float) $forecast, 4);
    }
}
