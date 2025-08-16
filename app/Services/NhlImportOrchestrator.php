<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\NhlImportProgressRepo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use App\Jobs\SeasonSumJob;


class NhlImportOrchestrator
{
    /** @var array<string> */
    private array $importTypes = ['pbp', 'summary', 'shifts', 'boxscore', 'shift-units', 'connect-events' ];

    public function __construct(private readonly NhlImportProgressRepo $repo)
    {
    }

    /** Daily entry point: scan tracker and dispatch eligible jobs. */
    public function processScheduled(string $gameDate): void
    {
        foreach (['pbp','summary','shifts','boxscore','shift-units','connect-events'] as $type) {
            $gameIds = DB::table('nhl_import_progress')
                ->where('import_type', $type)
                ->where('status', 'scheduled')
                ->whereDate('game_date', $gameDate)
                ->pluck('game_id');

            foreach ($gameIds as $gameId) {
                if ($this->readyFor((int)$gameId, $type)) {
                    $this->dispatchJob((int)$gameId, $type);
                }
            }
        }
    }


    /** Claim a (game_id, type) for work: scheduled → running. */
    public function claim(int $gameId, string $type): bool
    {
        return $this->repo->claim($gameId, $type);
    }

    /** Verify it’s running (used by jobs as a guard). */
    public function isRunning(int $gameId, string $type): bool
    {
        return $this->repo->isRunning($gameId, $type);
    }

    /** Jobs call this on success; centralizes status/metrics and advancement. */
    public function onSuccess(int $gameId, string $type, array $meta = []): void
    {
        $items = (int)($meta['items_count'] ?? 0);
        $this->repo->markCompleted($gameId, $type, $items);
        $this->seasonSummary($gameId, $type);
        $this->advance($gameId, $type);
    }

    /** Jobs call this on failure. */
    public function onFailure(int $gameId, string $type, string $message, $code = null): void
    {
        $this->repo->markError($gameId, $type, $message, $code);
    }


    public function onRunning(int $gameId, string $type): bool
    {
        return $this->repo->markRunning($gameId, $type);
    }


    /** Enforce order: pbp → summary → shifts → boxscore → unit-shifts → connect-events. */
    public function advance(int $gameId, string $completedType): void
    {
        $next = match ($completedType) {
            'pbp'           => 'summary',
            'summary'       => 'shifts',
            'shifts'        => 'boxscore',
            'boxscore'      => 'shift-units',
            'shift-units'   => 'connect-events',
            default    => null,
        };


        if ($next && $this->readyFor($gameId, $next)) {
            $this->dispatchJob($gameId, $next);
        }
    }

    /** Check if next stage exists (scheduled) and dependencies are completed. */
    public function readyFor(int $gameId, string $type): bool
    {
        $deps = match ($type) {
            'pbp'               => [],
            'summary'           => ['pbp'],
            'shifts'            => ['pbp','summary'],
            'boxscore'          => ['pbp','summary','shifts'],
            'shift-units'       => ['pbp','summary','shifts','boxscore'],
            'connect-events'    => ['pbp','summary','shifts','boxscore','shift-units'],
            default    => [],
        };

        if (!$this->repo->scheduledExists($gameId, $type)) {
            return false;
        }

        return empty($deps) || $this->repo->completedDepsCount($gameId, $deps) === count($deps);
    }

    /** Claim and dispatch the appropriate job. */
    public function dispatchJob(int $gameId, string $type): void
    {
        if (!$this->repo->claim($gameId, $type)) {
            return;
        }

        switch ($type) {
            case 'pbp':
                dispatch(new \App\Jobs\ImportPbpNhlJob($gameId));//->onQueue('pbp');
                break;
            case 'summary':
                dispatch(new \App\Jobs\SummarizePbpNhlJob($gameId));//->onQueue('summary');
                break;
            case 'shifts':
                dispatch(new \App\Jobs\ImportShiftsNhlJob($gameId));//->onQueue('shifts');
                break;
            case 'boxscore':
                dispatch(new \App\Jobs\ImportBoxscoreNhlJob($gameId));//->onQueue('boxscore');
                break;
            case 'shift-units': 
                dispatch(new \App\Jobs\MakeShiftUnitsNhlJob($gameId));//->onQueue('make-units');
                break;
            case 'connect-events':
                dispatch(new \App\Jobs\ConnectEventsShiftUnitsNhlJob($gameId));//->onQueue('connect-events');
                break;
            
        }
    }

    /** Stale-running sweeper using per-type thresholds from config. */
    public function sweepStale(): void
    {
        $thresholds = [
            'pbp'      => (int) config('nhlimport.max_pbp_seconds', 7200),
            'shifts'   => (int) config('nhlimport.max_shifts_seconds', 7200),
            'boxscore' => (int) config('nhlimport.max_boxscore_seconds', 7200),
            'summary'  => (int) config('nhlimport.max_game_summaries_seconds', 7200),
            'shift-units'  => (int) config('nhlimport.max_shift_units_seconds', 7200),
            'connect-events'  => (int) config('nhlimport.max_connect_events_seconds', 7200),
        ];

        foreach ($thresholds as $type => $secs) {
            $cutoff = now()->subSeconds(max(60, $secs));
            $this->repo->markStaleRunningToError($type, $cutoff);
        }
    }



    /**
     * If a SUMMARY just completed, dispatch SeasonSumJob for that season
     * only when **all** season summaries are completed (no scheduled/running/error left).
     */
    private function seasonSummary(int $gameId, string $type): void
    {
        if ($type !== 'boxscore') {
            return;
        }

        // Find the season for this game
        $seasonId = DB::table('nhl_import_progress')
            ->where('game_id', $gameId)
            ->value('season_id');

        if (!$seasonId) {
            return;
        }

        // Any summaries not completed yet? (scheduled/running/error block the season sum)
        $notDone = DB::table('nhl_import_progress')
            ->where('season_id', $seasonId)
            ->where('import_type', 'boxscore')
            ->whereIn('status', ['scheduled', 'running', 'error'])
            ->count();

        if ($notDone > 0) {
            return; // still work to do
        }

        // Best-effort de-dupe so multiple concurrent completions don't double-dispatch
        $lockKey = "season-sum-dispatch:{$seasonId}";
        if (Cache::lock($lockKey, 600)->get()) { // 10 min lock
            try {
                dispatch(new \App\Jobs\SeasonSumJob($seasonId));//->onQueue('summary');
            } finally {
                Cache::lock($lockKey)->release();
            }
        }
    }

}
