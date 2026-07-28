<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RebuildNhlGameImportJob;
use App\Models\NhlGameImportRun;
use App\Services\NhlDuplicatePlayByPlayRepair;
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

    public function handle(NhlDuplicatePlayByPlayRepair $repair): int
    {
        if (! $repair->repairLedgerExists()) {
            $this->warn('Repair ledger table does not exist yet. Run migrations before queueing repairs.');
            $this->reportLiveDuplicates($repair);

            return self::SUCCESS;
        }

        $this->reportLedger($repair);

        if (! (bool) $this->option('queue')) {
            $this->line('');
            $this->line('Dry run only. Add --queue to dispatch rebuilds for unqueued affected games.');

            return self::SUCCESS;
        }

        $gameIds = $repair->repairGameIds(
            (bool) $this->option('all'),
            $this->limitOption()
        );

        if ($gameIds === []) {
            $this->info('No duplicate-PBP repair games need queueing.');

            return self::SUCCESS;
        }

        $run = $this->createRepairRun($gameIds);

        foreach ($gameIds as $gameId) {
            RebuildNhlGameImportJob::dispatch($gameId, $run->id);
        }

        $repair->markQueued($gameIds);

        $this->info(sprintf('Queued %d NHL game rebuilds for duplicate-PBP repair run %d.', count($gameIds), $run->id));

        return self::SUCCESS;
    }

    /**
     * Report duplicate PBP rows that still exist before the migration runs.
     */
    private function reportLiveDuplicates(NhlDuplicatePlayByPlayRepair $repair): void
    {
        $summary = $repair->scan();

        $this->line(sprintf(
            'Current duplicate PBP rows: %d games, %d duplicate rows.',
            $summary['game_count'],
            $summary['duplicate_rows']
        ));
    }

    /**
     * Report the persisted post-migration repair ledger.
     */
    private function reportLedger(NhlDuplicatePlayByPlayRepair $repair): void
    {
        $summary = $repair->ledgerSummary();

        $this->line(sprintf(
            'Repair ledger: %d affected games, %d duplicate rows deleted, %d unqueued, %d queued.',
            $summary['game_count'],
            $summary['duplicate_rows'],
            $summary['unqueued_games'],
            $summary['queued_games']
        ));
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
            'action' => NhlGameImportRun::ACTION_REPAIR,
            'mode' => NhlGameImportRun::MODE_RANGE,
            'status' => NhlGameImportRun::STATUS_QUEUED,
            'start_date' => (string) ($bounds->latest_date ?? now()->toDateString()),
            'end_date' => (string) ($bounds->earliest_date ?? now()->toDateString()),
            'date_count' => (int) ($bounds->date_count ?? count($gameIds)),
            'queued_jobs' => count($gameIds),
            'payload' => [
                'repair' => 'duplicate_pbp',
                'repair_stage' => 'rebuilding',
                'affected_game_ids' => $gameIds,
                'queued_rebuild_game_count' => count($gameIds),
            ],
        ]);
    }

    private function limitOption(): ?int
    {
        $limit = $this->option('limit');

        if ($limit === null || $limit === '') {
            return null;
        }

        return max(1, (int) $limit);
    }
}
