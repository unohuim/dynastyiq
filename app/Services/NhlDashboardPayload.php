<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NhlGame;
use App\Models\NhlPlayerInjury;
use App\Models\NhlPlayerTransaction;
use App\Models\Player;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Assemble bounded public dashboard facts without using fantasy or prediction data. */
class NhlDashboardPayload
{
    /** Inject the shared, cached NHL editorial reader. */
    public function __construct(private readonly NhlDashboardNews $news)
    {
    }

    /** Return the dashboard's editorial cards and current local NHL facts. */
    public function build(string $query = ''): array
    {
        $slate = Carbon::now('America/Toronto');
        if ($slate->hour < 11) {
            $slate->subDay();
        }
        $date = $slate->toDateString();
        $facts = Cache::remember('nhl:dashboard:facts:v8:'.$date, 300, fn (): array => $this->facts($date));
        $stories = $this->news->feed();
        $articles = collect($stories['items']);

        return [
            ...$facts, 'featured' => $articles->take(3)->values()->all(),
            'headlines' => $articles->slice(3)->values()->all(),
            'news_unavailable' => $stories['unavailable'], 'news_updated_at' => $stories['updated_at'],
            'query' => $query, 'search' => $query !== '' ? $this->search($query, $articles) : null,
            'links' => ['news' => 'https://www.nhl.com/news',
                'line_combinations' => route('stats.units.index', ['date' => $facts['performance_date'], 'sum' => 0, 'pos' => ['F']]),
                'stats' => route('stats.index'), 'transactions' => route('transactions.index'),
                'injuries' => route('injuries.index'), 'games' => route('games.index')],
        ];
    }

    /** Cache shared facts separately from editorial feeds and request-specific searches. */
    private function facts(string $date): array
    {
        $cutoff = Carbon::parse($date);
        $schedule = NhlGame::query()->whereBetween('game_date', [$cutoff->copy()->subDays(120)->toDateString(), $date])
            ->whereIn('game_type', [2, 3])
            ->where(fn ($query) => $query->whereNotIn('game_state', ['PPD', 'CNCL'])->orWhereNull('game_state'))
            ->orderByDesc('game_date')->orderByDesc('nhl_game_id')->limit(1500)->get();
        $completeDay = $schedule->groupBy(fn (NhlGame $game): string => $game->game_date->toDateString())
            ->first(fn (Collection $day): bool => $day->every(fn (NhlGame $game): bool => in_array($game->game_state, ['FINAL', 'OFF'], true)));
        $performanceDate = $completeDay?->first()?->game_date?->toDateString();
        $finished = $schedule->filter(fn (NhlGame $game): bool => in_array($game->game_state, ['FINAL', 'OFF'], true))
            ->where('season_id', $schedule->first()?->season_id)->values();
        $boxscores = $finished->isEmpty() ? collect() : DB::table('nhl_boxscores as b')
            ->leftJoin('players as p', 'p.nhl_id', '=', 'b.nhl_player_id')
            ->whereIn('b.nhl_game_id', $finished->pluck('nhl_game_id'))->where('b.toi_seconds', '>', 0)
            ->select(['b.*', 'p.full_name', 'p.head_shot_url'])->get();
        $dayBoxes = $completeDay === null ? collect() : $boxscores->whereIn('nhl_game_id', $completeDay->pluck('nhl_game_id'));

        return [
            'slate_date' => $date, 'performance_date' => $performanceDate,
            'performers' => $this->performers($dayBoxes, $completeDay ?? collect()),
            'line_combinations' => $this->lineCombinations($completeDay ?? collect()),
            'transactions' => $this->transactions(), 'injuries' => $this->injuries(),
            'team_trends' => $this->teamTrends($finished, $boxscores),
            'player_trends' => $this->playerTrends($finished, $boxscores),
            'updated_at' => now()->toIso8601String(),
        ];
    }

    /** Retain every qualifying performance; fill to four real cards when fewer qualify. */
    private function performers(Collection $rows, Collection $games): array
    {
        $gsax = $this->goalieGsax($games->pluck('nhl_game_id')->all(), $rows->where('position', 'G'));
        $gamesById = $games->keyBy('nhl_game_id');
        $candidates = $rows->map(function (object $row) use ($gamesById, $gsax): array {
            $goalie = $row->position === 'G';
            $sv = $row->shots_against > 0 ? $row->saves / $row->shots_against : null;
            $savedAbove = $gsax->get($row->nhl_player_id);
            $qualified = $goalie ? ($savedAbove !== null && $savedAbove >= 1 && $sv !== null && $sv >= .920)
                : ($row->points >= 3 || $row->goals >= 2);
            $game = $gamesById->get($row->nhl_game_id);
            $side = (int) $game?->home_team_id === (int) $row->nhl_team_id ? 'home' : 'away';
            $badge = $goalie ? ($row->goals_against === 0 ? 'Shutout' : $row->saves.' saves')
                : ($row->goals >= 3 ? 'Hat trick' : ($row->goals >= 2 ? 'Multi-goal game' : $row->points.'-point night'));

            return [
                'nhl_player_id' => (int) $row->nhl_player_id, 'name' => $row->full_name ?: $row->player_name,
                'avatar' => $row->head_shot_url, 'position' => $row->position,
                'team' => $game?->{$side.'_team_abbrev'}, 'team_logo' => $game?->{$side.'_team_logo'},
                'url' => 'https://www.nhl.com/player/'.$row->nhl_player_id,
                'game_url' => route('games.show', $row->nhl_game_id), 'goalie' => $goalie,
                'goals' => (int) $row->goals, 'assists' => (int) $row->assists, 'points' => (int) $row->points,
                'saves' => (int) $row->saves, 'goals_against' => (int) $row->goals_against,
                'save_percentage' => $sv, 'gsax' => $savedAbove, 'badge' => $badge,
                'qualified' => $qualified, 'shots' => (int) $row->sog,
                // Comparable threshold multiples order qualifying performances, not predictions.
                'standout' => $goalie ? ($savedAbove ?? 0) : max($row->points / 3, $row->goals / 2),
            ];
        })->filter(fn (array $row): bool => $row['nhl_player_id'] > 0);
        $qualified = $candidates->where('qualified', true)->sortBy([
            ['standout', 'desc'], ['points', 'desc'], ['saves', 'desc'], ['nhl_player_id', 'asc'],
        ])->unique('nhl_player_id');
        $skaters = $candidates->where('goalie', false)->where('qualified', false)->sortBy([
            ['points', 'desc'], ['goals', 'desc'], ['shots', 'desc'], ['nhl_player_id', 'asc'],
        ]);
        $otherGoalies = $candidates->where('goalie', true)->where('qualified', false)->sortBy([
            ['save_percentage', 'desc'], ['saves', 'desc'], ['nhl_player_id', 'asc'],
        ]);

        return $qualified->concat($skaters)->concat($otherGoalies)->unique('nhl_player_id')->take(max(4, $qualified->count()))
            ->map(fn (array $row): array => collect($row)->except(['standout', 'shots'])->all())->values()->all();
    }

    /** Use one scored goal model and complete official SOG coverage; unavailable GSAx stays null. */
    private function goalieGsax(array $gameIds, Collection $goalies): Collection
    {
        if ($gameIds === [] || $goalies->isEmpty()) {
            return collect();
        }
        $modelId = DB::table('nhl_expected_goals_models as m')->where('m.prediction_target', 'goal')
            ->whereExists(function ($query) use ($gameIds): void {
                $query->selectRaw('1')->from('nhl_shot_attempt_predictions as p')
                    ->whereColumn('p.expected_goals_model_id', 'm.id')->whereIn('p.nhl_game_id', $gameIds)
                    ->where('p.is_scored', true)->where('p.prediction_target', 'goal');
            })->orderByDesc('m.trained_at')->orderByDesc('m.id')->value('m.id');
        if ($modelId === null) {
            return collect();
        }
        $expectations = DB::table('nhl_shot_attempts_facts as f')
            ->leftJoin('nhl_shot_attempt_predictions as p', function ($join) use ($modelId): void {
                $join->on('p.shot_attempt_fact_id', '=', 'f.id')->where('p.expected_goals_model_id', $modelId)
                    ->where('p.prediction_target', 'goal')->where('p.is_scored', true);
            })->whereIn('f.nhl_game_id', $gameIds)->where('f.is_shot_on_goal', true)
            ->where(fn ($query) => $query->where('f.is_empty_net', false)->orWhereNull('f.is_empty_net'))
            ->where(fn ($query) => $query->where('f.period_type', '!=', 'SO')->orWhereNull('f.period_type'))
            ->groupBy('f.goalie_player_id')->selectRaw('f.goalie_player_id, COUNT(*) AS attempts, COUNT(p.xg) AS scored, SUM(p.xg) AS xga')
            ->get()->keyBy('goalie_player_id');

        return $goalies->mapWithKeys(function (object $row) use ($expectations): array {
            $expected = $expectations->get($row->nhl_player_id);
            $complete = $expected && (int) $expected->attempts === (int) $row->shots_against
                && (int) $expected->scored === (int) $expected->attempts && $expected->attempts > 0;

            return [$row->nhl_player_id => $complete ? round((float) $expected->xga - $row->goals_against, 3) : null];
        });
    }

    /** Display the newest public real-hockey moves, excluding fantasy and draft events. */
    private function transactions(): array
    {
        return NhlPlayerTransaction::query()->with(['player', 'externalIdentity'])
            ->whereNotNull('transaction_date')->whereDate('transaction_date', '<=', today())
            ->whereNotIn('transaction_type', ['draft', 'drafted', 'transfer'])
            ->orderByDesc('transaction_date')->orderByDesc('id')->limit(4)->get()
            ->map(fn (NhlPlayerTransaction $row): array => [
                'id' => $row->id, 'name' => $row->player?->full_name ?? $row->externalIdentity?->display_name ?? 'Player transaction',
                'avatar' => $row->player?->head_shot_url, 'description' => $row->description,
                'type' => ucwords(str_replace(['_', '-'], ' ', (string) $row->transaction_type)),
                'team' => $row->to_team, 'date' => $row->transaction_date?->toDateString(),
                'url' => route('transactions.index', ['q' => $row->player?->full_name ?? $row->externalIdentity?->display_name]),
            ])->all();
    }

    /** Show only currently reported absences or uncertain availability, never recovered players. */
    private function injuries(): array
    {
        return NhlPlayerInjury::query()->with('player')->whereIn('availability', ['out', 'questionable', 'unknown'])
            ->orderBy('created_at')->orderBy('id')->limit(4)->get()
            ->map(fn (NhlPlayerInjury $row): array => [
                'id' => $row->id, 'name' => $row->player_name, 'avatar' => $row->player?->head_shot_url,
                'position' => $row->position, 'team' => $row->team_abbrev,
                'availability' => $row->availability, 'status' => $row->status_text,
                'body_part' => $row->body_part, 'updated_at' => $row->last_observed_at?->toIso8601String(),
                'anticipated_return_date' => $row->anticipated_return_date?->toDateString(),
                'url' => route('injuries.index', ['team' => $row->team_abbrev]),
            ])->all();
    }

    /** Rank forward trios using strictly five-on-five shift time and linked shot events. */
    private function lineCombinations(Collection $games): array
    {
        if ($games->isEmpty()) {
            return [];
        }

        $shifts = DB::table('nhl_unit_shifts as s')
            ->join('nhl_units as u', 'u.id', '=', 's.unit_id')
            ->whereIn('s.nhl_game_id', $games->pluck('nhl_game_id'))
            ->whereIn('u.unit_type', ['F', 'D', 'G'])
            ->whereColumn('s.end_game_seconds', '>', 's.start_game_seconds')
            ->get(['s.*', 'u.unit_type']);
        $members = DB::table('nhl_unit_players as up')->join('players as p', 'p.id', '=', 'up.player_id')
            ->whereIn('up.unit_id', $shifts->pluck('unit_id')->unique())
            ->orderBy('p.id')->get(['up.unit_id', 'p.id', 'p.nhl_id', 'p.full_name', 'p.first_name', 'p.last_name', 'p.position'])->groupBy('unit_id');
        $faceoffs = DB::table('play_by_plays')->whereIn('nhl_game_id', $games->pluck('nhl_game_id'))
            ->where('type_desc_key', 'faceoff')->get(['nhl_game_id', 'fo_winning_player_id', 'fo_losing_player_id'])
            ->groupBy('nhl_game_id')->map(fn (Collection $plays): Collection => $plays
                ->flatMap(fn (object $play): array => [$play->fo_winning_player_id, $play->fo_losing_player_id])
                ->filter()->countBy());
        $time = [];
        $countedShifts = [];
        foreach ($games as $game) {
            $boundaries = [];
            foreach ($shifts->where('nhl_game_id', $game->nhl_game_id) as $shift) {
                if (! in_array($shift->team_abbrev, [$game->home_team_abbrev, $game->away_team_abbrev], true)) {
                    continue;
                }
                $boundaries[(int) $shift->start_game_seconds]['start'][] = $shift;
                $boundaries[(int) $shift->end_game_seconds]['end'][] = $shift;
            }
            ksort($boundaries, SORT_NUMERIC);
            $active = [];
            $previous = null;
            foreach ($boundaries as $second => $changes) {
                $counts = [];
                foreach ($active as $shift) {
                    $kind = $shift->unit_type === 'G' ? 'goalies' : 'skaters';
                    $counts[$shift->team_abbrev][$kind] = ($counts[$shift->team_abbrev][$kind] ?? 0)
                        + $members->get($shift->unit_id, collect())->count();
                }
                $fiveOnFive = count($counts) === 2 && collect($counts)->every(
                    fn (array $count): bool => ($count['skaters'] ?? 0) === 5 && ($count['goalies'] ?? 0) === 1
                );
                if ($previous !== null && $fiveOnFive) {
                    foreach ($active as $shift) {
                        if ($shift->unit_type === 'F' && $members->get($shift->unit_id, collect())->count() === 3) {
                            $key = $game->nhl_game_id.':'.$shift->unit_id;
                            $time[$key] ??= ['unit_id' => (int) $shift->unit_id, 'game_id' => (int) $game->nhl_game_id,
                                'team' => $shift->team_abbrev, 'toi_seconds' => 0, 'shifts' => 0, 'gf' => 0, 'ga' => 0, 'cf' => 0, 'ca' => 0];
                            $time[$key]['toi_seconds'] += $second - $previous;
                            if (! isset($countedShifts[$key][$shift->id])) {
                                $time[$key]['shifts']++;
                                $countedShifts[$key][$shift->id] = true;
                            }
                        }
                    }
                }
                foreach ($changes['end'] ?? [] as $shift) {
                    unset($active[$shift->id]);
                }
                foreach ($changes['start'] ?? [] as $shift) {
                    $active[$shift->id] = $shift;
                }
                $previous = $second;
            }
        }

        $attemptPredicate = app(NhlPbpEventNormalizer::class)->shotAttemptSqlPredicate('p');
        $events = DB::table('event_unit_shifts as e')->join('nhl_unit_shifts as s', 's.id', '=', 'e.unit_shift_id')
            ->join('play_by_plays as p', 'p.id', '=', 'e.event_id')->join('nhl_units as u', 'u.id', '=', 's.unit_id')
            ->whereIn('s.nhl_game_id', $games->pluck('nhl_game_id'))->whereColumn('p.nhl_game_id', 's.nhl_game_id')
            ->where('u.unit_type', 'F')->where('p.situation_code', '1551')->whereRaw($attemptPredicate)
            ->distinct()->get(['s.nhl_game_id', 's.unit_id', 's.team_id', 'p.id', 'p.event_owner_team_id', 'p.type_desc_key']);
        foreach ($events as $event) {
            $key = $event->nhl_game_id.':'.$event->unit_id;
            $game = $games->firstWhere('nhl_game_id', $event->nhl_game_id);
            if (! isset($time[$key]) || ! in_array((int) $event->event_owner_team_id, [(int) $game->home_team_id, (int) $game->away_team_id], true)) {
                continue;
            }
            $for = (int) $event->event_owner_team_id === (int) $event->team_id;
            $time[$key][$for ? 'cf' : 'ca']++;
            if ($event->type_desc_key === 'goal') {
                $time[$key][$for ? 'gf' : 'ga']++;
            }
        }

        return collect($time)->map(function (array $row) use ($members, $games, $faceoffs): array {
            $game = $games->firstWhere('nhl_game_id', $row['game_id']);
            $side = $game->home_team_abbrev === $row['team'] ? 'home' : 'away';
            return [...$row, 'goal_diff' => $row['gf'] - $row['ga'], 'corsi' => $row['cf'] - $row['ca'],
                'cf_pct' => $row['cf'] + $row['ca'] > 0 ? $row['cf'] / ($row['cf'] + $row['ca']) * 100 : null,
                'players' => $this->orderedLinePlayers($members[$row['unit_id']], $faceoffs->get($row['game_id'], collect())),
                'team_logo' => $game->{$side.'_team_logo'},
                'url' => route('stats.units.index', ['nhl_game_id' => $row['game_id'], 'team' => $row['team'],
                    'season_id' => $game->season_id, 'game_type' => $game->game_type, 'pos' => ['F'], 'sum' => 0])];
        })->sortBy([['goal_diff', 'desc'], ['cf_pct', 'desc'], ['toi_seconds', 'desc'], ['unit_id', 'asc']])
            ->values()->all();
    }

    /** Put the game's busiest faceoff taker at C, then maximize listed wing eligibility with stable ties. */
    private function orderedLinePlayers(Collection $members, Collection $faceoffs): array
    {
        $players = $members->values()->all();
        $best = [0, 1, 2];
        $bestScore = -1;
        $bestDraws = -1;
        foreach ([[0, 1, 2], [0, 2, 1], [1, 0, 2], [1, 2, 0], [2, 0, 1], [2, 1, 0]] as $order) {
            $draws = (int) $faceoffs->get($players[$order[1]]->nhl_id, 0);
            $score = 0;
            foreach ($order as $slot => $index) {
                $positions = preg_split('/[^A-Z]+/', strtoupper((string) $players[$index]->position), -1, PREG_SPLIT_NO_EMPTY);
                $eligible = [['L', 'LW'], ['C'], ['R', 'RW']][$slot];
                $score += count(array_intersect($positions, $eligible)) > 0 ? 1 : 0;
            }
            if ($draws > $bestDraws || ($draws === $bestDraws && $score > $bestScore)) {
                $best = $order;
                $bestScore = $score;
                $bestDraws = $draws;
            }
        }

        return array_map(fn (int $index): string => $players[$index]->full_name
            ?: trim($players[$index]->first_name.' '.$players[$index]->last_name), $best);
    }

    /** Compare ten-game windows with twenty results available, otherwise five-game windows. */
    private function teamTrends(Collection $games, Collection $boxscores): array
    {
        $teams = [];
        $ppByTeamGame = $boxscores->groupBy(fn (object $row): string => $row->nhl_game_id.':'.$row->nhl_team_id)
            ->map(fn (Collection $rows): int => (int) $rows->sum('power_play_goals'));
        foreach ($games as $game) {
            if ($game->home_team_score === null || $game->away_team_score === null) {
                continue;
            }
            foreach (['home' => 'away', 'away' => 'home'] as $side => $opponent) {
                $team = $game->{$side.'_team_abbrev'};
                $name = trim((string) $game->{$side.'_team_place_name'}.' '.(string) $game->{$side.'_team_common_name'});
                $teams[$team][] = ['team' => $team, 'name' => $name ?: $team, 'logo' => $game->{$side.'_team_logo'},
                    'gf' => (int) $game->{$side.'_team_score'}, 'ga' => (int) $game->{$opponent.'_team_score'},
                    'ppg' => $ppByTeamGame->get($game->nhl_game_id.':'.$game->{$side.'_team_id'}),
                ];
            }
        }
        return collect($teams)->map(function (array $rows): array {
            $window = count($rows) >= 20 ? 10 : 5;
            $current = collect(array_slice($rows, 0, $window));
            $previous = collect(array_slice($rows, $window, $window));

            return ['team' => $rows[0]['team'], 'name' => $rows[0]['name'], 'logo' => $rows[0]['logo'], 'games' => $current->count(),
                'comparison_games' => $window, 'previous_games' => $previous->count(),
                'offense' => $current->avg('gf'), 'defense' => $current->avg('ga'),
                'special_teams' => $current->every(fn ($row): bool => $row['ppg'] !== null) ? $current->avg('ppg') : null,
                'offense_delta' => $previous->isNotEmpty() ? $current->avg('gf') - $previous->avg('gf') : null,
                'defense_delta' => $previous->isNotEmpty() ? $current->avg('ga') - $previous->avg('ga') : null,
                'special_teams_delta' => $previous->isNotEmpty()
                    && $current->concat($previous)->every(fn ($row): bool => $row['ppg'] !== null)
                    ? $current->avg('ppg') - $previous->avg('ppg') : null,
                'url' => route('games.index')];
        })->values()->all();
    }

    /** Measure player scoring form from the last five appearances against the previous five. */
    private function playerTrends(Collection $games, Collection $boxscores): array
    {
        $gameOrder = $games->pluck('nhl_game_id')->flip();
        $byId = $games->keyBy('nhl_game_id');

        return $boxscores->where('position', '!=', 'G')->sortBy(fn ($row): int => (int) $gameOrder->get($row->nhl_game_id))
            ->groupBy('nhl_player_id')->map(function (Collection $rows) use ($byId): ?array {
                $last = $rows->take(5);
                $prior = $rows->slice(5, 5);
                if ($prior->isEmpty()) {
                    return null;
                }
                $row = $rows->first();
                $game = $byId->get($row->nhl_game_id);
                $side = (int) $game?->home_team_id === (int) $row->nhl_team_id ? 'home' : 'away';
                $current = (float) $last->avg('points');
                $previous = (float) $prior->avg('points');

                return ['nhl_player_id' => (int) $row->nhl_player_id, 'name' => $row->full_name ?: $row->player_name,
                    'avatar' => $row->head_shot_url, 'position' => $row->position, 'team' => $game?->{$side.'_team_abbrev'},
                    'current' => $current, 'previous' => $previous, 'delta' => $current - $previous,
                    'delta_pct' => $previous > 0 ? ($current - $previous) / $previous * 100 : null,
                    'games' => $last->count(), 'previous_games' => $prior->count(),
                    'breakout' => $last->sum('points') >= 3 && $current > $previous && $current >= 2 * $previous,
                    'url' => 'https://www.nhl.com/player/'.$row->nhl_player_id];
            })->filter()->sortByDesc('delta')->take(100)->values()->all();
    }

    /** Search public canonical players and the already-fetched news feed, without another provider call. */
    private function search(string $query, Collection $articles): array
    {
        $players = Player::query()->whereNotNull('nhl_id')->where(function ($builder) use ($query): void {
            $builder->whereRaw('LOWER(full_name) LIKE ?', ['%'.mb_strtolower($query).'%'])
                ->orWhereRaw('LOWER(team_abbrev) = ?', [mb_strtolower($query)]);
        })->orderBy('full_name')->limit(8)->get(['nhl_id', 'full_name', 'position', 'team_abbrev', 'head_shot_url']);

        return ['players' => $players->map(fn (Player $player): array => [
            'name' => $player->full_name, 'position' => $player->position, 'team' => $player->team_abbrev,
            'avatar' => $player->head_shot_url, 'url' => 'https://www.nhl.com/player/'.$player->nhl_id,
        ])->all(), 'stories' => $articles->filter(fn (array $article): bool => str_contains(mb_strtolower($article['title'].' '.$article['summary']), mb_strtolower($query)))->values()->all()];
    }
}
