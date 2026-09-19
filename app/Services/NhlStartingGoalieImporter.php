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
            foreach ([['home', true, 'visit'], ['visit', false, 'home']] as [$side, $isHome, $opponentSide]) {
                $team = $this->team(data_get($matchup, $side . 'team'));
                $opponent = $this->team(data_get($matchup, $opponentSide . 'team'));
                $name = trim((string) (data_get($matchup, $side . 'Player') ?? data_get($matchup, $side . 'Goalie')));
                if ($team === null || $name === '') {
                    continue;
                }
                $game = DB::table('nhl_games')
                    ->whereDate('game_date', $date->toDateString())
                    ->where(function ($query) use ($team, $opponent): void {
                        $query->where(function ($matchupQuery) use ($team, $opponent): void {
                            $matchupQuery->where('home_team_abbrev', $team)
                                ->where('away_team_abbrev', $opponent);
                        })->orWhere(function ($matchupQuery) use ($team, $opponent): void {
                            $matchupQuery->where('away_team_abbrev', $team)
                                ->where('home_team_abbrev', $opponent);
                        });
                    })
                    ->first(['nhl_game_id', 'home_team_abbrev']);
                $gameId = $game?->nhl_game_id;
                $isHome = $game ? $game->home_team_abbrev === $team : $isHome;
                $matchupKey = $gameId
                    ? 'game:' . $gameId
                    : 'matchup:' . $date->toDateString() . ':' . implode(':', array_filter([$team, $opponent]));
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
