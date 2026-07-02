<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\SumNhlSeasonStats;

class PlayByPlayController extends Controller
{
    public function sum(string $season_id)
    {
        (new SumNhlSeasonStats())->sum($season_id);
    }


    public function ImportNHLPlayByPlay()
    {
        return response(
            'Play-by-play imports are handled through nhl:discover and nhl:process.',
            410
        );
    }

    public function ImportPlayByPlays()
    {
        return response(
            'Bulk play-by-play imports are handled through nhl:discover and nhl:process.',
            410
        );
    }
}
