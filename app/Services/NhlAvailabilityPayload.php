<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NhlPlayerInjury;
use App\Models\NhlStartingGoalieObservation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class NhlAvailabilityPayload
{
    /** @return array<string,mixed> */
    public function injuries(?string $team = null, ?int $nhlPlayerId = null): array
    {
        $rows = NhlPlayerInjury::query()
            ->when($team, fn ($query) => $query->where('team_abbrev', mb_strtoupper($team)))
            ->when($nhlPlayerId, fn ($query) => $query->where('nhl_player_id', $nhlPlayerId))
            ->orderBy('team_abbrev')->orderBy('player_name')->get();

        return [
            'injuries' => $rows->map(fn (NhlPlayerInjury $row): array => [
                'nhl_player_id' => $row->nhl_player_id, 'player_name' => $row->player_name,
                'team_abbrev' => $row->team_abbrev, 'position' => $row->position,
                'body_part' => $row->body_part, 'availability' => $row->availability,
                'evidence_level' => $row->evidence_level, 'status' => $row->status_text,
                'anticipated_return_text' => $row->anticipated_return_text,
                'anticipated_return_date' => $row->anticipated_return_date?->toDateString(),
                'anticipated_return_precision' => $row->anticipated_return_precision,
                'sources' => $row->sources,
                'first_reported_at' => $row->first_observed_at?->toIso8601String(),
                'status_updated_at' => $row->last_observed_at?->toIso8601String(),
                'last_seen_at' => $row->last_seen_at?->toIso8601String(),
            ])->values(),
            'meta' => ['count' => $rows->count(), 'generated_at' => now()->toIso8601String()],
        ];
    }

    /** @return array<string,mixed> */
    public function goalies(Carbon $date, ?int $nhlGameId = null): array
    {
        $rows = NhlStartingGoalieObservation::query()
            ->whereDate('game_date', $date->toDateString())
            ->when($nhlGameId, fn ($query) => $query->where('nhl_game_id', $nhlGameId))
            ->orderByDesc('fetched_at')->orderByDesc('id')->get()
            ->unique(fn (NhlStartingGoalieObservation $row): string => ($row->nhl_game_id ?? $row->game_date->toDateString()) . ':' . $row->team_abbrev)
            ->sortBy([['game_date', 'asc'], ['nhl_game_id', 'asc'], ['is_home', 'asc']])->values();

        $games = DB::table('nhl_games')
            ->whereIn('nhl_game_id', $rows->pluck('nhl_game_id')->filter()->unique())
            ->get()
            ->keyBy('nhl_game_id');

        $goalies = $rows->map(function (NhlStartingGoalieObservation $row) use ($games): array {
            $game = $row->nhl_game_id ? $games->get($row->nhl_game_id) : null;

            return [
                'nhl_game_id' => $row->nhl_game_id, 'game_date' => $row->game_date->toDateString(),
                'start_time_utc' => $game?->start_time_utc ? Carbon::parse($game->start_time_utc, 'UTC')->toIso8601String() : null,
                'team_abbrev' => $row->team_abbrev, 'opponent_abbrev' => $row->opponent_abbrev,
                'is_home' => $row->is_home, 'nhl_player_id' => $row->nhl_player_id,
                'player_name' => $row->player_name, 'status' => $row->status,
                'provider' => $row->provider, 'observed_at' => $row->fetched_at?->toIso8601String(),
            ];
        });
        $goalies = $this->addGoalieSeasonStats($goalies, $games);

        return [
            'starting_goalies' => $goalies,
            'games' => $this->groupGoaliesByGame($goalies, $games),
            'meta' => ['date' => $date->toDateString(), 'count' => $rows->count(), 'generated_at' => now()->toIso8601String()],
        ];
    }

    /**
     * Add the appropriate regular-season baseline to each goalie observation.
     *
     * @param Collection<int, array<string, mixed>> $goalies
     * @param Collection<int, object> $games
     * @return Collection<int, array<string, mixed>>
     */
    private function addGoalieSeasonStats(Collection $goalies, Collection $games): Collection
    {
        $contexts = $goalies->mapWithKeys(function (array $goalie) use ($games): array {
            $game = $goalie['nhl_game_id'] ? $games->get($goalie['nhl_game_id']) : null;

            if (! $game || ! $goalie['nhl_player_id']) {
                return [];
            }

            $seasonKey = (int) $game->game_type === 1
                ? $this->previousSeasonKey((string) $game->season_id)
                : (string) $game->season_id;

            return [$goalie['nhl_player_id'] . ':' . $seasonKey => [
                'nhl_player_id' => (int) $goalie['nhl_player_id'],
                'season_key' => $seasonKey,
            ]];
        });

        if ($contexts->isEmpty()) {
            return $goalies->map(fn (array $goalie): array => [...$goalie, 'season_stats' => null]);
        }

        $stats = DB::table('nhl_game_summaries as summaries')
            ->join('nhl_games as games', 'games.nhl_game_id', '=', 'summaries.nhl_game_id')
            ->where('games.game_type', 2)
            ->whereIn('summaries.nhl_player_id', $contexts->pluck('nhl_player_id')->unique())
            ->whereIn('games.season_id', $contexts->pluck('season_key')->unique())
            ->where(function ($query): void {
                $query->where('summaries.goalie_started', true)
                    ->orWhereNotNull('summaries.goalie_decision')
                    ->orWhere('summaries.sa', '>', 0)
                    ->orWhere('summaries.sv', '>', 0)
                    ->orWhere('summaries.ga', '>', 0)
                    ->orWhere('summaries.so', '>', 0);
            })
            ->groupBy('summaries.nhl_player_id', 'games.season_id')
            ->selectRaw(<<<'SQL'
summaries.nhl_player_id,
games.season_id,
COUNT(DISTINCT summaries.nhl_game_id) as games_played,
SUM(summaries.sa) as shots_against,
SUM(summaries.sv) as saves,
SUM(summaries.ga) as goals_against,
SUM(summaries.toi) as toi_seconds
SQL)
            ->get()
            ->keyBy(fn (object $row): string => $row->nhl_player_id . ':' . $row->season_id);

        return $goalies->map(function (array $goalie) use ($games, $stats): array {
            $game = $goalie['nhl_game_id'] ? $games->get($goalie['nhl_game_id']) : null;

            if (! $game || ! $goalie['nhl_player_id']) {
                return [...$goalie, 'season_stats' => null];
            }

            $seasonKey = (int) $game->game_type === 1
                ? $this->previousSeasonKey((string) $game->season_id)
                : (string) $game->season_id;
            $row = $stats->get($goalie['nhl_player_id'] . ':' . $seasonKey);

            if (! $row) {
                return [...$goalie, 'season_stats' => null];
            }

            $shotsAgainst = (int) $row->shots_against;
            $toiSeconds = (int) $row->toi_seconds;

            return [...$goalie, 'season_stats' => [
                'season_key' => $seasonKey,
                'games_played' => (int) $row->games_played,
                'goals_against_average' => $toiSeconds > 0
                    ? round(((int) $row->goals_against * 3600) / $toiSeconds, 2)
                    : null,
                'save_percentage' => $shotsAgainst > 0
                    ? round((int) $row->saves / $shotsAgainst, 3)
                    : null,
            ]];
        });
    }

    private function previousSeasonKey(string $seasonKey): string
    {
        if (! preg_match('/^(\d{4})(\d{4})$/', $seasonKey, $matches)) {
            return $seasonKey;
        }

        return ((int) $matches[1] - 1) . (string) ((int) $matches[2] - 1);
    }

    /**
     * Group the latest goalie observations into matchup-level presentation rows.
     *
     * @param Collection<int, array<string, mixed>> $goalies
     * @param Collection<int, object> $games
     * @return Collection<int, array<string, mixed>>
     */
    private function groupGoaliesByGame(Collection $goalies, Collection $games): Collection
    {
        return $goalies
            ->groupBy(function (array $goalie): string {
                if ($goalie['nhl_game_id']) {
                    return 'game:' . $goalie['nhl_game_id'];
                }

                $teams = array_filter([$goalie['team_abbrev'], $goalie['opponent_abbrev']]);
                sort($teams);

                return 'matchup:' . $goalie['game_date'] . ':' . implode(':', $teams);
            })
            ->map(function (Collection $matchup) use ($games): array {
                $first = $matchup->first();
                $game = $first['nhl_game_id'] ? $games->get($first['nhl_game_id']) : null;
                $homeGoalie = $matchup->first(fn (array $goalie): bool => $game
                    ? $goalie['team_abbrev'] === $game->home_team_abbrev
                    : $goalie['is_home'] === true);
                $awayGoalie = $matchup->first(fn (array $goalie): bool => $game
                    ? $goalie['team_abbrev'] === $game->away_team_abbrev
                    : $goalie['is_home'] === false);
                $homeTeam = $game?->home_team_abbrev ?? $homeGoalie['team_abbrev'] ?? $awayGoalie['opponent_abbrev'] ?? null;
                $awayTeam = $game?->away_team_abbrev ?? $awayGoalie['team_abbrev'] ?? $homeGoalie['opponent_abbrev'] ?? null;

                return [
                    'nhl_game_id' => $first['nhl_game_id'],
                    'game_date' => $first['game_date'],
                    'start_time_utc' => $first['start_time_utc'],
                    'away_team_abbrev' => $awayTeam,
                    'home_team_abbrev' => $homeTeam,
                    'away_team_logo' => $game?->away_team_logo,
                    'home_team_logo' => $game?->home_team_logo,
                    'away_goalie' => $awayGoalie,
                    'home_goalie' => $homeGoalie,
                ];
            })
            ->sortBy([['start_time_utc', 'asc'], ['nhl_game_id', 'asc']])
            ->values();
    }
}
