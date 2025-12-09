<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\PlatformState;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ValidateInitializationJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
    }

    public function handle(PlatformState $platformState): void
    {
        if (! $platformState->initialized()) {
            throw new \RuntimeException('Initialization validation failed.');
        }
    }
}
