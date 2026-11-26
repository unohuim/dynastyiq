<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NhlModelRun;
use App\Models\NhlPregameContextRun;
use App\Models\ScheduledProcess;

/** Explicit Vue model-row contract; existing domain services retain admission authority. */
class NhlSatModelPresentation
{
    /** Present the existing action inventory without embedding HTML in the Vue payload. */
    public function row(NhlModelRun $model, array $states): array
    {
        $pregame = NhlPregameContextRun::latestForModel($model->id);
        $schedule = ScheduledProcess::query()->where('key', ScheduledProcessManager::pregameContextKey($model->id))->first();
        $readiness = app(NhlSeasonGameEvaluation::class)->readiness($model);
        $locked = in_array($pregame?->status, ['queued', 'running', 'pausing', 'paused'], true);
        $running = $model->status === 'running';
        $action = static fn (string $group, string $label, string $route, array $data = [], bool $enabled = true, ?string $reason = null, bool $link = false): array => [
            'group' => $group, 'label' => $label, 'url' => route($route, $model),
            'data' => $data, 'enabled' => $enabled, 'reason' => $reason, 'link' => $link,
        ];
        $actions = [
            $action('Evaluate', 'View SOG', 'admin.nhl-sat-models.buckets', ['target' => 'goal'], link: true),
            $action('Evaluate', 'View SAT', 'admin.nhl-sat-models.buckets', ['target' => 'shot_on_goal'], link: true),
            $action('Evaluate', 'Eval SOG', 'admin.nhl-sat-models.train', ['evaluation' => 'sog', 'smoothing_prior_attempts' => 100], ! $running),
            $action('Evaluate', 'Eval SAT', 'admin.nhl-sat-models.train', ['evaluation' => 'sat', 'smoothing_prior_attempts' => 100], ! $running),
            $action('Build', 'Build Predictions', 'admin.nhl-sat-models.predictions.build', enabled: ! $running),
            $action('Profiles', 'View Profiles', 'admin.nhl-sat-models.profiles', link: true),
            $action('Profiles', 'Build Offensive Skaters', 'admin.nhl-sat-models.profiles.build', ['profile_type' => 'skater_offense'], ! $running),
            $action('Profiles', 'Build Goalies', 'admin.nhl-sat-models.profiles.build', ['profile_type' => 'goalie_faced'], ! $running),
            $action('Predict', 'Run Season Games', 'admin.nhl-sat-models.season-games', enabled: $readiness['ready'], reason: implode(' ', $readiness['reasons']), link: true),
            $action('Predict', 'Season Game Results', 'admin.nhl-sat-models.season-games', link: true),
            $action('Predict', 'Training Drift', 'admin.nhl-sat-models.profiles.training-drift', enabled: $states['drift']['can_view_training_drift'], link: true),
            $action('Predict', 'Bucket Stability', 'admin.nhl-sat-models.profiles.bucket-stability', enabled: $states['stability']['can_view_bucket_stability'], link: true),
            $action('Predict', 'Build /60 — Offense', 'admin.nhl-sat-models.rate-projections.build', ['profile_type' => 'skater_offense'], ! $running),
            $action('Predict', 'View /60', 'admin.nhl-sat-models.rate-projections', link: true),
            $action('Predict', 'Build TOI', 'admin.nhl-sat-models.toi-projections.build', enabled: ! $running && $states['toi']['can_build_toi_projection']),
            $action('Predict', 'View TOI', 'admin.nhl-sat-models.toi-projections', enabled: $states['toi']['can_view_toi_projection'], link: true),
            $action('Analysis', 'Build Pregame', 'admin.nhl-sat-models.context.build', enabled: ! $locked),
            $action('Analysis', 'Update Pregame', 'admin.nhl-sat-models.context.update', enabled: ! $locked),
            ['group' => 'Analysis', 'label' => 'View Pregame Impacts', 'url' => route('admin.nhl-sat-models.context.effects'), 'link' => true, 'enabled' => $pregame?->canViewImpacts() ?? false, 'data' => []],
            ['group' => 'Analysis', 'label' => 'Game Evaluations', 'url' => route('admin.nhl-sat-models.next-game'), 'link' => true, 'enabled' => true, 'data' => []],
            $action('Compare', 'Build Comparisons', 'admin.nhl-sat-models.rate-projections.compare.build', enabled: ! $running && $states['comparison']['can_build_rate_comparison']),
            $action('Compare', 'Bucket Comparisons', 'admin.nhl-sat-models.rate-projections.compare.raw', enabled: $states['comparison']['can_view_rate_comparison'], link: true),
            $action('Compare', 'Player Comparisons', 'admin.nhl-sat-models.rate-projections.compare.aggregates', enabled: $states['comparison']['can_view_rate_comparison'], link: true),
        ];
        $build = data_get($model->metrics, 'prediction_build', []);
        $profile = data_get($model->metrics, 'profile_build', []);
        $combined = ($build['status'] ?? null) === $model->status;
        $current = $combined ? $build : (($profile['status'] ?? null) === $model->status ? $profile : []);
        $stage = $combined ? ($build['stage'] ?? 'profiles') : 'profiles';
        $prefix = ['profiles' => 'profile', 'rates' => 'rate_projection', 'toi' => 'toi_projection'][$stage] ?? 'profile';
        $done = (int) data_get($model->metrics, $prefix.'_entities_completed', 0);
        $total = (int) data_get($model->metrics, $prefix.'_entities_queued', 0);
        if ($prefix === 'profile') {
            $done += (int) data_get($model->metrics, 'season_snapshot_entities_completed', 0);
            $total += (int) data_get($model->metrics, 'season_snapshot_entities_queued', 0);
        }

        return ['id' => (int) $model->id, 'name' => $model->name, 'version' => $model->model_version,
            'train' => $model->train_season_ids ?? [], 'test' => $model->target_season_id,
            'projection' => $model->projectionSeasonId(), 'status' => $model->status, 'notes' => $model->notes,
            'updated_at' => $model->updated_at?->toIso8601String(), 'training' => $states['training'],
            'build' => ['kind' => $combined ? 'predictions' : 'profiles', 'stage' => $stage, 'status' => $current['status'] ?? null,
                'error' => $current['error'] ?? null, 'completed' => $done, 'total' => $total,
                'discovering' => ($profile['status'] ?? null) === 'running' && ! ($profile['loading_complete'] ?? false)],
            'outdated' => collect(['skater_offense' => 'Skaters', 'goalie_faced' => 'Goalies'])
                ->filter(fn ($name, $type) => data_get($model->metrics, 'profile_freshness.'.$type.'.outdated'))->values()->all(),
            'pregame' => app(NhlSatPregameWorkspace::class)->progress($pregame),
            'automation' => ['enabled' => (bool) $schedule?->enabled,
                'settings_url' => route('admin.nhl-sat-models.context.auto-sync.show', $model),
                'toggle_url' => route('admin.nhl-sat-models.context.auto-sync.toggle', $model)],
            'actions' => $actions];
    }
}
