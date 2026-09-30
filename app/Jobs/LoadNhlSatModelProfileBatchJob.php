<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\NhlModelRun;
use App\Services\NhlSatModelEntityProfileBuilder;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

/**
 * Hydrates one profile batch in bounded inserts while keeping its loader pending.
 */
class LoadNhlSatModelProfileBatchJob implements ShouldQueue
{
    use Batchable;
    use Queueable;

    private const JOBS_PER_INSERT = 100;

    public int $tries = 1;

    public int $timeout = 1800;

    /**
     * Carry identifiers only; discover profile entities inside the worker.
     */
    public function __construct(
        public int $modelRunId,
        public int $satModelId,
        public ?int $sogModelId = null,
        public ?string $predictionBuildId = null
    ) {
        $this->afterCommit = true;
    }

    /**
     * Add child jobs without constructing or serializing the full job list at once.
     */
    public function handle(NhlSatModelEntityProfileBuilder $builder): void
    {
        $submitted = 0;
        $total = 0;
        try {
            $batch = $this->batch();
            if ($batch === null) {
                throw new RuntimeException('Profile loader requires its owning batch.');
            }
            if ($batch->cancelled()) {
                return;
            }

            $run = NhlModelRun::query()->findOrFail($this->modelRunId);
            if (! $run->acceptsPredictionStage($this->predictionBuildId, 'profiles')) {
                return;
            }

            $entities = $builder->prepareBuild($run);
            $snapshots = $builder->prepareSeasonSnapshotBuilds($run);
            $total = count($entities) + count($snapshots);
            $run->forceFill([
                'metrics' => array_merge($run->metrics ?? [], [
                    'profile_entities_queued' => count($entities),
                    'profile_entities_completed' => 0,
                    'season_snapshot_entities_queued' => count($snapshots),
                    'season_snapshot_entities_completed' => 0,
                ]),
            ])->save();

            if ($entities === [] && $snapshots === [] && $this->predictionBuildId !== null) {
                throw new RuntimeException('No profile entities or season snapshots are available.');
            }

            // The loader itself remains pending until both lists have been submitted.
            // Earlier children may finish, but cannot complete the batch prematurely.
            $jobs = [];
            foreach ([$entities, $snapshots] as $group) {
                foreach ($group as $entity) {
                    $jobs[] = new BuildNhlSatModelEntityProfileForEntityJob(
                        modelRunId: $this->modelRunId,
                        satModelId: $this->satModelId,
                        sogModelId: $this->sogModelId,
                        profileType: $entity['profile_type'],
                        entityKey: $entity['entity_key'],
                        snapshotSeasonId: $entity['season_id'] ?? null
                    );
                    if (count($jobs) === self::JOBS_PER_INSERT) {
                        if ($this->batch()?->cancelled()) {
                            return;
                        }
                        $batch->add($jobs);
                        $submitted += count($jobs);
                        $jobs = [];
                    }
                }
            }
            if ($jobs !== [] && ! $this->batch()?->cancelled()) {
                $batch->add($jobs);
            }
        } catch (Throwable $exception) {
            // No previous exception: reporters must not traverse SQL queue payloads.
            $failure = new RuntimeException(sprintf(
                'Profile loading failed (queued %d/%d): %s',
                $submitted,
                $total,
                BuildNhlSatModelEntityProfilesJob::failureMessage($exception)
            ));
            // Persist the useful error before the batch's finally callback can mark
            // the stage failed without details. The worker may call failed() again.
            $this->failed($failure);

            throw $failure;
        }
    }

    /**
     * Cancel partially loaded work and stop the combined workflow after any failure.
     */
    public function failed(Throwable $exception): void
    {
        $this->batch()?->cancel();
        (new BuildNhlSatModelEntityProfilesJob(
            $this->modelRunId,
            $this->satModelId,
            $this->sogModelId,
            $this->predictionBuildId
        ))->failed($exception);
    }
}
