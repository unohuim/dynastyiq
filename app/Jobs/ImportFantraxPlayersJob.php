<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\AdminImports;
use App\Models\ImportRun;
use App\Services\ImportFantraxPlayers;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ImportFantraxPlayersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, Batchable;

    public const TAG_IMPORT = 'import-fantax-players';

    /**
     * Create a new job instance.
     */
    public function __construct(
        private ?int $importRunId = null,
    ) {
        $this->onQueue('fantrax');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if ($this->importRunId !== null) {
            app(ImportFantraxPlayers::class)->prepare($this->importRunId);
            return;
        }

        app(AdminImports::class)->dispatch('fantrax');
    }

    public function failed(Throwable $throwable): void
    {
        $this->importRun()?->markFailed($throwable);
    }

    public function tags(): array
    {
        return [self::TAG_IMPORT];
    }

    private function importRun(): ?ImportRun
    {
        if ($this->importRunId === null) {
            return null;
        }

        return ImportRun::query()->find($this->importRunId);
    }
}
