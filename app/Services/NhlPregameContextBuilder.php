<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\NhlPregameContextDataUnavailable;
use App\Models\NhlPregameContextRun;
use App\Models\NhlPregameContextRunGame;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Builds compact, pregame-only team and player context snapshots for one game. */
class NhlPregameContextBuilder
{
    public const VERSION = 'pregame_context_v1';

    /**
     * @return array{team_context_count:int,player_context_count:int}
     */
    public function build(NhlPregameContextRun $run, int $gameId): array
    {
        $game = DB::table('nhl_games')->where('nhl_game_id', $gameId)->first();
        if ($game === null || $game->start_time_utc === null || $game->home_team_id === null || $game->away_team_id === null) {
            throw new NhlPregameContextDataUnavailable('The game is missing schedule context.');
        }

        $cutoff = now()->parse($game->start_time_utc);
        $participants = DB::table('nhl_game_summaries')
            ->where('nhl_game_id', $gameId)
            ->whereIn('nhl_team_id', [$game->away_team_id, $game->home_team_id])
            ->orderBy('nhl_team_id')
            ->get(['nhl_player_id', 'nhl_team_id']);

        if ($participants->isEmpty()) {
            throw new NhlPregameContextDataUnavailable('The game has no completed player summaries.');
        }

        $teamContextIds = [];
        foreach ([
            ['team_id' => (int) $game->away_team_id, 'opponent_id' => (int) $game->home_team_id, 'venue' => 'away'],
            ['team_id' => (int) $game->home_team_id, 'opponent_id' => (int) $game->away_team_id, 'venue' => 'home'],
        ] as $side) {
            $teamContextIds[$side['team_id']] = $this->storeTeamContext($run, $game, $cutoff, $side);
        }

        $playerCount = 0;
        foreach ($participants as $participant) {
            $teamId = (int) $participant->nhl_team_id;
            $opponentId = $teamId === (int) $game->away_team_id ? (int) $game->home_team_id : (int) $game->away_team_id;
            $venue = $teamId === (int) $game->away_team_id ? 'away' : 'home';
            $metrics = $this->playerMetrics((int) $participant->nhl_player_id, $teamId, $opponentId, $venue, $cutoff, (string) $game->season_id);

            DB::table('nhl_player_game_pregame_contexts')->upsert([[
                'run_id' => $run->id,
                'team_context_id' => $teamContextIds[$teamId] ?? null,
                'nhl_game_id' => $gameId,
                'nhl_player_id' => (int) $participant->nhl_player_id,
                'nhl_team_id' => $teamId,
                'opponent_team_id' => $opponentId,
                'game_date' => $game->game_date,
                'source_cutoff_at' => $cutoff,
                'venue' => $venue,
                'participant_source' => 'completed_game_summary',
                'context_version' => self::VERSION,
                'metrics' => json_encode($metrics, JSON_THROW_ON_ERROR),
                'ranks' => json_encode([], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]], ['nhl_game_id', 'nhl_player_id', 'nhl_team_id', 'context_version'], [
                'run_id', 'team_context_id', 'opponent_team_id', 'game_date', 'source_cutoff_at', 'venue', 'participant_source', 'metrics', 'ranks', 'updated_at',
            ]);
            $playerCount++;
        }

        return ['team_context_count' => count($teamContextIds), 'player_context_count' => $playerCount];
    }

    /** @param array{team_id:int,opponent_id:int,venue:string} $side */
    private function storeTeamContext(NhlPregameContextRun $run, object $game, CarbonInterface $cutoff, array $side): int
    {
        $history = $this->teamHistory($side['team_id'], $cutoff);
        $opponentHistory = $history->filter(fn (object $row): bool => (int) $row->opponent_team_id === $side['opponent_id'])->take(10);
        $lastGame = $history->first();
        $travel = $this->travelMetrics($side['team_id'], $game, $lastGame, $history);
        $daysSinceLastGame = $lastGame === null
            ? null
            : (int) now()->parse($lastGame->game_date)->startOfDay()
                ->diffInDays(now()->parse($game->game_date)->startOfDay());
        $schedule = [
            'days_rest' => $daysSinceLastGame === null ? null : max(0, $daysSinceLastGame - 1),
            'back_to_back' => $daysSinceLastGame === 1,
            'games_last_4_days' => $history->filter(fn (object $row): bool => now()->parse($row->start_time_utc)->gte($cutoff->copy()->subDays(4)))->count(),
            'games_last_7_days' => $history->filter(fn (object $row): bool => now()->parse($row->start_time_utc)->gte($cutoff->copy()->subDays(7)))->count(),
            'three_in_four' => $history->filter(fn (object $row): bool => now()->parse($row->start_time_utc)->gte($cutoff->copy()->subDays(4)))->count() >= 2,
            ...$travel,
        ];
        $opponent = [
            'last_10_games' => $opponentHistory->count(),
            'wins' => $opponentHistory->filter(fn (object $row): bool => (int) $row->team_score > (int) $row->opponent_score)->count(),
            'losses' => $opponentHistory->filter(fn (object $row): bool => (int) $row->team_score < (int) $row->opponent_score)->count(),
        ];

        DB::table('nhl_team_game_pregame_contexts')->upsert([[
            'run_id' => $run->id,
            'nhl_game_id' => $game->nhl_game_id,
            'nhl_team_id' => $side['team_id'],
            'opponent_team_id' => $side['opponent_id'],
            'game_date' => $game->game_date,
            'source_cutoff_at' => $cutoff,
            'venue' => $side['venue'],
            'context_version' => self::VERSION,
            'schedule_metrics' => json_encode($schedule, JSON_THROW_ON_ERROR),
            'opponent_metrics' => json_encode($opponent, JSON_THROW_ON_ERROR),
            'metrics' => json_encode([], JSON_THROW_ON_ERROR),
            'ranks' => json_encode([], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]], ['nhl_game_id', 'nhl_team_id', 'context_version'], [
            'run_id', 'opponent_team_id', 'game_date', 'source_cutoff_at', 'venue', 'schedule_metrics', 'opponent_metrics', 'metrics', 'ranks', 'updated_at',
        ]);

        return (int) DB::table('nhl_team_game_pregame_contexts')
            ->where('nhl_game_id', $game->nhl_game_id)->where('nhl_team_id', $side['team_id'])
            ->where('context_version', self::VERSION)->value('id');
    }

    private function teamHistory(int $teamId, CarbonInterface $cutoff): Collection
    {
        return DB::table('nhl_games')
            ->where('game_type', 2)->whereIn('game_state', ['OFF', 'FINAL'])
            ->where('start_time_utc', '<', $cutoff)
            ->where(fn ($query) => $query->where('home_team_id', $teamId)->orWhere('away_team_id', $teamId))
            ->orderByDesc('start_time_utc')->orderByDesc('nhl_game_id')
            ->get(['nhl_game_id', 'home_team_id', 'away_team_id', 'home_team_score', 'away_team_score', 'start_time_utc', 'game_date'])
            ->map(function (object $row) use ($teamId): object {
                $home = (int) $row->home_team_id === $teamId;
                $row->opponent_team_id = $home ? (int) $row->away_team_id : (int) $row->home_team_id;
                $row->team_score = $home ? (int) $row->home_team_score : (int) $row->away_team_score;
                $row->opponent_score = $home ? (int) $row->away_team_score : (int) $row->home_team_score;

                return $row;
            });
    }

    /** @return array<string,mixed> */
    private function playerMetrics(int $playerId, int $teamId, int $opponentId, string $venue, CarbonInterface $cutoff, string $seasonId): array
    {
        $history = DB::table('nhl_game_summaries as summaries')
            ->join('nhl_games as games', 'games.nhl_game_id', '=', 'summaries.nhl_game_id')
            ->where('summaries.nhl_player_id', $playerId)
            ->where('summaries.toi', '>', 0)
            ->where('games.game_type', 2)->whereIn('games.game_state', ['OFF', 'FINAL'])
            ->where('games.start_time_utc', '<', $cutoff)->orderByDesc('games.start_time_utc')->orderByDesc('games.nhl_game_id')
            ->select(['summaries.*', 'games.season_id', 'games.home_team_id', 'games.away_team_id', 'games.start_time_utc'])
            ->selectRaw('EXISTS (SELECT 1 FROM nhl_shot_attempts_facts facts WHERE facts.nhl_game_id = summaries.nhl_game_id AND facts.is_shot_attempt = true) as has_shot_facts')
            ->get();

        $gameIds = $history->pluck('nhl_game_id')->all();
        $exposure = DB::table('nhl_player_game_strength_summaries')
            ->where('nhl_player_id', $playerId)->whereIn('nhl_game_id', $gameIds)
            ->get()->groupBy('nhl_game_id');
        // Raw strength preserves EV/PP/PK when descriptive buckets say empty-net or penalty-shot.
        $strengthExpression = "CASE WHEN UPPER(strength) IN ('EV', 'PP', 'PK') THEN UPPER(strength) ELSE UPPER(strength_bucket) END";
        $attempts = DB::table('nhl_shot_attempts_facts')
            ->where('shooter_player_id', $playerId)->whereIn('nhl_game_id', $gameIds)
            ->where('is_shot_attempt', true)
            ->where(fn ($query) => $query->whereNull('period_type')->orWhere('period_type', '<>', 'SO'))
            ->select('nhl_game_id')
            ->selectRaw("{$strengthExpression} as strength, COUNT(*) as sat")
            ->selectRaw('SUM(CASE WHEN is_shot_on_goal THEN 1 ELSE 0 END) as sog')
            ->selectRaw('SUM(CASE WHEN is_goal THEN 1 ELSE 0 END) as goals')
            ->groupBy('nhl_game_id')->groupByRaw($strengthExpression)
            ->get()->groupBy('nhl_game_id');

        $isOpponent = fn (object $row): bool => ((int) $row->home_team_id === (int) $row->nhl_team_id ? (int) $row->away_team_id : (int) $row->home_team_id) === $opponentId;
        $isVenue = fn (object $row): bool => $venue === 'home' ? (int) $row->home_team_id === (int) $row->nhl_team_id : (int) $row->away_team_id === (int) $row->nhl_team_id;

        return [
            'all' => [
                'last_5' => $this->summaryMetrics($history->take(5)),
                'last_10' => $this->summaryMetrics($history->take(10)),
                'last_20' => $this->summaryMetrics($history->take(20)),
                'season_to_date' => $this->summaryMetrics($history->filter(fn (object $row): bool => (string) $row->season_id === $seasonId)),
                'venue_season_to_date' => $this->summaryMetrics($history->filter(fn (object $row): bool => (string) $row->season_id === $seasonId)->filter($isVenue)),
                'opponent_last_10' => $this->summaryMetrics($history->filter($isOpponent)->take(10)),
            ],
            'individual_strength_version' => 1,
            'strength' => collect(['EV', 'PP', 'PK'])->mapWithKeys(fn (string $strength): array => [
                $strength => $this->strengthMetrics($history, $exposure, $attempts, $opponentId, $venue, $seasonId, $strength),
            ])->all(),
        ];
    }

    /** @return array<string,int|float|null> */
    private function summaryMetrics(Collection $rows): array
    {
        $games = $rows->count();
        $toi = (int) $rows->sum('toi');
        $sat = (int) $rows->sum('sat');
        $sog = (int) $rows->sum('sog');
        $goals = (int) $rows->sum('g');

        return [
            'games' => $games, 'toi_seconds' => $toi, 'sat' => $sat, 'sog' => $sog, 'goals' => $goals,
            'assists' => (int) $rows->sum('a'), 'points' => (int) $rows->sum('pts'),
            'sat_per_60' => $toi > 0 ? round($sat * 3600 / $toi, 4) : null,
            'sog_per_60' => $toi > 0 ? round($sog * 3600 / $toi, 4) : null,
            'goals_per_60' => $toi > 0 ? round($goals * 3600 / $toi, 4) : null,
            'shooting_pct' => $sog > 0 ? round(100 * $goals / $sog, 4) : null,
        ];
    }

    /** @return array<string,array<string,int|float|null>> */
    private function strengthMetrics(Collection $history, Collection $exposure, Collection $attempts, int $opponentId, string $venue, string $seasonId, string $strength): array
    {
        // Choose appearances first: a game with no PP/PK time still belongs in L5/L10/L20.
        $history = $history->map(function (object $appearance) use ($exposure, $attempts, $strength): object {
            $strengthRows = $exposure->get($appearance->nhl_game_id, collect());
            $strengthRow = $strengthRows->firstWhere('strength', $strength);
            $facts = $attempts->get($appearance->nhl_game_id, collect());
            $counts = $facts->firstWhere('strength', $strength);
            $toi = (int) ($strengthRow->toi ?? 0);
            $available = (bool) $appearance->has_shot_facts && $strengthRows->isNotEmpty()
                && ! $facts->contains(fn (object $fact): bool => ! in_array($fact->strength, ['EV', 'PP', 'PK'], true))
                && ($toi > 0 || (int) ($counts->sat ?? 0) === 0);

            return (object) [
                'nhl_team_id' => $appearance->nhl_team_id,
                'home_team_id' => $appearance->home_team_id,
                'away_team_id' => $appearance->away_team_id,
                'season_id' => $appearance->season_id,
                'exposure' => $strengthRow,
                'available' => $available,
                'toi' => $toi,
                'sat' => (int) ($counts->sat ?? 0),
                'sog' => (int) ($counts->sog ?? 0),
                'goals' => (int) ($counts->goals ?? 0),
            ];
        });
        $isOpponent = fn (object $row): bool => ((int) $row->home_team_id === (int) $row->nhl_team_id ? (int) $row->away_team_id : (int) $row->home_team_id) === $opponentId;
        $isVenue = fn (object $row): bool => $venue === 'home' ? (int) $row->home_team_id === (int) $row->nhl_team_id : (int) $row->away_team_id === (int) $row->nhl_team_id;

        return [
            'last_5' => $this->strengthWindowMetrics($history->take(5)),
            'last_10' => $this->strengthWindowMetrics($history->take(10)),
            'last_20' => $this->strengthWindowMetrics($history->take(20)),
            'season_to_date' => $this->strengthWindowMetrics($history->filter(fn (object $row): bool => (string) $row->season_id === $seasonId)),
            'venue_season_to_date' => $this->strengthWindowMetrics($history->filter(fn (object $row): bool => (string) $row->season_id === $seasonId)->filter($isVenue)),
            'opponent_last_10' => $this->strengthWindowMetrics($history->filter($isOpponent)->take(10)),
        ];
    }

    /** Combine individual counts with matching strength exposure, never on-ice shot totals. */
    private function strengthWindowMetrics(Collection $rows): array
    {
        $missing = $rows->where('available', false)->count();
        $available = $rows->isNotEmpty() && $missing === 0;
        $toi = $rows->sum('toi');
        $sat = $available ? (int) $rows->sum('sat') : null;
        $sog = $available ? (int) $rows->sum('sog') : null;
        $goals = $available ? (int) $rows->sum('goals') : null;

        return array_merge($this->onIceMetrics($rows->pluck('exposure')->filter()), [
            'games' => $rows->count(),
            'individual_missing_games' => $missing,
            'sat' => $sat, 'sog' => $sog, 'goals' => $goals,
            'sat_per_60' => $available && $toi > 0 ? round($sat * 3600 / $toi, 4) : null,
            'sog_per_60' => $available && $toi > 0 ? round($sog * 3600 / $toi, 4) : null,
            'goals_per_60' => $available && $toi > 0 ? round($goals * 3600 / $toi, 4) : null,
            'shooting_pct' => $sog > 0 ? round(100 * $goals / $sog, 4) : null,
        ]);
    }

    /** @return array<string,int|float|null> */
    private function onIceMetrics(Collection $rows): array
    {
        $games = $rows->count();
        $toi = (int) $rows->sum('toi');
        $cf = (int) $rows->sum('satf');
        $ca = (int) $rows->sum('sata');
        $sf = (int) $rows->sum('sf');
        $sa = (int) $rows->sum('sa');
        $gf = (int) $rows->sum('gf');
        $ga = (int) $rows->sum('ga');
        $points = (int) $rows->sum('individual_pts');
        $onIceShooting = $sf > 0 ? 100 * $gf / $sf : null;
        $onIceSave = $sa > 0 ? 100 * (1 - ($ga / $sa)) : null;

        return [
            'games' => $games, 'toi_seconds' => $toi, 'corsi_for' => $cf, 'corsi_against' => $ca,
            'corsi_pct' => ($cf + $ca) > 0 ? round(100 * $cf / ($cf + $ca), 4) : null,
            'corsi_for_per_60' => $toi > 0 ? round($cf * 3600 / $toi, 4) : null,
            'corsi_against_per_60' => $toi > 0 ? round($ca * 3600 / $toi, 4) : null,
            'on_ice_shooting_pct' => $onIceShooting === null ? null : round($onIceShooting, 4),
            'on_ice_save_pct' => $onIceSave === null ? null : round($onIceSave, 4),
            'pdo' => $onIceShooting === null || $onIceSave === null ? null : round($onIceShooting + $onIceSave, 4),
            'ipp' => $gf > 0 ? round(100 * $points / $gf, 4) : null,
        ];
    }

    /** @return array<string,float|null> */
    private function travelMetrics(int $teamId, object $game, ?object $lastGame, Collection $history): array
    {
        $current = $this->arenaForTeam((int) $game->home_team_id, $game->game_date);
        if ($lastGame === null || $current === null) {
            return ['travel_km_since_last_game' => null, 'travel_mi_since_last_game' => null, 'travel_km_last_7_days' => null];
        }

        $lastVenue = $this->arenaForTeam((int) $lastGame->home_team_id, $lastGame->game_date);
        $distance = $lastVenue === null ? null : $this->distanceKm($lastVenue, $current);
        $windowStart = now()->parse($game->start_time_utc)->subDays(7);
        $recentGames = $history
            ->filter(fn (object $row): bool => now()->parse($row->start_time_utc)->gte($windowStart))
            ->sortBy('start_time_utc')
            ->values();
        // The preceding venue anchors the first arrival inside the window.
        $anchor = $history->first(fn (object $row): bool => now()->parse($row->start_time_utc)->lt($windowStart));
        $priorVenue = $anchor === null ? null : $this->arenaForTeam((int) $anchor->home_team_id, $anchor->game_date);
        $complete = $priorVenue !== null;
        $rolling = 0.0;
        foreach ($recentGames->concat([$game]) as $recentGame) {
            $venue = $this->arenaForTeam((int) $recentGame->home_team_id, $recentGame->game_date);
            if ($priorVenue === null || $venue === null) {
                $complete = false;
            } else {
                $rolling += $this->distanceKm($priorVenue, $venue);
            }
            $priorVenue = $venue;
        }

        return [
            'travel_km_since_last_game' => $distance === null ? null : round($distance, 2),
            'travel_mi_since_last_game' => $distance === null ? null : round($distance * 0.621371, 2),
            'travel_km_last_7_days' => $complete ? round($rolling, 2) : null,
        ];
    }

    /** @return object{latitude:float,longitude:float}|null */
    private function arenaForTeam(int $teamId, string $date): ?object
    {
        $row = DB::table('nhl_arena_locations')->where('nhl_team_id', $teamId)
            ->where(fn ($query) => $query->whereNull('effective_from')->orWhereDate('effective_from', '<=', $date))
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))
            ->orderByDesc('effective_from')->first(['latitude', 'longitude']);

        return $row === null ? null : (object) ['latitude' => (float) $row->latitude, 'longitude' => (float) $row->longitude];
    }

    private function distanceKm(object $from, object $to): float
    {
        $earthRadiusKm = 6371.0;
        $latDelta = deg2rad($to->latitude - $from->latitude);
        $lonDelta = deg2rad($to->longitude - $from->longitude);
        $a = sin($latDelta / 2) ** 2 + cos(deg2rad($from->latitude)) * cos(deg2rad($to->latitude)) * sin($lonDelta / 2) ** 2;

        return $earthRadiusKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
