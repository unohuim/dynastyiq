<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
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
            'tab' => ['nullable', Rule::in(['explorer', 'aggregates', 'buckets', 'qa'])],
            'season_id' => ['nullable', 'digits:8'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'team_id' => ['nullable', 'integer'],
            'strength_bucket' => ['nullable', 'string', 'max:32'],
            'attempt_result' => ['nullable', 'string', 'max:32'],
            'distance_bucket' => ['nullable', 'string', 'max:32'],
            'angle_bucket' => ['nullable', 'string', 'max:32'],
            'group_by' => ['nullable', Rule::in([
                'team_abbrev',
                'shooter_player_id',
                'goalie_player_id',
                'strength_bucket',
                'attempt_result',
                'distance_bucket',
                'angle_bucket',
                'shot_type_bucket',
                'is_rebound',
                'previous_event_type',
            ])],
            'sort' => ['nullable', Rule::in([
                'group_value',
                'attempts',
                'unblocked_attempts',
                'shots_on_goal',
                'goals',
                'goal_rate',
                'avg_distance',
                'avg_angle',
            ])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);

        $tab = (string) ($input['tab'] ?? 'explorer');
        $filters = $this->filters($input);
        $tableExists = Schema::hasTable('nhl_shot_attempts_facts');

        return view('admin.nhl-shot-attempts.index', [
            'activeTab' => $tab,
            'filters' => $filters,
            'tableExists' => $tableExists,
            'summary' => $tableExists ? $this->summary($filters) : $this->emptySummary(),
            'explorerRows' => $tableExists && $tab === 'explorer'
                ? $this->explorerRows($filters)
                : $this->emptyPaginator(),
            'aggregateRows' => $tableExists && $tab === 'aggregates'
                ? $this->aggregateRows(
                    $filters,
                    (string) ($input['group_by'] ?? 'team_abbrev'),
                    (string) ($input['sort'] ?? 'attempts'),
                    (string) ($input['direction'] ?? 'desc')
                )
                : collect(),
            'bucketRows' => $tableExists && $tab === 'buckets'
                ? $this->bucketRows($filters)
                : collect(),
            'qaRows' => $tableExists && $tab === 'qa'
                ? $this->qaRows($filters)
                : $this->emptyQaRows(),
            'groupBy' => (string) ($input['group_by'] ?? 'team_abbrev'),
            'options' => $tableExists ? $this->filterOptions() : $this->emptyOptions(),
            'sort' => (string) ($input['sort'] ?? 'attempts'),
            'direction' => (string) ($input['direction'] ?? 'desc'),
        ]);
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
        ] as $column) {
            if ($filters[$column] !== null && $filters[$column] !== '') {
                $query->where('nhl_shot_attempts_facts.' . $column, $filters[$column]);
            }
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
    private function explorerRows(array $filters): LengthAwarePaginator
    {
        return $this->baseQuery($filters)
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
                'distance_bucket',
                'angle_bucket',
                'shot_type_bucket',
                'is_rebound',
                'is_rush',
                'is_goal',
            ])
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

        if ($groupBy === 'goalie_player_id') {
            $query->leftJoin('players as goalies', 'goalies.nhl_id', '=', 'nhl_shot_attempts_facts.goalie_player_id');
            $groupExpression = "COALESCE(goalies.full_name, nhl_shot_attempts_facts.goalie_player_id::text)";
        }

        if ($groupBy === 'shooter_player_id') {
            $query->leftJoin('players as shooters', 'shooters.nhl_id', '=', 'nhl_shot_attempts_facts.shooter_player_id');
            $groupExpression = "COALESCE(shooters.full_name, nhl_shot_attempts_facts.shooter_player_id::text)";
        }

        return $query
            ->selectRaw($groupExpression . ' as group_value')
            ->selectRaw('COUNT(*) as attempts')
            ->selectRaw('SUM(CASE WHEN is_unblocked_attempt THEN 1 ELSE 0 END) as unblocked_attempts')
            ->selectRaw('SUM(CASE WHEN is_shot_on_goal THEN 1 ELSE 0 END) as shots_on_goal')
            ->selectRaw('SUM(CASE WHEN is_goal THEN 1 ELSE 0 END) as goals')
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
    private function bucketRows(array $filters)
    {
        return $this->baseQuery($filters)
            ->select(['distance_bucket', 'angle_bucket', 'strength_bucket'])
            ->selectRaw('COUNT(*) as attempts')
            ->selectRaw('SUM(CASE WHEN is_goal THEN 1 ELSE 0 END) as goals')
            ->selectRaw('SUM(CASE WHEN is_shot_on_goal THEN 1 ELSE 0 END) as shots_on_goal')
            ->selectRaw('AVG(shot_distance) as avg_distance')
            ->selectRaw('AVG(abs_shot_angle) as avg_angle')
            ->groupBy('distance_bucket', 'angle_bucket', 'strength_bucket')
            ->orderByDesc('attempts')
            ->limit(150)
            ->get();
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
        ];
    }
}
