<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Events\NhlSatModelUpdated;
use App\Models\NhlModelRun;
use App\Services\NhlSatModelGenericBucketStabilityBuilder;
use App\Services\NhlSatModelEntityProfileBuilder;
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
 * Queues per-entity SAT model profile builds.
 */
class BuildNhlSatModelEntityProfilesJob implements ShouldQueue, ShouldBeUnique
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

    public function __construct(
        public int $modelRunId,
        public int $satModelId,
        public ?int $sogModelId = null
    ) {
        $this->afterCommit = true;
    }

    /**
     * Prevent duplicate profile builds for the same model run.
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
        return 'nhl-sat-model-entity-profiles:' . $this->modelRunId;
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [
            'nhl-sat-model-entity-profiles',
            'model-run:' . $this->modelRunId,
        ];
    }

    public function handle(NhlSatModelEntityProfileBuilder $builder): void
    {
        $run = NhlModelRun::query()->findOrFail($this->modelRunId);
        $entities = $builder->prepareBuild($run);
        $snapshotEntities = $builder->prepareSeasonSnapshotBuilds($run);
        $jobs = array_map(
            fn (array $entity): BuildNhlSatModelEntityProfileForEntityJob => new BuildNhlSatModelEntityProfileForEntityJob(
                modelRunId: $this->modelRunId,
                satModelId: $this->satModelId,
                sogModelId: $this->sogModelId,
                profileType: $entity['profile_type'],
                entityKey: $entity['entity_key']
            ),
            $entities
        );
        $snapshotJobs = array_map(
            fn (array $entity): BuildNhlSatModelEntityProfileForEntityJob => new BuildNhlSatModelEntityProfileForEntityJob(
                modelRunId: $this->modelRunId,
                satModelId: $this->satModelId,
                sogModelId: $this->sogModelId,
                profileType: $entity['profile_type'],
                entityKey: $entity['entity_key'],
                snapshotSeasonId: $entity['season_id']
            ),
            $snapshotEntities
        );
        $jobs = array_merge($jobs, $snapshotJobs);

        $run->forceFill([
            'metrics' => array_merge($run->metrics ?? [], [
                'profile_entities_queued' => count($entities),
                'profile_entities_completed' => 0,
                'season_snapshot_entities_queued' => count($snapshotEntities),
                'season_snapshot_entities_completed' => 0,
            ]),
        ])->save();

        if ($jobs === []) {
            $this->markFinished(failed: false);

            return;
        }

        $modelRunId = $this->modelRunId;

        Bus::batch($jobs)
            ->name('NHL SAT model profiles ' . $this->modelRunId)
            ->allowFailures()
            ->finally(function (Batch $batch) use ($modelRunId): void {
                self::markFinishedForRun($modelRunId, failed: $batch->failedJobs > 0);
            })
            ->dispatch();
    }

    public function failed(Throwable $exception): void
    {
        $this->markFailed($exception->getMessage());

        Log::error('NHL SAT model entity profiles job failed.', [
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

        $counts = self::profileCountsForRun($modelRunId);
        $genericBucketStabilityCounts = self::genericBucketStabilityCountsForRun($modelRunId);
        $run->forceFill([
            'status' => $failed ? NhlModelRun::STATUS_FAILED : NhlModelRun::STATUS_COMPLETE,
            'metrics' => array_merge($run->metrics ?? [], [
                'profiles_completed_at' => now()->toIso8601String(),
                'profile_rows' => $counts,
                'season_snapshot_rows' => self::seasonSnapshotCountsForRun($modelRunId),
                'generic_bucket_stability_rows' => $genericBucketStabilityCounts,
            ]),
            'completed_at' => now(),
        ])->save();

        self::broadcastForRun($modelRunId, $failed ? 'profiles-failed' : 'profiles-completed');
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

        self::broadcastForRun($this->modelRunId, 'profiles-failed');
    }

    /**
     * @return array<string, int>
     */
    private static function profileCountsForRun(int $modelRunId): array
    {
        $counts = DB::table('nhl_sat_model_entity_profile_buckets')
            ->where('model_run_id', $modelRunId)
            ->selectRaw('profile_type, COUNT(*) as rows')
            ->groupBy('profile_type')
            ->pluck('rows', 'profile_type')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
        $counts['total'] = array_sum($counts);

        return $counts;
    }

    /**
     * @return array<string, int>
     */
    private static function seasonSnapshotCountsForRun(int $modelRunId): array
    {
        $rows = DB::table('nhl_sat_model_entity_test_profile_buckets')
            ->where('model_run_id', $modelRunId)
            ->selectRaw('test_season_id, profile_type, COUNT(*) as rows')
            ->groupBy('test_season_id', 'profile_type')
            ->orderBy('test_season_id')
            ->orderBy('profile_type')
            ->get();
        $counts = $rows
            ->mapWithKeys(fn (object $row): array => [
                $row->test_season_id . ':' . $row->profile_type => (int) $row->rows,
            ])
            ->all();
        $counts['total'] = array_sum($counts);

        return $counts;
    }

    /**
     * @return array<string, int>
     */
    private static function genericBucketStabilityCountsForRun(int $modelRunId): array
    {
        $run = NhlModelRun::query()->find($modelRunId);

        if ($run === null || ! DB::getSchemaBuilder()->hasTable('nhl_sat_model_generic_bucket_stabilities')) {
            return ['total' => 0];
        }

        return app(NhlSatModelGenericBucketStabilityBuilder::class)->build($run);
    }

    private static function broadcastForRun(int $modelRunId, string $reason): void
    {
        try {
            broadcast(new NhlSatModelUpdated($modelRunId, $reason));
        } catch (Throwable $throwable) {
            Log::warning('NHL SAT model profile broadcast failed.', [
                'model_run_id' => $modelRunId,
                'reason' => $reason,
                'error' => $throwable->getMessage(),
            ]);
        }
    }
}
