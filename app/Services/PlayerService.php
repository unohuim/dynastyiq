<?php


namespace App\Services;

use App\Models\Player;
use App\Models\Stat;



class PlayerService
{

    public function refreshAllCache(): void
    {
        $this->refreshCache();
        $this->refreshStatsCache();
    }




    public function refreshCache(): void
    {
        Cache::put('players_with_relations', Player::with(['team', 'stats', 'currentRanking', 'rankingsForUser', 'rankingProfiles', 
            'latestNhlStat', 'contracts', 'units'])
            ->get(), 3600);
    }

    public function getCachedPlayers()
    {
        return Cache::remember('players_with_relations', 3600, function () {
            return Player::with(['team', 'stats', 'currentRanking', 'rankingsForUser', 'rankingProfiles', 'latestNhlStat', 
                'contracts', 'units'])
                ->get();
        });
    }



    public function refreshStatsCache(): void
    {
        Cache::put('stats_with_relations', Stat::with(['player'])->get(), 3600);
    }


    public function getCachedStats()
    {
        return Cache::remember('stats_with_relations', 3600, function () {
            return Stat::with(['player'])->get();
        });
    }


    public function queryCachedStats()
    {
        return Cache::remember('stats_with_relations', 3600, function () {
            return Stat::with(['player']);
        });
    }
}
