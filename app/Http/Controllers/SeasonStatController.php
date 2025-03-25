<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Jobs\SumSeasonJob;
use App\Classes\SumSeason;

class SeasonStatController extends Controller
{
    public function Sum(string $season_id)
    {
    	SumSeasonJob::dispatch($season_id);
    	// $sumSeason = new SumSeason();
    	// $sumSeason->sum($season_id);
    }
}
