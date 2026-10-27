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
            'meetings' => ['head_to_head'], 'summary' => ['recap', 'comparison'],
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

        return 'nhl:public-game:v1:'.$game->nhl_game_id.':'.sha1($game->game_state.':'.(string) $game->updated_at.':'.$version);
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
        $events = $final ? $this->storedGoals((int) $game->nhl_game_id) : [];

        return ['game' => [
            'nhl_game_id' => (int) $game->nhl_game_id, 'game_date' => $game->game_date->toDateString(),
            'start_time_utc' => $game->start_time_utc?->toIso8601String(), 'venue' => $game->venue,
            'game_state' => $game->game_state, 'mode' => $final ? 'recap' : 'preview',
            'status' => match (strtoupper((string) $game->game_state)) {
                'OFF', 'FINAL' => $game->shootout_in_use ? 'Final / SO' : ($game->ot_in_use ? 'Final / OT' : 'Final'),
                'PPD' => 'Postponed', 'CNCL' => 'Cancelled', default => 'Preview',
            },
            ...$teams, 'prediction' => $prediction, 'events' => $events,
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

    /** Normalize imported goals and resolve all participant names in one query.
     * @return array<int,array<string,mixed>>
     */
    private function storedGoals(int $gameId): array
    {
        $goals = DB::table('play_by_plays')->where('nhl_game_id', $gameId)->where('type_desc_key', 'goal')
            ->orderBy('period')->orderBy('sort_order')->orderBy('id')->get();
        $ids = $goals->flatMap(fn ($row): array => [$row->scoring_player_id, $row->assist1_player_id, $row->assist2_player_id])->filter()->unique();
        $names = DB::table('players')->whereIn('nhl_id', $ids)->pluck('full_name', 'nhl_id');

        return $goals->map(fn ($row): array => [
            'id' => $row->id, 'period' => $row->period, 'time' => $row->time_in_period,
            'team_id' => $row->event_owner_team_id, 'scorer' => $names->get($row->scoring_player_id, 'Player #'.$row->scoring_player_id),
            'assists' => collect([$row->assist1_player_id, $row->assist2_player_id])->filter()->map(fn ($id) => $names->get($id, 'Player #'.$id))->all(),
            'away_score' => $row->away_score, 'home_score' => $row->home_score,
        ])->all();
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
                        'sog' => $row['sog'] ?? null, 'toi' => $row['toi'] ?? null, 'saves' => $row['saves'] ?? null,
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
        $events = collect($provider['plays'] ?? [])->where('typeDescKey', 'goal')->map(fn ($row): array => [
            'id' => $row['eventId'], 'period' => data_get($row, 'periodDescriptor.number'), 'time' => $row['timeInPeriod'] ?? null,
            'team_id' => data_get($row, 'details.eventOwnerTeamId'),
            'scorer' => $names->get(data_get($row, 'details.scoringPlayerId'), 'Scorer unavailable'),
            'assists' => collect([data_get($row, 'details.assist1PlayerId'), data_get($row, 'details.assist2PlayerId')])
                ->filter()->map(fn ($id) => $names->get($id, 'Player #'.$id))->all(),
            'away_score' => data_get($row, 'details.awayScore'), 'home_score' => data_get($row, 'details.homeScore'),
        ])->values()->all();

        return ['game' => [...$game, ...$teams, 'nhl_game_id' => $gameId, 'mode' => $final ? 'recap' : ($pregame ? 'preview' : 'live'),
            'status' => $final ? 'Final' : ($pregame ? ($state === 'PRE' ? 'Starting soon' : 'Preview') : 'Live'), 'venue' => data_get($box, 'venue.default'),
            'time_remaining' => data_get($box, 'clock.timeRemaining'), 'in_intermission' => data_get($box, 'clock.inIntermission'),
            'period_number' => data_get($box, 'periodDescriptor.number'), 'period_type' => data_get($box, 'periodDescriptor.periodType'),
            'prediction' => null, 'head_to_head' => [], 'events_available' => $provider['events_available'] ?? false, 'comparison' => $this->comparison($teams), 'events' => $events,
            'recap' => $final ? $this->recap($teams) : null]];
    }
}
