<?php

namespace App\Classes;

use App\Models\GameSummary;
use App\Models\SeasonStat;
use App\Models\Player;



class SumSeason
{
	public function sum($season)
    {
        //get all the playbyplay records for this game from db
        $player_ids = GameSummary::where('season_id', $season)->pluck('player_id')->unique();

        foreach($player_ids as $nhl_player_id) {
            $games = GameSummary::where('season_id', $season)->where('player_id', $nhl_player_id)->get();

            //est season stat record; find existing, or create new
            $seasonStat = SeasonStat::where('season_id', $season)->where('nhl_player_id', $nhl_player_id)->first();
            if(is_null($seasonStat)) $seasonStat = new SeasonStat();

            $player = Player::where('nhl_id', $nhl_player_id)->first();
            if(is_null($player)) {
                $playerImport = new ImportPlayer();
                $playerImport->import($nhl_player_id);

                $player = Player::where('nhl_id', $nhl_player_id)->first();
            }

            $seasonStat->player_id = $player->id;
            $seasonStat->season_id = $season;
            $seasonStat->nhl_player_id = $nhl_player_id;
            $seasonStat->nhl_team_id = $games->first()->nhl_team_id;

            $seasonStat->GP = $games->where('player_id', $nhl_player_id)->where('season_id', $season)->count();


            //goals`
            $seasonStat->G = $games->where('player_id', $nhl_player_id)->where('season_id', $season)->sum('G');
            $seasonStat->PPG = $games->where('player_id', $nhl_player_id)->where('season_id', $season)->sum('PPG');
            $seasonStat->SHG = $games->where('player_id', $nhl_player_id)->where('season_id', $season)->sum('SHG');
            $seasonStat->EVG = $games->where('player_id', $nhl_player_id)->where('season_id', $season)->sum('EVG');


            //assists
            $seasonStat->A = $games->where('player_id', $nhl_player_id)->where('season_id', $season)->sum('A');
            $seasonStat->SHA = $games->where('player_id', $nhl_player_id)->where('season_id', $season)->sum('SHA');
            $seasonStat->PPA = $games->where('player_id', $nhl_player_id)->where('season_id', $season)->sum('PPA');
            $seasonStat->EVA = $games->where('player_id', $nhl_player_id)->where('season_id', $season)->sum('EVA');


            //primary assists
            $seasonStat->A1 = $games->where('player_id', $nhl_player_id)->where('season_id', $season)->sum('A1');
            $seasonStat->PPA1 = $games->where('player_id', $nhl_player_id)->where('season_id', $season)->sum('PPA1');
            $seasonStat->EVA1 = $games->where('player_id', $nhl_player_id)->where('season_id', $season)->sum('EVA1');
          
            //secondary assists
            $seasonStat->A2 = $games->where('player_id', $nhl_player_id)->where('season_id', $season)->sum('A2');
            $seasonStat->PPA2 = $games->where('player_id', $nhl_player_id)->where('season_id', $season)->sum('PPA2');
            $seasonStat->EVA2 = $games->where('player_id', $nhl_player_id)->where('season_id', $season)->sum('EVA2');
          

            //points
            $seasonStat->PTS = $seasonStat->A + $seasonStat->G;
            $seasonStat->PPP = $seasonStat->PPA + $seasonStat->PPG;
            $seasonStat->SHP = $seasonStat->SHA + $seasonStat->SHG;
            $seasonStat->EVPTS = $seasonStat->EVA + $seasonStat->EVG;


            //faceoffs
            $seasonStat->FOW = $games->where('player_id', $nhl_player_id)->where('season_id', $season)->sum('FOW');
            $seasonStat->FOL = $games->where('player_id', $nhl_player_id)->where('season_id', $season)->sum('FOL');
            $seasonStat->FOT = $seasonStat->FOW + $seasonStat->FOL;
            if($seasonStat->FOT > 0) $seasonStat->FOW_percentage = $seasonStat->FOW / $seasonStat->FOT;
            

            //PIM
            $seasonStat->PIM = $games->where('player_id', $nhl_player_id)->where('season_id', $season)->sum('PIM');


            //hits
            $seasonStat->H = $games->where('player_id', $nhl_player_id)->where('season_id', $season)->sum('H');
            $seasonStat->TH = $games->where('player_id', $nhl_player_id)->where('season_id', $season)->sum('TH');
  

            //SOG
            $seasonStat->SOG = $games->where('player_id', $nhl_player_id)->where('season_id', $season)->sum('SOG');
            $seasonStat->PPSOG = $games->where('player_id', $nhl_player_id)->where('season_id', $season)->sum('PPSOG');
            $seasonStat->EVSOG = $games->where('player_id', $nhl_player_id)->where('season_id', $season)->sum('EVSOG');

            //Shots missed
            $seasonStat->SM = $games->where('player_id', $nhl_player_id)->where('season_id', $season)->sum('SM');
            $seasonStat->PPSM = $games->where('player_id', $nhl_player_id)->where('season_id', $season)->sum('PPSM');
            $seasonStat->EVSM = $games->where('player_id', $nhl_player_id)->where('season_id', $season)->sum('EVSM');

            //blocked shots
            $seasonStat->SB = $games->where('player_id', $nhl_player_id)->where('season_id', $season)->sum('SB');
            $seasonStat->PPSB = $games->where('player_id', $nhl_player_id)->where('season_id', $season)->sum('PPSB');
            $seasonStat->EVSB = $games->where('player_id', $nhl_player_id)->where('season_id', $season)->sum('EVSB');

            $seasonStat->SA = $seasonStat->SOG + $seasonStat->SM + $seasonStat->SB;
            $seasonStat->PPSA = $seasonStat->PPSOG + $seasonStat->PPSM + $seasonStat->PPSB;
            $seasonStat->EVSA = $seasonStat->EVSOG + $seasonStat->EVSM + $seasonStat->EVSB;

            $seasonStat->SOGvSA_p = $seasonStat->SOG > 0 ? $seasonStat->SOG / $seasonStat->SA : 0;


            $seasonStat->SOG_p = $seasonStat->SOG > 0 ? $seasonStat->G / $seasonStat->SOG : 0;
            $seasonStat->PPSOG_p = $seasonStat->PPSOG > 0 ? $seasonStat->PPG / $seasonStat->PPSOG : 0;
            $seasonStat->EVSOG_p = $seasonStat->EVSOG > 0 ? $seasonStat->EVG / $seasonStat->EVSOG : 0;


            //blocks
            $seasonStat->B = $games->where('player_id', $nhl_player_id)->where('season_id', $season)->sum('B');
            //giveaways & takewaways
            $seasonStat->GV = $games->where('player_id', $nhl_player_id)->where('season_id', $season)->sum('GV');
            $seasonStat->TK = $games->where('player_id', $nhl_player_id)->where('season_id', $season)->sum('TK');
            $seasonStat->TKvGV = $seasonStat->TK - $seasonStat->GV;


            //toi & shifts
            $seasonStat->TOI = $games->where('player_id', $nhl_player_id)->where('season_id', $season)->sum('TOI');
            $seasonStat->SHIFTS = $games->where('player_id', $nhl_player_id)->where('season_id', $season)->sum('SHIFTS');


            $seasonStat->save();
        }
        
    }

}
