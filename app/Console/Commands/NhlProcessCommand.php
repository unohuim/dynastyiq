<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Jobs\NhlOrchestratorJob;

class NhlProcessCommand extends Command
{
    protected $signature = 'nhl:process {--date=}';
    protected $description = 'Dispatch one orchestrator job per game_date with scheduled work.';

    public function handle(): int
    {
        $single = $this->option('date');

        $dates = $single
            ? collect([$single])
            : DB::table('nhl_import_progress')
                ->where('status', 'scheduled')
                ->distinct()
                ->orderBy('game_date')
                ->pluck('game_date');

        foreach ($dates as $date) {
            dispatch(new NhlOrchestratorJob((string)$date))->onQueue('orchestrator');
        }

        $this->info('Dispatched for '.count($dates).' game_date(s).');
        return self::SUCCESS;
    }
}
