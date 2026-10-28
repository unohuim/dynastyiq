<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\BuildNhlShotAttemptFacts;
use App\Services\NhlPbpReconciliation;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Consume the newest durable snapshot without accumulating polling ticks. */
final class ProcessNhlLivePbpJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $timeout = 120;
    public int $tries = 20;
    public int $uniqueFor = 180;
    public int $backoff = 10;

    /** Keep provider payloads out of queued messages. */
    public function __construct(public int $gameId)
    {
        $this->onQueue('nhl-live-stats');
        $this->afterCommit = true;
    }

    /** Coalesce queued and active work by game. */
    public function uniqueId(): string
    {
        return (string) $this->gameId;
    }

    /** Serialize with ordinary imports and derived rebuilds. */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('nhl-game-import:'.$this->gameId))->shared()->releaseAfter(5)->expireAfter(720)];
    }

    /** Expose failures and pending work in Horizon. */
    public function tags(): array
    {
        return ['nhl-live-stats', 'game:'.$this->gameId];
    }

    /** Commit events and derived facts together; a newer pending snapshot remains pending. */
    public function handle(NhlPbpReconciliation $reconciler, BuildNhlShotAttemptFacts $facts): void
    {
        $state = DB::table('nhl_pbp_processing_states')->where('nhl_game_id', $this->gameId)->first();
        if ($state === null || $state->processed_version >= $state->pending_version) {
            return;
        }
        DB::transaction(function () use ($state, $reconciler, $facts): void {
            $payload = json_decode($state->pending_payload, true, 512, JSON_THROW_ON_ERROR);
            $reconciler->reconcile($this->gameId, $payload, (int) $state->pending_version);
            $facts->buildForGame($this->gameId, false);
            $metrics = app(\App\Services\NhlLiveGameStatistics::class)->calculate($this->gameId, $state->model_ids);
            DB::table('nhl_pbp_processing_states')->where('nhl_game_id', $this->gameId)->update([
                'model_ids' => json_encode($metrics['model_ids'], JSON_THROW_ON_ERROR),
                'metrics' => json_encode($metrics, JSON_THROW_ON_ERROR),
                'processed_version' => $state->pending_version, 'processed_at' => now(), 'last_error' => null,
                'updated_at' => now(),
            ]);
        });
        $feed = app(\App\Services\NhlLiveGameFeed::class);
        $snapshot = $feed->snapshot($this->gameId);
        if ($snapshot !== null) {
            \App\Events\NhlLiveGameUpdated::dispatch([...$feed->compact($snapshot), 'changed' => true]);
        }
    }

    /** Preserve a visible error while the durable checkpoint remains recoverable. */
    public function failed(Throwable $exception): void
    {
        DB::table('nhl_pbp_processing_states')->where('nhl_game_id', $this->gameId)
            ->update(['last_error' => mb_substr($exception->getMessage(), 0, 2000)]);
    }
}
