<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\NhlScheduleRefresh;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Throwable;

/**
 * Refresh future NHL schedule rows for prediction use.
 */
class ImportNhlScheduleCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'nhl:schedule-import
        {--from= : Start date, default today (YYYY-MM-DD)}
        {--to= : End date, default July 1 next year (YYYY-MM-DD)}';

    /**
     * @var string
     */
    protected $description = 'Refresh NHL schedule rows in nhl_games without running game imports.';

    public function handle(NhlScheduleRefresh $refresh): int
    {
        try {
            $today = Carbon::today();
            $from = $this->dateOption('from') ?? $today->copy();
            $to = $this->dateOption('to') ?? $today->copy()->addYear()->setDate($today->year + 1, 7, 1);
        } catch (Throwable $throwable) {
            $this->error($throwable->getMessage());

            return self::INVALID;
        }

        $this->info(sprintf(
            'Refreshing NHL schedule %s to %s.',
            $from->toDateString(),
            $to->toDateString()
        ));

        $summary = $refresh->refreshRange($from, $to);

        $this->table(
            ['Dates', 'Fetched', 'Deleted', 'Inserted', 'Upserted', 'Replace Dates', 'Upsert Dates', 'Failures'],
            [[
                $summary['dates'],
                $summary['fetched'],
                $summary['deleted'],
                $summary['inserted'],
                $summary['upserted'],
                $summary['replaced_dates'],
                $summary['upserted_dates'],
                count($summary['failed_dates']),
            ]]
        );

        foreach ($summary['failed_dates'] as $failure) {
            $this->warn($failure['date'] . ': ' . $failure['error']);
        }

        return $summary['failed_dates'] === [] ? self::SUCCESS : self::FAILURE;
    }

    private function dateOption(string $name): ?Carbon
    {
        $value = trim((string) ($this->option($name) ?? ''));

        if ($value === '') {
            return null;
        }

        return Carbon::parse($value)->startOfDay();
    }
}
