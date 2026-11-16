<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\NhlTeam;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Present the stored NHL schedule without importing facts or preparing predictions. */
class NhlScheduleController extends Controller
{
    /** Render a Monday–Sunday team grid in the Eastern calendar. */
    public function index(Request $request): Response
    {
        $input = $request->validate(['week' => ['nullable', 'date_format:Y-m-d']]);
        $today = CarbonImmutable::now('America/Toronto')->startOfDay();
        $start = (isset($input['week']) ? CarbonImmutable::parse($input['week'], 'America/Toronto') : $today)
            ->startOfWeek(CarbonImmutable::MONDAY)->startOfDay();
        $end = $start->addDays(6);
        $seasons = array_unique([$this->seasonId($start), $this->seasonId($end)]);
        $seasonGames = DB::table('nhl_games')->whereIn('season_id', $seasons)->whereIn('game_type', [1, 2, 3]);
        $home = (clone $seasonGames)->whereNotNull('home_team_id')->selectRaw(
            'home_team_id AS nhl_id, home_team_abbrev AS abbrev, home_team_common_name AS common_name, home_team_place_name AS place_name'
        );
        $away = (clone $seasonGames)->whereNotNull('away_team_id')->selectRaw(
            'away_team_id AS nhl_id, away_team_abbrev AS abbrev, away_team_common_name AS common_name, away_team_place_name AS place_name'
        );
        $participants = DB::query()->fromSub($home->union($away), 'participants')->orderBy('nhl_id')->get()->unique('nhl_id');
        $references = NhlTeam::query()->whereIn('nhl_id', $participants->pluck('nhl_id'))
            ->get(['nhl_id', 'abbrev', 'full_name', 'common_name', 'place_name'])->keyBy('nhl_id');
        $days = collect(range(0, 6))->map(fn (int $offset): array => [
            'date' => $start->addDays($offset)->toDateString(),
            'label' => $start->addDays($offset)->format('D'),
            'display_date' => $start->addDays($offset)->format('M j'),
            'is_today' => $start->addDays($offset)->isSameDay($today),
        ])->all();
        $teams = [];
        foreach ($participants as $team) {
            $reference = $references->get($team->nhl_id);
            $abbrev = $reference?->abbrev ?: $team->abbrev;
            $name = $reference?->full_name ?: trim(($reference?->place_name ?: $team->place_name).' '.($reference?->common_name ?: $team->common_name));
            $teams[(int) $team->nhl_id] = ['id' => (int) $team->nhl_id, 'abbrev' => $abbrev,
                'name' => $name ?: $abbrev ?: 'Team '.$team->nhl_id,
                'logo' => $abbrev ? 'https://assets.nhle.com/logos/nhl/svg/'.$abbrev.'_light.svg' : null,
                'days' => array_fill_keys(array_column($days, 'date'), []), 'total_gp' => 0];
        }
        $games = DB::table('nhl_games')->whereBetween('game_date', [$start->toDateString(), $end->toDateString()])
            ->whereIn('season_id', $seasons)->whereIn('game_type', [1, 2, 3])
            ->orderBy('game_date')->orderBy('start_time_utc')->orderBy('nhl_game_id')
            ->get(['nhl_game_id', 'game_date', 'start_time_utc', 'game_state', 'game_schedule_state',
                'away_team_id', 'away_team_abbrev', 'home_team_id', 'home_team_abbrev']);
        $gameCount = 0;
        foreach ($games as $game) {
            $postponed = in_array(strtoupper((string) $game->game_state), ['PPD', 'CNCL'], true)
                || in_array(strtoupper((string) $game->game_schedule_state), ['PPD', 'CNCL'], true);
            $gameCount += $postponed ? 0 : 1;
            foreach (['home' => 'away', 'away' => 'home'] as $side => $opponent) {
                $id = (int) $game->{$side.'_team_id'};
                if (! isset($teams[$id])) {
                    continue;
                }
                $opponentId = (int) $game->{$opponent.'_team_id'};
                $opponentAbbrev = $teams[$opponentId]['abbrev'] ?? $game->{$opponent.'_team_abbrev'};
                $teams[$id]['days'][$game->game_date][] = [
                    'id' => (int) $game->nhl_game_id, 'href' => route('games.show', ['nhlGameId' => $game->nhl_game_id]),
                    'opponent' => $opponentAbbrev ?: 'TBD', 'venue' => $side,
                    'time' => $game->start_time_utc
                        ? CarbonImmutable::parse($game->start_time_utc, 'UTC')->setTimezone('America/Toronto')->format('g:i A') : 'TBD',
                    'status' => $postponed ? (strtoupper((string) $game->game_state) === 'CNCL'
                        || strtoupper((string) $game->game_schedule_state) === 'CNCL' ? 'Cancelled' : 'Postponed') : null,
                ];
                $teams[$id]['total_gp'] += $postponed ? 0 : 1;
            }
        }

        return Inertia::render('Tools/Schedule', ['schedule' => [
            'week_start' => $start->toDateString(), 'week_end' => $end->toDateString(),
            'label' => $start->format('M j').' – '.$end->format('M j, Y'), 'timezone' => 'America/Toronto',
            'is_current_week' => $start->isSameDay($today->startOfWeek(CarbonImmutable::MONDAY)),
            'days' => $days, 'teams' => collect($teams)->sortBy('abbrev', SORT_NATURAL | SORT_FLAG_CASE)->values()->all(),
            'previous_url' => route('tools.schedule', ['week' => $start->subWeek()->toDateString()]),
            'next_url' => route('tools.schedule', ['week' => $start->addWeek()->toDateString()]),
            'current_url' => route('tools.schedule'),
            'game_count' => $gameCount,
        ]]);
    }

    /** Match the existing NHL season window of September through August. */
    private function seasonId(CarbonImmutable $date): string
    {
        $year = $date->month >= 9 ? $date->year : $date->year - 1;
        return (string) $year.($year + 1);
    }
}
