<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\NhlGamePredictionHistory;
use App\Services\NhlGamePredictionPayload;
use App\Services\NhlGamePredictionResponseCache;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Compatibility entry point: background refreshes use the same tracked per-game worker as admin runs. */
final class RefreshNhlGamePredictionJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 0;
    public int $maxExceptions = 3;
    public ?string $deadline = null;
    public int $timeout = 120;
    public bool $failOnTimeout = true;
    public array $backoff = [10, 30, 60];
    public ?int $itemId = null;
    public int $generation = 1;

    /** Older serialized refresh deliveries are admitted once through the durable tracker. */
    public function __construct(public int $gameId, public array $input, public string $fingerprint, ?int $itemId = null, int $generation = 1)
    {
        $this->itemId = $itemId;
        $this->generation = $generation;
        $this->onQueue('projections');
        $this->afterCommit = true;
        $this->deadline = now()->addDay()->toIso8601String();
    }

    /** Delegate queue release/attempt handling to the existing run worker, retaining one durable item. */
    public function handle(NhlGamePredictionResponseCache $cache, NhlGamePredictionPayload $payload): void
    {
        $history = app(NhlGamePredictionHistory::class);
        if ($this->itemId === null) {
            $createdAt = new \DateTimeImmutable($this->deadline ?? now()->addDay()->toIso8601String());
            $newer = \Illuminate\Support\Facades\DB::table('nhl_game_prediction_run_games')
                ->where('nhl_game_id', $this->gameId)->where('created_at', '>=', $createdAt->modify('-1 day'))->exists();
            if (! $newer) {
                $history->queueRefresh($this->gameId, $this->input);
            }
            $cache->refreshFailed($this->gameId, $this->input);
            return;
        }
        $worker = new RunNhlGamePredictionJob($this->itemId, $this->generation);
        if ($this->job !== null) {
            $worker->setJob($this->job);
        }
        $worker->handle($payload, $cache, $history);
    }

    /** Only the matching generation may terminate its tracked attempt. */
    public function failed(\Throwable $exception): void
    {
        if ($this->itemId !== null) {
            (new RunNhlGamePredictionJob($this->itemId, $this->generation))->failed($exception);
        }
        app(NhlGamePredictionResponseCache::class)->refreshFailed($this->gameId, $this->input);
    }

    /** Waiting remains bounded independently of successful checkpoint deliveries. */
    public function retryUntil(): \DateTimeInterface
    {
        return new \DateTimeImmutable($this->deadline ?? now()->addDay()->toIso8601String());
    }
}
