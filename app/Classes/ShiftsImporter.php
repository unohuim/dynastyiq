<?php

namespace App\Classes;

use App\Models\Shift;
use App\Models\Player;
use App\Traits\HasAPITrait;
use App\Traits\HasClockTimeTrait;
use App\Classes\ImportPlayer;



class ShiftsImporter
{
	use HasAPITrait, HasClockTimeTrait;

	private string $NHL_API_BASE = "https://api-web.nhle.com/v1";
    private int $PERIOD_SECONDS = 20*60;


    /**
     * Create a new class instance.
     */
    public function __construct()
    {
        //
    }



    public function import($shifts)
    {
    	foreach($shifts as $s) {
    		$shift = Shift::firstOrNew(['id' => $s['id']]);

    		$shift->detail_code = $s['detailCode'];
    		$shift->duration = $s['duration'];
    		$shift->end_time = $s['endTime'];
    		$shift->event_description = $s['eventDescription'];
    		$shift->event_details = $s['eventDetails'];
    		$shift->event_number = $s['eventNumber'];
    		$shift->first_name = $s['firstName'];
    		$shift->game_id = $s['gameId'];
    		$shift->hex_value = $s['hexValue'];
    		$shift->last_name = $s['lastName'];
    		$shift->period = $s['period'];
    		$shift->player_id = $s['playerId'];
    		$shift->shift_number = $s['shiftNumber'];
    		$shift->start_time = $s['startTime'];
    		$shift->team_abbrev = $s['teamAbbrev'];
    		$shift->team_id = $s['teamId'];
    		$shift->team_name = $s['teamName'];
    		$shift->type_code = $s['typeCode'];


    		$player = Player::where('nhl_id', $shift->player_id)->first();
    		if(is_null($player)) {
    			$playerImport = new ImportPlayer();
    			$playerImport->import($shift->player_id);

    			$player = Player::where('nhl_id', $shift->player_id)->first();
    		}

    		$shift->pos_type = $player->pos_type;
    		$shift->position = $player->position;


    		if(!is_null($shift->duration)) $shift->seconds = $this->getSeconds($shift->duration);
    		$shift->start_game_seconds = $this->getSeconds($shift->start_time) + (($shift->period - 1) * $this->PERIOD_SECONDS);
    		$shift->end_game_seconds = $this->getSeconds($shift->end_time) + (($shift->period - 1) * $this->PERIOD_SECONDS);

    		$shift->save();
    	}
    }



    public function getShifts(int $game_id)
    {
        $url = "https://api.nhle.com/stats/rest/en/shiftcharts?cayenneExp=gameId=" . $game_id;
        return $this->getAPIData($url)['data'];
    }
}
