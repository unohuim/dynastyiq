<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Events\NhlGameImportStatusUpdated;
use App\Services\NhlImportOrchestrator;
use App\Support\NhlImportStages;
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
 * Claims PBP and starts the canonical NHL game import pipeline after rebuild reseeding.
 */
class DispatchNhlGameImportPipelineJob implements ShouldQueue, ShouldBeUnique
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
            'nhl-game-import-rebuild-dispatch',
            "game-id:{$this->gameId}",
            'run-id:' . ($this->runId ?? 'none'),
        ];
    }

    public function handle(NhlImportOrchestrator $orchestrator): void
    {
        broadcast(new NhlGameImportStatusUpdated('rebuild-dispatch-started', $this->runId, $this->gameId));

        $queued = $orchestrator->dispatchJob($this->gameId, NhlImportStages::PBP, $this->runId);

        if (! $queued) {
            Log::warning('NHL game rebuild did not dispatch the PBP import stage.', [
                'game_id' => $this->gameId,
                'run_id' => $this->runId,
            ]);
            broadcast(new NhlGameImportStatusUpdated('rebuild-dispatch-blocked', $this->runId, $this->gameId));
            Cache::lock($this->activeLockKey())->forceRelease();

            return;
        }

        broadcast(new NhlGameImportStatusUpdated('rebuild-dispatch-completed', $this->runId, $this->gameId, NhlImportStages::PBP));
        Cache::lock($this->activeLockKey())->forceRelease();
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
