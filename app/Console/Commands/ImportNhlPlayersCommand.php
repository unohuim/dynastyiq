<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ImportPlayersJob;
use App\Jobs\ImportNhlDraftPicksJob;
use App\Models\NhlTeam;
use App\Models\ImportRun;
use App\Services\ImportNhlTeams;
use Illuminate\Bus\Batch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ImportNhlPlayersCommand extends Command
{
    /**
     * Fallback team abbreviations used only when reference data is unavailable.
     *
     * @var array<int,string>
     */
    private const FALLBACK_TEAM_ABBREVS = [
        'ANA',
        'ARI',
        'BOS',
        'BUF',
        'CAR',
        'CBJ',
        'CGY',
        'CHI',
        'COL',
        'DAL',
        'DET',
        'EDM',
        'FLA',
        'LAK',
        'MIN',
        'MTL',
        'NJD',
        'NSH',
        'NYI',
        'NYR',
        'OTT',
        'PHI',
        'PIT',
        'SEA',
        'SJS',
        'STL',
        'TBL',
        'TOR',
        'UTA',
        'VAN',
        'VGK',
        'WPG',
        'WSH',
    ];

    /**
     * Usage: php artisan nhl:import --players
     */
    protected $signature = 'nhl:import
                            {--players : Import NHL players for all teams}
                            {--import-run-id= : Internal admin import run id}';

    protected $description = 'Import NHL data';

    public function handle(ImportNhlTeams $teamsImport): int
    {
        if (! $this->option('players')) {
            $this->error('Nothing to do. Try: nhl:import --players');
            return Command::FAILURE;
        }

        $teamsImport->sync();
        $importRunId = (string) Str::uuid();
        $adminImportRunId = $this->option('import-run-id')
            ? (int) $this->option('import-run-id')
            : null;

        $jobs = array_map(
            fn (string $abbrev): ImportPlayersJob => new ImportPlayersJob($abbrev, $importRunId, $adminImportRunId),
            $this->teamAbbrevs(),
        );

        $batch = Bus::batch($jobs)
            ->then(function (Batch $batch) use ($importRunId, $adminImportRunId): void {
                ImportNhlDraftPicksJob::dispatch($importRunId, $adminImportRunId);
            })
            ->catch(function (Batch $batch, Throwable $e) use ($adminImportRunId): void {
                if ($adminImportRunId !== null) {
                    ImportRun::query()->find($adminImportRunId)?->markFailed($e);
                }

                Log::error('NHL player discovery batch failed before draft discovery', [
                    'batchId' => $batch->id,
                    'error' => $e->getMessage(),
                ]);
            })
            ->name('NHLImport:PlayersThenDraftPicks')
            ->dispatch();

        if ($adminImportRunId !== null) {
            ImportRun::query()
                ->find($adminImportRunId)
                ?->setProgressTotal(null, 'NHL player records');
            $this->markDynamicTotal($adminImportRunId);
            $this->recordWorkBatch($adminImportRunId, $batch->id);
        }

        $this->info('Dispatched NHL player discovery batch.');
        return Command::SUCCESS;
    }

    /**
     * @return array<int,string>
     */
    private function teamAbbrevs(): array
    {
        $abbrevs = NhlTeam::query()
            ->orderBy('abbrev')
            ->pluck('abbrev')
            ->filter()
            ->values()
            ->all();

        return $abbrevs === [] ? self::FALLBACK_TEAM_ABBREVS : $abbrevs;
    }

    private function recordWorkBatch(int $importRunId, string $batchId): void
    {
        $importRun = ImportRun::query()->find($importRunId);

        if ($importRun === null) {
            return;
        }

        $meta = $importRun->meta ?? [];
        $meta['work_batch_id'] = $batchId;

        $importRun->update(['meta' => $meta]);
    }

    private function markDynamicTotal(int $importRunId): void
    {
        $importRun = ImportRun::query()->find($importRunId);

        if ($importRun === null) {
            return;
        }

        $meta = $importRun->meta ?? [];
        $meta['dynamic_total'] = true;

        $importRun->update(['meta' => $meta]);
    }
}
