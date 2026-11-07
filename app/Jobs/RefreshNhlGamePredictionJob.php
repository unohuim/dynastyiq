<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\NhlGamePredictionPreparing;
use App\Services\NhlGamePredictionPayload;
use App\Services\NhlGamePredictionResponseCache;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Refresh one stale partner prediction without blocking the requesting client. */
final class RefreshNhlGamePredictionJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 0;
    public int $maxExceptions = 3;
    public ?string $deadline = null;
    public int $timeout = 120;
    public array $backoff = [10, 30, 60];

    /** @param array<string,mixed> $input */
    public function __construct(public int $gameId, public array $input, public string $fingerprint)
    {
        $this->afterCommit = true;
        $this->deadline = now()->addDay()->toIso8601String();
    }

    public function handle(NhlGamePredictionResponseCache $cache, NhlGamePredictionPayload $payload): void
    {
        try {
            $cache->rebuild($this->gameId, $this->input, $this->fingerprint, $payload);
        } catch (NhlGamePredictionPreparing) {
            $this->release(15);
        }
    }

    /** Clear pending admission if the worker exhausts its deadline or fails terminally. */
    public function failed(\Throwable $exception): void
    {
        app(NhlGamePredictionResponseCache::class)->refreshFailed($this->gameId, $this->input);
    }

    /** Dependency waits do not exhaust attempts, but do have a fixed lifetime. */
    public function retryUntil(): \DateTimeInterface
    {
        return new \DateTimeImmutable($this->deadline ?? now()->addDay()->toIso8601String());
    }
}
