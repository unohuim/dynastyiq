<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Read public season leaders from stored NHL results without imports or predictions. */
class NhlStatsDashboardPayload
{
    /** Reuse the homepage's factual comparisons, independently of its editorial feed. */
    public function __construct(private readonly NhlDashboardPayload $dashboard)
    {
    }

    /** Return season leaders and current completed-night highlights for the stats landing. */
    public function build(?string $season = null, string $view = 'leaders'): array
    {
        $season = $season !== null && preg_match('/^\d{8}$/', $season)
            ? $season : (string) DB::table('nhl_season_stats')->where('game_type', 2)->where('gp', '>', 0)->max('season_id');
        $facts = Cache::remember('nhl:stats:leaders:v9:'.$view.':'.$season, 300, fn (): array => $this->seasonFacts($season, $view));

        return $view === 'leaders' ? [...$facts, ...$this->dashboard->statsHighlights()] : $facts;
    }

    /** Normalize actual season totals and rates; missing denominators remain unavailable. */
    private function seasonFacts(string $season, string $view): array
    {
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

        return ['season' => $season, 'hero' => $hero, 'goalie_hero' => $goalieHero, 'categories' => $categories->all(),
            'skaters' => $view === 'leaders' ? $skaters->all() : [], 'goalies' => $view === 'leaders' ? $goalies->all() : [], 'goalie_categories' => $goalieCategories,
            'rookies' => [], 'rookie_note' => 'Rookie eligibility is not available in the stored stats.',
            'teams' => in_array($view, ['leaders', 'teams'], true) ? $this->teams($season, $previousSeason) : []];
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
            ->leftJoin('nhl_shot_attempt_predictions as p', function ($join) use ($model): void {
                $join->on('p.shot_attempt_fact_id', '=', 'f.id')->where('p.expected_goals_model_id', $model)
                    ->where('p.prediction_target', 'goal')->where('p.is_scored', true);
            })->where('f.season_id', $season)->where('g.game_type', 2)->whereIn('g.game_state', ['OFF', 'FINAL'])
            ->whereColumn('s.nhl_game_id', 'f.nhl_game_id')->whereColumn('s.team_id', 'f.team_id')
            ->whereNotNull('player.nhl_id')->distinct()->select(['player.nhl_id', 'f.id', 'p.xg']);

        return DB::query()->fromSub($attempts, 'attempts')->groupBy('nhl_id')
            ->selectRaw('nhl_id, CASE WHEN COUNT(xg) = COUNT(*) THEN SUM(xg) ELSE NULL END AS xgf')
            ->get()->keyBy('nhl_id');
    }

    /** Select a stored goal model actually scoring completed games in the displayed season. */
    private function goalModelId(string $season): ?int
    {
        $id = DB::table('nhl_expected_goals_models as m')->where('m.prediction_target', 'goal')
            ->whereExists(function ($query) use ($season): void {
                $query->selectRaw('1')->from('nhl_shot_attempt_predictions as p')
                    ->join('nhl_shot_attempts_facts as f', 'f.id', '=', 'p.shot_attempt_fact_id')
                    ->join('nhl_games as g', 'g.nhl_game_id', '=', 'f.nhl_game_id')
                    ->whereColumn('p.expected_goals_model_id', 'm.id')->where('p.prediction_target', 'goal')
                    ->where('p.is_scored', true)->whereNotNull('p.xg')->where('f.season_id', $season)
                    ->where('g.game_type', 2)->whereIn('g.game_state', ['OFF', 'FINAL']);
            })->orderByDesc('m.trained_at')->orderByDesc('m.id')->value('m.id');

        return $id === null ? null : (int) $id;
    }

    /** Sum scored goalie-facing expectations, withholding partially scored attempt sets. */
    private function expectedAgainst(string $season, int $model): Collection
    {
        return DB::table('nhl_shot_attempts_facts as f')
            ->join('nhl_games as g', 'g.nhl_game_id', '=', 'f.nhl_game_id')
            ->leftJoin('nhl_shot_attempt_predictions as p', function ($join) use ($model): void {
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
                CASE WHEN COUNT(p.xg) = COUNT(*) THEN SUM(p.xg) ELSE NULL END AS xga')
            ->get()->keyBy('goalie_player_id');
    }

    /** Aggregate team results once per completed game, keeping GF and GA separate. */
    private function teams(string $season, string $previousSeason): array
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
        $result = [];
        foreach ($totals as $key => $team) {
            if (! str_starts_with($key, $season.':')) {
                continue;
            }
            $gp = $team['gp'];
            $old = $totals[$previousSeason.':'.$team['team']] ?? null;
            $box = $stats->get($ids->get($team['team']));
            $complete = $box !== null && (int) $box->games === $gp;
            $result[] = [...$team, 'gf_pg' => $team['gf'] / $gp, 'ga_pg' => $team['ga'] / $gp,
                'gf_delta' => $old ? $team['gf'] / $gp - $old['gf'] / $old['gp'] : null,
                'ga_delta' => $old ? $team['ga'] / $gp - $old['ga'] / $old['gp'] : null,
                'points' => $complete ? (int) $box->points : null, 'assists' => $complete ? (int) $box->assists : null,
                'sog' => $complete ? (int) $box->sog : null, 'hits' => $complete ? (int) $box->hits : null,
                'ppg_pg' => $complete ? (float) $box->ppg / $gp : null, 'url' => '/stats?view=teams'];
        }

        return $result;
    }
}
