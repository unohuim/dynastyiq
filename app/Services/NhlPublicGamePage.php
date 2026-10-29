<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NhlGame;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Cached public previews and recaps, separate from partner prediction assembly. */
final class NhlPublicGamePage
{
    /** Reuse existing lineup evidence and live provider readers. */
    public function __construct(
        private readonly NhlAnticipatedLineupPayload $lineups,
        private readonly NhlGameLiveContext $live,
    ) {
    }

    /** First paint reads only schedule facts, saved public odds and the shared live envelope.
     * @return array<string,mixed>
     */
    public function overview(int $gameId): array
    {
        $game = NhlGame::query()->findOrFail($gameId);
        $feed = app(NhlLiveGameFeed::class);
        $snapshot = $feed->snapshot($gameId);
        $state = strtoupper((string) $game->game_state);
        $final = in_array($state, ['OFF', 'FINAL'], true);
        $live = ! in_array($state, ['', 'FUT', 'PRE', 'OFF', 'FINAL', 'PPD', 'CNCL'], true);
        if (! $final && $snapshot !== null && ($feed->fresh($snapshot) || ($snapshot['game']['mode'] ?? null) === 'recap')
            && ($snapshot['game']['mode'] ?? null) !== 'preview') {
            $compact = $feed->compact($snapshot)['game'];

            return ['game' => [...$compact, 'venue' => $snapshot['game']['venue'] ?? null, 'prediction' => null]];
        }
        $teams = [];
        foreach (['away', 'home'] as $side) {
            $teams[$side] = [
                'team_abbrev' => $live ? null : $game->{$side.'_team_abbrev'},
                'team_name' => $live ? null : $game->{$side.'_team_common_name'},
                'team_logo' => $live ? null : $game->{$side.'_team_logo'},
                'score' => $final ? $game->{$side.'_team_score'} : null,
                'sog' => $final ? $game->{$side.'_team_sog'} : null,
            ];
        }

        return ['game' => [
            'nhl_game_id' => $gameId, 'game_date' => $game->game_date->toDateString(),
            'start_time_utc' => $game->start_time_utc?->toIso8601String(), 'venue' => $live ? null : $game->venue,
            'game_state' => $state, 'mode' => $final ? 'recap' : ($live ? 'live' : 'preview'),
            'live_data_unavailable' => $live,
            'status' => $final ? ($game->shootout_in_use ? 'Final / SO' : ($game->ot_in_use ? 'Final / OT' : 'Final'))
                : ($live ? 'Live' : match ($state) { 'PRE' => 'Starting soon', 'PPD' => 'Postponed', 'CNCL' => 'Cancelled', default => 'Preview' }),
            ...$teams, 'prediction' => ! $final && ! $live ? $this->prediction($game) : null,
        ]];
    }

    /** Read one deferred section without rebuilding unrelated preview sections.
     * @return array<string,mixed>|null Null means another builder already owns preparation.
     */
    public function panel(int $gameId, string $section): ?array
    {
        $game = NhlGame::query()->findOrFail($gameId);
        $feed = app(NhlLiveGameFeed::class);
        $snapshot = $feed->snapshot($gameId);
        $current = $snapshot !== null && ($feed->fresh($snapshot) || ($snapshot['game']['mode'] ?? null) === 'recap')
            && ($snapshot['game']['mode'] ?? null) !== 'preview' ? $snapshot['game'] : null;
        if ($current === null && ! in_array(strtoupper((string) $game->game_state), ['', 'FUT', 'PRE', 'OFF', 'FINAL', 'PPD', 'CNCL'], true)) {
            abort(503, 'Live details temporarily unavailable.');
        }
        if ($current === null && $section === 'matchup') {
            $current = ['mode' => 'preview'];
            foreach (['away', 'home'] as $side) {
                $current[$side] = ['form' => $this->form($game, (int) $game->{$side.'_team_id'}),
                    'starting_goalie' => app(NhlStartingGoalieSelector::class)->select($gameId, (string) $game->{$side.'_team_abbrev'}, (string) $game->season_id)];
            }
        } elseif ($current === null && $section === 'meetings') {
            $current = ['mode' => 'preview', 'head_to_head' => $this->meetings($game)];
        } elseif ($current === null) {
            $prepared = Cache::get($this->cacheKey($game));
            if (! is_array($prepared)) {
                \App\Jobs\RefreshNhlPublicGamePageJob::dispatch($gameId)->afterCommit();

                return null;
            }
            $current = $prepared['game'];
        }
        $keys = match ($section) {
            'matchup' => ['form', 'starting_goalie'],
            'lineups' => ['lineup', 'display_lineup', 'is_projected', 'predictions', 'injuries'],
            'summary' => ['pp_goals', 'pp_opportunities'],
            'boxscore' => ['boxscore'],
            default => [],
        };
        $data = [];
        foreach (['away', 'home'] as $side) {
            if ($keys !== []) {
                $data[$side] = Arr::only($current[$side] ?? [], $keys);
            }
        }
        $data = [...$data, ...Arr::only($current, match ($section) {
            'meetings' => ['head_to_head'], 'summary' => ['recap', 'comparison', 'period_scores'],
            'play_by_play' => ['plays', 'shots', 'map_events', 'plays_available'],
            'goals' => ['events', 'events_available'], default => [],
        })];

        return ['nhl_game_id' => $gameId, 'mode' => $current['mode'], 'section' => $section, 'data' => $data];
    }

    /** Serialize cold detail builds across queue workers and deferred browser requests.
     * @return array<string,mixed>|null
     */
    public function prepare(int $gameId, bool $refresh = false): ?array
    {
        $lock = Cache::lock('nhl:public-game:prepare:'.$gameId, 65);
        if (! $lock->get()) {
            return null;
        }
        try {
            return $this->payload($gameId, $refresh);
        } finally {
            $lock->release();
        }
    }

    /** Read a prepared game, refreshing only recent unfinished provider evidence.
     * @return array<string,mixed>
     */
    public function payload(int $gameId, bool $refresh = false): array
    {
        $game = NhlGame::query()->findOrFail($gameId);
        $state = strtoupper((string) $game->game_state);
        $final = in_array($state, ['FINAL', 'OFF'], true);
        $recent = in_array($game->game_date->toDateString(), [now('UTC')->toDateString(), now('UTC')->subDay()->toDateString()], true);
        if (! $final && $recent) {
            $provider = $this->live->publicDetail($game);
            if ($provider !== null) {
                $result = $this->livePayload($gameId, $provider);
                if ($result['game']['mode'] === 'recap') {
                    Cache::put($this->cacheKey($game), $result, 300);
                }

                return $result;
            }
            if (! in_array($state, ['', 'FUT', 'PRE', 'PPD', 'CNCL'], true)) {
                return ['game' => ['nhl_game_id' => $gameId, 'mode' => 'live', 'live_data_unavailable' => true,
                    'away' => [], 'home' => [], 'events' => [], 'comparison' => [], 'head_to_head' => []]];
            }
        }

        $key = $this->cacheKey($game);
        if ($refresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, $final ? 21600 : 300, fn (): array => $this->build($game));
    }

    /** Invalidate one prepared public snapshot after its source writes commit. */
    public function forget(int $gameId): void
    {
        Cache::forever('nhl:public-game:version:'.$gameId, (string) Str::uuid());
    }

    /** Invalidate and prepare one game only after the surrounding writes commit. */
    public function refreshAfterCommit(int $gameId): void
    {
        DB::afterCommit(function () use ($gameId): void {
            $this->forget($gameId);
            \App\Jobs\RefreshNhlPublicGamePageJob::dispatch($gameId)->afterCommit();
        });
    }

    /** Fence in-flight builders when schedule or public source facts change. */
    private function cacheKey(NhlGame $game): string
    {
        $version = Cache::get('nhl:public-game:version:'.$game->nhl_game_id, 'initial');

        return 'nhl:public-game:v2:'.$game->nhl_game_id.':'.sha1($game->game_state.':'.(string) $game->updated_at.':'.$version);
    }

    /** Build only the requested game's public facts; never run a prediction engine.
     * @return array<string,mixed>
     */
    private function build(NhlGame $game): array
    {
        $final = in_array(strtoupper((string) $game->game_state), ['FINAL', 'OFF'], true);
        $teams = [];
        $preview = $final ? [] : $this->lineups->gamePage((int) $game->nhl_game_id)['game'];
        $boxes = $final ? DB::table('nhl_boxscores')->where('nhl_game_id', $game->nhl_game_id)
            ->orderByDesc('points')->orderByDesc('sog')->orderBy('nhl_player_id')->get() : collect();
        if ($final && $boxes->isEmpty()) {
            $snapshot = app(NhlLiveGameFeed::class)->snapshot((int) $game->nhl_game_id);
            if (($snapshot['game']['mode'] ?? null) === 'recap') {
                return ['game' => $snapshot['game']];
            }
        }
        $summary = $final ? DB::table('nhl_game_summaries')->where('nhl_game_id', $game->nhl_game_id)->get() : collect();
        $prediction = $this->prediction($game);
        foreach (['away', 'home'] as $side) {
            $teamId = (int) $game->{$side.'_team_id'};
            $team = (string) $game->{$side.'_team_abbrev'};
            $rows = $boxes->where('nhl_team_id', $teamId)->values();
            $skaters = $rows->reject(fn ($row): bool => $row->position === 'G');
            $teams[$side] = [
                ...($preview[$side] ?? []),
                'team_id' => $teamId, 'team_abbrev' => $team, 'team_name' => $game->{$side.'_team_common_name'},
                'team_logo' => $game->{$side.'_team_logo'}, 'score' => $final ? $game->{$side.'_team_score'} : null,
                'sog' => $final ? ($game->{$side.'_team_sog'} ?? ($skaters->isEmpty() ? null : (int) $skaters->sum('sog'))) : null,
                'lineup' => $preview[$side]['lineup'] ?? null,
                'starting_goalie' => $final ? null : app(NhlStartingGoalieSelector::class)->select((int) $game->nhl_game_id, $team, (string) $game->season_id),
                'form' => $this->form($game, $teamId),
                'pp_goals' => $final ? $game->{$side.'_pp_goals'} : null,
                'pp_opportunities' => $final ? $game->{$side.'_pp_opportunities'} : null,
                'sat' => $summary->where('nhl_team_id', $teamId)->isEmpty() ? null : (int) $summary->where('nhl_team_id', $teamId)->sum('sat'),
                'hits' => $skaters->isEmpty() ? null : (int) $skaters->sum('hits'),
                'blocks' => $skaters->isEmpty() ? null : (int) $skaters->sum('blocks'),
                'pim' => $rows->isEmpty() ? null : (int) $rows->sum('penalty_minutes'),
                'boxscore' => $rows->map(fn ($row): array => [
                    'id' => $row->nhl_player_id, 'name' => $row->player_name, 'position' => $row->position,
                    'goals' => $row->goals, 'assists' => $row->assists, 'points' => $row->points,
                    'sog' => $row->sog, 'toi' => $row->toi, 'saves' => $row->saves, 'goals_against' => $row->goals_against,
                ])->all(),
            ];
        }
        $timeline = $final ? $this->storedTimeline((int) $game->nhl_game_id, $teams) : [];
        $events = $timeline['events'] ?? [];

        return ['game' => [
            'nhl_game_id' => (int) $game->nhl_game_id, 'game_date' => $game->game_date->toDateString(),
            'start_time_utc' => $game->start_time_utc?->toIso8601String(), 'venue' => $game->venue,
            'game_state' => $game->game_state, 'mode' => $final ? 'recap' : 'preview',
            'status' => match (strtoupper((string) $game->game_state)) {
                'OFF', 'FINAL' => $game->shootout_in_use ? 'Final / SO' : ($game->ot_in_use ? 'Final / OT' : 'Final'),
                'PPD' => 'Postponed', 'CNCL' => 'Cancelled', default => 'Preview',
            },
            ...$teams, ...$timeline, 'prediction' => $prediction, 'events' => $events,
            'comparison' => $final ? $this->comparison($teams) : [],
            'head_to_head' => $this->meetings($game),
            'recap' => $final ? $this->recap($teams) : null,
        ]];
    }

    /** Read only public prediction outputs from the latest successful canonical revision.
     * @return array<string,mixed>|null
     */
    private function prediction(NhlGame $game): ?array
    {
        $row = DB::table('nhl_game_prediction_revisions')->where('nhl_game_id', $game->nhl_game_id)
            ->where('is_canonical', true)->where('prediction_available', true)->orderByDesc('id')
            ->first(['payload->market_probabilities as markets', 'payload->prediction->predicted_score as score',
                'payload->prediction->predicted_total_goals as total_goals', 'predicted_at']);
        if ($row === null) {
            return null;
        }
        $markets = is_string($row->markets) ? json_decode($row->markets, true, 512, JSON_THROW_ON_ERROR) : $row->markets;
        $score = is_string($row->score) ? json_decode($row->score, true, 512, JSON_THROW_ON_ERROR) : $row->score;
        $probabilities = [];
        foreach ($markets ?? [] as $market) {
            if (($market['market_key'] ?? null) !== 'moneyline' || ($market['period_key'] ?? null) !== 'full_game'
                || ! in_array($market['selection_key'] ?? null, ['away', 'home'], true)
                || ($market['team_abbrev'] ?? null) !== $game->{$market['selection_key'].'_team_abbrev'}) {
                continue;
            }
            $value = $market['probability'] ?? null;
            if (is_numeric($value) && is_finite((float) $value) && $value >= 0 && $value <= 1) {
                $probabilities[$market['selection_key']] = (float) $value;
            }
        }

        return ['probabilities' => $probabilities, 'score' => $score,
            'total_goals' => is_numeric($row->total_goals) ? (float) $row->total_goals : null,
            'predicted_at' => $row->predicted_at];
    }

    /** Summarize this season's completed games strictly before the selected matchup.
     * @return array<string,mixed>
     */
    private function form(NhlGame $game, int $teamId): array
    {
        $rows = NhlGame::query()->where('season_id', $game->season_id)->where('game_type', $game->game_type)
            ->where('game_date', '<', $game->game_date->toDateString())->whereIn('game_state', ['OFF', 'FINAL'])
            ->whereNotNull('home_team_score')->whereNotNull('away_team_score')
            ->where(fn ($q) => $q->where('home_team_id', $teamId)->orWhere('away_team_id', $teamId))
            ->orderByDesc('game_date')->orderByDesc('nhl_game_id')->get();
        $records = $rows->map(function ($row) use ($teamId): array {
            $home = (int) $row->home_team_id === $teamId;
            $gf = (int) ($home ? $row->home_team_score : $row->away_team_score);
            $ga = (int) ($home ? $row->away_team_score : $row->home_team_score);

            return ['gf' => $gf, 'ga' => $ga, 'win' => $gf > $ga, 'otl' => $gf < $ga && ($row->ot_in_use || $row->shootout_in_use)];
        });
        $wins = $records->where('win', true)->count();
        $otl = $records->where('otl', true)->count();
        $last = $records->take(10);

        return ['gp' => $records->count(), 'wins' => $wins, 'losses' => $records->count() - $wins - $otl, 'otl' => $otl,
            'gf_gp' => $records->isEmpty() ? null : $records->sum('gf') / $records->count(),
            'ga_gp' => $records->isEmpty() ? null : $records->sum('ga') / $records->count(),
            'l10_gp' => $last->count(), 'l10_wins' => $last->where('win', true)->count(),
            'l10_losses' => $last->where('win', false)->where('otl', false)->count(), 'l10_otl' => $last->where('otl', true)->count()];
    }

    /** Read the five previous completed meetings without leaking the selected result.
     * @return array<int,array<string,mixed>>
     */
    private function meetings(NhlGame $game): array
    {
        return NhlGame::query()->where('game_date', '<', $game->game_date->toDateString())->whereIn('game_state', ['FINAL', 'OFF'])
            ->whereNotNull('away_team_score')->whereNotNull('home_team_score')
            ->whereIn('home_team_id', [$game->home_team_id, $game->away_team_id])
            ->whereIn('away_team_id', [$game->home_team_id, $game->away_team_id])
            ->orderByDesc('game_date')->orderByDesc('nhl_game_id')->limit(5)->get()
            ->map(fn ($row): array => ['id' => (int) $row->nhl_game_id, 'date' => $row->game_date->toDateString(),
                'away' => $row->away_team_abbrev, 'home' => $row->home_team_abbrev,
                'away_score' => $row->away_team_score, 'home_score' => $row->home_team_score])->all();
    }

    /** Read imported plays once and resolve all participant names in one batch.
     * @param array<string,array<string,mixed>> $teams
     * @return array<string,mixed>
     */
    private function storedTimeline(int $gameId, array $teams): array
    {
        $rows = DB::table('play_by_plays')->whereNull('withdrawn_at')->where('nhl_game_id', $gameId)
            ->orderBy('period')->orderBy('sort_order')->orderBy('id')->get();
        $fields = ['scoringPlayerId' => 'scoring_player_id', 'shootingPlayerId' => 'shooting_player_id',
            'assist1PlayerId' => 'assist1_player_id', 'assist2PlayerId' => 'assist2_player_id',
            'hittingPlayerId' => 'hitting_player_id', 'hitteePlayerId' => 'hittee_player_id',
            'committedByPlayerId' => 'committed_by_player_id', 'drawnByPlayerId' => 'drawn_by_player_id',
            'winningPlayerId' => 'fo_winning_player_id', 'losingPlayerId' => 'fo_losing_player_id',
            'blockingPlayerId' => 'blocking_player_id'];
        $ids = $rows->flatMap(fn ($row): array => array_values(array_map(fn ($field) => $row->{$field}, $fields)))->filter()->unique();
        $names = DB::table('players')->whereIn('nhl_id', $ids)->pluck('full_name', 'nhl_id');
        $plays = $rows->map(function ($row) use ($fields): array {
            $details = ['eventOwnerTeamId' => $row->event_owner_team_id,
                'awayScore' => $row->away_score, 'homeScore' => $row->home_score,
                'xCoord' => $row->x_coord, 'yCoord' => $row->y_coord, 'shotType' => $row->shot_type,
                'descKey' => $row->desc_key, 'duration' => $row->duration];
            foreach ($fields as $key => $field) {
                $details[$key] = $row->{$field};
            }

            return ['eventId' => $row->nhl_event_id ?? $row->id, 'typeDescKey' => $row->type_desc_key,
                'periodDescriptor' => ['number' => $row->period, 'periodType' => $row->period_type],
                'homeTeamDefendingSide' => $row->home_team_defending_side,
                'timeInPeriod' => $row->time_in_period, 'details' => $details];
        })->all();

        return $this->timeline($plays, $teams, $names, $rows->isNotEmpty());
    }

    /** Build bounded public event, rink and period projections from already loaded source facts.
     * @param array<int,array<string,mixed>> $source
     * @param array<string,array<string,mixed>> $teams
     * @param \Illuminate\Support\Collection<int,string> $names
     * @return array<string,mixed>
     */
    private function timeline(
        array $source,
        array $teams,
        \Illuminate\Support\Collection $names,
        bool $available,
        ?int $currentPeriod = null,
        ?string $currentPeriodType = null
    ): array
    {
        $plays = [];
        $shots = [];
        $mapEvents = [];
        $events = [];
        $periodTypes = [];
        foreach ($source as $index => $row) {
            $type = (string) ($row['typeDescKey'] ?? 'unknown');
            $period = (int) data_get($row, 'periodDescriptor.number', 0);
            if ($period < 1 || ! isset($row['eventId'])) {
                continue;
            }
            $periodTypes[$period] = data_get($row, 'periodDescriptor.periodType');
            $teamId = data_get($row, 'details.eventOwnerTeamId');
            $side = null;
            foreach (['away', 'home'] as $candidate) {
                if ($teamId !== null && isset($teams[$candidate]['team_id']) && (int) $teamId === (int) $teams[$candidate]['team_id']) {
                    $side = $candidate;
                }
            }
            $playerId = data_get($row, 'details.scoringPlayerId') ?? data_get($row, 'details.shootingPlayerId')
                ?? data_get($row, 'details.committedByPlayerId') ?? data_get($row, 'details.hittingPlayerId')
                ?? data_get($row, 'details.winningPlayerId') ?? data_get($row, 'details.playerId');
            $play = ['id' => $row['eventId'], 'order' => $index, 'type' => $type,
                'label' => ucfirst(str_replace('-', ' ', $type)), 'period' => $period,
                'period_type' => $periodTypes[$period], 'time' => $row['timeInPeriod'] ?? null,
                'side' => $side, 'team_id' => $teamId,
                'player' => $playerId === null ? null : $names->get($playerId, 'Player #'.$playerId),
                'detail' => str_replace('-', ' ', (string) (data_get($row, 'details.descKey') ?? data_get($row, 'details.shotType') ?? '')),
                'duration' => data_get($row, 'details.duration'),
                'away_score' => data_get($row, 'details.awayScore'), 'home_score' => data_get($row, 'details.homeScore')];
            $plays[] = $play;
            if ($type === 'goal' && $play['period_type'] !== 'SO') {
                $events[] = [...$play, 'scorer' => $play['player'] ?? 'Scorer unavailable',
                    'assists' => collect([data_get($row, 'details.assist1PlayerId'), data_get($row, 'details.assist2PlayerId')])
                        ->filter()->map(fn ($id) => $names->get($id, 'Player #'.$id))->values()->all()];
            }
            $x = data_get($row, 'details.xCoord');
            $y = data_get($row, 'details.yCoord');
            if (in_array($type, ['goal', 'shot-on-goal', 'missed-shot', 'blocked-shot', 'hit'], true)
                && $play['period_type'] !== 'SO' && $side !== null && is_numeric($x) && is_numeric($y)
                && abs((float) $x) <= 100 && abs((float) $y) <= 42.5) {
                $geometry = app(ShotGeometryService::class)->computeFromContext(
                    (int) $x, (int) $y, (int) $teamId, $row['homeTeamDefendingSide'] ?? null, $period,
                    (int) $teams['home']['team_id'], (int) $teams['away']['team_id'],
                );
                $homeDefending = strtolower((string) ($row['homeTeamDefendingSide'] ?? ''));
                $homeDefending = str_starts_with($homeDefending, 'l') ? 'left'
                    : (str_starts_with($homeDefending, 'r') ? 'right' : ($period % 2 === 0 ? 'right' : 'left'));
                $attackingSide = $side === 'away' ? $homeDefending : ($homeDefending === 'left' ? 'right' : 'left');
                $rawAngle = $geometry['angle'] ?? null;
                $angle = $rawAngle === null ? null : min(abs($rawAngle), abs(180.0 - abs($rawAngle)));
                $marker = [...$play, 'x' => (float) $x, 'y' => (float) $y,
                    'distance' => $type === 'hit' ? null : ($geometry['distance'] ?? null),
                    'angle' => $type === 'hit' ? null : $angle, 'attacking_net_side' => $attackingSide,
                    'blocking_player' => ($blocker = data_get($row, 'details.blockingPlayerId')) === null ? null : $names->get($blocker, 'Player #'.$blocker)];
                $mapEvents[] = $marker;
                if ($type !== 'hit') {
                    $shots[] = $marker;
                }
            }
        }
        if ($currentPeriod !== null && $currentPeriodType !== null) {
            $periodTypes[$currentPeriod] = $currentPeriodType;
        }
        $lastPeriod = $currentPeriod ?? (empty($periodTypes) ? 0 : max(array_keys($periodTypes)));
        $periods = [];
        for ($period = 1; $period <= max(3, $lastPeriod); $period++) {
            $line = ['period' => $period, 'label' => ($periodTypes[$period] ?? null) === 'SO' ? 'SO'
                : ($period <= 3 ? ['1st', '2nd', '3rd'][$period - 1] : ($period === 4 ? 'OT' : ($period - 3).'OT'))];
            foreach (['away', 'home'] as $side) {
                $goals = collect($events)->where('side', $side);
                $score = $teams[$side]['score'] ?? null;
                $shootout = in_array('SO', $periodTypes, true);
                $difference = $score === null ? null : (int) $score - $goals->count();
                $consistent = $difference === 0 || ($shootout && $difference === 1);
                $line[$side] = ! $available || ! $consistent || $period > $lastPeriod ? null
                    : ($line['label'] === 'SO' ? $difference : $goals->where('period', $period)->count());
            }
            $periods[] = $line;
        }

        return ['plays' => $plays, 'shots' => $shots, 'map_events' => $mapEvents, 'events' => $events,
            'plays_available' => $available, 'events_available' => $available, 'period_scores' => $periods];
    }

    /** @param array<string,array<string,mixed>> $teams @return array<int,array<string,mixed>> */
    private function comparison(array $teams): array
    {
        return collect(['sog' => 'Shots on goal', 'sat' => 'Shot attempts', 'hits' => 'Hits', 'blocks' => 'Blocks', 'pim' => 'PIM'])
            ->map(fn ($label, $key): array => ['label' => $label, 'away' => $teams['away'][$key] ?? null, 'home' => $teams['home'][$key] ?? null])->values()->all();
    }

    /** @param array<string,array<string,mixed>> $teams */
    private function recap(array $teams): ?string
    {
        if ($teams['away']['score'] === null || $teams['home']['score'] === null || $teams['away']['score'] === $teams['home']['score']) {
            return null;
        }
        $winner = $teams['away']['score'] > $teams['home']['score'] ? 'away' : 'home';
        $loser = $winner === 'away' ? 'home' : 'away';

        return $teams[$winner]['team_abbrev'].' defeated '.$teams[$loser]['team_abbrev'].' '.$teams[$winner]['score'].'–'.$teams[$loser]['score'].'.';
    }

    /** Present live facts exclusively from cached provider JSON.
     * @param array<string,mixed> $provider @return array<string,mixed>
     */
    public function livePayload(int $gameId, array $provider): array
    {
        $game = $provider['game'];
        $box = $provider['boxscore'];
        $state = strtoupper((string) $game['game_state']);
        $final = in_array($state, ['OFF', 'FINAL'], true);
        $pregame = in_array($state, ['', 'FUT', 'PRE', 'PPD', 'CNCL'], true);
        $teams = [];
        foreach (['away', 'home'] as $side) {
            $rows = collect();
            foreach (['forwards', 'defense', 'goalies'] as $group) {
                foreach (data_get($box, 'playerByGameStats.'.$side.'Team.'.$group, []) as $row) {
                    $rows->push(['id' => $row['playerId'] ?? null, 'name' => data_get($row, 'name.default'),
                        'position' => $group === 'goalies' ? 'G' : ($row['position'] ?? null),
                        'goals' => $row['goals'] ?? null, 'assists' => $row['assists'] ?? null, 'points' => $row['points'] ?? null,
                        'sog' => $row['sog'] ?? null, 'toi' => $row['toi'] ?? null,
                        'saves' => $row['saves'] ?? (isset($row['shotsAgainst'], $row['goalsAgainst'])
                            ? max(0, (int) $row['shotsAgainst'] - (int) $row['goalsAgainst']) : null),
                        'shots_against' => $row['shotsAgainst'] ?? null,
                        'hits' => $row['hits'] ?? null, 'blocks' => $row['blockedShots'] ?? null, 'pim' => $row['pim'] ?? null,
                        'goals_against' => $row['goalsAgainst'] ?? null]);
                }
            }
            $teams[$side] = [...$game[$side], 'form' => null, 'boxscore' => $rows->all()];
            foreach (['hits', 'blocks', 'pim'] as $metric) {
                $known = $rows->filter(fn ($row): bool => $row[$metric] !== null);
                $teams[$side][$metric] = $known->isEmpty() ? null : $known->sum($metric);
            }
        }
        $names = collect($provider['roster'] ?? [])->mapWithKeys(fn ($row): array => [
            $row['playerId'] => trim(data_get($row, 'firstName.default', '').' '.data_get($row, 'lastName.default', '')),
        ]);
        $timeline = $this->timeline($provider['plays'] ?? [], $teams, $names,
            (bool) ($provider['events_available'] ?? false), data_get($box, 'periodDescriptor.number'), data_get($box, 'periodDescriptor.periodType'));
        foreach (['away', 'home'] as $side) {
            // Count every attempt, including unlocated shots; shootouts are not SAT.
            $teams[$side]['sat'] = ! $timeline['plays_available'] ? null : collect($timeline['plays'])
                ->where('side', $side)->where('period_type', '!=', 'SO')
                ->whereIn('type', ['goal', 'shot-on-goal', 'missed-shot', 'blocked-shot'])->count();
        }

        return ['game' => [...$game, ...$teams, ...$timeline, 'nhl_game_id' => $gameId, 'mode' => $final ? 'recap' : ($pregame ? 'preview' : 'live'),
            'status' => $final ? 'Final' : ($pregame ? ($state === 'PRE' ? 'Starting soon' : 'Preview') : 'Live'), 'venue' => data_get($box, 'venue.default'),
            'time_remaining' => data_get($box, 'clock.timeRemaining'), 'in_intermission' => data_get($box, 'clock.inIntermission'),
            'period_number' => data_get($box, 'periodDescriptor.number'), 'period_type' => data_get($box, 'periodDescriptor.periodType'),
            'prediction' => null, 'head_to_head' => [], 'comparison' => $this->comparison($teams),
            'recap' => $final ? $this->recap($teams) : null]];
    }
}
