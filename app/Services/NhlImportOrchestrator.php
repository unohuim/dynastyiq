<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\NhlImportProgressRepo;
use Illuminate\Support\Facades\DB;

class NhlImportOrchestrator
{
    /** @var array<string> */
    private array $importTypes = ['pbp', 'summary', 'shifts', 'boxscore', 'shift-units', 'connect-events' ];

    public function __construct(private readonly NhlImportProgressRepo $repo)
    {
    }

    /** Daily entry point: scan tracker and dispatch eligible jobs. */
    public function processScheduled(int $perTypeLimit = 500, ?string $seasonId = null, ?int $gameType = null): void
    {
        // enforce order: pbp -> summary -> shifts -> boxscore -> unit-shifts -> connect-events
        foreach (['pbp','summary','shifts','boxscore','shift-units','connect-events'] as $type) {
            $gameIds = DB::table('nhl_import_progress')
                ->select('game_id')
                ->where('import_type', $type)
                ->where('status', 'scheduled')
                ->when($seasonId, fn ($q) => $q->where('season_id', $seasonId))
                ->when(!is_null($gameType), fn ($q) => $q->where('game_type', $gameType))
                ->orderByDesc('game_date')
                ->limit($perTypeLimit)
                ->pluck('game_id');

            foreach ($gameIds as $gameId) {
                if ($this->readyFor((int)$gameId, $type)) {

                    $this->dispatchJob((int)$gameId, $type); // claims -> running inside
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
        \Log::warning("completed type:  {$completedType}");

        $next = match ($completedType) {
            'pbp'           => 'summary',
            'summary'       => 'shifts',
            'shifts'        => 'boxscore',
            'boxscore'      => 'shift-units',
            'shift-units'   => 'connect-events',
            default    => null,
        };

        \Log::warning("next type:  {$next}");

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
                dispatch(new \App\Jobs\ImportPbpNhlJob($gameId))->onQueue('pbp');
                break;
            case 'summary':
                dispatch(new \App\Jobs\SummarizePbpNhlJob($gameId));
                break;
            case 'shifts':
                dispatch(new \App\Jobs\ImportShiftsNhlJob($gameId));
                break;
            case 'boxscore':
                dispatch(new \App\Jobs\ImportBoxscoreNhlJob($gameId));
                break;
            case 'shift-units': 
                dispatch(new \App\Jobs\MakeShiftUnitsNhlJob($gameId));
                break;
            case 'connect-events':
                dispatch(new \App\Jobs\ConnectEventsShiftUnitsNhlJob($gameId));
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
}
