<?php

namespace App\Classes;

use App\Models\Shift;
use App\Models\GameSummary;
use App\Models\PlayByPlay;



class SumGame
{
	public function sum($game)
    {
        //get all the playbyplay records for this game from db
        $plays = PlayByPlay::where('nhl_game_id', $game['id'])->get();

        //use the players from api json in $game
        foreach($game['rosterSpots'] as $player) {
            $summary = GameSummary::where('nhl_game_id', $game['id'])
                ->where('player_id', $player['playerId'])->first();

            //if there's no record, then we create one
            if(is_null($summary)) $summary = new GameSummary();

            //add basic game details
            $summary->nhl_game_id = $game['id'];
            $summary->season_id = $game['season'];
            $summary->game_date = $game['gameDate'];
            $summary->game_dow = date_format(date_create($summary->game_date), 'D');
            $summary->game_month = date_format(date_create($summary->game_date), 'M');
            $summary->away_team_id = $game['awayTeam']['id'];
            $summary->home_team_id = $game['homeTeam']['id'];
            $summary->away_team_abbrev = $game['awayTeam']['abbrev'];
            $summary->home_team_abbrev = $game['homeTeam']['abbrev'];
            $summary->player_id = $player['playerId'];
            $summary->nhl_team_id = $player['teamId'];


            //goals`
            $summary->G = $plays->where('scoring_player_id', $player['playerId'])->count();
            $summary->PPG = $plays->where('scoring_player_id', $player['playerId'])
                ->where('strength', 'PP')->count();
            $summary->SHG = $plays->where('scoring_player_id', $player['playerId'])
                ->where('strength', 'PK')->count();
            $summary->EVG = $plays->where('scoring_player_id', $player['playerId'])
                ->where('strength', 'EV')->count();


            //assists
            $summary->A = $plays->where('assist1_player_id', $player['playerId'])->count()
                + $plays->where('assist2_player_id', $player['playerId'])->count();
            $summary->SHA = $plays->where('assist1_player_id', $player['playerId'])->where('strength', 'PK')->count()
                + $plays->where('assist2_player_id', $player['playerId'])->where('strength', 'PK')->count();
            $summary->PPA = $plays->where('assist1_player_id', $player['playerId'])->where('strength', 'PP')->count()
                + $plays->where('assist2_player_id', $player['playerId'])->where('strength', 'PP')->count();
            $summary->EVA = $plays->where('assist1_player_id', $player['playerId'])->where('strength', 'EV')->count()
                + $plays->where('assist2_player_id', $player['playerId'])->where('strength', 'EV')->count();


            //primary assists
            $summary->A1 = $plays->where('assist1_player_id', $player['playerId'])->count();
            $summary->PPA1 = $plays->where('assist1_player_id', $player['playerId'])->where('strength', 'PP')->count();
            $summary->EVA1 = $plays->where('assist1_player_id', $player['playerId'])->where('strength', 'EV')->count();
          
            //secondary assists
            $summary->A2 = $plays->where('assist2_player_id', $player['playerId'])->count();
            $summary->PPA2 = $plays->where('assist2_player_id', $player['playerId'])->where('strength', 'PP')->count();
            $summary->EVA2 = $plays->where('assist2_player_id', $player['playerId'])->where('strength', 'EV')->count();
          

            //points
            $summary->PTS = $summary->A + $summary->G;
            $summary->PPP = $summary->PPA + $summary->PPG;
            $summary->SHP = $summary->SHA + $summary->SHG;
            $summary->EVPTS = $summary->EVA + $summary->EVG;


            //faceoffs
            $summary->FOW = $plays->where('fo_winning_player_id', $player['playerId'])
                ->where('type_desc_key', 'faceoff')->count();
            

            $summary->FOL = $plays->where('fo_losing_player_id', $player['playerId'])
                ->where('type_desc_key', 'faceoff')->count();
            

            $summary->FOT = $summary->FOW + $summary->FOL;
           

            if($summary->FOT > 0) $summary->FOW_percentage = $summary->FOW / $summary->FOT;
            

            //PIM
            $summary->PIM = $plays->where('committed_by_player_id', $player['playerId'])->sum('duration');


            //hits
            $summary->H = $plays->where('hitting_player_id', $player['playerId'])->count();
            $summary->TH = $plays->where('hittee_player_id', $player['playerId'])->count();
  

            //SOG
            $summary->SOG = $plays->where('shooting_player_id', $player['playerId'])
                ->where('type_desc_key', 'shot-on-goal')->count();
            $summary->PPSOG = $plays->where('shooting_player_id', $player['playerId'])
                ->where('type_desc_key', 'shot-on-goal')->where('strength', 'PP')->count();
            $summary->EVSOG = $plays->where('shooting_player_id', $player['playerId'])
                ->where('type_desc_key', 'shot-on-goal')->where('strength', 'EV')->count();

            //Shots missed
            $summary->SM = $plays->where('shooting_player_id', $player['playerId'])
                ->where('type_desc_key', 'missed-shot')->count();
            $summary->PPSM = $plays->where('shooting_player_id', $player['playerId'])
                ->where('type_desc_key', 'missed-shot')->where('strength', 'PP')->count();
            $summary->EVSM = $plays->where('shooting_player_id', $player['playerId'])
                ->where('type_desc_key', 'missed-shot')->where('strength', 'EV')->count();

            //blocked shots
            $summary->SB = $plays->where('shooting_player_id', $player['playerId'])
                ->where('type_desc_key', 'blocked-shot')->count();
            $summary->PPSB = $plays->where('shooting_player_id', $player['playerId'])
                ->where('type_desc_key', 'blocked-shot')->where('strength', 'PP')->count();
            $summary->EVSB = $plays->where('shooting_player_id', $player['playerId'])
                ->where('type_desc_key', 'blocked-shot')->where('strength', 'EV')->count();

            $summary->SA = $summary->SOG + $summary->SM + $summary->SB;
            $summary->PPSA = $summary->PPSOG + $summary->PPSM + $summary->PPSB;
            $summary->EVSA = $summary->EVSOG + $summary->EVSM + $summary->EVSB;

            $summary->SOGvSA_p = $summary->SOG > 0 ? $summary->SOG / $summary->SA : 0;


            $summary->SOG_p = $summary->SOG > 0 ? $summary->G / $summary->SOG : 0;
            $summary->PPSOG_p = $summary->PPSOG > 0 ? $summary->PPG / $summary->PPSOG : 0;
            $summary->EVSOG_p = $summary->EVSOG > 0 ? $summary->EVG / $summary->EVSOG : 0;


            //blocks
            $summary->B = $plays->where('blocking_player_id', $player['playerId'])
                ->where('type_desc_key', 'blocked-shot')->count();

            //giveaways & takewaways
            $summary->GV = $plays->where('player_id', $player['playerId'])
                ->where('type_desc_key', 'giveaway')->count();
            $summary->TK = $plays->where('player_id', $player['playerId'])
                ->where('type_desc_key', 'takeaway')->count();
            $summary->TKvGV = $summary->TK - $summary->GV;


            //toi & shifts
            $summary->TOI = Shift::where('player_id', $player['playerId'])->where('game_id', $game['id'])->sum('seconds');
            $summary->SHIFTS = Shift::where('player_id', $player['playerId'])->where('game_id', $game['id'])->count();


            $summary->save();
        }
    }


    /**
     * import player
     */
    


}
