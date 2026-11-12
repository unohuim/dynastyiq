<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NhlModelRun;
use Carbon\CarbonImmutable;
use Closure;
use DomainException;
use Generator;
use Illuminate\Support\Facades\DB;

/** Bounded historical result reader and chronological, model-owned custom-engine input builder. */
class NhlCustomGamePredictionInputs
{
    private array $filter = [];

    private array $metadata = [];

    private array $train = [];

    private array $seasons = [];

    private array $recent = [];

    private array $league = [];

    private array $teamTrain = [];

    /** Retained EV Train counts for every team's skaters, independent of the selected lineup. */
    private array $teamTrainBuckets = [];

    private array $teamSeason = [];

    private array $teamRecent = [];

    private array $trainOpponents = [];

    private array $pastAllowances = [];

    private array $allowanceSources = [];

    private string $bucketExpression = '';

    private array $forecastWork = [];

    private ?array $forecastPlayerIds = null;

    private bool $useSavedPlayerAggregates = false;

    /** Source cutoffs keep future fallback bucket counts aligned with saved totals. */
    private array $forecastPlayerCutoffs = [];

    /** Players lacking a same-season snapshot retain the existing chronological fallback ladder. */
    private array $forecastRawPlayers = [];

    /** Batched latest committed source rows for the requested future slate. */
    private array $futureSnapshots = [];

    /** Export existing accumulators as partitionable data, never one serialized reader object.
     * @return array<string,mixed>
     */
    public function checkpointState(): array
    {
        $state = array_intersect_key(get_object_vars($this), get_class_vars(self::class));
        if (($state['forecastWork']['defense'] ?? null) instanceof NhlCustomGameDefense) {
            $state['forecastWork']['defense'] = $state['forecastWork']['defense']->checkpointState();
        }

        return $state;
    }

    /** Restore accumulated counts/cursors without replaying completed history.
     * @param array<string,mixed> $state
     */
    public function restoreCheckpointState(array $state): void
    {
        if (is_array($state['forecastWork']['defense'] ?? null)) {
            $defense = new NhlCustomGameDefense();
            $defense->restoreCheckpointState($state['forecastWork']['defense']);
            $state['forecastWork']['defense'] = $defense;
        }
        if (($state['forecastWork'] ?? []) !== []) {
            $state['forecastWork']['contexts'] ??= [];
        }
        foreach (array_intersect_key($state, get_class_vars(self::class)) as $field => $value) {
            $this->{$field} = $value;
        }
    }

    /** Resolve one model universe and freeze its Train bucket mapping before reading Test. */
    public function prepare(int $modelId, ?string $predictionSeason = null): array
    {
        $this->forecastPlayerCutoffs = $this->forecastRawPlayers = [];
        $model = NhlModelRun::query()->findOrFail($modelId);
        $training = array_map('strval', (array) $model->train_season_ids);
        $season = $predictionSeason ?? $model->target_season_id;
        if ($predictionSeason !== null && $model->status !== NhlModelRun::STATUS_COMPLETE) {
            throw new DomainException('Requested-date prediction requires a complete SAT model.');
        }
        if ($model->model_family !== 'sat' || ! $season || $training === []) {
            throw new DomainException('A SAT model with explicit Train and Test seasons is required.');
        }
        sort($training);
        $reference = config('nhl-custom-prediction.reference_train_seasons');
        if (! is_array($reference)) {
            throw new DomainException('Load the new custom prediction configuration before preparing inputs.');
        }
        sort($reference);
        if (($predictionSeason === null && $training !== $reference) || max($training) >= (string) $season) {
            throw new DomainException('The frozen v1 coefficients require the documented 2022-23 through 2024-25 Train seasons.');
        }
        $run = DB::table('nhl_pregame_context_runs')->where('model_run_id', $modelId)
            ->where('status', 'completed')->orderByDesc('id')->first();
        if (! $run && $predictionSeason === null) {
            throw new DomainException('Build the model-owned pregame context before running custom predictions.');
        }
        if ($predictionSeason === null && DB::table('nhl_games')->where('season_id', $season)->where('game_type', 2)
            ->whereIn('game_state', ['OFF', 'FINAL'])->whereNull('start_time_utc')->exists()) {
            throw new DomainException('Repair Test games without a scheduled start cutoff before replaying.');
        }
        $this->filter = app(NhlPregameContextBuilder::class)->evaluationBucketFilter($modelId, $training);
        if ($this->filter['keys'] === []) {
            throw new DomainException('The model has no retained Train-confidence >= 0.50 buckets.');
        }
        $bucketModel = new \App\Models\NhlExpectedGoalsModel(['feature_config' => $this->filter['feature_config']]);
        $this->bucketExpression = app(NhlSatModelEntityProfileBuilder::class)->modelBucketKeySql($bucketModel, 'f');
        $this->metadata = ['model_id' => $modelId, 'model_name' => $model->name,
            'train_seasons' => $training, 'test_season' => (string) $model->target_season_id,
            'prediction_season' => $predictionSeason,
            'coefficient_reference_train_seasons' => $reference,
            'coefficients_transferred' => $training !== $reference,
            'context_version' => NhlPregameContextBuilder::VERSION, 'context_run_id' => $run?->id,
            'bucket_filter' => $this->filter];

        return $this->metadata;
    }

    /** Predict any scheduled state from prior results and supplied or anticipated lineup identities. */
    public function forecasts(int $modelId, string $date, array $lineups = [], ?Closure $progress = null): Generator
    {
        $this->useSavedPlayerAggregates = false;
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
            || ! CarbonImmutable::hasFormatWithModifiers($date, 'Y-m-d')
            || CarbonImmutable::createFromFormat('!Y-m-d', $date)->format('Y-m-d') !== $date) {
            throw new DomainException('A real prediction date in YYYY-MM-DD format is required.');
        }
        $targets = DB::table('nhl_games')->where('game_date', $date)->orderBy('start_time_utc')->orderBy('nhl_game_id')->get();
        if ($targets->isEmpty()) {
            return;
        }
        if ($targets->pluck('season_id')->unique()->count() !== 1) {
            throw new DomainException('Prediction games require one season per requested date.');
        }
        $this->prepare($modelId, (string) $targets->first()->season_id);
        $this->train = $this->seasons = $this->recent = $this->league = [];
        $this->teamTrainBuckets = $this->teamTrain = $this->teamSeason = $this->teamRecent = [];
        $this->trainOpponents = $this->pastAllowances = $this->allowanceSources = [];
        $asOf = CarbonImmutable::now('UTC');
        $cutoff = fn (object $g): CarbonImmutable => $g->start_time_utc
            ? CarbonImmutable::parse($g->start_time_utc, 'UTC')->min($asOf) : $asOf;
        foreach ($targets as $target) {
            $target->prediction_cutoff_at = $cutoff($target)->format('Y-m-d H:i:s');
        }
        $latestCutoff = $targets->map($cutoff)->sort()->last();
        $history = DB::table('nhl_games')->where('game_type', 2)->whereIn('game_state', ['OFF', 'FINAL'])
            ->where('season_id', '>=', min($this->metadata['train_seasons']))
            ->where('season_id', '<=', $this->metadata['prediction_season'])
            ->whereNotIn('nhl_game_id', $targets->pluck('nhl_game_id'))
            ->where('start_time_utc', '<', $latestCutoff)->orderBy('start_time_utc')->orderBy('nhl_game_id')
            ->get(['nhl_game_id', 'season_id', 'game_date', 'start_time_utc', 'home_team_id', 'away_team_id',
                'home_team_abbrev', 'away_team_abbrev', 'home_pp_goals', 'away_pp_goals',
                'home_pp_opportunities', 'away_pp_opportunities'])->all();
        $defense = new NhlCustomGameDefense();
        $training = array_values(array_filter($history, fn (object $g): bool => in_array((string) $g->season_id, $this->metadata['train_seasons'], true)));
        $progress?->__invoke('Fitting frozen Train defensive buckets.');
        $defense->fit($this->trainingDefenseRecords($training));
        $this->metadata['defense_fit'] = $defense->snapshot();
        $this->metadata['selected_games'] = $targets->count();
        $this->metadata['as_of'] = $asOf->toIso8601String();
        $events = [...$history, ...$targets->all()];
        usort($events, fn (object $a, object $b): int => [$a->prediction_cutoff_at ?? $a->start_time_utc, $a->nhl_game_id]
            <=> [$b->prediction_cutoff_at ?? $b->start_time_utc, $b->nhl_game_id]);
        $targetIds = array_fill_keys($targets->pluck('nhl_game_id')->all(), true);
        $processed = 0;
        foreach (array_chunk($events, (int) config('nhl-custom-prediction.batch_games')) as $batch) {
            $prior = array_values(array_filter($batch, fn (object $g): bool => ! isset($targetIds[$g->nhl_game_id])));
            $records = $prior === [] ? [] : $this->records($prior);
            foreach ($batch as $game) {
                if (! isset($targetIds[$game->nhl_game_id])) {
                    $this->pastAllowances[$game->nhl_game_id] = $this->allowances($game, $defense);
                    $this->advance($game, $records[$game->nhl_game_id], in_array((string) $game->season_id, $this->metadata['train_seasons'], true));
                    if (++$processed % 500 === 0) {
                        $progress?->__invoke($processed.' prior games processed.');
                    }
                    continue;
                }
                try {
                    $record = $lineups[$game->nhl_game_id] ?? $this->anticipatedParticipants($game);
                    $contexts = $this->predictionContexts($game, $record, $cutoff($game));
                    $allowances = $this->allowances($game, $defense);
                    $input = $this->input($game, $record, $contexts, $allowances, $defense, true);
                    $input['history_available_at'] = $cutoff($game)->toIso8601String();
                    yield ['game' => $game, 'input' => $input, 'error' => null];
                } catch (DomainException $exception) {
                    yield ['game' => $game, 'input' => null, 'error' => $exception->getMessage()];
                }
            }
        }
    }

    /** Freeze an operational date once; each continuation performs only a bounded page. */
    public function beginQueuedForecast(int $modelId, string $date, array $lineups): void
    {
        $this->useSavedPlayerAggregates = true;
        $this->forecastPlayerCutoffs = $this->forecastRawPlayers = [];
        $future = $date > now('America/Toronto')->toDateString();
        $asOf = CarbonImmutable::now('UTC');
        $targets = DB::table('nhl_games')->where('game_date', $date)->orderBy('start_time_utc')->orderBy('nhl_game_id')->get();
        if ($targets->isEmpty() || $targets->pluck('season_id')->unique()->count() !== 1) {
            throw new DomainException('A prediction date must identify games in one season.');
        }
        $this->prepare($modelId, (string) $targets->first()->season_id);
        $this->train = $this->seasons = $this->recent = $this->league = [];
        $this->teamTrainBuckets = $this->teamTrain = $this->teamSeason = $this->teamRecent = [];
        $this->trainOpponents = $this->pastAllowances = $this->allowanceSources = [];
        $this->forecastPlayerIds = [];
        $contexts = [];
        $errors = [];
        if ($future) {
            $this->loadFutureSnapshots($modelId, $targets->all(), $lineups, $asOf);
        }
        foreach ($targets as $target) {
            $record = $lineups[$target->nhl_game_id] ?? ['players' => []];
            if (($record['players'] ?? []) === []) {
                $errors[$target->nhl_game_id] = 'No skater lineup is available.';
                continue;
            }
            $target->future_context_fallback = $future;
            $contexts[$target->nhl_game_id] = $future
                ? $this->futurePredictionContexts($target, $record, $asOf)
                : $this->storedPredictionContexts($target, $record);
            $parent = $contexts[$target->nhl_game_id]['teams'][$target->nhl_game_id][$target->away_team_id];
            $target->prediction_cutoff_at = $future ? $asOf->format('Y-m-d H:i:s') : (string) $parent->source_cutoff_at;
            foreach (array_keys($record['players']) as $id) {
                $this->forecastPlayerIds[$id] = true;
            }
        }
        $history = DB::table('nhl_games')->where('game_type', 2)->whereIn('game_state', ['OFF', 'FINAL'])
            ->where('season_id', '>=', min($this->metadata['train_seasons']))
            ->where('season_id', '<=', (string) $targets->first()->season_id)
            ->where('game_date', '<', $date)->whereNotNull('start_time_utc')
            ->when($future, fn ($query) => $query->where('start_time_utc', '<', $asOf))
            ->orderBy('start_time_utc')->orderBy('nhl_game_id')->get()->all();
        $defense = new NhlCustomGameDefense();
        $defense->beginFit();
        $this->forecastWork = ['phase' => 'collect', 'cursor' => 0, 'defense' => $defense,
            'history' => $history, 'training' => array_values(array_filter($history,
                fn (object $game): bool => in_array((string) $game->season_id, $this->metadata['train_seasons'], true))),
            'targets' => $targets->all(), 'lineups' => $lineups, 'contexts' => $contexts,
            'inputs' => [], 'errors' => $errors];
        $runIds = [];
        foreach ($contexts as $context) {
            foreach ($context['teams'] as $teams) {
                foreach ($teams as $team) {
                    if ($team->run_id !== null) {
                        $runIds[(int) $team->run_id] = true;
                    }
                }
            }
        }
        $this->metadata['context_run_ids'] = array_keys($runIds);
        $this->metadata['context_run_id'] = count($runIds) === 1 ? array_key_first($runIds) : null;
        $this->metadata['selected_games'] = $targets->count();
        $this->metadata['as_of'] = $asOf->toIso8601String();
        $this->metadata['context_mode'] = $future ? 'latest_available' : 'exact_game';
    }

    /** Advance Train collection, fitting, history or one target without a season-sized job. */
    public function advanceQueuedForecast(): array
    {
        if ($this->forecastWork === []) {
            throw new DomainException('Initialize queued prediction inputs before advancing them.');
        }
        $work = &$this->forecastWork;
        $defense = $work['defense'];
        $size = max(1, (int) config('nhl-custom-prediction.batch_games', 25));
        if ($work['phase'] === 'collect') {
            $page = array_slice($work['training'], $work['cursor'], $size);
            $defense->collectFit($this->trainingDefenseRecords($page));
            $work['cursor'] += count($page);
            if ($work['cursor'] >= count($work['training'])) {
                $work['phase'] = 'fit';
                $work['cursor'] = 0;
                unset($work['training']);
            }
        } elseif ($work['phase'] === 'fit') {
            if ($defense->finishFitPage(max(1, (int) config('nhl-custom-prediction.fit_bucket_batch', 8)))) {
                $this->metadata['defense_fit'] = $defense->snapshot();
                $work['phase'] = 'history';
            }
        } elseif ($work['phase'] === 'history') {
            $page = array_slice($work['history'], $work['cursor'], $size);
            if ($page !== []) {
                $records = $this->records($page);
                foreach ($page as $game) {
                    $this->pastAllowances[$game->nhl_game_id] = $this->allowances($game, $defense);
                    $this->advance($game, $records[$game->nhl_game_id], in_array((string) $game->season_id, $this->metadata['train_seasons'], true));
                }
                $this->pruneForecastReferences();
            }
            $work['cursor'] += count($page);
            if ($work['cursor'] >= count($work['history'])) {
                $work['phase'] = 'targets';
                $work['cursor'] = 0;
                unset($work['history']);
            }
        } elseif ($work['phase'] === 'targets') {
            $target = $work['targets'][$work['cursor']] ?? null;
            if ($target !== null && ! isset($work['errors'][$target->nhl_game_id])) {
                try {
                    // Later games may use earlier completed games on this date; a target never uses itself.
                    $reader = clone $this;
                    $earlier = DB::table('nhl_games')->whereDate('game_date', $target->game_date)
                        ->where('game_type', 2)->whereIn('game_state', ['OFF', 'FINAL'])
                        ->where('nhl_game_id', '<>', $target->nhl_game_id)
                        ->where('start_time_utc', '<', $target->prediction_cutoff_at)
                        ->orderBy('start_time_utc')->orderBy('nhl_game_id')->get()->all();
                    if ($earlier !== []) {
                        $records = $reader->records($earlier);
                        foreach ($earlier as $game) {
                            $reader->pastAllowances[$game->nhl_game_id] = $reader->allowances($game, $defense);
                            $reader->advance($game, $records[$game->nhl_game_id], false);
                        }
                    }
                    $allowances = $reader->allowances($target, $defense);
                    $work['inputs'][$target->nhl_game_id] = $reader->input($target, $work['lineups'][$target->nhl_game_id],
                        $work['contexts'][$target->nhl_game_id], $allowances, $defense, true);
                } catch (DomainException $error) {
                    $work['errors'][$target->nhl_game_id] = $error->getMessage();
                }
            }
            $work['cursor']++;
            if ($work['cursor'] >= count($work['targets'])) {
                $work['phase'] = 'complete';
            }
        }

        return ['complete' => $work['phase'] === 'complete', 'phase' => $work['phase'], 'cursor' => $work['cursor'],
            'inputs' => $work['inputs'], 'errors' => $work['errors'], 'metadata' => $this->metadata];
    }

    /** Read saved model-owned context and same-game player links; never calculate it in the request. */
    private function storedPredictionContexts(object $game, array $record): array
    {
        $teams = DB::table('nhl_team_game_pregame_contexts')->where('model_run_id', $this->metadata['model_id'])
            ->where('nhl_game_id', $game->nhl_game_id)->where('context_version', NhlPregameContextBuilder::VERSION)
            ->get()->keyBy('nhl_team_id')->all();
        $players = DB::table('nhl_player_game_pregame_contexts')->where('model_run_id', $this->metadata['model_id'])
            ->where('nhl_game_id', $game->nhl_game_id)->where('context_version', NhlPregameContextBuilder::VERSION)
            ->whereIn('nhl_player_id', array_keys($record['players']))->get()->keyBy('nhl_player_id')->all();
        if (count($teams) !== 2 || count($players) !== count($record['players'])) {
            throw new DomainException('Saved pregame context is incomplete for the selected lineup.');
        }
        foreach ($teams as $team) {
            $team->evidence_source = 'saved_model_snapshot';
        }

        return ['teams' => [$game->nhl_game_id => $teams], 'players' => [$game->nhl_game_id => $players]];
    }

    /** Limit reusable rows to committed, same-model evidence already available today.
     * @return \Illuminate\Database\Query\Builder
     */
    private static function futureContextQuery(string $table, int $modelId, CarbonImmutable $asOf): \Illuminate\Database\Query\Builder
    {
        return DB::table($table)->where('model_run_id', $modelId)
            ->where('context_version', NhlPregameContextBuilder::VERSION)
            ->where('game_date', '<=', $asOf->setTimezone('America/Toronto')->toDateString())
            ->where('source_cutoff_at', '<=', $asOf)
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('nhl_pregame_context_run_games as work')
                ->whereColumn('work.run_id', $table.'.run_id')
                ->whereColumn('work.nhl_game_id', $table.'.nhl_game_id')
                ->where('work.state', 'complete')->whereNotNull('work.comparisons_completed_at'));
    }

    /** Invalidate future inputs from bounded commit markers rather than scanning every context metric row. */
    public static function futureContextIdentity(int $modelId): array
    {
        $commit = DB::table('nhl_pregame_context_run_games as work')
            ->join('nhl_pregame_context_runs as runs', 'runs.id', '=', 'work.run_id')
            ->where('runs.model_run_id', $modelId)->where('work.state', 'complete')
            ->whereNotNull('work.comparisons_completed_at')
            ->whereDate('work.game_date', '<=', now('America/Toronto')->toDateString())
            ->selectRaw('COUNT(*) AS games, MAX(work.id) AS latest_id, MAX(work.comparisons_completed_at) AS committed_at')->first();

        return ['calculation_date' => now('America/Toronto')->toDateString(), 'commit' => $commit];
    }

    /** Select at most one row per slate entity in two batched queries, preserving original source identities. */
    private function loadFutureSnapshots(int $modelId, array $targets, array $lineups, CarbonImmutable $asOf): void
    {
        $teamIds = $playerIds = [];
        foreach ($targets as $target) {
            $teamIds[] = (int) $target->away_team_id;
            $teamIds[] = (int) $target->home_team_id;
            foreach (array_keys($lineups[$target->nhl_game_id]['players'] ?? []) as $id) {
                $playerIds[] = (int) $id;
            }
        }
        foreach (['teams' => ['nhl_team_game_pregame_contexts', 'nhl_team_id', $teamIds],
            'players' => ['nhl_player_game_pregame_contexts', 'nhl_player_id', $playerIds]] as $key => [$table, $entity, $ids]) {
            $ranked = self::futureContextQuery($table, $modelId, $asOf)->whereIn($entity, array_unique($ids))
                ->select($table.'.*')->selectRaw('ROW_NUMBER() OVER (PARTITION BY '.$entity
                    .' ORDER BY source_cutoff_at DESC, id DESC) AS snapshot_order');
            $this->futureSnapshots[$key] = DB::query()->fromSub($ranked, 'snapshots')->where('snapshot_order', 1)
                ->get()->keyBy($entity)->all();
        }
        $this->futureSnapshots['seasons'] = DB::table('nhl_games')
            ->whereIn('nhl_game_id', array_column($this->futureSnapshots['players'], 'nhl_game_id'))
            ->pluck('season_id', 'nhl_game_id')->all();
    }

    /** Reuse personal/team history only; upcoming schedule and opponent bindings belong to this target. */
    private function futurePredictionContexts(object $game, array $record, CarbonImmutable $asOf): array
    {
        $contexts = ['teams' => [$game->nhl_game_id => []], 'players' => [$game->nhl_game_id => []]];
        foreach (['away' => $game->away_team_id, 'home' => $game->home_team_id] as $venue => $team) {
            $opponent = $venue === 'away' ? $game->home_team_id : $game->away_team_id;
            $context = isset($this->futureSnapshots['teams'][$team]) ? clone $this->futureSnapshots['teams'][$team] : null;
            $context ??= (object) ['id' => null, 'run_id' => null, 'nhl_game_id' => null,
                'game_date' => null, 'source_cutoff_at' => $asOf->format('Y-m-d H:i:s'), 'metrics' => '{}'];
            $context->evidence_source = $context->id === null ? 'prior_results_fallback' : 'latest_available_model_snapshot';
            // Never carry head-to-head samples, ranks or schedule evidence from another opponent/game.
            $context->opponent_metrics = '{}';
            $context->ranks = '{}';
            $context->schedule_metrics = json_encode(app(NhlPregameContextBuilder::class)
                ->predictionScheduleEvidence($game, (int) $team, (int) $opponent, $venue), JSON_THROW_ON_ERROR);
            $contexts['teams'][$game->nhl_game_id][$team] = $context;
        }
        foreach ($record['players'] as $id => $player) {
            $context = isset($this->futureSnapshots['players'][$id]) ? clone $this->futureSnapshots['players'][$id] : null;
            $sourceSeason = $context === null ? null : ($this->futureSnapshots['seasons'][$context->nhl_game_id] ?? null);
            if ($context === null || (string) $sourceSeason !== (string) $game->season_id) {
                $this->forecastRawPlayers[$id] = true;
            } else {
                $this->forecastPlayerCutoffs[$id] = CarbonImmutable::parse($context->source_cutoff_at, 'UTC')->timestamp;
            }
            $context ??= (object) ['id' => null, 'team_context_id' => null, 'nhl_game_id' => null,
                'source_cutoff_at' => $asOf->format('Y-m-d H:i:s'), 'metrics' => '{}'];
            $context->opponent_metrics = '{}';
            $context->ranks = '{}';
            $metrics = json_decode($context->metrics, true, 512, JSON_THROW_ON_ERROR);
            foreach (['all', 'EV', 'PP', 'PK'] as $lane) {
                $windows = $lane === 'all' ? ($metrics['all'] ?? []) : ($metrics['strength'][$lane] ?? []);
                $windows = array_filter($windows, fn (string $window): bool => ! str_starts_with($window, 'opponent_')
                    && ! str_starts_with($window, 'venue_'), ARRAY_FILTER_USE_KEY);
                if ($lane === 'all') {
                    $metrics['all'] = $windows;
                } elseif (isset($metrics['strength'][$lane])) {
                    $metrics['strength'][$lane] = $windows;
                }
            }
            $context->metrics = json_encode($metrics, JSON_THROW_ON_ERROR);
            $contexts['players'][$game->nhl_game_id][$id] = $context;
        }

        return $contexts;
    }

    /** Keep only allowance references still used by the selected players' rolling windows. */
    private function pruneForecastReferences(): void
    {
        $ids = [];
        foreach ($this->recent as $appearances) {
            foreach ($appearances as $appearance) {
                $ids[$appearance['game_id']] = true;
            }
        }
        $this->pastAllowances = array_intersect_key($this->pastAllowances, $ids);
        $this->allowanceSources = array_intersect_key($this->allowanceSources, $ids);
    }

    /** Resolve existing verified anticipated rosters, then the established projected-roster fallback. */
    public function anticipatedParticipants(object $game, bool $storedBoxscore = false, array $rosterOverrides = []): array
    {
        $record = ['players' => [], 'starters' => [], 'lineup_sources' => []];
        foreach (['away' => $game->away_team_id, 'home' => $game->home_team_id] as $venue => $team) {
            $abbrev = $game->{$venue.'_team_abbrev'};
            $lineup = app(NhlAnticipatedLineupPayload::class)->forGameTeam((int) $game->nhl_game_id, $abbrev, false);
            $roster = collect($lineup['players'] ?? [])->values()->all();
            $ids = $lineup === null ? null : app(NhlLineupPlayerResolver::class)->verifiedLineupIds(
                collect($lineup['players'] ?? [])->values()->all(), null, (int) $game->game_type
            );
            $source = 'anticipated_lineup';
            if ($storedBoxscore) {
                $roster = app(NhlGameLineupProjectionBuilder::class)->boxscoreRoster((int) $game->nhl_game_id, (int) $team);
                $ids = array_column($roster, 'nhl_player_id');
                $source = 'historical_participants';
            } elseif (array_key_exists($venue, $rosterOverrides)) {
                $ids = array_values(array_unique(array_map('intval', $rosterOverrides[$venue])));
                $source = 'provided_roster';
            }
            if ($ids === null) {
                $version = DB::table('nhl_player_toi_projections')->where('target_season_id', $game->season_id)
                    ->orderByDesc('updated_at')->value('projection_version');
                $roster = app(NhlGameLineupProjectionBuilder::class)->projectedRosterPreview((string) $game->season_id, (string) $version, $abbrev);
                $ids = array_column($roster, 'nhl_player_id');
                $source = 'projected_roster';
            }
            if ($ids === [] && ! $storedBoxscore) {
                $previous = DB::table('nhl_games')->where('start_time_utc', '<', $game->start_time_utc ?: now('UTC'))
                    ->where('game_type', 2)->whereIn('game_state', ['OFF', 'FINAL'])
                    ->where(fn ($q) => $q->where('home_team_id', $team)->orWhere('away_team_id', $team))
                    ->orderByDesc('start_time_utc')->value('nhl_game_id');
                $roster = $previous ? app(NhlGameLineupProjectionBuilder::class)->boxscoreRoster((int) $previous, (int) $team) : [];
                $ids = array_column($roster, 'nhl_player_id');
                $source = 'last_known_lineup';
            }
            $players = DB::table('players')->whereIn('nhl_id', $ids)->get(['nhl_id', 'full_name', 'position', 'pos_type'])->keyBy('nhl_id');
            $reported = collect($roster)->keyBy('nhl_player_id');
            if ($ids === []) {
                throw new DomainException('Resolved lineup identities are required before filling statistical inputs.');
            }
            foreach (array_unique($ids) as $id) {
                $player = $players->get($id);
                $evidence = (array) $reported->get($id, []);
                $position = $player?->pos_type ?: ($player?->position ?? $evidence['position'] ?? $evidence['lineup_role'] ?? null);
                if ((int) $id <= 0 || ($player === null && $evidence === []) || $position === null || in_array($position, ['G', 'goalie'], true)) {
                    throw new DomainException('Resolved skater identity and position evidence are required.');
                }
                $record['players'][(int) $id] = ['id' => (int) $id, 'team' => (int) $team,
                    'name' => $player?->full_name ?? $evidence['player_name'] ?? (string) $id,
                    'role' => in_array($position, ['D', 'defense'], true) ? 'D' : 'F', 'line_key' => $evidence['line_key'] ?? null];
            }
            $record['lineup_sources'][$team] = $source;
            if ($storedBoxscore) {
                $record['starters'][$team] = DB::table('nhl_game_summaries')->where('nhl_game_id', $game->nhl_game_id)
                    ->where('nhl_team_id', $team)->where('goalie_started', true)->value('nhl_player_id');
            } else {
                $starter = app(NhlStartingGoalieSelector::class)->select((int) $game->nhl_game_id, $abbrev, (string) $game->season_id);
                $record['starters'][$team] = $starter['nhl_player_id'] ?? null;
            }
        }

        return $record;
    }

    /** Calculate transient model-owned team evidence without creating context rows or target results. */
    private function predictionContexts(object $game, array $record, CarbonImmutable $cutoff): array
    {
        $contexts = ['teams' => [], 'players' => []];
        foreach (['away' => $game->away_team_id, 'home' => $game->home_team_id] as $venue => $team) {
            $opponent = $venue === 'away' ? $game->home_team_id : $game->away_team_id;
            $evidence = app(NhlPregameContextBuilder::class)->predictionTeamEvidence($game, $cutoff,
                ['team_id' => (int) $team, 'opponent_id' => (int) $opponent, 'venue' => $venue], $this->metadata['train_seasons']);
            $contexts['teams'][$game->nhl_game_id][$team] = (object) ['id' => null, 'source_cutoff_at' => $game->prediction_cutoff_at ?? $game->start_time_utc,
                'metrics' => json_encode($evidence['metrics'], JSON_THROW_ON_ERROR),
                'schedule_metrics' => json_encode($evidence['schedule'], JSON_THROW_ON_ERROR)];
            foreach ($record['players'] as $id => $player) {
                if ($player['team'] === (int) $team) {
                    $contexts['players'][$game->nhl_game_id][$id] = (object) ['id' => null, 'team_context_id' => null,
                        'source_cutoff_at' => $game->prediction_cutoff_at ?? $game->start_time_utc];
                }
            }
        }

        return $contexts;
    }

    /** Forecast before advancing history; excluded targets still become past evidence afterwards. */
    public function games(?string $from = null, ?string $to = null): Generator
    {
        $this->useSavedPlayerAggregates = false;
        if ($this->metadata === []) {
            throw new DomainException('Prepare the model before requesting game inputs.');
        }
        $this->train = $this->seasons = $this->recent = $this->league = [];
        $this->teamTrainBuckets = $this->teamTrain = $this->teamSeason = $this->teamRecent = [];
        $this->trainOpponents = $this->pastAllowances = $this->allowanceSources = [];
        $query = DB::table('nhl_games')->whereIn('season_id', [...$this->metadata['train_seasons'], $this->metadata['test_season']])
            ->where('game_type', 2)->whereIn('game_state', ['OFF', 'FINAL'])->whereNotNull('start_time_utc');
        if ($to !== null) {
            $query->where(fn ($scope) => $scope->whereIn('season_id', $this->metadata['train_seasons'])->orWhere('game_date', '<=', $to));
        }
        $games = $query->orderBy('start_time_utc')->orderBy('nhl_game_id')->get([
                'nhl_game_id', 'season_id', 'game_date', 'start_time_utc', 'home_team_id', 'away_team_id',
                'home_team_abbrev', 'away_team_abbrev', 'home_team_score', 'away_team_score',
                'home_pp_goals', 'away_pp_goals', 'home_pp_opportunities', 'away_pp_opportunities',
            ])->all();
        $defense = new NhlCustomGameDefense();
        $trainingGames = array_values(array_filter($games, fn (object $game): bool => in_array((string) $game->season_id, $this->metadata['train_seasons'], true)));
        $defense->fit($this->trainingDefenseRecords($trainingGames));
        $this->metadata['defense_fit'] = $defense->snapshot();
        $this->metadata['selected_games'] = count(array_filter($games, fn (object $game): bool =>
            (string) $game->season_id === $this->metadata['test_season']
            && ($from === null || $game->game_date >= $from) && ($to === null || $game->game_date <= $to)));
        foreach (array_chunk($games, (int) config('nhl-custom-prediction.batch_games')) as $batch) {
            $records = $this->records($batch);
            $contexts = $this->contexts($batch);
            foreach ($batch as $game) {
                $record = $records[$game->nhl_game_id];
                $isTest = (string) $game->season_id === $this->metadata['test_season'];
                $allowances = $this->allowances($game, $defense);
                if ($isTest && ($from === null || $game->game_date >= $from) && ($to === null || $game->game_date <= $to)) {
                    try {
                        $input = $this->input($game, $record, $contexts, $allowances, $defense);
                        yield ['game' => $game, 'input' => $input, 'actual' => $record['actual'],
                            'player_actual' => $record['player_actual'] ?? [], 'error' => null];
                    } catch (DomainException $exception) {
                        yield ['game' => $game, 'input' => null, 'actual' => $record['actual'],
                            'player_actual' => $record['player_actual'] ?? [], 'error' => $exception->getMessage()];
                    }
                }
                $this->pastAllowances[$game->nhl_game_id] = $allowances;
                $this->advance($game, $record, ! $isTest);
            }
        }
    }

    /** Return the frozen source definition, including the fitted Train allowances once initialized. */
    public function metadata(): array
    {
        return $this->metadata;
    }

    /** Fit only Train source games; Test facts never enter the defensive model. */
    private function trainingDefenseRecords(array $games): Generator
    {
        foreach (array_chunk($games, (int) config('nhl-custom-prediction.batch_games')) as $batch) {
            $records = $this->records($batch);
            foreach ($batch as $game) {
                foreach ([$game->home_team_id, $game->away_team_id] as $id) {
                    $other = $id === $game->home_team_id ? $game->away_team_id : $game->home_team_id;
                    yield ['team' => (int) $id, 'opponent' => (int) $other,
                        'valid' => $records[$game->nhl_game_id]['valid'],
                        'clock' => $records[$game->nhl_game_id]['clocks'][$id] ?? [],
                        'counts' => $records[$game->nhl_game_id]['counts'][$id] ?? []];
                }
            }
        }
    }

    /** Read a bounded game batch, with separate actual regulation counts and full-history exposure. */
    protected function records(array $games): array
    {
        $ids = array_map(fn (object $game): int => (int) $game->nhl_game_id, $games);
        $records = array_fill_keys($ids, ['players' => [], 'counts' => [], 'clocks' => [],
            'reg_clocks' => [], 'actual' => [], 'source' => false, 'valid' => false,
            'unmatched_shooter' => false, 'unclassified' => false, 'missing_period' => false]);
        $summaries = DB::table('nhl_game_summaries as s')->join('players as p', 'p.nhl_id', '=', 's.nhl_player_id')
            ->whereIn('s.nhl_game_id', $ids)->where('s.toi', '>', 0)
            ->get(['s.nhl_game_id', 's.nhl_team_id', 's.nhl_player_id', 's.goalie_started',
                'p.full_name', 'p.position', 'p.pos_type', 'p.is_goalie']);
        foreach ($summaries as $row) {
            $goalie = $row->is_goalie || $row->position === 'G' || $row->pos_type === 'G';
            if ($goalie) {
                if ($row->goalie_started) {
                    $records[$row->nhl_game_id]['starters'][$row->nhl_team_id] = (int) $row->nhl_player_id;
                }
                continue;
            }
            $records[$row->nhl_game_id]['players'][$row->nhl_player_id] = [
                'id' => (int) $row->nhl_player_id, 'team' => (int) $row->nhl_team_id,
                'name' => $row->full_name, 'role' => $row->position === 'D' || $row->pos_type === 'D' ? 'D' : 'F',
                'seconds' => [], 'counts' => [], 'unclassified' => false,
            ];
        }
        foreach (DB::table('nhl_player_game_strength_summaries')->whereIn('nhl_game_id', $ids)
            ->get(['nhl_game_id', 'nhl_player_id', 'strength', 'toi']) as $row) {
            if (isset($records[$row->nhl_game_id]['players'][$row->nhl_player_id])) {
                $records[$row->nhl_game_id]['players'][$row->nhl_player_id]['seconds'][$row->strength] = (float) $row->toi;
            }
        }
        $source = DB::table('nhl_shot_attempts_facts as f')->whereIn('f.nhl_game_id', $ids)
            ->where('f.is_shot_attempt', true)->whereIn('f.event_type', ['shot-on-goal', 'goal', 'missed-shot', 'blocked-shot'])
            ->where(fn ($query) => $query->whereNull('f.period_type')->orWhere('f.period_type', '<>', 'SO'))
            ->where(fn ($query) => $query->whereNull('f.is_empty_net')->orWhere('f.is_empty_net', false));
        foreach ((clone $source)->distinct()->pluck('f.nhl_game_id') as $id) {
            $records[$id]['source'] = true;
        }
        $classified = (clone $source)->select(['f.nhl_game_id', 'f.team_id', 'f.shooter_player_id', 'f.event_type', 'f.period'])
            ->selectRaw("CASE WHEN UPPER(f.strength) IN ('EV','PP','PK') THEN UPPER(f.strength) ELSE UPPER(f.strength_bucket) END as lane")
            ->selectRaw($this->bucketExpression.' as bucket_key');
        $ppGoals = DB::table('nhl_shot_attempts_facts as f')->whereIn('f.nhl_game_id', $ids)
            ->where('f.event_type', 'goal')
            ->where(fn ($query) => $query->whereNull('f.period_type')->orWhere('f.period_type', '<>', 'SO'))
            ->whereRaw("CASE WHEN UPPER(f.strength) IN ('EV','PP','PK') THEN UPPER(f.strength) ELSE UPPER(f.strength_bucket) END = 'PP'")
            ->groupBy('f.nhl_game_id', 'f.team_id')->get(['f.nhl_game_id', 'f.team_id', DB::raw('COUNT(*) goals')]);
        foreach ($ppGoals as $row) {
            $records[$row->nhl_game_id]['official_pp_goals'][$row->team_id] = (int) $row->goals;
        }
        $grouped = DB::query()->fromSub($classified, 'e')
            ->where(fn ($query) => $query->whereIn('bucket_key', $this->filter['keys'])->orWhereNull('lane')->orWhereNotIn('lane', ['EV', 'PP', 'PK']))
            ->groupBy('nhl_game_id', 'team_id', 'shooter_player_id', 'lane', 'bucket_key')
            ->select(['nhl_game_id', 'team_id', 'shooter_player_id', 'lane', 'bucket_key'])
            ->selectRaw("COUNT(*) sat, SUM(CASE WHEN event_type IN ('shot-on-goal','goal') THEN 1 ELSE 0 END) sog, SUM(CASE WHEN event_type='goal' THEN 1 ELSE 0 END) goals")
            ->selectRaw('SUM(CASE WHEN period IS NULL THEN 1 ELSE 0 END) missing_period')
            ->selectRaw("SUM(CASE WHEN period<=3 THEN 1 ELSE 0 END) reg_sat, SUM(CASE WHEN period<=3 AND event_type IN ('shot-on-goal','goal') THEN 1 ELSE 0 END) reg_sog, SUM(CASE WHEN period<=3 AND event_type='goal' THEN 1 ELSE 0 END) reg_goals");
        foreach ($grouped->get() as $row) {
            $record = &$records[$row->nhl_game_id];
            $record['missing_period'] = $record['missing_period'] || (int) $row->missing_period > 0;
            if (! in_array($row->lane, ['EV', 'PP', 'PK'], true)) {
                $record['unclassified'] = true;
                if (isset($record['players'][$row->shooter_player_id])) {
                    $record['players'][$row->shooter_player_id]['unclassified'] = true;
                }
                unset($record);
                continue;
            }
            if (! in_array($row->lane, ['EV', 'PP'], true)) {
                unset($record);
                continue;
            }
            foreach (['sat', 'sog', 'goals'] as $field) {
                $record['counts'][$row->team_id][$row->lane][$row->bucket_key][$field]
                    = ($record['counts'][$row->team_id][$row->lane][$row->bucket_key][$field] ?? 0) + (int) $row->{$field};
                $record['actual'][$row->team_id][$row->lane][$field]
                    = ($record['actual'][$row->team_id][$row->lane][$field] ?? 0) + (int) $row->{'reg_'.$field};
            }
            if (isset($record['players'][$row->shooter_player_id])
                && $record['players'][$row->shooter_player_id]['team'] === (int) $row->team_id) {
                $record['players'][$row->shooter_player_id]['counts'][$row->lane][$row->bucket_key]
                    = ['sat' => (int) $row->sat, 'sog' => (int) $row->sog, 'goals' => (int) $row->goals];
                $record['player_actual'][$row->shooter_player_id][$row->lane][$row->bucket_key]
                    = ['sat' => (int) $row->reg_sat, 'sog' => (int) $row->reg_sog, 'goals' => (int) $row->reg_goals];
            } else {
                $record['unmatched_shooter'] = true;
            }
            unset($record);
        }
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new DomainException('The custom historical clock reader requires PostgreSQL range unions.');
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $clocks = DB::select("WITH lanes AS (
            SELECT us.nhl_game_id, us.team_id,
                CASE WHEN u.unit_type IN ('PP','PK') THEN u.unit_type ELSE 'EV' END lane,
                range_agg(int4range(GREATEST(us.start_game_seconds,0),us.end_game_seconds,'[)')) spans,
                range_agg(int4range(LEAST(GREATEST(us.start_game_seconds,0),3600),LEAST(us.end_game_seconds,3600),'[)')) reg_spans
            FROM nhl_unit_shifts us JOIN nhl_units u ON u.id=us.unit_id
            WHERE us.nhl_game_id IN ($placeholders) AND u.unit_type IN ('F','D','PP','PK')
                AND us.end_game_seconds>GREATEST(us.start_game_seconds,0)
            GROUP BY 1,2,3)
            SELECT nhl_game_id,team_id,lane,
                (SELECT COALESCE(SUM(upper(span)-lower(span)),0) FROM unnest(spans) span) seconds,
                (SELECT COALESCE(SUM(upper(span)-lower(span)),0) FROM unnest(reg_spans) span) reg_seconds
            FROM lanes", $ids);
        foreach ($clocks as $row) {
            $records[$row->nhl_game_id]['clocks'][$row->team_id][$row->lane] = (float) $row->seconds;
            $records[$row->nhl_game_id]['reg_clocks'][$row->team_id][$row->lane] = (float) $row->reg_seconds;
        }
        foreach ($games as $game) {
            $record = &$records[$game->nhl_game_id];
            $record['valid'] = $record['source'] && ! $record['unclassified'] && ! $record['missing_period'];
            foreach ([$game->home_team_id, $game->away_team_id] as $team) {
                $record['valid'] = $record['valid'] && abs(array_sum($record['reg_clocks'][$team] ?? []) - 3600) <= 1;
            }
            unset($record);
        }

        return $records;
    }

    /** Fetch only the current model/version contexts for this bounded Test batch. */
    protected function contexts(array $games): array
    {
        $ids = array_map(fn (object $game): int => (int) $game->nhl_game_id,
            array_values(array_filter($games, fn (object $game): bool => (string) $game->season_id === $this->metadata['test_season'])));
        $contexts = ['teams' => [], 'players' => []];
        if ($ids === []) {
            return $contexts;
        }
        foreach (DB::table('nhl_team_game_pregame_contexts')->where('model_run_id', $this->metadata['model_id'])
            ->where('context_version', $this->metadata['context_version'])->whereIn('nhl_game_id', $ids)
            ->get(['id', 'nhl_game_id', 'nhl_team_id', 'source_cutoff_at', 'schedule_metrics', 'metrics']) as $row) {
            $contexts['teams'][$row->nhl_game_id][$row->nhl_team_id] = $row;
        }
        foreach (DB::table('nhl_player_game_pregame_contexts')->where('model_run_id', $this->metadata['model_id'])
            ->where('context_version', $this->metadata['context_version'])->whereIn('nhl_game_id', $ids)
            ->get(['id', 'nhl_game_id', 'nhl_player_id', 'nhl_team_id', 'team_context_id', 'source_cutoff_at']) as $row) {
            $contexts['players'][$row->nhl_game_id][$row->nhl_player_id] = $row;
        }

        return $contexts;
    }

    /** Resolve defensive history fallbacks without discarding measured zero-attempt windows. */
    private function allowances(object $game, NhlCustomGameDefense $defense): array
    {
        $out = [];
        foreach ([$game->home_team_id, $game->away_team_id] as $team) {
            foreach (['EV', 'PP'] as $lane) {
                foreach ($this->filter['keys'] as $key) {
                    try {
                        $resolved = $defense->resolveAllowance((int) $team, $lane, $key,
                            $lane === 'EV' ? array_slice($this->teamRecent[$team] ?? [], -20) : null);
                        $out[$team][$lane][$key] = $resolved['value'];
                        $this->allowanceSources[$game->nhl_game_id][$team][$lane][$key] = $resolved['source'];
                    } catch (DomainException) {
                        $out[$team][$lane][$key] = null;
                    }
                }
            }
        }

        return $out;
    }

    /** Supply inputs from prior history only; the target record contributes lineup identities and integrity checks. */
    private function input(object $game, array $record, array $contexts, array $allowances, NhlCustomGameDefense $defense, bool $prospective = false): array
    {
        if (! $prospective && (! $record['valid'] || $record['unmatched_shooter'])) {
            throw new DomainException('Target source facts, regulation clocks or shooter identities are incomplete.');
        }
        $confidence = app(NhlSatModelPredictionService::class);
        $skaterConfidence = $confidence->confidenceScores($this->metadata['model_id'], array_keys($record['players']));
        $goalieIds = array_values(array_unique(array_map('intval', array_filter($record['starters'] ?? []))));
        $goalieConfidence = $confidence->goalieConfidenceBuckets($this->metadata['model_id'], $goalieIds, $this->filter['keys']);
        $input = ['game_id' => (int) $game->nhl_game_id, 'source_cutoff_at' => $game->prediction_cutoff_at ?? $game->start_time_utc,
            'retained_bucket_keys' => $this->filter['keys'],
            'teams' => [], 'league_usage_seconds' => [], 'league_pk_probability' => $this->leaguePk((string) $game->season_id)];
        foreach (['EV', 'PP'] as $lane) {
            $reference = $this->league[$lane] ?? [];
            if (($reference['games'] ?? 0) <= 0 || ($reference['seconds'] ?? 0) <= 0) {
                throw new DomainException('Train player exposure is unavailable.');
            }
            $input['league_usage_seconds'][$lane] = $reference['seconds'] / $reference['games'];
        }
        foreach (['away' => $game->away_team_id, 'home' => $game->home_team_id] as $venue => $team) {
            $opponent = $venue === 'away' ? $game->home_team_id : $game->away_team_id;
            $context = $contexts['teams'][$game->nhl_game_id][$team] ?? null;
            $this->assertCutoff($context, $game);
            $schedule = json_decode($context->schedule_metrics, true, 512, JSON_THROW_ON_ERROR);
            if (! is_bool($schedule['back_to_back'] ?? null) || ! is_int($schedule['games_including_target_last_7_days'] ?? null)) {
                throw new DomainException('Schedule classification is unavailable.');
            }
            $teamTrain = $this->teamTrain[$team] ?? [];
            $shareDenominator = ($teamTrain['own_opps'] ?? 0) + ($teamTrain['opponent_opps'] ?? 0);
            $shareSource = 'team_train';
            if ($shareDenominator <= 0) {
                $teamTrain = ['own_opps' => array_sum(array_column($this->teamTrain, 'own_opps')),
                    'opponent_opps' => array_sum(array_column($this->teamTrain, 'opponent_opps'))];
                $shareDenominator = $teamTrain['own_opps'] + $teamTrain['opponent_opps'];
                $shareSource = 'league_train';
                if ($shareDenominator <= 0) {
                    throw new DomainException('Train league opportunity evidence is unavailable.');
                }
            }
            $recent = array_slice($this->teamRecent[$team] ?? [], -10);
            $allowed = $recent !== [] && ! in_array(null, array_column($recent, 'opponent_opps'), true)
                ? array_sum(array_column($recent, 'opponent_opps')) / count($recent) : null;
            $five = array_slice($this->teamRecent[$team] ?? [], -5);
            $allowedSource = 'last_5';
            $ppAllowed = $five !== [] && ! in_array(false, array_column($five, 'valid'), true)
                ? array_sum(array_column($five, 'pp_allowed')) / count($five) : null;
            if ($ppAllowed === null) {
                $history = $this->teamTrain[$team] ?? [];
                $allowedSource = 'team_train';
                if (($history['pp_allowed_games'] ?? 0) <= 0) {
                    $history = ['pp_allowed' => array_sum(array_column($this->teamTrain, 'pp_allowed')),
                        'pp_allowed_games' => array_sum(array_column($this->teamTrain, 'pp_allowed_games'))];
                    $allowedSource = 'league_train';
                }
                if (($history['pp_allowed_games'] ?? 0) <= 0) {
                    throw new DomainException('Train league PP goal history is unavailable.');
                }
                $ppAllowed = $history['pp_allowed'] / $history['pp_allowed_games'];
            }
            $side = ['id' => (int) $team, 'abbrev' => $venue === 'away' ? $game->away_team_abbrev : $game->home_team_abbrev,
                'context_id' => $context->id === null ? null : (int) $context->id, 'starting_goalie_id' => $record['starters'][$team] ?? null,
                'goalie_confidence_buckets' => isset($record['starters'][$team])
                    ? ($goalieConfidence[(int) $record['starters'][$team]] ?? []) : null,
                'lineup_source' => $record['lineup_sources'][$team] ?? 'historical_participants', 'schedule' => $schedule,
                'context_provenance' => ['game_id' => isset($context->nhl_game_id) ? (int) $context->nhl_game_id : null,
                    'source_cutoff_at' => $context->source_cutoff_at],
                'context_source' => $context->evidence_source ?? ($prospective ? 'transient_prior_results' : 'saved_model_snapshot'),
                'team_metrics' => json_decode($context->metrics, true, 512, JSON_THROW_ON_ERROR),
                'train_opportunity_share' => $teamTrain['own_opps'] / $shareDenominator,
                'train_opportunity_share_source' => $shareSource,
                'pk_opportunities_l10' => $allowed,
                'pp_allowed_l5' => $ppAllowed, 'pp_allowed_source' => $allowedSource,
                'pk_probability' => $this->teamPk((int) $team, (string) $game->season_id),
                'pp_offense_l20' => 0.0, 'pp_offense_sources' => [], 'players' => []];
            foreach ($record['players'] as $id => $player) {
                if ($player['team'] !== (int) $team) {
                    continue;
                }
                $playerContext = $contexts['players'][$game->nhl_game_id][$id] ?? null;
                $this->assertCutoff($playerContext, $game);
                if (! ($game->future_context_fallback ?? false) && (int) $playerContext->team_context_id !== (int) $context->id) {
                    throw new DomainException('Player context does not reference this team context.');
                }
                $prepared = ['context_provenance' => ['game_id' => $playerContext->nhl_game_id ?? null,
                    'source_cutoff_at' => $playerContext->source_cutoff_at,
                    'team_context_id' => $playerContext->team_context_id,
                    'source' => isset($this->forecastRawPlayers[$id]) ? 'prior_results_fallback'
                        : (($game->future_context_fallback ?? false) ? 'latest_available_model_snapshot' : 'saved_model_snapshot')],
                    'name' => $player['name'], 'role' => $player['role'], 'context_id' => $playerContext->id === null ? null : (int) $playerContext->id,
                    'confidence_score' => $skaterConfidence->get((int) $id),
                    'line_key' => $player['line_key'] ?? null,
                    'usage' => [], 'complete' => [], 'buckets' => [], 'history' => []];
                foreach (['EV', 'PP'] as $lane) {
                    $sources = $this->sources((int) $id, $lane, (string) $game->season_id, $playerContext);
                    $t = $sources['train'];
                    $ten = $sources['last_10'];
                    $usage = $ten !== null && $ten['games'] > 0 ? $ten['seconds'] / $ten['games'] : null;
                    $validRate = $t !== null && $ten !== null && $t['seconds'] > 0 && $ten['seconds'] > 0;
                    $prepared['usage'][$lane] = $usage;
                    $prepared['complete'][$lane] = $usage !== null && ($usage === 0.0 || $validRate);
                    foreach ($sources as $window => $values) {
                        $prepared['history'][$lane][$window] = $values === null ? null
                            : ['games' => $values['games'], 'seconds' => $values['seconds'],
                                'sat' => $values['totals']['sat'] ?? array_sum(array_column($values['buckets'], 'sat')),
                                'sog' => $values['totals']['sog'] ?? array_sum(array_column($values['buckets'], 'sog')),
                                'goals' => $values['totals']['goals'] ?? array_sum(array_column($values['buckets'], 'goals'))];
                    }
                    foreach ($this->filter['keys'] as $key) {
                        $league = $this->league[$lane];
                        $trainRate = $t !== null && $t['seconds'] > 0 ? 3600 * ($t['buckets'][$key]['sat'] ?? 0) / $t['seconds'] : 0.0;
                        $recentRate = $ten !== null && $ten['seconds'] > 0 ? 3600 * ($ten['buckets'][$key]['sat'] ?? 0) / $ten['seconds'] : 0.0;
                        $trainWeight = (float) config('nhl-custom-prediction.train_rate_weight');
                        $rate = $validRate ? $trainWeight * $trainRate + (1 - $trainWeight) * $recentRate
                            : 3600 * ($league['buckets'][$key]['sat'] ?? 0) / $league['seconds'];
                        if ($rate <= 0) {
                            continue;
                        }
                        $reference = $validRate ? $this->defensiveReference((int) $id, $lane, $key, $trainRate, $recentRate, $defense)
                            : ['value' => 1.0, 'sources' => ['league_train' => 1.0]];
                        $allowance = $allowances[$opponent][$lane][$key] ?? null;
                        if ($allowance === null || $reference === null) {
                            throw new DomainException('A required defensive allowance/reference is unavailable.');
                        }
                        $sogSource = $sources[$lane === 'EV' ? 'last_20' : 'last_5'];
                        $goalSource = $lane === 'EV' ? ($this->train[$id]['EV'] ?? null) : $sources['season_to_date'];
                        $evGoal = $lane === 'EV' ? $this->evGoalConversion(
                            $goalSource,
                            $this->teamTrainBuckets[$team] ?? [],
                            $league,
                            $key,
                        ) : null;
                        $prepared['buckets'][$lane][$key] = ['sat_per_60' => $rate,
                            'defense_allowance' => $allowance, 'defense_reference' => $reference['value'],
                            'defense_allowance_source' => $this->allowanceSources[$game->nhl_game_id][$opponent][$lane][$key],
                            'defense_reference_sources' => $reference['sources'],
                            'sog_probability' => $this->conversion($sogSource, $league, $key, 'sog', 'sat'),
                            'goal_probability' => $evGoal['probability'] ?? $this->conversion($goalSource, $league, $key, 'goals', 'sog'),
                            'sog_source' => ($sogSource['buckets'][$key]['sat'] ?? 0) > 0
                                ? ($lane === 'EV' ? 'last_20' : 'last_5') : 'train_league_bucket',
                            'goal_source' => $evGoal['source'] ?? (($goalSource['buckets'][$key]['sog'] ?? 0) > 0
                                ? 'season_to_date'
                                : ((($league['buckets'][$key]['sog'] ?? 0) > 0) ? 'train_league_bucket' : 'train_league_strength')),
                            'personal_sog_sample' => $sogSource['buckets'][$key]['sat'] ?? null,
                            'personal_goal_sample' => $goalSource['buckets'][$key]['sog'] ?? null,
                            'source' => $validRate ? 'train_l10' : 'train_league_fallback'];
                        if ($evGoal !== null) {
                            $prepared['buckets'][$lane][$key]['goal_conversion'] = $evGoal['evidence'];
                        }
                    }
                }
                $twenty = $sources['last_20'];
                $goalSource = 'last_20';
                if ($twenty === null || $twenty['games'] <= 0) {
                    $twenty = $sources['train'];
                    $goalSource = 'player_train';
                }
                if ($twenty === null || $twenty['games'] <= 0) {
                    $twenty = $this->league['PP'] ?? null;
                    $goalSource = 'league_train';
                }
                if ($twenty === null || $twenty['games'] <= 0) {
                    throw new DomainException('Train league player PP goal history is unavailable.');
                }
                $side['pp_offense_sources'][$id] = $goalSource;
                $side['pp_offense_l20'] += ($twenty['totals']['goals']
                    ?? array_sum(array_column($twenty['buckets'], 'goals'))) / $twenty['games'];
                $side['players'][$id] = $prepared;
            }
            if ($side['players'] === []) {
                throw new DomainException('No skater lineup is available.');
            }
            $input['teams'][$venue] = $side;
        }

        return $input;
    }

    /** Match current snapshot provenance to the target cutoff exactly. */
    private function assertCutoff(?object $context, object $game): void
    {
        $cutoff = $game->prediction_cutoff_at ?? $game->start_time_utc;
        if (($game->future_context_fallback ?? false) && $context !== null && $context->source_cutoff_at
            && CarbonImmutable::parse($context->source_cutoff_at, 'UTC')->lte(CarbonImmutable::parse($cutoff, 'UTC'))) {
            return;
        }
        if (! $context || ! $cutoff || ! $context->source_cutoff_at
            || CarbonImmutable::parse($context->source_cutoff_at, 'UTC')->timestamp
            !== CarbonImmutable::parse($cutoff, 'UTC')->timestamp) {
            throw new DomainException('A current model-owned pregame snapshot is missing or has a different cutoff.');
        }
    }

    /** Pool exposure before computing rates, retaining zero-strength appearances. */
    private function pool(array $records, string $lane, bool $raw = false): ?array
    {
        if ($records === []) {
            return null;
        }
        $out = ['games' => 0, 'seconds' => 0.0, 'buckets' => []];
        foreach ($records as $record) {
            if (! ($record['valid'][$lane] ?? false)) {
                return null;
            }
            $this->merge($out, $record, $lane, ! $this->useSavedPlayerAggregates || $raw);
        }

        return $out;
    }

    /** Aggregate a valid appearance without averaging individual percentages. */
    private function merge(array &$target, array $record, string $lane, bool $includeAggregates = true): void
    {
        if ($includeAggregates) {
            $target['games'] = ($target['games'] ?? 0) + 1;
            $target['seconds'] = ($target['seconds'] ?? 0) + ($record['seconds'][$lane] ?? 0);
        }
        foreach ($record['counts'][$lane] ?? [] as $key => $values) {
            foreach (['sat', 'sog', 'goals'] as $metric) {
                $target['buckets'][$key][$metric] = ($target['buckets'][$key][$metric] ?? 0) + $values[$metric];
            }
        }
        $target['buckets'] ??= [];
    }

    /** LSeason and season-to-date with incomplete source appearances remain unavailable. */
    private function sources(int $id, string $lane, string $season, ?object $context = null): array
    {
        $previous = $this->seasons[(string) ((int) $season - 10001)][$id][$lane] ?? null;
        $current = $this->seasons[$season][$id][$lane] ?? null;

        $sources = ['train' => $this->train[$id][$lane] ?? null,
            'last_game' => $this->pool(array_slice($this->recent[$id] ?? [], -1), $lane, isset($this->forecastRawPlayers[$id])),
            'last_5' => $this->pool(array_slice($this->recent[$id] ?? [], -5), $lane, isset($this->forecastRawPlayers[$id])),
            'last_10' => $this->pool(array_slice($this->recent[$id] ?? [], -10), $lane, isset($this->forecastRawPlayers[$id])),
            'last_20' => $this->pool(array_slice($this->recent[$id] ?? [], -20), $lane, isset($this->forecastRawPlayers[$id])),
            'last_season' => ($previous['missing'] ?? true) ? null : $previous,
            'season_to_date' => ($current['missing'] ?? true) ? null : $current];

        if (! $this->useSavedPlayerAggregates || isset($this->forecastRawPlayers[$id])) {
            return $sources;
        }

        $metrics = json_decode($context->metrics ?? '{}', true, 512, JSON_THROW_ON_ERROR);
        if (($metrics['individual_strength_version'] ?? null) !== 1) {
            throw new DomainException('Saved individual-strength pregame aggregates are unavailable.');
        }
        foreach ($sources as $window => $source) {
            $values = $metrics['strength'][$lane][$window] ?? null;
            $sources[$window] = $this->savedPlayerSource($values, $source, $window);
        }

        return $sources;
    }

    /** Reuse exact saved counts/exposure; only per-bucket counts are assembled by this reader. */
    private function savedPlayerSource(?array $values, ?array $source, string $window): ?array
    {
        if ($values === null) {
            throw new DomainException('Saved individual-strength pregame window '.$window.' is unavailable.');
        }
        foreach (['games', 'toi_seconds', 'sat', 'sog', 'goals', 'individual_missing_games'] as $field) {
            if (! array_key_exists($field, $values)) {
                throw new DomainException('Saved individual-strength pregame window '.$window.' is incomplete.');
            }
        }
        if ($values['games'] === 0 || ($window !== 'train' && $values['individual_missing_games'] > 0)) {
            return null;
        }
        foreach (['games', 'toi_seconds', 'sat', 'sog', 'goals', 'individual_missing_games'] as $field) {
            if (! is_numeric($values[$field]) || ! is_finite((float) $values[$field]) || $values[$field] < 0) {
                throw new DomainException('Saved individual-strength pregame window '.$window.' has invalid counts.');
            }
        }
        if ($source === null) {
            throw new DomainException('Bucket history does not support saved pregame window '.$window.'.');
        }

        return ['games' => (int) $values['games'], 'seconds' => (float) $values['toi_seconds'],
            'totals' => array_intersect_key($values, array_flip(['sat', 'sog', 'goals'])),
            'buckets' => $source['buckets']];
    }

    /**
     * Use retained Train SAT, not SOG or saved overall totals, to weight EV bucket conversion.
     *
     * @param array<string, mixed>|null $personal Raw personal Train buckets, independent of saved aggregate exposure.
     * @param array<string, mixed> $team All eligible skaters' Train buckets for the upcoming team.
     * @param array<string, mixed> $league Frozen retained EV Train reference.
     * @return array{probability: float, source: string, evidence: array<string, mixed>}
     */
    private function evGoalConversion(?array $personal, array $team, array $league, string $key): array
    {
        $rule = config('nhl-custom-prediction.ev_goal_conversion');
        $minimum = $rule['minimum_train_sat'] ?? null;
        $blend = $rule['low_volume_weights'] ?? [];
        if (! is_int($minimum) || $minimum < 1 || ! is_array($blend) || count($blend) !== 3
            || array_diff(['player', 'team', 'league'], array_keys($blend)) !== []) {
            throw new DomainException('Configure the EV Train bucket reliability rule before predicting.');
        }
        foreach ($blend as $weight) {
            if (! is_numeric($weight) || ! is_finite((float) $weight) || $weight < 0 || $weight > 1) {
                throw new DomainException('EV bucket reliability weights must be finite probabilities.');
            }
        }
        if (abs(array_sum($blend) - 1.0) > 0.000000001) {
            throw new DomainException('EV bucket reliability weights must sum to one.');
        }

        $sat = (int) ($personal['buckets'][$key]['sat'] ?? 0);
        $rates = [
            'player' => $this->conversion($personal, $league, $key, 'goals', 'sog'),
            'team' => $this->conversion($team, $league, $key, 'goals', 'sog'),
            'league' => $this->conversion(null, $league, $key, 'goals', 'sog'),
        ];
        $weights = $sat >= $minimum ? ['player' => 1.0, 'team' => 0.0, 'league' => 0.0] : $blend;
        $probability = 0.0;
        foreach ($weights as $component => $weight) {
            $probability += $weight * $rates[$component];
        }
        $source = $sat < $minimum ? 'train_player_team_league_blend'
            : ((($personal['buckets'][$key]['sog'] ?? 0) > 0) ? 'train_player_bucket'
                : ((($league['buckets'][$key]['sog'] ?? 0) > 0) ? 'train_league_bucket' : 'train_league_strength'));

        return ['probability' => $probability, 'source' => $source,
            'evidence' => ['train_sat' => $sat, 'minimum_train_sat' => $minimum,
                'weights' => $weights, 'probabilities' => $rates]];
    }

    /** Personal observed zeros are supported; only missing denominators reach league fallback. */
    private function conversion(?array $source, array $league, string $key, string $numerator, string $denominator): float
    {
        if (($source['buckets'][$key][$denominator] ?? 0) > 0) {
            return ($source['buckets'][$key][$numerator] ?? 0) / $source['buckets'][$key][$denominator];
        }
        if (($league['buckets'][$key][$denominator] ?? 0) > 0) {
            return ($league['buckets'][$key][$numerator] ?? 0) / $league['buckets'][$key][$denominator];
        }
        if ($denominator === 'sat') {
            throw new DomainException('The matching Train league bucket SAT denominator is unavailable.');
        }
        $den = array_sum(array_column($league['buckets'], $denominator));
        if ($den <= 0) {
            throw new DomainException('Train league conversion denominator is unavailable.');
        }

        return array_sum(array_column($league['buckets'], $numerator)) / $den;
    }

    /** Weight the two source-window defensive references by their offensive rate contributions. */
    private function defensiveReference(int $id, string $lane, string $key, float $trainRate, float $recentRate, NhlCustomGameDefense $defense): ?array
    {
        $trainSum = $trainSeconds = 0.0;
        $trainSources = $recentSources = [];
        foreach ($this->trainOpponents[$id][$lane] ?? [] as $opponent => $seconds) {
            if ($seconds <= 0) {
                continue;
            }
            $resolved = $defense->resolveAllowance((int) $opponent, $lane, $key);
            $trainSum += $seconds * $resolved['value'];
            $trainSeconds += $seconds;
            $trainSources[$resolved['source']] = ($trainSources[$resolved['source']] ?? 0) + $seconds;
        }
        $recentSum = $recentSeconds = 0.0;
        foreach (array_slice($this->recent[$id] ?? [], -10) as $record) {
            $seconds = $record['seconds'][$lane] ?? 0;
            if ($seconds <= 0) {
                continue;
            }
            $value = $this->pastAllowances[$record['game_id']][$record['opponent']][$lane][$key] ?? null;
            $source = $this->allowanceSources[$record['game_id']][$record['opponent']][$lane][$key] ?? null;
            if ($value === null) {
                $resolved = $defense->resolveAllowance((int) $record['opponent'], $lane, $key);
                $value = $resolved['value'];
                $source = $resolved['source'];
            }
            $recentSum += $seconds * $value;
            $recentSeconds += $seconds;
            $recentSources[$source] = ($recentSources[$source] ?? 0) + $seconds;
        }
        if ($trainSeconds <= 0 || $recentSeconds <= 0 || $trainRate + $recentRate <= 0) {
            return null;
        }

        $weight = (float) config('nhl-custom-prediction.train_rate_weight');
        $totalRate = $weight * $trainRate + (1 - $weight) * $recentRate;
        $sources = [];
        foreach ([[$trainSources, $trainSeconds, $weight * $trainRate],
            [$recentSources, $recentSeconds, (1 - $weight) * $recentRate]] as [$counts, $seconds, $rate]) {
            foreach ($counts as $source => $time) {
                $sources[$source] = ($sources[$source] ?? 0) + $rate * $time / $seconds / $totalRate;
            }
        }

        return ['value' => ($weight * $trainRate * $trainSum / $trainSeconds
            + (1 - $weight) * $recentRate * $recentSum / $recentSeconds) / $totalRate, 'sources' => $sources];
    }

    /** PK success is opponent PP goals/opportunities; start the target season from Train only. */
    private function teamPk(int $team, string $season): float
    {
        $row = $this->teamSeason[$season][$team] ?? [];
        if (($row['pk_opps'] ?? 0) <= 0) {
            $row = $this->teamTrain[$team] ?? [];
        }
        if (($row['pk_opps'] ?? 0) <= 0) {
            return $this->leaguePk($season);
        }

        return 1 - $row['official_pp_allowed'] / $row['pk_opps'];
    }

    /** Pool the league PK numerator and denominator, never team percentages. */
    private function leaguePk(string $season): float
    {
        $rows = $this->teamSeason[$season] ?? [];
        if (array_sum(array_column($rows, 'pk_opps')) <= 0) {
            $rows = $this->teamTrain;
        }
        $opportunities = array_sum(array_column($rows, 'pk_opps'));
        if ($opportunities <= 0) {
            throw new DomainException('League PK reference is unavailable.');
        }

        return 1 - array_sum(array_column($rows, 'official_pp_allowed')) / $opportunities;
    }

    /** Advance all player and team histories only after the two-sided target input has been yielded. */
    private function advance(object $game, array $record, bool $isTrain): void
    {
        foreach ($record['players'] as $id => $player) {
            $keepPersonal = ($this->forecastPlayerIds === null || isset($this->forecastPlayerIds[$id]))
                && (! isset($this->forecastPlayerCutoffs[$id])
                    || CarbonImmutable::parse($game->start_time_utc, 'UTC')->timestamp < $this->forecastPlayerCutoffs[$id]);
            $opponent = $player['team'] === (int) $game->home_team_id ? (int) $game->away_team_id : (int) $game->home_team_id;
            $current = [...$player, 'game_id' => $game->nhl_game_id, 'opponent' => $opponent, 'valid' => []];
            foreach (['EV', 'PP'] as $lane) {
                $current['valid'][$lane] = $record['source'] && $player['seconds'] !== [] && ! $player['unclassified']
                    && (($player['seconds'][$lane] ?? 0) > 0 || array_sum(array_column($player['counts'][$lane] ?? [], 'sat')) === 0);
                if ($isTrain && $current['valid'][$lane]) {
                    $this->league[$lane] ??= ['games' => 0, 'seconds' => 0.0, 'buckets' => []];
                    $this->merge($this->league[$lane], $current, $lane);
                    if ($lane === 'EV') {
                        $this->teamTrainBuckets[$player['team']] ??= ['buckets' => []];
                        $this->merge($this->teamTrainBuckets[$player['team']], $current, $lane, false);
                    }
                }
                if (! $keepPersonal) {
                    continue;
                }
                $target = &$this->seasons[$game->season_id][$id][$lane];
                $target ??= ['games' => 0, 'seconds' => 0.0, 'buckets' => [], 'missing' => false];
                $target['missing'] = $target['missing'] || ! $current['valid'][$lane];
                if ($current['valid'][$lane]) {
                    $this->merge($target, $current, $lane, ! $this->useSavedPlayerAggregates || isset($this->forecastRawPlayers[$id]));
                    if ($isTrain) {
                        $this->train[$id][$lane] ??= ['games' => 0, 'seconds' => 0.0, 'buckets' => []];
                        $this->merge($this->train[$id][$lane], $current, $lane, ! $this->useSavedPlayerAggregates || isset($this->forecastRawPlayers[$id]));
                        $this->trainOpponents[$id][$lane][$opponent]
                            = ($this->trainOpponents[$id][$lane][$opponent] ?? 0) + ($player['seconds'][$lane] ?? 0);
                    }
                }
                unset($target);
            }
            if ($keepPersonal) {
                $this->recent[$id][] = $current;
                if (count($this->recent[$id]) > 20) {
                    array_shift($this->recent[$id]);
                }
            }
        }
        foreach (['home' => $game->home_team_id, 'away' => $game->away_team_id] as $venue => $team) {
            $other = $venue === 'home' ? $game->away_team_id : $game->home_team_id;
            $own = $venue === 'home' ? $game->home_pp_opportunities : $game->away_pp_opportunities;
            $opponent = $venue === 'home' ? $game->away_pp_opportunities : $game->home_pp_opportunities;
            $officialAllowed = $venue === 'home' ? $game->away_pp_goals : $game->home_pp_goals;
            if ($officialAllowed === null && $record['source']) {
                $officialAllowed = $record['official_pp_goals'][$other] ?? 0;
            }
            $ppAllowed = array_sum(array_column($record['counts'][$other]['PP'] ?? [], 'goals'));
            $entry = ['opponent' => (int) $other, 'valid' => $record['valid'],
                'clock' => $record['clocks'][$team] ?? [], 'against' => $record['counts'][$other] ?? [],
                'own_opps' => $own === null ? null : (int) $own,
                'opponent_opps' => $opponent === null ? null : (int) $opponent, 'pp_allowed' => $ppAllowed];
            $this->teamRecent[$team][] = $entry;
            if (count($this->teamRecent[$team]) > 20) {
                array_shift($this->teamRecent[$team]);
            }
            if ($own !== null && $opponent !== null) {
                $this->teamSeason[$game->season_id][$team] ??= ['own_opps' => 0, 'opponent_opps' => 0, 'official_pp_allowed' => 0, 'pk_opps' => 0];
                $this->teamSeason[$game->season_id][$team]['own_opps'] += $own;
                $this->teamSeason[$game->season_id][$team]['opponent_opps'] += $opponent;
                if ($officialAllowed !== null) {
                    $this->teamSeason[$game->season_id][$team]['official_pp_allowed'] += $officialAllowed;
                    $this->teamSeason[$game->season_id][$team]['pk_opps'] += $opponent;
                }
                if ($isTrain) {
                    $this->teamTrain[$team] ??= ['own_opps' => 0, 'opponent_opps' => 0, 'official_pp_allowed' => 0, 'pk_opps' => 0];
                    $this->teamTrain[$team]['own_opps'] += $own;
                    $this->teamTrain[$team]['opponent_opps'] += $opponent;
                    if ($record['valid']) {
                        $this->teamTrain[$team]['pp_allowed'] = ($this->teamTrain[$team]['pp_allowed'] ?? 0) + $ppAllowed;
                        $this->teamTrain[$team]['pp_allowed_games'] = ($this->teamTrain[$team]['pp_allowed_games'] ?? 0) + 1;
                    }
                    if ($officialAllowed !== null) {
                        $this->teamTrain[$team]['official_pp_allowed'] += $officialAllowed;
                        $this->teamTrain[$team]['pk_opps'] += $opponent;
                    }
                }
            }
        }
    }
}
