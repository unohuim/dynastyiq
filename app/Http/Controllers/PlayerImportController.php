<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Classes\PlayerImporter;
use App\Models\Player;
use App\Jobs\ImportPlayersJob;
use App\Traits\HasAPITrait;


class PlayerImportController extends Controller
{
    use HasAPITrait;


    protected string $NHL_API_BASE = "https://api-web.nhle.com/v1";
    protected string $NHL_API_PATH_STANDINGS = "/standings/now";



    public function __construct(protected PlayerImporter $playerImporter)
    {

    }


    public function import()
    {
        $url = $this->NHL_API_BASE . $this->NHL_API_PATH_STANDINGS;
        $standings = $this->getAPIData($url);

        foreach($standings['standings'] as $team) {
            ImportPlayersJob::dispatch($team['teamAbbrev']['default']);
        }

        dd($standings);
    }



    public function importStats()
    {
        set_time_limit(0);

        $this->playerImporter->importStats();



    }
}
