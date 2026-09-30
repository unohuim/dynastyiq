<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\NhlExpectedGoalsModel;
use App\Models\NhlModelRun;
use App\Services\NhlSatModelEntityProfileBuilder;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Builds SAT profile rows for one model-run entity.
 */
class BuildNhlSatModelEntityProfileForEntityJob implements ShouldQueue
{
    use Batchable;
    use Queueable;
    use InteractsWithQueue;
    use SerializesModels;

    /**
     * @var int
     */
    public int $tries = 2;

    /**
     * @var array<int, int>
     */
    public array $backoff = [30, 120];

    /**
     * @var int
     */
    public int $timeout = 300;

    /** Default also applies when deserializing a pre-generation job payload. */
    public ?string $predictionBuildId = null;

    public ?string $receiptPage = null;

    public int $receiptIndex = 0;

    public function __construct(
        public int $modelRunId,
        public int $satModelId,
        public ?int $sogModelId,
        public string $profileType,
        public string $entityKey,
        public ?string $snapshotSeasonId = null,
        ?string $predictionBuildId = null,
        ?string $receiptPage = null,
        int $receiptIndex = 0
    ) {
        $this->predictionBuildId = $predictionBuildId;
        $this->receiptPage = $receiptPage;
        $this->receiptIndex = $receiptIndex;
        $this->afterCommit = true;
    }

    /**
     * Prevent duplicate profile builds for the same model-run entity.
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->uniqueKey()))
                ->expireAfter($this->timeout + 120),
        ];
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [
            'nhl-sat-model-entity-profile',
            'model-run:' . $this->modelRunId,
            $this->snapshotSeasonId === null ? 'sample:training' : 'sample:season-snapshot',
            ...($this->snapshotSeasonId === null ? [] : ['season:' . $this->snapshotSeasonId]),
            'profile-type:' . $this->profileType,
            'entity:' . $this->entityKey,
        ];
    }

    /** Commit an entity and its progress receipt together, once per build. */
    public function handle(NhlSatModelEntityProfileBuilder $builder): void
    {
        if ($this->predictionBuildId === null || $this->batch()?->cancelled()) {
            return;
        }
        $receipt = $this->receiptPage ?? sha1(($this->snapshotSeasonId ?? 'training') . ':' . $this->profileType . ':' . $this->entityKey);
        if ($this->receiptIndex < 0 || $this->receiptIndex >= 100) {
            throw new \RuntimeException('Invalid profile completion receipt index.');
        }
        DB::beginTransaction();
        try {
            $run = NhlModelRun::query()->findOrFail($this->modelRunId);
            if (! $run->acceptsPredictionStage($this->predictionBuildId, 'profiles')
                || ($run->metrics['profile_build']['completed_pages'][$receipt][$this->receiptIndex] ?? '0') === '1') {
                DB::rollBack();

                return;
            }
            $satModel = NhlExpectedGoalsModel::query()->findOrFail($this->satModelId);
            $sogModel = $this->sogModelId === null ? null : NhlExpectedGoalsModel::query()->findOrFail($this->sogModelId);
            if ($this->snapshotSeasonId !== null) {
                $builder->buildSeasonSnapshotEntity($run, $satModel, $sogModel, $this->profileType, $this->entityKey, $this->snapshotSeasonId);
            } else {
                $builder->buildEntity($run, $satModel, $sogModel, $this->profileType, $this->entityKey);
            }

            // Keep expensive independent entity writes parallel. Recheck ownership
            // under the run lock before committing either rows or progress.
            $run = NhlModelRun::query()->whereKey($this->modelRunId)->lockForUpdate()->firstOrFail();
            if (! $run->acceptsPredictionStage($this->predictionBuildId, 'profiles')
                || ($run->metrics['profile_build']['completed_pages'][$receipt][$this->receiptIndex] ?? '0') === '1'
                || $this->batch()?->cancelled()) {
                DB::rollBack();

                return;
            }
            $metrics = $run->metrics ?? [];
            // One 100-character receipt per page avoids a run-sized hash map
            // being rewritten for every entity completion.
            $page = str_pad($metrics['profile_build']['completed_pages'][$receipt] ?? '', 100, '0');
            $page[$this->receiptIndex] = '1';
            $metrics['profile_build']['completed_pages'][$receipt] = $page;
            $metricKey = $this->snapshotSeasonId === null ? 'profile_entities_completed' : 'season_snapshot_entities_completed';
            $metrics[$metricKey] = (int) ($metrics[$metricKey] ?? 0) + 1;
            $run->forceFill(['metrics' => $metrics])->save();
            if (((int) ($metrics['profile_entities_completed'] ?? 0) + (int) ($metrics['season_snapshot_entities_completed'] ?? 0)) % 25 === 0) {
                DB::afterCommit(function (): void {
                    try {
                        broadcast(new \App\Events\NhlSatModelUpdated($this->modelRunId, 'predictions-progress'));
                    } catch (Throwable) {
                        // Progress delivery must not fail a successfully built entity.
                    }
                });
            }
            DB::commit();
        } catch (Throwable $exception) {
            DB::rollBack();
            throw new \RuntimeException(BuildNhlSatModelEntityProfilesJob::failureMessage($exception));
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('NHL SAT model entity profile job failed.', [
            'model_run_id' => $this->modelRunId,
            'snapshot_season_id' => $this->snapshotSeasonId,
            'profile_type' => $this->profileType,
            'entity_key' => $this->entityKey,
            'error' => $exception->getMessage(),
        ]);
    }

    private function uniqueKey(): string
    {
        return 'nhl-sat-model-entity-profile:'
            . $this->modelRunId . ':'
            . ($this->snapshotSeasonId ?? 'training') . ':'
            . $this->profileType . ':'
            . sha1($this->entityKey);
    }
}
