<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\NhlGameImportEligibility;
use App\Services\NhlImportOrchestrator;
use App\Services\NhlValidationTroubleshootingExporter;
use App\Support\NhlImportStages;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Abstract base for NHL pipeline jobs.
 */
abstract class BaseNhlJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var int */
    public int $gameId;

    /** @var int */
    public int $tries = 5;

    /** @var array<int,int> */
    public array $backoff = [60, 120, 300, 600];

    /** @var int */
    public int $timeout = 600;

    public function __construct(int $gameId)
    {
        $this->gameId = $gameId;
    }

    /**
     * Return the canonical stage key.
     */
    abstract protected function stageName(): string;

    /**
     * Execute work for this stage and return processed item count.
     */
    abstract protected function perform(int $gameId): int;

    /**
     * Template handle: verify claimed work, perform it, then report.
     */
    public function handle(
        NhlImportOrchestrator $orchestrator,
        NhlGameImportEligibility $eligibility
    ): void
    {
        if (! $orchestrator->isRunning($this->gameId, $this->stageName())) {
            return;
        }

        try {
            if (
                $this->stageName() !== NhlImportStages::PBP
                && ! $eligibility->allowsStoredGame($this->gameId)
            ) {
                throw new \DomainException(sprintf(
                    'NHL game %d cannot run %s before PBP stores an allowed game type (%s).',
                    $this->gameId,
                    $this->stageName(),
                    $eligibility->allowedGameTypeList()
                ));
            }

            $count = $this->perform($this->gameId);

            $orchestrator->onSuccess($this->gameId, $this->stageName(), [
                'items_count' => $count,
            ]);

            return;
        } catch (Throwable $e) {
            $this->exportTroubleshootingPayloads($e);

            app(NhlImportOrchestrator::class)->onFailure(
                $this->gameId,
                $this->stageName(),
                $e->getMessage(),
                $e->getCode()
            );

            $this->fail($e);
        }
    }

    /**
     * Export raw provider payloads for any stage stoppage without masking the original failure.
     */
    private function exportTroubleshootingPayloads(Throwable $throwable): void
    {
        try {
            app(NhlValidationTroubleshootingExporter::class)->exportRawProviderPayloads($this->gameId, [
                'stage' => $this->stageName(),
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
                'code' => $throwable->getCode(),
            ]);
        } catch (Throwable $exportThrowable) {
            Log::warning('Failed to export NHL import stoppage troubleshooting payloads.', [
                'game_id' => $this->gameId,
                'stage' => $this->stageName(),
                'message' => $exportThrowable->getMessage(),
            ]);
        }
    }

    /**
     * Queue/Horizon tags.
     *
     * @return array<int,string>
     */
    public function tags(): array
    {
        return [
            'nhl-import-' . $this->stageName(),
            "game-id:{$this->gameId}",
        ];
    }
}
