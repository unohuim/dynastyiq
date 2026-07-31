<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/**
 * Admin analysis panel for NHL faceoff facts.
 */
class NhlFaceoffController extends Controller
{
    /**
     * Render the read-only faceoff analysis panel.
     */
    public function index(Request $request): View
    {
        $input = $request->validate([
            'tab' => ['nullable', Rule::in(['teams', 'players', 'units', 'games'])],
            'season_id' => ['nullable', 'digits:8'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'team_id' => ['nullable', 'integer'],
            'zone_bucket' => ['nullable', 'string', 'max:32'],
            'advancement_bucket' => ['nullable', 'string', 'max:32'],
            'strength_bucket' => ['nullable', 'string', 'max:32'],
            'min_faceoffs' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'sort' => ['nullable', Rule::in($this->allSortKeys())],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);

        $tab = (string) ($input['tab'] ?? 'teams');
        $tableExists = Schema::hasTable('nhl_faceoff_facts');
        $filters = $this->filters($input, $tableExists);
        $sort = $this->sortKey($tab, (string) ($input['sort'] ?? ''));
        $direction = $this->sortDirection((string) ($input['direction'] ?? ''));

        return view('admin.nhl-faceoffs.index', [
            'activeTab' => $tab,
            'filters' => $filters,
            'tableExists' => $tableExists,
            'summary' => $tableExists ? $this->summary($filters) : $this->emptySummary(),
            'teamRows' => $tableExists && $tab === 'teams'
                ? $this->teamRows($filters, $sort, $direction)
                : collect(),
            'playerRows' => $tableExists && $tab === 'players'
                ? $this->playerRows($filters, $sort, $direction)
                : collect(),
            'unitRows' => $tableExists && $tab === 'units'
                ? $this->unitRows($filters, $sort, $direction)
                : collect(),
            'gameRows' => $tableExists && $tab === 'games'
                ? $this->gameRows($filters, $sort, $direction)
                : collect(),
            'options' => $tableExists ? $this->filterOptions() : $this->emptyOptions(),
            'sort' => $sort,
            'direction' => $direction,
        ]);
    }

    /**
     * Normalize filter input and default to the latest available season.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function filters(array $input, bool $tableExists): array
    {
        return [
            'season_id' => (string) ($input['season_id'] ?? ($tableExists ? $this->latestSeasonId() : '')),
            'start_date' => (string) ($input['start_date'] ?? ''),
            'end_date' => (string) ($input['end_date'] ?? ''),
            'team_id' => (string) ($input['team_id'] ?? ''),
            'zone_bucket' => (string) ($input['zone_bucket'] ?? ''),
            'advancement_bucket' => (string) ($input['advancement_bucket'] ?? ''),
            'strength_bucket' => (string) ($input['strength_bucket'] ?? ''),
            'min_faceoffs' => (string) ($input['min_faceoffs'] ?? ''),
        ];
    }

    /**
     * Build a base facts query using filters that apply to raw facts.
     *
     * @param array<string, mixed> $filters
     */
    private function baseFactsQuery(array $filters, bool $filterPerspectiveZone = true): Builder
    {
        return DB::table('nhl_faceoff_facts as facts')
            ->when($filters['season_id'] !== '', fn (Builder $query) => $query->where('facts.season_id', $filters['season_id']))
            ->when($filters['start_date'] !== '', fn (Builder $query) => $query->whereDate('facts.game_date', '>=', $filters['start_date']))
            ->when($filters['end_date'] !== '', fn (Builder $query) => $query->whereDate('facts.game_date', '<=', $filters['end_date']))
            ->when($filterPerspectiveZone && $filters['zone_bucket'] !== '', function (Builder $query) use ($filters): void {
                $query->where(function (Builder $nested) use ($filters): void {
                    $nested->where('facts.winning_team_zone_bucket', $filters['zone_bucket'])
                        ->orWhere('facts.losing_team_zone_bucket', $filters['zone_bucket']);
                });
            })
            ->when($filters['advancement_bucket'] !== '', fn (Builder $query) => $query->where('facts.advancement_bucket', $filters['advancement_bucket']))
            ->when($filters['strength_bucket'] !== '', fn (Builder $query) => $query->where('facts.strength_bucket', $filters['strength_bucket']));
    }

    /**
     * Build a side-perspective query so totals include both teams taking the draw.
     *
     * @param array<string, mixed> $filters
     */
    private function sideRowsQuery(array $filters): Builder
    {
        $winning = $this->baseFactsQuery($filters, false)
            ->selectRaw("
                facts.id,
                facts.nhl_game_id,
                facts.game_date,
                facts.winning_team_id as team_id,
                facts.winning_team_abbrev as team_abbrev,
                facts.winning_player_id as player_id,
                facts.winning_unit_id as unit_id,
                facts.winning_team_zone_bucket as zone_bucket,
                1 as is_win,
                facts.advancement_bucket
            ");

        $losing = $this->baseFactsQuery($filters, false)
            ->selectRaw("
                facts.id,
                facts.nhl_game_id,
                facts.game_date,
                facts.losing_team_id as team_id,
                facts.losing_team_abbrev as team_abbrev,
                facts.losing_player_id as player_id,
                facts.losing_unit_id as unit_id,
                facts.losing_team_zone_bucket as zone_bucket,
                0 as is_win,
                null as advancement_bucket
            ");

        return DB::query()
            ->fromSub($winning->unionAll($losing), 'sides')
            ->when($filters['team_id'] !== '', fn (Builder $query) => $query->where('team_id', (int) $filters['team_id']))
            ->when($filters['zone_bucket'] !== '', fn (Builder $query) => $query->where('zone_bucket', $filters['zone_bucket']));
    }

    /**
     * Summary counts for the current filter set.
     *
     * @param array<string, mixed> $filters
     *
     * @return array<string, int|float|null>
     */
    private function summary(array $filters): array
    {
        $facts = $this->baseFactsQuery($filters)
            ->when($filters['team_id'] !== '', function (Builder $query) use ($filters): void {
                $query->where(function (Builder $nested) use ($filters): void {
                    $nested->where('facts.winning_team_id', (int) $filters['team_id'])
                        ->orWhere('facts.losing_team_id', (int) $filters['team_id']);
                });
            })
            ->selectRaw("
                COUNT(*) as faceoffs,
                SUM(CASE WHEN advancement_bucket = 'advanced' THEN 1 ELSE 0 END) as advanced,
                SUM(CASE WHEN advancement_bucket = 'held' THEN 1 ELSE 0 END) as held,
                SUM(CASE WHEN advancement_bucket = 'retreated' THEN 1 ELSE 0 END) as retreated,
                SUM(CASE WHEN next_event_type IS NULL THEN 1 ELSE 0 END) as missing_next_event,
                COUNT(DISTINCT nhl_game_id) as games
            ")
            ->first();

        $faceoffs = (int) ($facts?->faceoffs ?? 0);

        return [
            'faceoffs' => $faceoffs,
            'games' => (int) ($facts?->games ?? 0),
            'advanced' => (int) ($facts?->advanced ?? 0),
            'held' => (int) ($facts?->held ?? 0),
            'retreated' => (int) ($facts?->retreated ?? 0),
            'missing_next_event' => (int) ($facts?->missing_next_event ?? 0),
            'advanced_rate' => $faceoffs > 0 ? ((int) ($facts?->advanced ?? 0) / $faceoffs) * 100 : null,
            'held_rate' => $faceoffs > 0 ? ((int) ($facts?->held ?? 0) / $faceoffs) * 100 : null,
            'retreated_rate' => $faceoffs > 0 ? ((int) ($facts?->retreated ?? 0) / $faceoffs) * 100 : null,
        ];
    }

    /**
     * Aggregate faceoffs by team.
     *
     * @param array<string, mixed> $filters
     *
     * @return Collection<int, object>
     */
    private function teamRows(array $filters, string $sort, string $direction): Collection
    {
        $query = $this->sideRowsQuery($filters)
            ->whereNotNull('team_id')
            ->selectRaw($this->sideAggregateSelect('team_id, team_abbrev'))
            ->groupBy('team_id', 'team_abbrev');

        $this->applyMinFaceoffs($query, $filters);

        return $this->applyAggregateSort($query, $sort, $direction)->limit(100)->get();
    }

    /**
     * Aggregate faceoffs by player.
     *
     * @param array<string, mixed> $filters
     *
     * @return Collection<int, object>
     */
    private function playerRows(array $filters, string $sort, string $direction): Collection
    {
        $sideRows = $this->sideRowsQuery($filters)
            ->whereNotNull('player_id')
            ->selectRaw($this->sideAggregateSelect('player_id, team_abbrev'))
            ->groupBy('player_id', 'team_abbrev');

        $this->applyMinFaceoffs($sideRows, $filters);

        $query = DB::query()
            ->fromSub($sideRows, 'rows')
            ->leftJoin('players', 'players.nhl_id', '=', 'rows.player_id')
            ->selectRaw("
                rows.*,
                COALESCE(players.full_name, trim(CONCAT(players.first_name, ' ', players.last_name)), CAST(rows.player_id AS TEXT)) as player_name
            ");

        return $this->applyAggregateSort($query, $sort, $direction)->limit(150)->get();
    }

    /**
     * Aggregate faceoffs by unit.
     *
     * @param array<string, mixed> $filters
     *
     * @return Collection<int, object>
     */
    private function unitRows(array $filters, string $sort, string $direction): Collection
    {
        $query = $this->sideRowsQuery($filters)
            ->whereNotNull('unit_id')
            ->selectRaw($this->sideAggregateSelect('unit_id, team_abbrev'))
            ->groupBy('unit_id', 'team_abbrev');

        $this->applyMinFaceoffs($query, $filters);

        return $this->applyAggregateSort($query, $sort, $direction)->limit(150)->get();
    }

    /**
     * Apply the aggregate minimum faceoff threshold when requested.
     *
     * @param array<string, mixed> $filters
     */
    private function applyMinFaceoffs(Builder $query, array $filters): void
    {
        if ($filters['min_faceoffs'] === '') {
            return;
        }

        $query->havingRaw('COUNT(*) >= ?', [(int) $filters['min_faceoffs']]);
    }

    /**
     * Aggregate faceoffs by game.
     *
     * @param array<string, mixed> $filters
     *
     * @return Collection<int, object>
     */
    private function gameRows(array $filters, string $sort, string $direction): Collection
    {
        $query = $this->baseFactsQuery($filters)
            ->join('nhl_games as games', 'games.nhl_game_id', '=', 'facts.nhl_game_id')
            ->when($filters['team_id'] !== '', function (Builder $query) use ($filters): void {
                $query->where(function (Builder $nested) use ($filters): void {
                    $nested->where('facts.winning_team_id', (int) $filters['team_id'])
                        ->orWhere('facts.losing_team_id', (int) $filters['team_id']);
                });
            })
            ->selectRaw("
                facts.nhl_game_id,
                MAX(facts.game_date) as game_date,
                MAX(games.away_team_abbrev) as away_team_abbrev,
                MAX(games.home_team_abbrev) as home_team_abbrev,
                COUNT(*) as faceoffs,
                SUM(CASE WHEN facts.winning_team_zone_bucket = 'offensive' THEN 1 ELSE 0 END) as offensive_zone,
                SUM(CASE WHEN facts.winning_team_zone_bucket = 'neutral' THEN 1 ELSE 0 END) as neutral_zone,
                SUM(CASE WHEN facts.winning_team_zone_bucket = 'defensive' THEN 1 ELSE 0 END) as defensive_zone,
                SUM(CASE WHEN facts.advancement_bucket = 'advanced' THEN 1 ELSE 0 END) as advanced,
                SUM(CASE WHEN facts.advancement_bucket = 'held' THEN 1 ELSE 0 END) as held,
                SUM(CASE WHEN facts.advancement_bucket = 'retreated' THEN 1 ELSE 0 END) as retreated,
                AVG(CASE WHEN facts.advancement_bucket = 'advanced' THEN 1.0 ELSE 0.0 END) * 100 as advanced_rate,
                AVG(CASE WHEN facts.advancement_bucket = 'held' THEN 1.0 ELSE 0.0 END) * 100 as held_rate,
                AVG(CASE WHEN facts.advancement_bucket = 'retreated' THEN 1.0 ELSE 0.0 END) * 100 as retreated_rate,
                SUM(CASE WHEN facts.next_event_type IS NULL THEN 1 ELSE 0 END) as missing_next_event
            ")
            ->groupBy('facts.nhl_game_id');

        return $this->applyGameSort($query, $sort, $direction)->limit(150)->get();
    }

    /**
     * Shared aggregate select for side-perspective rows.
     */
    private function sideAggregateSelect(string $groupColumns): string
    {
        return "
            {$groupColumns},
            COUNT(*) as faceoffs,
            SUM(CASE WHEN zone_bucket = 'offensive' THEN 1 ELSE 0 END) as offensive_zone,
            SUM(CASE WHEN zone_bucket = 'neutral' THEN 1 ELSE 0 END) as neutral_zone,
            SUM(CASE WHEN zone_bucket = 'defensive' THEN 1 ELSE 0 END) as defensive_zone,
            SUM(CASE WHEN advancement_bucket = 'advanced' THEN 1 ELSE 0 END) as advanced,
            SUM(CASE WHEN advancement_bucket = 'held' THEN 1 ELSE 0 END) as held,
            SUM(CASE WHEN advancement_bucket = 'retreated' THEN 1 ELSE 0 END) as retreated,
            AVG(CASE WHEN advancement_bucket = 'advanced' THEN 1.0 ELSE 0.0 END) * 100 as advanced_rate,
            AVG(CASE WHEN advancement_bucket = 'held' THEN 1.0 ELSE 0.0 END) * 100 as held_rate,
            AVG(CASE WHEN advancement_bucket = 'retreated' THEN 1.0 ELSE 0.0 END) * 100 as retreated_rate
        ";
    }

    /**
     * Apply a whitelisted aggregate sort.
     */
    private function applyAggregateSort(Builder $query, string $sort, string $direction): Builder
    {
        $columns = [
            'team_abbrev' => 'team_abbrev',
            'team_id' => 'team_id',
            'player_name' => 'player_name',
            'player_id' => 'player_id',
            'unit_id' => 'unit_id',
            'faceoffs' => 'faceoffs',
            'offensive_zone' => 'offensive_zone',
            'neutral_zone' => 'neutral_zone',
            'defensive_zone' => 'defensive_zone',
            'advanced' => 'advanced',
            'held' => 'held',
            'retreated' => 'retreated',
            'advanced_rate' => 'advanced_rate',
            'held_rate' => 'held_rate',
            'retreated_rate' => 'retreated_rate',
        ];

        return $query->orderBy($columns[$sort] ?? 'faceoffs', $direction);
    }

    /**
     * Apply a whitelisted game sort.
     */
    private function applyGameSort(Builder $query, string $sort, string $direction): Builder
    {
        $columns = [
            'game_date' => 'game_date',
            'nhl_game_id' => 'nhl_game_id',
            'matchup' => 'away_team_abbrev',
            'faceoffs' => 'faceoffs',
            'offensive_zone' => 'offensive_zone',
            'neutral_zone' => 'neutral_zone',
            'defensive_zone' => 'defensive_zone',
            'advanced' => 'advanced',
            'held' => 'held',
            'retreated' => 'retreated',
            'advanced_rate' => 'advanced_rate',
            'held_rate' => 'held_rate',
            'retreated_rate' => 'retreated_rate',
            'missing_next_event' => 'missing_next_event',
        ];

        return $query->orderBy($columns[$sort] ?? 'game_date', $direction);
    }

    /**
     * Return filter option values from current facts.
     *
     * @return array<string, Collection<int, mixed>>
     */
    private function filterOptions(): array
    {
        return [
            'seasons' => DB::table('nhl_faceoff_facts')->whereNotNull('season_id')->distinct()->orderByDesc('season_id')->pluck('season_id'),
            'zones' => DB::table('nhl_faceoff_facts')->whereNotNull('winning_team_zone_bucket')->distinct()->orderBy('winning_team_zone_bucket')->pluck('winning_team_zone_bucket'),
            'advancements' => DB::table('nhl_faceoff_facts')->whereNotNull('advancement_bucket')->distinct()->orderBy('advancement_bucket')->pluck('advancement_bucket'),
            'strengths' => DB::table('nhl_faceoff_facts')->whereNotNull('strength_bucket')->distinct()->orderBy('strength_bucket')->pluck('strength_bucket'),
        ];
    }

    /**
     * Empty filter option values for missing-table environments.
     *
     * @return array<string, Collection<int, mixed>>
     */
    private function emptyOptions(): array
    {
        return [
            'seasons' => collect(),
            'zones' => collect(),
            'advancements' => collect(),
            'strengths' => collect(),
        ];
    }

    /**
     * Empty summary for missing-table environments.
     *
     * @return array<string, int|float|null>
     */
    private function emptySummary(): array
    {
        return [
            'faceoffs' => 0,
            'games' => 0,
            'advanced' => 0,
            'held' => 0,
            'retreated' => 0,
            'missing_next_event' => 0,
            'advanced_rate' => null,
            'held_rate' => null,
            'retreated_rate' => null,
        ];
    }

    /**
     * Resolve the latest available season id.
     */
    private function latestSeasonId(): string
    {
        return (string) (DB::table('nhl_faceoff_facts')->max('season_id') ?? '');
    }

    /**
     * Resolve the active sort key for the selected tab.
     */
    private function sortKey(string $tab, string $sort): string
    {
        $defaults = [
            'teams' => 'faceoffs',
            'players' => 'faceoffs',
            'units' => 'faceoffs',
            'games' => 'game_date',
        ];

        $allowed = $this->sortKeysForTab($tab);

        return in_array($sort, $allowed, true) ? $sort : ($defaults[$tab] ?? 'faceoffs');
    }

    /**
     * Resolve sort direction, defaulting to descending.
     */
    private function sortDirection(string $direction): string
    {
        return $direction === 'asc' ? 'asc' : 'desc';
    }

    /**
     * Return all allowed sort keys for validation.
     *
     * @return array<int, string>
     */
    private function allSortKeys(): array
    {
        return array_values(array_unique(array_merge(
            $this->sortKeysForTab('teams'),
            $this->sortKeysForTab('players'),
            $this->sortKeysForTab('units'),
            $this->sortKeysForTab('games'),
        )));
    }

    /**
     * Return allowed sort keys for a tab.
     *
     * @return array<int, string>
     */
    private function sortKeysForTab(string $tab): array
    {
        return match ($tab) {
            'teams' => [
                'team_abbrev',
                'team_id',
                'faceoffs',
                'offensive_zone',
                'neutral_zone',
                'defensive_zone',
                'advanced_rate',
                'held_rate',
                'retreated_rate',
                'advanced',
                'held',
                'retreated',
            ],
            'players' => [
                'player_name',
                'player_id',
                'team_abbrev',
                'faceoffs',
                'offensive_zone',
                'neutral_zone',
                'defensive_zone',
                'advanced_rate',
                'held_rate',
                'retreated_rate',
                'advanced',
                'held',
                'retreated',
            ],
            'units' => [
                'unit_id',
                'team_abbrev',
                'faceoffs',
                'offensive_zone',
                'neutral_zone',
                'defensive_zone',
                'advanced_rate',
                'held_rate',
                'retreated_rate',
                'advanced',
                'held',
                'retreated',
            ],
            'games' => [
                'game_date',
                'nhl_game_id',
                'matchup',
                'faceoffs',
                'offensive_zone',
                'neutral_zone',
                'defensive_zone',
                'advanced_rate',
                'held_rate',
                'retreated_rate',
                'advanced',
                'held',
                'retreated',
                'missing_next_event',
            ],
            default => ['faceoffs'],
        };
    }
}
