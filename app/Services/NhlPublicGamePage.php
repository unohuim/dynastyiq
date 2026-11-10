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
            'status' => $final ? $this->finalStatus($game)
                : ($live ? 'Live' : match ($state) { 'PRE' => 'Starting soon', 'PPD' => 'Postponed', 'CNCL' => 'Cancelled', default => 'Preview' }),
            ...$teams, 'prediction' => ! $final && ! $live ? $this->prediction($game) : null,
        ]];
    }

    /** Read one deferred section without rebuilding unrelated preview sections.
     * @return array<string,mixed>|null Null means another builder already owns preparation.
     */
    public function panel(int $gameId, string $section, ?int $predictionRevisionId = null): ?array
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
            $matchup = $this->matchup($game);
            $current = ['mode' => 'preview', 'matchup_expected_pending' => $matchup['expected_pending'],
                'prediction_drivers' => $this->predictionDrivers($game, $predictionRevisionId)];
            foreach (['away', 'home'] as $side) {
                $current[$side] = ['form' => $matchup[$side]['form'],
                    'starting_goalie' => app(NhlStartingGoalieSelector::class)->select($gameId, (string) $game->{$side.'_team_abbrev'}, (string) $game->season_id)];
            }
        } elseif ($current === null && $section === 'factors') {
            $current = ['mode' => 'preview', 'preview_analysis' => $this->previewAnalysis($game, $predictionRevisionId)];
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
            'matchup' => ['matchup_expected_pending', 'prediction_drivers'], 'factors' => ['preview_analysis'],
            'meetings' => ['head_to_head'], 'summary' => ['recap', 'comparison', 'period_scores'],
            'play_by_play' => ['plays', 'shots', 'map_events', 'plays_available'],
            'goals' => ['events', 'events_available'], default => [],
        })];

        return ['nhl_game_id' => $gameId, 'mode' => $current['mode'], 'section' => $section, 'data' => $data];
    }

    /** Share strictly prior-date Matchup statistics with the partner API in every game state.
     * @return array<string,mixed>
     */
    public function matchup(NhlGame $game): array
    {
        $expected = Cache::get($this->cacheKey($game).':matchup-expectations:v1');
        $pending = ! is_array($expected) && in_array(strtoupper((string) $game->game_state), ['', 'FUT', 'PRE', 'PPD', 'CNCL'], true);
        if ($pending && Cache::add($this->cacheKey($game).':matchup-expectations:queued', true, 90)) {
            try {
                \App\Jobs\RefreshNhlPublicGamePageJob::dispatch((int) $game->nhl_game_id)->afterCommit();
            } catch (\Throwable $error) {
                Cache::forget($this->cacheKey($game).':matchup-expectations:queued');
                throw $error;
            }
        }
        $result = ['through_date' => $game->game_date->copy()->subDay()->toDateString(),
            'head_to_head_limit' => 10, 'expected_pending' => $pending];
        foreach (['away', 'home'] as $side) {
            $result[$side] = ['team_id' => (int) $game->{$side.'_team_id'}, 'team_abbrev' => (string) $game->{$side.'_team_abbrev'},
                'form' => [...$this->form($game, (int) $game->{$side.'_team_id'}),
                    'xgf_gp' => $expected[$side]['xgf_gp'] ?? null, 'xga_gp' => $expected[$side]['xga_gp'] ?? null]];
        }

        return $result;
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
            $game = NhlGame::query()->findOrFail($gameId);
            // Publish normal player/recap detail before optional expected-stat work.
            $result = $this->payload($gameId, $refresh);
            if (($result['game']['mode'] ?? null) === 'preview'
                && in_array(strtoupper((string) $game->game_state), ['', 'FUT', 'PRE', 'PPD', 'CNCL'], true)) {
                $this->prepareMatchupExpectations($game);
            }

            return $result;
        } finally {
            if (isset($game)) {
                Cache::forget($this->cacheKey($game).':matchup-expectations:queued');
            }
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

        return 'nhl:public-game:v3:'.$game->nhl_game_id.':'.sha1($game->game_state.':'.(string) $game->updated_at.':'.$version);
    }

    /** Read the actual ending, not schedule flags describing which rules are enabled. */
    private function finalPeriodType(NhlGame $game): string
    {
        foreach ([data_get($game->game_outcome, 'lastPeriodType'), data_get($game->game_outcome, 'periodType'), $game->period_type] as $value) {
            $period = strtoupper(trim((string) $value));
            if (in_array($period, ['REG', 'OT', 'SO'], true)) {
                return $period;
            }
        }

        return 'REG';
    }

    /** Keep first-paint and prepared final labels consistent with the recorded ending. */
    private function finalStatus(NhlGame $game): string
    {
        return match ($this->finalPeriodType($game)) {
            'OT' => 'Final / OT',
            'SO' => 'Final / SO',
            default => 'Final',
        };
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
                'OFF', 'FINAL' => $this->finalStatus($game),
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
            ->first(['id', 'payload->market_probabilities as markets', 'payload->prediction->predicted_score as score',
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

        return ['revision_id' => (int) $row->id, 'probabilities' => $probabilities, 'score' => $score,
            'total_goals' => is_numeric($row->total_goals) ? (float) $row->total_goals : null,
            'predicted_at' => $row->predicted_at];
    }

    /** Curate actual saved scoring inputs/effects for Matchup without recalculating forecasts.
     * @return array<string,mixed>|null
     */
    private function predictionDrivers(NhlGame $game, ?int $revisionId): ?array
    {
        $fields = ['id', 'predicted_at', 'payload->inputs->algorithm_definition as definition',
            'payload->inputs->algorithm_version as algorithm_version',
            'payload->prediction->confidence_inputs->schedule_enabled as schedule_enabled',
            'payload->prediction->home_win_probability as home_probability',
            'evidence->custom_prediction->input->league_pk_probability as league_pk'];
        foreach (['away', 'home'] as $side) {
            $fields[] = 'payload->teams->'.$side.'->team_abbrev as '.$side.'_abbrev';
            $fields[] = 'payload->teams->'.$side.'->summary as '.$side.'_summary';
            foreach (['games', 'wins', 'win_pct'] as $metric) {
                $fields[] = 'evidence->custom_prediction->input->teams->'.$side.'->team_metrics->last_5->'.$metric.' as '.$side.'_l5_'.$metric;
            }
            foreach (['pp_offense_l20', 'pp_allowed_l5', 'pk_probability'] as $metric) {
                $fields[] = 'evidence->custom_prediction->input->teams->'.$side.'->'.$metric.' as '.$side.'_'.$metric;
            }
            foreach (['back_to_back', 'games_including_target_last_7_days'] as $metric) {
                $fields[] = 'evidence->custom_prediction->input->teams->'.$side.'->schedule->'.$metric.' as '.$side.'_'.$metric;
            }
        }
        $row = DB::table('nhl_game_prediction_revisions')->where('nhl_game_id', $game->nhl_game_id)
            ->where('is_canonical', true)->where('prediction_available', true)
            ->when($revisionId !== null, fn ($query) => $query->where('id', $revisionId))
            ->orderByDesc('id')->first($fields);
        if ($row === null || ! str_starts_with((string) $row->algorithm_version, 'custom_ev_pp_')
            || $row->away_abbrev !== $game->away_team_abbrev || $row->home_abbrev !== $game->home_team_abbrev) {
            return null;
        }
        $definition = json_decode($row->definition ?? '{}', true, 512, JSON_THROW_ON_ERROR) ?? [];
        $weight = $this->publicNumber($definition['team_l5_win_pct_weight'] ?? null);
        $home = $this->publicNumber($row->home_l5_win_pct);
        $away = $this->publicNumber($row->away_l5_win_pct);
        $formKnown = $weight !== null && $weight >= 0 && $weight <= 1 && $home !== null && $away !== null
            && $home >= 0 && $home <= 100 && $away >= 0 && $away <= 100
            && ($this->publicNumber($row->home_l5_games) ?? 0) > 0 && ($this->publicNumber($row->away_l5_games) ?? 0) > 0
            && floor((float) $row->home_l5_games) === (float) $row->home_l5_games
            && floor((float) $row->away_l5_games) === (float) $row->away_l5_games;
        $result = ['revision_id' => (int) $row->id, 'predicted_at' => $row->predicted_at, 'teams' => []];
        foreach (['away' => 'home', 'home' => 'away'] as $side => $opponent) {
            $summary = json_decode($row->{$side.'_summary'} ?? '{}', true, 512, JSON_THROW_ON_ERROR) ?? [];
            $change = $formKnown ? $weight * ($side === 'home' ? $home - $away : $away - $home) : null;
            $ev = $this->publicNumber($summary['ev_xgf_per_game'] ?? null);
            $pk = $this->publicNumber($row->{$opponent.'_pk_probability'});
            $leaguePk = $this->publicNumber($row->league_pk);
            $qualifies = in_array($row->schedule_enabled, [true, 1, 'true', '1'], true)
                && in_array($row->{$side.'_back_to_back'}, [true, 1, 'true', '1'], true)
                && $this->publicNumber($row->{$side.'_games_including_target_last_7_days'}) === 4.0;
            $result['teams'][$side] = ['abbrev' => $row->{$side.'_abbrev'},
                'l5_wins' => $this->publicNumber($row->{$side.'_l5_wins'}),
                'l5_games' => $this->publicNumber($row->{$side.'_l5_games'}),
                'ev_form_change_pct' => $change,
                'ev_goals_before_form' => $change !== null && 1 + $change / 100 > 0 && $ev !== null ? $ev / (1 + $change / 100) : null,
                'ev_goals' => $ev, 'pp_goals' => $this->publicNumber($summary['pp_xgf_per_game'] ?? null),
                'total_goals' => $this->publicNumber($summary['adjusted_xgf_per_game'] ?? null),
                'sog' => $this->publicNumber($summary['adjusted_xsog_per_game'] ?? null),
                'pp_offense_l20' => $this->publicNumber($row->{$side.'_pp_offense_l20'}),
                'pp_allowed_l5' => $this->publicNumber($row->{$side.'_pp_allowed_l5'}),
                'pp_source' => $pk !== null && $leaguePk !== null
                    ? ($pk > $leaguePk ? $row->{$opponent.'_abbrev'}.' goals allowed · Last 5' : 'Lineup goals · Last 20') : null,
                'schedule_applied' => $qualifies,
                'forward_sat_change_pct' => $qualifies && is_numeric($definition['schedule']['forward_sat_multiplier'] ?? null)
                    ? 100 * ($definition['schedule']['forward_sat_multiplier'] - 1) : null,
                'defense_sat_change_pct' => $qualifies && is_numeric($definition['schedule']['defense_sat_multiplier'] ?? null)
                    ? 100 * ($definition['schedule']['defense_sat_multiplier'] - 1) : null,
            ];
        }
        $homeProbability = $this->publicNumber($row->home_probability);
        $result['home_probability_schedule_change_points'] = $result['teams']['away']['schedule_applied']
            && $homeProbability !== null && $homeProbability > 0 && $homeProbability < 1
            ? $this->publicNumber($definition['schedule']['away_fourth_b2b_home_probability_delta'] ?? null) : null;
        if ($result['home_probability_schedule_change_points'] !== null) {
            $result['home_probability_schedule_change_points'] *= 100;
        }

        return $result;
    }

    /** Project a small public explanation from saved forecasts/context, never from raw results.
     * @return array<string,mixed>
     */
    private function previewAnalysis(NhlGame $game, ?int $predictionRevisionId = null): array
    {
        $empty = ['revision_id' => null, 'predicted_at' => null, 'context_at' => null, 'teams' => [], 'insights' => []];
        if (! in_array(strtoupper((string) $game->game_state), ['', 'FUT', 'PRE', 'PPD', 'CNCL'], true)) {
            return $empty;
        }
        $revision = DB::table('nhl_game_prediction_revisions')->where('nhl_game_id', $game->nhl_game_id)
            ->where('is_canonical', true)->where('prediction_available', true)
            ->when($predictionRevisionId !== null, fn ($query) => $query->where('id', $predictionRevisionId))->orderByDesc('id')
            ->first(['id', 'predicted_at', 'payload->inputs->sat_model_run_id as model_id', 'payload->inputs->training_season_ids as training_seasons']);
        if ($revision === null) {
            return $empty;
        }
        $cutoff = $game->start_time_utc?->min(now('UTC')) ?? now('UTC');
        $contexts = fn (string $entity) => DB::table('nhl_'.$entity.'_game_pregame_contexts as context')
            ->where('context.model_run_id', $revision->model_id)->where('context.nhl_game_id', $game->nhl_game_id)
            ->where('context.context_version', NhlPregameContextBuilder::VERSION)->where('context.source_cutoff_at', '<=', $cutoff)
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('nhl_pregame_context_run_games as work')
                ->whereColumn('work.run_id', 'context.run_id')->where('work.nhl_game_id', $game->nhl_game_id)
                ->where('work.state', 'complete')->whereNotNull('work.comparisons_completed_at'));
        $tokens = [];
        foreach (['team', 'player'] as $entity) {
            $tokens[$entity] = $revision->model_id === null ? [] : $contexts($entity)
                ->selectRaw('MAX(context.updated_at) AS updated_at, MAX(context.run_id) AS run_id, COUNT(*) AS rows')->first();
        }
        $key = 'nhl:public-preview-analysis:v5:'.$revision->id.':'.hash('sha256', json_encode($tokens, JSON_THROW_ON_ERROR));

        return Cache::remember($key, 300, function () use ($revision, $contexts, $game, $empty): array {
            $saved = $this->previewProjection((int) $revision->id);
            $teams = json_decode($saved->teams ?? '{}', true, 512, JSON_THROW_ON_ERROR) ?? [];
            $inputs = json_decode($saved->inputs ?? '{}', true, 512, JSON_THROW_ON_ERROR) ?? [];
            $training = json_decode($revision->training_seasons ?? '[]', true) ?? [];
            $baselineLabel = count($training) > 0 ? 'Last '.count($training).' Season'.(count($training) === 1 ? '' : 's') : 'Historical seasons';
            $ids = collect($teams)->flatMap(fn (array $team): array => array_column($team['roster'] ?? [], 'nhl_player_id'))->filter()->unique()->values();
            $teamContexts = $revision->model_id === null ? collect() : $contexts('team')
                ->get(['context.id', 'context.nhl_team_id', 'context.opponent_team_id', 'context.source_cutoff_at', 'context.metrics', 'context.opponent_metrics'])->keyBy('nhl_team_id');
            $playerContexts = $revision->model_id === null ? collect() : $contexts('player')->whereIn('context.nhl_player_id', $ids)
                ->get(['context.nhl_player_id', 'context.nhl_team_id', 'context.team_context_id', 'context.opponent_team_id',
                    'context.source_cutoff_at', 'context.metrics->all as all_metrics', 'context.metrics->strength->EV as ev_metrics',
                    'context.metrics->strength->PP as pp_metrics'])->keyBy('nhl_player_id');
            $result = [...$empty, 'revision_id' => (int) $revision->id, 'predicted_at' => $revision->predicted_at, 'baseline_label' => $baselineLabel];
            $candidates = [];
            foreach (['away', 'home'] as $side) {
                $abbrev = (string) $game->{$side.'_team_abbrev'};
                $teamId = (int) $game->{$side.'_team_id'};
                $opponent = (int) $game->{($side === 'away' ? 'home' : 'away').'_team_id'};
                $predicted = $teams[$side] ?? [];
                if (($predicted['team_abbrev'] ?? null) !== $abbrev) {
                    continue;
                }
                $roster = collect($predicted['roster'] ?? [])->filter(fn (array $p): bool => is_numeric($p['nhl_player_id'] ?? null)
                    && (int) $p['nhl_player_id'] > 0)->unique('nhl_player_id');
                $summary = $predicted['summary'] ?? [];
                $total = $this->publicNumber($summary['adjusted_xgf_per_game'] ?? null);
                $parent = $teamContexts->get($teamId);
                $eligible = $playerContexts->filter(fn ($context): bool => $parent !== null
                    && (int) $parent->opponent_team_id === $opponent && (int) $context->team_context_id === (int) $parent->id
                    && (int) $context->nhl_team_id === $teamId && (int) $context->opponent_team_id === $opponent
                    && $context->source_cutoff_at === $parent->source_cutoff_at);
                $players = $roster->map(function (array $p) use ($eligible, $inputs, $side, $baselineLabel): array {
                    $id = (int) $p['nhl_player_id'];
                    $context = $eligible->get($id);
                    $history = $inputs[$side]['players'][$id]['history'] ?? [];
                    $all = $context === null ? [] : json_decode($context->all_metrics ?? '{}', true, 512, JSON_THROW_ON_ERROR);
                    foreach (['EV' => 'ev_metrics', 'PP' => 'pp_metrics'] as $lane => $field) {
                        if ($context !== null) {
                            $windows = json_decode($context->{$field} ?? '{}', true, 512, JSON_THROW_ON_ERROR) ?? [];
                            if ($windows !== []) {
                                $history[$lane] = $windows;
                            }
                        }
                    }
                    $p['comparisons'] = $this->contributorComparisons($history, $baselineLabel);
                    $games = $this->publicNumber($all['last_10']['games'] ?? null);
                    $points = $this->publicNumber($all['last_10']['points'] ?? null);
                    $p['points_per_game'] = $games !== null && $games > 0 && $points !== null && $points >= 0 ? $points / $games : null;

                    return $p;
                });
                $leaders = $players->whereNotNull('points_per_game')->sortBy([['points_per_game', 'desc'], ['nhl_player_id', 'asc']])->take(3);
                $contributors = $leaders->concat($players->filter(fn (array $p): bool => ($this->publicNumber($p['projected_goals'] ?? null) ?? 0) > 0)
                    ->sortByDesc('projected_goals')->take(3))->unique('nhl_player_id')
                    ->map(function (array $p) use ($total): array {
                        $id = (int) $p['nhl_player_id'];
                        $goals = $this->publicNumber($p['projected_goals'] ?? null);

                        return ['id' => $id, 'name' => (string) ($p['player_name'] ?? 'Player'),
                            'goals' => $goals, 'points_per_game' => $p['points_per_game'],
                            'ev_goals' => $this->publicNumber(data_get($p, 'strengths.EV.goals')),
                            'pp_goals' => $this->publicNumber(data_get($p, 'strengths.PP.goals')),
                            'share_pct' => $total !== null && $total > 0 && $goals !== null ? 100 * $goals / $total : null,
                            'comparisons' => $p['comparisons']];
                    })->values()->all();
                $result['teams'][$side] = ['abbrev' => $abbrev, 'goals' => $total,
                    'ev_goals' => $this->publicNumber($summary['ev_xgf_per_game'] ?? null),
                    'pp_goals' => $this->publicNumber($summary['pp_xgf_per_game'] ?? null),
                    'sog' => $this->publicNumber($summary['adjusted_xsog_per_game'] ?? null), 'contributors' => $contributors];
                if ($parent === null || (int) $parent->opponent_team_id !== $opponent) {
                    continue;
                }
                $result['context_at'] = max($result['context_at'] ?? '', (string) $parent->source_cutoff_at);
                $windows = json_decode($parent->metrics ?? '{}', true, 512, JSON_THROW_ON_ERROR) ?? [];
                foreach (json_decode($parent->opponent_metrics ?? '{}', true, 512, JSON_THROW_ON_ERROR) ?? [] as $window => $values) {
                    $windows['opponent_'.$window] = $values;
                }
                $candidates = [...$candidates, ...$this->contextInsights($side, $abbrev, 'team:'.$teamId, $abbrev, $windows, false)];
                foreach ($roster as $p) {
                    $context = $playerContexts->get((int) $p['nhl_player_id']);
                    if ($context === null || (int) $context->team_context_id !== (int) $parent->id
                        || (int) $context->nhl_team_id !== $teamId || (int) $context->opponent_team_id !== $opponent
                        || $context->source_cutoff_at !== $parent->source_cutoff_at) {
                        continue;
                    }
                    $candidates = [...$candidates, ...$this->contextInsights($side, $abbrev, 'player:'.$p['nhl_player_id'],
                        (string) ($p['player_name'] ?? 'Player'), json_decode($context->all_metrics ?? '{}', true, 512, JSON_THROW_ON_ERROR) ?? [], true),
                        ...$this->contextInsights($side, $abbrev, 'player:'.$p['nhl_player_id'],
                            (string) ($p['player_name'] ?? 'Player'), json_decode($context->pp_metrics ?? '{}', true, 512, JSON_THROW_ON_ERROR) ?? [], true, true)];
                }
            }
            // Rank relative departures, deduplicate windows, then balance the two teams.
            $ranked = collect($candidates)->sortBy([['delta_pct', 'desc'], ['games', 'desc'], ['key', 'asc']])->unique('key');
            $selected = ['away' => [], 'home' => []];
            $entities = [];
            foreach ($ranked as $fact) {
                $limit = str_starts_with($fact['entity'], 'team:') ? 2 : 1;
                if (count($selected[$fact['side']]) >= 3 || ($entities[$fact['entity']] ?? 0) >= $limit) {
                    continue;
                }
                $fact['period'] = str_replace('opponent', (string) $game->{($fact['side'] === 'away' ? 'home' : 'away').'_team_abbrev'}, $fact['period']);
                $fact['period'] = str_replace('Historical seasons', $baselineLabel, $fact['period']);
                $fact['baseline_label'] = $baselineLabel;
                $selected[$fact['side']][] = Arr::except($fact, ['key', 'entity']);
                $entities[$fact['entity']] = ($entities[$fact['entity']] ?? 0) + 1;
            }
            for ($i = 0; $i < 3; $i++) {
                foreach (['away', 'home'] as $side) {
                    if (isset($selected[$side][$i])) {
                        $result['insights'][] = $selected[$side][$i];
                    }
                }
            }

            return $result;
        });
    }

    /** Missing and invalid values remain unknown rather than becoming synthetic zero facts. */
    private function publicNumber(mixed $value): ?float
    {
        return is_numeric($value) && is_finite((float) $value) ? (float) $value : null;
    }

    /** Project roster totals and compact historical totals without loading private bucket trees. */
    private function previewProjection(int $revisionId): object
    {
        if (DB::getDriverName() !== 'pgsql') {
            return DB::table('nhl_game_prediction_revisions')->where('id', $revisionId)->first([
                'payload->teams as teams', 'evidence->custom_prediction->input->teams as inputs',
            ]);
        }
        $rows = DB::select(<<<'SQL'
            WITH saved AS MATERIALIZED (
                SELECT payload::jsonb AS p, evidence::jsonb AS e
                FROM nhl_game_prediction_revisions WHERE id = ?
            ) SELECT side,
                jsonb_build_object(
                    'team_abbrev', p #> ARRAY['teams',side,'team_abbrev'],
                    'summary', p #> ARRAY['teams',side,'summary'],
                    'roster', COALESCE((SELECT jsonb_agg(jsonb_build_object(
                        'nhl_player_id', r->'nhl_player_id', 'player_name', r->'player_name',
                        'projected_goals', r->'projected_goals',
                        'strengths', jsonb_build_object('EV', jsonb_build_object('goals', r #> '{strengths,EV,goals}'),
                            'PP', jsonb_build_object('goals', r #> '{strengths,PP,goals}'))
                    )) FROM jsonb_array_elements(CASE WHEN jsonb_typeof(p #> ARRAY['teams',side,'roster']) = 'array'
                        THEN p #> ARRAY['teams',side,'roster'] ELSE '[]'::jsonb END) r), '[]'::jsonb)
                ) AS team,
                jsonb_build_object(
                    'players', COALESCE((SELECT jsonb_object_agg(player.key, jsonb_build_object('history', player.value->'history'))
                    FROM jsonb_each(CASE WHEN jsonb_typeof(e #> ARRAY['custom_prediction','input','teams',side,'players']) = 'object'
                        THEN e #> ARRAY['custom_prediction','input','teams',side,'players'] ELSE '{}'::jsonb END) player), '{}'::jsonb)
                ) AS inputs
            FROM saved CROSS JOIN (VALUES ('away'), ('home')) AS sides(side)
            SQL, [$revisionId]);
        $teams = $inputs = [];
        foreach ($rows as $row) {
            $teams[$row->side] = json_decode($row->team, true, 512, JSON_THROW_ON_ERROR);
            $inputs[$row->side] = json_decode($row->inputs, true, 512, JSON_THROW_ON_ERROR);
        }

        return (object) ['teams' => json_encode($teams, JSON_THROW_ON_ERROR),
            'inputs' => json_encode($inputs, JSON_THROW_ON_ERROR)];
    }

    /** Show actual saved historical rates rather than model formulas or fallback terminology. */
    private function contributorComparisons(array $history, string $baselineLabel): array
    {
        $comparisons = [];
        foreach ([['EV SAT/60', 'EV', 'sat', 'seconds', 3600], ['EV SAT/GP', 'EV', 'sat', 'games', 1],
            ['EV SH%', 'EV', 'goals', 'sog', 100],
            ['PP G/GP', 'PP', 'goals', 'games', 1]] as [$metric, $lane, $numerator, $denominator, $scale]) {
            $rates = [];
            foreach (['baseline' => 'train', 'recent' => 'last_10'] as $key => $period) {
                $sample = $history[$lane][$period] ?? [];
                $n = $this->publicNumber($sample[$numerator] ?? null);
                $d = $this->publicNumber($sample[$denominator] ?? ($denominator === 'seconds' ? ($sample['toi_seconds'] ?? null) : null));
                if ($denominator === 'seconds' && $d === null && ($sample['sat_per_60'] ?? null) !== null) {
                    $rates[$key] = $this->publicNumber($sample['sat_per_60']);
                    continue;
                }
                $rates[$key] = $n !== null && $n >= 0 && $d !== null && $d > 0 ? $scale * $n / $d : null;
            }
            if ($rates['baseline'] === null && $rates['recent'] === null) {
                continue;
            }
            $comparisons[] = ['recent' => $rates['recent'], 'metric' => $metric, 'baseline_label' => $baselineLabel,
                'delta_pct' => $rates['baseline'] !== null && $rates['baseline'] > 0 && $rates['recent'] !== null
                    ? 100 * ($rates['recent'] - $rates['baseline']) / $rates['baseline'] : null,
                'recent_label' => 'Last 10 Games', 'is_percentage' => $scale === 100];
        }

        return $comparisons;
    }

    /** Compare saved windows with Train, requiring five appearances and a 25% relative departure. */
    private function contextInsights(string $side, string $team, string $entity, string $name, array $windows, bool $player, bool $pp = false): array
    {
        $baseline = $windows['train'] ?? [];
        if (($baseline['games'] ?? 0) < 5) {
            return [];
        }
        $metrics = $player
            ? ['sat' => 'SAT/GP', 'sog' => 'SOG/GP', 'goals' => 'G/GP', 'points' => 'PTS/GP', 'sat_per_60' => 'SAT/60', 'shooting_pct' => 'SH%']
            : ['goals_for_per_game' => 'GF/GP', 'goals_against_per_game' => 'GA/GP', 'sat_for_per_game' => 'SAT/GP',
                'corsi_pct' => 'Corsi%', 'shooting_pct' => 'SH%'];
        if ($pp) {
            $metrics = Arr::only($metrics, ['goals', 'sog']);
        }
        $periods = ['last_5' => 'Last 5 Games', 'last_10' => 'Last 10 Games', 'last_20' => 'Last 20 Games', 'last_season' => 'Last Season',
            'opponent_train' => 'Historical seasons vs opponent', 'opponent_last_5' => 'Last 5 vs opponent',
            'opponent_last_10' => 'Last 10 vs opponent', 'opponent_last_20' => 'Last 20 vs opponent', 'opponent_last_season' => 'Last Season vs opponent'];
        $facts = [];
        foreach ($periods as $window => $label) {
            $sample = $windows[$window] ?? [];
            if (($sample['games'] ?? 0) < 5) {
                continue;
            }
            foreach ($metrics as $key => $metric) {
                $base = $this->publicNumber($baseline[$key] ?? null);
                $value = $this->publicNumber($sample[$key] ?? null);
                if ($player && in_array($key, ['sat', 'sog', 'goals', 'points'], true)) {
                    $base = $base === null ? null : $base / $baseline['games'];
                    $value = $value === null ? null : $value / $sample['games'];
                }
                if ($base === null || $value === null || $base <= 0 || $value < 0) {
                    continue;
                }
                $delta = 100 * abs($value - $base) / $base;
                if ($delta < 25) {
                    continue;
                }
                $facts[] = ['key' => $entity.':'.($pp ? 'PP:' : '').$metric, 'entity' => $entity, 'side' => $side,
                    'team' => $team, 'name' => $name, 'metric' => ($pp ? 'PP ' : '').$metric,
                    'period' => $label, 'value' => $value, 'baseline' => $base, 'games' => (int) $sample['games'],
                    'baseline_games' => (int) $baseline['games'], 'delta_pct' => $delta,
                    'direction' => $value > $base ? 'higher' : 'lower', 'is_percentage' => str_ends_with($metric, '%')];
            }
        }

        return $facts;
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
        $records = $rows->map(function ($row) use ($teamId, $game): array {
            $home = (int) $row->home_team_id === $teamId;
            $gf = (int) ($home ? $row->home_team_score : $row->away_team_score);
            $ga = (int) ($home ? $row->away_team_score : $row->home_team_score);

            $side = $home ? 'home' : 'away';
            $other = $home ? 'away' : 'home';

            return ['gf' => $gf, 'ga' => $ga, 'win' => $gf > $ga, 'otl' => $gf < $ga && in_array($this->finalPeriodType($row), ['OT', 'SO'], true),
                'pp_goals' => $row->{$side.'_pp_goals'}, 'pp_opps' => $row->{$side.'_pp_opportunities'},
                'pk_ga' => $row->{$other.'_pp_goals'}, 'pk_opps' => $row->{$other.'_pp_opportunities'},
                'against_matchup' => ((int) $row->home_team_id === (int) $game->home_team_id
                    && (int) $row->away_team_id === (int) $game->away_team_id)
                    || ((int) $row->home_team_id === (int) $game->away_team_id && (int) $row->away_team_id === (int) $game->home_team_id)];
        });
        $wins = $records->where('win', true)->count();
        $otl = $records->where('otl', true)->count();
        $last = $records->take(10);
        $lastFive = $records->take(5);
        $h2h = NhlGame::query()->where('game_type', $game->game_type)
            ->where('game_date', '<', $game->game_date->toDateString())->whereIn('game_state', ['OFF', 'FINAL'])
            ->whereNotNull('home_team_score')->whereNotNull('away_team_score')
            ->whereIn('home_team_id', [$game->home_team_id, $game->away_team_id])
            ->whereIn('away_team_id', [$game->home_team_id, $game->away_team_id])
            ->orderByDesc('game_date')->orderByDesc('nhl_game_id')->limit(10)->get()
            ->map(function (NhlGame $row) use ($teamId): array {
                $home = (int) $row->home_team_id === $teamId;
                $won = ($home ? $row->home_team_score : $row->away_team_score) > ($home ? $row->away_team_score : $row->home_team_score);

                return ['win' => $won, 'otl' => !$won && in_array($this->finalPeriodType($row), ['OT', 'SO'], true)];
            });
        $ppComplete = $records->isNotEmpty() && $records->every(fn (array $row): bool => $row['pp_goals'] !== null && $row['pp_opps'] !== null);
        $oppsComplete = $records->isNotEmpty() && $records->every(fn (array $row): bool => $row['pp_opps'] !== null);
        $pkComplete = $records->isNotEmpty() && $records->every(fn (array $row): bool => $row['pk_ga'] !== null && $row['pk_opps'] !== null);

        return ['gp' => $records->count(), 'wins' => $wins, 'losses' => $records->count() - $wins - $otl, 'otl' => $otl,
            'gf_gp' => $records->isEmpty() ? null : $records->sum('gf') / $records->count(),
            'ga_gp' => $records->isEmpty() ? null : $records->sum('ga') / $records->count(),
            'pp_pct' => $ppComplete && $records->sum('pp_opps') > 0 ? 100 * $records->sum('pp_goals') / $records->sum('pp_opps') : null,
            'pk_pct' => $pkComplete && $records->sum('pk_opps') > 0 ? 100 * (1 - $records->sum('pk_ga') / $records->sum('pk_opps')) : null,
            'pp_opps_gp' => $oppsComplete ? $records->sum('pp_opps') / $records->count() : null,
            'pp_opps_allowed_gp' => $records->isNotEmpty() && $records->every(fn (array $row): bool => $row['pk_opps'] !== null)
                ? $records->sum('pk_opps') / $records->count() : null,
            'h2h_gp' => $h2h->count(), 'h2h_wins' => $h2h->where('win', true)->count(),
            'h2h_losses' => $h2h->where('win', false)->where('otl', false)->count(), 'h2h_otl' => $h2h->where('otl', true)->count(),
            'l5_gp' => $lastFive->count(), 'l5_wins' => $lastFive->where('win', true)->count(),
            'l5_losses' => $lastFive->where('win', false)->where('otl', false)->count(), 'l5_otl' => $lastFive->where('otl', true)->count(),
            'l10_gp' => $last->count(), 'l10_wins' => $last->where('win', true)->count(),
            'l10_losses' => $last->where('win', false)->where('otl', false)->count(), 'l10_otl' => $last->where('otl', true)->count()];
    }

    /** Prepare expected-goal rates off the request path using the existing scored-statistics reader. */
    private function prepareMatchupExpectations(NhlGame $game): void
    {
        $ids = DB::table('nhl_games')->where('season_id', $game->season_id)->where('game_type', $game->game_type)
            ->whereIn('game_state', ['OFF', 'FINAL'])->whereDate('game_date', '<', $game->game_date->toDateString())
            ->where(fn ($query) => $query->whereIn('home_team_id', [$game->away_team_id, $game->home_team_id])
                ->orWhereIn('away_team_id', [$game->away_team_id, $game->home_team_id]))->pluck('nhl_game_id')->all();
        [$for, $against] = app(NhlStatsDashboardPayload::class)->teamExpectationsForGames((string) $game->season_id, $ids,
            $game->game_date->copy()->subDay()->toDateString(), (int) $game->game_type);
        $result = [];
        foreach (['away', 'home'] as $side) {
            $id = (int) $game->{$side.'_team_id'};
            $gp = $this->form($game, $id)['gp'];
            $rates = [];
            foreach (['xgf_gp' => $for->get($id), 'xga_gp' => $against->get($id)] as $key => $row) {
                $rates[$key] = $gp > 0 && $row !== null && (int) $row->games === $gp
                    && (int) $row->scored === (int) $row->attempts && $row->xg !== null ? (float) $row->xg / $gp : null;
            }
            $result[$side] = $rates;
        }
        Cache::put($this->cacheKey($game).':matchup-expectations:v1', $result, 300);
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
                'homeTeamDefendingSide' => $row->home_team_defending_side, 'situationCode' => $row->situation_code,
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
            $play['goal_strength'] = null;
            $situation = (string) ($row['situationCode'] ?? '');
            if ($type === 'goal' && $play['period_type'] !== 'SO' && $side !== null && preg_match('/^\d{4}$/', $situation)) {
                $strength = app(ImportNHLPlayByPlay::class)->determineStrength($situation, (int) $teamId,
                    (int) $teams['home']['team_id'], (int) $teams['away']['team_id']);
                $opponentGoalie = $side === 'home' ? $situation[0] : $situation[3];
                $play['goal_strength'] = $opponentGoalie === '0' ? 'ENG' : match ($strength) {
                    'PP' => 'PPG', 'PK' => 'SHG', default => 'EVG',
                };
            }
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
