<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\NhlDiscoverDayJob;
use Illuminate\Support\Carbon;

/**
 * Class NhlDiscovery
 *
 * Dispatches one discovery job per calendar day over a date range (inclusive).
 */
class NhlDiscovery
{
    /**
     * Dispatch per-day discovery jobs from the later date to the earlier date (inclusive).
     *
     * @param Carbon|string $start Later date (YYYY-MM-DD or Carbon)
     * @param Carbon|string $end   Earlier date (YYYY-MM-DD or Carbon)
     */
    public function discoverRange(Carbon|string $start, Carbon|string $end): void
    {
        $start = $start instanceof Carbon ? $start->copy()->startOfDay() : Carbon::parse((string) $start)->startOfDay();
        $end   = $end   instanceof Carbon ? $end->copy()->startOfDay()   : Carbon::parse((string) $end)->startOfDay();

        if ($start->lt($end)) {
            [$start, $end] = [$end, $start];
        }

        for ($cursor = $start->copy(); $cursor->gte($end); $cursor->subDay()) {
            NhlDiscoverDayJob::dispatch($cursor->toDateString());
        }
    }
}
