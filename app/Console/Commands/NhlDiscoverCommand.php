<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\NhlDiscoveryJob;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class NhlDiscoverCommand extends Command
{
    /**
     * Examples:
     *  php artisan nhl:discover --date=2025-02-15
     *  php artisan nhl:discover --newdays=7
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
        {--end= : End date (YYYY-MM-DD)}
        {--date= : Single date (YYYY-MM-DD) — overrides all other flags}
        {--newdays= : Integer; start=oldest progress date, end=oldest+newdays (overrides start/end/days)}';

    protected $description = 'Discover NHL games for a date window and dispatch per-day discovery jobs.';

    public function handle(): int
    {
        $season    = (string) ($this->option('season') ?? '');
        $startOpt  = $this->parseDate((string) ($this->option('start') ?? ''));
        $endOpt    = $this->parseDate((string) ($this->option('end') ?? ''));
        $daysRaw   = $this->option('days');
        $days      = ($daysRaw === null || $daysRaw === '') ? null : (int) $daysRaw;

        $dateOpt   = $this->parseDate((string) ($this->option('date') ?? ''));
        $newDaysRaw = $this->option('newdays');
        $newDays    = ($newDaysRaw === null || $newDaysRaw === '') ? null : (int) $newDaysRaw;

        // 1) --date has absolute precedence
        if ($dateOpt) {
            $start = $dateOpt;
            $end   = $dateOpt;
            dispatch(new NhlDiscoveryJob($start, $end));
            $this->info("Queued discovery (single day) {$start->toDateString()}.");
            return self::SUCCESS;
        }

        // 2) --season overrides the rest (except --date)
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

        // 3) --newdays overrides start/end/days logic
        if (is_int($newDays)) {
            $oldest = $this->oldestProgressDate();
            if (!$oldest) {
                $this->error('No oldest progress date found (configure apiImportNhl.progress_table/progress_date_column).');
                return self::INVALID;
            }
            $start = $oldest->copy();                      // older
            $end   = $oldest->copy()->addDays(max(0, $newDays)); // newer

            // Normalize to (start >= end) as in existing pattern
            if ($start->lt($end)) {
                [$start, $end] = [$end, $start];
            }

            dispatch(new NhlDiscoveryJob($start, $end));
            $this->info("Queued discovery via --newdays from {$oldest->toDateString()} → {$start->toDateString()}.");
            return self::SUCCESS;
        }

        // 4) existing logic
        if ($startOpt && $endOpt) {
            [$start, $end] = $this->normalizeOrder($startOpt, $endOpt);
        } elseif ($startOpt && is_int($days)) {
            $start = $startOpt;
            $end   = $startOpt->copy()->subDays(max(0, $days));
        } elseif ($endOpt && is_int($days)) {
            $end   = $endOpt;
            $start = $endOpt->copy()->addDays(max(0, $days));
        } elseif ($startOpt) {
            $start = $startOpt;
            $end   = $this->minSeasonEndDate();
        } elseif ($endOpt) {
            $start = Carbon::today();
            $end   = $endOpt;
        } elseif (is_int($days)) {
            $start = Carbon::today();
            $end   = Carbon::today()->copy()->subDays(max(0, $days));
        } else {
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

    /** For season like 20232024 → [start=YYYY-08-31 of endYear, end=YYYY-09-01 of startYear]. */
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

    /** Earliest boundary from config('apiImportNhl.min_season_id'). */
    private function minSeasonEndDate(): Carbon
    {
        $minSeasonId = (string) config('apiImportNhl.min_season_id', '20192020');
        $startYear   = (int) substr($minSeasonId, 0, 4);
        return Carbon::create($startYear, 9, 1)->startOfDay();
    }

    private function normalizeOrder(Carbon $a, Carbon $b): array
    {
        return $a->gte($b) ? [$a, $b] : [$b, $a];
    }

    /**
     * Oldest in-progress date (configurable source).
     * Expects config keys:
     *  - apiImportNhl.progress_table (default: nhl_discovery_progress)
     *  - apiImportNhl.progress_date_column (default: date)
     */
    private function oldestProgressDate(): ?Carbon
    {
        // Adjust table/column names here if yours differ.
        $minDate = DB::table('nhl_import_progress')->min('game_date');

        return $minDate ? Carbon::parse($minDate)->startOfDay() : null;
    }

}
