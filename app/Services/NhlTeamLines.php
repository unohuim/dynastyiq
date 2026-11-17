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
        $faceoffs = DB::table('nhl_boxscores')->whereIn('nhl_game_id', $gameIds)
            ->get(['nhl_game_id', 'nhl_player_id', 'faceoffs_won', 'faceoffs_lost'])
            ->groupBy('nhl_game_id')->map(fn (Collection $rows): Collection => $rows->mapWithKeys(
                fn (object $row): array => [$row->nhl_player_id => (int) $row->faceoffs_won + (int) $row->faceoffs_lost]
            ));

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
                $team['strengths'][$strength] = $this->selectLines(
                    $candidates, $members, $strength, $faceoffs->get($team['game']['id'] ?? 0, collect())
                );
            }
        }
        unset($team);

        return ['season_id' => $season, 'teams' => collect($teams)->sortBy('abbrev', SORT_NATURAL | SORT_FLAG_CASE)->values()->all()];
    }

    /** Greedily retain the highest-TOI groups without reusing players within a strength. */
    private function selectLines(Collection $rows, Collection $members, string $strength, Collection $faceoffs): array
    {
        $combinations = [];
        foreach ($rows as $row) {
            $players = $members->get($row->unit_id, collect());
            if ($players->isEmpty() || $players->contains(fn (object $player): bool => strtoupper((string) $player->pos_type) === 'G'
                || strtoupper((string) $player->position) === 'G')) {
                continue;
            }
            if ($strength === 'EV') {
                if (! in_array($row->unit_type, ['F', 'D'], true)
                    || $players->count() !== ($row->unit_type === 'F' ? 3 : 2)) {
                    continue;
                }
            } else {
                if ($row->unit_type !== $strength || ! in_array($players->count(), $strength === 'PP' ? [5] : [3, 4], true)) {
                    continue;
                }
            }
            $type = $strength === 'EV' ? $row->unit_type : 'units';
            $key = $type.':'.$players->pluck('id')->implode(',');
            $combinations[$key] ??= [
                'unit_id' => (int) $row->unit_id, 'type' => $type, 'toi_seconds' => 0, 'members' => $players,
            ];
            $combinations[$key]['toi_seconds'] += (int) $row->toi;
            $combinations[$key]['unit_id'] = min($combinations[$key]['unit_id'], (int) $row->unit_id);
        }
        usort($combinations, fn (array $a, array $b): int => $b['toi_seconds'] <=> $a['toi_seconds'] ?: $a['unit_id'] <=> $b['unit_id']);
        $limits = $strength === 'EV' ? ['F' => 4, 'D' => 3] : ['units' => 2];
        $selected = array_fill_keys(array_keys($limits), []);
        $used = [];
        foreach ($combinations as $combination) {
            $type = $combination['type'];
            $ids = $combination['members']->pluck('id')->all();
            if (count($selected[$type]) >= $limits[$type] || array_intersect($ids, $used) !== []) {
                continue;
            }
            $seconds = $combination['toi_seconds'];
            $combination['toi'] = sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
            $combination['players'] = $this->positionPlayers($combination['members'], $type, $faceoffs);
            unset($combination['members']);
            $selected[$type][] = $combination;
            $used = array_merge($used, $ids);
        }

        return $selected;
    }

    /** Infer display spots without changing unit membership or claiming verified shift roles. */
    private function positionPlayers(Collection $members, string $type, Collection $faceoffs): array
    {
        $layouts = match (true) {
            $type === 'D' => [['LD', 'RD']],
            $type === 'F' => [['LW', 'C', 'RW']],
            $members->count() === 5 => [['LW', 'C', 'RW', 'LD', 'RD']],
            $members->count() === 4 => [['LW', 'C', 'LD', 'RD'], ['C', 'RW', 'LD', 'RD']],
            default => [['C', 'LD', 'RD']],
        };
        $best = [];
        $bestScore = [-1, -1, -1];
        foreach ($layouts as $slots) {
            foreach ($this->permutations($members->values()->all()) as $players) {
                $score = [0, 0, 0];
                foreach ($slots as $index => $slot) {
                    $player = $players[$index];
                    $positions = preg_split('/[^A-Z]+/', strtoupper((string) $player->position), -1, PREG_SPLIT_NO_EMPTY);
                    if ($slot === 'C') {
                        $score[0] = (int) $faceoffs->get($player->nhl_id, 0);
                    }
                    if (in_array($slot, ['LD', 'RD'], true)) {
                        $score[1] += $this->playerType($player) === 'D' ? 1 : 0;
                    }
                    $eligible = match ($slot) {
                        'LW' => ['L', 'LW'], 'RW' => ['R', 'RW'], 'C' => ['C'], default => ['D'],
                    };
                    $score[2] += array_intersect($positions, $eligible) !== [] ? 1 : 0;
                }
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = [];
                    foreach ($slots as $index => $slot) {
                        $player = $players[$index];
                        $name = trim((string) ($player->full_name ?: $player->first_name.' '.$player->last_name));
                        $parts = preg_split('/\s+/u', $name, 2);
                        $first = trim((string) ($player->first_name ?: $parts[0]));
                        $last = trim((string) ($player->last_name ?: ($parts[1] ?? '')));
                        $best[] = [
                            'id' => (int) $player->id, 'nhl_id' => $player->nhl_id, 'name' => $name,
                            'short_name' => $last !== '' ? trim(mb_substr($first, 0, 1).' '.$last) : $name, 'slot' => $slot,
                        ];
                    }
                }
            }
        }

        return $best;
    }

    /** Enumerate at most five skaters with stable source-ID ties. */
    private function permutations(array $players): \Generator
    {
        if ($players === []) {
            yield [];
            return;
        }
        foreach ($players as $index => $player) {
            $remaining = $players;
            unset($remaining[$index]);
            foreach ($this->permutations(array_values($remaining)) as $tail) {
                yield [$player, ...$tail];
            }
        }
    }

    /** Use position type, then listed positions, when choosing defensive display spots. */
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
