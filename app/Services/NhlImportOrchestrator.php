<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\NhlOrchestratorJob;
use App\Jobs\SeasonSumJob;
use App\Events\NhlGameImportStatusUpdated;
use App\Models\NhlGameImportRun;
use App\Repositories\NhlImportProgressRepo;
use App\Support\NhlImportStages;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class NhlImportOrchestrator
{
    private const ON_ICE_STAGES = [
        NhlImportStages::SHIFTS,
        NhlImportStages::SHIFT_UNITS,
        NhlImportStages::CONNECT_EVENTS,
        NhlImportStages::SUM_GAME_UNITS,
    ];

    public function __construct(
        private readonly NhlImportProgressRepo $repo,
        private readonly NhlGameSourcePreflight $sourcePreflight
    )
    {
    }

    /** Daily entry point: scan tracker and dispatch eligible jobs. */
    public function processScheduled(string $gameDate, ?int $runId = null): void
    {
        if ($this->repo->runningExistsForDate($gameDate, $runId)) {
            return;
        }

        foreach ($this->repo->scheduledGameIdsForDate($gameDate, $runId) as $gameId) {
            foreach (NhlImportStages::ordered() as $type) {
                if ($this->readyFor((int) $gameId, $type, $runId)) {
                    if ($this->dispatchJob((int) $gameId, $type, $runId)) {
                        return;
                    }

                    if ($this->repo->runningExistsForDate($gameDate, $runId)) {
                        return;
                    }
                }
            }
        }
    }

    /** Fill configured active game slots for one discovery run. */
    public function fillActiveGameSlotsForRun(int $runId): int
    {
        $lock = Cache::lock("nhl-import-run-fill:{$runId}", 30);

        if (! $lock->get()) {
            return 0;
        }

        try {
            $slots = max(1, (int) config('apiImportNhl.active_game_import_slots', 8));
            $activeGames = $this->repo->activeGameCountForRun($runId);
            $availableSlots = max(0, $slots - $activeGames);
            $dispatched = 0;

            if ($availableSlots === 0) {
                return 0;
            }

            foreach ($this->repo->scheduledGameIdsForRun($runId) as $gameId) {
                if ($dispatched >= $availableSlots) {
                    break;
                }

                if ($this->dispatchFirstReadyStageForGame($gameId, $runId)) {
                    $dispatched++;
                }
            }

            if ($dispatched === 0) {
                $this->markRunCompletedIfDone($runId);
            }

            return $dispatched;
        } finally {
            $lock->release();
        }
    }

    /** Claim a (game_id, type) for work: scheduled → running. */
    public function claim(int $gameId, string $type, ?int $runId = null): bool
    {
        return $this->repo->claim($gameId, $type, $runId);
    }

    /** Verify it’s running (used by jobs as a guard). */
    public function isRunning(int $gameId, string $type, ?int $runId = null): bool
    {
        return $this->repo->isRunning($gameId, $type, $runId);
    }

    /** Determine whether a running stage belongs to an explicit reprocess run. */
    public function isReprocessStage(int $gameId, string $type): bool
    {
        $payload = DB::table('nhl_import_progress as progress')
            ->join('nhl_game_import_runs as runs', 'runs.id', '=', 'progress.run_id')
            ->where('progress.game_id', $gameId)
            ->where('progress.import_type', $type)
            ->where('progress.status', 'running')
            ->value('runs.payload');

        if ($payload === null) {
            return false;
        }

        if (is_string($payload)) {
            $payload = json_decode($payload, true) ?: [];
        }

        if (! is_array($payload)) {
            return false;
        }

        return filter_var($payload['reprocess_existing'] ?? false, FILTER_VALIDATE_BOOL);
    }

    /** Jobs call this on success; centralizes status/metrics and advancement. */
    public function onSuccess(int $gameId, string $type, array $meta = []): void
    {
        $items = (int) ($meta['items_count'] ?? 0);
        $runId = $this->runIdForStage($gameId, $type);
        $this->repo->markCompleted($gameId, $type, $items);
        $this->seasonSummary($gameId, $type);
        $this->advance($gameId, $type, $runId);
    }

    /** Jobs call this on failure. */
    public function onFailure(int $gameId, string $type, string $message, $code = null): void
    {
        $runId = $this->runIdForStage($gameId, $type);
        $this->repo->markError($gameId, $type, $message, $code);
        $this->repo->markGameError($gameId, $message, $code);
        $this->continueRunOrDate($gameId, $type, $runId);
    }

    /** Enforce canonical NHL stage order. */
    public function advance(int $gameId, string $completedType, ?int $runId = null): void
    {
        $next = NhlImportStages::nextAfter($completedType);

        if ($next && $this->readyFor($gameId, $next, $runId)) {
            $this->dispatchJob($gameId, $next, $runId);
            return;
        }

        if ($next === null) {
            $this->continueRunOrDate($gameId, $completedType, $runId);
        }
    }

    /** Check if next stage exists (scheduled) and dependencies are completed. */
    public function readyFor(int $gameId, string $type, ?int $runId = null): bool
    {
        $deps = NhlImportStages::dependenciesFor($type);

        if (!$this->repo->scheduledExists($gameId, $type, $runId)) {
            return false;
        }

        return empty($deps) || $this->repo->completedDepsCount($gameId, $deps, $runId) === count($deps);
    }

    /** Claim and dispatch the appropriate job. */
    public function dispatchJob(int $gameId, string $type, ?int $runId = null): bool
    {
        $jobClass = NhlImportStages::jobClassFor($type);

        if (! $jobClass) {
            return false;
        }

        if (! $this->sourcePreflightAllows($gameId, $type, $type === NhlImportStages::PBP, $runId)) {
            return false;
        }

        if (! $this->repo->claim($gameId, $type, $runId)) {
            return false;
        }

        dispatch(new $jobClass($gameId));

        return true;
    }

    private function dispatchFirstReadyStageForGame(int $gameId, int $runId): bool
    {
        foreach (NhlImportStages::ordered() as $type) {
            if ($this->readyFor($gameId, $type, $runId)) {
                return $this->dispatchJob($gameId, $type, $runId);
            }
        }

        return false;
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

    private function sourcePreflightAllows(int $gameId, string $type, bool $refresh, ?int $runId): bool
    {
        $result = $refresh
            ? $this->sourcePreflight->check($gameId)
            : $this->sourcePreflight->storedOrCheck($gameId);

        if (! $result['core_allowed']) {
            $this->repo->markSkipped(
                $gameId,
                NhlImportStages::ordered(),
                $result['core_message'] ?? 'NHL source preflight skipped import.'
            );

            return false;
        }

        if (
            in_array($type, self::ON_ICE_STAGES, true)
            && ! $result['on_ice_allowed']
        ) {
            $this->repo->markSkipped(
                $gameId,
                self::ON_ICE_STAGES,
                $result['on_ice_message'] ?? 'NHL shiftcharts source missing; on-ice stages skipped.'
            );

            if ($this->readyFor($gameId, NhlImportStages::VALIDATE_SUMMARY, $runId)) {
                $this->dispatchJob($gameId, NhlImportStages::VALIDATE_SUMMARY, $runId);
            }

            return false;
        }

        return true;
    }

    private function runIdForStage(int $gameId, string $type): ?int
    {
        $runId = DB::table('nhl_import_progress')
            ->where('game_id', $gameId)
            ->where('import_type', $type)
            ->value('run_id');

        return $runId !== null ? (int) $runId : null;
    }

    private function continueRunOrDate(int $gameId, string $type, ?int $runId): void
    {
        if ($runId !== null) {
            $this->fillActiveGameSlotsForRun($runId);
            return;
        }

        $this->dispatchNextScheduledGameForDate($gameId, $type);
    }

    private function dispatchNextScheduledGameForDate(int $gameId, string $type): void
    {
        $gameDate = DB::table('nhl_import_progress')
            ->where('game_id', $gameId)
            ->where('import_type', $type)
            ->value('game_date');

        if (! $gameDate) {
            return;
        }

        dispatch(new NhlOrchestratorJob((string) $gameDate));
    }

    private function markRunCompletedIfDone(int $runId): void
    {
        if ($this->repo->hasOpenRowsForRun($runId)) {
            return;
        }

        $run = NhlGameImportRun::query()->find($runId);

        if (! $run || $run->status === NhlGameImportRun::STATUS_COMPLETED) {
            return;
        }

        $payload = $run->payload ?? [];
        $payload['completed_at'] = now()->toIso8601String();

        $run->forceFill([
            'status' => NhlGameImportRun::STATUS_COMPLETED,
            'payload' => $payload,
            'updated_at' => now(),
        ])->save();

        broadcast(new NhlGameImportStatusUpdated('processing-completed', $runId));
    }
}
