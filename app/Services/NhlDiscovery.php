<?php

declare(strict_types=1);

namespace App\Services;

use App\Traits\HasAPITrait;
use Illuminate\Support\Carbon;
use App\Jobs\NhlDiscoverDayJob;
use App\Repositories\NhlImportProgressRepo;

class NhlDiscovery
{
    use HasAPITrait;

    /** @var array<string> */
    private array $importTypes = ['pbp', 'summary', 'shifts', 'boxscore', 'shift-units', 'connect-events'];

    public function __construct(private readonly NhlImportProgressRepo $repo)
    {
    }

    /**
     * Single entry point: determine end date, then dispatch one day-per-job from now() back to end date (inclusive).
     */
    public function sync(?int $daysBack = null): void
    {
        $start = Carbon::today()->startOfDay(); // now() is always the start date
        $end   = $this->resolveEndDate($daysBack)->startOfDay();

        $this->discover($end, $start);
    }

    /**
     * Iterate calendar days from $start (today) backward to $end (inclusive) and dispatch one job per day.
     */
    private function discover(Carbon $endDate, Carbon $startDate): void
    {
        $cursor = $startDate->copy();
        while ($cursor->gte($endDate)) {
            // Tiny, fast job: it will fetch dailyscores for this date and insert rows if games exist; otherwise it exits.
            NhlDiscoverDayJob::dispatch($cursor->toDateString());
            $cursor->subDay();
        }
    }

    /**
     * If $daysBack is provided, end date = today - daysBack.
     * Otherwise, derive end date as Aug 31 of the last year in min_season_id (e.g., 20242025 -> 2025-08-31).
     */
    private function resolveEndDate(?int $daysBack): Carbon
    {
        if (is_int($daysBack)) {
            return Carbon::today()->subDays(max(0, $daysBack));
        }

        $minSeasonId = (string) config('apiImportNhl.min_season_id', '20192020');
        $endYear     = (int) substr($minSeasonId, 4, 4);

        return Carbon::create($endYear, 8, 31)->endOfDay();
    }
}
