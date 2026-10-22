<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\NhlPregameContextDataUnavailable;
use App\Exceptions\NhlPregameContextRunStopped;
use App\Models\NhlExpectedGoalsModel;
use App\Models\NhlPregameContextRun;
use App\Models\NhlPregameContextRunGame;
use Closure;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Builds compact, pregame-only team and player context snapshots for one game. */
class NhlPregameContextBuilder
{
    public const VERSION = 'pregame_context_v2';

    public const EVALUATION_BUCKET_VERSION = 1;

    /**
     * Stream fresh league peer values from prior results, without reading or writing context rows.
     *
     * @return \Generator<int,array{entity_id:int,metric_key:string,window_key:string,strength:string,value:float}>
     */
    public function leagueResultRows(NhlPregameContextRun $run, object $targetGame, string $entityType, ?Closure $isStopped = null): \Generator
    {
        $cutoff = now()->parse($targetGame->start_time_utc);
        $training = array_map('strval', data_get($run->options, 'train_season_ids', []));
        $upcoming = [];
        $schedule = DB::table('nhl_games')->where('season_id', $targetGame->season_id)
            ->where('game_type', 2)->where('start_time_utc', '>=', $cutoff)
            ->orderBy('start_time_utc')->orderBy('nhl_game_id')
            ->get(['nhl_game_id', 'season_id', 'game_date', 'start_time_utc', 'home_team_id', 'away_team_id']);
        foreach ($schedule as $game) {
            foreach (['home', 'away'] as $venue) {
                $teamId = (int) $game->{$venue.'_team_id'};
                if ($teamId > 0 && ! isset($upcoming[$teamId])) {
                    $upcoming[$teamId] = ['game' => $game, 'side' => [
                        'team_id' => $teamId,
                        'opponent_id' => (int) $game->{($venue === 'home' ? 'away' : 'home').'_team_id'},
                        'venue' => $venue,
                    ]];
                }
            }
        }

        if ($entityType === 'team') {
            $teams = DB::table('nhl_games')->whereIn('season_id', $run->season_ids)
                ->where('game_type', 2)->whereIn('game_state', ['OFF', 'FINAL'])
                ->where('start_time_utc', '<', $cutoff)->get(['home_team_id', 'away_team_id'])
                ->flatMap(fn (object $game): array => [$game->home_team_id, $game->away_team_id])->filter()->unique()->sort();
            foreach ($teams as $teamId) {
                $this->assertRunNotStopped($isStopped);
                $next = $upcoming[$teamId] ?? null;
                if ($next !== null) {
                    [$metrics, $opponent, $scheduleMetrics] = $this->teamResultEvidence($next['game'], $cutoff, $next['side'], $training, $isStopped);
                    $sources = $this->metricSources($metrics, ['opponent' => $opponent, 'schedule' => $scheduleMetrics], false);
                } else {
                    $sources = $this->metricSources($this->teamMetrics($this->teamHistory((int) $teamId, $cutoff), (string) $targetGame->season_id, $training), [], false);
                }
                yield from $this->leagueMetricRows((int) $teamId, $sources);
            }

            return;
        }
        if ($entityType !== 'player') {
            throw new RuntimeException('League peers must be teams or players.');
        }

        $appearances = DB::table('nhl_game_summaries as summary')
            ->join('nhl_games as game', 'game.nhl_game_id', '=', 'summary.nhl_game_id')
            ->whereIn('game.season_id', $run->season_ids)->where('game.game_type', 2)
            ->whereIn('game.game_state', ['OFF', 'FINAL'])->where('game.start_time_utc', '<', $cutoff)
            ->where('summary.toi', '>', 0)
            ->select(['summary.nhl_player_id', 'summary.nhl_team_id'])
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY summary.nhl_player_id ORDER BY game.start_time_utc DESC, game.nhl_game_id DESC) AS appearance_rank');
        $players = DB::query()->fromSub($appearances, 'prior_appearance')->where('appearance_rank', 1)
            ->orderBy('nhl_player_id')->get(['nhl_player_id', 'nhl_team_id']);
        foreach ($players->chunk(max(1, (int) config('nhl-pregame.player_batch_size', 8))) as $batch) {
            $this->assertRunNotStopped($isStopped);
            [$histories, $exposures, $attempts] = $this->loadPlayerResultInputs($batch->pluck('nhl_player_id')->all(), $cutoff);
            foreach ($batch as $player) {
                $this->assertRunNotStopped($isStopped);
                $id = (int) $player->nhl_player_id;
                $teamId = (int) $player->nhl_team_id;
                $next = $upcoming[$teamId] ?? null;
                $metrics = $this->playerMetrics($id, $teamId, $next['side']['opponent_id'] ?? 0,
                    $next['side']['venue'] ?? 'home', $cutoff, (string) $targetGame->season_id, $training, null, [
                        'history' => $histories->get($id, collect()),
                        'exposure' => $exposures->get($id, collect()),
                        'attempts' => $attempts->get($id, collect()),
                    ]);
                $sources = $this->metricSources($metrics, [], true);
                if ($next === null) {
                    $sources = array_filter($sources, fn (string $window): bool => ! str_starts_with($window, 'opponent_') && ! str_starts_with($window, 'venue_'), ARRAY_FILTER_USE_KEY);
                }
                yield from $this->leagueMetricRows($id, $sources);
            }
            unset($histories, $exposures, $attempts);
        }
    }

    /** Stream only observed numeric values; missing metric evidence never enters the league population. */
    private function leagueMetricRows(int $entityId, array $sources): \Generator
    {
        foreach ($sources as $window => $strengths) {
            foreach ($strengths as $strength => $values) {
                foreach ($values as $metric => $value) {
                    if (is_bool($value)) {
                        $value = (int) $value;
                    }
                    if ($value !== null && is_numeric($value)) {
                        yield ['entity_id' => $entityId, 'metric_key' => (string) $metric,
                            'window_key' => $window, 'strength' => $strength, 'value' => round((float) $value, 6)];
                    }
                }
            }
        }
    }

    /**
     * Persist date-level league comparisons using each entity's latest snapshot at each cutoff.
     *
     * @param Closure|null $isStopped Optional run-scoped cancellation check.
     */
    public function buildLeagueBenchmarksForDate(
        ?int $modelRunId,
        string $gameDate,
        ?int $runId = null,
        ?Closure $isStopped = null,
        ?int $runDayId = null,
    ): void {
        foreach ([
            ['table' => 'nhl_team_game_pregame_context_metrics', 'context_table' => 'nhl_team_game_pregame_contexts', 'foreign_key' => 'team_context_id', 'entity_key' => 'nhl_team_id'],
            ['table' => 'nhl_player_game_pregame_context_metrics', 'context_table' => 'nhl_player_game_pregame_contexts', 'foreign_key' => 'player_context_id', 'entity_key' => 'nhl_player_id'],
        ] as $config) {
            $cutoffs = DB::table($config['context_table'])
                ->where('model_run_id', $modelRunId)
                ->when($modelRunId === null && $runId !== null, fn ($query) => $query->where('run_id', $runId))
                ->whereDate('game_date', $gameDate)
                ->where('context_version', self::VERSION)
                ->distinct()->orderBy('source_cutoff_at')->pluck('source_cutoff_at');

            foreach ($cutoffs as $cutoff) {
                $this->assertRunNotStopped($isStopped);
                $peerRows = DB::table($config['table'].' as metric')
                    ->join($config['context_table'].' as context', 'context.id', '=', 'metric.'.$config['foreign_key'])
                    ->where('context.model_run_id', $modelRunId)
                    ->when($modelRunId === null && $runId !== null, fn ($query) => $query->where('context.run_id', $runId))
                    ->whereDate('context.game_date', '<=', $gameDate)
                    ->where('context.source_cutoff_at', '<=', $cutoff)
                    ->where('context.context_version', self::VERSION)
                    ->where('metric.measure_key', 'value')
                    ->whereNotNull('metric.value')
                    ->whereNotExists(function ($newer) use ($config, $modelRunId, $runId, $gameDate, $cutoff): void {
                        $newer->selectRaw('1')->from($config['context_table'].' as newer')
                            ->whereColumn('newer.'.$config['entity_key'], 'context.'.$config['entity_key'])
                            ->where('newer.model_run_id', $modelRunId)
                            ->when($modelRunId === null && $runId !== null, fn ($query) => $query->where('newer.run_id', $runId))
                            ->where('newer.context_version', self::VERSION)
                            ->whereDate('newer.game_date', '<=', $gameDate)
                            ->where('newer.source_cutoff_at', '<=', $cutoff)
                            ->where(function ($later): void {
                                $later->whereColumn('newer.source_cutoff_at', '>', 'context.source_cutoff_at')
                                    ->orWhere(function ($sameCutoff): void {
                                        $sameCutoff->whereColumn('newer.source_cutoff_at', 'context.source_cutoff_at')
                                            ->whereColumn('newer.nhl_game_id', '>', 'context.nhl_game_id');
                                    });
                            });
                    })
                    ->select([
                        'metric.id', 'metric.metric_key', 'metric.window_key', 'metric.strength',
                        'metric.value', 'metric.'.$config['foreign_key'].' as context_metric_id',
                        'context.game_date as target_game_date', 'context.source_cutoff_at as target_cutoff_at',
                    ])
                    ->selectRaw('AVG(metric.value) OVER (PARTITION BY metric.metric_key, metric.window_key, metric.strength) AS league_average')
                    ->selectRaw('RANK() OVER (PARTITION BY metric.metric_key, metric.window_key, metric.strength ORDER BY metric.value DESC) AS league_rank')
                    ->selectRaw('COUNT(*) OVER (PARTITION BY metric.metric_key, metric.window_key, metric.strength) AS rank_population');

                $leagueAverageIds = [];
                if ($runDayId !== null) {
                    $groups = DB::query()->fromSub(clone $peerRows, 'peer_metrics')
                        ->select(['metric_key', 'window_key', 'strength'])
                        ->selectRaw('AVG(value) AS league_average')
                        ->selectRaw('COUNT(*) AS rank_population')
                        ->groupBy('metric_key', 'window_key', 'strength')->get();
                    $averageRows = $groups->map(fn (object $group): array => [
                        'run_id' => $runId,
                        'model_run_id' => $modelRunId,
                        'run_day_id' => $runDayId,
                        'game_date' => $gameDate,
                        'source_cutoff_at' => $cutoff,
                        'entity_type' => $config['entity_key'] === 'nhl_team_id' ? 'team' : 'player',
                        'metric_key' => $group->metric_key,
                        'window_key' => $group->window_key,
                        'strength' => $group->strength,
                        'league_average' => $group->league_average,
                        'rank_population' => $group->rank_population,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ])->all();
                    foreach (array_chunk($averageRows, 500) as $chunk) {
                        DB::table('nhl_pregame_context_league_averages')->upsert(
                            $chunk,
                            ['run_id', 'source_cutoff_at', 'entity_type', 'metric_key', 'window_key', 'strength'],
                            ['run_day_id', 'game_date', 'league_average', 'rank_population', 'updated_at']
                        );
                    }
                    $leagueAverageIds = DB::table('nhl_pregame_context_league_averages')
                        ->where('run_id', $runId)->where('run_day_id', $runDayId)
                        ->where('source_cutoff_at', $cutoff)
                        ->where('entity_type', $config['entity_key'] === 'nhl_team_id' ? 'team' : 'player')
                        ->get(['id', 'metric_key', 'window_key', 'strength'])
                        ->mapWithKeys(fn (object $row): array => [
                            implode('|', [$row->metric_key, $row->window_key, $row->strength]) => (int) $row->id,
                        ])->all();
                }

                $targetRows = DB::query()->fromSub($peerRows, 'ranked_metrics')
                    ->whereDate('target_game_date', $gameDate)
                    ->where('target_cutoff_at', $cutoff)
                    ->orderBy('id')
                    ->select([
                        'id', 'metric_key', 'window_key', 'strength', 'value', 'context_metric_id',
                        'league_average', 'league_rank', 'rank_population',
                    ]);
                $updates = [];
                foreach ($targetRows->cursor() as $row) {
                    $this->assertRunNotStopped($isStopped);
                    $value = (float) $row->value;
                    $leagueAverage = (float) $row->league_average;
                    $update = [
                        $config['foreign_key'] => $row->context_metric_id,
                        'metric_key' => $row->metric_key,
                        'window_key' => $row->window_key,
                        'strength' => $row->strength,
                        'measure_key' => 'value',
                        'value' => $row->value,
                        'league_average' => $leagueAverage,
                        'signed_difference' => $value - $leagueAverage,
                        'absolute_difference' => abs($value - $leagueAverage),
                        'percentage_difference' => $leagueAverage == 0.0 ? null : 100 * ($value - $leagueAverage) / abs($leagueAverage),
                        'league_rank' => $row->league_rank,
                        'rank_population' => $row->rank_population,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                    if ($runDayId !== null) {
                        $update['league_average_id'] = $leagueAverageIds[implode('|', [$row->metric_key, $row->window_key, $row->strength])] ?? null;
                    }
                    $updates[] = $update;

                    if (count($updates) === 300) {
                        $this->assertRunNotStopped($isStopped);
                        $this->upsertLeagueBenchmarkChunk($config['table'], $config['foreign_key'], $updates, $runDayId !== null);
                        $updates = [];
                    }
                }
                if ($updates !== []) {
                    $this->assertRunNotStopped($isStopped);
                    $this->upsertLeagueBenchmarkChunk($config['table'], $config['foreign_key'], $updates, $runDayId !== null);
                }
            }
        }
    }

    /**
     * Persist a bounded page of league comparisons without holding the date population in memory.
     *
     * @param list<array<string,int|float|string|null>> $rows
     */
    private function upsertLeagueBenchmarkChunk(string $table, string $foreignKey, array $rows, bool $includeLeagueAverageId): void
    {
        $updates = ['league_average', 'signed_difference', 'absolute_difference', 'percentage_difference', 'league_rank', 'rank_population', 'updated_at'];
        if ($includeLeagueAverageId) {
            $updates[] = 'league_average_id';
        }

        DB::table($table)->upsert(
            $rows,
            [$foreignKey, 'metric_key', 'window_key', 'strength', 'measure_key'],
            $updates
        );
    }

    /** Freeze the model's Train-only reliable bucket set; never use Test confidence. */
    public function evaluationBucketFilter(int $modelRunId, array $training): array
    {
        $ids = DB::table('nhl_sat_model_entity_profile_buckets')
            ->where('model_run_id', $modelRunId)->where('profile_type', 'skater_offense')
            ->distinct()->limit(2)->pluck('sat_expected_goals_model_id');
        $model = $ids->count() === 1 ? NhlExpectedGoalsModel::find($ids->first()) : null;
        $sources = array_map('strval', data_get($model?->feature_config, 'training_season_ids', []));
        $training = array_map('strval', $training);
        if (! $model || $sources === [] || array_diff($sources, $training) || array_diff($training, $sources)) {
            throw new RuntimeException('Game evaluation requires one SAT bucket model matching its frozen Train seasons.');
        }
        $buckets = $model->buckets()->where('fallback_level', 1)->orderBy('bucket_key')
            ->limit(5001)->get(['bucket_key', 'confidence_score']);
        if ($buckets->isEmpty() || $buckets->count() > 5000) {
            throw new RuntimeException('Game evaluation requires a bounded, built SAT bucket map.');
        }

        return ['version' => self::EVALUATION_BUCKET_VERSION, 'minimum_confidence' => 0.5,
            'sat_model_id' => $model->id, 'feature_config' => $model->feature_config,
            'training_seasons' => $sources,
            'keys' => $buckets->filter(fn ($bucket) => $bucket->confidence_score !== null
                && (float) $bucket->confidence_score >= 0.5)->pluck('bucket_key')->all()];
    }

    /** Count retained shots without dropping appearances or hiding unclassified source facts. */
    public function individualAttemptCounts(int $playerId, array $gameIds, ?array $bucketFilter = null): Collection
    {
        $strength = "CASE WHEN UPPER(f.strength) IN ('EV', 'PP', 'PK') THEN UPPER(f.strength) ELSE UPPER(f.strength_bucket) END";
        $retained = 'true';
        $bindings = [];
        if ($bucketFilter !== null) {
            if (($bucketFilter['version'] ?? null) !== self::EVALUATION_BUCKET_VERSION
                || (float) ($bucketFilter['minimum_confidence'] ?? 0) !== 0.5) {
                throw new RuntimeException('Refresh the saved evaluation bucket filter before comparing games.');
            }
            $keys = $bucketFilter['keys'];
            $model = new NhlExpectedGoalsModel(['feature_config' => $bucketFilter['feature_config']]);
            $expression = app(NhlSatModelEntityProfileBuilder::class)->modelBucketKeySql($model, 'f');
            $retained = $keys === [] ? 'false' : $expression.' IN ('.implode(',', array_fill(0, count($keys), '?')).')';
            $retained = "({$retained}) AND COALESCE(f.is_empty_net, false) = false";
            $bindings = $keys;
        }

        return DB::table('nhl_shot_attempts_facts as f')->where('f.shooter_player_id', $playerId)
            ->whereIn('f.nhl_game_id', $gameIds)->where('f.is_shot_attempt', true)
            ->where(fn ($query) => $query->whereNull('f.period_type')->orWhere('f.period_type', '<>', 'SO'))
            ->select('f.nhl_game_id')->selectRaw("{$strength} as strength, COUNT(*) as source_sat")
            ->selectRaw("SUM(CASE WHEN {$retained} THEN 1 ELSE 0 END) as sat", $bindings)
            ->selectRaw("SUM(CASE WHEN ({$retained}) AND f.is_shot_on_goal THEN 1 ELSE 0 END) as sog", $bindings)
            ->selectRaw("SUM(CASE WHEN ({$retained}) AND f.is_goal THEN 1 ELSE 0 END) as goals", $bindings)
            ->groupBy('f.nhl_game_id')->groupByRaw($strength)->get()->groupBy('nhl_game_id');
    }

    /**
     * @param NhlPregameContextRun $run Model-scoped build settings.
     * @param int $gameId NHL game identifier being rebuilt.
     * @param Closure|null $isStopped Optional run-scoped cancellation check.
     * @return array{team_context_count:int,player_context_count:int}
     */
    public function build(NhlPregameContextRun $run, int $gameId, ?Closure $isStopped = null): array
    {
        return DB::transaction(fn (): array => $this->buildSnapshot($run, $gameId, $isStopped));
    }

    /** Build a bounded game-day batch after loading shared player histories in bulk. */
    public function buildDay(NhlPregameContextRun $run, array $gameIds, ?Closure $isStopped = null): array
    {
        $totals = ['team_context_count' => 0, 'player_context_count' => 0, 'blocked_games' => 0, 'games' => []];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $gameIds))), 1) as $gameChunk) {
            $dayInputs = ['batch_players' => true];
            $metricWriteBuffer = [];
            DB::transaction(function () use ($run, $gameChunk, $isStopped, $dayInputs, &$metricWriteBuffer, &$totals): void {
                foreach ($gameChunk as $gameId) {
                    $this->assertRunNotStopped($isStopped);
                    try {
                        $counts = DB::transaction(function () use ($run, $gameId, $isStopped, $dayInputs, &$metricWriteBuffer): array {
                            return $this->buildSnapshot(
                                $run,
                                $gameId,
                                $isStopped,
                                $dayInputs,
                                $metricWriteBuffer,
                            );
                        });
                        $totals['team_context_count'] += $counts['team_context_count'];
                        $totals['player_context_count'] += $counts['player_context_count'];
                        $totals['games'][$gameId] = $counts;
                    } catch (NhlPregameContextDataUnavailable) {
                        $totals['blocked_games']++;
                        $totals['games'][$gameId] = ['blocked' => true];
                    }
                }
                $this->flushNormalizedMetricWrites($metricWriteBuffer);
            });
        }

        return $totals;
    }

    /** @return array<int,array{game:object|null,participants:Collection,players:array<int,array{history:Collection,exposure:Collection,attempts:Collection}>}> */
    private function loadDayPlayerInputs(array $gameIds, ?array $selectedPlayerIds = null): array
    {
        $games = DB::table('nhl_games')->whereIn('nhl_game_id', $gameIds)->get()->keyBy('nhl_game_id');
        $participantsByGame = DB::table('nhl_game_summaries')
            ->whereIn('nhl_game_id', $gameIds)
            ->when($selectedPlayerIds !== null, fn ($query) => $query->whereIn('nhl_player_id', $selectedPlayerIds))
            ->whereIn('nhl_team_id', $games->flatMap(fn (object $game): array => [(int) $game->home_team_id, (int) $game->away_team_id])->unique()->values())
            ->orderBy('nhl_game_id')->orderBy('nhl_team_id')->get(['nhl_game_id', 'nhl_player_id', 'nhl_team_id'])
            ->groupBy('nhl_game_id');
        $playerIds = $participantsByGame->flatten(1)->pluck('nhl_player_id')->map(fn (mixed $id): int => (int) $id)->unique()->values();
        if ($playerIds->isEmpty()) {
            return $games->mapWithKeys(fn (object $game, int|string $id): array => [$id => [
                'game' => $game, 'participants' => collect(), 'players' => [],
            ]])->all();
        }

        [$histories, $exposures, $attempts] = $this->loadPlayerResultInputs($playerIds->all(), now()->parse($games->max('start_time_utc')));

        return $games->mapWithKeys(function (object $game, int|string $gameId) use ($participantsByGame, $histories, $exposures, $attempts): array {
            $participants = $participantsByGame->get($gameId, collect());
            $cutoff = now()->parse($game->start_time_utc);
            $players = [];
            foreach ($participants as $participant) {
                $playerId = (int) $participant->nhl_player_id;
                $history = ($histories->get($playerId, collect()))
                    ->filter(fn (object $row): bool => now()->parse($row->start_time_utc)->lt($cutoff))->values();
                $ids = $history->pluck('nhl_game_id')->all();
                $players[$playerId] = [
                    'history' => $history,
                    'exposure' => ($exposures->get($playerId, collect()))->only($ids),
                    'attempts' => ($attempts->get($playerId, collect()))->only($ids),
                ];
            }

            return [$gameId => ['game' => $game, 'participants' => $participants, 'players' => $players]];
        })->all();
    }

    /** Load only past result columns for one bounded player group. */
    private function loadPlayerResultInputs(array $playerIds, CarbonInterface $cutoff): array
    {
        $histories = DB::table('nhl_game_summaries as summaries')
            ->join('nhl_games as games', 'games.nhl_game_id', '=', 'summaries.nhl_game_id')
            ->whereIn('summaries.nhl_player_id', $playerIds)
            ->where('summaries.toi', '>', 0)
            ->where('games.game_type', 2)->whereIn('games.game_state', ['OFF', 'FINAL'])
            ->where('games.start_time_utc', '<', $cutoff)
            ->orderByDesc('games.start_time_utc')->orderByDesc('games.nhl_game_id')
            ->select(['summaries.nhl_player_id', 'summaries.nhl_team_id', 'summaries.nhl_game_id',
                'summaries.toi', 'summaries.sat', 'summaries.sog', 'summaries.g', 'summaries.a', 'summaries.pts',
                'games.season_id', 'games.home_team_id', 'games.away_team_id', 'games.start_time_utc'])
            ->selectRaw('EXISTS (SELECT 1 FROM nhl_shot_attempts_facts facts WHERE facts.nhl_game_id = summaries.nhl_game_id AND facts.is_shot_attempt = true) as has_shot_facts')
            ->get()->groupBy('nhl_player_id');
        $historyGameIds = $histories->flatten(1)->pluck('nhl_game_id')->unique()->values();
        $exposures = DB::table('nhl_player_game_strength_summaries')
            ->whereIn('nhl_player_id', $playerIds)->whereIn('nhl_game_id', $historyGameIds)
            ->get(['nhl_player_id', 'nhl_game_id', 'strength', 'toi', 'satf', 'sata', 'sf', 'sa', 'gf', 'ga', 'individual_pts'])
            ->groupBy('nhl_player_id')->map(fn (Collection $rows): Collection => $rows->groupBy('nhl_game_id'));
        $attempts = DB::table('nhl_shot_attempts_facts as facts')
            ->whereIn('facts.shooter_player_id', $playerIds)->whereIn('facts.nhl_game_id', $historyGameIds)
            ->where('facts.is_shot_attempt', true)
            ->where(fn ($query) => $query->whereNull('facts.period_type')->orWhere('facts.period_type', '<>', 'SO'))
            ->select('facts.shooter_player_id', 'facts.nhl_game_id')
            ->selectRaw("CASE WHEN UPPER(facts.strength) IN ('EV', 'PP', 'PK') THEN UPPER(facts.strength) ELSE UPPER(facts.strength_bucket) END as strength")
            ->selectRaw('COUNT(*) as source_sat')
            ->selectRaw('COUNT(*) as sat')
            ->selectRaw('SUM(CASE WHEN facts.is_shot_on_goal THEN 1 ELSE 0 END) as sog')
            ->selectRaw('SUM(CASE WHEN facts.is_goal THEN 1 ELSE 0 END) as goals')
            ->groupBy('facts.shooter_player_id', 'facts.nhl_game_id')->groupByRaw("CASE WHEN UPPER(facts.strength) IN ('EV', 'PP', 'PK') THEN UPPER(facts.strength) ELSE UPPER(facts.strength_bucket) END")
            ->get()->groupBy('shooter_player_id')->map(fn (Collection $rows): Collection => $rows->groupBy('nhl_game_id'));

        return [$histories, $exposures, $attempts];
    }

    /** Rebuild one game atomically so corrected facts replace the prior model snapshot. */
    private function buildSnapshot(NhlPregameContextRun $run, int $gameId, ?Closure $isStopped, ?array $dayInputs = null, ?array &$metricWriteBuffer = null): array
    {
        $this->assertRunNotStopped($isStopped);
        $game = $dayInputs['game'] ?? DB::table('nhl_games')->where('nhl_game_id', $gameId)->first();
        if ($game === null || $game->start_time_utc === null || $game->home_team_id === null || $game->away_team_id === null) {
            throw new NhlPregameContextDataUnavailable('The game is missing schedule context.');
        }

        $cutoff = now()->parse($game->start_time_utc);
        $training = array_map('strval', data_get($run->options, 'train_season_ids', []));
        $modelRunId = $run->model_run_id;
        $participants = $dayInputs['participants'] ?? DB::table('nhl_game_summaries')
            ->where('nhl_game_id', $gameId)
            ->whereIn('nhl_team_id', [$game->away_team_id, $game->home_team_id])
            ->orderBy('nhl_team_id')
            ->get(['nhl_player_id', 'nhl_team_id']);

        if ($participants->isEmpty()) {
            throw new NhlPregameContextDataUnavailable('The game has no completed player summaries.');
        }

        $expectedParticipants = $participants->mapWithKeys(fn (object $participant): array => [
            sprintf('%d:%d', $participant->nhl_player_id, $participant->nhl_team_id) => true,
        ]);
        $existingPlayerContexts = DB::table('nhl_player_game_pregame_contexts')
            ->where('model_run_id', $modelRunId)->where('nhl_game_id', $gameId)
            ->where('context_version', self::VERSION)->get(['id', 'nhl_player_id', 'nhl_team_id']);
        $stalePlayerContextIds = $existingPlayerContexts
            ->reject(fn (object $context): bool => $expectedParticipants->has(
                sprintf('%d:%d', $context->nhl_player_id, $context->nhl_team_id)
            ))
            ->pluck('id')->all();
        if ($stalePlayerContextIds !== []) {
            $this->assertRunNotStopped($isStopped);
            DB::table('nhl_player_game_pregame_contexts')->whereIn('id', $stalePlayerContextIds)->delete();
        }

        $teamContextIds = [];
        foreach ([
            ['team_id' => (int) $game->away_team_id, 'opponent_id' => (int) $game->home_team_id, 'venue' => 'away'],
            ['team_id' => (int) $game->home_team_id, 'opponent_id' => (int) $game->away_team_id, 'venue' => 'home'],
        ] as $side) {
            $this->assertRunNotStopped($isStopped);
            $teamContextIds[$side['team_id']] = $this->storeTeamContext($run, $game, $cutoff, $side, $training, $isStopped, $metricWriteBuffer);
        }

        $playerCount = 0;
        $playerInputs = $dayInputs['players'] ?? [];
        foreach ($participants->values() as $participantIndex => $participant) {
            $this->assertRunNotStopped($isStopped);
            if (($dayInputs['batch_players'] ?? false) && $participantIndex % 8 === 0) {
                // Release the previous histories before reading the next bounded player batch.
                $playerInputs = [];
                $playerInputs = $this->loadDayPlayerInputs(
                    [$gameId],
                    $participants->values()->slice($participantIndex, 8)->pluck('nhl_player_id')->all(),
                )[$gameId]['players'] ?? [];
            }
            $teamId = (int) $participant->nhl_team_id;
            $teamContextId = $teamContextIds[$teamId] ?? null;
            if ($teamContextId === null) {
                throw new RuntimeException('Each player pregame context requires its same-game team context.');
            }
            $opponentId = $teamId === (int) $game->away_team_id ? (int) $game->home_team_id : (int) $game->away_team_id;
            $venue = $teamId === (int) $game->away_team_id ? 'away' : 'home';
            $metrics = $this->playerMetrics(
                (int) $participant->nhl_player_id,
                $teamId,
                $opponentId,
                $venue,
                $cutoff,
                (string) $game->season_id,
                $training,
                null,
                $playerInputs[(int) $participant->nhl_player_id] ?? null,
            );
            $this->assertRunNotStopped($isStopped);

            DB::table('nhl_player_game_pregame_contexts')->upsert([[
                'run_id' => $run->id,
                'model_run_id' => $modelRunId,
                'team_context_id' => $teamContextId,
                'nhl_game_id' => $gameId,
                'nhl_player_id' => (int) $participant->nhl_player_id,
                'nhl_team_id' => $teamId,
                'opponent_team_id' => $opponentId,
                'game_date' => $game->game_date,
                'source_cutoff_at' => $cutoff,
                'venue' => $venue,
                'participant_source' => 'completed_game_summary',
                'context_version' => self::VERSION,
                'metrics' => json_encode($metrics, JSON_THROW_ON_ERROR),
                'ranks' => json_encode([], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]], ['model_run_id', 'nhl_game_id', 'nhl_player_id', 'nhl_team_id', 'context_version'], [
                'run_id', 'team_context_id', 'opponent_team_id', 'game_date', 'source_cutoff_at', 'venue', 'participant_source', 'metrics', 'ranks', 'updated_at',
            ]);
            $playerContextId = (int) DB::table('nhl_player_game_pregame_contexts')
                ->where('model_run_id', $modelRunId)->where('nhl_game_id', $gameId)
                ->where('nhl_player_id', (int) $participant->nhl_player_id)->where('nhl_team_id', $teamId)
                ->where('context_version', self::VERSION)->value('id');
            $this->storeNormalizedMetrics('nhl_player_game_pregame_context_metrics', 'player_context_id', $playerContextId, $metrics, [], $metricWriteBuffer);
            $playerCount++;
        }

        $this->assertRunNotStopped($isStopped);

        return ['team_context_count' => count($teamContextIds), 'player_context_count' => $playerCount];
    }

    /** Abort the enclosing transaction when a run-specific stop signal is present. */
    private function assertRunNotStopped(?Closure $isStopped): void
    {
        if ($isStopped !== null && $isStopped()) {
            throw new NhlPregameContextRunStopped('The Pregame context run was stopped.');
        }
    }

    /**
     * @param array{team_id:int,opponent_id:int,venue:string} $side
     * @param Closure|null $isStopped Optional run-scoped cancellation check.
     * @return int Persisted team context identifier.
     */
    private function storeTeamContext(
        NhlPregameContextRun $run,
        object $game,
        CarbonInterface $cutoff,
        array $side,
        array $training,
        ?Closure $isStopped,
        ?array &$metricWriteBuffer,
    ): int {
        [$teamMetrics, $opponent, $schedule] = $this->teamResultEvidence($game, $cutoff, $side, $training, $isStopped);

        DB::table('nhl_team_game_pregame_contexts')->upsert([[
            'run_id' => $run->id,
            'model_run_id' => $run->model_run_id,
            'nhl_game_id' => $game->nhl_game_id,
            'nhl_team_id' => $side['team_id'],
            'opponent_team_id' => $side['opponent_id'],
            'game_date' => $game->game_date,
            'source_cutoff_at' => $cutoff,
            'venue' => $side['venue'],
            'context_version' => self::VERSION,
            'schedule_metrics' => json_encode($schedule, JSON_THROW_ON_ERROR),
            'opponent_metrics' => json_encode($opponent, JSON_THROW_ON_ERROR),
            'metrics' => json_encode($teamMetrics, JSON_THROW_ON_ERROR),
            'ranks' => json_encode([], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]], ['model_run_id', 'nhl_game_id', 'nhl_team_id', 'context_version'], [
            'run_id', 'opponent_team_id', 'game_date', 'source_cutoff_at', 'venue', 'schedule_metrics', 'opponent_metrics', 'metrics', 'ranks', 'updated_at',
        ]);

        $this->assertRunNotStopped($isStopped);
        $contextId = (int) DB::table('nhl_team_game_pregame_contexts')
            ->where('model_run_id', $run->model_run_id)->where('nhl_game_id', $game->nhl_game_id)->where('nhl_team_id', $side['team_id'])
            ->where('context_version', self::VERSION)->value('id');
        $this->storeNormalizedMetrics('nhl_team_game_pregame_context_metrics', 'team_context_id', $contextId, $teamMetrics, [
            'opponent' => $opponent,
            'schedule' => $schedule,
        ], $metricWriteBuffer);

        return $contextId;
    }

    /** Calculate team evidence from results and schedule references without saving context. */
    private function teamResultEvidence(object $game, CarbonInterface $cutoff, array $side, array $training, ?Closure $isStopped): array
    {
        $history = $this->teamHistory($side['team_id'], $cutoff);
        $this->assertRunNotStopped($isStopped);
        $headToHead = $history->filter(fn (object $row): bool => (int) $row->opponent_team_id === $side['opponent_id']);
        $lastGame = $history->first();
        $travel = $this->travelMetrics($side['team_id'], $game, $lastGame, $history);
        $daysSinceLastGame = $lastGame === null
            ? null
            : (int) now()->parse($lastGame->game_date)->startOfDay()
                ->diffInDays(now()->parse($game->game_date)->startOfDay());
        $opponentLastGame = DB::table('nhl_games')->where('game_type', 2)->whereIn('game_state', ['OFF', 'FINAL'])
            ->where('start_time_utc', '<', $cutoff)
            ->where(fn ($query) => $query->where('home_team_id', $side['opponent_id'])->orWhere('away_team_id', $side['opponent_id']))
            ->orderByDesc('start_time_utc')->orderByDesc('nhl_game_id')->first(['game_date']);
        $opponentDaysSinceLastGame = $opponentLastGame === null ? null
            : (int) now()->parse($opponentLastGame->game_date)->startOfDay()
                ->diffInDays(now()->parse($game->game_date)->startOfDay());
        $targetDate = now()->parse($game->game_date)->startOfDay();
        $priorSixDays = $history->filter(fn (object $row): bool => now()->parse($row->game_date)->startOfDay()
            ->betweenIncluded($targetDate->copy()->subDays(6), $targetDate->copy()->subDay()));
        $gamesIncludingTarget = $priorSixDays->count() + 1;
        $schedule = [
            'days_rest' => $daysSinceLastGame === null ? null : max(0, $daysSinceLastGame - 1),
            'opponent_days_rest' => $opponentDaysSinceLastGame === null ? null : max(0, $opponentDaysSinceLastGame - 1),
            'back_to_back' => $daysSinceLastGame === 1,
            'games_last_4_days' => $history->filter(fn (object $row): bool => now()->parse($row->start_time_utc)->gte($cutoff->copy()->subDays(4)))->count(),
            'games_last_7_days' => $history->filter(fn (object $row): bool => now()->parse($row->start_time_utc)->gte($cutoff->copy()->subDays(7)))->count(),
            'three_in_four' => $history->filter(fn (object $row): bool => now()->parse($row->start_time_utc)->gte($cutoff->copy()->subDays(4)))->count() >= 2,
            'games_including_target_last_7_days' => $gamesIncludingTarget,
            'third_in_seven' => $gamesIncludingTarget === 3,
            'fourth_in_seven' => $gamesIncludingTarget === 4,
            'back_to_back_fourth_in_seven' => $daysSinceLastGame === 1 && $gamesIncludingTarget === 4,
            'days_on_road' => $this->daysOnRoad($side['venue'], $game, $history),
            ...$travel,
        ];
        $opponent = $this->headToHeadMetrics($headToHead, (string) $game->season_id, $training);
        $teamMetrics = $this->teamMetrics($history, (string) $game->season_id, $training);
        $this->assertRunNotStopped($isStopped);

        return [$teamMetrics, $opponent, $schedule];
    }

    private function teamHistory(int $teamId, CarbonInterface $cutoff): Collection
    {
        $history = DB::table('nhl_games')
            ->where('game_type', 2)->whereIn('game_state', ['OFF', 'FINAL'])
            ->where('start_time_utc', '<', $cutoff)
            ->where(fn ($query) => $query->where('home_team_id', $teamId)->orWhere('away_team_id', $teamId))
            ->orderByDesc('start_time_utc')->orderByDesc('nhl_game_id')
            ->get(['nhl_game_id', 'season_id', 'home_team_id', 'away_team_id', 'home_team_score', 'away_team_score', 'home_team_sog', 'away_team_sog', 'start_time_utc', 'game_date'])
            ->map(function (object $row) use ($teamId): object {
                $home = (int) $row->home_team_id === $teamId;
                $row->is_home = $home;
                $row->opponent_team_id = $home ? (int) $row->away_team_id : (int) $row->home_team_id;
                $row->team_score = $home ? $row->home_team_score : $row->away_team_score;
                $row->opponent_score = $home ? $row->away_team_score : $row->home_team_score;
                $row->sog_for = $home ? $row->home_team_sog : $row->away_team_sog;
                $row->sog_against = $home ? $row->away_team_sog : $row->home_team_sog;

                return $row;
            });

        $attempts = DB::table('nhl_shot_attempts_facts')
            ->whereIn('nhl_game_id', $history->pluck('nhl_game_id'))
            ->where('is_shot_attempt', true)
            ->where(fn ($query) => $query->whereNull('period_type')->orWhere('period_type', '<>', 'SO'))
            ->select('nhl_game_id', 'team_id', DB::raw('COUNT(*) as sat'))
            ->groupBy('nhl_game_id', 'team_id')->get()->groupBy('nhl_game_id');

        return $history->map(function (object $row) use ($attempts, $teamId): object {
            $gameAttempts = $attempts->get($row->nhl_game_id, collect());
            $for = $gameAttempts->firstWhere('team_id', $teamId);
            $against = $gameAttempts->firstWhere('team_id', $row->opponent_team_id);
            $row->has_attempt_facts = $for !== null && $against !== null;
            $row->sat_for = $for === null ? null : (int) $for->sat;
            $row->sat_against = $against === null ? null : (int) $against->sat;

            return $row;
        });
    }

    /** @return array<string,array<string,int|float|null>> */
    private function teamMetrics(Collection $history, string $seasonId, array $training = []): array
    {
        $lastSeasonId = (string) ((int) $seasonId - 10001);
        $windows = [
            'last_game' => $history->take(1),
            'last_5' => $history->take(5),
            'last_10' => $history->take(10),
            'last_20' => $history->take(20),
            'last_season' => $history->where('season_id', $lastSeasonId),
            'season_to_date' => $history->where('season_id', $seasonId),
            'train' => $history->filter(fn (object $row): bool => in_array((string) $row->season_id, $training, true)),
        ];

        return collect($windows)->map(fn (Collection $rows): array => $this->teamWindowMetrics($rows))->all();
    }

    /** @return array<string,int|float|null> */
    private function teamWindowMetrics(Collection $rows): array
    {
        $games = $rows->count();
        $scoreComplete = $games > 0 && $rows->every(fn (object $row): bool => $row->team_score !== null && $row->opponent_score !== null);
        $sogComplete = $games > 0 && $rows->every(fn (object $row): bool => $row->sog_for !== null && $row->sog_against !== null);
        $gf = $scoreComplete ? (int) $rows->sum('team_score') : null;
        $ga = $scoreComplete ? (int) $rows->sum('opponent_score') : null;
        $satComplete = $games > 0 && $rows->every(fn (object $row): bool => $row->has_attempt_facts);
        $satFor = $satComplete ? (int) $rows->sum('sat_for') : null;
        $satAgainst = $satComplete ? (int) $rows->sum('sat_against') : null;
        $sogFor = $sogComplete ? (int) $rows->sum('sog_for') : null;
        $sogAgainst = $sogComplete ? (int) $rows->sum('sog_against') : null;
        $wins = $scoreComplete
            ? $rows->filter(fn (object $row): bool => (int) $row->team_score > (int) $row->opponent_score)->count()
            : null;

        return [
            'games' => $games,
            'wins' => $wins,
            'win_pct' => $wins !== null ? round(100 * $wins / $games, 4) : null,
            'goals_for' => $gf,
            'goals_against' => $ga,
            'goals_for_per_game' => $gf !== null ? round($gf / $games, 4) : null,
            'goals_against_per_game' => $ga !== null ? round($ga / $games, 4) : null,
            'sat_for' => $satFor,
            'sat_against' => $satAgainst,
            'sat_for_per_game' => $satFor !== null ? round($satFor / $games, 4) : null,
            'sat_against_per_game' => $satAgainst !== null ? round($satAgainst / $games, 4) : null,
            'corsi_pct' => $satFor !== null && $satAgainst !== null && ($satFor + $satAgainst) > 0
                ? round(100 * $satFor / ($satFor + $satAgainst), 4)
                : null,
            'sog_for' => $sogFor,
            'sog_against' => $sogAgainst,
            'sog_per_sat_pct' => $satFor !== null && $sogFor !== null && $satFor > 0 ? round(100 * $sogFor / $satFor, 4) : null,
            'shooting_pct' => $sogFor !== null && $gf !== null && $sogFor > 0 ? round(100 * $gf / $sogFor, 4) : null,
            'save_pct' => $sogAgainst !== null && $ga !== null && $sogAgainst > 0 ? round(100 * (1 - ($ga / $sogAgainst)), 4) : null,
            'pdo' => $sogFor === null || $sogAgainst === null || $gf === null || $ga === null || $sogFor === 0 || $sogAgainst === 0
                ? null
                : round((100 * $gf / $sogFor) + (100 * (1 - ($ga / $sogAgainst))), 4),
        ];
    }

    /** @return array<string,mixed> */
    private function headToHeadMetrics(Collection $rows, string $seasonId, array $training = []): array
    {
        return [
            'last_game' => $this->teamWindowMetrics($rows->take(1)),
            'last_5' => $this->teamWindowMetrics($rows->take(5)),
            'last_10' => $this->teamWindowMetrics($rows->take(10)),
            'last_20' => $this->teamWindowMetrics($rows->take(20)),
            'last_season' => $this->teamWindowMetrics($rows->filter(fn (object $row): bool => (string) $row->season_id === (string) ((int) $seasonId - 10001))),
            'train' => $this->teamWindowMetrics($rows->filter(fn (object $row): bool => in_array((string) $row->season_id, $training, true))),
        ];
    }

    /** Count consecutive calendar days in the current away sequence, including the target date. */
    private function daysOnRoad(string $venue, object $game, Collection $history): ?int
    {
        if ($venue !== 'away') {
            return 0;
        }
        if ($history->isEmpty()) {
            return null;
        }

        $firstRoadDate = now()->parse($game->game_date)->startOfDay();
        $homeAnchorFound = false;
        foreach ($history as $appearance) {
            if ($appearance->is_home) {
                $homeAnchorFound = true;
                break;
            }

            $firstRoadDate = now()->parse($appearance->game_date)->startOfDay();
        }
        if (! $homeAnchorFound) {
            return null;
        }

        return (int) $firstRoadDate->diffInDays(now()->parse($game->game_date)->startOfDay()) + 1;
    }

    /** @return array<string,mixed> */
    private function playerMetrics(int $playerId, int $teamId, int $opponentId, string $venue, CarbonInterface $cutoff, string $seasonId, array $training = [], ?array $bucketFilter = null, ?array $preloaded = null): array
    {
        $history = $preloaded['history'] ?? DB::table('nhl_game_summaries as summaries')
            ->join('nhl_games as games', 'games.nhl_game_id', '=', 'summaries.nhl_game_id')
            ->where('summaries.nhl_player_id', $playerId)
            ->where('summaries.toi', '>', 0)
            ->where('games.game_type', 2)->whereIn('games.game_state', ['OFF', 'FINAL'])
            ->where('games.start_time_utc', '<', $cutoff)->orderByDesc('games.start_time_utc')->orderByDesc('games.nhl_game_id')
            ->select(['summaries.*', 'games.season_id', 'games.home_team_id', 'games.away_team_id', 'games.start_time_utc'])
            ->selectRaw('EXISTS (SELECT 1 FROM nhl_shot_attempts_facts facts WHERE facts.nhl_game_id = summaries.nhl_game_id AND facts.is_shot_attempt = true) as has_shot_facts')
            ->get();

        $gameIds = $history->pluck('nhl_game_id')->all();
        $exposure = $preloaded['exposure'] ?? DB::table('nhl_player_game_strength_summaries')
            ->where('nhl_player_id', $playerId)->whereIn('nhl_game_id', $gameIds)
            ->get()->groupBy('nhl_game_id');
        $attempts = $preloaded['attempts'] ?? $this->individualAttemptCounts($playerId, $gameIds, $bucketFilter);

        $isOpponent = fn (object $row): bool => ((int) $row->home_team_id === (int) $row->nhl_team_id ? (int) $row->away_team_id : (int) $row->home_team_id) === $opponentId;
        $isVenue = fn (object $row): bool => $venue === 'home' ? (int) $row->home_team_id === (int) $row->nhl_team_id : (int) $row->away_team_id === (int) $row->nhl_team_id;

        return [
            'all' => $bucketFilter !== null
                ? $this->strengthMetrics($history, $exposure, $attempts, $opponentId, $venue, $seasonId, 'ALL', $training)
                : [
                'last_game' => $this->summaryMetrics($history->take(1)),
                ...($training === [] ? [] : ['train' => $this->summaryMetrics($history->filter(fn ($row) => in_array((string) $row->season_id, $training, true)))]),
                ...($training === [] ? [] : ['venue_train' => $this->summaryMetrics($history->filter(fn ($row) => in_array((string) $row->season_id, $training, true))->filter($isVenue))]),
                ...($training === [] ? [] : ['opponent_train' => $this->summaryMetrics($history->filter(fn ($row) => in_array((string) $row->season_id, $training, true))->filter($isOpponent))]),
                'last_season' => $this->summaryMetrics($history->where('season_id', (string) ((int) $seasonId - 10001))),
                'venue_last_season' => $this->summaryMetrics($history->where('season_id', (string) ((int) $seasonId - 10001))->filter($isVenue)),
                'opponent_last_season' => $this->summaryMetrics($history->where('season_id', (string) ((int) $seasonId - 10001))->filter($isOpponent)),
                'last_5' => $this->summaryMetrics($history->take(5)),
                'last_10' => $this->summaryMetrics($history->take(10)),
                'last_20' => $this->summaryMetrics($history->take(20)),
                'season_to_date' => $this->summaryMetrics($history->filter(fn (object $row): bool => (string) $row->season_id === $seasonId)),
                ...($training === [] ? [] : collect(range(1, 4))->mapWithKeys(fn (int $quarter): array => [
                    'q'.$quarter.'_to_date' => $this->summaryMetrics($history->where('season_id', $seasonId)->reverse()->values()->slice(($quarter - 1) * 20, $quarter === 4 ? 22 : 20)),
                ])->all()),
                'venue_season_to_date' => $this->summaryMetrics($history->filter(fn (object $row): bool => (string) $row->season_id === $seasonId)->filter($isVenue)),
                'opponent_last_10' => $this->summaryMetrics($history->filter($isOpponent)->take(10)),
                'opponent_last_5' => $this->summaryMetrics($history->filter($isOpponent)->take(5)),
                'opponent_last_20' => $this->summaryMetrics($history->filter($isOpponent)->take(20)),
                'opponent_season_to_date' => $this->summaryMetrics($history->filter(fn (object $row): bool => (string) $row->season_id === $seasonId)->filter($isOpponent)),
                'opponent_last_season' => $this->summaryMetrics($history->filter(fn (object $row): bool => (string) $row->season_id === (string) ((int) $seasonId - 10001))->filter($isOpponent)),
            ],
            'individual_strength_version' => 1,
            'strength' => collect(['EV', 'PP', 'PK'])->mapWithKeys(fn (string $strength): array => [
                $strength => $this->strengthMetrics($history, $exposure, $attempts, $opponentId, $venue, $seasonId, $strength, $training),
            ])->all(),
        ];
    }

    /** Read one player's evaluation context without writing shared snapshots or using target outcomes. */
    public function evaluationPlayerMetrics(int $playerId, int $teamId, int $opponentId, string $venue, CarbonInterface $cutoff, string $seasonId, array $training, ?array $bucketFilter = null): array
    {
        return $this->playerMetrics($playerId, $teamId, $opponentId, $venue, $cutoff, $seasonId, array_map('strval', $training), $bucketFilter);
    }

    /** @return array<string,int|float|null> */
    private function summaryMetrics(Collection $rows): array
    {
        $games = $rows->count();
        $toi = (int) $rows->sum('toi');
        $sat = (int) $rows->sum('sat');
        $sog = (int) $rows->sum('sog');
        $goals = (int) $rows->sum('g');

        return [
            'games' => $games, 'toi_seconds' => $toi, 'sat' => $sat, 'sog' => $sog, 'goals' => $goals,
            'assists' => (int) $rows->sum('a'), 'points' => (int) $rows->sum('pts'),
            'sat_per_60' => $toi > 0 ? round($sat * 3600 / $toi, 4) : null,
            'sog_per_60' => $toi > 0 ? round($sog * 3600 / $toi, 4) : null,
            'goals_per_60' => $toi > 0 ? round($goals * 3600 / $toi, 4) : null,
            'shooting_pct' => $sog > 0 ? round(100 * $goals / $sog, 4) : null,
        ];
    }

    /** Persist explicit metric/window rows while retaining the JSON snapshot for compatibility. */
    private function storeNormalizedMetrics(string $table, string $foreignKey, int $contextId, array $metrics, array $supplemental, ?array &$writeBuffer = null): void
    {
        $rows = [];
        $timestamp = now()->toDateTimeString();
        $sources = $this->metricSources($metrics, $supplemental, $foreignKey === 'player_context_id');

        foreach ($sources as $window => $strengths) {
            foreach ($strengths as $strength => $values) {
                $sampleGames = isset($values['games']) ? (int) $values['games'] : null;
                $exposure = isset($values['toi_seconds']) ? (float) $values['toi_seconds'] : null;
                foreach ($values as $metric => $value) {
                    if (is_bool($value)) {
                        $value = (int) $value;
                    }
                    if (! is_numeric($value) && $value !== null) {
                        continue;
                    }
                    $rows[] = [
                        $foreignKey => $contextId,
                        'metric_key' => (string) $metric,
                        'window_key' => $window,
                        'strength' => $strength,
                        'measure_key' => 'value',
                        'value' => $value,
                        'sample_games' => $sampleGames,
                        'exposure_seconds' => $exposure,
                        'league_average' => null,
                        'signed_difference' => null,
                        'absolute_difference' => null,
                        'percentage_difference' => null,
                        'league_rank' => null,
                        'rank_population' => null,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ];
                }
            }
        }

        if ($writeBuffer !== null) {
            foreach ($rows as $row) {
                $writeBuffer[] = $row;
            }
            if (count($writeBuffer) >= 2000) {
                $this->flushNormalizedMetricWrites($writeBuffer);
                $writeBuffer = [];
            }

            return;
        }

        $rowKey = fn (object|array $row): string => implode('|', [
            (string) data_get($row, 'metric_key'),
            (string) data_get($row, 'window_key'),
            (string) data_get($row, 'strength'),
            (string) data_get($row, 'measure_key'),
        ]);
        $expectedKeys = collect($rows)->map(fn (array $row): string => $rowKey($row))->flip();
        $existingRows = DB::table($table)->where($foreignKey, $contextId)
            ->get(['id', 'metric_key', 'window_key', 'strength', 'measure_key']);
        $staleMetricIds = $existingRows
            ->reject(fn (object $row): bool => $expectedKeys->has($rowKey($row)))
            ->pluck('id')->all();
        if ($staleMetricIds !== []) {
            DB::table($table)->whereIn('id', $staleMetricIds)->delete();
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table($table)->upsert(
                $chunk,
                [$foreignKey, 'metric_key', 'window_key', 'strength', 'measure_key'],
                ['value', 'sample_games', 'exposure_seconds', 'league_average', 'signed_difference',
                    'absolute_difference', 'percentage_difference', 'league_rank', 'rank_population', 'updated_at']
            );
        }
    }

    /** Normalize the same metric/window names for context storage and result-based league peers. */
    private function metricSources(array $metrics, array $supplemental, bool $isPlayer): array
    {
        $sources = [];

        if ($isPlayer) {
            foreach (($metrics['all'] ?? []) as $window => $values) {
                if (is_array($values)) {
                    $sources[$this->canonicalWindowKey((string) $window)] = ['ALL' => $values];
                }
            }
            foreach (($metrics['strength'] ?? []) as $strength => $windows) {
                foreach ($windows as $window => $values) {
                    if (is_array($values)) {
                        $sources[$this->canonicalWindowKey((string) $window)][(string) $strength] = $values;
                    }
                }
            }
        } else {
            foreach ($metrics as $window => $values) {
                if (is_array($values)) {
                    $sources[$this->canonicalWindowKey((string) $window)] = ['ALL' => $values];
                }
            }
            foreach (($supplemental['opponent'] ?? []) as $window => $values) {
                if (is_array($values)) {
                    $sources[$this->canonicalWindowKey('opponent_'.(string) $window)] = ['ALL' => $values];
                }
            }
            if (is_array($supplemental['schedule'] ?? null)) {
                $sources['pregame'] = ['ALL' => $supplemental['schedule']];
            }
        }

        return $sources;
    }

    /** Remove stale metric rows and upsert the buffered game-day metric rows in bounded chunks. */
    private function flushNormalizedMetricWrites(array $rows): void
    {
        foreach ([
            ['table' => 'nhl_team_game_pregame_context_metrics', 'foreign_key' => 'team_context_id'],
            ['table' => 'nhl_player_game_pregame_context_metrics', 'foreign_key' => 'player_context_id'],
        ] as $config) {
            $entityRows = array_values(array_filter($rows, fn (array $row): bool => array_key_exists($config['foreign_key'], $row)));
            if ($entityRows === []) {
                continue;
            }

            $expected = [];
            foreach ($entityRows as $row) {
                $id = (int) $row[$config['foreign_key']];
                $key = implode('|', [$row['metric_key'], $row['window_key'], $row['strength'], $row['measure_key']]);
                $expected[$id][$key] = true;
            }
            $contextIds = array_keys($expected);
            foreach (array_chunk($contextIds, 500) as $idChunk) {
                $existingRows = DB::table($config['table'])->whereIn($config['foreign_key'], $idChunk)
                    ->get(['id', $config['foreign_key'], 'metric_key', 'window_key', 'strength', 'measure_key']);
                $staleIds = $existingRows->reject(function (object $row) use ($config, $expected): bool {
                    $key = implode('|', [$row->metric_key, $row->window_key, $row->strength, $row->measure_key]);

                    return isset($expected[(int) $row->{$config['foreign_key']}][$key]);
                })->pluck('id')->all();
                foreach (array_chunk($staleIds, 500) as $staleChunk) {
                    DB::table($config['table'])->whereIn('id', $staleChunk)->delete();
                }
            }

            foreach (array_chunk($entityRows, 500) as $chunk) {
                DB::table($config['table'])->upsert(
                    $chunk,
                    [$config['foreign_key'], 'metric_key', 'window_key', 'strength', 'measure_key'],
                    ['value', 'sample_games', 'exposure_seconds', 'league_average', 'signed_difference',
                        'absolute_difference', 'percentage_difference', 'league_rank', 'rank_population', 'updated_at']
                );
            }
        }
    }

    private function canonicalWindowKey(string $window): string
    {
        return str_replace(
            ['opponent_last_', 'last_season', 'last_game', 'last_', 'season_to_date'],
            ['opponent_l', 'lseason', 'last_game', 'l', 'season_to_date'],
            $window
        );
    }

    /** @return array<string,array<string,int|float|null>> */
    private function strengthMetrics(Collection $history, Collection $exposure, Collection $attempts, int $opponentId, string $venue, string $seasonId, string $strength, array $training = []): array
    {
        // Choose appearances first: a game with no PP/PK time still belongs in L5/L10/L20.
        $history = $history->map(function (object $appearance) use ($exposure, $attempts, $strength): object {
            $strengthRows = $exposure->get($appearance->nhl_game_id, collect());
            $strengthRow = $strength === 'ALL' ? $appearance : $strengthRows->firstWhere('strength', $strength);
            $facts = $attempts->get($appearance->nhl_game_id, collect());
            $counts = $strength === 'ALL' ? (object) ['sat' => $facts->sum('sat'), 'source_sat' => $facts->sum('source_sat'), 'sog' => $facts->sum('sog'), 'goals' => $facts->sum('goals')]
                : $facts->firstWhere('strength', $strength);
            $toi = (int) ($strengthRow->toi ?? 0);
            $available = (bool) $appearance->has_shot_facts && ($strength === 'ALL' || $strengthRows->isNotEmpty())
                && ($strength === 'ALL' || ! $facts->contains(fn (object $fact): bool => ! in_array($fact->strength, ['EV', 'PP', 'PK'], true)))
                && ($toi > 0 || (int) ($counts->source_sat ?? 0) === 0);

            return (object) [
                'nhl_team_id' => $appearance->nhl_team_id,
                'home_team_id' => $appearance->home_team_id,
                'away_team_id' => $appearance->away_team_id,
                'season_id' => $appearance->season_id,
                'exposure' => $strengthRow,
                'available' => $available,
                'toi' => $toi,
                'sat' => (int) ($counts->sat ?? 0),
                'sog' => (int) ($counts->sog ?? 0),
                'goals' => (int) ($counts->goals ?? 0),
            ];
        });
        $isOpponent = fn (object $row): bool => ((int) $row->home_team_id === (int) $row->nhl_team_id ? (int) $row->away_team_id : (int) $row->home_team_id) === $opponentId;
        $isVenue = fn (object $row): bool => $venue === 'home' ? (int) $row->home_team_id === (int) $row->nhl_team_id : (int) $row->away_team_id === (int) $row->nhl_team_id;

        return [
            'last_game' => $this->strengthWindowMetrics($history->take(1)),
            ...($training === [] ? [] : ['train' => $this->strengthWindowMetrics($history->filter(fn ($row) => in_array((string) $row->season_id, $training, true)), true)]),
            ...($training === [] ? [] : ['venue_train' => $this->strengthWindowMetrics($history->filter(fn ($row) => in_array((string) $row->season_id, $training, true))->filter($isVenue), true)]),
            ...($training === [] ? [] : ['opponent_train' => $this->strengthWindowMetrics($history->filter(fn ($row) => in_array((string) $row->season_id, $training, true))->filter($isOpponent), true)]),
            'last_season' => $this->strengthWindowMetrics($history->where('season_id', (string) ((int) $seasonId - 10001))),
            'venue_last_season' => $this->strengthWindowMetrics($history->where('season_id', (string) ((int) $seasonId - 10001))->filter($isVenue)),
            'opponent_last_season' => $this->strengthWindowMetrics($history->where('season_id', (string) ((int) $seasonId - 10001))->filter($isOpponent)),
            'last_5' => $this->strengthWindowMetrics($history->take(5)),
            'last_10' => $this->strengthWindowMetrics($history->take(10)),
            'last_20' => $this->strengthWindowMetrics($history->take(20)),
            'season_to_date' => $this->strengthWindowMetrics($history->filter(fn (object $row): bool => (string) $row->season_id === $seasonId)),
            ...($training === [] ? [] : collect(range(1, 4))->mapWithKeys(fn (int $quarter): array => [
                'q'.$quarter.'_to_date' => $this->strengthWindowMetrics($history->where('season_id', $seasonId)->reverse()->values()->slice(($quarter - 1) * 20, $quarter === 4 ? 22 : 20)),
            ])->all()),
            'venue_season_to_date' => $this->strengthWindowMetrics($history->filter(fn (object $row): bool => (string) $row->season_id === $seasonId)->filter($isVenue)),
            'opponent_last_10' => $this->strengthWindowMetrics($history->filter($isOpponent)->take(10)),
            'opponent_last_5' => $this->strengthWindowMetrics($history->filter($isOpponent)->take(5)),
            'opponent_last_20' => $this->strengthWindowMetrics($history->filter($isOpponent)->take(20)),
            'opponent_season_to_date' => $this->strengthWindowMetrics($history->filter(fn (object $row): bool => (string) $row->season_id === $seasonId)->filter($isOpponent)),
            'opponent_last_season' => $this->strengthWindowMetrics($history->filter(fn (object $row): bool => (string) $row->season_id === (string) ((int) $seasonId - 10001))->filter($isOpponent)),
        ];
    }

    /** Combine individual counts with matching strength exposure, never on-ice shot totals. */
    private function strengthWindowMetrics(Collection $rows, bool $completeGamesOnly = false): array
    {
        $missing = $rows->where('available', false)->count();
        if ($completeGamesOnly) {
            $rows = $rows->where('available', true);
        }
        $available = $rows->isNotEmpty() && ($completeGamesOnly || $missing === 0);
        $toi = $rows->sum('toi');
        $sat = $available ? (int) $rows->sum('sat') : null;
        $sog = $available ? (int) $rows->sum('sog') : null;
        $goals = $available ? (int) $rows->sum('goals') : null;

        return array_merge($this->onIceMetrics($rows->pluck('exposure')->filter()), [
            'games' => $rows->count(),
            'individual_missing_games' => $missing,
            ...($completeGamesOnly ? ['usable_games' => $rows->count()] : []),
            'sat' => $sat, 'sog' => $sog, 'goals' => $goals,
            'sat_per_60' => $available && $toi > 0 ? round($sat * 3600 / $toi, 4) : null,
            'sog_per_60' => $available && $toi > 0 ? round($sog * 3600 / $toi, 4) : null,
            'goals_per_60' => $available && $toi > 0 ? round($goals * 3600 / $toi, 4) : null,
            'shooting_pct' => $sog > 0 ? round(100 * $goals / $sog, 4) : null,
        ]);
    }

    /** @return array<string,int|float|null> */
    private function onIceMetrics(Collection $rows): array
    {
        $games = $rows->count();
        $toi = (int) $rows->sum('toi');
        $cf = (int) $rows->sum('satf');
        $ca = (int) $rows->sum('sata');
        $sf = (int) $rows->sum('sf');
        $sa = (int) $rows->sum('sa');
        $gf = (int) $rows->sum('gf');
        $ga = (int) $rows->sum('ga');
        $points = (int) $rows->sum('individual_pts');
        $onIceShooting = $sf > 0 ? 100 * $gf / $sf : null;
        $onIceSave = $sa > 0 ? 100 * (1 - ($ga / $sa)) : null;

        return [
            'games' => $games, 'toi_seconds' => $toi, 'corsi_for' => $cf, 'corsi_against' => $ca,
            'corsi_pct' => ($cf + $ca) > 0 ? round(100 * $cf / ($cf + $ca), 4) : null,
            'corsi_for_per_60' => $toi > 0 ? round($cf * 3600 / $toi, 4) : null,
            'corsi_against_per_60' => $toi > 0 ? round($ca * 3600 / $toi, 4) : null,
            'on_ice_shooting_pct' => $onIceShooting === null ? null : round($onIceShooting, 4),
            'on_ice_save_pct' => $onIceSave === null ? null : round($onIceSave, 4),
            'pdo' => $onIceShooting === null || $onIceSave === null ? null : round($onIceShooting + $onIceSave, 4),
            'ipp' => $gf > 0 ? round(100 * $points / $gf, 4) : null,
        ];
    }

    /** @return array<string,float|null> */
    private function travelMetrics(int $teamId, object $game, ?object $lastGame, Collection $history): array
    {
        $current = $this->arenaForTeam((int) $game->home_team_id, $game->game_date);
        if ($lastGame === null || $current === null) {
            return ['travel_km_since_last_game' => null, 'travel_mi_since_last_game' => null, 'travel_km_last_7_days' => null];
        }

        $lastVenue = $this->arenaForTeam((int) $lastGame->home_team_id, $lastGame->game_date);
        $distance = $lastVenue === null ? null : $this->distanceKm($lastVenue, $current);
        $windowStart = now()->parse($game->start_time_utc)->subDays(7);
        $recentGames = $history
            ->filter(fn (object $row): bool => now()->parse($row->start_time_utc)->gte($windowStart))
            ->sortBy('start_time_utc')
            ->values();
        // The preceding venue anchors the first arrival inside the window.
        $anchor = $history->first(fn (object $row): bool => now()->parse($row->start_time_utc)->lt($windowStart));
        $priorVenue = $anchor === null ? null : $this->arenaForTeam((int) $anchor->home_team_id, $anchor->game_date);
        $complete = $priorVenue !== null;
        $rolling = 0.0;
        foreach ($recentGames->concat([$game]) as $recentGame) {
            $venue = $this->arenaForTeam((int) $recentGame->home_team_id, $recentGame->game_date);
            if ($priorVenue === null || $venue === null) {
                $complete = false;
            } else {
                $rolling += $this->distanceKm($priorVenue, $venue);
            }
            $priorVenue = $venue;
        }

        return [
            'travel_km_since_last_game' => $distance === null ? null : round($distance, 2),
            'travel_mi_since_last_game' => $distance === null ? null : round($distance * 0.621371, 2),
            'travel_km_last_7_days' => $complete ? round($rolling, 2) : null,
        ];
    }

    /** @return object{latitude:float,longitude:float}|null */
    private function arenaForTeam(int $teamId, string $date): ?object
    {
        $row = DB::table('nhl_arena_locations')->where('nhl_team_id', $teamId)
            ->where(fn ($query) => $query->whereNull('effective_from')->orWhereDate('effective_from', '<=', $date))
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))
            ->orderByDesc('effective_from')->first(['latitude', 'longitude']);

        return $row === null ? null : (object) ['latitude' => (float) $row->latitude, 'longitude' => (float) $row->longitude];
    }

    private function distanceKm(object $from, object $to): float
    {
        $earthRadiusKm = 6371.0;
        $latDelta = deg2rad($to->latitude - $from->latitude);
        $lonDelta = deg2rad($to->longitude - $from->longitude);
        $a = sin($latDelta / 2) ** 2 + cos(deg2rad($from->latitude)) * cos(deg2rad($to->latitude)) * sin($lonDelta / 2) ** 2;

        return $earthRadiusKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
