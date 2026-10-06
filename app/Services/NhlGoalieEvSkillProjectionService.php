<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Calculates a training-only EV goalie adjustment relative to league expectation. */
class NhlGoalieEvSkillProjectionService
{
    /**
     * Return the EV-only goal-prevention adjustment, or null when the legacy
     * bucket calculation must remain in control.
     *
     * @return array{adjustment:float,gsax_edge:float,history_seasons:int}|null
     */
    public function adjustment(int $modelId, int $goalieId): ?array
    {
        if (! Schema::hasTable('nhl_sat_model_entity_profile_buckets')
            || ! Schema::hasColumn('nhl_sat_model_entity_profile_buckets', 'strength')) {
            return null;
        }

        $goalie = DB::table('nhl_sat_model_entity_profile_buckets')
            ->where('model_run_id', $modelId)->where('profile_type', 'goalie_faced')
            ->where('strength', 'ev')->where('entity_id', $goalieId)
            ->selectRaw('SUM(expected_goals) xga, SUM(source_goals) goals')->first();
        $league = DB::table('nhl_sat_model_entity_profile_buckets')
            ->where('model_run_id', $modelId)->where('profile_type', 'goalie_faced')->where('strength', 'ev')
            ->selectRaw('SUM(expected_goals) xga, SUM(source_goals) goals')->first();
        if ((float) ($goalie->xga ?? 0) <= 0 || (float) ($league->xga ?? 0) <= 0) {
            return null;
        }

        $edge = (((float) $goalie->xga - (float) $goalie->goals) / (float) $goalie->xga)
            - (((float) $league->xga - (float) $league->goals) / (float) $league->xga);
        $historySeasons = 0;
        if (Schema::hasTable('nhl_sat_model_entity_test_profile_buckets')) {
            $historySeasons = DB::table('nhl_sat_model_entity_test_profile_buckets')
                ->where('model_run_id', $modelId)->where('profile_type', 'goalie_faced')
                ->where('strength', 'ev')->where('entity_id', $goalieId)
                ->distinct()->count('test_season_id');
        }
        $credit = $historySeasons >= 3 ? 0.20 : ($historySeasons === 2 ? 0.20 : 0.10);

        return [
            'adjustment' => max(-0.15, min(0.15, $edge * $credit)),
            'gsax_edge' => $edge,
            'history_seasons' => $historySeasons,
        ];
    }
}
