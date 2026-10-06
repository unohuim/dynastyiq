<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'nhl_sat_model_entity_profile_buckets',
            'nhl_sat_model_entity_test_profile_buckets',
        ] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->decimal('source_gsax_per_100_xga', 12, 4)->nullable()->after('source_gsax_per_60');
            });
        }

        foreach ([
            'nhl_sat_model_entity_rate_comparison_buckets',
            'nhl_sat_model_entity_rate_comparison_aggregates',
        ] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->decimal('train_gsax_xga', 12, 4)->nullable()->after('test_gsax');
                $table->decimal('test_gsax_xga', 12, 4)->nullable()->after('train_gsax_xga');
                $table->decimal('train_gsax_per_100_xga', 12, 4)->nullable()->after('test_gsax_xga');
                $table->decimal('test_gsax_per_100_xga', 12, 4)->nullable()->after('train_gsax_per_100_xga');
                $table->decimal('gsax_per_100_xga_drift', 12, 4)->nullable()->after('test_gsax_per_100_xga');
                $table->decimal('gsax_per_100_xga_drift_rate', 12, 6)->nullable()->after('gsax_per_100_xga_drift');
            });
        }
    }

    public function down(): void
    {
        foreach ([
            'nhl_sat_model_entity_rate_comparison_buckets',
            'nhl_sat_model_entity_rate_comparison_aggregates',
        ] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn([
                    'train_gsax_xga', 'test_gsax_xga', 'train_gsax_per_100_xga',
                    'test_gsax_per_100_xga', 'gsax_per_100_xga_drift', 'gsax_per_100_xga_drift_rate',
                ]);
            });
        }

        foreach ([
            'nhl_sat_model_entity_profile_buckets',
            'nhl_sat_model_entity_test_profile_buckets',
        ] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn('source_gsax_per_100_xga');
            });
        }
    }
};
