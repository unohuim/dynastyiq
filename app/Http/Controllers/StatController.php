<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Player;
use App\Models\Stat;
use App\Traits\HasAPITrait;



class StatController extends Controller
{
	use HasAPITrait;


    public function index(Request $request)
    {
    	$isProspect = $request->route('prospect', false);
    	$defaultSeason = $this->currentSeason();

        $stats = Stat::where('season_id', '20242025')
        	->where('is_prospect', $isProspect)
        	->orderBy('PTS', 'desc')->get();

     	return view('players', compact('isProspect', 'defaultSeason'));   
    }



    protected function currentSeason(): string
    {
        $year = now()->year;
        return (now()->month > 9)
             ? $year . ($year + 1)
             : ($year - 1) . $year;
    }


    public function ncaa()
    {
    	//$url = $this->NHL_API_BASE . "/gamecenter/" . $game_id . "/play-by-play";
    	$url_base = "https://hockey.sportdevs.com";
    	$url = $url_base . "/leagues";
    	// $url = $url_base . "/seasons";
    	$url = $url_base . "/classes"; //us is 386; nhl: 2071; nhl season_id: 46374; nhl match_id: 299931; nhl team_id: 35514; nhl player_id: 28570; ncaa league_id is 2428; ncaa season_id 51585; match_id 315020; team_id: 38792; player: 114025;
    	$url = $url_base . "/leagues-by-class?class_id=eq.386";
    	$url = $url_base . "/seasons-by-league?league_id=eq.2071";
    	$url = $url_base . "/matches?season_id=eq.46374";
    	$url = $url_base . "/players?team_id=eq.35514";
    	$url = $url_base . "/players?id=eq.27745";
    	// $url = $url_base . "/matches?season_id=eq.46374";
    	// $url = $url_base . "/matches-statistics?match_id=eq.299954";
    	// $url = $url_base . "/matches-incidents?match_id=eq.299954";
    	// $url = $url_base . "/matches-players-statistics?match_id=eq.299954";

    	// $url = $url_base . "/players-statistics?player_id=eq.28570&season_id=eq.46374";
    	// $url = $url_base . "/players-statistics?player_id=eq.114025&season_id=eq.51585";

    	$apiKey = "UFSJO3-Ogk-i3_eQ9obQbw";


        $leagues = $this->getAPIData($url, $apiKey);

        dd($leagues);

        // foreach($leagues as $league) {
        // 	echo("<p>" . $league['id'] . " - " . $league['name'] . " - League ID: " . $league['league_id'] . "</p>");
        // }


        foreach($leagues as $league) {
        	$league_champ = "";
        	if(isset($league['current_champion_team_name'])) $league_champ = $league['current_champion_team_name'];
        	echo("<p>" . $league['id'] . " - " . $league['name'] . " - Champ: " . $league_champ . "</p>");
        }
        
    }



    public function ep()
    {
    	$key = "scZO36nvm2Scw0zM3rm0YPEQMNJEo48f";
    	$url_base = "https://api.eliteprospects.com/v1";	//porter martone 726243; sam bennett 189371
    	$ep_leagues = "/players?q=&nhlDrafted=true&dateOfBirth:from=1999-01-01&dateOfBirth:to=2001-09-15";


    	$url = $url_base . $ep_leagues;

    	$url = $url . "&apiKey=" . $key;


    	// dd($url);

    	$leagues = $this->getAPIData($url);
    	
    	dd($leagues);
    }
}
