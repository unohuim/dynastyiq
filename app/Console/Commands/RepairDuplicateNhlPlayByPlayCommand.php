<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RebuildNhlGameImportJob;
use App\Models\NhlGameImportRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Report and rebuild NHL games affected by duplicate play-by-play event rows.
 */
class RepairDuplicateNhlPlayByPlayCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'nhl:repair-duplicate-pbp
                            {--queue : Queue full game rebuilds for affected games recorded by the dedupe migration.}
                            {--limit= : Maximum number of affected games to queue.}
                            {--all : Include games already marked as queued in the repair ledger.}';

    /**
     * @var string
     */
    protected $description = 'Report or rebuild NHL games affected by duplicate play-by-play rows';

    public function handle(): int
    {
        if (! $this->repairLedgerExists()) {
            $this->warn('Repair ledger table does not exist yet. Run migrations before queueing repairs.');
            $this->reportLiveDuplicates();

            return self::SUCCESS;
        }

        $this->reportLedger();

        if (! (bool) $this->option('queue')) {
            $this->line('');
            $this->line('Dry run only. Add --queue to dispatch rebuilds for unqueued affected games.');

            return self::SUCCESS;
        }

        $gameIds = $this->repairGameIds();

        if ($gameIds === []) {
            $this->info('No duplicate-PBP repair games need queueing.');

            return self::SUCCESS;
        }

        $run = $this->createRepairRun($gameIds);

        foreach ($gameIds as $gameId) {
            RebuildNhlGameImportJob::dispatch($gameId, $run->id);
        }

        DB::table('nhl_play_by_play_dedupe_repairs')
            ->whereIn('nhl_game_id', $gameIds)
            ->update([
                'rebuild_queued_at' => now(),
                'updated_at' => now(),
            ]);

        $this->info(sprintf('Queued %d NHL game rebuilds for duplicate-PBP repair run %d.', count($gameIds), $run->id));

        return self::SUCCESS;
    }

    /**
     * Report duplicate PBP rows that still exist before the migration runs.
     */
    private function reportLiveDuplicates(): void
    {
        $summary = DB::query()
            ->fromSub($this->duplicateGameQuery(), 'duplicate_games')
            ->selectRaw('COUNT(*) as game_count, COALESCE(SUM(duplicate_rows), 0) as duplicate_rows')
            ->first();

        $this->line(sprintf(
            'Current duplicate PBP rows: %d games, %d duplicate rows.',
            (int) ($summary->game_count ?? 0),
            (int) ($summary->duplicate_rows ?? 0)
        ));
    }

    /**
     * Report the persisted post-migration repair ledger.
     */
    private function reportLedger(): void
    {
        $summary = DB::table('nhl_play_by_play_dedupe_repairs')
            ->selectRaw(
                'COUNT(*) as game_count,
                 COALESCE(SUM(duplicate_rows_deleted), 0) as duplicate_rows,
                 COUNT(*) FILTER (WHERE rebuild_queued_at IS NULL) as unqueued_games,
                 COUNT(*) FILTER (WHERE rebuild_queued_at IS NOT NULL) as queued_games'
            )
            ->first();

        $this->line(sprintf(
            'Repair ledger: %d affected games, %d duplicate rows deleted, %d unqueued, %d queued.',
            (int) ($summary->game_count ?? 0),
            (int) ($summary->duplicate_rows ?? 0),
            (int) ($summary->unqueued_games ?? 0),
            (int) ($summary->queued_games ?? 0)
        ));
    }

    /**
     * Return duplicate-PBP affected game ids eligible for rebuild.
     *
     * @return array<int,int>
     */
    private function repairGameIds(): array
    {
        $query = DB::table('nhl_play_by_play_dedupe_repairs')
            ->orderBy('nhl_game_id');

        if (! (bool) $this->option('all')) {
            $query->whereNull('rebuild_queued_at');
        }

        $limit = $this->option('limit');

        if ($limit !== null && $limit !== '') {
            $query->limit(max(1, (int) $limit));
        }

        return $query
            ->pluck('nhl_game_id')
            ->map(fn (mixed $gameId): int => (int) $gameId)
            ->all();
    }

    /**
     * Create a visible Game Imports repair run for queued rebuild jobs.
     *
     * @param array<int,int> $gameIds
     */
    private function createRepairRun(array $gameIds): NhlGameImportRun
    {
        $bounds = DB::table('nhl_games')
            ->whereIn('nhl_game_id', $gameIds)
            ->selectRaw('MIN(game_date) as earliest_date, MAX(game_date) as latest_date, COUNT(DISTINCT game_date) as date_count')
            ->first();

        return NhlGameImportRun::query()->create([
            'action' => NhlGameImportRun::ACTION_PROCESS,
            'mode' => NhlGameImportRun::MODE_RANGE,
            'status' => NhlGameImportRun::STATUS_QUEUED,
            'start_date' => (string) ($bounds->latest_date ?? now()->toDateString()),
            'end_date' => (string) ($bounds->earliest_date ?? now()->toDateString()),
            'date_count' => (int) ($bounds->date_count ?? count($gameIds)),
            'queued_jobs' => count($gameIds),
            'payload' => [
                'repair' => 'duplicate_pbp',
                'affected_game_ids' => $gameIds,
            ],
        ]);
    }

    /**
     * Build the live duplicate-game query used before migration repair.
     */
    private function duplicateGameQuery()
    {
        return DB::table('play_by_plays')
            ->selectRaw('nhl_game_id, COUNT(*) - COUNT(DISTINCT nhl_event_id) as duplicate_rows')
            ->whereNotNull('nhl_event_id')
            ->groupBy('nhl_game_id')
            ->havingRaw('COUNT(*) > COUNT(DISTINCT nhl_event_id)');
    }

    /**
     * Determine whether the migration-created repair ledger exists.
     */
    private function repairLedgerExists(): bool
    {
        return DB::table('information_schema.tables')
            ->where('table_schema', 'public')
            ->where('table_name', 'nhl_play_by_play_dedupe_repairs')
            ->exists();
    }
}
