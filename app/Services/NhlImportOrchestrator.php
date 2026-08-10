<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\NhlGameImportStatusUpdated;
use App\Jobs\BuildNhlFaceoffFactsJob;
use App\Jobs\BuildNhlShotAttemptFactsJob;
use App\Jobs\NhlOrchestratorJob;
use App\Jobs\RefreshNhlGameContextJob;
use App\Jobs\SeasonSumJob;
use App\Models\NhlGameImportRun;
use App\Models\NhlGameSourceStatus;
use App\Models\NhlGameValidation;
use App\Repositories\NhlImportProgressRepo;
use App\Support\NhlImportStages;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class NhlImportOrchestrator
{
    private const ON_ICE_STAGES = [
        NhlImportStages::SHIFTS,
        NhlImportStages::SHIFT_UNITS,
        NhlImportStages::CONNECT_EVENTS,
        NhlImportStages::HTML_PBP_VERIFY,
        NhlImportStages::SUM_GAME_UNITS,
    ];

    public function __construct(
        private readonly NhlImportProgressRepo $repo,
        private readonly NhlGameSourcePreflight $sourcePreflight,
        private readonly NhlValidationTroubleshootingExporter $troubleshootingExporter
    ) {
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
    public function isReprocessStage(int $gameId, string $type, ?int $runId = null): bool
    {
        $query = DB::table('nhl_import_progress as progress')
            ->join('nhl_game_import_runs as runs', 'runs.id', '=', 'progress.run_id')
            ->where('progress.game_id', $gameId)
            ->where('progress.import_type', $type)
            ->where('progress.status', 'running');

        if ($runId !== null) {
            $query->where('progress.run_id', $runId);
        }

        $payload = $query->value('runs.payload');

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
        $this->repo->markCompleted($gameId, $type, $items, $runId, true);
        $this->clearTroubleshootingDirectoryAfterSuccessfulStage($gameId, $type);
        $this->seasonSummary($gameId, $type);
        $this->advance($gameId, $type, $runId);
    }

    /** Jobs call this on failure. */
    public function onFailure(
        int $gameId,
        string $type,
        string $message,
        $code = null,
        ?int $runId = null,
        ?string $sentryEventId = null,
        ?string $failureCategory = null,
        ?bool $retryable = null
    ): void
    {
        $runId ??= $this->runIdForStage($gameId, $type);
        $this->repo->markError(
            $gameId,
            $type,
            $message,
            $code,
            $runId,
            true,
            $sentryEventId,
            $failureCategory,
            $retryable
        );
        $this->repo->markGameError(
            $gameId,
            $message,
            $code,
            $runId,
            true,
            $sentryEventId,
            $failureCategory,
            $retryable
        );
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

        dispatch(new $jobClass($gameId, $runId));

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

    private function clearTroubleshootingDirectoryAfterSuccessfulStage(int $gameId, string $type): void
    {
        if ($type !== NhlImportStages::VALIDATE_SUMMARY) {
            $this->troubleshootingExporter->deleteGameDirectory($gameId);

            return;
        }

        $validation = NhlGameValidation::query()
            ->where('nhl_game_id', $gameId)
            ->where('validation_type', NhlGameValidation::TYPE_SUMMARY_BOXSCORE)
            ->first();

        if ($validation?->shouldDeleteTroubleshootingDirectory() === true) {
            $this->troubleshootingExporter->deleteGameDirectory($gameId);
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
                $result['core_message'] ?? 'NHL source preflight skipped import.',
                $runId,
                true
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
                $result['on_ice_message'] ?? 'NHL shiftcharts source missing; on-ice stages skipped.',
                $runId,
                true
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
            ->where('status', 'running')
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

        if ($this->shouldQueueFullPostProcess($run, $payload) && ! $this->hasFullPostProcessCompleted($payload)) {
            if ($this->hasFullPostProcessStarted($payload)) {
                return;
            }

            $this->queueFullPostProcess($run);

            return;
        }

        $payload['completed_at'] = now()->toIso8601String();

        $run->forceFill([
            'status' => NhlGameImportRun::STATUS_COMPLETED,
            'payload' => $payload,
            'updated_at' => now(),
        ])->save();

        broadcast(new NhlGameImportStatusUpdated('processing-completed', $runId));
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function shouldQueueFullPostProcess(NhlGameImportRun $run, array $payload): bool
    {
        if (($payload['process_scope'] ?? null) !== null) {
            return false;
        }

        if ($run->action === NhlGameImportRun::ACTION_PROCESS) {
            return true;
        }

        return $run->action === NhlGameImportRun::ACTION_DISCOVER
            && (bool) ($payload['processing_started_at'] ?? false);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function hasFullPostProcessStarted(array $payload): bool
    {
        return (bool) ($payload['post_process_enrichment_started_at'] ?? false);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function hasFullPostProcessCompleted(array $payload): bool
    {
        return (bool) ($payload['post_process_enrichment_completed_at'] ?? false);
    }

    private function queueFullPostProcess(NhlGameImportRun $run): void
    {
        $gameIds = $this->gameIdsForRun((int) $run->id);
        $payload = $run->payload ?? [];

        if ($gameIds === []) {
            $payload['post_process_enrichment_completed_at'] = now()->toIso8601String();
            $payload['completed_at'] = now()->toIso8601String();

            $run->forceFill([
                'status' => NhlGameImportRun::STATUS_COMPLETED,
                'payload' => $payload,
                'updated_at' => now(),
            ])->save();

            broadcast(new NhlGameImportStatusUpdated('processing-completed', (int) $run->id));

            return;
        }

        $runId = (int) $run->id;
        $jobs = [];

        foreach ($gameIds as $gameId) {
            $jobs[] = new BuildNhlShotAttemptFactsJob($gameId, $runId);
            $jobs[] = new BuildNhlFaceoffFactsJob($gameId, $runId);
            $jobs[] = new RefreshNhlGameContextJob($gameId, $runId);
        }

        $payload['post_process_enrichment_started_at'] = now()->toIso8601String();
        $payload['post_process_enrichment_game_count'] = count($gameIds);
        $payload['post_process_enrichment_job_count'] = count($jobs);
        $payload['post_process_enrichment_scopes'] = ['shots', 'faceoffs', 'refs_staff'];

        $run->forceFill([
            'status' => NhlGameImportRun::STATUS_RUNNING,
            'payload' => $payload,
            'updated_at' => now(),
        ])->save();

        $batch = Bus::batch($jobs)
            ->then(function (Batch $batch) use ($runId): void {
                self::recordFullPostProcessCompleted($runId, $batch->id);
            })
            ->catch(function (Batch $batch, Throwable $throwable) use ($runId): void {
                self::recordFullPostProcessFailure($runId, $batch->id, $throwable);
            })
            ->name('NHL:FullPostProcess:' . $runId)
            ->dispatch();

        $run->refresh();
        $payload = $run->payload ?? [];
        $payload['post_process_enrichment_batch_id'] = $batch->id;

        $run->forceFill([
            'payload' => $payload,
            'updated_at' => now(),
        ])->save();

        broadcast(new NhlGameImportStatusUpdated('post-process-enrichment-queued', $runId));
    }

    /**
     * @return array<int,int>
     */
    private function gameIdsForRun(int $runId): array
    {
        return DB::table('nhl_import_progress')
            ->where('run_id', $runId)
            ->distinct()
            ->orderBy('game_id')
            ->pluck('game_id')
            ->map(fn (mixed $gameId): int => (int) $gameId)
            ->all();
    }

    private static function recordFullPostProcessCompleted(int $runId, string $batchId): void
    {
        $run = NhlGameImportRun::query()->find($runId);

        if (! $run) {
            return;
        }

        $payload = $run->payload ?? [];
        $gameIds = DB::table('nhl_import_progress')
            ->where('run_id', $runId)
            ->distinct()
            ->pluck('game_id')
            ->map(fn (mixed $gameId): int => (int) $gameId)
            ->all();

        $payload['post_process_enrichment_batch_id'] = $batchId;
        $payload['post_process_enrichment_completed_at'] = now()->toIso8601String();
        $payload['post_process_enrichment_processed_game_count'] = count($gameIds);
        $payload['shot_fact_game_count'] = count($gameIds);
        $payload['shot_fact_processed_game_count'] = self::processedGameCount('nhl_shot_attempts_facts', $gameIds);
        $payload['faceoff_fact_game_count'] = count($gameIds);
        $payload['faceoff_fact_processed_game_count'] = self::processedGameCount('nhl_faceoff_facts', $gameIds);
        $payload['refs_staff_game_count'] = count($gameIds);
        $payload['refs_staff_processed_game_count'] = self::processedRightRailGameCount($gameIds);
        $payload['refs_staff_assignment_game_count'] = self::refsStaffAssignmentGameCount($gameIds);
        $payload['completed_at'] = now()->toIso8601String();

        $run->forceFill([
            'status' => NhlGameImportRun::STATUS_COMPLETED,
            'payload' => $payload,
            'updated_at' => now(),
        ])->save();

        broadcast(new NhlGameImportStatusUpdated('processing-completed', $runId));
    }

    private static function recordFullPostProcessFailure(int $runId, string $batchId, Throwable $throwable): void
    {
        $run = NhlGameImportRun::query()->find($runId);

        if (! $run) {
            return;
        }

        $payload = $run->payload ?? [];
        $payload['post_process_enrichment_batch_id'] = $batchId;
        $payload['post_process_enrichment_failed_at'] = now()->toIso8601String();
        $payload['post_process_enrichment_last_error'] = mb_substr($throwable->getMessage(), 0, 1000);

        $run->forceFill([
            'status' => NhlGameImportRun::STATUS_FAILED,
            'payload' => $payload,
            'last_error' => $payload['post_process_enrichment_last_error'],
            'updated_at' => now(),
        ])->save();

        Log::error('NHL full post-process enrichment batch failed.', [
            'run_id' => $runId,
            'batch_id' => $batchId,
            'error' => $throwable->getMessage(),
        ]);
        broadcast(new NhlGameImportStatusUpdated('post-process-enrichment-failed', $runId));
    }

    /**
     * @param array<int,int> $gameIds
     */
    private static function processedGameCount(string $table, array $gameIds): int
    {
        return (int) DB::table($table)
            ->whereIn('nhl_game_id', $gameIds)
            ->distinct()
            ->count('nhl_game_id');
    }

    /**
     * @param array<int,int> $gameIds
     */
    private static function processedRightRailGameCount(array $gameIds): int
    {
        return (int) DB::table('nhl_game_source_statuses')
            ->whereIn('nhl_game_id', $gameIds)
            ->where('source', NhlGameSourceStatus::SOURCE_RIGHT_RAIL)
            ->distinct()
            ->count('nhl_game_id');
    }

    /**
     * @param array<int,int> $gameIds
     */
    private static function refsStaffAssignmentGameCount(array $gameIds): int
    {
        $officialGameIds = DB::table('nhl_game_officials')
            ->whereIn('nhl_game_id', $gameIds)
            ->distinct()
            ->pluck('nhl_game_id')
            ->all();
        $staffGameIds = DB::table('nhl_game_team_staff')
            ->whereIn('nhl_game_id', $gameIds)
            ->distinct()
            ->pluck('nhl_game_id')
            ->all();

        return count(array_unique([
            ...array_map('intval', $officialGameIds),
            ...array_map('intval', $staffGameIds),
        ]));
    }
}
