<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\NhlDiscoveryJob;
use Carbon\Carbon;
use Illuminate\Console\Command;

class NhlDiscoverCommand extends Command
{
    /**
     * Examples:
     *  php artisan nhl:discover --end=2024-12-31
     *  php artisan nhl:discover --start=2025-05-13
     *  php artisan nhl:discover --start=2025-05-13 --days=10
     *  php artisan nhl:discover --end=2025-01-01 --days=10
     *  php artisan nhl:discover --season=20232024
     */
    protected $signature = 'nhl:discover
        {--days= : Integer days window}
        {--season= : Season id like 20232024}
        {--start= : Start date (YYYY-MM-DD)}
        {--end= : End date (YYYY-MM-DD)}';

    protected $description = 'Discover NHL games for a date window and dispatch per-day discovery jobs.';

    public function handle(): int
    {
        $season  = (string) ($this->option('season') ?? '');
        $startOpt = $this->parseDate((string) ($this->option('start') ?? ''));
        $endOpt   = $this->parseDate((string) ($this->option('end') ?? ''));
        $daysRaw  = $this->option('days');
        $days     = ($daysRaw === null || $daysRaw === '') ? null : (int) $daysRaw;

        // Season overrides everything else
        if ($season !== '') {
            [$seasonStart, $seasonEnd] = $this->seasonBounds($season);
            if (!$seasonStart || !$seasonEnd) {
                $this->error("Invalid season: {$season}");
                return self::INVALID;
            }
            dispatch(new NhlDiscoveryJob($seasonStart, $seasonEnd));
            $this->info("Queued discovery for season {$season} ({$seasonStart->toDateString()} → {$seasonEnd->toDateString()}).");
            return self::SUCCESS;
        }

        // If both start and end given, ignore days
        if ($startOpt && $endOpt) {
            [$start, $end] = $this->normalizeOrder($startOpt, $endOpt);
        }
        // start + days => end = start - days
        elseif ($startOpt && is_int($days)) {
            $start = $startOpt;
            $end   = $startOpt->copy()->subDays(max(0, $days));
        }
        // end + days => start = end + days
        elseif ($endOpt && is_int($days)) {
            $end   = $endOpt;
            $start = $endOpt->copy()->addDays(max(0, $days));
        }
        // start only => end = minSeasonEndDate
        elseif ($startOpt) {
            $start = $startOpt;
            $end   = $this->minSeasonEndDate();
        }
        // end only => start = today
        elseif ($endOpt) {
            $start = Carbon::today();
            $end   = $endOpt;
        }
        // days only => start = today, end = today - days
        elseif (is_int($days)) {
            $start = Carbon::today();
            $end   = Carbon::today()->copy()->subDays(max(0, $days));
        }
        // default => start = today, end = minSeasonEndDate
        else {
            $start = Carbon::today();
            $end   = $this->minSeasonEndDate();
        }

        if ($start->lt($end)) {
            [$start, $end] = [$end, $start];
        }

        dispatch(new NhlDiscoveryJob($start, $end));
        $this->info("Queued discovery {$start->toDateString()} → {$end->toDateString()}.");

        return self::SUCCESS;
    }

    private function parseDate(?string $value): ?Carbon
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            $this->warn("Ignored invalid date: {$value}");
            return null;
        }
    }

    /** For season like 20232024 → [start=2024-08-31, end=2023-09-01]. */
    private function seasonBounds(string $seasonId): array
    {
        $seasonId = trim($seasonId);
        if (strlen($seasonId) !== 8 || !ctype_digit($seasonId)) {
            return [null, null];
        }
        $startYear = (int) substr($seasonId, 0, 4);
        $endYear   = (int) substr($seasonId, 4, 4);

        try {
            $start = Carbon::create($endYear, 8, 31)->startOfDay(); // later
            $end   = Carbon::create($startYear, 9, 1)->startOfDay(); // earlier
            return [$start, $end];
        } catch (\Throwable) {
            return [null, null];
        }
    }

    /** End date fallback from config('apiImportNhl.min_season_id'). */
    private function minSeasonEndDate(): Carbon
    {
        $minSeasonId = (string) config('apiImportNhl.min_season_id', '20192020');
        $endYear     = (int) substr($minSeasonId, 4, 4);
        return Carbon::create($endYear, 8, 31)->startOfDay();
    }

    private function normalizeOrder(Carbon $a, Carbon $b): array
    {
        return $a->gte($b) ? [$a, $b] : [$b, $a];
    }
}
