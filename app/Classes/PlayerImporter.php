<?php

namespace App\Classes;

use App\Traits\HasAPITrait;
use App\Traits\HasClockTimeTrait;
use App\Models\Player;




class PlayerImporter
{
	use HasAPITrait, HasClockTimeTrait;

    private string $NHL_API_BASE = "https://api-web.nhle.com/v1";
    private string $NHL_API_PATH_STANDINGS = "/standings/now";
    private int $PERIOD_SECONDS = 20*60;


    /**
     * Create a new class instance.
     */
    public function __construct()
    {
        //
    }



    public function importStats()
    {
        foreach(Player::all() as $player) {
            $apiPlayer = $this->getPlayerAPI($player->nhl_id);
            $seasonTotals = $apiPlayer['seasonTotals'];

            foreach($seasonTotals as $seasonStats) {
                $stat = new Stat();

                $stat->player_id = $player->id;
                $stat->is_prospect = $player->is_prospect;
                $stat->nhl_team_id = $player->nhl_team_id;
                $stat->nhl_team_abbrev = $player->team_abbrev;
                $stat->player_name = $player->first_name . " " . $player->last_name;
                $stat->season_id = $seasonStats['season'];
                $stat->league_abbrev = $seasonStats['leagueAbbrev'];
                $stat->team_name = $seasonStats['teamName']['default'];
                if(isset($seasonStats['goals'])) $stat->G = $seasonStats['goals'];
                if(isset($seasonStats['assists'])) $stat->A = $seasonStats['assists'];
                if(isset($seasonStats['goals'])) $stat->PTS = (int)$seasonStats['goals'] + (int)$seasonStats['assists'];
                if(isset($seasonStats['gamesPlayed'])) $stat->GP = $seasonStats['gamesPlayed'];
                if(isset($seasonStats['goals'])) $stat->avgGpGP = (int)$seasonStats['goals'] / (int)$seasonStats['gamesPlayed'];
                if(isset($seasonStats['assists'])) $stat->avgApGP = (int)$seasonStats['assists'] / (int)$seasonStats['gamesPlayed'];
                if(isset($seasonStats['goals'])) $stat->avgPTSpGP = (int)$stat->PTS / (int)$seasonStats['gamesPlayed'];

                $stat->save();
            }

        }
    }
    


    private function getPlayerAPI(int $player_id)
    {
        $url = $this->NHL_API_BASE . "/player/" . $player_id . "/landing";
        return $this->getAPIData($url);
    }

    

}
