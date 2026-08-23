<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\NhlModelRun;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Broadcasts that an admin SAT model row changed.
 */
class NhlSatModelUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public int $modelId,
        public string $reason,
    ) {
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('admin.sat-models');
    }

    public function broadcastAs(): string
    {
        return 'admin.nhl-sat-models.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $model = NhlModelRun::query()->find($this->modelId);

        return [
            'model_id' => $this->modelId,
            'reason' => $this->reason,
            'status' => $model?->status,
            'row_html' => $model === null
                ? null
                : view('admin.nhl-sat-models._model-row', [
                    'comparisonState' => $this->rateComparisonStateForRun($model),
                    'run' => $model,
                ])->render(),
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array{has_rate_projections:bool,has_test_profiles:bool,has_rate_comparisons:bool,can_build_rate_comparison:bool,can_view_rate_comparison:bool}
     */
    private function rateComparisonStateForRun(NhlModelRun $run): array
    {
        $hasRateProjections = Schema::hasTable('nhl_sat_model_entity_rate_projection_buckets')
            && DB::table('nhl_sat_model_entity_rate_projection_buckets')
                ->where('model_run_id', $run->id)
                ->exists();
        $hasTestProfiles = $run->target_season_id !== null
            && Schema::hasTable('nhl_sat_model_entity_test_profile_buckets')
            && DB::table('nhl_sat_model_entity_test_profile_buckets')
                ->where('model_run_id', $run->id)
                ->where('test_season_id', (string) $run->target_season_id)
                ->exists();
        $hasRateComparisons = Schema::hasTable('nhl_sat_model_entity_rate_comparison_buckets')
            && Schema::hasTable('nhl_sat_model_entity_rate_comparison_aggregates')
            && DB::table('nhl_sat_model_entity_rate_comparison_buckets')
                ->where('model_run_id', $run->id)
                ->where('test_season_id', (string) ($run->target_season_id ?? ''))
                ->exists()
            && DB::table('nhl_sat_model_entity_rate_comparison_aggregates')
                ->where('model_run_id', $run->id)
                ->where('test_season_id', (string) ($run->target_season_id ?? ''))
                ->exists();

        return [
            'has_rate_projections' => $hasRateProjections,
            'has_test_profiles' => $hasTestProfiles,
            'has_rate_comparisons' => $hasRateComparisons,
            'can_build_rate_comparison' => $hasRateProjections && $hasTestProfiles,
            'can_view_rate_comparison' => $hasRateComparisons,
        ];
    }
}
