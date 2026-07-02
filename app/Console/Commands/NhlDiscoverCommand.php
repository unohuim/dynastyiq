<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Events\NhlGameImportStatusUpdated;
use App\Jobs\NhlDiscoveryJob;
use App\Models\NhlGameImportRun;
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
        {--newdays= : Integer; window = [oldest_progress_date ... oldest_progress_date - NEWDAYS]}';

    protected $description = 'Discover NHL games for a date window and dispatch per-day discovery jobs.';

    public function handle(): int
    {
        $season      = (string) ($this->option('season') ?? '');
        $startOpt    = $this->parseDate((string) ($this->option('start') ?? ''));
        $endOpt      = $this->parseDate((string) ($this->option('end') ?? ''));
        $daysRaw     = $this->option('days');
        $days        = ($daysRaw === null || $daysRaw === '') ? null : (int) $daysRaw;

        $dateOpt     = $this->parseDate((string) ($this->option('date') ?? ''));
        $newDaysRaw  = $this->option('newdays');
        $newDays     = ($newDaysRaw === null || $newDaysRaw === '') ? null : abs((int) $newDaysRaw);

        // 1) --date has absolute precedence
        if ($dateOpt) {
            $this->queueDiscoveryRun($dateOpt, $dateOpt, NhlGameImportRun::MODE_DATE, [
                'date' => $dateOpt->toDateString(),
            ]);
            $this->info("Queued discovery (single day) {$dateOpt->toDateString()}.");
            return self::SUCCESS;
        }

        // 2) --season overrides the rest (except --date)
        if ($season !== '') {
            [$seasonStart, $seasonEnd] = $this->seasonBounds($season);
            if (!$seasonStart || !$seasonEnd) {
                $this->error("Invalid season: {$season}");
                return self::INVALID;
            }
            $this->queueDiscoveryRun($seasonStart, $seasonEnd, NhlGameImportRun::MODE_SEASON, [
                'season' => $season,
            ]);
            $this->info("Queued discovery for season {$season} ({$seasonStart->toDateString()} → {$seasonEnd->toDateString()}).");
            return self::SUCCESS;
        }

        // 3) --newdays extends window further into the past from the current oldest progress date
        if (is_int($newDays)) {
            $oldest = $this->oldestProgressDate(); // from nhl_import_progress.game_date
            if (!$oldest) {
                $this->error('No rows in nhl_import_progress; cannot use --newdays.');
                return self::INVALID;
            }

            // Window: [oldest (newer), oldest - newDays (older)]
            $start = $oldest->copy();                       // newer boundary
            $end   = $oldest->copy()->subDays($newDays);    // older boundary

            // Ensure (start >= end)
            if ($start->lt($end)) {
                [$start, $end] = [$end, $start];
            }

            $this->queueDiscoveryRun($start, $end, NhlGameImportRun::MODE_NEWDAYS, [
                'newdays' => $newDays,
            ]);
            $this->info("Queued discovery via --newdays {$start->toDateString()} → {$end->toDateString()}.");
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

        $this->queueDiscoveryRun(
            $start,
            $end,
            $this->modeForOptions($startOpt, $endOpt, $days),
            $this->payloadForOptions($startOpt, $endOpt, $days)
        );
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
     * Record an admin-visible discovery run and dispatch the queued discovery job.
     *
     * @param array<string, mixed> $payload
     */
    private function queueDiscoveryRun(Carbon $start, Carbon $end, string $mode, array $payload): void
    {
        $run = NhlGameImportRun::create([
            'action' => NhlGameImportRun::ACTION_DISCOVER,
            'mode' => $mode,
            'status' => NhlGameImportRun::STATUS_QUEUED,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'date_count' => (int) $end->diffInDays($start) + 1,
            'queued_jobs' => 1,
            'payload' => $payload,
            'created_by' => null,
        ]);

        dispatch(new NhlDiscoveryJob($start, $end));
        broadcast(new NhlGameImportStatusUpdated('discovery-queued', $run->id));
    }

    /**
     * Determine the admin run mode for command options that use the default date-window branch.
     */
    private function modeForOptions(?Carbon $start, ?Carbon $end, ?int $days): string
    {
        if ($days !== null) {
            return NhlGameImportRun::MODE_DAYS;
        }

        if ($start || $end) {
            return NhlGameImportRun::MODE_RANGE;
        }

        return NhlGameImportRun::MODE_DEFAULT;
    }

    /**
     * Build a compact payload that mirrors the command-line date options.
     *
     * @return array<string, mixed>
     */
    private function payloadForOptions(?Carbon $start, ?Carbon $end, ?int $days): array
    {
        return array_filter([
            'start' => $start?->toDateString(),
            'end' => $end?->toDateString(),
            'days' => $days,
        ], fn ($value): bool => $value !== null && $value !== '');
    }

    /**
     * Oldest game_date in nhl_import_progress.
     */
    private function oldestProgressDate(): ?Carbon
    {
        $minDate = DB::table('nhl_import_progress')->min('game_date');
        return $minDate ? Carbon::parse($minDate)->startOfDay() : null;
    }
}
