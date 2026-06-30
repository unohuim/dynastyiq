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
        }

        if ($inserted > 0) {
            broadcast(new NhlGameImportStatusUpdated('progress-seeded'));
        }
    }

    /** Atomically claim a scheduled row (scheduled → running). */
    public function claim(int $gameId, string $type): bool
    {
        $claimed = DB::transaction(function () use ($gameId, $type) {
            $updated = DB::table('nhl_import_progress')
                ->where('game_id', $gameId)
                ->where('import_type', $type)
                ->where('status', 'scheduled')
                ->update([
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
    public function isRunning(int $gameId, string $type): bool
    {
        return DB::table('nhl_import_progress')
            ->where('game_id', $gameId)
            ->where('import_type', $type)
            ->where('status', 'running')
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

    /** Does a scheduled row exist for this (gameId,type)? */
    public function scheduledExists(int $gameId, string $type): bool
    {
        return DB::table('nhl_import_progress')
            ->where('game_id', $gameId)
            ->where('import_type', $type)
            ->where('status', 'scheduled')
            ->exists();
    }

    /** How many dependencies are completed for this game? */
    public function completedDepsCount(int $gameId, array $deps): int
    {
        if (empty($deps)) {
            return 0;
        }

        return (int) DB::table('nhl_import_progress')
            ->where('game_id', $gameId)
            ->whereIn('import_type', $deps)
            ->where('status', 'completed')
            ->count();
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
}
