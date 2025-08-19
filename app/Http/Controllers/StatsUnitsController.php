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
        $dir     = strtolower($request->get('dir', 'desc'));
        $dir     = in_array($dir, ['asc','desc'], true) ? $dir : 'desc';
        if (!in_array($sort, $this->sortable, true)) $sort = 'gf';

        $q = DB::table('nhl_unit_game_summaries as s')
            ->join('nhl_units as u', 'u.id', '=', 's.unit_id')
            ->select('s.*', 'u.unit_type') // removed u.unit_name
            ->selectSub(function ($sub) {
                $sub->from('nhl_unit_players as up')
                    ->join('players as p', 'p.id', '=', 'up.player_id')
                    ->whereColumn('up.unit_id', 's.unit_id')
                    ->selectRaw("GROUP_CONCAT(CONCAT_WS(' ', p.first_name, p.last_name) ORDER BY p.last_name SEPARATOR ' · ')");
            }, 'player_names')
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
        ]);
    }
}
