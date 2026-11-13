<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\NhlGameImportStatusUpdated;
use App\Jobs\RefreshNhlGoalieProjectionsJob;
use App\Models\NhlGameImportRun;
use App\Models\NhlModelRun;
use App\Models\Player;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Publish model-owned goalie rating snapshots without legacy projection or defensive-profile dependencies. */
class NhlGoalieProjectionRefresh
{
    /** Retain exclusion of drafts left by the superseded automatic season-projection pathway. */
    public const VERSION_PREFIX = 'sync-goalie-';

    /** Keep existing manual reads compatible while excluding abandoned automatic drafts. */
    public static function usable(Builder $query, string $alias = 'projections'): Builder
    {
        return $query->where(static function (Builder $query) use ($alias): void {
            $query->where($alias.'.projection_version', 'not like', self::VERSION_PREFIX.'%')
                ->orWhere($alias.'.status', 'published');
        });
    }

    /** Queue only the configured production SAT model; never substitute the experimental model. */
    public function queueSeason(string $season, ?int $runId = null): void
    {
        if (! preg_match('/^\d{8}$/', $season)) {
            throw new \InvalidArgumentException('Goalie refresh requires an eight-digit season.');
        }
        DB::transaction(function () use ($season, $runId): void {
            $run = $runId === null ? null : NhlGameImportRun::query()->lockForUpdate()->find($runId);
            if (data_get($run?->payload, 'goalie_refresh.version') !== null) {
                return;
            }
            $model = $this->productionModel($season);
            $context = ['target' => $season, 'run_id' => $runId, 'model_id' => $model?->id,
                'version' => 'sat-goalie-ratings-'.Str::uuid()];
            if ($model === null) {
                $this->record($context, 'skipped');
                return;
            }
            $this->record($context, 'queued');
            RefreshNhlGoalieProjectionsJob::dispatch($context);
        }, 3);
    }

    /** Reuse the same stored training data as the selected forecast, normalized to sixty minutes.
     * @param array<int,string> $keys Retained model Train-confidence >= 50% bucket keys.
     * @return array<string,mixed>|null Null means a build/lock race; a prior snapshot remains untouched.
     */
    public function snapshot(NhlModelRun $model, array $keys, bool $force = false): ?array
    {
        $keys = array_values(array_unique($keys));
        sort($keys);
        $cacheKey = 'nhl:sat-goalie-ratings:v1:'.$model->id.':'.hash('sha256', json_encode($keys, JSON_THROW_ON_ERROR));
        $model = $model->fresh();
        if ($model === null) {
            return null;
        }
        $stamp = $this->stamp($model);
        $saved = Cache::get($cacheKey);
        if ($model->status !== NhlModelRun::STATUS_COMPLETE) {
            return is_array($saved) ? $saved : null;
        }
        if (! $force && is_array($saved) && ($saved['source_stamp'] ?? null) === $stamp) {
            return $saved;
        }
        $lock = Cache::lock($cacheKey.':build', 120);
        if (! $lock->get()) {
            return ! $force && is_array($saved) ? $saved : null;
        }
        try {
            $rows = $keys === [] ? collect() : DB::table('nhl_sat_model_entity_profile_buckets')
                ->where('model_run_id', $model->id)->where('profile_type', 'goalie_faced')
                ->where('game_type', 2)->where('strength', 'all')->whereIn('matched_bucket_key', $keys)
                ->whereNotNull('entity_id')->select('entity_id')
                ->selectRaw('SUM(source_sat) AS sat, SUM(source_sog) AS sog, SUM(source_goals) AS goals, SUM(expected_goals) AS xga')
                ->selectRaw('MAX(source_toi_seconds) AS seconds, MIN(source_toi_seconds) AS min_seconds')
                ->selectRaw('COUNT(*) AS bucket_count, COUNT(source_toi_seconds) AS exposed_buckets')
                ->selectRaw('SUM(source_sat * confidence_score) AS weighted_confidence')
                ->groupBy('entity_id')->get();
            $ratings = [];
            foreach ($rows as $row) {
                $seconds = (float) $row->seconds;
                if ($seconds <= 0 || (float) $row->min_seconds !== $seconds || (int) $row->bucket_count !== (int) $row->exposed_buckets
                    || (float) $row->sat <= 0 || (float) $row->sog <= 0) {
                    continue;
                }
                $scale = 3600 / $seconds;
                $ratings[(int) $row->entity_id] = [
                    'projected_xga_per_game' => round((float) $row->xga * $scale, 4),
                    'projected_ga_per_game' => round((float) $row->goals * $scale, 4),
                    'projected_gsax_per_game' => round(((float) $row->xga - (float) $row->goals) * $scale, 4),
                    'confidence_score' => round(100 * max(0.0, min(1.0, (float) $row->weighted_confidence / (float) $row->sat))),
                    'source_sat' => (int) $row->sat, 'source_sog' => (int) $row->sog,
                    'source_goals' => (int) $row->goals, 'source_xga' => (float) $row->xga,
                    'source_toi_seconds' => (int) $seconds,
                ];
            }
            $current = $model->fresh();
            if ($current === null || $current->status !== NhlModelRun::STATUS_COMPLETE || $this->stamp($current) !== $stamp) {
                return null;
            }
            $snapshot = ['source_stamp' => $stamp, 'model_run_id' => (int) $model->id,
                'comparison_minutes' => 60, 'prepared_at' => now()->toIso8601String(), 'ratings' => $ratings];
            // Missing source data never replaces a previously usable prepared snapshot.
            if ($ratings !== []) {
                if (! Cache::forever($cacheKey, $snapshot)) {
                    throw new RuntimeException('Unable to publish the SAT goalie rating snapshot.');
                }
            }

            return $snapshot;
        } finally {
            $lock->release();
        }
    }

    /** Bind diagnostics to the forecast's exact starter and model, not a second starter selection.
     * @param array<int,string> $keys
     * @return array<string,mixed>
     */
    public function goalie(NhlModelRun $model, ?int $id, string $team, array $keys): array
    {
        $rating = $id === null ? null : ($this->snapshot($model, $keys)['ratings'][$id] ?? null);
        $name = $id === null ? null : Player::query()->where('nhl_id', $id)->value('full_name');

        return [
            'nhl_player_id' => $id, 'name' => $name ?? ($id === null ? null : (string) $id), 'team_abbrev' => $team,
            'selection_source' => 'prediction_starter',
            'projection_source' => $rating === null ? 'unavailable' : 'goalie_model',
            'projection_fallback_reason' => $rating === null ? 'missing_sat_goalie_profile' : null,
            'sat_model_run_id' => (int) $model->id, 'projection_basis' => 'sat_model_training_per_60',
            'comparison_minutes' => 60, 'projected_games' => null, 'projected_starts' => null, 'projected_toi_hours' => null,
            'projected_ev_xga_per_game' => null, 'projected_ev_ga_per_game' => null,
            'projected_pk_xga_per_game' => null, 'projected_pk_ga_per_game' => null,
            'projected_xga_per_game' => $rating['projected_xga_per_game'] ?? null,
            'projected_ga_per_game' => $rating['projected_ga_per_game'] ?? null,
            'projected_gsax_per_game' => $rating['projected_gsax_per_game'] ?? null,
            'confidence_score' => $rating['confidence_score'] ?? 0,
        ];
    }

    /** Refresh a snapshot on projections workers; this never dispatches legacy workload/profile builders.
     * @param array<string,mixed> $context
     */
    public function start(array $context): bool
    {
        $model = isset($context['model_id']) ? NhlModelRun::query()->find($context['model_id']) : $this->productionModel($context['target']);
        if ($model === null) {
            $this->record($context, 'skipped');
            return true;
        }
        $context['model_id'] = (int) $model->id;
        if ($model->status === NhlModelRun::STATUS_FAILED) {
            throw new RuntimeException('The production SAT model build failed; its goalie ratings cannot be refreshed.');
        }
        if ($model->status !== NhlModelRun::STATUS_COMPLETE) {
            return false;
        }
        $this->record($context, 'building');
        $filter = app(NhlPregameContextBuilder::class)->evaluationBucketFilter((int) $model->id, $model->train_season_ids);
        if ($filter['keys'] === []) {
            throw new RuntimeException('The production SAT model has no retained Train-confidence >= 50% buckets for goalie ratings.');
        }
        $snapshot = $this->snapshot($model, $filter['keys'], true);
        if ($snapshot === null) {
            return false;
        }
        if ($snapshot['ratings'] === []) {
            throw new RuntimeException('Build Goalies under the production SAT model Profiles menu before preparing goalie ratings.');
        }
        $this->record($context, 'ready');
        $this->releaseLegacyLease($context);

        return true;
    }

    /** Old serialized batch continuations are redirected to SAT snapshots, never legacy calculations. */
    public function performance(array $context): void
    {
        RefreshNhlGoalieProjectionsJob::dispatch($context, 'ratings');
    }

    /** Old completion callbacks must not publish partially rebuilt legacy season rows. */
    public function publish(array $context): void
    {
        $this->performance($context);
    }

    /** Record a preparation failure while preserving published snapshots and first meaningful errors. */
    public function fail(array $context, string $error): void
    {
        try {
            $this->record($context, 'failed', $error);
        } finally {
            $this->releaseLegacyLease($context);
        }
        Log::error('SAT goalie rating preparation failed.', ['season' => $context['target'], 'error' => $error]);
    }

    /** Wait for applicable goalie preparation as well as the independent public stats publishers. */
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

    /** Select only the named production model with Train seasons preceding the requested season. */
    private function productionModel(string $season): ?NhlModelRun
    {
        $eligible = NhlModelRun::query()->where('model_family', 'sat')
            ->whereRaw('LOWER(name) = ?', [mb_strtolower((string) config('nhl-custom-prediction.default_model_name', 'sat_v2'))])
            ->orderByDesc('id')->get()->filter(fn (NhlModelRun $model): bool =>
                $model->train_season_ids !== null && $model->train_season_ids !== [] && max($model->train_season_ids) < $season);

        return $eligible->first(fn (NhlModelRun $model): bool => $model->status === NhlModelRun::STATUS_COMPLETE) ?? $eligible->first();
    }

    /** Profile rebuild completion invalidates a snapshot; same-day season sync can also force renewal. */
    private function stamp(NhlModelRun $model): string
    {
        return hash('sha256', json_encode([$model->train_season_ids,
            data_get($model->metrics, 'profile_build.id'), data_get($model->metrics, 'profiles_completed_at')], JSON_THROW_ON_ERROR));
    }

    /** Merge into the existing admin progress payload without losing cache or aggregation updates. */
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
                'model_run_id' => $context['model_id'] ?? null, 'season' => $context['target'], 'error' => $error];
            $runStatus = $run->status === NhlGameImportRun::STATUS_FAILED ? NhlGameImportRun::STATUS_FAILED : self::runStatus($payload);
            if ($runStatus === NhlGameImportRun::STATUS_COMPLETED) {
                $payload['completed_at'] = now()->toIso8601String();
            }
            $run->update(['payload' => $payload, 'status' => $runStatus, 'last_error' => $error ?? $run->last_error]);
        }, 3);
        DB::afterCommit(static function () use ($context): void {
            try {
                broadcast(new NhlGameImportStatusUpdated('season-sync-progress', $context['run_id']));
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }

    /** Release only a lease owned by an old serialized automatic refresh. */
    private function releaseLegacyLease(array $context): void
    {
        if (isset($context['owner'])) {
            Cache::restoreLock('nhl:goalie-refresh:'.$context['target'], $context['owner'])->release();
        }
    }
}
