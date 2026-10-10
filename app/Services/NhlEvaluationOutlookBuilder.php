<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NhlModelRun;
use App\Models\NhlNextGameEvaluation;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Freeze existing model projections into an evaluation; never predict or score games. */
class NhlEvaluationOutlookBuilder
{
    private const ROW_LIMIT = 5000;

    /** Require successful, persisted projection builds rather than silently falling back to history. */
    public function validateModel(NhlModelRun $model): void
    {
        $seasons = array_map('strval', $model->train_season_ids ?? []);
        $season = $model->projectionSeasonId();
        if ($model->model_family !== 'sat' || $model->workflow_stage !== 'training'
            || $model->status !== 'complete' || $seasons === [] || $season === null || max($seasons) >= $season) {
            throw new RuntimeException('Choose a completed SAT model with a projection season after its Train seasons.');
        }
        foreach (['rate', 'toi'] as $stage) {
            $metrics = $model->metrics ?? [];
            $completed = $metrics[$stage . '_projections_completed_at'] ?? null;
            $started = $metrics[$stage . '_projections_started_at'] ?? null;
            if (! $completed || ($started && $started > $completed)
                || (int) ($metrics[$stage . '_projection_entities_queued'] ?? 0) < 1
                || (int) ($metrics[$stage . '_projection_entities_completed'] ?? 0)
                    !== (int) $metrics[$stage . '_projection_entities_queued']) {
                throw new RuntimeException('Build SAT/60 and TOI/GP projections for this model before starting an evaluation.');
            }
        }
        if (! $this->rates($model->id)->whereNotNull('projected_xsat_per_60')->exists()
            || ! $this->toi($model->id, $season)->whereNotNull('projected_toi_per_game_seconds')->exists()) {
            throw new RuntimeException('This model has no usable SAT/60 or TOI/GP projections. Build projections first.');
        }
    }

    /** Advance one player inside the evaluation job's checkpoint transaction. */
    public function advance(NhlNextGameEvaluation $evaluation): void
    {
        $model = NhlModelRun::query()->lockForUpdate()->findOrFail($evaluation->model_run_id);
        $this->validateModel($model);
        $inputs = $evaluation->inputs ?? [];
        if ($evaluation->season_id !== $model->projectionSeasonId()
            || ($inputs['model_updated_at'] ?? null) !== $model->updated_at->toISOString()) {
            throw new RuntimeException('The source model changed. Create a new evaluation to use the revised projections.');
        }
        if (($inputs['_work']['stage'] ?? 'initialize') === 'initialize') {
            $inputs['source_signature'] = $this->signature($model->id);
            $inputs['_work'] = [...$inputs['_work'], 'stage' => 'outlook', 'player_cursor' => 0,
                'players_completed' => 0, 'players_total' => $this->rates($model->id)->distinct()->count('entity_id')];
            $evaluation->update(['status' => 'building', 'inputs' => $inputs]);

            return;
        }
        $playerId = $this->rates($model->id)->where('entity_id', '>', $inputs['_work']['player_cursor'])
            ->orderBy('entity_id')->value('entity_id');
        if ($playerId === null) {
            if ($inputs['source_signature'] !== $this->signature($model->id)) {
                throw new RuntimeException('Projection outputs changed during preparation. Create a new evaluation.');
            }
            $inputs['ready_at'] = now()->toISOString();
            $evaluation->update(['status' => 'ready', 'inputs' => $inputs]);

            return;
        }
        $buckets = $this->rates($model->id)->where('entity_id', $playerId)->limit(self::ROW_LIMIT + 1)->get();
        if ($buckets->count() > self::ROW_LIMIT) {
            throw new RuntimeException('Player projection exceeds the evaluation bucket safety limit.');
        }
        $opportunity = $this->toi($model->id, $evaluation->season_id)->where('entity_id', $playerId)->first();
        foreach ($buckets->all() as $bucket) {
            $this->validateSources($bucket->source_season_ids, $inputs['training_seasons']);
        }
        if ($opportunity) {
            $this->validateSources($opportunity->source_season_ids, $inputs['training_seasons']);
        }
        $splits = DB::table('nhl_sat_model_entity_rate_projection_splits')->where('model_run_id', $model->id)
            ->where('profile_type', 'skater_offense')->where('entity_id', $playerId)
            ->whereIn('situation', NhlNextGameEvaluationService::STRENGTHS)->get()->keyBy('situation');
        $rows = [];
        foreach (NhlNextGameEvaluationService::STRENGTHS as $strength) {
            $split = $splits->get($strength);
            $sat = $split?->projected_sat_per_60;
            if ($strength === 'all' && $sat === null && $buckets->whereNotNull('projected_xsat_per_60')->isNotEmpty()) {
                $sat = $buckets->sum('projected_xsat_per_60');
            }
            $toi = $strength === 'all' ? $opportunity?->projected_toi_per_game_seconds : $split?->projected_toi_per_gp;
            foreach (['q1', 'q2', 'q3', 'q4', 'season'] as $period) {
                $rows[] = [
                    'evaluation_id' => $evaluation->id, 'nhl_player_id' => $playerId,
                    'player_name' => $buckets->first()->entity_name, 'strength' => $strength,
                    'period' => $period, 'revision' => 1, 'sat_per_60' => $sat, 'toi_per_game_seconds' => $toi,
                    'provenance' => json_encode(['method' => 'model_projection_initial_v1',
                        'model_run_id' => $model->id, 'split_projection_id' => $split?->id,
                        'toi_projection_id' => $strength === 'all' ? $opportunity?->id : $split?->id,
                        'bucket_projection_ids' => $strength === 'all' && $split?->projected_sat_per_60 === null ? $buckets->pluck('id')->all() : [],
                        'sat_available' => $sat !== null, 'toi_available' => $toi !== null], JSON_THROW_ON_ERROR),
                    'created_at' => now(), 'updated_at' => now(),
                ];
            }
        }
        DB::table('nhl_evaluation_player_outlooks')->upsert($rows,
            ['evaluation_id', 'nhl_player_id', 'strength', 'period', 'revision'],
            ['player_name', 'sat_per_60', 'toi_per_game_seconds', 'provenance', 'updated_at']);
        $inputs['_work']['player_cursor'] = (int) $playerId;
        $inputs['_work']['players_completed']++;
        $evaluation->update(['status' => 'building', 'inputs' => $inputs]);
    }

    /** Reject projections containing Test or otherwise unapproved source seasons. */
    private function validateSources(string $json, array $training): void
    {
        $sources = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($sources) || $sources === [] || array_diff($sources, $training) !== []) {
            throw new RuntimeException('Projection source seasons do not match this model’s Train seasons.');
        }
    }

    /** Model-scoped regular-season offensive projection population. */
    private function rates(int $modelId): Builder
    {
        return DB::table('nhl_sat_model_entity_rate_projection_buckets')->where('model_run_id', $modelId)
            ->where('profile_type', 'skater_offense')->where('game_type', 2)->whereNotNull('entity_id');
    }

    /** All-strength opportunity remains owned by the standalone TOI build. */
    private function toi(int $modelId, string $season): Builder
    {
        return DB::table('nhl_sat_model_entity_toi_projections')->where('model_run_id', $modelId)
            ->where('profile_type', 'skater_offense')->where('game_type', 2)->where('target_season_id', $season);
    }

    /** Small source fingerprints detect rebuilding while a multi-job snapshot is being frozen. */
    private function signature(int $modelId): array
    {
        $signature = [];
        foreach (['nhl_sat_model_entity_rate_projection_buckets', 'nhl_sat_model_entity_rate_projection_splits',
            'nhl_sat_model_entity_toi_projections'] as $table) {
            $signature[$table] = (array) DB::table($table)->where('model_run_id', $modelId)
                ->where('profile_type', 'skater_offense')->selectRaw('COUNT(*) as rows, MAX(id) as last_id, MAX(updated_at) as updated')->first();
        }

        return $signature;
    }
}
