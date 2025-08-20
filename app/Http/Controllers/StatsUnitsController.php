<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StatsUnitsController extends Controller
{
    /** @var string[] allow-list for sortable columns on s.* */
    private array $sortable = [
        'gf','ga','ev_gf','pp_gf','pk_gf','ev_ga','pp_ga','pk_ga',
        'sf','sa','ev_sf','pp_sf','pk_sf','ev_sa','pp_sa','pk_sa',
        'satf','sata','ff','fa','bf','ba','hf','ha','fow','fol','fot',
        'ozs','nzs','dzs','shifts','toi','pim_f','pim_a','penalties_f','penalties_a'
    ];

    public function index(Request $request)
    {
        $perPage = (int) $request->integer('per_page', 30);
        $sort    = (string) $request->get('sort', 'gf');
        $dir     = strtolower((string) $request->get('dir', 'desc'));
        $dir     = in_array($dir, ['asc','desc'], true) ? $dir : 'desc';

        if (!in_array($sort, $this->sortable, true)) {
            $sort = 'gf';
        }

        // Filter by unit type(s): F/D/G  (default: F only)
        $pos = collect((array) $request->input('pos', ['F']))
            ->map(fn ($v) => strtoupper((string) $v))
            ->intersect(['F','D','G'])
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

        $q = DB::table('nhl_unit_game_summaries as s')
            ->join('nhl_units as u', 'u.id', '=', 's.unit_id')
            ->leftJoin('nhl_games as g', 'g.nhl_game_id', '=', 's.nhl_game_id')
            ->select(
                's.*',
                'u.unit_type',
                'g.game_date',
                'g.home_team_abbrev as home',
                'g.away_team_abbrev as away',
            )
            ->selectSub(function ($sub) use ($playerNamesExpr) {
                $sub->from('nhl_unit_players as up')
                    ->join('players as p', 'p.id', '=', 'up.player_id')
                    ->whereColumn('up.unit_id', 's.unit_id')
                    ->selectRaw($playerNamesExpr);
            }, 'player_names')
            ->whereIn('u.unit_type', $pos)
            ->when($request->filled('team'), function ($qq) use ($request) {
                $qq->where('s.team_abbrev', strtoupper((string) $request->get('team')));
            })
            ->when($request->filled('game'), function ($qq) use ($request) {
                $qq->where('s.nhl_game_id', (int) $request->get('game'));
            })
            ->orderBy("s.$sort", $dir);

        $units = $q->paginate($perPage)->withQueryString();

        return view('stats-units', [
            'units'    => $units,
            'sortable' => $this->sortable,
            'sort'     => $sort,
            'dir'      => $dir,
            'perPage'  => $perPage,
            'team'     => (string) $request->get('team', ''),
            'game'     => (string) $request->get('game', ''),
            'pos'      => $pos,
        ]);
    }
}
