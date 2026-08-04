<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\BackfillNhlExpectedGoalsJob;
use App\Jobs\BuildNhlGoalieChanceProfilesJob;
use App\Jobs\BuildNhlGoalieProjectionsJob;
use App\Jobs\BuildNhlGoalieWorkloadProjectionsJob;
use App\Jobs\BuildNhlPlayerProjectionsJob;
use App\Jobs\BuildNhlPlayerToiProjectionsJob;
use App\Jobs\BuildNhlSkaterDefensiveChanceProfilesJob;
use App\Jobs\BuildNhlSkaterOffensiveChanceProfilesJob;
use App\Models\NhlExpectedGoalsModel;
use App\Services\NhlExpectedGoalsBackfiller;
use App\Services\NhlGoalieProjectionBuilder;
use App\Services\NhlGoalieWorkloadProjectionBuilder;
use App\Services\NhlPlayerProjectionBuilder;
use App\Services\NhlPlayerToiProjectionBuilder;
use App\Services\NhlProjectedTeamMatchupSimulator;
use App\Services\NhlShotAttemptAnalysisBuckets;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/**
 * Admin analysis panel for NHL shot-attempt facts.
 */
class NhlShotAttemptController extends Controller
{
    private const ANALYSIS_BUCKET_MIN_ATTEMPTS = 300;

    /**
     * Render the shot-attempt analysis panel.
     */
    public function index(Request $request): View
    {
        $input = $request->validate([
            'tab' => ['nullable', Rule::in(['explorer', 'aggregates', 'buckets', 'predictive', 'biometrics', 'player-profiles', 'skater-o-profiles', 'g-sat-profiles', 'skater-d-profiles', 'xg', 'projections', 'matchup', 'qa'])],
            'season_id' => ['nullable', 'digits:8'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'game_type' => ['nullable', 'integer', 'min:1', 'max:99'],
            'team_id' => ['nullable', 'integer'],
            'strength_bucket' => ['nullable', 'string', 'max:32'],
            'attempt_result' => ['nullable', 'string', 'max:32'],
            'distance_bucket' => ['nullable', 'string', 'max:32'],
            'angle_bucket' => ['nullable', 'string', 'max:32'],
            'shot_type_bucket' => ['nullable', 'string', 'max:32'],
            'shot_side' => ['nullable', Rule::in(['left', 'right', 'center', 'unknown'])],
            'is_off_wing_attempt' => ['nullable', Rule::in(['1', '0'])],
            'player_search' => ['nullable', 'string', 'max:120'],
            'position' => ['nullable', 'string', 'max:20'],
            'predictive_group' => ['nullable', Rule::in(array_keys($this->predictiveGroupDefinitions()))],
            'min_attempts' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'biometric_min_attempts' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'profile_min_attempts' => ['nullable', 'integer', 'min:1', 'max:10000'],
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
            'profile_sort' => ['nullable', Rule::in(array_keys($this->playerProfileSortColumns()))],
            'profile_direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'profile_bucket_sort' => ['nullable', Rule::in(array_keys($this->playerProfileBucketSortColumns()))],
            'profile_bucket_direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'goalie_profile_season_id' => ['nullable', 'digits:8'],
            'goalie_profile_game_type' => ['nullable', 'integer', 'min:1', 'max:99'],
            'goalie_profile_team_abbrev' => ['nullable', 'string', 'max:12'],
            'goalie_profile_goalie_search' => ['nullable', 'string', 'max:120'],
            'goalie_profile_shot_type_group' => ['nullable', 'string', 'max:32'],
            'goalie_profile_distance_group' => ['nullable', 'string', 'max:32'],
            'goalie_profile_angle_group' => ['nullable', 'string', 'max:32'],
            'goalie_profile_sequence_group' => ['nullable', 'string', 'max:32'],
            'goalie_profile_min_sat_against' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'goalie_profile_sort' => ['nullable', Rule::in(array_keys($this->goalieProfileSortColumns()))],
            'goalie_profile_direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'skater_o_profile_season_id' => ['nullable', 'digits:8'],
            'skater_o_profile_game_type' => ['nullable', 'integer', 'min:1', 'max:99'],
            'skater_o_profile_team_abbrev' => ['nullable', 'string', 'max:12'],
            'skater_o_profile_position' => ['nullable', 'string', 'max:12'],
            'skater_o_profile_player_search' => ['nullable', 'string', 'max:120'],
            'skater_o_profile_shot_type_group' => ['nullable', 'string', 'max:32'],
            'skater_o_profile_distance_group' => ['nullable', 'string', 'max:32'],
            'skater_o_profile_angle_group' => ['nullable', 'string', 'max:32'],
            'skater_o_profile_sequence_group' => ['nullable', 'string', 'max:32'],
            'skater_o_profile_min_sat_for' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'skater_o_profile_sort' => ['nullable', Rule::in(array_keys($this->skaterOProfileSortColumns()))],
            'skater_o_profile_direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'skater_d_profile_season_id' => ['nullable', 'digits:8'],
            'skater_d_profile_game_type' => ['nullable', 'integer', 'min:1', 'max:99'],
            'skater_d_profile_team_abbrev' => ['nullable', 'string', 'max:12'],
            'skater_d_profile_position' => ['nullable', 'string', 'max:12'],
            'skater_d_profile_player_search' => ['nullable', 'string', 'max:120'],
            'skater_d_profile_shot_type_group' => ['nullable', 'string', 'max:32'],
            'skater_d_profile_distance_group' => ['nullable', 'string', 'max:32'],
            'skater_d_profile_angle_group' => ['nullable', 'string', 'max:32'],
            'skater_d_profile_sequence_group' => ['nullable', 'string', 'max:32'],
            'skater_d_profile_min_sat_against' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'skater_d_profile_sort' => ['nullable', Rule::in(array_keys($this->skaterDProfileSortColumns()))],
            'skater_d_profile_direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'projection_source_season_id' => ['nullable', 'digits:8'],
            'projection_target_season_id' => ['nullable', 'digits:8'],
            'projection_version' => ['nullable', 'string', 'max:80'],
            'projection_status' => ['nullable', Rule::in(['draft', 'published', 'archived'])],
            'projection_team_abbrev' => ['nullable', 'string', 'max:12'],
            'projection_position' => ['nullable', 'string', 'max:12'],
            'projection_player_search' => ['nullable', 'string', 'max:120'],
            'projection_min_xsat' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'projection_sort' => ['nullable', Rule::in(array_keys($this->projectionSortColumns()))],
            'projection_direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'projection_bucket_sort' => ['nullable', Rule::in(array_keys($this->projectionBucketSortColumns()))],
            'projection_bucket_direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'toi_projection_sort' => ['nullable', Rule::in(array_keys($this->toiProjectionSortColumns()))],
            'toi_projection_direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'goalie_workload_target_season_id' => ['nullable', 'digits:8'],
            'goalie_workload_version' => ['nullable', 'string', 'max:80'],
            'goalie_workload_status' => ['nullable', Rule::in(['draft', 'published', 'archived'])],
            'goalie_workload_team_abbrev' => ['nullable', 'string', 'max:12'],
            'goalie_workload_goalie_search' => ['nullable', 'string', 'max:120'],
            'goalie_workload_min_career_gp' => ['nullable', 'integer', 'min:0', 'max:2000'],
            'goalie_workload_sort' => ['nullable', Rule::in(array_keys($this->goalieWorkloadSortColumns()))],
            'goalie_workload_direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'goalie_projection_target_season_id' => ['nullable', 'digits:8'],
            'goalie_projection_version' => ['nullable', 'string', 'max:80'],
            'goalie_projection_status' => ['nullable', Rule::in(['draft', 'published', 'archived'])],
            'goalie_projection_team_abbrev' => ['nullable', 'string', 'max:12'],
            'goalie_projection_goalie_search' => ['nullable', 'string', 'max:120'],
            'goalie_projection_min_projected_starts' => ['nullable', 'numeric', 'min:0', 'max:84'],
            'goalie_projection_sort' => ['nullable', Rule::in(array_keys($this->goalieProjectionSortColumns()))],
            'goalie_projection_direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'matchup_source_season_id' => ['nullable', 'digits:8'],
            'matchup_target_season_id' => ['nullable', 'digits:8'],
            'matchup_projection_version' => ['nullable', 'string', 'max:80'],
            'matchup_toi_projection_version' => ['nullable', 'string', 'max:80'],
            'matchup_goalie_projection_version' => ['nullable', 'string', 'max:80'],
            'matchup_team_a' => ['nullable', 'string', 'max:12'],
            'matchup_team_b' => ['nullable', 'string', 'max:12'],
            'matchup_team_a_goalie_id' => ['nullable', 'integer'],
            'matchup_team_b_goalie_id' => ['nullable', 'integer'],
        ]);

        $tab = (string) ($input['tab'] ?? 'explorer');
        $filters = $this->filters($input);
        $tableExists = Schema::hasTable('nhl_shot_attempts_facts');
        $xgTableExists = $this->xgTablesExist();
        $projectionTablesExist = $this->projectionTablesExist();
        $toiProjectionTableExists = $this->toiProjectionTableExists();
        $goalieWorkloadProjectionTableExists = $this->goalieWorkloadProjectionTableExists();
        $goalieProjectionTablesExist = $this->goalieProjectionTablesExist();
        $goalieProjectionBuildReady = $this->goalieProjectionBuildReady();
        $goalieProfileTableExists = $this->goalieProfileTableExists();
        $skaterOProfileTableExists = $this->skaterOProfileTableExists();
        $skaterDProfileTableExists = $this->skaterDProfileTableExists();
        $projectionFilters = $this->projectionFilters($input);
        $goalieWorkloadFilters = $this->goalieWorkloadFilters($input);
        $goalieProjectionFilters = $this->goalieProjectionFilters($input);
        $matchupFilters = $this->matchupFilters($input);
        $matchupFilters = $this->matchupFiltersWithDefaultGoalies($matchupFilters);
        $goalieProfileFilters = $this->goalieProfileFilters($input);
        $skaterOProfileFilters = $this->skaterOProfileFilters($input);
        $skaterDProfileFilters = $this->skaterDProfileFilters($input);
        $predictiveGroup = (string) ($input['predictive_group'] ?? 'distance_shot_type');
        $minAttempts = (int) ($input['min_attempts'] ?? 300);
        $biometricMinAttempts = (int) ($input['biometric_min_attempts'] ?? 300);
        $profileMinAttempts = (int) ($input['profile_min_attempts'] ?? 25);
        $sort = $this->sortKey($tab, (string) ($input['sort'] ?? ''));
        $direction = $this->sortDirection((string) ($input['direction'] ?? ''));
        $profileSort = (string) ($input['profile_sort'] ?? 'attempts');
        $profileDirection = $this->sortDirection((string) ($input['profile_direction'] ?? ''));
        $profileBucketSort = (string) ($input['profile_bucket_sort'] ?? 'attempts');
        $profileBucketDirection = $this->sortDirection((string) ($input['profile_bucket_direction'] ?? ''));
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
        $projectionSort = (string) ($input['projection_sort'] ?? 'projected_xgf');
        $projectionDirection = $this->sortDirection((string) ($input['projection_direction'] ?? ''));
        $projectionBucketSort = (string) ($input['projection_bucket_sort'] ?? 'projected_xgf');
        $projectionBucketDirection = $this->sortDirection((string) ($input['projection_bucket_direction'] ?? ''));
        $toiProjectionSort = (string) ($input['toi_projection_sort'] ?? 'projected_toi_per_game_seconds');
        $toiProjectionDirection = $this->sortDirection((string) ($input['toi_projection_direction'] ?? ''));
        $goalieWorkloadSort = (string) ($input['goalie_workload_sort'] ?? 'projected_starts');
        $goalieWorkloadDirection = $this->sortDirection((string) ($input['goalie_workload_direction'] ?? ''));
        $goalieProjectionSort = (string) ($input['goalie_projection_sort'] ?? 'projected_starts');
        $goalieProjectionDirection = $this->sortDirection((string) ($input['goalie_projection_direction'] ?? ''));
        $goalieProfileSort = (string) ($input['goalie_profile_sort'] ?? 'source_xga');
        $goalieProfileDirection = $this->sortDirection((string) ($input['goalie_profile_direction'] ?? ''));
        $skaterOProfileSort = (string) ($input['skater_o_profile_sort'] ?? 'source_xgf');
        $skaterOProfileDirection = $this->sortDirection((string) ($input['skater_o_profile_direction'] ?? ''));
        $skaterDProfileSort = (string) ($input['skater_d_profile_sort'] ?? 'source_xga_on_ice');
        $skaterDProfileDirection = $this->sortDirection((string) ($input['skater_d_profile_direction'] ?? ''));
        $latestXgModel = $xgTableExists ? $this->latestXgModel($filters['season_id'], NhlExpectedGoalsBackfiller::TARGET_GOAL) : null;
        $latestXsogModel = $xgTableExists ? $this->latestXgModel($filters['season_id'], NhlExpectedGoalsBackfiller::TARGET_SHOT_ON_GOAL) : null;
        $projectionRows = $projectionTablesExist && $tab === 'projections'
            ? $this->projectionRows($projectionFilters, $projectionSort, $projectionDirection)
            : collect();
        $toiProjectionRows = $toiProjectionTableExists && $tab === 'projections'
            ? $this->toiProjectionRows($projectionFilters, $toiProjectionSort, $toiProjectionDirection)
            : collect();
        $goalieWorkloadRows = $goalieWorkloadProjectionTableExists && $tab === 'projections'
            ? $this->goalieWorkloadRows($goalieWorkloadFilters, $goalieWorkloadSort, $goalieWorkloadDirection)
            : collect();
        $goalieProjectionRows = $goalieProjectionTablesExist && $tab === 'projections'
            ? $this->goalieProjectionRows($goalieProjectionFilters, $goalieProjectionSort, $goalieProjectionDirection)
            : collect();
        $goalieProfileRows = $goalieProfileTableExists && $tab === 'g-sat-profiles'
            ? $this->goalieProfileRows($goalieProfileFilters, $goalieProfileSort, $goalieProfileDirection)
            : collect();
        $skaterOProfileRows = $skaterOProfileTableExists && $tab === 'skater-o-profiles'
            ? $this->skaterOProfileRows($skaterOProfileFilters, $skaterOProfileSort, $skaterOProfileDirection)
            : collect();
        $skaterDProfileRows = $skaterDProfileTableExists && $tab === 'skater-d-profiles'
            ? $this->skaterDProfileRows($skaterDProfileFilters, $skaterDProfileSort, $skaterDProfileDirection)
            : collect();
        $matchupResult = $tab === 'matchup' && $this->matchupReady($matchupFilters)
            ? app(NhlProjectedTeamMatchupSimulator::class)->simulate(
                (string) $matchupFilters['source_season_id'],
                (string) $matchupFilters['target_season_id'],
                (string) $matchupFilters['projection_version'],
                (string) $matchupFilters['toi_projection_version'],
                (string) ($matchupFilters['goalie_projection_version'] ?? ''),
                (string) $matchupFilters['team_a'],
                (string) $matchupFilters['team_b'],
                $matchupFilters['team_a_goalie_id'] === null || $matchupFilters['team_a_goalie_id'] === ''
                    ? null
                    : (int) $matchupFilters['team_a_goalie_id'],
                $matchupFilters['team_b_goalie_id'] === null || $matchupFilters['team_b_goalie_id'] === ''
                    ? null
                    : (int) $matchupFilters['team_b_goalie_id']
            )
            : null;

        return view('admin.nhl-shot-attempts.index', [
            'activeTab' => $tab,
            'filters' => $filters,
            'tableExists' => $tableExists,
            'xgTableExists' => $xgTableExists,
            'projectionTablesExist' => $projectionTablesExist,
            'toiProjectionTableExists' => $toiProjectionTableExists,
            'goalieWorkloadProjectionTableExists' => $goalieWorkloadProjectionTableExists,
            'goalieProjectionTablesExist' => $goalieProjectionTablesExist,
            'goalieProjectionBuildReady' => $goalieProjectionBuildReady,
            'goalieProfileTableExists' => $goalieProfileTableExists,
            'skaterOProfileTableExists' => $skaterOProfileTableExists,
            'skaterDProfileTableExists' => $skaterDProfileTableExists,
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
                ? $this->biometricRows($filters, $latestXgModel?->id, $biometricMinAttempts, $sort, $direction)
                : collect(),
            'playerProfileRows' => $tableExists && $tab === 'player-profiles'
                ? $this->playerProfileRows(
                    $filters,
                    $latestXgModel?->id,
                    $latestXsogModel?->id,
                    $profileMinAttempts,
                    $profileSort,
                    $profileDirection
                )
                : collect(),
            'playerProfileBucketRows' => $tableExists && $tab === 'player-profiles'
                ? $this->playerProfileBucketRows(
                    $filters,
                    $latestXgModel?->id,
                    $latestXsogModel?->id,
                    $profileMinAttempts,
                    $profileBucketSort,
                    $profileBucketDirection
                )
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
            'projectionRows' => $projectionRows,
            'toiProjectionRows' => $toiProjectionRows,
            'goalieWorkloadRows' => $goalieWorkloadRows,
            'goalieProjectionRows' => $goalieProjectionRows,
            'projectionBucketRowsByProjection' => $projectionTablesExist && $tab === 'projections'
                ? $this->projectionBucketRowsByProjection($projectionRows, $projectionBucketSort, $projectionBucketDirection)
                : collect(),
            'goalieProfileRows' => $goalieProfileRows,
            'goalieProfileFilters' => $goalieProfileFilters,
            'goalieProfileOptions' => $goalieProfileTableExists ? $this->goalieProfileOptions() : $this->emptyGoalieProfileOptions(),
            'goalieProfileSort' => $goalieProfileSort,
            'goalieProfileDirection' => $goalieProfileDirection,
            'skaterOProfileRows' => $skaterOProfileRows,
            'skaterOProfileFilters' => $skaterOProfileFilters,
            'skaterOProfileOptions' => $skaterOProfileTableExists ? $this->skaterOProfileOptions() : $this->emptySkaterOProfileOptions(),
            'skaterOProfileSort' => $skaterOProfileSort,
            'skaterOProfileDirection' => $skaterOProfileDirection,
            'skaterDProfileRows' => $skaterDProfileRows,
            'skaterDProfileFilters' => $skaterDProfileFilters,
            'skaterDProfileOptions' => $skaterDProfileTableExists ? $this->skaterDProfileOptions() : $this->emptySkaterDProfileOptions(),
            'skaterDProfileSort' => $skaterDProfileSort,
            'skaterDProfileDirection' => $skaterDProfileDirection,
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
            'projectionFilters' => $projectionFilters,
            'projectionOptions' => ($projectionTablesExist || $toiProjectionTableExists) ? $this->projectionOptions() : $this->emptyProjectionOptions(),
            'projectionSort' => $projectionSort,
            'projectionDirection' => $projectionDirection,
            'projectionBucketSort' => $projectionBucketSort,
            'projectionBucketDirection' => $projectionBucketDirection,
            'toiProjectionSort' => $toiProjectionSort,
            'toiProjectionDirection' => $toiProjectionDirection,
            'goalieWorkloadFilters' => $goalieWorkloadFilters,
            'goalieWorkloadOptions' => $goalieWorkloadProjectionTableExists ? $this->goalieWorkloadOptions() : $this->emptyGoalieWorkloadOptions(),
            'goalieWorkloadSort' => $goalieWorkloadSort,
            'goalieWorkloadDirection' => $goalieWorkloadDirection,
            'goalieProjectionFilters' => $goalieProjectionFilters,
            'goalieProjectionOptions' => $goalieProjectionTablesExist ? $this->goalieProjectionOptions() : $this->emptyGoalieProjectionOptions(),
            'goalieProjectionSort' => $goalieProjectionSort,
            'goalieProjectionDirection' => $goalieProjectionDirection,
            'matchupFilters' => $matchupFilters,
            'matchupOptions' => $this->matchupOptions($matchupFilters),
            'matchupResult' => $matchupResult,
            'groupBy' => (string) ($input['group_by'] ?? 'team_abbrev'),
            'predictiveGroups' => $this->predictiveGroupDefinitions(),
            'predictiveGroup' => $predictiveGroup,
            'minAttempts' => $minAttempts,
            'biometricMinAttempts' => $biometricMinAttempts,
            'profileMinAttempts' => $profileMinAttempts,
            'profileSort' => $profileSort,
            'profileDirection' => $profileDirection,
            'profileBucketSort' => $profileBucketSort,
            'profileBucketDirection' => $profileBucketDirection,
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
     * Queue a first-pass player projection build.
     */
    public function buildProjections(Request $request): RedirectResponse
    {
        $input = $request->validate([
            'source_season_id' => ['required', 'digits:8'],
            'target_season_id' => ['required', 'digits:8'],
            'version' => ['nullable', 'string', 'max:80'],
        ]);

        if (!$this->projectionTablesExist()) {
            return redirect()
                ->route('admin.nhl-shot-attempts.index', ['tab' => 'projections'])
                ->with('error', 'Run the player projection migration before building projections.');
        }

        if (!$this->xgTablesExist()) {
            return redirect()
                ->route('admin.nhl-shot-attempts.index', ['tab' => 'projections'])
                ->with('error', 'Build xG and xSOG models before building player projections.');
        }

        $sourceSeasonId = (string) $input['source_season_id'];
        $targetSeasonId = (string) $input['target_season_id'];
        $version = (string) (($input['version'] ?? null) ?: app(NhlPlayerProjectionBuilder::class)->defaultVersion($targetSeasonId));

        BuildNhlPlayerProjectionsJob::dispatch($sourceSeasonId, $targetSeasonId, $version);

        return redirect()
            ->route('admin.nhl-shot-attempts.index', [
                'tab' => 'projections',
                'projection_source_season_id' => $sourceSeasonId,
                'projection_target_season_id' => $targetSeasonId,
            ])
            ->with('status', sprintf(
                'Queued player projections %s from %s to %s.',
                $version,
                $sourceSeasonId,
                $targetSeasonId
            ));
    }

    /**
     * Queue a first-pass player TOI projection build.
     */
    public function buildToiProjections(Request $request): RedirectResponse
    {
        $input = $request->validate([
            'source_season_id' => ['required', 'digits:8'],
            'target_season_id' => ['required', 'digits:8'],
            'version' => ['nullable', 'string', 'max:80'],
        ]);

        if (!$this->toiProjectionTableExists()) {
            return redirect()
                ->route('admin.nhl-shot-attempts.index', ['tab' => 'projections'])
                ->with('error', 'Run the TOI projection migration before building TOI projections.');
        }

        $sourceSeasonId = (string) $input['source_season_id'];
        $targetSeasonId = (string) $input['target_season_id'];
        $version = (string) (($input['version'] ?? null) ?: app(NhlPlayerToiProjectionBuilder::class)->defaultVersion($targetSeasonId));

        BuildNhlPlayerToiProjectionsJob::dispatch($sourceSeasonId, $targetSeasonId, $version);

        return redirect()
            ->route('admin.nhl-shot-attempts.index', [
                'tab' => 'projections',
                'projection_source_season_id' => $sourceSeasonId,
                'projection_target_season_id' => $targetSeasonId,
            ])
            ->with('status', sprintf(
                'Queued player TOI projections %s from %s to %s.',
                $version,
                $sourceSeasonId,
                $targetSeasonId
            ));
    }

    /**
     * Queue a first-pass goalie workload projection build.
     */
    public function buildGoalieWorkloadProjections(Request $request): RedirectResponse
    {
        $input = $request->validate([
            'source_season_id' => ['required', 'digits:8'],
            'target_season_id' => ['required', 'digits:8'],
            'version' => ['nullable', 'string', 'max:80'],
        ]);

        if (!$this->goalieWorkloadProjectionTableExists()) {
            return redirect()
                ->route('admin.nhl-shot-attempts.index', ['tab' => 'projections'])
                ->with('error', 'Run the goalie workload projection migration before building goalie projections.');
        }

        $sourceSeasonId = (string) $input['source_season_id'];
        $targetSeasonId = (string) $input['target_season_id'];
        $version = (string) (($input['version'] ?? null) ?: app(NhlGoalieWorkloadProjectionBuilder::class)->defaultVersion($targetSeasonId));

        BuildNhlGoalieWorkloadProjectionsJob::dispatch($sourceSeasonId, $targetSeasonId, $version);

        return redirect()
            ->route('admin.nhl-shot-attempts.index', [
                'tab' => 'projections',
                'projection_source_season_id' => $sourceSeasonId,
                'projection_target_season_id' => $targetSeasonId,
            ])
            ->with('status', sprintf(
                'Queued goalie workload projections %s from %s to %s.',
                $version,
                $sourceSeasonId,
                $targetSeasonId
            ));
    }

    /**
     * Queue a first-pass goalie projection build.
     */
    public function buildGoalieProjections(Request $request): RedirectResponse
    {
        $input = $request->validate([
            'source_season_id' => ['required', 'digits:8'],
            'target_season_id' => ['required', 'digits:8'],
            'goalie_workload_projection_version' => ['required', 'string', 'max:80'],
            'toi_projection_version' => ['required', 'string', 'max:80'],
            'version' => ['nullable', 'string', 'max:80'],
        ]);

        if (!$this->goalieProjectionBuildReady()) {
            return redirect()
                ->route('admin.nhl-shot-attempts.index', ['tab' => 'projections'])
                ->with('error', 'Run the goalie projection migrations before building goalie projections.');
        }

        $sourceSeasonId = (string) $input['source_season_id'];
        $targetSeasonId = (string) $input['target_season_id'];
        $goalieWorkloadProjectionVersion = (string) $input['goalie_workload_projection_version'];
        $toiProjectionVersion = (string) $input['toi_projection_version'];
        $builder = app(NhlGoalieProjectionBuilder::class);
        $version = (string) (($input['version'] ?? null) ?: $builder->defaultVersion($targetSeasonId));

        try {
            $builder->prepareBuild(
                $sourceSeasonId,
                $targetSeasonId,
                $goalieWorkloadProjectionVersion,
                $toiProjectionVersion,
                $version
            );
        } catch (\RuntimeException $exception) {
            return redirect()
                ->route('admin.nhl-shot-attempts.index', [
                    'tab' => 'projections',
                    'projection_source_season_id' => $sourceSeasonId,
                    'projection_target_season_id' => $targetSeasonId,
                    'goalie_workload_version' => $goalieWorkloadProjectionVersion,
                ])
                ->with('error', $exception->getMessage());
        }

        BuildNhlGoalieProjectionsJob::dispatch(
            $sourceSeasonId,
            $targetSeasonId,
            $goalieWorkloadProjectionVersion,
            $toiProjectionVersion,
            $version
        );

        return redirect()
            ->route('admin.nhl-shot-attempts.index', [
                'tab' => 'projections',
                'projection_source_season_id' => $sourceSeasonId,
                'projection_target_season_id' => $targetSeasonId,
                'goalie_workload_version' => $goalieWorkloadProjectionVersion,
            ])
            ->with('status', sprintf(
                'Queued goalie projections %s from %s to %s.',
                $version,
                $sourceSeasonId,
                $targetSeasonId
            ));
    }

    /**
     * Queue a historical goalie chance-profile build.
     */
    public function buildGoalieChanceProfiles(Request $request): RedirectResponse
    {
        $input = $request->validate([
            'source_season_id' => ['required', 'digits:8'],
            'game_type' => ['nullable', 'integer', 'min:1', 'max:99'],
        ]);

        if (!$this->goalieProfileTableExists()) {
            return redirect()
                ->route('admin.nhl-shot-attempts.index', ['tab' => 'g-sat-profiles'])
                ->with('error', 'Run the goalie chance profile migration before building goalie SAT profiles.');
        }

        if (!$this->xgTablesExist()) {
            return redirect()
                ->route('admin.nhl-shot-attempts.index', ['tab' => 'g-sat-profiles'])
                ->with('error', 'Build xG and xSOG models before building goalie SAT profiles.');
        }

        $sourceSeasonId = (string) $input['source_season_id'];
        $gameType = (int) ($input['game_type'] ?? 2);

        BuildNhlGoalieChanceProfilesJob::dispatch($sourceSeasonId, $gameType);

        return redirect()
            ->route('admin.nhl-shot-attempts.index', [
                'tab' => 'g-sat-profiles',
                'goalie_profile_season_id' => $sourceSeasonId,
                'goalie_profile_game_type' => $gameType,
            ])
            ->with('status', sprintf(
                'Queued goalie SAT profiles for %s game type %d.',
                $sourceSeasonId,
                $gameType
            ));
    }

    /**
     * Queue historical skater defensive chance-profile builds.
     */
    public function buildSkaterDProfiles(Request $request): RedirectResponse
    {
        $input = $request->validate([
            'source_season_id' => ['required', 'digits:8'],
            'game_type' => ['nullable', 'integer', 'min:1', 'max:99'],
        ]);

        if (!$this->skaterDProfileTableExists()) {
            return redirect()
                ->route('admin.nhl-shot-attempts.index', ['tab' => 'skater-d-profiles'])
                ->with('error', 'Run the skater defensive chance profile migration before building skater D profiles.');
        }

        if (!$this->xgTablesExist()) {
            return redirect()
                ->route('admin.nhl-shot-attempts.index', ['tab' => 'skater-d-profiles'])
                ->with('error', 'Build xG and xSOG models before building skater D profiles.');
        }

        $sourceSeasonId = (string) $input['source_season_id'];
        $gameType = (int) ($input['game_type'] ?? 2);

        BuildNhlSkaterDefensiveChanceProfilesJob::dispatch($sourceSeasonId, $gameType);

        return redirect()
            ->route('admin.nhl-shot-attempts.index', [
                'tab' => 'skater-d-profiles',
                'skater_d_profile_season_id' => $sourceSeasonId,
                'skater_d_profile_game_type' => $gameType,
            ])
            ->with('status', sprintf(
                'Queued skater D profiles for %s game type %d.',
                $sourceSeasonId,
                $gameType
            ));
    }

    /**
     * Queue historical skater offensive chance-profile builds.
     */
    public function buildSkaterOProfiles(Request $request): RedirectResponse
    {
        $input = $request->validate([
            'source_season_id' => ['required', 'digits:8'],
            'game_type' => ['nullable', 'integer', 'min:1', 'max:99'],
        ]);

        if (!$this->skaterOProfileTableExists()) {
            return redirect()
                ->route('admin.nhl-shot-attempts.index', ['tab' => 'skater-o-profiles'])
                ->with('error', 'Run the skater offensive chance profile migration before building skater O profiles.');
        }

        if (!$this->xgTablesExist()) {
            return redirect()
                ->route('admin.nhl-shot-attempts.index', ['tab' => 'skater-o-profiles'])
                ->with('error', 'Build xG and xSOG models before building skater O profiles.');
        }

        $sourceSeasonId = (string) $input['source_season_id'];
        $gameType = (int) ($input['game_type'] ?? 2);

        BuildNhlSkaterOffensiveChanceProfilesJob::dispatch($sourceSeasonId, $gameType);

        return redirect()
            ->route('admin.nhl-shot-attempts.index', [
                'tab' => 'skater-o-profiles',
                'skater_o_profile_season_id' => $sourceSeasonId,
                'skater_o_profile_game_type' => $gameType,
            ])
            ->with('status', sprintf(
                'Queued skater O profiles for %s game type %d.',
                $sourceSeasonId,
                $gameType
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
            'game_type' => $input['game_type'] ?? null,
            'team_id' => $input['team_id'] ?? null,
            'strength_bucket' => $input['strength_bucket'] ?? null,
            'attempt_result' => $input['attempt_result'] ?? null,
            'distance_bucket' => $input['distance_bucket'] ?? null,
            'angle_bucket' => $input['angle_bucket'] ?? null,
            'shot_type_bucket' => $input['shot_type_bucket'] ?? null,
            'shot_side' => $input['shot_side'] ?? null,
            'is_off_wing_attempt' => $input['is_off_wing_attempt'] ?? null,
            'player_search' => $input['player_search'] ?? null,
            'position' => $input['position'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function projectionFilters(array $input): array
    {
        return [
            'source_season_id' => $input['projection_source_season_id'] ?? null,
            'target_season_id' => $input['projection_target_season_id'] ?? null,
            'projection_version' => $input['projection_version'] ?? null,
            'status' => $input['projection_status'] ?? null,
            'team_abbrev' => $input['projection_team_abbrev'] ?? null,
            'position' => $input['projection_position'] ?? null,
            'player_search' => $input['projection_player_search'] ?? null,
            'min_xsat' => $input['projection_min_xsat'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function goalieWorkloadFilters(array $input): array
    {
        return [
            'target_season_id' => $input['goalie_workload_target_season_id'] ?? null,
            'projection_version' => $input['goalie_workload_version'] ?? null,
            'status' => $input['goalie_workload_status'] ?? null,
            'team_abbrev' => $input['goalie_workload_team_abbrev'] ?? null,
            'goalie_search' => $input['goalie_workload_goalie_search'] ?? null,
            'min_career_gp' => $input['goalie_workload_min_career_gp'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function goalieProjectionFilters(array $input): array
    {
        return [
            'target_season_id' => $input['goalie_projection_target_season_id'] ?? null,
            'projection_version' => $input['goalie_projection_version'] ?? null,
            'status' => $input['goalie_projection_status'] ?? null,
            'team_abbrev' => $input['goalie_projection_team_abbrev'] ?? null,
            'goalie_search' => $input['goalie_projection_goalie_search'] ?? null,
            'min_projected_starts' => $input['goalie_projection_min_projected_starts'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function matchupFilters(array $input): array
    {
        return [
            'source_season_id' => $input['matchup_source_season_id'] ?? null,
            'target_season_id' => $input['matchup_target_season_id'] ?? null,
            'projection_version' => $input['matchup_projection_version'] ?? null,
            'toi_projection_version' => $input['matchup_toi_projection_version'] ?? null,
            'goalie_projection_version' => $input['matchup_goalie_projection_version'] ?? null,
            'team_a' => $input['matchup_team_a'] ?? null,
            'team_b' => $input['matchup_team_b'] ?? null,
            'team_a_goalie_id' => $input['matchup_team_a_goalie_id'] ?? null,
            'team_b_goalie_id' => $input['matchup_team_b_goalie_id'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function matchupReady(array $filters): bool
    {
        foreach (['source_season_id', 'target_season_id', 'projection_version', 'toi_projection_version', 'team_a', 'team_b'] as $key) {
            if (($filters[$key] ?? null) === null || $filters[$key] === '') {
                return false;
            }
        }

        return mb_strtoupper((string) $filters['team_a']) !== mb_strtoupper((string) $filters['team_b']);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function matchupFiltersWithDefaultGoalies(array $filters): array
    {
        if (!$this->goalieProjectionTablesExist()) {
            return $filters;
        }

        $targetSeasonId = (string) ($filters['target_season_id'] ?? '');

        if ($targetSeasonId === '') {
            return $filters;
        }

        $version = (string) ($filters['goalie_projection_version'] ?? '');

        if ($version === '' || !$this->matchupGoalieProjectionVersionExists($targetSeasonId, $version)) {
            $version = (string) $this->latestGoalieProjectionVersion($targetSeasonId);
        }

        if ($version === '') {
            return $filters;
        }

        $filters['goalie_projection_version'] = $version;

        if (($filters['team_a_goalie_id'] ?? null) === null || $filters['team_a_goalie_id'] === '') {
            $filters['team_a_goalie_id'] = $this->defaultMatchupGoalieId($targetSeasonId, $version, (string) ($filters['team_a'] ?? ''));
        }

        if (($filters['team_b_goalie_id'] ?? null) === null || $filters['team_b_goalie_id'] === '') {
            $filters['team_b_goalie_id'] = $this->defaultMatchupGoalieId($targetSeasonId, $version, (string) ($filters['team_b'] ?? ''));
        }

        return $filters;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function goalieProfileFilters(array $input): array
    {
        return [
            'season_id' => $input['goalie_profile_season_id'] ?? null,
            'game_type' => $input['goalie_profile_game_type'] ?? null,
            'team_abbrev' => $input['goalie_profile_team_abbrev'] ?? null,
            'goalie_search' => $input['goalie_profile_goalie_search'] ?? null,
            'shot_type_group' => $input['goalie_profile_shot_type_group'] ?? null,
            'distance_group' => $input['goalie_profile_distance_group'] ?? null,
            'angle_group' => $input['goalie_profile_angle_group'] ?? null,
            'sequence_group' => $input['goalie_profile_sequence_group'] ?? null,
            'min_sat_against' => $input['goalie_profile_min_sat_against'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function skaterDProfileFilters(array $input): array
    {
        return [
            'season_id' => $input['skater_d_profile_season_id'] ?? null,
            'game_type' => $input['skater_d_profile_game_type'] ?? null,
            'team_abbrev' => $input['skater_d_profile_team_abbrev'] ?? null,
            'position' => $input['skater_d_profile_position'] ?? null,
            'player_search' => $input['skater_d_profile_player_search'] ?? null,
            'shot_type_group' => $input['skater_d_profile_shot_type_group'] ?? null,
            'distance_group' => $input['skater_d_profile_distance_group'] ?? null,
            'angle_group' => $input['skater_d_profile_angle_group'] ?? null,
            'sequence_group' => $input['skater_d_profile_sequence_group'] ?? null,
            'min_sat_against' => $input['skater_d_profile_min_sat_against'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function skaterOProfileFilters(array $input): array
    {
        return [
            'season_id' => $input['skater_o_profile_season_id'] ?? null,
            'game_type' => $input['skater_o_profile_game_type'] ?? null,
            'team_abbrev' => $input['skater_o_profile_team_abbrev'] ?? null,
            'position' => $input['skater_o_profile_position'] ?? null,
            'player_search' => $input['skater_o_profile_player_search'] ?? null,
            'shot_type_group' => $input['skater_o_profile_shot_type_group'] ?? null,
            'distance_group' => $input['skater_o_profile_distance_group'] ?? null,
            'angle_group' => $input['skater_o_profile_angle_group'] ?? null,
            'sequence_group' => $input['skater_o_profile_sequence_group'] ?? null,
            'min_sat_for' => $input['skater_o_profile_min_sat_for'] ?? null,
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

        if (($filters['game_type'] ?? null) !== null && $filters['game_type'] !== '') {
            $query
                ->join('nhl_games as filter_games', 'filter_games.nhl_game_id', '=', 'nhl_shot_attempts_facts.nhl_game_id')
                ->where('filter_games.game_type', (int) $filters['game_type']);
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
        $rows = collect();

        foreach ($this->analysisBuckets()->aggregateDefinitions() as $level => $definition) {
            if ($level === 99) {
                continue;
            }

            $query = $this->predictiveSampleQuery($filters)
                ->selectRaw('? as fallback_level', [$level])
                ->selectRaw('COUNT(*) as attempts')
                ->selectRaw('SUM(CASE WHEN nhl_shot_attempts_facts.is_goal THEN 1 ELSE 0 END) as goals')
                ->selectRaw('SUM(CASE WHEN nhl_shot_attempts_facts.is_shot_on_goal THEN 1 ELSE 0 END) as shots_on_goal')
                ->selectRaw('CASE WHEN COUNT(*) > 0 THEN (SUM(CASE WHEN nhl_shot_attempts_facts.is_shot_on_goal THEN 1 ELSE 0 END)::decimal / COUNT(*)) * 100 ELSE NULL END as sog_rate')
                ->selectRaw('CASE WHEN COUNT(*) > 0 THEN (SUM(CASE WHEN nhl_shot_attempts_facts.is_goal THEN 1 ELSE 0 END)::decimal / COUNT(*)) * 100 ELSE NULL END as goal_rate')
                ->selectRaw('AVG(nhl_shot_attempts_facts.shot_distance) as avg_distance')
                ->selectRaw('AVG(nhl_shot_attempts_facts.abs_shot_angle) as avg_angle')
                ->havingRaw('COUNT(*) >= ?', [self::ANALYSIS_BUCKET_MIN_ATTEMPTS]);

            foreach ($definition as $alias => $expression) {
                $query->selectRaw($expression . ' as ' . $alias)
                    ->groupByRaw($expression);
            }

            $rows = $rows->merge($query->get()->map(function (object $row): object {
                foreach (['shot_type_group', 'distance_group', 'angle_group', 'sequence_group'] as $column) {
                    if (! property_exists($row, $column)) {
                        $row->{$column} = null;
                    }
                }

                return $row;
            }));
        }

        return $this->sortAnalysisBucketRows($rows, $sort, $direction)
            ->take(150)
            ->values();
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
     * @param \Illuminate\Support\Collection<int, object> $rows
     */
    private function sortAnalysisBucketRows(Collection $rows, string $sort, string $direction): Collection
    {
        $sorter = match ($sort) {
            'fallback_level' => fn (object $row): int => (int) $row->fallback_level,
            'shot_type_group' => fn (object $row): string => (string) ($row->shot_type_group ?? ''),
            'distance_group' => fn (object $row): string => (string) ($row->distance_group ?? ''),
            'angle_group' => fn (object $row): string => (string) ($row->angle_group ?? ''),
            'sequence_group' => fn (object $row): string => (string) ($row->sequence_group ?? ''),
            'attempts' => fn (object $row): int => (int) $row->attempts,
            'shots_on_goal' => fn (object $row): int => (int) $row->shots_on_goal,
            'sog_rate' => fn (object $row): float => (float) ($row->sog_rate ?? 0),
            'goals' => fn (object $row): int => (int) $row->goals,
            'goal_rate' => fn (object $row): float => (float) ($row->goal_rate ?? 0),
            'avg_distance' => fn (object $row): float => (float) ($row->avg_distance ?? 0),
            'avg_angle' => fn (object $row): float => (float) ($row->avg_angle ?? 0),
            default => fn (object $row): int => (int) $row->attempts,
        };

        return $direction === 'asc'
            ? $rows->sortBy($sorter)
            : $rows->sortByDesc($sorter);
    }

    private function analysisBuckets(): NhlShotAttemptAnalysisBuckets
    {
        return app(NhlShotAttemptAnalysisBuckets::class);
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
    private function biometricRows(array $filters, mixed $goalModelId, int $minAttempts, string $sort, string $direction)
    {
        if (! $this->biometricColumnsExist()) {
            return collect();
        }

        $rows = collect();

        foreach ($this->biometricGroupDefinitions() as $definition) {
            $query = $this->predictiveSampleQuery($filters);

            foreach ($definition['required_columns'] as $column) {
                $query->whereNotNull('nhl_shot_attempts_facts.' . $column);
            }

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
                ->selectRaw($definition['context_1_sql'] . ' as context_1')
                ->selectRaw($definition['context_2_sql'] . ' as context_2')
                ->selectRaw($definition['context_3_sql'] . ' as context_3')
                ->selectRaw('COUNT(*) as attempts')
                ->selectRaw('SUM(CASE WHEN nhl_shot_attempts_facts.is_shot_on_goal THEN 1 ELSE 0 END) as shots_on_goal')
                ->selectRaw('SUM(CASE WHEN nhl_shot_attempts_facts.is_goal THEN 1 ELSE 0 END) as goals')
                ->selectRaw('CASE WHEN COUNT(*) > 0 THEN (SUM(CASE WHEN nhl_shot_attempts_facts.is_shot_on_goal THEN 1 ELSE 0 END)::decimal / COUNT(*)) * 100 ELSE NULL END as sog_rate')
                ->selectRaw('CASE WHEN COUNT(*) > 0 THEN (SUM(CASE WHEN nhl_shot_attempts_facts.is_goal THEN 1 ELSE 0 END)::decimal / COUNT(*)) * 100 ELSE NULL END as goal_rate')
                ->selectRaw('AVG(nhl_shot_attempts_facts.shot_distance) as avg_distance')
                ->selectRaw('AVG(nhl_shot_attempts_facts.abs_shot_angle) as avg_angle')
                ->groupByRaw($definition['label_sql'])
                ->groupByRaw($definition['sort_sql'])
                ->havingRaw('COUNT(*) >= ?', [$minAttempts]);

            foreach ($definition['context_group_sql'] as $contextGroupSql) {
                $query->groupByRaw($contextGroupSql);
            }

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

    /**
     * @param array<string, mixed> $filters
     */
    private function playerProfileRows(
        array $filters,
        mixed $goalModelId,
        mixed $sogModelId,
        int $minAttempts,
        string $sort,
        string $direction
    ) {
        $query = $this->playerProfileBaseQuery($filters, $goalModelId, $sogModelId)
            ->selectRaw('nhl_shot_attempts_facts.shooter_player_id as nhl_player_id')
            ->selectRaw("COALESCE(shooters.full_name, nhl_shot_attempts_facts.shooter_player_id::text) as player_name")
            ->selectRaw("COALESCE(shooters.position, shooters.pos_type, 'N/A') as position")
            ->selectRaw("COALESCE(nhl_teams.abbrev, shooters.team_abbrev, nhl_shot_attempts_facts.team_id::text) as team_abbrev")
            ->selectRaw('COUNT(*) as attempts')
            ->selectRaw('SUM(CASE WHEN nhl_shot_attempts_facts.is_shot_on_goal THEN 1 ELSE 0 END) as shots_on_goal')
            ->selectRaw('SUM(CASE WHEN nhl_shot_attempts_facts.is_goal THEN 1 ELSE 0 END) as goals')
            ->selectRaw('SUM(goal_predictions.xg) as xg')
            ->selectRaw('SUM(sog_predictions.xg) as xsog')
            ->selectRaw('CASE WHEN COUNT(*) > 0 THEN (SUM(CASE WHEN nhl_shot_attempts_facts.is_shot_on_goal THEN 1 ELSE 0 END)::decimal / COUNT(*)) * 100 ELSE NULL END as sog_rate')
            ->selectRaw('CASE WHEN COUNT(*) > 0 THEN (SUM(CASE WHEN nhl_shot_attempts_facts.is_goal THEN 1 ELSE 0 END)::decimal / COUNT(*)) * 100 ELSE NULL END as goal_rate')
            ->selectRaw('CASE WHEN COUNT(*) > 0 THEN (SUM(goal_predictions.xg) / COUNT(*)) * 100 ELSE NULL END as xg_per_sat')
            ->selectRaw('CASE WHEN COUNT(*) > 0 THEN (SUM(sog_predictions.xg) / COUNT(*)) * 100 ELSE NULL END as xsog_per_sat')
            ->selectRaw('AVG(nhl_shot_attempts_facts.shot_distance) as avg_distance')
            ->selectRaw('AVG(nhl_shot_attempts_facts.abs_shot_angle) as avg_angle')
            ->groupByRaw('nhl_shot_attempts_facts.shooter_player_id')
            ->groupByRaw("COALESCE(shooters.full_name, nhl_shot_attempts_facts.shooter_player_id::text)")
            ->groupByRaw("COALESCE(shooters.position, shooters.pos_type, 'N/A')")
            ->groupByRaw("COALESCE(nhl_teams.abbrev, shooters.team_abbrev, nhl_shot_attempts_facts.team_id::text)")
            ->havingRaw('COUNT(*) >= ?', [$minAttempts]);

        $column = $this->playerProfileSortColumns()[$sort] ?? $this->playerProfileSortColumns()['attempts'];

        return $query
            ->orderBy($column, $direction)
            ->orderByDesc('attempts')
            ->limit(150)
            ->get();
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function playerProfileBucketRows(
        array $filters,
        mixed $goalModelId,
        mixed $sogModelId,
        int $minAttempts,
        string $sort,
        string $direction
    ) {
        if (trim((string) ($filters['player_search'] ?? '')) === '') {
            return collect();
        }

        if ($goalModelId === null) {
            return collect();
        }

        $shotTypeExpression = "COALESCE(goal_buckets.bucket_dimensions->>'shot_type_group', 'Any')";
        $distanceExpression = "COALESCE(goal_buckets.bucket_dimensions->>'distance_group', 'Any')";
        $angleExpression = "COALESCE(goal_buckets.bucket_dimensions->>'angle_group', 'Any')";
        $sequenceExpression = "COALESCE(goal_buckets.bucket_dimensions->>'sequence_group', 'Any')";

        $query = $this->playerProfileBaseQuery($filters, $goalModelId, $sogModelId)
            ->join('nhl_expected_goals_model_buckets as goal_buckets', function ($join) use ($goalModelId): void {
                $join->on('goal_buckets.bucket_key', '=', 'goal_predictions.matched_bucket_key')
                    ->where('goal_buckets.expected_goals_model_id', '=', $goalModelId);
            })
            ->selectRaw('nhl_shot_attempts_facts.shooter_player_id as nhl_player_id')
            ->selectRaw("COALESCE(shooters.full_name, nhl_shot_attempts_facts.shooter_player_id::text) as player_name")
            ->selectRaw("COALESCE(nhl_teams.abbrev, shooters.team_abbrev, nhl_shot_attempts_facts.team_id::text) as team_abbrev")
            ->selectRaw('goal_predictions.fallback_level')
            ->selectRaw($shotTypeExpression . ' as shot_type_group')
            ->selectRaw($distanceExpression . ' as distance_group')
            ->selectRaw($angleExpression . ' as angle_group')
            ->selectRaw($sequenceExpression . ' as sequence_group')
            ->selectRaw('COUNT(*) as attempts')
            ->selectRaw('SUM(CASE WHEN nhl_shot_attempts_facts.is_shot_on_goal THEN 1 ELSE 0 END) as shots_on_goal')
            ->selectRaw('SUM(CASE WHEN nhl_shot_attempts_facts.is_goal THEN 1 ELSE 0 END) as goals')
            ->selectRaw('SUM(goal_predictions.xg) as xg')
            ->selectRaw('SUM(sog_predictions.xg) as xsog')
            ->selectRaw('CASE WHEN COUNT(*) > 0 THEN (SUM(CASE WHEN nhl_shot_attempts_facts.is_shot_on_goal THEN 1 ELSE 0 END)::decimal / COUNT(*)) * 100 ELSE NULL END as sog_rate')
            ->selectRaw('CASE WHEN COUNT(*) > 0 THEN (SUM(CASE WHEN nhl_shot_attempts_facts.is_goal THEN 1 ELSE 0 END)::decimal / COUNT(*)) * 100 ELSE NULL END as goal_rate')
            ->groupByRaw('nhl_shot_attempts_facts.shooter_player_id')
            ->groupByRaw("COALESCE(shooters.full_name, nhl_shot_attempts_facts.shooter_player_id::text)")
            ->groupByRaw("COALESCE(nhl_teams.abbrev, shooters.team_abbrev, nhl_shot_attempts_facts.team_id::text)")
            ->groupByRaw('goal_predictions.fallback_level')
            ->groupByRaw($shotTypeExpression)
            ->groupByRaw($distanceExpression)
            ->groupByRaw($angleExpression)
            ->groupByRaw($sequenceExpression)
            ->havingRaw('COUNT(*) >= ?', [$minAttempts]);

        $column = $this->playerProfileBucketSortColumns()[$sort] ?? $this->playerProfileBucketSortColumns()['attempts'];

        return $query
            ->orderBy($column, $direction)
            ->orderByDesc('attempts')
            ->limit(250)
            ->get();
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function playerProfileBaseQuery(array $filters, mixed $goalModelId, mixed $sogModelId)
    {
        $query = $this->predictiveSampleQuery($filters)
            ->whereNotNull('nhl_shot_attempts_facts.shooter_player_id')
            ->leftJoin('players as shooters', 'shooters.nhl_id', '=', 'nhl_shot_attempts_facts.shooter_player_id')
            ->leftJoin('nhl_teams', 'nhl_teams.nhl_id', '=', 'nhl_shot_attempts_facts.team_id');

        if ($goalModelId !== null) {
            $query->leftJoin('nhl_shot_attempt_predictions as goal_predictions', function ($join) use ($goalModelId): void {
                $join->on('goal_predictions.shot_attempt_fact_id', '=', 'nhl_shot_attempts_facts.id')
                    ->where('goal_predictions.expected_goals_model_id', '=', $goalModelId)
                    ->where('goal_predictions.prediction_target', '=', NhlExpectedGoalsBackfiller::TARGET_GOAL)
                    ->where('goal_predictions.is_scored', '=', true);
            });
        } else {
            $query->leftJoin(DB::raw('(SELECT NULL::bigint as shot_attempt_fact_id, NULL::decimal as xg) as goal_predictions'), function ($join): void {
                $join->on('goal_predictions.shot_attempt_fact_id', '=', 'nhl_shot_attempts_facts.id');
            });
        }

        if ($sogModelId !== null) {
            $query->leftJoin('nhl_shot_attempt_predictions as sog_predictions', function ($join) use ($sogModelId): void {
                $join->on('sog_predictions.shot_attempt_fact_id', '=', 'nhl_shot_attempts_facts.id')
                    ->where('sog_predictions.expected_goals_model_id', '=', $sogModelId)
                    ->where('sog_predictions.prediction_target', '=', NhlExpectedGoalsBackfiller::TARGET_SHOT_ON_GOAL)
                    ->where('sog_predictions.is_scored', '=', true);
            });
        } else {
            $query->leftJoin(DB::raw('(SELECT NULL::bigint as shot_attempt_fact_id, NULL::decimal as xg) as sog_predictions'), function ($join): void {
                $join->on('sog_predictions.shot_attempt_fact_id', '=', 'nhl_shot_attempts_facts.id');
            });
        }

        $playerSearch = trim((string) ($filters['player_search'] ?? ''));
        if ($playerSearch !== '') {
            $like = '%' . mb_strtolower($playerSearch) . '%';
            $query->where(function ($query) use ($like): void {
                $query->whereRaw("LOWER(COALESCE(shooters.full_name, '')) LIKE ?", [$like])
                    ->orWhereRaw('nhl_shot_attempts_facts.shooter_player_id::text LIKE ?', [$like]);
            });
        }

        $position = trim((string) ($filters['position'] ?? ''));
        if ($position !== '') {
            $query->whereRaw("UPPER(COALESCE(shooters.position, shooters.pos_type, '')) = ?", [mb_strtoupper($position)]);
        }

        return $query;
    }

    /**
     * @return array<string, string>
     */
    private function playerProfileSortColumns(): array
    {
        return [
            'player_name' => 'player_name',
            'team_abbrev' => 'team_abbrev',
            'position' => 'position',
            'attempts' => 'attempts',
            'shots_on_goal' => 'shots_on_goal',
            'sog_rate' => 'sog_rate',
            'goals' => 'goals',
            'goal_rate' => 'goal_rate',
            'xg' => 'xg',
            'xsog' => 'xsog',
            'xg_per_sat' => 'xg_per_sat',
            'xsog_per_sat' => 'xsog_per_sat',
            'avg_distance' => 'avg_distance',
            'avg_angle' => 'avg_angle',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function playerProfileBucketSortColumns(): array
    {
        return [
            'player_name' => 'player_name',
            'team_abbrev' => 'team_abbrev',
            'fallback_level' => 'fallback_level',
            'shot_type_group' => 'shot_type_group',
            'distance_group' => 'distance_group',
            'angle_group' => 'angle_group',
            'sequence_group' => 'sequence_group',
            'attempts' => 'attempts',
            'shots_on_goal' => 'shots_on_goal',
            'sog_rate' => 'sog_rate',
            'goals' => 'goals',
            'goal_rate' => 'goal_rate',
            'xg' => 'xg',
            'xsog' => 'xsog',
        ];
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
     * @return array<int, array{
     *     label:string,
     *     required_columns:array<int, string>,
     *     label_sql:string,
     *     sort_sql:string,
     *     context_1_sql:string,
     *     context_2_sql:string,
     *     context_3_sql:string,
     *     context_group_sql:array<int, string>
     * }>
     */
    private function biometricGroupDefinitions(): array
    {
        $heightBucket = "CASE
            WHEN shooter_height_inches IS NULL THEN 'Unknown'
            WHEN shooter_height_inches < 70 THEN '<5-10'
            WHEN shooter_height_inches < 73 THEN '5-10 to 6-0'
            WHEN shooter_height_inches < 76 THEN '6-1 to 6-3'
            WHEN shooter_height_inches < 79 THEN '6-4 to 6-6'
            ELSE '6-7+'
        END";
        $heightSort = "CASE
            WHEN shooter_height_inches IS NULL THEN 99
            WHEN shooter_height_inches < 70 THEN 1
            WHEN shooter_height_inches < 73 THEN 2
            WHEN shooter_height_inches < 76 THEN 3
            WHEN shooter_height_inches < 79 THEN 4
            ELSE 5
        END";
        $weightBucket = "CASE
            WHEN shooter_weight_lbs IS NULL THEN 'Unknown'
            WHEN shooter_weight_lbs < 180 THEN '<180'
            WHEN shooter_weight_lbs < 195 THEN '180-194'
            WHEN shooter_weight_lbs < 210 THEN '195-209'
            WHEN shooter_weight_lbs < 225 THEN '210-224'
            ELSE '225+'
        END";
        $weightSort = "CASE
            WHEN shooter_weight_lbs IS NULL THEN 99
            WHEN shooter_weight_lbs < 180 THEN 1
            WHEN shooter_weight_lbs < 195 THEN 2
            WHEN shooter_weight_lbs < 210 THEN 3
            WHEN shooter_weight_lbs < 225 THEN 4
            ELSE 5
        END";
        $emptyContext = "'N/A'";

        return [
            [
                'label' => 'Shooter Age',
                'required_columns' => ['shooter_age_years'],
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
                'context_1_sql' => $emptyContext,
                'context_2_sql' => $emptyContext,
                'context_3_sql' => $emptyContext,
                'context_group_sql' => [],
            ],
            [
                'label' => 'Shooter Height',
                'required_columns' => ['shooter_height_inches'],
                'label_sql' => $heightBucket,
                'sort_sql' => $heightSort,
                'context_1_sql' => $emptyContext,
                'context_2_sql' => $emptyContext,
                'context_3_sql' => $emptyContext,
                'context_group_sql' => [],
            ],
            [
                'label' => 'Shooter Weight',
                'required_columns' => ['shooter_weight_lbs'],
                'label_sql' => $weightBucket,
                'sort_sql' => $weightSort,
                'context_1_sql' => $emptyContext,
                'context_2_sql' => $emptyContext,
                'context_3_sql' => $emptyContext,
                'context_group_sql' => [],
            ],
            [
                'label' => 'Shooter Height + Weight',
                'required_columns' => ['shooter_height_inches', 'shooter_weight_lbs'],
                'label_sql' => $heightBucket,
                'sort_sql' => $heightSort,
                'context_1_sql' => $weightBucket,
                'context_2_sql' => $emptyContext,
                'context_3_sql' => $emptyContext,
                'context_group_sql' => [$weightBucket],
            ],
            [
                'label' => 'Height + Shot Context',
                'required_columns' => ['shooter_height_inches'],
                'label_sql' => $heightBucket,
                'sort_sql' => $heightSort,
                'context_1_sql' => 'shot_type_bucket',
                'context_2_sql' => 'distance_bucket',
                'context_3_sql' => 'angle_bucket',
                'context_group_sql' => ['shot_type_bucket', 'distance_bucket', 'angle_bucket'],
            ],
            [
                'label' => 'Weight + Shot Context',
                'required_columns' => ['shooter_weight_lbs'],
                'label_sql' => $weightBucket,
                'sort_sql' => $weightSort,
                'context_1_sql' => 'shot_type_bucket',
                'context_2_sql' => 'distance_bucket',
                'context_3_sql' => 'angle_bucket',
                'context_group_sql' => ['shot_type_bucket', 'distance_bucket', 'angle_bucket'],
            ],
            [
                'label' => 'Goalie Age',
                'required_columns' => ['goalie_age_years'],
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
                'context_1_sql' => $emptyContext,
                'context_2_sql' => $emptyContext,
                'context_3_sql' => $emptyContext,
                'context_group_sql' => [],
            ],
            [
                'label' => 'Goalie Height',
                'required_columns' => ['goalie_height_inches'],
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
                'context_1_sql' => $emptyContext,
                'context_2_sql' => $emptyContext,
                'context_3_sql' => $emptyContext,
                'context_group_sql' => [],
            ],
            [
                'label' => 'Goalie Weight',
                'required_columns' => ['goalie_weight_lbs'],
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
                'context_1_sql' => $emptyContext,
                'context_2_sql' => $emptyContext,
                'context_3_sql' => $emptyContext,
                'context_group_sql' => [],
            ],
        ];
    }

    private function sortBiometricRows($rows, string $sort, string $direction)
    {
        $sorter = match ($sort) {
            'profile' => fn (object $row): string => (string) $row->profile,
            'bucket' => fn (object $row): string => sprintf('%s:%02d:%s', $row->profile, (int) $row->bucket_sort, $row->bucket),
            'context_1' => fn (object $row): string => (string) ($row->context_1 ?? ''),
            'context_2' => fn (object $row): string => (string) ($row->context_2 ?? ''),
            'context_3' => fn (object $row): string => (string) ($row->context_3 ?? ''),
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
                    'fallback_level' => true,
                    'shot_type_group' => true,
                    'distance_group' => true,
                    'angle_group' => true,
                    'sequence_group' => true,
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
                    'context_1' => true,
                    'context_2' => true,
                    'context_3' => true,
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
            'player-profiles' => [
                'default' => 'attempts',
                'keys' => [
                    'player_name' => true,
                    'team_abbrev' => true,
                    'position' => true,
                    'attempts' => true,
                    'shots_on_goal' => true,
                    'sog_rate' => true,
                    'goals' => true,
                    'goal_rate' => true,
                    'xg' => true,
                    'xsog' => true,
                    'xg_per_sat' => true,
                    'xsog_per_sat' => true,
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
            'positions' => DB::table('players')
                ->whereNotNull('position')
                ->distinct()
                ->orderBy('position')
                ->pluck('position'),
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

    /**
     * @return array<string, mixed>
     */
    private function projectionOptions(): array
    {
        $sourceSeasonQuery = $this->projectionOptionBaseQuery('source_season_id');
        $targetSeasonQuery = $this->projectionOptionBaseQuery('target_season_id');
        $versionQuery = $this->projectionOptionBaseQuery('projection_version');
        $statusQuery = $this->projectionOptionBaseQuery('status');
        $teamQuery = $this->projectionOptionBaseQuery('team_abbrev');
        $positionQuery = $this->projectionOptionBaseQuery('position');

        if ($this->toiProjectionTableExists()) {
            $sourceSeasonQuery->union(
                DB::table('nhl_player_toi_projections')
                    ->select('source_season_id')
                    ->whereNotNull('source_season_id')
            );
            $targetSeasonQuery->union(
                DB::table('nhl_player_toi_projections')
                    ->select('target_season_id')
                    ->whereNotNull('target_season_id')
            );
            $versionQuery->union(
                DB::table('nhl_player_toi_projections')
                    ->select('projection_version')
                    ->whereNotNull('projection_version')
            );
            $statusQuery->union(
                DB::table('nhl_player_toi_projections')
                    ->select('status')
                    ->whereNotNull('status')
            );
            $teamQuery->union(
                DB::table('nhl_player_toi_projections')
                    ->select('target_team_abbrev as team_abbrev')
                    ->whereNotNull('target_team_abbrev')
            );
            $positionQuery->union(
                DB::table('nhl_player_toi_projections')
                    ->select('position')
                    ->whereNotNull('position')
            );
        }

        return [
            'sourceSeasons' => DB::query()
                ->fromSub($sourceSeasonQuery, 'source_seasons')
                ->orderByDesc('source_season_id')
                ->pluck('source_season_id'),
            'targetSeasons' => DB::query()
                ->fromSub($targetSeasonQuery, 'target_seasons')
                ->orderByDesc('target_season_id')
                ->pluck('target_season_id'),
            'versions' => DB::query()
                ->fromSub($versionQuery, 'versions')
                ->orderByDesc('projection_version')
                ->pluck('projection_version'),
            'statuses' => DB::query()
                ->fromSub($statusQuery, 'statuses')
                ->orderBy('status')
                ->pluck('status'),
            'teams' => DB::query()
                ->fromSub($teamQuery, 'teams')
                ->orderBy('team_abbrev')
                ->pluck('team_abbrev'),
            'positions' => DB::query()
                ->fromSub($positionQuery, 'positions')
                ->orderBy('position')
                ->pluck('position'),
        ];
    }

    private function projectionOptionBaseQuery(string $column)
    {
        if ($this->projectionTablesExist()) {
            $selectedColumn = $column === 'team_abbrev' ? 'team_abbrev' : $column;

            return DB::table('nhl_player_season_projections')
                ->select($selectedColumn)
                ->whereNotNull($selectedColumn);
        }

        $selectedColumn = $column === 'team_abbrev' ? 'target_team_abbrev as team_abbrev' : $column;
        $whereColumn = $column === 'team_abbrev' ? 'target_team_abbrev' : $column;

        return DB::table('nhl_player_toi_projections')
            ->selectRaw($selectedColumn)
            ->whereNotNull($whereColumn);
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function emptyProjectionOptions(): array
    {
        return [
            'sourceSeasons' => [],
            'targetSeasons' => [],
            'versions' => [],
            'statuses' => [],
            'teams' => [],
            'positions' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function goalieWorkloadOptions(): array
    {
        return [
            'targetSeasons' => DB::table('nhl_goalie_workload_projections')
                ->whereNotNull('target_season_id')
                ->distinct()
                ->orderByDesc('target_season_id')
                ->pluck('target_season_id'),
            'versions' => DB::table('nhl_goalie_workload_projections')
                ->whereNotNull('projection_version')
                ->distinct()
                ->orderByDesc('projection_version')
                ->pluck('projection_version'),
            'statuses' => DB::table('nhl_goalie_workload_projections')
                ->whereNotNull('status')
                ->distinct()
                ->orderBy('status')
                ->pluck('status'),
            'teams' => DB::table('nhl_goalie_workload_projections')
                ->whereNotNull('target_team_abbrev')
                ->distinct()
                ->orderBy('target_team_abbrev')
                ->pluck('target_team_abbrev'),
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function emptyGoalieWorkloadOptions(): array
    {
        return [
            'targetSeasons' => [],
            'versions' => [],
            'statuses' => [],
            'teams' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function goalieProjectionOptions(): array
    {
        return [
            'targetSeasons' => DB::table('nhl_goalie_season_projections')
                ->whereNotNull('target_season_id')
                ->distinct()
                ->orderByDesc('target_season_id')
                ->pluck('target_season_id'),
            'versions' => DB::table('nhl_goalie_season_projections')
                ->whereNotNull('projection_version')
                ->distinct()
                ->orderByDesc('projection_version')
                ->pluck('projection_version'),
            'statuses' => DB::table('nhl_goalie_season_projections')
                ->whereNotNull('status')
                ->distinct()
                ->orderBy('status')
                ->pluck('status'),
            'teams' => DB::table('nhl_goalie_season_projections')
                ->whereNotNull('target_team_abbrev')
                ->distinct()
                ->orderBy('target_team_abbrev')
                ->pluck('target_team_abbrev'),
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function emptyGoalieProjectionOptions(): array
    {
        return [
            'targetSeasons' => [],
            'versions' => [],
            'statuses' => [],
            'teams' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function matchupOptions(array $filters): array
    {
        if (!$this->matchupTablesExist()) {
            return [
                'sourceSeasons' => [],
                'targetSeasons' => [],
                'projectionVersions' => [],
                'toiProjectionVersions' => [],
                'goalieProjectionVersions' => [],
                'teams' => [],
                'teamAGoalies' => [],
                'teamBGoalies' => [],
            ];
        }

        return [
            'sourceSeasons' => DB::table('nhl_player_season_projections')
                ->whereNotNull('source_season_id')
                ->distinct()
                ->orderByDesc('source_season_id')
                ->pluck('source_season_id'),
            'targetSeasons' => DB::table('nhl_player_season_projections')
                ->whereNotNull('target_season_id')
                ->distinct()
                ->orderByDesc('target_season_id')
                ->pluck('target_season_id'),
            'projectionVersions' => DB::table('nhl_player_season_projections')
                ->whereNotNull('projection_version')
                ->distinct()
                ->orderByDesc('projection_version')
                ->pluck('projection_version'),
            'toiProjectionVersions' => DB::table('nhl_player_toi_projections')
                ->whereNotNull('projection_version')
                ->distinct()
                ->orderByDesc('projection_version')
                ->pluck('projection_version'),
            'goalieProjectionVersions' => $this->validMatchupGoalieProjectionVersions()
                ->orderByDesc('projection_version')
                ->pluck('projection_version'),
            'teams' => DB::table('nhl_player_toi_projections')
                ->whereNotNull('target_team_abbrev')
                ->distinct()
                ->orderBy('target_team_abbrev')
                ->pluck('target_team_abbrev'),
            'teamAGoalies' => $this->matchupGoalieOptions(
                (string) ($filters['source_season_id'] ?? ''),
                (string) ($filters['target_season_id'] ?? ''),
                (string) ($filters['goalie_projection_version'] ?? ''),
                (string) ($filters['team_a'] ?? ''),
                $filters['team_a_goalie_id'] === null || $filters['team_a_goalie_id'] === ''
                    ? null
                    : (int) $filters['team_a_goalie_id']
            ),
            'teamBGoalies' => $this->matchupGoalieOptions(
                (string) ($filters['source_season_id'] ?? ''),
                (string) ($filters['target_season_id'] ?? ''),
                (string) ($filters['goalie_projection_version'] ?? ''),
                (string) ($filters['team_b'] ?? ''),
                $filters['team_b_goalie_id'] === null || $filters['team_b_goalie_id'] === ''
                    ? null
                    : (int) $filters['team_b_goalie_id']
            ),
        ];
    }

    /**
     * @return \Illuminate\Database\Query\Builder
     */
    private function validMatchupGoalieProjectionVersions()
    {
        if (!$this->goalieProjectionTablesExist()) {
            return DB::table('nhl_goalie_season_projections')
                ->whereRaw('1 = 0');
        }

        return DB::table('nhl_goalie_season_projections as projections')
            ->join('nhl_goalie_projection_chance_buckets as buckets', function ($join): void {
                $join->on('buckets.projection_version', '=', 'projections.projection_version')
                    ->on('buckets.target_season_id', '=', 'projections.target_season_id')
                    ->on('buckets.goalie_player_id', '=', 'projections.goalie_player_id')
                    ->where('buckets.projection_strength', '=', 'ev');
            })
            ->whereNotNull('projections.projection_version')
            ->groupBy('projections.projection_version')
            ->selectRaw('projections.projection_version')
            ->selectRaw('MAX(projections.projected_at) as latest_projected_at');
    }

    /**
     * @return Collection<int, object>
     */
    private function matchupGoalieOptions(
        string $sourceSeasonId,
        string $targetSeasonId,
        string $goalieProjectionVersion,
        string $team,
        ?int $selectedGoalieId
    ): Collection
    {
        if ($sourceSeasonId === '' || $team === '' || !Schema::hasTable('nhl_goalie_chance_profile_buckets')) {
            return collect();
        }

        $team = mb_strtoupper($team);
        $profileTotals = DB::table('nhl_goalie_chance_profile_buckets')
            ->where('source_season_id', $sourceSeasonId)
            ->where('game_type', 2)
            ->selectRaw('goalie_player_id')
            ->selectRaw('MAX(team_abbrev) as source_team_abbrev')
            ->selectRaw('MAX(source_games) as source_games')
            ->selectRaw('SUM(source_sat_against) as source_sat_against')
            ->groupBy('goalie_player_id');

        $query = DB::table('players')
            ->leftJoinSub($profileTotals, 'profiles', 'profiles.goalie_player_id', '=', 'players.nhl_id')
            ->where(function ($query): void {
                $query->where('players.is_goalie', true)
                    ->orWhere('players.position', 'G')
                    ->orWhere('players.pos_type', 'G');
            })
            ->where('players.team_abbrev', $team)
            ->whereNotNull('players.nhl_id');

        if ($this->goalieProjectionTablesExist() && $targetSeasonId !== '' && $goalieProjectionVersion !== '') {
            $query->leftJoin('nhl_goalie_season_projections as projections', function ($join) use ($targetSeasonId, $goalieProjectionVersion): void {
                $join->on('projections.goalie_player_id', '=', 'players.nhl_id')
                    ->where('projections.target_season_id', '=', $targetSeasonId)
                    ->where('projections.projection_version', '=', $goalieProjectionVersion);
            });
        }

        return $query
            ->selectRaw('players.nhl_id as goalie_player_id')
            ->selectRaw("COALESCE(players.full_name, players.nhl_id::text) as goalie_name")
            ->selectRaw('profiles.source_team_abbrev')
            ->selectRaw('players.team_abbrev as current_team_abbrev')
            ->selectRaw('profiles.source_games')
            ->selectRaw('profiles.source_sat_against')
            ->selectRaw($this->goalieProjectionTablesExist() && $targetSeasonId !== '' && $goalieProjectionVersion !== ''
                ? 'projections.projected_starts'
                : 'NULL as projected_starts')
            ->orderByRaw('projected_starts DESC NULLS LAST')
            ->orderByDesc('profiles.source_games')
            ->orderByDesc('profiles.source_sat_against')
            ->orderBy('goalie_name')
            ->get();
    }

    private function latestGoalieProjectionVersion(string $targetSeasonId): ?string
    {
        $version = $this->validMatchupGoalieProjectionVersions()
            ->where('projections.target_season_id', $targetSeasonId)
            ->orderByDesc('latest_projected_at')
            ->orderByDesc('projection_version')
            ->value('projection_version');

        return $version === null ? null : (string) $version;
    }

    private function matchupGoalieProjectionVersionExists(string $targetSeasonId, string $goalieProjectionVersion): bool
    {
        return $this->validMatchupGoalieProjectionVersions()
            ->where('projections.target_season_id', $targetSeasonId)
            ->where('projections.projection_version', $goalieProjectionVersion)
            ->exists();
    }

    private function defaultMatchupGoalieId(string $targetSeasonId, string $goalieProjectionVersion, string $team): ?int
    {
        if (!$this->goalieProjectionTablesExist() || $team === '') {
            return null;
        }

        $goalieId = DB::table('nhl_goalie_season_projections')
            ->where('target_season_id', $targetSeasonId)
            ->where('projection_version', $goalieProjectionVersion)
            ->where('target_team_abbrev', mb_strtoupper($team))
            ->orderByDesc('projected_starts')
            ->orderByDesc('projected_games')
            ->value('goalie_player_id');

        return $goalieId === null ? null : (int) $goalieId;
    }

    /**
     * @return array<string, mixed>
     */
    private function goalieProfileOptions(): array
    {
        return [
            'seasons' => DB::table('nhl_goalie_chance_profile_buckets')
                ->whereNotNull('source_season_id')
                ->distinct()
                ->orderByDesc('source_season_id')
                ->pluck('source_season_id'),
            'gameTypes' => DB::table('nhl_goalie_chance_profile_buckets')
                ->whereNotNull('game_type')
                ->distinct()
                ->orderBy('game_type')
                ->pluck('game_type'),
            'teams' => DB::table('nhl_goalie_chance_profile_buckets')
                ->whereNotNull('team_abbrev')
                ->distinct()
                ->orderBy('team_abbrev')
                ->pluck('team_abbrev'),
            'shotTypes' => DB::table('nhl_goalie_chance_profile_buckets')
                ->whereNotNull('shot_type_group')
                ->distinct()
                ->orderBy('shot_type_group')
                ->pluck('shot_type_group'),
            'distances' => DB::table('nhl_goalie_chance_profile_buckets')
                ->whereNotNull('distance_group')
                ->distinct()
                ->orderBy('distance_group')
                ->pluck('distance_group'),
            'angles' => DB::table('nhl_goalie_chance_profile_buckets')
                ->whereNotNull('angle_group')
                ->distinct()
                ->orderBy('angle_group')
                ->pluck('angle_group'),
            'sequences' => DB::table('nhl_goalie_chance_profile_buckets')
                ->whereNotNull('sequence_group')
                ->distinct()
                ->orderBy('sequence_group')
                ->pluck('sequence_group'),
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function emptyGoalieProfileOptions(): array
    {
        return [
            'seasons' => [],
            'gameTypes' => [],
            'teams' => [],
            'shotTypes' => [],
            'distances' => [],
            'angles' => [],
            'sequences' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function skaterDProfileOptions(): array
    {
        return [
            'seasons' => DB::table('nhl_skater_defensive_chance_profile_buckets')
                ->whereNotNull('source_season_id')
                ->distinct()
                ->orderByDesc('source_season_id')
                ->pluck('source_season_id'),
            'gameTypes' => DB::table('nhl_skater_defensive_chance_profile_buckets')
                ->whereNotNull('game_type')
                ->distinct()
                ->orderBy('game_type')
                ->pluck('game_type'),
            'teams' => DB::table('nhl_skater_defensive_chance_profile_buckets')
                ->whereNotNull('team_abbrev')
                ->distinct()
                ->orderBy('team_abbrev')
                ->pluck('team_abbrev'),
            'positions' => DB::table('nhl_skater_defensive_chance_profile_buckets')
                ->whereNotNull('position')
                ->distinct()
                ->orderBy('position')
                ->pluck('position'),
            'shotTypes' => DB::table('nhl_skater_defensive_chance_profile_buckets')
                ->whereNotNull('shot_type_group')
                ->distinct()
                ->orderBy('shot_type_group')
                ->pluck('shot_type_group'),
            'distances' => DB::table('nhl_skater_defensive_chance_profile_buckets')
                ->whereNotNull('distance_group')
                ->distinct()
                ->orderBy('distance_group')
                ->pluck('distance_group'),
            'angles' => DB::table('nhl_skater_defensive_chance_profile_buckets')
                ->whereNotNull('angle_group')
                ->distinct()
                ->orderBy('angle_group')
                ->pluck('angle_group'),
            'sequences' => DB::table('nhl_skater_defensive_chance_profile_buckets')
                ->whereNotNull('sequence_group')
                ->distinct()
                ->orderBy('sequence_group')
                ->pluck('sequence_group'),
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function emptySkaterDProfileOptions(): array
    {
        return [
            'seasons' => [],
            'gameTypes' => [],
            'teams' => [],
            'positions' => [],
            'shotTypes' => [],
            'distances' => [],
            'angles' => [],
            'sequences' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function skaterOProfileOptions(): array
    {
        return [
            'seasons' => DB::table('nhl_skater_offensive_chance_profile_buckets')->whereNotNull('source_season_id')->distinct()->orderByDesc('source_season_id')->pluck('source_season_id'),
            'gameTypes' => DB::table('nhl_skater_offensive_chance_profile_buckets')->whereNotNull('game_type')->distinct()->orderBy('game_type')->pluck('game_type'),
            'teams' => DB::table('nhl_skater_offensive_chance_profile_buckets')->whereNotNull('team_abbrev')->distinct()->orderBy('team_abbrev')->pluck('team_abbrev'),
            'positions' => DB::table('nhl_skater_offensive_chance_profile_buckets')->whereNotNull('position')->distinct()->orderBy('position')->pluck('position'),
            'shotTypes' => DB::table('nhl_skater_offensive_chance_profile_buckets')->whereNotNull('shot_type_group')->distinct()->orderBy('shot_type_group')->pluck('shot_type_group'),
            'distances' => DB::table('nhl_skater_offensive_chance_profile_buckets')->whereNotNull('distance_group')->distinct()->orderBy('distance_group')->pluck('distance_group'),
            'angles' => DB::table('nhl_skater_offensive_chance_profile_buckets')->whereNotNull('angle_group')->distinct()->orderBy('angle_group')->pluck('angle_group'),
            'sequences' => DB::table('nhl_skater_offensive_chance_profile_buckets')->whereNotNull('sequence_group')->distinct()->orderBy('sequence_group')->pluck('sequence_group'),
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function emptySkaterOProfileOptions(): array
    {
        return [
            'seasons' => [],
            'gameTypes' => [],
            'teams' => [],
            'positions' => [],
            'shotTypes' => [],
            'distances' => [],
            'angles' => [],
            'sequences' => [],
        ];
    }

    private function projectionTablesExist(): bool
    {
        return Schema::hasTable('nhl_player_season_projections')
            && Schema::hasTable('nhl_player_projection_profile_buckets');
    }

    private function toiProjectionTableExists(): bool
    {
        return Schema::hasTable('nhl_player_toi_projections');
    }

    private function goalieWorkloadProjectionTableExists(): bool
    {
        return Schema::hasTable('nhl_goalie_workload_projections')
            && Schema::hasColumn('nhl_goalie_workload_projections', 'projected_starts')
            && Schema::hasColumn('nhl_goalie_workload_projections', 'target_role_bucket');
    }

    private function goalieProjectionTablesExist(): bool
    {
        return Schema::hasTable('nhl_goalie_season_projections')
            && Schema::hasTable('nhl_goalie_projection_chance_buckets')
            && Schema::hasColumn('nhl_goalie_season_projections', 'goalie_workload_projection_version')
            && Schema::hasColumn('nhl_goalie_season_projections', 'projected_starts')
            && Schema::hasColumn('nhl_goalie_season_projections', 'projected_ev_xga')
            && Schema::hasColumn('nhl_goalie_projection_chance_buckets', 'projection_strength');
    }

    private function goalieProjectionBuildReady(): bool
    {
        return $this->goalieProjectionTablesExist()
            && Schema::hasTable('nhl_goalie_workload_projections')
            && Schema::hasTable('nhl_goalie_chance_profile_buckets')
            && Schema::hasTable('nhl_player_toi_projections')
            && Schema::hasTable('nhl_shot_attempts_facts')
            && Schema::hasTable('nhl_shot_attempt_predictions');
    }

    private function goalieProfileTableExists(): bool
    {
        return Schema::hasTable('nhl_goalie_chance_profile_buckets');
    }

    private function skaterDProfileTableExists(): bool
    {
        return Schema::hasTable('nhl_skater_defensive_chance_profile_buckets');
    }

    private function skaterOProfileTableExists(): bool
    {
        return Schema::hasTable('nhl_skater_offensive_chance_profile_buckets');
    }

    private function matchupTablesExist(): bool
    {
        return Schema::hasTable('nhl_player_season_projections')
            && Schema::hasTable('nhl_player_projection_profile_buckets')
            && Schema::hasTable('nhl_player_toi_projections')
            && Schema::hasTable('nhl_skater_defensive_chance_profile_buckets')
            && Schema::hasTable('nhl_goalie_chance_profile_buckets');
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
     * @param array<string, mixed> $filters
     */
    private function projectionRows(array $filters, string $sort, string $direction): Collection
    {
        $hasSourceModelGoals = Schema::hasColumn('nhl_player_season_projections', 'source_model_goals');
        $hasFinishingAdjustment = Schema::hasColumn('nhl_player_season_projections', 'source_goals_above_xgf');
        $query = DB::table('nhl_player_season_projections as projections')
            ->leftJoin('players', 'players.nhl_id', '=', 'projections.player_id')
            ->select([
                'projections.id',
                'projections.projection_version',
                'projections.source_season_id',
                'projections.target_season_id',
                'projections.player_id',
                'projections.team_abbrev',
                'projections.position',
                'projections.source_games',
                'projections.source_sat',
                'projections.source_sog',
                'projections.source_goals',
                'projections.source_xgf',
                'projections.source_xsog',
                'projections.projected_games',
                'projections.projected_xsat',
                'projections.projected_xsog',
                'projections.projected_xgf',
                'projections.projected_goals',
                'projections.confidence_score',
                'projections.confidence_bucket',
                'projections.status',
                'projections.flags',
                'projections.projected_at',
            ])
            ->selectRaw(
                $hasSourceModelGoals
                    ? 'projections.source_model_goals'
                    : '0 as source_model_goals'
            )
            ->selectRaw(
                $hasFinishingAdjustment
                    ? 'projections.source_goals_above_xgf'
                    : 'NULL as source_goals_above_xgf'
            )
            ->selectRaw(
                $hasFinishingAdjustment
                    ? 'projections.finishing_regression_weight'
                    : 'NULL as finishing_regression_weight'
            )
            ->selectRaw(
                $hasFinishingAdjustment
                    ? 'projections.projected_goals_adjustment'
                    : 'NULL as projected_goals_adjustment'
            )
            ->selectRaw(
                "NULLIF(projections.projection_inputs->>'source_xsat_per_60', '')::numeric as source_xsat_per_60"
            )
            ->selectRaw(
                "NULLIF(projections.projection_inputs->>'source_xsog_per_60', '')::numeric as source_xsog_per_60"
            )
            ->selectRaw(
                "NULLIF(projections.projection_inputs->>'source_xgf_per_60', '')::numeric as source_xgf_per_60"
            )
            ->selectRaw(
                "CASE
                    WHEN NULLIF(projections.projection_inputs->>'projected_toi_hours', '')::numeric > 0
                    THEN projections.projected_xsat / NULLIF(projections.projection_inputs->>'projected_toi_hours', '')::numeric
                    ELSE NULL
                END as projected_xsat_per_60"
            )
            ->selectRaw(
                "CASE
                    WHEN NULLIF(projections.projection_inputs->>'projected_toi_hours', '')::numeric > 0
                    THEN projections.projected_xsog / NULLIF(projections.projection_inputs->>'projected_toi_hours', '')::numeric
                    ELSE NULL
                END as projected_xsog_per_60"
            )
            ->selectRaw(
                "CASE
                    WHEN NULLIF(projections.projection_inputs->>'projected_toi_hours', '')::numeric > 0
                    THEN projections.projected_xgf / NULLIF(projections.projection_inputs->>'projected_toi_hours', '')::numeric
                    ELSE NULL
                END as projected_xgf_per_60"
            )
            ->selectRaw("COALESCE(players.full_name, projections.player_id::text) as player_name");

        if (($filters['source_season_id'] ?? null) !== null && $filters['source_season_id'] !== '') {
            $query->where('projections.source_season_id', $filters['source_season_id']);
        }

        if (($filters['target_season_id'] ?? null) !== null && $filters['target_season_id'] !== '') {
            $query->where('projections.target_season_id', $filters['target_season_id']);
        }

        if (($filters['projection_version'] ?? null) !== null && $filters['projection_version'] !== '') {
            $query->where('projections.projection_version', $filters['projection_version']);
        }

        if (($filters['status'] ?? null) !== null && $filters['status'] !== '') {
            $query->where('projections.status', $filters['status']);
        }

        if (($filters['team_abbrev'] ?? null) !== null && $filters['team_abbrev'] !== '') {
            $query->where('projections.team_abbrev', mb_strtoupper((string) $filters['team_abbrev']));
        }

        if (($filters['position'] ?? null) !== null && $filters['position'] !== '') {
            $query->where('projections.position', mb_strtoupper((string) $filters['position']));
        }

        if (($filters['min_xsat'] ?? null) !== null && $filters['min_xsat'] !== '') {
            $query->where('projections.projected_xsat', '>=', (float) $filters['min_xsat']);
        }

        $playerSearch = trim((string) ($filters['player_search'] ?? ''));
        if ($playerSearch !== '') {
            $like = '%' . mb_strtolower($playerSearch) . '%';
            $query->where(function ($query) use ($like): void {
                $query->whereRaw("LOWER(COALESCE(players.full_name, '')) LIKE ?", [$like])
                    ->orWhereRaw('projections.player_id::text LIKE ?', [$like]);
            });
        }

        $sortColumn = $this->projectionSortColumns()[$sort] ?? 'projections.projected_xgf';
        if (!$hasSourceModelGoals && $sort === 'source_model_goals') {
            $sortColumn = 'source_model_goals';
        }

        if (
            !$hasFinishingAdjustment
            && in_array($sort, ['source_goals_above_xgf', 'finishing_regression_weight', 'projected_goals_adjustment'], true)
        ) {
            $sortColumn = $sort;
        }

        return $query
            ->orderBy($sortColumn, $direction)
            ->orderBy('player_name')
            ->limit(100)
            ->get();
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function toiProjectionRows(array $filters, string $sort, string $direction): Collection
    {
        $query = DB::table('nhl_player_toi_projections as projections')
            ->leftJoin('players', 'players.nhl_id', '=', 'projections.player_id')
            ->select([
                'projections.id',
                'projections.projection_version',
                'projections.source_season_id',
                'projections.target_season_id',
                'projections.player_id',
                'projections.source_team_abbrev',
                'projections.target_team_abbrev',
                'projections.position',
                'projections.age_years',
                'projections.source_games',
                'projections.source_toi_per_game_seconds',
                'projections.source_points',
                'projections.source_team_points_rank',
                'projections.source_role_bucket',
                'projections.target_team_points_rank',
                'projections.target_role_bucket',
                'projections.projected_games',
                'projections.projected_toi_per_game_seconds',
                'projections.projected_toi_hours',
                'projections.age_adjustment_seconds_per_game',
                'projections.role_adjustment_seconds_per_game',
                'projections.team_change_adjustment_seconds_per_game',
                'projections.confidence_score',
                'projections.confidence_bucket',
                'projections.status',
                'projections.flags',
                'projections.projected_at',
            ])
            ->selectRaw('projections.projected_toi_per_game_seconds - projections.source_toi_per_game_seconds as toi_diff_per_game_seconds')
            ->selectRaw("COALESCE(players.full_name, projections.player_id::text) as player_name");

        if (($filters['source_season_id'] ?? null) !== null && $filters['source_season_id'] !== '') {
            $query->where('projections.source_season_id', $filters['source_season_id']);
        }

        if (($filters['target_season_id'] ?? null) !== null && $filters['target_season_id'] !== '') {
            $query->where('projections.target_season_id', $filters['target_season_id']);
        }

        if (($filters['projection_version'] ?? null) !== null && $filters['projection_version'] !== '') {
            $query->where('projections.projection_version', $filters['projection_version']);
        }

        if (($filters['status'] ?? null) !== null && $filters['status'] !== '') {
            $query->where('projections.status', $filters['status']);
        }

        if (($filters['team_abbrev'] ?? null) !== null && $filters['team_abbrev'] !== '') {
            $query->where('projections.target_team_abbrev', mb_strtoupper((string) $filters['team_abbrev']));
        }

        if (($filters['position'] ?? null) !== null && $filters['position'] !== '') {
            $query->where('projections.position', mb_strtoupper((string) $filters['position']));
        }

        $playerSearch = trim((string) ($filters['player_search'] ?? ''));
        if ($playerSearch !== '') {
            $like = '%' . mb_strtolower($playerSearch) . '%';
            $query->where(function ($query) use ($like): void {
                $query->whereRaw("LOWER(COALESCE(players.full_name, '')) LIKE ?", [$like])
                    ->orWhereRaw('projections.player_id::text LIKE ?', [$like]);
            });
        }

        return $query
            ->orderBy($this->toiProjectionSortColumns()[$sort], $direction)
            ->orderBy('player_name')
            ->limit(100)
            ->get();
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function goalieWorkloadRows(array $filters, string $sort, string $direction): Collection
    {
        $careerGames = DB::table('stats')
            ->selectRaw('player_id')
            ->selectRaw('SUM(gp) as career_games')
            ->where('league_abbrev', 'NHL')
            ->where('game_type_id', 2)
            ->groupBy('player_id');

        $query = DB::table('nhl_goalie_workload_projections as projections')
            ->leftJoin('players', 'players.nhl_id', '=', 'projections.goalie_player_id')
            ->leftJoinSub($careerGames, 'career', 'career.player_id', '=', 'players.id')
            ->select([
                'projections.id',
                'projections.projection_version',
                'projections.source_season_id',
                'projections.target_season_id',
                'projections.goalie_player_id',
                'projections.source_team_abbrev',
                'projections.target_team_abbrev',
                'projections.position',
                'projections.source_role_bucket',
                'projections.target_role_bucket',
                'projections.source_games',
                'projections.source_starts',
                'projections.source_relief_games',
                'projections.source_toi_seconds',
                'projections.projected_games',
                'projections.projected_starts',
                'projections.projected_relief_games',
                'projections.projected_toi_seconds',
                'projections.projected_toi_hours',
                'projections.age_adjustment_starts',
                'projections.role_adjustment_starts',
                'projections.contract_adjustment_starts',
                'projections.durability_adjustment_starts',
                'projections.contract_aav',
                'projections.contract_years_remaining',
                'projections.team_contract_rank',
                'projections.confidence_score',
                'projections.confidence_bucket',
                'projections.status',
                'projections.flags',
                'projections.projected_at',
            ])
            ->selectRaw("COALESCE(players.full_name, projections.goalie_player_id::text) as goalie_name")
            ->selectRaw(
                "CASE
                    WHEN players.dob IS NULL THEN NULL
                    ELSE ROUND((DATE_PART('day', TO_DATE(SUBSTRING(projections.target_season_id, 1, 4) || '-09-15', 'YYYY-MM-DD')::timestamp - players.dob::timestamp) / 365.25)::numeric, 1)
                END as age_years"
            )
            ->selectRaw('COALESCE(career.career_games, 0) as career_games')
            ->selectRaw('projections.projected_starts - projections.source_starts as starts_diff');

        if (($filters['target_season_id'] ?? null) !== null && $filters['target_season_id'] !== '') {
            $query->where('projections.target_season_id', $filters['target_season_id']);
        }

        if (($filters['projection_version'] ?? null) !== null && $filters['projection_version'] !== '') {
            $query->where('projections.projection_version', $filters['projection_version']);
        }

        if (($filters['status'] ?? null) !== null && $filters['status'] !== '') {
            $query->where('projections.status', $filters['status']);
        }

        if (($filters['team_abbrev'] ?? null) !== null && $filters['team_abbrev'] !== '') {
            $query->where('projections.target_team_abbrev', mb_strtoupper((string) $filters['team_abbrev']));
        }

        if (($filters['min_career_gp'] ?? null) !== null && $filters['min_career_gp'] !== '') {
            $query->whereRaw('COALESCE(career.career_games, 0) >= ?', [(int) $filters['min_career_gp']]);
        }

        $goalieSearch = trim((string) ($filters['goalie_search'] ?? ''));
        if ($goalieSearch !== '') {
            $like = '%' . mb_strtolower($goalieSearch) . '%';
            $query->where(function ($query) use ($like): void {
                $query->whereRaw("LOWER(COALESCE(players.full_name, '')) LIKE ?", [$like])
                    ->orWhereRaw('projections.goalie_player_id::text LIKE ?', [$like]);
            });
        }

        return $query
            ->orderBy($this->goalieWorkloadSortColumns()[$sort], $direction)
            ->orderBy('goalie_name')
            ->limit(100)
            ->get();
    }

    /**
     * @param array<string, mixed> $filters
     * @return Collection<int, object>
     */
    private function goalieProjectionRows(array $filters, string $sort, string $direction): Collection
    {
        $query = DB::table('nhl_goalie_season_projections as projections')
            ->leftJoin('players', 'players.nhl_id', '=', 'projections.goalie_player_id')
            ->select([
                'projections.id',
                'projections.projection_version',
                'projections.goalie_workload_projection_version',
                'projections.toi_projection_version',
                'projections.source_season_id',
                'projections.target_season_id',
                'projections.goalie_player_id',
                'projections.source_team_abbrev',
                'projections.target_team_abbrev',
                'projections.position',
                'projections.source_games',
                'projections.source_toi_seconds',
                'projections.source_sat_against',
                'projections.source_sog_against',
                'projections.source_goals_against',
                'projections.source_xga',
                'projections.source_xsoga',
                'projections.source_gsax',
                'projections.projected_games',
                'projections.projected_starts',
                'projections.projected_relief_games',
                'projections.projected_toi_seconds',
                'projections.projected_toi_hours',
                'projections.projected_sata',
                'projections.projected_soga',
                'projections.projected_xga',
                'projections.projected_ga',
                'projections.projected_gsax',
                'projections.projected_xsoga',
                'projections.projected_ev_xga',
                'projections.projected_ev_ga',
                'projections.projected_pk_xga',
                'projections.projected_pk_ga',
                'projections.confidence_score',
                'projections.confidence_bucket',
                'projections.status',
                'projections.flags',
                'projections.projected_at',
            ])
            ->selectRaw("COALESCE(players.full_name, projections.goalie_player_id::text) as goalie_name")
            ->selectRaw('projections.projected_xga / NULLIF(projections.projected_games, 0) as projected_xga_per_game')
            ->selectRaw('projections.projected_ga / NULLIF(projections.projected_games, 0) as projected_ga_per_game')
            ->selectRaw('projections.projected_gsax / NULLIF(projections.projected_games, 0) as projected_gsax_per_game')
            ->selectRaw('projections.projected_xga * 3600 / NULLIF(projections.projected_toi_seconds, 0) as projected_xgaa')
            ->selectRaw('projections.projected_ga * 3600 / NULLIF(projections.projected_toi_seconds, 0) as projected_gaa')
            ->selectRaw('projections.projected_ev_xga / NULLIF(projections.projected_games, 0) as projected_ev_xga_per_game')
            ->selectRaw('projections.projected_ev_ga / NULLIF(projections.projected_games, 0) as projected_ev_ga_per_game')
            ->selectRaw('projections.projected_pk_xga / NULLIF(projections.projected_games, 0) as projected_pk_xga_per_game')
            ->selectRaw('projections.projected_pk_ga / NULLIF(projections.projected_games, 0) as projected_pk_ga_per_game');

        if (($filters['target_season_id'] ?? null) !== null && $filters['target_season_id'] !== '') {
            $query->where('projections.target_season_id', $filters['target_season_id']);
        }

        if (($filters['projection_version'] ?? null) !== null && $filters['projection_version'] !== '') {
            $query->where('projections.projection_version', $filters['projection_version']);
        }

        if (($filters['status'] ?? null) !== null && $filters['status'] !== '') {
            $query->where('projections.status', $filters['status']);
        }

        if (($filters['team_abbrev'] ?? null) !== null && $filters['team_abbrev'] !== '') {
            $query->where('projections.target_team_abbrev', mb_strtoupper((string) $filters['team_abbrev']));
        }

        if (($filters['min_projected_starts'] ?? null) !== null && $filters['min_projected_starts'] !== '') {
            $query->where('projections.projected_starts', '>=', (float) $filters['min_projected_starts']);
        }

        $goalieSearch = trim((string) ($filters['goalie_search'] ?? ''));
        if ($goalieSearch !== '') {
            $like = '%' . mb_strtolower($goalieSearch) . '%';
            $query->where(function ($query) use ($like): void {
                $query->whereRaw("LOWER(COALESCE(players.full_name, '')) LIKE ?", [$like])
                    ->orWhereRaw('projections.goalie_player_id::text LIKE ?', [$like]);
            });
        }

        return $query
            ->orderBy($this->goalieProjectionSortColumns()[$sort], $direction)
            ->orderBy('goalie_name')
            ->limit(100)
            ->get();
    }

    /**
     * @param array<string, mixed> $filters
     * @return Collection<int, object>
     */
    private function goalieProfileRows(array $filters, string $sort, string $direction): Collection
    {
        $query = DB::table('nhl_goalie_chance_profile_buckets as profiles')
            ->leftJoin('players', 'players.nhl_id', '=', 'profiles.goalie_player_id')
            ->select([
                'profiles.id',
                'profiles.source_season_id',
                'profiles.game_type',
                'profiles.goalie_player_id',
                'profiles.team_abbrev',
                'profiles.position',
                'profiles.matched_bucket_key',
                'profiles.fallback_level',
                'profiles.shot_type_group',
                'profiles.distance_group',
                'profiles.angle_group',
                'profiles.sequence_group',
                'profiles.source_games',
                'profiles.source_toi_seconds',
                'profiles.source_sat_against',
                'profiles.source_sog_against',
                'profiles.source_goals_against',
                'profiles.source_xga',
                'profiles.source_xsoga',
                'profiles.source_gsax',
                'profiles.source_gsax_per_100_sat_against',
                'profiles.source_profile_share',
                'profiles.goal_probability_against',
                'profiles.shot_on_goal_probability_against',
                'profiles.confidence_score',
                'profiles.confidence_bucket',
                'profiles.profiled_at',
            ])
            ->selectRaw("COALESCE(players.full_name, profiles.goalie_player_id::text) as goalie_name")
            ->selectRaw("
                CASE
                    WHEN profiles.source_toi_seconds > 0
                    THEN profiles.source_xga / (profiles.source_toi_seconds::numeric / 3600)
                    ELSE NULL
                END as source_xga_per_60
            ")
            ->selectRaw("
                CASE
                    WHEN profiles.source_toi_seconds > 0
                    THEN profiles.source_xsoga / (profiles.source_toi_seconds::numeric / 3600)
                    ELSE NULL
                END as source_xsoga_per_60
            ");

        if (($filters['season_id'] ?? null) !== null && $filters['season_id'] !== '') {
            $query->where('profiles.source_season_id', $filters['season_id']);
        }

        if (($filters['game_type'] ?? null) !== null && $filters['game_type'] !== '') {
            $query->where('profiles.game_type', (int) $filters['game_type']);
        }

        if (($filters['team_abbrev'] ?? null) !== null && $filters['team_abbrev'] !== '') {
            $query->where('profiles.team_abbrev', mb_strtoupper((string) $filters['team_abbrev']));
        }

        foreach (['shot_type_group', 'distance_group', 'angle_group', 'sequence_group'] as $column) {
            if (($filters[$column] ?? null) !== null && $filters[$column] !== '') {
                $query->where('profiles.' . $column, $filters[$column]);
            }
        }

        if (($filters['min_sat_against'] ?? null) !== null && $filters['min_sat_against'] !== '') {
            $query->where('profiles.source_sat_against', '>=', (int) $filters['min_sat_against']);
        }

        $goalieSearch = trim((string) ($filters['goalie_search'] ?? ''));
        if ($goalieSearch !== '') {
            $like = '%' . mb_strtolower($goalieSearch) . '%';
            $query->where(function ($query) use ($like): void {
                $query->whereRaw("LOWER(COALESCE(players.full_name, '')) LIKE ?", [$like])
                    ->orWhereRaw("LOWER(COALESCE(players.first_name, '')) LIKE ?", [$like])
                    ->orWhereRaw("LOWER(COALESCE(players.last_name, '')) LIKE ?", [$like])
                    ->orWhereRaw('profiles.goalie_player_id::text LIKE ?', [$like]);
            });
        }

        return $query
            ->orderBy($this->goalieProfileSortColumns()[$sort] ?? 'profiles.source_xga', $direction)
            ->orderBy('goalie_name')
            ->orderBy('profiles.fallback_level')
            ->limit(500)
            ->get();
    }

    /**
     * @param array<string, mixed> $filters
     * @return Collection<int, object>
     */
    private function skaterDProfileRows(array $filters, string $sort, string $direction): Collection
    {
        $query = DB::table('nhl_skater_defensive_chance_profile_buckets as profiles')
            ->leftJoin('players', 'players.nhl_id', '=', 'profiles.player_id')
            ->select([
                'profiles.id',
                'profiles.source_season_id',
                'profiles.game_type',
                'profiles.player_id',
                'profiles.team_abbrev',
                'profiles.position',
                'profiles.matched_bucket_key',
                'profiles.fallback_level',
                'profiles.shot_type_group',
                'profiles.distance_group',
                'profiles.angle_group',
                'profiles.sequence_group',
                'profiles.source_games',
                'profiles.source_toi_seconds',
                'profiles.source_sat_against_on_ice',
                'profiles.source_sog_against_on_ice',
                'profiles.source_goals_against_on_ice',
                'profiles.source_xga_on_ice',
                'profiles.source_xsoga_on_ice',
                'profiles.source_xga_per_60',
                'profiles.source_xsoga_per_60',
                'profiles.source_profile_share_against',
                'profiles.goal_probability_against',
                'profiles.shot_on_goal_probability_against',
                'profiles.confidence_score',
                'profiles.confidence_bucket',
                'profiles.profiled_at',
            ])
            ->selectRaw("COALESCE(players.full_name, profiles.player_id::text) as player_name");

        if (($filters['season_id'] ?? null) !== null && $filters['season_id'] !== '') {
            $query->where('profiles.source_season_id', $filters['season_id']);
        }

        if (($filters['game_type'] ?? null) !== null && $filters['game_type'] !== '') {
            $query->where('profiles.game_type', (int) $filters['game_type']);
        }

        if (($filters['team_abbrev'] ?? null) !== null && $filters['team_abbrev'] !== '') {
            $query->where('profiles.team_abbrev', mb_strtoupper((string) $filters['team_abbrev']));
        }

        if (($filters['position'] ?? null) !== null && $filters['position'] !== '') {
            $query->where('profiles.position', mb_strtoupper((string) $filters['position']));
        }

        foreach (['shot_type_group', 'distance_group', 'angle_group', 'sequence_group'] as $column) {
            if (($filters[$column] ?? null) !== null && $filters[$column] !== '') {
                $query->where('profiles.' . $column, $filters[$column]);
            }
        }

        if (($filters['min_sat_against'] ?? null) !== null && $filters['min_sat_against'] !== '') {
            $query->where('profiles.source_sat_against_on_ice', '>=', (int) $filters['min_sat_against']);
        }

        $playerSearch = trim((string) ($filters['player_search'] ?? ''));
        if ($playerSearch !== '') {
            $like = '%' . mb_strtolower($playerSearch) . '%';
            $query->where(function ($query) use ($like): void {
                $query->whereRaw("LOWER(COALESCE(players.full_name, '')) LIKE ?", [$like])
                    ->orWhereRaw("LOWER(COALESCE(players.first_name, '')) LIKE ?", [$like])
                    ->orWhereRaw("LOWER(COALESCE(players.last_name, '')) LIKE ?", [$like])
                    ->orWhereRaw('profiles.player_id::text LIKE ?', [$like]);
            });
        }

        return $query
            ->orderBy($this->skaterDProfileSortColumns()[$sort] ?? 'profiles.source_xga_on_ice', $direction)
            ->orderBy('player_name')
            ->orderBy('profiles.fallback_level')
            ->limit(500)
            ->get();
    }

    /**
     * @param array<string, mixed> $filters
     * @return Collection<int, object>
     */
    private function skaterOProfileRows(array $filters, string $sort, string $direction): Collection
    {
        $query = DB::table('nhl_skater_offensive_chance_profile_buckets as profiles')
            ->leftJoin('players', 'players.nhl_id', '=', 'profiles.player_id')
            ->select([
                'profiles.id',
                'profiles.source_season_id',
                'profiles.game_type',
                'profiles.player_id',
                'profiles.team_abbrev',
                'profiles.position',
                'profiles.matched_bucket_key',
                'profiles.fallback_level',
                'profiles.shot_type_group',
                'profiles.distance_group',
                'profiles.angle_group',
                'profiles.sequence_group',
                'profiles.source_games',
                'profiles.source_toi_seconds',
                'profiles.source_sat_for',
                'profiles.source_sog_for',
                'profiles.source_goals_for',
                'profiles.source_xgf',
                'profiles.source_xsog',
                'profiles.source_xgf_per_60',
                'profiles.source_xsog_per_60',
                'profiles.source_profile_share',
                'profiles.goal_probability',
                'profiles.shot_on_goal_probability',
                'profiles.confidence_score',
                'profiles.confidence_bucket',
                'profiles.profiled_at',
            ])
            ->selectRaw("COALESCE(players.full_name, profiles.player_id::text) as player_name");

        if (($filters['season_id'] ?? null) !== null && $filters['season_id'] !== '') {
            $query->where('profiles.source_season_id', $filters['season_id']);
        }

        if (($filters['game_type'] ?? null) !== null && $filters['game_type'] !== '') {
            $query->where('profiles.game_type', (int) $filters['game_type']);
        }

        if (($filters['team_abbrev'] ?? null) !== null && $filters['team_abbrev'] !== '') {
            $query->where('profiles.team_abbrev', mb_strtoupper((string) $filters['team_abbrev']));
        }

        if (($filters['position'] ?? null) !== null && $filters['position'] !== '') {
            $query->where('profiles.position', mb_strtoupper((string) $filters['position']));
        }

        foreach (['shot_type_group', 'distance_group', 'angle_group', 'sequence_group'] as $column) {
            if (($filters[$column] ?? null) !== null && $filters[$column] !== '') {
                $query->where('profiles.' . $column, $filters[$column]);
            }
        }

        if (($filters['min_sat_for'] ?? null) !== null && $filters['min_sat_for'] !== '') {
            $query->where('profiles.source_sat_for', '>=', (int) $filters['min_sat_for']);
        }

        $playerSearch = trim((string) ($filters['player_search'] ?? ''));
        if ($playerSearch !== '') {
            $like = '%' . mb_strtolower($playerSearch) . '%';
            $query->where(function ($query) use ($like): void {
                $query->whereRaw("LOWER(COALESCE(players.full_name, '')) LIKE ?", [$like])
                    ->orWhereRaw("LOWER(COALESCE(players.first_name, '')) LIKE ?", [$like])
                    ->orWhereRaw("LOWER(COALESCE(players.last_name, '')) LIKE ?", [$like])
                    ->orWhereRaw('profiles.player_id::text LIKE ?', [$like]);
            });
        }

        return $query
            ->orderBy($this->skaterOProfileSortColumns()[$sort] ?? 'profiles.source_xgf', $direction)
            ->orderBy('player_name')
            ->orderBy('profiles.fallback_level')
            ->limit(500)
            ->get();
    }

    /**
     * @param Collection<int, object> $projectionRows
     * @return Collection<int|string, Collection<int, object>>
     */
    private function projectionBucketRowsByProjection(
        Collection $projectionRows,
        string $sort,
        string $direction
    ): Collection {
        $projectionIds = $projectionRows
            ->pluck('id')
            ->filter()
            ->values();

        if ($projectionIds->isEmpty()) {
            return collect();
        }

        return DB::table('nhl_player_projection_profile_buckets')
            ->whereIn('player_season_projection_id', $projectionIds)
            ->select([
                'player_season_projection_id',
                'fallback_level',
                'shot_type_group',
                'distance_group',
                'angle_group',
                'sequence_group',
                'source_sat',
                'source_profile_share',
                'projected_xsat',
                'projected_xsog',
                'projected_xgf',
                'goal_probability',
                'shot_on_goal_probability',
            ])
            ->orderBy($this->projectionBucketSortColumns()[$sort], $direction)
            ->orderByDesc('source_sat')
            ->get()
            ->groupBy('player_season_projection_id');
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
     * @return array<string, string>
     */
    private function projectionSortColumns(): array
    {
        return [
            'player_name' => 'player_name',
            'team_abbrev' => 'projections.team_abbrev',
            'position' => 'projections.position',
            'source_games' => 'projections.source_games',
            'source_sat' => 'projections.source_sat',
            'source_sog' => 'projections.source_sog',
            'source_goals' => 'projections.source_goals',
            'source_model_goals' => 'projections.source_model_goals',
            'source_xgf' => 'projections.source_xgf',
            'source_goals_above_xgf' => 'projections.source_goals_above_xgf',
            'source_xsog' => 'projections.source_xsog',
            'source_xsat_per_60' => 'source_xsat_per_60',
            'source_xsog_per_60' => 'source_xsog_per_60',
            'source_xgf_per_60' => 'source_xgf_per_60',
            'projected_games' => 'projections.projected_games',
            'projected_xsat' => 'projections.projected_xsat',
            'projected_xsog' => 'projections.projected_xsog',
            'projected_xgf' => 'projections.projected_xgf',
            'projected_xsat_per_60' => 'projected_xsat_per_60',
            'projected_xsog_per_60' => 'projected_xsog_per_60',
            'projected_xgf_per_60' => 'projected_xgf_per_60',
            'finishing_regression_weight' => 'projections.finishing_regression_weight',
            'projected_goals_adjustment' => 'projections.projected_goals_adjustment',
            'projected_goals' => 'projections.projected_goals',
            'confidence_score' => 'projections.confidence_score',
            'confidence_bucket' => 'projections.confidence_bucket',
            'status' => 'projections.status',
            'projection_version' => 'projections.projection_version',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function toiProjectionSortColumns(): array
    {
        return [
            'player_name' => 'player_name',
            'source_team_abbrev' => 'projections.source_team_abbrev',
            'target_team_abbrev' => 'projections.target_team_abbrev',
            'position' => 'projections.position',
            'age_years' => 'projections.age_years',
            'source_games' => 'projections.source_games',
            'source_toi_per_game_seconds' => 'projections.source_toi_per_game_seconds',
            'source_points' => 'projections.source_points',
            'source_team_points_rank' => 'projections.source_team_points_rank',
            'source_role_bucket' => 'projections.source_role_bucket',
            'target_team_points_rank' => 'projections.target_team_points_rank',
            'target_role_bucket' => 'projections.target_role_bucket',
            'projected_games' => 'projections.projected_games',
            'projected_toi_per_game_seconds' => 'projections.projected_toi_per_game_seconds',
            'toi_diff_per_game_seconds' => 'toi_diff_per_game_seconds',
            'projected_toi_hours' => 'projections.projected_toi_hours',
            'age_adjustment_seconds_per_game' => 'projections.age_adjustment_seconds_per_game',
            'role_adjustment_seconds_per_game' => 'projections.role_adjustment_seconds_per_game',
            'confidence_score' => 'projections.confidence_score',
            'status' => 'projections.status',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function goalieWorkloadSortColumns(): array
    {
        return [
            'goalie_name' => 'goalie_name',
            'source_team_abbrev' => 'projections.source_team_abbrev',
            'target_team_abbrev' => 'projections.target_team_abbrev',
            'age_years' => 'age_years',
            'career_games' => 'career_games',
            'source_games' => 'projections.source_games',
            'source_starts' => 'projections.source_starts',
            'source_role_bucket' => 'projections.source_role_bucket',
            'target_role_bucket' => 'projections.target_role_bucket',
            'projected_games' => 'projections.projected_games',
            'projected_starts' => 'projections.projected_starts',
            'starts_diff' => 'starts_diff',
            'projected_relief_games' => 'projections.projected_relief_games',
            'projected_toi_hours' => 'projections.projected_toi_hours',
            'age_adjustment_starts' => 'projections.age_adjustment_starts',
            'role_adjustment_starts' => 'projections.role_adjustment_starts',
            'contract_adjustment_starts' => 'projections.contract_adjustment_starts',
            'durability_adjustment_starts' => 'projections.durability_adjustment_starts',
            'contract_aav' => 'projections.contract_aav',
            'team_contract_rank' => 'projections.team_contract_rank',
            'confidence_score' => 'projections.confidence_score',
            'status' => 'projections.status',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function goalieProjectionSortColumns(): array
    {
        return [
            'goalie_name' => 'goalie_name',
            'target_team_abbrev' => 'projections.target_team_abbrev',
            'source_games' => 'projections.source_games',
            'projected_games' => 'projections.projected_games',
            'projected_starts' => 'projections.projected_starts',
            'projected_toi_hours' => 'projections.projected_toi_hours',
            'projected_sata' => 'projections.projected_sata',
            'projected_soga' => 'projections.projected_soga',
            'projected_xga' => 'projections.projected_xga',
            'projected_ga' => 'projections.projected_ga',
            'projected_xga_per_game' => 'projected_xga_per_game',
            'projected_xgaa' => 'projected_xgaa',
            'projected_gaa' => 'projected_gaa',
            'projected_gsax' => 'projections.projected_gsax',
            'projected_ga_per_game' => 'projected_ga_per_game',
            'projected_gsax_per_game' => 'projected_gsax_per_game',
            'projected_ev_xga_per_game' => 'projected_ev_xga_per_game',
            'projected_ev_ga_per_game' => 'projected_ev_ga_per_game',
            'projected_pk_xga_per_game' => 'projected_pk_xga_per_game',
            'projected_pk_ga_per_game' => 'projected_pk_ga_per_game',
            'projected_xsoga' => 'projections.projected_xsoga',
            'confidence_score' => 'projections.confidence_score',
            'status' => 'projections.status',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function goalieProfileSortColumns(): array
    {
        return [
            'goalie_name' => 'goalie_name',
            'source_season_id' => 'profiles.source_season_id',
            'game_type' => 'profiles.game_type',
            'team_abbrev' => 'profiles.team_abbrev',
            'fallback_level' => 'profiles.fallback_level',
            'shot_type_group' => 'profiles.shot_type_group',
            'distance_group' => 'profiles.distance_group',
            'angle_group' => 'profiles.angle_group',
            'sequence_group' => 'profiles.sequence_group',
            'source_games' => 'profiles.source_games',
            'source_toi_seconds' => 'profiles.source_toi_seconds',
            'source_sat_against' => 'profiles.source_sat_against',
            'source_sog_against' => 'profiles.source_sog_against',
            'source_goals_against' => 'profiles.source_goals_against',
            'source_xga' => 'profiles.source_xga',
            'source_xsoga' => 'profiles.source_xsoga',
            'source_xga_per_60' => 'source_xga_per_60',
            'source_xsoga_per_60' => 'source_xsoga_per_60',
            'source_gsax' => 'profiles.source_gsax',
            'source_gsax_per_100_sat_against' => 'profiles.source_gsax_per_100_sat_against',
            'source_profile_share' => 'profiles.source_profile_share',
            'goal_probability_against' => 'profiles.goal_probability_against',
            'shot_on_goal_probability_against' => 'profiles.shot_on_goal_probability_against',
            'confidence_score' => 'profiles.confidence_score',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function skaterDProfileSortColumns(): array
    {
        return [
            'player_name' => 'player_name',
            'source_season_id' => 'profiles.source_season_id',
            'game_type' => 'profiles.game_type',
            'team_abbrev' => 'profiles.team_abbrev',
            'position' => 'profiles.position',
            'fallback_level' => 'profiles.fallback_level',
            'shot_type_group' => 'profiles.shot_type_group',
            'distance_group' => 'profiles.distance_group',
            'angle_group' => 'profiles.angle_group',
            'sequence_group' => 'profiles.sequence_group',
            'source_toi_seconds' => 'profiles.source_toi_seconds',
            'source_sat_against_on_ice' => 'profiles.source_sat_against_on_ice',
            'source_sog_against_on_ice' => 'profiles.source_sog_against_on_ice',
            'source_goals_against_on_ice' => 'profiles.source_goals_against_on_ice',
            'source_xga_on_ice' => 'profiles.source_xga_on_ice',
            'source_xsoga_on_ice' => 'profiles.source_xsoga_on_ice',
            'source_xga_per_60' => 'profiles.source_xga_per_60',
            'source_xsoga_per_60' => 'profiles.source_xsoga_per_60',
            'source_profile_share_against' => 'profiles.source_profile_share_against',
            'goal_probability_against' => 'profiles.goal_probability_against',
            'shot_on_goal_probability_against' => 'profiles.shot_on_goal_probability_against',
            'confidence_score' => 'profiles.confidence_score',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function skaterOProfileSortColumns(): array
    {
        return [
            'player_name' => 'player_name',
            'source_season_id' => 'profiles.source_season_id',
            'game_type' => 'profiles.game_type',
            'team_abbrev' => 'profiles.team_abbrev',
            'position' => 'profiles.position',
            'fallback_level' => 'profiles.fallback_level',
            'shot_type_group' => 'profiles.shot_type_group',
            'distance_group' => 'profiles.distance_group',
            'angle_group' => 'profiles.angle_group',
            'sequence_group' => 'profiles.sequence_group',
            'source_toi_seconds' => 'profiles.source_toi_seconds',
            'source_sat_for' => 'profiles.source_sat_for',
            'source_sog_for' => 'profiles.source_sog_for',
            'source_goals_for' => 'profiles.source_goals_for',
            'source_xgf' => 'profiles.source_xgf',
            'source_xsog' => 'profiles.source_xsog',
            'source_xgf_per_60' => 'profiles.source_xgf_per_60',
            'source_xsog_per_60' => 'profiles.source_xsog_per_60',
            'source_profile_share' => 'profiles.source_profile_share',
            'goal_probability' => 'profiles.goal_probability',
            'shot_on_goal_probability' => 'profiles.shot_on_goal_probability',
            'confidence_score' => 'profiles.confidence_score',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function projectionBucketSortColumns(): array
    {
        return [
            'fallback_level' => 'fallback_level',
            'shot_type_group' => 'shot_type_group',
            'distance_group' => 'distance_group',
            'angle_group' => 'angle_group',
            'sequence_group' => 'sequence_group',
            'source_sat' => 'source_sat',
            'source_profile_share' => 'source_profile_share',
            'projected_xsat' => 'projected_xsat',
            'projected_xsog' => 'projected_xsog',
            'projected_xgf' => 'projected_xgf',
            'goal_probability' => 'goal_probability',
            'shot_on_goal_probability' => 'shot_on_goal_probability',
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
            'positions' => [],
        ];
    }
}
