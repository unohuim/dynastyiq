<?php

namespace App\Classes;

use App\Traits\HasAPITrait;
use App\Models\Player;



class ImportPlayer
{
	use HasAPITrait;

	// protected string $player_id;
 //    protected bool $is_prospect;
    private string $NHL_API_BASE = "https://api-web.nhle.com/v1";


    /**
     * import player
     */
    public function import(string $player_id, bool $is_prospect=false): void
    {
        $url = $this->NHL_API_BASE . "/player/" . $player_id . "/landing";
        $apiPlayer = $this->getAPIData($url);

        //if player exists, then update record.. otherwise, create new
        $player = Player::firstOrNew([
            'nhl_id' => $apiPlayer['playerId']
        ]);

        //update record
        $player->nhl_team_id = $apiPlayer['currentTeamId'];
        $player->team_abbrev = $apiPlayer['currentTeamAbbrev'];
        $player->first_name = $apiPlayer['firstName']['default'];
        $player->last_name = $apiPlayer['lastName']['default'];
        $player->dob = $apiPlayer['birthDate'];
        $player->country_code = $apiPlayer['birthCountry'];
        $player->position = $apiPlayer['position'];
        $player->pos_type = ($player->position == "L" || $player->position == "R" || $player->position == "C") ? "F" : $player->position;
        $player->current_league_abbrev = "NHL";

        //determining the current league for prospects
        if($is_prospect) {
            $player->is_prospect = true;
            $seasonTotals = $apiPlayer['seasonTotals'];
            foreach($seasonTotals as $season) {
                $player->current_league_abbrev = $season['leagueAbbrev'];
            }
        }


        $player->save();
    }


}
