<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\NhlPregameContextRunStopped;
use App\Models\NhlPregameContextRun;
use App\Models\NhlPregameContextRunDay;
use Closure;
use Illuminate\Support\Facades\DB;

/** Calculates independent league baselines from results, then attaches context comparisons. */
class NhlPregameLeagueResults
{
    public const FINALIZING_ENTITY_CURSOR = PHP_INT_MAX;

    /** Reuse the context metric definitions while reading only raw results for league peers. */
    public function __construct(private NhlPregameContextBuilder $builder)
    {
    }

    /** Aggregate league peers without requiring any saved pregame context. */
    public function buildAverages(NhlPregameContextRun $run, NhlPregameContextRunDay $day, ?Closure $isStopped = null): void
    {
        foreach ($this->cutoffs($run, $day) as $game) {
            foreach (['team', 'player'] as $entityType) {
                $groups = [];
                foreach ($this->builder->leagueResultRows($run, $game, $entityType, $isStopped) as $row) {
                    $key = implode('|', [$row['metric_key'], $row['window_key'], $row['strength']]);
                    if (! isset($groups[$key])) {
                        $groups[$key] = ['metric_key' => $row['metric_key'], 'window_key' => $row['window_key'],
                            'strength' => $row['strength'], 'sum' => 0.0, 'count' => 0];
                    }
                    $groups[$key]['sum'] += $row['value'];
                    $groups[$key]['count']++;
                }
                $timestamp = now()->toDateTimeString();
                $rows = [];
                foreach ($groups as $group) {
                    $rows[] = ['run_id' => $run->id, 'model_run_id' => $run->model_run_id,
                        'run_day_id' => $day->id, 'game_date' => $day->game_date->format('Y-m-d'),
                        'source_cutoff_at' => $game->start_time_utc, 'entity_type' => $entityType,
                        'metric_key' => $group['metric_key'], 'window_key' => $group['window_key'],
                        'strength' => $group['strength'], 'league_average' => $group['sum'] / $group['count'],
                        'rank_population' => $group['count'], 'created_at' => $timestamp, 'updated_at' => $timestamp];
                }
                $this->assertActive($isStopped);
                foreach (array_chunk($rows, 500) as $chunk) {
                    DB::table('nhl_pregame_context_league_averages')->upsert($chunk,
                        ['run_id', 'source_cutoff_at', 'entity_type', 'metric_key', 'window_key', 'strength'],
                        ['league_average', 'rank_population', 'updated_at']);
                }
            }
        }
    }

    /** Attach ranks and deltas inside context work using the completed league averages. */
    public function attachComparisons(NhlPregameContextRun $run, NhlPregameContextRunDay $day, ?Closure $isStopped = null): void
    {
        foreach ($this->cutoffs($run, $day) as $game) {
            foreach (['team', 'player'] as $entityType) {
                $contextTable = 'nhl_'.$entityType.'_game_pregame_contexts';
                $metricTable = 'nhl_'.$entityType.'_game_pregame_context_metrics';
                $foreignKey = $entityType.'_context_id';
                $entityKey = 'nhl_'.$entityType.'_id';
                $contexts = DB::table($contextTable)->where('run_id', $run->id)
                    ->where('model_run_id', $run->model_run_id)->where('source_cutoff_at', $game->start_time_utc)
                    ->whereDate('game_date', $day->game_date)->where('context_version', NhlPregameContextBuilder::VERSION);
                if (! (clone $contexts)->exists()) {
                    continue;
                }

                $temporaryTable = 'pregame_peers_'.bin2hex(random_bytes(8));
                DB::statement("CREATE TEMPORARY TABLE {$temporaryTable} (entity_id BIGINT NOT NULL, metric_key VARCHAR(80) NOT NULL, window_key VARCHAR(32) NOT NULL, strength VARCHAR(8) NOT NULL, value DECIMAL(18,6) NOT NULL)");
                try {
                    $buffer = [];
                    foreach ($this->builder->leagueResultRows($run, $game, $entityType, $isStopped) as $row) {
                        $buffer[] = $row;
                        if (count($buffer) >= 500) {
                            $this->assertActive($isStopped);
                            DB::table($temporaryTable)->insert($buffer);
                            $buffer = [];
                        }
                    }
                    if ($buffer !== []) {
                        DB::table($temporaryTable)->insert($buffer);
                    }
                    DB::statement("CREATE INDEX {$temporaryTable}_lookup ON {$temporaryTable} (metric_key, window_key, strength, value, entity_id)");

                    DB::table($metricTable)->whereIn($foreignKey, (clone $contexts)->select('id'))->update([
                        'league_average_id' => null, 'league_average' => null, 'league_rank' => null,
                        'rank_population' => null, 'signed_difference' => null, 'absolute_difference' => null,
                        'percentage_difference' => null,
                    ]);
                    $rank = DB::table($temporaryTable.' as peer')->selectRaw('COUNT(*) + 1')
                        ->whereColumn('peer.metric_key', 'metric.metric_key')->whereColumn('peer.window_key', 'metric.window_key')
                        ->whereColumn('peer.strength', 'metric.strength')->whereColumn('peer.value', '>', 'metric.value')
                        ->whereColumn('peer.entity_id', '<>', 'context.'.$entityKey);
                    $rows = DB::table($metricTable.' as metric')
                        ->join($contextTable.' as context', 'context.id', '=', 'metric.'.$foreignKey)
                        ->join('nhl_pregame_context_league_averages as average', function ($join) use ($run, $day, $game, $entityType): void {
                            $join->on('average.metric_key', '=', 'metric.metric_key')->on('average.window_key', '=', 'metric.window_key')
                                ->on('average.strength', '=', 'metric.strength')->where('average.run_id', $run->id)
                                ->where('average.run_day_id', $day->id)->where('average.source_cutoff_at', $game->start_time_utc)
                                ->where('average.entity_type', $entityType);
                        })
                        ->whereIn('context.id', (clone $contexts)->select('id'))
                        ->where('metric.measure_key', 'value')->whereNotNull('metric.value')
                        ->whereExists(function ($peer) use ($temporaryTable, $entityKey): void {
                            $peer->selectRaw('1')->from($temporaryTable.' as population')
                                ->whereColumn('population.entity_id', 'context.'.$entityKey)
                                ->whereColumn('population.metric_key', 'metric.metric_key')
                                ->whereColumn('population.window_key', 'metric.window_key')
                                ->whereColumn('population.strength', 'metric.strength');
                        })
                        ->select(['metric.'.$foreignKey, 'metric.metric_key', 'metric.window_key', 'metric.strength',
                            'metric.value', 'average.id as league_average_id', 'average.league_average', 'average.rank_population'])
                        ->selectSub($rank, 'league_rank')->orderBy('metric.id');
                    $updates = [];
                    $timestamp = now()->toDateTimeString();
                    foreach ($rows->cursor() as $row) {
                        $average = (float) $row->league_average;
                        $difference = (float) $row->value - $average;
                        $updates[] = [$foreignKey => $row->{$foreignKey}, 'metric_key' => $row->metric_key,
                            'window_key' => $row->window_key, 'strength' => $row->strength, 'measure_key' => 'value',
                            'value' => $row->value, 'league_average_id' => $row->league_average_id,
                            'league_average' => $average, 'league_rank' => $row->league_rank,
                            'rank_population' => $row->rank_population, 'signed_difference' => $difference,
                            'absolute_difference' => abs($difference),
                            'percentage_difference' => $average == 0.0 ? null : 100 * $difference / abs($average),
                            'created_at' => $timestamp, 'updated_at' => $timestamp];
                        if (count($updates) >= 300) {
                            $this->writeComparisons($metricTable, $foreignKey, $updates, $isStopped);
                            $updates = [];
                        }
                    }
                    if ($updates !== []) {
                        $this->writeComparisons($metricTable, $foreignKey, $updates, $isStopped);
                    }
                } finally {
                    DB::statement("DROP TABLE IF EXISTS {$temporaryTable}");
                }
            }
        }
    }

    /**
     * Build one cutoff/entity population and checkpoint its reusable peer values atomically.
     *
     * @return bool True when all populations for the date have been committed.
     */
    public function buildNextAverages(NhlPregameContextRun $run, NhlPregameContextRunDay $day, ?Closure $isStopped = null): bool
    {
        $cutoffs = $this->cutoffs($run, $day);
        $cursor = (int) $day->league_cursor;
        if ($cursor >= count($cutoffs) * 2) {
            return true;
        }
        $game = $cutoffs[intdiv($cursor, 2)];
        $entityType = $cursor % 2 === 0 ? 'team' : 'player';
        if ((int) $day->league_entity_cursor === self::FINALIZING_ENTITY_CURSOR) {
            return $this->finalizeNextPopulation($run, $day, $game, $entityType, count($cutoffs) * 2, $isStopped);
        }
        $pageSize = $entityType === 'team' ? 8 : max(1, (int) config('nhl-pregame.league_entity_page_size', 32));
        $entities = $this->builder->leagueEntities($run, $game, $entityType, (int) $day->league_entity_cursor, $pageSize + 1);
        $populationComplete = $entities->count() <= $pageSize;
        $entities = $entities->take($pageSize);
        $reused = $entityType === 'player' && $cursor >= 3
            ? $this->unchangedPlayerRows($run, $day, $cutoffs[intdiv($cursor, 2) - 1], $game, $entities)
            : null;
        $reusedIds = $reused === null ? [] : (clone $reused)->distinct()->pluck('peer.entity_id')->all();
        $freshLimit = max(1, (int) config('nhl-pregame.player_batch_size', 8));
        $fresh = $entities->whereNotIn('entity_id', $reusedIds)->values();
        if ($entityType === 'player' && $fresh->count() > $freshLimit) {
            $boundary = (int) $fresh[$freshLimit]->entity_id;
            $entities = $entities->filter(fn (object $entity): bool => (int) $entity->entity_id < $boundary);
            $populationComplete = false;
        }
        $freshEntities = $entities->whereNotIn('entity_id', $reusedIds);
        $started = hrtime(true);
        $temporaryTable = 'pregame_peers_'.bin2hex(random_bytes(8));
        try {
            DB::transaction(function () use ($run, $day, $game, $entityType, $isStopped, $temporaryTable, $cursor, $started, $entities, $freshEntities, $reused, $populationComplete): void {
                $this->assertActive($isStopped);
                if ((int) $day->league_entity_cursor === 0) {
                    DB::table('nhl_pregame_context_league_peers')->whereIn('league_average_id',
                        DB::table('nhl_pregame_context_league_averages')->where('run_id', $run->id)
                            ->where('run_day_id', $day->id)->where('source_cutoff_at', $game->start_time_utc)
                            ->where('entity_type', $entityType)->select('id'))->delete();
                }
                DB::statement("CREATE TEMPORARY TABLE {$temporaryTable} (entity_id BIGINT NOT NULL, metric_key VARCHAR(80) NOT NULL, window_key VARCHAR(32) NOT NULL, strength VARCHAR(8) NOT NULL, value DECIMAL(18,6) NOT NULL)");
                if ($reused !== null) {
                    DB::table($temporaryTable)->insertUsing(['entity_id', 'metric_key', 'window_key', 'strength', 'value'],
                        (clone $reused)->whereIn('peer.entity_id', $entities->pluck('entity_id')->all()));
                }
                $buffer = [];
                foreach ($this->builder->leagueResultRows($run, $game, $entityType, $isStopped, $freshEntities) as $row) {
                    $buffer[] = $row;
                    if (count($buffer) >= 2000) {
                        $this->assertActive($isStopped);
                        DB::table($temporaryTable)->insert($buffer);
                        $buffer = [];
                    }
                }
                if ($buffer !== []) {
                    DB::table($temporaryTable)->insert($buffer);
                }
                $this->assertActive($isStopped);
                $groups = DB::table($temporaryTable)->select(['metric_key', 'window_key', 'strength'])
                    ->selectRaw('AVG(value) AS league_average, COUNT(*) AS rank_population')
                    ->groupBy('metric_key', 'window_key', 'strength')->get();
                $timestamp = now()->toDateTimeString();
                $rows = $groups->map(fn (object $group): array => [
                    'run_id' => $run->id, 'model_run_id' => $run->model_run_id, 'run_day_id' => $day->id,
                    'game_date' => $day->game_date->format('Y-m-d'), 'source_cutoff_at' => $game->start_time_utc,
                    'entity_type' => $entityType, 'metric_key' => $group->metric_key, 'window_key' => $group->window_key,
                    'strength' => $group->strength, 'league_average' => $group->league_average,
                    'rank_population' => $group->rank_population, 'created_at' => $timestamp, 'updated_at' => $timestamp,
                ])->all();
                foreach (array_chunk($rows, 500) as $chunk) {
                    DB::table('nhl_pregame_context_league_averages')->insertOrIgnore($chunk);
                }
                $averages = DB::table('nhl_pregame_context_league_averages')->where('run_id', $run->id)
                    ->where('run_day_id', $day->id)->where('source_cutoff_at', $game->start_time_utc)
                    ->where('entity_type', $entityType);
                $peers = DB::table($temporaryTable.' as peer')->joinSub((clone $averages)->select(['id', 'metric_key', 'window_key', 'strength']), 'average', function ($join): void {
                    $join->on('average.metric_key', '=', 'peer.metric_key')->on('average.window_key', '=', 'peer.window_key')
                        ->on('average.strength', '=', 'peer.strength');
                })->select(['average.id', 'peer.entity_id', 'peer.value'])
                    ->selectRaw('0 AS league_rank');
                // Replayed deterministic pages replace the same peer instead of failing on a duplicate key.
                DB::statement('INSERT INTO nhl_pregame_context_league_peers (league_average_id, entity_id, value, league_rank) '
                    .$peers->whereRaw('1 = 1')->toSql()
                    .' ON CONFLICT (league_average_id, entity_id) DO UPDATE SET value = EXCLUDED.value, league_rank = EXCLUDED.league_rank',
                    $peers->getBindings());
                $this->assertActive($isStopped);
                if ($populationComplete) {
                    // Zero denotes an unpublished group while its complete peer population is ranked.
                    (clone $averages)->update(['rank_population' => 0]);
                }
                $owner = NhlPregameContextRun::query()->whereKey($run->id)->lock(DB::getDriverName() === 'pgsql' ? 'for no key update' : true)->firstOrFail();
                if (! in_array($owner->status, [NhlPregameContextRun::STATUS_RUNNING, NhlPregameContextRun::STATUS_PAUSING], true)) {
                    throw new NhlPregameContextRunStopped('Pregame work is no longer active.');
                }
                $this->assertActive($isStopped);
                $updated = NhlPregameContextRunDay::query()->whereKey($day->id)->where('league_cursor', $cursor)
                    ->where('league_entity_cursor', $day->league_entity_cursor)->update([
                    'league_entity_cursor' => $populationComplete ? self::FINALIZING_ENTITY_CURSOR : (int) $entities->last()->entity_id,
                    'league_seconds' => DB::raw('league_seconds + '.round((hrtime(true) - $started) / 1e9, 3)),
                ]);
                if ($updated !== 1) {
                    throw new NhlPregameContextRunStopped('The league checkpoint has changed.');
                }
            });
        } finally {
            DB::statement("DROP TABLE IF EXISTS {$temporaryTable}");
        }

        return false;
    }

    /** Reuse prior-cutoff player values only when both their history and matchup are unchanged. */
    private function unchangedPlayerRows(NhlPregameContextRun $run, NhlPregameContextRunDay $day, object $previous, object $game, \Illuminate\Support\Collection $entities): ?\Illuminate\Database\Query\Builder
    {
        if ($entities->isEmpty()) {
            return null;
        }
        $changedTeams = DB::table('nhl_games')->where('game_type', 2)->where('season_id', $game->season_id)
            ->where('start_time_utc', '>=', $previous->start_time_utc)->where('start_time_utc', '<', $game->start_time_utc)
            ->get(['home_team_id', 'away_team_id'])
            ->flatMap(fn (object $row): array => [$row->home_team_id, $row->away_team_id])->unique()->all();
        $unchanged = $entities->whereNotIn('nhl_team_id', $changedTeams)
            ->filter(fn (object $row): bool => $row->latest_appearance_at < $previous->start_time_utc)->pluck('entity_id');
        if ($unchanged->isEmpty()) {
            return null;
        }

        return DB::table('nhl_pregame_context_league_peers as peer')
            ->join('nhl_pregame_context_league_averages as average', 'average.id', '=', 'peer.league_average_id')
            ->where('average.run_id', $run->id)->where('average.run_day_id', $day->id)
            ->where('average.source_cutoff_at', $previous->start_time_utc)->where('average.entity_type', 'player')
            ->whereIn('peer.entity_id', $unchanged)
            ->select(['peer.entity_id', 'average.metric_key', 'average.window_key', 'average.strength', 'peer.value']);
    }

    /** Finalize a bounded set of complete metric populations in a separately committed delivery. */
    private function finalizeNextPopulation(NhlPregameContextRun $run, NhlPregameContextRunDay $day, object $game, string $entityType, int $populationCount, ?Closure $isStopped): bool
    {
        $started = hrtime(true);

        return DB::transaction(function () use ($run, $day, $game, $entityType, $populationCount, $isStopped, $started): bool {
            $this->assertActive($isStopped);
            $averages = DB::table('nhl_pregame_context_league_averages')->where('run_id', $run->id)
                ->where('run_day_id', $day->id)->where('source_cutoff_at', $game->start_time_utc)->where('entity_type', $entityType);
            $ids = (clone $averages)->where('rank_population', 0)->orderBy('id')
                ->limit(max(1, (int) config('nhl-pregame.league_rank_group_page_size', 8)))->pluck('id');
            if ($ids->isNotEmpty()) {
                $this->finalizePopulation((clone $averages)->whereIn('id', $ids));
            }
            $more = (clone $averages)->where('rank_population', 0)->exists();
            $owner = NhlPregameContextRun::query()->whereKey($run->id)
                ->lock(DB::getDriverName() === 'pgsql' ? 'for no key update' : true)->firstOrFail();
            if (! in_array($owner->status, ['running', 'pausing'], true)) {
                throw new NhlPregameContextRunStopped('Pregame work is no longer active.');
            }
            $this->assertActive($isStopped);
            $updated = NhlPregameContextRunDay::query()->whereKey($day->id)->where('league_cursor', $day->league_cursor)
                ->where('league_entity_cursor', self::FINALIZING_ENTITY_CURSOR)->update([
                    'league_cursor' => $more ? $day->league_cursor : (int) $day->league_cursor + 1,
                    'league_entity_cursor' => $more ? self::FINALIZING_ENTITY_CURSOR : 0,
                    'league_seconds' => DB::raw('league_seconds + '.round((hrtime(true) - $started) / 1e9, 3)),
                ]);
            if ($updated !== 1) {
                throw new NhlPregameContextRunStopped('The league checkpoint has changed.');
            }

            return ! $more && (int) $day->league_cursor + 1 >= $populationCount;
        });
    }

    /** Rank only the supplied complete metric groups; ties retain descending competition rank. */
    private function finalizePopulation(\Illuminate\Database\Query\Builder $averages): void
    {
        (clone $averages)->whereNotExists(fn ($query) => $query->selectRaw('1')->from('nhl_pregame_context_league_peers as peer')
            ->whereColumn('peer.league_average_id', 'nhl_pregame_context_league_averages.id'))->delete();
        $ids = (clone $averages)->select('id');
        $population = DB::table('nhl_pregame_context_league_peers')->whereIn('league_average_id', $ids);
        $groups = (clone $population)->select('league_average_id')->selectRaw('AVG(value) AS league_average, COUNT(*) AS rank_population')->groupBy('league_average_id');
        DB::update('UPDATE nhl_pregame_context_league_averages AS average SET league_average = totals.league_average, rank_population = totals.rank_population, updated_at = ? FROM ('.$groups->toSql().') AS totals WHERE average.id = totals.league_average_id', [now()->toDateTimeString(), ...$groups->getBindings()]);
        $ranks = (clone $population)->select(['league_average_id', 'entity_id'])
            ->selectRaw('RANK() OVER (PARTITION BY league_average_id ORDER BY value DESC) AS league_rank');
        DB::update('UPDATE nhl_pregame_context_league_peers AS peer SET league_rank = ranked.league_rank FROM ('.$ranks->toSql().') AS ranked WHERE peer.league_average_id = ranked.league_average_id AND peer.entity_id = ranked.entity_id', $ranks->getBindings());
    }

    /** Attach one game's comparisons using persisted peers; never rebuild league histories. */
    public function attachGameComparisons(NhlPregameContextRun $run, NhlPregameContextRunDay $day, int $gameId, ?Closure $isStopped = null): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->attachGameComparisonsInDatabase($run, $day, $gameId, $isStopped);

            return;
        }
        foreach (['team', 'player'] as $entityType) {
            $contextTable = 'nhl_'.$entityType.'_game_pregame_contexts';
            $metricTable = 'nhl_'.$entityType.'_game_pregame_context_metrics';
            $foreignKey = $entityType.'_context_id';
            $entityKey = 'nhl_'.$entityType.'_id';
            $contexts = DB::table($contextTable)->where('run_id', $run->id)->where('model_run_id', $run->model_run_id)
                ->where('nhl_game_id', $gameId)->where('context_version', NhlPregameContextBuilder::VERSION);
            $this->assertActive($isStopped);
            DB::table($metricTable)->whereIn($foreignKey, (clone $contexts)->select('id'))->update([
                'league_average_id' => null, 'league_average' => null, 'league_rank' => null, 'rank_population' => null,
                'signed_difference' => null, 'absolute_difference' => null, 'percentage_difference' => null,
            ]);
            $rank = DB::table('nhl_pregame_context_league_peers as peer')->selectRaw('COUNT(*) + 1')
                ->whereColumn('peer.league_average_id', 'average.id')->whereColumn('peer.value', '>', 'metric.value')
                ->whereColumn('peer.entity_id', '<>', 'context.'.$entityKey);
            $rows = DB::table($metricTable.' as metric')->join($contextTable.' as context', 'context.id', '=', 'metric.'.$foreignKey)
                ->join('nhl_pregame_context_league_averages as average', function ($join) use ($run, $day, $entityType): void {
                    $join->on('average.metric_key', '=', 'metric.metric_key')->on('average.window_key', '=', 'metric.window_key')
                        ->on('average.strength', '=', 'metric.strength')->on('average.source_cutoff_at', '=', 'context.source_cutoff_at')
                        ->where('average.run_id', $run->id)->where('average.run_day_id', $day->id)->where('average.entity_type', $entityType);
                })
                ->join('nhl_pregame_context_league_peers as own_peer', function ($join) use ($entityKey): void {
                    $join->on('own_peer.league_average_id', '=', 'average.id')->on('own_peer.entity_id', '=', 'context.'.$entityKey);
                })
                ->whereIn('context.id', (clone $contexts)->select('id'))->where('metric.measure_key', 'value')->whereNotNull('metric.value')
                ->select(['metric.'.$foreignKey, 'metric.metric_key', 'metric.window_key', 'metric.strength', 'metric.value',
                    'average.id as league_average_id', 'average.league_average', 'average.rank_population'])
                ->selectRaw('CASE WHEN own_peer.value = metric.value THEN own_peer.league_rank ELSE ('.$rank->toSql().') END AS league_rank', $rank->getBindings())
                ->orderBy('metric.id');
            $timestamp = now()->toDateTimeString();
            $updates = [];
            foreach ($rows->cursor() as $row) {
                $average = (float) $row->league_average;
                $difference = (float) $row->value - $average;
                $updates[] = [$foreignKey => $row->{$foreignKey}, 'metric_key' => $row->metric_key,
                    'window_key' => $row->window_key, 'strength' => $row->strength, 'measure_key' => 'value', 'value' => $row->value,
                    'league_average_id' => $row->league_average_id, 'league_average' => $average, 'league_rank' => $row->league_rank,
                    'rank_population' => $row->rank_population, 'signed_difference' => $difference, 'absolute_difference' => abs($difference),
                    'percentage_difference' => $average == 0.0 ? null : 100 * $difference / abs($average),
                    'created_at' => $timestamp, 'updated_at' => $timestamp];
                if (count($updates) >= 300) {
                    $this->writeComparisons($metricTable, $foreignKey, $updates, $isStopped);
                    $updates = [];
                }
            }
            if ($updates !== []) {
                $this->writeComparisons($metricTable, $foreignKey, $updates, $isStopped);
            }
        }
    }

    /** Update comparison fields in place instead of clearing and round-tripping every metric through PHP. */
    private function attachGameComparisonsInDatabase(NhlPregameContextRun $run, NhlPregameContextRunDay $day, int $gameId, ?Closure $isStopped): void
    {
        foreach (['team', 'player'] as $entityType) {
            $this->assertActive($isStopped);
            $contexts = 'nhl_'.$entityType.'_game_pregame_contexts';
            $metrics = 'nhl_'.$entityType.'_game_pregame_context_metrics';
            $foreignKey = $entityType.'_context_id';
            $entityKey = 'nhl_'.$entityType.'_id';
            DB::update("WITH comparisons AS (
                SELECT metric.id, average.id AS average_id, average.league_average,
                    average.rank_population, own_peer.entity_id AS peer_id,
                    CASE WHEN own_peer.value = metric.value THEN own_peer.league_rank
                        ELSE (SELECT COUNT(*) + 1 FROM nhl_pregame_context_league_peers peer
                            WHERE peer.league_average_id = average.id AND peer.value > metric.value
                                AND peer.entity_id <> context.{$entityKey}) END AS league_rank,
                    metric.value - average.league_average AS difference
                FROM {$metrics} metric
                JOIN {$contexts} context ON context.id = metric.{$foreignKey}
                LEFT JOIN nhl_pregame_context_league_averages average ON average.metric_key = metric.metric_key
                    AND average.window_key = metric.window_key AND average.strength = metric.strength
                    AND average.source_cutoff_at = context.source_cutoff_at
                    AND average.run_id = ? AND average.run_day_id = ? AND average.entity_type = ?
                    AND metric.measure_key = 'value' AND metric.value IS NOT NULL
                LEFT JOIN nhl_pregame_context_league_peers own_peer ON own_peer.league_average_id = average.id
                    AND own_peer.entity_id = context.{$entityKey}
                WHERE context.run_id = ? AND context.model_run_id IS NOT DISTINCT FROM ? AND context.nhl_game_id = ?
                    AND context.context_version = ?
            ) UPDATE {$metrics} metric SET
                league_average_id = CASE WHEN comparison.peer_id IS NOT NULL THEN comparison.average_id END,
                league_average = CASE WHEN comparison.peer_id IS NOT NULL THEN comparison.league_average END,
                league_rank = CASE WHEN comparison.peer_id IS NOT NULL THEN comparison.league_rank END,
                rank_population = CASE WHEN comparison.peer_id IS NOT NULL THEN comparison.rank_population END,
                signed_difference = CASE WHEN comparison.peer_id IS NOT NULL THEN comparison.difference END,
                absolute_difference = CASE WHEN comparison.peer_id IS NOT NULL THEN ABS(comparison.difference) END,
                percentage_difference = CASE WHEN comparison.peer_id IS NOT NULL
                    THEN 100 * comparison.difference / NULLIF(ABS(comparison.league_average), 0) END,
                updated_at = ?
            FROM comparisons comparison WHERE metric.id = comparison.id", [
                $run->id, $day->id, $entityType, $run->id, $run->model_run_id, $gameId,
                NhlPregameContextBuilder::VERSION, now()->toDateTimeString(),
            ]);
        }
    }

    /** Discard transient ranking references after every game for the date has committed. */
    public function releaseDayPeers(NhlPregameContextRunDay $day): void
    {
        DB::table('nhl_pregame_context_league_peers')->whereIn('league_average_id',
            DB::table('nhl_pregame_context_league_averages')->where('run_day_id', $day->id)->select('id'))->delete();
    }

    /** Delete a bounded peer page without holding the parent run lock during the expensive delete. */
    public function releaseDayPeerPage(NhlPregameContextRun $run, NhlPregameContextRunDay $day, ?Closure $isStopped = null): bool
    {
        $limit = max(1, (int) config('nhl-pregame.peer_cleanup_page_size', 10000));
        // The run/cutoff prefix reuses the existing average-group index; no schema change is needed.
        $averages = DB::table('nhl_pregame_context_league_averages')->where('run_id', $run->id)
            ->where('run_day_id', $day->id)->whereIn('source_cutoff_at', collect($this->cutoffs($run, $day))->pluck('start_time_utc'))
            ->select('id');
        $page = DB::table('nhl_pregame_context_league_peers')->whereIn('league_average_id', $averages)
            ->limit($limit)->select(['league_average_id', 'entity_id']);

        return DB::transaction(function () use ($run, $day, $page, $limit, $isStopped): bool {
            $this->assertActive($isStopped);
            $deleted = DB::delete('DELETE FROM nhl_pregame_context_league_peers AS peer USING ('.$page->toSql().') AS page '
                .'WHERE peer.league_average_id = page.league_average_id AND peer.entity_id = page.entity_id', $page->getBindings());
            $owner = NhlPregameContextRun::query()->whereKey($run->id)
                ->lock(DB::getDriverName() === 'pgsql' ? 'for no key update' : true)->firstOrFail();
            if (! in_array($owner->status, ['running', 'pausing'], true)) {
                throw new NhlPregameContextRunStopped('Pregame work is no longer active.');
            }
            $this->assertActive($isStopped);
            app(NhlPregameContextOrchestrator::class)->clearDayTimeoutRetries($owner, $day->id, 'context');

            return $deleted === $limit;
        });
    }

    /** Obtain each distinct scheduled cutoff from run games, independently of saved context. */
    private function cutoffs(NhlPregameContextRun $run, NhlPregameContextRunDay $day): array
    {
        return DB::table('nhl_games as game')->join('nhl_pregame_context_run_games as work', 'work.nhl_game_id', '=', 'game.nhl_game_id')
            ->where('work.run_id', $run->id)->whereDate('work.game_date', $day->game_date)
            ->whereNotNull('game.start_time_utc')->orderBy('game.start_time_utc')->orderBy('game.nhl_game_id')
            ->get(['game.nhl_game_id', 'game.season_id', 'game.game_date', 'game.start_time_utc'])
            ->unique('start_time_utc')->values()->all();
    }

    /** Persist one bounded comparison page, preserving entity metric values and sample counts. */
    private function writeComparisons(string $table, string $foreignKey, array $rows, ?Closure $isStopped): void
    {
        $this->assertActive($isStopped);
        DB::table($table)->upsert($rows, [$foreignKey, 'metric_key', 'window_key', 'strength', 'measure_key'], [
            'league_average_id', 'league_average', 'league_rank', 'rank_population', 'signed_difference',
            'absolute_difference', 'percentage_difference', 'updated_at',
        ]);
    }

    /** Check cancellation between bounded units instead of querying cancellation per metric row. */
    private function assertActive(?Closure $isStopped): void
    {
        if ($isStopped !== null && $isStopped()) {
            throw new NhlPregameContextRunStopped('The Pregame context run was stopped.');
        }
    }
}
