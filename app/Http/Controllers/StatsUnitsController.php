<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StatsUnitsController extends Controller
{
    /** @var string[] allow-list for sortable columns on s.* */
    private array $sortable = [
        'gp',
        'gf', 'ga',
        'sf', 'sa',
        'satf', 'sata', 'ff', 'fa', 'bf', 'ba', 'hf', 'ha', 'fow', 'fol', 'fot',
        'ozs', 'nzs', 'dzs', 'shifts', 'toi', 'pim_f', 'pim_a', 'penalties_f', 'penalties_a',
    ];

    /** @var string[] */
    private array $totalFields = [
        'toi',
        'shifts',
        'ozs',
        'nzs',
        'dzs',
        'gf',
        'ga',
        'sf',
        'sa',
        'satf',
        'sata',
        'ff',
        'fa',
        'bf',
        'ba',
        'hf',
        'ha',
        'fow',
        'fol',
        'fot',
        'pim_f',
        'pim_a',
        'penalties_f',
        'penalties_a',
    ];

    public function index(Request $request)
    {
        $perPage = (int) $request->integer('per_page', 30);
        $sort    = (string) $request->get('sort', 'gf');
        $dir     = strtolower((string) $request->get('dir', 'desc'));
        $dir     = in_array($dir, ['asc', 'desc'], true) ? $dir : 'desc';
        $gameType = (int) $request->integer('game_type', 2);
        $displayMode = (string) $request->get('display', 'counts');
        $filters = [
            'gp_min' => $this->integerFilter($request, 'gp_min'),
            'gp_max' => $this->integerFilter($request, 'gp_max'),
            'shifts_min' => $this->integerFilter($request, 'shifts_min'),
            'shifts_max' => $this->integerFilter($request, 'shifts_max'),
            'toi_min' => $this->integerFilter($request, 'toi_min'),
            'toi_max' => $this->integerFilter($request, 'toi_max'),
            'gf_min' => $this->integerFilter($request, 'gf_min'),
            'gf_max' => $this->integerFilter($request, 'gf_max'),
            'sf_min' => $this->integerFilter($request, 'sf_min'),
            'sf_max' => $this->integerFilter($request, 'sf_max'),
            'satf_min' => $this->integerFilter($request, 'satf_min'),
            'satf_max' => $this->integerFilter($request, 'satf_max'),
        ];

        if (! in_array($sort, $this->sortable, true)) {
            $sort = 'gf';
        }

        if (! in_array($gameType, [1, 2, 3], true)) {
            $gameType = 2;
        }

        if (! in_array($displayMode, ['counts', 'share'], true)) {
            $displayMode = 'counts';
        }

        // Filter by unit type(s): F/D/PP/PK[/G] (default: F only)
        $pos = collect((array) $request->input('pos', ['F']))
            ->map(fn ($v) => strtoupper((string) $v))
            ->intersect(['F', 'D', 'PP', 'PK', 'G'])
            ->values()
            ->all();
        if (empty($pos)) {
            $pos = ['F'];
        }

        $playerNamesExpr = $this->playerNamesExpression();

        $availableSeasons = DB::table('nhl_unit_game_strength_summaries as s')
            ->join('nhl_games as g', 'g.nhl_game_id', '=', 's.nhl_game_id')
            ->select('g.season_id')
            ->distinct()
            ->orderByDesc('g.season_id')
            ->pluck('g.season_id')
            ->map(static fn (mixed $season): string => (string) $season)
            ->values()
            ->all();
        $seasonId = (string) $request->get('season_id', $availableSeasons[0] ?? '');

        if ($seasonId === '' || ! in_array($seasonId, $availableSeasons, true)) {
            $seasonId = $availableSeasons[0] ?? '';
        }

        $teamOptions = DB::table('nhl_unit_game_strength_summaries as s')
            ->join('nhl_units as u', 'u.id', '=', 's.unit_id')
            ->leftJoin('nhl_games as g', 'g.nhl_game_id', '=', 's.nhl_game_id')
            ->where('g.season_id', $seasonId)
            ->where('g.game_type', $gameType)
            ->whereIn('u.unit_type', $pos)
            ->whereNotNull('s.team_abbrev')
            ->distinct()
            ->orderBy('s.team_abbrev')
            ->pluck('s.team_abbrev')
            ->map(static fn (mixed $team): string => strtoupper((string) $team))
            ->filter()
            ->values()
            ->all();
        $team = strtoupper((string) $request->get('team', ''));

        if ($team !== '' && ! in_array($team, $teamOptions, true)) {
            $team = '';
        }

        $sumSelects = collect($this->totalFields)
            ->map(static fn (string $field): string => "SUM(s.{$field}) as {$field}")
            ->implode(', ');

        $q = DB::table('nhl_unit_game_strength_summaries as s')
            ->join('nhl_units as u', 'u.id', '=', 's.unit_id')
            ->leftJoin('nhl_games as g', 'g.nhl_game_id', '=', 's.nhl_game_id')
            ->selectRaw(
                "s.unit_id, u.unit_type, COALESCE(u.team_abbrev, MAX(s.team_abbrev)) as team_abbrev, " .
                "g.season_id, g.game_type, COUNT(DISTINCT s.nhl_game_id) as gp, {$sumSelects}"
            )
            ->where('g.season_id', $seasonId)
            ->where('g.game_type', $gameType)
            ->whereIn('u.unit_type', $pos)
            ->when($team !== '', function ($qq) use ($team) {
                $qq->where('s.team_abbrev', $team);
            })
            ->groupBy('s.unit_id', 'u.unit_type', 'u.team_abbrev', 'g.season_id', 'g.game_type');

        $filterStats = $this->filterStats(clone $q);
        $filterBounds = $filterStats['bounds'];
        $filterDefaults = $filterStats['defaults'];
        $filters = $this->withDefaultFilters($request, $filters, $filterDefaults);
        $this->applyAggregateFilters($q, $filters);

        $q->selectSub(function ($sub) use ($playerNamesExpr) {
            $sub->from('nhl_unit_players as up')
                ->join('players as p', 'p.id', '=', 'up.player_id')
                ->whereColumn('up.unit_id', 's.unit_id')
                ->selectRaw($playerNamesExpr);
        }, 'player_names');

        $this->applyOrdering($q, $sort, $dir, $displayMode);

        $units = $q->paginate($perPage)->withQueryString();
        $units->getCollection()->transform(function (object $row): object {
            $row->players = $this->unitPlayers((int) $row->unit_id);

            return $row;
        });

        return view('stats-units', [
            'units'    => $units,
            'sortable' => $this->sortable,
            'sort'     => $sort,
            'dir'      => $dir,
            'perPage'  => $perPage,
            'team'     => $team,
            'teamOptions' => $teamOptions,
            'pos'      => $pos,
            'availableSeasons' => $availableSeasons,
            'seasonId' => $seasonId,
            'seasonLabel' => $this->seasonLabel($seasonId),
            'gameType' => $gameType,
            'gameTypeLabel' => $this->gameTypeLabel($gameType),
            'displayMode' => $displayMode,
            'filterBounds' => $filterBounds,
            'filterDefaults' => $filterDefaults,
            'filters' => $filters,
        ]);
    }

    /**
     * Read an integer filter value from the request.
     */
    private function integerFilter(Request $request, string $key): ?int
    {
        if (! $request->filled($key)) {
            return null;
        }

        $value = $request->input($key);

        if (! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    /**
     * Return available aggregate filter stats for the current page context.
     *
     * @return array{bounds:array<string,array{min:int,max:int}>,defaults:array<string,int>}
     */
    private function filterStats(QueryBuilder $query): array
    {
        $rows = DB::query()
            ->fromSub($query, 'unit_rows')
            ->get(['gp', 'shifts', 'toi', 'gf', 'sf', 'satf']);

        return [
            'bounds' => [
                'gp' => $this->integerBounds($rows, 'gp'),
                'shifts' => $this->integerBounds($rows, 'shifts'),
                'toi' => $this->toiMinuteBounds($rows),
                'gf' => $this->integerBounds($rows, 'gf', true),
                'sf' => $this->integerBounds($rows, 'sf', true),
                'satf' => $this->integerBounds($rows, 'satf', true),
            ],
            'defaults' => [
                'gp_min' => $this->bottomTenPercentCutoff($rows, 'gp'),
                'shifts_min' => $this->bottomTenPercentCutoff($rows, 'shifts'),
                'toi_min' => (int) ceil($this->bottomTenPercentCutoff($rows, 'toi') / 60),
                'gf_min' => 0,
                'sf_min' => 0,
                'satf_min' => 0,
            ],
        ];
    }

    /**
     * @param \Illuminate\Support\Collection<int,object> $rows
     * @return array{min:int,max:int}
     */
    private function integerBounds($rows, string $field, bool $forceZeroMin = false): array
    {
        if ($rows->isEmpty()) {
            return ['min' => 0, 'max' => 0];
        }

        return [
            'min' => $forceZeroMin ? 0 : (int) $rows->min($field),
            'max' => (int) $rows->max($field),
        ];
    }

    /**
     * @param \Illuminate\Support\Collection<int,object> $rows
     * @return array{min:int,max:int}
     */
    private function toiMinuteBounds($rows): array
    {
        if ($rows->isEmpty()) {
            return ['min' => 0, 'max' => 0];
        }

        return [
            'min' => (int) floor(((int) $rows->min('toi')) / 60),
            'max' => (int) ceil(((int) $rows->max('toi')) / 60),
        ];
    }

    /**
     * @param \Illuminate\Support\Collection<int,object> $rows
     */
    private function bottomTenPercentCutoff($rows, string $field): int
    {
        if ($rows->isEmpty()) {
            return 0;
        }

        $values = $rows
            ->pluck($field)
            ->map(static fn (mixed $value): int => (int) $value)
            ->sort()
            ->values();
        $index = min($values->count() - 1, (int) floor($values->count() * 0.1));

        return (int) $values[$index];
    }

    /**
     * @param array<string,int|null> $filters
     * @param array<string,int> $defaults
     * @return array<string,int|null>
     */
    private function withDefaultFilters(Request $request, array $filters, array $defaults): array
    {
        foreach ($defaults as $key => $value) {
            if (! $request->filled($key)) {
                $filters[$key] = $value;
            }
        }

        return $filters;
    }

    /**
     * Apply aggregate filters after unit rows have been grouped.
     *
     * @param array<string,int|null> $filters
     */
    private function applyAggregateFilters(QueryBuilder $query, array $filters): void
    {
        if ($filters['gp_min'] !== null) {
            $query->havingRaw('COUNT(DISTINCT s.nhl_game_id) >= ?', [$filters['gp_min']]);
        }

        if ($filters['gp_max'] !== null) {
            $query->havingRaw('COUNT(DISTINCT s.nhl_game_id) <= ?', [$filters['gp_max']]);
        }

        if ($filters['shifts_min'] !== null) {
            $query->havingRaw('SUM(s.shifts) >= ?', [$filters['shifts_min']]);
        }

        if ($filters['shifts_max'] !== null) {
            $query->havingRaw('SUM(s.shifts) <= ?', [$filters['shifts_max']]);
        }

        if ($filters['toi_min'] !== null) {
            $query->havingRaw('SUM(s.toi) >= ?', [$filters['toi_min'] * 60]);
        }

        if ($filters['toi_max'] !== null) {
            $query->havingRaw('SUM(s.toi) <= ?', [$filters['toi_max'] * 60]);
        }

        foreach (['gf', 'sf', 'satf'] as $field) {
            if ($filters["{$field}_min"] !== null) {
                $query->havingRaw("SUM(s.{$field}) >= ?", [$filters["{$field}_min"]]);
            }

            if ($filters["{$field}_max"] !== null) {
                $query->havingRaw("SUM(s.{$field}) <= ?", [$filters["{$field}_max"]]);
            }
        }
    }

    private function seasonLabel(string $seasonId): string
    {
        if (preg_match('/^(\d{4})(\d{4})$/', $seasonId, $matches) !== 1) {
            return $seasonId;
        }

        return $matches[1] . '-' . substr($matches[2], -2);
    }

    private function gameTypeLabel(int $gameType): string
    {
        return match ($gameType) {
            1 => 'Preseason',
            3 => 'Postseason',
            default => 'Regular Season',
        };
    }

    /**
     * Apply raw count or paired share ordering for the selected unit stat.
     */
    private function applyOrdering($query, string $sort, string $dir, string $displayMode): void
    {
        $shareDenominators = [
            'gf' => ['gf', 'ga'],
            'ga' => ['gf', 'ga'],
            'sf' => ['sf', 'sa'],
            'sa' => ['sf', 'sa'],
            'satf' => ['satf', 'sata'],
            'sata' => ['satf', 'sata'],
            'ff' => ['ff', 'fa'],
            'fa' => ['ff', 'fa'],
            'bf' => ['bf', 'ba'],
            'ba' => ['bf', 'ba'],
            'hf' => ['hf', 'ha'],
            'ha' => ['hf', 'ha'],
            'fow' => ['fow', 'fol'],
            'fol' => ['fow', 'fol'],
            'ozs' => ['ozs', 'nzs', 'dzs'],
            'nzs' => ['ozs', 'nzs', 'dzs'],
            'dzs' => ['ozs', 'nzs', 'dzs'],
            'pim_f' => ['pim_f', 'pim_a'],
            'pim_a' => ['pim_f', 'pim_a'],
            'penalties_f' => ['penalties_f', 'penalties_a'],
            'penalties_a' => ['penalties_f', 'penalties_a'],
        ];

        if ($displayMode !== 'share' || ! array_key_exists($sort, $shareDenominators)) {
            $query->orderBy($sort, $dir);

            return;
        }

        $denominator = collect($shareDenominators[$sort])
            ->map(static fn (string $field): string => "SUM(s.{$field})")
            ->implode(' + ');

        $query->orderByRaw(
            "CASE WHEN ({$denominator}) > 0 THEN SUM(s.{$sort}) * 1.0 / ({$denominator}) ELSE 0 END {$dir}"
        );
    }

    /**
     * Return a driver-compatible aggregate expression for unit player names.
     */
    private function playerNamesExpression(): string
    {
        return match (DB::connection()->getDriverName()) {
            'pgsql' => "STRING_AGG(CONCAT_WS(' ', p.first_name, p.last_name), ' · ' ORDER BY p.last_name, p.first_name)",
            'sqlite' => "GROUP_CONCAT(TRIM(COALESCE(p.first_name, '') || ' ' || COALESCE(p.last_name, '')), ' · ')",
            default => "GROUP_CONCAT(CONCAT_WS(' ', p.first_name, p.last_name) ORDER BY p.last_name, p.first_name SEPARATOR ' · ')",
        };
    }

    /**
     * @return array<int,array{name:string,short_name:string,avatar_url:string|null,initials:string,position:string,pos_type:string}>
     */
    private function unitPlayers(int $unitId): array
    {
        return DB::table('nhl_unit_players as up')
            ->join('players as p', 'p.id', '=', 'up.player_id')
            ->where('up.unit_id', $unitId)
            ->orderBy('p.last_name')
            ->get(['p.first_name', 'p.last_name', 'p.full_name', 'p.head_shot_url', 'p.position', 'p.pos_type'])
            ->map(function (object $player): array {
                $fullName = trim((string) ($player->full_name ?: trim($player->first_name . ' ' . $player->last_name)));
                $firstName = trim((string) $player->first_name);
                $lastName = trim((string) $player->last_name);
                $shortName = trim(($firstName !== '' ? mb_substr($firstName, 0, 1) . '. ' : '') . $lastName);
                $initials = collect([$firstName, $lastName])
                    ->filter()
                    ->map(static fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))
                    ->implode('');

                return [
                    'name' => $fullName,
                    'short_name' => $shortName !== '' ? $shortName : $fullName,
                    'avatar_url' => $player->head_shot_url ? (string) $player->head_shot_url : null,
                    'initials' => $initials !== '' ? $initials : '?',
                    'position' => strtoupper(trim((string) $player->position)),
                    'pos_type' => strtoupper(trim((string) $player->pos_type)),
                ];
            })
            ->values()
            ->all();
    }
}
