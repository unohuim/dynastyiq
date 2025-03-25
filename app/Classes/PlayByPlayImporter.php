<?php

namespace App\Classes;

use App\Models\PlayByPlay;
use App\Traits\HasAPITrait;
use Illuminate\Support\Facades\Bus;
use Carbon\Carbon;
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



    public function import()
    {
        $date = Carbon::now()->yesterday();
        //$end_date = Carbon::now()->subDays(6);
        $end_date = new Carbon('first day of October 2024');
        // $end_date = Carbon::now()->subWeeks(2);


        while($date > $end_date) {
            $this->importPlayByPlaysByDate($date->toDateString());
            $date->subDays(1);
        }
    }



    private function importPlayByPlaysByDate(string $date)
    {        
        $games = $this->getGamesByDate($date);
        $jobsPlayByPlay = [];
        $jobsShifts = [];
        $sumGames = [];

        foreach($games['games'] as $game) {
            $playByPlay = $this->getPlayByPlay($game['id']);
            $jobsPlayByPlay[] = new ImportGamePlayByPlaysJob($playByPlay);

            $shifts = $this->shiftsImporter->getShifts($game['id']);
            $jobsShifts[] = new ImportShiftsJob($shifts);

            $sumGames[] = new SumGameJob($playByPlay);

            //create the game summary
            //$this->sumGame($playByPlay);
            //append season statistics, including averages and /60s
        }

        Bus::chain([
            $batPlayByPlays = Bus::batch($jobsPlayByPlay)->name('Import Play By Plays'),
            $batShifts = Bus::batch($jobsShifts)->name('Import Shifts'),
            $batSums = Bus::batch($sumGames)->name('Summarize Games')
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
