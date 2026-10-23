<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Read public season leaders from stored NHL results without imports or predictions. */
class NhlStatsDashboardPayload
{
    public const CACHE_PREFIX = 'nhl:stats:leaders:v12:';
    public const TEAM_CACHE_PREFIX = 'nhl:stats:teams:v2:';

    /** Reuse the homepage's factual comparisons, independently of its editorial feed. */
    public function __construct(private readonly NhlDashboardPayload $dashboard)
    {
    }

    /** Return season leaders and current completed-night highlights for the stats landing. */
    public function build(?string $season = null, string $view = 'leaders'): array
    {
        $season = $season !== null && preg_match('/^\d{8}$/', $season) ? $season : $this->latestPreparedSeason();
        if ($season === '') {
            return ['season' => '', 'cache_status' => 'ready', 'hero' => null, 'goalie_hero' => null,
                'categories' => [], 'goalie_categories' => [], 'skaters' => [], 'goalies' => [],
                'rookies' => [], 'teams' => [], 'mobile_leaders' => [], 'player_trends' => []];
        }
        $key = $this->cacheKey($season, $view);
        $facts = Cache::get($key);
        if (! is_array($facts)) {
            $facts = Cache::lock($key.':build', 240)->block(180, function () use ($key, $season, $view): array {
                $prepared = Cache::get($key);
                return is_array($prepared) ? $prepared : $this->refreshCachedFacts($season, $view);
            });
        }
        $facts['cache_status'] = 'ready';

        return $view === 'leaders' ? [...$facts, ...$this->dashboard->statsHighlights()] : $facts;
    }

    /** Publish source-triggered facts only after all calculations succeed, retaining them until refreshed. */
    public function refreshCachedFacts(string $season, string $view): array
    {
        if (! preg_match('/^\d{8}$/', $season) || ! in_array($view, ['leaders', 'skaters', 'goalies', 'teams'], true)) {
            throw new InvalidArgumentException('Only supported public season views can be cached.');
        }
        $facts = $this->seasonFacts($season, $view);
        $facts['prepared_at'] = now()->toIso8601String();
        $key = $this->cacheKey($season, $view);
        // Provider failures leave PP unavailable; retry those cold snapshots instead of retaining gaps forever.
        $published = $view === 'teams' && ! ($facts['team_pp_complete'] ?? false)
            ? Cache::put($key, $facts, now()->addMinutes(5))
            : Cache::forever($key, $facts);
        if (! $published) {
            throw new \RuntimeException('Public stats cache publication failed.');
        }
        if ($view === 'leaders') {
            // The publisher must make /stats usable even if coordinator metadata is missing.
            $this->refreshSeasonCatalogue();
        }

        return $facts;
    }

    /** Version the expanded Teams snapshot without invalidating ready player and Leaders data. */
    public function cacheKey(string $season, string $view): string
    {
        return $view === 'teams' ? self::TEAM_CACHE_PREFIX.$season : self::CACHE_PREFIX.$view.':'.$season;
    }

    /** Apply team/date selections to prepared game facts without source queries on warm reads. */
    public function teamView(string $season, array $filters = []): array
    {
        $facts = $this->build($season, 'teams');
        $type = (int) ($filters['game_type'] ?? 2);
        $rows = collect($facts['team_games'] ?? [])->filter(static function (array $row) use ($type, $filters): bool {
            return $row['game_type'] === $type
                && (empty($filters['from']) || $row['date'] >= $filters['from'])
                && (empty($filters['to']) || $row['date'] <= $filters['to']);
        });
        $teams = $this->aggregateTeamRows($rows);
        $facts['team_categories'] = $this->teamCategories($teams);
        $facts['team_hero'] = collect($teams)->sortByDesc('win_pct')->first();
        $facts['team_filters'] = ['game_type' => $type, 'period' => $filters['period'] ?? 'season',
            'from' => $filters['from'] ?? '', 'to' => $filters['to'] ?? '', 'slice' => $filters['slice'] ?? 'total'];
        $facts['teams'] = $teams;
        // Raw per-game facts stay server-side; the browser receives only the selected team rows.
        unset($facts['team_games']);
        return $facts;
    }

    /** Resolve the default season, repairing missing catalogue metadata on demand. */
    public function latestPreparedSeason(): string
    {
        $season = Cache::get(self::CACHE_PREFIX.'latest-season');
        if ($season === null) {
            $this->refreshSeasonCatalogue();
            $season = Cache::get(self::CACHE_PREFIX.'latest-season', '');
        }
        return (string) $season;
    }

    /** Publish current-season metadata during background preparation or a cold page visit. */
    public function refreshSeasonCatalogue(): void
    {
        $season = (string) DB::table('nhl_season_stats')->where('game_type', 2)->where('gp', '>', 0)->max('season_id');
        if (! Cache::forever(self::CACHE_PREFIX.'latest-season', $season)) {
            throw new \RuntimeException('Public stats season catalogue publication failed.');
        }
    }

    /** Normalize actual season totals and rates; missing denominators remain unavailable. */
    private function seasonFacts(string $season, string $view): array
    {
        if ($view === 'teams') {
            return $this->teamSeasonFacts($season);
        }
        $previousSeason = preg_match('/^\d{8}$/', $season) ? (string) ((int) $season - 10001) : '';
        $prior = DB::table('nhl_season_stats')->where('season_id', $previousSeason)->where('game_type', 2)
            ->where('gp', '>', 0)->get()->keyBy('nhl_player_id');
        $onIce = $view !== 'leaders' ? collect() : DB::table('nhl_player_game_strength_summaries as s')
            ->join('nhl_games as g', 'g.nhl_game_id', '=', 's.nhl_game_id')
            ->where('g.season_id', $season)->where('g.game_type', 2)->where('s.strength', 'EV')
            ->whereIn('g.game_state', ['OFF', 'FINAL'])->groupBy('s.nhl_player_id')
            ->selectRaw('s.nhl_player_id, SUM(s.satf) AS cf, SUM(s.sata) AS ca')->get()->keyBy('nhl_player_id');
        $model = in_array($view, ['leaders', 'goalies'], true) ? $this->goalModelId($season) : null;
        $expected = $view === 'leaders' ? $this->expectedFor($season, $model) : collect();
        $against = $model !== null ? $this->expectedAgainst($season, $model) : collect();
        $rows = DB::table('nhl_season_stats as s')->join('players as p', 'p.nhl_id', '=', 's.nhl_player_id')
            ->leftJoin('nhl_teams as t', 't.nhl_id', '=', 's.nhl_team_id')
            ->where('s.season_id', $season)->where('s.game_type', 2)->where('s.gp', '>', 0)
            ->get(['s.*', 'p.full_name', 'p.first_name', 'p.last_name', 'p.position', 'p.is_goalie',
                'p.head_shot_url', 'p.hero_image_url', 'p.team_abbrev as player_team', 't.abbrev as team']);
        $players = $rows->map(function (object $row) use ($prior, $onIce, $expected, $against, $model): array {
            $old = $prior->get($row->nhl_player_id);
            $ice = $onIce->get($row->nhl_player_id);
            $gp = (int) $row->gp;
            $team = $row->team ?: $row->player_team;
            $goalie = $row->position === 'G' || (bool) $row->is_goalie;
            $faced = $against->get($row->nhl_player_id);
            $completeGoalieScoring = $goalie && $faced !== null && (int) $faced->games === $gp
                && (int) $faced->goals_against === (int) $row->ga && $faced->xga !== null;
            $gsaxReason = match (true) {
                ! $goalie => null,
                $model === null => 'No scored xG data is available for this season.',
                $faced === null => 'No goalie-facing attempts are available for this season.',
                $faced->xga === null => $faced->scored_attempts.' of '.$faced->attempts.' attempts have xG scores.',
                (int) $faced->games !== $gp => 'Goalie data covers '.$faced->games.' of '.$gp.' appearances.',
                (int) $faced->goals_against !== (int) $row->ga => 'Imported goals allowed do not match the season total.',
                default => null,
            };
            $stats = ['points' => (int) $row->pts, 'goals' => (int) $row->g, 'assists' => (int) $row->a,
                'ppg' => (float) $row->pts / $gp, 'sog' => (int) $row->sog, 'hits' => (int) $row->h,
                'ppp' => (int) $row->ppp, 'blocks' => (int) $row->b, 'pim' => (int) $row->pim,
                'toi' => $row->toi !== null ? (float) $row->toi / $gp : null,
                'gaa' => $goalie && $row->toi > 0 ? (float) $row->ga * 3600 / $row->toi : null,
                'sv_pct' => $goalie && $row->sa > 0 ? (float) $row->sv / $row->sa * 100 : null,
                'wins' => (int) $row->wins, 'shutouts' => (int) $row->so,
                'quality_starts' => $goalie ? (int) $row->quality_starts : null,
                'quality_start_pct' => $goalie && $row->starts > 0 ? (float) $row->quality_starts / $row->starts * 100 : null,
                'saves' => $goalie ? (int) $row->sv : null,
                'ev_sv_pct' => $goalie && $row->evsa > 0 ? (float) $row->evsv / $row->evsa * 100 : null,
                'gsax' => $completeGoalieScoring ? (float) $faced->xga - (int) $row->ga : null,
                'corsi' => $ice ? (int) $ice->cf - (int) $ice->ca : null,
                'cf_pct' => $ice && $ice->cf + $ice->ca > 0 ? $ice->cf / ($ice->cf + $ice->ca) * 100 : null,
                'xgf' => $expected->get($row->nhl_player_id)?->xgf !== null
                    ? (float) $expected->get($row->nhl_player_id)->xgf : null];
            $deltas = [];
            foreach (['points' => 'pts', 'goals' => 'g', 'assists' => 'a', 'ppg' => 'pts', 'sog' => 'sog', 'hits' => 'h', 'ppp' => 'ppp', 'blocks' => 'b'] as $key => $column) {
                $base = $old && $old->gp > 0 ? (float) $old->{$column} / $old->gp : null;
                $rate = $key === 'ppg' ? $stats[$key] : $stats[$key] / $gp;
                $deltas[$key] = $base > 0 ? ($rate - $base) / $base * 100 : null;
            }

            return ['id' => (int) $row->nhl_player_id, 'name' => $row->full_name ?: trim($row->first_name.' '.$row->last_name),
                'first_name' => $row->first_name, 'last_name' => $row->last_name, 'team' => $team,
                'position' => $row->position, 'goalie' => $goalie, 'gp' => $gp,
                'avatar' => $row->head_shot_url, 'hero' => $row->hero_image_url,
                'logo' => $team ? 'https://assets.nhle.com/logos/nhl/svg/'.$team.'_light.svg' : null,
                'stats' => $stats, 'deltas' => $deltas, 'gsax_reason' => $gsaxReason,
                'url' => 'https://www.nhl.com/player/'.$row->nhl_player_id];
        });
        $skaters = $players->where('goalie', false)->values();
        $rookieIds = $view === 'leaders'
            ? app(ProspectEligibilityService::class)->eligibleNhlPlayerIds($skaters->pluck('id')->all(), for_drafting: false)
            : [];
        $goalies = $players->where('goalie', true)->values();
        $goalieCategories = collect(['quality_starts' => 'Quality Starts', 'gsax' => 'GSAx', 'ev_sv_pct' => 'EVSV%'])
            ->map(function (string $label, string $key) use ($goalies, $model): array {
                $leader = $goalies->filter(fn (array $player): bool => $player['stats'][$key] !== null)
                    ->sort(fn (array $a, array $b): int => $b['stats'][$key] <=> $a['stats'][$key]
                        ?: $b['gp'] <=> $a['gp'] ?: $a['id'] <=> $b['id'])->first();

                return ['key' => $key, 'label' => $label, 'player' => $leader,
                    'unavailable_label' => $key === 'gsax' ? ($model === null ? 'No scored xG' : 'Incomplete data') : 'Unavailable',
                    'unavailable_reason' => $key === 'gsax' ? ($model === null
                        ? 'No scored xG data is available for this season.'
                        : 'No goalie has complete scored attempts matching their season appearances and goals allowed.') : null];
            })->values()->all();
        $categories = collect(['points' => 'Points', 'goals' => 'Goals', 'assists' => 'Assists', 'ppg' => 'P/GP', 'sog' => 'SOG', 'hits' => 'Hits', 'ppp' => 'PPP', 'blocks' => 'Blocks'])
            ->map(function (string $label, string $key) use ($skaters): array {
                $leader = $skaters->sort(fn (array $a, array $b): int => $b['stats'][$key] <=> $a['stats'][$key]
                    ?: $b['stats']['points'] <=> $a['stats']['points'] ?: $a['id'] <=> $b['id'])->first();

                return ['key' => $key, 'label' => $label, 'player' => $leader, 'history' => []];
            })->values();
        $goalieHero = $goalieCategories[0]['player'] ?? null;
        $leaders = $categories->pluck('player.id')->push($goalieHero['id'] ?? null)->filter()->unique();
        $history = $leaders->isEmpty() ? collect() : DB::table('nhl_boxscores as b')
            ->join('nhl_games as g', 'g.nhl_game_id', '=', 'b.nhl_game_id')
            ->where('g.season_id', $season)->where('g.game_type', 2)->whereIn('g.game_state', ['OFF', 'FINAL'])
            ->whereIn('b.nhl_player_id', $leaders)->where('b.toi_seconds', '>', 0)
            ->orderByDesc('g.game_date')->orderByDesc('g.nhl_game_id')
            ->selectRaw('b.power_play_goals + b.power_play_assists AS ppp')
            ->addSelect(['b.nhl_player_id', 'b.points', 'b.goals', 'b.assists', 'b.sog', 'b.hits', 'b.blocks', 'b.sweater_number'])
            ->get()->groupBy('nhl_player_id');
        $categories = $categories->map(function (array $category) use ($history): array {
            $column = ['ppg' => 'points'][$category['key']] ?? $category['key'];
            $category['history'] = $history->get($category['player']['id'] ?? null, collect())
                ->take(5)->reverse()->pluck($column)->map(fn ($value): int => (int) $value)->values()->all();

            return $category;
        });
        $hero = $categories->first()['player'] ?? null;
        if ($hero !== null) {
            $hero['number'] = $history->get($hero['id'], collect())->first()?->sweater_number;
            $hero['ranks'] = collect(['points', 'ppg', 'sog', 'toi'])->mapWithKeys(fn (string $key): array => [
                $key => $hero['stats'][$key] === null ? null : 1 + $skaters->filter(fn (array $row): bool => $row['stats'][$key] !== null
                    && $row['stats'][$key] > $hero['stats'][$key])->count(),
            ])->all();
        }
        if ($goalieHero !== null) {
            $goalieHero['number'] = $history->get($goalieHero['id'], collect())->first()?->sweater_number;
            $goalieHero['ranks'] = collect(['quality_starts', 'quality_start_pct', 'gsax', 'ev_sv_pct', 'saves'])->mapWithKeys(fn (string $key): array => [
                $key => $goalieHero['stats'][$key] === null ? null : 1 + $goalies->filter(fn (array $row): bool => $row['stats'][$key] !== null
                    && $row['stats'][$key] > $goalieHero['stats'][$key])->count(),
            ])->all();
        }

        $teams = in_array($view, ['leaders', 'teams'], true) ? $this->teams($season, $previousSeason, $model, $view === 'leaders') : [];

        return ['season' => $season, 'hero' => $hero, 'goalie_hero' => $goalieHero, 'categories' => $categories->all(),
            'skaters' => $view === 'leaders' ? $skaters->all() : [], 'goalies' => $view === 'leaders' ? $goalies->all() : [], 'goalie_categories' => $goalieCategories,
            'rookies' => $skaters->whereIn('id', $rookieIds)->values()->all(), 'rookie_note' => 'No eligible rookie skaters have season stats yet.',
            'teams' => $teams,
            'mobile_leaders' => $view === 'leaders' ? $this->mobileLeaders($season, $skaters, $goalies, collect($teams)) : []];
    }

    /** Prepare the four compact leader rows as part of the shared season cache. */
    private function mobileLeaders(string $season, Collection $skaters, Collection $goalies, Collection $teams): array
    {
        $teamEntities = $teams->map(fn (array $team): array => [
            'id' => $team['team'], 'name' => $team['name'], 'short_name' => $team['team'],
            'team' => $team['team'], 'avatar' => $team['logo'], 'url' => '/stats?view=teams&season_id='.$season,
            'external' => false, 'stats' => ['goals' => $team['gf'], 'win_pct' => $team['win_pct'],
                'cf_pct' => $team['cf_pct'], 'gax' => $team['gax'], 'gsax' => $team['gsax']],
        ]);
        $rows = [
            ['title' => 'All Skaters', 'href' => '/stats?view=skaters&season_id='.$season, 'entities' => $skaters,
                'metrics' => ['goals' => 'G', 'assists' => 'A', 'ppp' => 'PPP', 'sog' => 'SOG', 'blocks' => 'Blocks', 'hits' => 'Hits']],
            ['title' => 'All Goalies', 'href' => '/stats?view=goalies&season_id='.$season, 'entities' => $goalies,
                'metrics' => ['wins' => 'W', 'ev_sv_pct' => 'EVSV%', 'quality_starts' => 'QS', 'gsax' => 'GSAx', 'saves' => 'SVs', 'quality_start_pct' => 'QS%']],
            ['title' => 'All Teams', 'href' => '/stats?view=teams&season_id='.$season, 'entities' => $teamEntities,
                'metrics' => ['win_pct' => 'W%', 'goals' => 'G', 'cf_pct' => 'Corsi%', 'gax' => 'GAx', 'gsax' => 'GSAx']],
            ['title' => 'All Prospects', 'href' => '/stats?view=skaters&perspective=prospects&season_id='.$season,
                'entities' => $this->prospects($season),
                'metrics' => ['goals' => 'G', 'assists' => 'A', 'points' => 'PTS', 'g_per_gp' => 'G/GP', 'pts_per_gp' => 'PTS/GP']],
        ];

        return array_map(function (array $row): array {
            $cards = [];
            foreach ($row['metrics'] as $key => $label) {
                $leader = $row['entities']->filter(fn (array $entity): bool => is_numeric($entity['stats'][$key] ?? null))
                    ->sort(fn (array $a, array $b): int => $b['stats'][$key] <=> $a['stats'][$key]
                        ?: ($b['stats']['points'] ?? 0) <=> ($a['stats']['points'] ?? 0)
                        ?: $a['id'] <=> $b['id'])->first();
                $cards[] = ['key' => $key, 'label' => $label, 'value' => $leader['stats'][$key] ?? null,
                    'entity' => $leader === null ? null : [
                        'name' => $leader['name'],
                        'short_name' => $leader['short_name'] ?? (! empty($leader['last_name'])
                            ? (! empty($leader['first_name']) ? mb_substr($leader['first_name'], 0, 1).'. ' : '').$leader['last_name']
                            : $leader['name']),
                        'subtitle' => $leader['league'] ?? $leader['team'] ?? null,
                        'image' => $leader['avatar'] ?? $leader['logo'] ?? null,
                        'url' => $leader['url'], 'external' => $leader['external'] ?? true,
                    ]];
            }

            return ['title' => $row['title'], 'href' => $row['href'], 'cards' => $cards];
        }, $rows);
    }

    /** Match the non-NHL Prospects report, keeping each player's league and pooled GP denominators separate. */
    private function prospects(string $season): Collection
    {
        $totals = DB::table('stats')->where('season_id', $season)->where('game_type_id', 2)
            ->where('is_prospect', true)->where('league_abbrev', '<>', 'NHL')
            ->groupBy('player_id', 'league_abbrev')->havingRaw('SUM(gp) > 0')
            ->select(['player_id', 'league_abbrev'])
            ->selectRaw('SUM(gp) AS gp, SUM(g) AS goals, SUM(a) AS assists, SUM(pts) AS points');

        return DB::query()->fromSub($totals, 's')->join('players as p', 'p.id', '=', 's.player_id')
            ->where(fn ($query) => $query->whereNull('p.is_goalie')->orWhere('p.is_goalie', false))
            ->where(fn ($query) => $query->whereNull('p.position')->orWhere('p.position', '<>', 'G'))
            ->where(fn ($query) => $query->whereNull('p.pos_type')->orWhere('p.pos_type', '<>', 'G'))
            ->get(['s.*', 'p.full_name', 'p.first_name', 'p.last_name', 'p.nhl_id', 'p.head_shot_url'])
            ->map(fn (object $row): array => [
                'id' => $row->player_id.'|'.$row->league_abbrev, 'name' => $row->full_name ?: trim($row->first_name.' '.$row->last_name),
                'first_name' => $row->first_name, 'last_name' => $row->last_name, 'league' => $row->league_abbrev,
                'avatar' => $row->head_shot_url, 'external' => $row->nhl_id !== null,
                'url' => $row->nhl_id !== null ? 'https://www.nhl.com/player/'.$row->nhl_id : '/stats?view=skaters&perspective=prospects&season_id='.$season,
                'stats' => ['goals' => (int) $row->goals, 'assists' => (int) $row->assists, 'points' => (int) $row->points,
                    'g_per_gp' => (float) $row->goals / $row->gp, 'pts_per_gp' => (float) $row->points / $row->gp],
            ]);
    }

    /** Read scored on-ice xGF from one existing goal model, deduplicating linked attempts. */
    private function expectedFor(string $season, ?int $model): Collection
    {
        if ($model === null) {
            return collect();
        }
        $attempts = DB::table('nhl_shot_attempts_facts as f')
            ->join('nhl_games as g', 'g.nhl_game_id', '=', 'f.nhl_game_id')
            ->join('event_unit_shifts as e', 'e.event_id', '=', 'f.play_by_play_id')
            ->join('nhl_unit_shifts as s', 's.id', '=', 'e.unit_shift_id')
            ->join('nhl_unit_shift_players as u', 'u.unit_shift_id', '=', 's.id')
            ->join('players as player', 'player.id', '=', 'u.player_id')
            ->join('nhl_expected_goals_models as m', fn ($join) => $join->where('m.id', $model))
            ->leftJoinSub($this->scoredGoalPredictions(), 'p', function ($join) use ($model): void {
                $join->on('p.shot_attempt_fact_id', '=', 'f.id')->where('p.expected_goals_model_id', $model)
                    ->where('p.prediction_target', 'goal')->where('p.is_scored', true);
            })->where('f.season_id', $season)->where('g.game_type', 2)->whereIn('g.game_state', ['OFF', 'FINAL'])
            ->where('f.is_shot_attempt', true)
            ->where(fn ($query) => $query->whereNull('f.is_empty_net')->orWhere('f.is_empty_net', false))
            ->where(fn ($query) => $query->whereNull('f.period_type')->orWhere('f.period_type', '!=', 'SO'))
            ->whereColumn('s.nhl_game_id', 'f.nhl_game_id')->whereColumn('s.team_id', 'f.team_id')
            ->whereNotNull('player.nhl_id')->distinct()->select(['player.nhl_id', 'f.id'])
            ->selectRaw($this->historicalGoalExpectation().' AS xg');

        return DB::query()->fromSub($attempts, 'attempts')->groupBy('nhl_id')
            ->selectRaw('nhl_id, CASE WHEN COUNT(xg) = COUNT(*) THEN SUM(xg) ELSE NULL END AS xgf')
            ->get()->keyBy('nhl_id');
    }

    /** Select a stored goal model actually scoring completed games in the displayed season. */
    private function goalModelId(string $season): ?int
    {
        $id = DB::table('nhl_expected_goals_models as m')->where('m.prediction_target', 'goal')
            ->whereExists(function ($query) use ($season): void {
                $query->selectRaw('1')->fromSub($this->scoredGoalPredictions(), 'p')
                    ->join('nhl_shot_attempts_facts as f', 'f.id', '=', 'p.shot_attempt_fact_id')
                    ->join('nhl_games as g', 'g.nhl_game_id', '=', 'f.nhl_game_id')
                    ->whereColumn('p.expected_goals_model_id', 'm.id')->where('p.prediction_target', 'goal')
                    ->where('p.is_scored', true)->whereNotNull('p.xg')->where('f.season_id', $season)
                    ->where('g.game_type', 2)->whereIn('g.game_state', ['OFF', 'FINAL']);
            })->orderByDesc('m.trained_at')->orderByDesc('m.id')->value('m.id');

        return $id === null ? null : (int) $id;
    }

    /** Prefer current SAT ratings, retaining legacy backfills only where no newer rating exists. */
    private function scoredGoalPredictions(): Builder
    {
        $scores = DB::table('nhl_shot_attempt_model_scores')->where('prediction_target', 'goal')->where('is_scored', true)
            ->select(['expected_goals_model_id', 'shot_attempt_fact_id', 'prediction_target', 'is_scored', 'probability as xg']);
        $legacy = DB::table('nhl_shot_attempt_predictions as legacy')->where('legacy.prediction_target', 'goal')
            ->where('legacy.is_scored', true)
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')->from('nhl_shot_attempt_model_scores as newer')
                    ->whereColumn('newer.expected_goals_model_id', 'legacy.expected_goals_model_id')
                    ->whereColumn('newer.shot_attempt_fact_id', 'legacy.shot_attempt_fact_id');
            })->select(['legacy.expected_goals_model_id', 'legacy.shot_attempt_fact_id', 'legacy.prediction_target', 'legacy.is_scored', 'legacy.xg']);

        return $scores->unionAll($legacy);
    }

    /** Match existing Train/Test profiles: SOG-trained goal expectations count actual SOG only. */
    private function historicalGoalExpectation(): string
    {
        return "CASE WHEN p.xg IS NULL THEN NULL
            WHEN m.feature_config->>'sample_mode' = 'sog' AND NOT COALESCE(f.is_shot_on_goal, false) THEN 0
            ELSE p.xg END";
    }

    /** Sum scored goalie-facing expectations, withholding partially scored attempt sets. */
    private function expectedAgainst(string $season, int $model): Collection
    {
        return DB::table('nhl_shot_attempts_facts as f')
            ->join('nhl_games as g', 'g.nhl_game_id', '=', 'f.nhl_game_id')
            ->join('nhl_expected_goals_models as m', fn ($join) => $join->where('m.id', $model))
            ->leftJoinSub($this->scoredGoalPredictions(), 'p', function ($join) use ($model): void {
                $join->on('p.shot_attempt_fact_id', '=', 'f.id')->where('p.expected_goals_model_id', $model)
                    ->where('p.prediction_target', 'goal')->where('p.is_scored', true);
            })
            ->where('f.season_id', $season)->where('g.game_type', 2)->whereIn('g.game_state', ['OFF', 'FINAL'])
            ->whereNotNull('f.goalie_player_id')->where('f.is_shot_attempt', true)
            ->where(fn ($query) => $query->whereNull('f.is_empty_net')->orWhere('f.is_empty_net', false))
            ->where(fn ($query) => $query->whereNull('f.period_type')->orWhere('f.period_type', '!=', 'SO'))
            ->groupBy('f.goalie_player_id')
            ->selectRaw('f.goalie_player_id, COUNT(DISTINCT f.nhl_game_id) AS games, COUNT(*) AS attempts, COUNT(p.xg) AS scored_attempts,
                SUM(CASE WHEN f.is_goal THEN 1 ELSE 0 END) AS goals_against,
                CASE WHEN COUNT(p.xg) = COUNT(*) THEN SUM('.$this->historicalGoalExpectation().') ELSE NULL END AS xga')
            ->get()->keyBy('goalie_player_id');
    }

    /** Prepare per-game team facts once, retaining historical team identity and explicit coverage. */
    private function teamSeasonFacts(string $season): array
    {
        app(ImportNhlBoxscore::class)->refreshMissingTeamPowerPlayTotals($season);
        $games = DB::table('nhl_games')->where('season_id', $season)->whereIn('game_type', [1, 2, 3])
            ->whereIn('game_state', ['OFF', 'FINAL'])->whereNotNull('home_team_score')->whereNotNull('away_team_score')
            ->orderBy('game_date')->orderBy('nhl_game_id')->get();
        $ids = $games->pluck('nhl_game_id');
        $boxes = DB::table('nhl_boxscores')->whereIn('nhl_game_id', $ids)->groupBy('nhl_game_id', 'nhl_team_id')
            ->selectRaw('nhl_game_id, nhl_team_id, SUM(hits) AS hits, SUM(blocks) AS blocks, SUM(sog) AS sog,
                SUM(penalty_minutes) AS pim, SUM(saves) AS saves')->get()
            ->keyBy(static fn (object $row): string => $row->nhl_game_id.':'.$row->nhl_team_id);
        $summaries = DB::table('nhl_game_summaries')->whereIn('nhl_game_id', $ids)->groupBy('nhl_game_id', 'nhl_team_id')
            ->selectRaw('nhl_game_id, nhl_team_id, SUM(sat) AS sat, SUM(hdsat) AS hdsat')->get()
            ->keyBy(static fn (object $row): string => $row->nhl_game_id.':'.$row->nhl_team_id);
        $attempts = DB::table('nhl_shot_attempts_facts')->whereIn('nhl_game_id', $ids)->where('is_shot_attempt', true)
            ->where(fn ($q) => $q->whereNull('period_type')->orWhere('period_type', '<>', 'SO'))
            ->where(fn ($q) => $q->whereNull('is_empty_net')->orWhere('is_empty_net', false))
            ->groupBy('nhl_game_id', 'team_id')->selectRaw("nhl_game_id, team_id,
                SUM(CASE WHEN (CASE WHEN UPPER(strength) IN ('EV','PP','PK') THEN UPPER(strength) ELSE UPPER(strength_bucket) END) = 'EV' THEN 1 ELSE 0 END) AS ev_sat")
            ->get()->keyBy(static fn (object $row): string => $row->nhl_game_id.':'.$row->team_id);
        $ends = DB::table('play_by_plays')->whereIn('nhl_game_id', $ids)->where('period_type', 'OT')
            ->whereIn('type_desc_key', ['goal', 'game-end', 'period-end'])->groupBy('nhl_game_id')
            ->selectRaw('nhl_game_id, MAX(seconds_in_game) AS elapsed')->get()->keyBy('nhl_game_id');
        $rows = [];
        foreach ($games as $game) {
            $outcome = is_string($game->game_outcome) ? json_decode($game->game_outcome, true) : (array) $game->game_outcome;
            $last = $outcome['lastPeriodType'] ?? $game->period_type;
            $regulation = (int) ($game->max_regulation_periods ?: 3) * 1200;
            $seconds = match ($last) {
                'REG' => $regulation,
                'SO' => (int) $game->game_type !== 3 ? $regulation + 300 : null,
                'OT' => $ends->get($game->nhl_game_id)?->elapsed,
                default => null,
            };
            if ($last === 'OT' && ($seconds === null || $seconds < $regulation
                || ((int) $game->game_type !== 3 && $seconds > $regulation + 300))) {
                $seconds = null;
            }
            foreach (['home' => 'away', 'away' => 'home'] as $side => $opponent) {
                $teamId = $game->{$side.'_team_id'};
                $key = $game->nhl_game_id.':'.$teamId;
                $box = $boxes->get($key);
                $summary = $summaries->get($key);
                $for = $attempts->get($key);
                $against = $attempts->get($game->nhl_game_id.':'.$game->{$opponent.'_team_id'});
                $won = $game->{$side.'_team_score'} > $game->{$opponent.'_team_score'};
                $rows[] = ['team' => $game->{$side.'_team_abbrev'},
                    'name' => trim((string) $game->{$side.'_team_place_name'}.' '.(string) $game->{$side.'_team_common_name'}) ?: $game->{$side.'_team_abbrev'},
                    'logo' => $game->{$side.'_team_logo'}, 'date' => (string) $game->game_date, 'game_type' => (int) $game->game_type,
                    'wins' => $won ? 1 : 0,
                    'points' => (int) $game->game_type === 2 ? ($won ? 2 : (in_array($last, ['OT', 'SO'], true) ? 1 : ($last === 'REG' ? 0 : null))) : null,
                    'gf' => (int) $game->{$side.'_team_score'}, 'ga' => (int) $game->{$opponent.'_team_score'},
                    'gaa_ga' => (int) $game->{$opponent.'_team_score'} - ($last === 'SO' && ! $won ? 1 : 0),
                    'toi_total_seconds' => $seconds === null ? null : (int) $seconds,
                    'hits' => $box ? (int) $box->hits : null, 'blocks' => $box ? (int) $box->blocks : null,
                    'sog' => $box ? (int) $box->sog : null, 'pim' => $box ? (int) $box->pim : null, 'saves' => $box ? (int) $box->saves : null,
                    'sat' => $summary ? (int) $summary->sat : null, 'hdsat' => $summary ? (int) $summary->hdsat : null,
                    'cf' => $for ? (int) $for->ev_sat : null, 'ca' => $against ? (int) $against->ev_sat : null,
                    'pp_goals' => $game->{$side.'_pp_goals'}, 'pp_opportunities' => $game->{$side.'_pp_opportunities'},
                    'pk_goals_against' => $game->{$opponent.'_pp_goals'}, 'pk_opportunities' => $game->{$opponent.'_pp_opportunities'}];
            }
        }
        $teams = $this->aggregateTeamRows(collect($rows)->where('game_type', 2));
        return ['season' => $season, 'team_games' => $rows, 'teams' => $teams,
            'team_pp_complete' => ! $games->contains(static fn (object $game): bool => $game->home_pp_goals === null
                || $game->home_pp_opportunities === null || $game->away_pp_goals === null || $game->away_pp_opportunities === null),
            'team_categories' => $this->teamCategories($teams), 'team_hero' => collect($teams)->sortByDesc('win_pct')->first(),
            'available_team_seasons' => DB::table('nhl_games')->whereIn('game_state', ['OFF','FINAL'])->distinct()->orderByDesc('season_id')->pluck('season_id')->all()];
    }

    /** Aggregate complete metric populations; a missing source in any selected game stays unavailable. */
    private function aggregateTeamRows(Collection $rows): array
    {
        return $rows->groupBy('team')->map(function (Collection $games): array {
            $first = $games->first();
            $row = ['id' => $first['team'], 'team' => $first['team'], 'name' => $first['name'], 'logo' => $first['logo'], 'gp' => $games->count()];
            foreach (['wins','points','gf','ga','gaa_ga','toi_total_seconds','hits','blocks','sog','pim','saves','sat','hdsat','cf','ca','pp_goals','pp_opportunities','pk_goals_against','pk_opportunities'] as $key) {
                $row[$key] = $games->contains(static fn (array $game): bool => $game[$key] === null) ? null : (int) $games->sum($key);
            }
            $row['win_pct'] = 100.0 * $row['wins'] / $row['gp'];
            $row['gaa'] = $row['toi_total_seconds'] > 0 ? $row['gaa_ga'] * 3600.0 / $row['toi_total_seconds'] : null;
            $row['cf_pct'] = $row['cf'] !== null && $row['ca'] !== null && $row['cf'] + $row['ca'] > 0 ? 100.0 * $row['cf'] / ($row['cf'] + $row['ca']) : null;
            $row['pp_pct'] = $row['pp_goals'] !== null && $row['pp_opportunities'] > 0 ? 100.0 * $row['pp_goals'] / $row['pp_opportunities'] : null;
            $row['pk_pct'] = $row['pk_goals_against'] !== null && $row['pk_opportunities'] > 0 ? (1 - $row['pk_goals_against'] / $row['pk_opportunities']) * 100.0 : null;
            return $row;
        })->values()->all();
    }

    /** Select the three team-category leaders independently; lower GAA ranks first. */
    private function teamCategories(array $teams): array
    {
        return collect(['win_pct' => 'W%', 'gaa' => 'GAA', 'cf_pct' => 'Corsi%'])->map(function (string $label, string $key) use ($teams): array {
            $eligible = collect($teams)->filter(static fn (array $team): bool => $team[$key] !== null);
            $leader = $key === 'gaa' ? $eligible->sortBy($key)->first() : $eligible->sortByDesc($key)->first();
            return ['key' => $key, 'label' => $label, 'team' => $leader];
        })->values()->all();
    }

    /** Aggregate team results once per completed game, keeping GF and GA separate. */
    private function teams(string $season, string $previousSeason, ?int $model = null, bool $advanced = false): array
    {
        $games = DB::table('nhl_games')->whereIn('season_id', [$season, $previousSeason])->where('game_type', 2)
            ->whereIn('game_state', ['OFF', 'FINAL'])->whereNotNull('home_team_score')->whereNotNull('away_team_score')
            ->orderByDesc('game_date')->orderByDesc('nhl_game_id')->get();
        $totals = [];
        foreach ($games as $game) {
            foreach (['home' => 'away', 'away' => 'home'] as $side => $opponent) {
                $team = $game->{$side.'_team_abbrev'};
                $key = $game->season_id.':'.$team;
                $totals[$key] ??= ['team' => $team, 'name' => trim((string) $game->{$side.'_team_place_name'}.' '.(string) $game->{$side.'_team_common_name'}) ?: $team,
                    'logo' => $game->{$side.'_team_logo'}, 'gp' => 0, 'gf' => 0, 'ga' => 0, 'wins' => 0, 'recent' => []];
                $totals[$key]['gp']++;
                $totals[$key]['gf'] += (int) $game->{$side.'_team_score'};
                $totals[$key]['ga'] += (int) $game->{$opponent.'_team_score'};
                $totals[$key]['wins'] += $game->{$side.'_team_score'} > $game->{$opponent.'_team_score'} ? 1 : 0;
                $totals[$key]['recent'][] = ['gf' => (int) $game->{$side.'_team_score'}, 'ga' => (int) $game->{$opponent.'_team_score'}];
            }
        }
        $stats = DB::table('nhl_boxscores as b')->join('nhl_games as g', 'g.nhl_game_id', '=', 'b.nhl_game_id')
            ->where('g.season_id', $season)->where('g.game_type', 2)->whereIn('g.game_state', ['OFF', 'FINAL'])
            ->groupBy('b.nhl_team_id')->selectRaw('b.nhl_team_id, COUNT(DISTINCT b.nhl_game_id) AS games,
                SUM(b.points) AS points, SUM(b.assists) AS assists, SUM(b.sog) AS sog,
                SUM(b.hits) AS hits, SUM(b.power_play_goals) AS ppg')->get()->keyBy('nhl_team_id');
        $ids = DB::table('nhl_teams')->pluck('nhl_id', 'abbrev');
        [$offense, $defense] = $advanced ? $this->teamAttemptTotals($season, $model) : [collect(), collect()];
        $result = [];
        foreach ($totals as $key => $team) {
            if (! str_starts_with($key, $season.':')) {
                continue;
            }
            $gp = $team['gp'];
            $old = $totals[$previousSeason.':'.$team['team']] ?? null;
            $box = $stats->get($ids->get($team['team']));
            $complete = $box !== null && (int) $box->games === $gp;
            $for = $offense->get($ids->get($team['team']));
            $against = $defense->get($ids->get($team['team']));
            $forCovered = $for !== null && (int) $for->games === $gp;
            $againstCovered = $against !== null && (int) $against->games === $gp;
            $evAttempts = (int) ($for?->ev_sat ?? 0) + (int) ($against?->ev_sat ?? 0);
            $result[] = [...$team, 'gf_pg' => $team['gf'] / $gp, 'ga_pg' => $team['ga'] / $gp,
                'win_pct' => 100.0 * $team['wins'] / $gp,
                'cf_pct' => $forCovered && $againstCovered && $evAttempts > 0 ? 100 * $for->ev_sat / $evAttempts : null,
                'gax' => $model !== null && $forCovered && (int) $for->scored === (int) $for->attempts ? (float) $for->goals - (float) $for->xg : null,
                'gsax' => $model !== null && $againstCovered && (int) $against->scored === (int) $against->attempts
                    && (int) $against->goalie_attempts === (int) $against->attempts ? (float) $against->xg - (float) $against->goals : null,
                'gf_delta' => $old ? $team['gf'] / $gp - $old['gf'] / $old['gp'] : null,
                'ga_delta' => $old ? $team['ga'] / $gp - $old['ga'] / $old['gp'] : null,
                'points' => $complete ? (int) $box->points : null, 'assists' => $complete ? (int) $box->assists : null,
                'sog' => $complete ? (int) $box->sog : null, 'hits' => $complete ? (int) $box->hits : null,
                'ppg_pg' => $complete ? (float) $box->ppg / $gp : null, 'url' => '/stats?view=teams'];
        }

        return $result;
    }

    /** Aggregate team attempt populations directly, preserving historical team identity and scored coverage. */
    private function teamAttemptTotals(string $season, ?int $model): array
    {
        $facts = DB::table('nhl_shot_attempts_facts as f')->join('nhl_games as g', 'g.nhl_game_id', '=', 'f.nhl_game_id')
            ->where('f.season_id', $season)->where('g.game_type', 2)->whereIn('g.game_state', ['OFF', 'FINAL'])
            ->where('f.is_shot_attempt', true)
            ->where(fn ($query) => $query->whereNull('f.is_empty_net')->orWhere('f.is_empty_net', false))
            ->where(fn ($query) => $query->whereNull('f.period_type')->orWhere('f.period_type', '<>', 'SO'))
            ->where(fn ($query) => $query->whereColumn('f.team_id', 'g.home_team_id')->orWhereColumn('f.team_id', 'g.away_team_id'))
            ->select(['f.nhl_game_id', 'f.team_id', 'f.goalie_player_id'])
            ->selectRaw('CASE WHEN f.team_id = g.home_team_id THEN g.away_team_id ELSE g.home_team_id END AS defending_team_id')
            ->selectRaw('CASE WHEN f.is_goal THEN 1 ELSE 0 END AS goal')
            ->selectRaw("CASE WHEN (CASE WHEN UPPER(f.strength) IN ('EV', 'PP', 'PK') THEN UPPER(f.strength) ELSE UPPER(f.strength_bucket) END) = 'EV' THEN 1 ELSE 0 END AS ev_sat");
        if ($model === null) {
            $facts->selectRaw('CAST(NULL AS DECIMAL(18,6)) AS xg');
        } else {
            $facts->join('nhl_expected_goals_models as m', fn ($join) => $join->where('m.id', $model))
                ->leftJoinSub($this->scoredGoalPredictions(), 'p', fn ($join) => $join->on('p.shot_attempt_fact_id', '=', 'f.id')
                    ->where('p.expected_goals_model_id', $model)->where('p.prediction_target', 'goal')->where('p.is_scored', true))
                ->selectRaw($this->historicalGoalExpectation().' AS xg');
        }
        $select = 'COUNT(DISTINCT nhl_game_id) AS games, COUNT(*) AS attempts, COUNT(xg) AS scored, SUM(xg) AS xg, SUM(goal) AS goals, SUM(ev_sat) AS ev_sat, COUNT(goalie_player_id) AS goalie_attempts';

        return [DB::query()->fromSub(clone $facts, 'attempt')->groupBy('team_id')->select('team_id')->selectRaw($select)->get()->keyBy('team_id'),
            DB::query()->fromSub(clone $facts, 'attempt')->groupBy('defending_team_id')->select('defending_team_id')->selectRaw($select)->get()->keyBy('defending_team_id')];
    }
}
