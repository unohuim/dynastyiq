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
use Illuminate\Database\QueryException;
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

    public bool $failOnTimeout = true;

    /**
     * @var int
     */
    public int $uniqueFor = 21600;

    public function __construct(
        public int $modelRunId,
        public int $satModelId,
        public ?int $sogModelId = null,
        public ?string $predictionBuildId = null,
        public array $profileTypes = ['skater_offense']
    ) {
        $this->onQueue('projections');
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

    /**
     * Start one batch whose pending loader adds bounded groups of entity jobs.
     */
    public function handle(NhlSatModelEntityProfileBuilder $builder): void
    {
        $run = NhlModelRun::query()->findOrFail($this->modelRunId);
        if ($this->predictionBuildId === null || ! $run->acceptsPredictionStage($this->predictionBuildId, 'profiles')) {
            return;
        }
        $modelRunId = $this->modelRunId;
        $predictionBuildId = $this->predictionBuildId;

        try {
            $claimed = DB::transaction(function (): bool {
                $run = NhlModelRun::query()->whereKey($this->modelRunId)->lock('for no key update')->firstOrFail();
                if (! $run->acceptsPredictionStage($this->predictionBuildId, 'profiles')
                    || data_get($run->metrics, 'profile_build.dispatched', false)) {
                    return false;
                }
                $metrics = $run->metrics ?? [];
                $metrics['profile_build'] = array_merge($metrics['profile_build'] ?? [], [
                    'id' => $this->predictionBuildId, 'status' => 'running', 'dispatched' => true,
                ]);
                $run->forceFill(['metrics' => $metrics])->save();

                return true;
            });
            if (! $claimed) {
                return;
            }
            Bus::batch([new LoadNhlSatModelProfileBatchJob(
                $this->modelRunId,
                $this->satModelId,
                $this->sogModelId,
                $this->predictionBuildId,
                profileTypes: $this->profileTypes
            )])
                ->name('NHL SAT model profiles ' . $this->modelRunId)
                ->onQueue('projections')
                ->allowFailures()
                ->finally(static function (Batch $batch) use ($modelRunId, $predictionBuildId): void {
                    try {
                        self::markFinishedForRun($modelRunId, failed: $batch->failedJobs > 0 || $batch->cancelled(), predictionBuildId: $predictionBuildId);
                    } catch (Throwable $exception) {
                        if ($predictionBuildId === null) {
                            throw new \RuntimeException(self::failureMessage($exception));
                        }
                        NhlModelRun::finishPredictionStage($modelRunId, $predictionBuildId, 'profiles', true, error: self::failureMessage($exception));
                        self::broadcastForRun($modelRunId, 'predictions-failed');
                    }
                })
                ->dispatch();
        } catch (Throwable $exception) {
            // Do not hand SQL/bindings or a large previous exception to queue logging.
            $this->failed($exception);
            throw new \RuntimeException(self::failureMessage($exception));
        }
    }

    /**
     * Record bounded failure details without serialized queue payloads.
     */
    public function failed(Throwable $exception): void
    {
        // Pre-deployment payloads have no generation and cannot update current work.
        if ($this->predictionBuildId === null) {
            return;
        }
        NhlModelRun::finishPredictionStage($this->modelRunId, $this->predictionBuildId, 'profiles', true,
            error: self::failureMessage($exception));
        self::broadcastForRun($this->modelRunId, 'profiles-failed');
    }

    /**
     * Keep database diagnostics but omit SQL text, bindings, and exception graphs.
     */
    public static function failureMessage(Throwable $exception): string
    {
        $message = $exception instanceof QueryException
            ? ($exception->getPrevious()?->getMessage() ?? 'Database operation failed.')
            : $exception->getMessage();

        return mb_substr($message, 0, 1000);
    }

    private static function markFinishedForRun(int $modelRunId, bool $failed, ?string $predictionBuildId = null): void
    {
        $run = NhlModelRun::query()->find($modelRunId);

        if ($predictionBuildId === null || $run === null || ! $run->acceptsPredictionStage($predictionBuildId, 'profiles')) {
            return;
        }

        $failed = $failed
            || ! data_get($run->metrics, 'profile_build.loading_complete', false)
            || (int) data_get($run->metrics, 'profile_entities_queued', 0) === 0
            || (int) data_get($run->metrics, 'profile_entities_queued', 0) !== (int) data_get($run->metrics, 'profile_entities_completed', 0)
            || (int) data_get($run->metrics, 'season_snapshot_entities_queued', 0) !== (int) data_get($run->metrics, 'season_snapshot_entities_completed', 0);

        $counts = self::profileCountsForRun($modelRunId);
        $genericBucketStabilityCounts = $failed ? ['total' => 0] : self::genericBucketStabilityCountsForRun($modelRunId);
        NhlModelRun::finishPredictionStage($modelRunId, $predictionBuildId, 'profiles', $failed, [
            'profiles_completed_at' => $failed ? null : now()->toIso8601String(),
            'profile_rows' => $counts,
            'season_snapshot_rows' => self::seasonSnapshotCountsForRun($modelRunId),
            'generic_bucket_stability_rows' => $genericBucketStabilityCounts,
        ]);
        self::broadcastForRun($modelRunId, $failed ? 'profiles-failed' : 'profiles-completed');
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
