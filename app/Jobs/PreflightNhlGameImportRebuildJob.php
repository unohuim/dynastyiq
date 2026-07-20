<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Events\NhlGameImportStatusUpdated;
use App\Services\NhlGameImportRebuilder;
use App\Services\NhlGameSourcePreflight;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Checks source availability and gathers context before destructive game rebuild work starts.
 */
class PreflightNhlGameImportRebuildJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 900;

    public function __construct(
        public int $gameId,
        public ?int $runId = null
    ) {
        $this->afterCommit = true;
    }

    public function uniqueId(): string
    {
        return (string) $this->gameId;
    }

    /**
     * @return array<int,string>
     */
    public function tags(): array
    {
        return [
            'nhl-game-import-rebuild-preflight',
            "game-id:{$this->gameId}",
            'run-id:' . ($this->runId ?? 'none'),
        ];
    }

    public function handle(
        NhlGameSourcePreflight $preflight,
        NhlGameImportRebuilder $rebuilder
    ): void {
        $lock = Cache::lock($this->activeLockKey(), 900);

        if (! $lock->get()) {
            Log::info('NHL game rebuild skipped because another rebuild is already active.', [
                'game_id' => $this->gameId,
                'run_id' => $this->runId,
            ]);
            broadcast(new NhlGameImportStatusUpdated('rebuild-already-active', $this->runId, $this->gameId));

            return;
        }

        broadcast(new NhlGameImportStatusUpdated('rebuild-preflight-started', $this->runId, $this->gameId));

        $context = $rebuilder->gameContext($this->gameId);
        $result = $preflight->check($this->gameId);

        if (! $result['core_allowed']) {
            Log::warning('NHL game rebuild skipped because core source preflight blocked it.', [
                'game_id' => $this->gameId,
                'run_id' => $this->runId,
                'message' => $result['core_message'] ?? $result['message'] ?? null,
                'statuses' => $result['statuses'],
            ]);
            broadcast(new NhlGameImportStatusUpdated('rebuild-preflight-blocked', $this->runId, $this->gameId));
            Cache::lock($this->activeLockKey())->forceRelease();

            return;
        }

        ClearNhlGameImportJob::dispatch($this->gameId, $this->runId, $context);
        broadcast(new NhlGameImportStatusUpdated('rebuild-preflight-completed', $this->runId, $this->gameId));
    }

    public function failed(Throwable $exception): void
    {
        Cache::lock($this->activeLockKey())->forceRelease();
    }

    private function activeLockKey(): string
    {
        return 'nhl-game-import-rebuild-active:' . $this->gameId;
    }
}
