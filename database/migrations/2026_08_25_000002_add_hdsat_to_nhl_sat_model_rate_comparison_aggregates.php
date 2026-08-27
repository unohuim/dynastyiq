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
            if (! Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'train_hdsat')) {
                $table->unsignedInteger('train_hdsat')
                    ->default(0)
                    ->after('train_sat');
            }

            if (! Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'test_hdsat')) {
                $table->unsignedInteger('test_hdsat')
                    ->default(0)
                    ->after('test_sat');
            }

            if (! Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'train_hdsat_per_60')) {
                $table->decimal('train_hdsat_per_60', 12, 4)
                    ->nullable()
                    ->after('share_drift_rate');
            }

            if (! Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'test_hdsat_per_60')) {
                $table->decimal('test_hdsat_per_60', 12, 4)
                    ->nullable()
                    ->after('train_hdsat_per_60');
            }

            if (! Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'hdsat_drift')) {
                $table->decimal('hdsat_drift', 12, 4)
                    ->nullable()
                    ->after('test_hdsat_per_60');
            }

            if (! Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', 'hdsat_drift_rate')) {
                $table->decimal('hdsat_drift_rate', 12, 6)
                    ->nullable()
                    ->after('hdsat_drift');
            }
        });
    }

    public function down(): void
    {
        Schema::table('nhl_sat_model_entity_rate_comparison_aggregates', function (Blueprint $table): void {
            foreach ([
                'hdsat_drift_rate',
                'hdsat_drift',
                'test_hdsat_per_60',
                'train_hdsat_per_60',
                'test_hdsat',
                'train_hdsat',
            ] as $column) {
                if (Schema::hasColumn('nhl_sat_model_entity_rate_comparison_aggregates', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
