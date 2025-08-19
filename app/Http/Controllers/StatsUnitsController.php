<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StatsUnitsController extends Controller
{
    /** @var string[] */
    private array $sortable = [
        'gf','ga','ev_gf','pp_gf','pk_gf','ev_ga','pp_ga','pk_ga',
        'sf','sa','ev_sf','pp_sf','pk_sf','ev_sa','pp_sa','pk_sa',
        'satf','sata','ff','fa','bf','ba','hf','ha','fow','fol','fot',
        'ozs','nzs','dzs','shifts','toi','pim_f','pim_a','penalties_f','penalties_a'
    ];

    public function index(Request $request)
    {
        $perPage = (int) $request->integer('per_page', 30);
        $sort    = $request->get('sort', 'gf');
        $dir     = in_array(strtolower($request->get('dir', 'desc')), ['asc','desc'], true) ? strtolower((string)$request->get('dir','desc')) : 'desc';

        if (!in_array($sort, $this->sortable, true)) $sort = 'gf';

        // pos_type filter (F/D/G). Default: only F.
        $pos = collect((array) $request->input('pos', ['F']))
            ->map(fn($v) => strtoupper((string)$v))
            ->intersect(['F','D','G'])
            ->values()
            ->all();
        if (empty($pos)) $pos = ['F'];

        $q = DB::table('nhl_unit_game_summaries as s')
            ->join('nhl_units as u', 'u.id', '=', 's.unit_id')
            ->leftJoin('nhl_games as g', 'g.nhl_game_id', '=', 's.nhl_game_id') // NEW
            ->select(
                's.*',
                'u.unit_type',
                'g.game_date',                                  // NEW
                'g.home_team_abbrev as home',                   // NEW
                'g.away_team_abbrev as away'                    // NEW
            )
            ->selectSub(function ($sub) {
                $sub->from('nhl_unit_players as up')
                    ->join('players as p', 'p.id', '=', 'up.player_id')
                    ->whereColumn('up.unit_id', 's.unit_id')
                    ->selectRaw("GROUP_CONCAT(CONCAT_WS(' ', p.first_name, p.last_name) ORDER BY p.last_name SEPARATOR ' · ')");
            }, 'player_names')
            ->whereIn('u.unit_type', $pos)
            ->when($request->filled('team'), fn($qq) => $qq->where('s.team_abbrev', strtoupper((string)$request->team)))
            ->when($request->filled('game'), fn($qq) => $qq->where('s.nhl_game_id', (int) $request->game))
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
            'pos'      => $pos, // pass current selection
        ]);
    }
}
