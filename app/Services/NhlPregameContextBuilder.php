<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\NhlPregameContextDataUnavailable;
use App\Models\NhlExpectedGoalsModel;
use App\Models\NhlPregameContextRun;
use App\Models\NhlPregameContextRunGame;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Builds compact, pregame-only team and player context snapshots for one game. */
class NhlPregameContextBuilder
{
    public const VERSION = 'pregame_context_v1';

    public const EVALUATION_BUCKET_VERSION = 1;

    /** Freeze the model's Train-only reliable bucket set; never use Test confidence. */
    public function evaluationBucketFilter(int $modelRunId, array $training): array
    {
        $ids = DB::table('nhl_sat_model_entity_profile_buckets')
            ->where('model_run_id', $modelRunId)->where('profile_type', 'skater_offense')
            ->distinct()->limit(2)->pluck('sat_expected_goals_model_id');
        $model = $ids->count() === 1 ? NhlExpectedGoalsModel::find($ids->first()) : null;
        $sources = array_map('strval', data_get($model?->feature_config, 'training_season_ids', []));
        $training = array_map('strval', $training);
        if (! $model || $sources === [] || array_diff($sources, $training) || array_diff($training, $sources)) {
            throw new RuntimeException('Game evaluation requires one SAT bucket model matching its frozen Train seasons.');
        }
        $buckets = $model->buckets()->where('fallback_level', 1)->orderBy('bucket_key')
            ->limit(5001)->get(['bucket_key', 'confidence_score']);
        if ($buckets->isEmpty() || $buckets->count() > 5000) {
            throw new RuntimeException('Game evaluation requires a bounded, built SAT bucket map.');
        }

        return ['version' => self::EVALUATION_BUCKET_VERSION, 'minimum_confidence' => 0.5,
            'sat_model_id' => $model->id, 'feature_config' => $model->feature_config,
            'training_seasons' => $sources,
            'keys' => $buckets->filter(fn ($bucket) => $bucket->confidence_score !== null
                && (float) $bucket->confidence_score >= 0.5)->pluck('bucket_key')->all()];
    }

    /** Count retained shots without dropping appearances or hiding unclassified source facts. */
    public function individualAttemptCounts(int $playerId, array $gameIds, ?array $bucketFilter = null): Collection
    {
        $strength = "CASE WHEN UPPER(f.strength) IN ('EV', 'PP', 'PK') THEN UPPER(f.strength) ELSE UPPER(f.strength_bucket) END";
        $retained = 'true';
        $bindings = [];
        if ($bucketFilter !== null) {
            if (($bucketFilter['version'] ?? null) !== self::EVALUATION_BUCKET_VERSION
                || (float) ($bucketFilter['minimum_confidence'] ?? 0) !== 0.5) {
                throw new RuntimeException('Refresh the saved evaluation bucket filter before comparing games.');
            }
            $keys = $bucketFilter['keys'];
            $model = new NhlExpectedGoalsModel(['feature_config' => $bucketFilter['feature_config']]);
            $expression = app(NhlSatModelEntityProfileBuilder::class)->modelBucketKeySql($model, 'f');
            $retained = $keys === [] ? 'false' : $expression.' IN ('.implode(',', array_fill(0, count($keys), '?')).')';
            $retained = "({$retained}) AND COALESCE(f.is_empty_net, false) = false";
            $bindings = $keys;
        }

        return DB::table('nhl_shot_attempts_facts as f')->where('f.shooter_player_id', $playerId)
            ->whereIn('f.nhl_game_id', $gameIds)->where('f.is_shot_attempt', true)
            ->where(fn ($query) => $query->whereNull('f.period_type')->orWhere('f.period_type', '<>', 'SO'))
            ->select('f.nhl_game_id')->selectRaw("{$strength} as strength, COUNT(*) as source_sat")
            ->selectRaw("SUM(CASE WHEN {$retained} THEN 1 ELSE 0 END) as sat", $bindings)
            ->selectRaw("SUM(CASE WHEN ({$retained}) AND f.is_shot_on_goal THEN 1 ELSE 0 END) as sog", $bindings)
            ->selectRaw("SUM(CASE WHEN ({$retained}) AND f.is_goal THEN 1 ELSE 0 END) as goals", $bindings)
            ->groupBy('f.nhl_game_id')->groupByRaw($strength)->get()->groupBy('nhl_game_id');
    }

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
        $headToHead = $history->filter(fn (object $row): bool => (int) $row->opponent_team_id === $side['opponent_id']);
        $lastGame = $history->first();
        $travel = $this->travelMetrics($side['team_id'], $game, $lastGame, $history);
        $daysSinceLastGame = $lastGame === null
            ? null
            : (int) now()->parse($lastGame->game_date)->startOfDay()
                ->diffInDays(now()->parse($game->game_date)->startOfDay());
        $targetDate = now()->parse($game->game_date)->startOfDay();
        $priorSixDays = $history->filter(fn (object $row): bool => now()->parse($row->game_date)->startOfDay()
            ->betweenIncluded($targetDate->copy()->subDays(6), $targetDate->copy()->subDay()));
        $gamesIncludingTarget = $priorSixDays->count() + 1;
        $schedule = [
            'days_rest' => $daysSinceLastGame === null ? null : max(0, $daysSinceLastGame - 1),
            'back_to_back' => $daysSinceLastGame === 1,
            'games_last_4_days' => $history->filter(fn (object $row): bool => now()->parse($row->start_time_utc)->gte($cutoff->copy()->subDays(4)))->count(),
            'games_last_7_days' => $history->filter(fn (object $row): bool => now()->parse($row->start_time_utc)->gte($cutoff->copy()->subDays(7)))->count(),
            'three_in_four' => $history->filter(fn (object $row): bool => now()->parse($row->start_time_utc)->gte($cutoff->copy()->subDays(4)))->count() >= 2,
            'games_including_target_last_7_days' => $gamesIncludingTarget,
            'third_in_seven' => $gamesIncludingTarget === 3,
            'fourth_in_seven' => $gamesIncludingTarget === 4,
            'back_to_back_fourth_in_seven' => $daysSinceLastGame === 1 && $gamesIncludingTarget === 4,
            'days_on_road' => $this->daysOnRoad($side['venue'], $game, $history),
            ...$travel,
        ];
        $opponent = $this->headToHeadMetrics($headToHead, (string) $game->season_id);
        $teamMetrics = $this->teamMetrics($history, (string) $game->season_id);

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
            'metrics' => json_encode($teamMetrics, JSON_THROW_ON_ERROR),
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
        $history = DB::table('nhl_games')
            ->where('game_type', 2)->whereIn('game_state', ['OFF', 'FINAL'])
            ->where('start_time_utc', '<', $cutoff)
            ->where(fn ($query) => $query->where('home_team_id', $teamId)->orWhere('away_team_id', $teamId))
            ->orderByDesc('start_time_utc')->orderByDesc('nhl_game_id')
            ->get(['nhl_game_id', 'season_id', 'home_team_id', 'away_team_id', 'home_team_score', 'away_team_score', 'home_team_sog', 'away_team_sog', 'start_time_utc', 'game_date'])
            ->map(function (object $row) use ($teamId): object {
                $home = (int) $row->home_team_id === $teamId;
                $row->is_home = $home;
                $row->opponent_team_id = $home ? (int) $row->away_team_id : (int) $row->home_team_id;
                $row->team_score = $home ? $row->home_team_score : $row->away_team_score;
                $row->opponent_score = $home ? $row->away_team_score : $row->home_team_score;
                $row->sog_for = $home ? $row->home_team_sog : $row->away_team_sog;
                $row->sog_against = $home ? $row->away_team_sog : $row->home_team_sog;

                return $row;
            });

        $attempts = DB::table('nhl_shot_attempts_facts')
            ->whereIn('nhl_game_id', $history->pluck('nhl_game_id'))
            ->where('is_shot_attempt', true)
            ->where(fn ($query) => $query->whereNull('period_type')->orWhere('period_type', '<>', 'SO'))
            ->select('nhl_game_id', 'team_id', DB::raw('COUNT(*) as sat'))
            ->groupBy('nhl_game_id', 'team_id')->get()->groupBy('nhl_game_id');

        return $history->map(function (object $row) use ($attempts, $teamId): object {
            $gameAttempts = $attempts->get($row->nhl_game_id, collect());
            $for = $gameAttempts->firstWhere('team_id', $teamId);
            $against = $gameAttempts->firstWhere('team_id', $row->opponent_team_id);
            $row->has_attempt_facts = $for !== null && $against !== null;
            $row->sat_for = $for === null ? null : (int) $for->sat;
            $row->sat_against = $against === null ? null : (int) $against->sat;

            return $row;
        });
    }

    /** @return array<string,array<string,int|float|null>> */
    private function teamMetrics(Collection $history, string $seasonId): array
    {
        $lastSeasonId = (string) ((int) $seasonId - 10001);
        $windows = [
            'last_5' => $history->take(5),
            'last_10' => $history->take(10),
            'last_20' => $history->take(20),
            'last_season' => $history->where('season_id', $lastSeasonId),
            'season_to_date' => $history->where('season_id', $seasonId),
        ];

        return collect($windows)->map(fn (Collection $rows): array => $this->teamWindowMetrics($rows))->all();
    }

    /** @return array<string,int|float|null> */
    private function teamWindowMetrics(Collection $rows): array
    {
        $games = $rows->count();
        $scoreComplete = $games > 0 && $rows->every(fn (object $row): bool => $row->team_score !== null && $row->opponent_score !== null);
        $sogComplete = $games > 0 && $rows->every(fn (object $row): bool => $row->sog_for !== null && $row->sog_against !== null);
        $gf = $scoreComplete ? (int) $rows->sum('team_score') : null;
        $ga = $scoreComplete ? (int) $rows->sum('opponent_score') : null;
        $satComplete = $games > 0 && $rows->every(fn (object $row): bool => $row->has_attempt_facts);
        $satFor = $satComplete ? (int) $rows->sum('sat_for') : null;
        $satAgainst = $satComplete ? (int) $rows->sum('sat_against') : null;
        $sogFor = $sogComplete ? (int) $rows->sum('sog_for') : null;
        $sogAgainst = $sogComplete ? (int) $rows->sum('sog_against') : null;
        $wins = $scoreComplete
            ? $rows->filter(fn (object $row): bool => (int) $row->team_score > (int) $row->opponent_score)->count()
            : null;

        return [
            'games' => $games,
            'wins' => $wins,
            'win_pct' => $wins !== null ? round(100 * $wins / $games, 4) : null,
            'goals_for' => $gf,
            'goals_against' => $ga,
            'goals_for_per_game' => $gf !== null ? round($gf / $games, 4) : null,
            'goals_against_per_game' => $ga !== null ? round($ga / $games, 4) : null,
            'sat_for' => $satFor,
            'sat_against' => $satAgainst,
            'sat_for_per_game' => $satFor !== null ? round($satFor / $games, 4) : null,
            'sat_against_per_game' => $satAgainst !== null ? round($satAgainst / $games, 4) : null,
            'corsi_pct' => $satFor !== null && $satAgainst !== null && ($satFor + $satAgainst) > 0
                ? round(100 * $satFor / ($satFor + $satAgainst), 4)
                : null,
            'sog_for' => $sogFor,
            'sog_against' => $sogAgainst,
            'sog_per_sat_pct' => $satFor !== null && $sogFor !== null && $satFor > 0 ? round(100 * $sogFor / $satFor, 4) : null,
            'shooting_pct' => $sogFor !== null && $gf !== null && $sogFor > 0 ? round(100 * $gf / $sogFor, 4) : null,
            'save_pct' => $sogAgainst !== null && $ga !== null && $sogAgainst > 0 ? round(100 * (1 - ($ga / $sogAgainst)), 4) : null,
            'pdo' => $sogFor === null || $sogAgainst === null || $gf === null || $ga === null || $sogFor === 0 || $sogAgainst === 0
                ? null
                : round((100 * $gf / $sogFor) + (100 * (1 - ($ga / $sogAgainst))), 4),
        ];
    }

    /** @return array<string,mixed> */
    private function headToHeadMetrics(Collection $rows, string $seasonId): array
    {
        return [
            'last_5' => $this->teamWindowMetrics($rows->take(5)),
            'last_10' => $this->teamWindowMetrics($rows->take(10)),
            'last_20' => $this->teamWindowMetrics($rows->take(20)),
            'last_season' => $this->teamWindowMetrics($rows->filter(fn (object $row): bool => (string) $row->season_id === (string) ((int) $seasonId - 10001))),
        ];
    }

    /** Count consecutive calendar days in the current away sequence, including the target date. */
    private function daysOnRoad(string $venue, object $game, Collection $history): ?int
    {
        if ($venue !== 'away') {
            return 0;
        }
        if ($history->isEmpty()) {
            return null;
        }

        $firstRoadDate = now()->parse($game->game_date)->startOfDay();
        $homeAnchorFound = false;
        foreach ($history as $appearance) {
            if ($appearance->is_home) {
                $homeAnchorFound = true;
                break;
            }

            $firstRoadDate = now()->parse($appearance->game_date)->startOfDay();
        }
        if (! $homeAnchorFound) {
            return null;
        }

        return (int) $firstRoadDate->diffInDays(now()->parse($game->game_date)->startOfDay()) + 1;
    }

    /** @return array<string,mixed> */
    private function playerMetrics(int $playerId, int $teamId, int $opponentId, string $venue, CarbonInterface $cutoff, string $seasonId, array $training = [], ?array $bucketFilter = null): array
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
        $attempts = $this->individualAttemptCounts($playerId, $gameIds, $bucketFilter);

        $isOpponent = fn (object $row): bool => ((int) $row->home_team_id === (int) $row->nhl_team_id ? (int) $row->away_team_id : (int) $row->home_team_id) === $opponentId;
        $isVenue = fn (object $row): bool => $venue === 'home' ? (int) $row->home_team_id === (int) $row->nhl_team_id : (int) $row->away_team_id === (int) $row->nhl_team_id;

        return [
            'all' => $bucketFilter !== null
                ? $this->strengthMetrics($history, $exposure, $attempts, $opponentId, $venue, $seasonId, 'ALL', $training)
                : [
                ...($training === [] ? [] : ['train' => $this->summaryMetrics($history->filter(fn ($row) => in_array((string) $row->season_id, $training, true)))]),
                ...($training === [] ? [] : ['venue_train' => $this->summaryMetrics($history->filter(fn ($row) => in_array((string) $row->season_id, $training, true))->filter($isVenue))]),
                ...($training === [] ? [] : ['opponent_train' => $this->summaryMetrics($history->filter(fn ($row) => in_array((string) $row->season_id, $training, true))->filter($isOpponent))]),
                'last_season' => $this->summaryMetrics($history->where('season_id', (string) ((int) $seasonId - 10001))),
                'venue_last_season' => $this->summaryMetrics($history->where('season_id', (string) ((int) $seasonId - 10001))->filter($isVenue)),
                'opponent_last_season' => $this->summaryMetrics($history->where('season_id', (string) ((int) $seasonId - 10001))->filter($isOpponent)),
                'last_5' => $this->summaryMetrics($history->take(5)),
                'last_10' => $this->summaryMetrics($history->take(10)),
                'last_20' => $this->summaryMetrics($history->take(20)),
                'season_to_date' => $this->summaryMetrics($history->filter(fn (object $row): bool => (string) $row->season_id === $seasonId)),
                ...($training === [] ? [] : collect(range(1, 4))->mapWithKeys(fn (int $quarter): array => [
                    'q'.$quarter.'_to_date' => $this->summaryMetrics($history->where('season_id', $seasonId)->reverse()->values()->slice(($quarter - 1) * 20, $quarter === 4 ? 22 : 20)),
                ])->all()),
                'venue_season_to_date' => $this->summaryMetrics($history->filter(fn (object $row): bool => (string) $row->season_id === $seasonId)->filter($isVenue)),
                'opponent_last_10' => $this->summaryMetrics($history->filter($isOpponent)->take(10)),
                'opponent_last_5' => $this->summaryMetrics($history->filter($isOpponent)->take(5)),
                'opponent_last_20' => $this->summaryMetrics($history->filter($isOpponent)->take(20)),
                'opponent_season_to_date' => $this->summaryMetrics($history->filter(fn (object $row): bool => (string) $row->season_id === $seasonId)->filter($isOpponent)),
                'opponent_last_season' => $this->summaryMetrics($history->filter(fn (object $row): bool => (string) $row->season_id === (string) ((int) $seasonId - 10001))->filter($isOpponent)),
            ],
            'individual_strength_version' => 1,
            'strength' => collect(['EV', 'PP', 'PK'])->mapWithKeys(fn (string $strength): array => [
                $strength => $this->strengthMetrics($history, $exposure, $attempts, $opponentId, $venue, $seasonId, $strength, $training),
            ])->all(),
        ];
    }

    /** Read one player's evaluation context without writing shared snapshots or using target outcomes. */
    public function evaluationPlayerMetrics(int $playerId, int $teamId, int $opponentId, string $venue, CarbonInterface $cutoff, string $seasonId, array $training, ?array $bucketFilter = null): array
    {
        return $this->playerMetrics($playerId, $teamId, $opponentId, $venue, $cutoff, $seasonId, array_map('strval', $training), $bucketFilter);
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
    private function strengthMetrics(Collection $history, Collection $exposure, Collection $attempts, int $opponentId, string $venue, string $seasonId, string $strength, array $training = []): array
    {
        // Choose appearances first: a game with no PP/PK time still belongs in L5/L10/L20.
        $history = $history->map(function (object $appearance) use ($exposure, $attempts, $strength): object {
            $strengthRows = $exposure->get($appearance->nhl_game_id, collect());
            $strengthRow = $strength === 'ALL' ? $appearance : $strengthRows->firstWhere('strength', $strength);
            $facts = $attempts->get($appearance->nhl_game_id, collect());
            $counts = $strength === 'ALL' ? (object) ['sat' => $facts->sum('sat'), 'source_sat' => $facts->sum('source_sat'), 'sog' => $facts->sum('sog'), 'goals' => $facts->sum('goals')]
                : $facts->firstWhere('strength', $strength);
            $toi = (int) ($strengthRow->toi ?? 0);
            $available = (bool) $appearance->has_shot_facts && ($strength === 'ALL' || $strengthRows->isNotEmpty())
                && ($strength === 'ALL' || ! $facts->contains(fn (object $fact): bool => ! in_array($fact->strength, ['EV', 'PP', 'PK'], true)))
                && ($toi > 0 || (int) ($counts->source_sat ?? 0) === 0);

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
            ...($training === [] ? [] : ['train' => $this->strengthWindowMetrics($history->filter(fn ($row) => in_array((string) $row->season_id, $training, true)), true)]),
            ...($training === [] ? [] : ['venue_train' => $this->strengthWindowMetrics($history->filter(fn ($row) => in_array((string) $row->season_id, $training, true))->filter($isVenue), true)]),
            ...($training === [] ? [] : ['opponent_train' => $this->strengthWindowMetrics($history->filter(fn ($row) => in_array((string) $row->season_id, $training, true))->filter($isOpponent), true)]),
            'last_season' => $this->strengthWindowMetrics($history->where('season_id', (string) ((int) $seasonId - 10001))),
            'venue_last_season' => $this->strengthWindowMetrics($history->where('season_id', (string) ((int) $seasonId - 10001))->filter($isVenue)),
            'opponent_last_season' => $this->strengthWindowMetrics($history->where('season_id', (string) ((int) $seasonId - 10001))->filter($isOpponent)),
            'last_5' => $this->strengthWindowMetrics($history->take(5)),
            'last_10' => $this->strengthWindowMetrics($history->take(10)),
            'last_20' => $this->strengthWindowMetrics($history->take(20)),
            'season_to_date' => $this->strengthWindowMetrics($history->filter(fn (object $row): bool => (string) $row->season_id === $seasonId)),
            ...($training === [] ? [] : collect(range(1, 4))->mapWithKeys(fn (int $quarter): array => [
                'q'.$quarter.'_to_date' => $this->strengthWindowMetrics($history->where('season_id', $seasonId)->reverse()->values()->slice(($quarter - 1) * 20, $quarter === 4 ? 22 : 20)),
            ])->all()),
            'venue_season_to_date' => $this->strengthWindowMetrics($history->filter(fn (object $row): bool => (string) $row->season_id === $seasonId)->filter($isVenue)),
            'opponent_last_10' => $this->strengthWindowMetrics($history->filter($isOpponent)->take(10)),
            'opponent_last_5' => $this->strengthWindowMetrics($history->filter($isOpponent)->take(5)),
            'opponent_last_20' => $this->strengthWindowMetrics($history->filter($isOpponent)->take(20)),
            'opponent_season_to_date' => $this->strengthWindowMetrics($history->filter(fn (object $row): bool => (string) $row->season_id === $seasonId)->filter($isOpponent)),
            'opponent_last_season' => $this->strengthWindowMetrics($history->filter(fn (object $row): bool => (string) $row->season_id === (string) ((int) $seasonId - 10001))->filter($isOpponent)),
        ];
    }

    /** Combine individual counts with matching strength exposure, never on-ice shot totals. */
    private function strengthWindowMetrics(Collection $rows, bool $completeGamesOnly = false): array
    {
        $missing = $rows->where('available', false)->count();
        if ($completeGamesOnly) {
            $rows = $rows->where('available', true);
        }
        $available = $rows->isNotEmpty() && ($completeGamesOnly || $missing === 0);
        $toi = $rows->sum('toi');
        $sat = $available ? (int) $rows->sum('sat') : null;
        $sog = $available ? (int) $rows->sum('sog') : null;
        $goals = $available ? (int) $rows->sum('goals') : null;

        return array_merge($this->onIceMetrics($rows->pluck('exposure')->filter()), [
            'games' => $rows->count(),
            'individual_missing_games' => $missing,
            ...($completeGamesOnly ? ['usable_games' => $rows->count()] : []),
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
