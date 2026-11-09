<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\NhlGamePredictionPreparing;
use App\Jobs\PrepareNhlGamePredictionJob;
use App\Models\NhlModelRun;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Shared default game calculation and compatibility view of the EV/PP engine. */
class NhlCustomGameForecast
{
    private int $nextPreparationDelay = 15;

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

    /** Read ready inputs or queue their shared dependency work without replaying history in HTTP. */
    public function predict(int $gameId, ?int $modelId = null, bool $storedBoxscore = false, array $rosterOverrides = []): array
    {
        $game = DB::table('nhl_games')->where('nhl_game_id', $gameId)->first();
        abort_if($game === null, 404);
        $model = $this->model($modelId, (string) $game->season_id);
        [$key, $fingerprint] = $this->preparationIdentity($game, $model, $storedBoxscore, $rosterOverrides);
        $cached = Cache::get($key);
        $storedInput = Cache::get($key.':input:'.$gameId);
        $hasError = is_array($cached) && isset($cached['errors'][$gameId]);
        if (! is_array($cached) || ($cached['fingerprint'] ?? null) !== $fingerprint
            || (! $hasError && (! is_array($storedInput) || ($storedInput['fingerprint'] ?? null) !== $fingerprint))) {
            $error = Cache::get($key.':error');
            if (is_array($error) && ($error['fingerprint'] ?? null) === $fingerprint) {
                throw new DomainException($error['message']);
            }
            if (is_array($cached) && ($cached['fingerprint'] ?? null) === $fingerprint && ! $hasError) {
                Cache::forget($key);
            }
            if (Cache::add($key.':queued', true, $this->preparationLifetime())) {
                try {
                    PrepareNhlGamePredictionJob::dispatch($gameId, (int) $model->id, $storedBoxscore, $rosterOverrides);
                } catch (\Throwable $error) {
                    Cache::forget($key.':queued');
                    throw $error;
                }
            }
            throw new NhlGamePredictionPreparing($gameId);
        }
        if ($hasError) {
            throw new DomainException($cached['errors'][$gameId]);
        }
        $input = $storedInput['input'] ?? null;
        if ($input === null) {
            throw new DomainException($cached['errors'][$gameId] ?? 'The scheduled game has no prepared inputs.');
        }

        return ['game' => $game, 'model' => $model, 'input' => $input,
            'metadata' => $cached['metadata'],
            'prediction' => app(NhlCustomGamePredictionEngine::class)->predict($input)];
    }

    /** Run one checkpoint; source changes discard pending derived state, never saved history. */
    public function advancePreparation(int $gameId, int $modelId, bool $storedBoxscore = false, array $rosterOverrides = []): bool
    {
        $this->nextPreparationDelay = 15;
        $game = DB::table('nhl_games')->where('nhl_game_id', $gameId)->first();
        if ($game === null) {
            throw new DomainException('The prediction game no longer exists.');
        }
        $model = $this->model($modelId, (string) $game->season_id);
        [$key, $fingerprint] = $this->preparationIdentity($game, $model, $storedBoxscore, $rosterOverrides);
        $lock = Cache::lock($key.':lock', 180);
        if (! $lock->get()) {
            return false;
        }
        try {
            $ready = Cache::get($key);
            if (is_array($ready) && ($ready['fingerprint'] ?? null) === $fingerprint) {
                Cache::forget($key.':queued');

                return true;
            }
            $work = Cache::get($key.':work');
            if (! is_array($work) || ($work['fingerprint'] ?? null) !== $fingerprint) {
                $work = ['fingerprint' => $fingerprint, 'phase' => 'context', 'lineups' => [], 'evidence' => [], 'errors' => []];
                $asOf = CarbonImmutable::now('UTC');
                $reader = app(NhlCustomGamePredictionInputs::class);
                foreach (DB::table('nhl_games')->where('game_date', $game->game_date)->get() as $target) {
                    try {
                        $finished = in_array($target->game_state, ['OFF', 'FINAL'], true);
                        $record = $reader->anticipatedParticipants($target, $storedBoxscore || $finished,
                            ! $finished && (int) $target->nhl_game_id === $gameId ? $rosterOverrides : []);
                        $work['lineups'][$target->nhl_game_id] = $record;
                        $work['evidence'][$target->nhl_game_id] = $this->contextEvidence($model, $target, $record, $asOf);
                    } catch (DomainException $error) {
                        $work['errors'][$target->nhl_game_id] = $error->getMessage();
                    }
                }
            }
            if (isset($work['context_run_id'])) {
                $dependency = DB::table('nhl_pregame_context_runs')->find($work['context_run_id']);
                if ($dependency !== null && in_array($dependency->status, ['failed', 'stopped'], true)) {
                    throw new DomainException('Pregame dependency '.$dependency->id.' '.$dependency->status.'. Resume or retry that context run before predicting.');
                }
                if ($dependency?->status === 'completed') {
                    foreach (DB::table('nhl_pregame_context_run_games')->where('run_id', $dependency->id)
                        ->whereIn('state', ['blocked', 'failed'])->get(['nhl_game_id', 'last_error']) as $blocked) {
                        $work['errors'][$blocked->nhl_game_id] = $blocked->last_error ?? 'Pregame source evidence is unavailable.';
                        unset($work['evidence'][$blocked->nhl_game_id], $work['lineups'][$blocked->nhl_game_id]);
                    }
                }
            }
            if ($work['phase'] === 'context') {
                $missing = [];
                foreach ($work['evidence'] as $id => $evidence) {
                    if (! $this->contextReady((int) $model->id, (int) $id, $evidence)) {
                        $missing[$id] = $evidence;
                    }
                }
                if ($missing !== []) {
                    $dependency = app(NhlPregameContextOrchestrator::class)->queuePredictionContexts($model, $missing, $work['lineups']);
                    if ($dependency !== null) {
                        $work['context_run_id'] = $dependency->id;
                    }
                } else {
                    $reader = app(NhlCustomGamePredictionInputs::class);
                    $reader->beginQueuedForecast((int) $model->id, (string) $game->game_date, $work['lineups']);
                    $work['reader'] = $reader;
                    $work['phase'] = 'inputs';
                    $this->nextPreparationDelay = 0;
                }
            } else {
                $step = $work['reader']->advanceQueuedForecast();
                $this->nextPreparationDelay = 0;
                $work['checkpoint'] = ['phase' => $step['phase'], 'cursor' => $step['cursor']];
                if ($step['complete']) {
                    [, $currentFingerprint] = $this->preparationIdentity($game, $model, $storedBoxscore, $rosterOverrides);
                    if ($currentFingerprint !== $fingerprint) {
                        Cache::forget($key.':work');

                        return false;
                    }
                    $step['errors'] = array_replace($step['errors'], $work['errors']);
                    foreach ($step['inputs'] as $id => $input) {
                        if (! Cache::put($key.':input:'.$id, ['fingerprint' => $fingerprint, 'input' => $input], $this->preparationLifetime())) {
                            throw new \RuntimeException('Unable to publish prepared game inputs.');
                        }
                    }
                    $manifest = ['fingerprint' => $fingerprint, 'metadata' => $step['metadata'], 'errors' => $step['errors']];
                    if (! Cache::put($key, $manifest, $this->preparationLifetime())) {
                        throw new \RuntimeException('Unable to publish prepared prediction inputs.');
                    }
                    Cache::forget($key.':work');
                    Cache::forget($key.':queued');
                    Cache::forget($key.':error');

                    return true;
                }
            }
            if (! Cache::put($key.':work', $work, $this->preparationLifetime())) {
                throw new \RuntimeException('Unable to save the prediction preparation checkpoint.');
            }
            Cache::put($key.':queued', true, $this->preparationLifetime());

            return false;
        } finally {
            $lock->release();
        }
    }

    /** Yield immediately after progress; poll pending context or another owner less frequently. */
    public function preparationDelay(): int
    {
        return $this->nextPreparationDelay;
    }

    /** Record a terminal preparation failure with a bounded lifetime and visible cause. */
    public function preparationFailed(int $gameId, int $modelId, bool $storedBoxscore, array $rosterOverrides, string $message): void
    {
        $game = DB::table('nhl_games')->where('nhl_game_id', $gameId)->first();
        if ($game === null) {
            return;
        }
        [$key, $fingerprint] = $this->preparationIdentity($game, $this->model($modelId, (string) $game->season_id), $storedBoxscore, $rosterOverrides);
        Cache::put($key.':error', ['fingerprint' => $fingerprint, 'message' => mb_substr($message, 0, 1500)], 300);
        Cache::forget($key.':queued');
    }

    /** Freeze only prior result evidence, model identity, schedule and the selected lineup. */
    public function contextEvidence(NhlModelRun $model, object $game, ?array $record = null, ?CarbonImmutable $asOf = null): array
    {
        $record ??= app(NhlCustomGamePredictionInputs::class)->anticipatedParticipants(
            $game, in_array($game->game_state, ['OFF', 'FINAL'], true)
        );
        $asOf ??= CarbonImmutable::now('UTC');
        $cutoff = $game->start_time_utc ? CarbonImmutable::parse($game->start_time_utc, 'UTC')->min($asOf) : $asOf;
        $results = $this->resultEvidence($model, $game, $cutoff);
        $identity = ['model' => [$model->id, $model->updated_at, $model->train_season_ids],
            'version' => NhlPregameContextBuilder::VERSION, 'results' => $results,
            'game' => [$game->nhl_game_id, $game->game_date, $game->start_time_utc, $game->away_team_id, $game->home_team_id],
            'participants' => $record];

        return ['fingerprint' => hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR)),
            'cutoff_at' => $cutoff->format('Y-m-d H:i:s'),
            'team_ids' => [(int) $game->away_team_id, (int) $game->home_team_id],
            'players' => $record['players'], 'starters' => $record['starters'] ?? [], 'participants' => $record];
    }

    /** A ready context includes both teams, each selected player and committed comparisons. */
    public function contextReady(int $modelId, int $gameId, array $evidence): bool
    {
        $teams = DB::table('nhl_team_game_pregame_contexts')->where('model_run_id', $modelId)
            ->where('nhl_game_id', $gameId)->where('context_version', NhlPregameContextBuilder::VERSION)
            ->whereIn('nhl_team_id', $evidence['team_ids'])->get(['id', 'run_id', 'nhl_team_id', 'schedule_metrics']);
        if ($teams->count() !== 2) {
            return false;
        }
        foreach ($teams as $team) {
            if (data_get(json_decode($team->schedule_metrics, true), 'prediction_evidence.fingerprint') !== $evidence['fingerprint']
                || ! DB::table('nhl_pregame_context_run_games')->where('run_id', $team->run_id)
                    ->where('nhl_game_id', $gameId)->where('state', 'complete')->whereNotNull('comparisons_completed_at')->exists()) {
                return false;
            }
        }
        $players = DB::table('nhl_player_game_pregame_contexts')->where('model_run_id', $modelId)
            ->where('nhl_game_id', $gameId)->where('context_version', NhlPregameContextBuilder::VERSION)
            ->whereIn('team_context_id', $teams->pluck('id'))->get(['nhl_player_id', 'nhl_team_id']);
        $keys = $players->mapWithKeys(fn (object $player): array => [$player->nhl_player_id.':'.$player->nhl_team_id => true]);
        foreach ($evidence['players'] as $id => $player) {
            if (! $keys->has($id.':'.$player['team'])) {
                return false;
            }
        }

        foreach ($evidence['starters'] ?? [] as $team => $id) {
            if ($id !== null && ! $keys->has($id.':'.$team)) {
                return false;
            }
        }
        return true;
    }

    /** Fingerprint actual result values; polling timestamps are not statistical evidence.
     * @return array<string,mixed>
     */
    public function resultEvidence(NhlModelRun $model, object $game, ?CarbonImmutable $cutoff = null): array
    {
        $games = DB::table('nhl_games')->where('game_type', 2)->whereIn('game_state', ['OFF', 'FINAL'])
            ->where('season_id', '>=', min($model->train_season_ids))->where('season_id', '<=', $game->season_id)
            ->whereDate('game_date', '<=', $game->game_date)
            ->when($cutoff !== null, fn ($query) => $query->where('start_time_utc', '<', $cutoff)
                ->where('nhl_game_id', '<>', $game->nhl_game_id))
            ->orderBy('nhl_game_id')->get([
                'nhl_game_id', 'season_id', 'game_type', 'game_date', 'start_time_utc', 'home_team_id', 'away_team_id',
                'home_team_score', 'away_team_score', 'home_team_sog', 'away_team_sog',
                'home_pp_goals', 'away_pp_goals', 'home_pp_opportunities', 'away_pp_opportunities',
            ]);
        $ids = $games->pluck('nhl_game_id')->all();
        $summaries = $this->summaryValueEvidence($ids);
        // A completed import publishes derived shot/strength facts beyond the summary columns.
        $imports = DB::table('nhl_import_progress')->whereIn('game_id', array_map('strval', $ids))
            ->where('status', 'completed')->selectRaw('COUNT(*) AS stages, MAX(updated_at) AS published_at')->first();

        $processed = DB::table('nhl_pbp_processing_states')->whereIn('nhl_game_id', $ids)
            ->where('processed_version', '>', 0)->orderBy('nhl_game_id')->get(['nhl_game_id', 'processed_version']);

        return ['version' => 'result_values_v1',
            'games' => hash('sha256', json_encode($games, JSON_THROW_ON_ERROR)),
            'summaries' => $summaries, 'imports' => $imports,
            'processed_pbp' => hash('sha256', json_encode($processed, JSON_THROW_ON_ERROR))];
    }

    /** Rehash only touched games, retaining a content digest rather than their touch timestamps.
     * @param array<int,int> $gameIds
     */
    private function summaryValueEvidence(array $gameIds): string
    {
        $query = DB::table('nhl_game_summaries')->whereIn('nhl_game_id', $gameIds)
            ->groupBy('nhl_game_id')->orderBy('nhl_game_id')
            ->selectRaw('nhl_game_id, COUNT(*) AS rows, MAX(updated_at) AS touched_at');
        if (DB::getDriverName() === 'pgsql') {
            // Transaction identity also detects corrections within the same timestamp precision.
            $query->selectRaw('SUM(CAST(CAST(xmin AS TEXT) AS BIGINT)) AS row_revision');
        }
        $metadata = $query->get();
        $keys = $metadata->map(fn (object $row): string => 'nhl:pregame:summary-values:v1:'.$row->nhl_game_id)->all();
        $cached = $keys === [] ? [] : Cache::many($keys);
        $missing = $tokens = $digests = [];
        foreach ($metadata as $row) {
            $id = (int) $row->nhl_game_id;
            $tokens[$id] = hash('sha256', json_encode($row, JSON_THROW_ON_ERROR));
            $entry = $cached['nhl:pregame:summary-values:v1:'.$id] ?? null;
            if (is_array($entry) && ($entry['token'] ?? null) === $tokens[$id] && is_string($entry['digest'] ?? null)) {
                $digests[$id] = $entry['digest'];
            } else {
                $missing[] = $id;
            }
        }
        foreach (array_chunk($missing, max(1, min(100, (int) config('nhl-custom-prediction.batch_games', 25)))) as $page) {
            $hashes = [];
            foreach ($page as $id) {
                $hashes[$id] = hash_init('sha256');
            }
            $rows = DB::table('nhl_game_summaries')->whereIn('nhl_game_id', $page)
                ->orderBy('nhl_game_id')->orderBy('nhl_player_id')->orderBy('id')->get();
            foreach ($rows as $row) {
                $values = (array) $row;
                unset($values['id'], $values['created_at'], $values['updated_at']);
                ksort($values);
                hash_update($hashes[(int) $row->nhl_game_id], json_encode($values, JSON_THROW_ON_ERROR)."\n");
            }
            $entries = [];
            foreach ($page as $id) {
                $digests[$id] = hash_final($hashes[$id]);
                $entries['nhl:pregame:summary-values:v1:'.$id] = ['token' => $tokens[$id], 'digest' => $digests[$id]];
            }
            Cache::putMany($entries, $this->preparationLifetime());
        }
        ksort($digests, SORT_NUMERIC);

        return hash('sha256', json_encode($digests, JSON_THROW_ON_ERROR));
    }

    /** Stable date keys prevent abandoned input variants from accumulating without expiry. */
    private function preparationIdentity(object $game, NhlModelRun $model, bool $storedBoxscore, array $rosterOverrides): array
    {
        $date = (string) $game->game_date;
        $results = $this->resultEvidence($model, $game);
        $schedule = DB::table('nhl_games')->where('game_date', $date)->orderBy('nhl_game_id')
            ->get(['nhl_game_id', 'start_time_utc', 'home_team_id', 'away_team_id', 'away_starter_lock', 'home_starter_lock']);
        $lineups = DB::table('nhl_current_lineups')->whereIn('nhl_game_id', $schedule->pluck('nhl_game_id'))
            ->orderBy('nhl_game_id')->orderBy('team_id')->get(['nhl_game_id', 'team_id', 'structure_hash']);
        $goalies = DB::table('nhl_starting_goalie_observations')->whereIn('nhl_game_id', $schedule->pluck('nhl_game_id'))
            ->orderByRaw("CASE status WHEN 'confirmed' THEN 0 WHEN 'expected' THEN 1 ELSE 2 END")
            ->orderByDesc('fetched_at')->orderByDesc('id')
            ->get(['nhl_game_id', 'team_abbrev', 'nhl_player_id', 'provider', 'status'])
            ->unique(fn (object $row): string => implode('|', [(string) $row->nhl_game_id, $row->team_abbrev,
                (string) $row->nhl_player_id, $row->provider, $row->status]))->values();
        $identity = ['model' => [$model->id, $model->updated_at, $model->train_season_ids], 'definition' => config('nhl-custom-prediction'),
            'date' => $date, 'schedule' => $schedule, 'results' => $results,
            'lineups' => $lineups, 'goalies' => $goalies, 'stored_boxscore' => $storedBoxscore,
            'roster_overrides' => $rosterOverrides, 'override_game' => $rosterOverrides === [] ? null : $game->nhl_game_id];
        $key = 'nhl:custom-inputs:queued-v3:'.$model->id.':'.$date.':'.(int) $storedBoxscore.':'
            .hash('sha256', json_encode([$rosterOverrides, $rosterOverrides === [] ? null : $game->nhl_game_id], JSON_THROW_ON_ERROR));

        return [$key, hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR))];
    }

    /** Keep pending checkpoint state alive while each successful bounded page advances it. */
    private function preparationLifetime(): int
    {
        return max(300, (int) config('nhl-custom-prediction.preparation_seconds', 21600));
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
