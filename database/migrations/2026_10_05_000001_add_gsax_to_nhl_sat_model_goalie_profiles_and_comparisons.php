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
                $table->decimal('source_gsax', 12, 4)->nullable()->after('goals_above_expected');
                $table->decimal('source_gsax_per_60', 12, 4)->nullable()->after('source_gsax');
            });
        }

        foreach ([
            'nhl_sat_model_entity_rate_comparison_buckets',
            'nhl_sat_model_entity_rate_comparison_aggregates',
        ] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->decimal('train_gsax', 12, 4)->nullable()->after('test_goals');
                $table->decimal('test_gsax', 12, 4)->nullable()->after('train_gsax');
                $table->unsignedInteger('train_gsax_toi_seconds')->nullable()->after('test_gsax');
                $table->unsignedInteger('test_gsax_toi_seconds')->nullable()->after('train_gsax_toi_seconds');
                $table->decimal('train_gsax_per_60', 12, 4)->nullable()->after('test_gsax_toi_seconds');
                $table->decimal('test_gsax_per_60', 12, 4)->nullable()->after('train_gsax_per_60');
                $table->decimal('gsax_drift', 12, 4)->nullable()->after('test_gsax_per_60');
                $table->decimal('gsax_drift_rate', 12, 6)->nullable()->after('gsax_drift');
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
                    'train_gsax', 'test_gsax', 'train_gsax_toi_seconds', 'test_gsax_toi_seconds',
                    'train_gsax_per_60', 'test_gsax_per_60', 'gsax_drift', 'gsax_drift_rate',
                ]);
            });
        }

        foreach ([
            'nhl_sat_model_entity_profile_buckets',
            'nhl_sat_model_entity_test_profile_buckets',
        ] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn(['source_gsax', 'source_gsax_per_60']);
            });
        }
    }
};
