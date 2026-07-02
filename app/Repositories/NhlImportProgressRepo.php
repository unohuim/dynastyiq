<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Events\NhlGameImportStatusUpdated;
use Illuminate\Support\Facades\DB;

class NhlImportProgressRepo
{
    /** Insert-if-missing tracker rows in chunks (idempotent). */
    public function insertScheduledRows(array $rows, int $chunk = 1000): void
    {
        $inserted = 0;

        foreach (array_chunk($rows, $chunk) as $part) {
            $inserted += DB::table('nhl_import_progress')->insertOrIgnore($part);
            $this->attachRunToLegacyRows($part);
        }

        if ($inserted > 0) {
            broadcast(new NhlGameImportStatusUpdated('progress-seeded'));
        }
    }

    /** Atomically claim a scheduled row (scheduled → running). */
    public function claim(int $gameId, string $type, ?int $runId = null): bool
    {
        $claimed = DB::transaction(function () use ($gameId, $type, $runId) {
            $query = DB::table('nhl_import_progress')
                ->where('game_id', $gameId)
                ->where('import_type', $type)
                ->where('status', 'scheduled');

            if ($runId !== null) {
                $query->where('run_id', $runId);
            }

            $updated = $query->update([
                'status' => 'running',
                'updated_at' => now(),
            ]);

            return $updated === 1;
        }, 1);

        if ($claimed) {
            broadcast(new NhlGameImportStatusUpdated('stage-running', gameId: $gameId, stage: $type));
        }

        return $claimed;
    }

    /** Verify row is currently running. */
    public function isRunning(int $gameId, string $type, ?int $runId = null): bool
    {
        $query = DB::table('nhl_import_progress')
            ->where('game_id', $gameId)
            ->where('import_type', $type)
            ->where('status', 'running');

        if ($runId !== null) {
            $query->where('run_id', $runId);
        }

        return $query->exists();
    }

    /** Determine whether any stage is running for a scheduled date/run scope. */
    public function runningExistsForDate(string $gameDate, ?int $runId = null): bool
    {
        $query = DB::table('nhl_import_progress')
            ->whereDate('game_date', $gameDate)
            ->where('status', 'running');

        if ($runId !== null) {
            $query->where('run_id', $runId);
        }

        return $query->exists();
    }

    /**
     * Return game ids that still have scheduled work for a date/run scope.
     *
     * @return array<int,int>
     */
    public function scheduledGameIdsForDate(string $gameDate, ?int $runId = null): array
    {
        $query = DB::table('nhl_import_progress')
            ->whereDate('game_date', $gameDate)
            ->where('status', 'scheduled')
            ->select('game_id')
            ->distinct()
            ->orderBy('game_id');

        if ($runId !== null) {
            $query->where('run_id', $runId);
        }

        return $query->pluck('game_id')
            ->map(fn ($gameId): int => (int) $gameId)
            ->all();
    }

    /** Count distinct games with a running stage for a run. */
    public function activeGameCountForRun(int $runId): int
    {
        return (int) DB::table('nhl_import_progress')
            ->where('run_id', $runId)
            ->where('status', 'running')
            ->distinct()
            ->count('game_id');
    }

    /**
     * Return scheduled game ids without a currently running stage for a run.
     *
     * @return array<int,int>
     */
    public function scheduledGameIdsForRun(int $runId): array
    {
        return DB::table('nhl_import_progress as scheduled')
            ->where('scheduled.run_id', $runId)
            ->where('scheduled.status', 'scheduled')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('nhl_import_progress as running')
                    ->whereColumn('running.run_id', 'scheduled.run_id')
                    ->whereColumn('running.game_id', 'scheduled.game_id')
                    ->where('running.status', 'running');
            })
            ->selectRaw('scheduled.game_id, MIN(scheduled.game_date) as first_game_date')
            ->groupBy('scheduled.game_id')
            ->orderBy('first_game_date')
            ->orderBy('scheduled.game_id')
            ->pluck('scheduled.game_id')
            ->map(fn ($gameId): int => (int) $gameId)
            ->all();
    }

    /** Determine whether a run still has scheduled or running work. */
    public function hasOpenRowsForRun(int $runId): bool
    {
        return DB::table('nhl_import_progress')
            ->where('run_id', $runId)
            ->whereIn('status', ['scheduled', 'running'])
            ->exists();
    }

    /** Mark success (completed) with items_count. */
    public function markCompleted(int $gameId, string $type, int $itemsCount): void
    {
        $updated = DB::table('nhl_import_progress')
            ->where('game_id', $gameId)
            ->where('import_type', $type)
            ->update([
                'items_count' => $itemsCount,
                'status'      => 'completed',
                'last_error'  => null,
                'updated_at'  => now(),
            ]);

        if ($updated > 0) {
            broadcast(new NhlGameImportStatusUpdated('stage-completed', gameId: $gameId, stage: $type));
        }
    }

    /** Requeue an existing progress row for an explicit admin rerun. */
    public function reschedule(int $gameId, string $type): void
    {
        $updated = DB::table('nhl_import_progress')
            ->where('game_id', $gameId)
            ->where('import_type', $type)
            ->update([
                'items_count' => 0,
                'status' => 'scheduled',
                'last_error' => null,
                'updated_at' => now(),
            ]);

        if ($updated > 0) {
            broadcast(new NhlGameImportStatusUpdated('stage-rescheduled', gameId: $gameId, stage: $type));
        }
    }

    /** Mark failure (error) with message/code. */
    public function markError(int $gameId, string $type, string $message, $code = null): void
    {
        $msg = trim(($code !== null ? '[' . (string)$code . '] ' : '') . $message);
        $updated = DB::table('nhl_import_progress')
            ->where('game_id', $gameId)
            ->where('import_type', $type)
            ->update([
                'status'     => 'error',
                'last_error' => mb_substr($msg, 0, 1000),
                'updated_at' => now(),
            ]);

        if ($updated > 0) {
            broadcast(new NhlGameImportStatusUpdated('stage-error', gameId: $gameId, stage: $type));
        }
    }

    /** Mark all not-yet-completed rows for a game as failed with the same message. */
    public function markGameError(int $gameId, string $message, $code = null): void
    {
        $msg = trim(($code !== null ? '[' . (string) $code . '] ' : '') . $message);
        $updated = DB::table('nhl_import_progress')
            ->where('game_id', $gameId)
            ->whereIn('status', ['scheduled', 'running'])
            ->update([
                'status' => 'error',
                'last_error' => mb_substr($msg, 0, 1000),
                'updated_at' => now(),
            ]);

        if ($updated > 0) {
            broadcast(new NhlGameImportStatusUpdated('game-error', gameId: $gameId));
        }
    }

    /**
     * Mark scheduled/running source-incomplete stages as skipped without flagging the game as failed.
     *
     * @param array<int,string> $types
     */
    public function markSkipped(int $gameId, array $types, string $message): void
    {
        if ($types === []) {
            return;
        }

        $updated = DB::table('nhl_import_progress')
            ->where('game_id', $gameId)
            ->whereIn('import_type', $types)
            ->whereIn('status', ['scheduled', 'running'])
            ->update([
                'status' => 'skipped',
                'last_error' => mb_substr($message, 0, 1000),
                'updated_at' => now(),
            ]);

        if ($updated > 0) {
            broadcast(new NhlGameImportStatusUpdated('stage-skipped', gameId: $gameId));
        }
    }

    /** Does a scheduled row exist for this (gameId,type)? */
    public function scheduledExists(int $gameId, string $type, ?int $runId = null): bool
    {
        $query = DB::table('nhl_import_progress')
            ->where('game_id', $gameId)
            ->where('import_type', $type)
            ->where('status', 'scheduled');

        if ($runId !== null) {
            $query->where('run_id', $runId);
        }

        return $query->exists();
    }

    /** How many dependencies are completed for this game? */
    public function completedDepsCount(int $gameId, array $deps, ?int $runId = null): int
    {
        if (empty($deps)) {
            return 0;
        }

        $query = DB::table('nhl_import_progress')
            ->where('game_id', $gameId)
            ->whereIn('import_type', $deps)
            ->whereIn('status', ['completed', 'skipped']);

        if ($runId !== null) {
            $query->where('run_id', $runId);
        }

        return (int) $query->count();
    }

    /** Mark stale running rows as error for a given type before cutoff. */
    public function markStaleRunningToError(string $type, \DateTimeInterface $cutoff): void
    {
        $updated = DB::table('nhl_import_progress')
            ->where('import_type', $type)
            ->where('status', 'running')
            ->where('updated_at', '<', $cutoff)
            ->update([
                'status'     => 'error',
                'last_error' => 'stale',
                'updated_at' => now(),
            ]);

        if ($updated > 0) {
            broadcast(new NhlGameImportStatusUpdated('stale-stage-error', stage: $type));
        }
    }

    /**
     * Attach old null-run rows to a new run when discovery encounters existing game/stage rows.
     *
     * @param array<int,array<string,mixed>> $rows
     */
    private function attachRunToLegacyRows(array $rows): void
    {
        $runId = collect($rows)->pluck('run_id')->filter()->first();

        if (! $runId) {
            return;
        }

        foreach ($rows as $row) {
            DB::table('nhl_import_progress')
                ->whereNull('run_id')
                ->where('game_id', $row['game_id'])
                ->where('import_type', $row['import_type'])
                ->update([
                    'run_id' => $runId,
                    'updated_at' => now(),
                ]);
        }
    }
}
