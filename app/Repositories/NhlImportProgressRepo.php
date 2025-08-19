<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Support\Facades\DB;

class NhlImportProgressRepo
{
    /** Insert-if-missing tracker rows in chunks (idempotent). */
    public function insertScheduledRows(array $rows, int $chunk = 1000): void
    {
        foreach (array_chunk($rows, $chunk) as $part) {
            DB::table('nhl_import_progress')->insertOrIgnore($part);
        }
    }

    /** Atomically claim a scheduled row (scheduled → running). */
    public function claim(int $gameId, string $type): bool
    {
        return DB::transaction(function () use ($gameId, $type) {
            $row = DB::table('nhl_import_progress')
                ->where('game_id', $gameId)
                ->where('import_type', $type)
                ->where('status', 'scheduled')
                ->lockForUpdate()
                ->first();

            if (!$row) {
                return false;
            }
            

            return true;
        }, 1);
    }


    public function markRunning(int $gameId, string $type): bool {
      return DB::table('nhl_import_progress')
        ->where('game_id',$gameId)->where('import_type',$type)
        ->where('status','scheduled')
        ->update(['status'=>'running','updated_at'=>now()]) === 1;
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
        DB::table('nhl_import_progress')
            ->where('game_id', $gameId)
            ->where('import_type', $type)
            ->update([
                'items_count' => $itemsCount,
                'status'      => 'completed',
                'last_error'  => null,
                'updated_at'  => now(),
            ]);
    }

    /** Mark failure (error) with message/code. */
    public function markError(int $gameId, string $type, string $message, $code = null): void
    {
        $msg = trim(($code !== null ? '[' . (string)$code . '] ' : '') . $message);
        DB::table('nhl_import_progress')
            ->where('game_id', $gameId)
            ->where('import_type', $type)
            ->update([
                'status'     => 'error',
                'last_error' => mb_substr($msg, 0, 1000),
                'updated_at' => now(),
            ]);
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
        DB::table('nhl_import_progress')
            ->where('import_type', $type)
            ->where('status', 'running')
            ->where('updated_at', '<', $cutoff)
            ->update([
                'status'     => 'error',
                'last_error' => 'stale',
                'updated_at' => now(),
            ]);
    }
}
