<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Events\ImportStreamEvent;
use App\Jobs\RefreshNhlPlayerMetadataChunkJob;
use App\Models\ImportRun;
use App\Models\Player;
use Illuminate\Bus\Batch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Throwable;

/**
 * Refreshes canonical NHL player metadata from player landing payloads.
 */
class RefreshNhlPlayerMetadataCommand extends Command
{
    private const CHUNK_SIZE = 50;

    protected $signature = 'nhl:refresh-player-metadata
                            {--import-run-id= : Internal admin import run id}';

    protected $description = 'Refresh existing local NHL player metadata from NHL player landing payloads';

    public function handle(): int
    {
        $importRun = $this->importRun();
        $playerIds = Player::query()
            ->whereNotNull('nhl_id')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $total = count($playerIds);

        $importRun?->setProgressTotal($total, 'NHL player metadata records');

        if ($total === 0) {
            $importRun?->markCompleted();
            $this->info('No local NHL player records found.');

            return Command::SUCCESS;
        }

        $jobs = collect($playerIds)
            ->chunk(self::CHUNK_SIZE)
            ->map(fn ($chunk): RefreshNhlPlayerMetadataChunkJob => new RefreshNhlPlayerMetadataChunkJob(
                $chunk->values()->all(),
                $importRun?->id,
            ))
            ->all();

        $importRunId = $importRun?->id;

        $batch = Bus::batch($jobs)
            ->then(function (Batch $batch) use ($importRunId): void {
                ImportRun::query()->find($importRunId)?->markCompleted();
                ImportStreamEvent::dispatch('nhl', 'NHL player metadata refresh completed', 'finished');
            })
            ->catch(function (Batch $batch, Throwable $throwable) use ($importRunId): void {
                ImportRun::query()->find($importRunId)?->markFailed($throwable);
                ImportStreamEvent::dispatch('nhl', $throwable->getMessage(), 'failed');
            })
            ->name('NHLImport:RefreshPlayerMetadata')
            ->onQueue('default')
            ->dispatch();

        $this->recordMetadataBatch($importRun, $batch->id);
        $this->info("Queued {$batch->totalJobs} NHL player metadata refresh jobs for {$total} local players.");

        return Command::SUCCESS;
    }

    private function importRun(): ?ImportRun
    {
        $importRunId = $this->option('import-run-id');

        if ($importRunId === null || $importRunId === '') {
            return null;
        }

        return ImportRun::query()->find((int) $importRunId);
    }

    private function recordMetadataBatch(?ImportRun $importRun, string $batchId): void
    {
        if ($importRun === null) {
            return;
        }

        $meta = $importRun->meta ?? [];
        $meta['metadata_batch_id'] = $batchId;

        $importRun->update(['meta' => $meta]);
    }
}
