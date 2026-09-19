<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;

class CbsNhlClient
{
    private const INJURIES_URL = 'https://www.cbssports.com/nhl/injuries/';

    public function injuries(): string
    {
        return Http::timeout(20)->retry(2, 250)
            ->withHeaders(['User-Agent' => 'DynastyIQ/1.0 NHL availability importer'])
            ->get(self::INJURIES_URL)
            ->throw()
            ->body();
    }
}
