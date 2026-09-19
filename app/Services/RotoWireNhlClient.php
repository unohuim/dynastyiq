<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

class RotoWireNhlClient
{
    private const NEWS_URL = 'https://www.rotowire.com/rss/news.php?sport=NHL';
    private const GOALIES_URL = 'https://www.rotowire.com/hockey/tables/projected-goalies.php';

    public function news(): string
    {
        return Http::timeout(20)->retry(2, 250)->get(self::NEWS_URL)->throw()->body();
    }

    /** @return array<int,array<string,mixed>> */
    public function projectedGoalies(Carbon $date): array
    {
        return Http::timeout(20)->retry(2, 250)
            ->get(self::GOALIES_URL, ['date' => $date->toDateString()])
            ->throw()
            ->json();
    }
}
