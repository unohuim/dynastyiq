<?php

declare(strict_types=1);

namespace App\Http\Controllers;

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

        if (! in_array($sort, $this->sortable, true)) {
            $sort = 'gf';
        }

        if (! in_array($gameType, [1, 2, 3], true)) {
            $gameType = 2;
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

        // Driver-specific expression for concatenating player names per unit
        $driver = DB::connection()->getDriverName(); // 'mysql' or 'pgsql'
        $playerNamesExpr = $driver === 'pgsql'
            ? "STRING_AGG((p.first_name || ' ' || p.last_name), ' · ' ORDER BY p.last_name)"
            : "GROUP_CONCAT(CONCAT_WS(' ', p.first_name, p.last_name) ORDER BY p.last_name SEPARATOR ' · ')";

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
            ->selectSub(function ($sub) use ($playerNamesExpr) {
                $sub->from('nhl_unit_players as up')
                    ->join('players as p', 'p.id', '=', 'up.player_id')
                    ->whereColumn('up.unit_id', 's.unit_id')
                    ->selectRaw($playerNamesExpr);
            }, 'player_names')
            ->where('g.season_id', $seasonId)
            ->where('g.game_type', $gameType)
            ->whereIn('u.unit_type', $pos)
            ->when($request->filled('team'), function ($qq) use ($request) {
                $qq->where('s.team_abbrev', strtoupper((string) $request->get('team')));
            })
            ->groupBy('s.unit_id', 'u.unit_type', 'u.team_abbrev', 'g.season_id', 'g.game_type')
            ->orderBy($sort, $dir);

        $units = $q->paginate($perPage)->withQueryString();

        return view('stats-units', [
            'units'    => $units,
            'sortable' => $this->sortable,
            'sort'     => $sort,
            'dir'      => $dir,
            'perPage'  => $perPage,
            'team'     => (string) $request->get('team', ''),
            'pos'      => $pos,
            'availableSeasons' => $availableSeasons,
            'seasonId' => $seasonId,
            'seasonLabel' => $this->seasonLabel($seasonId),
            'gameType' => $gameType,
            'gameTypeLabel' => $this->gameTypeLabel($gameType),
        ]);
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
}
