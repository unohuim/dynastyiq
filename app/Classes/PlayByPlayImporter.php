<?php

namespace App\Classes;

use App\Models\PlayByPlay;
use App\Traits\HasAPITrait;
use Illuminate\Support\Facades\Bus;
use App\Jobs\ImportGamePlayByPlaysJob;
use App\Jobs\SumGameJob;
use App\Jobs\ImportShiftsJob;
use App\Classes\ShiftsImporter;



class PlayByPlayImporter
{
    use HasAPITrait;

    private string $NHL_API_BASE = "https://api-web.nhle.com/v1";    

    protected $shiftsImporter;

    public function __construct()
    {
        $this->shiftsImporter = new ShiftsImporter();
    }




    public function importPlayByPlaysByDate(string $date)
    {        
        $games = $this->getGamesByDate($date);
        $jobsPlayByPlay = [];
        $jobsShifts = [];
        $sumGames = [];

        //making sure that there are games to process for this day
        if(!is_null($games) && count($games) > 0){
            foreach($games['games'] as $game) {
                $playByPlay = $this->getPlayByPlay($game['id']);
                $jobsPlayByPlay[] = new ImportGamePlayByPlaysJob($playByPlay);

                $shifts = $this->shiftsImporter->getShifts($game['id']);
                $jobsShifts[] = new ImportShiftsJob($shifts);

                $sumGames[] = new SumGameJob($playByPlay);

                //create the game summary
                //$this->sumGame($playByPlay);
                //append season statistics, including averages and /60s
                echo("<p>import scheduled, game " . $game['id'] . "..</p>");
            }
        }

        $batPlayByPlays = Bus::batch($jobsPlayByPlay)->name('Import Play By Plays - ' . $date);
        $batShifts = Bus::batch($jobsShifts)->name('Import Shifts - ' . $date);
        $batSums = Bus::batch($sumGames)->name('Summarize Games - ' . $date);

        Bus::chain([
            $batPlayByPlays,
            $batShifts,
            $batSums
        ])->dispatch();
    }



    private function getGamesByDate(string $date)
    {
        $url = $this->NHL_API_BASE . "/score/" . $date;
        return $this->getAPIData($url);
    }



    private function getPlayByPlay(int $game_id)
    {
        $url = $this->NHL_API_BASE . "/gamecenter/" . $game_id . "/play-by-play";
        return $this->getAPIData($url);
    }

}
