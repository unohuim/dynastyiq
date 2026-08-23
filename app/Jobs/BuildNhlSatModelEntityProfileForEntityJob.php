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

    public function __construct(
        public int $modelRunId,
        public int $satModelId,
        public ?int $sogModelId,
        public string $profileType,
        public string $entityKey,
        public ?string $snapshotSeasonId = null
    ) {
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

    public function handle(NhlSatModelEntityProfileBuilder $builder): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $run = NhlModelRun::query()->findOrFail($this->modelRunId);
        $satModel = NhlExpectedGoalsModel::query()->findOrFail($this->satModelId);
        $sogModel = $this->sogModelId === null ? null : NhlExpectedGoalsModel::query()->findOrFail($this->sogModelId);

        if ($this->snapshotSeasonId !== null) {
            $builder->buildSeasonSnapshotEntity(
                run: $run,
                satModel: $satModel,
                sogModel: $sogModel,
                profileType: $this->profileType,
                entityKey: $this->entityKey,
                seasonId: $this->snapshotSeasonId
            );
        } else {
            $builder->buildEntity(
                run: $run,
                satModel: $satModel,
                sogModel: $sogModel,
                profileType: $this->profileType,
                entityKey: $this->entityKey
            );
        }

        DB::transaction(function (): void {
            $run = NhlModelRun::query()->whereKey($this->modelRunId)->lockForUpdate()->first();

            if ($run === null) {
                return;
            }

            $metrics = $run->metrics ?? [];
            $metricKey = $this->snapshotSeasonId === null ? 'profile_entities_completed' : 'season_snapshot_entities_completed';
            $metrics[$metricKey] = ((int) ($metrics[$metricKey] ?? 0)) + 1;

            $run->forceFill(['metrics' => $metrics])->save();
        });
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
