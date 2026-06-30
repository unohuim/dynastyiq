<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\SeasonSumJob;
use App\Repositories\NhlImportProgressRepo;
use App\Support\NhlImportStages;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class NhlImportOrchestrator
{
    public function __construct(private readonly NhlImportProgressRepo $repo)
    {
    }

    /** Daily entry point: scan tracker and dispatch eligible jobs. */
    public function processScheduled(string $gameDate): void
    {
        foreach (NhlImportStages::ordered() as $type) {
            $gameIds = DB::table('nhl_import_progress')
                ->where('import_type', $type)
                ->where('status', 'scheduled')
                ->whereDate('game_date', $gameDate)
                ->pluck('game_id');

            foreach ($gameIds as $gameId) {
                if ($this->readyFor((int) $gameId, $type)) {
                    $this->dispatchJob((int) $gameId, $type);
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
        $items = (int) ($meta['items_count'] ?? 0);
        $this->repo->markCompleted($gameId, $type, $items);
        $this->seasonSummary($gameId, $type);
        $this->advance($gameId, $type);
    }

    /** Jobs call this on failure. */
    public function onFailure(int $gameId, string $type, string $message, $code = null): void
    {
        $this->repo->markError($gameId, $type, $message, $code);
    }

    /** Enforce canonical NHL stage order. */
    public function advance(int $gameId, string $completedType): void
    {
        $next = NhlImportStages::nextAfter($completedType);

        if ($next && $this->readyFor($gameId, $next)) {
            $this->dispatchJob($gameId, $next);
        }
    }

    /** Check if next stage exists (scheduled) and dependencies are completed. */
    public function readyFor(int $gameId, string $type): bool
    {
        $deps = NhlImportStages::dependenciesFor($type);

        if (!$this->repo->scheduledExists($gameId, $type)) {
            return false;
        }

        return empty($deps) || $this->repo->completedDepsCount($gameId, $deps) === count($deps);
    }

    /** Claim and dispatch the appropriate job. */
    public function dispatchJob(int $gameId, string $type): void
    {
        $jobClass = NhlImportStages::jobClassFor($type);

        if (!$jobClass || !$this->repo->claim($gameId, $type)) {
            return;
        }

        dispatch(new $jobClass($gameId));
    }

    /** Stale-running sweeper using per-type thresholds from config. */
    public function sweepStale(): void
    {
        foreach (NhlImportStages::ordered() as $type) {
            $configKey = NhlImportStages::timeoutConfigKeyFor($type);
            $secs = (int) config((string) $configKey, 7200);
            $cutoff = now()->subSeconds(max(60, $secs));
            $this->repo->markStaleRunningToError($type, $cutoff);
        }
    }

    /**
     * If all season validations have completed, dispatch the season stat rollup once.
     */
    private function seasonSummary(int $gameId, string $type): void
    {
        if ($type !== NhlImportStages::VALIDATE_SUMMARY) {
            return;
        }

        // Find the season for this game
        $seasonId = DB::table('nhl_import_progress')
            ->where('game_id', $gameId)
            ->value('season_id');

        if (! $seasonId) {
            return;
        }

        // Scheduled/running/error validations block the season sum.
        $notDone = DB::table('nhl_import_progress')
            ->where('season_id', $seasonId)
            ->where('import_type', NhlImportStages::VALIDATE_SUMMARY)
            ->whereIn('status', ['scheduled', 'running', 'error'])
            ->count();

        if ($notDone > 0) {
            return; // still work to do
        }

        // Best-effort de-dupe so multiple concurrent completions don't double-dispatch
        $lockKey = "season-sum-dispatch:{$seasonId}";
        $lock = Cache::lock($lockKey, 600);

        if ($lock->get()) { // 10 min lock
            try {
                dispatch(new SeasonSumJob($seasonId));
            } finally {
                $lock->release();
            }
        }
    }
}
