<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\BackfillNhlExpectedGoalsJob;
use App\Models\NhlExpectedGoalsModel;
use App\Services\NhlExpectedGoalsBackfiller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/**
 * Admin analysis panel for NHL shot-attempt facts.
 */
class NhlShotAttemptController extends Controller
{
    /**
     * Render the shot-attempt analysis panel.
     */
    public function index(Request $request): View
    {
        $input = $request->validate([
            'tab' => ['nullable', Rule::in(['explorer', 'aggregates', 'buckets', 'predictive', 'biometrics', 'xg', 'qa'])],
            'season_id' => ['nullable', 'digits:8'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'team_id' => ['nullable', 'integer'],
            'strength_bucket' => ['nullable', 'string', 'max:32'],
            'attempt_result' => ['nullable', 'string', 'max:32'],
            'distance_bucket' => ['nullable', 'string', 'max:32'],
            'angle_bucket' => ['nullable', 'string', 'max:32'],
            'shot_type_bucket' => ['nullable', 'string', 'max:32'],
            'shot_side' => ['nullable', Rule::in(['left', 'right', 'center', 'unknown'])],
            'is_off_wing_attempt' => ['nullable', Rule::in(['1', '0'])],
            'predictive_group' => ['nullable', Rule::in(array_keys($this->predictiveGroupDefinitions()))],
            'min_attempts' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'group_by' => ['nullable', Rule::in([
                'team_abbrev',
                'shooter_player_id',
                'goalie_player_id',
                'strength_bucket',
                'attempt_result',
                'distance_bucket',
                'angle_bucket',
                'shot_type_bucket',
                'shot_side',
                'is_off_wing_attempt',
                'is_rebound',
                'previous_event_type',
            ])],
            'sort' => ['nullable', Rule::in($this->allSortKeys())],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'xg_model_sort' => ['nullable', Rule::in(array_keys($this->xgModelSortColumns()))],
            'xg_model_direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'xg_bucket_sort' => ['nullable', Rule::in(array_keys($this->xgBucketSortColumns()))],
            'xg_bucket_direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'xg_team_sort' => ['nullable', Rule::in(array_keys($this->xgTeamSortColumns()))],
            'xg_team_direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'xg_shooter_sort' => ['nullable', Rule::in(array_keys($this->xgShooterSortColumns()))],
            'xg_shooter_direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'xg_trend_sort' => ['nullable', Rule::in(array_keys($this->xgTrendSortColumns()))],
            'xg_trend_direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'xg_profile_sort' => ['nullable', Rule::in(array_keys($this->xgProfileSortColumns()))],
            'xg_profile_direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);

        $tab = (string) ($input['tab'] ?? 'explorer');
        $filters = $this->filters($input);
        $tableExists = Schema::hasTable('nhl_shot_attempts_facts');
        $xgTableExists = $this->xgTablesExist();
        $predictiveGroup = (string) ($input['predictive_group'] ?? 'distance_shot_type');
        $minAttempts = (int) ($input['min_attempts'] ?? 300);
        $sort = $this->sortKey($tab, (string) ($input['sort'] ?? ''));
        $direction = $this->sortDirection((string) ($input['direction'] ?? ''));
        $xgModelSort = (string) ($input['xg_model_sort'] ?? 'trained_at');
        $xgModelDirection = $this->sortDirection((string) ($input['xg_model_direction'] ?? ''));
        $xgBucketSort = (string) ($input['xg_bucket_sort'] ?? 'smoothed_goal_probability');
        $xgBucketDirection = $this->sortDirection((string) ($input['xg_bucket_direction'] ?? ''));
        $xgTeamSort = (string) ($input['xg_team_sort'] ?? 'xg_for');
        $xgTeamDirection = $this->sortDirection((string) ($input['xg_team_direction'] ?? ''));
        $xgShooterSort = (string) ($input['xg_shooter_sort'] ?? 'ixg');
        $xgShooterDirection = $this->sortDirection((string) ($input['xg_shooter_direction'] ?? ''));
        $xgTrendSort = (string) ($input['xg_trend_sort'] ?? 'xg_per_sat_delta');
        $xgTrendDirection = $this->sortDirection((string) ($input['xg_trend_direction'] ?? ''));
        $xgProfileSort = (string) ($input['xg_profile_sort'] ?? 'recent_xg_per_sat');
        $xgProfileDirection = $this->sortDirection((string) ($input['xg_profile_direction'] ?? ''));
        $latestXgModel = $xgTableExists ? $this->latestXgModel($filters['season_id'], NhlExpectedGoalsBackfiller::TARGET_GOAL) : null;
        $latestXsogModel = $xgTableExists ? $this->latestXgModel($filters['season_id'], NhlExpectedGoalsBackfiller::TARGET_SHOT_ON_GOAL) : null;

        return view('admin.nhl-shot-attempts.index', [
            'activeTab' => $tab,
            'filters' => $filters,
            'tableExists' => $tableExists,
            'xgTableExists' => $xgTableExists,
            'summary' => $tableExists ? $this->summary($filters) : $this->emptySummary(),
            'explorerRows' => $tableExists && $tab === 'explorer'
                ? $this->explorerRows($filters, $sort, $direction)
                : $this->emptyPaginator(),
            'aggregateRows' => $tableExists && $tab === 'aggregates'
                ? $this->aggregateRows(
                    $filters,
                    (string) ($input['group_by'] ?? 'team_abbrev'),
                    $sort,
                    $direction
                )
                : collect(),
            'bucketRows' => $tableExists && $tab === 'buckets'
                ? $this->bucketRows($filters, $sort, $direction)
                : collect(),
            'predictiveRows' => $tableExists && $tab === 'predictive'
                ? $this->predictiveRows($filters, $predictiveGroup, $minAttempts, $sort, $direction)
                : collect(),
            'biometricRows' => $tableExists && $tab === 'biometrics'
                ? $this->biometricRows($filters, $latestXgModel?->id, $sort, $direction)
                : collect(),
            'qaRows' => $tableExists && $tab === 'qa'
                ? $this->qaRows($filters)
                : $this->emptyQaRows(),
            'qaTableRows' => $tableExists && $tab === 'qa'
                ? $this->qaTableRows($filters, $sort, $direction)
                : collect(),
            'xgSummary' => $xgTableExists && $tab === 'xg'
                ? $this->xgSummary($filters['season_id'], $latestXgModel)
                : $this->emptyXgSummary(),
            'xgModelRows' => $xgTableExists && $tab === 'xg'
                ? $this->xgModelRows($filters['season_id'], $xgModelSort, $xgModelDirection)
                : collect(),
            'xgBucketRows' => $xgTableExists && $tab === 'xg'
                ? $this->xgBucketRows($latestXgModel?->id, $xgBucketSort, $xgBucketDirection)
                : collect(),
            'xgTeamRows' => $xgTableExists && $tab === 'xg'
                ? $this->xgTeamRows($latestXgModel?->id, $xgTeamSort, $xgTeamDirection)
                : collect(),
            'xgShooterRows' => $xgTableExists && $tab === 'xg'
                ? $this->xgShooterRows($latestXgModel?->id, $latestXsogModel?->id, $xgShooterSort, $xgShooterDirection)
                : collect(),
            'xgTrendRows' => $xgTableExists && $tab === 'xg'
                ? $this->xgTrendRows($latestXgModel?->id, $latestXsogModel?->id, $xgTrendSort, $xgTrendDirection)
                : collect(),
            'xgProfileRows' => $xgTableExists && $tab === 'xg'
                ? $this->xgProfileRows($latestXgModel?->id, $latestXsogModel?->id, $xgProfileSort, $xgProfileDirection)
                : collect(),
            'xgSorts' => [
                'model' => $xgModelSort,
                'bucket' => $xgBucketSort,
                'team' => $xgTeamSort,
                'shooter' => $xgShooterSort,
                'trend' => $xgTrendSort,
                'profile' => $xgProfileSort,
            ],
            'xgDirections' => [
                'model' => $xgModelDirection,
                'bucket' => $xgBucketDirection,
                'team' => $xgTeamDirection,
                'shooter' => $xgShooterDirection,
                'trend' => $xgTrendDirection,
                'profile' => $xgProfileDirection,
            ],
            'groupBy' => (string) ($input['group_by'] ?? 'team_abbrev'),
            'predictiveGroups' => $this->predictiveGroupDefinitions(),
            'predictiveGroup' => $predictiveGroup,
            'minAttempts' => $minAttempts,
            'options' => $tableExists ? $this->filterOptions() : $this->emptyOptions(),
            'sort' => $sort,
            'direction' => $direction,
        ]);
    }

    /**
     * Build and backfill the xG model for the selected season.
     */
    public function buildXg(Request $request): RedirectResponse
    {
        $input = $request->validate([
            'season_id' => ['required', 'digits:8'],
            'version' => ['nullable', 'string', 'max:80'],
            'minimum_bucket_attempts' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'smoothing_prior_attempts' => ['nullable', 'integer', 'min:0', 'max:10000'],
        ]);

        if (!$this->xgTablesExist()) {
            return redirect()
                ->route('admin.nhl-shot-attempts.index', ['tab' => 'xg', 'season_id' => $input['season_id']])
                ->with('error', 'Run the xG migrations before building expected-goals predictions.');
        }

        $seasonId = (string) $input['season_id'];
        $version = (string) ($input['version'] ?? NhlExpectedGoalsBackfiller::DEFAULT_VERSION);
        $minimumBucketAttempts = (int) ($input['minimum_bucket_attempts'] ?? 300);
        $smoothingPriorAttempts = (int) ($input['smoothing_prior_attempts'] ?? 100);

        foreach ([NhlExpectedGoalsBackfiller::TARGET_GOAL, NhlExpectedGoalsBackfiller::TARGET_SHOT_ON_GOAL] as $target) {
            NhlExpectedGoalsModel::query()->updateOrCreate(
                ['name' => NhlExpectedGoalsBackfiller::MODEL_NAME, 'version' => $version, 'prediction_target' => $target],
                [
                    'model_type' => 'bucket_smoothed',
                    'training_season_id' => $seasonId,
                    'minimum_bucket_attempts' => $minimumBucketAttempts,
                    'smoothing_prior_attempts' => $smoothingPriorAttempts,
                    'metrics' => ['queued_at' => now()->toIso8601String()],
                    'status' => 'queued',
                    'trained_at' => null,
                ]
            );

            BackfillNhlExpectedGoalsJob::dispatch(
                $seasonId,
                $version,
                $minimumBucketAttempts,
                $smoothingPriorAttempts,
                $target
            );
        }

        return redirect()
            ->route('admin.nhl-shot-attempts.index', ['tab' => 'xg', 'season_id' => $seasonId])
            ->with('status', sprintf(
                'Queued shot outcome models %s for season %s.',
                $version,
                $seasonId
            ));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function filters(array $input): array
    {
        return [
            'season_id' => $input['season_id'] ?? null,
            'start_date' => $input['start_date'] ?? null,
            'end_date' => $input['end_date'] ?? null,
            'team_id' => $input['team_id'] ?? null,
            'strength_bucket' => $input['strength_bucket'] ?? null,
            'attempt_result' => $input['attempt_result'] ?? null,
            'distance_bucket' => $input['distance_bucket'] ?? null,
            'angle_bucket' => $input['angle_bucket'] ?? null,
            'shot_type_bucket' => $input['shot_type_bucket'] ?? null,
            'shot_side' => $input['shot_side'] ?? null,
            'is_off_wing_attempt' => $input['is_off_wing_attempt'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function baseQuery(array $filters)
    {
        $query = DB::table('nhl_shot_attempts_facts');

        foreach ([
            'season_id',
            'team_id',
            'strength_bucket',
            'attempt_result',
            'distance_bucket',
            'angle_bucket',
            'shot_type_bucket',
            'shot_side',
        ] as $column) {
            if ($filters[$column] !== null && $filters[$column] !== '') {
                $query->where('nhl_shot_attempts_facts.' . $column, $filters[$column]);
            }
        }

        if ($filters['is_off_wing_attempt'] !== null && $filters['is_off_wing_attempt'] !== '') {
            $query->where('nhl_shot_attempts_facts.is_off_wing_attempt', $filters['is_off_wing_attempt'] === '1');
        }

        if ($filters['start_date']) {
            $query->whereDate('nhl_shot_attempts_facts.game_date', '>=', $filters['start_date']);
        }

        if ($filters['end_date']) {
            $query->whereDate('nhl_shot_attempts_facts.game_date', '<=', $filters['end_date']);
        }

        return $query;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, int|float|null>
     */
    private function summary(array $filters): array
    {
        $row = $this->baseQuery($filters)
            ->selectRaw('COUNT(*) as attempts')
            ->selectRaw('SUM(CASE WHEN is_unblocked_attempt THEN 1 ELSE 0 END) as unblocked_attempts')
            ->selectRaw('SUM(CASE WHEN is_shot_on_goal THEN 1 ELSE 0 END) as shots_on_goal')
            ->selectRaw('SUM(CASE WHEN is_goal THEN 1 ELSE 0 END) as goals')
            ->selectRaw('SUM(CASE WHEN is_rebound THEN 1 ELSE 0 END) as rebounds')
            ->selectRaw('SUM(CASE WHEN is_rush THEN 1 ELSE 0 END) as rushes')
            ->selectRaw('SUM(CASE WHEN shot_distance IS NULL OR abs_shot_angle IS NULL THEN 1 ELSE 0 END) as missing_geometry')
            ->first();

        $attempts = (int) ($row->attempts ?? 0);
        $goals = (int) ($row->goals ?? 0);

        return [
            'attempts' => $attempts,
            'unblocked_attempts' => (int) ($row->unblocked_attempts ?? 0),
            'shots_on_goal' => (int) ($row->shots_on_goal ?? 0),
            'goals' => $goals,
            'rebounds' => (int) ($row->rebounds ?? 0),
            'rushes' => (int) ($row->rushes ?? 0),
            'missing_geometry' => (int) ($row->missing_geometry ?? 0),
            'goal_rate' => $attempts > 0 ? round(($goals / $attempts) * 100, 2) : null,
        ];
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function explorerRows(array $filters, string $sort, string $direction): LengthAwarePaginator
    {
        $query = $this->baseQuery($filters)
            ->select([
                'id',
                'nhl_game_id',
                'game_date',
                'period',
                'seconds_in_game',
                'team_id',
                'opponent_team_id',
                'shooter_player_id',
                'goalie_player_id',
                'attempt_result',
                'strength_bucket',
                'shot_distance',
                'abs_shot_angle',
                'shot_side',
                'distance_bucket',
                'angle_bucket',
                'shot_type_bucket',
                'is_off_wing_attempt',
                'is_rebound',
                'is_rush',
                'is_goal',
            ]);

        foreach ($this->explorerSortColumns($sort) as $column) {
            $query->orderBy($column, $direction);
        }

        return $query
            ->orderByDesc('game_date')
            ->orderByDesc('nhl_game_id')
            ->orderBy('seconds_in_game')
            ->paginate(50)
            ->withQueryString();
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function aggregateRows(array $filters, string $groupBy, string $sort, string $direction)
    {
        $query = $this->baseQuery($filters);
        $groupExpression = 'nhl_shot_attempts_facts.' . $groupBy;

        if ($groupBy === 'team_abbrev') {
            $query->leftJoin('nhl_teams', 'nhl_teams.nhl_id', '=', 'nhl_shot_attempts_facts.team_id');
            $groupExpression = 'nhl_teams.abbrev';
        }

        if ($groupBy === 'is_rebound') {
            $groupExpression = "CASE WHEN nhl_shot_attempts_facts.is_rebound THEN 'Rebound' WHEN nhl_shot_attempts_facts.is_rebound = false THEN 'No Rebound' ELSE 'Unknown' END";
        }

        if ($groupBy === 'is_off_wing_attempt') {
            $groupExpression = "CASE WHEN nhl_shot_attempts_facts.is_off_wing_attempt THEN 'Off-Wing' WHEN nhl_shot_attempts_facts.is_off_wing_attempt = false THEN 'Strong-Side' ELSE 'Unknown' END";
        }

        if ($groupBy === 'goalie_player_id') {
            $query->leftJoin('players as goalies', 'goalies.nhl_id', '=', 'nhl_shot_attempts_facts.goalie_player_id');
            $groupExpression = "COALESCE(goalies.full_name, nhl_shot_attempts_facts.goalie_player_id::text)";
        }

        if ($groupBy === 'shooter_player_id') {
            $query->leftJoin('players as shooters', 'shooters.nhl_id', '=', 'nhl_shot_attempts_facts.shooter_player_id');
            $groupExpression = "COALESCE(shooters.full_name, nhl_shot_attempts_facts.shooter_player_id::text)";
        }

        return $query
            ->where('nhl_shot_attempts_facts.shot_type_bucket', '<>', 'unknown')
            ->selectRaw($groupExpression . ' as group_value')
            ->selectRaw('COUNT(*) as attempts')
            ->selectRaw('SUM(CASE WHEN is_unblocked_attempt THEN 1 ELSE 0 END) as unblocked_attempts')
            ->selectRaw('SUM(CASE WHEN is_shot_on_goal THEN 1 ELSE 0 END) as shots_on_goal')
            ->selectRaw('SUM(CASE WHEN is_goal THEN 1 ELSE 0 END) as goals')
            ->selectRaw('CASE WHEN COUNT(*) > 0 THEN (SUM(CASE WHEN is_shot_on_goal THEN 1 ELSE 0 END)::decimal / COUNT(*)) * 100 ELSE NULL END as sog_rate')
            ->selectRaw('CASE WHEN COUNT(*) > 0 THEN (SUM(CASE WHEN is_goal THEN 1 ELSE 0 END)::decimal / COUNT(*)) * 100 ELSE NULL END as goal_rate')
            ->selectRaw('AVG(nhl_shot_attempts_facts.shot_distance) as avg_distance')
            ->selectRaw('AVG(nhl_shot_attempts_facts.abs_shot_angle) as avg_angle')
            ->groupByRaw($groupExpression)
            ->orderBy($sort, $direction)
            ->limit(100)
            ->get();
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function bucketRows(array $filters, string $sort, string $direction)
    {
        return $this->baseQuery($filters)
            ->where('shot_type_bucket', '<>', 'unknown')
            ->select(['distance_bucket', 'angle_bucket', 'strength_bucket', 'shot_side', 'is_off_wing_attempt'])
            ->selectRaw('COUNT(*) as attempts')
            ->selectRaw('SUM(CASE WHEN is_goal THEN 1 ELSE 0 END) as goals')
            ->selectRaw('SUM(CASE WHEN is_shot_on_goal THEN 1 ELSE 0 END) as shots_on_goal')
            ->selectRaw('CASE WHEN COUNT(*) > 0 THEN (SUM(CASE WHEN is_shot_on_goal THEN 1 ELSE 0 END)::decimal / COUNT(*)) * 100 ELSE NULL END as sog_rate')
            ->selectRaw('CASE WHEN COUNT(*) > 0 THEN (SUM(CASE WHEN is_goal THEN 1 ELSE 0 END)::decimal / COUNT(*)) * 100 ELSE NULL END as goal_rate')
            ->selectRaw('AVG(shot_distance) as avg_distance')
            ->selectRaw('AVG(abs_shot_angle) as avg_angle')
            ->groupBy('distance_bucket', 'angle_bucket', 'strength_bucket', 'shot_side', 'is_off_wing_attempt')
            ->orderBy($sort, $direction)
            ->orderByDesc('attempts')
            ->limit(150)
            ->get();
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function predictiveRows(array $filters, string $groupKey, int $minAttempts, string $sort, string $direction)
    {
        $definition = $this->predictiveGroupDefinitions()[$groupKey]
            ?? $this->predictiveGroupDefinitions()['distance_shot_type'];

        $query = $this->predictiveSampleQuery($filters);

        return $query
            ->selectRaw($definition['select'])
            ->selectRaw('COUNT(*) as attempts')
            ->selectRaw('SUM(CASE WHEN is_shot_on_goal THEN 1 ELSE 0 END) as shots_on_goal')
            ->selectRaw('SUM(CASE WHEN is_goal THEN 1 ELSE 0 END) as goals')
            ->selectRaw('CASE WHEN COUNT(*) > 0 THEN (SUM(CASE WHEN is_shot_on_goal THEN 1 ELSE 0 END)::decimal / COUNT(*)) * 100 ELSE NULL END as sog_rate')
            ->selectRaw('CASE WHEN COUNT(*) > 0 THEN (SUM(CASE WHEN is_goal THEN 1 ELSE 0 END)::decimal / COUNT(*)) * 100 ELSE NULL END as goal_rate')
            ->selectRaw('CASE WHEN SUM(CASE WHEN is_shot_on_goal THEN 1 ELSE 0 END) > 0 THEN (SUM(CASE WHEN is_goal THEN 1 ELSE 0 END)::decimal / SUM(CASE WHEN is_shot_on_goal THEN 1 ELSE 0 END)) * 100 ELSE NULL END as shooting_rate')
            ->selectRaw('AVG(shot_distance) as avg_distance')
            ->selectRaw('AVG(abs_shot_angle) as avg_angle')
            ->groupByRaw($definition['group'])
            ->havingRaw('COUNT(*) >= ?', [$minAttempts])
            ->orderBy($sort, $direction)
            ->orderByDesc('attempts')
            ->limit(150)
            ->get();
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function predictiveSampleQuery(array $filters)
    {
        return $this->baseQuery($filters)
            ->where('nhl_shot_attempts_facts.shot_type_bucket', '<>', 'unknown')
            ->where(function ($query): void {
                $query->whereNull('nhl_shot_attempts_facts.period_type')
                    ->orWhere('nhl_shot_attempts_facts.period_type', '<>', 'SO');
            })
            ->where(function ($query): void {
                $query->whereNull('nhl_shot_attempts_facts.is_empty_net')
                    ->orWhere('nhl_shot_attempts_facts.is_empty_net', false);
            });
    }

    /**
     * @return array<string, array{label:string, select:string, group:string, columns:array<int, string>}>
     */
    private function predictiveGroupDefinitions(): array
    {
        $deltaBucket = "CASE
            WHEN previous_event_seconds_delta IS NULL THEN 'unknown'
            WHEN previous_event_seconds_delta <= 1 THEN '00_01'
            WHEN previous_event_seconds_delta <= 3 THEN '02_03'
            WHEN previous_event_seconds_delta <= 5 THEN '04_05'
            WHEN previous_event_seconds_delta <= 10 THEN '06_10'
            WHEN previous_event_seconds_delta <= 20 THEN '11_20'
            ELSE '21_plus'
        END";
        $offWingBucket = "CASE
            WHEN is_off_wing_attempt THEN 'off_wing'
            WHEN is_off_wing_attempt = false THEN 'strong_side'
            ELSE 'center_or_unknown'
        END";

        return [
            'distance_shot_type' => [
                'label' => 'Distance + Shot Type',
                'select' => 'distance_bucket as dimension_1, shot_type_bucket as dimension_2, NULL as dimension_3',
                'group' => 'distance_bucket, shot_type_bucket',
                'columns' => ['Distance', 'Shot Type', ''],
            ],
            'distance_rebound' => [
                'label' => 'Distance + Rebound',
                'select' => 'distance_bucket as dimension_1, rebound_bucket as dimension_2, NULL as dimension_3',
                'group' => 'distance_bucket, rebound_bucket',
                'columns' => ['Distance', 'Rebound', ''],
            ],
            'distance_rush' => [
                'label' => 'Distance + Rush',
                'select' => 'distance_bucket as dimension_1, rush_bucket as dimension_2, NULL as dimension_3',
                'group' => 'distance_bucket, rush_bucket',
                'columns' => ['Distance', 'Rush', ''],
            ],
            'distance_strength_shot_type' => [
                'label' => 'Distance + Strength + Shot Type',
                'select' => 'distance_bucket as dimension_1, strength_bucket as dimension_2, shot_type_bucket as dimension_3',
                'group' => 'distance_bucket, strength_bucket, shot_type_bucket',
                'columns' => ['Distance', 'Strength', 'Shot Type'],
            ],
            'previous_event_timing' => [
                'label' => 'Previous Event + Timing',
                'select' => 'previous_event_type as dimension_1, ' . $deltaBucket . ' as dimension_2, NULL as dimension_3',
                'group' => 'previous_event_type, ' . $deltaBucket,
                'columns' => ['Previous Event', 'Delta Seconds', ''],
            ],
            'shot_type_offwing_strength' => [
                'label' => 'Shot Type + Off-Wing + Strength',
                'select' => 'shot_type_bucket as dimension_1, ' . $offWingBucket . ' as dimension_2, strength_bucket as dimension_3',
                'group' => 'shot_type_bucket, ' . $offWingBucket . ', strength_bucket',
                'columns' => ['Shot Type', 'Off-Wing', 'Strength'],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function biometricRows(array $filters, mixed $goalModelId, string $sort, string $direction)
    {
        if (! $this->biometricColumnsExist()) {
            return collect();
        }

        $rows = collect();

        foreach ($this->biometricGroupDefinitions() as $definition) {
            $query = $this->predictiveSampleQuery($filters)
                ->whereNotNull('nhl_shot_attempts_facts.' . $definition['column']);

            if ($goalModelId !== null) {
                $query->leftJoin('nhl_shot_attempt_predictions as goal_predictions', function ($join) use ($goalModelId): void {
                    $join->on('goal_predictions.shot_attempt_fact_id', '=', 'nhl_shot_attempts_facts.id')
                        ->where('goal_predictions.expected_goals_model_id', '=', $goalModelId)
                        ->where('goal_predictions.prediction_target', '=', NhlExpectedGoalsBackfiller::TARGET_GOAL)
                        ->where('goal_predictions.is_scored', '=', true);
                });
            }

            $query
                ->selectRaw('? as profile', [$definition['label']])
                ->selectRaw($definition['label_sql'] . ' as bucket')
                ->selectRaw($definition['sort_sql'] . ' as bucket_sort')
                ->selectRaw('COUNT(*) as attempts')
                ->selectRaw('SUM(CASE WHEN nhl_shot_attempts_facts.is_shot_on_goal THEN 1 ELSE 0 END) as shots_on_goal')
                ->selectRaw('SUM(CASE WHEN nhl_shot_attempts_facts.is_goal THEN 1 ELSE 0 END) as goals')
                ->selectRaw('CASE WHEN COUNT(*) > 0 THEN (SUM(CASE WHEN nhl_shot_attempts_facts.is_shot_on_goal THEN 1 ELSE 0 END)::decimal / COUNT(*)) * 100 ELSE NULL END as sog_rate')
                ->selectRaw('CASE WHEN COUNT(*) > 0 THEN (SUM(CASE WHEN nhl_shot_attempts_facts.is_goal THEN 1 ELSE 0 END)::decimal / COUNT(*)) * 100 ELSE NULL END as goal_rate')
                ->selectRaw('AVG(nhl_shot_attempts_facts.shot_distance) as avg_distance')
                ->selectRaw('AVG(nhl_shot_attempts_facts.abs_shot_angle) as avg_angle')
                ->groupByRaw($definition['label_sql'])
                ->groupByRaw($definition['sort_sql']);

            if ($goalModelId !== null) {
                $query
                    ->selectRaw('AVG(goal_predictions.xg) as avg_xg_per_sat')
                    ->selectRaw('SUM(CASE WHEN nhl_shot_attempts_facts.is_goal THEN 1 ELSE 0 END) - SUM(goal_predictions.xg) as goals_minus_xg');
            } else {
                $query
                    ->selectRaw('NULL as avg_xg_per_sat')
                    ->selectRaw('NULL as goals_minus_xg');
            }

            $rows = $rows->merge($query->get());
        }

        return $this->sortBiometricRows($rows, $sort, $direction)->values();
    }

    private function biometricColumnsExist(): bool
    {
        return Schema::hasColumn('nhl_shot_attempts_facts', 'shooter_age_years')
            && Schema::hasColumn('nhl_shot_attempts_facts', 'shooter_height_inches')
            && Schema::hasColumn('nhl_shot_attempts_facts', 'shooter_weight_lbs')
            && Schema::hasColumn('nhl_shot_attempts_facts', 'goalie_age_years')
            && Schema::hasColumn('nhl_shot_attempts_facts', 'goalie_height_inches')
            && Schema::hasColumn('nhl_shot_attempts_facts', 'goalie_weight_lbs');
    }

    /**
     * @return array<int, array{label:string,column:string,label_sql:string,sort_sql:string}>
     */
    private function biometricGroupDefinitions(): array
    {
        return [
            [
                'label' => 'Shooter Age',
                'column' => 'shooter_age_years',
                'label_sql' => "CASE
                    WHEN shooter_age_years IS NULL THEN 'Unknown'
                    WHEN shooter_age_years < 22 THEN '<22'
                    WHEN shooter_age_years < 25 THEN '22-24'
                    WHEN shooter_age_years < 28 THEN '25-27'
                    WHEN shooter_age_years < 31 THEN '28-30'
                    WHEN shooter_age_years < 34 THEN '31-33'
                    ELSE '34+'
                END",
                'sort_sql' => "CASE
                    WHEN shooter_age_years IS NULL THEN 99
                    WHEN shooter_age_years < 22 THEN 1
                    WHEN shooter_age_years < 25 THEN 2
                    WHEN shooter_age_years < 28 THEN 3
                    WHEN shooter_age_years < 31 THEN 4
                    WHEN shooter_age_years < 34 THEN 5
                    ELSE 6
                END",
            ],
            [
                'label' => 'Shooter Height',
                'column' => 'shooter_height_inches',
                'label_sql' => "CASE
                    WHEN shooter_height_inches IS NULL THEN 'Unknown'
                    WHEN shooter_height_inches < 70 THEN '<5-10'
                    WHEN shooter_height_inches < 73 THEN '5-10 to 6-0'
                    WHEN shooter_height_inches < 76 THEN '6-1 to 6-3'
                    WHEN shooter_height_inches < 79 THEN '6-4 to 6-6'
                    ELSE '6-7+'
                END",
                'sort_sql' => "CASE
                    WHEN shooter_height_inches IS NULL THEN 99
                    WHEN shooter_height_inches < 70 THEN 1
                    WHEN shooter_height_inches < 73 THEN 2
                    WHEN shooter_height_inches < 76 THEN 3
                    WHEN shooter_height_inches < 79 THEN 4
                    ELSE 5
                END",
            ],
            [
                'label' => 'Shooter Weight',
                'column' => 'shooter_weight_lbs',
                'label_sql' => "CASE
                    WHEN shooter_weight_lbs IS NULL THEN 'Unknown'
                    WHEN shooter_weight_lbs < 180 THEN '<180'
                    WHEN shooter_weight_lbs < 195 THEN '180-194'
                    WHEN shooter_weight_lbs < 210 THEN '195-209'
                    WHEN shooter_weight_lbs < 225 THEN '210-224'
                    ELSE '225+'
                END",
                'sort_sql' => "CASE
                    WHEN shooter_weight_lbs IS NULL THEN 99
                    WHEN shooter_weight_lbs < 180 THEN 1
                    WHEN shooter_weight_lbs < 195 THEN 2
                    WHEN shooter_weight_lbs < 210 THEN 3
                    WHEN shooter_weight_lbs < 225 THEN 4
                    ELSE 5
                END",
            ],
            [
                'label' => 'Goalie Age',
                'column' => 'goalie_age_years',
                'label_sql' => "CASE
                    WHEN goalie_age_years IS NULL THEN 'Unknown'
                    WHEN goalie_age_years < 24 THEN '<24'
                    WHEN goalie_age_years < 27 THEN '24-26'
                    WHEN goalie_age_years < 30 THEN '27-29'
                    WHEN goalie_age_years < 33 THEN '30-32'
                    WHEN goalie_age_years < 36 THEN '33-35'
                    ELSE '36+'
                END",
                'sort_sql' => "CASE
                    WHEN goalie_age_years IS NULL THEN 99
                    WHEN goalie_age_years < 24 THEN 1
                    WHEN goalie_age_years < 27 THEN 2
                    WHEN goalie_age_years < 30 THEN 3
                    WHEN goalie_age_years < 33 THEN 4
                    WHEN goalie_age_years < 36 THEN 5
                    ELSE 6
                END",
            ],
            [
                'label' => 'Goalie Height',
                'column' => 'goalie_height_inches',
                'label_sql' => "CASE
                    WHEN goalie_height_inches IS NULL THEN 'Unknown'
                    WHEN goalie_height_inches < 73 THEN '<6-1'
                    WHEN goalie_height_inches < 75 THEN '6-1 to 6-2'
                    WHEN goalie_height_inches < 77 THEN '6-3 to 6-4'
                    WHEN goalie_height_inches < 79 THEN '6-5 to 6-6'
                    ELSE '6-7+'
                END",
                'sort_sql' => "CASE
                    WHEN goalie_height_inches IS NULL THEN 99
                    WHEN goalie_height_inches < 73 THEN 1
                    WHEN goalie_height_inches < 75 THEN 2
                    WHEN goalie_height_inches < 77 THEN 3
                    WHEN goalie_height_inches < 79 THEN 4
                    ELSE 5
                END",
            ],
            [
                'label' => 'Goalie Weight',
                'column' => 'goalie_weight_lbs',
                'label_sql' => "CASE
                    WHEN goalie_weight_lbs IS NULL THEN 'Unknown'
                    WHEN goalie_weight_lbs < 185 THEN '<185'
                    WHEN goalie_weight_lbs < 200 THEN '185-199'
                    WHEN goalie_weight_lbs < 215 THEN '200-214'
                    WHEN goalie_weight_lbs < 230 THEN '215-229'
                    ELSE '230+'
                END",
                'sort_sql' => "CASE
                    WHEN goalie_weight_lbs IS NULL THEN 99
                    WHEN goalie_weight_lbs < 185 THEN 1
                    WHEN goalie_weight_lbs < 200 THEN 2
                    WHEN goalie_weight_lbs < 215 THEN 3
                    WHEN goalie_weight_lbs < 230 THEN 4
                    ELSE 5
                END",
            ],
        ];
    }

    private function sortBiometricRows($rows, string $sort, string $direction)
    {
        $sorter = match ($sort) {
            'profile' => fn (object $row): string => (string) $row->profile,
            'bucket' => fn (object $row): string => sprintf('%s:%02d:%s', $row->profile, (int) $row->bucket_sort, $row->bucket),
            'attempts' => fn (object $row): int => (int) $row->attempts,
            'shots_on_goal' => fn (object $row): int => (int) $row->shots_on_goal,
            'sog_rate' => fn (object $row): float => (float) ($row->sog_rate ?? 0),
            'goals' => fn (object $row): int => (int) $row->goals,
            'goal_rate' => fn (object $row): float => (float) ($row->goal_rate ?? 0),
            'avg_xg_per_sat' => fn (object $row): float => (float) ($row->avg_xg_per_sat ?? 0),
            'goals_minus_xg' => fn (object $row): float => (float) ($row->goals_minus_xg ?? 0),
            'avg_distance' => fn (object $row): float => (float) ($row->avg_distance ?? 0),
            'avg_angle' => fn (object $row): float => (float) ($row->avg_angle ?? 0),
            default => fn (object $row): string => sprintf('%s:%02d:%s', $row->profile, (int) $row->bucket_sort, $row->bucket),
        };

        return $direction === 'asc'
            ? $rows->sortBy($sorter)
            : $rows->sortByDesc($sorter);
    }

    /**
     * @return array<int, string>
     */
    private function allSortKeys(): array
    {
        return collect($this->sortDefinitions())
            ->flatMap(fn (array $definition): array => array_keys($definition['keys']))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<string, array{default:string,keys:array<string,mixed>}>
     */
    private function sortDefinitions(): array
    {
        return [
            'explorer' => [
                'default' => 'game',
                'keys' => [
                    'game' => ['game_date', 'nhl_game_id'],
                    'time' => ['period', 'seconds_in_game'],
                    'team_id' => ['team_id'],
                    'shooter_player_id' => ['shooter_player_id'],
                    'goalie_player_id' => ['goalie_player_id'],
                    'attempt_result' => ['attempt_result'],
                    'shot_distance' => ['shot_distance'],
                    'abs_shot_angle' => ['abs_shot_angle'],
                    'shot_side' => ['shot_side'],
                    'context' => ['strength_bucket', 'is_rebound', 'is_rush'],
                ],
            ],
            'aggregates' => [
                'default' => 'attempts',
                'keys' => [
                    'group_value' => true,
                    'attempts' => true,
                    'unblocked_attempts' => true,
                    'shots_on_goal' => true,
                    'sog_rate' => true,
                    'goals' => true,
                    'goal_rate' => true,
                    'avg_distance' => true,
                    'avg_angle' => true,
                ],
            ],
            'buckets' => [
                'default' => 'attempts',
                'keys' => [
                    'distance_bucket' => true,
                    'angle_bucket' => true,
                    'strength_bucket' => true,
                    'shot_side' => true,
                    'is_off_wing_attempt' => true,
                    'attempts' => true,
                    'shots_on_goal' => true,
                    'sog_rate' => true,
                    'goals' => true,
                    'goal_rate' => true,
                    'avg_distance' => true,
                    'avg_angle' => true,
                ],
            ],
            'predictive' => [
                'default' => 'goal_rate',
                'keys' => [
                    'dimension_1' => true,
                    'dimension_2' => true,
                    'dimension_3' => true,
                    'attempts' => true,
                    'shots_on_goal' => true,
                    'sog_rate' => true,
                    'goals' => true,
                    'goal_rate' => true,
                    'shooting_rate' => true,
                    'avg_distance' => true,
                    'avg_angle' => true,
                ],
            ],
            'biometrics' => [
                'default' => 'profile',
                'keys' => [
                    'profile' => true,
                    'bucket' => true,
                    'attempts' => true,
                    'shots_on_goal' => true,
                    'sog_rate' => true,
                    'goals' => true,
                    'goal_rate' => true,
                    'avg_xg_per_sat' => true,
                    'goals_minus_xg' => true,
                    'avg_distance' => true,
                    'avg_angle' => true,
                ],
            ],
            'qa' => [
                'default' => 'check',
                'keys' => [
                    'check' => true,
                    'rows' => true,
                ],
            ],
        ];
    }

    private function sortKey(string $tab, string $requestedSort): string
    {
        $definition = $this->sortDefinitions()[$tab] ?? $this->sortDefinitions()['explorer'];

        return array_key_exists($requestedSort, $definition['keys'])
            ? $requestedSort
            : $definition['default'];
    }

    private function sortDirection(string $requestedDirection): string
    {
        return $requestedDirection === 'asc' ? 'asc' : 'desc';
    }

    /**
     * @return array<int, string>
     */
    private function explorerSortColumns(string $sort): array
    {
        return $this->sortDefinitions()['explorer']['keys'][$sort]
            ?? $this->sortDefinitions()['explorer']['keys']['game'];
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function qaRows(array $filters)
    {
        return $this->baseQuery($filters)
            ->selectRaw('COUNT(*) as attempts')
            ->selectRaw('SUM(CASE WHEN shot_distance IS NULL OR abs_shot_angle IS NULL THEN 1 ELSE 0 END) as missing_geometry')
            ->selectRaw('SUM(CASE WHEN team_id IS NULL THEN 1 ELSE 0 END) as missing_team')
            ->selectRaw('SUM(CASE WHEN shooter_player_id IS NULL THEN 1 ELSE 0 END) as missing_shooter')
            ->selectRaw('SUM(CASE WHEN attempt_result IS NULL THEN 1 ELSE 0 END) as missing_result')
            ->selectRaw('SUM(CASE WHEN distance_bucket IS NULL THEN 1 ELSE 0 END) as missing_distance_bucket')
            ->selectRaw('SUM(CASE WHEN angle_bucket IS NULL THEN 1 ELSE 0 END) as missing_angle_bucket')
            ->selectRaw('SUM(CASE WHEN is_goal AND NOT is_shot_on_goal THEN 1 ELSE 0 END) as goals_not_sog')
            ->first();
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function qaTableRows(array $filters, string $sort, string $direction)
    {
        $rows = collect($this->qaRowsArray($filters))
            ->map(fn (int $rows, string $check): object => (object) [
                'check' => $check,
                'rows' => $rows,
            ]);

        return $direction === 'asc'
            ? $rows->sortBy($sort)->values()
            : $rows->sortByDesc($sort)->values();
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, int>
     */
    private function qaRowsArray(array $filters): array
    {
        $qaRows = $this->qaRows($filters);

        return [
            'Total attempts' => (int) ($qaRows->attempts ?? 0),
            'Missing geometry' => (int) ($qaRows->missing_geometry ?? 0),
            'Missing team' => (int) ($qaRows->missing_team ?? 0),
            'Missing shooter' => (int) ($qaRows->missing_shooter ?? 0),
            'Missing result' => (int) ($qaRows->missing_result ?? 0),
            'Missing distance bucket' => (int) ($qaRows->missing_distance_bucket ?? 0),
            'Missing angle bucket' => (int) ($qaRows->missing_angle_bucket ?? 0),
            'Goals not marked SOG' => (int) ($qaRows->goals_not_sog ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function filterOptions(): array
    {
        return [
            'seasons' => DB::table('nhl_shot_attempts_facts')
                ->whereNotNull('season_id')
                ->distinct()
                ->orderByDesc('season_id')
                ->pluck('season_id'),
            'strengthBuckets' => $this->distinctOptions('strength_bucket'),
            'attemptResults' => $this->distinctOptions('attempt_result'),
            'distanceBuckets' => $this->distinctOptions('distance_bucket'),
            'angleBuckets' => $this->distinctOptions('angle_bucket'),
            'shotTypeBuckets' => $this->distinctOptions('shot_type_bucket'),
            'shotSides' => $this->distinctOptions('shot_side'),
        ];
    }

    private function distinctOptions(string $column)
    {
        return DB::table('nhl_shot_attempts_facts')
            ->whereNotNull($column)
            ->distinct()
            ->orderBy($column)
            ->pluck($column);
    }

    private function xgTablesExist(): bool
    {
        return Schema::hasTable('nhl_expected_goals_models')
            && Schema::hasTable('nhl_expected_goals_model_buckets')
            && Schema::hasTable('nhl_shot_attempt_predictions')
            && Schema::hasColumn('nhl_expected_goals_models', 'prediction_target')
            && Schema::hasColumn('nhl_shot_attempt_predictions', 'prediction_target');
    }

    private function latestXgModel(mixed $seasonId, string $predictionTarget): ?object
    {
        $query = DB::table('nhl_expected_goals_models')
            ->where('prediction_target', $predictionTarget);

        if ($seasonId !== null && $seasonId !== '') {
            $query->where('training_season_id', $seasonId);
        }

        return $query
            ->orderByDesc('trained_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return array<string, int|float|string|null>
     */
    private function xgSummary(mixed $seasonId, ?object $latestModel): array
    {
        if ($latestModel === null) {
            return $this->emptyXgSummary();
        }

        $predictionSummary = DB::table('nhl_shot_attempt_predictions')
            ->where('expected_goals_model_id', $latestModel->id)
            ->where('prediction_target', $latestModel->prediction_target)
            ->selectRaw('COUNT(*) as prediction_count')
            ->selectRaw('SUM(CASE WHEN is_scored THEN 1 ELSE 0 END) as scored_count')
            ->selectRaw('SUM(CASE WHEN NOT is_scored THEN 1 ELSE 0 END) as excluded_count')
            ->selectRaw('SUM(xg) as total_xg')
            ->first();

        $modelQuery = DB::table('nhl_expected_goals_models')
            ->where('prediction_target', NhlExpectedGoalsBackfiller::TARGET_GOAL);

        if ($seasonId !== null && $seasonId !== '') {
            $modelQuery->where('training_season_id', $seasonId);
        }

        return [
            'model_count' => (int) $modelQuery->count(),
            'latest_version' => (string) $latestModel->version,
            'latest_status' => (string) $latestModel->status,
            'latest_target' => (string) $latestModel->prediction_target,
            'training_season_id' => (string) $latestModel->training_season_id,
            'bucket_count' => DB::table('nhl_expected_goals_model_buckets')
                ->where('expected_goals_model_id', $latestModel->id)
                ->count(),
            'prediction_count' => (int) ($predictionSummary->prediction_count ?? 0),
            'scored_count' => (int) ($predictionSummary->scored_count ?? 0),
            'excluded_count' => (int) ($predictionSummary->excluded_count ?? 0),
            'total_xg' => round((float) ($predictionSummary->total_xg ?? 0), 2),
        ];
    }

    private function xgModelRows(mixed $seasonId, string $sort, string $direction)
    {
        $bucketCounts = DB::table('nhl_expected_goals_model_buckets')
            ->select('expected_goals_model_id')
            ->selectRaw('COUNT(*) as bucket_count')
            ->groupBy('expected_goals_model_id');

        $predictionCounts = DB::table('nhl_shot_attempt_predictions')
            ->select('expected_goals_model_id')
            ->selectRaw('COUNT(*) as prediction_count')
            ->selectRaw('SUM(CASE WHEN is_scored THEN 1 ELSE 0 END) as scored_count')
            ->selectRaw('SUM(CASE WHEN NOT is_scored THEN 1 ELSE 0 END) as excluded_count')
            ->selectRaw('SUM(xg) as total_xg')
            ->groupBy('expected_goals_model_id');

        $query = DB::table('nhl_expected_goals_models as models')
            ->leftJoinSub($bucketCounts, 'buckets', 'buckets.expected_goals_model_id', '=', 'models.id')
            ->leftJoinSub($predictionCounts, 'predictions', 'predictions.expected_goals_model_id', '=', 'models.id')
            ->select([
                'models.id',
                'models.version',
                'models.prediction_target',
                'models.training_season_id',
                'models.status',
                'models.minimum_bucket_attempts',
                'models.smoothing_prior_attempts',
                'models.trained_at',
            ])
            ->selectRaw('COALESCE(buckets.bucket_count, 0) as bucket_count')
            ->selectRaw('COALESCE(predictions.prediction_count, 0) as prediction_count')
            ->selectRaw('COALESCE(predictions.scored_count, 0) as scored_count')
            ->selectRaw('COALESCE(predictions.excluded_count, 0) as excluded_count')
            ->selectRaw('COALESCE(predictions.total_xg, 0) as total_xg');

        if ($seasonId !== null && $seasonId !== '') {
            $query->where('models.training_season_id', $seasonId);
        }

        return $query
            ->orderBy($this->xgModelSortColumns()[$sort], $direction)
            ->orderByDesc('models.id')
            ->limit(25)
            ->get();
    }

    private function xgBucketRows(mixed $modelId, string $sort, string $direction)
    {
        if ($modelId === null) {
            return collect();
        }

        return DB::table('nhl_expected_goals_model_buckets')
            ->where('expected_goals_model_id', $modelId)
            ->select([
                'bucket_key',
                'fallback_level',
                'bucket_dimensions',
                'attempts',
                'goals',
                'raw_goal_rate',
                'smoothed_goal_probability',
            ])
            ->orderBy($this->xgBucketSortColumns()[$sort], $direction)
            ->orderByDesc('attempts')
            ->limit(75)
            ->get();
    }

    private function xgTeamRows(mixed $modelId, string $sort, string $direction)
    {
        if ($modelId === null) {
            return collect();
        }

        $orderBy = $this->xgTeamSortColumns()[$sort];
        $sql = <<<SQL
WITH team_ids AS (
    SELECT team_id FROM nhl_shot_attempt_predictions WHERE expected_goals_model_id = ? AND team_id IS NOT NULL
    UNION
    SELECT opponent_team_id FROM nhl_shot_attempt_predictions WHERE expected_goals_model_id = ? AND opponent_team_id IS NOT NULL
),
xgf AS (
    SELECT
        predictions.team_id,
        COUNT(*) as attempts_for,
        SUM(predictions.xg) as xg_for,
        SUM(CASE WHEN facts.is_goal THEN 1 ELSE 0 END) as goals_for
    FROM nhl_shot_attempt_predictions predictions
    INNER JOIN nhl_shot_attempts_facts facts ON facts.id = predictions.shot_attempt_fact_id
    WHERE predictions.expected_goals_model_id = ?
        AND predictions.is_scored = true
    GROUP BY predictions.team_id
),
xga AS (
    SELECT
        predictions.opponent_team_id as team_id,
        COUNT(*) as attempts_against,
        SUM(predictions.xg) as xg_against,
        SUM(CASE WHEN facts.is_goal THEN 1 ELSE 0 END) as goals_against
    FROM nhl_shot_attempt_predictions predictions
    INNER JOIN nhl_shot_attempts_facts facts ON facts.id = predictions.shot_attempt_fact_id
    WHERE predictions.expected_goals_model_id = ?
        AND predictions.is_scored = true
    GROUP BY predictions.opponent_team_id
)
SELECT
    COALESCE(teams.abbrev, team_ids.team_id::text) as team_abbrev,
    COALESCE(xgf.attempts_for, 0) as attempts_for,
    COALESCE(xgf.xg_for, 0) as xg_for,
    COALESCE(xgf.goals_for, 0) as goals_for,
    COALESCE(xga.attempts_against, 0) as attempts_against,
    COALESCE(xga.xg_against, 0) as xg_against,
    COALESCE(xga.goals_against, 0) as goals_against,
    COALESCE(xgf.goals_for, 0) - COALESCE(xga.goals_against, 0) as goal_diff,
    COALESCE(xgf.xg_for, 0) - COALESCE(xga.xg_against, 0) as xg_diff
FROM team_ids
LEFT JOIN nhl_teams teams ON teams.nhl_id = team_ids.team_id
LEFT JOIN xgf ON xgf.team_id = team_ids.team_id
LEFT JOIN xga ON xga.team_id = team_ids.team_id
ORDER BY {$orderBy} {$direction}, team_abbrev ASC
SQL;

        return collect(DB::select($sql, [$modelId, $modelId, $modelId, $modelId]));
    }

    private function xgShooterRows(mixed $goalModelId, mixed $sogModelId, string $sort, string $direction)
    {
        if ($goalModelId === null || $sogModelId === null) {
            return collect();
        }

        $orderBy = $this->xgShooterSortColumns()[$sort];
        $sql = <<<SQL
SELECT
    COALESCE(players.full_name, facts.shooter_player_id::text) as shooter_name,
    COALESCE(teams.abbrev, facts.team_id::text) as team_abbrev,
    COUNT(*) as sat,
    SUM(CASE WHEN facts.is_shot_on_goal THEN 1 ELSE 0 END) as sog,
    SUM(CASE WHEN facts.is_goal THEN 1 ELSE 0 END) as goals,
    SUM(goal_predictions.xg) as ixg,
    SUM(sog_predictions.xg) as xsog,
    CASE WHEN COUNT(*) > 0 THEN SUM(goal_predictions.xg) / COUNT(*) ELSE NULL END as xg_per_sat,
    CASE WHEN COUNT(*) > 0 THEN SUM(sog_predictions.xg) / COUNT(*) ELSE NULL END as xsog_per_sat,
    SUM(CASE WHEN facts.is_shot_on_goal THEN 1 ELSE 0 END) - SUM(sog_predictions.xg) as sog_minus_xsog,
    SUM(CASE WHEN facts.is_goal THEN 1 ELSE 0 END) - SUM(goal_predictions.xg) as goals_minus_ixg
FROM nhl_shot_attempts_facts facts
INNER JOIN nhl_shot_attempt_predictions goal_predictions
    ON goal_predictions.shot_attempt_fact_id = facts.id
    AND goal_predictions.expected_goals_model_id = ?
    AND goal_predictions.prediction_target = ?
    AND goal_predictions.is_scored = true
INNER JOIN nhl_shot_attempt_predictions sog_predictions
    ON sog_predictions.shot_attempt_fact_id = facts.id
    AND sog_predictions.expected_goals_model_id = ?
    AND sog_predictions.prediction_target = ?
    AND sog_predictions.is_scored = true
LEFT JOIN players ON players.nhl_id = facts.shooter_player_id
LEFT JOIN nhl_teams teams ON teams.nhl_id = facts.team_id
WHERE facts.shooter_player_id IS NOT NULL
GROUP BY facts.shooter_player_id, players.full_name, facts.team_id, teams.abbrev
HAVING COUNT(*) >= 25
ORDER BY {$orderBy} {$direction}, shooter_name ASC
LIMIT 150
SQL;

        return collect(DB::select($sql, [
            $goalModelId,
            NhlExpectedGoalsBackfiller::TARGET_GOAL,
            $sogModelId,
            NhlExpectedGoalsBackfiller::TARGET_SHOT_ON_GOAL,
        ]));
    }

    private function xgTrendRows(mixed $goalModelId, mixed $sogModelId, string $sort, string $direction)
    {
        if ($goalModelId === null || $sogModelId === null) {
            return collect();
        }

        $orderBy = $this->xgTrendSortColumns()[$sort];
        $sql = <<<SQL
WITH player_games AS (
    SELECT
        facts.shooter_player_id,
        facts.game_date,
        facts.team_id,
        COUNT(*) as sat,
        SUM(goal_predictions.xg) as ixg,
        SUM(sog_predictions.xg) as xsog
    FROM nhl_shot_attempts_facts facts
    INNER JOIN nhl_shot_attempt_predictions goal_predictions
        ON goal_predictions.shot_attempt_fact_id = facts.id
        AND goal_predictions.expected_goals_model_id = ?
        AND goal_predictions.prediction_target = ?
        AND goal_predictions.is_scored = true
    INNER JOIN nhl_shot_attempt_predictions sog_predictions
        ON sog_predictions.shot_attempt_fact_id = facts.id
        AND sog_predictions.expected_goals_model_id = ?
        AND sog_predictions.prediction_target = ?
        AND sog_predictions.is_scored = true
    WHERE facts.shooter_player_id IS NOT NULL
    GROUP BY facts.shooter_player_id, facts.game_date, facts.team_id
),
ranked_games AS (
    SELECT
        player_games.*,
        ROW_NUMBER() OVER (PARTITION BY player_games.shooter_player_id ORDER BY player_games.game_date DESC) as recent_rank
    FROM player_games
),
rollups AS (
    SELECT
        shooter_player_id,
        MAX(team_id) as team_id,
        SUM(sat) FILTER (WHERE recent_rank <= 5) as sat_5,
        SUM(ixg) FILTER (WHERE recent_rank <= 5) as ixg_5,
        SUM(xsog) FILTER (WHERE recent_rank <= 5) as xsog_5,
        SUM(sat) FILTER (WHERE recent_rank <= 10) as sat_10,
        SUM(ixg) FILTER (WHERE recent_rank <= 10) as ixg_10,
        SUM(xsog) FILTER (WHERE recent_rank <= 10) as xsog_10,
        SUM(sat) FILTER (WHERE recent_rank <= 20) as sat_20,
        SUM(ixg) FILTER (WHERE recent_rank <= 20) as ixg_20,
        SUM(xsog) FILTER (WHERE recent_rank <= 20) as xsog_20,
        SUM(sat) as season_sat,
        SUM(ixg) as season_ixg,
        SUM(xsog) as season_xsog
    FROM ranked_games
    GROUP BY shooter_player_id
)
SELECT
    COALESCE(players.full_name, rollups.shooter_player_id::text) as shooter_name,
    COALESCE(teams.abbrev, rollups.team_id::text) as team_abbrev,
    sat_5,
    CASE WHEN sat_5 > 0 THEN ixg_5 / sat_5 ELSE NULL END as xg_per_sat_5,
    CASE WHEN sat_5 > 0 THEN xsog_5 / sat_5 ELSE NULL END as xsog_per_sat_5,
    sat_10,
    CASE WHEN sat_10 > 0 THEN ixg_10 / sat_10 ELSE NULL END as xg_per_sat_10,
    CASE WHEN sat_10 > 0 THEN xsog_10 / sat_10 ELSE NULL END as xsog_per_sat_10,
    sat_20,
    CASE WHEN sat_20 > 0 THEN ixg_20 / sat_20 ELSE NULL END as xg_per_sat_20,
    CASE WHEN sat_20 > 0 THEN xsog_20 / sat_20 ELSE NULL END as xsog_per_sat_20,
    CASE WHEN season_sat > 0 THEN season_ixg / season_sat ELSE NULL END as season_xg_per_sat,
    CASE WHEN season_sat > 0 THEN season_xsog / season_sat ELSE NULL END as season_xsog_per_sat,
    CASE WHEN sat_10 > 0 AND season_sat > 0 THEN (ixg_10 / sat_10) - (season_ixg / season_sat) ELSE NULL END as xg_per_sat_delta,
    CASE WHEN sat_10 > 0 AND season_sat > 0 THEN (xsog_10 / sat_10) - (season_xsog / season_sat) ELSE NULL END as xsog_per_sat_delta
FROM rollups
LEFT JOIN players ON players.nhl_id = rollups.shooter_player_id
LEFT JOIN nhl_teams teams ON teams.nhl_id = rollups.team_id
WHERE season_sat >= 25
ORDER BY {$orderBy} {$direction}, shooter_name ASC
LIMIT 150
SQL;

        return collect(DB::select($sql, [
            $goalModelId,
            NhlExpectedGoalsBackfiller::TARGET_GOAL,
            $sogModelId,
            NhlExpectedGoalsBackfiller::TARGET_SHOT_ON_GOAL,
        ]));
    }

    private function xgProfileRows(mixed $goalModelId, mixed $sogModelId, string $sort, string $direction)
    {
        if ($goalModelId === null || $sogModelId === null) {
            return collect();
        }

        $orderBy = $this->xgProfileSortColumns()[$sort];
        $sql = <<<SQL
WITH player_games AS (
    SELECT DISTINCT facts.shooter_player_id, facts.game_date
    FROM nhl_shot_attempts_facts facts
    INNER JOIN nhl_shot_attempt_predictions goal_predictions
        ON goal_predictions.shot_attempt_fact_id = facts.id
        AND goal_predictions.expected_goals_model_id = ?
        AND goal_predictions.prediction_target = ?
        AND goal_predictions.is_scored = true
    WHERE facts.shooter_player_id IS NOT NULL
),
ranked_games AS (
    SELECT
        player_games.*,
        ROW_NUMBER() OVER (PARTITION BY player_games.shooter_player_id ORDER BY player_games.game_date DESC) as recent_rank
    FROM player_games
),
facts_with_window AS (
    SELECT
        facts.*,
        ranked_games.recent_rank
    FROM nhl_shot_attempts_facts facts
    INNER JOIN ranked_games
        ON ranked_games.shooter_player_id = facts.shooter_player_id
        AND ranked_games.game_date = facts.game_date
    WHERE facts.shooter_player_id IS NOT NULL
),
season_rows AS (
    SELECT
        facts.shooter_player_id,
        MAX(facts.team_id) as team_id,
        COUNT(*) as season_sat,
        AVG(facts.shot_distance) as season_avg_distance,
        AVG(facts.abs_shot_angle) as season_avg_angle,
        AVG(CASE WHEN facts.is_rush THEN 1.0 ELSE 0.0 END) as season_rush_rate,
        AVG(CASE WHEN facts.is_rebound THEN 1.0 ELSE 0.0 END) as season_rebound_rate,
        AVG(CASE WHEN facts.is_off_wing_attempt THEN 1.0 WHEN facts.is_off_wing_attempt = false THEN 0.0 ELSE NULL END) as season_offwing_rate,
        SUM(goal_predictions.xg) / COUNT(*) as season_xg_per_sat,
        SUM(sog_predictions.xg) / COUNT(*) as season_xsog_per_sat
    FROM facts_with_window facts
    INNER JOIN nhl_shot_attempt_predictions goal_predictions
        ON goal_predictions.shot_attempt_fact_id = facts.id
        AND goal_predictions.expected_goals_model_id = ?
        AND goal_predictions.prediction_target = ?
        AND goal_predictions.is_scored = true
    INNER JOIN nhl_shot_attempt_predictions sog_predictions
        ON sog_predictions.shot_attempt_fact_id = facts.id
        AND sog_predictions.expected_goals_model_id = ?
        AND sog_predictions.prediction_target = ?
        AND sog_predictions.is_scored = true
    GROUP BY facts.shooter_player_id
),
recent_rows AS (
    SELECT
        facts.shooter_player_id,
        COUNT(*) as recent_sat,
        AVG(facts.shot_distance) as recent_avg_distance,
        AVG(facts.abs_shot_angle) as recent_avg_angle,
        AVG(CASE WHEN facts.is_rush THEN 1.0 ELSE 0.0 END) as recent_rush_rate,
        AVG(CASE WHEN facts.is_rebound THEN 1.0 ELSE 0.0 END) as recent_rebound_rate,
        AVG(CASE WHEN facts.is_off_wing_attempt THEN 1.0 WHEN facts.is_off_wing_attempt = false THEN 0.0 ELSE NULL END) as recent_offwing_rate,
        SUM(goal_predictions.xg) / COUNT(*) as recent_xg_per_sat,
        SUM(sog_predictions.xg) / COUNT(*) as recent_xsog_per_sat
    FROM facts_with_window facts
    INNER JOIN nhl_shot_attempt_predictions goal_predictions
        ON goal_predictions.shot_attempt_fact_id = facts.id
        AND goal_predictions.expected_goals_model_id = ?
        AND goal_predictions.prediction_target = ?
        AND goal_predictions.is_scored = true
    INNER JOIN nhl_shot_attempt_predictions sog_predictions
        ON sog_predictions.shot_attempt_fact_id = facts.id
        AND sog_predictions.expected_goals_model_id = ?
        AND sog_predictions.prediction_target = ?
        AND sog_predictions.is_scored = true
    WHERE facts.recent_rank <= 10
    GROUP BY facts.shooter_player_id
)
SELECT
    COALESCE(players.full_name, season_rows.shooter_player_id::text) as shooter_name,
    COALESCE(teams.abbrev, season_rows.team_id::text) as team_abbrev,
    season_rows.season_sat,
    recent_rows.recent_sat,
    recent_rows.recent_xg_per_sat,
    season_rows.season_xg_per_sat,
    recent_rows.recent_xsog_per_sat,
    season_rows.season_xsog_per_sat,
    recent_rows.recent_avg_distance - season_rows.season_avg_distance as avg_distance_delta,
    recent_rows.recent_avg_angle - season_rows.season_avg_angle as avg_angle_delta,
    recent_rows.recent_rush_rate - season_rows.season_rush_rate as rush_rate_delta,
    recent_rows.recent_rebound_rate - season_rows.season_rebound_rate as rebound_rate_delta,
    recent_rows.recent_offwing_rate - season_rows.season_offwing_rate as offwing_rate_delta
FROM season_rows
INNER JOIN recent_rows ON recent_rows.shooter_player_id = season_rows.shooter_player_id
LEFT JOIN players ON players.nhl_id = season_rows.shooter_player_id
LEFT JOIN nhl_teams teams ON teams.nhl_id = season_rows.team_id
WHERE season_rows.season_sat >= 25
ORDER BY {$orderBy} {$direction}, shooter_name ASC
LIMIT 150
SQL;

        return collect(DB::select($sql, [
            $goalModelId,
            NhlExpectedGoalsBackfiller::TARGET_GOAL,
            $goalModelId,
            NhlExpectedGoalsBackfiller::TARGET_GOAL,
            $sogModelId,
            NhlExpectedGoalsBackfiller::TARGET_SHOT_ON_GOAL,
            $goalModelId,
            NhlExpectedGoalsBackfiller::TARGET_GOAL,
            $sogModelId,
            NhlExpectedGoalsBackfiller::TARGET_SHOT_ON_GOAL,
        ]));
    }

    /**
     * @return array<string, string>
     */
    private function xgModelSortColumns(): array
    {
        return [
            'version' => 'models.version',
            'prediction_target' => 'models.prediction_target',
            'training_season_id' => 'models.training_season_id',
            'status' => 'models.status',
            'bucket_count' => 'bucket_count',
            'prediction_count' => 'prediction_count',
            'scored_count' => 'scored_count',
            'excluded_count' => 'excluded_count',
            'total_xg' => 'total_xg',
            'trained_at' => 'models.trained_at',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function xgBucketSortColumns(): array
    {
        return [
            'bucket_key' => 'bucket_key',
            'fallback_level' => 'fallback_level',
            'attempts' => 'attempts',
            'goals' => 'goals',
            'raw_goal_rate' => 'raw_goal_rate',
            'smoothed_goal_probability' => 'smoothed_goal_probability',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function xgTeamSortColumns(): array
    {
        return [
            'team_abbrev' => 'team_abbrev',
            'attempts_for' => 'attempts_for',
            'xg_for' => 'xg_for',
            'goals_for' => 'goals_for',
            'attempts_against' => 'attempts_against',
            'xg_against' => 'xg_against',
            'goals_against' => 'goals_against',
            'goal_diff' => 'goal_diff',
            'xg_diff' => 'xg_diff',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function xgShooterSortColumns(): array
    {
        return [
            'shooter_name' => 'shooter_name',
            'team_abbrev' => 'team_abbrev',
            'sat' => 'sat',
            'sog' => 'sog',
            'goals' => 'goals',
            'ixg' => 'ixg',
            'xsog' => 'xsog',
            'xg_per_sat' => 'xg_per_sat',
            'xsog_per_sat' => 'xsog_per_sat',
            'sog_minus_xsog' => 'sog_minus_xsog',
            'goals_minus_ixg' => 'goals_minus_ixg',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function xgTrendSortColumns(): array
    {
        return [
            'shooter_name' => 'shooter_name',
            'team_abbrev' => 'team_abbrev',
            'sat_5' => 'sat_5',
            'xg_per_sat_5' => 'xg_per_sat_5',
            'xsog_per_sat_5' => 'xsog_per_sat_5',
            'sat_10' => 'sat_10',
            'xg_per_sat_10' => 'xg_per_sat_10',
            'xsog_per_sat_10' => 'xsog_per_sat_10',
            'sat_20' => 'sat_20',
            'xg_per_sat_20' => 'xg_per_sat_20',
            'xsog_per_sat_20' => 'xsog_per_sat_20',
            'xg_per_sat_delta' => 'xg_per_sat_delta',
            'xsog_per_sat_delta' => 'xsog_per_sat_delta',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function xgProfileSortColumns(): array
    {
        return [
            'shooter_name' => 'shooter_name',
            'team_abbrev' => 'team_abbrev',
            'season_sat' => 'season_sat',
            'recent_sat' => 'recent_sat',
            'recent_xg_per_sat' => 'recent_xg_per_sat',
            'recent_xsog_per_sat' => 'recent_xsog_per_sat',
            'avg_distance_delta' => 'avg_distance_delta',
            'avg_angle_delta' => 'avg_angle_delta',
            'rush_rate_delta' => 'rush_rate_delta',
            'rebound_rate_delta' => 'rebound_rate_delta',
            'offwing_rate_delta' => 'offwing_rate_delta',
        ];
    }

    /**
     * @return array<string, int|null>
     */
    private function emptySummary(): array
    {
        return [
            'attempts' => 0,
            'unblocked_attempts' => 0,
            'shots_on_goal' => 0,
            'goals' => 0,
            'rebounds' => 0,
            'rushes' => 0,
            'missing_geometry' => 0,
            'goal_rate' => null,
        ];
    }

    /**
     * @return array<string, int|float|string|null>
     */
    private function emptyXgSummary(): array
    {
        return [
            'model_count' => 0,
            'latest_version' => null,
            'latest_status' => null,
            'latest_target' => null,
            'training_season_id' => null,
            'bucket_count' => 0,
            'prediction_count' => 0,
            'scored_count' => 0,
            'excluded_count' => 0,
            'total_xg' => 0.0,
        ];
    }

    private function emptyPaginator(): LengthAwarePaginator
    {
        return new LengthAwarePaginator([], 0, 50);
    }

    private function emptyQaRows(): object
    {
        return (object) [
            'attempts' => 0,
            'missing_geometry' => 0,
            'missing_team' => 0,
            'missing_shooter' => 0,
            'missing_result' => 0,
            'missing_distance_bucket' => 0,
            'missing_angle_bucket' => 0,
            'goals_not_sog' => 0,
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function emptyOptions(): array
    {
        return [
            'seasons' => [],
            'strengthBuckets' => [],
            'attemptResults' => [],
            'distanceBuckets' => [],
            'angleBuckets' => [],
            'shotTypeBuckets' => [],
            'shotSides' => [],
        ];
    }
}
