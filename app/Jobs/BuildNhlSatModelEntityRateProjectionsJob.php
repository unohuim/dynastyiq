<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Events\NhlSatModelUpdated;
use App\Models\NhlModelRun;
use App\Services\NhlSatModelEntityRateProjectionBuilder;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Queues per-entity SAT model /60 projection builds.
 */
class BuildNhlSatModelEntityRateProjectionsJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;
    use InteractsWithQueue;
    use SerializesModels;

    /**
     * @var int
     */
    public int $tries = 1;

    /**
     * @var int
     */
    public int $timeout = 1800;

    /**
     * @var int
     */
    public int $uniqueFor = 21600;

    public function __construct(public int $modelRunId)
    {
        $this->afterCommit = true;
    }

    /**
     * Prevent duplicate /60 builds for the same model run.
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->uniqueId()))
                ->expireAfter($this->timeout + 300),
        ];
    }

    public function uniqueId(): string
    {
        return 'nhl-sat-model-entity-rate-projections:' . $this->modelRunId;
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [
            'nhl-sat-model-entity-rate-projections',
            'model-run:' . $this->modelRunId,
        ];
    }

    public function handle(NhlSatModelEntityRateProjectionBuilder $builder): void
    {
        $run = NhlModelRun::query()->findOrFail($this->modelRunId);
        $entities = $builder->prepareBuild($run);
        $jobs = array_map(
            fn (array $entity): BuildNhlSatModelEntityRateProjectionForEntityJob => new BuildNhlSatModelEntityRateProjectionForEntityJob(
                modelRunId: $this->modelRunId,
                profileType: $entity['profile_type'],
                entityKey: $entity['entity_key']
            ),
            $entities
        );

        $run->forceFill([
            'metrics' => array_merge($run->metrics ?? [], [
                'rate_projection_entities_queued' => count($jobs),
                'rate_projection_entities_completed' => 0,
            ]),
        ])->save();

        if ($jobs === []) {
            $this->markFinished(failed: false);

            return;
        }

        $modelRunId = $this->modelRunId;

        Bus::batch($jobs)
            ->name('NHL SAT model /60 projections ' . $this->modelRunId)
            ->allowFailures()
            ->finally(function (Batch $batch) use ($modelRunId): void {
                self::markFinishedForRun($modelRunId, failed: $batch->failedJobs > 0);
            })
            ->dispatch();
    }

    public function failed(Throwable $exception): void
    {
        $this->markFailed($exception->getMessage());

        Log::error('NHL SAT model entity /60 projections job failed.', [
            'model_run_id' => $this->modelRunId,
            'error' => $exception->getMessage(),
        ]);
    }

    private function markFinished(bool $failed): void
    {
        self::markFinishedForRun($this->modelRunId, $failed);
    }

    private static function markFinishedForRun(int $modelRunId, bool $failed): void
    {
        $run = NhlModelRun::query()->find($modelRunId);

        if ($run === null) {
            return;
        }

        $counts = self::projectionCountsForRun($modelRunId);
        $run->forceFill([
            'status' => $failed ? NhlModelRun::STATUS_FAILED : NhlModelRun::STATUS_COMPLETE,
            'metrics' => array_merge($run->metrics ?? [], [
                'rate_projections_completed_at' => now()->toIso8601String(),
                'rate_projection_rows' => $counts,
            ]),
            'completed_at' => now(),
        ])->save();

        self::broadcastForRun($modelRunId, $failed ? 'rate-projections-failed' : 'rate-projections-completed');
    }

    private function markFailed(string $message): void
    {
        $run = NhlModelRun::query()->find($this->modelRunId);

        $run?->forceFill([
            'status' => NhlModelRun::STATUS_FAILED,
            'metrics' => array_merge($run->metrics ?? [], [
                'failed_at' => now()->toIso8601String(),
                'error' => mb_substr($message, 0, 1000),
            ]),
            'completed_at' => now(),
        ])->save();

        self::broadcastForRun($this->modelRunId, 'rate-projections-failed');
    }

    /**
     * @return array<string, int>
     */
    private static function projectionCountsForRun(int $modelRunId): array
    {
        $counts = DB::table('nhl_sat_model_entity_rate_projection_buckets')
            ->where('model_run_id', $modelRunId)
            ->selectRaw('profile_type, COUNT(*) as rows')
            ->groupBy('profile_type')
            ->pluck('rows', 'profile_type')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
        $counts['total'] = array_sum($counts);

        return $counts;
    }

    private static function broadcastForRun(int $modelRunId, string $reason): void
    {
        try {
            broadcast(new NhlSatModelUpdated($modelRunId, $reason));
        } catch (Throwable $throwable) {
            Log::warning('NHL SAT model /60 broadcast failed.', [
                'model_run_id' => $modelRunId,
                'reason' => $reason,
                'error' => $throwable->getMessage(),
            ]);
        }
    }
}
