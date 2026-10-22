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
        $started = hrtime(true);
        $temporaryTable = 'pregame_peers_'.bin2hex(random_bytes(8));
        try {
            DB::transaction(function () use ($run, $day, $game, $entityType, $isStopped, $temporaryTable, $cursor, $started): void {
                DB::statement("CREATE TEMPORARY TABLE {$temporaryTable} (entity_id BIGINT NOT NULL, metric_key VARCHAR(80) NOT NULL, window_key VARCHAR(32) NOT NULL, strength VARCHAR(8) NOT NULL, value DECIMAL(18,6) NOT NULL)");
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
                    DB::table('nhl_pregame_context_league_averages')->upsert($chunk,
                        ['run_id', 'source_cutoff_at', 'entity_type', 'metric_key', 'window_key', 'strength'],
                        ['league_average', 'rank_population', 'updated_at']);
                }
                $averages = DB::table('nhl_pregame_context_league_averages')->where('run_id', $run->id)
                    ->where('run_day_id', $day->id)->where('source_cutoff_at', $game->start_time_utc)
                    ->where('entity_type', $entityType);
                DB::table('nhl_pregame_context_league_peers')->whereIn('league_average_id', (clone $averages)->select('id'))->delete();
                $peers = DB::table($temporaryTable.' as peer')->joinSub((clone $averages)->select(['id', 'metric_key', 'window_key', 'strength']), 'average', function ($join): void {
                    $join->on('average.metric_key', '=', 'peer.metric_key')->on('average.window_key', '=', 'peer.window_key')
                        ->on('average.strength', '=', 'peer.strength');
                })->select(['average.id', 'peer.entity_id', 'peer.value'])
                    ->selectRaw('RANK() OVER (PARTITION BY average.id ORDER BY peer.value DESC) AS league_rank');
                DB::table('nhl_pregame_context_league_peers')->insertUsing(['league_average_id', 'entity_id', 'value', 'league_rank'], $peers);
                $this->assertActive($isStopped);
                $owner = NhlPregameContextRun::query()->whereKey($run->id)->lock(DB::getDriverName() === 'pgsql' ? 'for no key update' : true)->firstOrFail();
                if (! in_array($owner->status, [NhlPregameContextRun::STATUS_RUNNING, NhlPregameContextRun::STATUS_PAUSING], true)) {
                    throw new NhlPregameContextRunStopped('Pregame work is no longer active.');
                }
                NhlPregameContextRunDay::query()->whereKey($day->id)->where('league_cursor', $cursor)->update([
                    'league_cursor' => $cursor + 1,
                    'league_seconds' => DB::raw('league_seconds + '.round((hrtime(true) - $started) / 1e9, 3)),
                ]);
            });
        } finally {
            DB::statement("DROP TABLE IF EXISTS {$temporaryTable}");
        }

        return $cursor + 1 >= count($cutoffs) * 2;
    }

    /** Attach one game's comparisons using persisted peers; never rebuild league histories. */
    public function attachGameComparisons(NhlPregameContextRun $run, NhlPregameContextRunDay $day, int $gameId, ?Closure $isStopped = null): void
    {
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

    /** Discard transient ranking references after every game for the date has committed. */
    public function releaseDayPeers(NhlPregameContextRunDay $day): void
    {
        DB::table('nhl_pregame_context_league_peers')->whereIn('league_average_id',
            DB::table('nhl_pregame_context_league_averages')->where('run_day_id', $day->id)->select('id'))->delete();
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
