<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Bus\Batchable;
use App\Traits\HasClockTimeTrait;
use App\Jobs\ImportPlayByPlay;


class ImportGamePlayByPlaysJob implements ShouldQueue
{
    use Queueable, HasClockTimeTrait, Batchable;


    protected $game;
    protected int $PERIOD_SECONDS = 20*60;


    /**
     * Create a new job instance.
     */
    public function __construct($game)
    {
        $this->game = $game;
    }



    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $penalties = [];
        $lastPlaySecondsInGame = 0;
        $strength = "EV";
        $lastPlayStrength = "EV";

        foreach($this->game['plays'] as $play){

            //extracting values, est variables
            $ownerid = 0;
            if(isset($play['details']['eventOwnerTeamId'])) $ownerid = $play['details']['eventOwnerTeamId'];
            $period = $play['periodDescriptor']['number'];
            $secondsInPeriod = $this->getSeconds($play['timeInPeriod']);
            $secondsInGame = $this->getSecondsInGame($secondsInPeriod, $period);

            
            $strength = $this->getStrength($play, $penalties, $secondsInGame, $lastPlaySecondsInGame, $lastPlayStrength);

            ImportPlayByPlay::dispatch($this->game, $play, $strength);

            $penalties = $this->removeExpiredPenalties($play, $penalties, $secondsInGame);
            $penalties = $this->addPenalty($play, $penalties, $secondsInGame);

            $lastPlaySecondsInGame = $secondsInGame;
            $lastPlayStrength = $strength;
        }
    }



    private function addPenalty($play, $penalties, $secondsInGame)
    {
        //penalty handling
        if($play['typeDescKey'] == "penalty") {
            $pplayer = 0;
            $team_id = $play['details']['eventOwnerTeamId'];
            if(isset($play['details']['committedByPlayerId'])) $pplayer = $play['details']['committedByPlayerId'];
            $penalties [] = [
                'time' => $secondsInGame,
                'team' => $team_id,
                'player' => $pplayer,
                'desc' => $play['details']['descKey'],
                'duration' => (int)$play['details']['duration'] * 60
            ];
        }

        return $penalties;
    }
    


    private function getStrength($play, $penalties, $secondsInGame, $lastPlaySecondsInGame, $lastPlayStrength)
    {
        $sameStoppage = ($secondsInGame == $lastPlaySecondsInGame) && $play['typeDescKey'] != "faceoff" ? true : false;

        $eventOwnerCount = 0;
        $eventNotOwnerCount = 0;
        if(isset($play['details']['eventOwnerTeamId'])) {
            foreach($penalties as $penalty) {
                if($penalty['team'] == $play['details']['eventOwnerTeamId']) $eventOwnerCount += 1;
                if($penalty['team'] != $play['details']['eventOwnerTeamId']) $eventNotOwnerCount += 1;
            }
        }

        $strength = $lastPlayStrength;
        if(!$sameStoppage) {
            if($eventOwnerCount > $eventNotOwnerCount) {$strength = "PK";}
            if($eventOwnerCount < $eventNotOwnerCount) {$strength = "PP";}
            if($eventOwnerCount == $eventNotOwnerCount) {$strength = "EV";}
        }

        return $strength;
    }



    private function getSecondsInGame($secondsInPeriod, $period)
    {
        return $secondsInPeriod + (((int) $period - 1) * $this->PERIOD_SECONDS);
    }



    private function removeExpiredPenalties($play, $penalties, $gameSeconds)
    {
        foreach($penalties as $key=>$penalty){

            //time expired
            if(((int)$gameSeconds - (int)$penalty['time']) > ((int) $penalty['duration'])) {
                unset($penalties[$key]);
            }

            //goal scored (and penalty is not a major)
            elseif($play['typeDescKey'] == "goal" && $play['details']['eventOwnerTeamId'] != $penalty['team'] && $penalty['duration'] < 121) {
                //was the goal scored during the penalty
                if(((int)$gameSeconds - (int)$penalty['time']) < ((int) $penalty['duration'])) unset($penalties[$key]);
            }
        }
        return $penalties;
    }
}
