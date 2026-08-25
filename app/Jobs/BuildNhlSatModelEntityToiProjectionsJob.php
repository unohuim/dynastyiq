<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Events\NhlSatModelUpdated;
use App\Models\NhlModelRun;
use App\Services\NhlSatModelEntityToiProjectionBuilder;
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
 * Queues per-entity SAT model TOI projection builds.
 */
class BuildNhlSatModelEntityToiProjectionsJob implements ShouldQueue, ShouldBeUnique
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
     * Prevent duplicate TOI builds for the same model run.
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
        return 'nhl-sat-model-entity-toi-projections:' . $this->modelRunId;
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [
            'nhl-sat-model-entity-toi-projections',
            'model-run:' . $this->modelRunId,
        ];
    }

    public function handle(NhlSatModelEntityToiProjectionBuilder $builder): void
    {
        $run = NhlModelRun::query()->findOrFail($this->modelRunId);
        $entities = $builder->prepareBuild($run);
        $jobs = array_map(
            fn (array $entity): BuildNhlSatModelEntityToiProjectionForEntityJob => new BuildNhlSatModelEntityToiProjectionForEntityJob(
                modelRunId: $this->modelRunId,
                profileType: $entity['profile_type'],
                entityKey: $entity['entity_key']
            ),
            $entities
        );

        $run->forceFill([
            'metrics' => array_merge($run->metrics ?? [], [
                'toi_projection_entities_queued' => count($jobs),
                'toi_projection_entities_completed' => 0,
            ]),
        ])->save();

        if ($jobs === []) {
            self::markFinishedForRun($this->modelRunId, failed: false);

            return;
        }

        $modelRunId = $this->modelRunId;

        Bus::batch($jobs)
            ->name('NHL SAT model TOI projections ' . $this->modelRunId)
            ->allowFailures()
            ->finally(function (Batch $batch) use ($modelRunId): void {
                self::markFinishedForRun($modelRunId, failed: $batch->failedJobs > 0);
            })
            ->dispatch();
    }

    public function failed(Throwable $exception): void
    {
        $run = NhlModelRun::query()->find($this->modelRunId);

        $run?->forceFill([
            'status' => NhlModelRun::STATUS_FAILED,
            'metrics' => array_merge($run->metrics ?? [], [
                'failed_at' => now()->toIso8601String(),
                'error' => mb_substr($exception->getMessage(), 0, 1000),
            ]),
            'completed_at' => now(),
        ])->save();

        self::broadcastForRun($this->modelRunId, 'toi-projections-failed');

        Log::error('NHL SAT model entity TOI projections job failed.', [
            'model_run_id' => $this->modelRunId,
            'error' => $exception->getMessage(),
        ]);
    }

    private static function markFinishedForRun(int $modelRunId, bool $failed): void
    {
        $run = NhlModelRun::query()->find($modelRunId);

        if ($run === null) {
            return;
        }

        $counts = DB::table('nhl_sat_model_entity_toi_projections')
            ->where('model_run_id', $modelRunId)
            ->selectRaw('profile_type, COUNT(*) as rows')
            ->groupBy('profile_type')
            ->pluck('rows', 'profile_type')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
        $counts['total'] = array_sum($counts);

        $run->forceFill([
            'status' => $failed ? NhlModelRun::STATUS_FAILED : NhlModelRun::STATUS_COMPLETE,
            'metrics' => array_merge($run->metrics ?? [], [
                'toi_projections_completed_at' => now()->toIso8601String(),
                'toi_projection_rows' => $counts,
            ]),
            'completed_at' => now(),
        ])->save();

        self::broadcastForRun($modelRunId, $failed ? 'toi-projections-failed' : 'toi-projections-completed');
    }

    private static function broadcastForRun(int $modelRunId, string $reason): void
    {
        try {
            broadcast(new NhlSatModelUpdated($modelRunId, $reason));
        } catch (Throwable $throwable) {
            Log::warning('NHL SAT model TOI projection broadcast failed.', [
                'model_run_id' => $modelRunId,
                'reason' => $reason,
                'error' => $throwable->getMessage(),
            ]);
        }
    }
}
