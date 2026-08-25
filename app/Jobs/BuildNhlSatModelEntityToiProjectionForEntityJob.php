<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\NhlModelRun;
use App\Services\NhlSatModelEntityToiProjectionBuilder;
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
 * Builds one model-run entity TOI projection row.
 */
class BuildNhlSatModelEntityToiProjectionForEntityJob implements ShouldQueue
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
    public int $timeout = 120;

    public function __construct(
        public int $modelRunId,
        public string $profileType,
        public string $entityKey
    ) {
        $this->afterCommit = true;
    }

    /**
     * Prevent duplicate TOI builds for the same model-run entity.
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
            'nhl-sat-model-entity-toi-projection',
            'model-run:' . $this->modelRunId,
            'profile-type:' . $this->profileType,
            'entity:' . $this->entityKey,
        ];
    }

    public function handle(NhlSatModelEntityToiProjectionBuilder $builder): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $builder->buildEntity(
            run: NhlModelRun::query()->findOrFail($this->modelRunId),
            profileType: $this->profileType,
            entityKey: $this->entityKey
        );

        DB::transaction(function (): void {
            $run = NhlModelRun::query()->whereKey($this->modelRunId)->lockForUpdate()->first();

            if ($run === null) {
                return;
            }

            $metrics = $run->metrics ?? [];
            $metrics['toi_projection_entities_completed'] = ((int) ($metrics['toi_projection_entities_completed'] ?? 0)) + 1;

            $run->forceFill(['metrics' => $metrics])->save();
        });
    }

    public function failed(Throwable $exception): void
    {
        Log::error('NHL SAT model entity TOI projection job failed.', [
            'model_run_id' => $this->modelRunId,
            'profile_type' => $this->profileType,
            'entity_key' => $this->entityKey,
            'error' => $exception->getMessage(),
        ]);
    }

    private function uniqueKey(): string
    {
        return 'nhl-sat-model-entity-toi-projection:'
            . $this->modelRunId . ':'
            . $this->profileType . ':'
            . sha1($this->entityKey);
    }
}
