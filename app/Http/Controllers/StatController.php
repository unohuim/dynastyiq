<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Player;
use App\Models\Stat;



class StatController extends Controller
{
    public function prospects()
    {
        $stats = Stat::where('season_id', '20242025')->where('league_abbrev', '<>', 'NHL')->orderBy('PTS', 'desc')->get();

        return view('prospects', compact('stats'));
    }
}
