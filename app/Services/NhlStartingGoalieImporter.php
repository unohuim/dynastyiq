<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NhlStartingGoalieObservation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class NhlStartingGoalieImporter
{
    private const SOURCE_URL = 'https://www.rotowire.com/hockey/tables/projected-goalies.php';

    private const TEAM_ALIASES = ['MON' => 'MTL', 'LOS' => 'LAK', 'LAS' => 'VGK'];

    public function __construct(
        private readonly RotoWireNhlClient $client,
        private readonly NhlAvailabilityPlayerResolver $players
    ) {
    }

    /** @return array{observed:int,unresolved:int} */
    public function import(Carbon $date): array
    {
        $rows = $this->client->projectedGoalies($date);
        $fetchedAt = now();
        $observed = 0;
        $unresolved = 0;
        $seen = [];

        foreach ($rows as $matchup) {
            $providerHomeTeam = $this->team(data_get($matchup, 'hometeam'));
            $providerAwayTeam = $this->team(data_get($matchup, 'visitteam'));
            $game = $this->resolveGame($date, $providerHomeTeam, $providerAwayTeam);

            foreach ([['home', true, 'visit'], ['visit', false, 'home']] as [$side, $isHome, $opponentSide]) {
                $team = $this->team(data_get($matchup, $side . 'team'));
                $opponent = $this->team(data_get($matchup, $opponentSide . 'team'));
                $name = trim((string) (data_get($matchup, $side . 'Player') ?? data_get($matchup, $side . 'Goalie')));
                if ($team === null || $name === '') {
                    continue;
                }
                $gameId = $game?->nhl_game_id;
                $isHome = $game ? $game->home_team_abbrev === $team : $isHome;
                $teams = array_filter([$team, $opponent]);
                sort($teams);
                $matchupKey = $gameId
                    ? 'game:' . $gameId
                    : 'matchup:' . $date->toDateString() . ':' . implode(':', $teams);
                $observationKey = $matchupKey . ':team:' . $team;

                if (isset($seen[$observationKey])) {
                    continue;
                }

                $seen[$observationKey] = true;
                $player = $this->players->resolve($name, $team, true);

                NhlStartingGoalieObservation::query()->create([
                    'nhl_game_id' => $gameId, 'game_date' => $date->toDateString(), 'team_abbrev' => $team,
                    'opponent_abbrev' => $opponent, 'is_home' => $isHome, 'player_id' => $player?->id,
                    'nhl_player_id' => $player?->nhl_id, 'player_name' => $name, 'provider' => 'rotowire',
                    'provider_player_key' => data_get($matchup, $side . 'PlayerID'),
                    'status' => $this->status((string) data_get($matchup, $side . 'Status', 'unknown')),
                    'provider_published_at' => null, 'fetched_at' => $fetchedAt,
                    'source_url' => self::SOURCE_URL . '?date=' . $date->toDateString(), 'raw_evidence' => $matchup,
                ]);
                $observed++;
                $unresolved += $player === null ? 1 : 0;
            }
        }
        return ['observed' => $observed, 'unresolved' => $unresolved];
    }

    private function resolveGame(Carbon $date, ?string $homeTeam, ?string $awayTeam): ?object
    {
        if ($homeTeam === null || $awayTeam === null) {
            return null;
        }

        $games = DB::table('nhl_games')
            ->whereDate('game_date', $date->toDateString())
            ->where(function ($query) use ($homeTeam, $awayTeam): void {
                $query->where(function ($matchupQuery) use ($homeTeam, $awayTeam): void {
                    $matchupQuery->where('home_team_abbrev', $homeTeam)
                        ->where('away_team_abbrev', $awayTeam);
                })->orWhere(function ($matchupQuery) use ($homeTeam, $awayTeam): void {
                    $matchupQuery->where('home_team_abbrev', $awayTeam)
                        ->where('away_team_abbrev', $homeTeam);
                });
            })
            ->orderBy('nhl_game_id')
            ->get(['nhl_game_id', 'home_team_abbrev', 'away_team_abbrev']);

        $exact = $games->filter(fn (object $game): bool => $game->home_team_abbrev === $homeTeam
            && $game->away_team_abbrev === $awayTeam);

        if ($exact->count() === 1) {
            return $exact->first();
        }

        return $games->count() === 1 ? $games->first() : null;
    }

    private function team(mixed $value): ?string
    {
        $team = mb_strtoupper(trim((string) $value));
        return $team === '' ? null : (self::TEAM_ALIASES[$team] ?? $team);
    }

    private function status(string $value): string
    {
        return match (mb_strtolower(trim($value))) {
            'confirmed' => 'confirmed',
            'expected', 'probable' => 'expected',
            default => 'unknown',
        };
    }
}
