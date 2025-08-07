<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use Illuminate\Support\Arr;
use App\Models\Shift;
use App\Models\PlayByPlay;
use App\Jobs\ImportPlayByPlaysByDateJob;
use Carbon\Carbon;
use Illuminate\Support\Facades\Bus;
use App\Services\ImportNHLPlayByPlay;
use App\Services\SumNHLPlayByPlay;
use App\Services\ImportNhlShifts;
use App\Services\ImportNhlBoxscore;
use App\Services\MakeNhlGameShiftUnits;
// use App\Services\CompareNhlPbPBoxscore;
use App\Services\ConnectEventsToUnitShifts;
use App\Models\NhlUnit;
// use App\Models\NhlUnitShift;



class PlayByPlayController extends Controller
{
    public function __construct()
    {
    }



    public function ImportNHLPlayByPlay()
    {
        $gameId = 2023020204;
        $importer = new ImportNHLPlayByPlay;
        $summer = new SumNHLPlayByPlay;
        $shiftsImporter = new ImportNhlShifts;
        $boxImporter = new ImportNhlBoxscore;
        $unitShifts = new MakeNhlGameShiftUnits;
        // $compare = new CompareNhlPbPBoxscore;
        $conn = new ConnectEventsToUnitShifts($gameId);

        $pbp = $importer->import($gameId);
        $sum = $summer->summarize($gameId);
        $boxe = $boxImporter->import($gameId);
        $shifts = $shiftsImporter->import($gameId);
        // $unitsh = $unitShifts->make($gameId);   
        // $conn->connect();
        // $conn->printEventUnitShiftCounts();
        // $units = $conn->showTopLine(null, "F");


        //$units = NhlUnit::with('players', 'events')->get();
        $units = NhlUnit::with('players', 'unitShifts.events')->get();


        echo("<div>");
        foreach($units as $u) {
            // dd($u);

            echo('<p>' . $u->team_abbrev . "- unit: " . $u->id . " - ");
            
                foreach($u->players as $p){
                    echo($p->first_name . " " . $p->last_name . " |");
                }

            echo('</p>');            

            foreach($u->unitShifts as $shift) {
                echo('<p> - Shift: ' . $shift->id . " (" . $shift->seconds . "s) period " . $shift->period);
                foreach($shift->events as $e) {
                    $zoneCode = $this->zoneFor($e, $shift);
                    echo("<p>--" . $e->time_in_period . ": " . " zone zone: " . $zoneCode . " | strength: " . $this->strengthFor($e, $shift) . " - " . $e->type_desc_key 
                        . " " . $this->statFor($e, $shift)
                         . '</p>');
                }
                echo('</p>');
            }
            
        }
        echo('</div>');

        

        return 'done';
    }


    private function statFor($e, $u)
    {
        if($e->event_owner_team_id == $u->team_id) return "FOR";
        return "AGAINST";

    }


    private function strengthFor($e, $u)
    {
        if($e->event_owner_team_id != $u->team_id) {
            if($e->strength == "PP") return "PK";
            if($e->strength == "PK") return "PP";
        }
        return $e->strength;
    }


    private function zoneFor($e, $u)
    {
        if($e->event_owner_team_id != $u->team_id) {
            if($e->zone_code == "O") return "D";
            if($e->zone_code == "D") return "O";
        }
        return $e->zone_code ?? "-";
    }

// echo('<span>' . $e->start_game_seconds . " | " . $e->team_abbrev . " | start zone: " . $e->starting_zone . " | end_zone: " . $e->ending_zone
//                         . " | "
//                          . '</span>');


    public function ImportPlayByPlays()
    {
        //$date = Carbon::now()->yesterday();

        $date = new Carbon('April 4th 2025');
        // $end_date = new Carbon('March 26th 2025');
        $end_date = new Carbon('March 3th 2025');
        // dd( $end_date->toDateString() );
        //$end_date = Carbon::now()->subDays(6);
        // $end_date = new Carbon('first day of October 2024');
        // $end_date = Carbon::now()->subWeeks(2);
        $playByPlays = [];

        while($date > $end_date) {
            // $playByPlays[] = new ImportPlayByPlaysJob($date->toDateString());
            ImportPlayByPlaysByDateJob::dispatch($date->toDateString());
            echo("<p>" . $date->toDateString() . " import started..</p>");
            // $this->importPlayByPlaysByDate($date->toDateString());
            $date->subDays(1);
        }
        
        // $batPlayByPlays = Bus::batch($playByPlays)->name('Processing Games - ' . $date->toDateString())
        //     ->dispatch();
        
        echo("Finished importing");
    }
}
