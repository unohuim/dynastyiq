<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use Illuminate\Support\Arr;
use App\Models\Shift;
use App\Models\PlayByPlay;
use App\Jobs\ImportPlayByPlaysJob;
use Carbon\Carbon;



class PlayByPlayController extends Controller
{
    public function __construct()
    {
    }



    public function ImportPlayByPlays()
    {
        $date = Carbon::now()->yesterday();
        $end_date = new Carbon('first day of January 2025');
        //$end_date = Carbon::now()->subDays(6);
        // $end_date = new Carbon('first day of October 2024');
        // $end_date = Carbon::now()->subWeeks(2);
        $playByPlays = [];

        while($date > $end_date) {
            $playByPlays[] = new ImportPlayByPlaysJob($date->toDateString());
            // ImportPlayByPlaysJob::dispatch($date->toDateString());
            echo("<p>" . $date->toDateString() . " import started..</p>");
            // $this->importPlayByPlaysByDate($date->toDateString());
            $date->subDays(1);
        }
        
        $batPlayByPlays = Bus::batch($playByPlays)->name('Processing Games - ' . $date->toDateString());
        
        echo("Finished importing");
    }
}
