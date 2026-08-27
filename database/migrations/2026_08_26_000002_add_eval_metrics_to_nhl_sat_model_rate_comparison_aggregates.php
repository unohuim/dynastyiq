<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nhl_sat_model_entity_rate_comparison_aggregates', function (Blueprint $table): void {
            foreach ($this->columns() as $column => $definition) {
                if (Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', $column)) {
                    continue;
                }

                match ($definition['type']) {
                    'unsignedInteger' => $table->unsignedInteger($column)->nullable()->after($definition['after']),
                    'decimal12_4' => $table->decimal($column, 12, 4)->nullable()->after($definition['after']),
                    'decimal12_6' => $table->decimal($column, 12, 6)->nullable()->after($definition['after']),
                };
            }
        });
    }

    public function down(): void
    {
        Schema::table('nhl_sat_model_entity_rate_comparison_aggregates', function (Blueprint $table): void {
            foreach (array_reverse(array_keys($this->columns())) as $column) {
                if (Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    /**
     * @return array<string, array{type:string,after:string}>
     */
    private function columns(): array
    {
        return [
            'train_eval_gp_per_season' => ['type' => 'decimal12_4', 'after' => 'test_games'],
            'test_eval_gp_per_season' => ['type' => 'decimal12_4', 'after' => 'train_eval_gp_per_season'],
            'train_eval_toi_seconds' => ['type' => 'unsignedInteger', 'after' => 'test_eval_gp_per_season'],
            'test_eval_toi_seconds' => ['type' => 'unsignedInteger', 'after' => 'train_eval_toi_seconds'],
            'train_eval_toi_per_gp' => ['type' => 'decimal12_4', 'after' => 'test_eval_toi_seconds'],
            'test_eval_toi_per_gp' => ['type' => 'decimal12_4', 'after' => 'train_eval_toi_per_gp'],
            'train_eval_sat' => ['type' => 'unsignedInteger', 'after' => 'test_eval_toi_per_gp'],
            'test_eval_sat' => ['type' => 'unsignedInteger', 'after' => 'train_eval_sat'],
            'train_eval_sat_per_gp' => ['type' => 'decimal12_4', 'after' => 'test_eval_sat'],
            'test_eval_sat_per_gp' => ['type' => 'decimal12_4', 'after' => 'train_eval_sat_per_gp'],
            'train_eval_sat_per_60' => ['type' => 'decimal12_4', 'after' => 'test_eval_sat_per_gp'],
            'test_eval_sat_per_60' => ['type' => 'decimal12_4', 'after' => 'train_eval_sat_per_60'],
            'train_eval_hdsat' => ['type' => 'unsignedInteger', 'after' => 'test_eval_sat_per_60'],
            'test_eval_hdsat' => ['type' => 'unsignedInteger', 'after' => 'train_eval_hdsat'],
            'train_eval_hdsat_per_gp' => ['type' => 'decimal12_4', 'after' => 'test_eval_hdsat'],
            'test_eval_hdsat_per_gp' => ['type' => 'decimal12_4', 'after' => 'train_eval_hdsat_per_gp'],
            'train_eval_hdsat_per_60' => ['type' => 'decimal12_4', 'after' => 'test_eval_hdsat_per_gp'],
            'test_eval_hdsat_per_60' => ['type' => 'decimal12_4', 'after' => 'train_eval_hdsat_per_60'],
            'train_eval_hdsat_sat_rate' => ['type' => 'decimal12_6', 'after' => 'test_eval_hdsat_per_60'],
            'test_eval_hdsat_sat_rate' => ['type' => 'decimal12_6', 'after' => 'train_eval_hdsat_sat_rate'],
            'train_eval_sog' => ['type' => 'unsignedInteger', 'after' => 'test_eval_hdsat_sat_rate'],
            'test_eval_sog' => ['type' => 'unsignedInteger', 'after' => 'train_eval_sog'],
            'train_eval_sog_per_gp' => ['type' => 'decimal12_4', 'after' => 'test_eval_sog'],
            'test_eval_sog_per_gp' => ['type' => 'decimal12_4', 'after' => 'train_eval_sog_per_gp'],
            'train_eval_sog_per_60' => ['type' => 'decimal12_4', 'after' => 'test_eval_sog_per_gp'],
            'test_eval_sog_per_60' => ['type' => 'decimal12_4', 'after' => 'train_eval_sog_per_60'],
            'train_eval_goals' => ['type' => 'unsignedInteger', 'after' => 'test_eval_sog_per_60'],
            'test_eval_goals' => ['type' => 'unsignedInteger', 'after' => 'train_eval_goals'],
            'train_eval_goals_per_gp' => ['type' => 'decimal12_4', 'after' => 'test_eval_goals'],
            'test_eval_goals_per_gp' => ['type' => 'decimal12_4', 'after' => 'train_eval_goals_per_gp'],
            'train_eval_goals_per_60' => ['type' => 'decimal12_4', 'after' => 'test_eval_goals_per_gp'],
            'test_eval_goals_per_60' => ['type' => 'decimal12_4', 'after' => 'train_eval_goals_per_60'],
        ];
    }
};
