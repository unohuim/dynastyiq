<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\NhlModelRun;
use App\Services\NhlSatModelEntityProfileBuilder;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** Loads one bounded entity page and its continuation into the same batch. */
class LoadNhlSatModelProfileBatchJob implements ShouldQueue
{
    use Batchable;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public bool $failOnTimeout = true;

    public int $partition = 0;

    public ?string $after = null;

    /** Carry a partition and cursor, never the full list of profile jobs. */
    public function __construct(
        public int $modelRunId,
        public int $satModelId,
        public ?int $sogModelId = null,
        public ?string $predictionBuildId = null,
        int $partition = 0,
        ?string $after = null,
    ) {
        $this->partition = $partition;
        $this->after = $after;
        $this->afterCommit = true;
    }

    /** Initialize once and submit at most 100 entities plus one continuation. */
    public function handle(NhlSatModelEntityProfileBuilder $builder): void
    {
        try {
            $batch = $this->batch();
            if ($batch === null) {
                throw new RuntimeException('Profile loader requires its owning batch.');
            }
            if ($batch->cancelled()) {
                return;
            }

            $run = NhlModelRun::query()->findOrFail($this->modelRunId);
            if ($this->predictionBuildId === null || ! $run->acceptsPredictionStage($this->predictionBuildId, 'profiles')) {
                return;
            }
            $pageKey = sha1($this->partition . ':' . ($this->after ?? ''));
            if (isset($run->metrics['profile_build']['loaded_pages'][$pageKey])) {
                return;
            }
            $partitions = $run->metrics['profile_build']['partitions'] ?? $builder->profilePartitions($run);
            $descriptor = $partitions[$this->partition] ?? null;
            if ($descriptor === null || $descriptor['profile_type'] !== 'skater_offense') {
                throw new RuntimeException('Profile build scope changed. Start a fresh offensive-skater profile build.');
            }
            // Discovery can scan many facts. Do not block entity progress commits
            // on the model row while that read-only query executes.
            $entities = $builder->profileEntityPage($run, $descriptor['profile_type'], $descriptor['season_id'], $this->after);

            DB::transaction(function () use ($builder, $batch, $partitions, $descriptor, $entities, $pageKey): void {
                $run = NhlModelRun::query()->whereKey($this->modelRunId)->lock('for no key update')->firstOrFail();
                if ($this->predictionBuildId === null || ! $run->acceptsPredictionStage($this->predictionBuildId, 'profiles')) {
                    return;
                }
                $metrics = $run->metrics ?? [];
                $build = $metrics['profile_build'] ?? ['id' => $this->predictionBuildId, 'status' => 'running'];
                if (isset($build['loaded_pages'][$pageKey])) {
                    return;
                }
                if (empty($build['initialized'])) {
                    if ($this->partition !== 0 || $this->after !== null) {
                        throw new RuntimeException('Profile continuation has no initialized build.');
                    }
                    $builder->clearProfileOutputs($run);
                    $build['initialized'] = true;
                    $build['partitions'] = $partitions;
                    $metrics['profile_entities_queued'] = 0;
                    $metrics['profile_entities_completed'] = 0;
                    $metrics['season_snapshot_entities_queued'] = 0;
                    $metrics['season_snapshot_entities_completed'] = 0;
                    $metrics['profiles_completed_at'] = null;
                }
                if (($build['partitions'][$this->partition] ?? null) !== $descriptor) {
                    throw new RuntimeException('Profile partition changed during discovery.');
                }
                $jobs = [];
                foreach ($entities as $index => $entityKey) {
                    $jobs[] = new BuildNhlSatModelEntityProfileForEntityJob(
                        $this->modelRunId, $this->satModelId, $this->sogModelId,
                        $descriptor['profile_type'], $entityKey, $descriptor['season_id'], $this->predictionBuildId,
                        $pageKey, $index
                    );
                }
                $metric = $descriptor['season_id'] === null ? 'profile_entities_queued' : 'season_snapshot_entities_queued';
                $metrics[$metric] = (int) ($metrics[$metric] ?? 0) + count($entities);
                $nextPartition = count($entities) === 100 ? $this->partition : $this->partition + 1;
                $nextAfter = count($entities) === 100 ? end($entities) : null;
                if ($nextPartition < count($partitions)) {
                    array_unshift($jobs, new self($this->modelRunId, $this->satModelId, $this->sogModelId,
                        $this->predictionBuildId, $nextPartition, $nextAfter));
                } else {
                    $build['loading_complete'] = true;
                    if ((int) $metrics['profile_entities_queued'] === 0) {
                        throw new RuntimeException('No training profile entities are available.');
                    }
                }
                if ($this->batch()?->cancelled()) {
                    throw new RuntimeException('Profile batch was cancelled while loading.');
                }
                // Entity receipts protect against duplicate delivery. An uncertain
                // cross-store publish must fail the build instead of reporting success.
                $build['loaded_pages'][$pageKey] = true;
                $metrics['profile_build'] = $build;
                $run->forceFill(['metrics' => $metrics])->save();
                foreach (array_chunk($jobs, 100) as $chunk) {
                    $batch->add($chunk);
                }
            });
        } catch (Throwable $exception) {
            $failure = new RuntimeException('Profile loading failed: ' . BuildNhlSatModelEntityProfilesJob::failureMessage($exception));
            $this->failed($failure);
            throw $failure;
        }
    }

    /** Fail only this generation and cancel its partially submitted batch. */
    public function failed(Throwable $exception): void
    {
        $this->batch()?->cancel();
        (new BuildNhlSatModelEntityProfilesJob($this->modelRunId, $this->satModelId,
            $this->sogModelId, $this->predictionBuildId))->failed($exception);
    }
}
