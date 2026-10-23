<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Listeners\QueueNhlStatsDashboardRefresh;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Queue public stats preparation after deployment or for previously imported seasons. */
class RefreshNhlStatsCacheCommand extends Command
{
    protected $signature = 'nhl:stats-cache {--season= : Eight-digit season ID} {--all : Prepare every stored season}';
    protected $description = 'Queue retained public stats snapshots without importing or changing source statistics.';

    /** Require an explicit season scope and leave calculations to projections workers. */
    public function handle(QueueNhlStatsDashboardRefresh $refresh): int
    {
        $season = $this->option('season');
        if ((bool) $this->option('all') === ($season !== null)) {
            $this->error('Choose --season=20262027 or --all.');
            return self::FAILURE;
        }
        if ($season !== null && ! preg_match('/^\d{8}$/', (string) $season)) {
            $this->error('Season must contain eight digits.');
            return self::FAILURE;
        }
        $seasons = $this->option('all')
            ? DB::table('nhl_season_stats')->distinct()->pluck('season_id')
                ->merge(DB::table('stats')->distinct()->pluck('season_id'))->unique()->sort()->values()->all()
            : [(string) $season];
        foreach ($seasons as $seasonId) {
            if (preg_match('/^\d{8}$/', (string) $seasonId)) {
                $refresh->queueSeason((string) $seasonId);
            }
        }
        $this->info('Public stats preparation queued on projections.');
        return self::SUCCESS;
    }
}
