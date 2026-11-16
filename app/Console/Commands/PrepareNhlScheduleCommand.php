<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ImportRun;
use App\Services\NhlScheduleSnapshots;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Prepare durable public schedule views through the existing admin import runner. */
class PrepareNhlScheduleCommand extends Command
{
    protected $signature = 'nhl:prepare-schedule {--season= : One eight-digit season; otherwise all stored seasons and current season} {--import-run-id=}';
    protected $description = 'Prepare weekly schedule snapshots and remaining-season off-night totals.';

    /** Record admin progress only after each season publishes successfully. */
    public function handle(NhlScheduleSnapshots $snapshots): int
    {
        $run = $this->option('import-run-id') ? ImportRun::findOrFail((int) $this->option('import-run-id')) : null;
        try {
            $seasons = $this->option('season') ? collect([(string) $this->option('season')])
                : DB::table('nhl_games')->distinct()->pluck('season_id')->push($snapshots->seasonId(CarbonImmutable::now('America/Toronto')))->unique()->sort()->values();
            $run?->setProgressTotal($seasons->count(), 'Seasons prepared');
            foreach ($seasons as $season) {
                $count = $snapshots->build((string) $season);
                $run?->recordProcessed();
                $this->info($season.': '.$count.' schedule snapshots prepared.');
            }
            $run?->markCompleted();
            return self::SUCCESS;
        } catch (Throwable $exception) {
            $run?->markFailed($exception);
            $this->error($exception->getMessage());
            return self::FAILURE;
        }
    }
}
