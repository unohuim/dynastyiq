<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Events\NhlGameImportStatusUpdated;
use App\Models\NhlGameImportRun;
use App\Services\NhlGameContextImporter;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Refreshes one game's right-rail officials and staff context.
 */
class RefreshNhlGameContextJob implements ShouldQueue
{
    use Batchable;
    use Queueable;
    use InteractsWithQueue;
    use SerializesModels;

    /**
     * @var int
     */
    public int $tries = 3;

    /**
     * @var array<int,int>
     */
    public array $backoff = [30, 120, 300];

    /**
     * @var int
     */
    public int $timeout = 120;

    public function __construct(public int $nhlGameId, public ?int $runId = null)
    {
        $this->afterCommit = true;
    }

    /**
     * Prevent duplicate context refreshes for the same NHL game.
     *
     * @return array<int,object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('nhl-game-context:' . $this->nhlGameId))
                ->expireAfter(300),
        ];
    }

    /**
     * @return array<int,string>
     */
    public function tags(): array
    {
        return array_filter([
            'nhl-game-context',
            'game:' . $this->nhlGameId,
            $this->runId ? 'run:' . $this->runId : null,
        ]);
    }

    public function handle(NhlGameContextImporter $importer): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $importer->import($this->nhlGameId);
    }

    public function failed(Throwable $exception): void
    {
        $message = mb_substr($exception->getMessage(), 0, 1000);

        if ($this->runId) {
            $run = NhlGameImportRun::query()->find($this->runId);

            if ($run) {
                $payload = $run->payload ?? [];
                $failedGameIds = array_values(array_unique([
                    ...array_map('intval', $payload['refs_staff_failed_game_ids'] ?? []),
                    $this->nhlGameId,
                ]));

                $payload['refs_staff_failed_at'] = now()->toIso8601String();
                $payload['refs_staff_last_error'] = $message;
                $payload['refs_staff_failed_game_ids'] = $failedGameIds;

                $run->forceFill([
                    'status' => NhlGameImportRun::STATUS_FAILED,
                    'payload' => $payload,
                    'last_error' => $message,
                    'updated_at' => now(),
                ])->save();

                broadcast(new NhlGameImportStatusUpdated('refs-staff-job-failed', $run->id, $this->nhlGameId));
            }
        }

        Log::error('NHL refs and staff job failed.', [
            'game_id' => $this->nhlGameId,
            'run_id' => $this->runId,
            'error' => $message,
        ]);
    }
}
