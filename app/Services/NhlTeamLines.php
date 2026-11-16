<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Read the latest completed team combinations from prepared strength summaries. */
class NhlTeamLines
{
    /** Return current-season teams and their non-overlapping, TOI-ranked combinations. */
    public function read(): array
    {
        $today = CarbonImmutable::now('America/Toronto');
        $year = $today->month >= 9 ? $today->year : $today->year - 1;
        $season = (string) $year.($year + 1);
        $games = DB::table('nhl_games')->where('season_id', $season)->whereIn('game_type', [1, 2, 3])
            ->orderByDesc('game_date')->orderByDesc('start_time_utc')->orderByDesc('nhl_game_id')
            ->get(['nhl_game_id', 'game_date', 'start_time_utc', 'game_state', 'game_schedule_state',
                'home_team_id', 'home_team_abbrev', 'away_team_id', 'away_team_abbrev']);
        $teams = [];
        foreach ($games as $game) {
            $completed = $game->game_date <= $today->toDateString()
                && in_array(strtoupper((string) $game->game_state), ['FINAL', 'OFF'], true)
                && ! in_array(strtoupper((string) $game->game_schedule_state), ['PPD', 'CNCL'], true);
            foreach (['home' => 'away', 'away' => 'home'] as $side => $opponent) {
                $id = (int) $game->{$side.'_team_id'};
                if ($id <= 0) {
                    continue;
                }
                $teams[$id] ??= ['id' => $id, 'abbrev' => $game->{$side.'_team_abbrev'}, 'game' => null];
                if ($completed && $teams[$id]['game'] === null) {
                    $teams[$id]['game'] = [
                        'id' => (int) $game->nhl_game_id, 'date' => $game->game_date,
                        'date_label' => CarbonImmutable::parse($game->game_date)->format('D M j'),
                        'opponent' => $game->{$opponent.'_team_abbrev'}, 'venue' => $side,
                        'href' => route('games.show', ['nhlGameId' => $game->nhl_game_id], false),
                    ];
                }
            }
        }

        $references = DB::table('nhl_teams')->whereIn('nhl_id', array_keys($teams))
            ->get(['nhl_id', 'abbrev', 'full_name'])->keyBy('nhl_id');
        $gameIds = collect($teams)->pluck('game.id')->filter()->unique()->values();
        $summaries = DB::table('nhl_unit_game_strength_summaries as s')
            ->join('nhl_units as u', 'u.id', '=', 's.unit_id')
            ->whereIn('s.nhl_game_id', $gameIds)->whereIn('s.strength', ['EV', 'PP', 'PK'])
            ->whereIn('u.unit_type', ['F', 'D', 'PP', 'PK'])->where('s.toi', '>', 0)
            ->get(['s.nhl_game_id', 's.team_id', 's.team_abbrev', 's.unit_id', 's.strength', 's.toi', 'u.unit_type']);
        $members = DB::table('nhl_unit_players as up')->join('players as p', 'p.id', '=', 'up.player_id')
            ->whereIn('up.unit_id', $summaries->pluck('unit_id')->unique())->orderBy('p.id')
            ->get(['up.unit_id', 'p.id', 'p.nhl_id', 'p.full_name', 'p.first_name', 'p.last_name', 'p.position', 'p.pos_type'])
            ->groupBy('unit_id');

        foreach ($teams as $id => &$team) {
            $reference = $references->get($id);
            $team['abbrev'] = $reference?->abbrev ?: $team['abbrev'];
            $team['name'] = $reference?->full_name ?: $team['abbrev'] ?: 'Team '.$id;
            $team['logo'] = $team['abbrev'] ? 'https://assets.nhle.com/logos/nhl/svg/'.$team['abbrev'].'_light.svg' : null;
            $team['strengths'] = [];
            foreach (['EV', 'PP', 'PK'] as $strength) {
                $candidates = $summaries->filter(fn (object $row): bool => $team['game'] !== null
                    && (int) $row->nhl_game_id === $team['game']['id'] && $row->strength === $strength
                    && ($row->team_id !== null ? (int) $row->team_id === $id : $row->team_abbrev === $team['abbrev']));
                $team['strengths'][$strength] = $this->selectLines($candidates, $members, $strength);
            }
        }
        unset($team);

        return ['season_id' => $season, 'teams' => collect($teams)->sortBy('abbrev', SORT_NATURAL | SORT_FLAG_CASE)->values()->all()];
    }

    /** Greedily retain the highest-TOI groups without reusing players within a strength. */
    private function selectLines(Collection $rows, Collection $members, string $strength): array
    {
        $combinations = [];
        foreach ($rows as $row) {
            $players = $members->get($row->unit_id, collect());
            if ($players->isEmpty() || $players->contains(fn (object $player): bool => strtoupper((string) $player->pos_type) === 'G'
                || strtoupper((string) $player->position) === 'G')) {
                continue;
            }
            if ($strength === 'EV' && $players->count() !== ($row->unit_type === 'F' ? 3 : 2)) {
                continue;
            }
            if (in_array($row->unit_type, ['PP', 'PK'], true)) {
                if ($row->unit_type !== $strength || $players->contains(fn (object $player): bool => $this->playerType($player) === null)) {
                    continue;
                }
                $groups = $players->groupBy(fn (object $player): string => (string) $this->playerType($player));
            } else {
                $groups = collect([$row->unit_type => $players]);
            }
            foreach ($groups as $type => $group) {
                $ids = $group->pluck('id')->map(fn ($id): int => (int) $id)->all();
                $key = $type.':'.implode(',', $ids);
                $combinations[$key] ??= [
                    'unit_id' => (int) $row->unit_id, 'type' => $type, 'toi_seconds' => 0,
                    'players' => $group->map(fn (object $player): array => [
                        'id' => (int) $player->id, 'nhl_id' => $player->nhl_id,
                        'name' => $player->full_name ?: trim($player->first_name.' '.$player->last_name),
                    ])->values()->all(),
                ];
                $combinations[$key]['toi_seconds'] += (int) $row->toi;
                $combinations[$key]['unit_id'] = min($combinations[$key]['unit_id'], (int) $row->unit_id);
            }
        }
        usort($combinations, fn (array $a, array $b): int => $b['toi_seconds'] <=> $a['toi_seconds'] ?: $a['unit_id'] <=> $b['unit_id']);
        $limits = $strength === 'EV' ? ['F' => 4, 'D' => 3] : ['F' => 2, 'D' => 2];
        $selected = ['F' => [], 'D' => []];
        $used = [];
        foreach ($combinations as $combination) {
            $type = $combination['type'];
            $ids = array_column($combination['players'], 'id');
            if (count($selected[$type]) >= $limits[$type] || array_intersect($ids, $used) !== []) {
                continue;
            }
            $seconds = $combination['toi_seconds'];
            $combination['toi'] = sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
            $selected[$type][] = $combination;
            $used = array_merge($used, $ids);
        }

        return $selected;
    }

    /** Use stored position types to split full special-teams units without inventing roles. */
    private function playerType(object $player): ?string
    {
        $type = strtoupper((string) $player->pos_type);
        if (in_array($type, ['F', 'D'], true)) {
            return $type;
        }
        $positions = preg_split('/[\s,\/;-]+/', strtoupper((string) $player->position));
        if (in_array('D', $positions, true)) {
            return 'D';
        }

        return array_intersect($positions, ['F', 'C', 'L', 'R', 'LW', 'RW', 'W']) !== [] ? 'F' : null;
    }
}
