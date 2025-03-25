<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use Illuminate\Support\Arr;
use App\Models\Shift;
use App\Models\PlayByPlay;
use App\Jobs\ImportPlayByPlaysJob;



class PlayByPlayController extends Controller
{
    public function __construct()
    {
    }



    public function ImportPlayByPlays()
    {
        ImportPlayByPlaysJob::dispatch();
        
        echo("Finished importing");
    }
}
