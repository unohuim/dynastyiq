<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\NhlGamePredictionPayload;
use App\Services\NhlGamePredictionResponseCache;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Refresh one stale partner prediction without blocking the requesting client. */
final class RefreshNhlGamePredictionJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 120;

    /** @param array<string,mixed> $input */
    public function __construct(public int $gameId, public array $input, public string $fingerprint)
    {
        $this->afterCommit = true;
    }

    public function handle(NhlGamePredictionResponseCache $cache, NhlGamePredictionPayload $payload): void
    {
        $cache->rebuild($this->gameId, $this->input, $this->fingerprint, $payload);
    }
}
