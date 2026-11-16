<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NhlTeam;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Present the stored NHL schedule without importing facts or preparing predictions. */
class NhlScheduleCalculator
{
    /** Calculate one weekly snapshot in the builder, never in a public read. */
    public function week(CarbonImmutable $start, CarbonImmutable $today, bool $includeOffNights = false): array
    {
        $end = $start->addDays(6);
        $seasons = array_unique([$this->seasonId($start), $this->seasonId($end)]);
        $currentSeason = $this->seasonId($today);
        $seasonGames = DB::table('nhl_games')->whereIn('season_id', array_unique([...$seasons, $currentSeason]))->whereIn('game_type', [1, 2, 3]);
        $home = (clone $seasonGames)->whereNotNull('home_team_id')->selectRaw(
            'season_id, home_team_id AS nhl_id, home_team_abbrev AS abbrev, home_team_common_name AS common_name, home_team_place_name AS place_name'
        );
        $away = (clone $seasonGames)->whereNotNull('away_team_id')->selectRaw(
            'season_id, away_team_id AS nhl_id, away_team_abbrev AS abbrev, away_team_common_name AS common_name, away_team_place_name AS place_name'
        );
        $seasonParticipants = DB::query()->fromSub($home->union($away), 'participants')->orderBy('nhl_id')->get();
        $leagueSizes = $seasonParticipants->groupBy('season_id')->map(fn ($rows): int => $rows->unique('nhl_id')->count());
        $participants = $seasonParticipants->whereIn('season_id', $seasons)->unique('nhl_id');
        $references = NhlTeam::query()->whereIn('nhl_id', $seasonParticipants->pluck('nhl_id'))
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
                'days' => array_fill_keys(array_column($days, 'date'), []), 'total_gp' => 0, 'off_night_gp' => 0];
        }
        $games = DB::table('nhl_games')->whereBetween('game_date', [$start->toDateString(), $end->toDateString()])
            ->whereIn('season_id', $seasons)->whereIn('game_type', [1, 2, 3])
            ->orderBy('game_date')->orderBy('start_time_utc')->orderBy('nhl_game_id')
            ->get(['nhl_game_id', 'game_date', 'start_time_utc', 'game_state', 'game_schedule_state',
                'away_team_id', 'away_team_abbrev', 'home_team_id', 'home_team_abbrev']);
        $activeGames = $games->reject(fn ($game): bool => $this->isInactive($game));
        foreach ($days as &$day) {
            $slate = $activeGames->where('game_date', $day['date']);
            $playing = $slate->pluck('home_team_id')->merge($slate->pluck('away_team_id'))->filter()->unique()->count();
            $leagueSize = $leagueSizes->get($this->seasonId(CarbonImmutable::parse($day['date'])), 0);
            $day['games'] = $slate->count();
            $day['teams_playing'] = $playing;
            $day['league_teams'] = $leagueSize;
            $day['is_off_night'] = $leagueSize > 0 && $playing * 2 <= $leagueSize;
        }
        unset($day);
        $offNights = array_column($days, 'is_off_night', 'date');
        $gameCount = 0;
        foreach ($games as $game) {
            $postponed = $this->isInactive($game);
            $gameCount += $postponed ? 0 : 1;
            foreach (['home' => 'away', 'away' => 'home'] as $side => $opponent) {
                $id = (int) $game->{$side.'_team_id'};
                if (! isset($teams[$id])) {
                    continue;
                }
                $opponentId = (int) $game->{$opponent.'_team_id'};
                $opponentAbbrev = $teams[$opponentId]['abbrev'] ?? $game->{$opponent.'_team_abbrev'};
                $teams[$id]['days'][$game->game_date][] = [
                    'id' => (int) $game->nhl_game_id, 'href' => route('games.show', ['nhlGameId' => $game->nhl_game_id], false),
                    'opponent' => $opponentAbbrev ?: 'TBD', 'venue' => $side,
                    'time' => $game->start_time_utc
                        ? CarbonImmutable::parse($game->start_time_utc, 'UTC')->setTimezone('America/Toronto')->format('g:i A') : 'TBD',
                    'status' => $postponed ? (strtoupper((string) $game->game_state) === 'CNCL'
                        || strtoupper((string) $game->game_schedule_state) === 'CNCL' ? 'Cancelled' : 'Postponed') : null,
                ];
                $teams[$id]['total_gp'] += $postponed ? 0 : 1;
                $teams[$id]['off_night_gp'] += ! $postponed && $offNights[$game->game_date] ? 1 : 0;
            }
        }

        return [
            'week_start' => $start->toDateString(), 'week_end' => $end->toDateString(),
            'label' => $start->format('M j').' – '.$end->format('M j, Y'), 'timezone' => 'America/Toronto',
            'is_current_week' => $start->isSameDay($today->startOfWeek(CarbonImmutable::MONDAY)),
            'days' => $days, 'teams' => collect($teams)->sortBy('abbrev', SORT_NATURAL | SORT_FLAG_CASE)->values()->all(),
            'previous_url' => route('tools.schedule', ['week' => $start->subWeek()->toDateString()]),
            'next_url' => route('tools.schedule', ['week' => $start->addWeek()->toDateString()]),
            'current_url' => route('tools.schedule'),
            'game_count' => $gameCount,
            'off_nights' => $includeOffNights ? $this->remainingOffNights($today, $seasonParticipants->where('season_id', $currentSeason)->unique('nhl_id'), $references) : null,
        ];
    }

    /** Count remaining current-season off-night games independently of the weekly view. */
    private function remainingOffNights(CarbonImmutable $today, Collection $participants, Collection $references): array
    {
        $season = $this->seasonId($today);
        $teams = [];
        foreach ($participants as $team) {
            $reference = $references->get($team->nhl_id);
            $abbrev = $reference?->abbrev ?: $team->abbrev;
            $name = $reference?->full_name ?: trim(($reference?->place_name ?: $team->place_name).' '.($reference?->common_name ?: $team->common_name));
            $teams[(int) $team->nhl_id] = [
                'id' => (int) $team->nhl_id, 'abbrev' => $abbrev, 'name' => $name ?: $abbrev ?: 'Team '.$team->nhl_id,
                'logo' => $abbrev ? 'https://assets.nhle.com/logos/nhl/svg/'.$abbrev.'_light.svg' : null,
                'off_night_gp' => 0,
            ];
        }
        $games = DB::table('nhl_games')->where('season_id', $season)->whereIn('game_type', [1, 2, 3])
            ->where('game_date', '>=', $today->toDateString())
            ->orderBy('game_date')->orderBy('start_time_utc')->orderBy('nhl_game_id')
            ->get(['nhl_game_id', 'game_date', 'start_time_utc', 'game_state', 'game_schedule_state',
                'home_team_id', 'home_team_abbrev', 'away_team_id', 'away_team_abbrev'])
            ->reject(fn ($game): bool => $this->isInactive($game));
        $gameCount = 0;
        foreach ($games->groupBy('game_date') as $date => $slate) {
            // Completed games still belong to the day's league slate, but not its remaining games.
            $playing = $slate->pluck('home_team_id')->merge($slate->pluck('away_team_id'))->filter()->unique()->count();
            $isOffNight = count($teams) > 0 && $playing * 2 <= count($teams);
            foreach ($slate as $game) {
                if (in_array(strtoupper((string) $game->game_state), ['FINAL', 'OFF'], true)) {
                    continue;
                }
                $gameCount++;
                if (! $isOffNight) {
                    continue;
                }
                foreach (['home' => 'away', 'away' => 'home'] as $side => $opponent) {
                    $id = (int) $game->{$side.'_team_id'};
                    if (! isset($teams[$id])) {
                        continue;
                    }
                    $teams[$id]['off_night_gp']++;
                }
            }
        }

        return [
            'season_id' => $season, 'from_date' => $today->toDateString(),
            'label' => 'Remaining '.substr($season, 0, 4).'–'.substr($season, 6, 2).' season · From '.$today->format('M j, Y'),
            'teams' => collect($teams)->sortBy('abbrev', SORT_NATURAL | SORT_FLAG_CASE)->values()->all(),
            'game_count' => $gameCount,
        ];
    }

    /** Exclude postponed and cancelled games from slate activity and GP. */
    private function isInactive(object $game): bool
    {
        return in_array(strtoupper((string) $game->game_state), ['PPD', 'CNCL'], true)
            || in_array(strtoupper((string) $game->game_schedule_state), ['PPD', 'CNCL'], true);
    }

    /** Match the existing NHL season window of September through August. */
    private function seasonId(CarbonImmutable $date): string
    {
        $year = $date->month >= 9 ? $date->year : $date->year - 1;
        return (string) $year.($year + 1);
    }
}
