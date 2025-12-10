<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ImportRun;
use App\Support\ImportBroadcast;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Throwable;

class RunImportCommandJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var array<string, mixed> */
    public array $options;

    public function __construct(
        public string $command,
        array $options = [],
        public string $source = ''
    ) {
        $this->options = $options;
    }

    public function handle(): void
    {
        $broadcast = new ImportBroadcast($this->source ?: $this->command, $this->batchId);

        try {
            $broadcast->started();
            Artisan::call($this->command, $this->options, $broadcast->output());

            ImportRun::create([
                'source' => $this->source ?: $this->command,
                'ran_at' => now(),
                'batch_id' => $this->batchId,
            ]);

            $broadcast->finished();
        } catch (Throwable $throwable) {
            $broadcast->failed($throwable);
            throw $throwable;
        }
    }
}
