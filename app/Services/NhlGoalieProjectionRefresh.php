<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\NhlGameImportStatusUpdated;
use App\Jobs\BuildNhlGoalieProjectionForGoalieJob;
use App\Jobs\BuildNhlGoalieWorkloadProjectionForGoalieJob;
use App\Jobs\RefreshNhlGoalieProjectionsJob;
use App\Models\NhlGameImportRun;
use Illuminate\Bus\Batch;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Refresh existing goalie projection configurations after sync and publish only complete versions. */
class NhlGoalieProjectionRefresh
{
    public const VERSION_PREFIX = 'sync-goalie-';

    /** Exclude unpublished automatic versions while preserving existing manual-version selection. */
    public static function usable(Builder $query, string $alias = 'projections'): Builder
    {
        return $query->where(static function (Builder $query) use ($alias): void {
            $query->where($alias.'.projection_version', 'not like', self::VERSION_PREFIX.'%')
                ->orWhere($alias.'.status', 'published');
        });
    }

    /** Reuse the synced target season's existing source/model/TOI settings, not a new algorithm. */
    public function queueSeason(string $season, ?int $runId = null): void
    {
        if (! preg_match('/^\d{8}$/', $season)) {
            throw new \InvalidArgumentException('Goalie refresh requires an eight-digit season.');
        }
        DB::transaction(fn () => $this->queueConfiguredSeason($season, $runId), 3);
    }

    /** Admit duplicate event deliveries atomically against the originating run, when present. */
    private function queueConfiguredSeason(string $season, ?int $runId): void
    {
        $existing = $runId === null ? null : NhlGameImportRun::query()->lockForUpdate()->find($runId);
        if (data_get($existing?->payload, 'goalie_refresh.version') !== null) {
            return;
        }
        $configuration = self::usable(DB::table('nhl_goalie_season_projections as projections'))
            ->where('target_season_id', $season)->where('status', '!=', 'archived')
            ->orderByDesc('projected_at')->orderByDesc('id')->first();
        if ($configuration === null) {
            $this->record(['target' => $season, 'run_id' => $runId], 'skipped');
            return;
        }
        $metadata = json_decode($configuration->metadata ?? '{}', true, 512, JSON_THROW_ON_ERROR);
        $context = ['target' => $season, 'source' => (string) $configuration->source_season_id,
            'toi' => $configuration->toi_projection_version, 'model' => $metadata['sat_model_run_id'] ?? null,
            'version' => self::VERSION_PREFIX.$season.'-'.Str::uuid(), 'run_id' => $runId,
            'owner' => (string) Str::uuid()];
        $this->record($context, 'queued');
        try {
            RefreshNhlGoalieProjectionsJob::dispatch($context);
        } catch (Throwable $exception) {
            $this->fail($context, $exception->getMessage());
            report($exception);
        }
    }

    /** Hold a cross-stage lease, then queue one existing workload job per current goalie.
     * @param array<string,mixed> $context
     */
    public function start(array $context): bool
    {
        if (DB::table('job_batches')->where('name', 'Goalie workload refresh '.$context['version'])->exists()) {
            return true;
        }
        $lock = Cache::lock($this->lockKey($context), 21600, $context['owner']);
        if (! $lock->get() && ! $lock->isOwnedByCurrentProcess()) {
            return false;
        }
        try {
            $setup = app(NhlGoalieWorkloadProjectionBuilder::class)->prepareBuild(
                $context['source'], $context['target'], $context['version']);
            $context['goalies'] = $setup['goalie_player_ids'];
            if ($context['goalies'] === []) {
                throw new RuntimeException('No current-team goalies are available for automatic projection refresh.');
            }
            $jobs = array_map(static fn (int $id): BuildNhlGoalieWorkloadProjectionForGoalieJob =>
                new BuildNhlGoalieWorkloadProjectionForGoalieJob($context['source'], $context['target'], $context['version'], $id),
                $context['goalies']);
            $this->record($context, 'building');
            Bus::batch($jobs)->onQueue('projections')->name('Goalie workload refresh '.$context['version'])
                ->then(static function (Batch $batch) use ($context): void {
                    if (! $batch->cancelled() && $batch->failedJobs === 0) {
                        try {
                            RefreshNhlGoalieProjectionsJob::dispatch($context, 'performance');
                        } catch (Throwable $exception) {
                            app(self::class)->fail($context, $exception->getMessage());
                            report($exception);
                        }
                    }
                })->catch(static function (Batch $batch, Throwable $exception) use ($context): void {
                    app(self::class)->fail($context, $exception->getMessage());
                })->finally(static function (Batch $batch) use ($context): void {
                    if ($batch->cancelled()) {
                        app(self::class)->fail($context, 'Goalie workload refresh was cancelled.');
                    }
                })->dispatch();
        } catch (Throwable $exception) {
            $this->fail($context, $exception->getMessage());
            throw $exception;
        }

        return true;
    }

    /** Queue performance only after all workload rows exist under the new isolated version.
     * @param array<string,mixed> $context
     */
    public function performance(array $context): void
    {
        if (DB::table('job_batches')->where('name', 'Goalie performance refresh '.$context['version'])->exists()) {
            return;
        }
        $this->assertOwner($context);
        $workload = DB::table('nhl_goalie_workload_projections')->where('target_season_id', $context['target'])
            ->where('projection_version', $context['version'])->pluck('goalie_player_id')->map(fn ($id): int => (int) $id)->all();
        if (array_diff($context['goalies'], $workload) !== []) {
            throw new RuntimeException('Automatic goalie refresh has incomplete workload rows.');
        }
        $context['goalies'] = DB::table('nhl_goalie_workload_projections')->where('target_season_id', $context['target'])
            ->where('projection_version', $context['version'])->where('projected_games', '>', 0)
            ->pluck('goalie_player_id')->map(fn ($id): int => (int) $id)->all();
        if ($context['goalies'] === []) {
            throw new RuntimeException('Automatic goalie refresh has no positive projected workloads.');
        }
        $setup = app(NhlGoalieProjectionBuilder::class)->prepareBuild($context['source'], $context['target'],
            $context['version'], $context['toi'], $context['version'], $context['model']);
        if ($setup['goalie_player_ids'] === [] || array_diff($context['goalies'], $setup['goalie_player_ids']) !== []) {
            throw new RuntimeException('Automatic goalie refresh has incomplete performance inputs.');
        }
        $jobs = array_map(static fn (int $id): BuildNhlGoalieProjectionForGoalieJob =>
            new BuildNhlGoalieProjectionForGoalieJob($context['source'], $context['target'], $context['version'],
                $context['toi'], $context['version'], $id, $context['model']), $context['goalies']);
        Bus::batch($jobs)->onQueue('projections')->name('Goalie performance refresh '.$context['version'])
            ->then(static function (Batch $batch) use ($context): void {
                if ($batch->cancelled() || $batch->failedJobs > 0) {
                    return;
                }
                try {
                    app(self::class)->publish($context);
                } catch (Throwable $exception) {
                    app(self::class)->fail($context, $exception->getMessage());
                    report($exception);
                }
            })->catch(static function (Batch $batch, Throwable $exception) use ($context): void {
                app(self::class)->fail($context, $exception->getMessage());
            })->finally(static function (Batch $batch) use ($context): void {
                if ($batch->cancelled()) {
                    app(self::class)->fail($context, 'Goalie performance refresh was cancelled.');
                }
            })->dispatch();
    }

    /** Publish both stages atomically; zero-row children cannot masquerade as successful coverage.
     * @param array<string,mixed> $context
     */
    public function publish(array $context): void
    {
        $this->assertOwner($context);
        DB::transaction(function () use ($context): void {
            $workloads = DB::table('nhl_goalie_workload_projections')->where('target_season_id', $context['target'])
                ->where('projection_version', $context['version'])->where('projected_games', '>', 0)
                ->pluck('goalie_player_id')->map(fn ($id): int => (int) $id)->all();
            $rows = DB::table('nhl_goalie_season_projections as projections')
                ->where('projections.target_season_id', $context['target'])->where('projections.projection_version', $context['version'])
                ->whereNotNull('projected_gsax')->where('projected_games', '>', 0)
                ->whereExists(static function (Builder $query): void {
                    $query->selectRaw('1')->from('nhl_goalie_projection_chance_buckets as buckets')
                        ->whereColumn('buckets.goalie_season_projection_id', 'projections.id')
                        ->whereColumn('buckets.projection_version', 'projections.projection_version')
                        ->whereColumn('buckets.target_season_id', 'projections.target_season_id')
                        ->whereColumn('buckets.goalie_player_id', 'projections.goalie_player_id')
                        ->whereIn('buckets.projection_strength', ['ev', 'all']);
                })->pluck('goalie_player_id')->map(fn ($id): int => (int) $id)->all();
            if ($context['goalies'] === [] || array_diff($context['goalies'], $rows) !== []
                || array_diff($context['goalies'], $workloads) !== []) {
                throw new RuntimeException('Automatic goalie refresh has incomplete performance projections, workloads or buckets.');
            }
            foreach (['nhl_goalie_workload_projections', 'nhl_goalie_season_projections'] as $table) {
                DB::table($table)->where('target_season_id', $context['target'])->where('projection_version', $context['version'])
                    ->update(['status' => 'published', 'updated_at' => now()]);
            }
            $this->record($context, 'ready');
        }, 3);
        $this->unlock($context);
    }

    /** Preserve prior published projections and surface automatic preparation failures.
     * @param array<string,mixed> $context
     */
    public function fail(array $context, string $error): void
    {
        try {
            $this->record($context, 'failed', $error);
        } finally {
            $this->unlock($context);
        }
        Log::error('Automatic goalie projection refresh failed.', ['season' => $context['target'], 'error' => $error]);
    }

    /** The existing season-sync UI must not report success before goalie preparation finishes. */
    public static function runStatus(array $payload): string
    {
        $cache = data_get($payload, 'stats_cache.status', 'queued');
        $goalies = data_get($payload, 'goalie_refresh.status', 'skipped');
        if ($cache === 'failed' || $goalies === 'failed') {
            return NhlGameImportRun::STATUS_FAILED;
        }

        return isset($payload['aggregation_completed_at']) && $cache === 'ready' && in_array($goalies, ['ready', 'skipped'], true)
            ? NhlGameImportRun::STATUS_COMPLETED : NhlGameImportRun::STATUS_RUNNING;
    }

    /** Merge progress under the existing run lock without losing cache/aggregation updates.
     * @param array<string,mixed> $context
     */
    private function record(array $context, string $status, ?string $error = null): void
    {
        if (($context['run_id'] ?? null) === null) {
            return;
        }
        DB::transaction(static function () use ($context, $status, $error): void {
            $run = NhlGameImportRun::query()->lockForUpdate()->find($context['run_id']);
            if ($run === null || $run->action !== NhlGameImportRun::ACTION_SEASON_SYNC) {
                return;
            }
            $payload = $run->payload ?? [];
            if (in_array(data_get($payload, 'goalie_refresh.status'), ['ready', 'failed'], true)) {
                return;
            }
            $payload['goalie_refresh'] = ['status' => $status, 'version' => $context['version'] ?? null,
                'season' => $context['target'], 'error' => $error];
            $runStatus = $run->status === NhlGameImportRun::STATUS_FAILED ? NhlGameImportRun::STATUS_FAILED : self::runStatus($payload);
            if ($runStatus === NhlGameImportRun::STATUS_COMPLETED) {
                $payload['completed_at'] = now()->toIso8601String();
            }
            $run->update(['payload' => $payload, 'status' => $runStatus,
                'last_error' => $error ?? $run->last_error]);
        }, 3);
        DB::afterCommit(static function () use ($context): void {
            try {
                broadcast(new NhlGameImportStatusUpdated('season-sync-progress', $context['run_id']));
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }

    /** @param array<string,mixed> $context */
    private function lockKey(array $context): string
    {
        return 'nhl:goalie-refresh:'.$context['target'];
    }

    /** @param array<string,mixed> $context */
    private function assertOwner(array $context): void
    {
        if (! isset($context['owner']) || ! Cache::restoreLock($this->lockKey($context), $context['owner'])->isOwnedByCurrentProcess()) {
            throw new RuntimeException('Automatic goalie refresh lease expired; its draft outputs remain unpublished.');
        }
    }

    /** @param array<string,mixed> $context */
    private function unlock(array $context): void
    {
        if (isset($context['owner'])) {
            Cache::restoreLock($this->lockKey($context), $context['owner'])->release();
        }
    }
}
